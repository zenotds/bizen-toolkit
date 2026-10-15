<?php
namespace Bizen\MediaManager\Classes;

defined( 'ABSPATH' ) || exit;

use Bizen\MediaManager\Model\Folder as FolderModel;
class Helpers {

    protected static $instance = null;

    public static function getInstance() {
		if ( null == self::$instance ) {
            self::$instance = new self();
		}
        return self::$instance;
    }

    public static function sanitize_array( $var ) {
        if ( is_array( $var ) ) {
            return array_map( 'self::sanitize_array', $var );
        } else {
            return is_scalar( $var ) ? sanitize_text_field( $var ) : $var;
        }
    }

    public static function sanitize_for_excel( $input ) {
        $input               = (string) $input;
        $dangerousCharacters = array( '=', '+', '-', '@', '|' );

        // Strip leading whitespace and formula sigils repeatedly, e.g. " \t =1+1".
        while ( '' !== $input ) {
            $trimmed = ltrim( $input, " \t\r\n\0\x0B" );

            if ( $trimmed !== $input ) {
                $input = $trimmed;
                continue;
            }

            if ( in_array( $input[0], $dangerousCharacters, true ) ) {
                $input = substr( $input, 1 );
                continue;
            }

            break;
        }

        return $input;
    }


    /**
     * Whether the current user may edit every supplied object.
     *
     * The whole batch is validated before any caller mutates anything, so a
     * request mixing authorized and unauthorized IDs is rejected atomically.
     *
     * @param array|int   $ids                Object IDs.
     * @param string|null $expected_post_type Required post type, or null to accept any.
     * @return bool
     */
    public static function canEditObjects( $ids, $expected_post_type = null ) {
        $ids = array_map( 'intval', (array) $ids );

        if ( count( $ids ) === 0 ) {
            return false;
        }

        foreach ( $ids as $id ) {
            if ( $id <= 0 ) {
                return false;
            }

            $post = get_post( $id );

            if ( ! $post ) {
                return false;
            }

            if ( null !== $expected_post_type && $post->post_type !== $expected_post_type ) {
                return false;
            }

            if ( ! current_user_can( 'edit_post', $id ) ) {
                return false;
            }
        }

        return true;
    }

    public static function sanitize_intval_array( $var ) {
        if ( is_array( $var ) ) {
            return array_map( 'intval', $var );
        } else {
            return intval( $var );
        }
    }

    public static function getAttachmentIdsByFolderId( $folder_id ) {
        global $wpdb;
        return $wpdb->get_col( 
            $wpdb->prepare( 
                'SELECT `attachment_id` FROM ' . $wpdb->prefix . 'fbv_attachment_folder WHERE `folder_id` = %d', 
                (int) $folder_id 
            ) 
        );
    }

    public static function getAttachmentCountByFolderId( $folder_id ) {
        return Tree::getCount( $folder_id );
    }

    public static function isListMode() {
		if ( function_exists( 'get_current_screen' ) ) {
            $screen = get_current_screen();
            return ( isset( $screen->id ) && 'upload' == $screen->id );
		}
        return false;
    }

    public static function findFolder( $folder_id, $tree ) {
        $folder = null;
        foreach ( $tree as $k => $v ) {
            if ( $v['id'] == $folder_id ) {
                $folder = $v;
                break;
            } else {
                $folder = self::findFolder( $folder_id, $v['children'] );
                if ( ! is_null( $folder ) ) {
                    break;
                } else {
                    continue;
                }
            }
        }
        return $folder;
    }

    public static function get_bytes( $post_id ) {
        $bytes = '';
        $meta  = wp_get_attachment_metadata( $post_id );
        if ( isset( $meta['filesize'] ) ) {
            $bytes = $meta['filesize'];
        } else {
            $attached_file = get_attached_file( $post_id );
            if ( file_exists( $attached_file ) ) {
                $bytes = \wp_filesize( $attached_file );
            }
        }
        return $bytes;
    }

