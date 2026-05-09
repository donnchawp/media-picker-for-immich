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

$immich_attrs = is_array( $attributes ) ? $attributes : array();
$immich_block = isset( $block ) && $block instanceof WP_Block ? $block : null;
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- HTML is already escaped inside render_album_block().
echo Immich_Media_Picker::instance()->render_album_block( $immich_attrs, $immich_block );
