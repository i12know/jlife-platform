<?php
/**
 * Accounts: joining with an invite code or on one's own, finishing sign-in,
 * consent (#58, #112).
 *
 * - Three ways in: an invite code (a group; also how an existing participant
 *   joins another group later, up to the limit), "Read on my own" (no code,
 *   no group; `mode=solo`) and "Start a group" (`mode=start`, adults only: a
 *   group name, then the invite code and link are shown once). All record the
 *   age band and need the same consent.
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
const JLIFE_AUTH_CONSENT_VERSION = 'draft-2026-11-18';

/**
 * Which way in the join page is on: 'solo' ("Read on my own", no code),
 * 'start' ("Start a group", adults only) or the default 'invite'. The route is
 * /join/?mode=solo or ?mode=start; the form carries it on.
 *
 * @param string $raw Requested mode.
 * @return string invite|solo|start|wait
 */
function jlife_auth_join_mode( $raw ) {
	return in_array( $raw, array( 'solo', 'start', 'wait' ), true ) ? $raw : 'invite';
}

/**
 * Is "I'll start Dec 20" offered right now (#178)? Only while the pilot is what
 * new people join: the join rule's pick is the pilot campaign. Before it and
 * after Dec 14 everyone joins the one campaign that is open, so there is
 * nothing to wait out.
 *
 * @return bool
 */
function jlife_auth_wait_offered() {
	if ( ! function_exists( 'jlife_challenges_open_campaign' ) || ! function_exists( 'jlife_challenges_is_pilot_campaign' ) ) {
		return false;
	}
	$campaign = jlife_challenges_open_campaign();
	return $campaign && jlife_challenges_is_pilot_campaign( $campaign );
}

/**
 * Errors after the emailed code is checked that must not stop sign-in: the
 * person is signed in and told, on /join, what did not happen.
 */
const JLIFE_AUTH_SOFT_JOIN_ERRORS = array(
	'jlife_already_enrolled',
	'jlife_group_limit',
	'jlife_minor_cannot_start',
	'jlife_start_limit',
	'jlife_start_rate',
	'jlife_group_name_invalid',
	'jlife_denied',
);

/**
 * Validate join details and send a join code.
 *
 * @param array  $input { email, first_name, invite_code, group_name, age_band (adult|minor), locale, mode (invite|solo|start|wait), reminders (truthy = the unticked-by-default box was ticked) }.
 * @param string $ip    Requester IP.
 * @return true|WP_Error
 */
