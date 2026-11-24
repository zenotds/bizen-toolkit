<?php
/**
 * Term Order module.
 *
 * Bizen module — written and maintained by Bizen (https://bizen.it).
 *
 * Drag-and-drop ordering of the terms of every taxonomy with an admin UI, plus
 * an "Order" column on the term list that doubles as the drag handle.
 *
 * The order lives in a `term_order` column on the terms table — the same one
 * Category Order and Taxonomy Terms Order, Simple Custom Post Order, Intuitive
 * Custom Post Order and Custom Taxonomy Order use — so a site moving off one of
 * those keeps its order as it is: deactivate the plugin, enable the module. The
 * column is added on first boot when it is not there yet.
 *
 * The order applies everywhere terms are queried with the default ordering (by
 * name), front end and admin: get_terms(), get_the_terms(), wp_list_categories()
 * and friends. An explicit orderby other than name is left alone, and so is the
 * term list when a column header has been clicked. `orderby => 'term_order'`
 * works too. WooCommerce product categories and attributes are skipped: WooCommerce
 * sorts those itself.
 *
 * Terms are dragged among their siblings (same parent); their children move with
 * them. The whole sibling group is renumbered from 1 on every drop, so dragging
 * on page 2 of a long list or in search results keeps the terms that are not
 * shown where they were. New terms go to the end of their group.
 *
 * With WPML, every language keeps its own order: a drop renumbers only the terms
 * of the language being edited. "Sync order across languages", under the term
 * list, copies the order of the language shown to its translations; terms with no
 * counterpart in that language go after the copied ones, in their current order.
 */

defined( 'ABSPATH' ) || exit;

