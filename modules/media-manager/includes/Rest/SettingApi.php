<?php
namespace Bizen\MediaManager\Rest;

defined( 'ABSPATH' ) || exit;

use Bizen\MediaManager\Controller\SettingController;

class SettingApi {
	private $controller;

	public function register_rest_routes() {
		$this->controller = new SettingController();

		register_rest_route(
			BIZEN_MM_REST_URL,
			'set-user-settings',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this->controller, 'setUserSettings' ),
				'permission_callback' => array( $this, 'permission_user_callback' ),
			)
		);
	}

	public function permission_user_callback() {
		return current_user_can( 'upload_files' );
	}
}
