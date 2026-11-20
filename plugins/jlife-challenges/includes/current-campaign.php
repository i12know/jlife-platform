<?php
/**
 * The current campaign (docs/challenges-engine-design.md §3b, #177).
 *
 * One rule decides which campaign is "current", for joining and for what a
 * participant sees: running > upcoming (nearest start) > most recently ended,
 * in each campaign's own timezone, with every running or upcoming staging
 * `test-*` campaign after every running or upcoming real one. Nothing else
 * sorts campaigns.
 *
 * @package jlife-challenges
 */

defined( 'ABSPATH' ) || exit;

/**
 * A campaign's phase at a moment: draft, closed, upcoming, running or ended.
 * Any status other than draft or active (closed, #179, or an older "finished")
 * is view-only and reads as closed.
 *
 * @param array                  $campaign Campaign row.
 * @param DateTimeImmutable|null $moment   Moment (default: now).
 * @return string
 */
function jlife_challenges_campaign_phase( $campaign, $moment = null ) {
	$status = (string) ( $campaign['status'] ?? '' );
	if ( 'draft' === $status ) {
		return 'draft';
	}
	if ( 'active' !== $status ) {
		return 'closed';
	}
	$today = jlife_challenges_local_date( $campaign, $moment ?? jlife_challenges_now() );
	if ( $today < (string) $campaign['start_date'] ) {
		return 'upcoming';
	}
	return $today > (string) $campaign['end_date'] ? 'ended' : 'running';
}

/**
 * Is this a disposable staging campaign (key starts with `test-`)?
 *
 * @param array $campaign Campaign row.
 * @return bool
 */
function jlife_challenges_is_test_campaign( $campaign ) {
	return 0 === strpos( (string) ( $campaign['campaign_key'] ?? '' ), JLIFE_CHALLENGES_TEST_PREFIX );
}

/**
 * Sort key for the rule: lower sorts first. Real running, real upcoming, test
 * running, test upcoming (running: latest start; upcoming: nearest start), then
 * ended or closed (most recent end, real before test).
 *
 * @param array  $campaign Campaign row.
 * @param string $phase    Its phase.
 * @return array
 */
function jlife_challenges_campaign_rank( $campaign, $phase ) {
	$test  = jlife_challenges_is_test_campaign( $campaign ) ? 1 : 0;
	$id    = (int) $campaign['id'];
	$start = jlife_challenges_days_between( '2000-01-01', (string) $campaign['start_date'] );
	$end   = jlife_challenges_days_between( '2000-01-01', (string) $campaign['end_date'] );
	if ( 'running' === $phase ) {
		return array( 2 * $test, -$start, -$id );
	}
	if ( 'upcoming' === $phase ) {
		return array( 1 + 2 * $test, $start, $id );
	}
	return array( 4, -$end, $test, -$id );
}

/**
 * The one rule (§3b): pick the current campaign from candidate rows.
 *
 * `join`: only running or upcoming campaigns (status active, not past the end).
 * `view`: any phase but draft (a participant's own enrollments).
 *
 * @param array[]                $campaigns Campaign rows.
 * @param string                 $purpose   join|view.
 * @param DateTimeImmutable|null $moment    Moment (default: now).
 * @return array|null Campaign row.
 */
function jlife_challenges_pick_campaign( array $campaigns, $purpose = 'view', $moment = null ) {
	$moment  = $moment ?? jlife_challenges_now();
	$allowed = 'join' === $purpose ? array( 'running', 'upcoming' ) : array( 'running', 'upcoming', 'ended', 'closed' );
	$ranked  = array();
	foreach ( $campaigns as $c ) {
		$phase = jlife_challenges_campaign_phase( $c, $moment );
		if ( in_array( $phase, $allowed, true ) ) {
			$ranked[] = array(
				'key'      => jlife_challenges_campaign_rank( $c, $phase ),
				'campaign' => $c,
			);
		}
	}
	if ( ! $ranked ) {
		return null;
	}
	usort( $ranked, static fn( $a, $b ) => $a['key'] <=> $b['key'] );
	return $ranked[0]['campaign'];
}

/**
 * Can people join this campaign now (running or upcoming: active and not yet
 * finished)?
 *
 * @param array $campaign Campaign row.
 * @return bool
 */
function jlife_challenges_campaign_is_open( $campaign ) {
	return in_array( jlife_challenges_campaign_phase( $campaign ), array( 'running', 'upcoming' ), true );
}

/**
 * The campaign new enrollments go into ("Read on my own", "Start a group"):
 * the rule's `join` pick among this site's campaigns. Filterable.
 *
 * @return array|null Campaign row.
 */
function jlife_challenges_open_campaign() {
	global $wpdb;
	$rows     = (array) $wpdb->get_results( 'SELECT * FROM ' . jlife_challenges_table( 'campaigns' ) . " WHERE status = 'active'", ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.NotPrepared -- custom table; name from $wpdb->prefix.
	$campaign = jlife_challenges_pick_campaign( $rows, 'join' );
	/**
	 * Filters the campaign new enrollments go into.
	 *
	 * @param mixed $campaign Campaign row (array), or null when none is open.
	 */
	$filtered = apply_filters( 'jlife_challenges_open_campaign', $campaign );
	return $filtered ? (array) $filtered : null;
}

/**
 * The participant's current campaign: the rule's `view` pick among the
 * campaigns of their active enrollments.
 *
 * @param int $user_id Participant.
 * @return array|null { campaign, enrollment }
 */
function jlife_challenges_participant_campaign( $user_id ) {
	$by_campaign = array();
	$campaigns   = array();
	foreach ( jlife_challenges_user_enrollments( $user_id ) as $e ) {
		$c = jlife_challenges_get_campaign( (int) $e['campaign_id'] );
		if ( $c ) {
			$by_campaign[ (int) $c['id'] ] = $e;
			$campaigns[]                   = $c;
		}
	}
	$c = jlife_challenges_pick_campaign( $campaigns, 'view' );
	return $c
		? array(
			'campaign'   => $c,
			'enrollment' => $by_campaign[ (int) $c['id'] ],
		)
		: null;
}
