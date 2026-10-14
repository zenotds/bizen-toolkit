<?php

namespace Bizen\MediaManager\Support;

use Bizen\MediaManager\Support\AdminColumns\FolderColumn;

defined('ABSPATH') || exit;


class AdminColumns
{
	public function __construct()
	{
		if( defined( 'ACP_VERSION' ) && version_compare( ACP_VERSION, '7.0.10', '<' ) ) {
			return;
		}
		add_filter('ac/column/types', array($this, 'register_column_type'), 10, 3);
	}

	public function register_column_type(array $factories, $table_screen, $container = null): array
	{
		// Only add column for Media screen
		if ((string) $table_screen->get_id() !== 'wp-media') {
			return $factories;
		}

		// Include custom column classes
		require_once __DIR__ . '/AdminColumns/FolderFormatter.php';
		require_once __DIR__ . '/AdminColumns/FolderColumn.php';

		// Register the folder column
		$factories[] = FolderColumn::class;

		return $factories;
	}
}