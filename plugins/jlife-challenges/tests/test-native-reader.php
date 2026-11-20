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
		$GLOBALS['wp_styles'] = null; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- a fresh style queue per test.
		$this->log            = wp_tempnam( 'jlnr-log' );
		$this->previous_log   = ini_set( 'error_log', $this->log ); // phpcs:ignore WordPress.PHP.IniSet.Risky -- test: capture the log.
	}

	/**
	 * Restore the log and the style queue.
	 */
	public function tear_down() {
		ini_set( 'error_log', (string) $this->previous_log ); // phpcs:ignore WordPress.PHP.IniSet.Risky -- test: restore the log.
		wp_delete_file( $this->log );
		$GLOBALS['wp_styles'] = null; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- leave no queue behind.
		wp_set_current_user( 0 );
		parent::tear_down();
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
	 * On: a Vietnamese reader (My language, the email language) reads Kinh Thánh
	 * 1925 on Today with no frame; the reader sits above the SOAP form, the
	 * reference chips and the form are as before, and typed text is untouched.
	 */
	public function test_flag_on_shows_the_vietnamese_default_on_the_page() {
		$this->reader_on();
		jlife_challenges_set_reminder( $this->u['a'], $this->u['a'], $this->campaign, false, 'vi' );
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
	 * An English reader's default (NIrV) is not licensed yet: the reader shows the
	 * Bible Gateway link for ERV, never a frame, never a blocked page.
	 */
	public function test_english_default_is_a_gateway_link_until_nirv_is_licensed() {
		$this->reader_on();
		wp_set_current_user( $this->u['a'] );
		$body = jlife_challenges_today_body( $this->view( $this->u['a'] ) );
		$this->assertStringContainsString( '<section class="jls-reader" lang="en"', $body );
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
		$this->assertStringContainsString( 'Open in Bible Gateway (ERV)', $c, 'no choice: the language default' );
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
