<?php
/**
 * Asset manifest for assets/js/immich-album-block.js.
 *
 * Hand-authored (no build step). Lists the WordPress script handles the
 * block editor script depends on, so register_block_type() resolves
 * dependencies correctly without a generated asset.php from
 * @wordpress/scripts.
 *
 * @package Immich_Media_Picker
 */

return array(
	'dependencies' => array(
		'wp-blocks',
		'wp-block-editor',
		'wp-components',
		'wp-element',
		'wp-i18n',
	),
	'version'      => '1.0.0',
);
