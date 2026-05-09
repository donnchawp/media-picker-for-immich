<?php
/**
 * Plugin Name: Media Picker for Immich
 * Description: Use photos and videos from your Immich server in WordPress without copying files, or import them into the media library.
 * Version: 0.1.0
 * Requires at least: 6.5
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
			'album.read'     => __( 'List albums in the picker and fetch their assets for the Album Gallery block.', 'media-picker-for-immich' ),
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
		add_action( 'save_post', array( $this, 'on_post_save' ), 10, 2 );
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

		// Re-queue while this batch was full — found_posts is the pre-write total
		// for the NOT EXISTS query, so it equals the batch size in the boundary
		// case (multiple of $batch_size remaining) and would mark the upgrade
		// done before the next batch ran.
		if ( count( $query->posts ) === $batch_size ) {
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
				// The plugin only reads immich_settings on its own admin pages,
				// REST/AJAX, and proxy requests — never on every WP page load —
				// so there's no benefit to autoloading it.
				'autoload'          => false,
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

		// One-shot migration: existing installs already wrote immich_settings
		// with autoload='yes'. register_setting() only affects fresh writes,
		// so flip the stored row once.
		// TODO: remove this block and delete the immich_settings_autoload_off
		// option after a couple of releases (target: ~2026-08).
		if ( ! get_option( 'immich_settings_autoload_off', false ) ) {
			wp_set_option_autoload( 'immich_settings', false );
			// Autoload the tiny migration flag so the check above is free
			// on every subsequent admin_init.
			update_option( 'immich_settings_autoload_off', 1 );
		}

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
		foreach ( self::CACHE_TYPES as $type ) {
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
	 * Build a signed proxy URL for an asset embedded in an album-gallery block.
	 *
	 * Lets anonymous frontend visitors fetch images that are not WP attachments.
	 * Authorisation is via HMAC over (asset_id, post_id) with wp_salt('nonce').
	 * Stable across page loads (no nonce-tick expiry) so cached frontend HTML
	 * keeps working for the post's lifetime, and resilient against tampering:
	 * a request can only succeed if the token matches the (asset, post) pair.
	 *
	 * @param string $asset_id Asset UUID.
	 * @param string $size     'thumbnail', 'preview', 'fullsize', 'original', or 'video'.
	 * @param int    $post_id  Post containing the album block. Used by the
	 *                         proxy to derive the post-author for API-key
	 *                         lookup (matching the existing public branch).
	 * @return string URL, or '' if any input is invalid.
	 */
	private function album_proxy_url( string $asset_id, string $size, int $post_id ): string {
		if ( '' === $asset_id || ! preg_match( self::UUID_PATTERN, $asset_id ) || $post_id <= 0 ) {
			return '';
		}
		$allowed = array( 'thumbnail', 'preview', 'fullsize', 'original', 'video' );
		if ( ! in_array( $size, $allowed, true ) ) {
			$size = 'preview';
		}
		$token = hash_hmac( 'sha256', $asset_id . '|' . $post_id, wp_salt( 'nonce' ) );
		$args  = array(
			'immich_media_proxy' => $size,
			'id'                 => $asset_id,
			'album_post'         => $post_id,
			'album_token'        => $token,
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
		$album_token   = isset( $_GET['album_token'] ) ? sanitize_text_field( wp_unslash( $_GET['album_token'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$album_post    = isset( $_GET['album_post'] ) ? absint( $_GET['album_post'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		// Preview-nonce paths are user-specific (signed with the viewer's session)
		// so the response must not be reused across users by shared caches/CDNs.
		// Album-token and the public attachment proxy paths are stable, anonymous,
		// and safe to cache publicly for a long time.
		$is_private = false;

		if ( '' !== $preview_nonce ) {
			if ( ! wp_verify_nonce( $preview_nonce, 'immich_preview' ) || ! current_user_can( 'upload_files' ) ) {
				status_header( 403 );
				exit( 'Forbidden.' );
			}
			$author_id  = get_current_user_id();
			$is_private = true;
		} elseif ( '' !== $album_token && $album_post > 0 ) {
			// Album-block path: signed proxy URL emitted by render_album_block.
			// HMAC over (asset_id, post_id) with wp_salt('nonce'). Stable across
			// page loads (no nonce-tick expiry) so cached frontend HTML stays
			// valid for the lifetime of the post.
			//
			// The token does not bind to size, so without restricting the
			// accepted sizes a visitor could swap a rendered preview URL to
			// `original` or `video` and pull the raw file with full EXIF/GPS
			// or a full video stream. Album blocks only render image sizes,
			// so accept those and reject the originals/video escalations.
			if ( ! in_array( $type, array( 'thumbnail', 'preview', 'fullsize' ), true ) ) {
				status_header( 403 );
				exit( 'Forbidden.' );
			}
			$expected = hash_hmac( 'sha256', $id . '|' . $album_post, wp_salt( 'nonce' ) );
			if ( ! hash_equals( $expected, $album_token ) ) {
				status_header( 403 );
				exit( 'Invalid album token.' );
			}
			// Tokens have no expiry, so without a post-status gate a URL minted
			// in editor preview or before unpublishing keeps streaming Immich
			// assets anonymously. Anonymous requests require the post to be
			// published AND not password-protected; private/draft/password-
			// protected posts require an authenticated reader. (post_status is
			// 'publish' for password-protected posts, and current_user_can(
			// 'read_post' ) does not consult the password cookie, so the
			// password check has to be explicit.)
			$post_status = get_post_status( $album_post );
			if ( false === $post_status || 'trash' === $post_status ) {
				status_header( 404 );
				exit( 'Post not found.' );
			}
			if ( ( 'publish' !== $post_status || post_password_required( $album_post ) )
				&& ! current_user_can( 'read_post', $album_post )
			) {
				status_header( 403 );
				exit( 'Forbidden.' );
			}
			$author_id = (int) get_post_field( 'post_author', $album_post );
			if ( 0 === $author_id ) {
				status_header( 404 );
				exit( 'Post not found.' );
			}
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
			'fullsize'  => array( 'image/jpeg', 'image/webp', 'image/png', 'image/gif' ),
			'original'  => array( 'image/jpeg', 'image/webp', 'image/png', 'image/gif', 'image/tiff', 'video/mp4', 'video/quicktime' ),
			'video'     => array( 'video/mp4', 'video/webm', 'video/ogg', 'video/quicktime' ),
		);

		$paths = $this->get_cache_paths( $type, $id );

		// Serve from cache if available.
		if ( file_exists( $paths['file'] ) && file_exists( $paths['meta'] ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- reading single-line cache metadata, not a remote URL
			$content_type = file_get_contents( $paths['meta'] );
			$this->serve_cached_asset( $paths['file'], $content_type, $type, $is_private );
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
			$this->serve_cached_asset( $paths['file'], $content_type, $type, $is_private );
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

		$this->serve_cached_asset( $paths['file'], $content_type, $type, $is_private );
	}

	/**
	 * Return the root cache directory inside the uploads folder. Creates
	 * the directory on first use and drops a deny-all .htaccess + an
	 * index.php stub so the cached binaries can't be fetched directly via
	 * the public uploads URL — handle_proxy_request() is the only path
	 * that should serve them, so it can enforce post-status auth.
	 *
	 * (Apache only. Nginx ignores .htaccess; the plugin docs note that
	 * Nginx installs need an equivalent `location` block to deny direct
	 * access to /wp-content/uploads/immich-cache/.)
	 */
	private function get_cache_root(): string {
		$upload_dir = wp_upload_dir();
		$root       = $upload_dir['basedir'] . '/immich-cache';
		if ( wp_mkdir_p( $root ) ) {
			$this->ensure_cache_root_protected( $root );
		}
		return $root;
	}

	private function ensure_cache_root_protected( string $root ): void {
		$htaccess = $root . '/.htaccess';
		if ( ! file_exists( $htaccess ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- one-shot drop, runs on cache-root creation only
			file_put_contents(
				$htaccess,
				"# Block direct access to cached Immich binaries; the proxy enforces auth.\n"
				. "Options -Indexes\n"
				. "<IfModule mod_authz_core.c>\n"
				. "    Require all denied\n"
				. "</IfModule>\n"
				. "<IfModule !mod_authz_core.c>\n"
				. "    Deny from all\n"
				. "</IfModule>\n"
			);
		}
		$index = $root . '/index.php';
		if ( ! file_exists( $index ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- one-shot drop, runs on cache-root creation only
			file_put_contents( $index, "<?php\n// Silence is golden.\n" );
		}
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

	private const CACHE_TYPES = array( 'thumbnail', 'preview', 'fullsize', 'original', 'video', 'person' );

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
	private function serve_cached_asset( string $file, string $content_type, string $type, bool $is_private = false ): void {
		header( 'X-Content-Type-Options: nosniff' );
		header( 'Content-Type: ' . $content_type );

		if ( 'video' === $type ) {
			// serve_cached_video exits on every code path; the explicit return
			// keeps the non-video flow safe if that ever changes.
			$this->serve_cached_video( $file, $is_private );
			return;
		}

		header( 'Content-Length: ' . filesize( $file ) );
		header( 'Cache-Control: ' . ( $is_private ? 'private, max-age=3600' : 'public, max-age=31536000' ) );
		readfile( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_readfile
		exit;
	}

	/**
	 * Serve a cached video file with Range request support and exit.
	 *
	 * @return never
	 */
	private function serve_cached_video( string $file, bool $is_private = false ): void {
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
		header( 'Cache-Control: ' . ( $is_private ? 'private, max-age=3600' : 'public, max-age=31536000' ) );

		while ( ob_get_level() > 0 ) {
			ob_end_clean();
		}

		readfile( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_readfile
		exit;
	}

	private function api_request( string $endpoint, string $method = 'GET', ?array $body = null, int $user_id = 0 ): array|\WP_Error {
		$api_key = $this->get_api_key( $user_id );
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
	 * Extract the HTTP status from an api_request() WP_Error, if present.
	 *
	 * @param \WP_Error $error Error returned by api_request().
	 * @return int 0 if the error is not from a non-2xx response or status is missing.
	 */
	private function api_error_status( \WP_Error $error ): int {
		$data = $error->get_error_data();
		return is_array( $data ) && isset( $data['status'] ) ? (int) $data['status'] : 0;
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
		$api_key = trim( wp_unslash( $_POST['key'] ?? '' ) );

		// Only admins may probe an arbitrary URL (settings-page "test before save").
		// Everyone else is pinned to the saved Immich URL — the per-user profile
		// form only sets a key, so non-admins have no legitimate need to redirect
		// the probe, and allowing it would turn this endpoint into an authenticated
		// SSRF tool against the internal network.
		if ( current_user_can( 'manage_options' ) ) {
			$base_url = esc_url_raw( wp_unslash( $_POST['url'] ?? '' ) );
			if ( '' === $base_url ) {
				$saved    = get_option( 'immich_settings', array() );
				$base_url = (string) ( $saved['api_url'] ?? '' );
			}
		} else {
			$saved    = get_option( 'immich_settings', array() );
			$base_url = (string) ( $saved['api_url'] ?? '' );
		}
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
		$probes['album.read']  = $this->probe_immich( $base_url, $api_key, '/api/albums' );

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

		foreach ( array( 'asset.read', 'asset.view', 'asset.download', 'person.read', 'album.read' ) as $slug ) {
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
		// Only enqueue on profile pages for users who can actually use the picker.
		// The localized payload includes the (potentially internal) Immich URL,
		// and the per-user API key form below is also gated on upload_files.
		$is_profile  = ( 'profile.php' === $hook || 'user-edit.php' === $hook )
			&& current_user_can( 'upload_files' );
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
		// The picker requires upload_files; users without it have no use for an
		// API key. Hiding the field also avoids leaking the internal Immich URL
		// (rendered by the Test Connection button below) to Subscribers/Contributors.
		if ( ! user_can( $user, 'upload_files' ) ) {
			return;
		}
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
		// Refuse to store a key for a user who can't use the picker, mirroring
		// the field-level guard in render_user_api_key_field.
		if ( ! user_can( $user_id, 'upload_files' ) ) {
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

		$items       = $response['assets']['items'] ?? array();
		$next_page   = $response['assets']['nextPage'] ?? null;
		$result      = array();
		$thumb_nonce = wp_create_nonce( 'immich_nonce' );
		foreach ( $items as $asset ) {
			if ( ! preg_match( '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $asset['id'] ?? '' ) ) {
				continue;
			}
			$item_type  = 'VIDEO' === ( $asset['type'] ?? '' ) ? 'VIDEO' : 'IMAGE';
			$item       = array(
				'id'       => $asset['id'],
				'type'     => $item_type,
				'thumbUrl' => admin_url( 'admin-ajax.php?action=immich_thumbnail&id=' . rawurlencode( $asset['id'] ) . '&nonce=' . $thumb_nonce ),
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

		$items       = $response['assets']['items'] ?? array();
		$next_page   = $response['assets']['nextPage'] ?? null;
		$result      = array();
		$thumb_nonce = wp_create_nonce( 'immich_nonce' );
		foreach ( $items as $asset ) {
			if ( ! preg_match( '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $asset['id'] ?? '' ) ) {
				continue;
			}
			$item_type  = 'VIDEO' === ( $asset['type'] ?? '' ) ? 'VIDEO' : 'IMAGE';
			$item       = array(
				'id'       => $asset['id'],
				'type'     => $item_type,
				'thumbUrl' => admin_url( 'admin-ajax.php?action=immich_thumbnail&id=' . rawurlencode( $asset['id'] ) . '&nonce=' . $thumb_nonce ),
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

		$people      = $response['people'] ?? $response;
		$result      = array();
		$thumb_nonce = wp_create_nonce( 'immich_nonce' );
		foreach ( $people as $person ) {
			if ( empty( $person['name'] ) ) {
				continue;
			}
			$result[] = array(
				'id'       => $person['id'],
				'name'     => $person['name'],
				'thumbUrl' => admin_url( 'admin-ajax.php?action=immich_thumbnail&type=person&id=' . urlencode( $person['id'] ) . '&nonce=' . $thumb_nonce ),
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

		$type       = sanitize_key( $_GET['type'] ?? 'asset' ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- verified in verify_ajax_request()
		$cache_type = 'person' === $type ? 'person' : 'thumbnail';
		$paths      = $this->get_cache_paths( $cache_type, $id );

		// Cache-first: a populated picker fires 50 thumbnail requests per browse
		// page, so without this every browse hits Immich 50× and pins a PHP
		// process per request for the upstream timeout. Per-user is_private=true
		// because per-user API keys mean different viewers can have different
		// libraries — the cache is shared but the response is not.
		if ( file_exists( $paths['file'] ) && file_exists( $paths['meta'] ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- reading single-line cache metadata
			$content_type = file_get_contents( $paths['meta'] );
			$this->serve_cached_asset( $paths['file'], $content_type, $cache_type, true );
		}

		$api_key = $this->get_api_key();
		if ( '' === $api_key ) {
			wp_die( 'No API key configured.', 500 );
		}

		$url = 'person' === $type
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

		// Best-effort cache write; concurrent fetches for the same uuid would
		// race with last-write-wins, which is harmless because the bytes are
		// identical. No file lock needed at this scale.
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- caching binary thumbnail
		file_put_contents( $paths['file'], $body );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- writing single-line cache metadata
		file_put_contents( $paths['meta'], $content_type );

		$this->serve_cached_asset( $paths['file'], $content_type, $cache_type, true );
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
		// Frontend CSS for the album gallery. Registered explicitly (rather
		// than relying on block.json's `style:` field reaching the right
		// auto-generated handle) so we can wp_enqueue_style() it directly
		// from render_album_block.
		wp_register_style(
			'immich-album-block',
			plugins_url( 'assets/css/immich-album-block.css', __FILE__ ),
			array(),
			'1.0.0'
		);

		$registered = register_block_type( __DIR__ . '/includes/block-album-gallery' );
		if ( $registered && ! empty( $registered->editor_script_handles ) ) {
			foreach ( $registered->editor_script_handles as $handle ) {
				wp_set_script_translations( $handle, 'media-picker-for-immich' );
			}
		}
	}

	/**
	 * Render an editor-only error notice for the album block.
	 *
	 * Anonymous viewers get an empty string; logged-in editors with
	 * edit_posts capability see a diagnostic notice.
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

	/**
	 * Render the immich/album-gallery Gutenberg block.
	 *
	 * Server-side renderer for the dynamic block. Fetches the album from
	 * Immich (with caching and stale fallback), emits core gallery markup,
	 * and prepends an editor-only stale-cache notice when applicable.
	 *
	 * @param array $attrs Block attributes (albumId, columns, imageSize,
	 *                     sortOrder, limit, lightbox, showCaptions).
	 * @return string Rendered HTML; empty for visitors when the album is
	 *                missing/unloadable; an inline error notice for editors.
	 */
	public function render_album_block( array $attrs, ?\WP_Block $block = null ): string {
		$album_id = isset( $attrs['albumId'] ) ? (string) $attrs['albumId'] : '';
		if ( '' === $album_id || ! preg_match( self::UUID_PATTERN, $album_id ) ) {
			return '';
		}

		// Editor-only cache refresh probe. Requires both edit_posts and a
		// matching _wpnonce so a clickjack can't be used to force a page-wide
		// cache miss against a logged-in editor. The action is keyed to the
		// album id so each album gets its own nonce.
		if ( ! empty( $_GET['immich_refresh'] )
			&& current_user_can( 'edit_posts' )
			&& isset( $_GET['_wpnonce'] )
			&& wp_verify_nonce(
				sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ),
				'immich_refresh_' . $album_id
			)
		) {
			$this->flush_album_cache( $album_id );
		}

		// WordPress's "load block CSS only when needed" optimisation enqueues
		// per-block stylesheets only for blocks it detects in the source
		// post_content. We emit core gallery+image markup at render time, so
		// core never sees those block delimiters and never auto-enqueues the
		// styles. Force them on so the gallery actually looks like a gallery
		// on the frontend.
		wp_enqueue_style( 'wp-block-gallery' );
		wp_enqueue_style( 'wp-block-image' );
		wp_enqueue_style( 'immich-album-block' );

		// Post id is needed both to authorise the album list fetch (per-user
		// API key falls back to the post author when no site-wide key is set,
		// matching the asset proxy) and to sign each asset URL further down.
		// Prefer block context (`usesContext: ["postId"]` in block.json) so
		// the block resolves correctly when rendered outside The Loop —
		// sidebar widgets, FSE template parts, query-loop variations — where
		// get_the_ID() may return 0 or a wholly unrelated post.
		$post_id = 0;
		if ( $block instanceof \WP_Block && isset( $block->context['postId'] ) ) {
			$post_id = (int) $block->context['postId'];
		}
		if ( $post_id <= 0 ) {
			$post_id = (int) get_the_ID();
		}
		$author_id = $post_id > 0 ? (int) get_post_field( 'post_author', $post_id ) : 0;

		$sort    = $this->validate_sort( isset( $attrs['sortOrder'] ) ? (string) $attrs['sortOrder'] : 'default' );
		$payload = $this->fetch_album_assets( $album_id, $sort, $author_id );
		if ( is_wp_error( $payload ) ) {
			return $this->render_album_error_notice( $payload );
		}

		$assets = $payload['assets'];
		if ( empty( $assets ) ) {
			if ( current_user_can( 'edit_posts' ) ) {
				return '<div class="immich-album-empty" style="border:1px dashed #ccc;padding:8px;color:#757575;">'
					. esc_html__( 'This Immich album is empty.', 'media-picker-for-immich' )
					. '</div>';
			}
			return '';
		}

		// Per-block limit (after sort, after cap). 0 = render everything (up to the cap).
		$limit = max( 0, (int) ( $attrs['limit'] ?? 0 ) );
		if ( $limit > 0 ) {
			$assets = array_slice( $assets, 0, $limit );
		}

		$size          = $this->validate_image_size( isset( $attrs['imageSize'] ) ? (string) $attrs['imageSize'] : 'preview' );
		$columns       = max( 1, min( 8, (int) ( $attrs['columns'] ?? 3 ) ) );
		$show_captions = ! empty( $attrs['showCaptions'] );

		// Per-figure width inline. Mirrors core's gallery `.columns-N` CSS
		// rule shape exactly, except core's lives inside `@media (min-width:
		// 600px)` so it doesn't apply on narrow viewports — emitting inline
		// makes the column count work everywhere. `width:` is also more
		// authoritative than `flex-basis` and wins against whatever default
		// flex behavior the theme might apply to the figures.
		$gap_px        = 16;
		$pct           = 100.0 / max( 1, $columns );
		$frac          = ( max( 1, $columns ) - 1 ) / max( 1, $columns );
		$figure_style  = sprintf(
			'width:calc(%.5f%% - var(--wp--style--unstable-gallery-gap,16px) * %.5f);',
			$pct,
			$frac
		);

		$children = '';
		foreach ( $assets as $a ) {
			$asset_id = isset( $a['id'] ) ? (string) $a['id'] : '';
			if ( '' === $asset_id || ! preg_match( self::UUID_PATTERN, $asset_id ) ) {
				continue;
			}
			$url = $this->album_proxy_url( $asset_id, $size, $post_id );
			if ( '' === $url ) {
				continue;
			}
			$alt     = isset( $a['originalFileName'] ) ? (string) $a['originalFileName'] : '';
			$caption = $show_captions && '' !== $alt
				? '<figcaption class="wp-element-caption">' . esc_html( $alt ) . '</figcaption>'
				: '';
			$children .= '<figure class="wp-block-image size-large" style="' . esc_attr( $figure_style ) . '">'
				. '<img src="' . esc_url( $url ) . '" alt="' . esc_attr( $alt ) . '" loading="lazy" />'
				. $caption
				. '</figure>';
		}

		// "View N more on Immich" link when the cap trimmed the gallery and the
		// user did not explicitly set a `limit`. The link points at the album's
		// Immich web UI URL — Immich serves both the API and the SPA from the
		// same origin, so trimming the API path off `get_api_url()` and
		// appending /albums/<uuid> yields the canonical browser URL.
		$total_count     = isset( $payload['total_count'] ) ? (int) $payload['total_count'] : count( $assets );
		$hidden          = max( 0, $total_count - count( $assets ) );
		$cap_applied     = ( 0 === $limit ) && ( $hidden > 0 );
		$show_album_link = ! empty( $attrs['showAlbumLink'] );

		// Gate the "View N more on Immich" link on an explicit per-block
		// opt-in: get_api_url() is the URL the WordPress server uses to talk
		// to Immich, which on a self-hosted setup is often a Docker-internal
		// or VPN-only hostname not reachable from a visitor's browser.
		// Defaulting off keeps galleries from shipping broken links.
		$more_link = '';
		if ( $cap_applied && $show_album_link ) {
			$album_url = rtrim( $this->get_api_url(), '/' ) . '/albums/' . rawurlencode( $album_id );
			$more_link = '<p class="immich-album-more"><a href="' . esc_url( $album_url ) . '" target="_blank" rel="noopener noreferrer">'
				. sprintf(
					/* translators: %d: number of additional assets in the Immich album. */
					esc_html( _n( 'View %d more on Immich', 'View %d more on Immich', $hidden, 'media-picker-for-immich' ) ),
					(int) $hidden
				)
				. ' &rarr;</a></p>';
		}

		// "Refresh from Immich" link, visible to logged-in editors on the
		// published frontend only (suppressed inside the editor's SSR
		// preview where it would just navigate the iframe). Carries a nonce
		// keyed to the album id so the cache-flush endpoint is CSRF-safe.
		$refresh_link  = '';
		$is_ssr_render = wp_is_rest_endpoint() || is_admin();
		if ( ! $is_ssr_render && current_user_can( 'edit_posts' ) && $post_id > 0 ) {
			$permalink = get_permalink( $post_id );
			if ( false !== $permalink ) {
				$refresh_url  = add_query_arg(
					array(
						'immich_refresh' => 1,
						'_wpnonce'       => wp_create_nonce( 'immich_refresh_' . $album_id ),
					),
					$permalink
				);
				$refresh_link = '<p class="immich-album-refresh" style="font-size:12px;color:#757575;margin-top:4px;">'
					. '<a href="' . esc_url( $refresh_url ) . '">'
					. esc_html__( 'Refresh this album from Immich', 'media-picker-for-immich' )
					. '</a></p>';
			}
		}

		// Standard WP gallery markup: flex layout via the core `is-layout-flex`
		// + `wp-block-gallery-is-layout-flex` classes. Set both the CSS
		// variable and the actual `gap:` property inline so the gap matches
		// what our per-figure width calc assumes. Without forcing `gap:`,
		// themes can override it via blockGap (theme.json) and produce an
		// off-by-one column count: each row overflows by (N-1)*(real_gap-16)
		// and the last item wraps.
		$wrapper_style = sprintf(
			'--wp--style--unstable-gallery-gap:%dpx;gap:%dpx;',
			$gap_px,
			$gap_px
		);
		$lightbox      = ! empty( $attrs['lightbox'] );
		// When the lightbox is on, ship the translated close-button label as a
		// data attribute so the viewScript can apply it without needing a
		// wp-i18n dependency or a wp_set_script_translations() registration.
		$lightbox_attr = '';
		if ( $lightbox ) {
			$lightbox_attr = ' data-immich-lightbox="1"'
				. ' data-immich-lightbox-close="' . esc_attr__( 'Close', 'media-picker-for-immich' ) . '"';
		}

		return '<figure class="wp-block-gallery has-nested-images columns-' . (int) $columns . ' is-layout-flex wp-block-gallery-is-layout-flex immich-album-gallery"'
			. ' style="' . esc_attr( $wrapper_style ) . '"'
			. $lightbox_attr
			. '>'
			. $children
			. '</figure>'
			. $more_link
			. $refresh_link;
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

	private const ALBUM_SORTS = array( 'default', 'oldest', 'newest', 'random' );

	/**
	 * Build the cache key for an (album, sort) variant.
	 *
	 * Shared across all renders of the album — sidebar widget, post content,
	 * template part, etc. — because the cached payload only depends on what
	 * Immich returns for the album, not on which post happens to be wrapping
	 * the block. Authors with per-user API keys still see their own data
	 * because the first render after a flush refetches under the post
	 * author's key; the resulting cache then serves anyone hitting the same
	 * (album, sort) within the TTL.
	 */
	private function album_cache_key( string $album_id, string $sort ): string {
		return 'immich_album_' . $album_id . '_' . $sort;
	}

	/**
	 * Invalidate every sort variant for one album.
	 *
	 * Wired up by the editor refresh probe (?immich_refresh=1) and by the
	 * save_post hook for any post whose content references the album.
	 */
	private function flush_album_cache( string $album_id ): void {
		foreach ( self::ALBUM_SORTS as $sort ) {
			delete_transient( $this->album_cache_key( $album_id, $sort ) );
		}
	}

	/**
	 * Delete the cached album payloads for any immich/album-gallery blocks
	 * referenced in the saved post's content. Skips revisions and autosaves;
	 * bails fast if the marker isn't present so parse_blocks() only runs when
	 * the post actually contains the block.
	 *
	 * Fires for every post type, including reusable blocks (`wp_block`) and
	 * template parts (`wp_template_part`), so editing a synced pattern or a
	 * template part that contains the block also invalidates the album.
	 *
	 * @param int      $post_id  Post being saved.
	 * @param \WP_Post $post     The post object.
	 */
	public function on_post_save( int $post_id, \WP_Post $post ): void {
		if ( wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) ) {
			return;
		}
		if ( '' === $post->post_content || false === strpos( $post->post_content, 'wp:immich/album-gallery' ) ) {
			return;
		}
		$album_ids = array();
		$this->collect_album_block_ids( parse_blocks( $post->post_content ), $album_ids );
		foreach ( array_keys( $album_ids ) as $album_id ) {
			$this->flush_album_cache( $album_id );
		}
	}

	private function collect_album_block_ids( array $blocks, array &$album_ids ): void {
		foreach ( $blocks as $block ) {
			if ( 'immich/album-gallery' === ( $block['blockName'] ?? '' ) ) {
				$album_id = isset( $block['attrs']['albumId'] ) ? (string) $block['attrs']['albumId'] : '';
				if ( '' !== $album_id && preg_match( self::UUID_PATTERN, $album_id ) ) {
					$album_ids[ $album_id ] = true;
				}
			}
			if ( ! empty( $block['innerBlocks'] ) ) {
				$this->collect_album_block_ids( $block['innerBlocks'], $album_ids );
			}
		}
	}

	/**
	 * Fetch and prepare the asset list for an album, with caching and hard cap.
	 *
	 * @param string $album_id  Validated UUID.
	 * @param string $sort      Sort key (default 'default'; expanded in Task 8).
	 * @param int    $author_id Post author whose per-user API key authorises the
	 *                          fetch when no site-wide key is configured. Mirrors
	 *                          handle_proxy_request()'s author lookup so cold-cache
	 *                          renders work for logged-out visitors.
	 * @return array{assets: array, total_count: int, fetched_at: int}|\WP_Error
	 */
	private function fetch_album_assets( string $album_id, string $sort = 'default', int $author_id = 0 ) {
		$key    = $this->album_cache_key( $album_id, $sort );
		$cached = get_transient( $key );
		if ( false !== $cached && is_array( $cached ) ) {
			return $cached;
		}

		$response = $this->api_request( '/api/albums/' . rawurlencode( $album_id ), 'GET', null, $author_id );
		if ( is_wp_error( $response ) ) {
			return $response;
		}

		if ( ! isset( $response['assets'] ) || ! is_array( $response['assets'] ) ) {
			return new \WP_Error(
				'immich_album_malformed',
				__( 'Immich returned an unexpected album response.', 'media-picker-for-immich' )
			);
		}

		$payload = $this->prepare_album_payload( $response, $sort );
		set_transient( $key, $payload, $this->album_cache_ttl() );
		return $payload;
	}

	/**
	 * Build the cache payload from a raw Immich /api/albums/{id} response.
	 *
	 * Applies sort and the hard cap. Stores only the fields needed for render.
	 * The `total_count` field is the album's full pre-cap size (used by the
	 * "View N more on Immich" link added in Task 13).
	 *
	 * @param array  $response Raw Immich response (must contain 'assets').
	 * @param string $sort     One of 'default', 'oldest', 'newest', 'random'.
	 * @return array{assets: array, total_count: int, fetched_at: int}
	 */
	private function prepare_album_payload( array $response, string $sort ): array {
		$raw   = isset( $response['assets'] ) && is_array( $response['assets'] ) ? $response['assets'] : array();
		$total = count( $raw );

		$sorted = $this->sort_album_assets( $raw, $sort );

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

	/**
	 * Sort an album's asset list by the requested order.
	 *
	 * @param array  $assets Raw asset array from Immich (each item has at least
	 *                       `fileCreatedAt` for date-based sorts).
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
}

add_action( 'plugins_loaded', function () {
	Immich_Media_Picker::instance();
} );
