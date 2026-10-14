<?php
namespace Bizen\MediaManager\Classes;

defined( 'ABSPATH' ) || exit;

/** Deletes the zip files built by "Download folder" a day after they were made. */
class Schedule {
	const ZIP_CLEANUP_HOOK = 'filebird_remove_zip_files';

	public function __construct() {
		add_action( self::ZIP_CLEANUP_HOOK, array( $this, 'actionRemoveZipFiles' ) );
	}

	public static function registerSchedule() {
		if ( ! wp_next_scheduled( self::ZIP_CLEANUP_HOOK ) ) {
			wp_schedule_event( time(), 'daily', self::ZIP_CLEANUP_HOOK );
		}
		// FileBird's twice-daily backup of the folder tree into wp_options; backups are gone.
		wp_clear_scheduled_hook( 'filebird_every_12_hours_jobs' );
	}

	public static function clearSchedule() {
		wp_clear_scheduled_hook( self::ZIP_CLEANUP_HOOK );
	}

	public function actionRemoveZipFiles() {
		$saved_downloads = get_option( 'filebird_saved_downloads', array() );
		if ( ! is_array( $saved_downloads ) ) {
			$saved_downloads = array();
		}
		foreach ( $saved_downloads as $time => $path ) {
			if ( ( time() - $time ) >= ( 24 * 60 * 60 ) ) {
				$wp_dir = wp_upload_dir();
				if ( file_exists( $wp_dir['basedir'] . $path ) ) {
					unlink( $wp_dir['basedir'] . $path );
				}
				unset( $saved_downloads[ $time ] );
			}
		}
		update_option( 'filebird_saved_downloads', $saved_downloads );
	}
}
