<?php
/**
 * WPML Independent Duplicates module.
 *
 * Bizen module — written and maintained by Bizen (https://bizen.it).
 *
 * A WPML duplicate stays bound to its original: WPML rewrites it every time the
 * original is saved, and it refuses to open it in the WordPress editor even when
 * the site is set to translate with it — Manual::maybeGetDataIfTranslationCreatedInNativeEditorViaConnection()
 * returns null for duplicates, so the edit link falls through to the classic
 * Translation Editor with its "Edit independently" dialog. On sites translated by
 * hand in the WordPress editor (ACF flexible content, page builders) that makes
 * "Duplicate" unusable.
 *
 * This module turns "Duplicate" into a one-off copy: right after WPML creates the
 * duplicate, the link is reset exactly as the "Translate independently" button
 * does (translation status set to complete, _icl_lang_duplicate_of removed). The
 * copy keeps its content and opens in the WordPress editor.
 *
 * Duplicates created before the module was enabled are left alone; unlink them
 * once with `wp bizen-wpml unlink-duplicates`.
 *
 * To keep some duplicates synced (a post type whose translations should mirror the
 * original), return false from `bizen_wpml_independent_duplicates_unlink`:
 *
 *     add_filter( 'bizen_wpml_independent_duplicates_unlink', function ( $unlink, $post_id ) {
 *         return 'team' === get_post_type( $post_id ) ? false : $unlink;
 *     }, 10, 2 );
 */

defined( 'ABSPATH' ) || exit;

return new class extends Bizen_Module {

	public function get_id(): string {
		return 'wpml-independent-duplicates';
	}

	public function get_name(): string {
		return __( 'Independent WPML duplicates', 'bizen-toolkit' );
	}

	public function get_description(): string {
		return __( 'Turns WPML "Duplicate" into a one-off copy: the duplicate is unlinked from the original right away, so it opens in the WordPress editor and is no longer overwritten when the original is saved.', 'bizen-toolkit' );
	}

	public function get_category(): string {
		return 'admin';
	}

	public function get_dependencies(): array {
		return [
			'sitepress-multilingual-cms/sitepress.php',
		];
	}

	public function boot(): void {
		// Last, so every other listener still sees a regular duplicate.
		add_action( 'icl_make_duplicate', [ $this, 'unlink_duplicate' ], PHP_INT_MAX, 4 );

		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			WP_CLI::add_command( 'bizen-wpml unlink-duplicates', [ $this, 'cli_unlink_duplicates' ] );
		}
	}

	public function unlink_duplicate( $master_post_id, $lang, $post_array, $id ): void {
		if ( ! $id || is_wp_error( $id ) || ! function_exists( 'wpml_load_core_tm' ) ) {
			return;
		}

		if ( $this->should_unlink( (int) $id, (int) $master_post_id ) ) {
			wpml_load_core_tm()->reset_duplicate_flag( $id );
		}
	}

	private function should_unlink( int $post_id, int $master_post_id ): bool {
		return (bool) apply_filters( 'bizen_wpml_independent_duplicates_unlink', true, $post_id, $master_post_id );
	}

	/**
	 * Unlinks every existing WPML duplicate, turning it into an independent translation.
	 *
	 * ## OPTIONS
	 *
	 * [--dry-run]
	 * : List the duplicates without unlinking them.
	 */
	public function cli_unlink_duplicates( array $args, array $assoc_args ): void {
		global $wpdb;

		if ( ! function_exists( 'wpml_load_core_tm' ) ) {
			WP_CLI::error( 'WPML is not loaded.' );
		}

		$tm = wpml_load_core_tm();

		$rows = $wpdb->get_results(
			"SELECT post_id, meta_value FROM {$wpdb->postmeta} WHERE meta_key = '_icl_lang_duplicate_of'"
		);

		$ids = [];
		foreach ( $rows as $row ) {
			$id = (int) $row->post_id;
			if ( get_post( $id ) && $this->should_unlink( $id, (int) $row->meta_value ) ) {
				$ids[] = $id;
			}
		}

		if ( ! $ids ) {
			WP_CLI::success( 'No duplicates found.' );
			return;
		}

		if ( ! empty( $assoc_args['dry-run'] ) ) {
			foreach ( $ids as $id ) {
				WP_CLI::log( sprintf( '%d  %s', $id, get_the_title( $id ) ) );
			}
			WP_CLI::success( sprintf( '%d duplicates would be unlinked.', count( $ids ) ) );
			return;
		}

		foreach ( $ids as $id ) {
			$tm->reset_duplicate_flag( $id );
		}

		WP_CLI::success( sprintf( '%d duplicates unlinked.', count( $ids ) ) );
	}
};
