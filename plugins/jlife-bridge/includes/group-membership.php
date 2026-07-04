<?php
/**
 * Live huddle membership from the D.T group record (spike S5, #12).
 *
 * The S3 contract: Disciple.Tools is authoritative for who leads and belongs
 * to a huddle. This is the production membership provider behind
 * jlife-huddles' gate — STUDY code never queries HUB tables itself.
 *
 * Mapping: the group's `assigned_to` user is the leader; member contacts map
 * to WP users through D.T's `corresponds_to_user` contact meta (participants
 * gain accounts when they need thread/note access — the S4 upgrade path).
 * Contacts without a linked user simply do not appear: membership stays
 * fail-closed for account-gated surfaces.
 *
 * @package jlife-bridge
 */

defined( 'ABSPATH' ) || exit;

/**
 * Read leader/member WP user IDs for a huddle from the HUB group record.
 *
 * @param int $dt_group_id D.T group post ID on HUB.
 * @return array|null { leader_user_ids, member_user_ids } or null if HUB unavailable.
 */
function jlife_bridge_get_group_membership( $dt_group_id ) {
	$hub = jlife_bridge_hub_blog_id();
	if ( ! $hub ) {
		return null;
	}

	switch_to_blog( $hub );

	$leaders = array();
	$members = array();

	$group = get_post( $dt_group_id );
	if ( $group && 'groups' === $group->post_type ) {
		$assigned = get_post_meta( $dt_group_id, 'assigned_to', true );
		if ( is_string( $assigned ) && 0 === strpos( $assigned, 'user-' ) ) {
			$leaders[] = (int) substr( $assigned, 5 );
		}

		// Member contacts are p2p connections; resolve each contact's linked
		// WP user via corresponds_to_user, skipping unlinked contacts.
		global $wpdb;
		$contact_ids = $wpdb->get_col( // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- D.T p2p table has no WP API from outside the theme.
			$wpdb->prepare(
				"SELECT p2p_from FROM {$wpdb->prefix}p2p WHERE p2p_to = %d AND p2p_type = %s", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from $wpdb->prefix.
				(int) $dt_group_id,
				'contacts_to_groups'
			)
		);
		foreach ( $contact_ids as $contact_id ) {
			$linked_user = get_post_meta( (int) $contact_id, 'corresponds_to_user', true );
			if ( $linked_user ) {
				$members[] = (int) $linked_user;
			}
		}
	}

	restore_current_blog();

	return array(
		'leader_user_ids' => $leaders,
		'member_user_ids' => $members,
	);
}
