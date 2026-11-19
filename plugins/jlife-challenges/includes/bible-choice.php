<?php
/**
 * My Bible (#140): each participant chooses their Bible on My Marathon.
 *
 * This file is only the My Marathon side: the two selects under the language
 * row, the `POST /jlife/v1/bible` route, the no-JavaScript form handler, and
 * the "My language" answer the Scripture plugin asks for. The choice itself
 * (storage, validation against the version registry, resolution order) belongs
 * to jlife-scripture: see jlife_scripture_set_user_choice() and
 * jlife_scripture_versions_for_user(). Every call into that plugin is guarded,
 * so without it the picker is simply absent.
 *
 * - Only the signed-in person's own choice, ever: no user ID is accepted.
 * - A person needs an active enrollment, like the rest of My Marathon.
 * - A preference, not an event: no audit row, no log line.
 *
 * @package jlife-challenges
 */

defined( 'ABSPATH' ) || exit;

/**
 * Nonce action for the My Bible form without JavaScript.
 */
const JLIFE_CHALLENGES_BIBLE_NONCE = 'jlife_bible';

add_filter( 'jlife_scripture_user_language', 'jlife_challenges_scripture_language', 10, 2 );

/**
 * Is the Scripture plugin's choice API here?
 *
 * @return bool
 */
function jlife_challenges_bible_choice_available() {
	return function_exists( 'jlife_scripture_set_user_choice' )
		&& function_exists( 'jlife_scripture_user_choice' )
		&& function_exists( 'jlife_scripture_selectable_version' )
		&& function_exists( 'jlife_scripture_selectable_versions' )
		&& function_exists( 'jlife_scripture_versions_for_user' )
		&& function_exists( 'jlife_scripture_can_render' )
		&& function_exists( 'jlife_scripture_registry' );
}

/**
 * "My language" for the Scripture plugin: for a participant with an active
 * enrollment, the reminder-email language shown on My Marathon (the saved
 * one, else the account's language, else the page's). Anyone else keeps the
 * Scripture plugin's own answer.
 *
 * @param string $lang    "en" or "vi" (the plugin's answer from the account's locale).
 * @param int    $user_id Participant (0 = not signed in).
 * @return string "en" or "vi".
 */
function jlife_challenges_scripture_language( $lang, $user_id ) {
	$user_id = (int) $user_id;
	if ( $user_id < 1 ) {
		return $lang;
	}
	$campaign_id = jlife_challenges_reminder_campaign_id( $user_id );
	if ( $campaign_id < 1 ) {
		return $lang;
	}
	$locale = jlife_challenges_reminder_shown_locale( $user_id, jlife_challenges_reminder_pref( $user_id, $campaign_id ) );
	return 0 === strpos( $locale, 'vi' ) ? 'vi' : 'en';
}

/**
 * Save the signed-in person's own choice. The caller passes their own user ID
 * (never one from a request); a person without an active enrollment is denied.
 *
 * @param int   $user_id Participant.
 * @param array $changes { primary?: string|null, secondary?: string|null } See jlife_scripture_set_user_choice().
 * @return true|WP_Error `jlife_denied`, or `jlife_bible_unknown` for a key the registry does not list as enabled.
 */
function jlife_challenges_save_bible_choice( $user_id, $changes ) {
	$user_id     = (int) $user_id;
	$campaign_id = $user_id > 0 ? jlife_challenges_reminder_campaign_id( $user_id ) : 0;
	if ( ! jlife_challenges_bible_choice_available() || null === jlife_challenges_active_enrollment( $campaign_id, $user_id ) ) {
		return jlife_challenges_denied();
	}
	return jlife_scripture_set_user_choice( $user_id, $changes );
}

/**
 * What the picker shows for a person: the stored first Bible when the registry
 * still offers it ('' = follow My language), and the second Bible that is
 * really shown ('' = none).
 *
 * @param int $user_id Participant.
 * @return array{bible_version: string, bible_version_2: string}
 */
function jlife_challenges_bible_state( $user_id ) {
	if ( ! jlife_challenges_bible_choice_available() ) {
		return array(
			'bible_version'   => '',
			'bible_version_2' => '',
		);
	}
	$stored = jlife_scripture_user_choice( $user_id );
	$pair   = jlife_scripture_versions_for_user( $user_id );
	return array(
		'bible_version'   => null !== jlife_scripture_selectable_version( $stored['primary'] ) ? $stored['primary'] : '',
		'bible_version_2' => null !== $pair[1] ? (string) $pair[1]['key'] : '',
	);
}

/**
 * REST: save the signed-in person's Bible choice at once. "bible_version" is
 * the first Bible (null or "" = follow My language), "bible_version_2" the
 * optional second one (null or "" = none); a field left out keeps its value.
 * Only ever their own choice: no user ID is accepted, and an organizer cannot
 * set it for anyone. WordPress's REST cookie authentication requires the
 * `wp_rest` nonce (without it the request counts as signed out).
 *
 * @param WP_REST_Request $request Request.
 * @return WP_REST_Response|WP_Error
 */
function jlife_challenges_rest_save_bible( WP_REST_Request $request ) {
	$user_id = get_current_user_id();
	$changes = array();
	foreach ( array(
		'bible_version'   => 'primary',
		'bible_version_2' => 'secondary',
	) as $param => $slot ) {
		if ( $request->has_param( $param ) ) {
			$changes[ $slot ] = $request->get_param( $param );
		}
	}
	$r = jlife_challenges_save_bible_choice( $user_id, $changes );
	if ( is_wp_error( $r ) ) {
		$r->add_data( array( 'status' => 'jlife_denied' === $r->get_error_code() ? 403 : 400 ) );
		return $r;
	}
	return new WP_REST_Response( jlife_challenges_bible_state( $user_id ), 200 );
}

