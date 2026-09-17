<?php
defined( 'ABSPATH' ) || exit;

abstract class Bizen_Module {

	abstract public function get_id(): string;
	abstract public function get_name(): string;
	abstract public function get_description(): string;
	abstract public function boot(): void;

	/** WP.org plugin slug this module is derived from, or null if not on WP.org. */
	public function get_source_slug(): ?string {
		return null;
	}

	/**
	 * GitHub repository in "owner/repo" format for upstream version checks,
	 * or null if the source is not hosted on GitHub.
	 * Used when get_source_slug() is null (i.e. plugin is not on WP.org).
	 */
	public function get_source_repo(): ?string {
		return null;
	}

	/** Version of the upstream code that was vendored. */
	public function get_source_version(): ?string {
		return null;
	}

	/**
	 * Plugin files that must be active for this module to work.
	 * Format: 'plugin-dir/plugin-file.php' (same as is_plugin_active()).
	 *
	 * @return string[]
	 */
	public function get_dependencies(): array {
		return [];
	}

	/**
	 * Plugins that conflict with this module (i.e. the original plugin being vendored).
	 * If any of these are active while the module is enabled, the module is blocked and
	 * an admin notice with a one-click deactivation link is shown.
	 *
	 * Each entry: [ 'file' => 'plugin-dir/plugin.php', 'name' => 'Human Readable Name' ]
	 *
	 * @return array<array{file: string, name: string}>
	 */
	public function get_conflicts(): array {
		return [];
	}

	/** Optional per-module settings HTML rendered inside the admin panel. */
	public function render_settings(): void {}

	public function dependencies_met(): bool {
		if ( empty( $this->get_dependencies() ) ) {
			return true;
		}
		include_once ABSPATH . 'wp-admin/includes/plugin.php';
		foreach ( $this->get_dependencies() as $file ) {
			if ( ! is_plugin_active( $file ) ) {
				return false;
			}
		}
		return true;
	}

	/** Returns the list of dependency plugin files that are NOT currently active. */
	public function get_missing_dependencies(): array {
		include_once ABSPATH . 'wp-admin/includes/plugin.php';
		return array_filter(
			$this->get_dependencies(),
			fn( $file ) => ! is_plugin_active( $file )
		);
	}
}
