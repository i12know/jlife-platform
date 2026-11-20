<?php
/**
 * API.Bible passage source (#141) for the native Scripture reader (#139).
 *
 * A narrow, self-contained source: it fetches one passage for one Bible from
 * API.Bible's REST API, server-side through wp_remote_get(), and returns the
 * text with the copyright line the API sends and the FUMS token for usage
 * reporting. It keeps no Bible text: caching belongs to #139's shared passage
 * cache, which should store a result for at most
 * jlife_challenges_apibible_cache_rule() allows. See
 * docs/bible-provider-entitlements.md (API.Bible) for the rules and sources.
 *
 * Privacy and secrets:
 * - The key comes from the JLIFE_APIBIBLE_KEY constant, defined in a private
 *   0600 file on the host (config/latoc-bible.example.php). It only travels in
 *   the request's api-key header: never in a URL, a log line, an error message
 *   or a return value.
 * - Log lines carry the version key, an outcome code and the HTTP status only;
 *   never Bible text, the key, a passage or a participant.
 * - FUMS reports go from this server, so API.Bible never sees a participant's
 *   IP address; they carry an anonymous install ID and a daily session ID and
 *   no user ID.
 *
 * The native reader's `apibible` source (plugins/jlife-scripture,
 * Jlife_Scripture_Apibible_Source, #141 part 2) calls the functions here and
 * nothing else: this file stays the only place that talks to API.Bible, holds
 * the key and counts calls.
 *
 * @package jlife-challenges
 */

defined( 'ABSPATH' ) || exit;

/**
 * API.Bible's REST base and FUMS v3 endpoint.
 */
const JLIFE_CHALLENGES_APIBIBLE_BASE = 'https://rest.api.bible/v1';
const JLIFE_CHALLENGES_APIBIBLE_FUMS = 'https://fums.api.bible/f3';

/**
 * The Starter plan allows 5,000 API calls a month; warn from 4,000.
 */
const JLIFE_CHALLENGES_APIBIBLE_MONTHLY_LIMIT = 5000;
const JLIFE_CHALLENGES_APIBIBLE_WARN_AT       = 4000;

/**
 * Network options: this month's call count, and the anonymous FUMS install ID.
 */
const JLIFE_CHALLENGES_APIBIBLE_USAGE_OPTION  = 'jlife_apibible_usage';
const JLIFE_CHALLENGES_APIBIBLE_DEVICE_OPTION = 'jlife_apibible_fums_device';

/**
 * The private host key, or '' when none is set. Never print or log the result.
 *
 * @return string
 */
function jlife_challenges_apibible_key() {
	$key = defined( 'JLIFE_APIBIBLE_KEY' ) && is_string( constant( 'JLIFE_APIBIBLE_KEY' ) ) ? trim( constant( 'JLIFE_APIBIBLE_KEY' ) ) : '';
	$key = (string) apply_filters( 'jlife_challenges_apibible_key', $key );
	if ( '' === $key || 'REPLACE_WITH_API_BIBLE_KEY' === $key || preg_match( '/[\s]/', $key ) ) {
		return '';
	}
	return $key;
}

/**
 * The cache rule for API.Bible text. #139's passage cache must not store a
 * result for longer, or a longer run of consecutive verses.
 *
 * - "fewer than 500 consecutive verses" (FAQ);
 * - "clear your cache every 14 days or less" (FAQ, a recommendation); the
 *   Acceptable Use terms require refreshing "at least once every 30 days".
 *   We use the stricter 14 days.
 *
 * @return array { max_age: int seconds, max_consecutive_verses: int, sources: string[] }
 */
function jlife_challenges_apibible_cache_rule() {
	return array(
		'max_age'                => 14 * DAY_IN_SECONDS,
		'max_consecutive_verses' => 499,
		'sources'                => array(
			'https://docs.api.bible/common-questions/',
			'https://api.bible/terms-and-conditions',
		),
	);
}

