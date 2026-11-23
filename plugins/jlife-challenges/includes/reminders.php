<?php
/**
 * Daily reading reminders, part 1 (#63 Phase B): who has asked for them, in
 * which language, and the one-click unsubscribe. Nothing here sends mail; the
 * queue and scheduler come next and use jlife_challenges_reminder_recipients()
 * and jlife_challenges_unsubscribe_url().
 *
 * - A person is a recipient only after choosing it (unticked checkbox at
 *   enrollment, or the "Email me each morning's reading." checkbox on Today and
 *   My Marathon, #135). No row means no reminders.
 * - Recipients require recorded consent (enrollments.consented), not just a ticked box.
 * - The row holds no email address; sending looks it up from the account.
 * - Unsubscribing changes only this table. It never touches sign-in codes or any
 *   jlife-auth state (the codes are a different kind of message).
 *
 * @package jlife-challenges
 */

defined( 'ABSPATH' ) || exit;

/**
 * Languages a reminder can be written in. The same two codes jlife-auth uses
 * for the account language; since #159 a reminder row's language is a copy of
 * the account's, set at enrollment and by the Language setting on My Marathon
 * (language.php).
 */
const JLIFE_CHALLENGES_REMINDER_LOCALES = array(
	'en_US' => 'English',
	'vi'    => 'Tiếng Việt',
);

/**
 * Nonce action for the reminder checkbox form without JavaScript (Today and My Marathon).
 */
const JLIFE_CHALLENGES_REMINDER_NONCE = 'jlife_reminder';

/**
 * A valid reminder language, or the fallback.
 *
 * @param string $locale   Candidate (en_US or vi; "en" is accepted as en_US).
 * @param string $fallback Used when the candidate is not supported.
 * @return string
 */
function jlife_challenges_reminder_locale( $locale, $fallback = 'en_US' ) {
	$locale = 'en' === $locale ? 'en_US' : (string) $locale;
	return array_key_exists( $locale, JLIFE_CHALLENGES_REMINDER_LOCALES ) ? $locale : $fallback;
}

/**
 * A person's reminder choice in a campaign.
 *
 * @param int $user_id     Participant.
 * @param int $campaign_id Campaign.
 * @return array|null Row (opted_in, opted_out, consent_version, locale, updated), or null if they never chose.
 */
function jlife_challenges_reminder_pref( $user_id, $campaign_id ) {
	global $wpdb;
	if ( (int) $user_id < 1 || (int) $campaign_id < 1 ) {
		return null;
	}
	$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . jlife_challenges_table( 'reminder_prefs' ) . ' WHERE campaign_id = %d AND user_id = %d', (int) $campaign_id, (int) $user_id ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery -- custom table; name from $wpdb->prefix.
	return $row ? $row : null;
}

/**
 * Is the person currently asking for reminders?
 *
 * @param array|null $pref Row from jlife_challenges_reminder_pref().
 * @return bool
 */
function jlife_challenges_reminder_is_on( $pref ) {
	return is_array( $pref ) && ! empty( $pref['opted_in'] ) && empty( $pref['opted_out'] );
}

/**
 * Write a person's choice (insert or update their one row).
 *
 * @param int    $user_id     Participant.
 * @param int    $campaign_id Campaign.
 * @param bool   $on          True = opted in now; false = opted out now.
 * @param string $locale      Valid reminder language.
 * @param string $consent     Consent wording version recorded with an opt-in.
 */
