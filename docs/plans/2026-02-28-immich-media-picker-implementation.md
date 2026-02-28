# Immich Media Picker Implementation Plan

> **For Claude:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task.

**Goal:** Build a WordPress plugin that adds an "Immich" tab to the media picker modal, allowing users to search/browse/import photos from Immich.

**Architecture:** Single PHP class (`Immich_Media_Picker`) handles settings, AJAX proxy endpoints, and asset enqueueing. Separate JS file extends `wp.media.view` for the modal tab. API keys resolve site-wide first, then per-user.

**Tech Stack:** PHP 8.0+, WordPress 6.4+ (Settings API, AJAX API, Media JS), wp-env for development.

**Design doc:** `docs/plans/2026-02-28-immich-media-picker-design.md`

---

### Task 1: Scaffold plugin and wp-env

**Files:**
- Create: `immich-media-picker.php`
- Create: `assets/js/immich-media-picker.js`
- Create: `.wp-env.json`
- Create: `readme.txt`

**Step 1: Create `.wp-env.json`**

```json
{
  "core": null,
  "plugins": ["."],
  "env": {
    "development": {
      "port": 8888
    }
  }
}
```

**Step 2: Create `readme.txt`**

```
=== Immich Media Picker ===
Contributors: donncha
Tags: immich, media, photos
Requires at least: 6.4
Tested up to: 6.7
Requires PHP: 8.0
Stable tag: 0.1.0
License: GPLv2 or later

Import photos from your Immich server into the WordPress media library.

== Description ==

Adds an "Immich" tab to the WordPress media picker modal. Search and browse your Immich photo library, then import selected photos directly into WordPress.

== Changelog ==

= 0.1.0 =
* Initial release
```

**Step 3: Create plugin bootstrap `immich-media-picker.php`**

```php
<?php
/**
 * Plugin Name: Immich Media Picker
 * Description: Import photos from your Immich server into the WordPress media library.
 * Version: 0.1.0
 * Requires at least: 6.4
 * Requires PHP: 8.0
 * Author: Donncha
 * License: GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'IMMICH_MEDIA_PICKER_VERSION', '0.1.0' );
define( 'IMMICH_MEDIA_PICKER_FILE', __FILE__ );
define( 'IMMICH_MEDIA_PICKER_DIR', plugin_dir_path( __FILE__ ) );
define( 'IMMICH_MEDIA_PICKER_URL', plugin_dir_url( __FILE__ ) );

class Immich_Media_Picker {

	public function __construct() {
		// Hooks will be added in subsequent tasks.
	}
}

add_action( 'plugins_loaded', function () {
	new Immich_Media_Picker();
} );
```

**Step 4: Create empty JS file `assets/js/immich-media-picker.js`**

```js
/* Immich Media Picker - media modal integration */
(function ($, wp) {
	'use strict';
	// Implementation in Task 6.
})(jQuery, wp);
```

**Step 5: Start wp-env and verify plugin activates**

Run: `npx wp-env start`
Then: `npx wp-env run cli wp plugin list`
Expected: `immich-media-picker` appears as active (or activate it: `npx wp-env run cli wp plugin activate immich-media-picker`).

**Step 6: Commit**

```bash
git add .wp-env.json readme.txt immich-media-picker.php assets/js/immich-media-picker.js
git commit -m "Scaffold plugin, wp-env config, and empty JS"
```

---

### Task 2: Settings page

**Files:**
- Modify: `immich-media-picker.php`

**Step 1: Add settings hooks in the constructor**

Add to `__construct()`:

```php
add_action( 'admin_menu', array( $this, 'add_settings_page' ) );
add_action( 'admin_init', array( $this, 'register_settings' ) );
```

**Step 2: Implement `add_settings_page()`**

```php
public function add_settings_page(): void {
	add_options_page(
		__( 'Immich Settings', 'immich-media-picker' ),
		__( 'Immich', 'immich-media-picker' ),
		'manage_options',
		'immich-media-picker',
		array( $this, 'render_settings_page' )
	);
}
```

**Step 3: Implement `register_settings()`**

