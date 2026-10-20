<?php
defined( 'ABSPATH' ) || exit;

/**
 * The Bizen Toolkit admin page: categories in a sidebar, modules in a list.
 *
 * Switching category, filtering and searching happen in the browser
 * (assets/admin.js) and the module switches save one by one over AJAX, so the
 * page never reloads. Without JavaScript every view is shown and the form still
 * saves with its submit button.
 */
class Bizen_Admin_Panel {

	private const SLUG  = 'bizen-toolkit';
	private const NONCE = 'bizen_toolkit_save';

	private Bizen_Module_Loader $loader;

	private string $hook = '';

	/** @var array<string, array>|null Installed plugins, by file. */
	private ?array $plugins = null;

	public function __construct( Bizen_Module_Loader $loader ) {
		$this->loader = $loader;
		add_action( 'admin_menu', [ $this, 'register_menu' ] );
		add_action( 'admin_init', [ $this, 'handle_post' ] );
		add_action( 'admin_enqueue_scripts', [ $this, 'enqueue' ] );
		add_action( 'wp_ajax_bizen_toolkit_toggle', [ $this, 'ajax_toggle' ] );
		add_action( 'wp_ajax_bizen_toolkit_check', [ $this, 'ajax_check' ] );
	}

	public function register_menu(): void {
		$icon_path = BIZEN_TOOLKIT_PATH . 'assets/menu-icon.svg';
		$icon      = file_exists( $icon_path )
			? 'data:image/svg+xml;base64,' . base64_encode( (string) file_get_contents( $icon_path ) )
			: 'dashicons-hammer';

		$this->hook = (string) add_menu_page(
			__( 'Bizen Toolkit', 'bizen-toolkit' ),
			__( 'Bizen Toolkit', 'bizen-toolkit' ),
			'manage_options',
			self::SLUG,
			[ $this, 'render_page' ],
			$icon,
			66
		);
	}

	public function enqueue( string $hook ): void {
		if ( $hook !== $this->hook ) {
			return;
		}

		wp_enqueue_style( 'bizen-toolkit-admin', BIZEN_TOOLKIT_URL . 'assets/admin.css', [], BIZEN_TOOLKIT_VERSION );
		wp_enqueue_script( 'bizen-toolkit-admin', BIZEN_TOOLKIT_URL . 'assets/admin.js', [], BIZEN_TOOLKIT_VERSION, true );
		wp_localize_script( 'bizen-toolkit-admin', 'bizenToolkit', [
			'ajaxUrl' => admin_url( 'admin-ajax.php' ),
			'nonce'   => wp_create_nonce( self::NONCE ),
			'i18n'    => [
				/* translators: %s: module name */
				'enabled'  => __( '%s enabled. It takes effect on the next page load.', 'bizen-toolkit' ),
				/* translators: %s: module name */
				'disabled' => __( '%s disabled. It takes effect on the next page load.', 'bizen-toolkit' ),
				'error'    => __( 'Could not save. Reload the page and try again.', 'bizen-toolkit' ),
				'checking' => __( 'Checking upstream versions…', 'bizen-toolkit' ),
				'checked'  => __( 'Version check completed.', 'bizen-toolkit' ),
			],
		] );
	}

	private function verify_request(): bool {
		return current_user_can( 'manage_options' ) && check_ajax_referer( self::NONCE, 'nonce', false );
	}

	public function ajax_toggle(): void {
		if ( ! $this->verify_request() ) {
			wp_send_json_error( null, 403 );
		}

		$id = sanitize_key( wp_unslash( $_POST['module'] ?? '' ) );
		if ( ! isset( $this->loader->get_modules()[ $id ] ) ) {
			wp_send_json_error( null, 400 );
		}

		$enabled = isset( $_POST['enabled'] ) && '1' === $_POST['enabled'];
		$this->loader->set_enabled( $id, $enabled );

		wp_send_json_success( [ 'enabled' => $enabled ] );
	}

