<?php
/**
 * Adapted from cf7-html-email-template-extension.php v2.2.2 (GPL-2.0+)
 *
 * Changes from original:
 *  - Removed plugin header (not a standalone plugin file).
 *  - Removed register_activation_hook() (handled by main bizen-toolkit.php).
 *  - Constants are defined in module.php before this file is loaded.
 *  - get_modules() method added for public access.
 */

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'Cf7_Html_Email_Template_Extension' ) ) {

	class Cf7_Html_Email_Template_Extension {

		protected static ?self $instance = null;
		protected array $actions  = [];
		protected array $filters  = [];
		protected array $modules  = [];

		public static function instance(): self {
			if ( is_null( self::$instance ) ) {
				self::$instance = new self();
			}
			return self::$instance;
		}

		public function __construct() {
			$this->add_modules();
		}

		public function get_module( string $name ) {
			return $this->modules[ $name ] ?? false;
		}

		public function get_modules(): array {
			return $this->modules;
		}

		public function add_action( string $hook, $callback, int $priority = 10, int $accepted_args = 1 ): void {
			$this->actions = $this->add_hook( $this->actions, $hook, $callback, $priority, $accepted_args );
		}

		public function add_filter( string $hook, $callback, int $priority = 10, int $accepted_args = 1 ): void {
			$this->filters = $this->add_hook( $this->filters, $hook, $callback, $priority, $accepted_args );
		}

		private function add_hook( array $hooks, string $hook, $callback, int $priority, int $accepted_args ): array {
			$hooks[] = compact( 'hook', 'callback', 'priority', 'accepted_args' );
			return $hooks;
		}

		private function add_modules(): void {
			$path = plugin_dir_path( __FILE__ ) . 'modules' . DIRECTORY_SEPARATOR;
			if ( ! is_dir( $path ) ) {
				return;
			}

			$candidates = [];
			foreach ( scandir( $path ) as $result ) {
				if ( $result[0] === '.' || ! is_dir( $path . $result ) ) {
					continue;
				}
				$classfile = $path . $result . DIRECTORY_SEPARATOR . 'class-module-' . $result . '.php';
				if ( ! file_exists( $classfile ) ) {
					continue;
				}
				$classname    = 'CF7HETE_Module_' . str_replace( ' ', '_', ucfirst( str_replace( '-', ' ', $result ) ) );
				$module_data  = get_file_data( $classfile, [ 'dependencies' => 'Depends' ] );
				$dependencies = $module_data['dependencies']
					? explode( ',', str_replace( ' ', '', $module_data['dependencies'] ) )
					: [];

				$candidates[ $result ] = [ $classfile, $classname, $dependencies ];
			}

			$this->load_modules_by_dependence( $candidates );
		}

		private function load_modules_by_dependence( array $modules ): void {
			$deferred = [];
			foreach ( $modules as $slug => $data ) {
				if ( ! empty( $data[2] ) && ! empty( array_diff( $data[2], array_keys( $this->modules ) ) ) ) {
					$deferred[ $slug ] = $data;
					continue;
				}
				require_once $data[0];
				if ( class_exists( $data[1] ) ) {
					$this->modules[ $slug ] = new $data[1]( $this );
				}
			}

			if ( $deferred ) {
				$loaded = array_keys( $this->modules );
				foreach ( $deferred as $data ) {
					if ( empty( array_diff( $data[2], $loaded ) ) ) {
						$this->load_modules_by_dependence( $deferred );
						break;
					}
				}
			}
		}

		public function run(): void {
			foreach ( $this->modules as $slug => $module ) {
				if ( method_exists( $module, 'run' ) ) {
					$module->run();
				}
				if ( property_exists( $module, 'includes' ) ) {
					foreach ( (array) $module->includes as $class ) {
						$file = CF7HETE_PLUGIN_PATH . '/modules/' . $slug . '/includes/' . $class . '.php';
						if ( file_exists( $file ) ) {
							require_once $file;
						}
					}
				}
			}

			foreach ( $this->modules as $module ) {
				if ( method_exists( $module, 'after_run' ) ) {
					$module->after_run();
				}
			}

			foreach ( $this->modules as $module ) {
				if ( method_exists( $module, 'define_hooks' ) ) {
					$module->define_hooks();
				}
			}

			foreach ( $this->filters as $h ) {
				add_filter( $h['hook'], $h['callback'], $h['priority'], $h['accepted_args'] );
			}
			foreach ( $this->actions as $h ) {
				add_action( $h['hook'], $h['callback'], $h['priority'], $h['accepted_args'] );
			}
		}
	}
}