function jlife_challenges_write_reminder_pref( $user_id, $campaign_id, $on, $locale, $consent = '' ) {
	global $wpdb;
	$table = jlife_challenges_table( 'reminder_prefs' );
	$now   = current_time( 'mysql', true );
	$old   = jlife_challenges_reminder_pref( $user_id, $campaign_id );
	$data  = array(
		'locale'  => $locale,
		'updated' => $now,
	);
	if ( $on ) {
		$data['opted_in']        = $now;
		$data['opted_out']       = null;
		$data['consent_version'] = (string) $consent;
	} else {
		$data['opted_out'] = $now;
	}
	// phpcs:disable WordPress.DB.DirectDatabaseQuery -- custom table; no WP API exists.
	if ( $old ) {
		$wpdb->update( $table, $data, array( 'id' => (int) $old['id'] ) );
	} else {
		$wpdb->insert(
			$table,
			$data + array(
				'campaign_id' => (int) $campaign_id,
				'user_id'     => (int) $user_id,
			)
		);
	}
	// phpcs:enable
}

/**
 * Turn a person's daily reminders on or off. The person themself, or an
 * organizer for them, may do it; the person needs an active enrollment.
 *
 * @param int    $actor       Acting user.
 * @param int    $user_id     Participant whose choice it is.
 * @param int    $campaign_id Campaign.
 * @param bool   $on          True to opt in, false to opt out.
 * @param string $locale      en_US or vi; '' keeps the earlier choice, else the account's language.
 * @return true|WP_Error
 */
function jlife_challenges_set_reminder( $actor, $user_id, $campaign_id, $on, $locale = '' ) {
	$actor       = (int) $actor;
	$user_id     = (int) $user_id;
	$campaign_id = (int) $campaign_id;
	if ( $actor < 1 || ( $actor !== $user_id && ! jlife_challenges_is_organizer( $actor ) ) ) {
		return jlife_challenges_denied();
	}
	if ( null === jlife_challenges_active_enrollment( $campaign_id, $user_id ) ) {
		return jlife_challenges_denied();
	}
	if ( $on && ! jlife_challenges_campaign_accepts_writes( $campaign_id ) ) {
		// A closed campaign sends nothing: opting out still works, opting in does not (#179).
		return jlife_challenges_campaign_closed_error();
	}
	$old      = jlife_challenges_reminder_pref( $user_id, $campaign_id );
	$fallback = jlife_challenges_reminder_locale( $old ? (string) $old['locale'] : (string) get_user_meta( $user_id, 'locale', true ) );
	$locale   = jlife_challenges_reminder_locale( (string) $locale, $fallback );
	$consent  = defined( 'JLIFE_AUTH_CONSENT_VERSION' ) ? JLIFE_AUTH_CONSENT_VERSION : '';
	jlife_challenges_write_reminder_pref( $user_id, $campaign_id, (bool) $on, $locale, $consent );
	jlife_challenges_audit( $actor, $on ? 'reminder_opt_in' : 'reminder_opt_out', 'reminder', $user_id, $campaign_id, array( 'locale' => $locale ) );
	return true;
}

/**
 * Users who should get the daily reminder: opted in, not opted out, with an
 * active enrollment, and with the consent wording accepted (the join form's box
 * is written before the /consent step, so a ticked box alone is not enough). The sending queue (next PR) starts from this list.
 *
 * @param int $campaign_id Campaign.
 * @return int[] User IDs.
 */
function jlife_challenges_reminder_recipients( $campaign_id ) {
	global $wpdb;
	$prefs = jlife_challenges_table( 'reminder_prefs' );
	$enrol = jlife_challenges_table( 'enrollments' );
	// phpcs:disable WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- custom tables; names from $wpdb->prefix.
	$ids = $wpdb->get_col(
		$wpdb->prepare(
			"SELECT p.user_id FROM {$prefs} p JOIN {$enrol} e ON e.campaign_id = p.campaign_id AND e.user_id = p.user_id
			WHERE p.campaign_id = %d AND p.opted_in IS NOT NULL AND p.opted_out IS NULL AND e.status = 'active' AND e.consented IS NOT NULL ORDER BY p.user_id",
			(int) $campaign_id
		)
	);
	// phpcs:enable
	return array_map( 'intval', $ids );
}

