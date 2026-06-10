<?php
defined( 'ABSPATH' ) || exit;

/**
 * Detects active plugins that conflict with enabled Bizen Toolkit modules.
 *
 * A conflict means the original upstream plugin is still installed and active
 * alongside the module that vendors its code — both would register the same
 * hooks/classes, causing unpredictable behaviour.
 *
 * When a conflict is found:
 *  - The module is blocked from booting (get_active_conflicts() returns it).
 *  - An admin notice is shown to users with manage_options, with a
 *    one-click deactivation link for each conflicting plugin.
 */
class Bizen_Conflict_Checker {

	/** @var array<string, array<array{file: string, name: string}>> module_id → active conflicts */
	private array $active = [];

	/**
	 * Scan enabled modules for active conflicting plugins.
	 *
	 * @param Bizen_Module[] $modules
	 * @param callable(string): bool $is_enabled  fn(module_id) => bool
	 */
	public function scan( array $modules, callable $is_enabled ): void {
		include_once ABSPATH . 'wp-admin/includes/plugin.php';

		foreach ( $modules as $id => $module ) {
			if ( ! $is_enabled( $id ) ) {
				continue;
			}
			foreach ( $module->get_conflicts() as $conflict ) {
				if ( is_plugin_active( $conflict['file'] ) ) {
					$this->active[ $id ][] = $conflict;
				}
			}
		}

		if ( ! empty( $this->active ) ) {
			add_action( 'admin_notices', [ $this, 'render_notices' ] );
		}
	}

	/** Returns true if the given module has at least one active conflict. */
	public function has_conflict( string $module_id ): bool {
		return ! empty( $this->active[ $module_id ] );
	}

	/** Returns all active conflicts keyed by module_id. */
	public function get_active(): array {
		return $this->active;
	}

	/** Renders one admin notice per conflicting plugin. */
	public function render_notices(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		// Collect all unique conflicting plugin files across all modules.
		$seen = [];
		foreach ( $this->active as $module_id => $conflicts ) {
			foreach ( $conflicts as $conflict ) {
				$file = $conflict['file'];
				if ( isset( $seen[ $file ] ) ) {
					continue;
				}
				$seen[ $file ] = true;

				$deactivate_url = wp_nonce_url(
					admin_url( 'plugins.php?action=deactivate&plugin=' . urlencode( $file ) ),
					'deactivate-plugin_' . $file
				);

				echo '<div class="notice notice-error"><p>' .
					wp_kses(
						sprintf(
							/* translators: 1: plugin name, 2: deactivation URL */
							__( '<strong>Bizen Toolkit:</strong> The plugin <strong>%1$s</strong> conflicts with an active module. Please deactivate it to avoid duplicated functionality. <a href="%2$s">Deactivate %1$s</a>', 'bizen-toolkit' ),
							esc_html( $conflict['name'] ),
							esc_url( $deactivate_url )
						),
						[
							'strong' => [],
							'a'      => [ 'href' => [] ],
						]
					) .
					'</p></div>';
			}
		}
	}
}