	public function ajax_check(): void {
		if ( ! $this->verify_request() ) {
			wp_send_json_error( null, 403 );
		}

		Bizen_Version_Monitor::trigger_check_now();

		ob_start();
		$this->render_updates();
		wp_send_json_success( [ 'html' => ob_get_clean() ] );
	}

	/** Form fallback for browsers without JavaScript. */
	public function handle_post(): void {
		if (
			! isset( $_POST['bizen_toolkit_nonce'] ) ||
			! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['bizen_toolkit_nonce'] ) ), self::NONCE )
		) {
			return;
		}

		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		if ( isset( $_POST['bizen_check_now'] ) ) {
			Bizen_Version_Monitor::trigger_check_now();
			wp_safe_redirect( add_query_arg( [ 'page' => self::SLUG, 'checked' => '1' ], admin_url( 'admin.php' ) ) . '#updates' );
			exit;
		}

		$states = [];
		foreach ( $this->loader->get_modules() as $id => $module ) {
			$states[ $id ] = isset( $_POST[ 'module_' . $id ] ) && $_POST[ 'module_' . $id ] === '1';
		}
		$this->loader->save_enabled( $states );

		wp_safe_redirect( add_query_arg( [ 'page' => self::SLUG, 'saved' => '1' ], admin_url( 'admin.php' ) ) );
		exit;
	}

	/**
	 * Categories, in sidebar order. Each is also the URL hash that opens it.
	 *
	 * @return array<string, string> category => label
	 */
	private function categories(): array {
		return [
			'form'         => __( 'Form', 'bizen-toolkit' ),
			'media'        => __( 'Media', 'bizen-toolkit' ),
			'multilingual' => __( 'Multilingual', 'bizen-toolkit' ),
			'admin'        => __( 'Admin', 'bizen-toolkit' ),
			'other'        => __( 'Other', 'bizen-toolkit' ),
		];
	}

	/**
	 * Modules bucketed by category, empty categories dropped. An unknown category
	 * falls into "other" rather than hiding the module.
	 *
	 * @param  Bizen_Module[] $modules
	 * @return array<string, Bizen_Module[]>
	 */
	private function group_modules( array $modules ): array {
		$groups = array_fill_keys( array_keys( $this->categories() ), [] );

		foreach ( $modules as $id => $module ) {
			$category = $module->get_category();
			$groups[ isset( $groups[ $category ] ) ? $category : 'other' ][ $id ] = $module;
		}

		return array_filter( $groups );
	}

	private function plugin_name( string $file ): string {
		if ( null === $this->plugins ) {
			include_once ABSPATH . 'wp-admin/includes/plugin.php';
			$this->plugins = get_plugins();
		}

		return $this->plugins[ $file ]['Name'] ?? dirname( $file );
	}

	/**
	 * Active plugins the module conflicts with, whether or not the module is on,
	 * so the warning shows before it is switched on.
	 *
	 * @return array<array{file: string, name: string}>
	 */
	private function active_conflicts( Bizen_Module $module ): array {
		include_once ABSPATH . 'wp-admin/includes/plugin.php';

		return array_values( array_filter( $module->get_conflicts(), fn( $conflict ) => is_plugin_active( $conflict['file'] ) ) );
	}

	public function render_page(): void {
		$modules = $this->loader->get_modules();
		$groups  = $this->group_modules( $modules );
		$labels  = $this->categories();
		$monitor = Bizen_Version_Monitor::get_results();
		$updates = count( array_filter( $monitor, fn( $row ) => ! empty( $row['has_update'] ) ) );

		$active = 0;
		$issues = 0;
		foreach ( $modules as $id => $module ) {
			if ( $this->loader->is_enabled( $id ) ) {
				$active++;
				if ( ! $module->dependencies_met() || $this->active_conflicts( $module ) ) {
					$issues++;
				}
			}
		}
		?>
		<div class="wrap bzn">
			<div class="bzn-head">
				<h1>
					<?php esc_html_e( 'Bizen Toolkit', 'bizen-toolkit' ); ?>
					<span class="bzn-version">v<?php echo esc_html( BIZEN_TOOLKIT_VERSION ); ?></span>
				</h1>
				<p class="bzn-status" role="status" aria-live="polite"></p>
				<label class="bzn-search">
					<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><circle cx="11" cy="11" r="7"/><path d="m20 20-3.5-3.5"/></svg>
					<span class="screen-reader-text"><?php esc_html_e( 'Search modules', 'bizen-toolkit' ); ?></span>
					<input type="search" class="bzn-search-input" placeholder="<?php esc_attr_e( 'Search modules…', 'bizen-toolkit' ); ?>">
				</label>
			</div>

			<?php if ( isset( $_GET['saved'] ) ) : ?>
				<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Settings saved.', 'bizen-toolkit' ); ?></p></div>
			<?php endif; ?>
			<?php if ( isset( $_GET['checked'] ) ) : ?>
				<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Version check completed.', 'bizen-toolkit' ); ?></p></div>
			<?php endif; ?>

			<form method="post" action="" class="bzn-layout">
				<?php wp_nonce_field( self::NONCE, 'bizen_toolkit_nonce' ); ?>

				<nav class="bzn-nav" aria-label="<?php esc_attr_e( 'Categories', 'bizen-toolkit' ); ?>">
					<a href="#all" class="bzn-nav-item" data-view="all">
						<span class="bzn-nav-label"><?php esc_html_e( 'All modules', 'bizen-toolkit' ); ?></span>
						<span class="bzn-count"><?php echo (int) count( $modules ); ?></span>
					</a>
					<?php foreach ( $groups as $category => $group ) : ?>
						<a href="#<?php echo esc_attr( $category ); ?>" class="bzn-nav-item" data-view="<?php echo esc_attr( $category ); ?>">
							<span class="bzn-nav-label"><?php echo esc_html( $labels[ $category ] ); ?></span>
							<span class="bzn-count"><?php echo (int) count( $group ); ?></span>
						</a>
					<?php endforeach; ?>
					<hr>
					<a href="#updates" class="bzn-nav-item" data-view="updates">
						<span class="bzn-nav-label"><?php esc_html_e( 'Updates', 'bizen-toolkit' ); ?></span>
						<?php if ( $updates ) : ?>
							<span class="bzn-count bzn-count-alert"><?php echo (int) $updates; ?></span>
						<?php endif; ?>
					</a>
					<p class="bzn-summary">
						<span>
							<?php esc_html_e( 'Active', 'bizen-toolkit' ); ?>:
							<strong class="bzn-active-count"><?php echo (int) $active; ?></strong> / <?php echo (int) count( $modules ); ?>
						</span>
						<span class="bzn-issues"<?php echo $issues ? '' : ' hidden'; ?>>
							<?php esc_html_e( 'Needs attention', 'bizen-toolkit' ); ?>:
							<strong class="bzn-issue-count"><?php echo (int) $issues; ?></strong>
						</span>
					</p>
				</nav>

				<div class="bzn-main">
					<section class="bzn-view bzn-view-modules">
						<div class="bzn-toolbar">
							<h2 class="bzn-title"><?php esc_html_e( 'All modules', 'bizen-toolkit' ); ?></h2>
							<div class="bzn-filters" role="group" aria-label="<?php esc_attr_e( 'Filter by status', 'bizen-toolkit' ); ?>">
								<button type="button" data-filter="all" aria-pressed="true"><?php esc_html_e( 'All', 'bizen-toolkit' ); ?></button>
								<button type="button" data-filter="on" aria-pressed="false"><?php esc_html_e( 'Active', 'bizen-toolkit' ); ?></button>
								<button type="button" data-filter="off" aria-pressed="false"><?php esc_html_e( 'Inactive', 'bizen-toolkit' ); ?></button>
								<button type="button" data-filter="issue" aria-pressed="false"><?php esc_html_e( 'Needs attention', 'bizen-toolkit' ); ?></button>
							</div>
						</div>

						<?php if ( empty( $modules ) ) : ?>
							<p class="bzn-empty"><?php esc_html_e( 'No modules found.', 'bizen-toolkit' ); ?></p>
						<?php endif; ?>

						<?php foreach ( $groups as $category => $group ) : ?>
							<div class="bzn-group" data-category="<?php echo esc_attr( $category ); ?>">
								<h3 class="bzn-group-title"><?php echo esc_html( $labels[ $category ] ); ?></h3>
								<?php foreach ( $group as $id => $module ) : ?>
									<?php $this->render_module( $id, $module, $monitor[ $id ] ?? null ); ?>
								<?php endforeach; ?>
							</div>
						<?php endforeach; ?>

						<p class="bzn-empty bzn-no-match" hidden><?php esc_html_e( 'No modules match this filter.', 'bizen-toolkit' ); ?></p>

						<p class="submit bzn-fallback">
							<input type="submit" class="button-primary" value="<?php esc_attr_e( 'Save settings', 'bizen-toolkit' ); ?>">
						</p>
					</section>

					<section class="bzn-view bzn-view-updates" id="bzn-updates">
						<?php $this->render_updates(); ?>
					</section>
				</div>
			</form>

			<p class="bzn-footer">
				<?php esc_html_e( 'Made with love by', 'bizen-toolkit' ); ?>
				<img src="<?php echo esc_url( BIZEN_TOOLKIT_URL . 'assets/logo.svg' ); ?>" alt="Bizen">
			</p>
		</div>
		<?php
	}

	private function render_module( string $id, Bizen_Module $module, ?array $mon ): void {
		$enabled   = $this->loader->is_enabled( $id );
		$missing   = $module->get_missing_dependencies();
		$conflicts = $this->active_conflicts( $module );
		$has_issue = $missing || $conflicts;
		$slug      = $module->get_source_slug();
		$repo      = $module->get_source_repo();
		$name      = $module->get_name();
		$switch_id = 'bzn-module-' . $id;

		ob_start();
		$module->render_settings();
		$settings = trim( (string) ob_get_clean() );

		$search = implode( ' ', [ $name, $module->get_summary(), $module->get_description(), $id ] );
		?>
		<article class="bzn-module"
			data-id="<?php echo esc_attr( $id ); ?>"
			data-enabled="<?php echo $enabled ? '1' : '0'; ?>"
			data-issue="<?php echo $has_issue ? '1' : '0'; ?>"
			data-search="<?php echo esc_attr( function_exists( 'mb_strtolower' ) ? mb_strtolower( $search ) : strtolower( $search ) ); ?>">
			<div class="bzn-module-head">
				<span class="bzn-switch">
					<input type="checkbox" role="switch" id="<?php echo esc_attr( $switch_id ); ?>"
						name="module_<?php echo esc_attr( $id ); ?>" value="1"
						data-module="<?php echo esc_attr( $id ); ?>"
						<?php checked( $enabled ); ?>>
					<span class="bzn-switch-track" aria-hidden="true"></span>
				</span>
				<div class="bzn-module-body">
					<div class="bzn-module-title">
						<label for="<?php echo esc_attr( $switch_id ); ?>"><?php echo esc_html( $name ); ?></label>
						<?php if ( $slug ) : ?>
							<span class="bzn-chip"><?php echo esc_html( 'WP.org ' . ( $module->get_source_version() ?? '' ) ); ?></span>
						<?php elseif ( $repo ) : ?>
							<span class="bzn-chip"><?php echo esc_html( 'GitHub ' . ( $module->get_source_version() ?? '' ) ); ?></span>
						<?php else : ?>
							<span class="bzn-chip"><?php esc_html_e( 'Core', 'bizen-toolkit' ); ?></span>
						<?php endif; ?>
						<?php if ( $conflicts ) : ?>
							<span class="bzn-chip bzn-chip-error"><?php esc_html_e( 'Conflict', 'bizen-toolkit' ); ?></span>
						<?php endif; ?>
						<?php if ( $missing ) : ?>
							<span class="bzn-chip bzn-chip-error">
								<?php
								/* translators: %s: comma-separated plugin names */
								echo esc_html( sprintf( __( 'Missing: %s', 'bizen-toolkit' ), implode( ', ', array_map( [ $this, 'plugin_name' ], $missing ) ) ) );
								?>
							</span>
						<?php endif; ?>
						<?php if ( $mon && ! empty( $mon['has_update'] ) ) : ?>
							<span class="bzn-chip bzn-chip-info">
								<?php
								/* translators: %s: upstream version */
								echo esc_html( sprintf( __( 'Upstream %s', 'bizen-toolkit' ), $mon['upstream_version'] ) );
								?>
							</span>
						<?php endif; ?>
					</div>
					<p class="bzn-module-summary"><?php echo esc_html( $module->get_summary() ); ?></p>

					<details class="bzn-details"<?php echo $conflicts ? ' open' : ''; ?>>
						<summary><?php esc_html_e( 'Details', 'bizen-toolkit' ); ?></summary>
						<div class="bzn-details-body">
							<?php if ( $module->get_description() !== $module->get_summary() ) : ?>
								<p><?php echo esc_html( $module->get_description() ); ?></p>
							<?php endif; ?>

							<?php foreach ( $conflicts as $conflict ) : ?>
								<?php
								$deactivate_url = wp_nonce_url(
									admin_url( 'plugins.php?action=deactivate&plugin=' . urlencode( $conflict['file'] ) ),
									'deactivate-plugin_' . $conflict['file']
								);
								?>
								<p class="bzn-error">
									<?php
									echo wp_kses(
										sprintf(
											/* translators: 1: plugin name, 2: deactivation URL */
											__( 'Conflicts with active plugin: <strong>%1$s</strong>. <a href="%2$s">Deactivate it</a> to enable this module.', 'bizen-toolkit' ),
											esc_html( $conflict['name'] ),
											esc_url( $deactivate_url )
										),
										[ 'strong' => [], 'a' => [ 'href' => [] ] ]
									);
									?>
								</p>
							<?php endforeach; ?>

							<dl class="bzn-meta">
								<dt><?php esc_html_e( 'Dependencies', 'bizen-toolkit' ); ?></dt>
								<dd>
									<?php if ( ! $module->get_dependencies() ) : ?>
										<span class="bzn-muted"><?php esc_html_e( 'None', 'bizen-toolkit' ); ?></span>
									<?php endif; ?>
									<?php foreach ( $module->get_dependencies() as $file ) : ?>
										<?php $ok = ! in_array( $file, $missing, true ); ?>
										<span class="bzn-chip <?php echo $ok ? 'bzn-chip-ok' : 'bzn-chip-error'; ?>">
											<?php echo $ok ? '&#10003;' : '&#10005;'; ?>
											<?php echo esc_html( $this->plugin_name( $file ) ); ?>
										</span>
									<?php endforeach; ?>
								</dd>
								<?php if ( $slug || $repo ) : ?>
									<dt><?php esc_html_e( 'Based on', 'bizen-toolkit' ); ?></dt>
									<dd>
										<a href="<?php echo esc_url( $slug ? 'https://wordpress.org/plugins/' . $slug . '/' : 'https://github.com/' . $repo ); ?>" target="_blank" rel="noopener"><?php echo esc_html( $slug ?: $repo ); ?></a>
										v<?php echo esc_html( $module->get_source_version() ?? '?' ); ?>
										<?php if ( $mon ) : ?>
											&middot;
											<?php if ( $mon['has_update'] ) : ?>
												<?php
												/* translators: %s: upstream version */
												echo esc_html( sprintf( __( '%s available upstream', 'bizen-toolkit' ), $mon['upstream_version'] ) );
												?>
											<?php else : ?>
												<?php esc_html_e( 'Up to date', 'bizen-toolkit' ); ?>
											<?php endif; ?>
										<?php endif; ?>
									</dd>
								<?php endif; ?>
							</dl>

							<?php if ( $settings ) : ?>
								<div class="bzn-settings"><?php echo $settings; // phpcs:ignore WordPress.Security.EscapeOutput -- module-rendered markup ?></div>
							<?php endif; ?>
						</div>
					</details>
				</div>
			</div>
		</article>
		<?php
	}

	/** The Updates view; also returned on its own by the "Check now" AJAX call. */
	private function render_updates(): void {
		$monitor    = Bizen_Version_Monitor::get_results();
		$next_check = wp_next_scheduled( Bizen_Version_Monitor::CRON_HOOK );
		$tracked    = array_filter( $this->loader->get_modules(), fn( $module ) => $module->get_source_slug() || $module->get_source_repo() );
		?>
		<div class="bzn-toolbar">
			<h2 class="bzn-title"><?php esc_html_e( 'Updates', 'bizen-toolkit' ); ?></h2>
		</div>
		<p class="bzn-muted"><?php esc_html_e( 'Weekly background check of the plugins some modules are based on. When the original has a newer version, the module is flagged in the list.', 'bizen-toolkit' ); ?></p>

		<div class="bzn-card bzn-monitor">
			<div>
				<span class="bzn-muted"><?php esc_html_e( 'Next check', 'bizen-toolkit' ); ?></span>
				<?php if ( $next_check ) : ?>
					<strong>
						<?php
						/* translators: %s: human-readable time difference, e.g. "3 days" */
						echo esc_html( sprintf( __( 'in %s', 'bizen-toolkit' ), human_time_diff( time(), $next_check ) ) );
						?>
					</strong>
				<?php else : ?>
					<strong class="bzn-error"><?php esc_html_e( 'Cron not scheduled — deactivate and reactivate the plugin to fix this.', 'bizen-toolkit' ); ?></strong>
				<?php endif; ?>
			</div>
			<?php if ( $monitor ) : ?>
				<div>
					<span class="bzn-muted"><?php esc_html_e( 'Last check', 'bizen-toolkit' ); ?></span>
					<strong><?php echo esc_html( wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), max( array_column( $monitor, 'last_checked' ) ) ) ); ?></strong>
				</div>
			<?php endif; ?>
			<input type="submit" name="bizen_check_now" class="button bzn-check-now" value="<?php esc_attr_e( 'Check now', 'bizen-toolkit' ); ?>">
		</div>

		<?php if ( $tracked ) : ?>
			<div class="bzn-table-wrap">
				<table class="widefat striped">
					<thead>
						<tr>
							<th><?php esc_html_e( 'Module', 'bizen-toolkit' ); ?></th>
							<th><?php esc_html_e( 'Based on', 'bizen-toolkit' ); ?></th>
							<th><?php esc_html_e( 'Bundled', 'bizen-toolkit' ); ?></th>
							<th><?php esc_html_e( 'Upstream version', 'bizen-toolkit' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $tracked as $id => $module ) : ?>
							<?php
							$mon  = $monitor[ $id ] ?? null;
							$slug = $module->get_source_slug();
							$repo = $module->get_source_repo();
							?>
							<tr>
								<td><strong><?php echo esc_html( $module->get_name() ); ?></strong></td>
								<td><a href="<?php echo esc_url( $slug ? 'https://wordpress.org/plugins/' . $slug . '/' : 'https://github.com/' . $repo ); ?>" target="_blank" rel="noopener"><?php echo esc_html( $slug ?: $repo ); ?></a></td>
								<td><?php echo esc_html( $module->get_source_version() ?? '—' ); ?></td>
								<td>
									<?php if ( ! $mon ) : ?>
										<span class="bzn-muted">—</span>
									<?php elseif ( $mon['has_update'] ) : ?>
										<span class="bzn-error">&#8593; <?php echo esc_html( $mon['upstream_version'] ); ?> &middot; <?php esc_html_e( 'Update available', 'bizen-toolkit' ); ?></span>
									<?php else : ?>
										<span class="bzn-ok">&#10003; <?php esc_html_e( 'Up to date', 'bizen-toolkit' ); ?></span>
									<?php endif; ?>
								</td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			</div>
		<?php endif; ?>
		<?php
	}
}
