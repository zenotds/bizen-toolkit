<?php
namespace Bizen\MediaManager\Rest;

use Bizen\MediaManager\Utils\Singleton;

defined( 'ABSPATH' ) || exit;

class RestApi {
    use Singleton;

    public function __construct() {
        add_action( 'rest_api_init', array( $this, 'rest_api_init' ) );
    }

    public function rest_api_init() {
        $rest_apis = array(
            new FolderApi(),
            new SettingApi(),
            new ToolsApi(),
        );

        foreach ( $rest_apis as $rest_api ) {
            $rest_api->register_rest_routes();
        }
    }
}