/**
 * Build an API.Bible passage ID such as JHN.1.29-JHN.1.34 from USFM-style parts.
 *
 * @param string $book        Three-character book code (JHN, 1CO).
 * @param int    $chapter     Start chapter.
 * @param int    $verse       Start verse; 0 for the whole chapter.
 * @param int    $end_chapter End chapter; 0 for the same chapter.
 * @param int    $end_verse   End verse; 0 for a single verse or chapter.
 * @return string The ID, or '' when the parts are not valid.
 */
function jlife_challenges_apibible_passage_id( $book, $chapter, $verse = 0, $end_chapter = 0, $end_verse = 0 ) {
	$book = strtoupper( (string) $book );
	if ( ! preg_match( '/^[0-9A-Z]{3}$/', $book ) || $chapter < 1 || $verse < 0 || $end_chapter < 0 || $end_verse < 0 ) {
		return '';
	}
	$start = $book . '.' . (int) $chapter . ( $verse ? '.' . (int) $verse : '' );
	if ( ! $end_verse && ! $end_chapter ) {
		return $start;
	}
	$end_chapter = $end_chapter ? (int) $end_chapter : (int) $chapter;
	return $start . '-' . $book . '.' . $end_chapter . ( $end_verse ? '.' . (int) $end_verse : '' );
}

/**
 * Whether a Bible ID and passage ID are safe to put in a request path.
 *
 * @param string $bible_id   API.Bible bibleId.
 * @param string $passage_id API.Bible passage ID.
 * @return bool
 */
function jlife_challenges_apibible_valid_ids( $bible_id, $passage_id ) {
	return (bool) preg_match( '/^[A-Za-z0-9-]{1,64}$/', (string) $bible_id )
		&& (bool) preg_match( '/^[0-9A-Z]{3}\.\d{1,3}(\.\d{1,3})?(-[0-9A-Z]{3}\.\d{1,3}(\.\d{1,3})?)?$/', (string) $passage_id );
}

/**
 * Fetch one passage. Never throws; a failure says how the reader should fall back.
 *
 * Outcomes:
 * - ok: content (sanitized HTML, or plain text with [n] verse numbers), copyright, FUMS token;
 * - link_only: the version is not licensed to us (401/403) or no key is set; show the link;
 * - fallback: a 429, an outage, a bad request, or this month's quota is used; show the link
 *   and try again later.
 *
 * Every request that reaches API.Bible counts toward this month's usage. After a
 * 403/401, 429 or outage the Bible is held for a while, so a busy page does not
 * spend the quota on calls that will fail.
 *
 * @param string $bible_id   API.Bible bibleId.
 * @param string $passage_id API.Bible passage ID (jlife_challenges_apibible_passage_id()).
 * @param array  $opts       { version: string registry key for logs, format: 'html'|'text' }.
 * @return array Result: outcome, reason, http_status, version, bible_id, passage_id, format,
 *               content, reference, verse_count, copyright, fums (array|null), fetched_at, cache.
 */
