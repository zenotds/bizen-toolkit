<?php
/**
 * ACFML Copy Once module.
 *
 * Bizen module — written and maintained by Bizen (https://bizen.it).
 *
 * For sites translated by hand in the WordPress editor, every ACF field should be
 * "Copy once": the translation starts as a copy of the original and is never
 * touched again. The only ACFML mode that gets there without side effects is
 * Expert, with every field set to Copy once:
 *
 * - "Each language has its own content" (localization) excludes every post using
 *   the group from the WPML Translation Editor (TranslationEditor\DisableHooks on
 *   wpml_tm_editor_exclude_posts), and the Translation Management dashboard
 *   treats those posts as blocked: they can no longer be duplicated in bulk. It
 *   also leaves text, textarea, wysiwyg, url and link on "Translate".
 * - "Same content in every language" sends the fields to translation and rewrites
 *   every field preference with its own defaults on each group save.
 * - In Expert mode ACFML leaves the per-field preference alone and writes it to
 *   the WPML per-meta-key settings every time a value is saved. A field added
 *   later, though, does not start on Copy once.
 *
 * This module forces Expert mode on every field group and Copy once on every
 * field when ACF loads them, from the database and from local JSON alike, so
 * nothing depends on how a group was configured, and again when ACF saves them,
 * so saving a group from the ACF admin stores both.
 *
 * WPML syncs from its own per-meta-key list (custom_fields_translation:
 * content_0_cards, bg_color…), and ACFML updates a key only when a value under
 * it is saved, so keys left on "Copy" by an earlier setup keep pushing the
 * original's flexible layouts, repeater row counts and selects onto every
 * translation until then. Each time a field group is saved the keys matching its
 * fields are realigned; `wp bizen-acfml realign` does it for every group without
 * saving them (saving field groups from WP-CLI makes ACF write clone fields
 * expanded into the local JSON).
 *
 * Left alone on purpose:
 * - Keys locked by a wpml-config.xml: WPML restores them on the next config load.
 * - The underscored twin of each key (_content_0_cards), which holds the ACF field
 *   key: ACFML sets it back to "Copy" on every value save outside localization
 *   mode, and fighting it would rewrite the WPML settings once per field on every
 *   save. Its value is the same field key in every language anyway.
 *
 * To keep a field out, return false from `bizen_acfml_copy_once_field`:
 *
 *     add_filter( 'bizen_acfml_copy_once_field', function ( $copy_once, $field ) {
 *         return 'seo_title' === $field['name'] ? false : $copy_once;
 *     }, 10, 2 );
 */

defined( 'ABSPATH' ) || exit;

