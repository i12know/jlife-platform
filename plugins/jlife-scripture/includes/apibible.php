<?php
/**
 * API.Bible helpers for the reader (#141 part 2): passage IDs from parsed
 * references, splitting the API's plain text into numbered verses, the error
 * codes the shared cache logs, the site's copyright page (terms 7.1 and 7.2),
 * the cached Bible metadata, and the `try` smoke test behind
 * `wp jlife scripture try`.
 *
 * The HTTP client, the key and the monthly counter live in jlife-challenges
 * (includes/bible-apibible.php, #147) and are used from here only through its
 * functions, each guarded: nothing here fails when that plugin is inactive.
 *
 * @package jlife-scripture
 */

defined( 'ABSPATH' ) || exit;

add_shortcode( 'jlife_bible_copyrights', 'jlife_scripture_shortcode_copyrights' );

/**
 * Bible metadata (name, notice) is kept for the cache rule's 14 days.
 */
const JLIFE_SCRIPTURE_APIBIBLE_INFO_TTL = 14 * DAY_IN_SECONDS;

/**
 * Is #147's API.Bible client here (jlife-challenges, or its one file)?
 *
 * @return bool
 */
function jlife_scripture_apibible_client_available() {
	return function_exists( 'jlife_challenges_apibible_fetch_passage' )
		&& function_exists( 'jlife_challenges_apibible_passage_id' )
		&& function_exists( 'jlife_challenges_apibible_fetch_bible' )
		&& function_exists( 'jlife_challenges_apibible_fums_report' );
}

/**
 * The API.Bible passage ID for one parsed segment (#147's helper builds it).
 * A partial verse (Luke 3:23a) is the whole verse, as for every source.
 *
 * @param array $segment Parsed segment.
 * @return string '' when the segment cannot be asked for.
 */
function jlife_scripture_apibible_passage_id( $segment ) {
	if ( ! function_exists( 'jlife_challenges_apibible_passage_id' ) ) {
		return '';
	}
	$usfm  = (string) $segment['usfm'];
	$start = (int) $segment['chapter_start'];
	$end   = (int) $segment['chapter_end'];
	if ( null === $segment['verse_start'] ) {
		return (string) jlife_challenges_apibible_passage_id( $usfm, $start, 0, $end > $start ? $end : 0, 0 );
	}
	$first = (int) $segment['verse_start'];
	$last  = (int) $segment['verse_end'];
	if ( $start === $end && $first === $last ) {
		return (string) jlife_challenges_apibible_passage_id( $usfm, $start, $first );
	}
	return (string) jlife_challenges_apibible_passage_id( $usfm, $start, $first, $end > $start ? $end : 0, $last );
}

/**
 * Split API.Bible's plain text ("[16] For … [17] …") into numbered verses.
 * Titles and notes are not requested, so every part starts with a verse
 * number. When a number is not larger than the one before, the next chapter
 * has begun. A bridged marker ("[16-17]") is the first verse of the bridge; a
 * verse with no text is left out. A single verse may come without a marker.
 *
 * @param string $text    The passage's data.content as plain text.
 * @param array  $segment Parsed segment the text was fetched for.
 * @return array[] Verses { chapter: int, verse: int, text: string }; empty when it cannot be split.
 */
function jlife_scripture_apibible_parse_verses( $text, $segment ) {
	$start = (int) $segment['chapter_start'];
	$end   = (int) $segment['chapter_end'];
	$parts = preg_split( '/\[(\d{1,3})(?:\s*[-\x{2013}]\s*\d{1,3})?\]/u', trim( (string) $text ), -1, PREG_SPLIT_DELIM_CAPTURE );
	if ( false === $parts ) {
		return array();
	}
	if ( 1 === count( $parts ) ) {
		$clean  = jlife_scripture_apibible_clean_text( $parts[0] );
		$single = null !== $segment['verse_start'] && $start === $end && $segment['verse_start'] === $segment['verse_end'];
		return ( $single && '' !== $clean ) ? array(
			array(
				'chapter' => $start,
				'verse'   => (int) $segment['verse_start'],
				'text'    => $clean,
			),
		) : array();
	}
	$verses   = array();
	$chapter  = $start;
	$previous = 0;
	for ( $i = 1, $n = count( $parts ); $i < $n; $i += 2 ) {
		$number = (int) $parts[ $i ];
		if ( $previous > 0 && $number <= $previous && $chapter < $end ) {
			++$chapter;
		}
		$previous = $number;
		$clean    = jlife_scripture_apibible_clean_text( (string) ( $parts[ $i + 1 ] ?? '' ) );
		if ( '' !== $clean ) {
			$verses[] = array(
				'chapter' => $chapter,
				'verse'   => $number,
				'text'    => $clean,
			);
		}
	}
	return $verses;
}

