<?php
defined( 'ABSPATH' ) || exit;

/**
 * Media → AI Disclosure: a grid for working through the backlog.
 *
 * It sits under Media rather than under the toolkit panel because that is where
 * someone already is when they are thinking about images, and it loads nothing
 * but an id, a thumbnail and a status — the media modal is slow because it
 * builds the whole attachment editor for every item, which is exactly the wrong
 * shape for classifying a few hundred files in a sitting.
 *
 * Work happens by selection: tick the images that belong together, apply a
 * status to all of them at once. Search narrows the grid first, so a batch is
 * usually "everything matching hero-" rather than a hunt through pages. A radio
 * on a single card still saves on the spot, for the one-off correction.
 *
 * The default view is everything nobody has looked at yet. Once that list is
 * empty the screen has done its job and day-to-day work happens in the field on
 * the attachment itself.
 */
class Bizen_AI_Triage_Screen {

	public const PAGE     = 'bizen-ai-disclosure';
	private const PER_PAGE = 60;

	/** Raster formats only: an icon or a logo is never a deepfake, and SVGs would flood the queue. */
	private const MIMES = [ 'image/jpeg', 'image/png', 'image/gif', 'image/webp', 'image/avif' ];

	public function __construct() {
		add_action( 'admin_menu', [ $this, 'register' ] );
		add_action( 'wp_ajax_bizen_ai_set_status', [ $this, 'ajax_set_status' ] );
		add_action( 'wp_ajax_bizen_ai_set_variant', [ $this, 'ajax_set_variant' ] );
	}

	public function register(): void {
		$hook = add_submenu_page(
			'upload.php',
			__( 'AI Disclosure', 'bizen-toolkit' ),
			__( 'AI Disclosure', 'bizen-toolkit' ),
			'upload_files',
			self::PAGE,
			[ $this, 'render' ]
		);

		if ( is_string( $hook ) ) {
			add_action( 'admin_print_styles-' . $hook, [ $this, 'enqueue' ] );
		}
	}

	private function current_filter(): string {
		return isset( $_GET['status'] ) ? sanitize_key( wp_unslash( $_GET['status'] ) ) : 'unreviewed';
	}

	private function current_search(): string {
		return isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : '';
	}

	/** The current view without a page number: where to land once the grid empties out. */
	private function view_url(): string {
		$args   = [ 'page' => self::PAGE, 'status' => $this->current_filter() ];
		$search = $this->current_search();

		if ( '' !== $search ) {
			$args['s'] = $search;
		}

		return add_query_arg( $args, admin_url( 'upload.php' ) );
	}

	public function enqueue(): void {
		$base = plugin_dir_url( __FILE__ ) . 'assets/';

		wp_enqueue_style( 'bizen-ai-triage', $base . 'triage.css', [], BIZEN_TOOLKIT_VERSION );
		wp_enqueue_script( 'bizen-ai-triage', $base . 'triage.js', [], BIZEN_TOOLKIT_VERSION, true );
		wp_localize_script(
			'bizen-ai-triage',
			'bizenAiTriage',
			[
				'ajaxUrl'  => admin_url( 'admin-ajax.php' ),
				'nonce'    => wp_create_nonce( 'bizen_ai_set_status' ),
				'filter'   => $this->current_filter(),
				'viewUrl'  => $this->view_url(),
				'styleNonce' => wp_create_nonce( 'bizen_ai_set_variant' ),
				'stylePreviews' => $this->variant_previews(),
				'saving'   => __( 'Saving…', 'bizen-toolkit' ),
				'saved'    => __( 'Saved', 'bizen-toolkit' ),
				'failed'   => __( 'Could not save', 'bizen-toolkit' ),
				'none'     => __( 'Nothing selected', 'bizen-toolkit' ),
				/* translators: %d: number of selected images */
				'selected' => __( '%d selected', 'bizen-toolkit' ),
				/* translators: %d: number of selected images */
				'confirm'  => __( 'Apply this status to %d selected images?', 'bizen-toolkit' ),
				/* translators: %d: number of selected images */
				'reset'    => __( 'Send %d selected images back to the review queue?', 'bizen-toolkit' ),
				'undone'   => __( 'Undone', 'bizen-toolkit' ),
			]
		);
	}