/**
 * Handle the My Bible form POST without JavaScript. The choice always applies
 * to the signed-in person, never to a user ID from the form. Both selects are
 * posted; an empty value clears.
 *
 * @param int $user_id Signed-in participant.
 * @return bool True when saved (the caller redirects, so a refresh never re-posts).
 */
function jlife_challenges_handle_bible_post( $user_id ) {
	if ( ! isset( $_POST['jl_bible'] ) || ! check_admin_referer( JLIFE_CHALLENGES_BIBLE_NONCE, '_jlife_bible' ) ) {
		return false;
	}
	$changes = array();
	foreach ( array(
		'jl_bible_version'   => 'primary',
		'jl_bible_version_2' => 'secondary',
	) as $field => $slot ) {
		if ( ! isset( $_POST[ $field ] ) ) {
			continue;
		}
		if ( ! is_string( $_POST[ $field ] ) ) { // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- a type test; the value is sanitized below.
			return false;
		}
		$changes[ $slot ] = sanitize_text_field( wp_unslash( $_POST[ $field ] ) );
	}
	return true === jlife_challenges_save_bible_choice( $user_id, $changes );
}

/**
 * The options of one select: a first "none" choice, then every enabled
 * version grouped by language. A version whose text the page cannot show says
 * "(opens Bible Gateway)".
 *
 * @param array  $groups   jlife_scripture_selectable_versions(): language => key => registry entry.
 * @param string $selected Selected key ('' = the first option).
 * @param string $none     Text of the first option.
 * @return string Escaped HTML.
 */
function jlife_challenges_bible_options( $groups, $selected, $none ) {
	$languages = jlife_scripture_registry()['languages'];
	$out       = '<option value=""' . selected( $selected, '', false ) . '>' . esc_html( $none ) . '</option>';
	foreach ( $groups as $language => $versions ) {
		$out .= '<optgroup label="' . esc_attr( (string) ( $languages[ $language ]['label'] ?? $language ) ) . '">';
		foreach ( $versions as $key => $version ) {
			$text = $version['short_code'] . ' — ' . $version['name'];
			if ( ! jlife_scripture_can_render( $version ) ) {
				/* translators: %s: Bible version, e.g. "ERV — Easy-to-Read Version". Its text opens on Bible Gateway instead of on the page. */
				$text = sprintf( __( '%s (opens Bible Gateway)', 'jlife-challenges' ), $text );
			}
			$out .= '<option value="' . esc_attr( (string) $key ) . '"' . selected( $selected, (string) $key, false ) . '>' . esc_html( $text ) . '</option>';
		}
		$out .= '</optgroup>';
	}
	return $out;
}

/**
 * The My Bible picker, for My Marathon's Daily reading email section (under
 * the language row): "My Bible" lists every enabled version grouped by
 * language; "Also show…" is an optional second Bible. With JavaScript a change
 * saves at once ("Saved." / restore and "Not saved. Please try again.", as for
 * the reminder checkbox); without it the Save button posts the form.
 *
 * @param int  $user_id     Participant.
 * @param int  $campaign_id Campaign.
 * @param bool $saved       Show "Saved." (after a no-JavaScript save).
 * @return string Escaped HTML, or '' without a campaign, a person, or the Scripture plugin.
 */
function jlife_challenges_render_bible_picker( $user_id, $campaign_id, $saved = false ) {
	if ( $user_id < 1 || $campaign_id < 1 || ! jlife_challenges_bible_choice_available() ) {
		return '';
	}
	$groups = jlife_scripture_selectable_versions();
	if ( array() === $groups ) {
		return '';
	}
	$state = jlife_challenges_bible_state( $user_id );
	return '<form method="post" class="jl-bible-picker" data-rest="' . esc_url( rest_url( 'jlife/v1/bible' ) ) . '" data-nonce="' . esc_attr( wp_create_nonce( 'wp_rest' ) ) . '"'
		. ' data-i18n="' . esc_attr(
			(string) wp_json_encode(
				array(
					'saved'  => __( 'Saved.', 'jlife-challenges' ),
					'failed' => __( 'Not saved. Please try again.', 'jlife-challenges' ),
				)
			)
		) . '">'
		. wp_nonce_field( JLIFE_CHALLENGES_BIBLE_NONCE, '_jlife_bible', false, false )
		. '<input type="hidden" name="jl_bible" value="save">'
		. '<p class="jl-field"><label for="jl-bible-version">' . esc_html__( 'My Bible', 'jlife-challenges' ) . '</label> '
		. '<select id="jl-bible-version" name="jl_bible_version">' . jlife_challenges_bible_options( $groups, $state['bible_version'], __( 'Default for my language', 'jlife-challenges' ) ) . '</select></p>'
		. '<p class="jl-field"><label for="jl-bible-version-2">' . esc_html__( 'Also show…', 'jlife-challenges' ) . '</label> '
		. '<select id="jl-bible-version-2" name="jl_bible_version_2">' . jlife_challenges_bible_options( $groups, $state['bible_version_2'], __( 'None', 'jlife-challenges' ) ) . '</select></p>'
		. '<p class="jl-hint">' . esc_html__( 'Your Bible follows the language above until you choose one.', 'jlife-challenges' ) . '</p>'
		. '<noscript><button type="submit" class="jl-button jl-small">' . esc_html__( 'Save', 'jlife-challenges' ) . '</button></noscript>'
		. '<p class="jl-reminder-status jl-bible-status" role="status" aria-live="polite">' . ( $saved ? esc_html__( 'Saved.', 'jlife-challenges' ) : '' ) . '</p></form>';
}