/**
 * The one-click unsubscribe token for a person and campaign. A keyed hash, so a
 * link cannot be made or changed without the site's secret, and it names no
 * email address.
 *
 * @param int $user_id     Participant.
 * @param int $campaign_id Campaign.
 * @return string
 */
function jlife_challenges_unsubscribe_token( $user_id, $campaign_id ) {
	$value = 'unsub|' . (int) $campaign_id . '|' . (int) $user_id;
	return function_exists( 'jlife_auth_hash' ) ? jlife_auth_hash( $value ) : hash_hmac( 'sha256', $value, wp_salt( 'auth' ) );
}

/**
 * The public unsubscribe link for a reminder's footer and its List-Unsubscribe header.
 *
 * @param int $user_id     Participant.
 * @param int $campaign_id Campaign.
 * @return string
 */
function jlife_challenges_unsubscribe_url( $user_id, $campaign_id ) {
	return add_query_arg(
		array(
			'c' => (int) $campaign_id,
			'u' => (int) $user_id,
			't' => jlife_challenges_unsubscribe_token( $user_id, $campaign_id ),
		),
		home_url( '/unsubscribe/' )
	);
}

/**
 * Is the token the right one for this person and campaign?
 *
 * @param int    $user_id     Participant.
 * @param int    $campaign_id Campaign.
 * @param string $token       Token from the link.
 * @return bool
 */
function jlife_challenges_unsubscribe_token_valid( $user_id, $campaign_id, $token ) {
	return (int) $user_id > 0 && (int) $campaign_id > 0 && '' !== $token
		&& hash_equals( jlife_challenges_unsubscribe_token( $user_id, $campaign_id ), $token );
}

/**
 * Stop a person's reminders because they used their unsubscribe link. Changes
 * only the reminder row (nothing about sign-in), and only if they were opted in.
 *
 * @param int $user_id     Participant.
 * @param int $campaign_id Campaign.
 */
function jlife_challenges_unsubscribe( $user_id, $campaign_id ) {
	$pref = jlife_challenges_reminder_pref( $user_id, $campaign_id );
	if ( ! jlife_challenges_reminder_is_on( $pref ) ) {
		return;
	}
	jlife_challenges_write_reminder_pref( $user_id, $campaign_id, false, jlife_challenges_reminder_locale( (string) $pref['locale'] ) );
	jlife_challenges_audit( 0, 'reminder_opt_out', 'reminder', (int) $user_id, (int) $campaign_id, array( 'via' => 'link' ) );
}

/**
 * What an /unsubscribe request means. The link's parameters come from the URL
 * (also for a POST, so a mail client's RFC 8058 one-click POST to the link
 * works). A GET only ever asks for confirmation. A bad or missing token is
 * "invalid" and changes nothing.
 *
 * @param string $method 'GET' or 'POST'.
 * @param array  $query  Query parameters (c, u, t).
 * @param array  $post   Posted fields (List-Unsubscribe, jl_unsubscribe).
 * @return array { state: invalid|confirm|done, user_id: int, campaign_id: int, locale: string }
 */
function jlife_challenges_unsubscribe_request( $method, $query, $post ) {
	$c = isset( $query['c'] ) ? absint( $query['c'] ) : 0;
	$u = isset( $query['u'] ) ? absint( $query['u'] ) : 0;
	$t = isset( $query['t'] ) && is_string( $query['t'] ) ? $query['t'] : '';
	$r = array(
		'state'       => 'invalid',
		'user_id'     => 0,
		'campaign_id' => 0,
		'locale'      => '',
	);
	if ( ! jlife_challenges_unsubscribe_token_valid( $u, $c, $t ) ) {
		return $r;
	}
	$pref      = jlife_challenges_reminder_pref( $u, $c );
	$r         = array(
		'state'       => 'confirm',
		'user_id'     => $u,
		'campaign_id' => $c,
		'locale'      => $pref ? jlife_challenges_reminder_locale( (string) $pref['locale'], '' ) : '',
	);
	$one_click = isset( $post['List-Unsubscribe'] ) && 'One-Click' === $post['List-Unsubscribe'];
	if ( 'POST' === strtoupper( (string) $method ) && ( $one_click || ! empty( $post['jl_unsubscribe'] ) ) ) {
		jlife_challenges_unsubscribe( $u, $c );
		$r['state'] = 'done';
	}
	return $r;
}

