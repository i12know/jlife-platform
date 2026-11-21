<?php
/**
 * Rollover, part 1 (docs/challenges-engine-design.md §3d, #180): carry the
 * people of one campaign (the closed pilot) and the "I'll start Dec 20"
 * choices into an upcoming campaign (the official Marathon) on the same site.
 *
 * - Site-scoped, with explicit source and destination IDs (ADR 0001). The
 *   source is only read; only new destination enrollments are written.
 * - An enrollment keeps the user ID, the age band and the consent record.
 *   Nothing else carries: no entries, points, shares, groups (#182), reminder
 *   choices (#181) or guardian consent.
 * - Every enrollment is one INSERT IGNORE on the UNIQUE (campaign_id, user_id)
 *   key, so reruns add nothing and an interrupted run is finished by rerunning.
 * - Results, output and the audit row are counts only.
 *
 * @package jlife-challenges
 */

defined( 'ABSPATH' ) || exit;

/**
 * Load and check the two campaigns. Fails closed, before anything is read or
 * written.
 *
 * @param int  $actor   Organizer.
 * @param int  $from_id Source campaign.
 * @param int  $to_id   Destination campaign.
 * @param bool $strict  Also require a destination that has not started and has
 *                      no entries, points or shares (preview and execute).
 *                      Verify passes false and reports those instead.
 * @return array|WP_Error { from: array, to: array }
 */
function jlife_challenges_rollover_campaigns( $actor, $from_id, $to_id, $strict = true ) {
	if ( ! jlife_challenges_is_organizer( $actor ) ) {
		return jlife_challenges_denied();
	}
	$from = jlife_challenges_get_campaign( (int) $from_id );
	$to   = jlife_challenges_get_campaign( (int) $to_id );
	if ( ! $from || ! $to ) {
		return new WP_Error( 'jlife_rollover_not_found', 'Both --from and --to must be campaign IDs on this site.' );
	}
	if ( (int) $from['id'] === (int) $to['id'] ) {
		return new WP_Error( 'jlife_rollover_same', 'The source and the destination must be different campaigns.' );
	}
	if ( jlife_challenges_is_test_campaign( $from ) || 'draft' === $from['status'] ) {
		return new WP_Error( 'jlife_rollover_source_invalid', 'The source must be a real campaign that has run (not a test campaign or a draft).' );
	}
	$real_destination = ! jlife_challenges_is_test_campaign( $to ) && ! jlife_challenges_is_pilot_campaign( $to ) && 'active' === $to['status'];
	if ( ! $real_destination || ( $strict && 'upcoming' !== jlife_challenges_campaign_phase( $to ) ) ) {
		return new WP_Error( 'jlife_rollover_destination_invalid', 'The destination must be an active campaign that has not started yet, and not a test or pilot campaign.' );
	}
	if ( $strict && array_sum( jlife_challenges_rollover_destination_counts( (int) $to['id'] ) ) > 0 ) {
		return new WP_Error( 'jlife_rollover_destination_not_empty', 'The destination already has SOAP entries, points or shares, so nothing was carried into it.' );
	}
	return array(
		'from' => $from,
		'to'   => $to,
	);
}

/**
 * The destination's participant data that must stay empty before Day 1.
 *
 * @param int $to_id Destination campaign.
 * @return int[] { destination_ledger_rows, destination_entries, destination_shares }
 */
function jlife_challenges_rollover_destination_counts( $to_id ) {
	return array(
		'destination_ledger_rows' => jlife_challenges_close_count( 'score_ledger', $to_id ),
		'destination_entries'     => jlife_challenges_close_count( 'soap_entries', $to_id ),
		'destination_shares'      => jlife_challenges_close_count( 'group_shares', $to_id ),
	);
}

/**
 * Row counts of the source campaign's data, to show the rollover left it alone.
 *
 * @param int $from_id Source campaign.
 * @return int[]
 */
