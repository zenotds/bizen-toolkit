<?php
namespace Bizen\MediaManager\Model;

use Bizen\MediaManager\Utils\Singleton;

defined( 'ABSPATH' ) || exit;

class SettingModel {
    use Singleton;

    const SETTING_KEY = 'fbv_settings';

    /**
     * Settings that used to have a switch on the settings page, which is gone: the
     * breadcrumb is always shown, the default folder theme is the only one, and SVG
     * upload and sanitisation belong to the toolkit. Whatever an earlier install
     * stored for them is overridden.
     */
    const FORCED_SETTINGS = array(
        'show_breadcrumb' => true,
        'theme'           => 'default',
        'svg_support'     => false,
    );

    private $settings         = array();
    private $default_settings = array();

    public function __construct() {
        $this->loadDefaultSettings();
        $this->checkAndMigrateIfNeeded();
        $this->loadSettings();
        add_filter( 'fbv_data', array( $this, 'addUserSettingsData' ), 10, 1 );
    }

    private function loadDefaultSettings() {
        $this->default_settings = array(
            'user_mode'           => false,
            'svg_support'         => false,
            'searching_api'       => false,
			'show_breadcrumb'     => true,
			'folder_counter_type' => 'counter_file_in_folder',
			'theme'               => 'default',
            'enable_cache_optimization' => false
        );
    }

    private function checkAndMigrateIfNeeded() {
        if ( get_option( self::SETTING_KEY, false ) === false ) {
            $this->migrateOldOptions();
        }
    }

    private function migrateOldOptions() {
        $old_options = array(
            'searching_api' => 'njt_fbv_is_search_using_api',
            'svg_support'   => 'njt_fbv_allow_svg_upload',
            'user_mode'     => 'njt_fbv_folder_per_user',
        );

        foreach ( $old_options as $new_option => $old_option ) {
            $this->settings[ $new_option ] = get_option( $old_option, $this->default_settings[ $new_option ] );
            delete_option( $old_option );
        }

        update_option( self::SETTING_KEY, $this->settings );
    }

    private function loadSettings() {
        $saved_settings = get_option( self::SETTING_KEY, array() );
        foreach($saved_settings as $k => $v) {
            $saved_settings[$k] = apply_filters( 'fbv_settings_' . $k, $v );
        }
        $this->settings = array_merge( $this->default_settings, $saved_settings, self::FORCED_SETTINGS );
    }

    public function addUserSettingsData( $data ) {
        $data['settings'] = $this->settings;
        return $data;
    }

    public function get( $key ) {
        $val = $this->settings[ $key ] ?? null;
        return $val;
        // return apply_filters( 'fbv_settings_' . $key, $val );
    }

    public function set( $key, $value ) {
        if ( array_key_exists( $key, $this->default_settings ) && ! array_key_exists( $key, self::FORCED_SETTINGS ) ) {
            $this->settings[ $key ] = $value;
            update_option( self::SETTING_KEY, $this->settings );
            return true;
        }
        return false;
    }

	public function setSettings( $settings ) {
		$settings       = array_diff_key( array_intersect_key( $settings, $this->default_settings ), self::FORCED_SETTINGS );
		$this->settings = array_merge( $this->settings, $settings );
		update_option( self::SETTING_KEY, $this->settings );
	}

    public function getAllSettings() {
        return $this->settings;
    }
}