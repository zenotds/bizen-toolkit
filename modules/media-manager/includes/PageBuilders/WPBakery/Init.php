<?php
namespace Bizen\MediaManager\PageBuilders\WPBakery;

use Bizen\MediaManager\Classes\Tree;
use Bizen\MediaManager\Model\Folder as FolderModel;
use Bizen\MediaManager\Classes\Helpers;

defined('ABSPATH') || exit;

class Init {
    private static $instance = null;

    public static function getInstance() {
        if (null == self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public function __construct() {
        if (function_exists('\vc_map')) {
            add_action('vc_before_init', array($this, 'register_vc_element'));
            add_shortcode('filebird_gallery', array($this, 'render_filebird_gallery'));
        }
    }

    public function register_vc_element() {
        $folders = Tree::getFolders( 'ord', 'ASC' );
        $folder_options = array(
            __('Select Folder', 'bizen-toolkit') => 0
        );

        // Build options including all children
        $this->build_folder_options($folders, $folder_options);
        
        \vc_map(array(
            'name' => __('Folder Gallery', 'bizen-toolkit'),
            'base' => 'filebird_gallery',
            'category' => __('Content', 'bizen-toolkit'),
            'icon' => 'icon-wpb-images-stack',
            'params' => array(
                array(
                    'type' => 'dropdown',
                    'heading' => __('Select Folder', 'bizen-toolkit'),
                    'param_name' => 'folder_id',
                    'value' => $folder_options,
                    'description' => __('Choose a folder to display images from', 'bizen-toolkit'),
                    'admin_label' => true,
                ),
                array(
                    'type' => 'dropdown',
                    'heading' => __('Columns', 'bizen-toolkit'),
                    'param_name' => 'columns',
                    'value' => array(
                        '1' => 1,
                        '2' => 2,
                        '3' => 3,
                        '4' => 4,
                        '5' => 5,
                        '6' => 6,
                    ),
                    'std' => 3,
                    'description' => __('Number of columns to display', 'bizen-toolkit'),
                ),
                array(
                    'type' => 'dropdown',
                    'heading' => __('Link To', 'bizen-toolkit'),
                    'param_name' => 'link_to',
                    'value' => array(
                        __('None', 'bizen-toolkit') => 'none',
                        __('Media File', 'bizen-toolkit') => 'file',
                        __('Attachment Page', 'bizen-toolkit') => 'post',
                    ),
                    'std' => 'file',
                    'description' => __('Link behavior when clicking on image', 'bizen-toolkit'),
                ),
                array(
                    'type' => 'dropdown',
                    'heading' => __('Image Size', 'bizen-toolkit'),
                    'param_name' => 'size',
                    'value' => array_merge(array('Full' => 'full'), $this->get_image_sizes()),
                    'std' => 'medium',
                    'description' => __('Select image size', 'bizen-toolkit'),
                ),
                array(
                    'type' => 'dropdown',
                    'heading' => __('Order By', 'bizen-toolkit'),
                    'param_name' => 'orderby',
                    'value' => array(
                        __('Date', 'bizen-toolkit') => 'date',
                        __('Title', 'bizen-toolkit') => 'title',
                        __('Random', 'bizen-toolkit') => 'rand',
                    ),
                    'std' => 'date',
                    'description' => __('Order images by', 'bizen-toolkit'),
                ),
                array(
                    'type' => 'dropdown',
                    'heading' => __('Order', 'bizen-toolkit'),
                    'param_name' => 'order',
                    'value' => array(
                        __('Descending', 'bizen-toolkit') => 'DESC',
                        __('Ascending', 'bizen-toolkit') => 'ASC',
                    ),
                    'std' => 'DESC',
                    'description' => __('Sort order', 'bizen-toolkit'),
                ),
            )
        ));
    }

    /**
     * Recursively builds folder options including all children
     * 
     * @param array $folders Array of folders
     * @param array &$options Reference to options array to be filled
     * @param string $prefix Optional prefix for child folders
     */
    private function build_folder_options($folders, &$options, $prefix = '') {
        if (!is_array($folders)) {
            return;
        }
        
        foreach ($folders as $folder) {
            $options[$prefix . '#' . $folder['id'] . ' ' . $folder['text']] = $folder['id'];
            
            // Process children recursively if they exist
            if (!empty($folder['children']) && is_array($folder['children'])) {
                $this->build_folder_options($folder['children'], $options, $prefix . '— ');
            }
        }
    }

    public function render_filebird_gallery($atts) {
        $atts = shortcode_atts(array(
            'folder_id' => 0,
            'columns' => 3,
            'link_to' => 'file',
            'size' => 'medium',
            'orderby' => 'date',
            'order' => 'DESC',
        ), $atts, 'filebird_gallery');

        $folder_id = intval($atts['folder_id']);
        
        if ($folder_id <= 0) {
            return '<div class="filebird-gallery-error">' . __('Please select a folder', 'bizen-toolkit') . '</div>';
        }

        $attachment_ids = Helpers::getAttachmentIdsByFolderId($folder_id);
        
        if (empty($attachment_ids)) {
            return '<div class="filebird-gallery-empty">' . __('No images found in this folder', 'bizen-toolkit') . '</div>';
        }

        $args = array(
            'post_type' => 'attachment',
            'post_status' => 'inherit',
            'post__in' => $attachment_ids,
            'posts_per_page' => -1,
            'orderby' => $atts['orderby'],
            'order' => $atts['order'],
        );

        $query = new \WP_Query($args);
        $output = '';

        if ($query->have_posts()) {
            $output .= '<div class="filebird-gallery filebird-gallery-columns-' . esc_attr($atts['columns']) . '">';
            
            while ($query->have_posts()) {
                $query->the_post();
                $attachment_id = get_the_ID();
                $image_src = wp_get_attachment_image_src($attachment_id, $atts['size']);
                $image_full = wp_get_attachment_image_src($attachment_id, 'full');
                
                $link = '';
                switch ($atts['link_to']) {
                    case 'file':
                        $link = $image_full[0];
                        break;
                    case 'post':
                        $link = get_attachment_link($attachment_id);
                        break;
                    default:
                        $link = '';
                }
                
                $output .= '<div class="filebird-gallery-item">';
                if ($link) {
                    $output .= '<a href="' . esc_url($link) . '">';
                }
                $output .= '<img src="' . esc_url($image_src[0]) . '" alt="' . esc_attr(get_the_title()) . '" />';
                if ($link) {
                    $output .= '</a>';
                }
                $output .= '</div>';
            }
            
            $output .= '</div>';
            $output .= '<style>
                .filebird-gallery {
                    display: flex;
                    flex-wrap: wrap;
                    margin: -10px;
                }
                .filebird-gallery-item {
                    box-sizing: border-box;
                    padding: 10px;
                }
                .filebird-gallery-columns-1 .filebird-gallery-item { width: 100%; }
                .filebird-gallery-columns-2 .filebird-gallery-item { width: 50%; }
                .filebird-gallery-columns-3 .filebird-gallery-item { width: 33.33%; }
                .filebird-gallery-columns-4 .filebird-gallery-item { width: 25%; }
                .filebird-gallery-columns-5 .filebird-gallery-item { width: 20%; }
                .filebird-gallery-columns-6 .filebird-gallery-item { width: 16.66%; }
                .filebird-gallery-item img {
                    width: 100%;
                    height: auto;
                    display: block;
                }
            </style>';
            
            wp_reset_postdata();
        }

        return $output;
    }

    private function get_image_sizes() {
        $sizes = array();
        $wp_sizes = get_intermediate_image_sizes();
        
        foreach ($wp_sizes as $size) {
            $sizes[$size] = $size;
        }
        
        return $sizes;
    }

}
