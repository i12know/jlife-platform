<?php
/**
 * Data upgrades, both run from jlife_challenges_install_tables() and both
 * idempotent:
 *
 * 1. Single-text entry (#98): the four SOAP columns on entries, revisions and
 *    group shares become one `body`, joined under the four headings with line
 *    breaks kept. Pre-launch data is test-only, but the step is still careful:
 *    it only drops the old columns after every row that had text has been
 *    copied.
 * 2. Group membership (#112, D14 changed): each enrollment's single
 *    `enrollments.group_id` becomes a row in `group_members`. See
 *    jlife_challenges_upgrade_memberships() for the column's fate.
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

/**
 * Copy each enrollment's single group (`enrollments.group_id`, D14 before #112)
 * into a `group_members` row. Idempotent and safe to run on every deploy:
 *
 * - Only enrollments with a group (group_id > 0) whose group still exists, and
 *   that have no membership row for that person and group (in ANY status), are
 *   copied. A membership that was later ended (left or removed) is therefore
 *   never resurrected by a re-run.
 * - An active enrollment becomes an active membership; any other enrollment
 *   status (removed) becomes a removed membership, with its left_at kept.
 *   joined_at is the enrollment's joined time; the role is `member` (nobody was
 *   a starter before PR2 of #112).
 * - INSERT IGNORE on UNIQUE (group_id, user_id) makes a concurrent run harmless.
 *
 * What happens to `enrollments.group_id`: it is RETIRED, not dropped. It stays
 * as `NOT NULL DEFAULT 0`, nothing writes it any more (new enrollments get 0,
 * "solo") and nothing reads it except this upgrade; the gate and every feed use
 * `group_members` only. It is kept for one release as the migration's source and
 * so a rollback of the plugin code still finds each person's old group. It is not
 * zeroed after copying, because the membership-exists check above is what makes
 * the copy idempotent. A later release drops it once the migration has been
 * verified on every deployment.
 *
 * @return int Membership rows created.
 */
function jlife_challenges_upgrade_memberships() {
	global $wpdb;
	$e = jlife_challenges_table( 'enrollments' );
	$m = jlife_challenges_table( 'group_members' );
	$g = jlife_challenges_table( 'groups' );
	if ( ! jlife_challenges_safe_table( $e ) || ! jlife_challenges_safe_table( $m ) || ! jlife_challenges_safe_table( $g ) ) {
		return 0;
	}
	if ( ! in_array( 'group_id', jlife_challenges_table_columns( $e ), true ) || ! jlife_challenges_table_columns( $m ) ) {
		return 0;
	}
	$wpdb->query(
		"INSERT IGNORE INTO `{$m}` (campaign_id, enrollment_id, user_id, group_id, role, status, joined_at, left_at)
		SELECT e.campaign_id, e.id, e.user_id, e.group_id, 'member', IF(e.status = 'active', 'active', 'removed'), e.joined, e.left_at
		FROM `{$e}` e
		JOIN `{$g}` g ON g.id = e.group_id
		LEFT JOIN `{$m}` x ON x.group_id = e.group_id AND x.user_id = e.user_id
		WHERE e.group_id > 0 AND x.id IS NULL"
	);
	$created = max( 0, (int) $wpdb->rows_affected );
	// dbDelta does not add a default to an existing column: give the retired column its DEFAULT 0 explicitly, once.
	$col = $wpdb->get_row( "SHOW COLUMNS FROM `{$e}` LIKE 'group_id'", ARRAY_A );
	if ( is_array( $col ) && null === $col['Default'] ) {
		$wpdb->query( "ALTER TABLE `{$e}` ALTER COLUMN `group_id` SET DEFAULT 0" );
	}
	return $created;
}
