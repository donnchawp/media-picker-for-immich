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

	private const DEFAULT_API_URL = 'http://immich-server:2283';

	public function __construct() {
		add_action( 'admin_menu', array( $this, 'add_settings_page' ) );
		add_action( 'admin_init', array( $this, 'register_settings' ) );
		add_action( 'show_user_profile', array( $this, 'render_user_api_key_field' ) );
		add_action( 'edit_user_profile', array( $this, 'render_user_api_key_field' ) );
		add_action( 'personal_options_update', array( $this, 'save_user_api_key' ) );
		add_action( 'edit_user_profile_update', array( $this, 'save_user_api_key' ) );
		add_action( 'wp_ajax_immich_search', array( $this, 'ajax_search' ) );
		add_action( 'wp_ajax_immich_people', array( $this, 'ajax_people' ) );
		add_action( 'wp_ajax_immich_thumbnail', array( $this, 'ajax_thumbnail' ) );
		add_action( 'wp_ajax_immich_import', array( $this, 'ajax_import' ) );
		add_action( 'wp_enqueue_media', array( $this, 'enqueue_assets' ) );
	}

	public function add_settings_page(): void {
		add_options_page(
			__( 'Immich Settings', 'immich-media-picker' ),
			__( 'Immich', 'immich-media-picker' ),
			'manage_options',
			'immich-media-picker',
			array( $this, 'render_settings_page' )
		);
	}

	public function register_settings(): void {
		register_setting(
			'immich_settings_group',
			'immich_settings',
			array(
				'type'              => 'array',
				'sanitize_callback' => array( $this, 'sanitize_settings' ),
				'default'           => array(
					'api_url' => self::DEFAULT_API_URL,
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

	public function sanitize_settings( array $input ): array {
		$existing = get_option( 'immich_settings', array() );

		$api_key = sanitize_text_field( $input['api_key'] ?? '' );

		$raw_url = $input['api_url'] ?? '';
		$api_url = esc_url_raw( $raw_url );
		if ( '' === $api_url && '' !== $raw_url ) {
			add_settings_error(
				'immich_settings',
				'invalid_api_url',
				__( 'The API URL you entered is not valid. The previous URL has been kept.', 'immich-media-picker' ),
				'error'
			);
			$api_url = $existing['api_url'] ?? '';
		}

		return array(
			'api_url' => $api_url,
			'api_key' => $api_key,
		);
	}

	public function render_api_url_field(): void {
		$settings = get_option( 'immich_settings', array() );
		$value    = $settings['api_url'] ?? self::DEFAULT_API_URL;
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

	private function get_api_key( int $user_id = 0 ): string {
		$settings = get_option( 'immich_settings', array() );
		if ( ! empty( $settings['api_key'] ) ) {
			return $settings['api_key'];
		}
		if ( 0 === $user_id ) {
			$user_id = get_current_user_id();
		}
		if ( 0 === $user_id ) {
			return '';
		}
		return get_user_meta( $user_id, 'immich_api_key', true ) ?: '';
	}

	private function get_api_url(): string {
		$settings = get_option( 'immich_settings', array() );
		return $settings['api_url'] ?? self::DEFAULT_API_URL;
	}

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

	public function enqueue_assets(): void {
		if ( ! current_user_can( 'upload_files' ) ) {
			return;
		}

		$api_key = $this->get_api_key();
		if ( '' === $api_key ) {
			return;
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
		' );
	}

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

	public function render_user_api_key_field( \WP_User $user ): void {
		$settings = get_option( 'immich_settings', array() );
		// Hide if site-wide key is set.
		if ( ! empty( $settings['api_key'] ) ) {
			return;
		}
		$value = get_user_meta( $user->ID, 'immich_api_key', true );
		?>
		<h2><?php esc_html_e( 'Immich', 'immich-media-picker' ); ?></h2>
		<?php wp_nonce_field( 'immich_save_user_api_key_' . $user->ID, 'immich_user_api_key_nonce' ); ?>
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
		if ( ! isset( $_POST['immich_user_api_key_nonce'] ) ) {
			return;
		}
		if ( ! wp_verify_nonce(
			sanitize_text_field( wp_unslash( $_POST['immich_user_api_key_nonce'] ) ),
			'immich_save_user_api_key_' . $user_id
		) ) {
			return;
		}
		if ( ! current_user_can( 'edit_user', $user_id ) ) {
			return;
		}
		$key = sanitize_text_field( wp_unslash( $_POST['immich_api_key'] ?? '' ) );
		update_user_meta( $user_id, 'immich_api_key', $key );
	}

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

		$items  = $response['assets']['items'] ?? array();
		$result = array();
		foreach ( $items as $asset ) {
			if ( ! preg_match( '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $asset['id'] ?? '' ) ) {
				continue;
			}
			$result[] = array(
				'id'       => $asset['id'],
				'thumbUrl' => admin_url( 'admin-ajax.php?action=immich_thumbnail&id=' . rawurlencode( $asset['id'] ) . '&nonce=' . wp_create_nonce( 'immich_nonce' ) ),
				'filename' => $asset['originalFileName'] ?? $asset['id'] . '.jpg',
			);
		}

		wp_send_json_success( $result );
	}

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
				'thumbUrl' => admin_url( 'admin-ajax.php?action=immich_thumbnail&type=person&id=' . urlencode( $person['id'] ) . '&nonce=' . wp_create_nonce( 'immich_nonce' ) ),
			);
		}

		wp_send_json_success( $result );
	}

	public function ajax_thumbnail(): void {
		if ( ! $this->verify_ajax_request() ) {
			return;
		}

		$id = sanitize_text_field( wp_unslash( $_GET['id'] ?? '' ) );
		if ( ! preg_match( '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $id ) ) {
			wp_die( 'Invalid asset ID.', 400 );
		}

		$api_key = $this->get_api_key();
		if ( '' === $api_key ) {
			wp_die( 'No API key configured.', 500 );
		}

		$type = sanitize_key( $_GET['type'] ?? 'asset' );
		$url  = 'person' === $type
			? rtrim( $this->get_api_url(), '/' ) . '/api/people/' . $id . '/thumbnail'
			: rtrim( $this->get_api_url(), '/' ) . '/api/assets/' . $id . '/thumbnail';

		$response = wp_remote_get( $url, array(
			'headers' => array( 'x-api-key' => $api_key ),
			'timeout' => 30,
		) );

		if ( is_wp_error( $response ) ) {
			wp_die( 'Failed to fetch thumbnail.', 502 );
		}

		$code = wp_remote_retrieve_response_code( $response );
		if ( $code < 200 || $code >= 300 ) {
			wp_die( 'Thumbnail not available.', (int) $code );
		}

		$content_type = strtok( wp_remote_retrieve_header( $response, 'content-type' ) ?: 'image/jpeg', ';' );
		$allowed      = array( 'image/jpeg', 'image/webp', 'image/png', 'image/gif' );
		if ( ! in_array( $content_type, $allowed, true ) ) {
			$content_type = 'image/jpeg';
		}
		$body = wp_remote_retrieve_body( $response );

		header( 'Content-Type: ' . $content_type );
		header( 'Cache-Control: public, max-age=86400' );
		echo $body; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- binary image data
		exit;
	}

	public function ajax_import(): void {
		if ( ! $this->verify_ajax_request() ) {
			return;
		}

		$id = sanitize_text_field( wp_unslash( $_POST['id'] ?? '' ) );
		if ( ! preg_match( '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $id ) ) {
			wp_send_json_error( 'Invalid asset ID.' );
			return;
		}

		$info = $this->api_request( '/api/assets/' . $id );
		if ( is_wp_error( $info ) ) {
			wp_send_json_error( $info->get_error_message() );
			return;
		}

		$filename = sanitize_file_name( $info['originalFileName'] ?? $id . '.jpg' );

		$api_key  = $this->get_api_key();
		$tmp_file = wp_tempnam( $filename );
		$url      = rtrim( $this->get_api_url(), '/' ) . '/api/assets/' . $id . '/original';
		$response = wp_remote_get( $url, array(
			'headers'  => array( 'x-api-key' => $api_key ),
			'timeout'  => 120,
			'stream'   => true,
			'filename' => $tmp_file,
		) );

		if ( is_wp_error( $response ) ) {
			wp_delete_file( $tmp_file );
			wp_send_json_error( 'Failed to download original: ' . $response->get_error_message() );
			return;
		}

		$code = wp_remote_retrieve_response_code( $response );
		if ( $code < 200 || $code >= 300 ) {
			wp_delete_file( $tmp_file );
			wp_send_json_error( 'Immich returned HTTP ' . $code );
			return;
		}

		$upload_dir = wp_upload_dir();
		$dest       = wp_unique_filename( $upload_dir['path'], $filename );
		$dest_path  = trailingslashit( $upload_dir['path'] ) . $dest;

		if ( ! rename( $tmp_file, $dest_path ) ) {
			wp_delete_file( $tmp_file );
			wp_send_json_error( 'Failed to move file to uploads.' );
			return;
		}

		// Set correct permissions.
		$stat = stat( dirname( $dest_path ) );
		@chmod( $dest_path, $stat['mode'] & 0000666 );

		$dest_url = trailingslashit( $upload_dir['url'] ) . $dest;

		$mime       = mime_content_type( $dest_path ) ?: 'application/octet-stream';
		$attachment = array(
			'post_title'     => pathinfo( $filename, PATHINFO_FILENAME ),
			'post_mime_type' => $mime,
			'post_status'    => 'inherit',
		);

		$attach_id = wp_insert_attachment( $attachment, $dest_path );
		if ( is_wp_error( $attach_id ) ) {
			wp_delete_file( $dest_path );
			wp_send_json_error( 'Failed to create attachment.' );
			return;
		}

		require_once ABSPATH . 'wp-admin/includes/image.php';
		$metadata = wp_generate_attachment_metadata( $attach_id, $dest_path );
		wp_update_attachment_metadata( $attach_id, $metadata );

		wp_send_json_success( array( 'attachmentId' => $attach_id ) );
	}
}

add_action( 'plugins_loaded', function () {
	new Immich_Media_Picker();
} );
