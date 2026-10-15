<?php
namespace Bizen\MediaManager\Classes\Attachment;

defined( 'ABSPATH' ) || exit;

use Bizen\MediaManager\Model\Folder as FolderModel;
use Bizen\MediaManager\Classes\Helpers;

class AttachmentDownloader {
    public function __construct() {
        if ( class_exists( '\ZipStream\ZipStream' ) && apply_filters( 'fbv_use_zipstream', true ) ) {
			add_action( 'wp_ajax_fbv_download_folder', array( $this, 'ajaxDownloadFolder' ) );
		} else {
			add_action( 'wp_ajax_fbv_download_folder', array( $this, 'ajaxDownloadFolderZip' ) );
		}
    }

	public function ajaxDownloadFolder() {
		check_ajax_referer( 'fbv_nonce', 'nonce', true );

		// Check if user has permission to access Media Library
		if ( ! current_user_can( 'upload_files' ) ) {
			wp_send_json_error(
				array(
					'errorCode'    => 403,
					'errorMessage' => __( 'You do not have permission to download folders.', 'bizen-toolkit' ),
				),
				403
			);
		}

		try {
			if ( isset( $_GET['do-download'] ) ) {
				$folder_id = ( ( isset( $_GET['folder_id'] ) ) ? intval( $_GET['folder_id'] ) : 0 );
				$folder    = self::getDownloadFolder( $folder_id );
				if ( $folder ) {
					self::prepareForStreaming();

					$options = new \ZipStream\Option\Archive();
					$options->setSendHttpHeaders( true );
					$options->setEnableZip64( (bool) apply_filters( 'fbv_zip_enable_zip64', false ) );
					$options->setFlushOutput( true );
					$options->setContentType( 'application/zip' );
					$options->setZeroHeader( true );
					$options->setLargeFileSize( 0 );
					$options->setLargeFileMethod( \ZipStream\Option\Method::STORE() );

					$zipname = $folder->name . '.zip';
					$zipname = apply_filters( 'fbv_download_filename', $zipname, $folder );

					$zip = new \ZipStream\ZipStream( $zipname, $options );

					$added = self::addAttachmentsToZip( $zip, $folder->attachment_ids, '' );
					self::addFolderToZip( $zip, $folder->children, '' );
					// An archive without entries is valid but Archive Utility on macOS refuses to open it.
					if ( 0 === $added && empty( $folder->children ) ) {
						$zip->addFile( self::getDirName( $folder->name ) . '/', '' );
					}
					$zip->finish();
					exit;
				}
			} else {
				$link = add_query_arg(
                     array(
						 'nonce'       => $_GET['nonce'],
						 'action'      => 'fbv_download_folder',
						 'folder_id'   => (int) $_GET['folder_id'],
						 'do-download' => true,
					 ),
                    admin_url( 'admin-ajax.php' )
                    );
				if ( is_ssl() && substr( $link, 0, 7 ) == 'http://' ) {
					$link = str_replace( 'http://', 'https://', $link );
				}
				wp_send_json_success(
					array(
						'link' => $link,
					)
				);
			}
		} catch ( \Exception $ex ) {
			self::handleDownloadError( $ex );
		} catch ( \Error $ex ) {
			self::handleDownloadError( $ex );
		}
	}

	private static function prepareForStreaming() {
		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( 0 );
		} elseif ( function_exists( 'ini_set' ) ) {
			@ini_set( 'max_execution_time', 0 );
		}
		if ( function_exists( 'ini_set' ) ) {
			@ini_set( 'display_errors', '0' );
			@ini_set( 'zlib.output_compression', 'Off' );
			@ini_set( 'output_buffering', 'Off' );
			@ini_set( 'implicit_flush', '1' );
		}
		if ( function_exists( 'wp_raise_memory_limit' ) ) {
			wp_raise_memory_limit( 'admin' );
		}
		if ( function_exists( 'apache_setenv' ) ) {
			@apache_setenv( 'no-gzip', '1' );
		}
		@ignore_user_abort( true );

		for ( $i = ob_get_level(); $i > 0; $i-- ) {
			@ob_end_clean();
		}

