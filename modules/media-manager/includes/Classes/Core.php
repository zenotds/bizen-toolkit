<?php
namespace Bizen\MediaManager\Classes;

use Bizen\MediaManager\Utils\Singleton;
use Bizen\MediaManager\Model\Folder as FolderModel;
use Bizen\MediaManager\Utils\Vite;
use Bizen\MediaManager\Classes\Attachment\AttachmentOrder;
use Bizen\MediaManager\Classes\Helpers as Helpers;
use Bizen\MediaManager\I18n as I18n;
use Bizen\MediaManager\Model\UserSettingModel;
use Bizen\MediaManager\Classes\FolderStateManager;
use Bizen\MediaManager\Model\SettingModel;

defined( 'ABSPATH' ) || exit;

class Core {
	private $userSettingModel = null;
	private $settingModel     = null;

    use Singleton;

    public function __construct() {
		$this->userSettingModel = UserSettingModel::getInstance();
		$this->settingModel     = SettingModel::getInstance();
		$this->loadModules();
	}

	private function doHooks() {
        add_filter( 'media_library_infinite_scrolling', '__return_true' );
		add_filter( 'ajax_query_attachments_args', array( $this, 'ajaxQueryAttachmentsArgs' ), 20 );
		add_filter( 'mla_media_modal_query_final_terms', array( $this, 'ajaxQueryAttachmentsArgs' ), 20 );

		add_filter( 'wp_prepare_attachment_for_js', array( $this, 'wpPrepareAttachmentForJs' ), 10, 3 );

		add_filter( 'restrict_manage_posts', array( $this, 'restrictManagePosts' ) );
		add_filter( 'posts_clauses', array( $this, 'postsClauses' ), 10, 2 );
		add_filter( 'attachment_fields_to_save', array( $this, 'attachment_fields_to_save' ), 10, 2 );

		add_action( 'admin_enqueue_scripts', array( $this, 'enqueueAdminScripts' ), PHP_INT_MAX );
		add_action( 'add_attachment', array( $this, 'addAttachment' ) );
		add_action( 'delete_attachment', array( $this, 'deleteAttachment' ) );
		add_action( 'pre-upload-ui', array( $this, 'actionPluploadUi' ) );
		add_action( 'attachment_fields_to_edit', array( $this, 'attachment_fields_to_edit' ), 10, 2 );
		add_filter( 'wp_edited_image_metadata', array( $this, 'edited_image_metadata' ), 10, 3 );
        add_filter( 'manage_media_columns', array( $this, 'manage_media_columns' ), 9 );
		add_action( 'manage_media_custom_column', array( $this, 'manage_media_custom_column' ), 10, 2 );
		add_filter( 'users_have_additional_content', array( $this, 'users_have_additional_content' ), 10, 2 );
		add_action( 'deleted_user', array( $this, 'deleted_user' ), 10, 3 );
		// Fix WordPress VIP doesn't load CSS file correctly
		add_filter( 'css_do_concat', array( $this, 'wordpress_vip_css_concat_filter' ), 10, 2 );
		// -----
	}

	public function manage_media_columns( $posts_columns ) {
		$posts_columns['fb_folder'] = __( 'Folder', 'bizen-toolkit' );
		return $posts_columns;
	}

	public function manage_media_custom_column( $column_name, $post_id ) {
		if ( 'fb_folder' === $column_name ) {
			$folder = FolderModel::getFolderFromPostId( $post_id );
			if ( ! empty( $folder ) ) {
				echo '<a href="javascript:;" data-id="' . esc_attr( $folder[0]->folder_id ) . '">' . esc_html( $folder[0]->name ) . '</a>';
			} else {
				echo '<a href="javascript:;" data-id="0">' . esc_html__( 'Uncategorized', 'bizen-toolkit' ) . '</a>';
			}
		}
	}

	public function wordpress_vip_css_concat_filter( $do_concat, $handle ) {
		if ( 'bizen-media-manager' === $handle ) {
			return false;
		}
		return $do_concat;
	}

