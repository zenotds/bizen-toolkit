<?php

namespace Bizen\MediaManager\Controller;

use Bizen\MediaManager\Classes\Helpers;
use Bizen\MediaManager\Model\SettingModel;
use Bizen\MediaManager\Model\UserSettingModel;

defined( 'ABSPATH' ) || exit;

class SettingController {
    public function setUserSettings( \WP_REST_Request $request ) {
        $params = Helpers::sanitize_array( $request->get_params() );

        UserSettingModel::getInstance()->setSettings( $params );
        
        if (current_user_can( 'manage_options' )) {
            SettingModel::getInstance()->setSettings( $params );
        }

		return rest_ensure_response( true );
    }
}
