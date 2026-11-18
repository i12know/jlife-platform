<?php
/**
 * Progress, counts and the score export. Points only; never SOAP text,
 * email addresses or tokens (D5).
 *
 * @package jlife-challenges
 */

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.DB.DirectDatabaseQuery -- custom tables are this plugin's data layer; every query goes through the gate; WordPress has no API for them.

/**
 * A participant's progress: total points and each day's status.
 *
 * @param int $viewer_user_id  Viewer (self or organizer).
 * @param int $subject_user_id Participant.
 * @param int $campaign_id     Campaign.
 * @return array|WP_Error { points, day_count, days: { d###: earned|late_no_point|removed_by_review } }
 */
function jlife_challenges_progress( $viewer_user_id, $subject_user_id, $campaign_id ) {
	$campaign = jlife_challenges_get_campaign( $campaign_id );
	if ( ! $campaign || ! jlife_challenges_can_read_progress( $viewer_user_id, $subject_user_id ) ) {
		return jlife_challenges_denied();
	}
	global $wpdb;
	$rows   = $wpdb->get_results( $wpdb->prepare( 'SELECT day_key, points, status FROM ' . jlife_challenges_table( 'score_ledger' ) . ' WHERE campaign_id = %d AND user_id = %d ORDER BY day_key', (int) $campaign_id, (int) $subject_user_id ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- table name from $wpdb->prefix.
	$days   = array();
	$points = 0;
	foreach ( $rows as $r ) {
		$days[ $r['day_key'] ] = $r['status'];
		$points               += (int) $r['points'];
	}
	return array(
		'points'    => $points,
		'day_count' => jlife_challenges_day_count( $campaign ),
		'days'      => $days,
	);
}

/**
 * Per-day counts for a campaign, per group. Organizers only. A person in
 * several groups is counted once in each of them (groups are for reading
 * together; points never depend on them), and a solo reader under group_id 0, so
 * summing the rows double-counts: use the ledger for totals.
 *
 * @param int $viewer_user_id Viewer.
 * @param int $campaign_id    Campaign.
 * @return array|WP_Error rows of { day_key, group_id, submitted, points }
 */
function jlife_challenges_day_counts( $viewer_user_id, $campaign_id ) {
	if ( ! jlife_challenges_can_read_counts( $viewer_user_id ) || ! jlife_challenges_get_campaign( $campaign_id ) ) {
		return jlife_challenges_denied();
	}
	global $wpdb;
	$l = jlife_challenges_table( 'score_ledger' );
	$m = jlife_challenges_table( 'group_members' );
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- custom tables; names from $wpdb->prefix.
	return $wpdb->get_results( $wpdb->prepare( "SELECT l.day_key, COALESCE(m.group_id, 0) AS group_id, COUNT(*) AS submitted, SUM(l.points) AS points FROM {$l} l LEFT JOIN {$m} m ON m.campaign_id = l.campaign_id AND m.user_id = l.user_id AND m.status = 'active' WHERE l.campaign_id = %d GROUP BY l.day_key, COALESCE(m.group_id, 0) ORDER BY l.day_key, group_id", (int) $campaign_id ), ARRAY_A );
}

/**
 * Score export rows for reconciliation and the prize: one row per
 * participant and day. `group` lists the person's active groups ("A; B"), empty
 * for a solo reader. No SOAP text, no email, no tokens. Organizers only; every
 * export is audited.
 *
 * @param int $viewer_user_id Viewer.
 * @param int $campaign_id    Campaign.
 * @return array|WP_Error rows of { user_id, name, group, day_key, points, status }
 */
function jlife_challenges_export_scores( $viewer_user_id, $campaign_id ) {
	if ( ! jlife_challenges_can_read_counts( $viewer_user_id ) || ! jlife_challenges_get_campaign( $campaign_id ) ) {
		return jlife_challenges_denied();
	}
	global $wpdb;
	$l = jlife_challenges_table( 'score_ledger' );
	$m = jlife_challenges_table( 'group_members' );
	$g = jlife_challenges_table( 'groups' );
	// phpcs:disable WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- custom tables; names from $wpdb->prefix.
	$rows   = $wpdb->get_results( $wpdb->prepare( "SELECT l.user_id, l.day_key, l.points, l.status FROM {$l} l WHERE l.campaign_id = %d ORDER BY l.user_id, l.day_key", (int) $campaign_id ), ARRAY_A );
	$groups = array();
	foreach ( (array) $wpdb->get_results( $wpdb->prepare( "SELECT m.user_id, g.name FROM {$m} m JOIN {$g} g ON g.id = m.group_id WHERE m.campaign_id = %d AND m.status = 'active' ORDER BY m.joined_at, m.id", (int) $campaign_id ), ARRAY_A ) as $r ) {
		$groups[ (int) $r['user_id'] ][] = (string) $r['name'];
	}
	// phpcs:enable
	$out = array();
	foreach ( $rows as $r ) {
		$u     = get_userdata( (int) $r['user_id'] );
		$out[] = array(
			'user_id' => (int) $r['user_id'],
			'name'    => $u ? $u->display_name : '',
			'group'   => implode( '; ', $groups[ (int) $r['user_id'] ] ?? array() ),
			'day_key' => $r['day_key'],
			'points'  => (int) $r['points'],
			'status'  => $r['status'],
		);
	}
	jlife_challenges_audit( $viewer_user_id, 'scores_exported', 'campaign', (int) $campaign_id, (int) $campaign_id, array( 'rows' => count( $out ) ) );
	return $out;
}
