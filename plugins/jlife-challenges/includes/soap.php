<?php
/**
 * SOAP entries and scoring (docs/challenges-engine-design.md §3).
 *
 * - An entry is private to its author. Drafts save server-side.
 * - Submitting needs all four fields. The first complete submission for a
 *   campaign day writes the ledger row once (database UNIQUE key): 1 point if
 *   on time (D1), 0 with a reason if late (D2). Later edits keep a revision
 *   history and never change the ledger (D3).
 * - Personal entries (campaign 0) are unscored, one per calendar day (P4).
 * - Never pre-filled with Bible text.
 *
 * @package jlife-challenges
 */

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.DB.DirectDatabaseQuery -- custom tables are this plugin's data layer; every query goes through the gate; WordPress has no API for them.

const JLIFE_CHALLENGES_FIELDS     = array( 'scripture', 'observation', 'application', 'prayer' );
const JLIFE_CHALLENGES_FIELD_MAX  = 10000;
const JLIFE_CHALLENGES_LATE_RULE  = 'after_grace_window';
const JLIFE_CHALLENGES_DAY_FORMAT = '/^\d{4}-\d{2}-\d{2}$/';

/**
 * Normalize submitted fields: the four SOAP fields only, plain text, capped.
 *
 * @param array $fields Raw fields.
 * @return array
 */
function jlife_challenges_clean_fields( $fields ) {
	$out = array();
	foreach ( JLIFE_CHALLENGES_FIELDS as $f ) {
		$v         = isset( $fields[ $f ] ) ? sanitize_textarea_field( (string) $fields[ $f ] ) : '';
		$out[ $f ] = mb_substr( trim( $v ), 0, JLIFE_CHALLENGES_FIELD_MAX );
	}
	return $out;
}

/**
 * Resolve and check the day an entry belongs to.
 *
 * @param int         $campaign_id Campaign (0 = personal).
 * @param string|null $day_key     Requested day key (campaign: d###; personal: Y-m-d); null = today.
 * @return array|WP_Error { campaign: ?array, day_key: string, index: int }
 */
function jlife_challenges_resolve_day( $campaign_id, $day_key ) {
	if ( 0 === (int) $campaign_id ) {
		$today = wp_date( 'Y-m-d', jlife_challenges_now()->getTimestamp() );
		$key   = null === $day_key ? $today : (string) $day_key;
		if ( ! preg_match( JLIFE_CHALLENGES_DAY_FORMAT, $key ) || $key > $today ) {
			return new WP_Error( 'jlife_day_invalid', __( 'That day is not available.', 'jlife-challenges' ) );
		}
		return array(
			'campaign' => null,
			'day_key'  => $key,
			'index'    => 0,
		);
	}
	$campaign = jlife_challenges_get_campaign( $campaign_id );
	if ( ! $campaign ) {
		return jlife_challenges_denied();
	}
	$index = null === $day_key ? jlife_challenges_current_day( $campaign ) : jlife_challenges_day_index( $campaign, $day_key );
	if ( $index < 1 || ! jlife_challenges_day_is_open( $campaign, $index ) ) {
		return new WP_Error( 'jlife_day_invalid', __( 'That day is not available.', 'jlife-challenges' ) );
	}
	return array(
		'campaign' => $campaign,
		'day_key'  => jlife_challenges_day_key( $index ),
		'index'    => $index,
	);
}

/**
 * Find an entry row by owner, campaign and day (no gate; internal).
 *
 * @param int    $user_id     Owner.
 * @param int    $campaign_id Campaign.
 * @param string $day_key     Day key.
 * @return array|null
 */
function jlife_challenges_find_entry( $user_id, $campaign_id, $day_key ) {
	global $wpdb;
	$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . jlife_challenges_table( 'soap_entries' ) . ' WHERE user_id = %d AND campaign_id = %d AND day_key = %s', (int) $user_id, (int) $campaign_id, (string) $day_key ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- table name from $wpdb->prefix.
	return $row ? $row : null;
}

/**
 * Create the entry row if missing (race-safe through the UNIQUE key).
 *
 * @param int    $user_id     Owner.
 * @param int    $campaign_id Campaign.
 * @param string $day_key     Day key.
 * @return array Entry row.
 */