```php
public function register_settings(): void {
	register_setting(
		'immich_settings_group',
		'immich_settings',
		array(
			'type'              => 'array',
			'sanitize_callback' => array( $this, 'sanitize_settings' ),
			'default'           => array(
				'api_url' => 'http://immich-server:2283',
				'api_key' => '',
			),
		)
	);

	add_settings_section(
		'immich_main',
		__( 'Immich Server', 'immich-media-picker' ),
		'__return_null',
		'immich-media-picker'
	);

	add_settings_field(
		'immich_api_url',
		__( 'API URL', 'immich-media-picker' ),
		array( $this, 'render_api_url_field' ),
		'immich-media-picker',
		'immich_main'
	);

	add_settings_field(
		'immich_api_key',
		__( 'Site-wide API Key', 'immich-media-picker' ),
		array( $this, 'render_api_key_field' ),
		'immich-media-picker',
		'immich_main'
	);
}
```

**Step 4: Implement sanitize callback and field renderers**

```php
public function sanitize_settings( array $input ): array {
	return array(
		'api_url' => esc_url_raw( $input['api_url'] ?? '' ),
		'api_key' => sanitize_text_field( $input['api_key'] ?? '' ),
	);
}

public function render_api_url_field(): void {
	$settings = get_option( 'immich_settings', array() );
	$value    = $settings['api_url'] ?? 'http://immich-server:2283';
	printf(
		'<input type="url" name="immich_settings[api_url]" value="%s" class="regular-text" />',
		esc_attr( $value )
	);
}

public function render_api_key_field(): void {
	$settings = get_option( 'immich_settings', array() );
	$value    = $settings['api_key'] ?? '';
	printf(
		'<input type="password" name="immich_settings[api_key]" value="%s" class="regular-text" />',
		esc_attr( $value )
	);
	echo '<p class="description">' . esc_html__( 'When set, all users will use this key. Leave empty to allow per-user keys.', 'immich-media-picker' ) . '</p>';
}
```

**Step 5: Implement `render_settings_page()`**

```php
public function render_settings_page(): void {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}
	?>
	<div class="wrap">
		<h1><?php echo esc_html( get_admin_page_title() ); ?></h1>
		<form action="options.php" method="post">
			<?php
			settings_fields( 'immich_settings_group' );
			do_settings_sections( 'immich-media-picker' );
			submit_button();
			?>
		</form>
	</div>
	<?php
}
```

**Step 6: Verify in wp-env**

Navigate to `http://localhost:8888/wp-admin/options-general.php?page=immich-media-picker`.
Expected: Settings page renders with both fields. Save and reload — values persist.

**Step 7: Commit**

```bash
git add immich-media-picker.php
git commit -m "Add Immich settings page with API URL and site-wide key fields"
```

---

### Task 3: Per-user API key on profile page

**Files:**
- Modify: `immich-media-picker.php`

**Step 1: Add profile hooks in the constructor**

```php
add_action( 'show_user_profile', array( $this, 'render_user_api_key_field' ) );
add_action( 'edit_user_profile', array( $this, 'render_user_api_key_field' ) );
add_action( 'personal_options_update', array( $this, 'save_user_api_key' ) );
add_action( 'edit_user_profile_update', array( $this, 'save_user_api_key' ) );
```

**Step 2: Implement the key resolution helper**

```php
private function get_api_key( int $user_id = 0 ): string {
	$settings = get_option( 'immich_settings', array() );
	if ( ! empty( $settings['api_key'] ) ) {
		return $settings['api_key'];
	}
	if ( 0 === $user_id ) {
		$user_id = get_current_user_id();
	}
	return get_user_meta( $user_id, 'immich_api_key', true ) ?: '';
}

private function get_api_url(): string {
	$settings = get_option( 'immich_settings', array() );
	return $settings['api_url'] ?? 'http://immich-server:2283';
}
```

**Step 3: Implement render and save for user profile field**

```php
public function render_user_api_key_field( \WP_User $user ): void {
	$settings = get_option( 'immich_settings', array() );
	// Hide if site-wide key is set.
	if ( ! empty( $settings['api_key'] ) ) {
		return;
	}
	$value = get_user_meta( $user->ID, 'immich_api_key', true );
	?>
	<h2><?php esc_html_e( 'Immich', 'immich-media-picker' ); ?></h2>
	<table class="form-table">
		<tr>
			<th><label for="immich_api_key"><?php esc_html_e( 'API Key', 'immich-media-picker' ); ?></label></th>
			<td>
				<input type="password" name="immich_api_key" id="immich_api_key" value="<?php echo esc_attr( $value ); ?>" class="regular-text" />
				<p class="description"><?php esc_html_e( 'Your personal Immich API key.', 'immich-media-picker' ); ?></p>
			</td>
		</tr>
	</table>
	<?php
}

public function save_user_api_key( int $user_id ): void {
	if ( ! current_user_can( 'edit_user', $user_id ) ) {
		return;
	}
	$key = sanitize_text_field( wp_unslash( $_POST['immich_api_key'] ?? '' ) );
	update_user_meta( $user_id, 'immich_api_key', $key );
}
```

