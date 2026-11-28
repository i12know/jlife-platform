<?php
/**
 * One Language setting on My Marathon (#159): English or Tiếng Việt, for the
 * participant screens, the daily reading email and the default Bible together.
 *
 * - The one source of truth is the account's `locale` user meta (what
 *   jlife_auth_front_locale() reads for the screens). Saving the setting writes
 *   it and copies the same code onto every reminder_prefs row of the person, so
 *   the reminder sender (which reads reminder_prefs.locale) needs no change.
 * - The default Bible follows the account's `locale` (jlife-scripture's own
 *   answer); an explicit My Bible choice is separate user meta and is never
 *   touched here.
 * - Only the signed-in person's own setting, ever: no user ID is accepted, an
 *   organizer cannot change it for anyone, and the person needs an active
 *   enrollment (the same gate as the rest of My Marathon).
 * - Audited as `language_change` (detail: the old and new language code only).
 * - Signed-out pages are untouched: they keep the site language or `?lang=`.
 *
 * Existing accounts whose saved email language differs from their screen
 * language keep that email language until they next save this setting; the
 * screen language is what the setting shows (owner confirmed 2026-11-20, #159).
 *
 * @package jlife-challenges
 */

defined( 'ABSPATH' ) || exit;

/**
 * Nonce action for the Language form without JavaScript.
 */
const JLIFE_CHALLENGES_LANGUAGE_NONCE = 'jlife_language';

/**
 * The language the setting shows for a person: their account's `locale`, else
 * the page's.
 *
 * @param int $user_id Participant.
 * @return string en_US or vi.
 */
function jlife_challenges_account_language( $user_id ) {
	$page = jlife_challenges_reminder_locale( determine_locale() );
	return jlife_challenges_reminder_locale( (string) get_user_meta( (int) $user_id, 'locale', true ), $page );
}

/**
 * Change a person's language: the account's `locale` (screens) and the
 * language of every reminder row they have (emails). The default Bible follows
 * the account's `locale` on its own.
 *
 * @param int    $actor   Acting user; must be the person themself.
 * @param int    $user_id Participant whose language it is.
 * @param string $locale  en_US or vi ("en" is accepted as en_US).
 * @return true|WP_Error `jlife_denied`, or `jlife_language_unknown` for any other code.
 */
function jlife_challenges_set_language( $actor, $user_id, $locale ) {
	$actor   = (int) $actor;
	$user_id = (int) $user_id;
	if ( $actor < 1 || $actor !== $user_id ) {
		return jlife_challenges_denied();
	}
	$campaign_id = jlife_challenges_reminder_campaign_id( $user_id );
	if ( null === jlife_challenges_active_enrollment( $campaign_id, $user_id ) ) {
		return jlife_challenges_denied();
	}
	$locale = jlife_challenges_reminder_locale( (string) $locale, '' );
	if ( '' === $locale ) {
		return new WP_Error( 'jlife_language_unknown', 'Unknown language.' );
	}
	$before = jlife_challenges_reminder_locale( (string) get_user_meta( $user_id, 'locale', true ), '' );
	update_user_meta( $user_id, 'locale', $locale );
	jlife_challenges_sync_reminder_language( $user_id, $locale );
	jlife_challenges_audit(
		$actor,
		'language_change',
		'account',
		$user_id,
		$campaign_id,
		array(
			'from'   => $before,
			'locale' => $locale,
		)
	);
	return true;
}

/**
 * Copy the account's language onto all of a person's reminder rows. Only the
 * language and the row's timestamp change: on/off and consent stay as they are.
 *
 * @param int    $user_id Participant.
 * @param string $locale  Valid reminder language.
 */
function jlife_challenges_sync_reminder_language( $user_id, $locale ) {
	global $wpdb;
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- custom table; no WP API exists.
	$wpdb->update(
		jlife_challenges_table( 'reminder_prefs' ),
		array(
			'locale'  => (string) $locale,
			'updated' => current_time( 'mysql', true ),
		),
		array( 'user_id' => (int) $user_id )
	);
}

/**
 * REST: save the signed-in person's language. Only ever their own: no user ID
 * is accepted. WordPress's REST cookie authentication requires the `wp_rest`
 * nonce (without it the request counts as signed out).
 *
 * @param WP_REST_Request $request Request (locale: string).
 * @return WP_REST_Response|WP_Error
 */
