<?php
namespace Bizen\MediaManager\Addons\PostType;

use Bizen\MediaManager\Addons\PostType\Routes as PostTypeRoutes;
use Bizen\MediaManager\Addons\PostType\Classes\MetaBox;
use Bizen\MediaManager\Addons\PostType\Models\Main as MainModel;
use Bizen\MediaManager\Model\Folder as FolderModel;
use Bizen\MediaManager\Addons\PostType\Models\PostTypeSettingModel;
use Bizen\MediaManager\I18n;
use Bizen\MediaManager\Classes\Helpers;
use Bizen\MediaManager\Utils\Singleton;

class Init {
    use Singleton;

    public $enabled_posttype = array();
    private $user_id         = null;
    private $config          = null;

    const PREFIX = 'fbv_pt_tax_';

    public function __construct() {
        $this->do_hooks();

        new PostTypeRoutes();
        new MetaBox();
    }

    public function isPostTypeAvailable( $post_type ) {
        return is_string( $post_type ) && in_array( $post_type, $this->enabled_posttype, true );
    }

    public function do_hooks() {
        // Late, so post types registered on init by themes and plugins are all there.
        add_action( 'init', array( $this, 'enable_post_types' ), 999 );
        add_action( 'restrict_manage_posts', array( $this, 'restrict_manage_posts' ), 10, 2 );
        add_action( 'pre_get_posts', array( $this, 'pre_get_posts' ) );

        add_filter( 'admin_init', array( $this, 'admin_init' ) );
        add_filter( 'admin_body_class', array( $this, 'admin_body_class' ) );
    }

    /** Every post type with an admin UI gets folders; there is no per-type setting any more. */
    public function enable_post_types() {
        $this->enabled_posttype = array_keys( Helpers::getRawPostTypes() );

        $this->register_taxonomies();

        foreach ( $this->enabled_posttype as $post_type ) {
            // Make post_modified sortable
            add_filter( 'manage_edit-' . $post_type . '_sortable_columns', array( $this, 'register_post_modified_sortable' ) );
            add_filter( 'manage_' . $post_type . '_posts_columns', array( $this, 'manager_accounts_columns' ), 10, 1 );
            add_action( 'manage_' . $post_type . '_posts_custom_column', array( $this, 'manager_accounts_show_columns' ), 10, 2 );
            add_action( 'save_post_' . $post_type, array( $this, 'auto_assign_folder' ), 10, 2 );
        }
    }

    public function auto_assign_folder( $post_id, $post ) {
        if ( wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) ) {
            return;
        }

        $post_type = $post->post_type;
        if ( ! $this->isPostTypeAvailable( $post_type ) ) {
            return;
        }

        if ( ! $this->isRefererFromFolderTree( $post_type ) ) {
            return;
        }

        $taxonomy = $this->getTaxonomyName( $post_type );

        // Already in a folder → don't overwrite (e.g. on update or after the user moved it).
        $existing = wp_get_object_terms( $post_id, $taxonomy, array( 'fields' => 'ids' ) );
        if ( is_wp_error( $existing ) || ! empty( $existing ) ) {
            return;
        }

        $config = $this->load_config( $post_type, get_current_user_id() );
        $folder = intval( $config->getSetting( 'FOLDER_STARTUP' ) );

        // Only assign when the user is browsing a real folder (skip All Folders / Uncategorized).
        if ( $folder <= 0 || ! MainModel::isFolderExist( $folder, $taxonomy ) ) {
            return;
        }

