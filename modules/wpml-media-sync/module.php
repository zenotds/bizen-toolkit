<?php
/**
 * WPML Media in Every Language module.
 *
 * Bizen module — written and maintained by Bizen (https://bizen.it).
 *
 * WPML gives every language its own copy of each attachment, sharing the file.
 * In the "Automatically detect the best options" media mode (should_handle_media_auto,
 * on by default since WPML 4.7) it stops creating those copies on upload —
 * WPML_Media_Attachments_Duplication::translate_attachments() bails out while the
 * flag is set — and only makes them on the fly for media used in a translated post.
 * It also deletes them again afterwards when their texts match the original
 * (PostWithMediaFiles::delete_duplicated_copied_media()). A file uploaded in one
 * language is therefore missing from the media library of the others until someone
 * switches to "Configure manually" and runs the duplication by hand.
 *
 * This module pins the manual mode: the _wpml_media option is rewritten on read
 * and on write with the automatic mode off and the three "New content" boxes on,
 * so WPML's own upload hook copies every new attachment (file, alt, caption,
 * title, description) into all languages. A sweep then fills in what is already
 * missing — on first boot, when a language is activated, and once a day for
 * whatever slipped past the upload hook (WordPress importer, direct inserts). It
 * runs on WP-cron in time-boxed batches and hands each attachment to WPML's own
 * duplication, so parents, translation status and metadata are set exactly as
 * WPML would set them. Run it on demand with `wp bizen-wpml sync-media`.
 *
 * Copies are made, never removed or overwritten: texts translated in one language
 * stay as they are.
 *
 * With FileBird active, each copy also lands in the folder of the attachment it was
 * copied from, and the sweep ends with what FileBird's "Sync WPML" button does —
 * every translation left outside the folder its siblings are in is put there. The
 * button is an AJAX handler (nonce, wp_send_json), so its query is reproduced here
 * rather than called.
 */

defined( 'ABSPATH' ) || exit;