**Step 4: Verify in wp-env**

1. Ensure site-wide key is empty in Settings > Immich.
2. Go to Users > Profile. Expected: "Immich" section with API Key field.
3. Set a site-wide key in Settings > Immich, reload profile. Expected: Immich section hidden.

**Step 5: Commit**

```bash
git add immich-media-picker.php
git commit -m "Add per-user Immich API key with site-wide override"
```

---

### Task 4: API client helper and AJAX proxy endpoints

**Files:**
- Modify: `immich-media-picker.php`

**Step 1: Add AJAX hooks in the constructor**

```php
add_action( 'wp_ajax_immich_search', array( $this, 'ajax_search' ) );
add_action( 'wp_ajax_immich_people', array( $this, 'ajax_people' ) );
add_action( 'wp_ajax_immich_thumbnail', array( $this, 'ajax_thumbnail' ) );
add_action( 'wp_ajax_immich_import', array( $this, 'ajax_import' ) );
```

**Step 2: Implement the API request helper**

```php
private function api_request( string $endpoint, string $method = 'GET', ?array $body = null ): array|\WP_Error {
	$api_key = $this->get_api_key();
	if ( '' === $api_key ) {
		return new \WP_Error( 'no_api_key', __( 'No Immich API key configured.', 'immich-media-picker' ) );
	}

	$url  = rtrim( $this->get_api_url(), '/' ) . $endpoint;
	$args = array(
		'headers' => array(
			'x-api-key'   => $api_key,
			'Accept'       => 'application/json',
			'Content-Type' => 'application/json',
		),
		'timeout' => 30,
	);

	if ( 'POST' === $method ) {
		$args['body'] = wp_json_encode( $body ?? array() );
		$response     = wp_remote_post( $url, $args );
	} else {
		$response = wp_remote_get( $url, $args );
	}

	if ( is_wp_error( $response ) ) {
		return $response;
	}

	$code = wp_remote_retrieve_response_code( $response );
	$json = json_decode( wp_remote_retrieve_body( $response ), true );

	if ( $code < 200 || $code >= 300 ) {
		return new \WP_Error(
			'immich_api_error',
			sprintf( 'Immich API returned %d', $code ),
			array( 'status' => $code, 'body' => $json )
		);
	}

	return $json ?? array();
}
```

**Step 3: Implement a shared AJAX permission check helper**

```php
private function verify_ajax_request(): bool {
	if ( ! check_ajax_referer( 'immich_nonce', 'nonce', false ) ) {
		wp_send_json_error( 'Invalid nonce.', 403 );
		return false;
	}
	if ( ! current_user_can( 'upload_files' ) ) {
		wp_send_json_error( 'Insufficient permissions.', 403 );
		return false;
	}
	return true;
}
```

**Step 4: Implement `ajax_search()`**

```php
public function ajax_search(): void {
	if ( ! $this->verify_ajax_request() ) {
		return;
	}

	$query      = sanitize_text_field( wp_unslash( $_POST['query'] ?? '' ) );
	$person_ids = array_map( 'sanitize_text_field', (array) ( $_POST['personIds'] ?? array() ) );

	if ( ! empty( $person_ids ) ) {
		$body     = array( 'personIds' => $person_ids );
		$response = $this->api_request( '/api/search/metadata', 'POST', $body );
	} elseif ( '' !== $query ) {
		$body     = array( 'query' => $query, 'size' => 50 );
		$response = $this->api_request( '/api/search/smart', 'POST', $body );
	} else {
		wp_send_json_success( array() );
		return;
	}

	if ( is_wp_error( $response ) ) {
		wp_send_json_error( $response->get_error_message() );
		return;
	}

	// smart search returns {assets: {items: [...]}}
	// metadata search returns {assets: {items: [...]}}
	$items  = $response['assets']['items'] ?? array();
	$result = array();
	foreach ( $items as $asset ) {
		$result[] = array(
			'id'       => $asset['id'],
			'thumbUrl' => admin_url( 'admin-ajax.php?action=immich_thumbnail&id=' . $asset['id'] . '&nonce=' . wp_create_nonce( 'immich_nonce' ) ),
			'filename' => $asset['originalFileName'] ?? $asset['id'] . '.jpg',
		);
	}

	wp_send_json_success( $result );
}
```

