<?php
namespace Bizen\MediaManager\Support;

use Bizen\MediaManager\Controller\Controller;

defined( 'ABSPATH' ) || exit;

class Polylang extends Controller {
	private static $instance = null;
	private $active;
	private $lang;
	private $lang_id = null;

	public function __construct() {
		global $polylang;

		$this->active = function_exists( 'pll_get_post_translations' );
		if ( $this->active ) {
			if ( $polylang->options['media_support'] == 1 ) {
				$this->lang = PLL()->model->get_language( get_user_meta( get_current_user_id(), 'pll_filter_content', true ) );
				if ( $this->lang ) {
					$this->lang_id = $this->lang->term_id;
				}
				if ( $this->lang_id != null ) {
					add_filter( 'fbv_speedup_get_count_query', '__return_true' );
				}
				add_filter( 'fbv_ids_assigned_to_folder', array( $this, 'assigned_to_folder' ), 10, 2 );
				add_filter( 'fbv_all_folders_and_count', array( $this, 'all_folders_and_count_query' ), 10, 2 );
				add_filter( 'fbv_data', array( $this, 'fbv_data' ), 10, 1 );
				add_filter( 'fbv_post_type_term_counter', array( $this, 'fbv_post_type_term_counter' ), 10, 5 );
				add_filter( 'fbv_post_type_all_counter', array( $this, 'fbv_post_type_all_counter' ), 10, 3 );
			}
		}
		
		// Store singleton instance
		if ( self::$instance === null ) {
			self::$instance = $this;
		}
	}
	public static function applyCountQuery( $query, $folder_id, $lang = null ) {
		// Check if Polylang is active and has media support
		if ( ! function_exists( 'pll_get_post_translations' ) ) {
			return $query;
		}
		
		global $polylang;
		if ( ! isset( $polylang->options['media_support'] ) || $polylang->options['media_support'] != 1 ) {
			return $query;
		}
		
		// Get or create instance
		if ( self::$instance === null ) {
			return $query; // Instance not initialized yet, return original query
		}
		
		// Call instance method
		return self::$instance->fbv_get_count_query( $query, $folder_id, $lang );
	}

	public function fbv_data( $data ) {
		$data['pll_lang'] = \pll_current_language( 'slug' );
		$data['icl_lang'] = '';

		return $data;
	}

	public function fbv_post_type_term_counter( $post_type, $join, $where, $groupby, $lang ) {
		global $wpdb;

		if ( empty( $lang ) ) {
			$lang_term_taxonomy_ids = $this->all_langs_where();
		} else {
			$lang_term_taxonomy_ids = intval( $this->get_preferred_language( $lang ) );
		}

		$join .= " INNER JOIN {$wpdb->term_relationships} AS tr1 ON tr1.object_id = term_relationships.object_id AND tr1.term_taxonomy_id IN ($lang_term_taxonomy_ids)";

		return array(
			'join'    => $join,
			'where'   => $where,
			'groupby' => $groupby,
		);
	}

	private function all_langs_where() {
		$term_taxonomy_ids = array();

		$all_langs = \pll_languages_list();

		foreach ( $all_langs as $slug ) {
			$term_taxonomy_ids[] = $this->get_preferred_language( $slug );
		}

		return implode( ',', array_map( 'intval', $term_taxonomy_ids ) );
	}


	public function fbv_post_type_all_counter( $query, $post_type, $lang ) {
		global $wpdb;

		if ( empty( $lang ) ) {
			$lang_term_taxonomy_ids = $this->all_langs_where();
		} else {
			$lang_term_taxonomy_ids = intval( $this->get_preferred_language( $lang ) );
		}

		$query = $wpdb->prepare(
            "SELECT COUNT(ID) 
			FROM $wpdb->posts AS posts
			INNER JOIN {$wpdb->prefix}term_relationships AS term_relationships ON term_relationships.object_id = posts.ID
			WHERE posts.post_type = %s AND (posts.post_status = 'publish' OR posts.post_status = 'draft')
			AND term_relationships.term_taxonomy_id IN ($lang_term_taxonomy_ids)
			",
			$post_type
		);

		return $query;
	}

	public function assigned_to_folder( $attachmentIds ) {
		$idArr = array();

		foreach ( $attachmentIds as $id ) {
			$translatedPostIds = \pll_get_post_translations( $id );
			foreach ( $translatedPostIds as $trid ) {
				array_push( $idArr, intval( $trid ) );
			}
		}

		return empty( $idArr ) ? $attachmentIds : $idArr;
	}

