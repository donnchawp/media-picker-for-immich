<?php
/**
 * Plugin Name: Media Picker for Immich
 * Description: Use photos and videos from your Immich server in WordPress without copying files, or import them into the media library.
 * Version: 0.1.0
 * Requires at least: 6.4
 * Requires PHP: 8.0
 * Author: Donncha
 * Text Domain: media-picker-for-immich
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

	private const COPY_SIZE_CHOICES = array( 'original', 'fullsize', 'preview', 'thumbnail' );

	/**
	 * Singleton instance, set in the constructor.
	 */
	private static ?self $the_instance = null;

	/**
	 * Retrieve the running plugin instance.
	 *
	 * Used by the block render shim (`includes/block-album-gallery/render.php`)
	 * to dispatch into the class method without holding a separate handle.
	 */
	public static function instance(): self {
		if ( null === self::$the_instance ) {
			self::$the_instance = new self();
		}
		return self::$the_instance;
	}

	/**
	 * The minimum Immich API key permissions the plugin needs to function.
	 *
	 * Single source of truth used by both the Settings page and the per-user
	 * profile field; readme.txt mirrors this list manually.
	 */
	private function required_api_key_permissions(): array {
		return array(
			'asset.read'     => __( 'List asset metadata and run library searches (browse, search by query, search by person).', 'media-picker-for-immich' ),
			'asset.view'     => __( 'Stream thumbnails and video playback through the proxy.', 'media-picker-for-immich' ),
			'asset.download' => __( 'Fetch full-resolution originals for the proxy and the Copy/import path.', 'media-picker-for-immich' ),
			'person.read'    => __( 'Populate the people filter dropdown and people thumbnails.', 'media-picker-for-immich' ),
		);
	}

	private function render_required_permissions_help(): void {
		echo '<p class="description">' . esc_html__( 'Your Immich API key must include these permissions:', 'media-picker-for-immich' ) . '</p>';
		echo '<ul class="immich-required-perms" style="margin-top:4px;">';
		foreach ( $this->required_api_key_permissions() as $slug => $description ) {
			printf(
				'<li><code>%s</code> &mdash; %s</li>',
				esc_html( $slug ),
				esc_html( $description )
			);
		}
		echo '</ul>';
	}

	public function __construct() {
		add_action( 'admin_menu', array( $this, 'add_settings_page' ) );
		add_action( 'admin_menu', array( $this, 'add_cache_files_page' ) );
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
		add_action( 'wp_ajax_immich_save_picker_split', array( $this, 'ajax_save_picker_split' ) );
		add_action( 'wp_ajax_immich_test_connection', array( $this, 'ajax_test_connection' ) );
		add_action( 'wp_ajax_immich_albums', array( $this, 'ajax_albums' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_admin_assets' ) );
		add_action( 'wp_enqueue_media', array( $this, 'enqueue_assets' ) );
		add_action( 'wp_enqueue_scripts', array( $this, 'register_frontend_assets' ) );
		add_action( 'init', array( $this, 'handle_proxy_request' ) );
		add_action( 'init', array( $this, 'maybe_schedule_add_mode_upgrade' ) );
		add_action( 'init', array( $this, 'register_blocks' ) );
		add_action( 'immich_upgrade_add_mode', array( $this, 'run_add_mode_upgrade' ) );
		add_filter( 'wp_get_attachment_url', array( $this, 'filter_attachment_url' ), 10, 2 );
		add_filter( 'image_downsize', array( $this, 'filter_image_downsize' ), 10, 3 );
		add_filter( 'the_content', array( $this, 'maybe_enqueue_lightbox' ) );
		add_action( 'immich_cache_gc', array( $this, 'run_cache_gc' ) );
	}

	/**
	 * Schedule a one-shot WP-Cron event to backfill _immich_add_mode meta on
	 * existing Immich-tracked attachments. Idempotent: only runs while the
	 * upgrade option is unset and no event is already pending.
	 */
	public function maybe_schedule_add_mode_upgrade(): void {
		if ( get_option( 'immich_add_mode_upgrade_done' ) ) {
			return;
		}
		if ( wp_next_scheduled( 'immich_upgrade_add_mode' ) ) {
			return;
		}
		wp_schedule_single_event( time() + 5, 'immich_upgrade_add_mode' );
	}

	public function run_add_mode_upgrade(): void {
		$batch_size = 200;
		$query      = new \WP_Query( array(
			'post_type'      => 'attachment',
			'post_status'    => 'inherit',
			'posts_per_page' => $batch_size,
			'fields'         => 'ids',
			// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- one-shot upgrade
			'meta_query'     => array(
				'relation' => 'AND',
				array(
					'key'     => '_immich_asset_id',
					'compare' => 'EXISTS',
				),
				array(
					'key'     => '_immich_add_mode',
					'compare' => 'NOT EXISTS',
				),
			),
		) );

		foreach ( $query->posts as $attach_id ) {
			$file = get_post_meta( $attach_id, '_wp_attached_file', true );
			$mode = $file ? 'copy' : 'select';
			update_post_meta( $attach_id, '_immich_add_mode', $mode );
		}

		if ( $query->found_posts > $batch_size ) {
			wp_schedule_single_event( time() + 5, 'immich_upgrade_add_mode' );
		} else {
			update_option( 'immich_add_mode_upgrade_done', 1, false );
		}
	}

	public function add_settings_page(): void {
		add_options_page(
			__( 'Immich Settings', 'media-picker-for-immich' ),
			__( 'Immich', 'media-picker-for-immich' ),
			'manage_options',
			'media-picker-for-immich',
			array( $this, 'render_settings_page' )
		);
	}

	public function add_cache_files_page(): void {
		add_media_page(
			__( 'Immich Cache Files', 'media-picker-for-immich' ),
			__( 'Cache Files', 'media-picker-for-immich' ),
			'manage_options',
			'immich-cache-files',
			array( $this, 'render_cache_files_page' )
		);
	}

	public function render_cache_files_page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to view cached files.', 'media-picker-for-immich' ) );
		}

		require_once IMMICH_MEDIA_PICKER_DIR . 'includes/class-immich-cache-list-table.php';

		$notice = '';

		// Empty Cache action.
		if ( isset( $_POST['immich_empty_cache'] ) ) {
			check_admin_referer( 'immich_empty_cache' );
			$count   = $this->empty_cache();
			/* translators: %d: number of cached files removed */
			$notice  = sprintf( _n( 'Removed %d cached file.', 'Removed %d cached files.', $count, 'media-picker-for-immich' ), $count );
		}

		// Single-row delete from the Name column row action.
		if ( isset( $_GET['immich_act'] ) && 'delete' === sanitize_key( $_GET['immich_act'] ) && isset( $_GET['cache_type'], $_GET['cache_uuid'] ) ) {
			$uuid = sanitize_text_field( wp_unslash( $_GET['cache_uuid'] ) );
			$type = sanitize_key( wp_unslash( $_GET['cache_type'] ) );
			check_admin_referer( 'immich_delete_cache_' . $uuid );
			if ( $this->delete_cache_file( $type, $uuid ) ) {
				$notice = __( 'Cached file deleted.', 'media-picker-for-immich' );
			}
		}

		$preview_nonce = wp_create_nonce( 'immich_preview' );
		$table         = new Immich_Cache_List_Table( $this, 'immich-cache-files', $preview_nonce );

		// Bulk delete is dispatched here (not inside the table, since we need
		// the plugin instance to actually delete).
		$action = $table->current_action();
		if ( 'delete' === $action ) {
			check_admin_referer( 'bulk-cache_files' );
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- verified above
			$selected = isset( $_REQUEST['cache_items'] ) ? (array) wp_unslash( $_REQUEST['cache_items'] ) : array();
			$deleted  = 0;
			foreach ( $selected as $entry ) {
				$entry = sanitize_text_field( $entry );
				if ( ! str_contains( $entry, ':' ) ) {
					continue;
				}
				[ $type, $uuid ] = explode( ':', $entry, 2 );
				if ( $this->delete_cache_file( $type, $uuid ) ) {
					$deleted++;
				}
			}
			/* translators: %d: number of cached files removed */
			$notice = sprintf( _n( 'Removed %d cached file.', 'Removed %d cached files.', $deleted, 'media-picker-for-immich' ), $deleted );
		}

		$table->prepare_items();
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Immich Cache Files', 'media-picker-for-immich' ); ?></h1>

			<?php if ( '' !== $notice ) : ?>
				<div class="notice notice-success is-dismissible"><p><?php echo esc_html( $notice ); ?></p></div>
			<?php endif; ?>

			<p class="description">
				<?php
				printf(
					/* translators: 1: number of cached files, 2: human-readable size */
					esc_html__( 'Currently caching %1$d files using %2$s.', 'media-picker-for-immich' ),
					(int) $table->total_count(),
					esc_html( size_format( $table->total_size() ) ?: '0 B' )
				);
				?>
			</p>

			<form method="post">
				<?php
				wp_nonce_field( 'bulk-cache_files' );
				$table->display();
				?>
			</form>

			<?php if ( $table->total_count() > 0 ) : ?>
				<form method="post" onsubmit="return confirm('<?php echo esc_js( __( 'Delete every cached file? They will be re-fetched from Immich on demand.', 'media-picker-for-immich' ) ); ?>');">
					<?php wp_nonce_field( 'immich_empty_cache' ); ?>
					<input type="hidden" name="immich_empty_cache" value="1" />
					<p>
						<button type="submit" class="button button-secondary">
							<?php esc_html_e( 'Empty Cache', 'media-picker-for-immich' ); ?>
						</button>
					</p>
				</form>
			<?php endif; ?>
		</div>
		<?php
	}

	public function register_settings(): void {
		register_setting(
			'immich_settings_group',
			'immich_settings',
			array(
				'type'              => 'array',
				'sanitize_callback' => array( $this, 'sanitize_settings' ),
				'default'           => array(
					'api_url'   => self::DEFAULT_API_URL,
					'api_key'   => '',
					'cache_gc'  => false,
					'cache_ttl' => 24,
					'copy_size' => 'original',
				),
			)
		);

		add_settings_section(
			'immich_main',
			__( 'Immich Server', 'media-picker-for-immich' ),
			'__return_null',
			'media-picker-for-immich'
		);

		add_settings_field(
			'immich_api_url',
			__( 'API URL', 'media-picker-for-immich' ),
			array( $this, 'render_api_url_field' ),
			'media-picker-for-immich',
			'immich_main'
		);

		add_settings_field(
			'immich_api_key',
			__( 'Site-wide API Key', 'media-picker-for-immich' ),
			array( $this, 'render_api_key_field' ),
			'media-picker-for-immich',
			'immich_main'
		);

		add_settings_field(
			'immich_copy_size',
			__( 'Copy Resolution', 'media-picker-for-immich' ),
			array( $this, 'render_copy_size_field' ),
			'media-picker-for-immich',
			'immich_main'
		);

		add_settings_section(
			'immich_cache',
			__( 'Cache', 'media-picker-for-immich' ),
			array( $this, 'render_cache_section' ),
			'media-picker-for-immich'
		);

		add_settings_field(
			'immich_cache_gc',
			__( 'Cache Cleanup', 'media-picker-for-immich' ),
			array( $this, 'render_cache_gc_field' ),
			'media-picker-for-immich',
			'immich_cache'
		);

		add_settings_field(
			'immich_cache_ttl',
			__( 'Cache Lifetime', 'media-picker-for-immich' ),
			array( $this, 'render_cache_ttl_field' ),
			'media-picker-for-immich',
			'immich_cache'
		);
	}

	public function sanitize_settings( array $input ): array {
		$existing = get_option( 'immich_settings', array() );

		$api_key = trim( wp_unslash( $input['api_key'] ?? '' ) );

		$raw_url = $input['api_url'] ?? '';
		$api_url = esc_url_raw( $raw_url );
		if ( '' === $api_url && '' !== $raw_url ) {
			add_settings_error(
				'immich_settings',
				'invalid_api_url',
				__( 'The API URL you entered is not valid. The previous URL has been kept.', 'media-picker-for-immich' ),
				'error'
			);
			$api_url = $existing['api_url'] ?? '';
		}

		$cache_gc  = ! empty( $input['cache_gc'] );
		$cache_ttl = absint( $input['cache_ttl'] ?? 24 );
		$cache_ttl = max( 1, $cache_ttl );

		$copy_size = $input['copy_size'] ?? 'original';
		if ( ! in_array( $copy_size, self::COPY_SIZE_CHOICES, true ) ) {
			$copy_size = 'original';
		}

		// Schedule or unschedule the GC cron based on the setting.
		if ( $cache_gc && ! wp_next_scheduled( 'immich_cache_gc' ) ) {
			wp_schedule_event( time(), 'hourly', 'immich_cache_gc' );
		} elseif ( ! $cache_gc ) {
			wp_clear_scheduled_hook( 'immich_cache_gc' );
		}

		return array(
			'api_url'   => $api_url,
			'api_key'   => $api_key,
			'cache_gc'  => $cache_gc,
			'cache_ttl' => $cache_ttl,
			'copy_size' => $copy_size,
		);
	}

	public function render_copy_size_field(): void {
		$settings = get_option( 'immich_settings', array() );
		$value    = in_array( $settings['copy_size'] ?? '', self::COPY_SIZE_CHOICES, true )
			? $settings['copy_size']
			: 'original';

		$labels = array(
			'original'  => __( 'Original (full resolution)', 'media-picker-for-immich' ),
			'fullsize'  => __( 'Fullsize (web-friendly version of the original; same as Original for JPEG sources)', 'media-picker-for-immich' ),
			'preview'   => __( 'Preview (large compressed JPEG)', 'media-picker-for-immich' ),
			'thumbnail' => __( 'Thumbnail (small)', 'media-picker-for-immich' ),
		);

		echo '<select name="immich_settings[copy_size]">';
		foreach ( self::COPY_SIZE_CHOICES as $choice ) {
			printf(
				'<option value="%s"%s>%s</option>',
				esc_attr( $choice ),
				selected( $value, $choice, false ),
				esc_html( $labels[ $choice ] )
			);
		}
		echo '</select>';
		echo '<p class="description">' . esc_html__( 'Resolution requested from Immich when you click Copy. Affects new copies only; previously copied assets are unchanged.', 'media-picker-for-immich' ) . '</p>';
	}

	public function render_api_url_field(): void {
		$settings = get_option( 'immich_settings', array() );
		$value    = $settings['api_url'] ?? self::DEFAULT_API_URL;
		printf(
			'<input type="url" name="immich_settings[api_url]" value="%s" class="regular-text" id="immich_settings_api_url" />',
			esc_attr( $value )
		);
	}

	public function render_api_key_field(): void {
		$settings = get_option( 'immich_settings', array() );
		$value    = $settings['api_key'] ?? '';
		printf(
			'<input type="password" name="immich_settings[api_key]" value="%s" class="regular-text" id="immich_settings_api_key" />',
			esc_attr( $value )
		);
		echo '<p class="description">' . esc_html__( 'When set, all users will use this key. Leave empty to allow per-user keys.', 'media-picker-for-immich' ) . '</p>';
		$this->render_test_connection_button( 'site' );
		$this->render_required_permissions_help();
	}

	private function render_test_connection_button( string $context ): void {
		printf(
			'<p class="immich-test-connection"><button type="button" class="button immich-test-btn" data-immich-context="%s">%s</button> <span class="immich-test-result" aria-live="polite"></span></p>',
			esc_attr( $context ),
			esc_html__( 'Test Connection', 'media-picker-for-immich' )
		);
	}

	public function render_cache_section(): void {
		$cache_dir = $this->get_cache_root();
		$writable  = wp_mkdir_p( $cache_dir ) && wp_is_writable( $cache_dir );
		if ( ! $writable ) {
			printf(
				'<div class="notice notice-error inline"><p>%s <code>%s</code></p></div>',
				esc_html__( 'The cache directory is not writable. Proxied media will not be cached. Please check permissions for:', 'media-picker-for-immich' ),
				esc_html( $cache_dir )
			);
		}
	}

	public function render_cache_gc_field(): void {
		$settings = get_option( 'immich_settings', array() );
		$checked  = ! empty( $settings['cache_gc'] );
		printf(
			'<label><input type="checkbox" name="immich_settings[cache_gc]" value="1" %s /> %s</label>',
			checked( $checked, true, false ),
			esc_html__( 'Automatically delete cached files after the lifetime below.', 'media-picker-for-immich' )
		);
	}

	public function render_cache_ttl_field(): void {
		$settings = get_option( 'immich_settings', array() );
		$value    = $settings['cache_ttl'] ?? 24;
		printf(
			'<input type="number" name="immich_settings[cache_ttl]" value="%s" min="1" class="small-text" /> %s',
			esc_attr( $value ),
			esc_html__( 'hours', 'media-picker-for-immich' )
		);
	}

	/**
	 * WP-Cron callback: delete cached proxy files older than the configured TTL.
	 */
	public function run_cache_gc(): void {
		$settings = get_option( 'immich_settings', array() );
		if ( empty( $settings['cache_gc'] ) ) {
			return;
		}

		$ttl_seconds = ( (int) ( $settings['cache_ttl'] ?? 24 ) ) * HOUR_IN_SECONDS;
		$cache_root  = $this->get_cache_root();

		if ( ! is_dir( $cache_root ) ) {
			return;
		}

		$now = time();
		foreach ( array( 'thumbnail', 'original', 'video' ) as $type ) {
			$dir = $cache_root . '/' . $type;
			if ( ! is_dir( $dir ) ) {
				continue;
			}
			$handle = opendir( $dir );
			if ( ! $handle ) {
				continue;
			}
			while ( false !== ( $entry = readdir( $handle ) ) ) {
				if ( '.' === $entry || '..' === $entry ) {
					continue;
				}
				// Skip lock files — they're tiny and cleaned up naturally.
				if ( str_ends_with( $entry, '.lock' ) ) {
					continue;
				}
				$path  = $dir . '/' . $entry;
				$mtime = filemtime( $path );
				if ( false !== $mtime && ( $now - $mtime ) > $ttl_seconds ) {
					wp_delete_file( $path );
				}
			}
			closedir( $handle );
		}
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
	 * Build the Immich endpoint that ajax_import (Copy) downloads from,
	 * based on the configured copy_size setting.
	 *
	 * - 'original'  → /api/assets/{id}/original (full resolution)
	 * - 'fullsize'  → /api/assets/{id}/thumbnail?size=fullsize
	 * - 'preview'   → /api/assets/{id}/thumbnail?size=preview
	 * - 'thumbnail' → /api/assets/{id}/thumbnail?size=thumbnail
	 */
	private function copy_endpoint_url( string $asset_id ): string {
		$settings  = get_option( 'immich_settings', array() );
		$copy_size = in_array( $settings['copy_size'] ?? '', self::COPY_SIZE_CHOICES, true )
			? $settings['copy_size']
			: 'original';

		$base = rtrim( $this->get_api_url(), '/' ) . '/api/assets/' . $asset_id;
		if ( 'original' === $copy_size ) {
			return $base . '/original';
		}
		return $base . '/thumbnail?size=' . rawurlencode( $copy_size );
	}

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

	/**
	 * Public proxy endpoint — intentionally unauthenticated so proxied images
	 * work in published posts for anonymous visitors. Only assets that have a
	 * corresponding WordPress attachment (created via "Use" or "Copy") are
	 * served. The Immich API key is never exposed; it stays server-side.
	 *
	 * Assets are cached locally on first request. Concurrent requests for the
	 * same asset block on a file lock so only one upstream fetch occurs.
	 */
	public function handle_proxy_request(): void {
		if ( ! isset( $_GET['immich_media_proxy'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- public proxy endpoint, no auth required
			return;
		}

		$type = sanitize_key( $_GET['immich_media_proxy'] ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( ! in_array( $type, array( 'thumbnail', 'preview', 'fullsize', 'original', 'video' ), true ) ) {
			status_header( 400 );
			exit( 'Invalid type.' );
		}

		$id = sanitize_text_field( wp_unslash( $_GET['id'] ?? '' ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( ! preg_match( '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $id ) ) {
			status_header( 400 );
			exit( 'Invalid ID.' );
		}

		// Preview path: an authenticated admin can view assets that haven't
		// been added yet (used by the picker preview overlay). Reuses the
		// same cache + Range support as the public path.
		$preview_nonce = isset( $_GET['preview_nonce'] ) ? sanitize_text_field( wp_unslash( $_GET['preview_nonce'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( '' !== $preview_nonce ) {
			if ( ! wp_verify_nonce( $preview_nonce, 'immich_preview' ) || ! current_user_can( 'upload_files' ) ) {
				status_header( 403 );
				exit( 'Forbidden.' );
			}
			$author_id = get_current_user_id();
		} else {
			// Only proxy assets that have been explicitly added via the plugin.
			$attachments = get_posts( array(
				'post_type'      => 'attachment',
				'post_status'    => 'inherit',
				'posts_per_page' => 1,
				'meta_key'       => '_immich_asset_id', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
				'meta_value'     => $id, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
				'fields'         => 'ids',
			) );
			if ( empty( $attachments ) ) {
				status_header( 404 );
				exit( 'Asset not found.' );
			}
			$author_id = (int) get_post_field( 'post_author', $attachments[0] );
		}

		$allowed_types = array(
			'thumbnail' => array( 'image/jpeg', 'image/webp', 'image/png', 'image/gif' ),
			'preview'   => array( 'image/jpeg', 'image/webp', 'image/png', 'image/gif' ),
			'original'  => array( 'image/jpeg', 'image/webp', 'image/png', 'image/gif', 'image/tiff', 'video/mp4', 'video/quicktime' ),
			'video'     => array( 'video/mp4', 'video/webm', 'video/ogg', 'video/quicktime' ),
		);

		$paths = $this->get_cache_paths( $type, $id );

		// Serve from cache if available.
		if ( file_exists( $paths['file'] ) && file_exists( $paths['meta'] ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- reading single-line cache metadata, not a remote URL
			$content_type = file_get_contents( $paths['meta'] );
			$this->serve_cached_asset( $paths['file'], $content_type, $type );
		}

		// Cache miss — acquire lock so only one request fetches from Immich.
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- lock file for cache synchronization
		$lock_fh = fopen( $paths['lock'], 'cb' );
		if ( ! $lock_fh ) {
			status_header( 500 );
			exit( 'Failed to acquire cache lock.' );
		}
		flock( $lock_fh, LOCK_EX );

		// Double-check: another process may have populated the cache while we waited.
		if ( file_exists( $paths['file'] ) && file_exists( $paths['meta'] ) ) {
			flock( $lock_fh, LOCK_UN );
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
			fclose( $lock_fh );
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
			$content_type = file_get_contents( $paths['meta'] );
			$this->serve_cached_asset( $paths['file'], $content_type, $type );
		}

		$api_key = $this->get_api_key( $author_id );
		if ( '' === $api_key ) {
			flock( $lock_fh, LOCK_UN );
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
			fclose( $lock_fh );
			status_header( 500 );
			exit( 'No API key configured.' );
		}

		$base    = rtrim( $this->get_api_url(), '/' );
		$api_url = match ( $type ) {
			'thumbnail' => $base . '/api/assets/' . $id . '/thumbnail',
			'preview'   => $base . '/api/assets/' . $id . '/thumbnail?size=preview',
			'fullsize'  => $base . '/api/assets/' . $id . '/thumbnail?size=fullsize',
			'video'     => $base . '/api/assets/' . $id . '/video/playback',
			default     => $base . '/api/assets/' . $id . '/original',
		};
		$timeout = match ( $type ) {
			'thumbnail' => 10,
			'preview'   => 15,
			'video'     => 120,
			default     => 30,
		};

		// Fetch from Immich and stream directly to the cache file.
		$response = wp_remote_get( $api_url, array(
			'headers'  => array( 'x-api-key' => $api_key ),
			'timeout'  => $timeout,
			'stream'   => true,
			'filename' => $paths['file'],
		) );

		if ( is_wp_error( $response ) ) {
			wp_delete_file( $paths['file'] );
			flock( $lock_fh, LOCK_UN );
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
			fclose( $lock_fh );
			status_header( 502 );
			exit( 'Upstream error.' );
		}

		$code = wp_remote_retrieve_response_code( $response );
		if ( $code < 200 || $code >= 300 ) {
			wp_delete_file( $paths['file'] );
			flock( $lock_fh, LOCK_UN );
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
			fclose( $lock_fh );
			status_header( (int) $code );
			exit( 'Asset not available.' );
		}

		$content_type = strtok( wp_remote_retrieve_header( $response, 'content-type' ) ?: 'application/octet-stream', ';' );
		if ( ! in_array( $content_type, $allowed_types[ $type ], true ) ) {
			wp_delete_file( $paths['file'] );
			flock( $lock_fh, LOCK_UN );
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
			fclose( $lock_fh );
			status_header( 502 );
			exit( 'Unexpected content type.' );
		}

		// Write content-type metadata, then release the lock.
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- writing single-line cache metadata
		file_put_contents( $paths['meta'], $content_type );
		flock( $lock_fh, LOCK_UN );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		fclose( $lock_fh );

		$this->serve_cached_asset( $paths['file'], $content_type, $type );
	}

	/**
	 * Return the root cache directory inside the uploads folder.
	 */
	private function get_cache_root(): string {
		$upload_dir = wp_upload_dir();
		return $upload_dir['basedir'] . '/immich-cache';
	}

	private function get_cache_paths( string $type, string $id ): array {
		$cache_dir = $this->get_cache_root() . '/' . $type;
		wp_mkdir_p( $cache_dir );
		return array(
			'file' => $cache_dir . '/' . $id,
			'meta' => $cache_dir . '/' . $id . '.type',
			'lock' => $cache_dir . '/' . $id . '.lock',
		);
	}

	private const CACHE_TYPES = array( 'thumbnail', 'preview', 'original', 'video' );

	private const UUID_PATTERN = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i';

	/**
	 * Walk the cache root and return one entry per cached binary file
	 * (skipping .type sidecars and .lock companions).
	 *
	 * @return array<int, array{type:string, uuid:string, path:string, size:int, mtime:int}>
	 */
	public function enumerate_cache_files(): array {
		$root  = $this->get_cache_root();
		$items = array();

		foreach ( self::CACHE_TYPES as $type ) {
			$dir = $root . '/' . $type;
			if ( ! is_dir( $dir ) ) {
				continue;
			}
			$handle = @opendir( $dir ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- defensive: dir may briefly be unreadable
			if ( ! $handle ) {
				continue;
			}
			while ( false !== ( $entry = readdir( $handle ) ) ) {
				if ( '.' === $entry || '..' === $entry ) {
					continue;
				}
				if ( ! preg_match( self::UUID_PATTERN, $entry ) ) {
					continue;
				}
				$path = $dir . '/' . $entry;
				if ( ! is_file( $path ) ) {
					continue;
				}
				$size  = (int) filesize( $path );
				$mtime = (int) filemtime( $path );
				$items[] = array(
					'type'  => $type,
					'uuid'  => $entry,
					'path'  => $path,
					'size'  => $size,
					'mtime' => $mtime,
				);
			}
			closedir( $handle );
		}

		return $items;
	}

	/**
	 * Delete a single cache entry's binary, .type sidecar, and any leftover
	 * .lock file. Validates that $type is in the allowlist and $uuid matches
	 * the canonical UUID pattern; refuses anything else.
	 */
	public function delete_cache_file( string $type, string $uuid ): bool {
		if ( ! in_array( $type, self::CACHE_TYPES, true ) ) {
			return false;
		}
		if ( ! preg_match( self::UUID_PATTERN, $uuid ) ) {
			return false;
		}
		$paths   = $this->get_cache_paths( $type, $uuid );
		$deleted = false;
		foreach ( array( 'file', 'meta', 'lock' ) as $key ) {
			if ( file_exists( $paths[ $key ] ) ) {
				wp_delete_file( $paths[ $key ] );
				$deleted = true;
			}
		}
		return $deleted;
	}

	/**
	 * Delete every cached binary across every subdir. Returns count of binaries
	 * removed (matching the UUID-named files only; .type/.lock companions are
	 * removed alongside their owners but not counted).
	 */
	public function empty_cache(): int {
		$count = 0;
		foreach ( $this->enumerate_cache_files() as $item ) {
			if ( $this->delete_cache_file( $item['type'], $item['uuid'] ) ) {
				$count++;
			}
		}
		return $count;
	}

	/**
	 * Map UUID → WP attachment ID for any of the given UUIDs that have a
	 * matching `_immich_asset_id` post meta. Single query rather than per-row.
	 *
	 * @param string[] $uuids
	 * @return array<string, int>
	 */
	public function lookup_attachments_for_uuids( array $uuids ): array {
		$uuids = array_values( array_unique( array_filter( $uuids, fn( $u ) => preg_match( self::UUID_PATTERN, $u ) ) ) );
		if ( empty( $uuids ) ) {
			return array();
		}
		global $wpdb;
		$placeholders = implode( ',', array_fill( 0, count( $uuids ), '%s' ) );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- placeholders generated from count, values passed via prepare()
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT post_id, meta_value FROM {$wpdb->postmeta} WHERE meta_key = '_immich_asset_id' AND meta_value IN ($placeholders)",
				...$uuids
			)
		);
		$map = array();
		foreach ( $rows ?: array() as $row ) {
			$map[ $row->meta_value ] = (int) $row->post_id;
		}
		return $map;
	}

	/**
	 * Serve a cached asset and exit. For video, handles Range requests.
	 *
	 * @return never
	 */
	private function serve_cached_asset( string $file, string $content_type, string $type ): void {
		header( 'X-Content-Type-Options: nosniff' );
		header( 'Content-Type: ' . $content_type );

		if ( 'video' === $type ) {
			$this->serve_cached_video( $file );
		}

		header( 'Content-Length: ' . filesize( $file ) );
		header( 'Cache-Control: public, max-age=31536000' );
		readfile( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_readfile
		exit;
	}

	/**
	 * Serve a cached video file with Range request support and exit.
	 *
	 * @return never
	 */
	private function serve_cached_video( string $file ): void {
		$size  = filesize( $file );
		$range = isset( $_SERVER['HTTP_RANGE'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_RANGE'] ) ) : '';

		header( 'Accept-Ranges: bytes' );

		if ( '' !== $range && preg_match( '/bytes=(\d+)-(\d*)/', $range, $m ) ) {
			$start = (int) $m[1];
			$end   = '' !== $m[2] ? (int) $m[2] : $size - 1;
			$end   = min( $end, $size - 1 );

			if ( $start > $end || $start >= $size ) {
				status_header( 416 );
				header( 'Content-Range: bytes */' . $size );
				exit;
			}

			$length = $end - $start + 1;
			status_header( 206 );
			header( 'Content-Range: bytes ' . $start . '-' . $end . '/' . $size );
			header( 'Content-Length: ' . $length );
			header( 'Cache-Control: no-store' );

			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- serving partial file content for Range request
			$fh = fopen( $file, 'rb' );
			if ( ! $fh ) {
				status_header( 500 );
				exit( 'Failed to read cached file.' );
			}
			fseek( $fh, $start );

			while ( ob_get_level() > 0 ) {
				ob_end_clean();
			}

			$remaining = $length;
			while ( $remaining > 0 && ! feof( $fh ) ) {
				$chunk = min( 8192, $remaining );
				// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped, WordPress.WP.AlternativeFunctions.file_system_operations_fread -- binary video data
				echo fread( $fh, $chunk );
				$remaining -= $chunk;
				flush();
			}

			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
			fclose( $fh );
			exit;
		}

		// Full request.
		header( 'Content-Length: ' . $size );
		header( 'Cache-Control: public, max-age=31536000' );

		while ( ob_get_level() > 0 ) {
			ob_end_clean();
		}

		readfile( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_readfile
		exit;
	}

	private function api_request( string $endpoint, string $method = 'GET', ?array $body = null ): array|\WP_Error {
		$api_key = $this->get_api_key();
		if ( '' === $api_key ) {
			return new \WP_Error( 'no_api_key', __( 'No Immich API key configured.', 'media-picker-for-immich' ) );
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

	/**
	 * Probe a single Immich endpoint with the given URL/key. Used by the
	 * Test Connection button so we can hit values that haven't been saved
	 * to the database yet.
	 */
	private function probe_immich( string $base_url, string $api_key, string $path, string $method = 'GET', ?array $body = null, array $extra_headers = array() ): array {
		$url  = rtrim( $base_url, '/' ) . $path;
		$args = array(
			'timeout' => 8,
			'headers' => array_merge(
				array(
					'x-api-key' => $api_key,
					'Accept'    => 'application/json',
				),
				$extra_headers
			),
		);

		if ( 'POST' === $method ) {
			$args['headers']['Content-Type'] = 'application/json';
			$args['body']                    = wp_json_encode( $body ?? array() );
			$response                        = wp_remote_post( $url, $args );
		} else {
			$response = wp_remote_get( $url, $args );
		}

		if ( is_wp_error( $response ) ) {
			$msg     = $response->get_error_message();
			$timeout = stripos( $msg, 'timed out' ) !== false || stripos( $msg, 'timeout' ) !== false;
			return array(
				'ok'      => false,
				'code'    => 0,
				'status'  => $timeout ? 'timeout' : 'unreachable',
				'message' => $msg,
				'body'    => null,
			);
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		$json = json_decode( wp_remote_retrieve_body( $response ), true );
		$ok   = $code >= 200 && $code < 300;
		return array(
			'ok'      => $ok,
			'code'    => $code,
			'status'  => $ok ? 'ok' : ( ( 401 === $code || 403 === $code ) ? 'unauthorized' : 'http_error' ),
			'message' => sprintf( 'HTTP %d', $code ),
			'body'    => $json,
		);
	}

	public function ajax_test_connection(): void {
		if ( ! check_ajax_referer( 'immich_test_connection', 'nonce', false ) ) {
			wp_send_json_error( array( 'message' => __( 'Invalid nonce.', 'media-picker-for-immich' ) ), 403 );
		}
		if ( ! current_user_can( 'upload_files' ) ) {
			wp_send_json_error( array( 'message' => __( 'Insufficient permissions.', 'media-picker-for-immich' ) ), 403 );
		}

		// phpcs:disable WordPress.Security.NonceVerification.Missing -- verified above
		$base_url = esc_url_raw( wp_unslash( $_POST['url'] ?? '' ) );
		$api_key  = trim( wp_unslash( $_POST['key'] ?? '' ) );
		// phpcs:enable

		if ( '' === $base_url ) {
			wp_send_json_error( array(
				'status'  => 'invalid',
				'message' => __( 'Enter a valid API URL before testing.', 'media-picker-for-immich' ),
			) );
		}
		if ( '' === $api_key ) {
			wp_send_json_error( array(
				'status'  => 'invalid',
				'message' => __( 'Enter an API key before testing.', 'media-picker-for-immich' ),
			) );
		}

		// Run scope probes; the aggregate doubles as the connection test.
		// /users/me is unsuitable here because it requires user.read, which is
		// outside the scope set the plugin actually needs.
		$probes = array();
		$scopes = array();

		$probes['asset.read']  = $this->probe_immich( $base_url, $api_key, '/api/search/metadata', 'POST', array( 'size' => 1, 'page' => 1 ) );
		$probes['person.read'] = $this->probe_immich( $base_url, $api_key, '/api/people?size=1' );

		$asset_id = '';
		if ( $probes['asset.read']['ok'] && is_array( $probes['asset.read']['body'] ) ) {
			$asset_id = $probes['asset.read']['body']['assets']['items'][0]['id'] ?? '';
		}

		if ( '' !== $asset_id ) {
			$probes['asset.view']     = $this->probe_immich( $base_url, $api_key, '/api/assets/' . rawurlencode( $asset_id ) . '/thumbnail' );
			$probes['asset.download'] = $this->probe_immich( $base_url, $api_key, '/api/assets/' . rawurlencode( $asset_id ) . '/original', 'GET', null, array( 'Range' => 'bytes=0-0' ) );
			// 206 (Partial Content) also counts as success for the Range probe.
			if ( ! $probes['asset.download']['ok'] && 206 === $probes['asset.download']['code'] ) {
				$probes['asset.download']['ok']     = true;
				$probes['asset.download']['status'] = 'ok';
			}
		}

		foreach ( array( 'asset.read', 'asset.view', 'asset.download', 'person.read' ) as $slug ) {
			if ( ! isset( $probes[ $slug ] ) ) {
				$scopes[ $slug ] = 'unverified';
			} else {
				$scopes[ $slug ] = $this->scope_status( $probes[ $slug ] );
			}
		}

		// Aggregate connection status from the probes.
		$any_ok            = false;
		$any_unreachable   = false;
		$any_timeout       = false;
		$any_unauthorized  = false;
		$last_http_code    = 0;
		foreach ( $probes as $probe ) {
			if ( $probe['ok'] ) {
				$any_ok = true;
			} else {
				switch ( $probe['status'] ) {
					case 'timeout':
						$any_timeout = true;
						break;
					case 'unreachable':
						$any_unreachable = true;
						break;
					case 'unauthorized':
						$any_unauthorized = true;
						break;
					default:
						$last_http_code = $probe['code'] ?: $last_http_code;
				}
			}
		}

		if ( $any_ok ) {
			wp_send_json_success( array(
				'ok'      => true,
				'status'  => 'connected',
				'scopes'  => $scopes,
				'message' => __( 'Connected.', 'media-picker-for-immich' ),
			) );
		}

		if ( $any_unreachable ) {
			$status  = 'unreachable';
			$message = __( 'Could not reach the Immich server. Check the API URL.', 'media-picker-for-immich' );
		} elseif ( $any_timeout ) {
			$status  = 'timeout';
			$message = __( 'The Immich server did not respond in time.', 'media-picker-for-immich' );
		} elseif ( $any_unauthorized ) {
			$status  = 'unauthorized';
			$message = __( 'API key rejected for every required permission. Generate a new key with the listed scopes.', 'media-picker-for-immich' );
		} else {
			$status  = 'http_error';
			/* translators: %d: HTTP status code */
			$message = sprintf( __( 'Unexpected response from Immich (HTTP %d).', 'media-picker-for-immich' ), $last_http_code );
		}

		wp_send_json_success( array(
			'ok'      => false,
			'status'  => $status,
			'scopes'  => $scopes,
			'message' => $message,
		) );
	}

	private function scope_status( array $probe ): string {
		if ( $probe['ok'] ) {
			return 'ok';
		}
		if ( 'unauthorized' === $probe['status'] ) {
			return 'missing';
		}
		return 'error';
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
			'media-picker-for-immich',
			IMMICH_MEDIA_PICKER_URL . 'assets/js/media-picker-for-immich.js',
			$deps,
			filemtime( IMMICH_MEDIA_PICKER_DIR . 'assets/js/media-picker-for-immich.js' ),
			true
		);

		wp_set_script_translations( 'media-picker-for-immich', 'media-picker-for-immich' );

		wp_localize_script( 'media-picker-for-immich', 'ImmichMediaPicker', array(
			'ajaxUrl'      => admin_url( 'admin-ajax.php' ),
			'nonce'        => wp_create_nonce( 'immich_nonce' ),
			'splitPct'     => $this->get_picker_split_pct( get_current_user_id() ),
			'previewNonce' => wp_create_nonce( 'immich_preview' ),
			'proxyUrl'     => home_url( '/' ),
		) );

		wp_enqueue_style(
			'media-picker-for-immich',
			plugin_dir_url( __FILE__ ) . 'assets/css/media-picker-for-immich.css',
			array( 'media-views' ),
			filemtime( plugin_dir_path( __FILE__ ) . 'assets/css/media-picker-for-immich.css' )
		);
	}

	public function enqueue_admin_assets( string $hook ): void {
		$is_settings = 'settings_page_media-picker-for-immich' === $hook;
		$is_profile  = 'profile.php' === $hook || 'user-edit.php' === $hook;
		if ( ! $is_settings && ! $is_profile ) {
			return;
		}

		wp_enqueue_script(
			'immich-admin',
			IMMICH_MEDIA_PICKER_URL . 'assets/js/immich-admin.js',
			array( 'jquery', 'wp-i18n' ),
			filemtime( IMMICH_MEDIA_PICKER_DIR . 'assets/js/immich-admin.js' ),
			true
		);
		wp_set_script_translations( 'immich-admin', 'media-picker-for-immich' );
		$saved = get_option( 'immich_settings', array() );
		wp_localize_script( 'immich-admin', 'ImmichAdmin', array(
			'ajaxUrl' => admin_url( 'admin-ajax.php' ),
			'nonce'   => wp_create_nonce( 'immich_test_connection' ),
			'savedUrl' => $saved['api_url'] ?? '',
		) );
		wp_enqueue_style(
			'immich-admin',
			IMMICH_MEDIA_PICKER_URL . 'assets/css/immich-admin.css',
			array(),
			filemtime( IMMICH_MEDIA_PICKER_DIR . 'assets/css/immich-admin.css' )
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
				do_settings_sections( 'media-picker-for-immich' );
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
		<h2><?php esc_html_e( 'Immich', 'media-picker-for-immich' ); ?></h2>
		<?php wp_nonce_field( 'immich_save_user_api_key_' . $user->ID, 'immich_user_api_key_nonce' ); ?>
		<table class="form-table">
			<tr>
				<th><label for="immich_api_key"><?php esc_html_e( 'API Key', 'media-picker-for-immich' ); ?></label></th>
				<td>
					<input type="password" name="immich_api_key" id="immich_api_key" value="<?php echo esc_attr( $value ); ?>" class="regular-text" />
					<p class="description"><?php esc_html_e( 'Your personal Immich API key.', 'media-picker-for-immich' ); ?></p>
					<?php $this->render_test_connection_button( 'user' ); ?>
					<?php $this->render_required_permissions_help(); ?>
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
		$key = trim( wp_unslash( $_POST['immich_api_key'] ?? '' ) ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- API keys may contain characters that sanitize_text_field would strip
		update_user_meta( $user_id, 'immich_api_key', $key );
	}

	public function filter_attachment_url( string $url, int $attachment_id ): string {
		$immich_id = get_post_meta( $attachment_id, '_immich_asset_id', true );
		if ( ! $immich_id ) {
			return $url;
		}

		// Copied assets have a real local file in uploads/ — serve that, not the proxy.
		if ( 'copy' === get_post_meta( $attachment_id, '_immich_add_mode', true ) ) {
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

		// Copied assets have a real local file — let core handle downsize normally.
		if ( 'copy' === get_post_meta( $attachment_id, '_immich_add_mode', true ) ) {
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

		$body       = array(
			'size'  => 50,
			'page'  => $page,
			'order' => 'desc',
		);
		$asset_type = sanitize_text_field( wp_unslash( $_GET['assetType'] ?? '' ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- verified in verify_ajax_request()
		if ( in_array( $asset_type, array( 'IMAGE', 'VIDEO' ), true ) ) {
			$body['type'] = $asset_type;
		}

		$response = $this->api_request( '/api/search/metadata', 'POST', $body );

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
			$item_type  = 'VIDEO' === ( $asset['type'] ?? '' ) ? 'VIDEO' : 'IMAGE';
			$item       = array(
				'id'       => $asset['id'],
				'type'     => $item_type,
				'thumbUrl' => admin_url( 'admin-ajax.php?action=immich_thumbnail&id=' . rawurlencode( $asset['id'] ) . '&nonce=' . wp_create_nonce( 'immich_nonce' ) ),
				'filename' => $asset['originalFileName'] ?? $asset['id'] . '.jpg',
			);
			if ( 'VIDEO' === $item_type && ! empty( $asset['duration'] ) ) {
				$item['duration'] = (string) $asset['duration'];
			}
			$result[] = $item;
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
		$asset_type   = sanitize_text_field( wp_unslash( $_POST['assetType'] ?? '' ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified in verify_ajax_request()
		$asset_type   = in_array( $asset_type, array( 'IMAGE', 'VIDEO' ), true ) ? $asset_type : '';

		if ( '' !== $query ) {
			$body = array( 'query' => $query, 'size' => 50, 'page' => $page );
			if ( ! empty( $person_ids ) ) {
				$body['personIds'] = $person_ids;
			}
			if ( '' !== $asset_type ) {
				$body['type'] = $asset_type;
			}
			$response = $this->api_request( '/api/search/smart', 'POST', $body );
		} elseif ( ! empty( $person_ids ) ) {
			$body = array( 'personIds' => $person_ids, 'size' => 50, 'page' => $page );
			if ( '' !== $asset_type ) {
				$body['type'] = $asset_type;
			}
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
			$item_type  = 'VIDEO' === ( $asset['type'] ?? '' ) ? 'VIDEO' : 'IMAGE';
			$item       = array(
				'id'       => $asset['id'],
				'type'     => $item_type,
				'thumbUrl' => admin_url( 'admin-ajax.php?action=immich_thumbnail&id=' . rawurlencode( $asset['id'] ) . '&nonce=' . wp_create_nonce( 'immich_nonce' ) ),
				'filename' => $asset['originalFileName'] ?? $asset['id'] . '.jpg',
			);
			if ( 'VIDEO' === $item_type && ! empty( $asset['duration'] ) ) {
				$item['duration'] = (string) $asset['duration'];
			}
			$result[] = $item;
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
			return;
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
		$url      = $this->copy_endpoint_url( $id );
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

		$raw_type   = $info['type'] ?? 'IMAGE';
		$asset_type = in_array( $raw_type, array( 'IMAGE', 'VIDEO' ), true ) ? $raw_type : 'IMAGE';

		update_post_meta( $attach_id, '_immich_asset_id', $id );
		update_post_meta( $attach_id, '_immich_asset_type', $asset_type );
		update_post_meta( $attach_id, '_immich_add_mode', 'copy' );

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
		update_post_meta( $attach_id, '_immich_add_mode', 'select' );

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

	/**
	 * Read the picker's saved split percentage (live grid % of vertical space).
	 * Defaults to 70 when the user has no saved preference. Clamped 10-90.
	 */
	private function get_picker_split_pct( int $user_id ): int {
		$saved = (int) get_user_meta( $user_id, '_immich_picker_split_pct', true );
		if ( $saved < 10 || $saved > 90 ) {
			return 70;
		}
		return $saved;
	}

	public function ajax_save_picker_split(): void {
		if ( ! $this->verify_ajax_request() ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified in verify_ajax_request()
		$pct = (int) ( $_POST['pct'] ?? 0 );
		if ( $pct < 10 || $pct > 90 ) {
			wp_send_json_error( 'Out of range.' );
			return;
		}

		update_user_meta( get_current_user_id(), '_immich_picker_split_pct', $pct );
		wp_send_json_success( array( 'splitPct' => $pct ) );
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
			$asset_type = get_post_meta( $post->ID, '_immich_asset_type', true );
			$add_mode   = get_post_meta( $post->ID, '_immich_add_mode', true );
			$items[]    = array(
				'attachmentId' => $post->ID,
				'immichId'     => $immich_id,
				'type'         => 'VIDEO' === $asset_type ? 'VIDEO' : 'IMAGE',
				'addMode'      => 'copy' === $add_mode ? 'copy' : 'select',
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

	/**
	 * Register Gutenberg blocks shipped by this plugin.
	 */
	public function register_blocks(): void {
		$registered = register_block_type( __DIR__ . '/includes/block-album-gallery' );
		if ( $registered && ! empty( $registered->editor_script_handles ) ) {
			foreach ( $registered->editor_script_handles as $handle ) {
				wp_set_script_translations( $handle, 'media-picker-for-immich' );
			}
		}
	}

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

		$sort    = $this->validate_sort( isset( $attrs['sortOrder'] ) ? (string) $attrs['sortOrder'] : 'default' );
		$payload = $this->fetch_album_assets( $album_id, $sort );
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
			$asset_id = isset( $a['id'] ) ? (string) $a['id'] : '';
			if ( '' === $asset_id || ! preg_match( self::UUID_PATTERN, $asset_id ) ) {
				continue;
			}
			$url      = home_url( '/?immich_media_proxy=' . rawurlencode( $size ) . '&id=' . rawurlencode( $asset_id ) );
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
	 * Validate the sortOrder block attribute.
	 *
	 * @param string $sort Raw attribute value.
	 * @return string One of 'default', 'oldest', 'newest', 'random'.
	 */
	private function validate_sort( string $sort ): string {
		$allowed = array( 'default', 'oldest', 'newest', 'random' );
		return in_array( $sort, $allowed, true ) ? $sort : 'default';
	}

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

		// Sort variants land in Task 8; for now sort='default' is a no-op.
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
}

add_action( 'plugins_loaded', function () {
	Immich_Media_Picker::instance();
} );