return new class extends Bizen_Module {

	private const MODE_KEY   = 'acfml_field_group_mode';
	private const EXPERT     = 'advanced';
	private const PREFERENCE = 'wpml_cf_preferences';

	// Preference index => index of the keys locked by a wpml-config.xml.
	private const INDEXES = [
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
		return __( 'Puts every ACF field group in Expert mode with every field set to "Copy once", and realigns the WPML custom field settings when a field group is saved: saving the original no longer overwrites the translations, and pages can still be duplicated in bulk.', 'bizen-toolkit' );
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
		add_filter( 'acf/load_field_group', [ $this, 'force_expert_mode' ] );

		// After ACF merges the local JSON groups in (priority 20), which would bring their stored mode back.
		add_filter( 'acf/load_field_groups', [ $this, 'force_expert_mode_on_all' ], 30 );

		add_filter( 'acf/load_field', [ $this, 'force_copy_once' ] );

		// Same on save, so the database and the local JSON hold it too, and ACFML sees Expert mode.
		add_filter( 'acf/pre_update_field_group', [ $this, 'force_expert_mode' ] );
		add_filter( 'acf/update_field', [ $this, 'force_copy_once' ] );

		// After ACFML (priority 9), which rewrites the field preferences.
		add_action( 'acf/update_field_group', [ $this, 'realign_group' ], 20 );

		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			WP_CLI::add_command( 'bizen-acfml realign', [ $this, 'cli_realign' ] );
		}
	}

	public function force_expert_mode( $field_group ) {
		if ( is_array( $field_group ) ) {
			$field_group[ self::MODE_KEY ] = self::EXPERT;
		}

		return $field_group;
	}

	public function force_expert_mode_on_all( $field_groups ) {
		return is_array( $field_groups ) ? array_map( [ $this, 'force_expert_mode' ], $field_groups ) : $field_groups;
	}

	public function force_copy_once( $field ) {
		if ( ! is_array( $field ) || empty( $field['name'] ) ) {
			return $field;
		}

		if ( apply_filters( 'bizen_acfml_copy_once_field', true, $field ) ) {
			$field[ self::PREFERENCE ] = WPML_COPY_ONCE_CUSTOM_FIELD;
		}

		return $field;
	}

	public function realign_group( $field_group ): void {
		if ( is_array( $field_group ) ) {
			$this->realign( [ $field_group ] );
		}
	}

	/**
	 * Sets every WPML custom field setting matching an ACF field to "Copy once".
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

		$field_groups = acf_get_field_groups();
		if ( ! $field_groups ) {
			WP_CLI::success( 'No field groups.' );
			return;
		}

		$dry_run = ! empty( $assoc_args['dry-run'] );
		$changes = $this->realign( $field_groups, $dry_run );

		if ( $dry_run ) {
			foreach ( $changes as $change ) {
				WP_CLI::log( $change );
			}
		}

		WP_CLI::success( sprintf(
			'%d settings %s to Copy once across %d field groups.',
			count( $changes ),
			$dry_run ? 'would be set' : 'set',
			count( $field_groups )
		) );
	}

	/**
	 * @param  array[] $field_groups
	 * @return string[] The settings changed, as "index: meta_key old → new".
	 */
	private function realign( array $field_groups, bool $dry_run = false ): array {
		$patterns = [];
		foreach ( $field_groups as $field_group ) {
			$this->collect_patterns( (array) acf_get_fields( $field_group ), '', $patterns );
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
				$meta_key = (string) $meta_key;

				if ( WPML_COPY_ONCE_CUSTOM_FIELD === (int) $preference
					|| '_' === substr( $meta_key, 0, 1 )
					|| in_array( $meta_key, $locked, true )
					|| ! $this->matches( $meta_key, $patterns ) ) {
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

	/**
	 * Builds the meta key pattern of every Copy once field, the way ACF names
	 * the values: repeater and flexible rows add "_<row>_", groups add "_". A clone
	 * stores nothing under its own name, and ACF already writes the prefix (if any)
	 * into the names of the fields it clones.
	 */
	private function collect_patterns( array $fields, string $base, array &$patterns ): void {
		foreach ( $fields as $field ) {
			$type = $field['type'] ?? '';

			if ( 'clone' === $type ) {
				$this->collect_patterns( (array) ( $field['sub_fields'] ?? [] ), $base, $patterns );
				continue;
			}

			if ( empty( $field['name'] ) ) {
				continue;
			}

			$pattern = $base . preg_quote( $field['name'], '#' );

			if ( WPML_COPY_ONCE_CUSTOM_FIELD === (int) ( $field[ self::PREFERENCE ] ?? -1 ) ) {
				$patterns[] = $pattern;
			}

			$suffix = 'group' === $type ? '_' : '_\d+_';

			if ( ! empty( $field['sub_fields'] ) ) {
				$this->collect_patterns( (array) $field['sub_fields'], $pattern . $suffix, $patterns );
			}

			foreach ( (array) ( $field['layouts'] ?? [] ) as $layout ) {
				$this->collect_patterns( (array) ( $layout['sub_fields'] ?? [] ), $pattern . '_\d+_', $patterns );
			}
		}
	}

	private function matches( string $name, array $patterns ): bool {
		foreach ( $patterns as $pattern ) {
			if ( preg_match( '#^' . $pattern . '$#', $name ) ) {
				return true;
			}
		}

		return false;
	}
};