	public function get_preferred_language( $lang ) {
		$pll_lang = PLL()->model->get_language( $lang );

		if ( $pll_lang ) {
			if ( version_compare( POLYLANG_VERSION, '3.4', '>=' ) ) {
				return $pll_lang->get_tax_prop( 'language', 'term_taxonomy_id' );
			} else {
				return $pll_lang->term_props['language']['term_taxonomy_id'];
			}
		}
		return $this->lang_id;
	}

	public function fbv_get_count_query( $q, $folder_id, $lang ) {
		$lang_id = $this->lang_id;

		if ( $lang ) {
			$lang_id = $this->get_preferred_language( $lang );
		}

		global $wpdb;
		if ( is_null( $lang_id ) ) {
			return $q;
		} else {
			if ( $folder_id == -1 ) {
				$q  = "SELECT COUNT(tmp.ID) FROM
        (   
            SELECT posts.ID
            FROM $wpdb->posts AS posts
            LEFT JOIN $wpdb->term_relationships AS trs 
            ON posts.ID = trs.object_id
            WHERE posts.post_type = 'attachment' 
            AND posts.ID NOT IN (SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = '_elementor_is_screenshot') ";
				$q .= "AND trs.term_taxonomy_id IN ({$lang_id})";
				$q .= "AND (posts.post_status = 'inherit' OR posts.post_status = 'private')
            GROUP BY posts.ID
        ) as tmp";
			} else {
				if ( $folder_id > 0 ) {
					$q  = "SELECT COUNT(tmp.ID) FROM
          (   
              SELECT posts.ID
              FROM $wpdb->posts AS posts
              LEFT JOIN $wpdb->term_relationships AS trs ON posts.ID = trs.object_id
              RIGHT JOIN {$wpdb->prefix}fbv_attachment_folder as fbv ON (posts.ID = fbv.attachment_id)
              WHERE posts.post_type = 'attachment' 
              AND posts.ID NOT IN (SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = '_elementor_is_screenshot') 
              AND fbv.folder_id = " . (int) $folder_id . ' ';
					$q .= "AND trs.term_taxonomy_id IN ({$lang_id}) ";
					$q .= "AND (posts.post_status = 'inherit' OR posts.post_status = 'private')
              GROUP BY posts.ID
          ) as tmp";
					// exit($q);
				}
			}
			return $q;
		}
	}

	public function all_folders_and_count_query( $query, $lang ) {
		global $wpdb;

		$lang_id = $this->lang_id;

		if ( $lang ) {
			$lang_id = $this->get_preferred_language( $lang );
		}

		$check_author = apply_filters( 'fbv_will_check_author', true );

		$select = '';
		$join   = '';
		$where  = '';
		if ( is_null( $lang_id ) ) {
			$select = "SELECT fbva.folder_id as folder_id, count(DISTINCT(fbva.attachment_id)) as counter
                  FROM {$wpdb->prefix}fbv_attachment_folder AS fbva";
		} else {
			$select = "SELECT fbva.folder_id as folder_id, count(fbva.attachment_id) as counter
                  FROM {$wpdb->prefix}fbv_attachment_folder AS fbva";
			$join  .= " INNER JOIN {$wpdb->term_relationships} AS trs ON fbva.attachment_id = trs.object_id ";
			$where .= " AND trs.term_taxonomy_id IN ({$lang_id}) ";
		}

		$join .= " INNER JOIN {$wpdb->prefix}fbv as fbv ON fbv.id = fbva.folder_id ";
		$join .= " INNER JOIN {$wpdb->posts} as posts ON posts.ID = fbva.attachment_id ";

		// Ensure per-folder counts exclude the same attachments as Tree::getCount()
		// (e.g. Elementor screenshots with meta_key `_elementor_is_screenshot`)
		$where .= " WHERE posts.post_type = 'attachment' AND (posts.post_status = 'inherit' OR posts.post_status = 'private') ";
		$where .= " AND posts.ID NOT IN (SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = '_elementor_is_screenshot') ";
		if( $check_author ) {
			$where .= $wpdb->prepare( ' AND fbv.created_by = %d', apply_filters( 'fbv_folder_created_by', '0' ) );
		}
		$where .= ' GROUP BY fbva.folder_id';

		$query = $select . $join . $where;
		return $query;
	}
}