/**
 * The /unsubscribe page body for a request state.
 *
 * @param array $req   jlife_challenges_unsubscribe_request() result.
 * @param array $query The link's parameters (re-sent by the confirm form).
 * @return string Escaped HTML.
 */
function jlife_challenges_unsubscribe_body( $req, $query ) {
	$marathon = '<p><a class="jl-button" href="' . esc_url( home_url( '/marathon/' ) ) . '">' . esc_html__( 'Go to My Marathon', 'jlife-challenges' ) . '</a></p></section>';
	if ( 'confirm' === $req['state'] ) {
		$action = add_query_arg(
			array(
				'c' => (int) $req['campaign_id'],
				'u' => (int) $req['user_id'],
				't' => (string) $query['t'],
			),
			home_url( '/unsubscribe/' )
		);
		return '<section class="jl-confirm"><h1>' . esc_html__( 'Stop the daily reading emails?', 'jlife-challenges' ) . '</h1>'
			. '<p>' . esc_html__( 'You will no longer get the morning reading by email. This does not affect your sign-in codes or your account.', 'jlife-challenges' ) . '</p>'
			. '<form method="post" action="' . esc_url( $action ) . '"><input type="hidden" name="jl_unsubscribe" value="1">'
			. '<button type="submit" class="jl-button jl-primary">' . esc_html__( 'Stop the emails', 'jlife-challenges' ) . '</button></form></section>';
	}
	if ( 'done' === $req['state'] ) {
		return '<section class="jl-confirm"><h1>' . esc_html__( 'You are unsubscribed', 'jlife-challenges' ) . '</h1>'
			. '<p>' . esc_html__( 'We will not email you the daily reading any more. You can turn the emails back on any time from My Marathon.', 'jlife-challenges' ) . '</p>' . $marathon;
	}
	return '<section class="jl-confirm"><h1>' . esc_html__( 'This link did not work', 'jlife-challenges' ) . '</h1>'
		. '<p>' . esc_html__( 'It may be incomplete or out of date. To stop the daily reading emails, sign in and use the switch on My Marathon.', 'jlife-challenges' ) . '</p>' . $marathon;
}

/**
 * /unsubscribe: public (no sign-in). A GET only shows a confirm button, so a
 * mail scanner that opens the link changes nothing. A valid link's page is
 * written in the language the person chose for their reminders.
 */
