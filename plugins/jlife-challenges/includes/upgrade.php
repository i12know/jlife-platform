<?php
/**
 * Data upgrade for the single-text entry (#98): the four SOAP columns on
 * entries, revisions and group shares become one `body`, joined under the four
 * headings with line breaks kept. Runs from jlife_challenges_install_tables().
 *
 * Pre-launch data is test-only, but the step is still careful: it only drops
 * the old columns after every row that had text has been copied.
 *
 * @package jlife-challenges
 */

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQL.NotPrepared -- the plugin's own tables; names come from $wpdb->prefix and are checked against a strict pattern.

/**
 * The old per-part columns, in SOAP order, and the letter each one became.
 */
const JLIFE_CHALLENGES_LEGACY_COLUMNS = array(
	'scripture'   => 'S',
	'observation' => 'O',
	'application' => 'A',
	'prayer'      => 'P',
);

/**
 * Is this a table name we can safely put in a query?
 *
 * @param string $table Table name.
 * @return bool
 */
function jlife_challenges_safe_table( $table ) {
	return 1 === preg_match( '/^[A-Za-z0-9_]+$/', (string) $table );
}

/**
 * Column names of a table (empty if it doesn't exist).
 *
 * @param string $table Full table name.
 * @return string[]
 */
function jlife_challenges_table_columns( $table ) {
	global $wpdb;
	if ( ! jlife_challenges_safe_table( $table ) ) {
		return array();
	}
	$cols = $wpdb->get_col( "SHOW COLUMNS FROM `{$table}`" );
	return is_array( $cols ) ? array_map( 'strval', $cols ) : array();
}

/**
 * Join one old row's parts into one text under the English headings.
 *
 * @param array $row        Row with the old part columns (any may be missing or null).
 * @param bool  $skip_empty Leave out parts with no text (group shares only held what was chosen).
 * @return string '' when every part is empty.
 */
function jlife_challenges_join_legacy_row( $row, $skip_empty = false ) {
	$sections = array();
	$headings = array();
	foreach ( JLIFE_CHALLENGES_LEGACY_COLUMNS as $column => $letter ) {
		$text                = trim( (string) ( $row[ $column ] ?? '' ) );
		$sections[ $letter ] = $text;
		if ( ! $skip_empty || '' !== $text ) {
			$headings[ $letter ] = jlife_challenges_soap_headings_en()[ $letter ];
		}
	}
	if ( '' === implode( '', $sections ) ) {
		return '';
	}
	return jlife_challenges_join_sections( $sections, $headings );
}

/**
 * Copy old part columns into `body` for rows that have no body yet.
 *
 * @param string $table      Full table name (must have `id` and `body`).
 * @param bool   $skip_empty See jlife_challenges_join_legacy_row().
 * @return array { migrated: int, failed: int }
 */
function jlife_challenges_copy_legacy_to_body( $table, $skip_empty = false ) {
	global $wpdb;
	$columns = jlife_challenges_table_columns( $table );
	$legacy  = array_values( array_intersect( array_keys( JLIFE_CHALLENGES_LEGACY_COLUMNS ), $columns ) );
	$result  = array(
		'migrated' => 0,
		'failed'   => 0,
	);
	if ( ! in_array( 'body', $columns, true ) || ! $legacy ) {
		return $result;
	}
	$select = '`id`, `' . implode( '`, `', $legacy ) . '`';
	$last   = 0;
	do {
		$rows  = $wpdb->get_results( $wpdb->prepare( "SELECT {$select} FROM `{$table}` WHERE id > %d AND (body IS NULL OR body = '') ORDER BY id LIMIT 200", $last ), ARRAY_A );
		$rows  = is_array( $rows ) ? $rows : array();
		$fetch = count( $rows );
		foreach ( $rows as $row ) {
			$last = (int) $row['id'];
			$body = jlife_challenges_join_legacy_row( $row, $skip_empty );
			if ( '' === $body ) {
				continue;
			}
			if ( false === $wpdb->update( $table, array( 'body' => $body ), array( 'id' => $last ) ) ) {
				++$result['failed'];
			} else {
				++$result['migrated'];
			}
		}
	} while ( 200 === $fetch );
	return $result;
}

/**
 * Drop old columns that still exist.
 *
 * @param string   $table   Full table name.
 * @param string[] $columns Column names to drop.
 */
function jlife_challenges_drop_columns( $table, $columns ) {
	global $wpdb;
	if ( ! jlife_challenges_safe_table( $table ) ) {
		return;
	}
	foreach ( array_intersect( $columns, jlife_challenges_table_columns( $table ) ) as $column ) {
		if ( jlife_challenges_safe_table( $column ) ) {
			$wpdb->query( "ALTER TABLE `{$table}` DROP COLUMN `{$column}`" );
		}
	}
}

/**
 * Run the four-fields-to-one-text upgrade on entries, revisions and shares.
 *
 * @return int Rows migrated.
 */
function jlife_challenges_upgrade_data() {
	$legacy = array_keys( JLIFE_CHALLENGES_LEGACY_COLUMNS );
	$total  = 0;
	$jobs   = array(
		array(
			'table'      => jlife_challenges_table( 'soap_entries' ),
			'skip_empty' => false,
			'drop'       => $legacy,
		),
		array(
			'table'      => jlife_challenges_table( 'soap_revisions' ),
			'skip_empty' => false,
			'drop'       => $legacy,
		),
		array(
			'table'      => jlife_challenges_table( 'group_shares' ),
			'skip_empty' => true,
			'drop'       => array_merge( $legacy, array( 'fields' ) ),
		),
	);
	foreach ( $jobs as $job ) {
		$r      = jlife_challenges_copy_legacy_to_body( $job['table'], $job['skip_empty'] );
		$total += $r['migrated'];
		if ( 0 === $r['failed'] ) {
			jlife_challenges_drop_columns( $job['table'], $job['drop'] );
		}
	}
	return $total;
}