**Step 5: Implement `ajax_people()`**

```php
public function ajax_people(): void {
	if ( ! $this->verify_ajax_request() ) {
		return;
	}

	$response = $this->api_request( '/api/people' );

	if ( is_wp_error( $response ) ) {
		wp_send_json_error( $response->get_error_message() );
		return;
	}

	$people = $response['people'] ?? $response;
	$result = array();
	foreach ( $people as $person ) {
		if ( empty( $person['name'] ) ) {
			continue;
		}
		$result[] = array(
			'id'       => $person['id'],
			'name'     => $person['name'],
			'thumbUrl' => admin_url( 'admin-ajax.php?action=immich_thumbnail&id=' . $person['thumbnailPath'] . '&nonce=' . wp_create_nonce( 'immich_nonce' ) ),
		);
	}

	wp_send_json_success( $result );
}
```

**Step 6: Implement `ajax_thumbnail()`**

```php
public function ajax_thumbnail(): void {
	if ( ! $this->verify_ajax_request() ) {
		return;
	}

	$id = sanitize_text_field( $_GET['id'] ?? '' );
	// Validate UUID format.
	if ( ! preg_match( '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $id ) ) {
		wp_die( 'Invalid asset ID.', 400 );
	}

	$api_key = $this->get_api_key();
	if ( '' === $api_key ) {
		wp_die( 'No API key configured.', 500 );
	}

	$url      = rtrim( $this->get_api_url(), '/' ) . '/api/assets/' . $id . '/thumbnail';
	$response = wp_remote_get( $url, array(
		'headers' => array( 'x-api-key' => $api_key ),
		'timeout' => 30,
	) );

	if ( is_wp_error( $response ) ) {
		wp_die( 'Failed to fetch thumbnail.', 502 );
	}

	$content_type = wp_remote_retrieve_header( $response, 'content-type' ) ?: 'image/jpeg';
	$body         = wp_remote_retrieve_body( $response );

	header( 'Content-Type: ' . $content_type );
	header( 'Cache-Control: public, max-age=86400' );
	echo $body; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- binary image data
	exit;
}
```

**Step 7: Implement `ajax_import()`**

```php
public function ajax_import(): void {
	if ( ! $this->verify_ajax_request() ) {
		return;
	}

	$id = sanitize_text_field( wp_unslash( $_POST['id'] ?? '' ) );
	if ( ! preg_match( '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $id ) ) {
		wp_send_json_error( 'Invalid asset ID.' );
		return;
	}

	// Fetch asset info for the filename.
	$info = $this->api_request( '/api/assets/' . $id );
	if ( is_wp_error( $info ) ) {
		wp_send_json_error( $info->get_error_message() );
		return;
	}

	$filename = sanitize_file_name( $info['originalFileName'] ?? $id . '.jpg' );

	// Fetch original file.
	$api_key  = $this->get_api_key();
	$url      = rtrim( $this->get_api_url(), '/' ) . '/api/assets/' . $id . '/original';
	$response = wp_remote_get( $url, array(
		'headers' => array( 'x-api-key' => $api_key ),
		'timeout' => 120,
	) );

	if ( is_wp_error( $response ) ) {
		wp_send_json_error( 'Failed to download original: ' . $response->get_error_message() );
		return;
	}

	$body = wp_remote_retrieve_body( $response );
	if ( empty( $body ) ) {
		wp_send_json_error( 'Empty response from Immich.' );
		return;
	}

	// Save to uploads.
	$upload = wp_upload_bits( $filename, null, $body );
	if ( ! empty( $upload['error'] ) ) {
		wp_send_json_error( 'Upload failed: ' . $upload['error'] );
		return;
	}

	// Create attachment.
	$mime       = $upload['type'] ?: mime_content_type( $upload['file'] );
	$attachment = array(
		'post_title'     => pathinfo( $filename, PATHINFO_FILENAME ),
		'post_mime_type' => $mime,
		'post_status'    => 'inherit',
	);

	$attach_id = wp_insert_attachment( $attachment, $upload['file'] );
	if ( is_wp_error( $attach_id ) ) {
		wp_send_json_error( 'Failed to create attachment.' );
		return;
	}

	require_once ABSPATH . 'wp-admin/includes/image.php';
	$metadata = wp_generate_attachment_metadata( $attach_id, $upload['file'] );
	wp_update_attachment_metadata( $attach_id, $metadata );

	wp_send_json_success( array( 'attachmentId' => $attach_id ) );
}
```

