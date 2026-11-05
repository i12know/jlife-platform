<?php
/**
 * Campaigns, groups and enrollment (docs/challenges-engine-design.md §2).
 *
 * One primary group per participant per campaign (D14). Invite codes are
 * stored hashed and shown once. Moves and removals are audited (D18).
 *
 * @package jlife-challenges
 */

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.DB.DirectDatabaseQuery -- custom tables are this plugin's data layer; every query goes through the gate; WordPress has no API for them.

const JLIFE_CHALLENGES_AGE_BANDS = array( 'adult', 'minor' );

/**
 * Create a campaign. Organizers only.
 *
 * @param int   $actor User creating it.
 * @param array $args  campaign_key, plan_id, title, start_date, end_date, timezone, grace_days, status.
 * @return int|WP_Error Campaign ID.
 */
function jlife_challenges_create_campaign( $actor, $args ) {
	if ( ! jlife_challenges_is_organizer( $actor ) ) {
		return jlife_challenges_denied();
	}
	$args = wp_parse_args(
		$args,
		array(
			'timezone'   => 'America/Los_Angeles',
			'grace_days' => 1,
			'status'     => 'draft',
		)
	);
	foreach ( array( 'campaign_key', 'plan_id', 'title', 'start_date', 'end_date' ) as $k ) {
		if ( empty( $args[ $k ] ) ) {
			return new WP_Error( 'jlife_campaign_invalid', "Missing {$k}." );
		}
	}
	if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $args['start_date'] ) || ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $args['end_date'] )
		|| jlife_challenges_days_between( $args['start_date'], $args['end_date'] ) < 0 ) {
		return new WP_Error( 'jlife_campaign_invalid', 'Invalid dates.' );
	}
	if ( ! in_array( $args['timezone'], timezone_identifiers_list(), true ) ) {
		return new WP_Error( 'jlife_campaign_invalid', 'Invalid timezone.' );
	}
	global $wpdb;
	$ok = $wpdb->insert(
		jlife_challenges_table( 'campaigns' ),
		array(
			'campaign_key' => sanitize_key( $args['campaign_key'] ),
			'plan_id'      => (string) $args['plan_id'],
			'title'        => sanitize_text_field( $args['title'] ),
			'start_date'   => $args['start_date'],
			'end_date'     => $args['end_date'],
			'timezone'     => $args['timezone'],
			'grace_days'   => max( 0, (int) $args['grace_days'] ),
			'status'       => (string) $args['status'],
			'created'      => current_time( 'mysql', true ),
		),
		array( '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%s', '%s' )
	);
	if ( false === $ok ) {
		return new WP_Error( 'jlife_db', 'Could not create campaign.' );
	}
	$id = (int) $wpdb->insert_id;
	jlife_challenges_audit( $actor, 'campaign_created', 'campaign', $id, $id );
	return $id;
}

/**
 * Get a campaign row.
 *
 * @param int $campaign_id Campaign ID.
 * @return array|null
 */
function jlife_challenges_get_campaign( $campaign_id ) {
	global $wpdb;
	$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . jlife_challenges_table( 'campaigns' ) . ' WHERE id = %d', (int) $campaign_id ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- table name from $wpdb->prefix.
	return $row ? $row : null;
}

/**
 * Get a group row.
 *
 * @param int $group_id Group ID.
 * @return array|null
 */
function jlife_challenges_get_group( $group_id ) {
	global $wpdb;
	$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . jlife_challenges_table( 'groups' ) . ' WHERE id = %d', (int) $group_id ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- table name from $wpdb->prefix.
	return $row ? $row : null;
}

/**
 * Hash an invite code for storage and lookup.
 *
 * @param string $code Invite code.
 * @return string
 */
function jlife_challenges_hash_invite( $code ) {
	return hash_hmac( 'sha256', strtoupper( trim( (string) $code ) ), wp_salt( 'auth' ) );
}

/**
 * Create a group with a fresh invite code. Organizers only.
 *
 * @param int    $actor       Organizer.
 * @param int    $campaign_id Campaign.
 * @param string $name        Group name.
 * @return array|WP_Error { id, invite_code } — the code is returned only here.
 */
