<?php
/**
 * Accounts: joining with an invite code, finishing sign-in, consent (#58).
 *
 * - Participants are 13 or older; the age band (adult|minor) is recorded at
 *   join (D19). Under-13s cannot join.
 * - Language (English / Tiếng Việt) is chosen at join and stored as the
 *   user's WordPress locale (D13).
 * - Consent is accepted once per wording version (D15) and recorded on the
 *   user and on each active enrollment.
 *
 * @package jlife-auth
 */

defined( 'ABSPATH' ) || exit;

const JLIFE_AUTH_LOCALES = array(
	'en_US' => 'English',
	'vi'    => 'Tiếng Việt',
);

/**
 * Current consent wording version. Bump it when the owner changes the
 * wording (#65) so everyone is asked again.
 */
const JLIFE_AUTH_CONSENT_VERSION = 'draft-2026-11-05';

/**
 * Validate join details and send a join code.
 *
 * @param array  $input { email, first_name, invite_code, age_band (adult|minor), locale }.
 * @param string $ip    Requester IP.
 * @return true|WP_Error
 */
function jlife_auth_start_join( $input, $ip = '' ) {
	$first = sanitize_text_field( (string) ( $input['first_name'] ?? '' ) );
	$band  = (string) ( $input['age_band'] ?? '' );
	$loc   = (string) ( $input['locale'] ?? 'en_US' );
	$code  = strtoupper( preg_replace( '/[^A-Za-z0-9]/', '', (string) ( $input['invite_code'] ?? '' ) ) );
	if ( '' === $first ) {
		return new WP_Error( 'jlife_auth_name', __( 'Please enter your first name.', 'jlife-auth' ) );
	}
	if ( ! in_array( $band, array( 'adult', 'minor' ), true ) ) {
		return new WP_Error( 'jlife_auth_age', __( 'Please choose your age group. J-Life is for ages 13 and up.', 'jlife-auth' ) );
	}
	if ( ! array_key_exists( $loc, JLIFE_AUTH_LOCALES ) ) {
		$loc = 'en_US';
	}
	if ( ! function_exists( 'jlife_challenges_group_for_invite' ) || ! jlife_challenges_group_for_invite( $code ) ) {
		return new WP_Error( 'jlife_invite_invalid', __( 'That invite code is not valid. Please ask your group for a new one.', 'jlife-auth' ) );
	}
	return jlife_auth_request_code(
		(string) ( $input['email'] ?? '' ),
		'join',
		array(
			'first_name'  => mb_substr( $first, 0, 60 ),
			'invite_code' => $code,
			'age_band'    => $band,
			'locale'      => $loc,
		),
		$ip
	);
}

/**
 * Create a participant account for a verified email address.
 *
 * @param string $email   Normalized, verified email.
 * @param array  $payload Join payload.
 * @return int|WP_Error User ID.
 */
function jlife_auth_create_participant( $email, $payload ) {
	$base = sanitize_user( strtok( $email, '@' ), true );
	$base = '' === $base ? 'participant' : $base;
	do {
		$login = $base . '-' . strtolower( wp_generate_password( 5, false ) );
	} while ( username_exists( $login ) );
	$user_id = wp_insert_user(
		array(
			'user_login'   => $login,
			'user_email'   => $email,
			'user_pass'    => wp_generate_password( 32, true, true ), // Never used: sign-in is by code.
			'first_name'   => $payload['first_name'],
			'display_name' => $payload['first_name'],
			'nickname'     => $payload['first_name'],
			'role'         => 'subscriber',
			'locale'       => $payload['locale'],
		)
	);
	return is_wp_error( $user_id ) ? $user_id : (int) $user_id;
}

/**
 * Finish sign-in or join after the code is checked. Does not set cookies
 * (see jlife_auth_log_in) so it can be tested directly.
 *
 * @param string $email Email address.
 * @param string $code  Code as typed.
 * @return array|WP_Error { user_id, purpose, notice }
 */
function jlife_auth_complete( $email, $code ) {
	$checked = jlife_auth_check_code( $email, $code );
	if ( is_wp_error( $checked ) ) {
		return $checked;
	}
	$email = jlife_auth_normalize_email( $email );
	$user  = get_user_by( 'email', $email );

	if ( 'signin' === $checked['purpose'] ) {
		return $user ? array(
			'user_id' => (int) $user->ID,
			'purpose' => 'signin',
			'notice'  => '',
		) : new WP_Error( 'jlife_auth_code', __( 'That code is not valid. Please check it or ask for a new one.', 'jlife-auth' ) );
	}

	$payload = (array) $checked['payload'];
	$user_id = $user ? (int) $user->ID : jlife_auth_create_participant( $email, $payload );
	if ( is_wp_error( $user_id ) ) {
		return $user_id;
	}
	$notice = '';
	$joined = jlife_challenges_join_with_code( $user_id, $payload['invite_code'], $payload['age_band'] );
	if ( is_wp_error( $joined ) ) {
		if ( 'jlife_already_enrolled' !== $joined->get_error_code() ) {
			return $joined;
		}
		$notice = $joined->get_error_message();
	}
	return array(
		'user_id' => $user_id,
		'purpose' => 'join',
		'notice'  => $notice,
	);
}

/**
 * Does the user still need to accept the current consent wording?
 *
 * @param int $user_id User.
 * @return bool
 */
function jlife_auth_needs_consent( $user_id ) {
	return jlife_auth_is_participant( $user_id ) && JLIFE_AUTH_CONSENT_VERSION !== get_user_meta( $user_id, 'jlife_consent_version', true );
}

/**
 * Record acceptance of the current consent wording.
 *
 * @param int $user_id User.
 */
function jlife_auth_record_consent( $user_id ) {
	update_user_meta( $user_id, 'jlife_consent_version', JLIFE_AUTH_CONSENT_VERSION );
	update_user_meta( $user_id, 'jlife_consent_at', current_time( 'mysql', true ) );
	if ( function_exists( 'jlife_challenges_user_enrollments' ) ) {
		foreach ( jlife_challenges_user_enrollments( $user_id ) as $e ) {
			jlife_challenges_record_consent( $user_id, (int) $e['campaign_id'], JLIFE_AUTH_CONSENT_VERSION );
		}
	}
}
