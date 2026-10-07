<?php
/**
 * CF7 HTML Editor module.
 *
 * Bizen module — written and maintained by Bizen (https://bizen.it).
 *
 * Started as a vendored copy of CF7 Coder v1.0.1 (GPL-2.0+,
 * https://wordpress.org/plugins/cf7-coder/), which stopped working with
 * Contact Form 7 6.2. Now maintained in-house and no longer tracked upstream.
 *
 * Swaps the form-template textarea for a CodeMirror HTML editor and adds
 * per-form switches to the CF7 "Status" box: test mode, autop off, redirect,
 * hide form, GA/GTM event and a few submit-time behaviours. Settings are CF7
 * properties stored under the same `_wpcf7_*` meta keys CF7 Coder used, so
 * forms configured with the original plugin keep their settings.
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

	public function get_category(): string {
		return 'form';
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
		require_once __DIR__ . '/class-cf7-html-editor.php';
		new Bizen_CF7_HTML_Editor();
	}
};
