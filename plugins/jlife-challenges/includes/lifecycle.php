<?php
/**
 * Account deletion (D15): a deleted user's entries, revisions, shares,
 * points, memberships, reminder choices, reminder send log, enrollments and photos (files too, #244) are removed. The audit entry records the purge
 * without any SOAP text.
 *
 * @package jlife-challenges
 */

defined( 'ABSPATH' ) || exit;

add_action( 'deleted_user', 'jlife_challenges_purge_user_data' );

/**
 * Remove everything the engine stores about a deleted user.
 *
 * @param int $user_id Deleted user's ID.
 */
function jlife_challenges_purge_user_data( $user_id ) {
	global $wpdb;
	$user_id = (int) $user_id;
	$entries = jlife_challenges_table( 'soap_entries' );
	$revs    = jlife_challenges_table( 'soap_revisions' );
	jlife_questions_delete_for_user( $user_id ); // Bible questions (#249) go with the account.
	jlife_challenges_delete_photos( array( 'user_id' => $user_id ) ); // Files first, then rows (#244): a deleted account leaves no photo, original or share copy.
	// phpcs:disable WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- custom tables own this data; names from $wpdb->prefix.
	$wpdb->query( $wpdb->prepare( "DELETE r FROM {$revs} r JOIN {$entries} e ON e.id = r.entry_id WHERE e.user_id = %d", $user_id ) );
	$wpdb->delete( $entries, array( 'user_id' => $user_id ), array( '%d' ) );
	$wpdb->delete( jlife_challenges_table( 'group_shares' ), array( 'user_id' => $user_id ), array( '%d' ) );
	$wpdb->delete( jlife_challenges_table( 'score_ledger' ), array( 'user_id' => $user_id ), array( '%d' ) );
	$wpdb->delete( jlife_challenges_table( 'group_members' ), array( 'user_id' => $user_id ), array( '%d' ) );
	$wpdb->delete( jlife_challenges_table( 'reminder_sends' ), array( 'user_id' => $user_id ), array( '%d' ) );
	$wpdb->delete( jlife_challenges_table( 'reminder_prefs' ), array( 'user_id' => $user_id ), array( '%d' ) );
	$wpdb->delete( jlife_challenges_table( 'enrollments' ), array( 'user_id' => $user_id ), array( '%d' ) );
	// phpcs:enable
	jlife_challenges_audit( 0, 'user_purged', 'user', $user_id );
}
