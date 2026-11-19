<?php
/**
 * The on-page Scripture reader (#139). Given a plan day and a participant,
 * shows each of the day's references in the participant's Bible: the first
 * open, the others collapsed (<details>), with verse numbers and the version
 * name and copyright line under the text. A version that may not render text
 * (link-only, not yet licensed), or any failure, shows an "Open in Bible
 * Gateway" link instead, so reading and SOAP are never blocked.
 *
 * Off by default: jlife_scripture_maybe_render_day() returns null until the
 * site option jlife_scripture_native_reader is "1", and the screens keep
 * their current reader.
 *
 * @package jlife-scripture
 */

defined( 'ABSPATH' ) || exit;

/**
 * Is the on-page reader turned on for this site?
 *
 * @return bool
 */
function jlife_scripture_reader_enabled() {
	return '1' === (string) get_option( 'jlife_scripture_native_reader', '0' );
}

/**
 * The reader for a day when the feature flag is on; null when it is off (the
 * caller keeps its current reader).
 *
 * @param mixed $day     Plan day (reading-plan JSON day: passages, i18n, links).
 * @param int   $user_id Participant (0 = not signed in: the page's language).
 * @param array $args    See jlife_scripture_render_day().
 * @return string|null
 */
function jlife_scripture_maybe_render_day( $day, $user_id = 0, $args = array() ) {
	if ( ! jlife_scripture_reader_enabled() || ! is_array( $day ) || empty( $day['passages'] ) ) {
		return null;
	}
	return jlife_scripture_render_day( $day, (int) $user_id, $args );
}

/**
 * The version a language reads in, and its link fallback.
 *
 * @param string $lang Language code.
 * @return array{version: array|null, native: bool, fallback: array|null}
 */
function jlife_scripture_reader_choice( $lang ) {
	$version = jlife_scripture_default_version( $lang );
	return array(
		'version'  => $version,
		'native'   => null !== $version && jlife_scripture_can_render( $version ),
		'fallback' => jlife_scripture_fallback_version( $lang ),
	);
}

/**
 * Render a day's passages.
 *
 * @param mixed $day     Plan day: passages[] { display }, optional i18n.<lang>.passages[] labels.
 * @param int   $user_id Participant whose `locale` picks the language (0 = page language).
 * @param array $args    { language?: "en"|"vi" (overrides the participant's) }.
 * @return string Markup (empty when the day has no passages).
 */
function jlife_scripture_render_day( $day, $user_id = 0, $args = array() ) {
	$passages = is_array( $day ) ? (array) ( $day['passages'] ?? array() ) : array();
	if ( array() === $passages ) {
		return '';
	}
	$lang   = in_array( $args['language'] ?? '', array( 'en', 'vi' ), true ) ? $args['language'] : jlife_scripture_language_for_user( (int) $user_id );
	$choice = jlife_scripture_reader_choice( $lang );
	$labels = (array) ( $day['i18n'][ $lang ]['passages'] ?? array() );
	$out    = '';
	foreach ( array_values( $passages ) as $i => $passage ) {
		$display  = (string) ( is_array( $passage ) ? ( $passage['display'] ?? '' ) : $passage );
		$label    = is_string( $labels[ $i ] ?? null ) && '' !== $labels[ $i ] ? $labels[ $i ] : $display;
		$segments = jlife_scripture_parse_reference( $display );
		$body     = '';
		$shown    = false;
		if ( ! is_wp_error( $segments ) && $choice['native'] ) {
			$result = jlife_scripture_get_passage( $choice['version'], $segments );
			$shown  = ! is_wp_error( $result );
			$body   = $shown
				? jlife_scripture_render_verses( $result ) . jlife_scripture_render_attribution( $choice['version'] )
				: '<p class="jls-note">' . esc_html__( 'The Bible text could not be loaded.', 'jlife-scripture' ) . '</p>';
		}
		if ( ! $shown ) {
			$body .= jlife_scripture_render_link( is_wp_error( $segments ) ? null : $segments, $choice['fallback'], $day, $lang );
		}
		$out .= '<details class="jls-passage"' . ( 0 === $i ? ' open' : '' ) . '>'
			. '<summary>' . esc_html( $label ) . '</summary>'
			. '<div class="jls-body">' . $body . '</div>'
			. '</details>';
	}
	jlife_scripture_enqueue_style();
	return '<section class="jls-reader" lang="' . esc_attr( $lang ) . '" aria-label="' . esc_attr__( 'Scripture reading', 'jlife-scripture' ) . '">' . $out . '</section>';
}

