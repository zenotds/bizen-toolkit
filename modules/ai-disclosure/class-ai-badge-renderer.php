<?php
defined( 'ABSPATH' ) || exit;

/**
 * Puts the disclosure next to flagged images on the front end.
 *
 * The badge is a sibling of the image, not part of it. object-fit crops the
 * content of an image box, never the elements positioned over it, so a badge
 * placed this way survives every responsive crop the theme throws at it —
 * which a watermark burnt into the pixels does not.
 *
 * Where it lands is a property of the composition, not of the file: the same
 * photo is clear in the corner of a card and behind an overlay panel in a hero.
 * So the corner is chosen by whoever places the image, defaulting to the bottom
 * right, and only the default is automatic.
 *
 * The EU label is used as an <img> rather than inlined on purpose: the official
 * files carry .cls-1 / .cls-2 style blocks, and two inlined copies on one page
 * would repaint each other exactly the way the svg-flatten module exists to
 * prevent. As an <img> it also gets its accessible name from alt, so the
 * disclosure reaches a screen reader without anyone rewriting the alt text of
 * the image underneath.
 *
 * Themes that build their own markup — a Twig macro emitting <picture>, a page
 * builder, a hand-written gallery — never reach the attachment helpers below.
 * They ask for the badge instead:
 *
 *     apply_filters( 'bizen_ai_disclosure_badge', '', $attachment_id, 'top-right' )
 *
 * which returns the markup, or an empty string when the image needs none or the
 * module is switched off. Callers wrap their own element in
 * .bizen-ai-media (add --fill when the image is stretched to a sized parent).
 */
class Bizen_AI_Badge_Renderer {

	/** status => [ file stem, intrinsic width, intrinsic height ] */
	private const ICONS = [
		Bizen_AI_Status::GENERATED   => [ 'label-ai-generated', 1790, 567 ],
		Bizen_AI_Status::MANIPULATED => [ 'label-ai-modified', 1701, 567 ],
	];

	private const POSITIONS = [ 'bottom-right', 'bottom-left', 'top-right', 'top-left' ];

	private const DEFAULT_POSITION = 'bottom-right';

	public function __construct() {
		add_filter( 'wp_get_attachment_image', [ $this, 'filter_attachment_image' ], 10, 2 );
		add_filter( 'wp_content_img_tag', [ $this, 'filter_content_image' ], 10, 3 );
		add_action( 'wp_enqueue_scripts', [ $this, 'enqueue' ] );
		add_filter( 'bizen_ai_disclosure_badge', [ $this, 'badge_for' ], 10, 3 );
	}

	public function enqueue(): void {
		wp_enqueue_style(
			'bizen-ai-disclosure',
			plugin_dir_url( __FILE__ ) . 'assets/badge.css',
			[],
			BIZEN_TOOLKIT_VERSION
		);
	}

	/** @param string $html */
	public function filter_attachment_image( $html, $attachment_id ): string {
		return $this->wrap( (string) $html, (int) $attachment_id );
	}

	/** @param string $filtered_image */
	public function filter_content_image( $filtered_image, $context, $attachment_id ): string {
		return $this->wrap( (string) $filtered_image, (int) $attachment_id );
	}

	/**
	 * Badge markup on its own, for markup this module does not generate.
	 * Empty string when the attachment needs no disclosure.
	 */
	public function badge_for( $html, $attachment_id = 0, $position = '' ): string {
		$attachment_id = (int) $attachment_id;
		$status        = Bizen_AI_Status::get( $attachment_id );

		if ( ! Bizen_AI_Status::needs_badge( $status ) ) {
			return (string) $html;
		}

		return $this->badge( $status, $attachment_id, (string) $position );
	}

	private function wrap( string $html, int $attachment_id ): string {
		if ( '' === $html || $attachment_id < 1 ) {
			return $html;
		}

		$status = Bizen_AI_Status::get( $attachment_id );
		if ( ! Bizen_AI_Status::needs_badge( $status ) ) {
			return $html;
		}

		/**
		 * Filters where the badge sits for images rendered through the core
		 * attachment helpers. One of the values in self::POSITIONS.
		 *
		 * @param string $position
		 * @param int    $attachment_id
		 * @param string $status
		 */
		$position = (string) apply_filters( 'bizen_ai_disclosure_position', self::DEFAULT_POSITION, $attachment_id, $status );

		$badge = $this->badge( $status, $attachment_id, $position );
		if ( '' === $badge ) {
			return $html;
		}

		/**
		 * Filters the wrapper class.
		 *
		 * The default is an inline-block that leaves the image's own box alone.
		 * Add 'bizen-ai-media--fill' for heroes and covers, where the image is
		 * stretched to a parent that sizes it, and 'bizen-ai-media--below' to
		 * move the disclosure under the image instead of over it — which is how
		 * art. 50(4) wants it presented for evidently artistic work.
		 *
		 * @param string $class
		 * @param int    $attachment_id
		 * @param string $status
		 */
		$class = (string) apply_filters( 'bizen_ai_disclosure_wrapper_class', 'bizen-ai-media', $attachment_id, $status );

		return sprintf( '<span class="%s">%s%s</span>', esc_attr( $class ), $html, $badge );
	}

	private function badge( string $status, int $attachment_id, string $position = '' ): string {
		$icon = self::ICONS[ $status ] ?? null;
		if ( null === $icon ) {
			return '';
		}

		[ $stem, $width, $height ] = $icon;

		$position = in_array( $position, self::POSITIONS, true ) ? $position : self::DEFAULT_POSITION;

		/**
		 * Filters the icon colourway: 'black' (black pill, white lettering) or
		 * 'white'. Both ship with the module, straight from the Commission set.
		 *
		 * @param string $variant
		 * @param int    $attachment_id
		 * @param string $status
		 */
		$variant = (string) apply_filters( 'bizen_ai_disclosure_icon_variant', 'black', $attachment_id, $status );
		$variant = in_array( $variant, [ 'black', 'white' ], true ) ? $variant : 'black';

		$html = sprintf(
			'<img class="bizen-ai-badge bizen-ai-badge--%1$s bizen-ai-badge--%2$s" src="%3$s" width="%4$d" height="%5$d" alt="%6$s" decoding="async">',
			esc_attr( $status ),
			esc_attr( $position ),
			esc_url( plugin_dir_url( __FILE__ ) . 'assets/icons/' . $stem . '-' . $variant . '.svg' ),
			$width,
			$height,
			esc_attr( Bizen_AI_Status::label( $status ) )
		);

		/**
		 * Filters the whole badge element.
		 *
		 * @param string $html
		 * @param string $status
		 * @param int    $attachment_id
		 * @param string $position
		 */
		return (string) apply_filters( 'bizen_ai_disclosure_badge_html', $html, $status, $attachment_id, $position );
	}
}
