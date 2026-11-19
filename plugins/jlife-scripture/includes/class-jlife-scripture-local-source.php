<?php
/**
 * Local source (#139): text imported into the verses table from a
 * public-domain dataset (Kinh Thánh 1925 from eBible.org `vie1934`). No
 * network and no cache: the table is the store.
 *
 * @package jlife-scripture
 */

defined( 'ABSPATH' ) || exit;

/**
 * Reads verses from {base_prefix}jlife_scripture_verses.
 */
class Jlife_Scripture_Local_Source implements Jlife_Scripture_Source {

	/**
	 * Registry source value.
	 *
	 * @return string
	 */
	public function id() {
		return 'local';
	}

	/**
	 * Local text needs no cache.
	 *
	 * @return bool
	 */
	public function cacheable() {
		return false;
	}

	/**
	 * Verses for each segment from the version's dataset (source_id).
	 *
	 * @param array $version  Registry version entry.
	 * @param array $segments Parsed segments.
	 * @return array|WP_Error
	 */
	public function fetch( array $version, array $segments ) {
		global $wpdb;
		$table   = jlife_scripture_table( 'verses' );
		$dataset = (string) $version['source_id'];
		$out     = array();
		foreach ( $segments as $s ) {
			$cs = (int) $s['chapter_start'];
			$ce = (int) $s['chapter_end'];
			if ( null === $s['verse_start'] ) {
				$where = $wpdb->prepare( 'chapter BETWEEN %d AND %d', $cs, $ce );
			} elseif ( $cs === $ce ) {
				$where = $wpdb->prepare( 'chapter = %d AND verse BETWEEN %d AND %d', $cs, (int) $s['verse_start'], (int) $s['verse_end'] );
			} else {
				$where = $wpdb->prepare(
					'( ( chapter = %d AND verse >= %d ) OR ( chapter > %d AND chapter < %d ) OR ( chapter = %d AND verse <= %d ) )',
					$cs,
					(int) $s['verse_start'],
					$cs,
					$ce,
					$ce,
					(int) $s['verse_end']
				);
			}
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- the plugin's own table; $where is built with prepare() above.
			$rows = $wpdb->get_results( $wpdb->prepare( "SELECT chapter, verse, text FROM {$table} WHERE dataset = %s AND book = %s AND {$where} ORDER BY chapter, verse", $dataset, (string) $s['usfm'] ), ARRAY_A );
			if ( ! is_array( $rows ) || array() === $rows ) {
				return new WP_Error( 'jlife_scripture_not_found', __( 'This passage is not available here.', 'jlife-scripture' ) );
			}
			$out[] = array_map(
				static fn( $r ) => array(
					'chapter' => (int) $r['chapter'],
					'verse'   => (int) $r['verse'],
					'text'    => (string) $r['text'],
				),
				$rows
			);
		}
		return $out;
	}
}