function jlife_challenges_rollover_source_snapshot( $from_id ) {
	global $wpdb;
	$entries = jlife_challenges_table( 'soap_entries' );
	$revs    = jlife_challenges_table( 'soap_revisions' );
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- custom tables; names from $wpdb->prefix.
	$revisions = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$revs} r JOIN {$entries} s ON s.id = r.entry_id WHERE s.campaign_id = %d", (int) $from_id ) );
	return array(
		'enrollments'        => jlife_challenges_close_count( 'enrollments', $from_id ),
		'active_enrollments' => jlife_challenges_close_count( 'enrollments', $from_id, 'active' ),
		'groups'             => jlife_challenges_close_count( 'groups', $from_id ),
		'group_members'      => jlife_challenges_close_count( 'group_members', $from_id ),
		'soap_entries'       => jlife_challenges_close_count( 'soap_entries', $from_id ),
		'soap_revisions'     => $revisions,
		'score_ledger'       => jlife_challenges_close_count( 'score_ledger', $from_id ),
		'group_shares'       => jlife_challenges_close_count( 'group_shares', $from_id ),
		'live_shares'        => jlife_challenges_close_count( 'group_shares', $from_id, 'shared' ),
		'reminder_prefs'     => jlife_challenges_close_count( 'reminder_prefs', $from_id ),
	);
}

/**
 * The consent record an enrollment carries: the source enrollment's if it has
 * one, else the account's (written by the consent step), else none (the
 * person is asked at sign-in, as after any join).
 *
 * @param int        $user_id Participant.
 * @param array|null $source  Source enrollment row, or null.
 * @return array { consent_version: string, consented: string ('' = none) }
 */
function jlife_challenges_rollover_consent( $user_id, $source = null ) {
	if ( $source && ! empty( $source['consented'] ) ) {
		return array(
			'consent_version' => (string) $source['consent_version'],
			'consented'       => (string) $source['consented'],
		);
	}
	$at = (string) get_user_meta( $user_id, 'jlife_consent_at', true );
	return array(
		'consent_version' => '' !== $at ? (string) get_user_meta( $user_id, 'jlife_consent_version', true ) : '',
		'consented'       => $at,
	);
}

/**
 * Who would be carried, and why anyone is not. Reads only.
 *
 * @param array $from Source campaign row.
 * @param array $to   Destination campaign row.
 * @return array { counts: int[], expected: int[] user IDs, to_enroll: array<int,array> carry details by user ID }
 */
function jlife_challenges_rollover_plan( $from, $to ) {
	global $wpdb;
	$e = jlife_challenges_table( 'enrollments' );
	// phpcs:disable WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- custom table; name from $wpdb->prefix.
	$source = (array) $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$e} WHERE campaign_id = %d ORDER BY id", (int) $from['id'] ), ARRAY_A );
	$dest   = (array) $wpdb->get_results( $wpdb->prepare( "SELECT user_id, status FROM {$e} WHERE campaign_id = %d", (int) $to['id'] ), ARRAY_A );
	// phpcs:enable
	$counts     = array(
		'source_enrollments'             => 0,
		'waiting_flags'                  => 0,
		'already_enrolled'               => 0,
		'to_enroll'                      => 0,
		'skipped_left_source'            => 0,
		'skipped_removed_in_destination' => 0,
		'skipped_wait_details_missing'   => 0,
	);
	$candidates = array();
	$in_source  = array();
	foreach ( $source as $row ) {
		$uid               = (int) $row['user_id'];
		$in_source[ $uid ] = true;
		if ( 'active' !== $row['status'] ) {
			++$counts['skipped_left_source'];
			continue;
		}
		++$counts['source_enrollments'];
		$candidates[ $uid ] = array_merge( array( 'age_band' => (string) $row['age_band'] ), jlife_challenges_rollover_consent( $uid, $row ) );
	}
	$waiting                 = jlife_challenges_waiting_for_official_ids();
	$counts['waiting_flags'] = count( $waiting );
	foreach ( $waiting as $uid ) {
		if ( isset( $in_source[ $uid ] ) ) {
			continue; // Carried, or counted as left, through their source enrollment.
		}
		$band = jlife_challenges_waiting_age_band( $uid );
		if ( '' === $band ) {
			++$counts['skipped_wait_details_missing'];
			continue;
		}
		$candidates[ $uid ] = array_merge( array( 'age_band' => $band ), jlife_challenges_rollover_consent( $uid ) );
	}
	$dest_status = array();
	foreach ( $dest as $row ) {
		$dest_status[ (int) $row['user_id'] ] = (string) $row['status'];
	}
	$expected  = array();
	$to_enroll = array();
	foreach ( $candidates as $uid => $carry ) {
		if ( ! isset( $dest_status[ $uid ] ) ) {
			++$counts['to_enroll'];
			$to_enroll[ $uid ] = $carry;
			$expected[]        = $uid;
		} elseif ( 'active' === $dest_status[ $uid ] ) {
			++$counts['already_enrolled'];
			$expected[] = $uid;
		} else {
			++$counts['skipped_removed_in_destination'];
		}
	}
	return array(
		'counts'    => $counts,
		'expected'  => $expected,
		'to_enroll' => $to_enroll,
	);
}