/**
 * One verse's text on a single line: spacing collapsed, trimmed.
 *
 * @param string $text Raw text between two verse markers.
 * @return string
 */
function jlife_scripture_apibible_clean_text( $text ) {
	return trim( (string) preg_replace( '/\s+/u', ' ', $text ) );
}

/**
 * The short error code the shared cache may log for an API.Bible outcome
 * (jlife_scripture_loggable_code() allows only these, never free text).
 *
 * @param string $reason The client's outcome reason.
 * @return string
 */
function jlife_scripture_apibible_error_code( $reason ) {
	$codes = array(
		'not_licensed' => 'forbidden',
		'rate_limited' => 'rate_limited',
		'unavailable'  => 'server_error',
		'bad_request'  => 'not_found',
	);
	return $codes[ $reason ] ?? 'jlife_scripture_source_unavailable';
}

/**
 * The link under API.Bible text to the full copyright information (terms 7.1
 * and 7.2): the site's own "copyright" page, with the version as the anchor.
 * API.Bible's metadata carries the information but no URL, so the owner makes
 * the page (shortcode [jlife_bible_copyrights]) and sets the site option
 * jlife_scripture_copyright_page_url. Empty until then: no link is shown.
 *
 * @param array $version Registry version entry.
 * @return string URL, or ''.
 */
function jlife_scripture_copyright_page_url( $version ) {
	$base = (string) apply_filters( 'jlife_scripture_copyright_page_url', (string) get_option( 'jlife_scripture_copyright_page_url', '' ), $version );
	$base = esc_url_raw( (string) preg_replace( '/#.*$/', '', trim( $base ) ), array( 'http', 'https' ) );
	return '' === $base ? '' : $base . '#' . rawurlencode( (string) $version['key'] );
}

/**
 * One version's metadata from API.Bible, kept for the cache rule so a page
 * with several Bibles does not call the API for every visitor.
 *
 * @param array $version Registry version entry (source apibible).
 * @return array|null { name, abbreviation, copyright, info, language }, or null.
 */
function jlife_scripture_apibible_bible_info( $version ) {
	if ( ! jlife_scripture_apibible_client_available() ) {
		return null;
	}
	$name   = 'jlife_scripture_apibible_info_' . md5( (string) $version['source_id'] );
	$cached = get_site_transient( $name );
	if ( is_array( $cached ) ) {
		return $cached;
	}
	$result = jlife_challenges_apibible_fetch_bible( (string) $version['source_id'], array( 'version' => (string) $version['key'] ) );
	return 'ok' === $result['outcome'] ? jlife_scripture_apibible_store_info( $version, $result ) : null;
}

/**
 * Keep a metadata result for the cache rule.
 *
 * @param array $version Registry version entry.
 * @param array $result  jlife_challenges_apibible_fetch_bible() result (outcome ok).
 * @return array The stored fields.
 */
function jlife_scripture_apibible_store_info( $version, $result ) {
	$info = array(
		'name'         => (string) $result['name'],
		'abbreviation' => (string) $result['abbreviation'],
		'copyright'    => (string) $result['copyright'],
		'info'         => (string) $result['info'],
		'language'     => (string) $result['language'],
	);
	set_site_transient( 'jlife_scripture_apibible_info_' . md5( (string) $version['source_id'] ), $info, JLIFE_SCRIPTURE_APIBIBLE_INFO_TTL );
	return $info;
}

/**
 * [jlife_bible_copyrights] for the site's copyright page (terms 7.1): one
 * section per API.Bible version with the name, abbreviation and notice from the
 * Bible's metadata. Lists the enabled versions; all="yes" lists every API.Bible
 * version (to preview the page before enabling). Each section's id is the
 * version key, the anchor the citations link to.
 *
 * @param array|string $atts Shortcode attributes.
 * @return string
 */