	public function render(): void {
		if ( ! current_user_can( 'upload_files' ) ) {
			wp_die( esc_html__( 'You do not have permission to manage media.', 'bizen-toolkit' ) );
		}

		$filter = $this->current_filter();
		$search = $this->current_search();
		$paged  = max( 1, (int) ( $_GET['paged'] ?? 1 ) );
		$counts = $this->counts();
		$query  = $this->query( $filter, $paged, $search );
		?>
		<div class="wrap bizen-ai-triage">
			<h1><?php esc_html_e( 'AI Disclosure', 'bizen-toolkit' ); ?></h1>

			<p class="description" style="max-width:52em;">
				<?php esc_html_e( 'Images marked "AI generated" or "AI modified" carry the EU disclosure label on the front end. Mark an image "No AI" to clear it from this queue — an image nobody has reviewed is not the same as one confirmed to be AI-free.', 'bizen-toolkit' ); ?>
			</p>

			<?php $this->render_options(); ?>

			<?php $this->render_search( $filter, $search ); ?>
			<?php $this->render_filters( $filter, $search, $counts ); ?>

			<div class="clear"></div>

			<?php if ( '' !== $search ) : ?>
				<p class="bizen-ai-results">
					<?php
					printf(
						/* translators: 1: number of matches, 2: search term */
						esc_html__( '%1$s matching %2$s', 'bizen-toolkit' ),
						'<strong>' . esc_html( number_format_i18n( (int) $query->found_posts ) ) . '</strong>',
						'<em>' . esc_html( $search ) . '</em>'
					);
					?>
					<a href="<?php echo esc_url( add_query_arg( [ 'page' => self::PAGE, 'status' => $filter ], admin_url( 'upload.php' ) ) ); ?>">
						<?php esc_html_e( 'Clear search', 'bizen-toolkit' ); ?>
					</a>
				</p>
			<?php endif; ?>

			<?php if ( ! $query->have_posts() ) : ?>
				<p><em><?php esc_html_e( 'Nothing to review here.', 'bizen-toolkit' ); ?></em></p>
			<?php else : ?>

				<?php $this->render_toolbar(); ?>

				<div class="bizen-ai-grid" id="bizen-ai-grid">
					<?php
					foreach ( $query->posts as $attachment_id ) {
						$this->render_item( (int) $attachment_id );
					}
					?>
				</div>

				<?php $this->render_pagination( $query, $filter, $search, $paged ); ?>

			<?php endif; ?>
		</div>
		<?php
	}

	/** @return array<string, string> variant => preview image URL */
	private function variant_previews(): array {
		$base     = plugin_dir_url( __FILE__ ) . 'assets/icons/label-ai-generated-';
		$previews = [];

		foreach ( array_keys( Bizen_AI_Status::variants() ) as $variant ) {
			$previews[ $variant ] = $base . $variant . '.svg';
		}

		return $previews;
	}

	/**
	 * The one setting the module has, on the module's own screen.
	 *
	 * It is site-wide, so it asks for manage_options while the queue itself only
	 * asks for upload_files: an editor clears the backlog, an administrator
	 * decides how the label looks everywhere.
	 */
	private function render_options(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$current  = Bizen_AI_Status::icon_variant();
		$previews = $this->variant_previews();
		?>
		<div class="bizen-ai-options" id="bizen-ai-options">
			<label for="bizen-ai-variant"><?php esc_html_e( 'EU label style', 'bizen-toolkit' ); ?></label>

			<select id="bizen-ai-variant">
				<?php foreach ( Bizen_AI_Status::variants() as $value => $label ) : ?>
					<option value="<?php echo esc_attr( $value ); ?>"<?php selected( $current, $value ); ?>>
						<?php echo esc_html( $label ); ?>
					</option>
				<?php endforeach; ?>
			</select>

			<span class="bizen-ai-options__preview">
				<img src="<?php echo esc_url( $previews[ $current ] ?? '' ); ?>" alt="">
			</span>

			<span class="bizen-ai-options__feedback" aria-live="polite"></span>
		</div>
		<?php
	}

