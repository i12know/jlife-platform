<?php
/**
 * Campaigns, groups and enrollment (docs/challenges-engine-design.md §2).
 *
 * A participant has ONE enrollment per campaign (consent, progress, entries and
 * points are personal) and is solo (no group) or in up to
 * JLIFE_CHALLENGES_MAX_GROUPS groups through `group_members` rows (D14 as
 * changed in #112). Scoring never depends on groups. Invite codes are stored
 * hashed and shown once. Joins, leaves, moves and removals are audited (D18).
 *
 * @package jlife-challenges
 */

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.DB.DirectDatabaseQuery -- custom tables are this plugin's data layer; every query goes through the gate; WordPress has no API for them.

const JLIFE_CHALLENGES_AGE_BANDS = array( 'adult', 'minor' );

/**
 * Most groups one person can be in at once, per campaign (D14 changed, #112).
 * Adults and minors alike.
 */
const JLIFE_CHALLENGES_MAX_GROUPS = 3;

/**
 * Wrong invite codes one signed-in person may try per hour before waiting.
 */
const JLIFE_CHALLENGES_JOIN_TRIES = 10;

/**
 * Groups one adult may start per campaign (#112 part 2). Counted over every
 * status, so leaving or an organizer disabling a group does not free the count.
 */
const JLIFE_CHALLENGES_MAX_STARTED_GROUPS = 2;

/**
 * Group-creation attempts one signed-in person may make per hour, refused ones
 * included.
 */
const JLIFE_CHALLENGES_START_TRIES = 5;

/**
 * Longest group name, in characters.
 */
const JLIFE_CHALLENGES_GROUP_NAME_MAX = 60;

/**
 * Create a campaign. Organizers only.
 *
 * @param int   $actor User creating it.
 * @param array $args  campaign_key, plan_id, title, start_date, end_date, timezone, grace_days, status, scoring_engine, accepts_photos (0 default, #244).
 * @return int|WP_Error Campaign ID.
 */