function jlife_challenges_create_group( $actor, $campaign_id, $name ) {
	if ( ! jlife_challenges_is_organizer( $actor ) || ! jlife_challenges_get_campaign( $campaign_id ) ) {
		return jlife_challenges_denied();
	}
	$name = sanitize_text_field( (string) $name );
	if ( '' === $name ) {
		return new WP_Error( 'jlife_group_invalid', 'Group name required.' );
	}
	global $wpdb;
	$ok = $wpdb->insert(
		jlife_challenges_table( 'groups' ),
		array(
			'campaign_id' => (int) $campaign_id,
			'name'        => $name,
			'status'      => 'active',
			'created'     => current_time( 'mysql', true ),
		),
		array( '%d', '%s', '%s', '%s' )
	);
	if ( false === $ok ) {
		return new WP_Error( 'jlife_db', 'Could not create group.' );
	}
	$id = (int) $wpdb->insert_id;
	jlife_challenges_audit( $actor, 'group_created', 'group', $id, (int) $campaign_id );
	$code = jlife_challenges_reset_invite( $actor, $id );
	return is_wp_error( $code ) ? $code : array(
		'id'          => $id,
		'invite_code' => $code,
	);
}

/**
 * Issue a new invite code for a group (the old one stops working).
 *
 * @param int $actor    Organizer.
 * @param int $group_id Group.
 * @param int $days     Days until the code expires.
 * @return string|WP_Error The new code (shown once).
 */
function jlife_challenges_reset_invite( $actor, $group_id, $days = 60 ) {
	$group = jlife_challenges_get_group( $group_id );
	if ( ! jlife_challenges_is_organizer( $actor ) || ! $group ) {
		return jlife_challenges_denied();
	}
	$alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789'; // No 0/O/1/I confusion.
	$code     = '';
	for ( $i = 0; $i < 8; $i++ ) {
		$code .= $alphabet[ random_int( 0, strlen( $alphabet ) - 1 ) ];
	}
	global $wpdb;
	$wpdb->update(
		jlife_challenges_table( 'groups' ),
		array(
			'invite_hash'    => jlife_challenges_hash_invite( $code ),
			'invite_expires' => gmdate( 'Y-m-d H:i:s', time() + (int) $days * DAY_IN_SECONDS ),
		),
		array( 'id' => (int) $group_id ),
		array( '%s', '%s' ),
		array( '%d' )
	);
	jlife_challenges_audit( $actor, 'invite_reset', 'group', (int) $group_id, (int) $group['campaign_id'] );
	return $code;
}

/**
 * Get a participant's enrollment (any status).
 *
 * @param int $campaign_id Campaign.
 * @param int $user_id     User.
 * @return array|null
 */
function jlife_challenges_get_enrollment( $campaign_id, $user_id ) {
	global $wpdb;
	$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . jlife_challenges_table( 'enrollments' ) . ' WHERE campaign_id = %d AND user_id = %d', (int) $campaign_id, (int) $user_id ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- table name from $wpdb->prefix.
	return $row ? $row : null;
}

/**
 * Insert or reactivate an enrollment.
 *
 * @param int    $actor    Acting user (participant or organizer).
 * @param int    $user_id  Participant.
 * @param array  $group    Group row.
 * @param string $age_band adult|minor.
 * @return int|WP_Error Enrollment ID.
 */
function jlife_challenges_write_enrollment( $actor, $user_id, $group, $age_band ) {
	if ( ! in_array( $age_band, JLIFE_CHALLENGES_AGE_BANDS, true ) ) {
		return new WP_Error( 'jlife_age_band', 'age_band must be adult or minor (participants are 13 or older).' );
	}
	$campaign_id = (int) $group['campaign_id'];
	$existing    = jlife_challenges_get_enrollment( $campaign_id, $user_id );
	if ( $existing && 'active' === $existing['status'] ) {
		return new WP_Error( 'jlife_already_enrolled', __( 'You are already in a group for this campaign.', 'jlife-challenges' ) );
	}
	global $wpdb;
	$now  = current_time( 'mysql', true );
	$data = array(
		'campaign_id' => $campaign_id,
		'user_id'     => (int) $user_id,
		'group_id'    => (int) $group['id'],
		'status'      => 'active',
		'age_band'    => $age_band,
		'joined'      => $now,
		'left_at'     => null,
	);
	if ( $existing ) {
		$wpdb->update( jlife_challenges_table( 'enrollments' ), $data, array( 'id' => (int) $existing['id'] ) );
		$id = (int) $existing['id'];
	} else {
		$wpdb->insert( jlife_challenges_table( 'enrollments' ), $data );
		$id = (int) $wpdb->insert_id;
	}
	jlife_challenges_audit( $actor, 'enrolled', 'enrollment', $id, $campaign_id, array( 'group_id' => (int) $group['id'] ) );
	return $id;
}