	public function ajax_set_variant(): void {
		check_ajax_referer( 'bizen_ai_set_variant', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( [ 'message' => __( 'You do not have permission to change this setting.', 'bizen-toolkit' ) ], 403 );
		}

		$variant = isset( $_POST['variant'] ) ? sanitize_key( wp_unslash( $_POST['variant'] ) ) : '';

		if ( ! isset( Bizen_AI_Status::variants()[ $variant ] ) ) {
			wp_send_json_error( [ 'message' => __( 'Unknown label style.', 'bizen-toolkit' ) ], 400 );
		}

		update_option( Bizen_AI_Status::OPTION_VARIANT, $variant );

		wp_send_json_success( [ 'variant' => $variant ] );
	}

	private function render_search( string $filter, string $search ): void {
		?>
		<form method="get" class="bizen-ai-search">
			<input type="hidden" name="page" value="<?php echo esc_attr( self::PAGE ); ?>">
			<input type="hidden" name="status" value="<?php echo esc_attr( $filter ); ?>">
			<p class="search-box">
				<label class="screen-reader-text" for="bizen-ai-search-input">
					<?php esc_html_e( 'Search images', 'bizen-toolkit' ); ?>
				</label>
				<input type="search" id="bizen-ai-search-input" name="s" value="<?php echo esc_attr( $search ); ?>">
				<input type="submit" class="button" value="<?php esc_attr_e( 'Search', 'bizen-toolkit' ); ?>">
			</p>
		</form>
		<?php
	}

	private function render_filters( string $current, string $search, array $counts ): void {
		$tabs = [ 'unreviewed' => __( 'To review', 'bizen-toolkit' ) ]
			+ Bizen_AI_Status::labels()
			+ [ 'all' => __( 'All images', 'bizen-toolkit' ) ];

		echo '<ul class="subsubsub">';
		$last = array_key_last( $tabs );

		foreach ( $tabs as $key => $label ) {
			$args = [ 'page' => self::PAGE, 'status' => $key ];
			if ( '' !== $search ) {
				$args['s'] = $search;
			}

			printf(
				'<li><a href="%1$s" class="%2$s" data-status-tab="%3$s">%4$s <span class="count">(<span data-count="%3$s">%5$s</span>)</span></a>%6$s</li>',
				esc_url( add_query_arg( $args, admin_url( 'upload.php' ) ) ),
				$key === $current ? 'current' : '',
				esc_attr( $key ),
				esc_html( $label ),
				esc_html( number_format_i18n( $counts[ $key ] ?? 0 ) ),
				$key === $last ? '' : ' |'
			);
		}

		echo '</ul>';
	}

	private function render_toolbar(): void {
		?>
		<div class="bizen-ai-toolbar" id="bizen-ai-toolbar">
			<label class="bizen-ai-toolbar__all">
				<input type="checkbox" id="bizen-ai-select-all">
				<span><?php esc_html_e( 'Select all', 'bizen-toolkit' ); ?></span>
			</label>

			<span class="bizen-ai-toolbar__count" data-selected-count>&nbsp;</span>

			<span class="bizen-ai-toolbar__actions">
				<span class="bizen-ai-toolbar__label"><?php esc_html_e( 'Mark selection as:', 'bizen-toolkit' ); ?></span>
				<?php foreach ( Bizen_AI_Status::labels() as $status => $label ) : ?>
					<button type="button" class="button" data-bulk-status="<?php echo esc_attr( $status ); ?>" disabled>
						<?php echo esc_html( $label ); ?>
					</button>
				<?php endforeach; ?>

				<button type="button" class="button" data-bulk-status="<?php echo esc_attr( Bizen_AI_Status::UNREVIEWED ); ?>" disabled>
					<?php esc_html_e( 'To review', 'bizen-toolkit' ); ?>
				</button>
			</span>

			<span class="bizen-ai-toolbar__feedback" aria-live="polite"></span>

			<button type="button" class="button-link bizen-ai-toolbar__undo" hidden>
				<?php esc_html_e( 'Undo', 'bizen-toolkit' ); ?>
			</button>
		</div>
		<?php
	}

