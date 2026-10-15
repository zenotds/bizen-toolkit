<?php
/**
 * Media Manager module.
 *
 * Bizen module — maintained by Bizen (https://bizen.it). Started as FileBird Pro
 * 6.5.7 by Ninja Team (GPL-2.0+), stripped of its branding and of everything that
 * is not the media library itself, and maintained in-house from here on. Brought
 * up to 6.5.9 by hand: its sidebar bundle, folder download, permission checks and
 * folder-cycle guard; its backup management was left out.
 *
 * Virtual folders for the media library and for the list screen of every post
 * type: the folder tree beside the grid, list and media modal, drag and drop,
 * upload into a folder, sorting, counters, colours, folder download as a zip,
 * the folder field for ACF, the folder column for Admin Columns, the folder
 * gallery for the block editor, Elementor and WPBakery, WPML and Polylang
 * aware counts.
 *
 * Data stays where FileBird keeps it — the wp_fbv and wp_fbv_attachment_folder
 * tables, the fbv_pt_tax_* taxonomies, the fbv_* options and user meta — so a
 * site moving off FileBird keeps its folders, and the fbv_* hooks still fire for
 * code written against them. The REST namespace is the module's own.
 *
 * What went: the licence and its updater, review and feedback prompts, the
 * settings page (breadcrumb always on, default theme only, folders on every post
 * type — narrow them with bizen_media_manager_post_types), the Windows and
 * Dropbox themes, CSV import/export and the twice-daily backups, import from
 * other folder plugins and from FileBird 3, the public REST API and its key,
 * and SVG upload (the toolkit's business). "Calculate file sizes" and "Clear all
 * folders" sit in the Tools submenu of the sidebar's ⋮ menu; the WPML folder
 * sync belongs to the wpml-media-sync module, which adds its own entry there.
 *
 * The sidebar is FileBird 6.5.9's compiled React bundle; there is no source to
 * rebuild it from, so assets/dist/main-*.js carries string patches, and its file
 * name hash was recomputed after them:
 *  - sidebar title reads i18n.media_manager instead of "FileBird";
 *  - the settings modal no longer renders the theme picker and breadcrumb switch;
 *  - the ⋮ menu ends with a Tools submenu (admins, media library only) whose
 *    bizen_* keys are handed with the bundle's toast to window.bizenMediaManager
 *    (assets/js/tools.js); "Sync WPML folders" joins it when fbv_data carries
 *    wpml_folder_sync, set by the wpml-media-sync module;
 *  - the never-mounted deactivation feedback dialog lost its vendor URLs.
 * tools/media-manager-patch-bundle.py applies them to an upstream dist folder;
 * each anchor must match exactly once or the run stops, so a new upstream bundle
 * shows at once which patches need a new anchor.
 */

defined( 'ABSPATH' ) || exit;

return new class extends Bizen_Module {

	public function get_id(): string {
		return 'media-manager';
	}

	public function get_name(): string {
		return __( 'Media Manager', 'bizen-toolkit' );
	}

	public function get_description(): string {
		return __( 'Folders for the media library and for every post type list, with drag and drop, upload into a folder, sorting, counters and a folder gallery. Reads the folders of an existing FileBird install as they are.', 'bizen-toolkit' );
	}

	public function get_category(): string {
		return 'media';
	}

	public function get_conflicts(): array {
		return [
			[ 'file' => 'filebird/filebird.php', 'name' => 'FileBird' ],
			[ 'file' => 'filebird-pro/filebird.php', 'name' => 'FileBird Pro' ],
		];
	}

	public function boot(): void {
		define( 'BIZEN_MM_VERSION', BIZEN_TOOLKIT_VERSION );
		define( 'BIZEN_MM_PLUGIN_PATH', __DIR__ . '/' );
		define( 'BIZEN_MM_PLUGIN_URL', BIZEN_TOOLKIT_URL . 'modules/media-manager/' );
		define( 'BIZEN_MM_REST_URL', 'bizen-media-manager/v1' );

		spl_autoload_register( [ $this, 'autoload' ] );

		\Bizen\MediaManager\Plugin::prepareRun();

		\Bizen\MediaManager\Classes\Core::getInstance();
		\Bizen\MediaManager\Rest\RestApi::getInstance();
		\Bizen\MediaManager\Addons\PostType\Init::getInstance();
		new \Bizen\MediaManager\Support\SupportController();
		new \Bizen\MediaManager\Classes\Schedule();
		\Bizen\MediaManager\PageBuilders\WPBakery\Init::getInstance();
		\Bizen\MediaManager\PageBuilders\Elementor\Init::getInstance();

		if ( function_exists( 'register_block_type' ) ) {
			require __DIR__ . '/blocks/folder-gallery/init.php';
		}
	}

	/**
	 * Bizen\MediaManager\Foo\Bar → includes/Foo/Bar.php, plus the libraries behind
	 * "Download folder" in vendor/: ZipStream 2.2.6 and what it requires. Another
	 * plugin that already loaded the same namespaces wins, as with Composer.
	 */
	public function autoload( string $class ): void {
		static $map = [
			'Bizen\\MediaManager\\'          => 'includes/',
			'ZipStream\\'                    => 'vendor/zipstream/src/',
			'MyCLabs\\Enum\\'                => 'vendor/php-enum/src/',
			'Psr\\Http\\Message\\'           => 'vendor/http-message/src/',
			'Symfony\\Polyfill\\Mbstring\\'  => 'vendor/polyfill-mbstring/',
		];

		foreach ( $map as $prefix => $dir ) {
			if ( strncmp( $prefix, $class, strlen( $prefix ) ) !== 0 ) {
				continue;
			}

			if ( 'ZipStream\\' === $prefix && ! extension_loaded( 'mbstring' ) ) {
				require_once __DIR__ . '/vendor/polyfill-mbstring/bootstrap.php';
			}

			$file = __DIR__ . '/' . $dir . str_replace( '\\', '/', substr( $class, strlen( $prefix ) ) ) . '.php';
			if ( file_exists( $file ) ) {
				require $file;
			}
			return;
		}
	}
};