function jlife_scripture_shortcode_copyrights( $atts ) {
	$atts = shortcode_atts( array( 'all' => '' ), (array) $atts, 'jlife_bible_copyrights' );
	$all  = in_array( strtolower( (string) $atts['all'] ), array( 'yes', '1', 'true' ), true );
	$out  = '';
	foreach ( jlife_scripture_registry()['versions'] as $version ) {
		if ( 'apibible' !== $version['source'] || ( ! $all && true !== $version['enabled'] ) ) {
			continue;
		}
		$info   = jlife_scripture_apibible_bible_info( $version );
		$title  = null !== $info ? $info['name'] . ( '' !== $info['abbreviation'] ? ' (' . $info['abbreviation'] . ')' : '' ) : $version['name'] . ' (' . $version['short_code'] . ')';
		$notice = ( null !== $info && '' !== $info['copyright'] ) ? $info['copyright'] : (string) $version['copyright']['text'];
		$out   .= '<section class="jls-copyright" id="' . esc_attr( (string) $version['key'] ) . '"><h2>' . esc_html( $title ) . '</h2><p>' . esc_html( $notice ) . '</p>';
		if ( null !== $info && '' !== $info['info'] ) {
			$out .= '<p>' . esc_html( $info['info'] ) . '</p>';
		}
		$out .= '<p><a href="' . esc_url( (string) $version['copyright']['url'] ) . '" target="_blank" rel="noopener">' . esc_html__( 'More information', 'jlife-scripture' ) . '</a></p></section>';
	}
	return $out;
}

/**
 * Smoke-test one passage of one version for the owner (`wp jlife scripture
 * try`), before the version is enabled: fetch it through the same source and
 * shared cache the reader uses and describe the result. The version may still
 * be disabled and link-only: the override lives only inside this call. No
 * display is reported to FUMS. The key is never in the output; the text is
 * (the owner is checking it), so this must stay a CLI-only tool.
 *
 * @param string $key       Registry version key (nlt, nkjv, csb …).
 * @param string $reference A reference such as "John 3:16-18".
 * @param array  $opts      { metadata: bool also fetch the Bible's metadata (one more API call) }.
 * @return array|WP_Error { ok: bool, calls: int API calls this command spent, lines: string[] }.
 */
