# Immich Album Gallery Block — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Build the dynamic Gutenberg block `immich/album-gallery` per the spec at `docs/superpowers/specs/2026-05-08-immich-album-block-design.md`, resolving GitHub issue [#7](https://github.com/donnchawp/media-picker-for-immich/issues/7).

**Architecture:** Server-rendered Gutenberg block (`apiVersion: 3`) that emits core Gallery markup. Block-driven picker reuses the existing Immich tab in an "albums-only" mode triggered by `library.type === 'immich-album'`. Hybrid caching (5-min transient on the album list per sort variant + on-disk thumb cache via the existing proxy + editor refresh flag). No build step — vanilla `wp.element.createElement` for the block JS.

**Tech Stack:** PHP 8.0+, WordPress 6.4+ (Interactivity-API lightbox), vanilla JS using `wp.blocks` / `wp.blockEditor` / `wp.components` / `wp.element` / `wp.serverSideRender` / `wp.i18n` globals.

**Verification:** No automated tests in this repo; verification is manual at `http://localhost:8888` (admin/password) on wp-env (`make start`). Each task ends with concrete manual verification steps.

**Branch:** `feat/issue-7-album-gallery-block` off `main`. Squash-merge with `--delete-branch` per the repo's PR convention.

---

## Pre-flight (do once before Task 1)

- [ ] **Branch off `main`.**

  ```bash
  git checkout main && git pull --ff-only && git checkout -b feat/issue-7-album-gallery-block
  ```

- [ ] **Confirm wp-env is up.**

  ```bash
  make start
  ```

  Visit `http://localhost:8888/wp-admin/`, log in (admin/password). Confirm Settings → Immich is configured (URL + API key) and at least three Immich albums exist on the test server: one ~20 assets, one >100 assets (to exercise the cap), one empty.

---

## Task 1 — Block scaffolding

Goal: A new block called "Immich Album Gallery" appears in the inserter and inserts as a static placeholder. No picker, no render, no behavior yet.

**Files:**
- Create: `includes/block-album-gallery/block.json`
- Create: `includes/block-album-gallery/render.php`
- Create: `assets/js/immich-album-block.js`
- Modify: `media-picker-for-immich.php` (add `register_block_type` call in `init`)

- [ ] **Step 1: Create `includes/block-album-gallery/block.json`.**

  ```json
  {
    "$schema": "https://schemas.wp.org/trunk/block.json",
    "apiVersion": 3,
    "name": "immich/album-gallery",
    "title": "Immich Album Gallery",
    "category": "media",
    "icon": "format-gallery",
    "description": "Embed a live Immich album as a gallery.",
    "textdomain": "media-picker-for-immich",
    "attributes": {
      "albumId":      { "type": "string",  "default": "" },
      "columns":      { "type": "number",  "default": 3 },
      "imageSize":    { "type": "string",  "default": "preview" },
      "sortOrder":    { "type": "string",  "default": "default" },
      "limit":        { "type": "number",  "default": 0 },
      "lightbox":     { "type": "boolean", "default": true },
      "showCaptions": { "type": "boolean", "default": false }
    },
    "supports": {
      "html":  false,
      "align": ["wide", "full"]
    },
    "editorScript": "file:../../assets/js/immich-album-block.js",
    "render":       "file:./render.php"
  }
  ```

- [ ] **Step 2: Create `includes/block-album-gallery/render.php`.**

  ```php
  <?php
  /**
   * Server-side render shim for immich/album-gallery.
   *
   * @package Immich_Media_Picker
   */

  defined( 'ABSPATH' ) || exit;

  echo Immich_Media_Picker::instance()->render_album_block( is_array( $attributes ) ? $attributes : array() );
  ```

- [ ] **Step 3: Add a stub `render_album_block` method to the main plugin class.**

  In `media-picker-for-immich.php`, add at the end of the `Immich_Media_Picker` class body (anywhere before the class closes):

  ```php
  /**
   * Render the immich/album-gallery block.
   *
   * Stub — full implementation in Task 5+.
   *
   * @param array $attrs Block attributes.
   * @return string Rendered HTML.
   */
  public function render_album_block( array $attrs ): string {
      $album_id = isset( $attrs['albumId'] ) ? (string) $attrs['albumId'] : '';
      if ( '' === $album_id || ! preg_match( self::UUID_PATTERN, $album_id ) ) {
          return '';
      }
      return '<p>' . esc_html__( 'Immich album gallery placeholder', 'media-picker-for-immich' ) . ' (id: ' . esc_html( $album_id ) . ')</p>';
  }
  ```

- [ ] **Step 4: Register the block in `init`.**

  Find the existing `init()` method (around the action `add_action( 'init', array( $this, 'handle_proxy_request' ) );` near line 77) and add a new `add_action` near the others, plus a new method:

  In the constructor / hooks setup area, add:

  ```php
  add_action( 'init', array( $this, 'register_blocks' ) );
  ```

  And add the method to the class:

  ```php
  /**
   * Register Gutenberg blocks shipped by this plugin.
   */
  public function register_blocks(): void {
      register_block_type( __DIR__ . '/includes/block-album-gallery' );
  }
  ```

- [ ] **Step 5: Create `assets/js/immich-album-block.js` (placeholder edit only).**

  ```js
  ( function ( blocks, blockEditor, components, element, i18n ) {
      'use strict';
      var registerBlockType = blocks.registerBlockType;
      var useBlockProps     = blockEditor.useBlockProps;
      var Placeholder       = components.Placeholder;
      var el                = element.createElement;
      var __                = i18n.__;

      registerBlockType( 'immich/album-gallery', {
          edit: function ( props ) {
              var blockProps = useBlockProps();
              return el(
                  'div',
                  blockProps,
                  el(
                      Placeholder,
                      {
                          icon: 'format-gallery',
                          label: __( 'Immich Album Gallery', 'media-picker-for-immich' ),
                          instructions: __( 'Pick an album from your Immich server.', 'media-picker-for-immich' )
                      },
                      el( 'p', null, props.attributes.albumId
                          ? __( 'Album:', 'media-picker-for-immich' ) + ' ' + props.attributes.albumId
                          : __( 'No album selected yet.', 'media-picker-for-immich' )
                      )
                  )
              );
          },
          save: function () { return null; }
      } );
  } )( window.wp.blocks, window.wp.blockEditor, window.wp.components, window.wp.element, window.wp.i18n );
  ```

- [ ] **Step 6: Manually verify in the editor.**

  1. Reload `http://localhost:8888/wp-admin/post-new.php`.
  2. Click the `+` inserter; search for "Immich Album Gallery". Block should appear in the Media category.
  3. Insert it. Block should render as a Placeholder with the gallery icon + "No album selected yet."
  4. Save the post (draft is fine), reload — block still inserts cleanly.
  5. View the post on the frontend (Preview link). Frontend should render nothing (since `albumId` is empty and the stub bails).

- [ ] **Step 7: Commit.**

  ```bash
  git add includes/block-album-gallery/ assets/js/immich-album-block.js media-picker-for-immich.php
  git commit -m "Scaffold immich/album-gallery block (#7)"
  ```

---

## Task 2 — Album AJAX endpoint and `preview_proxy_url` helper

Goal: A new authenticated AJAX endpoint that returns the list of Immich albums for the picker. Plus a small helper that produces preview-nonce-signed proxy URLs (needed for album cover thumbs without the cover being a WP attachment).

**Files:**
- Modify: `media-picker-for-immich.php`

- [ ] **Step 1: Register the new AJAX action.**

  Near the other `add_action( 'wp_ajax_immich_*', ... );` lines (around line 65–73), add:

  ```php
  add_action( 'wp_ajax_immich_albums', array( $this, 'ajax_albums' ) );
  ```

- [ ] **Step 2: Add the `preview_proxy_url` helper to the class.**

  Pick a sensible spot — alongside `handle_proxy_request` (~line 544) is fine, since it's a peer concept.

  ```php
  /**
   * Build a preview-nonce-signed proxy URL for an asset.
   *
   * Lets logged-in users with upload_files capability fetch a thumbnail
   * for an asset that is not (yet) a WordPress attachment. Used by the
   * album picker to render album cover thumbnails.
   *
   * @param string $asset_id Asset UUID. Empty string returns ''.
   * @param string $size     'thumbnail', 'preview', 'fullsize', or 'video'.
   * @return string URL, or '' if asset_id is empty/invalid.
   */
  private function preview_proxy_url( string $asset_id, string $size = 'thumbnail' ): string {
      if ( '' === $asset_id || ! preg_match( self::UUID_PATTERN, $asset_id ) ) {
          return '';
      }
      $allowed = array( 'thumbnail', 'preview', 'fullsize', 'video' );
      if ( ! in_array( $size, $allowed, true ) ) {
          $size = 'thumbnail';
      }
      $args = array(
          'immich_media_proxy' => $size,
          'id'                 => $asset_id,
          'preview_nonce'      => wp_create_nonce( 'immich_preview' ),
      );
      return add_query_arg( $args, home_url( '/' ) );
  }
  ```

