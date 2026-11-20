<?php
/**
 * Organizer oversight of groups (#112 part 2): list, rename, disable and
 * re-enable any group, including ones a participant started. Reached from
 * WP-CLI (`wp jlife group ...`, see class-jlife-group-command.php); there is no
 * participant-facing path to any of it. Every change is audited (D18).
 *
 * The CLI list shows group names, status, who started it (a user ID) and how many
 * active members each has; it never prints codes, hashes, entries or member names.
 * The J-Life → Groups screen (groups-admin-page.php, #64 part 1) adds invite
 * expiry and revoking, and a member list of display names so an organizer can add,
 * move and remove people; it never shows a code again after it is made, a hash,
 * an entry, a share, an email address or a session token.
 *
 * @package jlife-challenges
 */

defined( 'ABSPATH' ) || exit;

/**
 * Invite expiry choices on the Groups screen, in days.
 */
const JLIFE_CHALLENGES_INVITE_DAYS = array( 7, 14, 30, 60, 90 );

// phpcs:disable WordPress.DB.DirectDatabaseQuery -- custom tables are this plugin's data layer; every query goes through the gate; WordPress has no API for them.

/**
 * List groups with their status and active member counts. Organizers only.
 *
 * @param int $actor       Organizer.
 * @param int $campaign_id Campaign, or 0 for all.
 * @return array[]|WP_Error Rows of { id, campaign_id, name, status, started_by, created, members }.
 */
function jlife_challenges_list_groups( $actor, $campaign_id = 0 ) {
	if ( ! jlife_challenges_is_organizer( $actor ) ) {
		return jlife_challenges_denied();
	}
	global $wpdb;
	$g     = jlife_challenges_table( 'groups' );
	$m     = jlife_challenges_table( 'group_members' );
	$e     = jlife_challenges_table( 'enrollments' );
	$where = (int) $campaign_id > 0 ? $wpdb->prepare( 'WHERE g.campaign_id = %d', (int) $campaign_id ) : '';
	// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQL.NotPrepared -- custom tables; names from $wpdb->prefix; $where is prepared above.
	$rows = $wpdb->get_results( "SELECT g.id, g.campaign_id, g.name, g.status, g.started_by, g.created, (SELECT COUNT(*) FROM {$m} m JOIN {$e} e ON e.id = m.enrollment_id AND e.status = 'active' WHERE m.group_id = g.id AND m.status = 'active') AS members FROM {$g} g {$where} ORDER BY g.id", ARRAY_A );
	return is_array( $rows ) ? $rows : array();
}

/**
 * Rename a group. Organizers only; the same name rules as starting a group.
 *
 * @param int    $actor    Organizer.
 * @param int    $group_id Group.
 * @param string $name     New name.
 * @return true|WP_Error
 */
function jlife_challenges_rename_group( $actor, $group_id, $name ) {
	$group = jlife_challenges_get_group( $group_id );
	if ( ! jlife_challenges_is_organizer( $actor ) || ! $group ) {
		return jlife_challenges_denied();
	}
	$name = jlife_challenges_clean_group_name( $name );
	if ( is_wp_error( $name ) ) {
		return $name;
	}
	global $wpdb;
	$wpdb->update( jlife_challenges_table( 'groups' ), array( 'name' => $name ), array( 'id' => (int) $group_id ), array( '%s' ), array( '%d' ) );
	jlife_challenges_audit(
		$actor,
		'group_renamed',
		'group',
		(int) $group_id,
		(int) $group['campaign_id'],
		array(
			'from' => (string) $group['name'],
			'to'   => $name,
		)
	);
	return true;
}

/**
 * Disable a group: its invite code stops working, its members lose its feed and
 * the slot it used, and nobody can post to it. Nothing is deleted, so enabling
 * it again restores it. Organizers only.
 *
 * @param int $actor    Organizer.
 * @param int $group_id Group.
 * @return true|WP_Error
 */
function jlife_challenges_disable_group( $actor, $group_id ) {
	return jlife_challenges_set_group_status( $actor, $group_id, 'disabled' );
}

