<?php
/**
 * Cache files list table for Media → Cache Files.
 *
 * @package Media_Picker_For_Immich
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'WP_List_Table' ) ) {
	require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

class Immich_Cache_List_Table extends WP_List_Table {

	private Immich_Media_Picker $plugin;

	private string $page_slug;

	private string $preview_nonce;

	/** @var array<string, int> */
	private array $attachment_map = array();

	private int $total_size = 0;

	private int $total_count = 0;

	public function __construct( Immich_Media_Picker $plugin, string $page_slug, string $preview_nonce ) {
		parent::__construct( array(
			'singular' => 'cache_file',
			'plural'   => 'cache_files',
			'ajax'     => false,
		) );
		$this->plugin        = $plugin;
		$this->page_slug     = $page_slug;
		$this->preview_nonce = $preview_nonce;
	}

	public function get_columns(): array {
		return array(
			'cb'         => '<input type="checkbox" />',
			'thumb'      => __( 'Thumbnail', 'media-picker-for-immich' ),
			'name'       => __( 'Name', 'media-picker-for-immich' ),
			'cache_type' => __( 'Cache Type', 'media-picker-for-immich' ),
			'size'       => __( 'Size', 'media-picker-for-immich' ),
			'age'        => __( 'Age', 'media-picker-for-immich' ),
		);
	}

	protected function get_sortable_columns(): array {
		return array(
			'name'       => array( 'name', false ),
			'cache_type' => array( 'cache_type', false ),
			'size'       => array( 'size', false ),
			'age'        => array( 'age', true ),
		);
	}

	protected function get_bulk_actions(): array {
		return array(
			'delete' => __( 'Delete', 'media-picker-for-immich' ),
		);
	}

	public function total_size(): int {
		return $this->total_size;
	}

	public function total_count(): int {
		return $this->total_count;
	}

	public function prepare_items(): void {
		$items = $this->plugin->enumerate_cache_files();

		// Compute totals across the entire cache, not the current page.
		$this->total_count = count( $items );
		$this->total_size  = array_sum( array_column( $items, 'size' ) );

		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only sort/page params
		$orderby = sanitize_key( wp_unslash( $_GET['orderby'] ?? 'age' ) );
		$order   = isset( $_GET['order'] ) && 'asc' === sanitize_key( wp_unslash( $_GET['order'] ) ) ? 'asc' : 'desc';
		$paged   = max( 1, absint( wp_unslash( $_GET['paged'] ?? 1 ) ) );
		// phpcs:enable

		usort( $items, function ( $a, $b ) use ( $orderby ) {
			switch ( $orderby ) {
				case 'name':
					return strcasecmp( $a['uuid'], $b['uuid'] );
				case 'cache_type':
					return strcmp( $a['type'], $b['type'] );
				case 'size':
					return $a['size'] <=> $b['size'];
				case 'age':
				default:
					return $a['mtime'] <=> $b['mtime'];
			}
		} );

		if ( 'desc' === $order ) {
			$items = array_reverse( $items );
		}

		$per_page = 20;
		$page_items = array_slice( $items, ( $paged - 1 ) * $per_page, $per_page );

		// Resolve names for the current page only.
		$uuids                 = array_unique( array_column( $page_items, 'uuid' ) );
		$this->attachment_map  = $this->plugin->lookup_attachments_for_uuids( $uuids );

		$this->_column_headers = array(
			$this->get_columns(),
			array(),
			$this->get_sortable_columns(),
		);

		$this->set_pagination_args( array(
			'total_items' => $this->total_count,
			'per_page'    => $per_page,
		) );

		$this->items = $page_items;
	}

	public function column_cb( $item ): string {
		return sprintf(
			'<input type="checkbox" name="cache_items[]" value="%s" />',
			esc_attr( $item['type'] . ':' . $item['uuid'] )
		);
	}

	public function column_thumb( $item ): string {
		$src = add_query_arg(
			array(
				'immich_media_proxy' => 'thumbnail',
				'id'                 => $item['uuid'],
				'preview_nonce'      => $this->preview_nonce,
			),
			home_url( '/' )
		);
		return sprintf(
			'<img src="%s" alt="" style="width:48px;height:48px;object-fit:cover;border-radius:3px;background:#f0f0f1;" loading="lazy" />',
			esc_url( $src )
		);
	}

	public function column_name( $item ): string {
		$attach_id = $this->attachment_map[ $item['uuid'] ] ?? 0;
		if ( $attach_id ) {
			$title    = get_the_title( $attach_id );
			$edit_url = get_edit_post_link( $attach_id );
			$display  = $edit_url
				? sprintf( '<a href="%s">%s</a>', esc_url( $edit_url ), esc_html( $title ) )
				: esc_html( $title );
		} else {
			$display = sprintf(
				'<code title="%s">%s…</code>',
				esc_attr( $item['uuid'] ),
				esc_html( substr( $item['uuid'], 0, 8 ) )
			);
		}

		$delete_url = wp_nonce_url(
			add_query_arg(
				array(
					'page'        => $this->page_slug,
					'immich_act'  => 'delete',
					'cache_type'  => $item['type'],
					'cache_uuid'  => $item['uuid'],
				),
				admin_url( 'upload.php' )
			),
			'immich_delete_cache_' . $item['uuid']
		);
		$actions = array(
			'delete' => sprintf(
				'<a href="%s" class="submitdelete">%s</a>',
				esc_url( $delete_url ),
				esc_html__( 'Delete', 'media-picker-for-immich' )
			),
		);

		return $display . $this->row_actions( $actions );
	}

	public function column_cache_type( $item ): string {
		return esc_html( $item['type'] );
	}

	public function column_size( $item ): string {
		return esc_html( size_format( $item['size'] ) ?: '0 B' );
	}

	public function column_age( $item ): string {
		$diff = human_time_diff( $item['mtime'], time() );
		/* translators: %s: human-readable duration like "5 mins" */
		return esc_html( sprintf( __( '%s ago', 'media-picker-for-immich' ), $diff ) );
	}

	public function no_items(): void {
		esc_html_e( 'No cached files yet. Browsing or proxying Immich assets will populate the cache.', 'media-picker-for-immich' );
	}
}
