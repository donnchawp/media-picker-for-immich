<?php
/**
 * Plugin Name: Immich Media Picker
 * Description: Use photos and videos from your Immich server in WordPress without copying files, or import them into the media library.
 * Version: 0.1.0
 * Requires at least: 6.4
 * Requires PHP: 8.0
 * Author: Donncha
 * Text Domain: immich-media-picker
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
		add_action( 'wp_ajax_immich_browse', array( $this, 'ajax_browse' ) );
		add_action( 'wp_ajax_immich_search', array( $this, 'ajax_search' ) );
		add_action( 'wp_ajax_immich_people', array( $this, 'ajax_people' ) );
		add_action( 'wp_ajax_immich_thumbnail', array( $this, 'ajax_thumbnail' ) );
		add_action( 'wp_ajax_immich_import', array( $this, 'ajax_import' ) );
		add_action( 'wp_ajax_immich_use', array( $this, 'ajax_use' ) );
		add_action( 'wp_ajax_immich_used_assets', array( $this, 'ajax_used_assets' ) );
		add_action( 'wp_enqueue_media', array( $this, 'enqueue_assets' ) );
		add_action( 'wp_enqueue_scripts', array( $this, 'register_frontend_assets' ) );
		add_action( 'init', array( $this, 'handle_proxy_request' ) );
		add_filter( 'wp_get_attachment_url', array( $this, 'filter_attachment_url' ), 10, 2 );
		add_filter( 'image_downsize', array( $this, 'filter_image_downsize' ), 10, 3 );
		add_filter( 'the_content', array( $this, 'maybe_enqueue_lightbox' ) );
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

	/**
	 * Public proxy endpoint — intentionally unauthenticated so proxied images
	 * work in published posts for anonymous visitors. The Immich API key is
	 * never exposed; it stays server-side. UUIDs are validated but not secret.
	 */
	public function handle_proxy_request(): void {
		if ( ! isset( $_GET['immich_media_proxy'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- public proxy endpoint, no auth required
			return;
		}

		$type = sanitize_key( $_GET['immich_media_proxy'] ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( ! in_array( $type, array( 'thumbnail', 'original', 'video' ), true ) ) {
			status_header( 400 );
			exit( 'Invalid type.' );
		}

		$id = sanitize_text_field( wp_unslash( $_GET['id'] ?? '' ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( ! preg_match( '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $id ) ) {
			status_header( 400 );
			exit( 'Invalid ID.' );
		}

		// Look up the attachment author so we use their API key, not the viewer's.
		$author_id  = 0;
		$attachments = get_posts( array(
			'post_type'      => 'attachment',
			'post_status'    => 'inherit',
			'posts_per_page' => 1,
			'meta_key'       => '_immich_asset_id', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
			'meta_value'     => $id, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
			'fields'         => 'ids',
		) );
		if ( ! empty( $attachments ) ) {
			$author_id = (int) get_post_field( 'post_author', $attachments[0] );
		}

		$api_key = $this->get_api_key( $author_id );
		if ( '' === $api_key ) {
			status_header( 500 );
			exit( 'No API key configured.' );
		}

		if ( 'video' === $type ) {
			$base = rtrim( $this->get_api_url(), '/' );
			$this->stream_video( $base . '/api/assets/' . $id . '/video/playback', $api_key );
			return;
		}

		$base    = rtrim( $this->get_api_url(), '/' );
		$api_url = 'thumbnail' === $type
			? $base . '/api/assets/' . $id . '/thumbnail'
			: $base . '/api/assets/' . $id . '/original';
		$timeout = 'thumbnail' === $type ? 10 : 30;

		// Stream originals via temp file to avoid buffering large files in memory.
		if ( 'original' === $type ) {
			$tmp_file = tempnam( get_temp_dir(), 'immich_' );
			$response = wp_remote_get( $api_url, array(
				'headers'  => array( 'x-api-key' => $api_key ),
				'timeout'  => $timeout,
				'stream'   => true,
				'filename' => $tmp_file,
			) );

			if ( is_wp_error( $response ) ) {
				@unlink( $tmp_file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
				status_header( 502 );
				exit( 'Upstream error.' );
			}

			$code = wp_remote_retrieve_response_code( $response );
			if ( $code < 200 || $code >= 300 ) {
				wp_delete_file( $tmp_file );
				status_header( (int) $code );
				exit( 'Asset not available.' );
			}

			$content_type  = strtok( wp_remote_retrieve_header( $response, 'content-type' ) ?: 'application/octet-stream', ';' );
			$allowed_types = array( 'image/jpeg', 'image/webp', 'image/png', 'image/gif', 'image/tiff', 'video/mp4', 'video/quicktime' );
			if ( ! in_array( $content_type, $allowed_types, true ) ) {
				wp_delete_file( $tmp_file );
				status_header( 502 );
				exit( 'Unexpected content type.' );
			}

			header( 'Content-Type: ' . $content_type );
			header( 'Content-Length: ' . filesize( $tmp_file ) );
			header( 'Cache-Control: public, max-age=31536000' );
			header( 'X-Content-Type-Options: nosniff' );
			readfile( $tmp_file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_readfile
			wp_delete_file( $tmp_file );
			exit;
		}

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

		$content_type  = strtok( wp_remote_retrieve_header( $response, 'content-type' ) ?: 'application/octet-stream', ';' );
		$allowed_types = array( 'image/jpeg', 'image/webp', 'image/png', 'image/gif' );
		if ( ! in_array( $content_type, $allowed_types, true ) ) {
			status_header( 502 );
			exit( 'Unexpected content type.' );
		}
		$body = wp_remote_retrieve_body( $response );

		header( 'Content-Type: ' . $content_type );
		header( 'Content-Length: ' . mb_strlen( $body, '8bit' ) );
		header( 'Cache-Control: public, max-age=31536000' );
		header( 'X-Content-Type-Options: nosniff' );
		echo $body; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- binary image data
		exit;
	}

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
				'max_redirects' => 0,
			),
		) );

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- streaming binary video data from remote API
		$remote = fopen( $url, 'rb', false, $context );
		if ( ! $remote ) {
			status_header( 502 );
			exit( 'Failed to connect to Immich.' );
		}

		// Parse response headers from stream metadata.
		$meta             = stream_get_meta_data( $remote );
		$response_headers = $meta['wrapper_data'] ?? array();
		$status_code      = 200;
		$content_type     = '';

		foreach ( $response_headers as $header_line ) {
			if ( preg_match( '/^HTTP\/[\d.]+ (\d+)/', $header_line, $m ) ) {
				$status_code = (int) $m[1];
			} elseif ( preg_match( '/^(Content-Type|Content-Length|Content-Range|Accept-Ranges):\s*(.+)/i', $header_line, $m ) ) {
				if ( 'content-type' === strtolower( $m[1] ) ) {
					$content_type = strtok( trim( $m[2] ), ';' );
				} else {
					header( $m[1] . ': ' . trim( $m[2] ) );
				}
			}
		}

		// Validate status before content-type (error responses may have non-video content types).
		if ( $status_code < 200 || ( $status_code >= 300 && 206 !== $status_code ) ) {
			fclose( $remote ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
			status_header( $status_code >= 500 ? 502 : $status_code );
			exit( 'Asset not available.' );
		}

		$allowed_video_types = array( 'video/mp4', 'video/webm', 'video/ogg', 'video/quicktime' );
		if ( ! in_array( $content_type, $allowed_video_types, true ) ) {
			fclose( $remote ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
			status_header( 502 );
			exit( 'Unexpected content type.' );
		}

		header( 'Content-Type: ' . $content_type );
		status_header( $status_code );
		header( 'X-Content-Type-Options: nosniff' );
		if ( 206 === $status_code ) {
			header( 'Cache-Control: no-store' );
		} else {
			header( 'Cache-Control: public, max-age=31536000' );
		}

		// Flush any output buffers to enable true streaming.
		while ( ob_get_level() > 0 ) {
			ob_end_clean();
		}

		// Stream in 8KB chunks.
		while ( ! feof( $remote ) ) {
			echo fread( $remote, 8192 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped, WordPress.WP.AlternativeFunctions.file_system_operations_fread
			flush();
		}

		fclose( $remote ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		exit;
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

		// On the Media Library page, depend on media-grid so our script
		// loads after MediaFrame.Manage is defined.
		$deps = array( 'jquery', 'media-views', 'wp-i18n' );
		$screen = get_current_screen();
		if ( $screen && 'upload' === $screen->id ) {
			$deps[] = 'media-grid';
		}

		wp_enqueue_script(
			'immich-media-picker',
			IMMICH_MEDIA_PICKER_URL . 'assets/js/immich-media-picker.js',
			$deps,
			filemtime( IMMICH_MEDIA_PICKER_DIR . 'assets/js/immich-media-picker.js' ),
			true
		);

		wp_set_script_translations( 'immich-media-picker', 'immich-media-picker' );

		wp_localize_script( 'immich-media-picker', 'ImmichMediaPicker', array(
			'ajaxUrl' => admin_url( 'admin-ajax.php' ),
			'nonce'   => wp_create_nonce( 'immich_nonce' ),
		) );

		wp_enqueue_style(
			'immich-media-picker',
			plugin_dir_url( __FILE__ ) . 'assets/css/immich-media-picker.css',
			array( 'media-views' ),
			filemtime( plugin_dir_path( __FILE__ ) . 'assets/css/immich-media-picker.css' )
		);
	}

	public function register_frontend_assets(): void {
		wp_register_style(
			'immich-lightbox',
			IMMICH_MEDIA_PICKER_URL . 'assets/css/immich-lightbox.css',
			array(),
			filemtime( IMMICH_MEDIA_PICKER_DIR . 'assets/css/immich-lightbox.css' )
		);

		wp_register_script(
			'immich-lightbox',
			IMMICH_MEDIA_PICKER_URL . 'assets/js/immich-lightbox.js',
			array(),
			filemtime( IMMICH_MEDIA_PICKER_DIR . 'assets/js/immich-lightbox.js' ),
			true
		);
	}

	public function maybe_enqueue_lightbox( string $content ): string {
		if ( ! str_contains( $content, 'immich_media_proxy=original' ) ) {
			return $content;
		}

		// Add data-immich-lightbox to <a> tags that link to an Immich original
		// AND directly contain an <img>. Links wrapping video or other content
		// are left alone.
		$content = preg_replace(
			'/(<a\b[^>]*href="[^"]*immich_media_proxy=original[^"]*"[^>]*)(>)\s*(<img\b)/si',
			'$1 data-immich-lightbox$2$3',
			$content
		);

		if ( str_contains( $content, 'data-immich-lightbox' ) ) {
			wp_enqueue_style( 'immich-lightbox' );
			wp_enqueue_script( 'immich-lightbox' );
		}

		return $content;
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

	public function filter_attachment_url( string $url, int $attachment_id ): string {
		$immich_id = get_post_meta( $attachment_id, '_immich_asset_id', true );
		if ( ! $immich_id ) {
			return $url;
		}

		$asset_type = get_post_meta( $attachment_id, '_immich_asset_type', true );
		if ( 'VIDEO' === $asset_type ) {
			return home_url( '/?immich_media_proxy=video&id=' . rawurlencode( $immich_id ) );
		}

		return home_url( '/?immich_media_proxy=original&id=' . rawurlencode( $immich_id ) );
	}

	public function filter_image_downsize( $downsize, int $attachment_id, $size ) {
		$immich_id = get_post_meta( $attachment_id, '_immich_asset_id', true );
		if ( ! $immich_id ) {
			return $downsize;
		}

		// Skip videos — let WordPress handle them as video type so Gutenberg
		// creates a Video block instead of an Image block.
		$asset_type = get_post_meta( $attachment_id, '_immich_asset_type', true );
		if ( 'VIDEO' === $asset_type ) {
			return $downsize;
		}

		$meta      = wp_get_attachment_metadata( $attachment_id );
		$width     = $meta['width'] ?? 0;
		$height    = $meta['height'] ?? 0;
		$size_slug = is_array( $size ) ? '' : $size;

		if ( 'full' === $size_slug ) {
			return array(
				home_url( '/?immich_media_proxy=original&id=' . rawurlencode( $immich_id ) ),
				$width,
				$height,
				false,
			);
		}

		// For array sizes (e.g. [100, 100] from srcset), use the requested dimensions.
		if ( is_array( $size ) ) {
			return array(
				home_url( '/?immich_media_proxy=thumbnail&id=' . rawurlencode( $immich_id ) ),
				(int) ( $size[0] ?? 250 ),
				(int) ( $size[1] ?? 250 ),
				true,
			);
		}

		// Return accurate dimensions from stored metadata when available.
		$size_data = $meta['sizes'][ $size_slug ] ?? null;
		$w         = $size_data['width'] ?? 250;
		$h         = $size_data['height'] ?? 250;

		return array(
			home_url( '/?immich_media_proxy=thumbnail&id=' . rawurlencode( $immich_id ) ),
			$w,
			$h,
			true,
		);
	}

	public function ajax_browse(): void {
		if ( ! $this->verify_ajax_request() ) {
			return;
		}

		$page = absint( $_GET['page'] ?? 1 ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- verified in verify_ajax_request()
		$page = max( 1, $page );

		$response = $this->api_request( '/api/search/metadata', 'POST', array(
			'size'  => 50,
			'page'  => $page,
			'order' => 'desc',
		) );

		if ( is_wp_error( $response ) ) {
			wp_send_json_error( $response->get_error_message() );
			return;
		}

		$items     = $response['assets']['items'] ?? array();
		$next_page = $response['assets']['nextPage'] ?? null;
		$result    = array();
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

		wp_send_json_success( array( 'items' => $result, 'nextPage' => $next_page ) );
	}

	public function ajax_search(): void {
		if ( ! $this->verify_ajax_request() ) {
			return;
		}

		$query        = sanitize_text_field( wp_unslash( $_POST['query'] ?? '' ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified in verify_ajax_request()
		$page         = absint( $_POST['page'] ?? 1 ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified in verify_ajax_request()
		$page         = max( 1, $page );
		$uuid_pattern = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i';
		$person_ids   = array_filter(
			array_map( 'sanitize_text_field', isset( $_POST['personIds'] ) ? (array) wp_unslash( $_POST['personIds'] ) : array() ), // phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified in verify_ajax_request()
			fn( $id ) => preg_match( $uuid_pattern, $id )
		);

		if ( '' !== $query ) {
			$body = array( 'query' => $query, 'size' => 50, 'page' => $page );
			if ( ! empty( $person_ids ) ) {
				$body['personIds'] = $person_ids;
			}
			$response = $this->api_request( '/api/search/smart', 'POST', $body );
		} elseif ( ! empty( $person_ids ) ) {
			$body     = array( 'personIds' => $person_ids, 'size' => 50, 'page' => $page );
			$response = $this->api_request( '/api/search/metadata', 'POST', $body );
		} else {
			wp_send_json_success( array( 'items' => array(), 'nextPage' => null ) );
			return;
		}

		if ( is_wp_error( $response ) ) {
			wp_send_json_error( $response->get_error_message() );
			return;
		}

		$items    = $response['assets']['items'] ?? array();
		$next_page = $response['assets']['nextPage'] ?? null;
		$result   = array();
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

		wp_send_json_success( array( 'items' => $result, 'nextPage' => $next_page ) );
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

		$id = sanitize_text_field( wp_unslash( $_GET['id'] ?? '' ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- verified in verify_ajax_request()
		if ( ! preg_match( '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $id ) ) {
			wp_die( 'Invalid asset ID.', 400 );
		}

		$api_key = $this->get_api_key();
		if ( '' === $api_key ) {
			wp_die( 'No API key configured.', 500 );
		}

		$type = sanitize_key( $_GET['type'] ?? 'asset' ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- verified in verify_ajax_request()
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

		header( 'X-Content-Type-Options: nosniff' );
		header( 'Content-Type: ' . $content_type );
		header( 'Cache-Control: public, max-age=86400' );
		echo $body; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- binary image data
		exit;
	}

	public function ajax_import(): void {
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

		// Check MIME before moving to public directory.
		$mime          = mime_content_type( $tmp_file ) ?: 'application/octet-stream';
		$allowed_mimes = array( 'image/jpeg', 'image/png', 'image/gif', 'image/webp', 'image/tiff', 'video/mp4', 'video/quicktime' );
		if ( ! in_array( $mime, $allowed_mimes, true ) ) {
			wp_delete_file( $tmp_file );
			wp_send_json_error( 'Unsupported file type.' );
			return;
		}

		$upload_dir = wp_upload_dir();
		$dest       = wp_unique_filename( $upload_dir['path'], $filename );
		$dest_path  = trailingslashit( $upload_dir['path'] ) . $dest;

		if ( ! copy( $tmp_file, $dest_path ) ) {
			wp_delete_file( $tmp_file );
			wp_send_json_error( 'Failed to move file to uploads.' );
			return;
		}
		wp_delete_file( $tmp_file );

		// Set correct permissions matching the parent directory.
		$stat  = stat( dirname( $dest_path ) );
		$perms = $stat['mode'] & 0000666;
		if ( function_exists( 'WP_Filesystem' ) && WP_Filesystem() ) {
			global $wp_filesystem;
			$wp_filesystem->chmod( $dest_path, $perms );
		}

		$dest_url = trailingslashit( $upload_dir['url'] ) . $dest;

		$attachment = array(
			'guid'           => $dest_url,
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

		$filename      = sanitize_file_name( $info['originalFileName'] ?? $id . '.jpg' );
		$raw_type      = $info['type'] ?? 'IMAGE';
		$asset_type    = in_array( $raw_type, array( 'IMAGE', 'VIDEO' ), true ) ? $raw_type : 'IMAGE';
		$allowed_mimes = array( 'image/jpeg', 'image/png', 'image/gif', 'image/webp', 'image/tiff', 'video/mp4', 'video/quicktime' );
		$mime          = $info['originalMimeType'] ?? '';
		if ( ! in_array( $mime, $allowed_mimes, true ) ) {
			$mime = 'VIDEO' === $asset_type ? 'video/mp4' : 'image/jpeg';
		}
		$width  = (int) ( $info['exifInfo']['exifImageWidth'] ?? 0 );
		$height = (int) ( $info['exifInfo']['exifImageHeight'] ?? 0 );

		// Return existing attachment if this asset was already used.
		$existing = get_posts( array(
			'post_type'      => 'attachment',
			'post_status'    => 'inherit',
			'posts_per_page' => 1,
			'meta_key'       => '_immich_asset_id', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
			'meta_value'     => $id, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
			'fields'         => 'ids',
		) );
		if ( ! empty( $existing ) ) {
			wp_send_json_success( array( 'attachmentId' => $existing[0] ) );
			return;
		}

		$proxy_type = 'VIDEO' === $asset_type ? 'video' : 'original';
		$attachment = array(
			'post_author'    => get_current_user_id(),
			'post_title'     => pathinfo( $filename, PATHINFO_FILENAME ),
			'post_mime_type' => $mime,
			'post_status'    => 'inherit',
			'guid'           => home_url( '/?immich_media_proxy=' . $proxy_type . '&id=' . rawurlencode( $id ) ),
		);

		$attach_id = wp_insert_attachment( $attachment );
		if ( is_wp_error( $attach_id ) ) {
			wp_send_json_error( 'Failed to create attachment.' );
			return;
		}

		update_post_meta( $attach_id, '_immich_asset_id', $id );
		update_post_meta( $attach_id, '_immich_asset_type', $asset_type );

		$metadata = array( 'file' => 'immich-proxy/' . $id );
		if ( $width > 0 && $height > 0 ) {
			$metadata['width']  = $width;
			$metadata['height'] = $height;
		}
		if ( 'IMAGE' === $asset_type && $width > 0 && $height > 0 ) {
			$metadata['sizes'] = array(
				'thumbnail' => array(
					'width'     => min( $width, 250 ),
					'height'    => (int) round( $height * min( $width, 250 ) / max( $width, 1 ) ),
					'file'      => $id,
					'mime-type' => $mime,
				),
				'medium' => array(
					'width'     => min( $width, 600 ),
					'height'    => (int) round( $height * min( $width, 600 ) / max( $width, 1 ) ),
					'file'      => $id,
					'mime-type' => $mime,
				),
				'large' => array(
					'width'     => min( $width, 1024 ),
					'height'    => (int) round( $height * min( $width, 1024 ) / max( $width, 1 ) ),
					'file'      => $id,
					'mime-type' => $mime,
				),
			);
		}
		wp_update_attachment_metadata( $attach_id, $metadata );

		wp_send_json_success( array( 'attachmentId' => $attach_id ) );
	}

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
			'meta_key'       => '_immich_asset_id', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
			'meta_compare'   => 'EXISTS',
			'orderby'        => 'date',
			'order'          => 'DESC',
		) );

		$items = array();
		foreach ( $query->posts as $post ) {
			$immich_id = get_post_meta( $post->ID, '_immich_asset_id', true );
			if ( ! $immich_id ) {
				continue;
			}
			$items[] = array(
				'attachmentId' => $post->ID,
				'immichId'     => $immich_id,
				'title'        => $post->post_title,
				'thumbUrl'     => home_url( '/?immich_media_proxy=thumbnail&id=' . rawurlencode( $immich_id ) ),
			);
		}

		$has_more = ( $page * $per_page ) < $query->found_posts;

		wp_send_json_success( array(
			'items'    => $items,
			'nextPage' => $has_more ? $page + 1 : null,
			'total'    => $query->found_posts,
		) );
	}
}

add_action( 'plugins_loaded', function () {
	new Immich_Media_Picker();
} );