**Step 8: Verify endpoints respond**

Using wp-env CLI or curl, test that hitting `admin-ajax.php?action=immich_search` without a nonce returns 403. (Full integration test requires an Immich server.)

**Step 9: Commit**

```bash
git add immich-media-picker.php
git commit -m "Add Immich API client and AJAX proxy endpoints"
```

---

### Task 5: Enqueue JS and pass config to frontend

**Files:**
- Modify: `immich-media-picker.php`

**Step 1: Add enqueue hook in the constructor**

```php
add_action( 'wp_enqueue_media', array( $this, 'enqueue_assets' ) );
```

**Step 2: Implement `enqueue_assets()`**

```php
public function enqueue_assets(): void {
	if ( ! current_user_can( 'upload_files' ) ) {
		return;
	}

	$api_key = $this->get_api_key();
	if ( '' === $api_key ) {
		return; // No key configured — don't show the tab.
	}

	wp_enqueue_script(
		'immich-media-picker',
		IMMICH_MEDIA_PICKER_URL . 'assets/js/immich-media-picker.js',
		array( 'jquery', 'media-views' ),
		IMMICH_MEDIA_PICKER_VERSION,
		true
	);

	wp_localize_script( 'immich-media-picker', 'ImmichMediaPicker', array(
		'ajaxUrl' => admin_url( 'admin-ajax.php' ),
		'nonce'   => wp_create_nonce( 'immich_nonce' ),
	) );
}
```

**Step 3: Verify in wp-env**

Go to any post editor, click "Add Media". Check browser console/network: `immich-media-picker.js` should be loaded.

**Step 4: Commit**

```bash
git add immich-media-picker.php
git commit -m "Enqueue media picker JS with AJAX config"
```

---

### Task 6: JS — Media modal Immich tab

**Files:**
- Modify: `assets/js/immich-media-picker.js`

This is the largest task. The JS extends the WordPress media modal to add a custom router tab with search, people filter, thumbnail grid, and import.

**Step 1: Write the Immich content view**

