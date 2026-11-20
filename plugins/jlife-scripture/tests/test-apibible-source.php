<?php
/**
 * The API.Bible source for the native reader (#141 part 2): NLT, NKJV and CSB
 * through #147's client and #148's shared cache. Every request is answered by a
 * fake through pre_http_request (CI never calls the live API) and every Bible
 * text in it is made up. The three versions stay disabled in the shipped
 * registry; tests enable NLT the way an owner's registry edit would.
 *
 * @package jlife-scripture
 */

/**
 * API.Bible source tests.
 */
class Test_Apibible_Source extends Jlife_Scripture_TestCase {

	/**
	 * A fake key; it must never appear anywhere but the api-key header.
	 */
	const KEY = 'fake-KEY-0123456789abcdef';

	/**
	 * Made-up verse text and notice (never real Scripture).
	 */
	const COPYRIGHT = 'FAKE COPYRIGHT NOTICE (c) 2026 Example Publisher.';
	const FUMS      = 'fakeFUMStoken123';

	/**
	 * The account's NLT bibleId (not a secret), as recorded in the registry.
	 */
	const NLT_ID = 'd6e14a625393b4da-01';

	/**
	 * Requests seen: { url, args }.
	 *
	 * @var array[]
	 */
	private $requests = array();

	/**
	 * What the fake answers: an HTTP status, or 0 for a network error.
	 *
	 * @var int
	 */
	private $status = 200;

	/**
	 * Override for the passage's data.content (null = made up from the passage ID).
	 *
	 * @var string|null
	 */
	private $content = null;

	/**
	 * Override for the passage's data.verseCount (null = the real count).
	 *
	 * @var int|null
	 */
	private $verse_count = null;

	/**
	 * A fake key and a fake network; empty counters and holds.
	 */
	public function set_up() {
		parent::set_up();
		if ( ! function_exists( 'jlife_challenges_apibible_fetch_passage' ) ) {
			$this->markTestSkipped( 'The API.Bible client (jlife-challenges) is not beside this plugin.' );
		}
		$this->requests    = array();
		$this->status      = 200;
		$this->content     = null;
		$this->verse_count = null;
		add_filter( 'jlife_challenges_apibible_key', array( $this, 'filter_key' ) );
		add_filter( 'pre_http_request', array( $this, 'fake_http' ), 10, 3 );
		delete_site_option( JLIFE_CHALLENGES_APIBIBLE_USAGE_OPTION );
		foreach ( array( 'nlt', 'nkjv', 'csb' ) as $key ) {
			$id = jlife_scripture_get_version( $key )['source_id'];
			delete_site_transient( jlife_challenges_apibible_hold_key( $id ) );
			delete_site_transient( 'jlife_scripture_apibible_info_' . md5( $id ) );
		}
	}

	/**
	 * Undo the fakes.
	 */
	public function tear_down() {
		remove_filter( 'jlife_challenges_apibible_key', array( $this, 'filter_key' ) );
		remove_filter( 'pre_http_request', array( $this, 'fake_http' ), 10 );
		delete_option( 'jlife_scripture_copyright_page_url' );
		parent::tear_down();
	}

	/**
	 * The fake key.
	 *
	 * @return string
	 */
	public function filter_key() {
		return self::KEY;
	}

