<?php
/**
 * Disable Flamingo Addressbook module.
 *
 * Vendored from Disable Flamingo Addressbook v1.0 by Christian Sabo (GPL-2.0+)
 * WP.org: https://wordpress.org/plugins/disable-flamingo-addressbook/
 *
 * Stops Flamingo from collecting personal data into its address book:
 * unhooks the user-profile and comment listeners, and blanks the contact
 * args so Flamingo_Contact::add() aborts (it bails on an empty email).
 * Existing address book entries are not affected.
 */

defined( 'ABSPATH' ) || exit;

return new class extends Bizen_Module {

	public function get_id(): string {
		return 'disable-flamingo-addressbook';
	}

	public function get_name(): string {
		return __( 'Disable Flamingo Addressbook', 'bizen-toolkit' );
	}

	public function get_description(): string {
		return __( 'Prevents Flamingo from saving any data to its address book — form submissions are still stored in the inbound messages log.', 'bizen-toolkit' );
	}

	public function get_source_slug(): ?string {
		return 'disable-flamingo-addressbook';
	}

	public function get_source_version(): ?string {
		return '1.0';
	}

	public function get_dependencies(): array {
		return [ 'flamingo/flamingo.php' ];
	}

	public function get_conflicts(): array {
		return [
			[ 'file' => 'disable-flamingo-addressbook/disable-flamingo-addressbook.php', 'name' => 'Disable Flamingo Addressbook' ],
		];
	}

	public function boot(): void {
		// Flamingo registers these at plugin file load, so they exist by the
		// time modules boot (plugins_loaded/999) and can be removed directly.
		remove_action( 'profile_update', 'flamingo_user_profile_update' );
		remove_action( 'user_register', 'flamingo_user_profile_update' );
		remove_action( 'wp_insert_comment', 'flamingo_insert_comment' );
		remove_action( 'transition_comment_status', 'flamingo_transition_comment_status', 10 );

		add_filter( 'flamingo_add_contact', [ $this, 'blank_contact' ] );
	}

	/** Returns empty contact args so Flamingo_Contact::add() discards the entry. */
	public function blank_contact( $args ): array {
		return [
			'email' => '',
			'name'  => '',
			'props' => [],
		];
	}
};