/**
 * Enable a disabled group. Refused if that would put any member over the
 * group limit. Organizers only.
 *
 * @param int $actor    Organizer.
 * @param int $group_id Group.
 * @return true|WP_Error
 */
function jlife_challenges_enable_group( $actor, $group_id ) {
	return jlife_challenges_set_group_status( $actor, $group_id, 'active' );
}

/**
 * Set a group's status to active or disabled (audited).
 *
 * @param int    $actor    Organizer.
 * @param int    $group_id Group.
 * @param string $status   active|disabled.
 * @return true|WP_Error
 */
function jlife_challenges_set_group_status( $actor, $group_id, $status ) {
	$group = jlife_challenges_get_group( $group_id );
	if ( ! jlife_challenges_is_organizer( $actor ) || ! $group || ! in_array( $status, array( 'active', 'disabled' ), true ) ) {
		return jlife_challenges_denied();
	}
	if ( $status === $group['status'] ) {
		return true;
	}
	global $wpdb;
	if ( 'active' === $status ) {
		$m     = jlife_challenges_table( 'group_members' );
		$e     = jlife_challenges_table( 'enrollments' );
		$users = $wpdb->get_col( $wpdb->prepare( "SELECT m.user_id FROM {$m} m JOIN {$e} e ON e.id = m.enrollment_id AND e.status = 'active' WHERE m.group_id = %d AND m.status = 'active'", (int) $group_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- custom tables; names from $wpdb->prefix.
		foreach ( $users as $user_id ) {
			if ( count( jlife_challenges_active_memberships( (int) $group['campaign_id'], (int) $user_id ) ) >= JLIFE_CHALLENGES_MAX_GROUPS ) {
				return jlife_challenges_group_limit_error();
			}
		}
	}
	$wpdb->update( jlife_challenges_table( 'groups' ), array( 'status' => $status ), array( 'id' => (int) $group_id ), array( '%s' ), array( '%d' ) );
	jlife_challenges_audit( $actor, 'active' === $status ? 'group_enabled' : 'group_disabled', 'group', (int) $group_id, (int) $group['campaign_id'] );
	return true;
}

/**
 * What state a group's invite code is in. The code itself is stored hashed and
 * cannot be shown again; this is all an organizer can learn about it.
 *
 * @param array $group Group row.
 * @return array { state: none|active|expired, expires: UTC 'Y-m-d H:i:s' or '' }
 */
function jlife_challenges_invite_state( array $group ) {
	if ( '' === (string) ( $group['invite_hash'] ?? '' ) ) {
		return array(
			'state'   => 'none',
			'expires' => '',
		);
	}
	$expires = (string) ( $group['invite_expires'] ?? '' );
	return array(
		'state'   => ( '' !== $expires && strtotime( $expires . ' UTC' ) < time() ) ? 'expired' : 'active',
		'expires' => $expires,
	);
}

/**
 * Revoke a group's invite code: nobody can join with it any more, and no member
 * is touched. Organizers only; audited. Revoking a group with no code does nothing.
 *
 * @param int $actor    Organizer.
 * @param int $group_id Group.
 * @return true|WP_Error
 */
function jlife_challenges_revoke_invite( $actor, $group_id ) {
	$group = jlife_challenges_get_group( $group_id );
	if ( ! jlife_challenges_is_organizer( $actor ) || ! $group ) {
		return jlife_challenges_denied();
	}
	if ( 'none' === jlife_challenges_invite_state( $group )['state'] ) {
		return true;
	}
	global $wpdb;
	$wpdb->update(
		jlife_challenges_table( 'groups' ),
		array(
			'invite_hash'    => '',
			'invite_expires' => null,
		),
		array( 'id' => (int) $group_id ),
		array( '%s', '%s' ),
		array( '%d' )
	);
	jlife_challenges_audit( $actor, 'invite_revoked', 'group', (int) $group_id, (int) $group['campaign_id'] );
	return true;
}

/**
 * A participant's display name for an organizer screen or export. A name that is
 * empty or looks like an email address is replaced by "Participant #ID", so an
 * address never reaches a screen or file through the name.
 *
 * @param int $user_id User.
 * @return string
 */
function jlife_challenges_admin_person_name( $user_id ) {
	$user = (int) $user_id > 0 ? get_userdata( (int) $user_id ) : false;
	$name = $user ? trim( (string) $user->display_name ) : '';
	if ( '' === $name || false !== strpos( $name, '@' ) ) {
		/* translators: %d: a participant's user ID, shown when there is no usable display name. */
		return sprintf( __( 'Participant #%d', 'jlife-challenges' ), (int) $user_id );
	}
	return $name;
}

/**
 * The campaigns on this site, oldest first. Used to pick which campaign's groups an
 * organizer screen shows.
 *
 * @return array[] Rows of { id, title, status }.
 */
function jlife_challenges_admin_campaigns() {
	global $wpdb;
	$rows = $wpdb->get_results( 'SELECT id, title, status FROM ' . jlife_challenges_table( 'campaigns' ) . ' ORDER BY id', ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- table name from $wpdb->prefix.
	return is_array( $rows ) ? $rows : array();
}

/**
 * The campaign an organizer screen shows: the one asked for if it exists, else the
 * open campaign, else the first.
 *
 * @param array[] $campaigns  From jlife_challenges_admin_campaigns().
 * @param int     $campaign_id Campaign asked for (0 = none).
 * @return array|null Campaign row ({ id, title, status }).
 */
function jlife_challenges_admin_default_campaign( array $campaigns, $campaign_id ) {
	foreach ( $campaigns as $c ) {
		if ( (int) $c['id'] === (int) $campaign_id ) {
			return $c;
		}
	}
	$by_id = array_column( $campaigns, null, 'id' );
	$open  = jlife_challenges_open_campaign();
	if ( $open && isset( $by_id[ $open['id'] ] ) ) {
		return $by_id[ $open['id'] ];
	}
	return $campaigns ? $campaigns[0] : null;
}

/**
 * The people in a group, for an organizer: display name and role only. Active
 * members of an active enrollment.
 *
 * @param int $actor    Organizer.
 * @param int $group_id Group.
 * @return array[]|WP_Error Rows of { user_id, name, role, joined_at }.
 */
function jlife_challenges_group_members( $actor, $group_id ) {
	if ( ! jlife_challenges_is_organizer( $actor ) || ! jlife_challenges_get_group( $group_id ) ) {
		return jlife_challenges_denied();
	}
	global $wpdb;
	$m = jlife_challenges_table( 'group_members' );
	$e = jlife_challenges_table( 'enrollments' );
	// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- custom tables; names from $wpdb->prefix.
	$rows = (array) $wpdb->get_results( $wpdb->prepare( "SELECT m.user_id, m.role, m.joined_at FROM {$m} m JOIN {$e} e ON e.id = m.enrollment_id AND e.status = 'active' WHERE m.group_id = %d AND m.status = 'active' ORDER BY m.joined_at, m.id", (int) $group_id ), ARRAY_A );
	$out  = array();
	foreach ( $rows as $r ) {
		$out[] = array(
			'user_id'   => (int) $r['user_id'],
			'name'      => jlife_challenges_admin_person_name( (int) $r['user_id'] ),
			'role'      => (string) $r['role'],
			'joined_at' => (string) $r['joined_at'],
		);
	}
	return $out;
}

/**
 * People an organizer can add to a group: active enrollments in the group's
 * campaign who are not already active members of it. Display names only.
 *
 * @param int $actor    Organizer.
 * @param int $group_id Group.
 * @return array[]|WP_Error Rows of { user_id, name }, by name.
 */
function jlife_challenges_group_add_candidates( $actor, $group_id ) {
	$group = jlife_challenges_get_group( $group_id );
	if ( ! jlife_challenges_is_organizer( $actor ) || ! $group ) {
		return jlife_challenges_denied();
	}
	global $wpdb;
	$m = jlife_challenges_table( 'group_members' );
	$e = jlife_challenges_table( 'enrollments' );
	// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- custom tables; names from $wpdb->prefix.
	$ids = $wpdb->get_col( $wpdb->prepare( "SELECT e.user_id FROM {$e} e WHERE e.campaign_id = %d AND e.status = 'active' AND NOT EXISTS ( SELECT 1 FROM {$m} m WHERE m.group_id = %d AND m.user_id = e.user_id AND m.status = 'active' ) ORDER BY e.id", (int) $group['campaign_id'], (int) $group_id ) );
	$out = array();
	foreach ( $ids as $id ) {
		$out[] = array(
			'user_id' => (int) $id,
			'name'    => jlife_challenges_admin_person_name( (int) $id ),
		);
	}
	usort(
		$out,
		static function ( $x, $y ) {
			$by_name = strcasecmp( $x['name'], $y['name'] );
			return 0 !== $by_name ? $by_name : $x['user_id'] <=> $y['user_id'];
		}
	);
	return $out;
}

/**
 * An organizer adds an existing, enrolled participant to a group. It never
 * enrolls anyone: consent comes from the participant's own sign-up. The group
 * limit applies, and a disabled group is refused.
 *
 * @param int $actor    Organizer.
 * @param int $user_id  Participant, already enrolled in the group's campaign.
 * @param int $group_id Group.
 * @return int|WP_Error Membership ID.
 */
function jlife_challenges_admin_add_member( $actor, $user_id, $group_id ) {
	$group = jlife_challenges_get_group( $group_id );
	if ( ! jlife_challenges_is_organizer( $actor ) || ! $group ) {
		return jlife_challenges_denied();
	}
	$enrollment = jlife_challenges_active_enrollment( (int) $group['campaign_id'], (int) $user_id );
	if ( ! $enrollment ) {
		return jlife_challenges_denied();
	}
	return jlife_challenges_add_membership( $actor, $enrollment, $group, 'member', 'organizer' );
}

/**
 * An organizer removes someone from one group. Only that membership ends: the
 * enrollment, entries, points and the person's other groups are untouched, and
 * what they shared stays with the group (D18).
 *
 * @param int $actor    Organizer.
 * @param int $user_id  Participant.
 * @param int $group_id Group they leave.
 * @return true|WP_Error
 */
function jlife_challenges_remove_from_group( $actor, $user_id, $group_id ) {
	$group      = jlife_challenges_get_group( $group_id );
	$membership = $group ? jlife_challenges_get_membership( (int) $group_id, (int) $user_id ) : null;
	if ( ! jlife_challenges_is_organizer( $actor ) || ! $membership || 'active' !== $membership['status'] || ! jlife_challenges_active_enrollment( (int) $group['campaign_id'], (int) $user_id ) ) {
		return jlife_challenges_denied();
	}
	global $wpdb;
	$wpdb->update(
		jlife_challenges_table( 'group_members' ),
		array(
			'status'  => 'removed',
			'left_at' => current_time( 'mysql', true ),
		),
		array( 'id' => (int) $membership['id'] )
	);
	jlife_challenges_audit( $actor, 'group_member_removed', 'enrollment', (int) $membership['enrollment_id'], (int) $group['campaign_id'], array( 'group_id' => (int) $group_id ) );
	return true;
}

/**
 * An organizer account to act as in CLI commands: the site admin email's user
 * if it can manage the site, else the first administrator.
 *
 * @return int
 */
function jlife_challenges_cli_organizer() {
	$user = get_user_by( 'email', (string) get_option( 'admin_email' ) );
	if ( $user && jlife_challenges_is_organizer( $user->ID ) ) {
		return (int) $user->ID;
	}
	foreach ( get_users(
		array(
			'role'   => 'administrator',
			'fields' => 'ID',
			'number' => 1,
		)
	) as $id ) {
		return (int) $id;
	}
	foreach ( get_super_admins() as $login ) {
		$u = get_user_by( 'login', $login );
		if ( $u ) {
			return (int) $u->ID;
		}
	}
	WP_CLI::error( 'No administrator account found.' );
	return 0;
}
