<?php
/**
 * The Bible version allowlist (#139): data/bible-versions.json is the single
 * source of truth. A version may render text on the page only when it is
 * enabled, its display is "native", it records license evidence, and its
 * source has a registered implementation. Everything else is a Bible Gateway
 * link. Changing a language's default is a data edit (default_for).
 *
 * @package jlife-scripture
 */

defined( 'ABSPATH' ) || exit;

const JLIFE_SCRIPTURE_SOURCES  = array( 'local', 'youversion', 'apibible', 'link' );
const JLIFE_SCRIPTURE_DISPLAYS = array( 'native', 'link' );

/**
 * The shipped registry file, decoded once per request.
 *
 * @return array
 */
function jlife_scripture_registry_file() {
	static $doc = null;
	if ( null === $doc ) {
		$raw = file_get_contents( dirname( JLIFE_SCRIPTURE_FILE ) . '/data/bible-versions.json' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- the plugin's own data file.
		$doc = is_string( $raw ) ? json_decode( $raw, true ) : null;
		$doc = is_array( $doc ) ? $doc : array();
	}
	return $doc;
}

/**
 * Problems with one version entry (empty when valid).
 *
 * @param mixed $v Version entry.
 * @return string[]
 */
function jlife_scripture_version_problems( $v ) {
	if ( ! is_array( $v ) ) {
		return array( 'not an object' );
	}
	$p = array();
	if ( ! preg_match( '/^[a-z0-9-]{1,40}$/', (string) ( $v['key'] ?? '' ) ) ) {
		$p[] = 'key';
	}
	foreach ( array( 'language', 'name', 'short_code', 'source_id' ) as $field ) {
		if ( ! is_string( $v[ $field ] ?? null ) || '' === trim( $v[ $field ] ) ) {
			$p[] = $field;
		}
	}
	if ( ! in_array( $v['source'] ?? null, JLIFE_SCRIPTURE_SOURCES, true ) ) {
		$p[] = 'source';
	}
	if ( ! in_array( $v['display'] ?? null, JLIFE_SCRIPTURE_DISPLAYS, true ) ) {
		$p[] = 'display';
	}
	if ( 'link' === ( $v['source'] ?? '' ) && 'link' !== ( $v['display'] ?? '' ) ) {
		$p[] = 'a link source can only display as a link';
	}
	if ( ! is_string( $v['copyright']['text'] ?? null ) || '' === trim( $v['copyright']['text'] ) ) {
		$p[] = 'copyright.text';
	}
	$age = $v['cache']['max_age_seconds'] ?? null;
	if ( ! is_int( $age ) || $age < 0 ) {
		$p[] = 'cache.max_age_seconds';
	}
	$max = $v['cache']['max_consecutive_verses'] ?? null;
	if ( null !== $max && ( ! is_int( $max ) || $max < 1 ) ) {
		$p[] = 'cache.max_consecutive_verses';
	}
	if ( ! is_bool( $v['enabled'] ?? null ) ) {
		$p[] = 'enabled';
	}
	if ( ! is_array( $v['default_for'] ?? null ) ) {
		$p[] = 'default_for';
	}
	if ( true === ( $v['enabled'] ?? false ) && 'native' === ( $v['display'] ?? '' )
		&& ( ! is_string( $v['license_evidence'] ?? null ) || '' === trim( $v['license_evidence'] ) ) ) {
		$p[] = 'an enabled native version needs license_evidence';
	}
	return $p;
}

/**
 * The validated registry: languages, and versions keyed by `key`. Invalid
 * versions are left out (so they can never render). Filterable for tests and
 * for a site that needs a different allowlist without a code change.
 *
 * @return array{languages: array<string, array>, versions: array<string, array>}
 */
function jlife_scripture_registry() {
	$doc = apply_filters( 'jlife_scripture_registry', jlife_scripture_registry_file() );
	$doc = is_array( $doc ) ? $doc : array();
	$out = array(
		'languages' => array(),
		'versions'  => array(),
	);
	foreach ( (array) ( $doc['versions'] ?? array() ) as $v ) {
		if ( array() === jlife_scripture_version_problems( $v ) && ! isset( $out['versions'][ $v['key'] ] ) ) {
			$out['versions'][ $v['key'] ] = $v;
		}
	}
	foreach ( (array) ( $doc['languages'] ?? array() ) as $lang => $cfg ) {
		if ( is_string( $lang ) && is_array( $cfg ) ) {
			$out['languages'][ $lang ] = $cfg;
		}
	}
	return $out;
}

/**
 * One version by key.
 *
 * @param string $key Version key, e.g. "kt1925".
 * @return array|null
 */
function jlife_scripture_get_version( $key ) {
	return jlife_scripture_registry()['versions'][ (string) $key ] ?? null;
}

/**
 * The language's default version: the first one whose default_for lists it
 * (it may not be renderable yet; the reader then uses the fallback link).
 *
 * @param string $lang Language code, "en" or "vi".
 * @return array|null
 */
function jlife_scripture_default_version( $lang ) {
	foreach ( jlife_scripture_registry()['versions'] as $v ) {
		if ( $v['language'] === $lang && in_array( $lang, $v['default_for'], true ) ) {
			return $v;
		}
	}
	return null;
}

/**
 * The language's fallback link version (today's Bible Gateway link: ERV, BPT).
 *
 * @param string $lang Language code.
 * @return array|null An enabled link version in that language, or null.
 */
function jlife_scripture_fallback_version( $lang ) {
	$registry = jlife_scripture_registry();
	$key      = (string) ( $registry['languages'][ $lang ]['fallback'] ?? '' );
	$v        = $registry['versions'][ $key ] ?? null;
	if ( ! $v || 'link' !== $v['source'] || $v['language'] !== $lang || ! $v['enabled'] ) {
		return null;
	}
	return $v;
}

/**
 * May J-Life show this version's text on the page? Enabled, native, with
 * license evidence, and a registered source that is not link-only.
 *
 * @param mixed $version Version entry.
 * @return bool
 */
function jlife_scripture_can_render( $version ) {
	return is_array( $version )
		&& array() === jlife_scripture_version_problems( $version )
		&& true === $version['enabled']
		&& 'native' === $version['display']
		&& 'link' !== $version['source']
		&& null !== jlife_scripture_get_source( (string) $version['source'] );
}

/**
 * The reader's language for a participant: their `locale` user meta (the
 * same rule as jlife_challenges_language_order()), or for a visitor who is
 * not signed in, the page's locale. Only "vi" and "en" are offered.
 *
 * Reads one language preference; no other participant data.
 *
 * @param int $user_id Participant (0 = not signed in).
 * @return string "vi" or "en".
 */
function jlife_scripture_language_for_user( $user_id ) {
	$locale = $user_id > 0 ? (string) get_user_meta( (int) $user_id, 'locale', true ) : determine_locale();
	return 0 === strpos( $locale, 'vi' ) ? 'vi' : 'en';
}