return new class extends Bizen_Module {

	private const OPTION      = '_wpml_media';
	private const CRON_HOOK   = 'bizen_wpml_media_sync';
	private const CURSOR      = 'bizen_wpml_media_sync_cursor';
	private const SWEPT       = 'bizen_wpml_media_sync_swept';
	private const BATCH       = 50;
	private const TIME_BUDGET = 20;

	public function get_id(): string {
		return 'wpml-media-sync';
	}

	public function get_name(): string {
		return __( 'WPML media in every language', 'bizen-toolkit' );
	}

	public function get_description(): string {
		return __( 'Every media file uploaded in one language shows up in the media library of all the others. Locks WPML media translation on "Configure manually" with duplication of new media on, and fills in existing media that is missing from a language, once a day and whenever a language is added. With FileBird, the copies go into the same folder as the original.', 'bizen-toolkit' );
	}

	public function get_category(): string {
		return 'media';
	}

	public function get_dependencies(): array {
		return [
			'sitepress-multilingual-cms/sitepress.php',
		];
	}

	public function boot(): void {
		add_filter( 'option_' . self::OPTION, [ $this, 'force_settings' ] );
		add_filter( 'default_option_' . self::OPTION, [ $this, 'force_settings' ] );
		add_filter( 'pre_update_option_' . self::OPTION, [ $this, 'force_settings' ] );

		add_action( self::CRON_HOOK, [ $this, 'run_sweep' ] );
		add_action( 'wpml_update_active_languages', [ $this, 'schedule_sweep' ] );
		add_action( 'init', [ $this, 'maybe_schedule' ] );

		// Whichever of WPML and FileBird handles add_attachment first, the copy ends up in the folder.
		add_action( 'wpml_after_duplicate_attachment', [ $this, 'copy_folder' ], 10, 2 );

		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			WP_CLI::add_command( 'bizen-wpml sync-media', [ $this, 'cli_sync_media' ] );
		}
	}

	/**
	 * The settings WPML reads as "Configure manually" with every "New content" box on.
	 * The upgrade banner and the switch back to automatic on String Translation
	 * activation are dropped with it, so neither can flip the mode behind our back.
	 */
	public function force_settings( $value ) {
		$value = is_array( $value ) ? $value : [];

		$value['should_handle_media_auto'] = false;
		$value['new_content_settings']     = [
			'always_translate_media' => true,
			'duplicate_media'        => true,
			'duplicate_featured'     => true,
		];

		unset(
			$value['should_show_handle_media_auto_banner_after_upgrade'],
			$value['should_show_handle_media_auto_notice_30_days_after_upgrade'],
			$value['should_enable_handle_media_auto_on_st_activation']
		);

		return $value;
	}

	public function maybe_schedule(): void {
		if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_schedule_event( time() + ( get_option( self::SWEPT ) ? DAY_IN_SECONDS : 0 ), 'daily', self::CRON_HOOK );
		}
	}

	/** A new language starts with an empty media library: sweep now rather than tomorrow. */
	public function schedule_sweep(): void {
		delete_option( self::CURSOR );
		wp_schedule_single_event( time(), self::CRON_HOOK );
	}

	/**
	 * One pass over the attachments, resumed from the stored cursor. A pass that runs
	 * out of time stores where it stopped and queues its own continuation.
	 */
	public function run_sweep(): void {
		$duplicator = $this->duplicator();
		if ( ! $duplicator ) {
			return;
		}

		$this->assign_missing_languages();

		$deadline = time() + self::TIME_BUDGET;
		$cursor   = (int) get_option( self::CURSOR, 0 );

		while ( time() < $deadline ) {
			$ids = $this->incomplete_attachments( $cursor, self::BATCH );
			if ( ! $ids ) {
				$this->sync_filebird_folders();
				delete_option( self::CURSOR );
				update_option( self::SWEPT, time(), false );
				return;
			}

			foreach ( $ids as $id ) {
				$this->duplicate( $duplicator, $id );
				$cursor = $id;
			}
		}

		update_option( self::CURSOR, $cursor, false );
		wp_schedule_single_event( time() + MINUTE_IN_SECONDS, self::CRON_HOOK );
	}

	/**
	 * Copies the attachments missing from at least one active language into those languages.
	 *
	 * ## OPTIONS
	 *
	 * [--dry-run]
	 * : List the attachments without copying them.
	 */
	public function cli_sync_media( array $args, array $assoc_args ): void {
		$duplicator = $this->duplicator();
		if ( ! $duplicator ) {
			WP_CLI::error( 'WPML media duplication is not available.' );
		}

		$dry_run = ! empty( $assoc_args['dry-run'] );

		if ( ! $dry_run ) {
			$assigned = $this->assign_missing_languages();
			if ( $assigned ) {
				WP_CLI::log( sprintf( '%d attachments had no language and were set to their parent\'s or the default one.', $assigned ) );
			}
		}

		$count  = 0;
		$cursor = 0;
		while ( $ids = $this->incomplete_attachments( $cursor, self::BATCH ) ) {
			foreach ( $ids as $id ) {
				if ( $dry_run ) {
					WP_CLI::log( sprintf( '%d  %s', $id, get_the_title( $id ) ) );
				} else {
					$this->duplicate( $duplicator, $id );
				}
				$cursor = $id;
				++$count;
			}
		}

		if ( ! $dry_run ) {
			$foldered = $this->sync_filebird_folders();
			if ( $foldered ) {
				WP_CLI::log( sprintf( '%d translations put into their FileBird folder.', $foldered ) );
			}
			delete_option( self::CURSOR );
			update_option( self::SWEPT, time(), false );
		}

		WP_CLI::success( sprintf( $dry_run ? '%d attachments would be copied.' : '%d attachments copied.', $count ) );
	}

	/** WPML's own duplication service, or null when this WPML version does not have it. */
	private function duplicator(): ?object {
		if ( ! function_exists( 'WPML\Container\make' ) || ! class_exists( 'WPML_Media_Attachments_Duplication' ) ) {
			return null;
		}

		try {
			$duplicator = \WPML\Container\make( 'WPML_Media_Attachments_Duplication' );
		} catch ( \Throwable $e ) {
			return null;
		}

		return method_exists( $duplicator, 'save_translated_attachments' ) ? $duplicator : null;
	}

	/** The override flag makes WPML copy into every missing language whatever its settings say. */
	private function duplicate( object $duplicator, int $attachment_id ): void {
		try {
			$duplicator->save_translated_attachments( $attachment_id, true );
		} catch ( \Throwable $e ) {
			// One broken attachment must not stop the sweep.
		}
	}

	/**
	 * Originals, from $after on, with fewer translations than there are active languages.
	 * A language that never fills (translation paused) keeps its attachments in this
	 * list, which is why sweeps walk by cursor instead of looping until it is empty.
	 *
	 * @return int[]
	 */
	private function incomplete_attachments( int $after, int $limit ): array {
		global $wpdb;

		$languages = $this->active_languages();
		if ( count( $languages ) < 2 ) {
			return [];
		}

		$placeholders = implode( ',', array_fill( 0, count( $languages ), '%s' ) );

		return array_map( 'intval', $wpdb->get_col(
			$wpdb->prepare(
				"SELECT t.element_id
				FROM {$wpdb->prefix}icl_translations t
				INNER JOIN {$wpdb->posts} p ON p.ID = t.element_id AND p.post_type = 'attachment'
				LEFT JOIN {$wpdb->prefix}icl_translations tt
					ON tt.trid = t.trid AND tt.element_type = 'post_attachment' AND tt.language_code IN ($placeholders)
				WHERE t.element_type = 'post_attachment'
					AND t.source_language_code IS NULL
					AND t.element_id > %d
				GROUP BY t.element_id
				HAVING COUNT( DISTINCT tt.language_code ) < %d
				ORDER BY t.element_id ASC
				LIMIT %d",
				array_merge( $languages, [ $after, count( $languages ), $limit ] )
			)
		) );
	}

	/**
	 * Attachments with no language at all — uploaded before WPML, or imported — are
	 * invisible to the sweep and to every language's media library. They get their
	 * parent's language, or the default one, as WPML does on upload.
	 */
	private function assign_missing_languages(): int {
		global $wpdb;

		$rows = $wpdb->get_results(
			"SELECT p.ID, p.post_parent
			FROM {$wpdb->posts} p
			LEFT JOIN {$wpdb->prefix}icl_translations t
				ON t.element_id = p.ID AND t.element_type = 'post_attachment'
			WHERE p.post_type = 'attachment' AND t.element_id IS NULL"
		);

		$default = apply_filters( 'wpml_default_language', null );

		foreach ( $rows as $row ) {
			$language = null;
			if ( $row->post_parent ) {
				$language = apply_filters( 'wpml_element_language_code', null, [
					'element_id'   => (int) $row->post_parent,
					'element_type' => get_post_type( (int) $row->post_parent ),
				] );
			}

			do_action( 'wpml_set_element_language_details', [
				'element_id'    => (int) $row->ID,
				'element_type'  => 'post_attachment',
				'trid'          => false,
				'language_code' => $language ?: $default,
			] );
		}

		return count( $rows );
	}

	public function copy_folder( $source_id, $copy_id ): void {
		global $wpdb;

		if ( ! $this->filebird_active() || ! $copy_id ) {
			return;
		}

		$table = $wpdb->prefix . 'fbv_attachment_folder';
		if ( $wpdb->get_var( $wpdb->prepare( "SELECT 1 FROM {$table} WHERE attachment_id = %d LIMIT 1", $copy_id ) ) ) {
			return;
		}

		$folder_id = (int) $wpdb->get_var( $wpdb->prepare( "SELECT folder_id FROM {$table} WHERE attachment_id = %d LIMIT 1", $source_id ) );
		if ( $folder_id ) {
			\FileBird\Model\Folder::setFoldersForPosts( (int) $copy_id, $folder_id );
		}
	}

	/**
	 * Translations in no folder whose trid has an attachment in one, each put in the
	 * original's folder (or a sibling's, when the original has none). One folder per
	 * attachment, as FileBird's own sync does: setFoldersForPosts() clears the
	 * attachment before each insert, so passing several would keep only the last.
	 */
	private function sync_filebird_folders(): int {
		global $wpdb;

		if ( ! $this->filebird_active() ) {
			return 0;
		}

		$rows = $wpdb->get_results(
			"SELECT t.element_id, (
				SELECT f2.folder_id
				FROM {$wpdb->prefix}icl_translations t2
				INNER JOIN {$wpdb->prefix}fbv_attachment_folder f2 ON f2.attachment_id = t2.element_id
				WHERE t2.trid = t.trid AND t2.element_type = 'post_attachment'
				ORDER BY t2.source_language_code IS NULL DESC, t2.element_id ASC
				LIMIT 1
			) AS folder_id
			FROM {$wpdb->prefix}icl_translations t
			LEFT JOIN {$wpdb->prefix}fbv_attachment_folder f ON f.attachment_id = t.element_id
			WHERE t.element_type = 'post_attachment' AND f.attachment_id IS NULL
			HAVING folder_id IS NOT NULL"
		);

		foreach ( $rows as $row ) {
			\FileBird\Model\Folder::setFoldersForPosts( (int) $row->element_id, (int) $row->folder_id );
		}

		return count( $rows );
	}

	private function filebird_active(): bool {
		return class_exists( '\FileBird\Model\Folder' ) && method_exists( '\FileBird\Model\Folder', 'setFoldersForPosts' );
	}

	/** @return string[] */
	private function active_languages(): array {
		$codes = array_map( 'strval', array_keys( (array) apply_filters( 'wpml_active_languages', null, [ 'skip_missing' => 0 ] ) ) );

		if ( class_exists( '\WPML\LanguageEditor\TranslationPause' ) && method_exists( '\WPML\LanguageEditor\TranslationPause', 'filterTranslatable' ) ) {
			$codes = array_values( \WPML\LanguageEditor\TranslationPause::filterTranslatable( $codes ) );
		}

		return $codes;
	}
};