/**
 * Preview: what an execute would do, as counts. Changes nothing.
 *
 * @param int $actor   Organizer.
 * @param int $from_id Source campaign.
 * @param int $to_id   Destination campaign.
 * @return int[]|WP_Error Counts (see jlife_challenges_rollover_plan()).
 */
function jlife_challenges_rollover_preview( $actor, $from_id, $to_id ) {
	$c = jlife_challenges_rollover_campaigns( $actor, $from_id, $to_id );
	if ( is_wp_error( $c ) ) {
		return $c;
	}
	return jlife_challenges_rollover_plan( $c['from'], $c['to'] )['counts'];
}

/**
 * Execute: enroll everyone the preview counts in the destination. Safe to run
 * again; one `campaign_rollover` audit row per run, counts only.
 *
 * @param int $actor   Organizer.
 * @param int $from_id Source campaign.
 * @param int $to_id   Destination campaign.
 * @return int[]|WP_Error Preview counts plus enrolled_now and source_changed_by_run.
 */
function jlife_challenges_rollover_execute( $actor, $from_id, $to_id ) {
	$c = jlife_challenges_rollover_campaigns( $actor, $from_id, $to_id );
	if ( is_wp_error( $c ) ) {
		return $c;
	}
	$from_id = (int) $c['from']['id'];
	$to_id   = (int) $c['to']['id'];
	$plan    = jlife_challenges_rollover_plan( $c['from'], $c['to'] );
	$before  = jlife_challenges_rollover_source_snapshot( $from_id );
	$now     = current_time( 'mysql', true );
	$e       = jlife_challenges_table( 'enrollments' );
	$added   = 0;
	global $wpdb;
	foreach ( $plan['to_enroll'] as $uid => $carry ) {
		/**
		 * Fires before each enrollment is written (a fault-injection point for the recovery tests).
		 *
		 * @param int $to_id Destination campaign.
		 */
		do_action( 'jlife_challenges_rollover_enroll', $to_id );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- custom table; name from $wpdb->prefix. INSERT IGNORE on UNIQUE (campaign_id, user_id) makes reruns add nothing.
		$wrote = $wpdb->query( $wpdb->prepare( "INSERT IGNORE INTO {$e} (campaign_id, user_id, group_id, status, age_band, consent_version, consented, joined) VALUES (%d, %d, 0, 'active', %s, %s, NULLIF(%s, ''), %s)", $to_id, (int) $uid, (string) $carry['age_band'], (string) $carry['consent_version'], (string) $carry['consented'], $now ) );
		if ( false === $wrote ) {
			return new WP_Error( 'jlife_db', sprintf( 'A database write failed after %d new enrollment(s); nothing after it was written. Run --execute again to finish.', $added ) );
		}
		$added += (int) $wrote;
	}
	$after  = jlife_challenges_rollover_source_snapshot( $from_id );
	$result = array_merge(
		$plan['counts'],
		array(
			'enrolled_now'          => $added,
			'source_changed_by_run' => $before === $after ? 0 : 1,
		)
	);
	jlife_challenges_audit(
		$actor,
		'campaign_rollover',
		'campaign',
		$from_id,
		$to_id,
		array_merge(
			$result,
			array(
				'source_before' => $before,
				'source_after'  => $after,
			)
		)
	);
	return $result;
}