    private function loadModules() {
		new Attachment\AttachmentSize();
		new Attachment\AttachmentDownloader();

		new Modules\ModuleExclude();
		new Modules\ModuleUser();
		new Modules\ModuleCompatibility();
	}

    public function enqueueAdminScripts( $screenId ) {
		$fbv_data = apply_filters(
			'fbv_data',
			array(
				'nonce'                  => wp_create_nonce( 'fbv_nonce' ),
				'rest_nonce'             => wp_create_nonce( 'wp_rest' ),
				'is_upload_screen'       => 'upload.php' === $screenId ? '1' : '0',
				'i18n'                   => I18n::getTranslation(),
				'media_mode'             => get_user_option( 'media_library_mode', get_current_user_id() ),
				'json_url'               => apply_filters( 'filebird_json_url', get_rest_url( null, BIZEN_MM_REST_URL ) ),
				'media_url'              => admin_url( 'upload.php' ),
				'asset_url'              => BIZEN_MM_PLUGIN_URL . 'assets/',
				// The bundle still mounts the "import from other plugins" banner; it stays empty.
				'data_import'            => array( 'plugins' => array() ),
				'can_manage_options'     => current_user_can( 'manage_options' ),
				'is_new_user'            => false,
				'update_database_notice' => false,
				'is_rtl'                 => is_rtl(),
				'tree_mode'              => 'attachment',
				'theme_colors'           => array( 'default' => '#8f8f8f' ),
				'license'                => array( 'status' => true ),
				'folder_search_api'      => apply_filters( 'fbv_folder_api_search', $this->settingModel->get( 'searching_api' ) ),
			)
		);

		$script_handle = Vite::enqueue_vite( 'main.tsx' );

		wp_enqueue_script( 'jquery-ui-draggable' );
		wp_enqueue_script( 'jquery-ui-droppable' );
		wp_enqueue_script( 'wp-dom-ready' );
		wp_enqueue_style( 'bizen-media-manager', BIZEN_MM_PLUGIN_URL . 'assets/dist/style.css', array(), BIZEN_MM_VERSION );

		// Touch Punch only maps touch events onto jQuery UI's mouse ones, so it is safe on
		// desktops too. Loading it on wp_is_mobile() alone left out tablets that report a
		// desktop user agent (iPadOS), where folders and files could not be dragged.
		wp_enqueue_script( 'jquery-touch-punch-fixed', BIZEN_MM_PLUGIN_URL . 'assets/js/jquery.ui.touch-punch.js', array( 'jquery-ui-widget', 'jquery-ui-mouse' ), BIZEN_MM_VERSION, false );

		wp_enqueue_script( 'bizen-media-manager-tools', BIZEN_MM_PLUGIN_URL . 'assets/js/tools.js', array(), BIZEN_MM_VERSION, false );

		wp_localize_script( 'bizen-media-manager-tools', 'fbv_data', $fbv_data );
		wp_localize_script( $script_handle, 'fbv_data', $fbv_data );
		wp_localize_script( 'wp-dom-ready', 'fbv_data', $fbv_data );
	}

    public function restrictManagePosts() {
		$screen = get_current_screen();
		if ( $screen->id == 'upload' ) {
			$fbv     = ( ( isset( $_GET['fbv'] ) ) ? (int) sanitize_text_field( $_GET['fbv'] ) : FolderModel::ALL_CATEGORIES );
			$folders = FolderModel::allFolders();

			$all       = new \stdClass();
			$all->id   = -1;
			$all->name = __( 'All Folders', 'bizen-toolkit' );

			$uncategorized       = new \stdClass();
			$uncategorized->id   = 0;
			$uncategorized->name = __( 'Uncategorized', 'bizen-toolkit' );

			array_unshift( $folders, $all, $uncategorized );
			echo '<select name="fbv" id="filter-by-fbv" class="fbv-filter attachment-filters fbv">';
			foreach ( $folders as $k => $folder ) {
				echo sprintf( '<option value="%1$d" %3$s>%2$s</option>', esc_html( $folder->id ), esc_html( $folder->name ), selected( intval( $folder->id ), $fbv, false ) );
			}
			echo '</select>';
		}
	}

