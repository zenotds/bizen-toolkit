<?php
defined( 'ABSPATH' ) || exit;

/**
 * The status vocabulary, shared by the uploader, the badge renderer and the
 * triage screen so the three never drift apart.
 *
 * An attachment with no meta row has never been looked at, which is not the
 * same as having been cleared — that distinction is the whole point of the
 * triage queue, so it is modelled as the absence of a value rather than as a
 * fourth status.
 */
class Bizen_AI_Status {

	public const META_STATUS = '_bizen_ai_status';
	public const META_SOURCE = '_bizen_ai_status_source';

	public const NONE        = 'none';
	public const GENERATED   = 'generated';
	public const MANIPULATED = 'manipulated';

	/** Statuses that put a badge on the front end. */
	private const BADGED = [ self::GENERATED, self::MANIPULATED ];

	/** @return array<string, string> status => human label */
	public static function labels(): array {
		return [
			self::NONE        => __( 'No AI', 'bizen-toolkit' ),
			self::GENERATED   => __( 'AI generated', 'bizen-toolkit' ),
			self::MANIPULATED => __( 'AI modified', 'bizen-toolkit' ),
		];
	}

	public static function label( string $status ): string {
		return self::labels()[ $status ] ?? '';
	}

	public static function is_valid( string $status ): bool {
		return isset( self::labels()[ $status ] );
	}

	public static function needs_badge( string $status ): bool {
		return in_array( $status, self::BADGED, true );
	}

	/** Empty string when the attachment has never been reviewed. */
	public static function get( int $attachment_id ): string {
		$status = (string) get_post_meta( $attachment_id, self::META_STATUS, true );

		return self::is_valid( $status ) ? $status : '';
	}

	/** $source is 'auto' for a status read out of the file, 'manual' for a human decision. */
	public static function set( int $attachment_id, string $status, string $source = 'manual' ): bool {
		if ( ! self::is_valid( $status ) ) {
			return false;
		}

		update_post_meta( $attachment_id, self::META_STATUS, $status );
		update_post_meta( $attachment_id, self::META_SOURCE, 'auto' === $source ? 'auto' : 'manual' );

		return true;
	}

	public static function source( int $attachment_id ): string {
		return (string) get_post_meta( $attachment_id, self::META_SOURCE, true );
	}
}
