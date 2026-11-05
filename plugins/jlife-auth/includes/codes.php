<?php
/**
 * One-time sign-in codes (#58, D7).
 *
 * - 6 digits, single use, valid 10 minutes, stored only as a keyed hash.
 * - At most 5 wrong guesses per code; then the code is void.
 * - Requests are rate-limited per email address and per IP address.
 * - No account enumeration: requesting a code answers the same way whether
 *   or not the address belongs to an account.
 * - The email contains the code only, never a sign-in link.
 *
 * @package jlife-auth
 */

defined( 'ABSPATH' ) || exit;

const JLIFE_AUTH_CODE_TTL        = 600; // 10 minutes.
const JLIFE_AUTH_MAX_ATTEMPTS    = 5;
const JLIFE_AUTH_EMAIL_LIMIT     = 3; // Codes per email per window.
const JLIFE_AUTH_EMAIL_WINDOW    = 900; // 15 minutes.
const JLIFE_AUTH_IP_LIMIT        = 10; // Codes per IP per window.
const JLIFE_AUTH_IP_WINDOW       = 3600; // 1 hour.
const JLIFE_AUTH_PURPOSES        = array( 'signin', 'join' );
const JLIFE_AUTH_CODE_KEY_PREFIX = 'jlife_auth_code_';

/**
 * Normalize an email address.
 *
 * @param string $email Raw email.
 * @return string Lowercased, trimmed; '' if invalid.
 */
function jlife_auth_normalize_email( $email ) {
	$email = strtolower( trim( (string) $email ) );
	return is_email( $email ) ? $email : '';
}

/**
 * Keyed hash used for storage keys and code hashes (never the raw value).
 *
 * @param string $value Value.
 * @return string
 */
function jlife_auth_hash( $value ) {
	return hash_hmac( 'sha256', (string) $value, wp_salt( 'auth' ) );
}

/**
 * Transient key for an email's pending code.
 *
 * @param string $email Normalized email.
 * @return string
 */
function jlife_auth_code_key( $email ) {
	return JLIFE_AUTH_CODE_KEY_PREFIX . substr( jlife_auth_hash( 'code|' . $email ), 0, 40 );
}

/**
 * Count a request against a rate-limit bucket.
 *
 * @param string $bucket Bucket identity.
 * @param int    $limit  Max requests per window.
 * @param int    $window Window in seconds.
 * @return bool True if this request is within the limit.
 */
function jlife_auth_rate_ok( $bucket, $limit, $window ) {
	$key   = 'jlife_auth_rl_' . substr( jlife_auth_hash( 'rl|' . $bucket ), 0, 40 );
	$state = get_transient( $key );
	$now   = time();
	if ( ! is_array( $state ) || $state['reset'] <= $now ) {
		$state = array(
			'count' => 0,
			'reset' => $now + $window,
		);
	}
	++$state['count'];
	set_transient( $key, $state, max( 1, $state['reset'] - $now ) );
	return $state['count'] <= $limit;
}

/**
 * Request a sign-in or join code.
 *
 * For 'signin', a code is emailed only if the address has an account; the
 * answer is the same either way. For 'join', the invite is checked first by
 * the caller and the code is always sent (the account is created on verify).
 *
 * @param string $email   Email address.
 * @param string $purpose signin|join.
 * @param array  $payload Join details (first_name, invite_code, age_band, locale).
 * @param string $ip      Requester IP (for rate limiting).
 * @return true|WP_Error  WP_Error only for a malformed address or rate limiting.
 */
function jlife_auth_request_code( $email, $purpose = 'signin', $payload = array(), $ip = '' ) {
	$email = jlife_auth_normalize_email( $email );
	if ( '' === $email ) {
		return new WP_Error( 'jlife_auth_email', __( 'Please enter a valid email address.', 'jlife-auth' ) );
	}
	if ( ! in_array( $purpose, JLIFE_AUTH_PURPOSES, true ) ) {
		return new WP_Error( 'jlife_auth_purpose', 'Unknown purpose.' );
	}
	$email_ok = jlife_auth_rate_ok( 'email|' . $email, JLIFE_AUTH_EMAIL_LIMIT, JLIFE_AUTH_EMAIL_WINDOW );
	$ip_ok    = '' === $ip || jlife_auth_rate_ok( 'ip|' . $ip, JLIFE_AUTH_IP_LIMIT, JLIFE_AUTH_IP_WINDOW );
	if ( ! $email_ok || ! $ip_ok ) {
		return new WP_Error( 'jlife_auth_slow_down', __( 'Too many code requests. Please wait a few minutes and try again.', 'jlife-auth' ) );
	}
	if ( 'signin' === $purpose && ! get_user_by( 'email', $email ) ) {
		return true; // Same answer as a real send: no account enumeration.
	}

	$code = sprintf( '%06d', random_int( 0, 999999 ) );
	set_transient(
		jlife_auth_code_key( $email ),
		array(
			'hash'     => jlife_auth_hash( 'otp|' . $email . '|' . $code ),
			'expires'  => time() + JLIFE_AUTH_CODE_TTL,
			'attempts' => 0,
			'purpose'  => $purpose,
			'payload'  => $payload,
		),
		JLIFE_AUTH_CODE_TTL
	);

	/* translators: %s: site name. */
	$subject = sprintf( __( 'Your %s sign-in code', 'jlife-auth' ), wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ) );
	$body    = sprintf(
		/* translators: %s: 6-digit code. */
		__( "Your code is: %s\n\nIt works once and expires in 10 minutes.\nIf you did not ask for it, you can ignore this email.", 'jlife-auth' ),
		$code
	);
	if ( ! wp_mail( $email, $subject, $body ) ) {
		// The participant sees the same answer either way (no enumeration);
		// organizers find delivery problems in the server log.
		error_log( 'jlife-auth: sign-in code email could not be sent.' ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- operational signal, no personal data.
	}
	return true;
}

/**
 * Check a code. Single use; void after too many wrong guesses or expiry.
 *
 * @param string $email Email address.
 * @param string $code  Code as typed.
 * @return array|WP_Error { purpose, payload } on success.
 */
function jlife_auth_check_code( $email, $code ) {
	$email   = jlife_auth_normalize_email( $email );
	$invalid = new WP_Error( 'jlife_auth_code', __( 'That code is not valid. Please check it or ask for a new one.', 'jlife-auth' ) );
	if ( '' === $email ) {
		return $invalid;
	}
	$key   = jlife_auth_code_key( $email );
	$state = get_transient( $key );
	if ( ! is_array( $state ) || $state['expires'] < time() || $state['attempts'] >= JLIFE_AUTH_MAX_ATTEMPTS ) {
		delete_transient( $key );
		return $invalid;
	}
	$code = preg_replace( '/\D/', '', (string) $code );
	if ( ! hash_equals( $state['hash'], jlife_auth_hash( 'otp|' . $email . '|' . $code ) ) ) {
		++$state['attempts'];
		set_transient( $key, $state, max( 1, $state['expires'] - time() ) );
		return $invalid;
	}
	delete_transient( $key );
	return array(
		'purpose' => $state['purpose'],
		'payload' => $state['payload'],
	);
}
