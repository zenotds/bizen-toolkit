<?php
namespace Bizen\MediaManager\Rest;

defined( 'ABSPATH' ) || exit;

use Bizen\MediaManager\Model\Folder as FolderModel;

/**
 * "Clear all folders", reached from the Tools submenu of the folder sidebar.
 * Same route as FileBird's tool, so the bundle's own calls keep working.
 */
class ToolsApi {
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

	/** Drops every media folder. The attachments themselves are not touched. */
	public function clearAll() {
		FolderModel::deleteAll();
		delete_option( 'fbv_folder_colors' );

		return rest_ensure_response(
			array(
				'success' => true,
				'mess'    => __( 'All folders deleted.', 'bizen-toolkit' ),
			)
		);
	}
}
