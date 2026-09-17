<?php
defined( 'ABSPATH' ) || exit;

/**
 * Reads the IPTC "Digital Source Type" out of a file's XMP packet.
 *
 * Generators that follow the IPTC guidance write one of a small set of IRIs
 * into Iptc4xmpExt:DigitalSourceType, and that is the only signal read here.
 * C2PA manifests live in signed JUMBF boxes that PHP cannot open without a
 * library, but the tools that write them generally mirror the same value into
 * XMP, so the cheap read covers most of what the expensive one would.
 *
 * A file with no marker returns null and stays unreviewed. Stripped metadata
 * looks exactly like a camera photo, so the absence of a marker is never taken
 * as evidence that no AI was involved.
 */
class Bizen_AI_XMP_Reader {

	/** IPTC vocabulary term => module status. */
	private const MAP = [
		'trainedalgorithmicmedia'              => Bizen_AI_Status::GENERATED,
		'compositewithtrainedalgorithmicmedia' => Bizen_AI_Status::MANIPULATED,
	];

	/** How much of each end of the file to scan when it is too big to slurp. */
	private const SCAN_BYTES = 512000;

	/** Files above this size are read head-and-tail rather than whole. */
	private const SLURP_LIMIT = 2097152;

	/** Returns a Bizen_AI_Status constant, or null when the file carries no usable marker. */
	public static function detect( string $path ): ?string {
		$packet = self::extract_packet( $path );
		if ( null === $packet ) {
			return null;
		}

		$iri = self::digital_source_type( $packet );
		if ( null === $iri ) {
			return null;
		}

		$term   = strtolower( (string) substr( $iri, (int) strrpos( $iri, '/' ) + 1 ) );
		$status = self::MAP[ $term ] ?? null;

		/**
		 * Filters the status inferred from a file's provenance metadata.
		 * Lets a site map vocabulary terms this module does not know about,
		 * or fall back to xmp:CreatorTool for generators that write no IRI.
		 *
		 * @param string|null $status Status constant, or null for "leave unreviewed".
		 * @param string      $iri    Raw DigitalSourceType value found in the file.
		 * @param string      $packet The whole XMP packet.
		 * @param string      $path   Absolute path to the file.
		 */
		return apply_filters( 'bizen_ai_disclosure_detected_status', $status, $iri, $packet, $path );
	}

	/** Pulls the <x:xmpmeta> block out of a file, wherever the container put it. */
	private static function extract_packet( string $path ): ?string {
		if ( ! is_readable( $path ) ) {
			return null;
		}

		$size = (int) @filesize( $path );
		if ( $size < 1 ) {
			return null;
		}

		if ( $size <= self::SLURP_LIMIT ) {
			$haystack = (string) @file_get_contents( $path );
		} else {
			// JPEG and PNG put XMP near the front, WebP and MP4-derived formats
			// can put it at the very end, so look at both.
			$handle = @fopen( $path, 'rb' );
			if ( ! $handle ) {
				return null;
			}
			$haystack = (string) fread( $handle, self::SCAN_BYTES );
			fseek( $handle, -self::SCAN_BYTES, SEEK_END );
			$haystack .= (string) fread( $handle, self::SCAN_BYTES );
			fclose( $handle );
		}

		if ( '' === $haystack ) {
			return null;
		}

		$start = stripos( $haystack, '<x:xmpmeta' );
		if ( false === $start ) {
			return null;
		}

		$end = stripos( $haystack, '</x:xmpmeta>', $start );
		if ( false === $end ) {
			return null;
		}

		return substr( $haystack, $start, $end - $start + 12 );
	}

	/** The property turns up as an attribute, an rdf:resource or element text depending on the writer. */
	private static function digital_source_type( string $packet ): ?string {
		$patterns = [
			'/<[\w.-]*:?DigitalSourceType[^>]*rdf:resource\s*=\s*["\']([^"\']+)["\']/i',
			'/DigitalSourceType\s*=\s*["\']([^"\']+)["\']/i',
			'/<[\w.-]*:?DigitalSourceType[^>]*>\s*([^<\s][^<]*?)\s*<\//i',
		];

		foreach ( $patterns as $pattern ) {
			if ( preg_match( $pattern, $packet, $match ) ) {
				$value = trim( $match[1] );
				if ( '' !== $value ) {
					return $value;
				}
			}
		}

		return null;
	}
}