function jlife_challenges_apibible_fetch_passage( $bible_id, $passage_id, array $opts = array() ) {
	$version = sanitize_key( (string) ( $opts['version'] ?? '' ) );
	$format  = 'text' === ( $opts['format'] ?? 'html' ) ? 'text' : 'html';
	$result  = array(
		'outcome'     => 'fallback',
		'reason'      => 'bad_request',
		'http_status' => 0,
		'version'     => $version,
		'bible_id'    => (string) $bible_id,
		'passage_id'  => (string) $passage_id,
		'format'      => $format,
		'content'     => '',
		'reference'   => '',
		'verse_count' => 0,
		'copyright'   => '',
		'fums'        => null,
		'fetched_at'  => time(),
		'cache'       => jlife_challenges_apibible_cache_rule() + array( 'cacheable' => false ),
	);

	if ( ! jlife_challenges_apibible_valid_ids( $bible_id, $passage_id ) ) {
		$result['bible_id']   = '';
		$result['passage_id'] = '';
		return jlife_challenges_apibible_finish( $result );
	}
	$blocked = jlife_challenges_apibible_preflight( $result, $bible_id );
	if ( null !== $blocked ) {
		return $blocked;
	}
	$key = jlife_challenges_apibible_key();

	$url      = JLIFE_CHALLENGES_APIBIBLE_BASE . '/bibles/' . rawurlencode( $bible_id ) . '/passages/' . rawurlencode( $passage_id ) . '?' . http_build_query(
		array(
			'content-type'            => $format,
			'include-notes'           => 'false',
			'include-titles'          => 'false',
			'include-chapter-numbers' => 'false',
			'include-verse-numbers'   => 'true',
			'include-verse-spans'     => 'false',
		),
		'',
		'&'
	);
	$response = jlife_challenges_apibible_request( $url, $key );
	unset( $key );
	$status                = is_wp_error( $response ) ? 0 : (int) wp_remote_retrieve_response_code( $response );
	$result['http_status'] = $status;

	if ( 200 !== $status ) {
		$result['reason']  = jlife_challenges_apibible_reason_for_status( $status );
		$result['outcome'] = 'not_licensed' === $result['reason'] ? 'link_only' : 'fallback';
		jlife_challenges_apibible_hold( $bible_id, $result['reason'] );
		return jlife_challenges_apibible_finish( $result );
	}

	$body = json_decode( (string) wp_remote_retrieve_body( $response ), true );
	$data = is_array( $body ) && isset( $body['data'] ) && is_array( $body['data'] ) ? $body['data'] : array();
	$raw  = isset( $data['content'] ) && is_string( $data['content'] ) ? $data['content'] : '';
	if ( '' === trim( $raw ) ) {
		$result['reason'] = 'bad_response';
		return jlife_challenges_apibible_finish( $result );
	}
	$meta  = is_array( $body['meta'] ?? null ) ? $body['meta'] : array();
	$token = (string) ( $meta['fumsToken'] ?? ( $meta['fumsId'] ?? '' ) );
	$token = preg_match( '/^[A-Za-z0-9._~-]{1,512}$/', $token ) ? $token : '';
	$count = (int) ( $data['verseCount'] ?? 0 );

	$result['outcome']            = 'ok';
	$result['reason']             = 'ok';
	$result['content']            = 'html' === $format ? wp_kses_post( $raw ) : sanitize_textarea_field( $raw );
	$result['reference']          = sanitize_text_field( (string) ( $data['reference'] ?? '' ) );
	$result['verse_count']        = $count;
	$result['copyright']          = trim( wp_strip_all_tags( (string) ( $data['copyright'] ?? '' ) ) );
	$result['fums']               = '' !== $token ? array(
		'version' => 3,
		'token'   => $token,
	) : null;
	$result['cache']['cacheable'] = $count > 0 && $count <= $result['cache']['max_consecutive_verses'];
	return $result;
}

/**
 * The checks before any request to API.Bible: a key is set, the Bible is not
 * being held after a failure, and this month's quota is not used up. Shared by
 * every kind of request, so none of them can skip a check.
 *
 * @param array  $result   A result array (outcome, reason, version, http_status …) to finish.
 * @param string $bible_id API.Bible bibleId.
 * @return array|null The finished result to return as is, or null to go ahead.
 */
function jlife_challenges_apibible_preflight( array $result, $bible_id ) {
	if ( '' === jlife_challenges_apibible_key() ) {
		return jlife_challenges_apibible_finish(
			array_merge(
				$result,
				array(
					'outcome' => 'link_only',
					'reason'  => 'no_key',
				)
			)
		);
	}
	$held = get_site_transient( jlife_challenges_apibible_hold_key( $bible_id ) );
	if ( is_array( $held ) ) {
		$result['outcome'] = 'not_licensed' === ( $held['reason'] ?? '' ) ? 'link_only' : 'fallback';
		$result['reason']  = 'held';
		return $result; // Already logged when the hold started.
	}
	if ( jlife_challenges_apibible_usage()['calls'] >= JLIFE_CHALLENGES_APIBIBLE_MONTHLY_LIMIT ) {
		return jlife_challenges_apibible_finish( array_merge( $result, array( 'reason' => 'quota' ) ) );
	}
	return null;
}

