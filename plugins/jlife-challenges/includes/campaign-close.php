<?php
/**
 * Closing a campaign (docs/challenges-engine-design.md §3c, #179).
 *
 * Closing sets `campaigns.status = 'closed'`. From then on the campaign is
 * read-only (jlife_challenges_campaign_accepts_writes() in gate.php) and its
 * queued reminder emails are cancelled. Nothing is deleted or made more
 * visible: entries, shares, points and memberships stay as they were. Output
 * and the audit row are counts only.
 *
 * @package jlife-challenges
 */

defined( 'ABSPATH' ) || exit;

/**
 * Count a campaign's rows in one engine table.
 *
 * @param string $table       Table suffix.
 * @param int    $campaign_id Campaign.
 * @param string $status      Row status to count ('' = any).
 * @return int
 */
function jlife_challenges_close_count( $table, $campaign_id, $status = '' ) {
	global $wpdb;
	$t = jlife_challenges_table( $table );
	if ( '' === $status ) {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- custom table; name from $wpdb->prefix.
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$t} WHERE campaign_id = %d", (int) $campaign_id ) );
	}
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- custom table; name from $wpdb->prefix.
	return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$t} WHERE campaign_id = %d AND status = %s", (int) $campaign_id, $status ) );
}

/**
 * What closing would do, as counts only. Changes nothing.
 *
 * @param int $actor       Organizer.
 * @param int $campaign_id Campaign.
 * @return array|WP_Error { already_closed, enrollments, groups, entries, ledger_rows, shares, reminders_to_cancel }
 */
function jlife_challenges_close_preview( $actor, $campaign_id ) {
	$campaign = jlife_challenges_get_campaign( (int) $campaign_id );
	if ( ! jlife_challenges_is_organizer( $actor ) || ! $campaign ) {
		return jlife_challenges_denied();
	}
	if ( 'closed' !== $campaign['status'] && 'active' !== $campaign['status'] ) {
		return new WP_Error( 'jlife_campaign_not_active', 'Only an active campaign can be closed.' );
	}
	return array(
		'already_closed'      => 'closed' === $campaign['status'] ? 1 : 0,
		'enrollments'         => jlife_challenges_close_count( 'enrollments', (int) $campaign_id, 'active' ),
		'groups'              => jlife_challenges_close_count( 'groups', (int) $campaign_id, 'active' ),
		'entries'             => jlife_challenges_close_count( 'soap_entries', (int) $campaign_id ),
		'ledger_rows'         => jlife_challenges_close_count( 'score_ledger', (int) $campaign_id ),
		'shares'              => jlife_challenges_close_count( 'group_shares', (int) $campaign_id, 'shared' ),
		'reminders_to_cancel' => jlife_challenges_close_count( 'reminder_sends', (int) $campaign_id, 'queued' ),
	);
}

/**
 * Close a campaign: mark it closed and cancel its queued reminder emails.
 * Safe to run twice: a closed campaign is left as it is (any queued reminder
 * row that slipped in is still cancelled) and audited only the first time.
 *
 * @param int $actor       Organizer.
 * @param int $campaign_id Campaign.
 * @return array|WP_Error { changed: bool, reminders_cancelled: int, ...preview counts }
 */
function jlife_challenges_close_campaign( $actor, $campaign_id ) {
	$plan = jlife_challenges_close_preview( $actor, $campaign_id );
	if ( is_wp_error( $plan ) ) {
		return $plan;
	}
	global $wpdb;
	$changed = false;
	if ( ! $plan['already_closed'] ) {
		jlife_challenges_release_due_shares(); // #209: shares whose day had opened go out before the campaign closes.
		// Only an active row flips, so two runs at once close it once.
		$changed = (bool) $wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- custom table; no WP API exists.
			jlife_challenges_table( 'campaigns' ),
			array( 'status' => 'closed' ),
			array(
				'id'     => (int) $campaign_id,
				'status' => 'active',
			),
			array( '%s' ),
			array( '%d', '%s' )
		);
	}
	$t = jlife_challenges_table( 'reminder_sends' );
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- custom table; name from $wpdb->prefix.
	$cancelled = (int) $wpdb->query( $wpdb->prepare( "UPDATE {$t} SET status = 'cancelled', last_error_class = 'campaign_closed', updated = %s WHERE campaign_id = %d AND status = 'queued'", current_time( 'mysql', true ), (int) $campaign_id ) );
	unset( $plan['already_closed'], $plan['reminders_to_cancel'] );
	$counts = array_merge( $plan, array( 'reminders_cancelled' => $cancelled ) );
	if ( $changed ) {
		jlife_challenges_withdraw_unreleased_shares( 0, 0, (int) $campaign_id ); // #209: nothing still scheduled can come out in a closed campaign.
		jlife_challenges_audit( $actor, 'campaign_closed', 'campaign', (int) $campaign_id, (int) $campaign_id, $counts );
	}
	return array_merge( array( 'changed' => $changed ), $counts );
}