    public static function array_to_in_clause( $array ) {
        // Escape each value and wrap it in single quotes
        $escaped_values = array_map(
            function( $value ) {
            // Escape the value to prevent SQL injection
            return "'" . esc_sql( $value ) . "'";
			},
            $array
            );

        // Join the escaped values into a comma-separated string
        $values_string = implode( ', ', $escaped_values );

        // Return the formatted string
        return $values_string;
    }
    /** Types with an admin screen that is not a post list, or whose list must stay untouched. */
    public static function getExcludedPostTypes() {
        return array(
            'attachment',
            'wp_navigation',
            'wp_block',
            'wp_template',
            'wp_template_part',
            'wp_global_styles',
            'wp_font_family',
            'wp_font_face',
            'shop_order',
            'shop_order_refund',
            'shop_coupon',
        );
    }

    /**
     * Post types that get a folder tree on their list screen: every type with an
     * admin UI. There is no per-type switch any more; narrow it with the
     * bizen_media_manager_post_types filter (slug => label).
     */
    public static function getRawPostTypes() {
        $postTypes  = array();
        $_postTypes = get_post_types(
            array(
                'show_ui' => true,
            ),
            'objects'
        );

        foreach ( $_postTypes as $key => $postType ) {
            if ( in_array( $key, self::getExcludedPostTypes(), true ) ) {
                continue;
            }
            $postTypes[ $key ] = $postType->labels->singular_name;
        }

        return apply_filters( 'bizen_media_manager_post_types', $postTypes );
    }

    public static function buildExclusionConditions() {
        global $wpdb;
        
        $conditions = array();

        $exclusions = array(
            'elementor_screenshots' => function_exists( '_is_elementor_installed' ),
            'picu_collections'      => function_exists( 'picu_exclude_collection_images_from_library' ),
            'uncode_gallery'        => function_exists( 'uncode_get_gallery_attachment_ids' ),
            'pdf_thumbnails'        => class_exists( 'PIGEN' ),
            'w3tc_cache'            => defined( 'W3TC_VERSION' ),
        );

        // Build exclusion conditions safely using subqueries
        if ( ! empty( $exclusions['elementor_screenshots'] ) ) {
            $conditions[] = "posts.ID NOT IN (SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = '_elementor_is_screenshot')";
        }
        
        if ( ! empty( $exclusions['picu_collections'] ) ) {
            $conditions[] = "posts.post_parent NOT IN (SELECT {$wpdb->posts}.ID FROM {$wpdb->posts} WHERE {$wpdb->posts}.post_type = 'picu_collection')";
        }
        
        if ( ! empty( $exclusions['uncode_gallery'] ) && function_exists( 'uncode_get_gallery_attachment_ids' ) ) {
            $attachment_ids = uncode_get_gallery_attachment_ids();
            $sanitized_ids  = array_filter( array_map( 'absint', (array) $attachment_ids ) );
            if ( ! empty( $sanitized_ids ) ) {
                $media_attachments_ids = implode( ',', $sanitized_ids );
                $conditions[] = "posts.ID NOT IN ($media_attachments_ids)";
            }

        }
        
        if ( ! empty( $exclusions['pdf_thumbnails'] ) ) {
            $opt = get_option( 'pigen_options' );
            if ( isset( $opt['hidethumb'] ) && $opt['hidethumb'] !== '' ) {
                $conditions[] = "posts.ID NOT IN (SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = '_thumbnail_id')";
            }
        }
        
        if ( ! empty( $exclusions['w3tc_cache'] ) ) {
            $conditions[] = "posts.ID NOT IN (SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = 'w3tc_imageservice_file')";
        }

        if( function_exists( 'fifu_get_author' ) ) {
            $conditions[] = "posts.post_author != " . intval( fifu_get_author() );
        }
        
        return $conditions;
    }
}
