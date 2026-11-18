<?php
/**
 * Organizer oversight of groups (#112 part 2): list, rename, disable and
 * re-enable any group, including ones a participant started. Reached from
 * WP-CLI (`wp jlife group ...`, see class-jlife-group-command.php); there is no
 * participant-facing path to any of it. Every change is audited (D18).
 *
 * Organizers see group names, status, who started it (a user ID) and how many
 * active members it has. They never get codes, hashes, entries or a member list.
 *
 * @package jlife-challenges
 */

defined( 'ABSPATH' ) || exit;

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
