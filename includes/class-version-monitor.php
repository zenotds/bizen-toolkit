<?php
defined( 'ABSPATH' ) || exit;

class Bizen_Version_Monitor {

	const CRON_HOOK = 'bizen_toolkit_version_check';
	const OPTION    = 'bizen_toolkit_upstream_versions';

	public static function schedule_cron(): void {
		if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_schedule_event( time(), 'weekly', self::CRON_HOOK );
		}
	}

	public static function clear_cron(): void {
		wp_clear_scheduled_hook( self::CRON_HOOK );
	}

	/**
	 * Check upstream versions for all monitorable modules.
	 * Supports two sources: WP.org (via source_slug) and GitHub (via source_repo).
	 *
	 * @param Bizen_Module[] $modules
	 */
	public static function check_updates( array $modules ): void {
		$results = get_option( self::OPTION, [] );

		foreach ( $modules as $id => $module ) {
			$result = null;

			if ( $module->get_source_slug() ) {
				$result = self::check_wporg( $module->get_source_slug() );
			} elseif ( $module->get_source_repo() ) {
				$result = self::check_github( $module->get_source_repo() );
			}

			if ( ! $result ) {
				continue;
			}

			$vendored = $module->get_source_version() ?? '0';

			$results[ $id ] = [
				'upstream_version' => $result['version'],
				'vendored_version' => $vendored,
				'has_update'       => version_compare( $result['version'], $vendored, '>' ),
				'last_checked'     => time(),
				'source'           => $result['source'],
				'source_ref'       => $result['ref'],
			];
		}

		update_option( self::OPTION, $results );
	}

	/** Query the WP.org Plugins API. Returns ['version', 'source', 'ref'] or null. */
	private static function check_wporg( string $slug ): ?array {
		$response = wp_remote_get(
			add_query_arg(
				[
					'action'                    => 'plugin_information',
					'request[slug]'             => $slug,
					'request[fields][versions]' => 'false',
				],
				'https://api.wordpress.org/plugins/info/1.2/'
			),
			[ 'timeout' => 10 ]
		);

		if ( is_wp_error( $response ) ) {
			return null;
		}

		$data = json_decode( wp_remote_retrieve_body( $response ) );
		if ( empty( $data->version ) ) {
			return null;
		}

		return [ 'version' => $data->version, 'source' => 'wporg', 'ref' => $slug ];
	}

	/**
	 * Query the GitHub Releases API for the latest release tag.
	 * Works with public repos; no auth required within standard rate limits.
	 *
	 * @param string $repo  Format: "owner/repo"
	 */
	private static function check_github( string $repo ): ?array {
		$response = wp_remote_get(
			'https://api.github.com/repos/' . $repo . '/releases/latest',
			[
				'timeout' => 10,
				'headers' => [ 'User-Agent' => 'Bizen-Toolkit/' . BIZEN_TOOLKIT_VERSION ],
			]
		);

		if ( is_wp_error( $response ) ) {
			return null;
		}

		$data = json_decode( wp_remote_retrieve_body( $response ) );
		if ( empty( $data->tag_name ) ) {
			return null;
		}

		// Strip leading "v" from tag names (e.g. "v2.2.2" → "2.2.2").
		$version = ltrim( $data->tag_name, 'v' );

		return [ 'version' => $version, 'source' => 'github', 'ref' => $repo ];
	}

	public static function get_results(): array {
		return get_option( self::OPTION, [] );
	}

	public static function trigger_check_now(): void {
		do_action( self::CRON_HOOK );
	}
}
