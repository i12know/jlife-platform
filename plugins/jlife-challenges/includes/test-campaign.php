<?php
/**
 * Test-drive campaign for staging (latoc): create one that starts today, and
 * remove every trace of it before the real Marathon. Test campaigns are
 * identified by a campaign_key starting with "test-"; nothing else is ever
 * touched by the removal.
 *
 * @package jlife-challenges
 */

defined( 'ABSPATH' ) || exit;

const JLIFE_CHALLENGES_TEST_PREFIX = 'test-';

/**
 * Campaign IDs of all test campaigns.
 *
 * @return int[]
 */
function jlife_challenges_test_campaign_ids() {
	global $wpdb;
	$t = jlife_challenges_table( 'campaigns' );
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- custom table; name from $wpdb->prefix.
	$ids = $wpdb->get_col( $wpdb->prepare( "SELECT id FROM {$t} WHERE campaign_key LIKE %s", $wpdb->esc_like( JLIFE_CHALLENGES_TEST_PREFIX ) . '%' ) );
	return array_map( 'intval', $ids );
}

/**
 * Create the test campaign (Day 1 = today, Pacific) with one "Test Group",
 * or, if one exists, issue a fresh invite code for its group.
 *
 * @param int    $actor   Organizer.
 * @param string $plan_id Reading plan.
 * @return array|WP_Error { campaign_id, group_id, invite_code, created, start_date }
 */
function jlife_challenges_create_test_campaign( $actor, $plan_id = 'rpl-life-and-teachings-of-jesus-2026' ) {
	if ( ! jlife_challenges_is_organizer( $actor ) ) {
		return jlife_challenges_denied();
	}
	$existing = jlife_challenges_test_campaign_ids();
	if ( $existing ) {
		$campaign_id = max( $existing );
		global $wpdb;
		$t = jlife_challenges_table( 'groups' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- custom table; name from $wpdb->prefix.
		$group_id = (int) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$t} WHERE campaign_id = %d ORDER BY id LIMIT 1", $campaign_id ) );
		if ( $group_id ) {
			$code = jlife_challenges_reset_invite( $actor, $group_id );
			if ( is_wp_error( $code ) ) {
				return $code;
			}
			return array(
				'campaign_id' => $campaign_id,
				'group_id'    => $group_id,
				'invite_code' => $code,
				'created'     => false,
				'start_date'  => (string) jlife_challenges_get_campaign( $campaign_id )['start_date'],
			);
		}
	}

	$tz    = new DateTimeZone( 'America/Los_Angeles' );
	$today = jlife_challenges_now()->setTimezone( $tz );
	$start = $today->format( 'Y-m-d' );
	$cid   = jlife_challenges_create_campaign(
		$actor,
		array(
			'campaign_key' => JLIFE_CHALLENGES_TEST_PREFIX . str_replace( '-', '', $start ),
			'plan_id'      => $plan_id,
			'title'        => 'TEST Bible Marathon',
			'start_date'   => $start,
			'end_date'     => $today->modify( '+103 days' )->format( 'Y-m-d' ),
			'status'       => 'active',
		)
	);
	if ( is_wp_error( $cid ) ) {
		return $cid;
	}
	$group = jlife_challenges_create_group( $actor, $cid, 'Test Group' );
	if ( is_wp_error( $group ) ) {
		return $group;
	}
	return array(
		'campaign_id' => (int) $cid,
		'group_id'    => (int) $group['id'],
		'invite_code' => (string) $group['invite_code'],
		'created'     => true,
		'start_date'  => $start,
	);
}

/**
 * Remove all test campaigns: their groups, enrollments, SOAP entries and
 * revisions, points, shares and audit rows. Participant accounts that were
 * only ever in test campaigns are deleted too, so testers start fresh in the
 * real Marathon. Staff accounts (who can edit posts) are never deleted.
 *
 * @param int $actor Organizer.
 * @return array|WP_Error Counts: campaigns, entries, users.
 */
function jlife_challenges_remove_test_campaigns( $actor ) {
	if ( ! jlife_challenges_is_organizer( $actor ) ) {
		return jlife_challenges_denied();
	}
	$ids = jlife_challenges_test_campaign_ids();
	if ( ! $ids ) {
		return array(
			'campaigns' => 0,
			'entries'   => 0,
			'users'     => 0,
		);
	}
	global $wpdb;
	$in      = implode( ',', $ids ); // Integers only (intval above).
	$entries = jlife_challenges_table( 'soap_entries' );
	$enroll  = jlife_challenges_table( 'enrollments' );
	// phpcs:disable WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQL.NotPrepared -- custom tables; names from $wpdb->prefix, IDs are integers.
	$testers   = array_map( 'intval', $wpdb->get_col( "SELECT DISTINCT user_id FROM {$enroll} WHERE campaign_id IN ({$in})" ) );
	$n_entries = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$entries} WHERE campaign_id IN ({$in})" );
	$wpdb->query( 'DELETE r FROM ' . jlife_challenges_table( 'soap_revisions' ) . " r JOIN {$entries} e ON e.id = r.entry_id WHERE e.campaign_id IN ({$in})" );
	foreach ( array( 'group_shares', 'score_ledger', 'soap_entries', 'enrollments', 'groups', 'audit_log' ) as $table ) {
		$wpdb->query( 'DELETE FROM ' . jlife_challenges_table( $table ) . " WHERE campaign_id IN ({$in})" );
	}
	$wpdb->query( 'DELETE FROM ' . jlife_challenges_table( 'campaigns' ) . " WHERE id IN ({$in})" );
	// phpcs:enable

	$deleted = 0;
	foreach ( $testers as $user_id ) {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- custom table; name from $wpdb->prefix.
		$other = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$enroll} WHERE user_id = %d", $user_id ) );
		if ( $other > 0 || user_can( $user_id, 'edit_posts' ) ) {
			continue; // Staff, or also in a real campaign: keep the account.
		}
		if ( is_multisite() ) {
			require_once ABSPATH . 'wp-admin/includes/ms.php';
			$ok = wpmu_delete_user( $user_id );
		} else {
			require_once ABSPATH . 'wp-admin/includes/user.php';
			$ok = wp_delete_user( $user_id );
		}
		$deleted += $ok ? 1 : 0;
	}
	jlife_challenges_audit( $actor, 'test_campaigns_removed', 'campaign', 0, 0, array( 'count' => count( $ids ) ) );
	return array(
		'campaigns' => count( $ids ),
		'entries'   => $n_entries,
		'users'     => $deleted,
	);
}
