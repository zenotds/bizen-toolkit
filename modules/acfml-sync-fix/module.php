<?php
/**
 * ACFML Repeater Sync Disabler
 *
 * Bizen module — written and maintained by Bizen (https://bizen.it).
 */

defined( 'ABSPATH' ) || exit;

return new class extends Bizen_Module {

	public function get_id(): string {
		return 'acfml-sync-fix';
	}

	public function get_name(): string {
		return __( 'Disable Sync Repeater for ACFML', 'bizen-toolkit' );
	}

	public function get_description(): string {
		return __( 'Disables the ACFML repeater sync checkbox and its stored option — prevents accidental field sync across languages.', 'bizen-toolkit' );
	}

	public function get_dependencies(): array {
		return [
			'advanced-custom-fields-pro/acf.php',
			'wpml-string-translation/plugin.php',
		];
	}

	public function get_conflicts(): array {
		return [
			[ 'file' => 'disable-sync-repeater-option-for-acfml/disable-sync-repeater-option-for-acfml.php', 'name' => 'Disable Sync Repeater Option for ACFML' ],
		];
	}

	public function boot(): void {
		// Disable the repeater sync checkbox on post edit screens.
		add_action( 'add_meta_boxes', [ $this, 'remove_meta_box' ], 11 );

		// Delete the stored sync option so it cannot persist across sessions.
		add_action( 'plugins_loaded', [ $this, 'delete_sync_option' ] );
	}

	public function remove_meta_box(): void {
		if ( ! class_exists( 'ACFML\Repeater\Sync\CheckboxUI' ) ||
			! defined( 'ACFML\Repeater\Sync\CheckboxUI::META_BOX_ID' ) ) {
			return;
		}

		global $pagenow;
		if ( 'post.php' !== $pagenow ) {
			return;
		}

		$screen = get_current_screen();
		if ( ! $screen || ! $screen->post_type ) {
			return;
		}

		remove_meta_box(
			\ACFML\Repeater\Sync\CheckboxUI::META_BOX_ID,
			$screen->post_type,
			'normal'
		);
	}

	public function delete_sync_option(): void {
		if ( ! is_admin() ||
			! class_exists( 'ACFML\Repeater\Sync\CheckboxOption' ) ||
			! defined( 'ACFML\Repeater\Sync\CheckboxOption::SYNCHRONISE_WP_OPTION_NAME' ) ) {
			return;
		}

		$option = \ACFML\Repeater\Sync\CheckboxOption::SYNCHRONISE_WP_OPTION_NAME;
		if ( get_option( $option ) !== false ) {
			delete_option( $option );
		}
	}
};