/**
 * Verses with their numbers; a new segment starts a new paragraph, and a
 * verse in a new chapter is numbered "chapter:verse". A verse that two
 * segments share ("Luke 24:10b, d") is shown once.
 *
 * @param array $result One list of verses per segment.
 * @return string
 */
function jlife_scripture_render_verses( $result ) {
	$html    = '';
	$seen    = array();
	$chapter = null;
	foreach ( $result as $verses ) {
		$para = '';
		foreach ( $verses as $v ) {
			$id = $v['chapter'] . ':' . $v['verse'];
			if ( isset( $seen[ $id ] ) ) {
				continue;
			}
			$seen[ $id ] = true;
			$number      = ( null !== $chapter && $v['chapter'] !== $chapter ) ? $id : (string) $v['verse'];
			$chapter     = $v['chapter'];
			$para       .= '<span class="jls-verse"><sup class="jls-num">' . esc_html( $number ) . '</sup> ' . esc_html( $v['text'] ) . '</span> ';
		}
		if ( '' !== $para ) {
			$html .= '<p>' . rtrim( $para ) . '</p>';
		}
	}
	return '<div class="jls-text">' . $html . '</div>';
}

/**
 * The version name and its copyright line, under the text.
 *
 * @param array $version Version entry.
 * @return string
 */
function jlife_scripture_render_attribution( $version ) {
	$notice = esc_html( (string) $version['copyright']['text'] );
	$url    = (string) ( $version['copyright']['url'] ?? '' );
	if ( '' !== $url ) {
		$notice = '<a href="' . esc_url( $url ) . '" target="_blank" rel="noopener">' . $notice . '</a>';
	}
	return '<p class="jls-version"><small><span class="jls-version-name">' . esc_html( (string) $version['name'] ) . '</span> · ' . $notice . '</small></p>';
}

/**
 * The "Open in Bible Gateway" link for a passage in the language's link
 * version, with that version's notice. Without a parsed reference, the
 * plan's own link for the day is used.
 *
 * @param array|null $segments Segments, or null when the reference did not parse.
 * @param array|null $fallback Link version.
 * @param array      $day      Plan day (for its pre-built links).
 * @param string     $lang     Language code.
 * @return string
 */
function jlife_scripture_render_link( $segments, $fallback, $day, $lang ) {
	$code = $fallback ? (string) $fallback['source_id'] : (string) ( $day['links'][ $lang ]['version'] ?? '' );
	if ( $fallback && null !== $segments ) {
		$url = jlife_scripture_bible_gateway_url( $segments, $code );
	} else {
		$url = (string) ( $day['links'][ $lang ]['combined']['url'] ?? '' );
	}
	if ( '' === $url || '' === $code ) {
		return '';
	}
	/* translators: %s: Bible version code, e.g. "ERV". */
	$text = sprintf( __( 'Open in Bible Gateway (%s)', 'jlife-scripture' ), $code );
	$out  = '<p class="jls-link"><a href="' . esc_url( $url ) . '" target="_blank" rel="noopener">' . esc_html( $text ) . '</a></p>';
	if ( $fallback ) {
		$out .= '<p class="jls-version"><small>' . esc_html( (string) $fallback['copyright']['text'] ) . '</small></p>';
	}
	return $out;
}

/**
 * The reader's stylesheet (phone-first, no inner scroll box, dark mode).
 */
function jlife_scripture_enqueue_style() {
	wp_enqueue_style( 'jlife-scripture', plugins_url( 'assets/scripture.css', JLIFE_SCRIPTURE_FILE ), array(), '0.1.0' );
}
