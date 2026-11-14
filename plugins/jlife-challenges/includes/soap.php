<?php
/**
 * SOAP entries and scoring (docs/challenges-engine-design.md §3, §3a).
 *
 * - An entry is ONE free text (#98), private to its author. Drafts save
 *   server-side. New entries start from the S/O/A/P heading template.
 * - Submitting saves the text first, then runs it through the campaign's
 *   scoring engine (scoring-engine.php) synchronously. If the engine passes
 *   it, the first passing submission for a campaign day writes the ledger row
 *   once (database UNIQUE key): 1 point if on time (D1), 0 with a reason if
 *   late (D2). If it doesn't, the text stays saved with no point and the
 *   reasons are returned; submitting again after editing can still earn it.
 *   Later edits keep a revision history and never change the ledger (D3), and
 *   a point is never taken back.
 * - Personal entries (campaign 0) are unscored, one per calendar day (P4).
 * - Never pre-filled with Bible text.
 *
 * @package jlife-challenges
 */

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.DB.DirectDatabaseQuery -- custom tables are this plugin's data layer; every query goes through the gate; WordPress has no API for them.

const JLIFE_CHALLENGES_BODY_MAX   = 20000;
const JLIFE_CHALLENGES_LATE_RULE  = 'after_grace_window';
const JLIFE_CHALLENGES_DAY_FORMAT = '/^\d{4}-\d{2}-\d{2}$/';

/**
 * Normalize an entry's text: plain text, line breaks kept as "\n", trimmed, capped.
 *
 * @param mixed $text Raw text.
 * @return string
 */
