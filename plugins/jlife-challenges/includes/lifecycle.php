<?php
/**
 * Account deletion (D15): a deleted user's entries, revisions, shares,
 * points and enrollments are removed. The audit entry records the purge
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
	// phpcs:disable WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- custom tables own this data; names from $wpdb->prefix.
	$wpdb->query( $wpdb->prepare( "DELETE r FROM {$revs} r JOIN {$entries} e ON e.id = r.entry_id WHERE e.user_id = %d", $user_id ) );
	$wpdb->delete( $entries, array( 'user_id' => $user_id ), array( '%d' ) );
	$wpdb->delete( jlife_challenges_table( 'group_shares' ), array( 'user_id' => $user_id ), array( '%d' ) );
	$wpdb->delete( jlife_challenges_table( 'score_ledger' ), array( 'user_id' => $user_id ), array( '%d' ) );
	$wpdb->delete( jlife_challenges_table( 'enrollments' ), array( 'user_id' => $user_id ), array( '%d' ) );
	// phpcs:enable
	jlife_challenges_audit( 0, 'user_purged', 'user', $user_id );
}
