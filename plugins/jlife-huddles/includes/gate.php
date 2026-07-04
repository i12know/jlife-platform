<?php
/**
 * The privacy gate (spike S5, #12): every read/write decision in one place.
 *
 * Rules encoded here, matching the matrix in docs/spikes/S5-privacy-model.md:
 *
 * - Huddle threads: readable/writable by members and leaders of that huddle
 *   only. Site admins may read (moderation reality, disclosed in the privacy
 *   statement) but do not write.
 * - Private notes: author-only, full stop — leaders, coaches, and site
 *   admins get WP_Error through app code. (DB operators can technically
 *   read the table; that is the honesty statement, not an app permission.)
 * - Progress: self sees own detail; the huddle leader sees completion
 *   flags; aggregate counts are the only shape that leaves the huddle
 *   (coach/HUB tile via the bridge).
 * - Magic-link actors (S4 bearer tokens) may touch progress and
 *   leader-visible response surfaces only — never threads or private notes.
 *
 * Membership is authoritative from Disciple.Tools (S3 contract): the
 * `jlife_huddles_group_membership` filter supplies it (bridge in production,
 * fixtures in tests). Unknown groups fail CLOSED.
 *
 * @package jlife-huddles
 */

defined( 'ABSPATH' ) || exit;

/**
 * Resolve huddle membership as WP user IDs.
 *
 * @param int $dt_group_id D.T group (huddle) ID.
 * @return array { leader_user_ids: int[], member_user_ids: int[] }
 */
function jlife_huddles_group_membership( $dt_group_id ) {
	/**
	 * Supply membership for a huddle. Production: jlife-bridge reads the
	 * D.T group live. Tests: fixtures. Null means "unknown" → fail closed.
	 *
	 * @param array|null $membership { leader_user_ids, member_user_ids } or null.
	 * @param int        $dt_group_id D.T group ID.
	 */
	$membership = apply_filters( 'jlife_huddles_group_membership', null, (int) $dt_group_id );

	if ( ! is_array( $membership ) && function_exists( 'jlife_bridge_get_group_membership' ) ) {
		$membership = jlife_bridge_get_group_membership( (int) $dt_group_id );
	}

	if ( ! is_array( $membership ) ) {
		return array(
			'leader_user_ids' => array(),
			'member_user_ids' => array(),
		);
	}
	return array(
		'leader_user_ids' => array_map( 'intval', isset( $membership['leader_user_ids'] ) ? (array) $membership['leader_user_ids'] : array() ),
		'member_user_ids' => array_map( 'intval', isset( $membership['member_user_ids'] ) ? (array) $membership['member_user_ids'] : array() ),
	);
}

/**
 * Is the user the leader of this huddle?
 *
 * @param int $user_id     WP user ID.
 * @param int $dt_group_id D.T group ID.
 * @return bool
 */
function jlife_huddles_is_leader( $user_id, $dt_group_id ) {
	if ( $user_id < 1 ) {
		return false;
	}
	$m = jlife_huddles_group_membership( $dt_group_id );
	return in_array( (int) $user_id, $m['leader_user_ids'], true );
}

/**
 * Is the user a member (or leader) of this huddle?
 *
 * @param int $user_id     WP user ID.
 * @param int $dt_group_id D.T group ID.
 * @return bool
 */
function jlife_huddles_is_member( $user_id, $dt_group_id ) {
	if ( $user_id < 1 ) {
		return false;
	}
	$m = jlife_huddles_group_membership( $dt_group_id );
	return in_array( (int) $user_id, $m['member_user_ids'], true )
		|| in_array( (int) $user_id, $m['leader_user_ids'], true );
}

/**
 * May the user read the huddle discussion thread?
 *
 * @param int    $user_id     WP user ID (0 = anonymous).
 * @param int    $dt_group_id D.T group ID.
 * @param string $lesson_id   Lesson the thread belongs to (reserved).
 * @return bool
 */
function jlife_huddles_can_read_thread( $user_id, $dt_group_id, $lesson_id = '' ) {
	unset( $lesson_id );
	if ( jlife_huddles_is_member( $user_id, $dt_group_id ) ) {
		return true;
	}
	// Site admins can read (moderation; disclosed), never silently write.
	return $user_id > 0 && user_can( $user_id, 'manage_options' );
}

/**
 * May the user post to the huddle discussion thread?
 *
 * @param int    $user_id     WP user ID (0 = anonymous).
 * @param int    $dt_group_id D.T group ID.
 * @param string $lesson_id   Lesson the thread belongs to (reserved).
 * @return bool
 */
function jlife_huddles_can_write_thread( $user_id, $dt_group_id, $lesson_id = '' ) {
	unset( $lesson_id );
	return jlife_huddles_is_member( $user_id, $dt_group_id );
}

/**
 * May the viewer read a private note authored by someone?
 *
 * Author-only. No leader, coach, or admin carve-out at the app layer.
 *
 * @param int $viewer_user_id Viewer's WP user ID (0 = anonymous).
 * @param int $author_user_id Note author's WP user ID.
 * @return bool
 */
function jlife_huddles_can_read_private_note( $viewer_user_id, $author_user_id ) {
	return $viewer_user_id > 0 && (int) $viewer_user_id === (int) $author_user_id;
}

/**
 * May the viewer create/update a private note for this author?
 *
 * @param int $viewer_user_id Viewer's WP user ID.
 * @param int $author_user_id Note author's WP user ID.
 * @return bool
 */
function jlife_huddles_can_write_private_note( $viewer_user_id, $author_user_id ) {
	return jlife_huddles_can_read_private_note( $viewer_user_id, $author_user_id );
}

/**
 * May the viewer read a subject's progress detail?
 *
 * Self only. Leaders use the flags API (status values, never bodies);
 * coaches/HUB use aggregates.
 *
 * @param int $viewer_user_id  Viewer's WP user ID.
 * @param int $subject_user_id Whose progress.
 * @param int $dt_group_id     Huddle scope.
 * @return bool
 */
function jlife_huddles_can_read_progress( $viewer_user_id, $subject_user_id, $dt_group_id ) {
	unset( $dt_group_id );
	return $viewer_user_id > 0 && (int) $viewer_user_id === (int) $subject_user_id;
}

/**
 * May the viewer read per-member completion flags for a huddle?
 *
 * @param int $viewer_user_id Viewer's WP user ID.
 * @param int $dt_group_id    Huddle scope.
 * @return bool
 */
function jlife_huddles_can_read_progress_flags( $viewer_user_id, $dt_group_id ) {
	return jlife_huddles_is_leader( $viewer_user_id, $dt_group_id );
}

/**
 * May the viewer read aggregate progress counts for a huddle?
 *
 * @param int $viewer_user_id Viewer's WP user ID.
 * @param int $dt_group_id    Huddle scope.
 * @return bool
 */
function jlife_huddles_can_read_progress_aggregate( $viewer_user_id, $dt_group_id ) {
	if ( jlife_huddles_is_leader( $viewer_user_id, $dt_group_id ) ) {
		return true;
	}
	return $viewer_user_id > 0 && user_can( $viewer_user_id, 'manage_options' );
}

/**
 * May a magic-link actor (S4 bearer token) touch this surface at all?
 *
 * The S4/S5 shared rule: links carry lesson view, progress, and
 * leader-visible responses only. Threads and private notes require accounts.
 *
 * @param string $surface One of 'progress', 'leader_response', 'thread', 'private_note'.
 * @return bool
 */
function jlife_huddles_link_actor_can( $surface ) {
	return in_array( $surface, array( 'progress', 'leader_response' ), true );
}