function jlife_challenges_screen_unsubscribe() {
	// phpcs:disable WordPress.Security.NonceVerification -- no session here: the signed token in the link is the authority, and a GET changes nothing.
	$method = isset( $_SERVER['REQUEST_METHOD'] ) ? strtoupper( sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) ) : 'GET';
	$query  = array(
		'c' => isset( $_GET['c'] ) ? sanitize_text_field( wp_unslash( $_GET['c'] ) ) : '',
		'u' => isset( $_GET['u'] ) ? sanitize_text_field( wp_unslash( $_GET['u'] ) ) : '',
		't' => isset( $_GET['t'] ) ? sanitize_text_field( wp_unslash( $_GET['t'] ) ) : '',
	);
	$post   = array(
		'List-Unsubscribe' => isset( $_POST['List-Unsubscribe'] ) ? sanitize_text_field( wp_unslash( $_POST['List-Unsubscribe'] ) ) : '',
		'jl_unsubscribe'   => isset( $_POST['jl_unsubscribe'] ) ? '1' : '',
	);
	// phpcs:enable
	$req   = jlife_challenges_unsubscribe_request( $method, $query, $post );
	$build = static function () use ( $req, $query ) {
		return array( __( 'Daily reading emails', 'jlife-challenges' ), jlife_challenges_unsubscribe_body( $req, $query ) );
	};
	$page  = function_exists( 'jlife_auth_in_locale' ) && '' !== $req['locale'] ? jlife_auth_in_locale( $req['locale'], $build ) : $build();
	nocache_headers();
	header( 'Content-Type: text/html; charset=utf-8' );
	header( 'X-Robots-Tag: noindex, nofollow' );
	header( 'Referrer-Policy: no-referrer' ); // The link carries a token: never pass it on.
	$base = plugins_url( 'assets/', dirname( __DIR__ ) . '/jlife-challenges.php' );
	echo '<!doctype html><html lang="' . esc_attr( str_replace( '_', '-', '' !== $req['locale'] ? $req['locale'] : determine_locale() ) ) . '"><head><meta charset="utf-8">' // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- every part is escaped.
		. '<meta name="viewport" content="width=device-width, initial-scale=1"><meta name="robots" content="noindex, nofollow"><meta name="referrer" content="no-referrer">'
		. '<title>' . esc_html( $page[0] ) . '</title>'
		. '<link rel="stylesheet" href="' . esc_url( $base . 'screens.css?v=26' ) . '">' // phpcs:ignore WordPress.WP.EnqueuedResources.NonEnqueuedStylesheet -- standalone page without wp_head.
		. '</head><body><main>' . $page[1] . '</main></body></html>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built from escaped parts.
	exit;
}

/**
 * The language a choice is saved in when the request names none: '' (keep the
 * saved one) once the person has a row, else the language the checkbox screens
 * show for them (their account's, then the page's).
 *
 * @param int    $user_id     Participant.
 * @param int    $campaign_id Campaign.
 * @param string $posted      Language from the request, or ''.
 * @return string
 */
function jlife_challenges_reminder_request_locale( $user_id, $campaign_id, $posted ) {
	if ( '' !== $posted || null !== jlife_challenges_reminder_pref( $user_id, $campaign_id ) ) {
		return $posted;
	}
	return jlife_challenges_reminder_shown_locale( $user_id, null );
}

/**
 * The reminder language the screens show: the saved one, else the account's,
 * else the page's.
 *
 * @param int        $user_id Participant.
 * @param array|null $pref    Row from jlife_challenges_reminder_pref().
 * @return string
 */
function jlife_challenges_reminder_shown_locale( $user_id, $pref ) {
	return jlife_challenges_reminder_locale( $pref ? (string) $pref['locale'] : (string) get_user_meta( $user_id, 'locale', true ), jlife_challenges_reminder_locale( determine_locale() ) );
}

/**
 * Is the participant reminder opt-in offered (#189)? Only while the site's saved
 * sending mode (J-Life → Reminders, jlife_challenges_reminders_mode()) is Live.
 * In Off, Dry run and Test every opt-in prompt is hidden and no new opt-in is
 * accepted; existing choices are kept as they are, and opting out (the
 * unsubscribe link, or an "off" from a page opened earlier) always works.
 *
 * @return bool
 */
function jlife_challenges_reminder_optin_offered() {
	return function_exists( 'jlife_challenges_reminders_mode' ) && 'live' === jlife_challenges_reminders_mode();
}

/**
 * Can a reminder choice be made for this campaign now (#181)? The opt-in is
 * offered (saved Live, #189) and the campaign is running or upcoming. An
 * upcoming campaign (for example the official Marathon during Dec 14–19
 * onboarding) takes the choice ahead of Day 1; nothing is sent before Day 1.
 * An ended or closed campaign (the pilot after Dec 13) offers none.
 *
 * @param int $campaign_id Campaign.
 * @return bool
 */
