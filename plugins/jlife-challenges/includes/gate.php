<?php
/**
 * The engine's privacy gate: every read/write decision in one place,
 * matching the matrix in docs/challenges-engine-design.md §4.
 *
 * - SOAP entries: author only. Organizers and leaders never read SOAP text
 *   through these functions (the P1 review path will be separate and audited).
 * - Group shares: readable by current members of that group only; created
 *   and removed by their author only; organizers may hide (D17), never browse.
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
 * May the viewer read a group's feed? Current active members only.
 *
 * @param int $viewer_user_id Viewer.
 * @param int $group_id       Group ID.
 * @return bool
 */
function jlife_challenges_can_read_feed( $viewer_user_id, $group_id ) {
	$group = jlife_challenges_get_group( $group_id );
	if ( ! $group || 'active' !== $group['status'] ) {
		return false;
	}
	$e = jlife_challenges_active_enrollment( (int) $group['campaign_id'], $viewer_user_id );
	return null !== $e && (int) $e['group_id'] === (int) $group_id;
}

/**
 * Does a participant still need guardian consent before sharing (D19)?
 *
 * @param array $enrollment Enrollment row.
 * @return bool
 */
function jlife_challenges_needs_guardian_consent( $enrollment ) {
	$required = (bool) apply_filters( 'jlife_challenges_require_guardian_consent', (bool) get_option( 'jlife_challenges_require_guardian_consent', true ) );
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