/**
 * One Bible's metadata: name, abbreviation, the copyright notice and the
 * free-text info, from `GET /v1/bibles/{bibleId}`. API.Bible's terms (7.1) say
 * the copyright information for a "copyright" page comes from this response.
 * Same rules as a passage: the key only in the header, a counted call, the
 * hold after a failure, one allowlisted log line on a non-ok outcome. Nothing is
 * cached here (the caller keeps it for at most the cache rule).
 *
 * @param string $bible_id API.Bible bibleId.
 * @param array  $opts     { version: string registry key for logs }.
 * @return array Result: outcome (ok|link_only|fallback), reason, http_status, version, bible_id,
 *               name, abbreviation, copyright, info, language, fetched_at.
 */
function jlife_challenges_apibible_fetch_bible( $bible_id, array $opts = array() ) {
	$version = sanitize_key( (string) ( $opts['version'] ?? '' ) );
	$result  = array(
		'outcome'      => 'fallback',
		'reason'       => 'bad_request',
		'http_status'  => 0,
		'version'      => $version,
		'bible_id'     => (string) $bible_id,
		'name'         => '',
		'abbreviation' => '',
		'copyright'    => '',
		'info'         => '',
		'language'     => '',
		'fetched_at'   => time(),
	);
	if ( ! preg_match( '/^[A-Za-z0-9-]{1,64}$/', (string) $bible_id ) ) {
		$result['bible_id'] = '';
		return jlife_challenges_apibible_finish( $result );
	}
	$blocked = jlife_challenges_apibible_preflight( $result, $bible_id );
	if ( null !== $blocked ) {
		return $blocked;
	}
	$key      = jlife_challenges_apibible_key();
	$response = jlife_challenges_apibible_request( JLIFE_CHALLENGES_APIBIBLE_BASE . '/bibles/' . rawurlencode( $bible_id ), $key );
	unset( $key );
	$status                = is_wp_error( $response ) ? 0 : (int) wp_remote_retrieve_response_code( $response );
	$result['http_status'] = $status;
	if ( 200 !== $status ) {
		$result['reason']  = jlife_challenges_apibible_reason_for_status( $status );
		$result['outcome'] = 'not_licensed' === $result['reason'] ? 'link_only' : 'fallback';
		jlife_challenges_apibible_hold( $bible_id, $result['reason'] );
		return jlife_challenges_apibible_finish( $result );
	}
	$body = json_decode( (string) wp_remote_retrieve_body( $response ), true );
	$data = is_array( $body ) && is_array( $body['data'] ?? null ) ? $body['data'] : array();
	$name = sanitize_text_field( (string) ( $data['name'] ?? '' ) );
	if ( '' === $name ) {
		$result['reason'] = 'bad_response';
		return jlife_challenges_apibible_finish( $result );
	}
	$result['outcome']      = 'ok';
	$result['reason']       = 'ok';
	$result['name']         = $name;
	$result['abbreviation'] = sanitize_text_field( (string) ( $data['abbreviation'] ?? '' ) );
	$result['copyright']    = trim( wp_strip_all_tags( (string) ( $data['copyright'] ?? '' ) ) );
	$result['info']         = trim( wp_strip_all_tags( (string) ( $data['info'] ?? '' ) ) );
	$result['language']     = sanitize_text_field( (string) ( $data['language']['id'] ?? '' ) );
	return $result;
}

/**
 * One GET to API.Bible with the key in the api-key header, counted toward this
 * month's usage. The key never goes in the URL.
 *
 * @param string $url Full request URL (no key).
 * @param string $key The account key.
 * @return array|WP_Error wp_remote_get() result.
 */