function jlife_challenges_reminder_choice_open( $campaign_id ) {
	$campaign = (int) $campaign_id > 0 ? jlife_challenges_get_campaign( (int) $campaign_id ) : null;
	return $campaign && jlife_challenges_reminder_optin_offered() && jlife_challenges_campaign_is_open( $campaign );
}

/**
 * The checkbox's wording. Before Day 1 of a campaign that is not the pilot,
 * the start date is named (#181): "Email me each morning's Marathon reading
 * starting December 20, 2026?". Otherwise the everyday wording.
 *
 * @param int $campaign_id Campaign.
 * @return string Translated, unescaped.
 */
function jlife_challenges_reminder_label( $campaign_id ) {
	$campaign = jlife_challenges_get_campaign( (int) $campaign_id );
	if ( $campaign && 'upcoming' === jlife_challenges_campaign_phase( $campaign ) && ! jlife_challenges_is_pilot_campaign( $campaign ) ) {
		/* translators: %s: the Marathon's first day, e.g. "December 20, 2026". */
		return sprintf( __( 'Email me each morning\'s Marathon reading starting %s?', 'jlife-challenges' ), jlife_challenges_format_date( (string) $campaign['start_date'] ) );
	}
	return __( 'Email me each morning\'s reading.', 'jlife-challenges' );
}

/**
 * Handle a reminder form POST (the checkbox without JavaScript, on Today or My
 * Marathon). The choice always applies to the signed-in person, never to a user
 * ID from the form. The form sends a hidden "off" before the checkbox's "on",
 * so an unticked box posts "off". A post without the field (another form, such
 * as Language or My Bible) changes nothing. An "on" while the opt-in is not
 * offered (#189), or for a campaign that has ended or closed (#181), changes
 * nothing either; an "off" is always honored.
 *
 * @param int $user_id     Signed-in participant.
 * @param int $campaign_id Their campaign.
 * @return string '' (no valid post), 'on' or 'off' (saved: the caller redirects, so a refresh never re-posts).
 */
function jlife_challenges_handle_reminder_post( $user_id, $campaign_id ) {
	if ( ! isset( $_POST['jl_reminder'] ) || ! check_admin_referer( JLIFE_CHALLENGES_REMINDER_NONCE, '_jlife_reminder' ) ) {
		return '';
	}
	$choice = sanitize_key( wp_unslash( $_POST['jl_reminder'] ) );
	if ( ! in_array( $choice, array( 'on', 'off' ), true ) ) {
		return '';
	}
	if ( 'on' === $choice && ! jlife_challenges_reminder_choice_open( $campaign_id ) ) {
		return '';
	}
	// The language is not this form's: the Language setting owns it (#159).
	$locale = jlife_challenges_reminder_request_locale( $user_id, $campaign_id, '' );
	$r      = jlife_challenges_set_reminder( $user_id, $user_id, $campaign_id, 'on' === $choice, $locale );
	return is_wp_error( $r ) ? '' : $choice;
}

/**
 * REST: save the signed-in person's reminder checkbox at once (#135). Only ever
 * their own choice: no user ID is accepted. "on" left out changes nothing and
 * just answers the saved state (#189: no rewrite, so no new consent version is
 * recorded). "on": true while the opt-in is not offered (#189) is refused with
 * `jlife_reminders_not_offered` (403); "on": false always works. A "locale" in the body is ignored: since #159 the email language is
 * the account's, changed only by the Language setting (language.php). Goes
 * through jlife_challenges_set_reminder(), so the enrollment check, consent
 * version and audit are the same as every other path.
 *
 * @param WP_REST_Request $request Request (on: bool).
 * @return WP_REST_Response|WP_Error
 */
