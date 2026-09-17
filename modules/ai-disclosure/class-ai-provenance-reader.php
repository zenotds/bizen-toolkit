<?php
defined( 'ABSPATH' ) || exit;

/**
 * Reads a file's provenance and says what kind of AI involvement it declares.
 *
 * Two passes, in order of trust.
 *
 * The first reads Iptc4xmpExt:DigitalSourceType out of the XMP packet. That is
 * a single authoritative field, so where it exists nothing else is consulted.
 *
 * The second is a plain scan of the same bytes for the IPTC vocabulary terms,
 * and it exists because the generators people actually use — ChatGPT, Gemini —
 * ship C2PA and no XMP at all. A C2PA manifest lives in a signed JUMBF box that
 * PHP cannot open without a library, but the term travels through it as a
 * readable string, so looking for the string finds what parsing the box would
 * have found. It is a heuristic and it is treated as one: these are IPTC
 * NewsCodes terms that do not occur in ordinary image data, so a false positive
 * needs a file that talks about AI provenance without having any.
 *
 * A file with neither returns null and stays unreviewed. Stripped metadata is
 * indistinguishable from a camera photo, so silence is never read as "no AI".
 */
class Bizen_AI_Provenance_Reader {

	/**
	 * IPTC vocabulary term => module status.
	 *
	 * Order matters for the raw scan: trainedAlgorithmicMedia is a substring of
	 * compositeWithTrainedAlgorithmicMedia, so the longer term is tested first.
	 * A file whose history carries both was composited, which is the narrower
	 * and more accurate claim.
	 */
	private const MAP = [
		'compositewithtrainedalgorithmicmedia' => Bizen_AI_Status::MANIPULATED,
		'trainedalgorithmicmedia'              => Bizen_AI_Status::GENERATED,
	];

	/** How much of each end of the file to scan when it is too big to slurp. */
	private const SCAN_BYTES = 512000;

	/** Files above this size are read head-and-tail rather than whole. */
	private const SLURP_LIMIT = 2097152;

	/** Returns a Bizen_AI_Status constant, or null when the file declares nothing. */
	public static function detect( string $path ): ?string {
		$bytes = self::read_window( $path );
		if ( null === $bytes ) {
			return null;
		}

		$marker = self::from_xmp( $bytes );
		$status = null !== $marker ? self::map( $marker ) : self::from_raw( $bytes );

		/**
		 * Filters the status inferred from a file's provenance metadata.
		 * Lets a site map vocabulary terms this module does not know about, or
		 * fall back to xmp:CreatorTool for generators that write no term at all.
		 *
		 * @param string|null $status Status constant, or null for "leave unreviewed".
		 * @param string|null $marker The DigitalSourceType value found in XMP, if any.
		 * @param string      $bytes  The bytes that were scanned.
		 * @param string      $path   Absolute path to the file.
		 */
		return apply_filters( 'bizen_ai_disclosure_detected_status', $status, $marker, $bytes, $path );
	}

	/** Maps a vocabulary term or full IRI onto a status. */
	private static function map( string $value ): ?string {
		$term = strtolower( (string) substr( $value, (int) strrpos( $value, '/' ) + 1 ) );

		return self::MAP[ $term ] ?? null;
	}

	/** The bytes worth looking at: whole file when small, both ends when not. */
	private static function read_window( string $path ): ?string {
		if ( ! is_readable( $path ) ) {
			return null;
		}

		$size = (int) @filesize( $path );
		if ( $size < 1 ) {
			return null;
		}

		if ( $size <= self::SLURP_LIMIT ) {
			$bytes = (string) @file_get_contents( $path );
		} else {
			// JPEG and PNG put provenance near the front, WebP and MP4-derived
			// formats can put it at the very end, so look at both.
			$handle = @fopen( $path, 'rb' );
			if ( ! $handle ) {
				return null;
			}
			$bytes = (string) fread( $handle, self::SCAN_BYTES );
			fseek( $handle, -self::SCAN_BYTES, SEEK_END );
			$bytes .= (string) fread( $handle, self::SCAN_BYTES );
			fclose( $handle );
		}

		return '' === $bytes ? null : $bytes;
	}

	/** The structured answer: DigitalSourceType inside the XMP packet. */
	private static function from_xmp( string $bytes ): ?string {
		$start = stripos( $bytes, '<x:xmpmeta' );
		if ( false === $start ) {
			return null;
		}

		$end = stripos( $bytes, '</x:xmpmeta>', $start );
		if ( false === $end ) {
			return null;
		}

		$packet = substr( $bytes, $start, $end - $start + 12 );

		// The property turns up as an attribute, an rdf:resource or element text
		// depending on which tool wrote it.
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

	/** The fallback: the same vocabulary, found anywhere in the bytes. */
	private static function from_raw( string $bytes ): ?string {
		foreach ( self::MAP as $term => $status ) {
			if ( false !== stripos( $bytes, $term ) ) {
				return $status;
			}
		}

		return null;
	}
}