- [ ] **Step 3: Add the `ajax_albums` method to the class.**

  Place it near the other `ajax_*` methods (e.g., after `ajax_people` ~line 1498):

  ```php
  /**
   * AJAX: list Immich albums for the picker.
   */
  public function ajax_albums(): void {
      if ( ! $this->verify_ajax_request() ) {
          return;
      }
      $response = $this->api_request( '/api/albums' );
      if ( is_wp_error( $response ) ) {
          wp_send_json_error(
              array(
                  'message' => $response->get_error_message(),
                  'code'    => $response->get_error_code(),
              )
          );
      }
      $items = array_map(
          function ( $a ) {
              return array(
                  'id'        => isset( $a['id'] ) ? (string) $a['id'] : '',
                  'name'      => isset( $a['albumName'] ) ? (string) $a['albumName'] : '',
                  'count'     => isset( $a['assetCount'] ) ? (int) $a['assetCount'] : 0,
                  'thumbnail' => $this->preview_proxy_url( isset( $a['albumThumbnailAssetId'] ) ? (string) $a['albumThumbnailAssetId'] : '', 'thumbnail' ),
              );
          },
          (array) $response
      );
      // Filter out malformed entries (no id).
      $items = array_values( array_filter( $items, function ( $i ) { return '' !== $i['id']; } ) );
      wp_send_json_success( array( 'items' => $items ) );
  }
  ```

- [ ] **Step 4: Manually verify with the browser console.**

  1. From any WP admin page, open DevTools → Console.
  2. Run:

     ```js
     fetch( ajaxurl, {
         method: 'POST',
         credentials: 'same-origin',
         headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
         body: 'action=immich_albums&_ajax_nonce=' + encodeURIComponent( window.ImmichMediaPicker.nonce )
     } ).then( r => r.json() ).then( console.log );
     ```

     (`ImmichMediaPicker.nonce` is localized into any page that enqueues the picker, e.g. `post-new.php`.)

  3. Expected: `{ success: true, data: { items: [ { id, name, count, thumbnail }, ... ] } }`.
  4. Fetch the `thumbnail` URL of the first item in a new tab — should return a JPEG.
  5. Repeat without `_ajax_nonce` → should fail (`-1` or `0`).

- [ ] **Step 5: Commit.**

  ```bash
  git add media-picker-for-immich.php
  git commit -m "Add ajax_albums endpoint and preview_proxy_url helper (#7)"
  ```

---

## Task 3 — Picker albums-only mode

Goal: When `wp.media({ library: { type: 'immich-album' } })` is called, the existing Immich tab renders an Albums-only grid (no People dropdown, no live/cached split, no per-asset Insert button). Clicking a tile fires a `immich:album-selected` event on the frame.

**Files:**
- Modify: `assets/js/media-picker-for-immich.js`
- Modify: `assets/css/media-picker-for-immich.css`

- [ ] **Step 1: Find the type-detection code added by PR #11.**

  Search `assets/js/media-picker-for-immich.js` for `_readAllowedTypes` or the `props.get('type')` read. Most likely at the start of the View's render or in `_runAction`. Note its location — Task 3.2 modifies the same area.

- [ ] **Step 2: Add an `isAlbumMode` flag and short-circuit render.**

  Inside the View definition (the object passed to `wp.media.View.extend({...})`), add a method `_isAlbumMode()` and call it early in `render`:

  ```js
  _isAlbumMode: function () {
      try {
          var lib = this.controller && this.controller.state() && this.controller.state().get( 'library' );
          if ( ! lib ) { return false; }
          return lib.props.get( 'type' ) === 'immich-album';
      } catch ( e ) { return false; }
  },
  ```

  In `render` (or wherever the toolbar+grid+used-pane are constructed), at the very top:

  ```js
  if ( this._isAlbumMode() ) {
      this.$el.empty().addClass( 'immich-browser is-album-mode' );
      this._renderAlbumGrid();
      return this;
  }
  // ...existing render path unchanged
  ```

- [ ] **Step 3: Implement `_renderAlbumGrid`.**

  Add this method to the View:

  ```js
  _renderAlbumGrid: function () {
      var self = this;
      this.$el.append(
          '<div class="immich-status">' + _.escape( wp.i18n.__( 'Loading albums...', 'media-picker-for-immich' ) ) + '</div>' +
          '<div class="immich-album-grid" role="list"></div>'
      );
      var $status = this.$el.find( '.immich-status' );
      var $grid   = this.$el.find( '.immich-album-grid' );

      wp.ajax.post( 'immich_albums', { _ajax_nonce: window.ImmichMediaPicker.nonce } )
          .done( function ( data ) {
              $status.hide();
              if ( ! data || ! data.items || ! data.items.length ) {
                  $grid.html( '<p class="immich-empty">' + _.escape( wp.i18n.__( 'No albums in this Immich server.', 'media-picker-for-immich' ) ) + '</p>' );
                  return;
              }
              var html = data.items.map( function ( a ) {
                  return '<button type="button" class="immich-album-tile" role="listitem"' +
                      ' data-album-id="' + _.escape( a.id ) + '"' +
                      ' data-album-name="' + _.escape( a.name ) + '">' +
                      ( a.thumbnail
                          ? '<img class="immich-album-tile-cover" src="' + _.escape( a.thumbnail ) + '" alt="" />'
                          : '<span class="immich-album-tile-cover is-empty"></span>' ) +
                      '<span class="immich-album-tile-name">' + _.escape( a.name ) + '</span>' +
                      '<span class="immich-album-tile-count">' + _.escape( String( a.count ) ) + '</span>' +
                      '</button>';
              } ).join( '' );
              $grid.html( html );
          } )
          .fail( function ( resp ) {
              $status.text( ( resp && resp.message ) ? resp.message : wp.i18n.__( 'Could not load albums.', 'media-picker-for-immich' ) );
          } );

      this.$el.off( 'click.immichAlbums' ).on( 'click.immichAlbums', '.immich-album-tile', function () {
          var $btn = jQuery( this );
          self.controller.trigger( 'immich:album-selected', {
              id:   $btn.attr( 'data-album-id' ),
              name: $btn.attr( 'data-album-name' )
          } );
      } );
  },
  ```

