# Proxied Immich Media Implementation Plan

> **For Claude:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task.

**Goal:** Add "Use" mode that creates virtual WordPress attachments referencing Immich assets, served via a public proxy endpoint.

**Architecture:** Public proxy endpoint on `init` serves images/video from Immich. Virtual attachments created via `wp_insert_attachment` with no local file. `image_downsize` and `wp_get_attachment_url` filters redirect URLs to proxy. UI gets "Use" and "Copy" buttons plus a "Previously added" section.

**Tech Stack:** WordPress Plugin API (hooks, filters, AJAX), Immich REST API, PHP stream contexts for video

**Design doc:** `docs/plans/2026-03-01-proxied-media-design.md`

---

### Task 1: Public Proxy Endpoint for Images

Add a public proxy endpoint that serves Immich thumbnails and originals through WordPress.

**Files:**
- Modify: `immich-media-picker.php:26-38` (constructor hooks)
- Modify: `immich-media-picker.php:148` (after `get_api_url()`, add new method)

**Step 1: Add `init` hook in constructor**

In the constructor (line 37, before the closing `}`), add:

```php
add_action( 'init', array( $this, 'handle_proxy_request' ) );
```

**Step 2: Add the proxy handler method**

Add after `get_api_url()` (after line 147):

```php
public function handle_proxy_request(): void {
    if ( ! isset( $_GET['immich_media_proxy'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- public proxy endpoint, no auth required
        return;
    }

    $type = sanitize_key( $_GET['immich_media_proxy'] ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
    if ( ! in_array( $type, array( 'thumbnail', 'original' ), true ) ) {
        status_header( 400 );
        exit( 'Invalid type.' );
    }

    $id = sanitize_text_field( wp_unslash( $_GET['id'] ?? '' ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
    if ( ! preg_match( '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $id ) ) {
        status_header( 400 );
        exit( 'Invalid ID.' );
    }

    $api_key = $this->get_api_key();
    if ( '' === $api_key ) {
        status_header( 500 );
        exit( 'No API key configured.' );
    }

    $base    = rtrim( $this->get_api_url(), '/' );
    $api_url = 'thumbnail' === $type
        ? $base . '/api/assets/' . $id . '/thumbnail'
        : $base . '/api/assets/' . $id . '/original';
    $timeout = 'thumbnail' === $type ? 10 : 30;

    $response = wp_remote_get( $api_url, array(
        'headers' => array( 'x-api-key' => $api_key ),
        'timeout' => $timeout,
    ) );

    if ( is_wp_error( $response ) ) {
        status_header( 502 );
        exit( 'Upstream error.' );
    }

    $code = wp_remote_retrieve_response_code( $response );
    if ( $code < 200 || $code >= 300 ) {
        status_header( (int) $code );
        exit( 'Asset not available.' );
    }

    $content_type = strtok( wp_remote_retrieve_header( $response, 'content-type' ) ?: 'application/octet-stream', ';' );
    $body         = wp_remote_retrieve_body( $response );

    header( 'Content-Type: ' . $content_type );
    header( 'Content-Length: ' . strlen( $body ) );
    header( 'Cache-Control: public, max-age=31536000' );
    header( 'X-Content-Type-Options: nosniff' );
    echo $body; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- binary image data
    exit;
}
```

**Step 3: Verify manually**

Visit `http://localhost:8888/?immich_media_proxy=thumbnail&id=<valid-uuid>` in browser.
Expected: Immich thumbnail displayed. Check response headers for `Cache-Control` and correct `Content-Type`.

**Step 4: Commit**

```
git add immich-media-picker.php
git commit -m "Add public proxy endpoint for Immich thumbnails and originals"
```

---

### Task 2: Video Streaming Proxy

Add video type to the proxy endpoint with chunked streaming and Range request support.

**Files:**
- Modify: `immich-media-picker.php` — `handle_proxy_request()` method

**Step 1: Allow `video` type**

Change the type whitelist in `handle_proxy_request()`:

```php
if ( ! in_array( $type, array( 'thumbnail', 'original', 'video' ), true ) ) {
```

