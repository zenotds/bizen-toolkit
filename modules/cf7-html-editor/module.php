<?php
/**
 * CF7 HTML Editor module.
 *
 * Vendored from CF7 Coder v1.0.1 by Aurovrata Venet (GPL-2.0+)
 * WP.org: https://wordpress.org/plugins/cf7-coder/
 *
 * The vendor class is renamed CF7_Coder_Bizen to avoid conflicts with the original plugin.
 */

defined( 'ABSPATH' ) || exit;

return new class extends Bizen_Module {

	public function get_id(): string {
		return 'cf7-html-editor';
	}

	public function get_name(): string {
		return __( 'HTML Editor for CF7', 'bizen-toolkit' );
	}

	public function get_description(): string {
		return __( 'Adds a CodeMirror HTML editor, test mode, redirect after submit, GA/GTM events, auto-hide message, and more to Contact Form 7.', 'bizen-toolkit' );
	}

	public function get_source_slug(): ?string {
		return 'cf7-coder';
	}

	public function get_source_version(): ?string {
		return '1.0.1';
	}

	public function get_dependencies(): array {
		return [ 'contact-form-7/wp-contact-form-7.php' ];
	}

	public function get_conflicts(): array {
		return [
			[ 'file' => 'cf7-coder/cf7-coder.php', 'name' => 'CF7 HTML Editor (cf7-coder)' ],
		];
	}

	public function boot(): void {
		// Require the adapted vendor class, then instantiate it.
		require_once __DIR__ . '/class-cf7-coder.php';
		new CF7_Coder_Bizen();
	}
};
