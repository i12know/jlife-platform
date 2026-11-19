<?php
/**
 * Daily reading reminders, part 1 (#63 Phase B): who has asked for them, in
 * which language, and the one-click unsubscribe. Nothing here sends mail; the
 * queue and scheduler come next and use jlife_challenges_reminder_recipients()
 * and jlife_challenges_unsubscribe_url().
 *
 * - A person is a recipient only after choosing it (unticked checkbox at
 *   enrollment, the one-time prompt on Today, or My Marathon). No row means no
 *   reminders.
 * - Recipients require recorded consent (enrollments.consented), not just a ticked box.
 * - The row holds no email address; sending looks it up from the account.
 * - Unsubscribing changes only this table. It never touches sign-in codes or any
 *   jlife-auth state (the codes are a different kind of message).
 *
 * @package jlife-challenges
 */

defined( 'ABSPATH' ) || exit;

/**
 * Languages a reminder can be written in (chosen at enrollment, changeable on
 * My Marathon). The same two codes jlife-auth uses for the account language.
 */
const JLIFE_CHALLENGES_REMINDER_LOCALES = array(
	'en_US' => 'English',
	'vi'    => 'Tiếng Việt',
);

/**
 * Nonce action for the reminder forms (Today's prompt and My Marathon).
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
		. '<link rel="stylesheet" href="' . esc_url( $base . 'screens.css?v=9' ) . '">' // phpcs:ignore WordPress.WP.EnqueuedResources.NonEnqueuedStylesheet -- standalone page without wp_head.
		. '</head><body><main>' . $page[1] . '</main></body></html>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built from escaped parts.
	exit;
}

/**
 * Handle a reminder form POST (Today's prompt or My Marathon). The choice always
 * applies to the signed-in person, never to a user ID from the form.
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
	$locale = isset( $_POST['jl_reminder_locale'] ) ? sanitize_text_field( wp_unslash( $_POST['jl_reminder_locale'] ) ) : '';
	$r      = jlife_challenges_set_reminder( $user_id, $user_id, $campaign_id, 'on' === $choice, $locale );
	return is_wp_error( $r ) ? '' : $choice;
}

/**
 * The campaign a signed-in person's reminder choice belongs to: their most
 * recent campaign with an active enrollment.
 *
 * @param int $user_id Participant.
 * @return int Campaign ID, or 0.
 */
function jlife_challenges_reminder_campaign_id( $user_id ) {
	$pc = jlife_challenges_participant_campaign( $user_id );
	return $pc ? (int) $pc['campaign']['id'] : 0;
}

/**
 * Today's one-time prompt: shown to an enrolled person with no choice yet. Either
 * answer writes a row, so it never shows again.
 *
 * @param int $user_id     Participant.
 * @param int $campaign_id Campaign.
 * @return string Escaped HTML, or '' when they already chose.
 */
function jlife_challenges_render_reminder_prompt( $user_id, $campaign_id ) {
	if ( $user_id < 1 || $campaign_id < 1 || null !== jlife_challenges_reminder_pref( $user_id, $campaign_id ) ) {
		return '';
	}
	$locale = jlife_challenges_reminder_locale( (string) get_user_meta( $user_id, 'locale', true ), jlife_challenges_reminder_locale( determine_locale() ) );
	return '<form method="post" class="jl-note jl-reminder-prompt">' . wp_nonce_field( JLIFE_CHALLENGES_REMINDER_NONCE, '_jlife_reminder', true, false )
		. '<input type="hidden" name="jl_reminder_locale" value="' . esc_attr( $locale ) . '">'
		. '<p><strong>' . esc_html__( 'Get each morning\'s reading by email?', 'jlife-challenges' ) . '</strong></p>'
		. '<p>' . esc_html__( 'One short email a day with the reading. You can turn it off at any time.', 'jlife-challenges' ) . '</p>'
		. '<button type="submit" name="jl_reminder" value="on" class="jl-button jl-primary">' . esc_html__( 'Yes', 'jlife-challenges' ) . '</button> '
		. '<button type="submit" name="jl_reminder" value="off" class="jl-button">' . esc_html__( 'No thanks', 'jlife-challenges' ) . '</button></form>';
}

/**
 * The My Marathon switch: on/off and language, defaulting to the account's language.
 *
 * @param int  $user_id     Participant.
 * @param int  $campaign_id Campaign.
 * @param bool $saved       Show "Saved." after a change.
 * @return string Escaped HTML.
 */
function jlife_challenges_render_reminder_settings( $user_id, $campaign_id, $saved = false ) {
	$pref   = jlife_challenges_reminder_pref( $user_id, $campaign_id );
	$on     = jlife_challenges_reminder_is_on( $pref );
	$locale = jlife_challenges_reminder_locale( $pref ? (string) $pref['locale'] : (string) get_user_meta( $user_id, 'locale', true ), jlife_challenges_reminder_locale( determine_locale() ) );
	$opts   = '';
	foreach ( JLIFE_CHALLENGES_REMINDER_LOCALES as $code => $label ) {
		$opts .= '<option value="' . esc_attr( $code ) . '"' . selected( $locale, $code, false ) . '>' . esc_html( $label ) . '</option>';
	}
	return '<section class="jl-reminders"><h2>' . esc_html__( 'Daily reading email', 'jlife-challenges' ) . '</h2>'
		. ( $saved ? '<p class="jl-ok" role="status">' . esc_html__( 'Saved.', 'jlife-challenges' ) . '</p>' : '' )
		. '<p>' . esc_html( $on ? __( 'You get each morning\'s reading by email.', 'jlife-challenges' ) : __( 'You are not getting the reading by email.', 'jlife-challenges' ) ) . '</p>'
		. '<form method="post">' . wp_nonce_field( JLIFE_CHALLENGES_REMINDER_NONCE, '_jlife_reminder', true, false )
		. '<label for="jl-reminder-locale">' . esc_html__( 'Language of the email', 'jlife-challenges' ) . '</label>'
		. '<select id="jl-reminder-locale" name="jl_reminder_locale">' . $opts . '</select> '
		. '<button type="submit" name="jl_reminder" value="' . ( $on ? 'off' : 'on' ) . '" class="jl-button' . ( $on ? '' : ' jl-primary' ) . '">'
		. esc_html( $on ? __( 'Turn off', 'jlife-challenges' ) : __( 'Turn on', 'jlife-challenges' ) ) . '</button>'
		. ( $on ? ' <button type="submit" name="jl_reminder" value="on" class="jl-button">' . esc_html__( 'Save language', 'jlife-challenges' ) . '</button>' : '' )
		. '</form></section>';
}