**Step 2: Add video streaming branch**

Before the existing `wp_remote_get` call in `handle_proxy_request()`, add a branch for video:

```php
if ( 'video' === $type ) {
    $this->stream_video( $base . '/api/assets/' . $id . '/video/playback', $api_key );
    return;
}
```

**Step 3: Add `stream_video()` method**

Add a new private method after `handle_proxy_request()`:

```php
private function stream_video( string $url, string $api_key ): void {
    $headers = array( 'x-api-key: ' . $api_key );

    // Forward Range header for seeking support.
    $range = isset( $_SERVER['HTTP_RANGE'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_RANGE'] ) ) : '';
    if ( '' !== $range ) {
        $headers[] = 'Range: ' . $range;
    }

    $context = stream_context_create( array(
        'http' => array(
            'method'        => 'GET',
            'header'        => implode( "\r\n", $headers ),
            'ignore_errors' => true,
            'timeout'       => 60,
        ),
    ) );

    $remote = @fopen( $url, 'rb', false, $context ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
    if ( ! $remote ) {
        status_header( 502 );
        exit( 'Failed to connect to Immich.' );
    }

    // Parse response headers from stream metadata.
    $meta            = stream_get_meta_data( $remote );
    $response_headers = $meta['wrapper_data'] ?? array();
    $status_code     = 200;

    foreach ( $response_headers as $header_line ) {
        if ( preg_match( '/^HTTP\/[\d.]+ (\d+)/', $header_line, $m ) ) {
            $status_code = (int) $m[1];
        } elseif ( preg_match( '/^(Content-Type|Content-Length|Content-Range|Accept-Ranges):\s*(.+)/i', $header_line, $m ) ) {
            header( $m[1] . ': ' . trim( $m[2] ) );
        }
    }

    status_header( $status_code );
    header( 'Cache-Control: public, max-age=31536000' );
    header( 'X-Content-Type-Options: nosniff' );

    // Stream in 8KB chunks.
    while ( ! feof( $remote ) ) {
        echo fread( $remote, 8192 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped, WordPress.WP.AlternativeFunctions.file_system_operations_fread
        flush();
    }

    fclose( $remote ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
    exit;
}
```

**Step 4: Verify manually**

Visit `http://localhost:8888/?immich_media_proxy=video&id=<valid-video-uuid>` in browser.
Expected: Video plays, seeking works (check network tab for 206 responses).

**Step 5: Commit**

```
git add immich-media-picker.php
git commit -m "Add video streaming with Range request support to proxy endpoint"
```

---

### Task 3: "Use" AJAX Endpoint (Virtual Attachment Creation)

Add `ajax_use` that creates a WordPress attachment with no local file, storing the Immich asset ID in post meta.

**Files:**
- Modify: `immich-media-picker.php:26-38` (constructor — add AJAX hook)
- Modify: `immich-media-picker.php` (add `ajax_use()` method after `ajax_import()`)

**Step 1: Register the AJAX action**

In the constructor, after line 36 (`add_action( 'wp_ajax_immich_import', ...)`), add:

```php
add_action( 'wp_ajax_immich_use', array( $this, 'ajax_use' ) );
```

**Step 2: Add `ajax_use()` method**

Add after `ajax_import()`:

```php
public function ajax_use(): void {
    if ( ! $this->verify_ajax_request() ) {
        return;
    }

    $id = sanitize_text_field( wp_unslash( $_POST['id'] ?? '' ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified in verify_ajax_request()
    if ( ! preg_match( '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $id ) ) {
        wp_send_json_error( 'Invalid asset ID.' );
        return;
    }

    $info = $this->api_request( '/api/assets/' . $id );
    if ( is_wp_error( $info ) ) {
        wp_send_json_error( $info->get_error_message() );
        return;
    }

    $filename   = sanitize_file_name( $info['originalFileName'] ?? $id . '.jpg' );
    $asset_type = $info['type'] ?? 'IMAGE'; // IMAGE or VIDEO
    $mime       = $info['originalMimeType'] ?? ( 'VIDEO' === $asset_type ? 'video/mp4' : 'image/jpeg' );
    $width      = (int) ( $info['exifInfo']['exifImageWidth'] ?? 0 );
    $height     = (int) ( $info['exifInfo']['exifImageHeight'] ?? 0 );

    $attachment = array(
        'post_title'     => pathinfo( $filename, PATHINFO_FILENAME ),
        'post_mime_type' => $mime,
        'post_status'    => 'inherit',
    );

    $attach_id = wp_insert_attachment( $attachment );
    if ( is_wp_error( $attach_id ) ) {
        wp_send_json_error( 'Failed to create attachment.' );
        return;
    }

    update_post_meta( $attach_id, '_immich_asset_id', $id );
    update_post_meta( $attach_id, '_immich_asset_type', $asset_type );

    if ( 'IMAGE' === $asset_type && $width > 0 && $height > 0 ) {
        wp_update_attachment_metadata( $attach_id, array(
            'width'  => $width,
            'height' => $height,
            'file'   => 'immich-proxy/' . $id,
            'sizes'  => array(
                'thumbnail' => array(
                    'width'     => 250,
                    'height'    => 250,
                    'file'      => $id,
                    'mime-type' => $mime,
                ),
                'medium' => array(
                    'width'     => 600,
                    'height'    => 600,
                    'file'      => $id,
                    'mime-type' => $mime,
                ),
                'large' => array(
                    'width'     => 1024,
                    'height'    => 1024,
                    'file'      => $id,
                    'mime-type' => $mime,
                ),
            ),
        ) );
    }

    wp_send_json_success( array( 'attachmentId' => $attach_id ) );
}
```

**Step 3: Commit**

```
git add immich-media-picker.php
git commit -m "Add ajax_use endpoint for creating virtual Immich attachments"
```

---

### Task 4: URL Generation Hooks (`image_downsize` and `wp_get_attachment_url`)

Hook into WordPress URL generation so proxied attachments return proxy URLs instead of looking for local files.

**Files:**
- Modify: `immich-media-picker.php:26-38` (constructor — add filter hooks)
- Modify: `immich-media-picker.php` (add two filter methods)

**Step 1: Register filters in constructor**

In the constructor, add:

```php
add_filter( 'wp_get_attachment_url', array( $this, 'filter_attachment_url' ), 10, 2 );
add_filter( 'image_downsize', array( $this, 'filter_image_downsize' ), 10, 3 );
```

**Step 2: Add `filter_attachment_url()` method**

```php
public function filter_attachment_url( string $url, int $attachment_id ): string {
    $immich_id = get_post_meta( $attachment_id, '_immich_asset_id', true );
    if ( ! $immich_id ) {
        return $url;
    }

    $asset_type = get_post_meta( $attachment_id, '_immich_asset_type', true );
    if ( 'VIDEO' === $asset_type ) {
        return home_url( '/?immich_media_proxy=video&id=' . $immich_id );
    }

    return home_url( '/?immich_media_proxy=original&id=' . $immich_id );
}
```

**Step 3: Add `filter_image_downsize()` method**

```php
public function filter_image_downsize( $downsize, int $attachment_id, $size ) {
    $immich_id = get_post_meta( $attachment_id, '_immich_asset_id', true );
    if ( ! $immich_id ) {
        return $downsize;
    }

    $meta   = wp_get_attachment_metadata( $attachment_id );
    $width  = $meta['width'] ?? 0;
    $height = $meta['height'] ?? 0;

    if ( 'full' === $size ) {
        return array(
            home_url( '/?immich_media_proxy=original&id=' . $immich_id ),
            $width,
            $height,
            false,
        );
    }

    // All non-full sizes use the Immich thumbnail endpoint.
    return array(
        home_url( '/?immich_media_proxy=thumbnail&id=' . $immich_id ),
        250,
        250,
        true,
    );
}
```

**Step 4: Verify manually**

Use WP-CLI to check a proxied attachment's URL:
```
make cli CMD="post meta get <attachment_id> _immich_asset_id"
```
Then check the attachment URL in the media library — it should show the proxy URL, not a broken local file path.

**Step 5: Commit**

