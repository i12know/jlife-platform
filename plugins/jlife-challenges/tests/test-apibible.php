<?php
/**
 * API.Bible source (#141): copyright and FUMS captured, 401/403/429/5xx and
 * outages fall back to the link, the key never reaches a URL, a log line, an
 * error or a result, and the usage counter counts real HTTP calls only. Every
 * request is answered by a fake through pre_http_request; CI never calls the
 * live API.
 *
 * @package jlife-challenges
 */

/**
 * API.Bible tests.
 */
class Test_Apibible extends WP_UnitTestCase {

	/**
	 * A fake key; it must never appear anywhere but the api-key header.
	 */
	const KEY = 'fake-KEY-0123456789abcdef';

	/**
	 * A made-up Bible ID in API.Bible's format.
	 */
	const BIBLE = 'aaaabbbbccccdddd-01';

	/**
	 * Fake passage text: must never be logged.
	 */
	const TEXT = 'FAKE-VERSE-TEXT for tests only';

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
	 * Temporary error log.
	 *
	 * @var string
	 */
	private $log = '';

	/**
	 * Previous error_log setting.
	 *
	 * @var string|false
	 */
	private $old_log = false;

	/**
	 * Fake key, fake HTTP, private error log, empty counters.
	 */
	public function set_up() {
		parent::set_up();
		$this->requests = array();
		$this->status   = 200;
		add_filter( 'jlife_challenges_apibible_key', array( $this, 'filter_key' ) );
		add_filter( 'pre_http_request', array( $this, 'fake_http' ), 10, 3 );
		delete_site_option( JLIFE_CHALLENGES_APIBIBLE_USAGE_OPTION );
		delete_site_transient( jlife_challenges_apibible_hold_key( self::BIBLE ) );
		$this->log     = (string) wp_tempnam( 'jlife-apibible-log' );
		$this->old_log = ini_get( 'error_log' );
		ini_set( 'error_log', $this->log ); // phpcs:ignore WordPress.PHP.IniSet.Risky -- capture this test's log lines.
	}