		if ( ! headers_sent() ) {
			@header_remove( 'Content-Encoding' );
			header( 'X-Accel-Buffering: no' );
		}
	}

	private static function handleDownloadError( $ex ) {
		if ( headers_sent() ) {
			exit;
		}
		wp_send_json_error(
			array(
				'errorCode'    => $ex->getCode(),
				'errorMessage' => $ex->getMessage(),
			)
		);
	}

	public function ajaxDownloadFolderZip() {
		check_ajax_referer( 'fbv_nonce', 'nonce', true );

		// Check if user has permission to access Media Library
		if ( ! current_user_can( 'upload_files' ) ) {
			wp_send_json_error(
				array(
					'errorCode'    => 403,
					'errorMessage' => __( 'You do not have permission to download folders.', 'bizen-toolkit' ),
				),
				403
			);
		}
		
		try {
			if ( function_exists( 'set_time_limit' ) ) {
				@set_time_limit( 0 );
			} elseif ( function_exists( 'ini_set' ) ) {
				@ini_set( 'max_execution_time', 0 );
			}
			if ( function_exists( 'wp_raise_memory_limit' ) ) {
				wp_raise_memory_limit( 'admin' );
			}

			$wp_dir        = wp_upload_dir();
			$upload_folder = $wp_dir['path'] . DIRECTORY_SEPARATOR;

			$folder_id = ( ( isset( $_GET['folder_id'] ) ) ? intval( $_GET['folder_id'] ) : 0 );
			$folder    = self::getDownloadFolder( $folder_id );
			if ( $folder ) {
				$zip = new \ZipArchive();

				$zipname = $folder->name . '-' . uniqid() . '-' . time() . '.zip';
				$zipname = apply_filters( 'fbv_download_filename', $zipname, $folder );
				$zip->open( $upload_folder . $zipname, \ZipArchive::CREATE );
				$attachment_ids = $folder->attachment_ids;
				foreach ( $attachment_ids as $k => $id ) {
					$file = get_attached_file( $id );
					if ( $file && is_readable( $file ) ) {
						$zip->addFile( $file, \basename( $file ) );
					}
				}

				self::addFolderToZipO( $zip, $folder->children, '' );

				if ( 0 === $zip->numFiles ) {
					$zip->addEmptyDir( self::getDirName( $folder->name ) );
				}
				$zip->close();

				//save path to database
				$saved_downloads = get_option( 'filebird_saved_downloads', array() );
				if ( ! is_array( $saved_downloads ) ) {
					$saved_downloads = array();
				}
				$saved_downloads[ time() ] = $wp_dir['subdir'] . '/' . $zipname;
				update_option( 'filebird_saved_downloads', $saved_downloads );
				// end saving
				$link = trailingslashit( $wp_dir['url'] ) . $zipname;
				if ( is_ssl() && substr( $link, 0, 7 ) == 'http://' ) {
					$link = str_replace( 'http://', 'https://', $link );
				}
				wp_send_json_success(
					array(
						'link' => $link,
					)
				);
			}
		} catch ( \Exception $ex ) {
			wp_send_json_error(
				array(
					'errorCode'    => $ex->getCode(),
					'errorMessage' => $ex->getMessage(),
				)
			);
		} catch ( \Error $ex ) {
			wp_send_json_error(
				array(
					'errorCode'    => $ex->getCode(),
					'errorMessage' => $ex->getMessage(),
				)
			);
		}
	}

	/**
	 * Resolve what a download of $folder_id contains: its name, the attachments at the root of
	 * the archive and the subfolders below it. Besides real folders this covers the two virtual
	 * ones: Uncategorized (0) is only the files outside the user's folders, All Files (-1) is the
	 * user's whole folder tree with the uncategorized files at the root.
	 */
	private static function getDownloadFolder( $folder_id ) {
		if ( $folder_id > 0 ) {
			$folder = FolderModel::getChildrenOfFolder( $folder_id, 0 );
			if ( ! $folder ) {
				return null;
			}
			$folder->attachment_ids = Helpers::getAttachmentIdsByFolderId( $folder_id );
			return $folder;
		}

		if ( 0 !== $folder_id && -1 !== $folder_id ) {
			return null;
		}

		$folder                 = new \stdClass();
		$folder->id             = $folder_id;
		$folder->name           = 0 === $folder_id ? __( 'Uncategorized', 'bizen-toolkit' ) : __( 'All Files', 'bizen-toolkit' );
		$folder->attachment_ids = self::getUncategorizedAttachmentIds();
		$folder->children       = 0 === $folder_id ? array() : self::getRootFolders();

		return $folder;
	}

	/**
	 * Top level folders of the current user, each with its subfolders.
	 */
	private static function getRootFolders() {
		global $wpdb;

		$folders = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT name, id FROM {$wpdb->prefix}fbv WHERE parent = 0 AND created_by = %d",
				apply_filters( 'fbv_folder_created_by', 0 )
			)
		);
		foreach ( $folders as $k => $v ) {
			$folders[ $k ]->children = FolderModel::getChildrenOfFolder( $v->id, 1 );
		}

		return $folders;
	}

	/**
	 * Attachments that are not in any folder of the current user, same as the Uncategorized view.
	 */
	private static function getUncategorizedAttachmentIds() {
		global $wpdb;

		$where   = array(
			"posts.post_type = 'attachment'",
			"(posts.post_status = 'inherit' OR posts.post_status = 'private')",
			$wpdb->prepare(
				"posts.ID NOT IN (SELECT fbva.attachment_id FROM {$wpdb->prefix}fbv_attachment_folder AS fbva INNER JOIN {$wpdb->prefix}fbv AS fbv ON fbva.folder_id = fbv.id WHERE fbv.created_by = %d)",
				apply_filters( 'fbv_folder_created_by', 0 )
			),
		);
		$where   = array_merge( $where, Helpers::buildExclusionConditions() );

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return $wpdb->get_col( "SELECT posts.ID FROM {$wpdb->posts} AS posts WHERE " . implode( ' AND ', $where ) );
	}

	/**
	 * Folder names may contain slashes, which would nest the entry in the archive.
	 */
	private static function getDirName( $name ) {
		return str_replace( array( '/', '\\' ), '-', $name );
	}

	/**
	 * Add the given attachments to the archive, under $dir. Returns how many were added.
	 */
	private static function addAttachmentsToZip( &$zip, $attachment_ids, $dir = '' ) {
		global $wpdb;

		$attachment_ids = array_filter( array_map( 'intval', (array) $attachment_ids ) );
		if ( empty( $attachment_ids ) ) {
			return 0;
		}

		$uploads = wp_get_upload_dir();
		if ( false !== $uploads['error'] ) {
			return 0;
		}

		$placeholders = implode( ',', array_fill( 0, count( $attachment_ids ), '%d' ) );
		$query        = $wpdb->prepare(
			"SELECT post_id, meta_value from {$wpdb->postmeta} where meta_key = '_wp_attached_file' AND post_id IN ($placeholders)",
			$attachment_ids
		);
		$files        = $wpdb->get_results( $query );

		$file_options = new \ZipStream\Option\File();
		$file_options->setMethod( \ZipStream\Option\Method::STORE() );

		$added = 0;
		foreach ( $files as $v ) {
			$file = $v->meta_value;
			if ( ! $file || 0 === strpos( $file, '/' ) || preg_match( '|^.:\\\|', $file ) ) {
				continue;
			}

			$path = $uploads['basedir'] . "/$file";
			$name = '' !== $dir ? $dir . '/' . \basename( $file ) : \basename( $file );

			try {
				if ( is_readable( $path ) ) {
					$zip->addFileFromPath( $name, $path, $file_options );
					++$added;
				} elseif ( self::addRemoteFileToZip( $zip, $name, (int) $v->post_id, $file_options ) ) {
					++$added;
				}
			} catch ( \Exception $ex ) {
				continue;
			} catch ( \Error $ex ) {
				continue;
			}
		}

		return $added;
	}

	/**
	 * Fallback for attachments not on disk, eg. offloaded to a CDN. Returns whether it was added.
	 */
	private static function addRemoteFileToZip( &$zip, $name, $post_id, $file_options ) {
		if ( ! function_exists( 'curl_init' ) ) {
			return false;
		}

		$file_url = wp_get_attachment_url( $post_id );
		if ( ! $file_url ) {
			return false;
		}

		$fp = tmpfile();
		if ( false === $fp ) {
			return false;
		}

		$ch = curl_init();
		curl_setopt( $ch, CURLOPT_FILE, $fp );
		curl_setopt( $ch, CURLOPT_SSL_VERIFYHOST, 0 );
		curl_setopt( $ch, CURLOPT_SSL_VERIFYPEER, 0 );
		curl_setopt( $ch, CURLOPT_FOLLOWLOCATION, true );
		curl_setopt( $ch, CURLOPT_FAILONERROR, true );
		curl_setopt( $ch, CURLOPT_CONNECTTIMEOUT, (int) apply_filters( 'fbv_download_remote_connect_timeout', 10 ) );
		curl_setopt( $ch, CURLOPT_TIMEOUT, (int) apply_filters( 'fbv_download_remote_timeout', 120 ) );
		curl_setopt( $ch, CURLOPT_URL, $file_url );
		$success = curl_exec( $ch );
		curl_close( $ch );

		if ( false !== $success ) {
			fflush( $fp );
			rewind( $fp );
			$zip->addFileFromStream( $name, $fp, $file_options );
		}

		fclose( $fp );

		return false !== $success;
	}

    private static function addFolderToZip( &$zip, $children, $parent_dir = '' ) {
		foreach ( $children as $k => $v ) {
			$dir = '' !== $parent_dir ? $parent_dir . '/' . self::getDirName( $v->name ) : self::getDirName( $v->name );

			// Directory entry, so folders without files are kept too.
			$zip->addFile( $dir . '/', '' );
			self::addAttachmentsToZip( $zip, Helpers::getAttachmentIdsByFolderId( $v->id ), $dir );

			if ( \is_array( $v->children ) ) {
				self::addFolderToZip( $zip, $v->children, $dir );
			}
		}
	}

	private static function addFolderToZipO( &$zip, $children, $parent_dir = '' ) {
		foreach ( $children as $k => $v ) {
			$folder_name = $v->name;
			$folder_id   = $v->id;

			// $folder_name = sanitize_title( $folder_name );

			$attachment_ids = Helpers::getAttachmentIdsByFolderId( $folder_id );
			$empty_dir      = $parent_dir != '' ? $parent_dir . '/' . $folder_name : $folder_name;
			$zip->addEmptyDir( $empty_dir );

			foreach ( $attachment_ids as $k => $id ) {
				$file = get_attached_file( $id );
				if ( $file && is_readable( $file ) ) {
					$zip->addFile( $file, $empty_dir . '/' . \basename( $file ) );
				}
			}
			if ( \is_array( $v->children ) ) {
				self::addFolderToZipO( $zip, $v->children, $empty_dir );
			}
		}
	}
}