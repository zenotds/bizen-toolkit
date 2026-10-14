<?php
namespace Bizen\MediaManager;

defined( 'ABSPATH' ) || exit;

use Bizen\MediaManager\Classes\Schedule;

/**
 * Tables and cron. The module has no activation hook of its own, so the setup runs
 * whenever the stored version differs from the module's: on first boot, and after
 * an update. Tables are only created when missing, so the folders of a site that
 * ran FileBird are picked up as they are.
 */
class Plugin {
	const VERSION_OPTION = 'bizen_media_manager_version';

	public static function prepareRun() {
		if ( get_option( self::VERSION_OPTION ) !== BIZEN_MM_VERSION ) {
			Install::create_tables();
			Schedule::registerSchedule();
			update_option( self::VERSION_OPTION, BIZEN_MM_VERSION );
		}
	}
}