	/**
	 * Undo the fakes.
	 */
	public function tear_down() {
		remove_filter( 'jlife_challenges_apibible_key', array( $this, 'filter_key' ) );
		remove_filter( 'pre_http_request', array( $this, 'fake_http' ), 10 );
		ini_set( 'error_log', (string) $this->old_log ); // phpcs:ignore WordPress.PHP.IniSet.Risky -- restore.
		wp_delete_file( $this->log );
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
		if ( false !== strpos( $url, '/passages/' ) ) {
			return $this->response(
				200,
				wp_json_encode(
					array(
						'data' => array(
							'id'         => 'JHN.1.29-JHN.1.34',
							'bibleId'    => self::BIBLE,
							'content'    => '<p class="p"><span data-number="29" class="v">29</span>' . self::TEXT . '<script>alert(1)</script></p>',
							'reference'  => 'John 1:29-34',
							'verseCount' => 6,
							'copyright'  => 'Fake Bible © 2026 Example Publisher. Used by permission.',
						),
						'meta' => array( 'fumsToken' => 'fumsTOKEN123' ),
					)
				)
			);
		}
		if ( false !== strpos( $url, '/bibles/' . self::BIBLE ) ) {
			return $this->response(
				200,
				wp_json_encode(
					array(
						'data' => array(
							'id'           => self::BIBLE,
							'abbreviation' => 'NLT',
							'name'         => 'Fake Living Translation',
							'copyright'    => 'Fake Bible © 2026 Example Publisher. Used by permission.',
							'info'         => '<p>Fake info for tests only.</p>',
							'language'     => array( 'id' => 'eng' ),
						),
					)
				)
			);
		}
		return $this->response(
			200,
			wp_json_encode(
				array(
					'data' => array(
						array(
							'id'           => self::BIBLE,
							'abbreviation' => 'NLT',
							'name'         => 'Fake Living Translation',
							'language'     => array( 'id' => 'eng' ),
						),
						array(
							'id'           => 'eeeeffff00001111-01',
							'abbreviation' => 'WEB',
							'name'         => 'Fake Open Bible',
							'language'     => array( 'id' => 'eng' ),
						),
					),
				)
			)
		);
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
	 * Fetch John 1:29-34 from the fake Bible.
	 *
	 * @param string $format html|text.
	 * @return array
	 */
	private function fetch( $format = 'html' ) {
		return jlife_challenges_apibible_fetch_passage(
			self::BIBLE,
			'JHN.1.29-JHN.1.34',
			array(
				'version' => 'nlt',
				'format'  => $format,
			)
		);
	}

	/**
	 * The captured log.
	 *
	 * @return string
	 */
	private function log_text() {
		return (string) file_get_contents( $this->log ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- local temp file.
	}

	/**
	 * A passage comes back sanitized, with the returned copyright, the FUMS token
	 * and a cache rule; the key is only in the api-key header.
	 */
	public function test_a_passage_returns_text_copyright_and_fums() {
		$r = $this->fetch();
		$this->assertSame( 'ok', $r['outcome'] );
		$this->assertStringContainsString( self::TEXT, $r['content'] );
		$this->assertStringContainsString( 'data-number="29"', $r['content'], 'verse numbers kept' );
		$this->assertStringNotContainsString( '<script', $r['content'] );
		$this->assertSame( 'Fake Bible © 2026 Example Publisher. Used by permission.', $r['copyright'] );
		$this->assertSame( 'John 1:29-34', $r['reference'] );
		$this->assertSame(
			array(
				'version' => 3,
				'token'   => 'fumsTOKEN123',
			),
			$r['fums']
		);
		$this->assertTrue( $r['cache']['cacheable'] );
		$this->assertSame( 14 * DAY_IN_SECONDS, $r['cache']['max_age'] );
		$this->assertSame( 499, $r['cache']['max_consecutive_verses'] );

		$this->assertCount( 1, $this->requests );
		$req = $this->requests[0];
		$this->assertStringStartsWith( 'https://rest.api.bible/v1/bibles/' . self::BIBLE . '/passages/JHN.1.29-JHN.1.34?', $req['url'] );
		$this->assertStringContainsString( 'content-type=html', $req['url'] );
		$this->assertStringContainsString( 'include-verse-numbers=true', $req['url'] );
		$this->assertStringNotContainsString( self::KEY, $req['url'] );
		$this->assertSame( self::KEY, $req['args']['headers']['api-key'] );
		$this->assertStringNotContainsString( self::KEY, wp_json_encode( $r ) );
	}

	/**
	 * Text format asks for plain text with verse numbers.
	 */
	public function test_text_format() {
		$r = $this->fetch( 'text' );
		$this->assertSame( 'text', $r['format'] );
		$this->assertStringContainsString( 'content-type=text', $this->requests[0]['url'] );
	}

	/**
	 * 401 and 403 make the version link-only; 429, 5xx and network errors fall
	 * back to the link. Each logs the version and status only, never the key or text.
	 */
	public function test_errors_fall_back_and_log_only_status_and_version() {
		$cases = array(
			401 => 'link_only',
			403 => 'link_only',
			429 => 'fallback',
			500 => 'fallback',
			503 => 'fallback',
			0   => 'fallback',
		);
		foreach ( $cases as $status => $outcome ) {
			delete_site_transient( jlife_challenges_apibible_hold_key( self::BIBLE ) );
			$this->status = $status;
			$r            = $this->fetch();
			$this->assertSame( $outcome, $r['outcome'], "status {$status}" );
			$this->assertSame( '', $r['content'] );
			$this->assertSame( '', jlife_challenges_apibible_render( $r ), 'nothing rendered: the caller shows the link' );
			$this->assertStringNotContainsString( self::KEY, wp_json_encode( $r ) );
		}
		$log = $this->log_text();
		$this->assertStringContainsString( 'version=nlt outcome=not_licensed status=403', $log );
		$this->assertStringContainsString( 'version=nlt outcome=rate_limited status=429', $log );
		$this->assertStringContainsString( 'version=nlt outcome=unavailable status=503', $log );
		$this->assertStringContainsString( 'version=nlt outcome=unavailable status=0', $log );
		$this->assertStringNotContainsString( self::KEY, $log );
		$this->assertStringNotContainsString( 'FAKE-VERSE', $log );
		$this->assertStringNotContainsString( 'JHN', $log );
	}

	/**
	 * After a 429 the Bible is held: the next view makes no call and spends no quota.
	 */
	public function test_a_failure_holds_the_bible_without_more_calls() {
		$this->status = 429;
		$this->fetch();
		$this->status = 200;
		$r            = $this->fetch();
		$this->assertSame( 'fallback', $r['outcome'] );
		$this->assertSame( 'held', $r['reason'] );
		$this->assertCount( 1, $this->requests );
		$this->assertSame( 1, jlife_challenges_apibible_usage()['calls'] );
	}

	/**
	 * The counter counts each real HTTP call, not a refused or held one, and
	 * starts again each month; at the limit no call is made.
	 */
	public function test_usage_counts_real_calls_only() {
		$this->fetch();
		$this->fetch();
		$this->assertSame( 2, jlife_challenges_apibible_usage()['calls'] );

		jlife_challenges_apibible_fetch_passage( 'bad id!', 'JHN.1.1' );
		jlife_challenges_apibible_fetch_passage( self::BIBLE, 'John 1:1' );
		remove_filter( 'jlife_challenges_apibible_key', array( $this, 'filter_key' ) );
		$no_key = $this->fetch();
		add_filter( 'jlife_challenges_apibible_key', array( $this, 'filter_key' ) );
		$this->assertSame( 'link_only', $no_key['outcome'] );
		$this->assertSame( 2, jlife_challenges_apibible_usage()['calls'] );
		$this->assertCount( 2, $this->requests );

		update_site_option(
			JLIFE_CHALLENGES_APIBIBLE_USAGE_OPTION,
			array(
				'month' => '2000-01',
				'calls' => 4999,
				'fums'  => 7,
			)
		);
		$this->assertSame( 0, jlife_challenges_apibible_usage()['calls'], 'a new month starts at zero' );

		update_site_option(
			JLIFE_CHALLENGES_APIBIBLE_USAGE_OPTION,
			array(
				'month' => gmdate( 'Y-m' ),
				'calls' => JLIFE_CHALLENGES_APIBIBLE_MONTHLY_LIMIT,
				'fums'  => 0,
			)
		);
		$r = $this->fetch();
		$this->assertSame( 'quota', $r['reason'] );
		$this->assertCount( 2, $this->requests, 'no call over the limit' );
		$this->assertSame( 'near', jlife_challenges_apibible_usage_state( JLIFE_CHALLENGES_APIBIBLE_WARN_AT ) );
		$this->assertSame( 'over', jlife_challenges_apibible_usage_state( JLIFE_CHALLENGES_APIBIBLE_MONTHLY_LIMIT ) );
		$this->assertSame( 'ok', jlife_challenges_apibible_usage_state( 10 ) );
	}

	/**
	 * Rendering a passage (fresh or from a cache) shows the copyright and the
	 * API.Bible link, and reports the view to FUMS from the server with
	 * anonymous IDs only. FUMS reports are counted apart from API calls.
	 */
	public function test_rendering_reports_the_view_to_fums() {
		$r    = $this->fetch();
		$html = jlife_challenges_apibible_render( $r );
		$this->assertStringContainsString( self::TEXT, $html );
		$this->assertStringContainsString( 'Fake Bible © 2026 Example Publisher.', $html );
		$this->assertStringContainsString( 'href="https://api.bible"', $html );

		$fums = $this->requests[1];
		$this->assertStringStartsWith( 'https://fums.api.bible/f3?t=fumsTOKEN123&dId=', $fums['url'] );
		$this->assertStringContainsString( '&sId=', $fums['url'] );
		$this->assertStringNotContainsString( 'uId=', $fums['url'] );
		$this->assertStringNotContainsString( self::KEY, $fums['url'] );
		$this->assertFalse( $fums['args']['blocking'] );
		$this->assertArrayNotHasKey( 'api-key', (array) ( $fums['args']['headers'] ?? array() ) );

		jlife_challenges_apibible_render( $r ); // A second view of the same (cached) passage.
		$this->assertCount( 3, $this->requests );
		$usage = jlife_challenges_apibible_usage();
		$this->assertSame( 1, $usage['calls'] );
		$this->assertSame( 2, $usage['fums'] );

		$this->assertSame( jlife_challenges_apibible_fums_device(), jlife_challenges_apibible_fums_device(), 'stable install ID' );
		$this->assertSame( '', jlife_challenges_apibible_fums_url( array( 'bad token!' ) ) );
	}

	/**
	 * The lookup lists the account's Bibles (metadata only) and needs a key.
	 */
	public function test_list_bibles() {
		$rows = jlife_challenges_apibible_list_bibles( 'eng' );
		$this->assertSame( 'NLT', $rows[0]['abbreviation'] );
		$this->assertSame( self::BIBLE, $rows[0]['id'] );
		$this->assertSame( 'eng', $rows[1]['language'] );
		$this->assertSame( 'https://rest.api.bible/v1/bibles?language=eng', $this->requests[0]['url'] );
		$this->assertSame( 1, jlife_challenges_apibible_usage()['calls'] );

		$this->status = 401;
		$error        = jlife_challenges_apibible_list_bibles();
		$this->assertWPError( $error );
		$this->assertStringNotContainsString( self::KEY, $error->get_error_message() );

		remove_filter( 'jlife_challenges_apibible_key', array( $this, 'filter_key' ) );
		$this->assertWPError( jlife_challenges_apibible_list_bibles() );
		$this->assertCount( 2, $this->requests );
	}

	/**
	 * Passage IDs in API.Bible's format.
	 */
	public function test_passage_ids() {
		$this->assertSame( 'JHN.1.29-JHN.1.34', jlife_challenges_apibible_passage_id( 'jhn', 1, 29, 0, 34 ) );
		$this->assertSame( 'LUK.3.23', jlife_challenges_apibible_passage_id( 'LUK', 3, 23 ) );
		$this->assertSame( 'MAT.5-MAT.7', jlife_challenges_apibible_passage_id( 'MAT', 5, 0, 7 ) );
		$this->assertSame( '1CO.13', jlife_challenges_apibible_passage_id( '1CO', 13 ) );
		$this->assertSame( '', jlife_challenges_apibible_passage_id( 'John', 1 ) );
	}

	/**
	 * The admin figure: counts, and warnings near and over the limit.
	 */
	public function test_admin_usage_figure() {
		$usage = array(
			'month' => '2026-12',
			'calls' => 120,
			'fums'  => 900,
		);
		$html  = jlife_challenges_scripture_admin_html( $usage, true );
		$this->assertStringContainsString( '120 of 5000', $html );
		$this->assertStringNotContainsString( 'notice-warning', $html );
		$this->assertStringContainsString( 'notice-warning', jlife_challenges_scripture_admin_html( array( 'calls' => 4200 ) + $usage, true ) );
		$this->assertStringContainsString( 'notice-error', jlife_challenges_scripture_admin_html( array( 'calls' => 5000 ) + $usage, true ) );
		$this->assertStringContainsString( 'No API.Bible key', jlife_challenges_scripture_admin_html( $usage, false ) );
	}

	/**
	 * One Bible's metadata (terms 7.1: the copyright information comes from it):
	 * name, abbreviation, copyright and info as text, from a single counted call
	 * with the key only in the header.
	 */
	public function test_bible_metadata() {
		$r = jlife_challenges_apibible_fetch_bible( self::BIBLE, array( 'version' => 'nlt' ) );
		$this->assertSame( 'ok', $r['outcome'] );
		$this->assertSame( 'Fake Living Translation', $r['name'] );
		$this->assertSame( 'NLT', $r['abbreviation'] );
		$this->assertSame( 'Fake Bible © 2026 Example Publisher. Used by permission.', $r['copyright'] );
		$this->assertSame( 'Fake info for tests only.', $r['info'], 'tags stripped' );
		$this->assertSame( 'eng', $r['language'] );
		$this->assertCount( 1, $this->requests );
		$this->assertSame( JLIFE_CHALLENGES_APIBIBLE_BASE . '/bibles/' . self::BIBLE, $this->requests[0]['url'], 'no query string, no key in the URL' );
		$this->assertSame( self::KEY, $this->requests[0]['args']['headers']['api-key'] );
		$this->assertSame( 1, jlife_challenges_apibible_usage()['calls'], 'a real call is counted' );
		$this->assertStringNotContainsString( self::KEY, wp_json_encode( $r ) );
	}

	/**
	 * Metadata failures follow the passage rules: 401/403 link-only, 429, 5xx and
	 * timeouts fall back, a bad ID makes no call, a missing key makes no call, and
	 * the log holds only the version, an outcome and the status.
	 */
	public function test_bible_metadata_failures() {
		$cases = array(
			401 => 'link_only',
			403 => 'link_only',
			429 => 'fallback',
			503 => 'fallback',
			0   => 'fallback',
		);
		foreach ( $cases as $status => $outcome ) {
			delete_site_transient( jlife_challenges_apibible_hold_key( self::BIBLE ) );
			$this->status = $status;
			$r            = jlife_challenges_apibible_fetch_bible( self::BIBLE, array( 'version' => 'nlt' ) );
			$this->assertSame( $outcome, $r['outcome'], "status {$status}" );
			$this->assertSame( '', $r['name'] );
		}
		delete_site_transient( jlife_challenges_apibible_hold_key( self::BIBLE ) );
		$this->status = 200;
		$before       = count( $this->requests );
		$r            = jlife_challenges_apibible_fetch_bible( '../etc', array( 'version' => 'nlt' ) );
		$this->assertSame( 'fallback', $r['outcome'] );
		$this->assertSame( '', $r['bible_id'] );
		$this->assertCount( $before, $this->requests, 'an invalid ID makes no request' );
		remove_filter( 'jlife_challenges_apibible_key', array( $this, 'filter_key' ) );
		$r = jlife_challenges_apibible_fetch_bible( self::BIBLE, array( 'version' => 'nlt' ) );
		$this->assertSame( 'link_only', $r['outcome'] );
		$this->assertSame( 'no_key', $r['reason'] );
		$this->assertCount( $before, $this->requests, 'no key, no request' );
		$log = $this->log_text();
		$this->assertStringContainsString( 'version=nlt outcome=not_licensed status=401', $log );
		$this->assertStringContainsString( 'version=nlt outcome=no_key status=0', $log );
		$this->assertStringNotContainsString( self::KEY, $log );
		$this->assertStringNotContainsString( self::BIBLE, $log );
	}
}