function jlife_auth_start_join( $input, $ip = '' ) {
	$first = sanitize_text_field( (string) ( $input['first_name'] ?? '' ) );
	$band  = (string) ( $input['age_band'] ?? '' );
	$loc   = (string) ( $input['locale'] ?? 'en_US' );
	$code  = strtoupper( preg_replace( '/[^A-Za-z0-9]/', '', (string) ( $input['invite_code'] ?? '' ) ) );
	$mode  = jlife_auth_join_mode( (string) ( $input['mode'] ?? '' ) );
	if ( '' === $first ) {
		return new WP_Error( 'jlife_auth_name', __( 'Please enter your first name.', 'jlife-auth' ) );
	}
	if ( ! in_array( $band, array( 'adult', 'minor' ), true ) ) {
		return new WP_Error( 'jlife_auth_age', __( 'Please choose your age group. J-Life is for ages 13 and up.', 'jlife-auth' ) );
	}
	if ( ! array_key_exists( $loc, JLIFE_AUTH_LOCALES ) ) {
		$loc = 'en_US';
	}
	$campaign_id = 0;
	$group_name  = '';
	if ( 'solo' === $mode || 'start' === $mode ) {
		// Read on my own / start a group: no code. A code typed anyway is ignored.
		$campaign    = function_exists( 'jlife_challenges_open_campaign' ) ? jlife_challenges_open_campaign() : null;
		$campaign_id = $campaign ? (int) $campaign['id'] : 0;
		$code        = '';
		if ( ! $campaign_id ) {
			return new WP_Error( 'jlife_auth_no_campaign', __( 'There is no Marathon open to join right now. Please try again later.', 'jlife-auth' ) );
		}
	}
	if ( 'wait' === $mode ) {
		// "I'll start Dec 20": an account and a flag, no campaign, no group, no reminder choice. A code typed anyway is ignored.
		$code = '';
		if ( ! jlife_auth_wait_offered() ) {
			return new WP_Error( 'jlife_auth_wait_closed', __( 'That choice is no longer offered. You can join the Marathon now instead.', 'jlife-auth' ) );
		}
	} elseif ( 'start' === $mode ) {
		// Adults only. This is checked again, on the stored age band, when the group is made.
		if ( 'adult' !== $band ) {
			return new WP_Error( 'jlife_auth_minor_cannot_start', __( 'Only adults (18 or older) can start a group. You can join a group with an invite code, or read on your own.', 'jlife-auth' ) );
		}
		$group_name = jlife_challenges_clean_group_name( (string) ( $input['group_name'] ?? '' ) );
		if ( is_wp_error( $group_name ) ) {
			return $group_name;
		}
	} elseif ( 'solo' !== $mode && ( ! function_exists( 'jlife_challenges_joinable_group_for_invite' ) || ! jlife_challenges_joinable_group_for_invite( $code ) ) ) {
		return new WP_Error( 'jlife_invite_invalid', __( 'That invite code is not valid. Please ask your group for a new one.', 'jlife-auth' ) );
	}
	return jlife_auth_request_code(
		(string) ( $input['email'] ?? '' ),
		'join',
		array(
			'first_name'  => mb_substr( $first, 0, 60 ),
			'invite_code' => $code,
			'group_name'  => $group_name,
			'mode'        => $mode,
			'campaign_id' => $campaign_id,
			'age_band'    => $band,
			'locale'      => $loc,
			// The box is only offered in saved Live mode (#189); a crafted post outside it opts in nobody.
			'reminders'   => ( 'wait' !== $mode && jlife_auth_reminder_optin_offered() && ! empty( $input['reminders'] ) ) ? 1 : 0,
		),
		$ip
	);
}

/**
 * Is the join form's "Email me each morning's reading" box offered? Only while
 * the site's saved reminder mode is Live (#189; jlife-challenges decides).
 *
 * @return bool
 */