```js
(function ($, wp) {
	'use strict';

	if ( ! wp || ! wp.media || ! wp.media.view ) {
		return;
	}

	var config = window.ImmichMediaPicker || {};

	/**
	 * Immich browser content view.
	 */
	var ImmichBrowser = wp.media.View.extend({
		className: 'immich-browser',
		template: false,

		events: {
			'input .immich-search-input': 'onSearchInput',
			'change .immich-people-select': 'onPeopleChange',
			'click .immich-thumb': 'onThumbClick',
			'click .immich-import-btn': 'onImportClick',
		},

		initialize: function () {
			this.selected = {};
			this.searchTimer = null;
			this.render();
			this.loadPeople();
		},

		render: function () {
			this.$el.html(
				'<div class="immich-toolbar">' +
					'<input type="search" class="immich-search-input" placeholder="Search photos..." />' +
					'<select class="immich-people-select"><option value="">All people</option></select>' +
					'<button type="button" class="button button-primary immich-import-btn" disabled>Import Selected</button>' +
				'</div>' +
				'<div class="immich-grid"></div>' +
				'<div class="immich-status"></div>'
			);
			return this;
		},

		loadPeople: function () {
			var self = this;
			$.ajax({
				url: config.ajaxUrl,
				method: 'GET',
				data: { action: 'immich_people', nonce: config.nonce },
				dataType: 'json',
				success: function (resp) {
					if ( ! resp.success || ! resp.data ) return;
					var $select = self.$('.immich-people-select');
					resp.data.forEach(function (person) {
						$select.append(
							$('<option>').val(person.id).text(person.name)
						);
					});
				},
			});
		},

		onSearchInput: function () {
			var self = this;
			clearTimeout(this.searchTimer);
			this.searchTimer = setTimeout(function () {
				self.doSearch();
			}, 400);
		},

		onPeopleChange: function () {
			this.doSearch();
		},

		doSearch: function () {
			var self = this;
			var query = this.$('.immich-search-input').val();
			var personId = this.$('.immich-people-select').val();

			var data = {
				action: 'immich_search',
				nonce: config.nonce,
			};

			if (personId) {
				data.personIds = [personId];
			}
			if (query) {
				data.query = query;
			}

			if ( ! query && ! personId ) {
				this.$('.immich-grid').empty();
				return;
			}

			this.$('.immich-status').text('Searching...');

			$.ajax({
				url: config.ajaxUrl,
				method: 'POST',
				data: data,
				dataType: 'json',
				success: function (resp) {
					self.$('.immich-status').text('');
					if ( ! resp.success ) {
						self.$('.immich-status').text('Error: ' + (resp.data || 'Unknown error'));
						return;
					}
					self.renderGrid(resp.data || []);
				},
				error: function () {
					self.$('.immich-status').text('Request failed.');
				},
			});
		},

		renderGrid: function (items) {
			var self = this;
			var $grid = this.$('.immich-grid').empty();
			this.selected = {};
			this.updateImportButton();

			if ( ! items.length ) {
				$grid.html('<p class="immich-no-results">No results found.</p>');
				return;
			}

			items.forEach(function (item) {
				var $thumb = $(
					'<div class="immich-thumb" data-id="' + item.id + '" data-filename="' + _.escape(item.filename) + '">' +
						'<img src="' + item.thumbUrl + '" alt="' + _.escape(item.filename) + '" />' +
						'<span class="immich-check dashicons dashicons-yes-alt"></span>' +
					'</div>'
				);
				$grid.append($thumb);
			});
		},

		onThumbClick: function (e) {
			var $thumb = $(e.currentTarget);
			var id = $thumb.data('id');

			if ( this.selected[id] ) {
				delete this.selected[id];
				$thumb.removeClass('selected');
			} else {
				// If not multi-select mode, deselect others.
				if ( ! this.controller.options.multiple ) {
					this.$('.immich-thumb').removeClass('selected');
					this.selected = {};
				}
				this.selected[id] = true;
				$thumb.addClass('selected');
			}

			this.updateImportButton();
		},

		updateImportButton: function () {
			var count = Object.keys(this.selected).length;
			var $btn = this.$('.immich-import-btn');
			$btn.prop('disabled', count === 0);
			$btn.text(count > 1 ? 'Import ' + count + ' Selected' : 'Import Selected');
		},

		onImportClick: function () {
			var self = this;
			var ids = Object.keys(this.selected);
			if ( ! ids.length ) return;

			var $btn = this.$('.immich-import-btn');
			$btn.prop('disabled', true).text('Importing...');

			var imported = 0;
			var total = ids.length;

			ids.forEach(function (id) {
				$.ajax({
					url: config.ajaxUrl,
					method: 'POST',
					data: {
						action: 'immich_import',
						nonce: config.nonce,
						id: id,
					},
					dataType: 'json',
					success: function (resp) {
						imported++;
						if ( resp.success && resp.data && resp.data.attachmentId ) {
							var attachment = wp.media.attachment(resp.data.attachmentId);
							attachment.fetch().then(function () {
								self.controller.state().get('selection').add(attachment);
							});
						}
						if ( imported >= total ) {
							$btn.prop('disabled', false).text('Import Selected');
							self.$('.immich-status').text(imported + ' photo(s) imported.');
							// Clear selections.
							self.selected = {};
							self.$('.immich-thumb').removeClass('selected');
							self.updateImportButton();
						}
					},
					error: function () {
						imported++;
						if ( imported >= total ) {
							$btn.prop('disabled', false).text('Import Selected');
							self.$('.immich-status').text('Some imports failed.');
						}
					},
				});
			});
		},
	});

	/**
	 * Hook into the media modal to add the Immich tab.
	 */
	var originalPostFrameCreate = wp.media.view.MediaFrame.Post.prototype.browseRouter;

	wp.media.view.MediaFrame.Post.prototype.browseRouter = function (routerView) {
		originalPostFrameCreate.call(this, routerView);
		routerView.set('immich', {
			text: 'Immich',
			priority: 60,
		});
	};

	// Also hook the Select frame (used when "Set featured image", etc.).
	var originalSelectCreate = wp.media.view.MediaFrame.Select.prototype.browseRouter;

	if ( originalSelectCreate ) {
		wp.media.view.MediaFrame.Select.prototype.browseRouter = function (routerView) {
			originalSelectCreate.call(this, routerView);
			routerView.set('immich', {
				text: 'Immich',
				priority: 60,
			});
		};
	}

	// Render the Immich content when the tab is activated.
	var originalContent = wp.media.view.MediaFrame.Post.prototype.bindHandlers;

	wp.media.view.MediaFrame.Post.prototype.bindHandlers = function () {
		originalContent.call(this);
		this.on('content:create:immich', function () {
			var view = new ImmichBrowser({
				controller: this,
			});
			this.content.set(view);
		}, this);
	};

	// Same for Select frame.
	var originalSelectBind = wp.media.view.MediaFrame.Select.prototype.bindHandlers;

	if ( originalSelectBind ) {
		wp.media.view.MediaFrame.Select.prototype.bindHandlers = function () {
			originalSelectBind.call(this);
			this.on('content:create:immich', function () {
				var view = new ImmichBrowser({
					controller: this,
				});
				this.content.set(view);
			}, this);
		};
	}

})(jQuery, wp);
```

