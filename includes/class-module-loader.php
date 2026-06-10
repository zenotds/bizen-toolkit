<?php
defined( 'ABSPATH' ) || exit;

class Bizen_Module_Loader {

	/** @var Bizen_Module[] */
	private array $modules = [];

	/** @var array<string, bool> */
	private array $enabled = [];

	private Bizen_Conflict_Checker $conflict_checker;

	public function __construct() {
		$this->conflict_checker = new Bizen_Conflict_Checker();
	}

	public function get_conflict_checker(): Bizen_Conflict_Checker {
		return $this->conflict_checker;
	}

	/**
	 * Discover + boot enabled modules.
	 * Called at plugins_loaded/999 so all other plugins are available.
	 */
	public function load(): void {
		$this->enabled = get_option( 'bizen_toolkit_modules', [] );
		$this->discover();

		// Run conflict check before booting; conflicting modules are blocked.
		$this->conflict_checker->scan( $this->modules, [ $this, 'is_enabled' ] );

		foreach ( $this->modules as $id => $module ) {
			if ( ! $this->is_enabled( $id ) ) {
				continue;
			}
			if ( $this->conflict_checker->has_conflict( $id ) ) {
				continue; // notice already queued by conflict_checker
			}
			if ( ! $module->dependencies_met() ) {
				$this->show_dependency_notice( $module );
				continue;
			}
			$module->boot();
		}
	}

	/**
	 * Discover all modules by scanning the modules directory.
	 * Returns module instances without booting them.
	 * Safe to call from a cron context (no WP hooks registered).
	 */
	public function discover(): void {
		$dirs = glob( BIZEN_TOOLKIT_MODULES_PATH . '*', GLOB_ONLYDIR );
		if ( ! $dirs ) {
			return;
		}
		foreach ( $dirs as $dir ) {
			$file = $dir . '/module.php';
			if ( ! file_exists( $file ) ) {
				continue;
			}
			$module = require $file;
			if ( $module instanceof Bizen_Module ) {
				$this->modules[ $module->get_id() ] = $module;
			}
		}
	}

	/** @return Bizen_Module[] */
	public function get_modules(): array {
		return $this->modules;
	}

	public function is_enabled( string $id ): bool {
		return ! empty( $this->enabled[ $id ] );
	}

	public function save_enabled( array $states ): void {
		update_option( 'bizen_toolkit_modules', $states );
		$this->enabled = $states;
	}

	private function show_dependency_notice( Bizen_Module $module ): void {
		if ( ! is_admin() ) {
			return;
		}
		add_action( 'admin_notices', function () use ( $module ) {
			$missing = implode( ', ', $module->get_missing_dependencies() );
			printf(
				'<div class="notice notice-warning"><p><strong>Bizen Toolkit — %s:</strong> %s</p></div>',
				esc_html( $module->get_name() ),
				sprintf(
					/* translators: %s: comma-separated list of plugin files */
					esc_html__( 'Required plugin(s) not active: %s', 'bizen-toolkit' ),
					'<code>' . esc_html( $missing ) . '</code>'
				)
			);
		} );
	}
}
