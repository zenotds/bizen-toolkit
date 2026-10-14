<?php

namespace Bizen\MediaManager\Utils;

defined( 'ABSPATH' ) || exit;

/**
 * Loads an entry of the compiled bundle in assets/dist as an ES module, with
 * modulepreload hints for the chunks it imports. File names carry a content
 * hash, so the manifest is the only place that knows them.
 */
class Vite {
	const HANDLE_PREFIX = 'module/bizen-media-manager/';

	public static function base_path() {
		return BIZEN_MM_PLUGIN_URL . 'assets/dist/';
	}

	/**
	 * @param string $script Manifest key of the entry.
	 * @return string Script handle, for wp_localize_script().
	 */
	public static function enqueue_vite( string $script = 'main.tsx' ) {
		self::enqueue_preload( $script );

		$handle = self::HANDLE_PREFIX . $script;
		wp_enqueue_script( $handle, self::asset_url( $script ), array(), null, true );

		add_filter(
			'script_loader_tag',
			function ( $tag, $tag_handle, $src ) {
				if ( 0 === strpos( $tag_handle, self::HANDLE_PREFIX ) ) {
					$tag = '<script type="module" src="' . esc_url( $src ) . '" id="' . esc_attr( $tag_handle ) . '-js"></script>';
				}
				return $tag;
			},
			10,
			3
		);

		return $handle;
	}

	private static function enqueue_preload( $script ) {
		add_action(
			'admin_print_footer_scripts',
			function () use ( $script ) {
				foreach ( self::imports_urls( $script ) as $url ) {
					echo '<link rel="modulepreload" href="' . esc_url( $url ) . '">';
				}
			}
		);
	}

	private static function get_manifest(): array {
		static $manifest = null;

		if ( null === $manifest ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
			$manifest = json_decode( (string) file_get_contents( BIZEN_MM_PLUGIN_PATH . 'assets/dist/manifest.json' ), true ) ?: array();
		}

		return $manifest;
	}

	private static function asset_url( string $entry ): string {
		$manifest = self::get_manifest();

		return self::base_path() . ( $manifest[ $entry ]['file'] ?? $entry );
	}

	private static function imports_urls( string $entry ): array {
		$urls     = array();
		$manifest = self::get_manifest();

		foreach ( $manifest[ $entry ]['imports'] ?? array() as $import ) {
			if ( isset( $manifest[ $import ]['file'] ) ) {
				$urls[] = self::base_path() . $manifest[ $import ]['file'];
			}
		}

		return $urls;
	}
}
