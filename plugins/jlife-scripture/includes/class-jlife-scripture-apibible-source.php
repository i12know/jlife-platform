<?php
/**
 * API.Bible source (#141 part 2): NLT, NKJV and CSB through the client in
 * jlife-challenges (#147) and the shared passage cache (#139).
 *
 * This class only turns the client's answer into what the reader needs. It
 * does not talk to the network itself, hold the key or count calls: that all
 * stays in jlife_challenges_apibible_*(), and every call here is guarded, so
 * with jlife-challenges inactive the source is simply unavailable and the
 * reader shows the Bible Gateway link.
 *
 * Per fetched passage it returns the verses (plain text, numbered), and keeps
 * in the cache entry's meta what a later display needs: the copyright notice
 * the API sent, the FUMS tokens, and the cache rule (cacheable, max_age). A
 * display, fetched or from the cache, reports to FUMS through
 * track_display(); only a real fetch counts against the monthly API calls.
 *
 * @package jlife-scripture
 */

defined( 'ABSPATH' ) || exit;

/**
 * API.Bible text for the native reader.
 */
class Jlife_Scripture_Apibible_Source implements Jlife_Scripture_Source, Jlife_Scripture_Display_Tracking, Jlife_Scripture_Source_Attribution {

	/**
	 * What the last fetch() learned, for the `wp jlife scripture try` command.
	 * Outcome codes only, never text or the key.
	 *
	 * @var array{fetched: bool, http_status: int, outcome: string, reason: string}
	 */
	private static $diagnostics = array(
		'fetched'     => false,
		'http_status' => 0,
		'outcome'     => '',
		'reason'      => '',
	);

	/**
	 * Forget the last fetch.
	 */
	public static function reset_diagnostics() {
		self::$diagnostics = array(
			'fetched'     => false,
			'http_status' => 0,
			'outcome'     => '',
			'reason'      => '',
		);
	}

	/**
	 * What the last fetch() learned.
	 *
	 * @return array{fetched: bool, http_status: int, outcome: string, reason: string}
	 */
	public static function diagnostics() {
		return self::$diagnostics;
	}

	/**
	 * Keep the client's outcome codes for diagnostics (no text, no key).
	 *
	 * @param array $result A client result.
	 */
	private static function record( array $result ) {
		self::$diagnostics['http_status'] = (int) $result['http_status'];
		self::$diagnostics['outcome']     = (string) $result['outcome'];
		self::$diagnostics['reason']      = (string) $result['reason'];
	}

	/**
	 * Registry source value.
	 *
	 * @return string
	 */
	public function id() {
		return 'apibible';
	}

	/**
	 * Licensed network text goes through the shared cache.
	 *
	 * @return bool
	 */
	public function cacheable() {
		return true;
	}

	/**
	 * One passage: each part (segment) is fetched as plain text with verse
	 * numbers and split into verses. A verse shared by two parts is fetched once.
	 *
	 * @param array $version  Registry version entry.
	 * @param array $segments Parsed segments.
	 * @return array|WP_Error { segments: <verse lists>, meta: array }.
	 */
	public function fetch( array $version, array $segments ) {
		self::reset_diagnostics();
		self::$diagnostics['fetched'] = true;
		if ( ! jlife_scripture_apibible_client_available() ) {
			return new WP_Error( 'jlife_scripture_source_unavailable' );
		}
		$lists     = array();
		$results   = array();
		$tokens    = array();
		$copyright = '';
		$cacheable = true;
		$max_age   = null;
		foreach ( $segments as $segment ) {
			$passage_id = jlife_scripture_apibible_passage_id( $segment );
			if ( '' === $passage_id ) {
				return new WP_Error( 'jlife_scripture_not_found' );
			}
			if ( ! isset( $results[ $passage_id ] ) ) {
				$results[ $passage_id ] = jlife_challenges_apibible_fetch_passage(
					(string) $version['source_id'],
					$passage_id,
					array(
						'version' => (string) $version['key'],
						'format'  => 'text',
					)
				);
			}
			$result = $results[ $passage_id ];
			self::record( $result );
			if ( 'ok' !== $result['outcome'] ) {
				return new WP_Error( jlife_scripture_apibible_error_code( (string) $result['reason'] ) );
			}
			$verses = jlife_scripture_apibible_parse_verses( (string) $result['content'], $segment );
			if ( array() === $verses ) {
				return new WP_Error( 'jlife_scripture_not_found' );
			}
			$lists[] = $verses;
			if ( ! empty( $result['fums']['token'] ) ) {
				$tokens[] = (string) $result['fums']['token'];
			}
			if ( '' === $copyright ) {
				$copyright = (string) $result['copyright'];
			}
			$cacheable = $cacheable && ! empty( $result['cache']['cacheable'] );
			$age       = (int) $result['cache']['max_age'];
			$max_age   = null === $max_age ? $age : min( $max_age, $age );
		}
		return array(
			'segments' => $lists,
			'meta'     => array(
				'copyright' => $copyright,
				'fums'      => array_values( array_unique( $tokens ) ),
				'cacheable' => $cacheable,
				'max_age'   => (int) $max_age,
			),
		);
	}

	/**
	 * Report one display to FUMS (API.Bible's usage reporting), for a fetched
	 * passage and for one served from the cache alike. A call to the client that
	 * makes no API.Bible REST request: it counts as a FUMS report, not an API call.
	 *
	 * @param array $version  Registry version entry.
	 * @param array $segments Parsed segments.
	 * @param array $meta     The meta stored with the passage.
	 */
	public function track_display( array $version, array $segments, array $meta ) {
		unset( $version, $segments );
		$tokens = array_values( array_filter( (array) ( $meta['fums'] ?? array() ), 'is_string' ) );
		if ( array() !== $tokens && function_exists( 'jlife_challenges_apibible_fums_report' ) ) {
			jlife_challenges_apibible_fums_report( $tokens );
		}
	}

	/**
	 * Under the text: the notice the API returned, the Starter plan's visible
	 * link to API.Bible, and (when the site has one) the link to the full
	 * copyright information (terms 7.1 and 7.2).
	 *
	 * @param array  $version  Registry version entry.
	 * @param array  $meta     The meta stored with the passage.
	 * @param string $language The reader's language ("en" or "vi"): the copyright page is the one in that language.
	 * @return string
	 */
	public function attribution_html( array $version, array $meta, $language ) {
		$notice = trim( (string) ( $meta['copyright'] ?? '' ) );
		if ( '' === $notice ) {
			$notice = (string) $version['copyright']['text'];
		}
		$out  = '<p class="jls-version"><small><span class="jls-version-name">' . esc_html( (string) $version['name'] ) . '</span> · ' . esc_html( $notice )
			. ' · <a href="https://api.bible" target="_blank" rel="noopener">' . esc_html__( 'Scripture text provided by API.Bible', 'jlife-scripture' ) . '</a>';
		$page = jlife_scripture_copyright_page_url( $version, (string) $language );
		if ( '' !== $page ) {
			$out .= ' · <a href="' . esc_url( $page ) . '">' . esc_html__( 'Full copyright information', 'jlife-scripture' ) . '</a>';
		}
		return $out . '</small></p>';
	}
}