function jlife_challenges_ensure_entry( $user_id, $campaign_id, $day_key ) {
	global $wpdb;
	$now = current_time( 'mysql', true );
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- custom table; INSERT IGNORE relies on the UNIQUE key.
	$wpdb->query( $wpdb->prepare( 'INSERT IGNORE INTO ' . jlife_challenges_table( 'soap_entries' ) . " (user_id, campaign_id, day_key, scripture, observation, application, prayer, status, revision, created, updated) VALUES (%d, %d, %s, '', '', '', '', 'draft', 0, %s, %s)", (int) $user_id, (int) $campaign_id, (string) $day_key, $now, $now ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- table name from $wpdb->prefix.
	return jlife_challenges_find_entry( $user_id, $campaign_id, $day_key );
}

/**
 * Save a draft (any fields may be empty). Only while the entry is a draft.
 *
 * @param int         $user_id     Author.
 * @param int         $campaign_id Campaign (0 = personal).
 * @param string|null $day_key     Day (null = today).
 * @param array       $fields      SOAP fields.
 * @return int|WP_Error Entry ID.
 */
function jlife_challenges_save_draft( $user_id, $campaign_id, $day_key, $fields ) {
	if ( ! jlife_challenges_can_write_entry( $user_id, $campaign_id ) ) {
		return jlife_challenges_denied();
	}
	$day = jlife_challenges_resolve_day( $campaign_id, $day_key );
	if ( is_wp_error( $day ) ) {
		return $day;
	}
	$entry = jlife_challenges_ensure_entry( $user_id, $campaign_id, $day['day_key'] );
	if ( ! $entry ) {
		return new WP_Error( 'jlife_db', 'Could not save.' );
	}
	if ( 'draft' !== $entry['status'] ) {
		return new WP_Error( 'jlife_already_submitted', __( 'This entry is already submitted. Use Save changes instead.', 'jlife-challenges' ) );
	}
	global $wpdb;
	$wpdb->update(
		jlife_challenges_table( 'soap_entries' ),
		array_merge( jlife_challenges_clean_fields( $fields ), array( 'updated' => current_time( 'mysql', true ) ) ),
		array( 'id' => (int) $entry['id'] )
	);
	return (int) $entry['id'];
}

/**
 * Submit an entry (or save changes to a submitted one). Idempotent: repeating
 * a submission never creates a second point.
 *
 * @param int         $user_id     Author.
 * @param int         $campaign_id Campaign (0 = personal).
 * @param string|null $day_key     Day (null = today).
 * @param array       $fields      SOAP fields; all four required.
 * @return array|WP_Error { entry_id, day_key, revision, points, status, reason, first_submission }
 */
function jlife_challenges_submit( $user_id, $campaign_id, $day_key, $fields ) {
	if ( ! jlife_challenges_can_write_entry( $user_id, $campaign_id ) ) {
		return jlife_challenges_denied();
	}
	$clean   = jlife_challenges_clean_fields( $fields );
	$missing = array_keys( array_filter( $clean, static fn( $v ) => '' === $v ) );
	if ( $missing ) {
		return new WP_Error(
			'jlife_soap_incomplete',
			__( 'Please fill in all four parts of SOAP before submitting. Your writing has been kept.', 'jlife-challenges' ),
			array( 'missing' => $missing )
		);
	}
	$day = jlife_challenges_resolve_day( $campaign_id, $day_key );
	if ( is_wp_error( $day ) ) {
		return $day;
	}
	$entry = jlife_challenges_ensure_entry( $user_id, $campaign_id, $day['day_key'] );
	if ( ! $entry ) {
		return new WP_Error( 'jlife_db', 'Could not save.' );
	}

	global $wpdb;
	$now      = current_time( 'mysql', true );
	$first    = 'draft' === $entry['status'];
	$revision = (int) $entry['revision'] + 1;
	$update   = array_merge(
		$clean,
		array(
			'status'   => 'submitted',
			'revision' => $revision,
			'updated'  => $now,
		)
	);
	if ( $first ) {
		$update['submitted'] = $now;
	}
	$wpdb->update( jlife_challenges_table( 'soap_entries' ), $update, array( 'id' => (int) $entry['id'] ) );
	$wpdb->insert(
		jlife_challenges_table( 'soap_revisions' ),
		array_merge(
			$clean,
			array(
				'entry_id' => (int) $entry['id'],
				'revision' => $revision,
				'saved'    => $now,
			)
		)
	);

	$result = array(
		'entry_id'         => (int) $entry['id'],
		'day_key'          => $day['day_key'],
		'revision'         => $revision,
		'points'           => 0,
		'status'           => 'unscored',
		'reason'           => '',
		'first_submission' => $first,
	);
	if ( $day['campaign'] ) {
		$ledger = jlife_challenges_record_score( $day['campaign'], $user_id, $day['index'], (int) $entry['id'] );
		$result = array_merge( $result, $ledger );
	}
	return $result;
}

/**
 * Write the day's ledger row once (UNIQUE campaign/user/day) and return it.
 *
 * @param array $campaign Campaign row.
 * @param int   $user_id  Participant.
 * @param int   $index    Day index.
 * @param int   $entry_id Entry ID.
 * @return array { points, status, reason }
 */
function jlife_challenges_record_score( $campaign, $user_id, $index, $entry_id ) {
	global $wpdb;
	$on_time = jlife_challenges_is_on_time( $campaign, $index );
	$day_key = jlife_challenges_day_key( $index );
	// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared -- custom table; name from $wpdb->prefix; INSERT IGNORE relies on the UNIQUE key.
	$wpdb->query(
		$wpdb->prepare(
			'INSERT IGNORE INTO ' . jlife_challenges_table( 'score_ledger' ) . " (campaign_id, user_id, day_key, entry_id, points, status, reason, source, created) VALUES (%d, %d, %s, %d, %d, %s, %s, 'platform', %s)",
			(int) $campaign['id'],
			(int) $user_id,
			$day_key,
			(int) $entry_id,
			$on_time ? 1 : 0,
			$on_time ? 'earned' : 'late_no_point',
			$on_time ? '' : JLIFE_CHALLENGES_LATE_RULE,
			current_time( 'mysql', true )
		)
	);
	$row = $wpdb->get_row( $wpdb->prepare( 'SELECT points, status, reason FROM ' . jlife_challenges_table( 'score_ledger' ) . ' WHERE campaign_id = %d AND user_id = %d AND day_key = %s', (int) $campaign['id'], (int) $user_id, $day_key ), ARRAY_A );
	// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared
	return array(
		'points' => (int) ( $row['points'] ?? 0 ),
		'status' => (string) ( $row['status'] ?? '' ),
		'reason' => (string) ( $row['reason'] ?? '' ),
	);
}

/**
 * Read an entry by ID. Author only; anything else looks like "not found".
 *
 * @param int $viewer_user_id Viewer.
 * @param int $entry_id       Entry ID.
 * @return array|WP_Error
 */
function jlife_challenges_get_entry( $viewer_user_id, $entry_id ) {
	global $wpdb;
	// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared -- table name from $wpdb->prefix.
	$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . jlife_challenges_table( 'soap_entries' ) . ' WHERE id = %d', (int) $entry_id ), ARRAY_A );
	// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared
	if ( ! $row || ! jlife_challenges_can_read_entry( $viewer_user_id, $row ) ) {
		return jlife_challenges_denied();
	}
	return $row;
}

/**
 * The viewer's own entry for a day, or null if none yet.
 *
 * @param int         $user_id     Author.
 * @param int         $campaign_id Campaign (0 = personal).
 * @param string|null $day_key     Day (null = today).
 * @return array|null|WP_Error
 */
function jlife_challenges_get_my_entry( $user_id, $campaign_id, $day_key = null ) {
	if ( ! jlife_challenges_can_write_entry( $user_id, $campaign_id ) ) {
		return jlife_challenges_denied();
	}
	$day = jlife_challenges_resolve_day( $campaign_id, $day_key );
	if ( is_wp_error( $day ) ) {
		return $day;
	}
	return jlife_challenges_find_entry( $user_id, $campaign_id, $day['day_key'] );
}
