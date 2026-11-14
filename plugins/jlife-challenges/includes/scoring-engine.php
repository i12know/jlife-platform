<?php
/**
 * Scoring engines (#98, docs/challenges-engine-design.md §3a).
 *
 * An entry is one free text. Whether it earns the day's point is decided by a
 * separate, pluggable engine, so writing and saving never depend on the text's
 * structure. The first engine, "soap-regex", only checks that the S, O, A and P
 * parts are there, in order, and not empty. It never judges wording.
 *
 * Engine contract: jlife_challenges_score_entry( $text, $context ) returns
 * { passes: bool, reasons: string[], engine: id, version: string }. It is
 * deterministic for a given text, context and engine version. Engines run once,
 * at submit time; they can add points, never take one back.
 *
 * No engine here sends text anywhere. An engine that calls an AI or other
 * outside service needs its own privacy decision first (see the design doc).
 *
 * @package jlife-challenges
 */

defined( 'ABSPATH' ) || exit;

const JLIFE_CHALLENGES_DEFAULT_ENGINE = 'soap-regex';

/**
 * The four headings in SOAP order, translated for the current locale. Also the
 * template new entries start with.
 *
 * @return array<string,string> Letter => heading, e.g. 'S' => 'S — Scripture'.
 */
function jlife_challenges_soap_headings() {
	return array(
		'S' => __( 'S — Scripture', 'jlife-challenges' ),
		'O' => __( 'O — Observation', 'jlife-challenges' ),
		'A' => __( 'A — Application', 'jlife-challenges' ),
		'P' => __( 'P — Prayer', 'jlife-challenges' ),
	);
}

/**
 * The English headings, fixed. Used to join the old four-field rows (the
 * migration must not depend on whoever happens to run it) and understood by
 * every locale.
 *
 * @return array<string,string>
 */
function jlife_challenges_soap_headings_en() {
	return array(
		'S' => 'S — Scripture',
		'O' => 'O — Observation',
		'A' => 'A — Application',
		'P' => 'P — Prayer',
	);
}

/**
 * The words that may follow a heading letter, English and Vietnamese (the
 * template words), so "S — Scripture" and "S — Kinh Thánh" are both headings.
 *
 * @return array<string,string[]>
 */
function jlife_challenges_soap_heading_words() {
	return array(
		'S' => array( 'Scripture', 'Kinh Thánh' ),
		'O' => array( 'Observation', 'Quan sát' ),
		'A' => array( 'Application', 'Áp dụng' ),
		'P' => array( 'Prayer', 'Cầu nguyện' ),
	);
}

/**
 * The template new entries start with: the four headings, a blank line apart.
 *
 * @return string
 */
function jlife_challenges_soap_template() {
	return implode( "\n\n", jlife_challenges_soap_headings() );
}

/**
 * Join four sections under headings, keeping line breaks. An empty section is
 * just its heading, so four empty sections are exactly the template.
 *
 * @param array<string,string> $sections Letter (S, O, A, P) => text.
 * @param array<string,string> $headings Letter => heading.
 * @return string
 */
function jlife_challenges_join_sections( $sections, $headings ) {
	$parts = array();
	foreach ( $headings as $letter => $heading ) {
		$text    = trim( (string) ( $sections[ $letter ] ?? '' ) );
		$parts[] = '' === $text ? $heading : $heading . "\n" . $text;
	}
	return implode( "\n\n", $parts );
}

/**
 * The registered scoring engines.
 *
 * Each engine: label, version, callback( string $text, array $context ): array
 * with 'passes' (bool) and 'reasons' (string[]). Add engines with the
 * jlife_challenges_scoring_engines filter; a callback that isn't callable is
 * ignored and the default is used. The default always exists.
 *
 * @return array<string,array{label:string,version:string,callback:mixed}>
 */
function jlife_challenges_scoring_engines() {
	$engines = (array) apply_filters(
		'jlife_challenges_scoring_engines',
		array(
			JLIFE_CHALLENGES_DEFAULT_ENGINE => array(
				'label'    => 'SOAP regex',
				'version'  => '1',
				'callback' => 'jlife_challenges_engine_soap_regex',
			),
		)
	);
	if ( ! isset( $engines[ JLIFE_CHALLENGES_DEFAULT_ENGINE ] ) ) {
		$engines[ JLIFE_CHALLENGES_DEFAULT_ENGINE ] = array(
			'label'    => 'SOAP regex',
			'version'  => '1',
			'callback' => 'jlife_challenges_engine_soap_regex',
		);
	}
	return $engines;
}

/**
 * The engine id for a campaign (the default if unset or unknown).
 *
 * @param array|null $campaign Campaign row, or null (personal entries).
 * @return string
 */
function jlife_challenges_campaign_engine( $campaign ) {
	$id      = is_array( $campaign ) ? (string) ( $campaign['scoring_engine'] ?? '' ) : '';
	$engines = jlife_challenges_scoring_engines();
	return isset( $engines[ $id ] ) ? $id : JLIFE_CHALLENGES_DEFAULT_ENGINE;
}

