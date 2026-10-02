<?php
/**
 * ACFML Copy Once module.
 *
 * Bizen module — written and maintained by Bizen (https://bizen.it).
 *
 * For sites translated by hand in the WordPress editor, every ACF field should be
 * "Copy once": the translation starts as a copy of the original and is never
 * touched again. ACFML gets there only partially, in three places:
 *
 * 1. The field group mode overrides the per-field preference, so setting fields
 *    to "Copy once" by hand is ignored unless the group is in Expert mode.
 * 2. "Each language has its own content" (localization) maps every field type to
 *    Copy once except text, textarea, wysiwyg, url and link, which stay
 *    "Translate" (ACFML\FieldGroup\ModeDefaults).
 * 3. WPML syncs from its own per-meta-key list (custom_fields_translation:
 *    content_0_cards, _content, bg_color…), not from the ACF fields. When a group
 *    changes mode ACFML only fills the keys missing from that list
 *    (SetFieldPreferencesAsDefault::fill()) and never overwrites the existing
 *    ones, so keys left on "Copy" keep pushing the original's values — flexible
 *    layouts, repeater row counts, selects — onto every translation on save.
 *
 * This module maps localization mode to Copy once for every field type, and each
 * time a localization group is saved it realigns the WPML keys matching that
 * group's field name patterns. Groups already in localization mode can be
 * realigned without saving them with `wp bizen-acfml realign` (saving field groups
 * from WP-CLI makes ACF write clone fields expanded into the local JSON).
 *
 * Expert mode groups are left alone: there the per-field preference is the source
 * of truth. Keys set to "Don't translate" and keys locked by a wpml-config.xml are
 * left alone too.
 */

defined( 'ABSPATH' ) || exit;

return new class extends Bizen_Module {

	private const MODE_KEY     = 'acfml_field_group_mode';
	private const LOCALIZATION = 'localization';
	private const PATTERNS     = 'acfml_field_name_patterns';
	// Preference index => index of the keys locked by a wpml-config.xml.
	private const INDEXES      = [
		'custom_fields_translation'      => 'custom_fields_readonly_config',
		'custom_term_fields_translation' => 'custom_term_fields_readonly_config',
	];

	public function get_id(): string {
		return 'acfml-copy-once';
	}

	public function get_name(): string {
		return __( 'ACFML: Copy once everywhere', 'bizen-toolkit' );
	}

	public function get_description(): string {
		return __( 'In the "Each language has its own content" mode every ACF field becomes "Copy once" (text fields included), and the WPML custom field settings are realigned when the field group is saved, so saving the original no longer overwrites the translations.', 'bizen-toolkit' );
	}

	public function get_category(): string {
		return 'admin';
	}

	public function get_dependencies(): array {
		return [
			'sitepress-multilingual-cms/sitepress.php',
			'acfml/wpml-acf.php',
		];
	}

	public function boot(): void {
		add_filter( 'acfml_field_group_mode_field_translation_preference', [ $this, 'copy_once' ], 10, 2 );

		// After ACFML (priority 9), which rewrites the field preferences and the name patterns.
		add_action( 'acf/update_field_group', [ $this, 'realign_group' ], 20 );

		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			WP_CLI::add_command( 'bizen-acfml realign', [ $this, 'cli_realign' ] );
		}
	}

	public function copy_once( $preference, $mode ) {
		return self::LOCALIZATION === $mode ? WPML_COPY_ONCE_CUSTOM_FIELD : $preference;
	}

	public function realign_group( $field_group ): void {
		if ( self::LOCALIZATION !== ( $field_group[ self::MODE_KEY ] ?? null ) ) {
			return;
		}

		$this->realign( [ $field_group['key'] ] );
	}

	/**
	 * Sets every WPML custom field setting matching a localization group to "Copy once".
	 *
	 * ## OPTIONS
	 *
	 * [--dry-run]
	 * : List the settings that would change without saving them.
	 */
	public function cli_realign( array $args, array $assoc_args ): void {
		if ( ! function_exists( 'acf_get_field_groups' ) ) {
			WP_CLI::error( 'ACF is not active.' );
		}

		$keys = [];
		foreach ( acf_get_field_groups() as $field_group ) {
			if ( self::LOCALIZATION === ( $field_group[ self::MODE_KEY ] ?? null ) ) {
				$keys[] = $field_group['key'];
			}
		}

		if ( ! $keys ) {
			WP_CLI::success( 'No field groups in "Each language has its own content" mode.' );
			return;
		}

		$dry_run = ! empty( $assoc_args['dry-run'] );
		$changes = $this->realign( $keys, $dry_run );

		if ( $dry_run ) {
			foreach ( $changes as $change ) {
				WP_CLI::log( $change );
			}
		}

		WP_CLI::success( sprintf(
			'%d settings %s to Copy once across %d field groups.',
			count( $changes ),
			$dry_run ? 'would be set' : 'set',
			count( $keys )
		) );
	}

	/**
	 * @param  string[] $group_keys
	 * @return string[] The settings changed, as "index: meta_key old → new".
	 */
	private function realign( array $group_keys, bool $dry_run = false ): array {
		$all_patterns = (array) get_option( self::PATTERNS, [] );

		$patterns = [];
		foreach ( $group_keys as $key ) {
			foreach ( (array) ( $all_patterns[ $key ] ?? [] ) as $pattern ) {
				if ( '' !== $pattern ) {
					$patterns[] = $pattern;
				}
			}
		}

		$tm = function_exists( 'wpml_load_core_tm' ) ? wpml_load_core_tm() : null;
		if ( ! $patterns || ! $tm ) {
			return [];
		}

		$tm->load_settings_if_required();

		$changes = [];
		foreach ( self::INDEXES as $index => $readonly_index ) {
			$locked = (array) ( $tm->settings[ $readonly_index ] ?? [] );

			foreach ( (array) ( $tm->settings[ $index ] ?? [] ) as $meta_key => $preference ) {
				// Already Copy once, or deliberately ignored.
				if ( in_array( (int) $preference, [ WPML_COPY_ONCE_CUSTOM_FIELD, WPML_IGNORE_CUSTOM_FIELD ], true ) ) {
					continue;
				}

				// Locked by a wpml-config.xml: WPML would restore it on the next config load.
				if ( in_array( $meta_key, $locked, true ) ) {
					continue;
				}

				// The underscored twin holds the ACF field key reference and follows its field.
				if ( ! $this->matches( ltrim( (string) $meta_key, '_' ), $patterns ) ) {
					continue;
				}

				$changes[] = sprintf( '%s: %s %d → %d', $index, $meta_key, $preference, WPML_COPY_ONCE_CUSTOM_FIELD );

				if ( ! $dry_run ) {
					$tm->settings[ $index ][ $meta_key ] = WPML_COPY_ONCE_CUSTOM_FIELD;
				}
			}
		}

		if ( $changes && ! $dry_run ) {
			$tm->save_settings();
		}

		return $changes;
	}

	/** Same matching ACFML uses for its name patterns (FieldNamePatterns::matchesPattern). */
	private function matches( string $name, array $patterns ): bool {
		foreach ( $patterns as $pattern ) {
			if ( preg_match( '/^' . $pattern . '$/', $name ) ) {
				return true;
			}
		}

		return false;
	}
};
