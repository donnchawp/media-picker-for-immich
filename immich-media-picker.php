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
		if ( '' === $api_key ) {
			$api_key = $existing['api_key'] ?? '';
		}

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
}

add_action( 'plugins_loaded', function () {
	new Immich_Media_Picker();
} );