    public function postsClauses( $clauses, $query ) {
		global $wpdb;

		if ( $query->get( 'post_type' ) !== 'attachment' ) {
			return $clauses;
		}

		$fb_folder = ( new FolderStateManager( $query, $this->userSettingModel ) )->getFbFolder();

		if ( ! \is_null( $fb_folder ) ) {
			$clauses = ( new AttachmentOrder( $query, $clauses ) )->apply_order();

			if ( FolderModel::ALL_CATEGORIES === $fb_folder ) {
				return $clauses;
			} elseif ( FolderModel::UN_CATEGORIZED === $fb_folder ) {
				$clauses = FolderModel::getRelationsWithFolderUser( $clauses );
			} else {
				$include_subfolders = apply_filters( 'fbv_query_include_subfolders', false, $fb_folder );

				if ($include_subfolders) {
					$descendant_ids = FolderModel::getAllDescendantIds($fb_folder);
					$all_folder_ids = array_merge(array($fb_folder), array_map('intval', $descendant_ids));
					$placeholders = implode(', ', array_fill(0, count($all_folder_ids), '%d'));

					$clauses['join'] .= $wpdb->prepare(
						" LEFT JOIN {$wpdb->prefix}fbv_attachment_folder AS fbva ON fbva.attachment_id = {$wpdb->posts}.ID AND fbva.folder_id IN ({$placeholders}) ",
						...$all_folder_ids
					);
				} else {
					$clauses['join'] .= $wpdb->prepare(" LEFT JOIN {$wpdb->prefix}fbv_attachment_folder AS fbva ON fbva.attachment_id = {$wpdb->posts}.ID AND fbva.folder_id = %d ", $fb_folder);
				}

				$clauses['where'] .= ' AND fbva.folder_id IS NOT NULL';
			}
		}

		return $clauses;
	}

    public function addAttachment( $post_id ) {
		$fbv = ( ( isset( $_REQUEST['fbv'] ) ) ? sanitize_text_field( $_REQUEST['fbv'] ) : '' );
		if ( $fbv != '' ) {
			if ( is_numeric( $fbv ) ) {
				$parent = $fbv;
			} else {
				$fbv    = explode( '/', ltrim( rtrim( $fbv, '/' ), '/' ) );
				$parent = (int) $fbv[0];
				if ( $parent < 0 ) {
					$parent = 0; //important
				}
				if ( apply_filters( 'fbv_auto_create_folders', true ) ) {
					unset( $fbv[0] );
					foreach ( $fbv as $k => $v ) {
						$folder = FolderModel::newOrGet( $v, $parent );
						$parent = $folder['id'];
					}
				}
			}

			$ids = array_map( 'intval', apply_filters( 'fbv_ids_assigned_to_folder', array( $post_id ) ) );
			$user_has_own_folder = SettingModel::getInstance()->get( 'user_mode' ) === '1';
			$current_user_id     = get_current_user_id();
			if( FolderModel::verifyAuthor( $parent, $current_user_id, $user_has_own_folder ) ){
				FolderModel::setFoldersForPosts( $ids, $parent );
			}
		}
	}

	public function deleteAttachment( $post_id ) {
		FolderModel::deleteFoldersOfPost( $post_id );
	}

	public function ajaxQueryAttachmentsArgs( $query ) {
		// phpcs::ignore WordPress.Security.NonceVerification.Recommended
		if ( isset( $_REQUEST['query']['fbv'] ) ) {
			$fbv          = Helpers::sanitize_intval_array( $_REQUEST['query']['fbv'] );
			$query['fbv'] = ( new FolderStateManager( $query, $this->userSettingModel ) )->getState( $fbv );
		}
		return $query;
	}

