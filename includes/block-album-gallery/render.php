<?php
/**
 * Server-side render shim for immich/album-gallery.
 *
 * @package Immich_Media_Picker
 */

defined( 'ABSPATH' ) || exit;

echo Immich_Media_Picker::instance()->render_album_block( is_array( $attributes ) ? $attributes : array() );
