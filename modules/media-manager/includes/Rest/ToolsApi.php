<?php
namespace Bizen\MediaManager\Rest;

defined( 'ABSPATH' ) || exit;

use Bizen\MediaManager\Model\Folder as FolderModel;

/**
 * "Clear all folders", reached from the Tools submenu of the folder sidebar.
 * Same route as FileBird's tool, so the bundle's own calls keep working.
 */
class ToolsApi {
	const POST_TYPE_TAXONOMY_PREFIX = 'fbv_pt_tax_';

	public function register_rest_routes() {
		register_rest_route(
			BIZEN_MM_REST_URL,
			'fb-wipe-clear-all-data',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'clearAll' ),
				'permission_callback' => function () {
					return current_user_can( 'manage_options' );
				},
			)
		);
	}

	/** Drops every folder, media and post type alike. Attachments and posts are not touched. */
	public function clearAll() {
		$this->deletePostTypeFolders();
		FolderModel::deleteAll();
		delete_option( 'fbv_folder_colors' );

		return rest_ensure_response(
			array(
				'success' => true,
				'mess'    => __( 'All folders deleted.', 'bizen-toolkit' ),
			)
		);
	}

	private function deletePostTypeFolders() {
		global $wpdb;

		$taxonomies = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT DISTINCT `taxonomy` FROM {$wpdb->term_taxonomy} WHERE `taxonomy` LIKE %s",
				$wpdb->esc_like( self::POST_TYPE_TAXONOMY_PREFIX ) . '%'
			)
		);

		foreach ( $taxonomies as $taxonomy ) {
			$term_ids = $wpdb->get_col( $wpdb->prepare( "SELECT `term_id` FROM {$wpdb->term_taxonomy} WHERE `taxonomy` = %s", $taxonomy ) );

			if ( empty( $term_ids ) ) {
				continue;
			}

			$object_ids = $wpdb->get_col(
				$wpdb->prepare(
					"SELECT DISTINCT `tr`.`object_id`
					FROM {$wpdb->term_relationships} AS `tr`
					INNER JOIN {$wpdb->term_taxonomy} AS `tt` ON `tt`.`term_taxonomy_id` = `tr`.`term_taxonomy_id`
					WHERE `tt`.`taxonomy` = %s",
					$taxonomy
				)
			);

			// Terms shared with another taxonomy (split-term leftovers) keep their row and meta.
			$queries = array(
				"DELETE FROM {$wpdb->termmeta} WHERE `term_id` IN (SELECT `term_id` FROM {$wpdb->term_taxonomy} WHERE `taxonomy` = %s) AND `term_id` NOT IN (SELECT `term_id` FROM {$wpdb->term_taxonomy} WHERE `taxonomy` != %s)",
				"DELETE FROM {$wpdb->terms} WHERE `term_id` IN (SELECT `term_id` FROM {$wpdb->term_taxonomy} WHERE `taxonomy` = %s) AND `term_id` NOT IN (SELECT `term_id` FROM {$wpdb->term_taxonomy} WHERE `taxonomy` != %s)",
				"DELETE FROM {$wpdb->term_relationships} WHERE `term_taxonomy_id` IN (SELECT `term_taxonomy_id` FROM {$wpdb->term_taxonomy} WHERE `taxonomy` = %s)",
				"DELETE FROM {$wpdb->term_taxonomy} WHERE `taxonomy` = %s",
			);

			foreach ( $queries as $query ) {
				$args = array_fill( 0, substr_count( $query, '%s' ), $taxonomy );
				// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
				$wpdb->query( $wpdb->prepare( $query, $args ) );
			}

			foreach ( $object_ids as $object_id ) {
				wp_cache_delete( $object_id, "{$taxonomy}_relationships" );
			}

			clean_term_cache( $term_ids, $taxonomy );

			delete_option( "{$taxonomy}_children" );
			delete_option( 'fbv_' . substr( $taxonomy, strlen( self::POST_TYPE_TAXONOMY_PREFIX ) ) . '_folder_colors' );
		}
	}
}