function jlife_challenges_rest_save_reminder( WP_REST_Request $request ) {
	$user_id     = get_current_user_id();
	$campaign_id = jlife_challenges_reminder_campaign_id( $user_id );
	if ( null === jlife_challenges_active_enrollment( $campaign_id, $user_id ) ) {
		$r = jlife_challenges_denied();
	} elseif ( null === $request['on'] ) {
		$r = true;
	} elseif ( $request['on'] && ! jlife_challenges_reminder_choice_open( $campaign_id ) ) {
		$r = new WP_Error( 'jlife_reminders_not_offered', 'Daily reading emails are not offered right now.' );
	} else {
		$locale = jlife_challenges_reminder_request_locale( $user_id, $campaign_id, '' );
		$r      = jlife_challenges_set_reminder( $user_id, $user_id, $campaign_id, (bool) $request['on'], $locale );
	}
	if ( is_wp_error( $r ) ) {
		$r->add_data( array( 'status' => 403 ) );
		return $r;
	}
	$pref = jlife_challenges_reminder_pref( $user_id, $campaign_id );
	return new WP_REST_Response(
		array(
			'on'     => jlife_challenges_reminder_is_on( $pref ),
			'locale' => jlife_challenges_reminder_shown_locale( $user_id, $pref ),
		),
		200
	);
}

/**
 * The campaign a signed-in person's reminder choice belongs to: their current
 * campaign (docs/challenges-engine-design.md §3b).
 *
 * @param int $user_id Participant.
 * @return int Campaign ID, or 0.
 */
function jlife_challenges_reminder_campaign_id( $user_id ) {
	$pc = jlife_challenges_participant_campaign( $user_id );
	return $pc ? (int) $pc['campaign']['id'] : 0;
}

/**
 * The daily reading email checkbox (#135), the same on Today (under Save draft)
 * and My Marathon. Ticked = the saved choice is on. With
 * JavaScript a change saves at once through the REST route and says "Saved."
 * or, on failure, puts the box back and asks to try again; without it, the Save
 * button posts the form. Shown only while the opt-in is offered (saved mode
 * Live, #189) and the campaign is running or upcoming (#181); hiding it changes
 * nothing that is saved. The choice is always this campaign's own row: no other
 * campaign's choice is read, copied or pre-ticked.
 *
 * @param int  $user_id     Participant.
 * @param int  $campaign_id Campaign.
 * @param bool $marathon    On My Marathon (its own element ID).
 * @param bool $saved       Show "Saved." (after a no-JavaScript save).
 * @return string Escaped HTML, or '' without a campaign, outside Live mode, or for an ended or closed campaign.
 */
function jlife_challenges_render_reminder_toggle( $user_id, $campaign_id, $marathon = false, $saved = false ) {
	if ( $user_id < 1 || $campaign_id < 1 || ! jlife_challenges_reminder_choice_open( $campaign_id ) ) {
		return '';
	}
	$pref = jlife_challenges_reminder_pref( $user_id, $campaign_id );
	$on   = jlife_challenges_reminder_is_on( $pref );
	$id   = $marathon ? 'jl-reminder-on-marathon' : 'jl-reminder-on';
	$out  = '<form method="post" class="jl-reminder-toggle" data-rest="' . esc_url( rest_url( 'jlife/v1/reminder' ) ) . '" data-nonce="' . esc_attr( wp_create_nonce( 'wp_rest' ) ) . '"'
		. ' data-i18n="' . esc_attr(
			(string) wp_json_encode(
				array(
					'saved'  => __( 'Saved.', 'jlife-challenges' ),
					'failed' => __( 'Not saved. Please try again.', 'jlife-challenges' ),
				)
			)
		) . '">'
		. wp_nonce_field( JLIFE_CHALLENGES_REMINDER_NONCE, '_jlife_reminder', false, false )
		. '<input type="hidden" name="jl_reminder" value="off">'
		. '<label class="jl-check" for="' . esc_attr( $id ) . '"><input type="checkbox" id="' . esc_attr( $id ) . '" name="jl_reminder" value="on"' . checked( $on, true, false ) . '> '
		. esc_html( jlife_challenges_reminder_label( $campaign_id ) ) . '</label>';
	return $out . '<noscript><button type="submit" class="jl-button jl-small">' . esc_html__( 'Save', 'jlife-challenges' ) . '</button></noscript>'
		. '<p class="jl-reminder-status" role="status" aria-live="polite">' . ( $saved ? esc_html__( 'Saved.', 'jlife-challenges' ) : '' ) . '</p></form>';
}

