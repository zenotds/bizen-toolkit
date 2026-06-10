<?php
defined( 'ABSPATH' ) || exit;

class Bizen_Admin_Panel {

	private Bizen_Module_Loader $loader;

	public function __construct( Bizen_Module_Loader $loader ) {
		$this->loader = $loader;
		add_action( 'admin_menu', [ $this, 'register_menu' ] );
		add_action( 'admin_init', [ $this, 'handle_post' ] );
	}

	public function register_menu(): void {
		$icon_path = BIZEN_TOOLKIT_PATH . 'assets/icon.svg';
		$icon      = file_exists( $icon_path )
			? 'data:image/svg+xml;base64,' . base64_encode( (string) file_get_contents( $icon_path ) )
			: 'dashicons-hammer';

		add_menu_page(
			__( 'Bizen Toolkit', 'bizen-toolkit' ),
			__( 'Bizen Toolkit', 'bizen-toolkit' ),
			'manage_options',
			'bizen-toolkit',
			[ $this, 'render_page' ],
			$icon,
			66
		);
	}

	public function handle_post(): void {
		if (
			! isset( $_POST['bizen_toolkit_nonce'] ) ||
			! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['bizen_toolkit_nonce'] ) ), 'bizen_toolkit_save' )
		) {
			return;
		}

		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		// Handle "Check now" button
		if ( isset( $_POST['bizen_check_now'] ) ) {
			Bizen_Version_Monitor::trigger_check_now();
			wp_safe_redirect( add_query_arg( [ 'page' => 'bizen-toolkit', 'tab' => 'tools', 'checked' => '1' ], admin_url( 'admin.php' ) ) );
			exit;
		}

		// Save module states
		$states  = [];
		$modules = $this->loader->get_modules();
		foreach ( $modules as $id => $module ) {
			$states[ $id ] = isset( $_POST[ 'module_' . $id ] ) && $_POST[ 'module_' . $id ] === '1';
		}
		$this->loader->save_enabled( $states );

		wp_safe_redirect( add_query_arg( [ 'page' => 'bizen-toolkit', 'tab' => 'modules', 'saved' => '1' ], admin_url( 'admin.php' ) ) );
		exit;
	}

	public function render_page(): void {
		$modules    = $this->loader->get_modules();
		$monitor    = Bizen_Version_Monitor::get_results();
		$next_check = wp_next_scheduled( Bizen_Version_Monitor::CRON_HOOK );
		$conflicts  = $this->loader->get_conflict_checker()->get_active();
		$active_tab = ( isset( $_GET['tab'] ) && $_GET['tab'] === 'tools' ) ? 'tools' : 'modules';
		?>
		<div class="wrap">
			<h1>
				<?php esc_html_e( 'Bizen Toolkit', 'bizen-toolkit' ); ?>
				<span style="font-size:13px;font-weight:400;color:#aaa;margin-left:8px;">
					v<?php echo esc_html( BIZEN_TOOLKIT_VERSION ); ?>
				</span>
			</h1>

			<?php if ( isset( $_GET['saved'] ) ) : ?>
				<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Settings saved.', 'bizen-toolkit' ); ?></p></div>
			<?php endif; ?>
			<?php if ( isset( $_GET['checked'] ) ) : ?>
				<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Version check completed.', 'bizen-toolkit' ); ?></p></div>
			<?php endif; ?>

			<nav class="nav-tab-wrapper" style="margin-bottom:20px;">
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=bizen-toolkit&tab=modules' ) ); ?>"
				   class="nav-tab<?php echo $active_tab === 'modules' ? ' nav-tab-active' : ''; ?>">
					<?php esc_html_e( 'Moduli', 'bizen-toolkit' ); ?>
				</a>
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=bizen-toolkit&tab=tools' ) ); ?>"
				   class="nav-tab<?php echo $active_tab === 'tools' ? ' nav-tab-active' : ''; ?>">
					<?php esc_html_e( 'Tools', 'bizen-toolkit' ); ?>
				</a>
			</nav>

			<form method="post" action="">
				<?php wp_nonce_field( 'bizen_toolkit_save', 'bizen_toolkit_nonce' ); ?>

				<?php if ( $active_tab === 'modules' ) : ?>

					<p style="color:#757575;"><?php esc_html_e( 'Enable or disable individual modules. Modules with unmet dependencies are shown but cannot be booted.', 'bizen-toolkit' ); ?></p>

					<table class="widefat striped" style="max-width:900px;">
						<thead>
							<tr>
								<th style="width:40px;"><?php esc_html_e( 'Active', 'bizen-toolkit' ); ?></th>
								<th><?php esc_html_e( 'Module', 'bizen-toolkit' ); ?></th>
								<th style="width:160px;"><?php esc_html_e( 'Dependencies', 'bizen-toolkit' ); ?></th>
								<th style="width:180px;"><?php esc_html_e( 'Upstream version', 'bizen-toolkit' ); ?></th>
							</tr>
						</thead>
						<tbody>
						<?php if ( empty( $modules ) ) : ?>
							<tr><td colspan="4"><em><?php esc_html_e( 'No modules found.', 'bizen-toolkit' ); ?></em></td></tr>
						<?php else : ?>
							<?php foreach ( $modules as $id => $module ) : ?>
								<?php
								$enabled       = $this->loader->is_enabled( $id );
								$deps_met      = $module->dependencies_met();
								$missing       = $module->get_missing_dependencies();
								$mon           = $monitor[ $id ] ?? null;
								$has_conflict  = ! empty( $conflicts[ $id ] );
								$row_conflicts = $conflicts[ $id ] ?? [];
								?>
								<tr<?php echo $has_conflict ? ' style="background:#fff8f8;"' : ''; ?>>
									<td style="text-align:center;">
										<input
											type="checkbox"
											name="module_<?php echo esc_attr( $id ); ?>"
											value="1"
											<?php checked( $enabled ); ?>
										>
									</td>
									<td>
										<strong><?php echo esc_html( $module->get_name() ); ?></strong>
										<?php if ( $has_conflict ) : ?>
											<span style="background:#dc3232;color:#fff;font-size:10px;padding:1px 5px;border-radius:3px;margin-left:6px;vertical-align:middle;">
												<?php esc_html_e( 'CONFLICT', 'bizen-toolkit' ); ?>
											</span>
										<?php endif; ?>
										<?php if ( $module->get_source_slug() ) : ?>
											<a href="https://wordpress.org/plugins/<?php echo esc_attr( $module->get_source_slug() ); ?>/"
											   target="_blank"
											   title="<?php echo esc_attr( sprintf( __( 'Based on %1$s v%2$s (WP.org)', 'bizen-toolkit' ), $module->get_source_slug(), $module->get_source_version() ?? '?' ) ); ?>"
											   style="text-decoration:none;margin-left:5px;color:#aaa;font-size:12px;vertical-align:middle;">&#9432;</a>
										<?php elseif ( $module->get_source_repo() ) : ?>
											<a href="https://github.com/<?php echo esc_attr( $module->get_source_repo() ); ?>"
											   target="_blank"
											   title="<?php echo esc_attr( sprintf( __( 'Based on %1$s v%2$s (GitHub)', 'bizen-toolkit' ), $module->get_source_repo(), $module->get_source_version() ?? '?' ) ); ?>"
											   style="text-decoration:none;margin-left:5px;color:#aaa;font-size:12px;vertical-align:middle;">&#9432;</a>
										<?php endif; ?>
										<br>
										<span style="color:#757575;font-size:12px;"><?php echo esc_html( $module->get_description() ); ?></span>
										<?php foreach ( $row_conflicts as $conflict ) : ?>
											<?php
											$deactivate_url = wp_nonce_url(
												admin_url( 'plugins.php?action=deactivate&plugin=' . urlencode( $conflict['file'] ) ),
												'deactivate-plugin_' . $conflict['file']
											);
											?>
											<br>
											<span style="color:#dc3232;font-size:11px;">
												&#9888;
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
											</span>
										<?php endforeach; ?>
									</td>
									<td>
										<?php if ( empty( $module->get_dependencies() ) ) : ?>
										<?php elseif ( $deps_met ) : ?>
											<span style="color:#46b450;">&#10003; <?php esc_html_e( 'Loaded', 'bizen-toolkit' ); ?></span>
										<?php else : ?>
											<span style="color:#dc3232;" title="<?php echo esc_attr( implode( ', ', $missing ) ); ?>">
												&#9888; <?php esc_html_e( 'Missing', 'bizen-toolkit' ); ?>
											</span>
											<br>
											<span style="color:#aaa;font-size:11px;"><?php echo esc_html( implode( ', ', $missing ) ); ?></span>
										<?php endif; ?>
									</td>
									<td>
										<?php if ( ! $mon && ! $module->get_source_slug() && ! $module->get_source_repo() ) : ?>
											<span style="color:#aaa;"><?php esc_html_e( 'Core', 'bizen-toolkit' ); ?></span>
										<?php elseif ( ! $mon ) : ?>
											<span style="color:#aaa;">—</span>
										<?php elseif ( $mon['has_update'] ) : ?>
											<span style="color:#dc3232;" title="<?php printf( esc_attr__( 'Upstream: %s — Vendored: %s', 'bizen-toolkit' ), $mon['upstream_version'], $mon['vendored_version'] ); ?>">
												&#8593; <?php echo esc_html( $mon['upstream_version'] ); ?>
											</span>
											<span style="color:#aaa;font-size:11px;display:block;"><?php esc_html_e( 'Update available', 'bizen-toolkit' ); ?></span>
										<?php else : ?>
											<span style="color:#46b450;">&#10003; <?php echo esc_html( $mon['vendored_version'] ); ?></span>
											<span style="color:#aaa;font-size:11px;display:block;"><?php esc_html_e( 'Up to date', 'bizen-toolkit' ); ?></span>
										<?php endif; ?>
									</td>
								</tr>
							<?php endforeach; ?>
						<?php endif; ?>
						</tbody>
					</table>

					<p class="submit">
						<input type="submit" class="button-primary" value="<?php esc_attr_e( 'Save settings', 'bizen-toolkit' ); ?>">
					</p>

				<?php else : ?>

					<h2 style="margin-top:0;"><?php esc_html_e( 'Version Monitor', 'bizen-toolkit' ); ?></h2>
					<p style="color:#757575;">
						<?php esc_html_e( 'Weekly background check against the WordPress.org plugin API. When an upstream version is newer than the vendored one, a flag appears in the Modules table.', 'bizen-toolkit' ); ?>
					</p>
					<p>
						<?php if ( $next_check ) : ?>
							<?php printf(
								/* translators: %s: human-readable time difference */
								esc_html__( 'Next scheduled check: %s', 'bizen-toolkit' ),
								'<strong>' . esc_html( human_time_diff( time(), $next_check ) . ' ' . __( 'from now', 'bizen-toolkit' ) ) . '</strong>'
							); ?>
						<?php else : ?>
							<span style="color:#dc3232;"><?php esc_html_e( 'Cron not scheduled — deactivate and reactivate the plugin to fix this.', 'bizen-toolkit' ); ?></span>
						<?php endif; ?>
					</p>

					<?php if ( $monitor ) : ?>
						<?php $last = max( array_column( $monitor, 'last_checked' ) ); ?>
						<p style="color:#757575;font-size:12px;">
							<?php printf(
								esc_html__( 'Last checked: %s', 'bizen-toolkit' ),
								esc_html( wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $last ) )
							); ?>
						</p>
					<?php endif; ?>

					<input type="submit" name="bizen_check_now" class="button" value="<?php esc_attr_e( 'Check now', 'bizen-toolkit' ); ?>">

				<?php endif; ?>

			</form>

			<p style="margin-top:40px;padding-top:16px;border-top:1px solid #dcdcde;color:#aaa;font-size:12px;display:flex;align-items:center;gap:6px;">
				<?php esc_html_e( 'Made with love by', 'bizen-toolkit' ); ?>
				<img src="<?php echo esc_url( BIZEN_TOOLKIT_URL . 'assets/logo.svg' ); ?>"
				     alt="Bizen"
				     style="height:16px;width:auto;display:block;opacity:0.5;">
			</p>
		</div>
		<?php
	}
}