	private function render_item( int $attachment_id ): void {
		$status = Bizen_AI_Status::get( $attachment_id );
		$auto   = 'auto' === Bizen_AI_Status::source( $attachment_id );
		$name   = get_the_title( $attachment_id );
		$thumb  = wp_get_attachment_image( $attachment_id, 'thumbnail', false, [ 'loading' => 'lazy', 'alt' => '' ] );
		?>
		<div class="bizen-ai-item" data-id="<?php echo esc_attr( (string) $attachment_id ); ?>">
			<div class="bizen-ai-item__thumb">
				<?php echo $thumb ? wp_kses_post( $thumb ) : '<span class="bizen-ai-item__placeholder"></span>'; ?>

				<label class="bizen-ai-item__check">
					<input type="checkbox" class="bizen-ai-item__select">
					<span class="screen-reader-text">
						<?php
						/* translators: %s: attachment title */
						printf( esc_html__( 'Select %s', 'bizen-toolkit' ), esc_html( $name ) );
						?>
					</span>
				</label>

				<?php if ( $auto && '' !== $status ) : ?>
					<span class="bizen-ai-item__auto" title="<?php esc_attr_e( 'Read from the file\'s provenance metadata — confirm or correct it.', 'bizen-toolkit' ); ?>">
						<?php esc_html_e( 'auto', 'bizen-toolkit' ); ?>
					</span>
				<?php endif; ?>
			</div>

			<div class="bizen-ai-item__name" title="<?php echo esc_attr( $name ); ?>">
				<a href="<?php echo esc_url( get_edit_post_link( $attachment_id ) ); ?>"><?php echo esc_html( $name ); ?></a>
			</div>

			<fieldset class="bizen-ai-item__choices">
				<legend class="screen-reader-text">
					<?php
					/* translators: %s: attachment title */
					printf( esc_html__( 'AI disclosure status for %s', 'bizen-toolkit' ), esc_html( $name ) );
					?>
				</legend>
				<?php foreach ( Bizen_AI_Status::labels() as $value => $label ) : ?>
					<label>
						<input
							type="radio"
							name="bizen_ai_status_<?php echo esc_attr( (string) $attachment_id ); ?>"
							value="<?php echo esc_attr( $value ); ?>"
							<?php checked( $status, $value ); ?>
						>
						<span><?php echo esc_html( $label ); ?></span>
					</label>
				<?php endforeach; ?>
			</fieldset>

			<span class="bizen-ai-item__feedback" aria-live="polite"></span>
		</div>
		<?php
	}

	private function render_pagination( WP_Query $query, string $filter, string $search, int $paged ): void {
		if ( $query->max_num_pages < 2 ) {
			return;
		}

		$args = [ 'page' => self::PAGE, 'status' => $filter ];
		if ( '' !== $search ) {
			$args['s'] = $search;
		}

		$links = paginate_links(
			[
				'base'      => add_query_arg( 'paged', '%#%' ),
				'format'    => '',
				'current'   => $paged,
				'total'     => (int) $query->max_num_pages,
				'add_args'  => $args,
				'prev_text' => '&laquo;',
				'next_text' => '&raquo;',
			]
		);

		if ( $links ) {
			echo '<div class="tablenav"><div class="tablenav-pages">' . wp_kses_post( $links ) . '</div></div>';
		}
	}