/**
 * A participant joins a group with an invite code.
 *
 * @param int    $user_id  Participant (signed in).
 * @param string $code     Invite code.
 * @param string $age_band adult|minor (the sign-up flow blocks under-13s).
 * @return int|WP_Error Enrollment ID.
 */
function jlife_challenges_join_with_code( $user_id, $code, $age_band ) {
	if ( $user_id < 1 ) {
		return jlife_challenges_denied();
	}
	$group = jlife_challenges_group_for_invite( $code );
	if ( ! $group ) {
		return new WP_Error( 'jlife_invite_invalid', __( 'That invite code is not valid. Please ask your group for a new one.', 'jlife-challenges' ) );
	}
	return jlife_challenges_write_enrollment( $user_id, $user_id, $group, $age_band );
}

/**
 * The active, unexpired group an invite code opens, or null.
 *
 * @param string $code Invite code (any case).
 * @return array|null Group row.
 */
function jlife_challenges_group_for_invite( $code ) {
	if ( '' === trim( (string) $code ) ) {
		return null;
	}
	global $wpdb;
	$group = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . jlife_challenges_table( 'groups' ) . " WHERE invite_hash = %s AND status = 'active'", jlife_challenges_hash_invite( $code ) ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- table name from $wpdb->prefix.
	if ( ! $group || ( $group['invite_expires'] && strtotime( $group['invite_expires'] . ' UTC' ) < time() ) ) {
		return null;
	}
	return $group;
}

/**
 * A user's active enrollments across campaigns.
 *
 * @param int $user_id User.
 * @return array[] Enrollment rows.
 */
function jlife_challenges_user_enrollments( $user_id ) {
	global $wpdb;
	return (array) $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . jlife_challenges_table( 'enrollments' ) . " WHERE user_id = %d AND status = 'active'", (int) $user_id ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- table name from $wpdb->prefix.
}

/**
 * An organizer adds a participant to a group.
 *
 * @param int    $actor    Organizer.
 * @param int    $user_id  Participant.
 * @param int    $group_id Group.
 * @param string $age_band adult|minor.
 * @return int|WP_Error Enrollment ID.
 */
function jlife_challenges_enroll( $actor, $user_id, $group_id, $age_band = 'adult' ) {
	$group = jlife_challenges_get_group( $group_id );
	if ( ! jlife_challenges_is_organizer( $actor ) || ! $group || 'active' !== $group['status'] ) {
		return jlife_challenges_denied();
	}
	return jlife_challenges_write_enrollment( $actor, $user_id, $group, $age_band );
}

/**
 * Move a participant to another group in the same campaign (D18: their past
 * shares stay with the old group).
 *
 * @param int $actor        Organizer.
 * @param int $user_id      Participant.
 * @param int $new_group_id Destination group.
 * @return true|WP_Error
 */
function jlife_challenges_move_member( $actor, $user_id, $new_group_id ) {
	$group = jlife_challenges_get_group( $new_group_id );
	if ( ! jlife_challenges_is_organizer( $actor ) || ! $group || 'active' !== $group['status'] ) {
		return jlife_challenges_denied();
	}
	$e = jlife_challenges_active_enrollment( (int) $group['campaign_id'], $user_id );
	if ( ! $e ) {
		return jlife_challenges_denied();
	}
	global $wpdb;
	$wpdb->update( jlife_challenges_table( 'enrollments' ), array( 'group_id' => (int) $new_group_id ), array( 'id' => (int) $e['id'] ), array( '%d' ), array( '%d' ) );
	jlife_challenges_audit(
		$actor,
		'member_moved',
		'enrollment',
		(int) $e['id'],
		(int) $group['campaign_id'],
		array(
			'from_group' => (int) $e['group_id'],
			'to_group'   => (int) $new_group_id,
		)
	);
	return true;
}

