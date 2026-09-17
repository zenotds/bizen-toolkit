<?php
/**
 * AI Disclosure module.
 *
 * Bizen module — written and maintained by Bizen (https://bizen.it).
 *
 * AI Act art. 50(4) puts the disclosure duty on whoever publishes a deepfake —
 * an image resembling a real person, place or event that would pass as
 * authentic. The Commission's guidance is specific about how it has to reach
 * the reader: on first exposure, "without specific technical tools or dedicated
 * actions". Metadata alone does not count, and neither does a tooltip, since
 * hovering is a dedicated action and does not exist on touch.
 *
 * So the disclosure lives in the DOM next to the image rather than burnt into
 * the pixels, where every responsive crop would be free to cut it off. The
 * label itself is the Commission's own, from the EU icon set for AI-generated
 * content published alongside the Code of Practice — free to use, no
 * attribution required, shipped in assets/icons.
 *
 * Images only, for now: art. 50(4) covers video and audio on the same terms,
 * but neither goes through the attachment helpers this module filters, and the
 * disclosure would have to land on a poster frame or beside a player. See the
 * README.
 *
 * Note the scope this does not cover either: it flags an attachment, it cannot
 * judge one. Only content meeting the three deepfake criteria — close resemblance, a
 * subject that exists or plausibly could, an appearance of authenticity — owes
 * a disclosure at all, and a stylised illustration owes none. That call is
 * editorial and stays with whoever reviews the queue.
 *
 * Status lives in _bizen_ai_status on the attachment:
 *
 *   (meta absent)  never reviewed — what the triage screen lists by default
 *   none           reviewed, no AI involved
 *   generated      synthetic from the start, no capture behind it
 *   manipulated    a real capture with a substantial AI-generated part
 *
 * Routine retouching does not make an image "manipulated": art. 50(2) exempts
 * editing assistance that does not substantially alter the input, so exposure,
 * crop, denoise and upscale stay "none".
 *
 * _bizen_ai_status_source records whether a human decided ("manual") or the
 * uploader read it out of the file ("auto"), so detection can be re-run over
 * auto rows later without overwriting anyone's decision.
 *
 * Detection reads IPTC provenance, from an XMP packet where there is one and
 * from the raw bytes of a C2PA manifest where there is not — which is the case
 * for most of what ChatGPT and Gemini hand you. It only ever acts on a positive
 * marker: a file carrying none stays unreviewed rather than being called clean.
 */

defined( 'ABSPATH' ) || exit;

return new class extends Bizen_Module {

	/** Provenance read at upload time, keyed by path, consumed when the attachment appears. */
	private array $pending = [];

	public function get_id(): string {
		return 'ai-disclosure';
	}

	public function get_name(): string {
		return __( 'AI Disclosure', 'bizen-toolkit' );
	}

	public function get_description(): string {
		return __( 'Flags AI-generated and AI-modified images and prints the official EU disclosure label next to them on the front end. Reads provenance metadata on upload, adds a field to the media library, and puts a review queue under Media → AI Disclosure.', 'bizen-toolkit' );
	}

	public function boot(): void {
		require_once __DIR__ . '/class-ai-status.php';
		require_once __DIR__ . '/class-ai-provenance-reader.php';

		// Priority 1: optimisation plugins that strip metadata hook the same filter,
		// and once it is gone the file is indistinguishable from a camera photo.
		add_filter( 'wp_handle_upload', [ $this, 'read_provenance' ], 1 );
		add_action( 'add_attachment', [ $this, 'apply_provenance' ] );

		add_filter( 'attachment_fields_to_edit', [ $this, 'add_field' ], 10, 2 );
		add_filter( 'attachment_fields_to_save', [ $this, 'save_field' ], 10, 2 );

		if ( is_admin() ) {
			require_once __DIR__ . '/class-ai-triage-screen.php';
			new Bizen_AI_Triage_Screen();
		} else {
			require_once __DIR__ . '/class-ai-badge-renderer.php';
			new Bizen_AI_Badge_Renderer();
		}
	}

	public function read_provenance( array $upload ): array {
		$file = (string) ( $upload['file'] ?? '' );
		$type = (string) ( $upload['type'] ?? '' );

		if ( '' !== $file && str_starts_with( $type, 'image/' ) ) {
			$status = Bizen_AI_Provenance_Reader::detect( $file );
			if ( null !== $status ) {
				$this->pending[ $file ] = $status;
			}
		}

		return $upload;
	}

	public function apply_provenance( $attachment_id ): void {
		$attachment_id = (int) $attachment_id;

		if ( ! wp_attachment_is_image( $attachment_id ) ) {
			return;
		}

		// Never talk over a decision that is already on record.
		if ( '' !== Bizen_AI_Status::get( $attachment_id ) ) {
			return;
		}

		$file = (string) get_attached_file( $attachment_id );
		if ( '' === $file ) {
			return;
		}

		// Sideloads and importers never pass through wp_handle_upload, so fall
		// back to reading the file here.
		$status = $this->pending[ $file ] ?? Bizen_AI_Provenance_Reader::detect( $file );
		unset( $this->pending[ $file ] );

		if ( null !== $status ) {
			Bizen_AI_Status::set( $attachment_id, $status, 'auto' );
		}
	}

	/** @param array $form_fields */
	public function add_field( $form_fields, $post ): array {
		$form_fields = (array) $form_fields;

		if ( ! $post instanceof WP_Post || ! wp_attachment_is_image( $post->ID ) ) {
			return $form_fields;
		}

		$current = Bizen_AI_Status::get( (int) $post->ID );

		$options = sprintf(
			'<option value="">%s</option>',
			esc_html__( '— Not reviewed —', 'bizen-toolkit' )
		);

		foreach ( Bizen_AI_Status::labels() as $value => $label ) {
			$options .= sprintf(
				'<option value="%1$s"%2$s>%3$s</option>',
				esc_attr( $value ),
				selected( $current, $value, false ),
				esc_html( $label )
			);
		}

		$form_fields['bizen_ai_status'] = [
			'label' => __( 'AI disclosure', 'bizen-toolkit' ),
			'input' => 'html',
			'html'  => sprintf(
				'<select name="attachments[%1$d][bizen_ai_status]" id="attachments-%1$d-bizen_ai_status">%2$s</select>',
				(int) $post->ID,
				$options
			),
			'helps' => __( 'Generated and modified images carry the EU disclosure label on the front end.', 'bizen-toolkit' ),
		];

		return $form_fields;
	}

	/** @param array $post @param array $attachment */
	public function save_field( $post, $attachment ): array {
		$post = (array) $post;

		if ( ! isset( $attachment['bizen_ai_status'] ) ) {
			return $post;
		}

		$id = (int) ( $post['ID'] ?? 0 );
		if ( $id < 1 || ! current_user_can( 'edit_post', $id ) ) {
			return $post;
		}

		$status = sanitize_key( (string) $attachment['bizen_ai_status'] );

		if ( '' === $status ) {
			Bizen_AI_Status::clear( $id );
		} else {
			Bizen_AI_Status::set( $id, $status );
		}

		return $post;
	}
};
