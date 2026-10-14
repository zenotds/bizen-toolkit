<?php
namespace Bizen\MediaManager\PageBuilders\Elementor;

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
        // Check if Elementor is active
        if ($this->is_elementor_active()) {
            // Register Elementor widget category
            add_action('elementor/elements/categories_registered', array($this, 'register_elementor_category'));
            
            // For Elementor 3.5.0 and above
            add_action('elementor/widgets/register', array($this, 'register_widgets'));
            
            // For Elementor < 3.5.0
            add_action('elementor/widgets/widgets_registered', array($this, 'register_widgets_legacy'));
            
            // Load scripts and styles
            add_action('elementor/editor/before_enqueue_scripts', array($this, 'editor_scripts'));
            add_action('elementor/frontend/after_enqueue_styles', array($this, 'frontend_styles'));
        }
    }

    /**
     * Check if Elementor is active
     */
    private function is_elementor_active() {
        return did_action('elementor/loaded');
    }
    

    /**
     * Register the Media Manager category in Elementor
     */
    public function register_elementor_category($elements_manager) {
        $elements_manager->add_category(
            'filebird',
            [
                'title' => esc_html__('Media Manager', 'bizen-toolkit'),
                'icon' => 'fa fa-folder-open',
            ]
        );
    }

    /**
     * Register Elementor widgets (for Elementor 3.5.0+)
     */
    public function register_widgets($widgets_manager) {
        // Include widget file
        require_once(__DIR__ . '/widgets/FolderGalleryWidget.php');
        
        // Register widget with namespace
        if (class_exists('\Elementor\Widget_Base')) {
            $widgets_manager->register(new FolderGalleryWidget());
        }
    }
    
    /**
     * Register Elementor widgets (legacy support for Elementor < 3.5.0)
     */
    public function register_widgets_legacy() {
        // Make sure the Elementor Widget base exists
        if (class_exists('\Elementor\Widget_Base') && class_exists('\Elementor\Plugin')) {
            // Include widget file
            require_once(__DIR__ . '/widgets/FolderGalleryWidget.php');
            
            // Register widget using legacy method
            \Elementor\Plugin::instance()->widgets_manager->register_widget_type(new FolderGalleryWidget());
        }
    }

    /**
     * Load editor scripts
     */
    public function editor_scripts() {
        // Editor styles
        wp_enqueue_style(
            'filebird-elementor-editor',
            plugin_dir_url(__FILE__) . 'assets/css/editor.css',
            [],
            BIZEN_MM_VERSION
        );
    }

    /**
     * Load frontend styles
     */
    public function frontend_styles() {
        // Frontend styles
        wp_enqueue_style(
            'filebird-elementor-frontend',
            plugin_dir_url(__FILE__) . 'assets/css/frontend.css',
            [],
            BIZEN_MM_VERSION
        );
    }
}
