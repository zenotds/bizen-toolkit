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
 * fields are realigned.
 *
 * `wp bizen-acfml realign` does both for every group at once, without saving
 * them through ACF (saving field groups from WP-CLI makes ACF write clone fields
 * expanded into the local JSON):
 * - Local JSON files get Expert mode, Copy once and a new "modified" time, written
 *   back with the file's own indentation, so ACF offers "Sync available" wherever
 *   the database copy is older — locally and on every site the JSON is deployed to.
 * - Groups stored only in the database are updated in place.
 * - The WPML per-meta-key settings are realigned.
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
		if ( is_array( $field ) && $this->wants_copy_once( $field ) ) {
			$field[ self::PREFERENCE ] = WPML_COPY_ONCE_CUSTOM_FIELD;
		}

		return $field;
	}

	private function wants_copy_once( array $field ): bool {
		return ! empty( $field['name'] ) && apply_filters( 'bizen_acfml_copy_once_field', true, $field );
	}

	public function realign_group( $field_group ): void {
		if ( is_array( $field_group ) ) {
			$this->realign( [ $field_group ] );
		}
	}

	/**
	 * Stores Expert mode and Copy once in every field group definition — local JSON
	 * and database — and sets every WPML custom field setting matching an ACF field
	 * to "Copy once".
	 *
	 * ## OPTIONS
	 *
	 * [--dry-run]
	 * : List what would change without saving anything.
	 */
	public function cli_realign( array $args, array $assoc_args ): void {
		if ( ! function_exists( 'acf_get_field_groups' ) ) {
			WP_CLI::error( 'ACF is not active.' );
		}

		$dry_run  = ! empty( $assoc_args['dry-run'] );
		$stored   = $this->store_definitions( $dry_run );
		$settings = $this->realign( (array) acf_get_field_groups(), $dry_run );

		if ( $dry_run ) {
			foreach ( array_merge( $stored, $settings ) as $change ) {
				WP_CLI::log( $change );
			}
		}

		WP_CLI::success( sprintf(
			'%d field groups %s Expert / Copy once, %d WPML settings %s Copy once.',
			count( $stored ),
			$dry_run ? 'would be set to' : 'set to',
			count( $settings ),
			$dry_run ? 'would be set to' : 'set to'
		) );

		if ( ! $dry_run && preg_grep( '/^json: /', $stored ) ) {
			WP_CLI::log( 'Local JSON updated: sync the field groups from ACF > Field Groups where it says "Sync available".' );
		}
	}

	/**
	 * Writes Expert mode and Copy once into the stored definitions, bypassing the
	 * ACF save. A group with a local JSON file is changed there only: the newer
	 * "modified" time makes ACF offer the sync into the database.
	 *
	 * @return string[] The groups changed, as "json: <path>" or "db: <title> (<key>)".
	 */
	private function store_definitions( bool $dry_run ): array {
		$changes = [];
		$in_json = [];

		$files = function_exists( 'acf_get_local_json_files' ) ? (array) acf_get_local_json_files( 'acf-field-group' ) : [];

		foreach ( $files as $key => $path ) {
			$raw   = (string) file_get_contents( $path );
			$group = json_decode( $raw, true );

			if ( ! is_array( $group ) || ! isset( $group['fields'] ) ) {
				continue;
			}

			$in_json[ $group['key'] ?? $key ] = true;

			if ( ! $this->set_expert_copy_once( $group ) ) {
				continue;
			}

			$changes[] = 'json: ' . $path;

			if ( ! $dry_run ) {
				$group['modified'] = time();

				if ( false === file_put_contents( $path, $this->encode_like( $group, $raw ) ) ) {
					WP_CLI::warning( 'Could not write ' . $path );
				}
			}
		}

		foreach ( (array) acf_get_raw_field_groups() as $group ) {
			if ( isset( $in_json[ $group['key'] ] ) ) {
				continue;
			}

			$changed = $this->update_post_settings( (int) $group['ID'], $dry_run, function ( array $settings ) {
				$settings[ self::MODE_KEY ] = self::EXPERT;
				return $settings;
			} );

			$changed = $this->store_db_fields( (int) $group['ID'], $dry_run ) || $changed;

			if ( ! $changed ) {
				continue;
			}

			$changes[] = sprintf( 'db: %s (%s)', $group['title'] ?? '', $group['key'] );

			if ( ! $dry_run ) {
				global $wpdb;
				$wpdb->update(
					$wpdb->posts,
					[ 'post_modified' => current_time( 'mysql' ), 'post_modified_gmt' => current_time( 'mysql', true ) ],
					[ 'ID' => (int) $group['ID'] ]
				);
				clean_post_cache( (int) $group['ID'] );
			}
		}

		return $changes;
	}

	/** Sets Expert mode and Copy once on a decoded JSON group; true if anything changed. */
	private function set_expert_copy_once( array &$group ): bool {
		$changed = false;

		if ( self::EXPERT !== ( $group[ self::MODE_KEY ] ?? null ) ) {
			$group[ self::MODE_KEY ] = self::EXPERT;
			$changed                 = true;
		}

		$this->set_copy_once_on( $group['fields'], $changed );

		return $changed;
	}

	private function set_copy_once_on( array &$fields, bool &$changed ): void {
		foreach ( $fields as &$field ) {
			if ( ! is_array( $field ) ) {
				continue;
			}

			if ( $this->wants_copy_once( $field ) && WPML_COPY_ONCE_CUSTOM_FIELD !== (int) ( $field[ self::PREFERENCE ] ?? -1 ) ) {
				$field[ self::PREFERENCE ] = WPML_COPY_ONCE_CUSTOM_FIELD;
				$changed                   = true;
			}

			if ( ! empty( $field['sub_fields'] ) && is_array( $field['sub_fields'] ) ) {
				$this->set_copy_once_on( $field['sub_fields'], $changed );
			}

			if ( ! empty( $field['layouts'] ) && is_array( $field['layouts'] ) ) {
				foreach ( $field['layouts'] as &$layout ) {
					if ( ! empty( $layout['sub_fields'] ) && is_array( $layout['sub_fields'] ) ) {
						$this->set_copy_once_on( $layout['sub_fields'], $changed );
					}
				}
				unset( $layout );
			}
		}
		unset( $field );
	}

	/** Copy once on every field stored under a database parent (group, field or layout parent). */
	private function store_db_fields( int $parent_id, bool $dry_run ): bool {
		$changed = false;

		foreach ( (array) acf_get_raw_fields( $parent_id ) as $field ) {
			if ( $this->wants_copy_once( $field ) ) {
				$changed = $this->update_post_settings( (int) $field['ID'], $dry_run, function ( array $settings ) {
					$settings[ self::PREFERENCE ] = WPML_COPY_ONCE_CUSTOM_FIELD;
					return $settings;
				} ) || $changed;
			}

			$changed = $this->store_db_fields( (int) $field['ID'], $dry_run ) || $changed;
		}

		return $changed;
	}

	/** Rewrites the serialized settings ACF keeps in post_content; true if they change. */
	private function update_post_settings( int $post_id, bool $dry_run, callable $modify ): bool {
		global $wpdb;

		$post     = get_post( $post_id );
		$settings = $post ? acf_maybe_unserialize( $post->post_content ) : null;

		if ( ! is_array( $settings ) ) {
			return false;
		}

		$updated = $modify( $settings );

		if ( $updated == $settings ) { // phpcs:ignore Universal.Operators.StrictComparisons -- "3" and 3 are the same preference.
			return false;
		}

		if ( ! $dry_run ) {
			$wpdb->update( $wpdb->posts, [ 'post_content' => maybe_serialize( $updated ) ], [ 'ID' => $post_id ] );
			clean_post_cache( $post_id );
		}

		return true;
	}

	/** Encodes like ACF does, keeping the original file's indentation, slashes and final newline. */
	private function encode_like( array $data, string $original ): string {
		$flags = JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE;
		if ( false === strpos( $original, '\\/' ) ) {
			$flags |= JSON_UNESCAPED_SLASHES;
		}

		$json = (string) json_encode( $data, $flags );

		if ( preg_match( '/^\{\R\t/', $original ) ) {
			$json = preg_replace_callback( '/^(?: {4})+/m', fn( $m ) => str_repeat( "\t", strlen( $m[0] ) / 4 ), $json );
		}

		if ( preg_match( '/\R\z/', $original ) ) {
			$json .= "\n";
		}

		return $json;
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