	/**
	 * Answer every request without the network.
	 *
	 * @param mixed  $pre  Short-circuit value.
	 * @param array  $args Request args.
	 * @param string $url  Request URL.
	 * @return array|WP_Error
	 */
	public function fake_http( $pre, $args, $url ) {
		unset( $pre );
		$this->requests[] = array(
			'url'  => $url,
			'args' => $args,
		);
		if ( 0 === strpos( $url, JLIFE_CHALLENGES_APIBIBLE_FUMS ) ) {
			return $this->response( 200, '' );
		}
		if ( 0 === $this->status ) {
			return new WP_Error( 'http_request_failed', 'cURL error 28: Operation timed out' );
		}
		if ( 200 !== $this->status ) {
			return $this->response( $this->status, '{"statusCode":' . $this->status . ',"message":"error"}' );
		}
		if ( 1 === preg_match( '#/passages/([0-9A-Z.\-]+)\?#', $url, $m ) ) {
			$content = null === $this->content ? $this->made_up_content( $m[1] ) : $this->content;
			return $this->response(
				200,
				wp_json_encode(
					array(
						'data' => array(
							'id'         => $m[1],
							'content'    => $content,
							'reference'  => 'Fake reference',
							'verseCount' => null === $this->verse_count ? substr_count( $content, '[' ) : $this->verse_count,
							'copyright'  => self::COPYRIGHT,
						),
						'meta' => array( 'fumsToken' => self::FUMS ),
					)
				)
			);
		}
		if ( 1 === preg_match( '#/bibles/([A-Za-z0-9\-]+)$#', $url, $m ) ) {
			return $this->response(
				200,
				wp_json_encode(
					array(
						'data' => array(
							'id'           => $m[1],
							'abbreviation' => 'FAKE-' . substr( $m[1], 0, 4 ),
							'name'         => 'Fake Bible ' . substr( $m[1], 0, 4 ),
							'copyright'    => self::COPYRIGHT,
							'info'         => '<p>Fake info for tests only.</p>',
							'language'     => array( 'id' => 'eng' ),
						),
					)
				)
			);
		}
		return $this->response( 404, '{}' );
	}

	/**
	 * Text-format content for a same-chapter passage ID, like API.Bible's:
	 * leading spaces, "[n]" before each verse, made-up words.
	 *
	 * @param string $passage_id E.g. JHN.1.1-JHN.1.2 or LUK.3.23.
	 * @return string
	 */
	private function made_up_content( $passage_id ) {
		preg_match( '/^[0-9A-Z]{3}\.(\d+)\.(\d+)(?:-[0-9A-Z]{3}\.(\d+)\.(\d+))?$/', $passage_id, $m );
		$first = (int) ( $m[2] ?? 1 );
		$last  = (int) ( $m[4] ?? $first );
		$out   = '';
		for ( $v = $first; $v <= $last; $v++ ) {
			$out .= "\n     [{$v}] FAKE-VERSE-{$v} for tests only. ";
		}
		return $out;
	}

	/**
	 * A wp_remote_get()-shaped response.
	 *
	 * @param int    $code HTTP status.
	 * @param string $body Body.
	 * @return array
	 */
	private function response( $code, $body ) {
		return array(
			'headers'  => array(),
			'body'     => $body,
			'response' => array(
				'code'    => $code,
				'message' => '',
			),
			'cookies'  => array(),
			'filename' => null,
		);
	}

	/**
	 * Enable the NLT the way an owner's registry edit will once the gates clear.
	 */
	private function enable_nlt() {
		$this->patch_version(
			'nlt',
			array(
				'display'          => 'native',
				'enabled'          => true,
				'license_evidence' => 'Test only.',
			)
		);
	}

	/**
	 * The passage requests seen (not FUMS, not metadata).
	 *
	 * @return string[] URLs.
	 */
	private function passage_requests() {
		return array_values(
			array_filter(
				array_column( $this->requests, 'url' ),
				static fn( $url ) => false !== strpos( $url, '/passages/' )
			)
		);
	}

	/**
	 * The FUMS reports seen.
	 *
	 * @return string[] URLs.
	 */
	private function fums_requests() {
		return array_values(
			array_filter(
				array_column( $this->requests, 'url' ),
				static fn( $url ) => 0 === strpos( $url, JLIFE_CHALLENGES_APIBIBLE_FUMS )
			)
		);
	}