function jlife_challenges_apibible_request( $url, $key ) {
	jlife_challenges_apibible_count( 'calls' );
	return wp_remote_get(
		$url,
		array(
			'timeout'     => 8,
			'redirection' => 0,
			'headers'     => array(
				'api-key' => $key,
				'Accept'  => 'application/json',
			),
		)
	);
}

/**
 * The outcome code for a non-200 HTTP status (0 = no response).
 *
 * @param int $status HTTP status.
 * @return string not_licensed|rate_limited|bad_request|unavailable
 */
function jlife_challenges_apibible_reason_for_status( $status ) {
	if ( 401 === $status || 403 === $status ) {
		return 'not_licensed';
	}
	if ( 429 === $status ) {
		return 'rate_limited';
	}
	if ( 400 === $status || 404 === $status ) {
		return 'bad_request';
	}
	return 'unavailable';
}

/**
 * Log a non-ok outcome (version key, outcome code and HTTP status only) and
 * return the result unchanged.
 *
 * @param array $result Fetch result.
 * @return array
 */
function jlife_challenges_apibible_finish( array $result ) {
	if ( 'ok' !== $result['outcome'] ) {
		jlife_challenges_apibible_log( (string) $result['version'], (string) $result['reason'], (int) $result['http_status'] );
	}
	return $result;
}

/**
 * Write one allowlisted line to the PHP error log.
 *
 * @param string $version Registry key.
 * @param string $reason  Outcome code.
 * @param int    $status  HTTP status (0 = none).
 */
