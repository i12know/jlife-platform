<?php
/**
 * The API.Bible source for the native reader (#141 part 2): NLT, NKJV and CSB
 * through #147's client and #148's shared cache. Every request is answered by a
 * fake through pre_http_request (CI never calls the live API) and every Bible
 * text in it is made up. The three versions are enabled in the shipped
 * registry (owner smoke test 2026-11-19), so these tests use it as it is.
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
		delete_option( 'jlife_scripture_copyright_page_id' );
		unset( $GLOBALS['jlife_scripture_test_pll'] );
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
	 * Put NLT back to how it was before it was enabled: disabled, link only,
	 * no license evidence (to test what works on a version that is not enabled yet).
	 */
	private function disable_nlt() {
		$this->patch_version(
			'nlt',
			array(
				'display'          => 'link',
				'enabled'          => false,
				'license_evidence' => null,
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
	 * The three versions are enabled and native with their recorded bibleIds
	 * and the owner's license evidence, so they are renderable and selectable;
	 * the rights holders' sites are their copyright links; the defaults did not move.
	 */
	public function test_the_three_versions_are_enabled_native_and_selectable() {
		$expected = array(
			'nlt'  => array( 'd6e14a625393b4da-01', 'https://www.tyndale.com/' ),
			'nkjv' => array( '63097d2a0a2f7db3-01', 'https://www.thomasnelson.com/' ),
			'csb'  => array( 'a556c5305ee15c3f-01', 'https://csbible.com/' ),
		);
		foreach ( $expected as $key => $row ) {
			$v = jlife_scripture_get_version( $key );
			$this->assertSame( $row[0], $v['source_id'], $key );
			$this->assertSame( $row[1], $v['copyright']['url'], "{$key}: the rights holder's site" );
			$this->assertSame( 'apibible', $v['source'] );
			$this->assertTrue( $v['enabled'], "{$key} is enabled" );
			$this->assertSame( 'native', $v['display'] );
			$this->assertSame( array(), $v['default_for'], 'not a default for any language' );
			$this->assertStringContainsString( 'API.Bible Starter plan, app Jlife', (string) $v['license_evidence'] );
			$this->assertStringContainsString( 'wp jlife scripture try', (string) $v['license_evidence'] );
			$this->assertStringContainsString( '2026-11-19', (string) $v['license_evidence'] );
			$this->assertSame( 1209600, $v['cache']['max_age_seconds'], '14 days' );
			$this->assertSame( 499, $v['cache']['max_consecutive_verses'] );
			$this->assertTrue( jlife_scripture_can_render( $v ), "{$key} can render text" );
			$this->assertSame( $key, jlife_scripture_selectable_version( $key )['key'], 'offered in the picker' );
		}
		$this->assertSame( array( 'nlt', 'nkjv', 'csb', 'erv' ), array_keys( jlife_scripture_selectable_versions()['en'] ), 'English, in registry order' );
		$this->assertSame( 'nirv', jlife_scripture_default_version( 'en' )['key'], 'NIrV stays the English default' );
		$this->assertSame( 'kt1925', jlife_scripture_default_version( 'vi' )['key'] );
		$this->assertFalse( jlife_scripture_can_render( jlife_scripture_get_version( 'nirv' ) ), 'NIrV is still not renderable' );
		$this->assertInstanceOf( Jlife_Scripture_Apibible_Source::class, jlife_scripture_get_source( 'apibible' ) );
		$this->assertSame( 0, count( $this->requests ), 'nothing was fetched' );
	}

	/**
	 * Each of the three renders on the page for a participant who chose it, from
	 * its own bibleId, through the mocked API: text, the version's name and the
	 * API's notice, no Bible Gateway link.
	 */
	public function test_nlt_nkjv_and_csb_render_on_the_page_through_the_api() {
		$ids = array(
			'nlt'  => self::NLT_ID,
			'nkjv' => '63097d2a0a2f7db3-01',
			'csb'  => 'a556c5305ee15c3f-01',
		);
		foreach ( $ids as $key => $id ) {
			$user = $this->participant( 'en_US' );
			$this->assertTrue( jlife_scripture_set_user_choice( $user, array( 'primary' => $key ) ), $key );
			$html = jlife_scripture_render_day( $this->day( 'John 1:1-2' ), $user );
			$this->assertStringContainsString( '<sup class="jls-num">1</sup> FAKE-VERSE-1 for tests only.', $html, $key );
			$this->assertStringContainsString( '<span class="jls-version-name">' . esc_html( jlife_scripture_get_version( $key )['name'] ) . '</span>', $html );
			$this->assertStringContainsString( self::COPYRIGHT, $html );
			$this->assertStringNotContainsString( 'Open in Bible Gateway', $html );
			$last = array_slice( $this->passage_requests(), -1 )[0];
			$this->assertStringContainsString( '/bibles/' . $id . '/passages/JHN.1.1-JHN.1.2?', $last, "{$key} is fetched with its own bibleId" );
		}
		$this->assertSame( 3, jlife_challenges_apibible_usage()['calls'], 'one real call per version' );
		$this->assertSame( 3, count( $this->fums_requests() ), 'and each display reported' );
	}

	/**
	 * The source returns the passage as verses with numbers, plus the copyright
	 * the API sent and the FUMS token, and the page shows the text under the
	 * participant's chosen Bible with the notice and the required links.
	 */
	public function test_the_source_returns_text_copyright_and_shows_them() {
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
	 * so the link goes to the site's own page. The old URL option still works as
	 * a fallback (for one release), with the version as the anchor.
	 */
	public function test_the_copyright_page_url_option_is_still_a_fallback() {
		$user = $this->participant( 'en_US' );
		jlife_scripture_set_user_choice( $user, array( 'primary' => 'nlt' ) );
		$this->assertStringNotContainsString( 'Full copyright information', jlife_scripture_render_day( $this->day( 'John 1:1-2' ), $user ), 'nothing is set yet' );
		update_option( 'jlife_scripture_copyright_page_url', 'https://example.test/bible-copyrights/#old' );
		$html = jlife_scripture_render_day( $this->day( 'John 1:1-2' ), $user );
		$this->assertStringContainsString( '<a href="https://example.test/bible-copyrights/#nlt">Full copyright information</a>', $html );
		$this->assertStringNotContainsString( '#old', $html );
		update_option( 'jlife_scripture_copyright_page_url', 'javascript:alert(1)' );
		$this->assertStringNotContainsString( 'Full copyright information', jlife_scripture_render_day( $this->day( 'John 1:1-2' ), $user ), 'only a real link' );
	}

	/**
	 * The page ID option resolves to that page's permalink (with the version as
	 * the anchor) and wins over the URL option; a page that is not published
	 * falls back to the URL option. No Polylang is involved yet.
	 */
	public function test_the_copyright_page_id_resolves_to_the_pages_link() {
		$this->assertFalse( function_exists( 'pll_get_post' ), 'this test is about a site without Polylang' );
		$page = self::factory()->post->create(
			array(
				'post_type'   => 'page',
				'post_status' => 'publish',
				'post_title'  => 'Bible Copyrights',
			)
		);
		$user = $this->participant( 'vi' );
		jlife_scripture_set_user_choice( $user, array( 'primary' => 'nkjv' ) );
		update_option( 'jlife_scripture_copyright_page_id', $page );
		update_option( 'jlife_scripture_copyright_page_url', 'https://example.test/old-page/' );
		$html = jlife_scripture_render_day( $this->day( 'John 1:1-2' ), $user );
		$this->assertStringContainsString( '<a href="' . esc_url( get_permalink( $page ) . '#nkjv' ) . '">Full copyright information</a>', $html, 'the page ID wins; the anchor is the version' );
		$this->assertStringNotContainsString( 'old-page', $html );

		wp_update_post(
			array(
				'ID'          => $page,
				'post_status' => 'draft',
			)
		);
		$html = jlife_scripture_render_day( $this->day( 'John 1:1-2' ), $user );
		$this->assertStringContainsString( 'href="https://example.test/old-page/#nkjv"', $html, 'a draft page is not linked: the URL option answers' );
		update_option( 'jlife_scripture_copyright_page_id', 999999 );
		$this->assertStringContainsString( 'href="https://example.test/old-page/#nkjv"', jlife_scripture_render_day( $this->day( 'John 1:1-2' ), $user ), 'a missing page too' );
		delete_option( 'jlife_scripture_copyright_page_url' );
		$this->assertStringNotContainsString( 'Full copyright information', jlife_scripture_render_day( $this->day( 'John 1:1-2' ), $user ), 'then no link at all' );
	}

	/**
	 * With Polylang the link goes to the page translated into the reader's
	 * language (Tiếng Việt reader: the Vietnamese page, English reader: the
	 * English one), falling back to the original page when there is no published
	 * translation; the anchor is the version either way.
	 */
	public function test_the_copyright_link_follows_the_readers_language_with_polylang() {
		require_once __DIR__ . '/polylang-stub.php';
		delete_option( 'jlife_scripture_copyright_page_url' );
		$make = static fn( $title ) => self::factory()->post->create(
			array(
				'post_type'   => 'page',
				'post_status' => 'publish',
				'post_title'  => $title,
			)
		);
		$en   = $make( 'Bible Copyrights' );
		$vi   = $make( 'Bản quyền Kinh Thánh' );

		$GLOBALS['jlife_scripture_test_pll'] = array(
			$en => array(
				'en' => $en,
				'vi' => $vi,
			),
			$vi => array(
				'en' => $en,
				'vi' => $vi,
			),
		);
		update_option( 'jlife_scripture_copyright_page_id', $en );

		$en_reader = $this->participant( 'en_US' );
		$vi_reader = $this->participant( 'vi' );
		foreach ( array( $en_reader, $vi_reader ) as $reader ) {
			jlife_scripture_set_user_choice( $reader, array( 'primary' => 'nlt' ) );
		}
		$link    = static fn( $page, $key = 'nlt' ) => '<a href="' . esc_url( get_permalink( $page ) . '#' . $key ) . '">Full copyright information</a>';
		$this->assertStringContainsString( $link( $en ), jlife_scripture_render_day( $this->day( 'John 1:1-2' ), $en_reader ), 'an English reader: the English page' );
		$vi_html = jlife_scripture_render_day( $this->day( 'John 1:1-2' ), $vi_reader );
		$this->assertStringContainsString( $link( $vi ), $vi_html, 'a Vietnamese reader of an English Bible: the Vietnamese page' );
		$this->assertStringNotContainsString( get_permalink( $en ) . '#nlt', $vi_html );

		// By the page language (the public pages force it) and from either page's ID.
		$nlt = jlife_scripture_get_version( 'nlt' );
		$this->assertSame( get_permalink( $vi ) . '#nlt', jlife_scripture_copyright_page_url( $nlt, 'vi' ) );
		$this->assertSame( get_permalink( $en ) . '#nlt', jlife_scripture_copyright_page_url( $nlt, 'en' ) );
		update_option( 'jlife_scripture_copyright_page_id', $vi );
		$this->assertSame( get_permalink( $en ) . '#nlt', jlife_scripture_copyright_page_url( $nlt, 'en' ), 'set to the Vietnamese page, an English reader still gets the English one' );
		$this->assertSame( get_permalink( $vi ) . '#nlt', jlife_scripture_copyright_page_url( $nlt, '' ), 'no language: the page itself' );
		update_option( 'jlife_scripture_copyright_page_id', $en );

		// No translation, or one that is not published: the original page.
		$GLOBALS['jlife_scripture_test_pll'][ $en ]['vi'] = 0;
		$this->assertSame( get_permalink( $en ) . '#nlt', jlife_scripture_copyright_page_url( $nlt, 'vi' ), 'no Vietnamese page: the original' );
		$GLOBALS['jlife_scripture_test_pll'][ $en ]['vi'] = $vi;
		wp_update_post(
			array(
				'ID'          => $vi,
				'post_status' => 'draft',
			)
		);
		$this->assertSame( get_permalink( $en ) . '#nlt', jlife_scripture_copyright_page_url( $nlt, 'vi' ), 'an unpublished translation is not linked' );

		// The smoke test shows both languages' links.
		wp_update_post(
			array(
				'ID'          => $vi,
				'post_status' => 'publish',
			)
		);
		$out = implode( "\n", jlife_scripture_try_passage( 'nlt', 'John 1:1-2' )['lines'] );
		$this->assertStringContainsString( 'Copyright page link (en): ' . get_permalink( $en ) . '#nlt', $out );
		$this->assertStringContainsString( 'Copyright page link (vi): ' . get_permalink( $vi ) . '#nlt', $out );
		unset( $GLOBALS['jlife_scripture_test_pll'] );
	}

	/**
	 * The copyright page's shortcode is built from the Bible metadata, fetched
	 * once per version and kept for the cache rule; the page lists the enabled
	 * versions (or all with all="yes").
	 */
	public function test_the_copyright_page_shortcode_uses_cached_metadata() {
		foreach ( array( 'nkjv', 'csb' ) as $key ) {
			$this->patch_version(
				$key,
				array(
					'display'          => 'link',
					'enabled'          => false,
					'license_evidence' => null,
				)
			);
		}
		$html = do_shortcode( '[jlife_bible_copyrights]' );
		$this->assertStringContainsString( '<section class="jls-copyright" id="nlt">', $html );
		$this->assertStringContainsString( 'Fake Bible d6e1 (FAKE-d6e1)', $html, 'name and abbreviation from the metadata' );
		$this->assertStringContainsString( self::COPYRIGHT, $html );
		$this->assertStringContainsString( 'Fake info for tests only.', $html );
		$this->assertStringNotContainsString( 'id="nkjv"', $html, 'enabled versions only (the other two are disabled here)' );
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
		$this->disable_nlt();
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
		$this->assertStringContainsString( 'Copyright page link: not set (create a page with [jlife_bible_copyrights] and set the site option jlife_scripture_copyright_page_id', $out );
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