function jlife_scripture_try_passage( $key, $reference, $opts = array() ) {
	$key     = (string) $key;
	$version = jlife_scripture_get_version( $key );
	if ( null === $version ) {
		return new WP_Error( 'jlife_scripture_try_unknown', 'That version key is not in the registry.' );
	}
	$source = jlife_scripture_get_source( (string) $version['source'] );
	if ( null === $source || 'link' === $version['source'] ) {
		return new WP_Error( 'jlife_scripture_try_link', 'That version is a Bible Gateway link only: there is no text source to try.' );
	}
	$segments = jlife_scripture_parse_reference( (string) $reference );
	if ( is_wp_error( $segments ) ) {
		return new WP_Error( 'jlife_scripture_try_reference', 'That reference could not be read.' );
	}
	$known = jlife_scripture_verse_count( $segments );
	$max   = $version['cache']['max_consecutive_verses'];
	if ( null !== $max && null !== $known && $known > $max ) {
		return new WP_Error( 'jlife_scripture_try_long', sprintf( 'That passage is over the license limit of %d consecutive verses.', (int) $max ) );
	}

	$override = static function ( $doc ) use ( $key ) {
		foreach ( (array) ( $doc['versions'] ?? array() ) as $i => $v ) {
			if ( ( $v['key'] ?? '' ) === $key ) {
				$doc['versions'][ $i ]['enabled']          = true;
				$doc['versions'][ $i ]['display']          = 'native';
				$doc['versions'][ $i ]['license_evidence'] = 'wp jlife scripture try (command line only)';
			}
		}
		return $doc;
	};
	add_filter( 'jlife_scripture_registry', $override );
	try {
		$tried = jlife_scripture_get_version( $key );
		$calls = jlife_scripture_apibible_calls();
		if ( $source instanceof Jlife_Scripture_Apibible_Source ) {
			Jlife_Scripture_Apibible_Source::reset_diagnostics();
		}
		$entry = null === $tried ? new WP_Error( 'jlife_scripture_try_unknown' ) : jlife_scripture_fetch_entry( $tried, $segments );
	} finally {
		remove_filter( 'jlife_scripture_registry', $override );
	}

	$lines   = array();
	$lines[] = sprintf( 'Version: %s (%s), source %s', $key, (string) $version['name'], (string) $version['source'] );
	$lines[] = sprintf( 'Reference: %s -> passage %s', jlife_scripture_format_segment( $segments[0] ) . ( count( $segments ) > 1 ? ' …' : '' ), jlife_scripture_passage_key( $segments ) );
	$diag    = $source instanceof Jlife_Scripture_Apibible_Source ? Jlife_Scripture_Apibible_Source::diagnostics() : null;
	$fetched = null === $diag || $diag['fetched'];
	$spent   = max( 0, jlife_scripture_apibible_calls() - $calls );

	if ( is_wp_error( $entry ) ) {
		$lines[] = 'Result: failed (the passage could not be loaded; the reader would show the Bible Gateway link)';
		if ( null !== $diag ) {
			$lines[] = $diag['fetched'] && $diag['http_status'] > 0 ? 'HTTP status: ' . $diag['http_status'] : 'HTTP status: none (no request was made, or the network failed)';
			$lines[] = '' !== $diag['outcome'] ? sprintf( 'Outcome: %s (%s)', $diag['outcome'], $diag['reason'] ) : 'Outcome: the source did not answer';
		}
		$ok = false;
	} else {
		$stored = jlife_scripture_entry_max_age( $version, $entry['meta'] ) > 0;
		if ( ! $source->cacheable() ) {
			$lines[] = 'Cache: not used (local text)';
		} elseif ( ! $fetched ) {
			$lines[] = 'Cache: hit (served from the shared cache; no API request)';
		} elseif ( $stored ) {
			$lines[] = sprintf( 'Cache: miss (fetched, now stored for up to %d days)', (int) ceil( jlife_scripture_entry_max_age( $version, $entry['meta'] ) / DAY_IN_SECONDS ) );
		} else {
			$lines[] = 'Cache: miss (fetched, not stored: the API says it may not be cached)';
		}
		if ( null !== $diag ) {
			$lines[] = $fetched ? 'HTTP status: ' . $diag['http_status'] : 'HTTP status: none (no request was made)';
		}
		$lines[] = 'Copyright: ' . ( '' !== (string) ( $entry['meta']['copyright'] ?? '' ) ? (string) $entry['meta']['copyright'] : '(none returned: the registry line would be shown)' );
		$lines[] = 'Text:';
		foreach ( $entry['segments'] as $verses ) {
			foreach ( $verses as $verse ) {
				$lines[] = sprintf( '  %d:%d %s', $verse['chapter'], $verse['verse'], $verse['text'] );
			}
		}
		$ok = true;
	}

	if ( ! empty( $opts['metadata'] ) && jlife_scripture_apibible_client_available() && 'apibible' === $version['source'] ) {
		$meta    = jlife_challenges_apibible_fetch_bible( (string) $version['source_id'], array( 'version' => $key ) );
		$lines[] = 'ok' === $meta['outcome']
			? sprintf( 'Bible metadata: ok, HTTP status %d (%s, %s); notice: %s', (int) $meta['http_status'], $meta['name'], $meta['abbreviation'], $meta['copyright'] )
			: sprintf( 'Bible metadata: failed (%s), HTTP status %d', $meta['reason'], (int) $meta['http_status'] );
		if ( 'ok' === $meta['outcome'] ) {
			jlife_scripture_apibible_store_info( $version, $meta );
		}
		$spent = max( 0, jlife_scripture_apibible_calls() - $calls );
	}

	$page    = jlife_scripture_copyright_page_url( $version );
	$lines[] = '' !== $page ? 'Copyright page link: ' . $page : 'Copyright page link: not set (create a page with [jlife_bible_copyrights] and set the site option jlife_scripture_copyright_page_url)';
	if ( 'apibible' === $version['source'] ) {
		$limit   = defined( 'JLIFE_CHALLENGES_APIBIBLE_MONTHLY_LIMIT' ) ? (int) constant( 'JLIFE_CHALLENGES_APIBIBLE_MONTHLY_LIMIT' ) : 5000;
		$lines[] = sprintf( 'API calls used by this command: %d (this month: %d of %d)', $spent, jlife_scripture_apibible_calls(), $limit );
	}
	return array(
		'ok'    => $ok,
		'calls' => $spent,
		'lines' => $lines,
	);
}

/**
 * This month's API.Bible call count from the client (0 without it).
 *
 * @return int
 */
function jlife_scripture_apibible_calls() {
	return function_exists( 'jlife_challenges_apibible_usage' ) ? (int) jlife_challenges_apibible_usage()['calls'] : 0;
}
