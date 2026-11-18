<?php
/**
 * The engine's privacy gate: every read/write decision in one place,
 * matching the matrix in docs/challenges-engine-design.md §4.
 *
 * - SOAP entries: author only. Organizers and leaders never read SOAP text
 *   through these functions (the P1 review path will be separate and audited).
 * - Group shares: readable by current members of THAT group only (a person may
 *   be in up to 3 groups, #112; being in one says nothing about another);
 *   created for a group the author is in and removed by their author only;
 *   organizers may hide (D17), never browse. A solo reader (enrolled, no active
 *   membership) has no group to read or share to.
 * - Membership comes from `group_members` only; the retired
 *   `enrollments.group_id` column is never consulted.
 * - Scores: a participant sees their own; organizers see detail and counts.
 * - Unknown objects are denied the same way as forbidden ones.
 *
 * @package jlife-challenges
 */

defined( 'ABSPATH' ) || exit;

/**
 * The uniform denial (no hint whether the object exists).
 *
 * @return WP_Error
 */
function jlife_challenges_denied() {
	return new WP_Error( 'jlife_denied', __( 'Not found or not allowed.', 'jlife-challenges' ) );
}

/**
 * Is the user an organizer (manages campaigns, sees scores, may hide shares)?
 *
 * @param int $user_id User ID.
 * @return bool
 */
function jlife_challenges_is_organizer( $user_id ) {
	return $user_id > 0 && user_can( $user_id, 'manage_options' );
}

/**
 * The user's active enrollment in a campaign, or null.
 *
 * @param int $campaign_id Campaign ID.
 * @param int $user_id     User ID.
 * @return array|null
 */
function jlife_challenges_active_enrollment( $campaign_id, $user_id ) {
	if ( $user_id < 1 || $campaign_id < 1 ) {
		return null;
	}
	$e = jlife_challenges_get_enrollment( $campaign_id, $user_id );
	return ( $e && 'active' === $e['status'] ) ? $e : null;
}

/**
 * May the user write their own SOAP entry in this campaign (0 = personal)?
 *
 * @param int $user_id     User ID.
 * @param int $campaign_id Campaign ID or 0.
 * @return bool
 */
function jlife_challenges_can_write_entry( $user_id, $campaign_id ) {
	if ( $user_id < 1 ) {
		return false;
	}
	return 0 === (int) $campaign_id || null !== jlife_challenges_active_enrollment( (int) $campaign_id, $user_id );
}

/**
 * May the viewer read this SOAP entry row? Author only.
 *
 * @param int   $viewer_user_id Viewer.
 * @param array $entry          Entry row.
 * @return bool
 */
function jlife_challenges_can_read_entry( $viewer_user_id, $entry ) {
	return $viewer_user_id > 0 && (int) $entry['user_id'] === (int) $viewer_user_id;
}

/**
 * The user's active group memberships in a campaign, oldest first, each with the
 * group's name. Only memberships on an active enrollment and an active group
 * count; a solo reader gets an empty list.
 *
 * @param int $campaign_id Campaign ID.
 * @param int $user_id     User ID.
 * @return array[] Rows of { id, enrollment_id, group_id, role, joined_at, name }.
 */
function jlife_challenges_active_memberships( $campaign_id, $user_id ) {
	$campaign_id = (int) $campaign_id;
	$user_id     = (int) $user_id;
	if ( $user_id < 1 || $campaign_id < 1 ) {
		return array();
	}
	global $wpdb;
	$m = jlife_challenges_table( 'group_members' );
	$e = jlife_challenges_table( 'enrollments' );
	$g = jlife_challenges_table( 'groups' );
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- custom tables; names from $wpdb->prefix.
	$rows = $wpdb->get_results( $wpdb->prepare( "SELECT m.id, m.enrollment_id, m.group_id, m.role, m.joined_at, g.name FROM {$m} m JOIN {$e} e ON e.id = m.enrollment_id AND e.status = 'active' JOIN {$g} g ON g.id = m.group_id AND g.status = 'active' WHERE m.user_id = %d AND m.campaign_id = %d AND m.status = 'active' ORDER BY m.joined_at, m.id", $user_id, $campaign_id ), ARRAY_A );
	return is_array( $rows ) ? $rows : array();
}

/**
 * Is the user an active member of this group (active enrollment, active group,
 * active membership)? Unknown groups answer false, like forbidden ones.
 *
 * @param int $user_id  User ID.
 * @param int $group_id Group ID.
 * @return bool
 */
function jlife_challenges_is_group_member( $user_id, $group_id ) {
	$group = jlife_challenges_get_group( $group_id );
	if ( ! $group || 'active' !== $group['status'] || (int) $user_id < 1 ) {
		return false;
	}
	foreach ( jlife_challenges_active_memberships( (int) $group['campaign_id'], (int) $user_id ) as $m ) {
		if ( (int) $m['group_id'] === (int) $group_id ) {
			return true;
		}
	}
	return false;
}

/**
 * May the viewer read a group's feed? Current active members of THAT group only.
 *
 * @param int $viewer_user_id Viewer.
 * @param int $group_id       Group ID.
 * @return bool
 */
function jlife_challenges_can_read_feed( $viewer_user_id, $group_id ) {
	return jlife_challenges_is_group_member( $viewer_user_id, $group_id );
}

/**
 * Does a participant still need guardian consent before sharing (D19)?
 *
 * D19 (owner decision 2026-11-05, #55): participants aged 13-17 share with
 * their own (youth) group without a separate guardian step in J-Life; the
 * church's existing consent process covers it. So the requirement is off by
 * default. A deployment whose church needs a recorded guardian consent turns
 * it on with the option set to '1' (or the filter).
 *
 * @param array $enrollment Enrollment row.
 * @return bool
 */
function jlife_challenges_needs_guardian_consent( $enrollment ) {
	// Option values are '1' or '0' (default); update_option( ..., false ) on a
	// missing option is a no-op in WordPress, so booleans are not used.
	$required = (bool) apply_filters( 'jlife_challenges_require_guardian_consent', '1' === (string) get_option( 'jlife_challenges_require_guardian_consent', '0' ) );
	return $required && 'minor' === $enrollment['age_band'] && empty( $enrollment['guardian_consent'] );
}

/**
 * May the user see score counts and export scores for a campaign?
 *
 * @param int $viewer_user_id Viewer.
 * @return bool
 */
function jlife_challenges_can_read_counts( $viewer_user_id ) {
	return jlife_challenges_is_organizer( $viewer_user_id );
}

/**
 * May the viewer read a participant's score detail? Self or organizer.
 *
 * @param int $viewer_user_id  Viewer.
 * @param int $subject_user_id Participant.
 * @return bool
 */
function jlife_challenges_can_read_progress( $viewer_user_id, $subject_user_id ) {
	if ( $viewer_user_id < 1 ) {
		return false;
	}
	return (int) $viewer_user_id === (int) $subject_user_id || jlife_challenges_is_organizer( $viewer_user_id );
}