/**
 * Remove a participant from the campaign (their entries and points remain theirs).
 *
 * @param int $actor       Organizer.
 * @param int $campaign_id Campaign.
 * @param int $user_id     Participant.
 * @return true|WP_Error
 */
function jlife_challenges_remove_member( $actor, $campaign_id, $user_id ) {
	$e = jlife_challenges_active_enrollment( $campaign_id, $user_id );
	if ( ! jlife_challenges_is_organizer( $actor ) || ! $e ) {
		return jlife_challenges_denied();
	}
	global $wpdb;
	$wpdb->update(
		jlife_challenges_table( 'enrollments' ),
		array(
			'status'  => 'removed',
			'left_at' => current_time( 'mysql', true ),
		),
		array( 'id' => (int) $e['id'] )
	);
	jlife_challenges_audit( $actor, 'member_removed', 'enrollment', (int) $e['id'], (int) $campaign_id, array( 'group_id' => (int) $e['group_id'] ) );
	return true;
}

/**
 * Record that a minor's parent/guardian consent is on file (D19).
 *
 * @param int $actor       Organizer.
 * @param int $campaign_id Campaign.
 * @param int $user_id     Participant.
 * @return true|WP_Error
 */
function jlife_challenges_record_guardian_consent( $actor, $campaign_id, $user_id ) {
	$e = jlife_challenges_active_enrollment( $campaign_id, $user_id );
	if ( ! jlife_challenges_is_organizer( $actor ) || ! $e ) {
		return jlife_challenges_denied();
	}
	global $wpdb;
	$wpdb->update( jlife_challenges_table( 'enrollments' ), array( 'guardian_consent' => current_time( 'mysql', true ) ), array( 'id' => (int) $e['id'] ) );
	jlife_challenges_audit( $actor, 'guardian_consent_recorded', 'enrollment', (int) $e['id'], (int) $campaign_id );
	return true;
}

/**
 * Record the participant's acceptance of the consent screen (D15).
 *
 * @param int    $user_id     Participant.
 * @param int    $campaign_id Campaign.
 * @param string $version     Consent wording version.
 * @return true|WP_Error
 */
function jlife_challenges_record_consent( $user_id, $campaign_id, $version ) {
	$e = jlife_challenges_active_enrollment( $campaign_id, $user_id );
	if ( ! $e ) {
		return jlife_challenges_denied();
	}
	global $wpdb;
	$wpdb->update(
		jlife_challenges_table( 'enrollments' ),
		array(
			'consent_version' => sanitize_text_field( (string) $version ),
			'consented'       => current_time( 'mysql', true ),
		),
		array( 'id' => (int) $e['id'] )
	);
	return true;
}

add_filter( 'jlife_huddles_group_membership', 'jlife_challenges_membership_source', 20, 2 );

/**
 * Supply launch-group membership to the shared gate (D16). D.T groups replace
 * this source after launch (P9). Only answers for J-Life groups.
 *
 * @param array|null $membership  Membership from an earlier source, if any.
 * @param int        $group_id    Group ID.
 * @return array|null
 */
function jlife_challenges_membership_source( $membership, $group_id ) {
	if ( is_array( $membership ) ) {
		return $membership;
	}
	$group = jlife_challenges_get_group( $group_id );
	if ( ! $group ) {
		return $membership;
	}
	global $wpdb;
	$members = $wpdb->get_col( $wpdb->prepare( 'SELECT user_id FROM ' . jlife_challenges_table( 'enrollments' ) . " WHERE group_id = %d AND status = 'active'", (int) $group_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- table name from $wpdb->prefix.
	return array(
		'exists'          => 'active' === $group['status'],
		'leader_user_ids' => array(),
		'member_user_ids' => array_map( 'intval', $members ),
	);
}