	public function wpPrepareAttachmentForJs( $response, $attachment, $meta ) {
		$folders = FolderModel::getFolderFromPostId( $attachment->ID );
		$response['fbv'] = count( $folders ) > 0 ? (int)$folders[0]->folder_id : 0;
		return $response;
	}

    public function attachment_fields_to_edit( $form_fields, $post ) {
		$fbv_folder  = FolderModel::getFolderFromPostId( $post->ID );
		$fbv_folder  = count( $fbv_folder ) > 0 ? $fbv_folder[0] : (object) array(
			'folder_id' => 0,
			'name'      => __( 'Uncategorized', 'bizen-toolkit' ),
		);
		$folder_name = esc_attr( $fbv_folder->name );
		$folder_id   = (int) $fbv_folder->folder_id;
		$post_id     = (int) $post->ID;
		$read_only   = current_user_can( 'edit_post', $post_id ) ? '' : 'readonly';

		$form_fields['fbv'] = array(
			'html'  => "<div class='fbv-attachment-edit-wrapper' data-folder-id='{$folder_id}' data-attachment-id='{$post_id}'><input {$read_only} type='text' value='{$folder_name}'/></div>",
			'label' => esc_html__( 'Folder:', 'bizen-toolkit' ),
			'helps' => esc_html__( 'Click on the folder name to move this file to another folder', 'bizen-toolkit' ),
			'input' => 'html',
		);

		return $form_fields;
	}

	public function edited_image_metadata( $new_image_meta, $new_attachment_id, $attachment_id ) {
		$folder = FolderModel::getFolderFromPostId( $attachment_id );
		if ( is_array( $folder ) && count( $folder ) > 0 ) {
			if ( (int) $folder[0]->folder_id > 0 ) {
				FolderModel::setFoldersForPosts( $new_attachment_id, (int) $folder[0]->folder_id );
			}
		}
		return $new_image_meta;
	}

	public function attachment_fields_to_save( $post, $attachment ) {
		if ( isset( $attachment['fbv'] ) ) {
			FolderModel::setFoldersForPosts( $post['ID'], $attachment['fbv'] );
		}
		return $post;
	}

	public function actionPluploadUi() {
		?>
			<div class="fbv-upload-inline">
				<label for="fbv"><?php esc_html_e( 'Choose folder: ', 'bizen-toolkit' ); ?></label>
				<span id="fbv-folder-selector" class="fbv-folder-selector" name="fbv"></span>
			</div>
		<?php
	}

	public function getFlatTree( $data = array(), $parent = 0, $default = null, $level = 0 ) {
		$tree = is_null( $default ) ? array() : $default;
		foreach ( $data as $k => $v ) {
			if ( $v->parent == $parent ) {
				$node     = array(
					'title' => str_repeat( '-', $level ) . $v->name,
					'value' => $v->id,
				);
				$tree[]   = $node;
				$children = $this->getFlatTree( $data, $v->id, null, $level + 1 );
				foreach ( $children as $k2 => $child ) {
					$tree[] = $child;
				}
			}
		}
		return $tree;
	}

	public function deleted_user( $id, $reassign, $user ) {
		if ( $reassign === null ) {
			FolderModel::deleteByAuthor( $id );
		} else {
			FolderModel::updateAuthor( $id, (int) $reassign );
		}
	}

	public function users_have_additional_content( $users_have_content, $userids ) {
		global $wpdb;
		if ( $userids && ! $users_have_content ) {
			$userids = array_map( 'intval', (array) $userids );
			$userids = array_filter( $userids, function( $id ) {
				return $id !== 0;
			} );
			if ( empty( $userids ) ) {
				return $users_have_content;
			}
			if ( $wpdb->get_var( "SELECT id FROM {$wpdb->prefix}fbv WHERE created_by IN( " . implode( ',', $userids ) . ' ) LIMIT 1' ) ) {
				$users_have_content = true;
			}
		}
		return $users_have_content;
	}
}