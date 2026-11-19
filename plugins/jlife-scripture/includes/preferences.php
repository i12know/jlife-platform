<?php
/**
 * A participant's Bible choice (#140). Two optional values on the person's own
 * account (user meta, like `locale`): `jlife_bible_version` (a registry key;
 * absent = follow My language) and `jlife_bible_version_2` (an optional second
 * Bible; absent = none). Only registry keys are stored: no text, no address.
 *
 * Resolution order for what a person reads: their explicit choice (when the
 * registry still lists it as enabled), then the registry's default for their
 * language ("My language"), then the site default. Defaults come from the
 * registry only; no version key is written in this file. The My Marathon
 * picker and its REST route live in jlife-challenges, which calls the
 * functions here; this plugin owns the storage and the rules.
 *
 * Callers pass the viewer's own user ID only: nothing here is a way to read
 * someone else's choice, and no function acts for another person.
 *
 * @package jlife-scripture
 */

defined( 'ABSPATH' ) || exit;

const JLIFE_SCRIPTURE_META_PRIMARY   = 'jlife_bible_version';
const JLIFE_SCRIPTURE_META_SECONDARY = 'jlife_bible_version_2';

/**
 * A version a person may choose: it exists in the registry and is enabled.
 *
 * @param mixed $key Version key.
 * @return array|null The registry entry, or null.
 */
function jlife_scripture_selectable_version( $key ) {
	if ( ! is_string( $key ) || '' === $key ) {
		return null;
	}
	$version = jlife_scripture_get_version( $key );
	return ( null !== $version && true === $version['enabled'] ) ? $version : null;
}

/**
 * Every version a person may choose, grouped by language in the registry's
 * language order, and in registry order inside a language. Versions that
 * cannot show text (link-only, or not yet licensed) are included: the page
 * opens Bible Gateway for them (see jlife_scripture_can_render()).
 *
 * @return array<string, array<string, array>> Language => key => registry entry. Empty languages are left out.
 */
function jlife_scripture_selectable_versions() {
	$registry = jlife_scripture_registry();
	$groups   = array();
	foreach ( array_keys( $registry['languages'] ) as $language ) {
		$groups[ $language ] = array();
	}
	foreach ( $registry['versions'] as $key => $version ) {
		if ( true === $version['enabled'] && isset( $groups[ $version['language'] ] ) ) {
			$groups[ $version['language'] ][ $key ] = $version;
		}
	}
	return array_filter( $groups );
}

/**
 * What a person has stored, as keys ('' = nothing stored).
 *
 * @param int $user_id Participant (their own ID).
 * @return array{primary: string, secondary: string}
 */
function jlife_scripture_user_choice( $user_id ) {
	$user_id = (int) $user_id;
	$out     = array(
		'primary'   => '',
		'secondary' => '',
	);
	if ( $user_id > 0 ) {
		$first            = get_user_meta( $user_id, JLIFE_SCRIPTURE_META_PRIMARY, true );
		$second           = get_user_meta( $user_id, JLIFE_SCRIPTURE_META_SECONDARY, true );
		$out['primary']   = is_string( $first ) ? $first : '';
		$out['secondary'] = is_string( $second ) ? $second : '';
	}
	return $out;
}

/**
 * The site default Bible: the default for the site's own language, else
 * English. The last resort when a language has no default of its own.
 *
 * @return array|null
 */
function jlife_scripture_site_default_version() {
	$language = strtolower( substr( (string) get_locale(), 0, 2 ) );
	return jlife_scripture_default_version( $language ) ?? jlife_scripture_default_version( 'en' );
}

/**
 * Apply the resolution order to a stored choice.
 *
 * @param array  $choice   Stored keys: { primary: string, secondary: string }, '' = nothing stored.
 * @param string $language My language ("en" or "vi").
 * @return array{0: array|null, 1: array|null} First and second Bible.
 */
function jlife_scripture_resolve_choice( $choice, $language ) {
	$first  = jlife_scripture_selectable_version( $choice['primary'] )
		?? jlife_scripture_default_version( $language )
		?? jlife_scripture_site_default_version();
	$second = jlife_scripture_selectable_version( $choice['secondary'] );
	if ( null !== $second && null !== $first && $second['key'] === $first['key'] ) {
		$second = null; // The same Bible twice shows once.
	}
	return array( $first, $second );
}

/**
 * The Bible(s) a person reads in: [ first, second|null ] as registry entries.
 *
 * Order: their explicit choice when it is still enabled, else the language
 * default ("My language", see jlife_scripture_language_for_user()), else the
 * site default. A default may be a version that cannot show text yet (NIrV
 * before it is licensed); the reader then shows its fallback link. With an
 * explicit `$language` (the public /reading/ pages, `?lang=`), no stored choice
 * is read: the language's default alone.
 *
 * @param int    $user_id  Participant (0 = not signed in). Pass the viewer's own ID.
 * @param string $language Optional "en" or "vi" to force the language and ignore stored choices.
 * @return array{0: array|null, 1: array|null}
 */
function jlife_scripture_versions_for_user( $user_id, $language = '' ) {
	$user_id = (int) $user_id;
	$choice  = array(
		'primary'   => '',
		'secondary' => '',
	);
	if ( '' === $language ) {
		$language = jlife_scripture_language_for_user( $user_id );
		$choice   = jlife_scripture_user_choice( $user_id );
	}
	return jlife_scripture_resolve_choice( $choice, $language );
}

/**
 * Save a person's own choice. Every key is checked against the registry first
 * and a refusal changes nothing.
 *
 * @param int   $user_id Participant (the signed-in person; never taken from a request).
 * @param array $changes { primary?: string|null, secondary?: string|null } A slot left out keeps its value; '' or null clears it.
 * @return true|WP_Error `jlife_bible_unknown` for a key the registry does not list as enabled.
 */
function jlife_scripture_set_user_choice( $user_id, $changes ) {
	$user_id = (int) $user_id;
	if ( $user_id < 1 || false === get_userdata( $user_id ) ) {
		return new WP_Error( 'jlife_bible_no_user', 'No such account.' );
	}
	$choice = jlife_scripture_user_choice( $user_id );
	foreach ( array( 'primary', 'secondary' ) as $slot ) {
		if ( ! array_key_exists( $slot, $changes ) ) {
			continue;
		}
		$value = $changes[ $slot ];
		if ( null === $value || '' === $value ) {
			$choice[ $slot ] = '';
		} elseif ( null !== jlife_scripture_selectable_version( $value ) ) {
			$choice[ $slot ] = $value;
		} else {
			return new WP_Error( 'jlife_bible_unknown', 'That Bible version is not available.' );
		}
	}
	// A second Bible equal to the first (including the language default) is dropped, not stored.
	$resolved = jlife_scripture_resolve_choice( $choice, jlife_scripture_language_for_user( $user_id ) );
	if ( null !== $resolved[0] && $choice['secondary'] === $resolved[0]['key'] ) {
		$choice['secondary'] = '';
	}
	foreach ( array(
		'primary'   => JLIFE_SCRIPTURE_META_PRIMARY,
		'secondary' => JLIFE_SCRIPTURE_META_SECONDARY,
	) as $slot => $meta ) {
		if ( '' === $choice[ $slot ] ) {
			delete_user_meta( $user_id, $meta );
		} else {
			update_user_meta( $user_id, $meta, $choice[ $slot ] );
		}
	}
	return true;
}