/**
 * The detail of the latest execute for this source and destination, or null.
 *
 * @param int $from_id Source campaign.
 * @param int $to_id   Destination campaign.
 * @return array|null
 */
function jlife_challenges_rollover_last_run( $from_id, $to_id ) {
	global $wpdb;
	$t = jlife_challenges_table( 'audit_log' );
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- custom table; name from $wpdb->prefix.
	$detail = $wpdb->get_var( $wpdb->prepare( "SELECT detail FROM {$t} WHERE action = 'campaign_rollover' AND object_id = %d AND campaign_id = %d ORDER BY id DESC LIMIT 1", (int) $from_id, (int) $to_id ) );
	$detail = null === $detail ? null : json_decode( (string) $detail, true );
	return is_array( $detail ) ? $detail : null;
}

/**
 * Verify: every expected person is enrolled once, the destination has no
 * entries, points or shares, and the last execute left the source unchanged.
 * Reads only.
 *
 * @param int $actor   Organizer.
 * @param int $from_id Source campaign.
 * @param int $to_id   Destination campaign.
 * @return array|WP_Error Counts, plus ok (bool).
 */
function jlife_challenges_rollover_verify( $actor, $from_id, $to_id ) {
	$c = jlife_challenges_rollover_campaigns( $actor, $from_id, $to_id, false );
	if ( is_wp_error( $c ) ) {
		return $c;
	}
	$from_id = (int) $c['from']['id'];
	$to_id   = (int) $c['to']['id'];
	$plan    = jlife_challenges_rollover_plan( $c['from'], $c['to'] );
	global $wpdb;
	$e = jlife_challenges_table( 'enrollments' );
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- custom table; name from $wpdb->prefix.
	$rows   = (array) $wpdb->get_results( $wpdb->prepare( "SELECT user_id, COUNT(*) AS n FROM {$e} WHERE campaign_id = %d AND status = 'active' GROUP BY user_id", $to_id ), ARRAY_A );
	$active = array();
	foreach ( $rows as $r ) {
		$active[ (int) $r['user_id'] ] = (int) $r['n'];
	}
	$once = 0;
	foreach ( $plan['expected'] as $uid ) {
		$once += 1 === ( $active[ $uid ] ?? 0 ) ? 1 : 0;
	}
	$last         = jlife_challenges_rollover_last_run( $from_id, $to_id );
	$now          = jlife_challenges_rollover_source_snapshot( $from_id );
	$result       = array_merge(
		array(
			'expected'                => count( $plan['expected'] ),
			'enrolled_once'           => $once,
			'missing'                 => count( $plan['expected'] ) - $once,
			'duplicate_enrollments'   => count( array_filter( $active, static fn( $n ) => $n > 1 ) ),
			'destination_enrollments' => array_sum( $active ),
		),
		jlife_challenges_rollover_destination_counts( $to_id ),
		array(
			'executed'                 => $last ? 1 : 0,
			'source_changed_by_run'    => $last && ( $last['source_before'] ?? null ) !== ( $last['source_after'] ?? null ) ? 1 : 0,
			'source_changed_since_run' => $last && ( $last['source_after'] ?? null ) !== $now ? 1 : 0,
		)
	);
	$result['ok'] = 1 === $result['executed'] && 0 === $result['missing'] && 0 === $result['duplicate_enrollments']
		&& 0 === $result['destination_ledger_rows'] && 0 === $result['destination_entries'] && 0 === $result['destination_shares']
		&& 0 === $result['source_changed_by_run'];
	return $result;
}