function jlife_challenges_create_campaign( $actor, $args ) {
	if ( ! jlife_challenges_is_organizer( $actor ) ) {
		return jlife_challenges_denied();
	}
	$args = wp_parse_args(
		$args,
		array(
			'timezone'       => 'America/Los_Angeles',
			'grace_days'     => 1,
			'status'         => 'draft',
			'scoring_engine' => JLIFE_CHALLENGES_DEFAULT_ENGINE,
			'accepts_photos' => 0,
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
	$engines = jlife_challenges_scoring_engines();
	if ( ! isset( $engines[ (string) $args['scoring_engine'] ] ) ) {
		return new WP_Error( 'jlife_campaign_invalid', 'Unknown scoring engine.' );
	}
	global $wpdb;
	$ok = $wpdb->insert(
		jlife_challenges_table( 'campaigns' ),
		array(
			'campaign_key'   => sanitize_key( $args['campaign_key'] ),
			'plan_id'        => (string) $args['plan_id'],
			'title'          => sanitize_text_field( $args['title'] ),
			'start_date'     => $args['start_date'],
			'end_date'       => $args['end_date'],
			'timezone'       => $args['timezone'],
			'grace_days'     => max( 0, (int) $args['grace_days'] ),
			'status'         => (string) $args['status'],
			'scoring_engine' => (string) $args['scoring_engine'],
			'accepts_photos' => empty( $args['accepts_photos'] ) ? 0 : 1,
			'created'        => current_time( 'mysql', true ),
		),
		array( '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%s', '%s', '%d', '%s' )
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
 * Create a group with a fresh invite code. Organizers only. The name follows the
 * same rules as starting a group: plain text, 1 to 60 characters.
 *
 * @param int    $actor       Organizer.
 * @param int    $campaign_id Campaign.
 * @param string $name        Group name.
 * @param int    $days        Days until the first invite code expires.
 * @return array|WP_Error { id, invite_code } — the code is returned only here.
 */
function jlife_challenges_create_group( $actor, $campaign_id, $name, $days = 60 ) {
	if ( ! jlife_challenges_is_organizer( $actor ) || ! jlife_challenges_get_campaign( $campaign_id ) ) {
		return jlife_challenges_denied();
	}
	if ( ! jlife_challenges_campaign_accepts_writes( (int) $campaign_id ) ) {
		return jlife_challenges_campaign_closed_error();
	}
	$name = jlife_challenges_clean_group_name( $name );
	if ( is_wp_error( $name ) ) {
		return $name;
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
	$code = jlife_challenges_reset_invite( $actor, $id, (int) $days );
	return is_wp_error( $code ) ? $code : array(
		'id'          => $id,
		'invite_code' => $code,
	);
}

/**
 * Issue a new invite code for a group (the old one stops working). An
 * organizer may reset any group's code; otherwise only the group's own active
 * starter may, and only while the group is active. Anyone else, including
 * another member, a non-member and a starter who has left, is denied.
 *
 * @param int $actor    Organizer, or the group's starter.
 * @param int $group_id Group.
 * @param int $days     Days until the code expires.
 * @return string|WP_Error The new code (shown once).
 */
function jlife_challenges_reset_invite( $actor, $group_id, $days = 60 ) {
	$group     = jlife_challenges_get_group( $group_id );
	$organizer = jlife_challenges_is_organizer( $actor );
	if ( ! $group || ! ( $organizer || jlife_challenges_is_group_starter( $actor, $group_id ) ) ) {
		return jlife_challenges_denied();
	}
	if ( ! jlife_challenges_campaign_accepts_writes( (int) $group['campaign_id'] ) ) {
		return jlife_challenges_campaign_closed_error();
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
	jlife_challenges_audit( $actor, 'invite_reset', 'group', (int) $group_id, (int) $group['campaign_id'], array( 'by' => $organizer ? 'organizer' : 'starter' ) );
	return $code;
}

/**
 * Is the user the active starter of this group (an active member whose
 * membership role is `starter`, in an active group)? A starter who left, or
 * whose group an organizer disabled, is not.
 *
 * @param int $user_id  User.
 * @param int $group_id Group.
 * @return bool
 */
function jlife_challenges_is_group_starter( $user_id, $group_id ) {
	if ( (int) $user_id < 1 || ! jlife_challenges_is_group_member( $user_id, $group_id ) ) {
		return false;
	}
	$m = jlife_challenges_get_membership( $group_id, $user_id );
	return $m && 'starter' === $m['role'] && 'active' === $m['status'];
}

/**
 * Clean and check a group name: plain text, 1 to JLIFE_CHALLENGES_GROUP_NAME_MAX
 * characters after cleaning. Too long is refused, never silently cut.
 *
 * @param string $name Raw name.
 * @return string|WP_Error The cleaned name.
 */
function jlife_challenges_clean_group_name( $name ) {
	$name = sanitize_text_field( (string) $name );
	if ( '' === $name || mb_strlen( $name ) > JLIFE_CHALLENGES_GROUP_NAME_MAX ) {
		return new WP_Error(
			'jlife_group_name_invalid',
			sprintf(
				/* translators: %d: the most characters a group name can have. */
				__( 'Please give your group a name of up to %d characters.', 'jlife-challenges' ),
				JLIFE_CHALLENGES_GROUP_NAME_MAX
			)
		);
	}
	return $name;
}

/**
 * How many groups a person has started in a campaign, in any status.
 *
 * @param int $user_id     User.
 * @param int $campaign_id Campaign.
 * @return int
 */
function jlife_challenges_started_count( $user_id, $campaign_id ) {
	global $wpdb;
	return (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . jlife_challenges_table( 'groups' ) . ' WHERE started_by = %d AND campaign_id = %d', (int) $user_id, (int) $campaign_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- table name from $wpdb->prefix.
}

/**
 * Why the person cannot start a group right now, or null when they can. Checks
 * everything except the name and the rate limit. Never writes.
 *
 * @param int $user_id User.
 * @return WP_Error|null
 */
function jlife_challenges_start_blocker( $user_id ) {
	$campaign = (int) $user_id > 0 ? jlife_challenges_open_campaign() : null;
	$e        = $campaign ? jlife_challenges_active_enrollment( (int) $campaign['id'], (int) $user_id ) : null;
	if ( ! $campaign || ! $e ) {
		return jlife_challenges_denied();
	}
	if ( 'adult' !== $e['age_band'] ) {
		return new WP_Error( 'jlife_minor_cannot_start', __( 'Only adults (18 or older) can start a group. You can join a group with an invite code, or read on your own.', 'jlife-challenges' ) );
	}
	if ( jlife_challenges_started_count( $user_id, (int) $campaign['id'] ) >= JLIFE_CHALLENGES_MAX_STARTED_GROUPS ) {
		return new WP_Error(
			'jlife_start_limit',
			sprintf(
				/* translators: %d: the most groups one person can start. */
				_n( 'You have already started the most groups you can (%d).', 'You have already started the most groups you can (%d).', JLIFE_CHALLENGES_MAX_STARTED_GROUPS, 'jlife-challenges' ),
				JLIFE_CHALLENGES_MAX_STARTED_GROUPS
			)
		);
	}
	if ( count( jlife_challenges_active_memberships( (int) $campaign['id'], (int) $user_id ) ) >= JLIFE_CHALLENGES_MAX_GROUPS ) {
		return jlife_challenges_group_limit_error();
	}
	return null;
}

/**
 * May the person be offered "Start a group" right now? An adult with an active
 * enrollment in the open campaign, a free group slot and a start to spare.
 *
 * @param int $user_id User.
 * @return bool
 */
function jlife_challenges_can_start_group( $user_id ) {
	return null === jlife_challenges_start_blocker( $user_id );
}

/**
 * An adult starts a group (#112 part 2). The group is made in the open campaign,
 * the person becomes its `starter` member (it uses one of their 3 slots) and
 * gets a fresh invite code, returned only here. Minors can never start one: the
 * check reads the stored enrollment, not anything the form said. Limits: 2
 * started per person per campaign, 5 attempts an hour. Audited (D18).
 *
 * @param int    $user_id Participant (signed in).
 * @param string $name    Group name.
 * @return array|WP_Error { id, invite_code, name, campaign_id }
 */
function jlife_challenges_start_group( $user_id, $name ) {
	$user_id = (int) $user_id;
	if ( $user_id < 1 ) {
		return jlife_challenges_denied();
	}
	$key   = 'jlife_chal_start_tries_' . $user_id;
	$tries = (int) get_transient( $key );
	if ( $tries >= JLIFE_CHALLENGES_START_TRIES ) {
		return new WP_Error( 'jlife_start_rate', __( 'Too many tries. Please wait an hour before starting a group.', 'jlife-challenges' ) );
	}
	set_transient( $key, $tries + 1, HOUR_IN_SECONDS );

	$blocked = jlife_challenges_start_blocker( $user_id );
	if ( $blocked ) {
		$campaign = jlife_challenges_open_campaign();
		$e        = $campaign ? jlife_challenges_active_enrollment( (int) $campaign['id'], $user_id ) : null;
		if ( $e && 'jlife_denied' !== $blocked->get_error_code() ) {
			jlife_challenges_audit( $user_id, 'group_start_refused', 'enrollment', (int) $e['id'], (int) $campaign['id'], array( 'reason' => $blocked->get_error_code() ) );
		}
		return $blocked;
	}
	$name = jlife_challenges_clean_group_name( $name );
	if ( is_wp_error( $name ) ) {
		return $name;
	}
	$campaign   = (array) jlife_challenges_open_campaign();
	$enrollment = (array) jlife_challenges_active_enrollment( (int) $campaign['id'], $user_id );

	global $wpdb;
	$groups = jlife_challenges_table( 'groups' );
	$ok     = $wpdb->insert(
		$groups,
		array(
			'campaign_id' => (int) $campaign['id'],
			'name'        => $name,
			'status'      => 'active',
			'started_by'  => $user_id,
			'created'     => current_time( 'mysql', true ),
		),
		array( '%d', '%s', '%s', '%d', '%s' )
	);
	if ( false === $ok ) {
		return new WP_Error( 'jlife_db', 'Could not create group.' );
	}
	$group_id = (int) $wpdb->insert_id;
	// Two requests can both pass the checks above; whoever finishes over a limit backs out.
	if ( jlife_challenges_started_count( $user_id, (int) $campaign['id'] ) > JLIFE_CHALLENGES_MAX_STARTED_GROUPS ) {
		$fail = new WP_Error( 'jlife_start_limit', __( 'You have already started the most groups you can.', 'jlife-challenges' ) );
	} else {
		$added = jlife_challenges_add_membership( $user_id, $enrollment, (array) jlife_challenges_get_group( $group_id ), 'starter', 'start_group' );
		$fail  = is_wp_error( $added ) ? $added : null;
	}
	if ( $fail ) {
		$wpdb->delete( $groups, array( 'id' => $group_id ), array( '%d' ) );
		return $fail;
	}
	jlife_challenges_audit( $user_id, 'group_started', 'group', $group_id, (int) $campaign['id'] );
	$code = jlife_challenges_reset_invite( $user_id, $group_id );
	if ( is_wp_error( $code ) ) {
		return $code;
	}
	return array(
		'id'          => $group_id,
		'invite_code' => $code,
		'name'        => $name,
		'campaign_id' => (int) $campaign['id'],
	);
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
 * Open (create or reactivate) a person's enrollment in a campaign, with no
 * group. Groups are separate membership rows. An enrollment that is already
 * active is returned unchanged: its age band and consent are never rewritten by
 * a later join. A new or reactivated enrollment carries the consent record the
 * account already has (jlife_challenges_rollover_consent(); #181), so a person
 * who agreed once is not left without consent in the next campaign they join.
 *
 * @param int    $user_id     Participant.
 * @param int    $campaign_id Campaign.
 * @param string $age_band    adult|minor (used only when creating or reactivating).
 * @return array|WP_Error { enrollment: row, created: bool } `created` is true for a new or reactivated enrollment.
 */
function jlife_challenges_open_enrollment( $user_id, $campaign_id, $age_band ) {
	if ( ! in_array( $age_band, JLIFE_CHALLENGES_AGE_BANDS, true ) ) {
		return new WP_Error( 'jlife_age_band', 'age_band must be adult or minor (participants are 13 or older).' );
	}
	if ( ! jlife_challenges_campaign_accepts_writes( (int) $campaign_id ) ) {
		return jlife_challenges_campaign_closed_error();
	}
	$existing = jlife_challenges_get_enrollment( $campaign_id, $user_id );
	if ( $existing && 'active' === $existing['status'] ) {
		return array(
			'enrollment' => $existing,
			'created'    => false,
		);
	}
	global $wpdb;
	$data = array(
		'campaign_id' => (int) $campaign_id,
		'user_id'     => (int) $user_id,
		'group_id'    => 0, // Retired column (see upgrade.php): membership lives in group_members.
		'status'      => 'active',
		'age_band'    => $age_band,
		'joined'      => current_time( 'mysql', true ),
		'left_at'     => null,
	);
	// The consent record (#181; PR #197 decision 4): someone who already accepted the participation
	// consent carries the account's record into a new enrollment, exactly as the rollover does. A
	// re-joined enrollment keeps its own. No record on the account: none here (asked at sign-in).
	$consent = jlife_challenges_rollover_consent( (int) $user_id, $existing );
	if ( '' !== $consent['consented'] ) {
		$data['consent_version'] = $consent['consent_version'];
		$data['consented']       = $consent['consented'];
	}
	if ( $existing ) {
		$wpdb->update( jlife_challenges_table( 'enrollments' ), $data, array( 'id' => (int) $existing['id'] ) );
	} else {
		$wpdb->insert( jlife_challenges_table( 'enrollments' ), $data );
	}
	$row = jlife_challenges_get_enrollment( $campaign_id, $user_id );
	return $row ? array(
		'enrollment' => $row,
		'created'    => true,
	) : new WP_Error( 'jlife_db', 'Could not enroll.' );
}

/**
 * Add a person to a group (up to JLIFE_CHALLENGES_MAX_GROUPS per campaign).
 * Reactivates a membership they left. Audited, except a move (the move writes
 * its own audit row).
 *
 * @param int    $actor      Acting user (the participant, or an organizer).
 * @param array  $enrollment The person's active enrollment row.
 * @param array  $group      Group row (active, same campaign).
 * @param string $role       member|starter.
 * @param string $source     invite_code|organizer|start_group|move (recorded in the audit).
 * @return int|WP_Error Membership ID.
 */
function jlife_challenges_add_membership( $actor, $enrollment, $group, $role = 'member', $source = 'invite_code' ) {
	if ( 'active' !== $group['status'] || (int) $group['campaign_id'] !== (int) $enrollment['campaign_id'] || 'active' !== $enrollment['status'] ) {
		return jlife_challenges_denied();
	}
	if ( ! jlife_challenges_campaign_accepts_writes( (int) $group['campaign_id'] ) ) {
		return jlife_challenges_campaign_closed_error();
	}
	$role        = in_array( $role, array( 'starter', 'member' ), true ) ? $role : 'member';
	$user_id     = (int) $enrollment['user_id'];
	$campaign_id = (int) $enrollment['campaign_id'];
	$existing    = jlife_challenges_get_membership( (int) $group['id'], $user_id );
	if ( $existing && 'active' === $existing['status'] ) {
		return new WP_Error( 'jlife_already_enrolled', __( 'You are already in this group.', 'jlife-challenges' ) );
	}
	$before = count( jlife_challenges_active_memberships( $campaign_id, $user_id ) );
	if ( $before >= JLIFE_CHALLENGES_MAX_GROUPS ) {
		return jlife_challenges_group_limit_error();
	}
	global $wpdb;
	$t    = jlife_challenges_table( 'group_members' );
	$data = array(
		'campaign_id'   => $campaign_id,
		'enrollment_id' => (int) $enrollment['id'],
		'user_id'       => $user_id,
		'group_id'      => (int) $group['id'],
		'role'          => $role,
		'status'        => 'active',
		'joined_at'     => current_time( 'mysql', true ),
		'left_at'       => null,
	);
	if ( $existing ) {
		$wpdb->update( $t, $data, array( 'id' => (int) $existing['id'] ) );
		$id = (int) $existing['id'];
	} elseif ( false === $wpdb->insert( $t, $data ) ) {
		// UNIQUE (group_id, user_id): a concurrent request just added them.
		return new WP_Error( 'jlife_already_enrolled', __( 'You are already in this group.', 'jlife-challenges' ) );
	} else {
		$id = (int) $wpdb->insert_id;
	}
	// Two requests can both pass the count above; whoever finishes over the limit backs out.
	$after = count( jlife_challenges_active_memberships( $campaign_id, $user_id ) );
	if ( $after > JLIFE_CHALLENGES_MAX_GROUPS ) {
		$wpdb->update(
			$t,
			array(
				'status'  => 'left',
				'left_at' => current_time( 'mysql', true ),
			),
			array( 'id' => $id )
		);
		return jlife_challenges_group_limit_error();
	}
	if ( 'move' !== $source ) {
		jlife_challenges_audit(
			$actor,
			'group_joined',
			'enrollment',
			(int) $enrollment['id'],
			$campaign_id,
			array(
				'group_id' => (int) $group['id'],
				'role'     => $role,
				'source'   => $source,
			)
		);
	}
	return $id;
}

/**
 * The refusal for a fourth group.
 *
 * @return WP_Error
 */
function jlife_challenges_group_limit_error() {
	return new WP_Error(
		'jlife_group_limit',
		sprintf(
			/* translators: %d: the most groups one person can be in. */
			_n(
				'You are already in the most groups you can join (%d). Ask an organizer if you need to change one.',
				'You are already in the most groups you can join (%d). Ask an organizer if you need to change one.',
				JLIFE_CHALLENGES_MAX_GROUPS,
				'jlife-challenges'
			),
			JLIFE_CHALLENGES_MAX_GROUPS
		)
	);
}

/**
 * A membership row for a person and group, any status.
 *
 * @param int $group_id Group.
 * @param int $user_id  User.
 * @return array|null
 */
function jlife_challenges_get_membership( $group_id, $user_id ) {
	global $wpdb;
	$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . jlife_challenges_table( 'group_members' ) . ' WHERE group_id = %d AND user_id = %d', (int) $group_id, (int) $user_id ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- table name from $wpdb->prefix.
	return $row ? $row : null;
}

/**
 * A participant joins a group with an invite code. With no enrollment yet it
 * creates one; with one (solo, or already in other groups) it keeps it and adds
 * the group, up to the limit. Wrong guesses are rate limited per person.
 *
 * @param int    $user_id  Participant (signed in).
 * @param string $code     Invite code.
 * @param string $age_band adult|minor (the sign-up flow blocks under-13s); ignored for an existing enrollment.
 * @return int|WP_Error Membership ID.
 */
function jlife_challenges_join_with_code( $user_id, $code, $age_band ) {
	if ( $user_id < 1 ) {
		return jlife_challenges_denied();
	}
	$key   = 'jlife_chal_join_tries_' . (int) $user_id;
	$tries = (int) get_transient( $key );
	if ( $tries >= JLIFE_CHALLENGES_JOIN_TRIES ) {
		return new WP_Error( 'jlife_join_rate', __( 'Too many codes were tried. Please wait an hour, or ask your group for a new one.', 'jlife-challenges' ) );
	}
	$group = jlife_challenges_joinable_group_for_invite( $code );
	if ( ! $group ) {
		set_transient( $key, $tries + 1, HOUR_IN_SECONDS );
		return new WP_Error( 'jlife_invite_invalid', __( 'That invite code is not valid. Please ask your group for a new one.', 'jlife-challenges' ) );
	}
	$opened = jlife_challenges_open_enrollment( $user_id, (int) $group['campaign_id'], $age_band );
	if ( is_wp_error( $opened ) ) {
		return $opened;
	}
	if ( $opened['created'] ) {
		jlife_challenges_audit( $user_id, 'enrolled', 'enrollment', (int) $opened['enrollment']['id'], (int) $group['campaign_id'], array( 'mode' => 'invite_code' ) );
	}
	$id = jlife_challenges_add_membership( $user_id, $opened['enrollment'], $group, 'member', 'invite_code' );
	if ( ! is_wp_error( $id ) ) {
		delete_transient( $key );
	}
	return $id;
}

/**
 * "Read on my own": enroll with no group. Consent and the age band are kept as
 * for any join. An existing active enrollment is returned unchanged.
 *
 * @param int    $user_id     Participant (signed in).
 * @param int    $campaign_id Campaign (see jlife_challenges_open_campaign()).
 * @param string $age_band    adult|minor.
 * @return int|WP_Error Enrollment ID.
 */
function jlife_challenges_enroll_solo( $user_id, $campaign_id, $age_band ) {
	if ( ! in_array( $age_band, JLIFE_CHALLENGES_AGE_BANDS, true ) ) {
		return new WP_Error( 'jlife_age_band', 'age_band must be adult or minor (participants are 13 or older).' );
	}
	$campaign = jlife_challenges_get_campaign( $campaign_id );
	if ( $user_id < 1 || ! $campaign || ! jlife_challenges_campaign_is_open( $campaign ) ) {
		return jlife_challenges_denied();
	}
	$opened = jlife_challenges_open_enrollment( $user_id, (int) $campaign_id, $age_band );
	if ( is_wp_error( $opened ) ) {
		return $opened;
	}
	if ( $opened['created'] ) {
		jlife_challenges_audit( $user_id, 'enrolled', 'enrollment', (int) $opened['enrollment']['id'], (int) $campaign_id, array( 'mode' => 'solo' ) );
	}
	return (int) $opened['enrollment']['id'];
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
 * The group an invite code opens, only while its campaign can be joined
 * (running or upcoming, §3b). A code for a group in an ended, closed or draft
 * campaign opens nothing, like an unknown code.
 *
 * @param string $code Invite code.
 * @return array|null Group row.
 */
function jlife_challenges_joinable_group_for_invite( $code ) {
	$group = jlife_challenges_group_for_invite( $code );
	if ( ! $group ) {
		return null;
	}
	$campaign = jlife_challenges_get_campaign( (int) $group['campaign_id'] );
	return $campaign && jlife_challenges_campaign_is_open( $campaign ) ? $group : null;
}

/**
 * An organizer adds a participant to a group (creating the enrollment if they
 * have none). The limit of JLIFE_CHALLENGES_MAX_GROUPS applies to organizers too.
 *
 * @param int    $actor    Organizer.
 * @param int    $user_id  Participant.
 * @param int    $group_id Group.
 * @param string $age_band adult|minor (used only when this creates the enrollment).
 * @return int|WP_Error Membership ID.
 */
function jlife_challenges_enroll( $actor, $user_id, $group_id, $age_band = 'adult' ) {
	$group = jlife_challenges_get_group( $group_id );
	if ( ! jlife_challenges_is_organizer( $actor ) || ! $group || 'active' !== $group['status'] ) {
		return jlife_challenges_denied();
	}
	$opened = jlife_challenges_open_enrollment( $user_id, (int) $group['campaign_id'], $age_band );
	if ( is_wp_error( $opened ) ) {
		return $opened;
	}
	if ( $opened['created'] ) {
		jlife_challenges_audit( $actor, 'enrolled', 'enrollment', (int) $opened['enrollment']['id'], (int) $group['campaign_id'], array( 'mode' => 'organizer' ) );
	}
	return jlife_challenges_add_membership( $actor, $opened['enrollment'], $group, 'member', 'organizer' );
}

/**
 * A participant leaves a group. Their shares there stay with that group (D18);
 * they lose its feed; the slot is free for another group. The enrollment, their
 * entries and their points are untouched.
 *
 * @param int $user_id  Participant.
 * @param int $group_id Group.
 * @return true|WP_Error
 */
function jlife_challenges_leave_group( $user_id, $group_id ) {
	$m = jlife_challenges_get_membership( $group_id, $user_id );
	if ( $user_id < 1 || ! $m || 'active' !== $m['status'] ) {
		return jlife_challenges_denied();
	}
	// A share whose day has already opened is released while its author is still the starter; the rest are withdrawn below (#209).
	jlife_challenges_release_due_shares( (int) $group_id );
	global $wpdb;
	$wpdb->update(
		jlife_challenges_table( 'group_members' ),
		array(
			'status'  => 'left',
			'left_at' => current_time( 'mysql', true ),
		),
		array( 'id' => (int) $m['id'] )
	);
	jlife_challenges_audit( $user_id, 'group_left', 'enrollment', (int) $m['enrollment_id'], (int) $m['campaign_id'], array( 'group_id' => (int) $group_id ) );
	jlife_challenges_withdraw_unreleased_shares( (int) $user_id, (int) $group_id, (int) $m['campaign_id'] );
	return true;
}

/**
 * Move a participant to another group in the same campaign: one membership is
 * swapped for another and the rest are kept (D18: their past shares stay with
 * the old group).
 *
 * @param int $actor         Organizer.
 * @param int $user_id       Participant.
 * @param int $new_group_id  Destination group.
 * @param int $from_group_id Group they leave. May be 0 only when they are in exactly one.
 * @return true|WP_Error
 */
function jlife_challenges_move_member( $actor, $user_id, $new_group_id, $from_group_id = 0 ) {
	$group = jlife_challenges_get_group( $new_group_id );
	if ( ! jlife_challenges_is_organizer( $actor ) || ! $group || 'active' !== $group['status'] ) {
		return jlife_challenges_denied();
	}
	$e = jlife_challenges_active_enrollment( (int) $group['campaign_id'], $user_id );
	if ( ! $e ) {
		return jlife_challenges_denied();
	}
	if ( ! jlife_challenges_campaign_accepts_writes( (int) $group['campaign_id'] ) ) {
		return jlife_challenges_campaign_closed_error();
	}
	$current = jlife_challenges_active_memberships( (int) $group['campaign_id'], $user_id );
	if ( ! $from_group_id && 1 === count( $current ) ) {
		$from_group_id = (int) $current[0]['group_id'];
	}
	if ( ! $from_group_id ) {
		return new WP_Error( 'jlife_move_ambiguous', 'Name the group the person is leaving.' );
	}
	if ( (int) $from_group_id === (int) $new_group_id ) {
		return new WP_Error( 'jlife_move_same', __( 'Choose a different group to move them to.', 'jlife-challenges' ) );
	}
	$from = jlife_challenges_get_membership( $from_group_id, $user_id );
	if ( ! $from || 'active' !== $from['status'] || (int) $from['campaign_id'] !== (int) $group['campaign_id'] ) {
		return jlife_challenges_denied();
	}
	global $wpdb;
	$t = jlife_challenges_table( 'group_members' );
	jlife_challenges_release_due_shares( (int) $from_group_id ); // #209: release what has opened before the starter role goes.
	// End the old membership first so the swap never trips the limit; put it back if the new one fails.
	$wpdb->update(
		$t,
		array(
			'status'  => 'left',
			'left_at' => current_time( 'mysql', true ),
		),
		array( 'id' => (int) $from['id'] )
	);
	$added = jlife_challenges_add_membership( $actor, $e, $group, 'member', 'move' );
	if ( is_wp_error( $added ) ) {
		$wpdb->update(
			$t,
			array(
				'status'  => 'active',
				'left_at' => null,
			),
			array( 'id' => (int) $from['id'] )
		);
		return $added;
	}
	jlife_challenges_withdraw_unreleased_shares( (int) $user_id, (int) $from_group_id, (int) $group['campaign_id'] );
	jlife_challenges_audit(
		$actor,
		'member_moved',
		'enrollment',
		(int) $e['id'],
		(int) $group['campaign_id'],
		array(
			'from_group' => (int) $from_group_id,
			'to_group'   => (int) $new_group_id,
		)
	);
	return true;
}

/**
 * Remove a participant from the campaign: the enrollment and every membership
 * end (their entries and points remain theirs).
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
	$now       = current_time( 'mysql', true );
	$group_ids = array_map( 'intval', array_column( jlife_challenges_active_memberships( $campaign_id, $user_id ), 'group_id' ) );
	foreach ( $group_ids as $gid ) {
		jlife_challenges_release_due_shares( $gid ); // Already-open shares are released while the author is still eligible (#209).
	}
	$wpdb->update(
		jlife_challenges_table( 'enrollments' ),
		array(
			'status'  => 'removed',
			'left_at' => $now,
		),
		array( 'id' => (int) $e['id'] )
	);
	$wpdb->update(
		jlife_challenges_table( 'group_members' ),
		array(
			'status'  => 'removed',
			'left_at' => $now,
		),
		array(
			'enrollment_id' => (int) $e['id'],
			'status'        => 'active',
		)
	);
	jlife_challenges_audit( $actor, 'member_removed', 'enrollment', (int) $e['id'], (int) $campaign_id, array( 'group_ids' => $group_ids ) );
	jlife_challenges_withdraw_unreleased_shares( (int) $user_id, 0, (int) $campaign_id );
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
	$m       = jlife_challenges_table( 'group_members' );
	$e       = jlife_challenges_table( 'enrollments' );
	$members = $wpdb->get_col( $wpdb->prepare( "SELECT m.user_id FROM {$m} m JOIN {$e} e ON e.id = m.enrollment_id AND e.status = 'active' WHERE m.group_id = %d AND m.status = 'active'", (int) $group_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- tables from $wpdb->prefix.
	return array(
		'exists'          => 'active' === $group['status'],
		'leader_user_ids' => array(),
		'member_user_ids' => array_map( 'intval', $members ),
	);
}
