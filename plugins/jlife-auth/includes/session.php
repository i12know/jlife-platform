<?php
/**
 * Sessions and the participant experience boundary (#58).
 *
 * - Participants stay signed in on their phone through the campaign.
 * - Participants never see wp-admin or the admin bar.
 * - J-Life pages use the participant's chosen language (D13).
 *
 * @package jlife-auth
 */

defined( 'ABSPATH' ) || exit;

const JLIFE_AUTH_SESSION_DAYS = 180;

/**
 * Is this user a participant (no editing rights)?
 *
 * @param int $user_id User.
 * @return bool
 */
function jlife_auth_is_participant( $user_id ) {
	return $user_id > 0 && ! user_can( $user_id, 'edit_posts' );
}

/**
 * Sign the user in on this device ("remember me").
 *
 * @param int $user_id User.
 */
function jlife_auth_log_in( $user_id ) {
	wp_set_current_user( $user_id );
	wp_set_auth_cookie( $user_id, true, is_ssl() );
}

add_filter( 'auth_cookie_expiration', 'jlife_auth_cookie_expiration', 10, 3 );

/**
 * Participants' remembered sessions last through the campaign; staff keep
 * WordPress's default (shorter) sessions.
 *
 * @param int  $length   Seconds.
 * @param int  $user_id  User.
 * @param bool $remember Remember me.
 * @return int
 */
function jlife_auth_cookie_expiration( $length, $user_id, $remember ) {
	return ( $remember && jlife_auth_is_participant( (int) $user_id ) ) ? JLIFE_AUTH_SESSION_DAYS * DAY_IN_SECONDS : $length;
}

add_filter( 'show_admin_bar', 'jlife_auth_show_admin_bar' );

/**
 * Hide the admin bar for participants.
 *
 * @param bool $show Default.
 * @return bool
 */
function jlife_auth_show_admin_bar( $show ) {
	return jlife_auth_is_participant( get_current_user_id() ) ? false : $show;
}

/**
 * Should this request to wp-admin be turned away?
 *
 * @param int    $user_id User.
 * @param string $script  Requested admin script (basename).
 * @return bool
 */
function jlife_auth_should_block_admin( $user_id, $script ) {
	return jlife_auth_is_participant( $user_id ) && ! in_array( $script, array( 'admin-ajax.php', 'admin-post.php' ), true );
}

add_action( 'admin_init', 'jlife_auth_block_admin' );

/**
 * Send participants away from wp-admin.
 */
function jlife_auth_block_admin() {
	$script = isset( $_SERVER['SCRIPT_NAME'] ) ? basename( sanitize_text_field( wp_unslash( $_SERVER['SCRIPT_NAME'] ) ) ) : '';
	if ( ! wp_doing_ajax() && jlife_auth_should_block_admin( get_current_user_id(), $script ) ) {
		wp_safe_redirect( home_url( '/' ) );
		exit;
	}
}

add_filter( 'determine_locale', 'jlife_auth_front_locale' );

/**
 * On the front end, signed-in participants see their chosen language.
 *
 * @param string $locale Locale.
 * @return string
 */
function jlife_auth_front_locale( $locale ) {
	if ( is_admin() || ! did_action( 'plugins_loaded' ) || ! function_exists( 'wp_get_current_user' ) ) {
		return $locale;
	}
	$user_id = get_current_user_id();
	if ( $user_id > 0 ) {
		$chosen = get_user_meta( $user_id, 'locale', true );
		if ( is_string( $chosen ) && array_key_exists( $chosen, JLIFE_AUTH_LOCALES ) ) {
			return $chosen;
		}
	}
	return $locale;
}