	/**
	 * Cached rows holding text.
	 *
	 * @return int
	 */
	private function stored_rows() {
		global $wpdb;
		return (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . jlife_scripture_table( 'cache' ) . ' WHERE body IS NOT NULL' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.NotPrepared -- test: the plugin's own table.
	}

	/**
	 * The three bibleIds are recorded as source_id, and the versions are not
	 * renderable yet: disabled, link display, no license evidence.
	 */
	public function test_ids_are_recorded_but_the_versions_cannot_render_yet() {
		$ids = array(
			'nlt'  => 'd6e14a625393b4da-01',
			'nkjv' => '63097d2a0a2f7db3-01',
			'csb'  => 'a556c5305ee15c3f-01',
		);
		foreach ( $ids as $key => $id ) {
			$v = jlife_scripture_get_version( $key );
			$this->assertSame( $id, $v['source_id'], $key );
			$this->assertSame( 'apibible', $v['source'] );
			$this->assertFalse( $v['enabled'], "{$key} stays disabled" );
			$this->assertSame( 'link', $v['display'] );
			$this->assertNull( $v['license_evidence'] );
			$this->assertFalse( jlife_scripture_can_render( $v ), "{$key} cannot render text" );
			$this->assertNull( jlife_scripture_selectable_version( $key ), 'not offered in the picker' );
		}
		$this->assertInstanceOf( Jlife_Scripture_Apibible_Source::class, jlife_scripture_get_source( 'apibible' ) );
		$this->assertSame( 0, count( $this->requests ), 'nothing was fetched' );
	}

	/**
	 * The source returns the passage as verses with numbers, plus the copyright
	 * the API sent and the FUMS token, and the page shows the text under the
	 * participant's chosen Bible with the notice and the required links.
	 */
	public function test_the_source_returns_text_copyright_and_shows_them() {
		$this->enable_nlt();
		$segments = jlife_scripture_parse_reference( 'John 1:1-2' );
		$entry    = jlife_scripture_get_passage_entry( jlife_scripture_get_version( 'nlt' ), $segments );
		$this->assertIsArray( $entry );
		$this->assertSame(
			array(
				array(
					'chapter' => 1,
					'verse'   => 1,
					'text'    => 'FAKE-VERSE-1 for tests only.',
				),
				array(
					'chapter' => 1,
					'verse'   => 2,
					'text'    => 'FAKE-VERSE-2 for tests only.',
				),
			),
			$entry['segments'][0]
		);
		$this->assertSame( self::COPYRIGHT, $entry['meta']['copyright'] );
		$this->assertSame( array( self::FUMS ), $entry['meta']['fums'] );
		$this->assertSame( 14 * DAY_IN_SECONDS, $entry['meta']['max_age'], 'the cache rule rides with the entry' );

		$user = $this->participant( 'en_US' );
		jlife_scripture_set_user_choice( $user, array( 'primary' => 'nlt' ) );
		$html = jlife_scripture_render_day( $this->day( 'John 1:1-2' ), $user );
		$this->assertStringContainsString( '<sup class="jls-num">1</sup> FAKE-VERSE-1 for tests only.', $html );
		$this->assertStringContainsString( '<sup class="jls-num">2</sup> FAKE-VERSE-2 for tests only.', $html );
		$this->assertStringContainsString( '<span class="jls-version-name">New Living Translation</span>', $html );
		$this->assertStringContainsString( self::COPYRIGHT, $html, 'the notice the API returned' );
		$this->assertStringContainsString( '<a href="https://api.bible" target="_blank" rel="noopener">Scripture text provided by API.Bible</a>', $html, 'the Starter plan link' );
		$this->assertStringNotContainsString( 'Full copyright information', $html, 'no copyright page is set yet' );
		$this->assertStringNotContainsString( self::KEY, $html );
		$this->assertStringNotContainsString( 'must replace this line', $html, 'the registry placeholder is replaced by the API notice' );
	}

	/**
	 * The request carries the key only in the api-key header, asks for plain
	 * text with verse numbers and no titles or notes.
	 */
	public function test_the_request_has_the_key_only_in_the_header() {
		$this->enable_nlt();
		jlife_scripture_get_passage_entry( jlife_scripture_get_version( 'nlt' ), jlife_scripture_parse_reference( 'John 1:1-2' ) );
		$urls = $this->passage_requests();
		$this->assertCount( 1, $urls );
		$this->assertStringStartsWith( JLIFE_CHALLENGES_APIBIBLE_BASE . '/bibles/' . self::NLT_ID . '/passages/JHN.1.1-JHN.1.2?', $urls[0] );
		$this->assertStringContainsString( 'content-type=text', $urls[0] );
		$this->assertStringContainsString( 'include-verse-numbers=true', $urls[0] );
		$this->assertStringContainsString( 'include-titles=false', $urls[0] );
		$this->assertStringNotContainsString( self::KEY, $urls[0] );
		$this->assertSame( self::KEY, $this->requests[0]['args']['headers']['api-key'] );
	}

	/**
	 * A cache hit makes no API call and does not move the monthly counter, but
	 * every display still reports to FUMS with the stored token.
	 */
	public function test_a_cache_hit_sends_fums_and_spends_no_call() {
		$this->enable_nlt();
		$user = $this->participant( 'en_US' );
		jlife_scripture_set_user_choice( $user, array( 'primary' => 'nlt' ) );
		$day = $this->day( 'John 1:1-2' );

		jlife_scripture_render_day( $day, $user );
		$this->assertCount( 1, $this->passage_requests() );
		$this->assertSame( 1, jlife_challenges_apibible_usage()['calls'] );
		$this->assertCount( 1, $this->fums_requests(), 'the first display reports' );
		$this->assertSame( 1, $this->stored_rows() );

		foreach ( array( 2, 3 ) as $n ) {
			$html = jlife_scripture_render_day( $day, $user );
			$this->assertStringContainsString( 'FAKE-VERSE-1', $html, "view {$n} from the cache" );
		}
		$this->assertCount( 1, $this->passage_requests(), 'no more API requests' );
		$this->assertSame( 1, jlife_challenges_apibible_usage()['calls'], 'the monthly counter did not move' );
		$fums = $this->fums_requests();
		$this->assertCount( 3, $fums, 'every display, cached or not, reports' );
		foreach ( $fums as $url ) {
			$this->assertStringContainsString( 't=' . self::FUMS, $url );
			$this->assertStringContainsString( 'dId=', $url );
			$this->assertStringContainsString( 'sId=', $url );
			$this->assertStringNotContainsString( 'uId=', $url, 'no user ID' );
			$this->assertStringNotContainsString( self::KEY, $url );
		}
		$this->assertSame( 3, jlife_challenges_apibible_usage()['fums'], 'FUMS is counted apart from the API calls' );
	}

	/**
	 * What is stored never outlives the cache rule: a row older than 14 days is
	 * not served and is fetched again.
	 */
	public function test_the_cache_honours_the_fourteen_day_rule() {
		$this->enable_nlt();
		$segments = jlife_scripture_parse_reference( 'John 1:1-2' );
		$version  = jlife_scripture_get_version( 'nlt' );

		$this->now = 1800000000;
		jlife_scripture_get_passage_entry( $version, $segments );
		$this->now = 1800000000 + 14 * DAY_IN_SECONDS - 5;
		jlife_scripture_get_passage_entry( $version, $segments );
		$this->assertCount( 1, $this->passage_requests(), 'still fresh just inside 14 days' );
		$this->now = 1800000000 + 14 * DAY_IN_SECONDS + 5;
		jlife_scripture_get_passage_entry( $version, $segments );
		$this->assertCount( 2, $this->passage_requests(), 'past 14 days it is fetched again' );
	}

	/**
	 * 401 and 403 (the version is not licensed to the account) fall back to the
	 * Bible Gateway link, store nothing, and hold the Bible so a busy page does
	 * not keep calling.
	 */
	public function test_not_licensed_falls_back_to_the_link_and_stores_nothing() {
		$this->enable_nlt();
		$user = $this->participant( 'en_US' );
		jlife_scripture_set_user_choice( $user, array( 'primary' => 'nlt' ) );
		foreach ( array( 401, 403 ) as $status ) {
			delete_site_transient( jlife_challenges_apibible_hold_key( self::NLT_ID ) );
			$this->status = $status;
			$before       = count( $this->passage_requests() );
			$html         = jlife_scripture_render_day( $this->day( 'John 1:1-2' ), $user );
			$this->assertStringContainsString( 'The Bible text could not be loaded.', $html, (string) $status );
			$this->assertStringContainsString( 'Open in Bible Gateway (ERV)', $html );
			$this->assertStringNotContainsString( 'jls-text', $html );
			$this->assertSame( $before + 1, count( $this->passage_requests() ) );
		}
		$this->assertSame( 0, $this->stored_rows(), 'a failure is never cached' );
		$this->assertCount( 0, $this->fums_requests(), 'nothing was shown, so nothing is reported' );
		$count = count( $this->passage_requests() );
		jlife_scripture_render_day( $this->day( 'John 1:1-2' ), $user );
		$this->assertCount( $count, $this->passage_requests(), 'the held Bible is not asked again' );
		$log = $this->logged();
		$this->assertStringContainsString( 'J-Life API.Bible: version=nlt outcome=not_licensed status=403', $log );
		$this->assertStringContainsString( 'jlife-scripture: fetch failed: source=apibible version=nlt passage=JHN.1.1-JHN.1.2 code=forbidden', $log );
		$this->assertStringNotContainsString( self::KEY, $log );
	}

	/**
	 * 429, 5xx and network errors fall back to the link too, with their own
	 * short codes in the log, and nothing is stored.
	 */
	public function test_rate_limits_and_outages_fall_back_to_the_link() {
		$this->enable_nlt();
		$version  = jlife_scripture_get_version( 'nlt' );
		$segments = jlife_scripture_parse_reference( 'John 1:1-2' );
		$cases    = array(
			429 => 'rate_limited',
			500 => 'server_error',
			503 => 'server_error',
			0   => 'server_error',
		);
		foreach ( $cases as $status => $code ) {
			delete_site_transient( jlife_challenges_apibible_hold_key( self::NLT_ID ) );
			$this->status = $status;
			$r            = jlife_scripture_get_passage_entry( $version, $segments );
			$this->assertWPError( $r, (string) $status );
			$this->assertSame( 'The Bible text could not be loaded.', $r->get_error_message(), 'generic: no detail from the API' );
		}
		$this->assertSame( 0, $this->stored_rows() );
		$log = $this->logged();
		$this->assertStringContainsString( 'code=rate_limited', $log );
		$this->assertStringContainsString( 'code=server_error', $log );
		$this->assertStringNotContainsString( 'timed out', $log );
		$this->assertStringNotContainsString( self::KEY, $log );
	}

	/**
	 * A passage over the version's limit (499 consecutive verses) is never
	 * fetched; a response the API itself flags as over the limit is shown once
	 * but never stored.
	 */
	public function test_the_consecutive_verse_limit_is_respected() {
		$this->enable_nlt();
		$version = jlife_scripture_get_version( 'nlt' );
		$this->assertSame( 499, $version['cache']['max_consecutive_verses'] );
		$r = jlife_scripture_get_passage_entry( $version, jlife_scripture_parse_reference( 'Matthew 1:1-18:35' ) );
		$this->assertWPError( $r, 'Matthew 1:1-18:35 is 618 verses' );
		$this->assertCount( 0, $this->requests, 'asked of the source never' );

		$this->verse_count = 500; // The API says the passage is over the limit.
		$segments          = jlife_scripture_parse_reference( 'John 1:1-2' );
		$first             = jlife_scripture_get_passage_entry( $version, $segments );
		$this->assertIsArray( $first );
		$this->assertFalse( $first['meta']['cacheable'] );
		$this->assertSame( 0, $this->stored_rows(), 'not stored' );
		jlife_scripture_get_passage_entry( $version, $segments );
		$this->assertCount( 2, $this->passage_requests(), 'so it is fetched again' );
	}

	/**
	 * A partial verse (Luke 3:23a) fetches the whole verse, like the other
	 * sources; a verse shared by two parts of a reference is fetched once and
	 * shown once.
	 */
	public function test_partial_verses_fetch_the_whole_verse() {
		$this->enable_nlt();
		$version = jlife_scripture_get_version( 'nlt' );
		$entry   = jlife_scripture_get_passage_entry( $version, jlife_scripture_parse_reference( 'Luke 3:23a' ) );
		$this->assertIsArray( $entry );
		$this->assertStringContainsString( '/passages/LUK.3.23?', $this->passage_requests()[0] );
		$this->assertSame( 23, $entry['segments'][0][0]['verse'] );

		$this->requests = array();
		$entry          = jlife_scripture_get_passage_entry( $version, jlife_scripture_parse_reference( 'Luke 24:10b, d' ) );
		$this->assertIsArray( $entry );
		$this->assertCount( 1, $this->passage_requests(), 'one request for the shared verse' );
		$this->assertCount( 2, $entry['segments'], 'a list per part, as the reader expects' );
		$user = $this->participant( 'en_US' );
		jlife_scripture_set_user_choice( $user, array( 'primary' => 'nlt' ) );
		$html = jlife_scripture_render_day( $this->day( 'Luke 24:10b, d' ), $user );
		$this->assertSame( 1, substr_count( $html, '<sup class="jls-num">10</sup>' ), 'shown once' );
	}

	/**
	 * A passage that crosses a chapter: verse numbers that start over mark the
	 * next chapter, which the reader numbers chapter:verse.
	 */
	public function test_a_passage_across_chapters_numbers_the_next_chapter() {
		$this->enable_nlt();
		$this->content = "\n [35] FAKE-VERSE-35 for tests only. [36] FAKE-VERSE-36 for tests only. \n [1] FAKE-VERSE-1 for tests only. [2] FAKE-VERSE-2 for tests only.";
		$entry         = jlife_scripture_get_passage_entry( jlife_scripture_get_version( 'nlt' ), jlife_scripture_parse_reference( 'John 3:35-4:2' ) );
		$this->assertIsArray( $entry );
		$this->assertStringContainsString( '/passages/JHN.3.35-JHN.4.2?', $this->passage_requests()[0] );
		$chapters = array_map( static fn( $v ) => $v['chapter'] . ':' . $v['verse'], $entry['segments'][0] );
		$this->assertSame( array( '3:35', '3:36', '4:1', '4:2' ), $chapters );
	}

	/**
	 * The text parser on its own: bridged markers, no markers for a single
	 * verse, junk before the first marker, odd spacing, and an answer with no
	 * verses at all.
	 */
	public function test_the_text_parser() {
		$one = jlife_scripture_parse_reference( 'John 1:5' )[0];
		$two = jlife_scripture_parse_reference( 'John 1:16-18' )[0];

		$verses = jlife_scripture_apibible_parse_verses( "\n   [16-17]  FAKE   one\n two.  [18] FAKE three.", $two );
		$this->assertSame( array( '1:16', '1:18' ), array_map( static fn( $v ) => $v['chapter'] . ':' . $v['verse'], $verses ) );
		$this->assertSame( 'FAKE one two.', $verses[0]['text'], 'whitespace collapsed' );

		$verses = jlife_scripture_apibible_parse_verses( '  FAKE no marker at all. ', $one );
		$this->assertSame( array( '1:5' ), array_map( static fn( $v ) => $v['chapter'] . ':' . $v['verse'], $verses ), 'a single verse needs no marker' );
		$this->assertSame( array(), jlife_scripture_apibible_parse_verses( 'FAKE no marker at all.', $two ), 'a range with no markers cannot be split' );
		$this->assertSame( array(), jlife_scripture_apibible_parse_verses( '   ', $one ) );
		$verses = jlife_scripture_apibible_parse_verses( 'junk [1] FAKE a. [2] [3] FAKE c.', jlife_scripture_parse_reference( 'John 1:1-3' )[0] );
		$this->assertSame( array( 1, 3 ), array_column( $verses, 'verse' ), 'an empty verse is left out; text before the first marker is dropped' );
	}

	/**
	 * Passage IDs come from the parsed segments through #147's helper.
	 */
	public function test_passage_ids_from_segments() {
		$cases = array(
			'John 1:29-34'  => 'JHN.1.29-JHN.1.34',
			'Luke 3:23a'    => 'LUK.3.23',
			'John 3:22-4:3' => 'JHN.3.22-JHN.4.3',
			'Psalm 23'      => 'PSA.23',
		);
		foreach ( $cases as $reference => $id ) {
			$segments = jlife_scripture_parse_reference( $reference );
			$this->assertSame( $id, jlife_scripture_apibible_passage_id( $segments[0] ), $reference );
		}
	}

	/**
	 * An answer that is not what the source needs (no content, no verse
	 * markers for a range) falls back to the link without a stored row.
	 */
	public function test_a_malformed_answer_falls_back() {
		$this->enable_nlt();
		$version  = jlife_scripture_get_version( 'nlt' );
		$segments = jlife_scripture_parse_reference( 'John 1:1-2' );

		$this->content = 'FAKE text without any verse numbers.';
		$this->assertWPError( jlife_scripture_get_passage_entry( $version, $segments ) );
		$this->content = '';
		$this->assertWPError( jlife_scripture_get_passage_entry( $version, $segments ) );
		$this->assertSame( 0, $this->stored_rows() );
		$this->assertStringNotContainsString( 'FAKE text', $this->logged(), 'no Bible text in the log' );
	}

	/**
	 * The full copyright information (terms 7.1/7.2): the metadata carries no URL,
	 * so the link goes to the site's own copyright page when the owner sets one,
	 * with the version as the anchor.
	 */
	public function test_the_copyright_page_link() {
		$this->enable_nlt();
		$user = $this->participant( 'en_US' );
		jlife_scripture_set_user_choice( $user, array( 'primary' => 'nlt' ) );
		update_option( 'jlife_scripture_copyright_page_url', 'https://example.test/bible-copyrights/#old' );
		$html = jlife_scripture_render_day( $this->day( 'John 1:1-2' ), $user );
		$this->assertStringContainsString( '<a href="https://example.test/bible-copyrights/#nlt">Full copyright information</a>', $html );
		$this->assertStringNotContainsString( '#old', $html );
		update_option( 'jlife_scripture_copyright_page_url', 'javascript:alert(1)' );
		$this->assertStringNotContainsString( 'Full copyright information', jlife_scripture_render_day( $this->day( 'John 1:1-2' ), $user ), 'only a real link' );
	}

	/**
	 * The copyright page's shortcode is built from the Bible metadata, fetched
	 * once per version and kept for the cache rule; the page lists the enabled
	 * versions (or all with all="yes").
	 */
	public function test_the_copyright_page_shortcode_uses_cached_metadata() {
		$this->enable_nlt();
		$html = do_shortcode( '[jlife_bible_copyrights]' );
		$this->assertStringContainsString( '<section class="jls-copyright" id="nlt">', $html );
		$this->assertStringContainsString( 'Fake Bible d6e1 (FAKE-d6e1)', $html, 'name and abbreviation from the metadata' );
		$this->assertStringContainsString( self::COPYRIGHT, $html );
		$this->assertStringContainsString( 'Fake info for tests only.', $html );
		$this->assertStringNotContainsString( 'id="nkjv"', $html, 'enabled versions only' );
		$this->assertCount( 1, $this->requests );
		$this->assertStringEndsWith( '/bibles/' . self::NLT_ID, $this->requests[0]['url'] );

		do_shortcode( '[jlife_bible_copyrights]' );
		$this->assertCount( 1, $this->requests, 'cached: no second call' );
		$this->assertSame( 1, jlife_challenges_apibible_usage()['calls'] );

		$all = do_shortcode( '[jlife_bible_copyrights all="yes"]' );
		foreach ( array( 'nlt', 'nkjv', 'csb' ) as $key ) {
			$this->assertStringContainsString( 'id="' . $key . '"', $all );
		}
		$this->assertCount( 3, $this->requests, 'one call per version that was not cached' );
		$this->assertStringNotContainsString( self::KEY, $all );

		// A failure leaves the section with the registry's own notice and no detail.
		delete_site_transient( 'jlife_scripture_apibible_info_' . md5( self::NLT_ID ) );
		$this->status = 403;
		$html         = do_shortcode( '[jlife_bible_copyrights]' );
		$this->assertStringContainsString( 'id="nlt"', $html );
		$this->assertStringNotContainsString( self::COPYRIGHT, $html );
	}

	/**
	 * `wp jlife scripture try` works on a version that is still disabled (the
	 * override lives only inside the call), prints the text, copyright, cache
	 * status and HTTP status, counts one API call, and never prints the key.
	 */
	public function test_try_works_on_a_disabled_version_and_never_prints_the_key() {
		$version = jlife_scripture_get_version( 'nlt' );
		$this->assertFalse( jlife_scripture_can_render( $version ) );
		$r = jlife_scripture_try_passage( 'nlt', 'John 1:1-2' );
		$this->assertIsArray( $r );
		$this->assertTrue( $r['ok'] );
		$this->assertSame( 1, $r['calls'], 'one API call' );
		$out = implode( "\n", $r['lines'] );
		$this->assertStringContainsString( 'FAKE-VERSE-1 for tests only.', $out );
		$this->assertStringContainsString( 'Copyright: ' . self::COPYRIGHT, $out );
		$this->assertStringContainsString( 'Cache: miss', $out );
		$this->assertStringContainsString( 'HTTP status: 200', $out );
		$this->assertStringContainsString( 'JHN.1.1-JHN.1.2', $out );
		$this->assertStringContainsString( 'this month: 1 of 5000', $out );
		$this->assertStringContainsString( 'Copyright page link: not set', $out );
		$this->assertStringNotContainsString( self::KEY, $out );
		$this->assertStringNotContainsString( self::KEY, (string) wp_json_encode( $r ) );
		$this->assertFalse( jlife_scripture_can_render( jlife_scripture_get_version( 'nlt' ) ), 'the version is still disabled afterwards' );
		$this->assertNull( jlife_scripture_selectable_version( 'nlt' ) );

		$again = jlife_scripture_try_passage( 'nlt', 'John 1:1-2' );
		$this->assertSame( 0, $again['calls'], 'a cache hit spends no call' );
		$this->assertStringContainsString( 'Cache: hit', implode( "\n", $again['lines'] ) );
		$this->assertStringContainsString( 'HTTP status: none (no request was made)', implode( "\n", $again['lines'] ) );

		$meta = jlife_scripture_try_passage( 'csb', 'John 1:1-2', array( 'metadata' => true ) );
		$this->assertSame( 2, $meta['calls'], 'the metadata check is one more call, and says so' );
		$this->assertStringContainsString( 'metadata', implode( "\n", $meta['lines'] ) );
		$this->assertStringNotContainsString( self::KEY, implode( "\n", $meta['lines'] ) );
	}

	/**
	 * `try` says what went wrong in words, with the HTTP status, and refuses
	 * what it cannot try (an unknown version, a link-only one, a bad reference,
	 * a passage over the limit) without calling the API.
	 */
	public function test_try_reports_failures_and_refuses_bad_input() {
		$this->status = 403;
		$r            = jlife_scripture_try_passage( 'nkjv', 'John 1:1-2' );
		$this->assertFalse( $r['ok'] );
		$out = implode( "\n", $r['lines'] );
		$this->assertStringContainsString( 'HTTP status: 403', $out );
		$this->assertStringContainsString( 'Result: failed', $out );
		$this->assertStringNotContainsString( self::KEY, $out );
		$this->assertStringNotContainsString( 'FAKE-VERSE', $out );

		$before = count( $this->requests );
		foreach ( array(
			array( 'nope', 'John 1:1-2' ),
			array( 'erv', 'John 1:1-2' ),
			array( 'nlt', 'Not a reference' ),
			array( 'nlt', 'Matthew 1:1-18:35' ),
		) as $args ) {
			$this->assertWPError( jlife_scripture_try_passage( $args[0], $args[1] ), implode( ' / ', $args ) );
		}
		$this->assertCount( $before, $this->requests, 'none of those called the API' );
	}

	/**
	 * Nothing in a log line holds Bible text or the key, across the paths above.
	 */
	public function test_no_bible_text_or_key_in_logs() {
		$this->enable_nlt();
		$user = $this->participant( 'en_US' );
		jlife_scripture_set_user_choice( $user, array( 'primary' => 'nlt' ) );
		jlife_scripture_render_day( $this->day( 'John 1:1-2' ), $user );
		jlife_scripture_render_day( $this->day( 'John 1:1-2' ), $user );
		jlife_scripture_try_passage( 'csb', 'John 1:1-2' );
		$this->status = 500;
		jlife_scripture_render_day( $this->day( 'John 1:3-4' ), $user );
		$log = $this->logged();
		$this->assertStringNotContainsString( 'FAKE-VERSE', $log );
		$this->assertStringNotContainsString( self::COPYRIGHT, $log );
		$this->assertStringNotContainsString( self::KEY, $log );
		$this->assertStringNotContainsString( self::FUMS, $log );
	}
}
