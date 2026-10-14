<?php
namespace Bizen\MediaManager\Classes\Attachment;

defined( 'ABSPATH' ) || exit;

use Bizen\MediaManager\Model\UserSettingModel;
use Bizen\MediaManager\Classes\Helpers;

class AttachmentOrder {
	/** Columns the saved "Auto sort files by" preference may name (wp_posts.post_*). */
	const DEFAULT_SORT_COLUMNS = array( 'name', 'title', 'date', 'modified', 'author' );

	private $query;
	private $clauses;
	private $userSettingModel;

	public function __construct( $query, $clauses ) {
		$this->query            = $query;
		$this->clauses          = $clauses;
		$this->userSettingModel = UserSettingModel::getInstance();
	}

	/**
	 * Both the order and the saved preference reach the SQL as-is, and the
	 * preference is written by any user who can upload: each is reduced to a
	 * known column and ASC/DESC first.
	 */
	public function apply_order() {
		global $wpdb;

		$meta_key     = AttachmentSize::META_KEY;
		$order        = self::direction( $this->query->get( 'order' ) );
		$orderby      = $this->query->get( 'orderby' );
		$option_order = $this->userSettingModel->get( 'DEFAULT_SORT_FILES' );

		if ( $orderby === $meta_key && '' !== (string) $this->query->get( 'order' ) ) {
			$this->clauses['join']   .= $wpdb->prepare( " LEFT JOIN {$wpdb->postmeta} AS fbmt ON ({$wpdb->posts}.ID = fbmt.post_id AND fbmt.meta_key = %s) ", $meta_key );
			$this->clauses['orderby'] = " fbmt.meta_value + 0 {$order} ";
		}

		// File names sort naturally (img2 before img10): numeric prefix first, then the
		// name, both in the chosen direction — the direction used to apply to the name only.
		if ( $orderby === 'fb_filename' ) {
			$this->clauses['fields'] .= ' ,SUBSTRING_INDEX(postmeta.meta_value, \'/\', -1) as postfilename ';
			$this->clauses['join']   .= " LEFT JOIN {$wpdb->postmeta} AS postmeta ON ({$wpdb->posts}.ID = postmeta.post_id AND postmeta.meta_key = '_wp_attached_file') ";
			$this->clauses['orderby'] = " CAST(postfilename AS UNSIGNED) {$order}, postfilename {$order} ";
		}

		// "By name" means what the library shows, the title. WordPress reads "name" as the
		// slug, which carries -2/-scaled suffixes and drifts from the title after a rename.
		if ( $orderby === 'name' ) {
			$this->clauses['orderby'] = " {$wpdb->posts}.post_title {$order}, {$wpdb->posts}.post_name {$order} ";
		}

		if ( Helpers::isListMode() && is_array( $option_order ) && in_array( $option_order['orderby'] ?? '', self::DEFAULT_SORT_COLUMNS, true ) ) {
			if ( '' === $orderby ) {
				$column                   = 'name' === $option_order['orderby'] ? 'title' : $option_order['orderby'];
				$this->clauses['orderby'] = "{$wpdb->posts}.post_{$column} " . self::direction( $option_order['order'] ?? '' );
			}
		}

		return $this->clauses;
	}

	private static function direction( $order ) {
		return 'ASC' === strtoupper( (string) $order ) ? 'ASC' : 'DESC';
	}
}