        wp_set_object_terms( $post_id, array( $folder ), $taxonomy, false );
    }

    private function isRefererFromFolderTree( $post_type ) {
        $referer = wp_get_referer();
        if ( empty( $referer ) ) {
            return false;
        }

        $path = wp_parse_url( $referer, PHP_URL_PATH );
        if ( empty( $path ) || 'edit.php' !== basename( $path ) ) {
            return false;
        }

        parse_str( (string) wp_parse_url( $referer, PHP_URL_QUERY ), $query );
        // edit.php with no post_type defaults to "post".
        $referer_post_type = isset( $query['post_type'] ) ? sanitize_key( $query['post_type'] ) : 'post';

        return $referer_post_type === $post_type;
    }

    // TODO: Get default folder from user meta maybe the folder is deleted or not exist
    public function load_config( $typenow, $user_id ) {
        $this->user_id = $user_id;
        $this->config  = new PostTypeSettingModel(
            array(
                'DEFAULT_FOLDER'       => array(
                    'meta'    => "fbv_folder_{$typenow}_default",
                    'default' => FolderModel::ALL_CATEGORIES,
                ),
                'FOLDER_STARTUP'       => array(
                    'meta'    => "fbv_folder_{$typenow}_startup",
                    'default' => FolderModel::ALL_CATEGORIES,
                ),
                'DEFAULT_SORT_FOLDERS' => array(
                    'meta'    => "fbv_folder_{$typenow}_sort",
                    'default' => null,
                ),
                'DEFAULT_SORT_FILES'   => array(
                    'meta'    => "fbv_files_{$typenow}_sort",
                    'default' => null,
                ),
			),
            $user_id,
            $typenow
        );

        return $this->config;
    }

    public function manager_accounts_columns( $columns ) {
        $columns['fb_folder'] = __( 'Folder', 'bizen-toolkit' );
		return $columns;
    }

    public function manager_accounts_show_columns( $column_name, $post_id ) {
        if ( 'fb_folder' === $column_name ) {
			$folder = MainModel::getFolderOfPost( get_post_type( $post_id ), $post_id );
			if ( ! empty( $folder ) ) {
				echo '<a href="javascript:;" data-id="' . esc_attr( $folder->term_id ) . '">' . esc_html( $folder->name ) . '</a>';
			} else {
				echo '<a href="javascript:;" data-id="0">' . esc_html__( 'Uncategorized', 'bizen-toolkit' ) . '</a>';
			}
		}
    }

    private function register_taxonomies() {
        if ( is_array( $this->enabled_posttype ) ) {
            foreach ( $this->enabled_posttype as $post_type ) {
                $labels = array(
                    'name'              => __( 'Folders', 'bizen-toolkit' ),
                    'singular_name'     => sprintf( __( '%s folder', 'bizen-toolkit' ), $post_type ),
                    'search_items'      => __( 'Search Folders', 'bizen-toolkit' ),
                    'all_items'         => __( 'All Folders', 'bizen-toolkit' ),
                    'parent_item'       => __( 'Parent Folder', 'bizen-toolkit' ),
                    'parent_item_colon' => __( 'Parent Folder:', 'bizen-toolkit' ),
                    'edit_item'         => __( 'Edit Folder', 'bizen-toolkit' ),
                    'update_item'       => __( 'Update Folder', 'bizen-toolkit' ),
                    'add_new_item'      => __( 'Add New Folder', 'bizen-toolkit' ),
                    'new_item_name'     => __( 'New Folder Name', 'bizen-toolkit' ),
                    'menu_name'         => __( 'Folder', 'bizen-toolkit' ),
                );

                $args = array(
                    'hierarchical'      => true,
                    'show_in_rest'      => false,
                    'show_ui'           => false,
                    'show_admin_column' => false,
                    'query_var'         => false,
                    'labels'            => $labels,
                    'show_tagcloud'     => false,
                    'public'            => false,
                    // 'update_count_callback' => '_update_post_term_count',
                );

                register_taxonomy( $this->getTaxonomyName( $post_type ), $post_type, $args );
            }
        }
    }

    public function restrict_manage_posts( $post_type, $which ) {
		if ( is_array( $this->enabled_posttype ) && in_array( $post_type, $this->enabled_posttype ) && $which == 'top' ) {
            $selected = '-1';
            $taxonomy = sanitize_key( $this->getTaxonomyName( $post_type ) );

            if ( isset( $_GET[ $taxonomy ] ) ) {
                $selected = (int) $_GET[ $taxonomy ];
            }

            $categories = get_terms(
                array(
					'taxonomy'   => $taxonomy,
					'hide_empty' => false,
                    'meta_key'   => MainModel::ORDER_META_KEY,
					'fields'     => 'id=>name',
                    'meta_query' => array(
                        array(
                            'key'     => MainModel::AUTHOR_KEY,
                            'value'   => apply_filters( 'fbv_folder_created_by', 0 ),
                            'compare' => '=',
                        ),
                    ),
				)
            );

            $categories[-1] = __( 'All Folders', 'bizen-toolkit' );
            $categories[0]  = __( 'Uncategorized', 'bizen-toolkit' );

            echo sprintf( '<select name="%1$s" id="%1$s" class="fbv-filter">', esc_html( $taxonomy ) );
			foreach ( $categories as $k => $category ) {
				echo sprintf( '<option value="%1$d" %3$s>%2$s</option>', esc_html( $k ), esc_html( $category ), selected( $k, $selected, false ) );
			}
			echo '</select>';
		}
    }

    public function getQueryFolder( $config, $taxonomy ) {
        $default_folder = intval( $config->getSetting( 'DEFAULT_FOLDER' ) );
        $startup_folder = intval( $config->getSetting( 'FOLDER_STARTUP' ) );
        $current_folder = null;

        if ( $default_folder == FolderModel::PREVIOUS_FOLDER ) {
            $current_folder = $startup_folder;
        } elseif ( in_array( $default_folder, array( FolderModel::ALL_CATEGORIES, FolderModel::UN_CATEGORIZED ) ) ) {
            return $default_folder;
        } else {
            $current_folder = $default_folder;
        }

        if ( MainModel::isFolderExist( $current_folder, $taxonomy ) ) {
            return $current_folder;
        }

        $config->setSetting( 'FOLDER_STARTUP', FolderModel::ALL_CATEGORIES );
        return FolderModel::ALL_CATEGORIES;
    }

	public function pre_get_posts( $query ) {
        global $wpdb;

        // Only the list table of the admin: the stored folder is a per-user view
        // setting, and must never filter (or be written by) front-end queries.
        if ( ! is_admin() || wp_doing_ajax() || 'edit.php' !== ( $GLOBALS['pagenow'] ?? '' ) || ! $query->is_main_query() ) {
            return;
        }

        $post_type = $query->get( 'post_type' );

        if ( ! $this->isPostTypeAvailable( $post_type ) ) {
			return;
		}

        $config         = $this->load_config( $post_type, get_current_user_id() );
        $taxonomy       = $this->getTaxonomyName( $post_type );
        $current_folder = isset( $_GET[ $taxonomy ] ) ? intval( $_GET[ $taxonomy ] ) : null;

        if ( \is_null( $current_folder ) ) {
            $current_folder = $this->getQueryFolder( $config, $taxonomy );
        }

        $config->setSetting( 'FOLDER_STARTUP', $current_folder );

        if ( $current_folder == FolderModel::ALL_CATEGORIES ) {
            return $query;
        }

        $old_tax_query = $query->get( 'tax_query' );
        $tax_query     = array();

		if ( $current_folder == FolderModel::UN_CATEGORIZED ) {
            $assigned_terms = $wpdb->get_col(
                $wpdb->prepare(
                "SELECT tt.term_id
                    FROM {$wpdb->prefix}term_relationships AS tr
                    INNER JOIN {$wpdb->prefix}term_taxonomy tt ON tt.term_taxonomy_id = tr.term_taxonomy_id AND tt.taxonomy = %s
                    INNER JOIN {$wpdb->prefix}termmeta tm ON tm.term_id = tt.term_id AND meta_key = 'fbv_author' AND meta_value = %d
                    GROUP BY tt.term_id
                ",
                $taxonomy,
                apply_filters( 'fbv_folder_created_by', '0' )
            )
                );

            $tax_query = array(
                array(
					'taxonomy' => $taxonomy,
        			'operator' => 'NOT IN',
                    'terms'    => array_map( 'absint', $assigned_terms ),
				),
                $old_tax_query,
			);
		} else {
			$tax_query = array(
				array(
					'taxonomy'         => $taxonomy,
        			'operator'         => 'IN',
                    'include_children' => false,
                    'terms'            => array( $current_folder ),
				),
                $old_tax_query,
			);
		}

        $query->set( 'tax_query', $tax_query );
    }

    public function register_post_modified_sortable( $columns ) {
        //orderby=post_modified&order=desc
       $columns['post_modified'] = 'post_modified';
       return $columns;
    }

    private function prepareSetting( $setting, $post_type ) {
        $default_folder = intval( $setting['DEFAULT_FOLDER'] );

        if ( $default_folder > 0 && MainModel::isFolderExist( $default_folder, $this->getTaxonomyName( $post_type ) ) === false ) {
            $setting['DEFAULT_FOLDER'] = FolderModel::ALL_CATEGORIES;
        }

        return $setting;
    }

    public function admin_init() {
        add_filter( 'fbv_data', array( $this, 'localize_script' ) );
    }

    public function localize_script( $data ) {
        global $typenow;
        $screen = get_current_screen();

        if ( in_array( $typenow, $this->enabled_posttype ) && 'edit' === $screen->base ) {
            $this->load_config( $typenow, get_current_user_id() );

            $data['tree_mode']     = 'post_type';
            $data['user_settings'] = $this->prepareSetting( array_merge( $data['user_settings'], $this->config->getAllSettings() ), $typenow );

            $translation = I18n::getTranslation();

            // The sidebar of a post list holds that type's folders, not media.
            $data['i18n']['media_manager'] = __( 'Folders', 'bizen-toolkit' );

            if ( $typenow === 'post' ) {
                $data['i18n']['all_files'] = $translation['all_posts'];
            } elseif ( $typenow === 'page' ) {
                $data['i18n']['all_files'] = $translation['all_pages'];
            } else {
                $data['i18n']['all_files'] = $translation['all_items'];
            }
            $data['folder_counter_type_' . $typenow] = get_user_meta( get_current_user_id(), 'fbv_folder_counter_type_' . $typenow, true );
        }

        return $data;
    }

    public function admin_body_class( $classes ) {
        global $typenow;

        $screen = get_current_screen();

        if ( $screen->id === "edit-$typenow" && in_array( $typenow, $this->enabled_posttype ) ) {
            $classes .= ' filebird-post-type ';
        }

        if ( $screen->id === 'upload' ) {
            $classes .= ' filebird-upload-php ';
        }

        return $classes;
    }

    private function getTaxonomyName( $post_type ) {
        return self::PREFIX . $post_type;
    }
}
