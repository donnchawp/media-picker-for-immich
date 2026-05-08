<?php
/**
 * Server-side render shim for immich/album-gallery.
 *
 * WP exposes `$attributes`, `$content`, and `$block` in this scope; we forward
 * the `$block` so render_album_block() can read `$block->context['postId']`
 * for blocks rendered outside The Loop (sidebar widgets, FSE template parts).
 *
 * @package Immich_Media_Picker
 */

defined( 'ABSPATH' ) || exit;

echo Immich_Media_Picker::instance()->render_album_block(
	is_array( $attributes ) ? $attributes : array(),
	isset( $block ) && $block instanceof WP_Block ? $block : null
);