```
git add immich-media-picker.php
git commit -m "Add URL generation hooks for proxied Immich attachments"
```

---

### Task 5: Rename UI Labels — "Use" and "Copy"

Rename the existing "Import" button to "Copy" and add a "Use" button.

**Files:**
- Modify: `assets/js/immich-media-picker.js:17-22` (events)
- Modify: `assets/js/immich-media-picker.js:36-53` (render)
- Modify: `assets/js/immich-media-picker.js:230-235` (updateImportButton)
- Modify: `assets/js/immich-media-picker.js:237-291` (onImportClick)

**Step 1: Update the render method toolbar HTML**

In `render()` (line 41), replace the single import button with two buttons:

```javascript
'<button type="button" class="button button-primary immich-use-btn" disabled>Use Selected</button>' +
'<button type="button" class="button immich-copy-btn" disabled>Copy Selected</button>' +
```

**Step 2: Update events hash**

Replace `'click .immich-import-btn': 'onImportClick'` with:

```javascript
'click .immich-use-btn': 'onUseClick',
'click .immich-copy-btn': 'onCopyClick',
```

**Step 3: Rename `updateImportButton` to `updateButtons`**

Update the method to handle both buttons:

```javascript
updateButtons: function () {
    var count = Object.keys(this.selected).length;
    this.$('.immich-use-btn').prop('disabled', count === 0)
        .text(count > 1 ? 'Use ' + count + ' Selected' : 'Use Selected');
    this.$('.immich-copy-btn').prop('disabled', count === 0)
        .text(count > 1 ? 'Copy ' + count + ' Selected' : 'Copy Selected');
},
```

Update all calls to `updateImportButton` → `updateButtons` (in `onThumbClick`, `doSearch`, `renderGrid`, `checkComplete`).

**Step 4: Add `onUseClick` method**

This is the "Use" (proxy) action — calls `immich_use` AJAX endpoint:

```javascript
onUseClick: function () {
    var self = this;
    var ids = Object.keys(this.selected);
    if ( ! ids.length ) return;

    var $btn = this.$('.immich-use-btn');
    this.$('.immich-use-btn, .immich-copy-btn').prop('disabled', true);
    $btn.text('Adding...');

    var succeeded = 0;
    var failed = 0;
    var completed = 0;
    var total = ids.length;

    function useNext(index) {
        if ( index >= ids.length ) {
            self.onActionComplete(succeeded, failed, total);
            return;
        }

        $btn.text('Adding ' + (index + 1) + ' of ' + total + '...');

        $.ajax({
            url: config.ajaxUrl,
            method: 'POST',
            data: {
                action: 'immich_use',
                nonce: config.nonce,
                id: ids[index],
            },
            dataType: 'json',
            success: function (resp) {
                completed++;
                if ( resp.success && resp.data && resp.data.attachmentId ) {
                    succeeded++;
                    var attachment = wp.media.attachment(resp.data.attachmentId);
                    attachment.fetch().then(function () {
                        if ( self.controller.state().get('selection') ) {
                            self.controller.state().get('selection').add(attachment);
                        }
                    });
                } else {
                    failed++;
                }
                useNext(index + 1);
            },
            error: function () {
                completed++;
                failed++;
                useNext(index + 1);
            },
        });
    }

    useNext(0);
},
```

**Step 5: Rename `onImportClick` to `onCopyClick`**

Rename the existing method. Update the AJAX action inside it from `immich_import` to `immich_import` (no change to AJAX action name — keep backend compatible). Update button references from `.immich-import-btn` to `.immich-copy-btn` and text from "Importing" to "Copying":