function jlife_challenges_apibible_log( $version, $reason, $status ) {
	$reason = in_array( $reason, array( 'no_key', 'not_licensed', 'rate_limited', 'bad_request', 'unavailable', 'bad_response', 'quota' ), true ) ? $reason : 'other';
	error_log( sprintf( 'J-Life API.Bible: version=%s outcome=%s status=%d', '' !== sanitize_key( $version ) ? sanitize_key( $version ) : '-', $reason, (int) $status ) ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- allowlisted operational line: no text, key or person.
}

/**
 * The transient name that holds a Bible after a failure.
 *
 * @param string $bible_id API.Bible bibleId.
 * @return string
 */
function jlife_challenges_apibible_hold_key( $bible_id ) {
	return 'jlife_apibible_hold_' . md5( (string) $bible_id );
}

/**
 * Pause requests for a Bible after a failure: an hour when it is not licensed,
 * 15 minutes after a 429, 5 minutes after an outage.
 *
 * @param string $bible_id API.Bible bibleId.
 * @param string $reason   Outcome code.
 */
function jlife_challenges_apibible_hold( $bible_id, $reason ) {
	$seconds = array(
		'not_licensed' => HOUR_IN_SECONDS,
		'rate_limited' => 15 * MINUTE_IN_SECONDS,
		'unavailable'  => 5 * MINUTE_IN_SECONDS,
	);
	if ( isset( $seconds[ $reason ] ) ) {
		set_site_transient( jlife_challenges_apibible_hold_key( $bible_id ), array( 'reason' => $reason ), $seconds[ $reason ] );
	}
}

/**
 * This month's usage (UTC calendar month), across the whole network: calls to
 * the REST API, and FUMS reports (kept apart: the docs don't say they count).
 *
 * @return array { month: string Y-m, calls: int, fums: int }
 */
function jlife_challenges_apibible_usage() {
	$month = gmdate( 'Y-m' );
	$saved = get_site_option( JLIFE_CHALLENGES_APIBIBLE_USAGE_OPTION, array() );
	if ( ! is_array( $saved ) || ( $saved['month'] ?? '' ) !== $month ) {
		return array(
			'month' => $month,
			'calls' => 0,
			'fums'  => 0,
		);
	}
	return array(
		'month' => $month,
		'calls' => (int) ( $saved['calls'] ?? 0 ),
		'fums'  => (int) ( $saved['fums'] ?? 0 ),
	);
}

/**
 * Add to this month's usage.
 *
 * @param string $what calls|fums.
 * @param int    $n    How many.
 */
function jlife_challenges_apibible_count( $what, $n = 1 ) {
	$usage          = jlife_challenges_apibible_usage();
	$usage[ $what ] = (int) ( $usage[ $what ] ?? 0 ) + (int) $n;
	update_site_option( JLIFE_CHALLENGES_APIBIBLE_USAGE_OPTION, $usage );
}

/**
 * Usage state for display: ok, near (from 4,000) or over (5,000 or more).
 *
 * @param int $calls Calls this month.
 * @return string ok|near|over
 */
function jlife_challenges_apibible_usage_state( $calls ) {
	if ( $calls >= JLIFE_CHALLENGES_APIBIBLE_MONTHLY_LIMIT ) {
		return 'over';
	}
	return $calls >= JLIFE_CHALLENGES_APIBIBLE_WARN_AT ? 'near' : 'ok';
}

/**
 * The Bibles this account can use (metadata only), for recording bibleIds.
 *
 * @param string $language Optional ISO 639-3 code, e.g. eng.
 * @return array[]|WP_Error Rows { id, abbreviation, name, language }.
 */
function jlife_challenges_apibible_list_bibles( $language = '' ) {
	$key = jlife_challenges_apibible_key();
	if ( '' === $key ) {
		return new WP_Error( 'jlife_apibible_no_key', 'No API.Bible key is set on this host (JLIFE_APIBIBLE_KEY).' );
	}
	$url = JLIFE_CHALLENGES_APIBIBLE_BASE . '/bibles';
	if ( preg_match( '/^[a-z]{3}$/', (string) $language ) ) {
		$url .= '?language=' . $language;
	}
	$response = jlife_challenges_apibible_request( $url, $key );
	unset( $key );
	$status = is_wp_error( $response ) ? 0 : (int) wp_remote_retrieve_response_code( $response );
	if ( 200 !== $status ) {
		jlife_challenges_apibible_log( 'list', jlife_challenges_apibible_reason_for_status( $status ), $status );
		return new WP_Error( 'jlife_apibible_http', sprintf( 'API.Bible answered with HTTP status %d.', $status ) );
	}
	$body = json_decode( (string) wp_remote_retrieve_body( $response ), true );
	$rows = array();
	foreach ( ( is_array( $body ) && is_array( $body['data'] ?? null ) ) ? $body['data'] : array() as $bible ) {
		if ( ! is_array( $bible ) ) {
			continue;
		}
		$rows[] = array(
			'id'           => sanitize_text_field( (string) ( $bible['id'] ?? '' ) ),
			'abbreviation' => sanitize_text_field( (string) ( $bible['abbreviation'] ?? '' ) ),
			'name'         => sanitize_text_field( (string) ( $bible['name'] ?? '' ) ),
			'language'     => sanitize_text_field( (string) ( $bible['language']['id'] ?? '' ) ),
		);
	}
	return $rows;
}

/**
 * The anonymous FUMS install ID: random, made once per network, never tied to a person.
 *
 * @return string
 */
function jlife_challenges_apibible_fums_device() {
	$id = (string) get_site_option( JLIFE_CHALLENGES_APIBIBLE_DEVICE_OPTION, '' );
	if ( ! preg_match( '/^[0-9a-f-]{36}$/', $id ) ) {
		$id = wp_generate_uuid4();
		update_site_option( JLIFE_CHALLENGES_APIBIBLE_DEVICE_OPTION, $id );
	}
	return $id;
}

/**
 * The FUMS session ID: random, replaced every day.
 *
 * @return string
 */
function jlife_challenges_apibible_fums_session() {
	$id = get_site_transient( 'jlife_apibible_fums_session' );
	if ( ! is_string( $id ) || ! preg_match( '/^[0-9a-f-]{36}$/', $id ) ) {
		$id = wp_generate_uuid4();
		set_site_transient( 'jlife_apibible_fums_session', $id, DAY_IN_SECONDS );
	}
	return $id;
}

/**
 * The FUMS v3 report URL for displayed passages: one t= per view, the install
 * ID and the session ID. No user ID (uId) and no personal data.
 *
 * @param array $tokens FUMS tokens, one per displayed passage; anything else is dropped.
 * @return string '' when there is nothing to report.
 */
function jlife_challenges_apibible_fums_url( array $tokens ) {
	$parts = array();
	foreach ( array_slice( $tokens, 0, 25 ) as $token ) {
		if ( is_string( $token ) && preg_match( '/^[A-Za-z0-9._~-]{1,512}$/', $token ) ) {
			$parts[] = 't=' . rawurlencode( $token );
		}
	}
	if ( ! $parts ) {
		return '';
	}
	$parts[] = 'dId=' . rawurlencode( jlife_challenges_apibible_fums_device() );
	$parts[] = 'sId=' . rawurlencode( jlife_challenges_apibible_fums_session() );
	return JLIFE_CHALLENGES_APIBIBLE_FUMS . '?' . implode( '&', $parts );
}

/**
 * Report displayed passages to FUMS, from the server and without waiting for
 * the answer. Call it every time API.Bible text is shown, cached or not.
 *
 * @param string[] $tokens FUMS tokens of the passages just shown.
 * @return bool Whether a report was sent.
 */
function jlife_challenges_apibible_fums_report( array $tokens ) {
	$url = jlife_challenges_apibible_fums_url( $tokens );
	if ( '' === $url || ! apply_filters( 'jlife_challenges_apibible_fums_enabled', true ) ) {
		return false;
	}
	wp_remote_get(
		$url,
		array(
			'blocking'    => false,
			'timeout'     => 3,
			'redirection' => 0,
		)
	);
	jlife_challenges_apibible_count( 'fums', substr_count( $url, '?t=' ) + substr_count( $url, '&t=' ) );
	return true;
}

/**
 * Render an ok result: the passage, the copyright line the API returned, and the
 * Starter plan's visible link to API.Bible. Reports the view to FUMS. A non-ok
 * result renders '' so the caller shows the Bible Gateway link instead.
 *
 * @param array $result jlife_challenges_apibible_fetch_passage() result (possibly from cache).
 * @return string HTML.
 */
function jlife_challenges_apibible_render( array $result ) {
	if ( 'ok' !== ( $result['outcome'] ?? '' ) || '' === (string) ( $result['content'] ?? '' ) ) {
		return '';
	}
	$text = 'text' === ( $result['format'] ?? 'html' )
		? '<p>' . nl2br( esc_html( trim( (string) $result['content'] ) ) ) . '</p>'
		: wp_kses_post( (string) $result['content'] );
	if ( ! empty( $result['fums']['token'] ) ) {
		jlife_challenges_apibible_fums_report( array( (string) $result['fums']['token'] ) );
	}
	return '<div class="jl-scripture jl-scripture-apibible">' . $text . jlife_challenges_apibible_attribution_html( $result ) . '</div>';
}

/**
 * The attribution under API.Bible text: the returned copyright notice and a
 * visible link to API.Bible (Starter plan requirement).
 *
 * @param array $result Fetch result.
 * @return string HTML.
 */
function jlife_challenges_apibible_attribution_html( array $result ) {
	$html = '<p class="jl-scripture-copyright">';
	if ( '' !== (string) ( $result['copyright'] ?? '' ) ) {
		$html .= esc_html( (string) $result['copyright'] ) . ' ';
	}
	return $html . '<a href="https://api.bible" rel="noopener">' . esc_html__( 'Scripture text provided by API.Bible', 'jlife-challenges' ) . '</a></p>';
}