function jlife_challenges_clean_text( $text ) {
	$text = sanitize_textarea_field( is_scalar( $text ) ? (string) $text : '' );
	$text = str_replace( array( "\r\n", "\r" ), "\n", $text );
	return mb_substr( trim( $text ), 0, JLIFE_CHALLENGES_BODY_MAX );
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
	$wpdb->query( $wpdb->prepare( 'INSERT IGNORE INTO ' . jlife_challenges_table( 'soap_entries' ) . " (user_id, campaign_id, day_key, body, status, revision, created, updated) VALUES (%d, %d, %s, '', 'draft', 0, %s, %s)", (int) $user_id, (int) $campaign_id, (string) $day_key, $now, $now ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- table name from $wpdb->prefix.
	return jlife_challenges_find_entry( $user_id, $campaign_id, $day_key );
}

/**
 * Save a draft (any text, even none). Only while the entry is a draft. A draft
 * is never shared, whatever else is on the form.
 *
 * @param int         $user_id     Author.
 * @param int         $campaign_id Campaign (0 = personal).
 * @param string|null $day_key     Day (null = today).
 * @param string      $text        The entry text.
 * @return int|WP_Error Entry ID.
 */
function jlife_challenges_save_draft( $user_id, $campaign_id, $day_key, $text ) {
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
		array(
			'body'    => jlife_challenges_clean_text( $text ),
			'updated' => current_time( 'mysql', true ),
		),
		array( 'id' => (int) $entry['id'] )
	);
	return (int) $entry['id'];
}

/**
 * Submit an entry (or save changes to a submitted one): save the text, then
 * score it with the campaign's engine. Idempotent: repeating a submission never
 * creates a second point. An entry that doesn't pass is still saved.
 *
 * @param int         $user_id     Author.
 * @param int         $campaign_id Campaign (0 = personal).
 * @param string|null $day_key     Day (null = today).
 * @param string      $text        The entry text.
 * @return array|WP_Error { entry_id, day_key, revision, points, status, reason, first_submission, passes, reasons, engine, engine_version }
 */
function jlife_challenges_submit( $user_id, $campaign_id, $day_key, $text ) {
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
	$clean    = jlife_challenges_clean_text( $text );
	$now      = current_time( 'mysql', true );
	$revision = (int) $entry['revision'] + 1;
	$table    = jlife_challenges_table( 'soap_entries' );

	// 1. Save the text first, whatever the engine says.
	global $wpdb;
	$wpdb->update(
		$table,
		array(
			'body'     => $clean,
			'revision' => $revision,
			'updated'  => $now,
		),
		array( 'id' => (int) $entry['id'] )
	);
	$wpdb->insert(
		jlife_challenges_table( 'soap_revisions' ),
		array(
			'entry_id' => (int) $entry['id'],
			'revision' => $revision,
			'body'     => $clean,
			'saved'    => $now,
		)
	);

	// 2. Then score it, synchronously.
	$score = jlife_challenges_score_entry(
		$clean,
		array(
			'campaign' => $day['campaign'],
			'day_key'  => $day['day_key'],
			'index'    => $day['index'],
		)
	);
	$first = $score['passes'] && 'draft' === $entry['status'];
	if ( $score['passes'] ) {
		$wpdb->update(
			$table,
			array_merge(
				array( 'status' => 'submitted' ),
				$first ? array( 'submitted' => $now ) : array()
			),
			array( 'id' => (int) $entry['id'] )
		);
	}

	$result = array(
		'entry_id'         => (int) $entry['id'],
		'day_key'          => $day['day_key'],
		'revision'         => $revision,
		'points'           => 0,
		'status'           => 'unscored',
		'reason'           => '',
		'first_submission' => $first,
		'passes'           => $score['passes'],
		'reasons'          => $score['reasons'],
		'engine'           => $score['engine'],
		'engine_version'   => $score['version'],
	);
	if ( $day['campaign'] ) {
		$ledger = $score['passes']
			? jlife_challenges_record_score( $day['campaign'], $user_id, $day['index'], (int) $entry['id'], $score )
			: jlife_challenges_ledger_row( (int) $day['campaign']['id'], $user_id, $day['day_key'] );
		if ( $ledger ) {
			$result = array_merge( $result, $ledger );
		}
	}
	return $result;
}

/**
 * The day's ledger row for a participant, as { points, status, reason }, or null.
 *
 * @param int    $campaign_id Campaign.
 * @param int    $user_id     Participant.
 * @param string $day_key     Day key.
 * @return array|null
 */
function jlife_challenges_ledger_row( $campaign_id, $user_id, $day_key ) {
	global $wpdb;
	$row = $wpdb->get_row( $wpdb->prepare( 'SELECT points, status, reason FROM ' . jlife_challenges_table( 'score_ledger' ) . ' WHERE campaign_id = %d AND user_id = %d AND day_key = %s', (int) $campaign_id, (int) $user_id, (string) $day_key ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- table name from $wpdb->prefix.
	return $row ? array(
		'points' => (int) $row['points'],
		'status' => (string) $row['status'],
		'reason' => (string) $row['reason'],
	) : null;
}

/**
 * Write the day's ledger row once (UNIQUE campaign/user/day) and return it. The
 * row records which scoring engine and version passed the entry. It is only
 * ever added: a later submission or engine never changes or removes it.
 *
 * @param array $campaign Campaign row.
 * @param int   $user_id  Participant.
 * @param int   $index    Day index.
 * @param int   $entry_id Entry ID.
 * @param array $score    Engine result ({ engine, version }).
 * @return array { points, status, reason }
 */
function jlife_challenges_record_score( $campaign, $user_id, $index, $entry_id, $score ) {
	global $wpdb;
	$on_time = jlife_challenges_is_on_time( $campaign, $index );
	$day_key = jlife_challenges_day_key( $index );
	// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared -- custom table; name from $wpdb->prefix; INSERT IGNORE relies on the UNIQUE key.
	$wpdb->query(
		$wpdb->prepare(
			'INSERT IGNORE INTO ' . jlife_challenges_table( 'score_ledger' ) . " (campaign_id, user_id, day_key, entry_id, points, status, reason, source, engine, engine_version, created) VALUES (%d, %d, %s, %d, %d, %s, %s, 'platform', %s, %s, %s)",
			(int) $campaign['id'],
			(int) $user_id,
			$day_key,
			(int) $entry_id,
			$on_time ? 1 : 0,
			$on_time ? 'earned' : 'late_no_point',
			$on_time ? '' : JLIFE_CHALLENGES_LATE_RULE,
			(string) $score['engine'],
			(string) $score['version'],
			current_time( 'mysql', true )
		)
	);
	// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared
	$row = jlife_challenges_ledger_row( (int) $campaign['id'], $user_id, $day_key );
	return $row ? $row : array(
		'points' => 0,
		'status' => '',
		'reason' => '',
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
