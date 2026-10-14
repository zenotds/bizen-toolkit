<?php

namespace Bizen\MediaManager\Support;

defined( 'ABSPATH' ) || exit;

use Bizen\MediaManager\Model\Folder as FolderModel;

class DocumentGallery {
	public function __construct() {
		add_action( 'init', array( $this, 'init' ) );
	}

	public function init() {
		if ( ! defined( 'DG_VERSION' ) || version_compare( DG_VERSION, '4.3.2', '<=' ) ) {
			return;
		}
		add_action( 'dg_query', array( $this, 'dg_query' ), 10, 4 );
	}

	public function dg_query( &$query, $taxa, &$excluded_keys, &$errs ) {
		if ( ! empty( $taxa['fbv'] ) ) {
			$excluded_keys[] = 'fbv';

			if ( is_null( FolderModel::findById( $taxa['fbv'] ) ) ) {
				$errs[] = __( 'This folder ID does not exist, please check again.', 'bizen-toolkit' );
				return;
			}

			$query['fbv']              = $taxa['fbv'];
			$query['suppress_filters'] = false;
		}
	}
}