/**
 * Run an entry through its campaign's scoring engine, synchronously.
 *
 * @param string $text    Entry text.
 * @param array  $context { campaign?: array, day_key?: string, index?: int, engine?: string }.
 *                        "engine" overrides the campaign's choice.
 * @return array { passes: bool, reasons: string[], engine: string, version: string }
 */
function jlife_challenges_score_entry( $text, $context = array() ) {
	$engines = jlife_challenges_scoring_engines();
	$id      = isset( $context['engine'] ) ? (string) $context['engine'] : jlife_challenges_campaign_engine( $context['campaign'] ?? null );
	if ( ! isset( $engines[ $id ] ) || ! is_callable( $engines[ $id ]['callback'] ) ) {
		$id = JLIFE_CHALLENGES_DEFAULT_ENGINE;
	}
	$engine = $engines[ $id ];
	$result = (array) call_user_func( $engine['callback'], (string) $text, (array) $context );
	return array(
		'passes'  => ! empty( $result['passes'] ),
		'reasons' => array_values( array_map( 'strval', (array) ( $result['reasons'] ?? array() ) ) ),
		'engine'  => $id,
		'version' => (string) $engine['version'],
	);
}

/**
 * Read one line as a SOAP heading: the letter S, O, A or P (any case), then ":"
 * or a dash (—, –, -), then an optional heading word, then optional text.
 *
 * @param string $line One line of the entry.
 * @return array|null { letter: string, rest: string } (rest = same-line text after the heading), or null.
 */
function jlife_challenges_parse_heading( $line ) {
	if ( ! preg_match( '/^[ \t]*([SOAP])[ \t]*[:\x{2014}\x{2013}\-]+[ \t]*(.*)$/iu', $line, $m ) ) {
		return null;
	}
	$letter = strtoupper( $m[1] );
	$rest   = trim( $m[2] );
	foreach ( jlife_challenges_soap_heading_words()[ $letter ] as $word ) {
		$pattern = '/^' . preg_quote( $word, '/' ) . '(?=$|[\s:.\x{2014}\x{2013}\-])[\s:.\x{2014}\x{2013}\-]*/iu';
		if ( preg_match( $pattern, $rest ) ) {
			$rest = trim( (string) preg_replace( $pattern, '', $rest, 1 ) );
			break;
		}
	}
	return array(
		'letter' => $letter,
		'rest'   => $rest,
	);
}

/**
 * The "soap-regex" engine: S, O, A and P headings in that order, each with some
 * non-whitespace text beyond its heading line. The untouched template fails.
 *
 * @param string $text    Entry text.
 * @param array  $context Unused.
 * @return array { passes: bool, reasons: string[] }
 */
function jlife_challenges_engine_soap_regex( $text, $context = array() ) {
	unset( $context );
	$headings = jlife_challenges_soap_headings();
	$lines    = explode( "\n", str_replace( array( "\r\n", "\r" ), "\n", (string) $text ) );

	// The first heading line for each letter.
	$found = array();
	foreach ( $lines as $i => $line ) {
		$h = jlife_challenges_parse_heading( $line );
		if ( $h && ! isset( $found[ $h['letter'] ] ) ) {
			$found[ $h['letter'] ] = array(
				'line' => $i,
				'rest' => $h['rest'],
			);
		}
	}

	$reasons = array();
	$last    = -1;
	$ordered = true;
	foreach ( array_keys( $headings ) as $letter ) {
		if ( ! isset( $found[ $letter ] ) ) {
			/* translators: %s: a heading such as "P — Prayer". */
			$reasons[] = sprintf( __( 'Add the %s part', 'jlife-challenges' ), $headings[ $letter ] );
			continue;
		}
		if ( $found[ $letter ]['line'] < $last ) {
			$ordered = false;
		}
		$last = max( $last, $found[ $letter ]['line'] );
	}
	if ( ! $ordered ) {
		$reasons[] = __( 'Put the parts in order: S, then O, then A, then P', 'jlife-challenges' );
		return array(
			'passes'  => false,
			'reasons' => $reasons,
		);
	}

	// Each section runs from its heading to the next heading found (or the end).
	$starts = array();
	foreach ( $found as $letter => $f ) {
		$starts[ $letter ] = $f['line'];
	}
	foreach ( $found as $letter => $f ) {
		$next = count( $lines );
		foreach ( $starts as $start ) {
			if ( $start > $f['line'] && $start < $next ) {
				$next = $start;
			}
		}
		$body = $f['rest'] . implode( '', array_slice( $lines, $f['line'] + 1, $next - $f['line'] - 1 ) );
		if ( '' === trim( $body ) ) {
			/* translators: %s: a heading such as "P — Prayer". */
			$reasons[] = sprintf( __( 'Add something under %s', 'jlife-challenges' ), $headings[ $letter ] );
		}
	}
	return array(
		'passes'  => array() === $reasons,
		'reasons' => $reasons,
	);
}
