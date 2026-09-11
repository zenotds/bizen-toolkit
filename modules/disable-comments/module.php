<?php
/**
 * Disable Comments module.
 *
 * Bizen module — written and maintained by Bizen (https://bizen.it).
 *
 * Turns the WordPress comment system off site-wide by overriding it at runtime:
 * no database rows are touched, so disabling the module restores the previous
 * per-post settings and any existing comments exactly as they were.
 *
 * Front end: comments and pings are reported closed for every post (old ones
 * included), existing comments never render, the comment template is emptied,
 * comment feeds and the REST comment routes are blocked, and direct POSTs to
 * wp-comments-post.php are rejected.
 *
 * Admin: the Comments menu, the Discussion settings page, the admin-bar node
 * and the dashboard comments widget are hidden, and direct URL access to those
 * screens is redirected to the dashboard.
 *
 * Comments created programmatically by other plugins (WooCommerce order notes,
 * for example) are deliberately left alone — only the public comment paths and
 * the UI are shut down.
 */

defined( 'ABSPATH' ) || exit;

return new class extends Bizen_Module {

	public function get_id(): string {
		return 'disable-comments';
	}

	public function get_name(): string {
		return __( 'Disable Comments', 'bizen-toolkit' );
	}

	public function get_description(): string {
		return __( 'Disables the comment system site-wide — closes comments on all posts and pages (new and existing), hides existing ones, and removes the Comments menu and Discussion settings from the admin.', 'bizen-toolkit' );
	}

	public function get_conflicts(): array {
		return [
			[ 'file' => 'disable-comments/disable-comments.php', 'name' => 'Disable Comments' ],
		];
	}

	public function boot(): void {
		// Report comments closed everywhere, whatever each post has stored.
		add_filter( 'comments_open', '__return_false', PHP_INT_MAX );
		add_filter( 'pings_open', '__return_false', PHP_INT_MAX );

		// New posts are created closed too, so the stored state matches the override.
		add_filter( 'pre_option_default_comment_status', [ $this, 'force_closed' ] );
		add_filter( 'pre_option_default_ping_status', [ $this, 'force_closed' ] );

		// Drop comment support from every post type that declares it.
		add_action( 'init', [ $this, 'remove_post_type_support' ], PHP_INT_MAX );

		// Close the public write paths.
		add_action( 'pre_comment_on_post', [ $this, 'block_submission' ] );
		add_filter( 'rest_endpoints', [ $this, 'remove_rest_endpoints' ] );
		add_filter( 'xmlrpc_methods', [ $this, 'remove_pingback_methods' ] );
		add_filter( 'wp_headers', [ $this, 'remove_pingback_header' ] );

		// The admin bar renders on both sides, so unhook it unconditionally.
		add_action( 'wp_before_admin_bar_render', [ $this, 'remove_admin_bar_node' ] );

		if ( is_admin() ) {
			add_action( 'admin_menu', [ $this, 'remove_admin_menus' ], PHP_INT_MAX );
			add_action( 'admin_init', [ $this, 'redirect_admin_pages' ] );
			add_action( 'wp_dashboard_setup', [ $this, 'remove_dashboard_widget' ] );
			return;
		}

		// Hide whatever was posted before the module was switched on.
		add_filter( 'comments_array', '__return_empty_array', PHP_INT_MAX );
		add_filter( 'get_comments_number', '__return_zero', PHP_INT_MAX );
		add_filter( 'comments_template', [ $this, 'empty_comments_template' ], PHP_INT_MAX );
		add_filter( 'feed_links_show_comments_feed', '__return_false' );
		add_action( 'template_redirect', [ $this, 'block_comment_feed' ], 9 );
		add_action( 'widgets_init', [ $this, 'unregister_recent_comments_widget' ], PHP_INT_MAX );
	}

	public function force_closed(): string {
		return 'closed';
	}

	public function remove_post_type_support(): void {
		foreach ( get_post_types() as $post_type ) {
			if ( post_type_supports( $post_type, 'comments' ) ) {
				remove_post_type_support( $post_type, 'comments' );
			}
			if ( post_type_supports( $post_type, 'trackbacks' ) ) {
				remove_post_type_support( $post_type, 'trackbacks' );
			}
		}
	}

	/** Rejects direct POSTs to wp-comments-post.php. */
	public function block_submission(): void {
		wp_die(
			esc_html__( 'Comments are closed.', 'bizen-toolkit' ),
			'',
			[ 'response' => 403 ]
		);
	}

	/** Unregisters the REST comment routes so comments cannot be read or created through the API. */
	public function remove_rest_endpoints( array $endpoints ): array {
		foreach ( array_keys( $endpoints ) as $route ) {
			if ( str_starts_with( $route, '/wp/v2/comments' ) ) {
				unset( $endpoints[ $route ] );
			}
		}
		return $endpoints;
	}

	public function remove_pingback_methods( array $methods ): array {
		unset(
			$methods['pingback.ping'],
			$methods['pingback.extensions.getPingbacks']
		);
		return $methods;
	}

	public function remove_pingback_header( array $headers ): array {
		unset( $headers['X-Pingback'] );
		return $headers;
	}

	/** Themes that call comments_template() unconditionally get an empty file. */
	public function empty_comments_template(): string {
		return plugin_dir_path( __FILE__ ) . 'comments-template.php';
	}

	public function block_comment_feed(): void {
		if ( is_comment_feed() ) {
			wp_die(
				esc_html__( 'Comments are closed.', 'bizen-toolkit' ),
				'',
				[ 'response' => 403 ]
			);
		}
	}

	public function unregister_recent_comments_widget(): void {
		unregister_widget( 'WP_Widget_Recent_Comments' );
	}

	public function remove_admin_bar_node(): void {
		global $wp_admin_bar;
		if ( $wp_admin_bar instanceof WP_Admin_Bar ) {
			$wp_admin_bar->remove_node( 'comments' );
		}
	}

	public function remove_admin_menus(): void {
		remove_menu_page( 'edit-comments.php' );
		remove_submenu_page( 'options-general.php', 'options-discussion.php' );
	}

	/** The hidden screens stay reachable via URL even without their menu entries. */
	public function redirect_admin_pages(): void {
		if ( wp_doing_ajax() ) {
			return;
		}

		global $pagenow;
		$blocked = [ 'edit-comments.php', 'comment.php', 'options-discussion.php' ];

		if ( in_array( $pagenow, $blocked, true ) ) {
			wp_safe_redirect( admin_url(), 302 );
			exit;
		}
	}

	public function remove_dashboard_widget(): void {
		remove_meta_box( 'dashboard_recent_comments', 'dashboard', 'normal' );
	}
};