	private function query( string $filter, int $paged, string $search = '' ): WP_Query {
		$args = [
			'post_type'              => 'attachment',
			'post_status'            => 'inherit',
			'post_mime_type'         => self::MIMES,
			'posts_per_page'         => self::PER_PAGE,
			'paged'                  => $paged,
			'orderby'                => 'date',
			'order'                  => 'DESC',
			'fields'                 => 'ids',
			'update_post_term_cache' => false,
		];

		if ( '' !== $search ) {
			$args['s'] = $search;
		}

		if ( 'unreviewed' === $filter ) {
			$args['meta_query'] = [
				[
					'key'     => Bizen_AI_Status::META_STATUS,
					'compare' => 'NOT EXISTS',
				],
			];
		} elseif ( Bizen_AI_Status::is_valid( $filter ) ) {
			$args['meta_query'] = [
				[
					'key'     => Bizen_AI_Status::META_STATUS,
					'value'   => $filter,
					'compare' => '=',
				],
			];
		}

		return new WP_Query( $args );
	}

	/**
	 * Counts for the filter tabs, keyed by tab.
	 *
	 * Deliberately library-wide rather than scoped to the search: the tabs say
	 * how much is left to review overall, which is the number worth knowing
	 * while narrowing the grid. The match count sits next to the search instead.
	 *
	 * @return array<string, int>
	 */
	private function counts(): array {
		global $wpdb;

		$placeholders = implode( ', ', array_fill( 0, count( self::MIMES ), '%s' ) );

		$sql = $wpdb->prepare(
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- placeholders built above.
			"SELECT COALESCE( pm.meta_value, '' ) AS status, COUNT(*) AS total
			   FROM {$wpdb->posts} p
			   LEFT JOIN {$wpdb->postmeta} pm ON pm.post_id = p.ID AND pm.meta_key = %s
			  WHERE p.post_type = 'attachment'
			    AND p.post_mime_type IN ( {$placeholders} )
			  GROUP BY status",
			array_merge( [ Bizen_AI_Status::META_STATUS ], self::MIMES )
		);

		$counts = [ 'unreviewed' => 0, 'all' => 0 ];
		foreach ( array_keys( Bizen_AI_Status::labels() ) as $status ) {
			$counts[ $status ] = 0;
		}

		foreach ( (array) $wpdb->get_results( $sql, ARRAY_A ) as $row ) {
			$status = (string) ( $row['status'] ?? '' );
			$total  = (int) ( $row['total'] ?? 0 );
			$key    = Bizen_AI_Status::is_valid( $status ) ? $status : 'unreviewed';

			$counts[ $key ] += $total;
			$counts['all']  += $total;
		}

		return $counts;
	}

	public function ajax_set_status(): void {
		check_ajax_referer( 'bizen_ai_set_status', 'nonce' );

		$status   = isset( $_POST['status'] ) ? sanitize_key( wp_unslash( $_POST['status'] ) ) : '';
		$clearing = Bizen_AI_Status::UNREVIEWED === $status;

		if ( ! $clearing && ! Bizen_AI_Status::is_valid( $status ) ) {
			wp_send_json_error( [ 'message' => __( 'Unknown status.', 'bizen-toolkit' ) ], 400 );
		}

		$ids = isset( $_POST['ids'] ) ? array_map( 'absint', (array) wp_unslash( $_POST['ids'] ) ) : [];
		$ids = array_filter( array_unique( $ids ) );
		if ( ! $ids ) {
			wp_send_json_error( [ 'message' => __( 'No images selected.', 'bizen-toolkit' ) ], 400 );
		}

		$saved = [];
		foreach ( $ids as $id ) {
			if ( ! current_user_can( 'edit_post', $id ) ) {
				continue;
			}
			if ( 'attachment' !== get_post_type( $id ) ) {
				continue;
			}
			if ( $clearing ) {
				Bizen_AI_Status::clear( $id );
				$saved[] = $id;
			} elseif ( Bizen_AI_Status::set( $id, $status ) ) {
				$saved[] = $id;
			}
		}

		if ( ! $saved ) {
			wp_send_json_error( [ 'message' => __( 'Nothing was saved.', 'bizen-toolkit' ) ], 403 );
		}

		wp_send_json_success(
			[
				'saved'  => $saved,
				'counts' => $this->counts(),
			]
		);
	}
}
