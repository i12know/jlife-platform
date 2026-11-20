<?php
/**
 * The day's Scripture on Today and /soap (#139 part 3): with the
 * jlife_scripture_native_reader option on, the participant's own Bible shows on
 * the page in place of the framed Bible Gateway reader; with it off (the
 * default) nothing changes. Written before the wiring.
 *
 * @package jlife-challenges
 */

// phpcs:disable WordPress.Security.NonceVerification -- tests drive the screens directly.

/**
 * Native reader wiring tests.
 */
class Test_Native_Reader extends Jlife_Challenges_TestCase {

	/**
	 * Captured PHP error log for this test.
	 *
	 * @var string
	 */
	private $log = '';

	/**
	 * Previous error_log setting.
	 *
	 * @var string|false
	 */
	private $previous_log = false;

	/**
	 * A fake key; it must never appear anywhere but the api-key header.
	 */
	const KEY = 'fake-KEY-0123456789abcdef';

	/**
	 * Made-up notice (never real Scripture).
	 */
	const COPYRIGHT = 'FAKE COPYRIGHT NOTICE (c) 2026 Example Publisher.';

	/**
	 * What the fake API.Bible answers: an HTTP status, or 0 for a network error.
	 *
	 * @var int
	 */
	private $api_status = 200;

	/**
	 * The challenges tables, and (when the Scripture plugin is beside this one)
	 * its tables with the committed public-domain Kinh Thánh 1925 text.
	 *
	 * @throws RuntimeException When the shipped dataset does not import.
	 */
	public static function wpSetUpBeforeClass() {
		parent::wpSetUpBeforeClass();
		if ( function_exists( 'jlife_scripture_import_dataset' ) ) {
			jlife_scripture_install_tables();
			$r = jlife_scripture_import_dataset( 'vie1934' );
			if ( is_wp_error( $r ) ) {
				throw new RuntimeException( $r->get_error_message() ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- test setup.
			}
		}
	}

	/**
	 * Needs the Scripture plugin; start with its stylesheet unqueued and the
	 * PHP error log captured.
	 */
	public function set_up() {
		parent::set_up();
		if ( ! function_exists( 'jlife_scripture_maybe_render_day' ) ) {
			$this->markTestSkipped( 'jlife-scripture is not loaded.' );
		}
		add_filter( 'jlife_challenges_apibible_key', array( $this, 'filter_key' ) );
		add_filter( 'pre_http_request', array( $this, 'fake_http' ), 10, 3 );
		delete_site_option( JLIFE_CHALLENGES_APIBIBLE_USAGE_OPTION );
		delete_site_transient( jlife_challenges_apibible_hold_key( jlife_scripture_get_version( 'nlt' )['source_id'] ) );
		$GLOBALS['wp_styles'] = null; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- a fresh style queue per test.
		$this->log            = wp_tempnam( 'jlnr-log' );
		$this->previous_log   = ini_set( 'error_log', $this->log ); // phpcs:ignore WordPress.PHP.IniSet.Risky -- test: capture the log.
	}

	/**
	 * Restore the log and the style queue.
	 */
	public function tear_down() {
		remove_filter( 'jlife_challenges_apibible_key', array( $this, 'filter_key' ) );
		remove_filter( 'pre_http_request', array( $this, 'fake_http' ), 10 );
		ini_set( 'error_log', (string) $this->previous_log ); // phpcs:ignore WordPress.PHP.IniSet.Risky -- test: restore the log.
		wp_delete_file( $this->log );
		$GLOBALS['wp_styles'] = null; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- leave no queue behind.
		wp_set_current_user( 0 );
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
	 * Answer API.Bible's requests without the network: a plain-text passage of
	 * made-up verses ("[n] FAKE-VERSE-n for tests only.") for the passage ID asked.
	 *
	 * @param mixed  $pre  Short-circuit value.
	 * @param array  $args Request args.
	 * @param string $url  Request URL.
	 * @return array|WP_Error
	 */
	public function fake_http( $pre, $args, $url ) {
		unset( $pre, $args );
		$ok = array(
			'headers'  => array(),
			'body'     => '',
			'response' => array(
				'code'    => 200,
				'message' => '',
			),
			'cookies'  => array(),
			'filename' => null,
		);
		if ( 0 === strpos( $url, JLIFE_CHALLENGES_APIBIBLE_FUMS ) ) {
			return $ok;
		}
		if ( 0 === $this->api_status ) {
			return new WP_Error( 'http_request_failed', 'cURL error 28: Operation timed out' );
		}
		if ( 200 !== $this->api_status ) {
			$ok['response']['code'] = $this->api_status;
			$ok['body']             = '{"statusCode":' . $this->api_status . ',"message":"error"}';
			return $ok;
		}
		if ( 1 !== preg_match( '#/passages/([0-9A-Z.\-]+)\?#', $url, $m ) || 1 !== preg_match( '/^[0-9A-Z]{3}\.(\d+)\.(\d+)(?:-[0-9A-Z]{3}\.(\d+)\.(\d+))?$/', $m[1], $p ) ) {
			return new WP_Error( 'http_request_failed', 'unexpected request' );
		}
		$content = '';
		for ( $v = (int) $p[2]; $v <= (int) ( $p[4] ?? $p[2] ); $v++ ) {
			$content .= "\n     [{$v}] FAKE-VERSE-{$v} for tests only. ";
		}
		$ok['body'] = (string) wp_json_encode(
			array(
				'data' => array(
					'id'         => $m[1],
					'content'    => $content,
					'reference'  => 'Fake reference',
					'verseCount' => substr_count( $content, '[' ),
					'copyright'  => self::COPYRIGHT,
				),
				'meta' => array( 'fumsToken' => 'fakeFUMStoken123' ),
			)
		);
		return $ok;
	}

	/**
	 * Today's day view for a participant, with a reading like the plan's: a
	 * title, one passage and the Bible Gateway links the framed reader uses.
	 *
	 * @param int      $user_id  Participant.
	 * @param string[] $passages Passage references (empty = none).
	 * @return array
	 */
	private function view( $user_id, $passages = array( 'Luke 3:1-6' ) ) {
		$links = array(
			'en' => array(
				'version'  => 'ERV',
				'combined' => array( 'url' => 'https://www.biblegateway.com/passage/?search=Luke+3:1-6&version=ERV' ),
			),
			'vi' => array(
				'version'  => 'BPT',
				'combined' => array( 'url' => 'https://www.biblegateway.com/passage/?search=Luke+3:1-6&version=BPT' ),
			),
		);
		return array_merge(
			jlife_challenges_day_view( $user_id ),
			array(
				'reading'   => array(
					'plan'         => array(
						'links_config' => array(
							'en' => array(
								'label'  => 'English',
								'notice' => 'Easy-to-Read Version (ERV)',
								'embed'  => true,
							),
							'vi' => array(
								'label'  => 'Tiếng Việt',
								'notice' => 'Vietnamese Bible: Easy-to-Read Version (BPT)',
								'embed'  => true,
							),
						),
					),
					'day'          => array(
						'title'    => 'John the Baptizer Prepares the Way',
						'passages' => array_map(
							static fn( $display ) => array( 'display' => $display ),
							$passages
						),
						'links'    => $links,
					),
					'supplemental' => array(),
				),
				'languages' => array( 'en', 'vi' ),
			)
		);
	}

	/**
	 * Turn the on-page reader on for this site.
	 */
	private function reader_on() {
		update_option( 'jlife_scripture_native_reader', '1' );
	}

	/**
	 * Off by default: Today and /soap keep the framed Bible Gateway reader, the
	 * plain reference list, and load no Scripture stylesheet.
	 */
	public function test_flag_off_changes_nothing() {
		$this->assertFalse( jlife_scripture_reader_enabled() );
		wp_set_current_user( $this->u['a'] );
		$view = $this->view( $this->u['a'] );
		foreach ( array( jlife_challenges_today_body( $view ), jlife_challenges_soap_body( $view ) ) as $body ) {
			$this->assertStringContainsString( '<details class="jl-reader"><summary>Read today&#039;s passages</summary>', $body );
			$this->assertStringContainsString( '<ul class="jl-passages"><li>Luke 3:1-6</li></ul>', $body );
			$this->assertStringContainsString( '<iframe', $body );
			$this->assertStringNotContainsString( 'jls-reader', $body );
		}
		$this->assertStringNotContainsString( 'scripture.css', jlife_challenges_page_html( 'Today', jlife_challenges_today_body( $view ), 'today' ) );
	}

	/**
	 * On: a Vietnamese reader (My language: since #159 the account language,
	 * set by the Language setting) reads Kinh Thánh 1925 on Today with no frame;
	 * the reader sits above the SOAP form, the reference chips and the form are
	 * as before, and typed text is untouched.
	 */
	public function test_flag_on_shows_the_vietnamese_default_on_the_page() {
		$this->reader_on();
		$this->assertTrue( jlife_challenges_set_language( $this->u['a'], $this->u['a'], 'vi' ) );
		wp_set_current_user( $this->u['a'] );
		$body = jlife_challenges_today_body( $this->view( $this->u['a'] ), array( 'text' => 'My own words (kept).' ) );

		$this->assertStringContainsString( '<section class="jls-reader" lang="vi"', $body );
		$this->assertStringContainsString( '<details class="jls-passage" open><summary>Luke 3:1-6</summary>', $body );
		$this->assertStringContainsString( 'class="jls-text"', $body );
		$this->assertStringContainsString( '<sup class="jls-num">1</sup>', $body );
		$this->assertStringContainsString( '<span class="jls-version-name">Kinh Thánh 1925 (Bản Truyền Thống)</span>', $body );
		$this->assertStringNotContainsString( '<iframe', $body );
		$this->assertStringNotContainsString( 'class="jl-reader"', $body );
		$this->assertStringNotContainsString( 'biblegateway.com', $body, 'no link needed when the text shows' );

		// The title and everything else stay; only the plain list the reader repeats is gone.
		$this->assertStringContainsString( '<h2>John the Baptizer Prepares the Way</h2>', $body );
		$this->assertStringNotContainsString( 'class="jl-passages"', $body );
		$this->assertStringContainsString( 'data-insert="Luke 3:1-6"', $body, 'the reference chip is still there' );
		$this->assertStringContainsString( 'My own words (kept).</textarea>', $body );
		$this->assertSame( 1, substr_count( $body, '<form method="post" class="jl-soap"' ), 'one SOAP form' );
		$reader = strpos( $body, 'class="jls-reader"' );
		$this->assertLessThan( strpos( $body, '<form method="post" class="jl-soap"' ), $reader, 'the reader is above the form' );
		$this->assertGreaterThan( strpos( $body, 'class="jl-reading"' ), $reader, 'and below the reading title' );
		$this->assertSame( '', (string) file_get_contents( $this->log ), 'nothing logged' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- test log.
	}

	/**
	 * An English reader's default is the NLT through API.Bible (owner,
	 * 2026-11-19): its text on the page with the API's notice, never a frame.
	 */
	public function test_english_default_is_the_nlt_through_the_api() {
		$this->reader_on();
		wp_set_current_user( $this->u['a'] );
		$body = jlife_challenges_today_body( $this->view( $this->u['a'] ) );
		$this->assertStringContainsString( '<section class="jls-reader" lang="en"', $body );
		$this->assertStringContainsString( '<span class="jls-version-name">New Living Translation</span>', $body );
		$this->assertStringContainsString( '<sup class="jls-num">1</sup> FAKE-VERSE-1 for tests only.', $body );
		$this->assertStringContainsString( self::COPYRIGHT, $body );
		$this->assertStringContainsString( 'href="https://api.bible"', $body );
		$this->assertStringNotContainsString( 'Open in Bible Gateway', $body );
		$this->assertStringNotContainsString( '<iframe', $body );
		$this->assertStringContainsString( 'class="jl-soap"', $body );
		$this->assertStringNotContainsString( self::KEY, $body );
	}

	/**
	 * When API.Bible cannot answer, the English reader shows the short note and
	 * the Bible Gateway link for ERV, never a frame, never a blocked page.
	 */
	public function test_english_default_falls_back_to_the_erv_link_when_the_api_cannot_answer() {
		$this->reader_on();
		$this->api_status = 503;
		wp_set_current_user( $this->u['a'] );
		$body = jlife_challenges_today_body( $this->view( $this->u['a'] ) );
		$this->assertStringContainsString( '<section class="jls-reader" lang="en"', $body );
		$this->assertStringContainsString( 'The Bible text could not be loaded.', $body );
		$this->assertStringContainsString( 'Open in Bible Gateway (ERV)', $body );
		$this->assertStringContainsString( 'href="https://www.biblegateway.com/passage/?search=Luke+3:1-6&#038;version=ERV"', $body, 'the link is for the passage in the Bible the reader would show' );
		$this->assertStringNotContainsString( 'jls-text', $body );
		$this->assertStringNotContainsString( '<iframe', $body );
		$this->assertStringContainsString( 'class="jl-soap"', $body );
	}

	/**
	 * The reader shows the signed-in person's own choice, their second Bible
	 * under the first, and nobody else's.
	 */
	public function test_it_shows_the_viewers_own_bibles() {
		$this->reader_on();
		$this->assertTrue( jlife_scripture_set_user_choice( $this->u['a'], array( 'primary' => 'kt1925' ) ) );
		$this->assertTrue( jlife_scripture_set_user_choice( $this->u['a'], array( 'secondary' => 'erv' ) ) );
		$this->assertTrue( jlife_scripture_set_user_choice( $this->u['b'], array( 'primary' => 'bpt' ) ) );

		wp_set_current_user( $this->u['a'] );
		$a = jlife_challenges_today_body( $this->view( $this->u['a'] ) );
		$this->assertStringContainsString( 'class="jls-text"', $a, 'their first Bible: the 1925 text' );
		$this->assertStringContainsString( '<div class="jls-edition" lang="en">', $a );
		$this->assertLessThan( strpos( $a, 'Open in Bible Gateway (ERV)' ), strpos( $a, 'class="jls-text"' ), 'the second Bible is under the first' );

		wp_set_current_user( $this->u['b'] );
		$b = jlife_challenges_today_body( $this->view( $this->u['b'] ) );
		$this->assertStringContainsString( 'Open in Bible Gateway (BPT)', $b, 'another participant reads in their own Bible' );
		$this->assertStringNotContainsString( 'class="jls-text"', $b );
		$this->assertStringNotContainsString( 'jls-edition', $b );

		wp_set_current_user( $this->u['c'] );
		$c = jlife_challenges_today_body( $this->view( $this->u['c'] ) );
		$this->assertStringContainsString( '<span class="jls-version-name">New Living Translation</span>', $c, 'no choice: the language default' );
		$this->assertStringNotContainsString( 'Open in Bible Gateway', $c );
	}

	/**
	 * /soap?day=… gets the same reader as Today.
	 */
	public function test_the_soap_page_gets_the_same_reader() {
		$this->reader_on();
		jlife_scripture_set_user_choice( $this->u['a'], array( 'primary' => 'kt1925' ) );
		wp_set_current_user( $this->u['a'] );
		$body = jlife_challenges_soap_body( $this->view( $this->u['a'] ) );
		$this->assertStringContainsString( '<section class="jls-reader" lang="vi"', $body );
		$this->assertStringContainsString( 'class="jls-text"', $body );
		$this->assertStringNotContainsString( '<iframe', $body );
		$this->assertSame( 1, substr_count( $body, '<form method="post" class="jl-soap"' ) );
	}

	/**
	 * A source that fails (here the 1925 text's) never blocks the page: a short
	 * note and the Bible Gateway link, and the SOAP form as usual.
	 */
	public function test_an_unavailable_passage_falls_back_to_the_link() {
		$this->reader_on();
		add_filter(
			'jlife_scripture_sources',
			static function ( $sources ) {
				$sources['local'] = new class() implements Jlife_Scripture_Source {

					/**
					 * Registry source value.
					 *
					 * @return string
					 */
					public function id() {
						return 'local';
					}

					/**
					 * No cache.
					 *
					 * @return bool
					 */
					public function cacheable() {
						return false;
					}

					/**
					 * A source that is down.
					 *
					 * @param array $version  Version.
					 * @param array $segments Segments.
					 * @return WP_Error
					 */
					public function fetch( array $version, array $segments ) {
						return new WP_Error( 'jlife_scripture_unavailable', 'Unavailable.' );
					}
				};
				return $sources;
			}
		);
		jlife_scripture_set_user_choice( $this->u['a'], array( 'primary' => 'kt1925' ) );
		wp_set_current_user( $this->u['a'] );
		$body = jlife_challenges_today_body( $this->view( $this->u['a'] ) );
		$this->assertStringContainsString( 'The Bible text could not be loaded.', $body );
		$this->assertStringContainsString( 'Open in Bible Gateway (BPT)', $body );
		$this->assertStringContainsString( 'class="jl-soap"', $body );
	}

	/**
	 * A day with no passages has nothing to show on the page, so the framed
	 * reader and the plain list stay (nothing is half-switched).
	 */
	public function test_a_day_without_passages_keeps_the_framed_reader() {
		$this->reader_on();
		wp_set_current_user( $this->u['a'] );
		$body = jlife_challenges_today_body( $this->view( $this->u['a'], array() ) );
		$this->assertStringContainsString( '<details class="jl-reader">', $body );
		$this->assertStringNotContainsString( 'jls-reader', $body );
	}

	/**
	 * The standalone participant pages have no wp_head, so the page includes the
	 * reader's stylesheet itself, only when the reader was shown.
	 */
	public function test_the_page_loads_the_reader_stylesheet_only_when_used() {
		$this->reader_on();
		wp_set_current_user( $this->u['a'] );
		$html = jlife_challenges_page_html( 'Today', jlife_challenges_today_body( $this->view( $this->u['a'] ) ), 'today' );
		$this->assertMatchesRegularExpression( '/<link[^>]*id=.jlife-scripture-css.[^>]*assets\/scripture\.css\?ver=/', $html );
		$this->assertLessThan( strpos( $html, '</head>' ), strpos( $html, 'scripture.css' ), 'in the head' );
		$this->assertSame( 1, substr_count( $html, 'scripture.css' ) );

		$GLOBALS['wp_styles'] = null; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- a fresh queue: a page that shows no reader.
		$this->assertStringNotContainsString( 'scripture.css', jlife_challenges_page_html( 'My Marathon', '<p>x</p>', 'marathon' ) );
	}
}