function jlife_challenges_rest_save_language( WP_REST_Request $request ) {
	$user_id = get_current_user_id();
	$r       = jlife_challenges_set_language( $user_id, $user_id, is_string( $request['locale'] ) ? $request['locale'] : '' );
	if ( is_wp_error( $r ) ) {
		$r->add_data( array( 'status' => 'jlife_denied' === $r->get_error_code() ? 403 : 400 ) );
		return $r;
	}
	return new WP_REST_Response( array( 'locale' => jlife_challenges_account_language( $user_id ) ), 200 );
}

/**
 * Handle the Language form POST without JavaScript. The choice always applies
 * to the signed-in person, never to a user ID from the form.
 *
 * @param int $user_id Signed-in participant.
 * @return bool True when saved (the caller redirects, so the next page is in the new language).
 */
function jlife_challenges_handle_language_post( $user_id ) {
	if ( ! isset( $_POST['jl_language'] ) ) {
		return false;
	}
	check_admin_referer( JLIFE_CHALLENGES_LANGUAGE_NONCE, '_jlife_language' );
	if ( ! is_string( $_POST['jl_language'] ) ) { // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- a type test; the value is sanitized below.
		return false;
	}
	$locale = sanitize_text_field( wp_unslash( $_POST['jl_language'] ) );
	return true === jlife_challenges_set_language( $user_id, $user_id, $locale );
}

/**
 * My Marathon's Language section: one select (English / Tiếng Việt, each in
 * its own language). With JavaScript a change saves at once and the page
 * reloads in the new language; on failure the select goes back and asks to try
 * again. Without it the Save button posts the form.
 *
 * @param int  $user_id     Participant.
 * @param int  $campaign_id Campaign.
 * @param bool $saved       Show "Saved." (after a save and reload).
 * @return string Escaped HTML, or '' without a person or a campaign.
 */
function jlife_challenges_render_language_setting( $user_id, $campaign_id, $saved = false ) {
	if ( $user_id < 1 || $campaign_id < 1 ) {
		return '';
	}
	$current = jlife_challenges_account_language( $user_id );
	$opts    = '';
	foreach ( JLIFE_CHALLENGES_REMINDER_LOCALES as $code => $label ) {
		$lang  = str_replace( '_', '-', $code );
		$opts .= '<option value="' . esc_attr( $code ) . '" lang="' . esc_attr( $lang ) . '"' . selected( $current, $code, false ) . '>' . esc_html( $label ) . '</option>';
	}
	return '<section class="jl-reminders jl-language"><h2>' . esc_html__( 'Language', 'jlife-challenges' ) . '</h2>'
		. '<form method="post" class="jl-language-form" data-rest="' . esc_url( rest_url( 'jlife/v1/language' ) ) . '" data-nonce="' . esc_attr( wp_create_nonce( 'wp_rest' ) ) . '"'
		. ' data-done="' . esc_url( add_query_arg( 'language', 'saved', jlife_challenges_screen_url( 'marathon' ) ) ) . '"'
		. ' data-i18n="' . esc_attr(
			(string) wp_json_encode(
				array(
					'saving' => __( 'Saving…', 'jlife-challenges' ),
					'failed' => __( 'Not saved. Please try again.', 'jlife-challenges' ),
				)
			)
		) . '">'
		. wp_nonce_field( JLIFE_CHALLENGES_LANGUAGE_NONCE, '_jlife_language', false, false )
		. '<p class="jl-field"><label for="jl-language">' . esc_html__( 'Language for screens and emails', 'jlife-challenges' ) . '</label> '
		. '<select id="jl-language" name="jl_language">' . $opts . '</select></p>'
		. '<p class="jl-hint">' . esc_html__( 'The page reloads in the language you choose. Your Bible follows it too, unless you chose one below.', 'jlife-challenges' ) . '</p>'
		. '<noscript><button type="submit" class="jl-button jl-small">' . esc_html__( 'Save', 'jlife-challenges' ) . '</button></noscript>'
		. '<p class="jl-reminder-status jl-language-status" role="status" aria-live="polite">' . ( $saved ? esc_html__( 'Saved.', 'jlife-challenges' ) : '' ) . '</p></form>'
		. '</section>';
}