- [ ] **Step 4: Make sure type-filter forwarding ignores `immich-album`.**

  Find `_immichRequestType` (added by PR #15). It currently maps `IMAGE`/`VIDEO` for the `assetType` payload. Confirm it reads `library.props.get('type')` and only matches `'image'` and `'video'`. If it has any branch matching arbitrary truthy strings, add an explicit guard:

  ```js
  if ( type === 'immich-album' ) { return null; }
  ```

  (If the existing function already returns null for unknown values, no change is needed — verify by reading it.)

- [ ] **Step 5: Add CSS for the album grid and tiles.**

  In `assets/css/media-picker-for-immich.css`, append:

  ```css
  .immich-browser.is-album-mode .immich-toolbar,
  .immich-browser.is-album-mode .immich-used-pane,
  .immich-browser.is-album-mode .immich-split-handle {
      display: none;
  }
  .immich-album-grid {
      display: grid;
      grid-template-columns: repeat( auto-fill, minmax( 160px, 1fr ) );
      gap: 12px;
      padding: 12px;
      align-content: start;
  }
  .immich-album-tile {
      display: flex;
      flex-direction: column;
      gap: 4px;
      cursor: pointer;
      background: none;
      border: 1px solid transparent;
      padding: 6px;
      border-radius: 4px;
      text-align: left;
  }
  .immich-album-tile:hover,
  .immich-album-tile:focus-visible {
      border-color: var( --wp-admin-theme-color, #2271b1 );
      outline: none;
  }
  .immich-album-tile-cover {
      width: 100%;
      aspect-ratio: 1 / 1;
      object-fit: cover;
      border-radius: 4px;
      display: block;
      background: #e0e0e0;
  }
  .immich-album-tile-cover.is-empty { background: #d0d0d0; }
  .immich-album-tile-name { font-weight: 600; line-height: 1.3; }
  .immich-album-tile-count { color: #757575; font-size: 12px; }
  ```

- [ ] **Step 6: Manually verify by triggering from the browser console.**

  1. Open `http://localhost:8888/wp-admin/post-new.php`.
  2. DevTools console:

     ```js
     var f = wp.media( { library: { type: 'immich-album' }, multiple: false } );
     f.on( 'immich:album-selected', function ( a ) { console.log( 'picked', a ); } );
     f.open();
     ```

  3. Frame opens. Click the Immich tab. Albums grid renders. People dropdown / live grid / cached split are all hidden.
  4. Click an album tile → console logs `picked {id, name}`.
  5. Close the frame; reopen with `wp.media()` (no library type) → existing picker unchanged.
  6. Open from an Image block → existing picker unchanged.

- [ ] **Step 7: Commit.**

  ```bash
  git add assets/js/media-picker-for-immich.js assets/css/media-picker-for-immich.css
  git commit -m "Picker: albums-only mode for the Immich tab (#7)"
  ```

---

## Task 4 — Wire the block to the picker

Goal: The block's "Pick album" button opens the picker, the user picks an album, and `albumId` is saved on the block.

**Files:**
- Modify: `assets/js/immich-album-block.js`

- [ ] **Step 1: Replace the block edit component with one that opens the picker.**

  Overwrite `assets/js/immich-album-block.js`:

  ```js
  ( function ( blocks, blockEditor, components, element, i18n ) {
      'use strict';
      var registerBlockType = blocks.registerBlockType;
      var useBlockProps     = blockEditor.useBlockProps;
      var Placeholder       = components.Placeholder;
      var Button            = components.Button;
      var Fragment          = element.Fragment;
      var el                = element.createElement;
      var __                = i18n.__;

      function openAlbumPicker( onPicked ) {
          var frame = wp.media( {
              title:    __( 'Choose an Immich album', 'media-picker-for-immich' ),
              library:  { type: 'immich-album' },
              button:   { text: __( 'Insert album', 'media-picker-for-immich' ) },
              multiple: false
          } );
          frame.on( 'immich:album-selected', function ( album ) {
              if ( album && album.id ) {
                  onPicked( album );
              }
              frame.close();
          } );
          frame.open();
      }

      registerBlockType( 'immich/album-gallery', {
          edit: function ( props ) {
              var blockProps = useBlockProps();
              var attrs      = props.attributes;
              var setAttrs   = props.setAttributes;

              function pick() {
                  openAlbumPicker( function ( album ) {
                      setAttrs( { albumId: album.id } );
                  } );
              }

              var label = attrs.albumId
                  ? __( 'Change album', 'media-picker-for-immich' )
                  : __( 'Pick album', 'media-picker-for-immich' );

              var body = el(
                  Placeholder,
                  {
                      icon: 'format-gallery',
                      label: __( 'Immich Album Gallery', 'media-picker-for-immich' ),
                      instructions: attrs.albumId
                          ? __( 'Album:', 'media-picker-for-immich' ) + ' ' + attrs.albumId
                          : __( 'Pick an album from your Immich server.', 'media-picker-for-immich' )
                  },
                  el( Button, { variant: 'primary', onClick: pick }, label )
              );

              return el( Fragment, null, el( 'div', blockProps, body ) );
          },
          save: function () { return null; }
      } );
  } )( window.wp.blocks, window.wp.blockEditor, window.wp.components, window.wp.element, window.wp.i18n );
  ```

- [ ] **Step 2: Manually verify.**

  1. New post → insert Immich Album Gallery block.
  2. Click "Pick album" → media frame opens with the Immich tab and Albums grid.
  3. Pick an album → frame closes, block instructions update to "Album: <uuid>".
  4. Save the post; reload — `albumId` persists; block placeholder still shows the ID.
  5. Click "Change album" → picker reopens; pick a different album → block updates.
  6. View the post anonymously → still nothing (render still returns the stub placeholder for editors only via `current_user_can`-ish; we render an empty string for visitors via the early bail. **Actually, the current stub returns an HTML string for any valid `albumId`** — confirm visitors see "Immich album gallery placeholder (id: …)". This will be replaced in Task 5.

- [ ] **Step 3: Commit.**

  ```bash
  git add assets/js/immich-album-block.js
  git commit -m "Wire album block to the picker (#7)"
  ```

---

## Task 5 — Render callback (happy path)

Goal: The published page renders an actual gallery for an album. No caching, no sort, no limit, no error handling, no cap. Just fetch the album, emit core Gallery markup with proxy URLs, return.

**Files:**
- Modify: `media-picker-for-immich.php`

- [ ] **Step 1: Replace the stub `render_album_block` with a working implementation.**

  Replace the body of `render_album_block` from Task 1.3 with:

  ```php
  /**
   * Render the immich/album-gallery block.
   *
   * @param array $attrs Block attributes.
   * @return string Rendered HTML.
   */
  public function render_album_block( array $attrs ): string {
      $album_id = isset( $attrs['albumId'] ) ? (string) $attrs['albumId'] : '';
      if ( '' === $album_id || ! preg_match( self::UUID_PATTERN, $album_id ) ) {
          return '';
      }

      $payload = $this->fetch_album_assets( $album_id );
      if ( is_wp_error( $payload ) ) {
          return '';
      }

      $assets = $payload['assets'];
      if ( empty( $assets ) ) {
          return '';
      }

      $size    = $this->validate_image_size( isset( $attrs['imageSize'] ) ? (string) $attrs['imageSize'] : 'preview' );
      $columns = max( 1, min( 8, (int) ( $attrs['columns'] ?? 3 ) ) );

      $children = '';
      foreach ( $assets as $a ) {
          $url      = home_url( '/?immich_media_proxy=' . rawurlencode( $size ) . '&id=' . rawurlencode( $a['id'] ) );
          $alt      = isset( $a['originalFileName'] ) ? (string) $a['originalFileName'] : '';
          $children .= '<figure class="wp-block-image size-large">'
              . '<img src="' . esc_url( $url ) . '" alt="' . esc_attr( $alt ) . '" loading="lazy" />'
              . '</figure>';
      }

      return '<figure class="wp-block-gallery has-nested-images columns-' . (int) $columns . ' is-layout-flex wp-block-gallery-is-layout-flex">'
          . $children
          . '</figure>';
  }

  /**
   * Validate the imageSize block attribute.
   *
   * @param string $size Raw attribute value.
   * @return string One of 'thumbnail', 'preview', 'fullsize'.
   */
  private function validate_image_size( string $size ): string {
      $allowed = array( 'thumbnail', 'preview', 'fullsize' );
      return in_array( $size, $allowed, true ) ? $size : 'preview';
  }

  /**
   * Fetch and prepare the asset list for an album.
   *
   * No caching yet — see Task 7. No sort yet — see Task 8.
   *
   * @param string $album_id Validated UUID.
   * @return array{assets: array, total_count: int, fetched_at: int}|\WP_Error
   */
  private function fetch_album_assets( string $album_id ) {
      $response = $this->api_request( '/api/albums/' . rawurlencode( $album_id ) );
      if ( is_wp_error( $response ) ) {
          return $response;
      }
      $assets = isset( $response['assets'] ) && is_array( $response['assets'] ) ? $response['assets'] : array();
      return array(
          'assets'      => array_values( $assets ),
          'total_count' => count( $assets ),
          'fetched_at'  => time(),
      );
  }
  ```

- [ ] **Step 2: Manually verify on the frontend.**

  1. Reload the post that has the block (or create a new one with an album picked).
  2. View the published post anonymously (private/incognito).
  3. DOM: `<figure class="wp-block-gallery has-nested-images columns-3 is-layout-flex wp-block-gallery-is-layout-flex">` containing N `<figure class="wp-block-image size-large">` children with `<img src="?immich_media_proxy=preview&id=...">`.
  4. Images load (Network: 200s, JPEG content-type).
  5. Empty album → block renders nothing.
  6. Block with no albumId → renders nothing.

- [ ] **Step 3: Commit.**

  ```bash
  git add media-picker-for-immich.php
  git commit -m "Render album block as core gallery markup (#7)"
  ```

---

## Task 6 — ServerSideRender + InspectorControls

Goal: The block in the editor shows a live preview of the rendered gallery (via `<ServerSideRender>`), and the InspectorControls sidebar exposes the full attribute set. The behaviors implemented so far (columns, imageSize) become tunable; the rest are wired up but their server-side effects are added in later tasks.

**Files:**
- Modify: `assets/js/immich-album-block.js`

- [ ] **Step 1: Replace the block edit component.**

  Overwrite `assets/js/immich-album-block.js`:

  ```js
  ( function ( blocks, blockEditor, components, element, ssr, i18n ) {
      'use strict';
      var registerBlockType = blocks.registerBlockType;
      var useBlockProps     = blockEditor.useBlockProps;
      var InspectorControls = blockEditor.InspectorControls;
      var Placeholder       = components.Placeholder;
      var Button            = components.Button;
      var PanelBody         = components.PanelBody;
      var RangeControl      = components.RangeControl;
      var SelectControl     = components.SelectControl;
      var ToggleControl     = components.ToggleControl;
      var Fragment          = element.Fragment;
      var el                = element.createElement;
      var ServerSideRender  = ssr;
      var __                = i18n.__;

      function openAlbumPicker( onPicked ) {
          var frame = wp.media( {
              title:    __( 'Choose an Immich album', 'media-picker-for-immich' ),
              library:  { type: 'immich-album' },
              button:   { text: __( 'Insert album', 'media-picker-for-immich' ) },
              multiple: false
          } );
          frame.on( 'immich:album-selected', function ( album ) {
              if ( album && album.id ) { onPicked( album ); }
              frame.close();
          } );
          frame.open();
      }

      function buildInspector( attrs, setAttrs, onPick ) {
          return el(
              InspectorControls,
              null,
              el(
                  PanelBody,
                  { title: __( 'Album', 'media-picker-for-immich' ), initialOpen: true },
                  el( Button, { variant: 'secondary', onClick: onPick },
                      attrs.albumId
                          ? __( 'Change album', 'media-picker-for-immich' )
                          : __( 'Pick album', 'media-picker-for-immich' ) ),
                  attrs.albumId
                      ? el( 'p', { style: { fontSize: '12px', color: '#757575', marginTop: '8px' } },
                          __( 'Album ID:', 'media-picker-for-immich' ) + ' ' + attrs.albumId )
                      : null
              ),
              el(
                  PanelBody,
                  { title: __( 'Layout', 'media-picker-for-immich' ), initialOpen: true },
                  el( RangeControl, {
                      label: __( 'Columns', 'media-picker-for-immich' ),
                      min: 1, max: 8, value: attrs.columns,
                      onChange: function ( v ) { setAttrs( { columns: v } ); }
                  } ),
                  el( SelectControl, {
                      label: __( 'Image size', 'media-picker-for-immich' ),
                      value: attrs.imageSize,
                      options: [
                          { label: __( 'Thumbnail (~250 px)', 'media-picker-for-immich' ), value: 'thumbnail' },
                          { label: __( 'Preview (~1440 px)', 'media-picker-for-immich' ), value: 'preview' },
                          { label: __( 'Full size', 'media-picker-for-immich' ), value: 'fullsize' }
                      ],
                      onChange: function ( v ) { setAttrs( { imageSize: v } ); }
                  } ),
                  el( SelectControl, {
                      label: __( 'Sort order', 'media-picker-for-immich' ),
                      value: attrs.sortOrder,
                      options: [
                          { label: __( 'Album order', 'media-picker-for-immich' ), value: 'default' },
                          { label: __( 'Oldest first', 'media-picker-for-immich' ), value: 'oldest' },
                          { label: __( 'Newest first', 'media-picker-for-immich' ), value: 'newest' },
                          { label: __( 'Random', 'media-picker-for-immich' ), value: 'random' }
                      ],
                      onChange: function ( v ) { setAttrs( { sortOrder: v } ); }
                  } ),
                  el( RangeControl, {
                      label: __( 'Limit (0 = all)', 'media-picker-for-immich' ),
                      min: 0, max: 200, value: attrs.limit,
                      onChange: function ( v ) { setAttrs( { limit: v } ); }
                  } )
              ),
              el(
                  PanelBody,
                  { title: __( 'Display', 'media-picker-for-immich' ), initialOpen: false },
                  el( ToggleControl, {
                      label: __( 'Lightbox', 'media-picker-for-immich' ),
                      checked: attrs.lightbox,
                      onChange: function ( v ) { setAttrs( { lightbox: v } ); }
                  } ),
                  el( ToggleControl, {
                      label: __( 'Show captions', 'media-picker-for-immich' ),
                      checked: attrs.showCaptions,
                      onChange: function ( v ) { setAttrs( { showCaptions: v } ); }
                  } )
              )
          );
      }

      registerBlockType( 'immich/album-gallery', {
          edit: function ( props ) {
              var blockProps = useBlockProps();
              var attrs      = props.attributes;
              var setAttrs   = props.setAttributes;

              function pick() {
                  openAlbumPicker( function ( album ) {
                      setAttrs( { albumId: album.id } );
                  } );
              }

              var inspector = buildInspector( attrs, setAttrs, pick );

              var body = attrs.albumId
                  ? el( ServerSideRender, {
                      block: 'immich/album-gallery',
                      attributes: attrs
                  } )
                  : el( Placeholder, {
                      icon: 'format-gallery',
                      label: __( 'Immich Album Gallery', 'media-picker-for-immich' ),
                      instructions: __( 'Pick an album from your Immich server.', 'media-picker-for-immich' )
                  }, el( Button, { variant: 'primary', onClick: pick }, __( 'Pick album', 'media-picker-for-immich' ) ) );

              return el( Fragment, null, inspector, el( 'div', blockProps, body ) );
          },
          save: function () { return null; }
      } );
  } )( window.wp.blocks, window.wp.blockEditor, window.wp.components, window.wp.element, window.wp.serverSideRender, window.wp.i18n );
  ```

- [ ] **Step 2: Manually verify in the editor.**

  1. Edit a post with the block. Sidebar shows three panels: Album, Layout, Display.
  2. Block body shows a live preview of the gallery (via ServerSideRender).
  3. Change Columns 3→6 → preview re-renders with 6 columns.
  4. Change Image size to Thumbnail → preview re-renders with smaller images.
  5. Sort order changes don't visibly affect anything yet (no behavior — Task 8). That's expected.
  6. Toggle controls flip cleanly; values persist after save+reload.
  7. Block without `albumId` → still shows the Placeholder + Pick button.

- [ ] **Step 3: Commit.**

  ```bash
  git add assets/js/immich-album-block.js
  git commit -m "Block: ServerSideRender preview + InspectorControls (#7)"
  ```

---

## Task 7 — Caching layer + hard cap

Goal: Album fetches are cached for 5 min by default (filterable). Asset list is capped to 100 by default (filterable). Cache key includes a placeholder for sort order (Task 8 will populate the variants); for now use `default`.

**Files:**
- Modify: `media-picker-for-immich.php`

- [ ] **Step 1: Add the TTL and cap helper methods.**

  Place near `fetch_album_assets`:

  ```php
  /**
   * Default transient TTL for album asset lists, in seconds.
   *
   * @return int Filterable via `immich_album_cache_ttl`.
   */
  private function album_cache_ttl(): int {
      return (int) apply_filters( 'immich_album_cache_ttl', 5 * MINUTE_IN_SECONDS );
  }

  /**
   * Hard cap on rendered assets per block.
   *
   * @return int Filterable via `immich_album_max_assets`.
   */
  private function album_max_assets(): int {
      return max( 1, (int) apply_filters( 'immich_album_max_assets', 100 ) );
  }

  /**
   * Build the cache key for an album+sort variant.
   */
  private function album_cache_key( string $album_id, string $sort ): string {
      return 'immich_album_' . $album_id . '_' . $sort;
  }

  /**
   * Delete all sort-variant transients for an album.
   *
   * @param string $album_id UUID.
   */
  private function flush_album_cache( string $album_id ): void {
      foreach ( array( 'default', 'oldest', 'newest', 'random' ) as $sort ) {
          delete_transient( $this->album_cache_key( $album_id, $sort ) );
      }
  }
  ```

- [ ] **Step 2: Update `fetch_album_assets` to cache + cap.**

  Replace the existing `fetch_album_assets` body with:

  ```php
  /**
   * Fetch and prepare the asset list for an album, with caching and hard cap.
   *
   * @param string $album_id Validated UUID.
   * @param string $sort     Sort key (default 'default'; expanded in Task 8).
   * @return array{assets: array, total_count: int, fetched_at: int}|\WP_Error
   */
  private function fetch_album_assets( string $album_id, string $sort = 'default' ) {
      $key    = $this->album_cache_key( $album_id, $sort );
      $cached = get_transient( $key );
      if ( false !== $cached && is_array( $cached ) ) {
          return $cached;
      }

      $response = $this->api_request( '/api/albums/' . rawurlencode( $album_id ) );
      if ( is_wp_error( $response ) ) {
          return $response;
      }

      $payload = $this->prepare_album_payload( $response, $sort );
      set_transient( $key, $payload, $this->album_cache_ttl() );
      return $payload;
  }

  /**
   * Build the cache payload from a raw Immich /api/albums/{id} response.
   *
   * Applies sort and the hard cap. Stores only the fields needed for render.
   *
   * @param array  $response Raw Immich response (must contain 'assets').
   * @param string $sort     Sort key.
   * @return array{assets: array, total_count: int, fetched_at: int}
   */
  private function prepare_album_payload( array $response, string $sort ): array {
      $raw   = isset( $response['assets'] ) && is_array( $response['assets'] ) ? $response['assets'] : array();
      $total = count( $raw );

      // Sort comes in Task 8; for now sort='default' is a no-op.
      $sorted = array_values( $raw );

      $cap     = $this->album_max_assets();
      $trimmed = array_slice( $sorted, 0, $cap );

      // Reduce to the fields render uses.
      $minimal = array_map(
          function ( $a ) {
              return array(
                  'id'               => isset( $a['id'] ) ? (string) $a['id'] : '',
                  'originalFileName' => isset( $a['originalFileName'] ) ? (string) $a['originalFileName'] : '',
                  'fileCreatedAt'    => isset( $a['fileCreatedAt'] ) ? (string) $a['fileCreatedAt'] : '',
                  'type'             => isset( $a['type'] ) ? (string) $a['type'] : 'IMAGE',
              );
          },
          $trimmed
      );

      return array(
          'assets'      => $minimal,
          'total_count' => $total,
          'fetched_at'  => time(),
      );
  }
  ```

- [ ] **Step 3: Update `render_album_block` to pass `sortOrder`.**

  Find the call to `$this->fetch_album_assets( $album_id );` from Task 5 and replace with:

  ```php
  $sort    = $this->validate_sort( isset( $attrs['sortOrder'] ) ? (string) $attrs['sortOrder'] : 'default' );
  $payload = $this->fetch_album_assets( $album_id, $sort );
  ```

  Add the `validate_sort` helper next to `validate_image_size`:

  ```php
  /**
   * Validate the sortOrder block attribute.
   *
   * @param string $sort Raw attribute value.
   * @return string One of 'default', 'oldest', 'newest', 'random'.
   */
  private function validate_sort( string $sort ): string {
      $allowed = array( 'default', 'oldest', 'newest', 'random' );
      return in_array( $sort, $allowed, true ) ? $sort : 'default';
  }
  ```

  Also rename the local variable `$payload` consistently (the previous code used `$payload`; keep it):

  ```php
  if ( is_wp_error( $payload ) ) {
      return '';
  }
  $assets = $payload['assets'];
  ```

- [ ] **Step 4: Manually verify caching.**

  1. Reload the post with the block while monitoring Immich's HTTP log (or wp-env via `make logs`). One `/api/albums/<id>` request hits.
  2. Reload again within 5 min — no Immich call. Cached payload returned.
  3. From WP-CLI: `make cli ARGS="transient delete --all"`, reload — one Immich call again.
  4. Insert a second post using the same album with the same sort — first view hits Immich (different cache only when sort differs; same sort = same key, but if the first post's transient is still warm, no hit). Confirm with deliberate flushing if needed.
  5. Set `immich_album_max_assets` filter to 5 in `mu-plugins/test.php`:

     ```php
     <?php
     add_filter( 'immich_album_max_assets', function () { return 5; } );
     ```

     Flush the transient, reload the post — only 5 images render.
  6. Remove the test mu-plugin.

- [ ] **Step 5: Commit.**

  ```bash
  git add media-picker-for-immich.php
  git commit -m "Cache album fetches; hard cap with filter (#7)"
  ```

---

## Task 8 — Sort variants

Goal: `sortOrder` actually changes the rendered order. Cached separately per (album, sort).

**Files:**
- Modify: `media-picker-for-immich.php`

- [ ] **Step 1: Implement sort logic in `prepare_album_payload`.**

  Replace the line `$sorted = array_values( $raw );` from Task 7 with:

  ```php
  $sorted = $this->sort_album_assets( $raw, $sort );
  ```

  And add the helper method:

  ```php
  /**
   * Sort an album's asset list by the requested order.
   *
   * @param array  $assets Raw asset array from Immich.
   * @param string $sort   One of 'default', 'oldest', 'newest', 'random'.
   * @return array Sorted (numerically-keyed) array.
   */
  private function sort_album_assets( array $assets, string $sort ): array {
      $assets = array_values( $assets );
      switch ( $sort ) {
          case 'oldest':
              usort( $assets, function ( $a, $b ) {
                  return strcmp( (string) ( $a['fileCreatedAt'] ?? '' ), (string) ( $b['fileCreatedAt'] ?? '' ) );
              } );
              return $assets;
          case 'newest':
              usort( $assets, function ( $a, $b ) {
                  return strcmp( (string) ( $b['fileCreatedAt'] ?? '' ), (string) ( $a['fileCreatedAt'] ?? '' ) );
              } );
              return $assets;
          case 'random':
              shuffle( $assets );
              return $assets;
          case 'default':
          default:
              return $assets;
      }
  }
  ```

- [ ] **Step 2: Manually verify.**

  1. Edit the post. Set Sort order to "Newest first". Save. Flush transients (`make cli ARGS="transient delete --all"` — note the `_album_*` family). Reload published post.
  2. Frontend: images appear in `fileCreatedAt` descending order. Confirm against Immich's UI for the same album.
  3. "Oldest first" → opposite order.
  4. "Random" → first reload sets the random order; subsequent reloads within the TTL window show the same order (cache hit). Flush + reload → new random order.
  5. Same album in two posts with `default` and `newest` settings — each variant cached separately at `immich_album_<uuid>_default` and `immich_album_<uuid>_newest`.
  6. `make cli ARGS="option list --search='_transient_immich_album_*' --field=option_name"` lists the variants.

- [ ] **Step 3: Commit.**

  ```bash
  git add media-picker-for-immich.php
  git commit -m "Sort album assets by default/oldest/newest/random (#7)"
  ```

---

## Task 9 — Per-block `limit` and `showCaptions`

Goal: The `limit` block attribute trims the rendered list (after sort, after cap). `showCaptions=true` emits `<figcaption>` containing the asset's filename.

**Files:**
- Modify: `media-picker-for-immich.php`

- [ ] **Step 1: Apply `limit` after fetch.**

  In `render_album_block`, after `$assets = $payload['assets'];` add:

  ```php
  $limit = max( 0, (int) ( $attrs['limit'] ?? 0 ) );
  if ( $limit > 0 ) {
      $assets = array_slice( $assets, 0, $limit );
  }
  ```

- [ ] **Step 2: Render `<figcaption>` when `showCaptions=true`.**

  Replace the foreach loop body in `render_album_block` with:

  ```php
  $show_captions = ! empty( $attrs['showCaptions'] );
  $children      = '';
  foreach ( $assets as $a ) {
      $url      = home_url( '/?immich_media_proxy=' . rawurlencode( $size ) . '&id=' . rawurlencode( $a['id'] ) );
      $alt      = isset( $a['originalFileName'] ) ? (string) $a['originalFileName'] : '';
      $caption  = $show_captions && '' !== $alt
          ? '<figcaption class="wp-element-caption">' . esc_html( $alt ) . '</figcaption>'
          : '';
      $children .= '<figure class="wp-block-image size-large">'
          . '<img src="' . esc_url( $url ) . '" alt="' . esc_attr( $alt ) . '" loading="lazy" />'
          . $caption
          . '</figure>';
  }
  ```

- [ ] **Step 3: Manually verify.**

  1. Set Limit to 3 → only 3 images render.
  2. Set Limit to 0 → all (up to the cap) render.
  3. Toggle Show captions on → filenames render below each image.
  4. Toggle off → no captions.

- [ ] **Step 4: Commit.**

  ```bash
  git add media-picker-for-immich.php
  git commit -m "Block: limit and showCaptions attributes (#7)"
  ```

---

## Task 10 — Lightbox markup

Goal: When `lightbox=true`, render markup that triggers WP 6.4+ core's Interactivity-API lightbox. When false, render plain images.

The exact markup core emits depends on the WP version. The simplest path is to emit each child via `render_block( [ 'blockName' => 'core/image', 'attrs' => [ 'lightbox' => [ 'enabled' => true ], ... ] ] )` so we ride along with whatever core does today.

**Files:**
- Modify: `media-picker-for-immich.php`

- [ ] **Step 1: Replace the inline child markup with a `render_block` call.**

  Replace the foreach loop body in `render_album_block` from Task 9 with:

  ```php
  $lightbox      = ! empty( $attrs['lightbox'] );
  $show_captions = ! empty( $attrs['showCaptions'] );
  $children      = '';
  foreach ( $assets as $a ) {
      $url     = home_url( '/?immich_media_proxy=' . rawurlencode( $size ) . '&id=' . rawurlencode( $a['id'] ) );
      $alt     = isset( $a['originalFileName'] ) ? (string) $a['originalFileName'] : '';
      $caption = $show_captions ? $alt : '';

      $image_attrs = array(
          'url'        => $url,
          'alt'        => $alt,
          'sizeSlug'   => 'large',
      );
      if ( '' !== $caption ) {
          $image_attrs['caption'] = $caption;
      }
      if ( $lightbox ) {
          $image_attrs['lightbox'] = array( 'enabled' => true );
      }

      $children .= render_block(
          array(
              'blockName' => 'core/image',
              'attrs'     => $image_attrs,
              'innerHTML' => '<figure class="wp-block-image size-large">'
                  . '<img src="' . esc_url( $url ) . '" alt="' . esc_attr( $alt ) . '" />'
                  . ( '' !== $caption ? '<figcaption class="wp-element-caption">' . esc_html( $caption ) . '</figcaption>' : '' )
                  . '</figure>',
          )
      );
  }
  ```

  This delegates the lightbox-attribute rendering to core's `core/image` block, so whatever Interactivity-API attributes core adds (now or later) flow through.

- [ ] **Step 2: Manually verify in WP 6.4+.**

  1. Confirm WP version: `make cli ARGS="core version"` → 6.4 or later.
  2. View published post. Click an image → core lightbox opens (full-screen overlay with prev/next buttons).
  3. Toggle Lightbox off → click image → nothing happens (or follows default `<a>` if a theme adds one).
  4. Inspect rendered HTML: the `<figure class="wp-block-image">` children carry whatever data attributes core's `render_block_core_image` emits — confirm they're present when on, absent when off.

- [ ] **Step 3: Commit.**

  ```bash
  git add media-picker-for-immich.php
  git commit -m "Lightbox via render_block(core/image) (#7)"
  ```

---

## Task 11 — Editor refresh path + stale-cache fallback + error rendering

Goal: A logged-in editor with `?immich_refresh=1` busts the album's transients and re-fetches. On Immich error with stale cache, serve stale + show editors a "Showing cached version" notice. Without stale, render an error notice for editors and nothing for visitors.

**Files:**
- Modify: `media-picker-for-immich.php`

- [ ] **Step 1: Add `read_stale_transient` helper.**

  Place near the cache helpers from Task 7:

  ```php
  /**
   * Read a transient ignoring its expiry.
   *
   * Used as a degraded fallback when Immich is unreachable. Works only
   * with the default DB-backed transient store; with an external object
   * cache the underlying option does not exist and this returns false.
   *
   * @param string $key Transient key (without the `_transient_` prefix).
   * @return mixed Stored value, or false if not present.
   */
  private function read_stale_transient( string $key ) {
      $value = get_option( '_transient_' . $key, false );
      return false === $value ? false : $value;
  }
  ```

- [ ] **Step 2: Update `fetch_album_assets` to surface stale-vs-fresh.**

  Replace `fetch_album_assets` from Task 7 with:

  ```php
  /**
   * Fetch and prepare the asset list for an album, with caching and stale fallback.
   *
   * Return shape:
   *   array{ assets: array, total_count: int, fetched_at: int, stale: bool }
   * On hard failure (no cache, no stale), returns WP_Error.
   *
   * @param string $album_id Validated UUID.
   * @param string $sort     Sort key.
   * @return array|\WP_Error
   */
  private function fetch_album_assets( string $album_id, string $sort = 'default' ) {
      $key    = $this->album_cache_key( $album_id, $sort );
      $cached = get_transient( $key );
      if ( false !== $cached && is_array( $cached ) ) {
          $cached['stale'] = false;
          return $cached;
      }

      $response = $this->api_request( '/api/albums/' . rawurlencode( $album_id ) );
      if ( is_wp_error( $response ) ) {
          $stale = $this->read_stale_transient( $key );
          if ( false !== $stale && is_array( $stale ) ) {
              $stale['stale'] = true;
              return $stale;
          }
          return $response;
      }

      $payload          = $this->prepare_album_payload( $response, $sort );
      $payload['stale'] = false;
      set_transient( $key, $payload, $this->album_cache_ttl() );
      return $payload;
  }
  ```

- [ ] **Step 3: Add the editor refresh probe and error renderer to `render_album_block`.**

  At the top of `render_album_block` (after the `albumId` validation), add:

  ```php
  if ( current_user_can( 'edit_posts' ) && ! empty( $_GET['immich_refresh'] ) ) {
      $this->flush_album_cache( $album_id );
  }
  ```

  Replace the existing error short-circuit:

  ```php
  if ( is_wp_error( $payload ) ) {
      return '';
  }
  ```

  With:

  ```php
  if ( is_wp_error( $payload ) ) {
      return $this->render_album_error_notice( $payload );
  }
  ```

  Add the `render_album_error_notice` helper:

  ```php
  /**
   * Render an editor-only error notice.
   *
   * Anonymous visitors get nothing.
   *
   * @param \WP_Error $error The error to display.
   * @return string Empty string for anonymous viewers, notice div for editors.
   */
  private function render_album_error_notice( \WP_Error $error ): string {
      if ( ! current_user_can( 'edit_posts' ) ) {
          return '';
      }
      return '<div class="immich-album-error" style="border:1px solid #d63638;padding:8px;color:#d63638;">'
          . esc_html__( 'Could not load Immich album.', 'media-picker-for-immich' )
          . ' <code>' . esc_html( $error->get_error_message() ) . '</code>'
          . '</div>';
  }
  ```

- [ ] **Step 4: Add a stale notice for editors when serving stale.**

  At the point in `render_album_block` where you have the gallery markup ready (right before `return`), prepend a stale notice if applicable:

  ```php
  $stale_notice = '';
  if ( ! empty( $payload['stale'] ) && current_user_can( 'edit_posts' ) ) {
      $stale_notice = '<div class="immich-album-stale" style="background:#fcf9e8;border:1px solid #dba617;padding:6px;margin-bottom:8px;">'
          . esc_html__( 'Showing cached version of this Immich album (Immich is unreachable).', 'media-picker-for-immich' )
          . '</div>';
  }
  return $stale_notice
      . '<figure class="wp-block-gallery has-nested-images columns-' . (int) $columns . ' is-layout-flex wp-block-gallery-is-layout-flex">'
      . $children
      . '</figure>';
  ```

- [ ] **Step 5: Manually verify.**

  1. Cached state: load post, no Immich call.
  2. Append `?immich_refresh=1` (logged in as admin/editor) → Immich call fires; cache repopulates.
  3. Append `?immich_refresh=1` while logged out → ignored (no `edit_posts` cap); cache untouched.
  4. Block Immich at the network layer (`/etc/hosts` mapping the hostname to `127.0.0.2`, or stop the container). With cache populated, reload as admin → gallery renders + yellow "Showing cached version" notice.
  5. Anonymous viewer at the same URL → gallery renders, no notice.
  6. Flush cache + Immich still down + reload as admin → red error notice.
  7. Anonymous viewer in same state → empty page (no notice).
  8. Restore Immich.

- [ ] **Step 6: Commit.**

  ```bash
  git add media-picker-for-immich.php
  git commit -m "Editor refresh, stale fallback, error rendering for album block (#7)"
  ```

---

## Task 12 — Empty / not-found editor notices

Goal: Editors see specific notices when the album is empty or has been removed from Immich. Visitors still see nothing.

**Files:**
- Modify: `media-picker-for-immich.php`

- [ ] **Step 1: Detect 404 from Immich and surface it distinctly.**

  `api_request` (in `media-picker-for-immich.php` ~line 912) always returns a single error code, `immich_api_error`, with the HTTP status in the error data array under the `status` key. Add a small helper:

  ```php
  /**
   * Extract the HTTP status from an api_request WP_Error, if any.
   *
   * @param \WP_Error $error Error from api_request().
   * @return int 0 if not present.
   */
  private function api_error_status( \WP_Error $error ): int {
      $data = $error->get_error_data();
      return is_array( $data ) && isset( $data['status'] ) ? (int) $data['status'] : 0;
  }
  ```

  Update `fetch_album_assets` so 404 short-circuits without trying stale:

  ```php
  if ( is_wp_error( $response ) ) {
      $is_not_found = ( 404 === $this->api_error_status( $response ) );
      if ( ! $is_not_found ) {
          $stale = $this->read_stale_transient( $key );
          if ( false !== $stale && is_array( $stale ) ) {
              $stale['stale'] = true;
              return $stale;
          }
      }
      return $response;
  }
  ```

- [ ] **Step 2: Replace the empty-album short-circuit with editor-aware rendering.**

  In `render_album_block`, replace:

  ```php
  if ( empty( $assets ) ) {
      return '';
  }
  ```

  With:

  ```php
  if ( empty( $assets ) ) {
      if ( current_user_can( 'edit_posts' ) ) {
          return '<div class="immich-album-empty" style="border:1px dashed #ccc;padding:8px;color:#757575;">'
              . esc_html__( 'This Immich album is empty.', 'media-picker-for-immich' )
              . '</div>';
      }
      return '';
  }
  ```

- [ ] **Step 3: Differentiate 404 in `render_album_error_notice`.**

  Replace the helper from Task 11.3 with:

  ```php
  /**
   * Render an editor-only error notice.
   *
   * @param \WP_Error $error The error to display.
   * @return string Empty string for anonymous viewers, notice div for editors.
   */
  private function render_album_error_notice( \WP_Error $error ): string {
      if ( ! current_user_can( 'edit_posts' ) ) {
          return '';
      }
      $is_not_found = ( 404 === $this->api_error_status( $error ) );
      $message      = $is_not_found
          ? __( 'Album was removed from Immich.', 'media-picker-for-immich' )
          : __( 'Could not load Immich album.', 'media-picker-for-immich' );
      $color        = $is_not_found ? '#dba617' : '#d63638';
      return '<div class="immich-album-error" style="border:1px solid ' . esc_attr( $color ) . ';padding:8px;color:' . esc_attr( $color ) . ';">'
          . esc_html( $message )
          . ' <code>' . esc_html( $error->get_error_message() ) . '</code>'
          . '</div>';
  }
  ```

- [ ] **Step 4: Manually verify.**

  1. Pick the empty album in a post → editor sees the dashed "This Immich album is empty" box; visitor sees nothing.
  2. In Immich, delete a test album that's currently embedded in a post; flush cache (`?immich_refresh=1`); reload as editor → "Album was removed from Immich" notice (yellow).
  3. Same post as visitor → blank.
  4. Restore Immich state.

- [ ] **Step 5: Commit.**

  ```bash
  git add media-picker-for-immich.php
  git commit -m "Album block: empty + not-found editor notices (#7)"
  ```

---

## Task 13 — "View N more on Immich" link

Goal: When the album has more assets than the rendered count (because the cap trimmed it, not because the user set `limit`), append a small link to the album's Immich web URL.

**Files:**
- Modify: `media-picker-for-immich.php`

- [ ] **Step 1: Compute the truncation flag in `render_album_block`.**

  In `render_album_block`, after `$assets = $payload['assets'];` and after the per-block `limit` slice, compute the link:

  ```php
  $total_count = isset( $payload['total_count'] ) ? (int) $payload['total_count'] : count( $assets );
  $hidden      = max( 0, $total_count - count( $assets ) );
  $cap_applied = ( 0 === $limit ) && ( $hidden > 0 );

  $more_link = '';
  if ( $cap_applied ) {
      $immich_url = rtrim( $this->get_api_url(), '/' );
      // The Immich web UI lives at the same origin as the API by convention.
      // Album URL pattern: {origin}/albums/{album_id}
      $album_url = $immich_url . '/albums/' . rawurlencode( $album_id );
      $more_link = '<p class="immich-album-more"><a href="' . esc_url( $album_url ) . '" target="_blank" rel="noopener noreferrer">'
          . sprintf(
              /* translators: %d: number of additional assets in the Immich album. */
              esc_html( _n( 'View %d more on Immich', 'View %d more on Immich', $hidden, 'media-picker-for-immich' ) ),
              (int) $hidden
          )
          . ' &rarr;</a></p>';
  }
  ```

- [ ] **Step 2: Append the link to the return value.**

  Update the final return statement:

  ```php
  return $stale_notice
      . '<figure class="wp-block-gallery has-nested-images columns-' . (int) $columns . ' is-layout-flex wp-block-gallery-is-layout-flex">'
      . $children
      . '</figure>'
      . $more_link;
  ```

- [ ] **Step 3: Manually verify.**

  1. Pick the >100-asset album. Reload published post → at most 100 images render + "View N more on Immich →" link. Click it → opens the album's Immich web page in a new tab.
  2. Set Limit to 5 (`limit > 0`) → 5 images, **no** "View N more" link.
  3. Set Limit to 0 with the small album (~20 assets, below the cap) → no link.

- [ ] **Step 4: Commit.**

  ```bash
  git add media-picker-for-immich.php
  git commit -m "Album block: 'View N more on Immich' truncation link (#7)"
  ```

---

## Task 14 — Cache Files admin page: Album Lists section

Goal: Extend the existing Cache Files admin page (PR #20) with a section listing active album-list transients, with per-row Refresh / Delete and a bulk "Empty all" button.

**Files:**
- Modify: `media-picker-for-immich.php`
- Possibly modify: `includes/class-immich-cache-list-table.php` (if reuse fits — otherwise inline the album list)

- [ ] **Step 1: Inventory active album-list transients.**

  Add a method to the main class:

  ```php
  /**
   * Enumerate active album-list transients.
   *
   * Returns one entry per (uuid, sort) variant.
   *
   * @return array<int,array{key:string,uuid:string,sort:string,total_count:int,fetched_at:int,size:int}>
   */
  public function enumerate_album_caches(): array {
      global $wpdb;
      $rows  = $wpdb->get_results(
          "SELECT option_name, option_value FROM {$wpdb->options}
           WHERE option_name LIKE '_transient_immich_album_%'
           AND option_name NOT LIKE '_transient_timeout_%'"
      );
      $items = array();
      foreach ( $rows as $row ) {
          $name = (string) $row->option_name;
          // _transient_immich_album_<uuid>_<sort>
          if ( ! preg_match( '/^_transient_immich_album_([0-9a-f-]{36})_(default|oldest|newest|random)$/i', $name, $m ) ) {
              continue;
          }
          $payload = maybe_unserialize( $row->option_value );
          if ( ! is_array( $payload ) ) {
              continue;
          }
          $items[] = array(
              'key'         => substr( $name, strlen( '_transient_' ) ),
              'uuid'        => $m[1],
              'sort'        => $m[2],
              'total_count' => isset( $payload['total_count'] ) ? (int) $payload['total_count'] : 0,
              'fetched_at'  => isset( $payload['fetched_at'] ) ? (int) $payload['fetched_at'] : 0,
              'size'        => strlen( (string) $row->option_value ),
          );
      }
      usort( $items, function ( $a, $b ) { return $b['fetched_at'] <=> $a['fetched_at']; } );
      return $items;
  }
  ```

- [ ] **Step 2: Render the new section on the Cache Files page.**

  Find `render_cache_files_page` (~line 183 area). After the existing list table render, append:

  ```php
  $album_caches = $this->enumerate_album_caches();
  echo '<h2>' . esc_html__( 'Album lists', 'media-picker-for-immich' ) . '</h2>';
  if ( empty( $album_caches ) ) {
      echo '<p>' . esc_html__( 'No cached album lists.', 'media-picker-for-immich' ) . '</p>';
  } else {
      echo '<form method="post" style="margin-bottom:8px;">';
      wp_nonce_field( 'immich_empty_album_caches' );
      echo '<button type="submit" name="immich_empty_album_caches" value="1" class="button">'
          . esc_html__( 'Empty all album caches', 'media-picker-for-immich' )
          . '</button>';
      echo '</form>';

      echo '<table class="wp-list-table widefat striped"><thead><tr>'
          . '<th>' . esc_html__( 'Album UUID', 'media-picker-for-immich' ) . '</th>'
          . '<th>' . esc_html__( 'Sort', 'media-picker-for-immich' ) . '</th>'
          . '<th>' . esc_html__( 'Assets', 'media-picker-for-immich' ) . '</th>'
          . '<th>' . esc_html__( 'Cached', 'media-picker-for-immich' ) . '</th>'
          . '<th>' . esc_html__( 'Size', 'media-picker-for-immich' ) . '</th>'
          . '<th>' . esc_html__( 'Actions', 'media-picker-for-immich' ) . '</th>'
          . '</tr></thead><tbody>';
      foreach ( $album_caches as $row ) {
          $delete_url = wp_nonce_url(
              add_query_arg(
                  array(
                      'page'           => 'immich-cache-files',
                      'immich_act'     => 'delete_album',
                      'key'            => rawurlencode( $row['key'] ),
                  ),
                  admin_url( 'upload.php' )
              ),
              'immich_delete_album_' . $row['key']
          );
          echo '<tr>'
              . '<td><code>' . esc_html( $row['uuid'] ) . '</code></td>'
              . '<td>' . esc_html( $row['sort'] ) . '</td>'
              . '<td>' . (int) $row['total_count'] . '</td>'
              . '<td>' . esc_html( human_time_diff( $row['fetched_at'] ) ) . ' ' . esc_html__( 'ago', 'media-picker-for-immich' ) . '</td>'
              . '<td>' . esc_html( size_format( $row['size'] ) ) . '</td>'
              . '<td><a href="' . esc_url( $delete_url ) . '">' . esc_html__( 'Delete', 'media-picker-for-immich' ) . '</a></td>'
              . '</tr>';
      }
      echo '</tbody></table>';
  }
  ```

- [ ] **Step 3: Handle the action endpoints.**

  At the top of `render_cache_files_page`, before any rendering, add the handlers (placement next to the existing `immich_empty_cache` handler):

  ```php
  // Empty all album caches.
  if ( ! empty( $_POST['immich_empty_album_caches'] ) && check_admin_referer( 'immich_empty_album_caches' ) ) {
      global $wpdb;
      $deleted = $wpdb->query(
          "DELETE FROM {$wpdb->options}
           WHERE option_name LIKE '_transient_immich_album_%'
           OR option_name LIKE '_transient_timeout_immich_album_%'"
      );
      add_settings_error(
          'immich_album_caches',
          'emptied',
          /* translators: %d: number of cache entries deleted. */
          sprintf( __( 'Emptied %d cached album entries.', 'media-picker-for-immich' ), (int) $deleted ),
          'updated'
      );
  }

  // Delete a single album cache.
  if ( isset( $_GET['immich_act'] ) && 'delete_album' === $_GET['immich_act'] ) {
      $key = isset( $_GET['key'] ) ? sanitize_text_field( wp_unslash( $_GET['key'] ) ) : '';
      check_admin_referer( 'immich_delete_album_' . $key );
      if ( '' !== $key && preg_match( '/^immich_album_[0-9a-f-]{36}_(default|oldest|newest|random)$/i', $key ) ) {
          delete_transient( $key );
          add_settings_error( 'immich_album_caches', 'deleted', __( 'Album cache deleted.', 'media-picker-for-immich' ), 'updated' );
      }
  }
  ```

  Add `settings_errors( 'immich_album_caches' );` near the top of the page render output if not already present.

- [ ] **Step 4: Manually verify.**

  1. Visit Media → Cache Files. Scroll to "Album lists" section.
  2. After rendering a couple of posts in different sort orders, the table lists each (uuid, sort) variant with correct count, age, size.
  3. Click Delete on one row → row disappears; subsequent post view re-fetches.
  4. Click "Empty all album caches" → table empties; subsequent post view re-fetches.
  5. Try direct GET to `?page=immich-cache-files&immich_act=delete_album&key=foo` without a nonce → fails (`check_admin_referer` halts).
  6. Try direct GET with malformed key → no-op.

- [ ] **Step 5: Commit.**

  ```bash
  git add media-picker-for-immich.php
  git commit -m "Cache Files page: Album lists section (#7)"
  ```

---

## Task 15 — Documentation and final regression sweep

Goal: Update README and readme.txt; run the full Section D test plan from the spec; open the PR.

**Files:**
- Modify: `README.md`
- Modify: `readme.txt`

- [ ] **Step 1: Update `README.md`.**

  Add a short "Album Gallery block" section. Keep it factual:

  ```markdown
  ## Album Gallery block

  This plugin ships an "Immich Album Gallery" Gutenberg block (`immich/album-gallery`) that embeds a live Immich album as a gallery. Insert the block, click "Pick album", and choose an album from your Immich server.

  Per-block options (sidebar):

  - **Columns** (1–8) — grid density.
  - **Image size** — `thumbnail`, `preview` (default), or `fullsize`.
  - **Sort order** — Album order (default), oldest, newest, or random.
  - **Limit** — cap to N images; 0 means all up to the global cap.
  - **Lightbox** — toggle the WP 6.4+ core lightbox on click.
  - **Show captions** — render the asset filename below each image.

  The album is fetched live on each page render and cached for 5 minutes. Logged-in editors can force a refresh by appending `?immich_refresh=1` to the post URL. Cached album lists are visible (and clearable) on Media → Cache Files.

  Filters:

  - `immich_album_cache_ttl` (int seconds, default `300`)
  - `immich_album_max_assets` (int, default `100`)
  ```

- [ ] **Step 2: Update `readme.txt`.**

  Add to the `== Description ==` section a one-paragraph mention of the block. Add to `== Frequently Asked Questions ==` a short entry:

  ```markdown
  = How do I embed a whole Immich album in a post? =

  Add the "Immich Album Gallery" block, click "Pick album", choose an album from your Immich server. The gallery renders live and is cached for 5 minutes.
  ```

- [ ] **Step 3: Verify `.distignore` ships the new files.**

  ```bash
  rsync -an --exclude-from=.distignore . /tmp/mpfi-test/ 2>&1 | grep -E 'block-album-gallery|immich-album-block' | head
  ```

  Both `includes/block-album-gallery/` (with `block.json` and `render.php`) and `assets/js/immich-album-block.js` should appear. If not, edit `.distignore` to remove any blocking entry. (As of this plan, `.distignore` excludes only `/.git`, `/.gitignore`, `/.wp-env.json`, `/.claude`, `/Makefile`, `/README.md`, `/docs`, `/whats-next.md`, `/screenshots`, `/dist`, `/.distignore` — none of which match.)

- [ ] **Step 4: Run the full Section D manual test plan from the spec.**

  Walk through every checkbox in `docs/superpowers/specs/2026-05-08-immich-album-block-design.md` Section D (D.1 through D.9). Note any deviations.

- [ ] **Step 5: Commit docs + open PR.**

  ```bash
  git add README.md readme.txt
  git commit -m "Document Immich Album Gallery block (#7)"
  git push -u origin feat/issue-7-album-gallery-block
  gh pr create --title "Add Immich Album Gallery block (#7)" --body "$(cat <<'EOF'
  ## Summary

  Resolves #7. Adds a server-rendered Gutenberg block, `immich/album-gallery`, that embeds an Immich album as a live gallery using core Gallery markup.

  - **Authoring**: Block-driven picker — clicking "Pick album" opens the existing media frame in an albums-only mode (`library.type === 'immich-album'`); selecting an album stores its UUID on the block.
  - **Rendering**: Server-side render emits `figure.wp-block-gallery` with `core/image` children, so theme styles and the WP 6.4+ Interactivity-API lightbox apply for free.
  - **Caching**: 5-minute transient per (album, sort) variant (filter `immich_album_cache_ttl`); image binaries cached on disk by the existing proxy. Logged-in editors can force a refresh with `?immich_refresh=1`.
  - **Cap**: Hard cap of 100 assets per block (filter `immich_album_max_assets`); when applied, a "View N more on Immich →" link is appended.
  - **Errors**: Fail closed for visitors; chatty notices for editors (`edit_posts` cap). Stale-cache fallback when Immich is unreachable.
  - **Cache Files admin page**: New "Album lists" section listing cached variants with Refresh / Delete actions.

  Spec: `docs/superpowers/specs/2026-05-08-immich-album-block-design.md`
  Plan: `docs/superpowers/plans/2026-05-08-immich-album-block.md`

  ## Test plan

  - [ ] Block authoring: insert block, pick album, change album, inspect ServerSideRender preview.
  - [ ] InspectorControls: each control changes the rendered preview as expected.
  - [ ] Frontend render: core gallery markup, proxy URLs, theme styling.
  - [ ] Lightbox: clicking opens core lightbox when on; plain images when off.
  - [ ] Caching: one Immich call per 5 min; `?immich_refresh=1` busts cache for editors only.
  - [ ] Sort variants cached separately.
  - [ ] Hard cap: large album shows cap-applied gallery + "View N more" link.
  - [ ] Errors: stop Immich → editor sees stale notice; visitor sees gallery; without stale, editor sees error notice; visitor sees nothing.
  - [ ] Empty album: editor sees notice; visitor sees nothing.
  - [ ] Album removed in Immich: editor sees "removed" notice; visitor sees nothing.
  - [ ] Cache Files admin page: Album lists section lists, refreshes, deletes correctly.
  - [ ] Picker integration regressions: standard Add Media flow, Image block, Manage frame all unchanged.
  EOF
  )"
  ```

---

## Notes for the implementing agent

- **Read the spec first.** `docs/superpowers/specs/2026-05-08-immich-album-block-design.md` is the source of truth for behavior. This plan is the construction order.
- **Single feature branch.** `feat/issue-7-album-gallery-block` off `main`. One commit per task as listed; squash-merge at the end via `gh pr merge --squash --delete-branch`.
- **Do not introduce a build step.** Vanilla `wp.element.createElement` only. No `npm`, no JSX, no `package.json`.
- **Trust the existing helpers.** `verify_ajax_request()`, `api_request()`, `UUID_PATTERN`, `handle_proxy_request`, `get_api_url`, `get_api_key`, `Immich_Media_Picker::instance()` — all present and load-bearing. Don't replicate.
- **Capabilities matter.** Picker AJAX = `verify_ajax_request()` (nonce + `upload_files`). Block-renderer REST = WP default (`edit_posts`). Refresh probe = `current_user_can('edit_posts')`. Cache Files actions = `manage_options` (existing).
- **Stay within manual verification.** No new test framework. The maintainer runs the test plan at `localhost:8888`.
- **Out of scope** (do NOT implement): shortcode equivalent, snapshot mode, per-image alt-text overrides, WP-CLI cache flush command.
