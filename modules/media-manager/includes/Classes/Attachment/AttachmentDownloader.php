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
		global $wpdb;

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
				if ( function_exists( 'set_time_limit' ) ) {
					@set_time_limit( 0 );
				} else {
					if ( function_exists( 'ini_set' ) ) {
						@ini_set( 'max_execution_time', 0 );
					}
				}
				$folder_id = ( ( isset( $_GET['folder_id'] ) ) ? intval( $_GET['folder_id'] ) : 0 );
				if ( $folder_id > 0 ) {
					$folder = FolderModel::getChildrenOfFolder( $folder_id, 0 );

					$options = new \ZipStream\Option\Archive();
					$options->setSendHttpHeaders( true );
					$options->setEnableZip64( false );
					$options->setFlushOutput( true );
					$zipname = $folder->name . '.zip';
					$zipname = apply_filters( 'fbv_download_filename', $zipname, $folder );

					$zip = new \ZipStream\ZipStream( $zipname, $options );

					$attachment_ids = Helpers::getAttachmentIdsByFolderId( $folder_id );
					$files          = array();
					if ( count( $attachment_ids ) > 0 ) {
						$attachment_ids = array_filter( array_map( 'intval', $attachment_ids ) );
						if ( ! empty( $attachment_ids ) ) {
							$placeholders = implode( ',', array_fill( 0, count( $attachment_ids ), '%d' ) );
							$query = $wpdb->prepare( 
								"SELECT post_id, meta_value from {$wpdb->postmeta} where meta_key = '_wp_attached_file' AND post_id IN ($placeholders)", 
								$attachment_ids 
							);
							$files = $wpdb->get_results( $query );
						}
					}

					$uploads = wp_get_upload_dir();
					if ( false === $uploads['error'] ) {
						foreach ( $files as $v ) {
							$file    = $v->meta_value;
							$post_id = $v->post_id;
							if ( $file && 0 !== strpos( $file, '/' ) && ! preg_match( '|^.:\\\|', $file ) ) {
								$file = $uploads['basedir'] . "/$file";

								if ( file_exists( $file ) ) {
									// $zip->addFile( $file, \basename( $file ) );
									$zip->addFileFromPath( \basename( $file ), $file );
								} else {
									$file_url = wp_get_attachment_url( $post_id );

									$fp = tmpfile();
									$ch = curl_init();
									curl_setopt( $ch, CURLOPT_FILE, $fp );
									curl_setopt( $ch, CURLOPT_SSL_VERIFYHOST, 0 );
									curl_setopt( $ch, CURLOPT_SSL_VERIFYPEER, 0 );
									curl_setopt( $ch, CURLOPT_URL, $file_url );
									curl_exec( $ch );
									fflush( $fp );
									rewind( $fp );

									$zip->addFileFromStream( \basename( $file ), $fp );

									fclose( $fp );
									curl_close( $ch );
								}
							}
						}
					}

					self::addFolderToZip( $zip, $folder->children, '' );
					$zip->finish();
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
			$wp_dir        = wp_upload_dir();
			$upload_folder = $wp_dir['path'] . DIRECTORY_SEPARATOR;

			$folder_id = ( ( isset( $_GET['folder_id'] ) ) ? intval( $_GET['folder_id'] ) : 0 );
			if ( $folder_id > 0 ) {
				$folder = FolderModel::getChildrenOfFolder( $folder_id, 0 );

				$zip = new \ZipArchive();

				$zipname = $folder->name . '-' . uniqid() . '-' . time() . '.zip';
				$zipname = apply_filters( 'fbv_download_filename', $zipname, $folder );
				$zip->open( $upload_folder . $zipname, \ZipArchive::CREATE );
				$attachment_ids = Helpers::getAttachmentIdsByFolderId( $folder_id );
				foreach ( $attachment_ids as $k => $id ) {
					$file = get_attached_file( $id );
					if ( $file ) {
						$zip->addFile( $file, \basename( $file ) );
					}
				}

				self::addFolderToZipO( $zip, $folder->children, '' );

				if ( count( $attachment_ids ) == 0 ) {
					$zip->addEmptyDir( '.' );
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

    private static function addFolderToZip( &$zip, $children, $parent_dir = '' ) {
		global $wpdb;
		foreach ( $children as $k => $v ) {
			$folder_name = $v->name;
			$folder_id   = $v->id;

			// $folder_name = sanitize_title( $folder_name );

			$attachment_ids = Helpers::getAttachmentIdsByFolderId( $folder_id );
			$empty_dir      = $parent_dir != '' ? $parent_dir . '/' . $folder_name : $folder_name;
			// $zip->addEmptyDir( $empty_dir );
			$files = array();
			if ( count( $attachment_ids ) > 0 ) {
				$attachment_ids = array_filter( array_map( 'intval', $attachment_ids ) );
				if ( ! empty( $attachment_ids ) ) {
					$placeholders = implode( ',', array_fill( 0, count( $attachment_ids ), '%d' ) );
					$query = $wpdb->prepare( 
						"SELECT meta_value from {$wpdb->postmeta} where meta_key = '_wp_attached_file' AND post_id IN ($placeholders)", 
						$attachment_ids 
					);
					$files = $wpdb->get_col( $query );
				}
			}

			$uploads = wp_get_upload_dir();
			if ( false === $uploads['error'] ) {
				foreach ( $files as $file ) {
					if ( $file && 0 !== strpos( $file, '/' ) && ! preg_match( '|^.:\\\|', $file ) ) {
						$file = $uploads['basedir'] . "/$file";
						$zip->addFileFromPath( $empty_dir . '/' . \basename( $file ), $file );
						// $zip->addFile( $file, $empty_dir . '/' . \basename( $file ) );
					}
				}
			}
			if ( \is_array( $v->children ) ) {
				self::addFolderToZip( $zip, $v->children, $empty_dir );
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
				if ( $file ) {
					$zip->addFile( $file, $empty_dir . '/' . \basename( $file ) );
				}
			}
			if ( \is_array( $v->children ) ) {
				self::addFolderToZipO( $zip, $v->children, $empty_dir );
			}
		}
	}
}