**Step 2: Add inline CSS via PHP**

Add to `enqueue_assets()` in `immich-media-picker.php`:

```php
wp_add_inline_style( 'media-views', '
	.immich-toolbar {
		display: flex;
		gap: 8px;
		padding: 16px;
		align-items: center;
		border-bottom: 1px solid #ddd;
	}
	.immich-search-input {
		flex: 1;
		min-width: 200px;
		padding: 6px 10px;
	}
	.immich-people-select {
		max-width: 200px;
	}
	.immich-grid {
		display: grid;
		grid-template-columns: repeat(auto-fill, minmax(150px, 1fr));
		gap: 8px;
		padding: 16px;
		overflow-y: auto;
		max-height: calc(100% - 80px);
	}
	.immich-thumb {
		position: relative;
		cursor: pointer;
		border: 3px solid transparent;
		border-radius: 4px;
		overflow: hidden;
		aspect-ratio: 1;
	}
	.immich-thumb img {
		width: 100%;
		height: 100%;
		object-fit: cover;
		display: block;
	}
	.immich-thumb .immich-check {
		display: none;
		position: absolute;
		top: 4px;
		right: 4px;
		color: #fff;
		background: #0073aa;
		border-radius: 50%;
		font-size: 20px;
		width: 24px;
		height: 24px;
		line-height: 24px;
		text-align: center;
	}
	.immich-thumb.selected {
		border-color: #0073aa;
	}
	.immich-thumb.selected .immich-check {
		display: block;
	}
	.immich-thumb:hover {
		border-color: #0073aa;
		opacity: 0.9;
	}
	.immich-status {
		padding: 8px 16px;
		color: #666;
	}
	.immich-no-results {
		grid-column: 1 / -1;
		text-align: center;
		color: #666;
		padding: 40px;
	}
	.immich-browser {
		height: 100%;
		display: flex;
		flex-direction: column;
	}
');
```

**Step 3: Verify in wp-env**

1. Open post editor, click "Add Media".
2. "Immich" tab should appear in the modal.
3. Click it — search box, people dropdown, and empty grid appear.
4. (Full functionality requires an Immich server on the network.)

**Step 4: Commit**

```bash
git add assets/js/immich-media-picker.js immich-media-picker.php
git commit -m "Add Immich tab to media modal with search, people filter, and import"
```

---

### Task 7: Final verification and cleanup

**Step 1: Full manual test**

With an Immich server reachable:
1. Configure API URL and key in Settings > Immich.
2. Open media modal, click Immich tab.
3. Search for a term — thumbnails appear.
4. Filter by person — thumbnails update.
5. Click thumbnail(s), click "Import Selected".
6. Imported photos appear in the media library.

**Step 2: Code review pass**

- Check all AJAX handlers have nonce + capability checks.
- Check all user input is sanitized.
- Check all output is escaped.
- Verify no API key is exposed to the browser.

**Step 3: Commit any fixes**

```bash
git add -A
git commit -m "Final cleanup and verification"
```
