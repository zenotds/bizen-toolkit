<?php
/**
 * CF7 Email Template module.
 *
 * Vendored from html-template-for-cf7 v2.2.2 by Mário Valney (GPL-2.0+)
 * Source: https://github.com/mariovalney/cf7-html-email-template-extension
 *
 * The original plugin's constants (CF7HETE_*) are defined here pointing to
 * this module directory so all internal includes and asset URLs resolve correctly.
 */

defined( 'ABSPATH' ) || exit;

return new class extends Bizen_Module {

	public function get_id(): string {
		return 'cf7-email-template';
	}

	public function get_name(): string {
		return __( 'HTML Template for CF7', 'bizen-toolkit' );
	}

	public function get_description(): string {
		return __( 'Wraps Contact Form 7 emails in a custom HTML header/footer template with a live Ace editor preview.', 'bizen-toolkit' );
	}

	public function get_source_slug(): ?string {
		return 'cf7-html-email-template-extension';
	}

	public function get_source_version(): ?string {
		return '2.2.2';
	}

	public function get_dependencies(): array {
		return [ 'contact-form-7/wp-contact-form-7.php' ];
	}

	public function get_conflicts(): array {
		return [
			[ 'file' => 'cf7-html-email-template-extension/cf7-html-email-template-extension.php', 'name' => 'HTML Template for CF7' ],
		];
	}

	public function boot(): void {
		// Define constants that the vendored classes reference, pointing to this module directory.
		if ( ! defined( 'CF7HETE_VERSION' ) ) {
			define( 'CF7HETE_VERSION',         '2.2.2' );
		}
		if ( ! defined( 'CF7HETE_PLUGIN_FILE' ) ) {
			define( 'CF7HETE_PLUGIN_FILE',     __FILE__ );
		}
		if ( ! defined( 'CF7HETE_PLUGIN_PATH' ) ) {
			// Without trailing slash — matches original behaviour.
			define( 'CF7HETE_PLUGIN_PATH',     rtrim( plugin_dir_path( __FILE__ ), '/' ) );
		}
		if ( ! defined( 'CF7HETE_PLUGIN_URL' ) ) {
			define( 'CF7HETE_PLUGIN_URL',      rtrim( plugin_dir_url( __FILE__ ), '/' ) );
		}
		if ( ! defined( 'CF7HETE_PLUGIN_BASENAME' ) ) {
			define( 'CF7HETE_PLUGIN_BASENAME', plugin_basename( __FILE__ ) );
		}
		if ( ! defined( 'CF7HETE_PLUGIN_DIR' ) ) {
			define( 'CF7HETE_PLUGIN_DIR',      dirname( CF7HETE_PLUGIN_BASENAME ) );
		}

		// Load vendored includes (backward-compat filter + module base class).
		require_once CF7HETE_PLUGIN_PATH . '/includes/backward-compatibility.php';
		require_once CF7HETE_PLUGIN_PATH . '/includes/class-module-base.php';

		// Bootstrap the core class (module loader + hook runner).
		require_once __DIR__ . '/class-core.php';

		$core = Cf7_Html_Email_Template_Extension::instance();
		$core->run();
	}
};