return new class extends Bizen_Module {

	private const COLUMN_FLAG = 'bizen_term_order_column';
	private const NONCE       = 'bizen_term_order';

	public function get_id(): string {
		return 'term-order';
	}

	public function get_name(): string {
		return __( 'Term Order', 'bizen-toolkit' );
	}

	public function get_description(): string {
		return __( 'Drag-and-drop ordering of categories, tags and custom taxonomy terms, with an Order column on the term list. The order applies wherever terms are listed by default, front end included; with WPML each language has its own order, and a button copies the order of one language to the others. Uses the same term_order column as Category Order and Taxonomy Terms Order, Simple Custom Post Order and Intuitive Custom Post Order, so existing orders are kept.', 'bizen-toolkit' );
	}

	public function get_summary(): string {
		return __( 'Drag-and-drop ordering of taxonomy terms, with an Order column.', 'bizen-toolkit' );
	}

	public function get_category(): string {
		return 'admin';
	}

	public function get_conflicts(): array {
		return [
			[ 'file' => 'taxonomy-terms-order/taxonomy-terms-order.php', 'name' => 'Category Order and Taxonomy Terms Order' ],
			[ 'file' => 'simple-custom-post-order/simple-custom-post-order.php', 'name' => 'Simple Custom Post Order' ],
			[ 'file' => 'intuitive-custom-post-order/intuitive-custom-post-order.php', 'name' => 'Intuitive Custom Post Order' ],
			[ 'file' => 'custom-taxonomy-order-ne/customtaxorder.php', 'name' => 'Custom Taxonomy Order' ],
		];
	}

	public function boot(): void {
		if ( ! $this->ensure_column() ) {
			return;
		}

		add_filter( 'get_terms_orderby', [ $this, 'orderby' ], 10, 3 );
		add_action( 'created_term', [ $this, 'place_new_term' ], PHP_INT_MAX, 3 );

		if ( is_admin() ) {
			add_action( 'admin_init', [ $this, 'register_columns' ] );
			add_action( 'admin_enqueue_scripts', [ $this, 'enqueue' ] );
			add_action( 'wp_ajax_bizen_term_order', [ $this, 'ajax_save' ] );
			add_action( 'admin_post_bizen_term_order_sync', [ $this, 'sync_languages' ] );
			add_action( 'admin_notices', [ $this, 'sync_notice' ] );
			add_filter( 'removable_query_args', fn( $args ) => array_merge( $args, [ 'bizen_term_order_synced' ] ) );
		}
	}

	/** Adds wp_terms.term_order when missing. False when it could not be added. */
	private function ensure_column(): bool {
		global $wpdb;

		if ( get_option( self::COLUMN_FLAG ) ) {
			return true;
		}

		$has_column = fn() => (bool) $wpdb->get_var( "SHOW COLUMNS FROM {$wpdb->terms} LIKE 'term_order'" );

		if ( ! $has_column() ) {
			$wpdb->query( "ALTER TABLE {$wpdb->terms} ADD `term_order` INT(4) NOT NULL DEFAULT 0" );
		}

		if ( ! $has_column() ) {
			return false;
		}

		update_option( self::COLUMN_FLAG, 1 );
		return true;
	}

	private function is_sortable( string $taxonomy ): bool {
		$tax = get_taxonomy( $taxonomy );
		if ( ! $tax || ! $tax->show_ui ) {
			return false;
		}

		// WooCommerce orders these itself (term meta "order", its own drag and drop).
		if ( function_exists( 'taxonomy_is_product_attribute' ) && ( 'product_cat' === $taxonomy || taxonomy_is_product_attribute( $taxonomy ) ) ) {
			return false;
		}

		return true;
	}

	public function orderby( $orderby, $args, $taxonomies ) {
		// Core maps term_order to the relationships table, which only exists in object queries.
		if ( 'term_order' === $args['orderby'] && empty( $args['object_ids'] ) ) {
			return 't.term_order';
		}

		if ( 'name' !== $args['orderby'] || ! $taxonomies ) {
			return $orderby;
		}

		foreach ( (array) $taxonomies as $taxonomy ) {
			if ( ! $this->is_sortable( $taxonomy ) ) {
				return $orderby;
			}
		}

		// A column header clicked on the term list.
		if ( is_admin() && 'edit-tags.php' === ( $GLOBALS['pagenow'] ?? '' ) && ! empty( $_GET['orderby'] ) ) {
			return $orderby;
		}

		return 't.term_order ASC, t.name';
	}

	/** New terms go last in their group. */
	public function place_new_term( $term_id, $tt_id, $taxonomy ): void {
		global $wpdb;

		if ( ! $this->is_sortable( $taxonomy ) ) {
			return;
		}

		$term  = get_term( $term_id, $taxonomy );
		$order = 1 + (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT MAX(t.term_order)
			FROM {$wpdb->terms} t
			INNER JOIN {$wpdb->term_taxonomy} tt ON tt.term_id = t.term_id
			WHERE tt.taxonomy = %s AND tt.parent = %d",
			$taxonomy,
			$term instanceof WP_Term ? $term->parent : 0
		) );

		$this->save_orders( [ (int) $term_id => $order ], $taxonomy );
	}

	public function register_columns(): void {
		foreach ( get_taxonomies( [ 'show_ui' => true ] ) as $taxonomy ) {
			if ( $this->is_sortable( $taxonomy ) ) {
				add_filter( "manage_edit-{$taxonomy}_columns", [ $this, 'add_column' ] );
				add_filter( "manage_{$taxonomy}_custom_column", [ $this, 'render_column' ], 10, 3 );
				add_action( "after-{$taxonomy}-table", [ $this, 'render_sync_button' ] );
			}
		}
	}

	/** First after the checkbox: the cell is the drag handle. */
	public function add_column( $columns ) {
		$cb = isset( $columns['cb'] ) ? [ 'cb' => $columns['cb'] ] : [];

		return $cb + [ 'term_order' => __( 'Order', 'bizen-toolkit' ) ] + $columns;
	}

	public function render_column( $content, $column, $term_id ) {
		if ( 'term_order' !== $column ) {
			return $content;
		}

		$term = get_term( $term_id );
		if ( ! $term instanceof WP_Term ) {
			return $content;
		}

		return sprintf(
			'<span class="bizen-term-order" data-parent="%d"><span class="dashicons dashicons-menu" aria-hidden="true"></span> <span class="bizen-term-order-value">%d</span></span>',
			$term->parent,
			(int) ( $term->term_order ?? 0 )
		);
	}

	/** Only with WPML, more than one language and a single language shown. */
	public function render_sync_button( $taxonomy ): void {
		$language  = apply_filters( 'wpml_current_language', null );
		$languages = (array) apply_filters( 'wpml_active_languages', null, [ 'skip_missing' => 0 ] );

		if ( ! $language || 'all' === $language || count( $languages ) < 2 || ! current_user_can( get_taxonomy( $taxonomy )->cap->edit_terms ) ) {
			return;
		}

		printf(
			'<form method="post" action="%s" onsubmit="return confirm(%s);">
				<input type="hidden" name="action" value="bizen_term_order_sync">
				<input type="hidden" name="taxonomy" value="%s">
				<input type="hidden" name="language" value="%s">
				%s
				<p><button type="submit" class="button">%s</button></p>
			</form>',
			esc_url( admin_url( 'admin-post.php' ) ),
			esc_attr( wp_json_encode( __( 'Replace the term order of the other languages with the order of this one?', 'bizen-toolkit' ) ) ),
			esc_attr( $taxonomy ),
			esc_attr( $language ),
			wp_nonce_field( self::NONCE, '_wpnonce', true, false ),
			esc_html__( 'Sync order across languages', 'bizen-toolkit' )
		);
	}

	/**
	 * Gives every translation the number of its counterpart in the source language.
	 * Terms with no counterpart go after the highest copied number, keeping their
	 * order within their sibling group.
	 */
	public function sync_languages(): void {
		global $wpdb;

		check_admin_referer( self::NONCE );

		$taxonomy = sanitize_key( wp_unslash( $_POST['taxonomy'] ?? '' ) );
		$source   = sanitize_key( wp_unslash( $_POST['language'] ?? '' ) );
		$tax      = get_taxonomy( $taxonomy );

		if ( ! $tax || ! $source || ! $this->is_sortable( $taxonomy ) || ! current_user_can( $tax->cap->edit_terms ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to do that.' ), 403 );
		}

		$rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT t.term_id, t.term_order, tt.parent, ic.trid, ic.language_code
			FROM {$wpdb->terms} t
			INNER JOIN {$wpdb->term_taxonomy} tt ON tt.term_id = t.term_id
			INNER JOIN {$wpdb->prefix}icl_translations ic ON ic.element_id = tt.term_taxonomy_id AND ic.element_type = CONCAT( 'tax_', tt.taxonomy )
			WHERE tt.taxonomy = %s
			ORDER BY tt.parent ASC, t.term_order ASC, t.name ASC",
			$taxonomy
		) );

		$copied = [];
		foreach ( $rows as $row ) {
			if ( $row->language_code === $source ) {
				$copied[ (int) $row->trid ] = (int) $row->term_order;
			}
		}

		$offset  = $copied ? max( $copied ) : 0;
		$rank    = [];
		$changed = [];

		foreach ( $rows as $row ) {
			if ( $row->language_code === $source ) {
				continue;
			}

			if ( isset( $copied[ (int) $row->trid ] ) ) {
				$order = $copied[ (int) $row->trid ];
			} else {
				$rank[ $row->language_code ][ $row->parent ] = ( $rank[ $row->language_code ][ $row->parent ] ?? 0 ) + 1;
				$order = $offset + $rank[ $row->language_code ][ $row->parent ];
			}

			if ( (int) $row->term_order !== $order ) {
				$changed[ (int) $row->term_id ] = $order;
			}
		}

		$this->save_orders( $changed, $taxonomy );

		wp_safe_redirect( add_query_arg( 'bizen_term_order_synced', count( $changed ), wp_get_referer() ?: admin_url( 'edit-tags.php?taxonomy=' . $taxonomy ) ) );
		exit;
	}

	public function sync_notice(): void {
		if ( 'edit-tags.php' !== ( $GLOBALS['pagenow'] ?? '' ) || ! isset( $_GET['bizen_term_order_synced'] ) ) {
			return;
		}

		$count = (int) $_GET['bizen_term_order_synced'];

		printf(
			'<div class="notice notice-success is-dismissible"><p>%s</p></div>',
			esc_html( sprintf(
				/* translators: %d: number of translated terms whose order changed */
				_n( 'Order synced across languages: %d term updated.', 'Order synced across languages: %d terms updated.', $count, 'bizen-toolkit' ),
				$count
			) )
		);
	}

	public function enqueue( $hook ): void {
		$screen = get_current_screen();

		if ( 'edit-tags.php' !== $hook || ! $screen || ! $this->is_sortable( (string) $screen->taxonomy ) ) {
			return;
		}

		$base = plugin_dir_url( __FILE__ ) . 'assets/';
		wp_enqueue_style( 'bizen-term-order', $base . 'term-order.css', [], BIZEN_TOOLKIT_VERSION );

		// Dragging makes no sense while the list is sorted by another column.
		if ( ! empty( $_GET['orderby'] ) ) {
			return;
		}

		wp_enqueue_script( 'bizen-term-order', $base . 'term-order.js', [ 'jquery', 'jquery-ui-sortable' ], BIZEN_TOOLKIT_VERSION, true );
		wp_localize_script( 'bizen-term-order', 'bizenTermOrder', [
			'ajaxUrl'  => admin_url( 'admin-ajax.php' ),
			'nonce'    => wp_create_nonce( self::NONCE ),
			'taxonomy' => $screen->taxonomy,
		] );
	}

	/**
	 * Receives the new order of the sibling rows shown on the page. The slots those
	 * terms held in the full sibling group are refilled in the new order, then the
	 * group is renumbered from 1 — terms on other pages keep their place.
	 */
	public function ajax_save(): void {
		check_ajax_referer( self::NONCE, 'nonce' );

		$taxonomy = sanitize_key( wp_unslash( $_POST['taxonomy'] ?? '' ) );
		$tax      = get_taxonomy( $taxonomy );
		$ids      = array_values( array_unique( array_map( 'intval', (array) ( $_POST['ids'] ?? [] ) ) ) );
		$parent   = (int) ( $_POST['parent'] ?? 0 );

		if ( ! $tax || ! $this->is_sortable( $taxonomy ) || ! current_user_can( $tax->cap->edit_terms ) ) {
			wp_send_json_error( null, 403 );
		}

		$group = $this->siblings( $taxonomy, $parent, $ids[0] ?? 0 );
		$slots = array_keys( array_intersect( array_keys( $group ), $ids ) );

		if ( ! $ids || count( $slots ) !== count( $ids ) ) {
			wp_send_json_error( null, 409 ); // The page is stale.
		}

		$sequence = array_keys( $group );
		foreach ( $slots as $i => $slot ) {
			$sequence[ $slot ] = $ids[ $i ];
		}

		$changed = [];
		foreach ( $sequence as $i => $term_id ) {
			if ( $group[ $term_id ] !== $i + 1 ) {
				$changed[ $term_id ] = $i + 1;
			}
		}

		$this->save_orders( $changed, $taxonomy );

		wp_send_json_success( array_map( fn( $id ) => array_search( $id, $sequence, true ) + 1, array_combine( $ids, $ids ) ) );
	}

	/**
	 * term_id => term_order of a sibling group, in its current order. With WPML,
	 * only the terms in the same language as $term_id.
	 *
	 * @return array<int, int>
	 */
	private function siblings( string $taxonomy, int $parent, int $term_id ): array {
		global $wpdb;

		$join  = '';
		$where = '';
		$args  = [ $taxonomy, $parent ];

		if ( defined( 'ICL_SITEPRESS_VERSION' ) ) {
			$icl   = $wpdb->prefix . 'icl_translations';
			$join  = "INNER JOIN {$icl} ic ON ic.element_id = tt.term_taxonomy_id AND ic.element_type = CONCAT( 'tax_', tt.taxonomy )";
			$where = "AND ic.language_code = (
				SELECT ic2.language_code FROM {$icl} ic2
				INNER JOIN {$wpdb->term_taxonomy} tt2 ON tt2.term_taxonomy_id = ic2.element_id
				WHERE tt2.term_id = %d AND ic2.element_type = CONCAT( 'tax_', tt2.taxonomy )
			)";
			$args[] = $term_id;
		}

		$rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT t.term_id, t.term_order
			FROM {$wpdb->terms} t
			INNER JOIN {$wpdb->term_taxonomy} tt ON tt.term_id = t.term_id
			{$join}
			WHERE tt.taxonomy = %s AND tt.parent = %d {$where}
			ORDER BY t.term_order ASC, t.name ASC",
			$args
		) );

		return array_combine(
			array_map( 'intval', wp_list_pluck( $rows, 'term_id' ) ),
			array_map( 'intval', wp_list_pluck( $rows, 'term_order' ) )
		);
	}

	/** @param array<int, int> $orders term_id => term_order */
	private function save_orders( array $orders, string $taxonomy ): void {
		global $wpdb;

		if ( ! $orders ) {
			return;
		}

		foreach ( $orders as $term_id => $order ) {
			$wpdb->update( $wpdb->terms, [ 'term_order' => $order ], [ 'term_id' => $term_id ], [ '%d' ], [ '%d' ] );
		}

		clean_term_cache( array_keys( $orders ), $taxonomy, false );
	}
};
