<?php
/**
 * Plugin Name: Bizen Toolkit
 * Plugin URI:  https://bizen.it
 * Description: Bizen Toolkit - WordPress enhancements
 * Version:     1.0.4
 * Author:      Bizen
 * Author URI:  https://bizen.it
 * License:     GPL-2.0+
 * License URI: http://www.gnu.org/licenses/gpl-2.0.txt
 * Text Domain: bizen-toolkit
 * Requires at least: 5.8
 * Requires PHP: 8.0
 */

defined( 'ABSPATH' ) || exit;

define( 'BIZEN_TOOLKIT_VERSION',      '1.0.0' );
define( 'BIZEN_TOOLKIT_FILE',         __FILE__ );
define( 'BIZEN_TOOLKIT_PATH',         plugin_dir_path( __FILE__ ) );
define( 'BIZEN_TOOLKIT_URL',          plugin_dir_url( __FILE__ ) );
define( 'BIZEN_TOOLKIT_MODULES_PATH', BIZEN_TOOLKIT_PATH . 'modules/' );

require_once BIZEN_TOOLKIT_PATH . 'includes/class-module-base.php';
require_once BIZEN_TOOLKIT_PATH . 'includes/class-conflict-checker.php';
require_once BIZEN_TOOLKIT_PATH . 'includes/class-module-loader.php';
require_once BIZEN_TOOLKIT_PATH . 'includes/class-version-monitor.php';
require_once BIZEN_TOOLKIT_PATH . 'includes/class-admin-panel.php';

// Wire cron handler early (before plugins_loaded so WP-cron triggers it correctly)
add_action( 'bizen_toolkit_version_check', function () {
	$loader = new Bizen_Module_Loader();
	$loader->discover();
	Bizen_Version_Monitor::check_updates( $loader->get_modules() );
} );

add_action( 'init', function () {
	load_plugin_textdomain( 'bizen-toolkit', false, dirname( plugin_basename( BIZEN_TOOLKIT_FILE ) ) . '/languages' );
} );

add_action( 'plugins_loaded', function () {
	$loader = new Bizen_Module_Loader();
	$loader->load();

	if ( is_admin() ) {
		new Bizen_Admin_Panel( $loader );
	}
}, 999 );

register_activation_hook( __FILE__, function () {
	Bizen_Version_Monitor::schedule_cron();
} );

register_deactivation_hook( __FILE__, function () {
	Bizen_Version_Monitor::clear_cron();
} );
