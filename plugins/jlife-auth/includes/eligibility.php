<?php
/**
 * Who may sign in with an emailed code.
 *
 * Emailed codes are for participants only. Staff (anyone with a role or
 * capability beyond the participant roles, on any site in the network, and
 * network super admins) sign in on the standard WordPress login instead.
 *
 * - The rule is an allowlist: every role and capability the account holds, on
 *   every site of the network, must be a participant role. A custom role
 *   without `edit_posts` is still staff.
 * - It is checked when a code is requested and again when it is entered, so a
 *   promotion in between voids the code.
 * - Gaining a staff role ends the person's existing sessions, so a session
 *   started with a code never carries staff rights.
 * - A staff address gets a short notice email instead of a code; the screen
 *   answers exactly as for any other address (no account enumeration).
 *
 * Someone who is both staff and a participant uses two accounts with two
 * addresses (for example name+latoc@example.test for the participant one).
 *
 * @package jlife-auth
 */

defined( 'ABSPATH' ) || exit;

/**
 * Roles that may sign in with an emailed code.
 *
 * @return string[]
 */
function jlife_auth_participant_roles() {
	/**
	 * Filters the roles that may sign in with an emailed code. Add a role only
	 * if it grants nothing beyond what a participant may do.
	 *
	 * @param string[] $roles Default: subscriber.
	 */
	return array_values( array_filter( array_map( 'strval', (array) apply_filters( 'jlife_auth_participant_roles', array( 'subscriber' ) ) ) ) );
}

/**
 * The account's roles and capabilities on every site of the network, read
 * from the per-site `*capabilities` user meta (so sites other than the
 * current one count too).
 *
 * @param int $user_id User.
 * @return array<int, array> One capability array per site.
 */
function jlife_auth_capability_sets( $user_id ) {
	global $wpdb;
	$pattern = '/^' . preg_quote( $wpdb->base_prefix, '/' ) . '(?:\d+_)?capabilities$/';
	$sets    = array();
	foreach ( (array) get_user_meta( (int) $user_id ) as $key => $values ) {
		if ( ! preg_match( $pattern, (string) $key ) ) {
			continue;
		}
		foreach ( (array) $values as $value ) {
			$sets[] = (array) maybe_unserialize( $value );
		}
	}
	return $sets;
}

/**
 * May this account sign in with an emailed code?
 *
 * @param int $user_id User.
 * @return bool False for super admins and for anyone holding any role or
 *              capability outside the participant roles on any site.
 */
function jlife_auth_email_code_allowed( $user_id ) {
	$user_id = (int) $user_id;
	if ( $user_id <= 0 || ! get_userdata( $user_id ) || is_super_admin( $user_id ) ) {
		return false;
	}
	$allowed = jlife_auth_participant_roles();
	foreach ( jlife_auth_capability_sets( $user_id ) as $caps ) {
		foreach ( $caps as $cap => $granted ) {
			if ( ! empty( $granted ) && ! in_array( (string) $cap, $allowed, true ) ) {
				return false;
			}
		}
	}
	return true;
}

/**
 * Tell a staff address that emailed codes are not for staff accounts. Sent
 * instead of a code; no code is created and nothing on screen differs.
 *
 * @param string $email   Normalized address.
 * @param array  $payload Request details (locale used for the language).
 */
function jlife_auth_send_staff_notice( $email, $payload = array() ) {
	list( $subject, $body ) = jlife_auth_in_locale(
		(string) ( $payload['locale'] ?? '' ),
		static function () {
			$site = wp_specialchars_decode( apply_filters( 'jlife_auth_email_site_name', get_bloginfo( 'name' ) ), ENT_QUOTES );
			return array(
				/* translators: %s: site name. */
				sprintf( __( '%s: please use the staff sign-in', 'jlife-auth' ), $site ),
				__( "Someone asked for a sign-in code for this address. This address belongs to a staff account, and staff accounts cannot sign in with an emailed code. No code was sent.\n\nPlease sign in on the site's staff login page with your password.\n\nTo take part in a reading campaign as a participant, join with a different email address.\n\nIf you did not ask for a code, you can ignore this email.", 'jlife-auth' ),
			);
		}
	);
	if ( ! wp_mail( $email, $subject, $body ) ) {
		error_log( 'jlife-auth: staff sign-in notice could not be sent.' ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- operational signal, no personal data.
	}
}

add_action( 'set_user_role', 'jlife_auth_end_sessions_on_staff_role', 10, 2 );
add_action( 'add_user_role', 'jlife_auth_end_sessions_on_staff_role', 10, 2 );

/**
 * A role outside the participant roles ends the person's sessions, so a
 * session started with an emailed code never carries staff rights.
 *
 * @param int    $user_id User.
 * @param string $role    Role just given.
 */
function jlife_auth_end_sessions_on_staff_role( $user_id, $role ) {
	if ( ! in_array( (string) $role, jlife_auth_participant_roles(), true ) ) {
		jlife_auth_end_sessions( (int) $user_id );
	}
}

add_action( 'add_user_to_blog', 'jlife_auth_end_sessions_on_site_role', 10, 2 );

/**
 * Same rule when someone is added to another site of the network.
 *
 * @param int    $user_id User.
 * @param string $role    Role on that site.
 */
function jlife_auth_end_sessions_on_site_role( $user_id, $role ) {
	jlife_auth_end_sessions_on_staff_role( $user_id, $role );
}

add_action( 'granted_super_admin', 'jlife_auth_end_sessions' );

/**
 * End every session of a user.
 *
 * @param int $user_id User.
 */
function jlife_auth_end_sessions( $user_id ) {
	if ( (int) $user_id > 0 ) {
		WP_Session_Tokens::get_instance( (int) $user_id )->destroy_all();
	}
}