```javascript
onCopyClick: function () {
    var self = this;
    var ids = Object.keys(this.selected);
    if ( ! ids.length ) return;

    var $btn = this.$('.immich-copy-btn');
    this.$('.immich-use-btn, .immich-copy-btn').prop('disabled', true);
    $btn.text('Copying...');

    var succeeded = 0;
    var failed = 0;
    var completed = 0;
    var total = ids.length;

    function copyNext(index) {
        if ( index >= ids.length ) {
            self.onActionComplete(succeeded, failed, total);
            return;
        }

        $btn.text('Copying ' + (index + 1) + ' of ' + total + '...');

        $.ajax({
            url: config.ajaxUrl,
            method: 'POST',
            data: {
                action: 'immich_import',
                nonce: config.nonce,
                id: ids[index],
            },
            dataType: 'json',
            success: function (resp) {
                completed++;
                if ( resp.success && resp.data && resp.data.attachmentId ) {
                    succeeded++;
                    var attachment = wp.media.attachment(resp.data.attachmentId);
                    attachment.fetch().then(function () {
                        if ( self.controller.state().get('selection') ) {
                            self.controller.state().get('selection').add(attachment);
                        }
                    });
                } else {
                    failed++;
                }
                copyNext(index + 1);
            },
            error: function () {
                completed++;
                failed++;
                copyNext(index + 1);
            },
        });
    }

    copyNext(0);
},
```

**Step 6: Extract shared completion logic**

Replace `checkComplete` with `onActionComplete`:

```javascript
onActionComplete: function (succeeded, failed, total) {
    this.$('.immich-use-btn, .immich-copy-btn').prop('disabled', false);
    this.updateButtons();

    if ( failed > 0 ) {
        this.$('.immich-status-text').text(succeeded + ' added, ' + failed + ' failed.');
    } else {
        this.$('.immich-status-text').text(succeeded + ' photo(s) added.');
    }

    this.selected = {};
    this.$('.immich-thumb').removeClass('selected');
    this.updateButtons();

    if ( succeeded > 0 ) {
        var library = this.controller.state().get('library');
        if ( library ) {
            library._requery( true );
        }
        this.loadUsedAssets();
    }
},
```

**Step 7: Commit**

```
git add assets/js/immich-media-picker.js
git commit -m "Rename Import to Use/Copy with separate actions"
```

---

### Task 6: "Previously Added" Section

Add the AJAX endpoint and UI section showing already-used Immich assets below search results.

**Files:**
- Modify: `immich-media-picker.php` (constructor + new AJAX method)
- Modify: `assets/js/immich-media-picker.js` (render + new methods)
- Modify: `assets/css/immich-media-picker.css` (divider + used grid styles)

**Step 1: Register AJAX action in PHP constructor**

```php
add_action( 'wp_ajax_immich_used_assets', array( $this, 'ajax_used_assets' ) );
```

**Step 2: Add `ajax_used_assets()` PHP method**

```php
public function ajax_used_assets(): void {
    if ( ! $this->verify_ajax_request() ) {
        return;
    }

    $page     = absint( $_GET['page'] ?? 1 ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- verified in verify_ajax_request()
    $page     = max( 1, $page );
    $per_page = 50;

    $query = new \WP_Query( array(
        'post_type'      => 'attachment',
        'post_status'    => 'inherit',
        'posts_per_page' => $per_page,
        'paged'          => $page,
        'meta_key'       => '_immich_asset_id',
        'meta_compare'   => 'EXISTS',
        'orderby'        => 'date',
        'order'          => 'DESC',
    ) );

    $items = array();
    foreach ( $query->posts as $post ) {
        $immich_id = get_post_meta( $post->ID, '_immich_asset_id', true );
        $items[]   = array(
            'attachmentId' => $post->ID,
            'immichId'     => $immich_id,
            'title'        => $post->post_title,
            'thumbUrl'     => home_url( '/?immich_media_proxy=thumbnail&id=' . $immich_id ),
        );
    }

    $has_more = ( $page * $per_page ) < $query->found_posts;

    wp_send_json_success( array(
        'items'    => $items,
        'nextPage' => $has_more ? $page + 1 : null,
        'total'    => $query->found_posts,
    ) );
}
```

**Step 3: Update JS render to include the used section**

In the `render()` method, add after the `.immich-grid` div:

```javascript
'<div class="immich-used-divider" style="display:none;"><span>Previously added</span></div>' +
'<div class="immich-used-grid"></div>'
```

Place these before the `.immich-status` div.

**Step 4: Add JS methods for loading/displaying used assets**

Add to the ImmichBrowser view:

```javascript
loadUsedAssets: function () {
    this.usedPage = 1;
    this.usedNextPage = null;
    this.$('.immich-used-grid').empty();
    this.fetchUsedPage(1);
},

fetchUsedPage: function (page) {
    var self = this;

    $.ajax({
        url: config.ajaxUrl,
        method: 'GET',
        data: {
            action: 'immich_used_assets',
            nonce: config.nonce,
            page: page,
        },
        dataType: 'json',
        success: function (resp) {
            if ( ! resp.success ) return;

            var items = resp.data.items || [];
            self.usedNextPage = resp.data.nextPage || null;

            if ( items.length > 0 || page > 1 ) {
                self.$('.immich-used-divider').show();
            }

            items.forEach(function (item) {
                var $thumb = $(
                    '<div class="immich-thumb immich-used-thumb" data-attachment-id="' + _.escape(item.attachmentId) + '">' +
                        '<img src="' + _.escape(item.thumbUrl) + '" alt="' + _.escape(item.title) + '" />' +
                    '</div>'
                );
                self.$('.immich-used-grid').append($thumb);
            });
        },
    });
},
```

**Step 5: Call `loadUsedAssets` on initialize**

In `initialize()`, after `this.loadPeople()`, add:

```javascript
this.loadUsedAssets();
```

**Step 6: Handle clicks on used assets**

Add to the events hash:

```javascript
'click .immich-used-thumb': 'onUsedThumbClick',
```

Add the handler:

```javascript
onUsedThumbClick: function (e) {
    var $thumb = $(e.currentTarget);
    var attachmentId = $thumb.data('attachment-id');

    var attachment = wp.media.attachment(attachmentId);
    attachment.fetch().then(function () {
        if ( this.controller.state().get('selection') ) {
            this.controller.state().get('selection').add(attachment);
        }
    }.bind(this));
},
```

**Step 7: Add infinite scroll for used grid**

Wire up scroll on `.immich-used-grid` to load more pages, similar to the search grid scroll handler.

In `render()`, add after the existing grid scroll binding:

```javascript
this.$('.immich-used-grid').on('scroll', function () {
    self.onUsedGridScroll();
});
```

Add the handler:

```javascript
onUsedGridScroll: function () {
    if ( ! this.usedNextPage ) return;

    var $grid = this.$('.immich-used-grid');
    var scrollTop = $grid.scrollTop();
    var scrollHeight = $grid[0].scrollHeight;
    var clientHeight = $grid[0].clientHeight;

    if ( scrollTop + clientHeight >= scrollHeight - 200 ) {
        this.fetchUsedPage(this.usedNextPage);
    }
},
```

**Step 8: Add CSS for the divider and used grid**

Append to `assets/css/immich-media-picker.css`:

```css
.immich-used-divider {
    display: flex;
    align-items: center;
    padding: 8px 16px;
    color: #666;
    font-size: 12px;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    border-top: 1px solid #ddd;
}

.immich-used-divider span {
    white-space: nowrap;
}

.immich-used-grid {
    display: flex;
    flex-wrap: wrap;
    gap: 8px;
    padding: 16px;
    overflow-y: auto;
    flex: 1;
    min-height: 0;
    align-content: flex-start;
}

.immich-used-thumb {
    opacity: 0.85;
}

.immich-used-thumb:hover {
    opacity: 1;
}
```

**Step 9: Commit**

```
git add immich-media-picker.php assets/js/immich-media-picker.js assets/css/immich-media-picker.css
git commit -m "Add Previously Added section showing already-used Immich assets"
```

---

### Task 7: Plugin Description Update

Update the plugin header description since the plugin now does more than import.

**Files:**
- Modify: `immich-media-picker.php:4`

**Step 1: Update plugin description**

Change line 4 from:
```
 * Description: Import photos from your Immich server into the WordPress media library.
```
to:
```
 * Description: Use photos and videos from your Immich server in WordPress without copying files, or import them into the media library.
```

**Step 2: Commit**

```
git add immich-media-picker.php
git commit -m "Update plugin description to reflect Use and Copy modes"
```