/**
 * My Marathon's settings: the Language section (#159; language.php), then the
 * "Daily reading email" section with the checkbox and, under it, the My Bible
 * picker (#140; bible-choice.php). Outside Live mode (#189) the checkbox is
 * hidden and the section is headed "Bible", holding only the picker; with no
 * picker either, the section is left out.
 *
 * @param int  $user_id        Participant.
 * @param int  $campaign_id    Campaign.
 * @param bool $saved          Show "Saved." (after a no-JavaScript save).
 * @param bool $bible_saved    Show "Saved." under the My Bible picker (after its no-JavaScript save).
 * @param bool $language_saved Show "Saved." under the Language setting (after its save and reload).
 * @return string Escaped HTML.
 */
function jlife_challenges_render_reminder_settings( $user_id, $campaign_id, $saved = false, $bible_saved = false, $language_saved = false ) {
	$toggle  = jlife_challenges_render_reminder_toggle( $user_id, $campaign_id, true, $saved );
	$picker  = jlife_challenges_render_bible_picker( $user_id, $campaign_id, $bible_saved );
	$heading = '' !== $toggle ? __( 'Daily reading email', 'jlife-challenges' ) : __( 'Bible', 'jlife-challenges' );
	$section = '' !== ( $toggle . $picker ) ? '<section class="jl-reminders"><h2>' . esc_html( $heading ) . '</h2>' . $toggle . $picker . '</section>' : '';
	$inner   = jlife_challenges_render_language_setting( $user_id, $campaign_id, $language_saved ) . $section;
	if ( '' === $inner ) {
		return '';
	}
	$open = $saved || $bible_saved || $language_saved;
	return '<details class="jl-settings"' . ( $open ? ' open' : '' ) . '><summary>'
		. esc_html( jlife_challenges_settings_summary( $user_id, $campaign_id, '' !== $toggle ) )
		. '</summary>' . $inner . '</details>';
}

/**
 * The closed settings row's text (#237), from the saved state only: "Settings:
 * English · NLT", with " + code" for a second Bible and " · Email: On/Off" only
 * while the reminder choice is offered (saved mode Live, #189).
 *
 * @param int  $user_id     Participant.
 * @param int  $campaign_id Campaign.
 * @param bool $show_email  Whether the reminder checkbox is shown.
 * @return string Plain text (escape on output).
 */
function jlife_challenges_settings_summary( $user_id, $campaign_id, $show_email ) {
	$locale = jlife_challenges_account_language( $user_id );
	$parts  = array( JLIFE_CHALLENGES_REMINDER_LOCALES[ $locale ] ?? $locale );
	if ( jlife_challenges_bible_choice_available() ) {
		$pair  = jlife_scripture_versions_for_user( $user_id );
		$codes = array();
		foreach ( $pair as $version ) {
			if ( is_array( $version ) && '' !== (string) ( $version['short_code'] ?? '' ) ) {
				$codes[] = (string) $version['short_code'];
			}
		}
		if ( array() !== $codes ) {
			$parts[] = implode( ' + ', $codes );
		}
	}
	if ( $show_email ) {
		$on      = jlife_challenges_reminder_is_on( jlife_challenges_reminder_pref( $user_id, $campaign_id ) );
		$parts[] = $on ? __( 'Email: On', 'jlife-challenges' ) : __( 'Email: Off', 'jlife-challenges' );
	}
	/* translators: %s: current choices, e.g. "English · NLT". */
	return sprintf( __( 'Settings: %s', 'jlife-challenges' ), implode( ' · ', $parts ) );
}