function jlife_auth_reminder_optin_offered() {
	return function_exists( 'jlife_challenges_reminder_optin_offered' ) && jlife_challenges_reminder_optin_offered();
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
 * @return array|WP_Error { user_id, purpose, notice, notice_code, started?, waiting? } `waiting` is true after "I'll start Dec 20"; `started` is { id, invite_code, name } when a group was just started; `notice` explains a join that did not happen (the person is still signed in).
 */
function jlife_auth_complete( $email, $code ) {
	$checked = jlife_auth_check_code( $email, $code );
	if ( is_wp_error( $checked ) ) {
		return $checked;
	}
	$email = jlife_auth_normalize_email( $email );
	$user  = get_user_by( 'email', $email );
	if ( $user && ! jlife_auth_email_code_allowed( $user->ID ) ) {
		// Checked again here: an account that became staff after the code was sent cannot use it (the code is already spent).
		return new WP_Error( 'jlife_auth_code', __( 'That code is not valid. Please check it or ask for a new one.', 'jlife-auth' ) );
	}

	if ( 'signin' === $checked['purpose'] ) {
		return $user ? array(
			'user_id'     => (int) $user->ID,
			'purpose'     => 'signin',
			'notice'      => '',
			'notice_code' => '',
		) : new WP_Error( 'jlife_auth_code', __( 'That code is not valid. Please check it or ask for a new one.', 'jlife-auth' ) );
	}

	$payload = (array) $checked['payload'];
	$user_id = $user ? (int) $user->ID : jlife_auth_create_participant( $email, $payload );
	if ( is_wp_error( $user_id ) ) {
		return $user_id;
	}
	// Messages from the join work are written in the person's own language (D13): their account's, else the one chosen on the form.
	$chosen = $user ? (string) get_user_meta( $user_id, 'locale', true ) : (string) ( $payload['locale'] ?? '' );
	$done   = jlife_auth_in_locale(
		$chosen,
		static function () use ( $user_id, $payload ) {
			return jlife_auth_do_join( $user_id, $payload );
		}
	);
	if ( is_wp_error( $done['error'] ) ) {
		return $done['error'];
	}
	$result = array(
		'user_id'     => $user_id,
		'purpose'     => 'join',
		'notice'      => $done['notice'],
		'notice_code' => $done['notice_code'],
	);
	if ( $done['started'] ) {
		$result['started'] = $done['started'];
	}
	if ( ! empty( $done['waiting'] ) ) {
		$result['waiting'] = true;
	}
	return $result;
}

/**
 * The join itself, after the emailed code is checked: a group by invite code,
 * "Read on my own", or "Start a group". Errors that must not stop sign-in come
 * back as a notice.
 *
 * @param int   $user_id Participant.
 * @param array $payload Join details from the code request.
 * @return array { error: WP_Error|null, notice: string, notice_code: string, started: array|null, waiting?: bool }
 */
function jlife_auth_do_join( $user_id, $payload ) {
	$out  = array(
		'error'       => null,
		'notice'      => '',
		'notice_code' => '',
		'started'     => null,
	);
	$mode = jlife_auth_join_mode( (string) ( $payload['mode'] ?? '' ) );
	if ( 'wait' === $mode ) {
		// The person chose to start on Dec 20 (#178). Only that choice is kept here,
		// with no enrollment, consent or reminder; the rollover (#180) enrolls them later.
		if ( function_exists( 'jlife_challenges_set_waiting_for_official' ) ) {
			jlife_challenges_set_waiting_for_official( $user_id );
			$out['waiting'] = true;
		}
		return $out;
	}
	if ( 'solo' === $mode ) {
		$joined = jlife_challenges_enroll_solo( $user_id, (int) ( $payload['campaign_id'] ?? 0 ), $payload['age_band'] );
	} elseif ( 'start' === $mode ) {
		// The enrollment keeps its stored age band: a person already enrolled as 13-17 stays a minor here.
		$campaign = jlife_challenges_open_campaign();
		$joined   = $campaign ? jlife_challenges_enroll_solo( $user_id, (int) $campaign['id'], $payload['age_band'] ) : jlife_challenges_denied();
		if ( ! is_wp_error( $joined ) ) {
			$joined = jlife_challenges_start_group( $user_id, (string) ( $payload['group_name'] ?? '' ) );
			if ( ! is_wp_error( $joined ) ) {
				$out['started'] = array(
					'id'          => (int) $joined['id'],
					'invite_code' => (string) $joined['invite_code'],
					'name'        => (string) $joined['name'],
				);
			}
		}
	} else {
		$joined = jlife_challenges_join_with_code( $user_id, $payload['invite_code'], $payload['age_band'] );
	}
	if ( is_wp_error( $joined ) ) {
		// Already in that group, at the group limit, or could not start a group: they are signed in; tell them.
		if ( ! in_array( $joined->get_error_code(), JLIFE_AUTH_SOFT_JOIN_ERRORS, true ) ) {
			$out['error'] = $joined;
			return $out;
		}
		$out['notice']      = $joined->get_error_message();
		$out['notice_code'] = $joined->get_error_code();
	}
	// The unticked "email me each morning's reading" box: only a ticked box opts in, in the language chosen on the form.
	if ( ! empty( $payload['reminders'] ) && function_exists( 'jlife_challenges_reminder_campaign_id' ) ) {
		$campaign_id = jlife_challenges_reminder_campaign_id( $user_id );
		if ( $campaign_id > 0 ) {
			jlife_challenges_set_reminder( $user_id, $user_id, $campaign_id, true, (string) ( $payload['locale'] ?? '' ) );
		}
	}
	return $out;
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
