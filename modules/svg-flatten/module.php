<?php
/**
 * Flatten SVG on Upload module.
 *
 * Bizen module — written and maintained by Bizen (https://bizen.it).
 *
 * Illustrator keeps its colours in a <style> block keyed to class names (.cls-1)
 * and ids (Layer_1, gradients, clip paths) that come out identical in every
 * export. Inline two of those files on the same page and they fight: the last
 * <style> repaints the first icon, and the first `url(#a)` gradient paints every
 * shape that references that id.
 *
 * On upload each SVG is rewritten so nothing it carries is global — declarations
 * move onto the elements, the <style> goes away, unreferenced ids are dropped and
 * the surviving ones are namespaced per file. Colours land as presentation
 * attributes rather than inline styles, so a page can still recolour an icon with
 * `svg path { fill: currentColor }`.
 *
 * Not a sanitizer: scripts and event handlers pass through untouched. Pair it with
 * Safe SVG (or equivalent) wherever untrusted users can upload — this module does
 * not enable SVG uploads on its own either.
 */

defined( 'ABSPATH' ) || exit;

return new class extends Bizen_Module {

	public function get_id(): string {
		return 'svg-flatten';
	}

	public function get_name(): string {
		return __( 'Flatten SVG on Upload', 'bizen-toolkit' );
	}

	public function get_description(): string {
		return __( 'Rewrites uploaded SVGs so they can be inlined side by side — the style block is dropped, its declarations move onto the elements as fill/stroke attributes, and ids are namespaced per file.', 'bizen-toolkit' );
	}

	public function boot(): void {
		require_once __DIR__ . '/class-svg-flattener.php';

		// Media library and sideloads, before the file reaches anything downstream.
		add_filter( 'wp_handle_upload', [ $this, 'flatten_upload' ] );

		// Attachments created outside the upload handler: wp_upload_bits, importers, seeds.
		add_action( 'add_attachment', [ $this, 'flatten_attachment' ] );

		// For code holding an SVG string that never becomes an attachment.
		add_filter( 'bizen_toolkit_flatten_svg', [ 'Bizen_SVG_Flattener', 'flatten' ] );
	}

	public function flatten_upload( array $upload ): array {
		if ( 'image/svg+xml' === ( $upload['type'] ?? '' ) ) {
			$this->flatten_file( (string) ( $upload['file'] ?? '' ) );
		}

		return $upload;
	}

	/** Flattening is idempotent, so the second pass on an uploaded file writes nothing. */
	public function flatten_attachment( int $attachment_id ): void {
		if ( 'image/svg+xml' !== get_post_mime_type( $attachment_id ) ) {
			return;
		}

		$this->flatten_file( (string) get_attached_file( $attachment_id ) );
	}

	private function flatten_file( string $file ): void {
		if ( '' === $file || ! is_file( $file ) || ! is_writable( $file ) ) {
			return;
		}

		/** Guards against parsing a pathological file into memory; maps and floor plans get large. */
		$max_size = (int) apply_filters( 'bizen_toolkit_flatten_svg_max_size', 2 * MB_IN_BYTES );
		if ( filesize( $file ) > $max_size ) {
			return;
		}

		$svg = file_get_contents( $file );
		if ( false === $svg ) {
			return;
		}

		$flat = Bizen_SVG_Flattener::flatten( $svg );
		if ( $flat !== $svg ) {
			file_put_contents( $file, $flat );
		}
	}
};
