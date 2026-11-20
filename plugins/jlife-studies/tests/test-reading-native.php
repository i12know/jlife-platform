<?php
/**
 * The public /reading/ day pages with the on-page Scripture reader (#139, the
 * last display surface): behind the site option jlife_scripture_native_reader
 * (default off) the page shows the page language's default Bible instead of
 * the per-language Bible Gateway link blocks. Written before the wiring.
 *
 * @package jlife-studies
 */

// phpcs:disable WordPress.Security.NonceVerification -- tests set the page's ?lang= like a request.

/**
 * Public reading page + native reader tests.
 */
class Test_Reading_Native extends WP_UnitTestCase {

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
	 * The Scripture tables and the committed public-domain Kinh Thánh 1925 text,
	 * when the Scripture plugin sits beside this one (wp-env maps both).
	 *
	 * @throws RuntimeException When the shipped dataset does not import.
	 */
	public static function wpSetUpBeforeClass() {
		if ( function_exists( 'jlife_scripture_import_dataset' ) ) {
			jlife_scripture_install_tables();
			$r = jlife_scripture_import_dataset( 'vie1934' );
			if ( is_wp_error( $r ) ) {
				throw new RuntimeException( $r->get_error_message() ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- test setup.
			}
		}
	}

	/**
	 * Both plans imported, a fresh style queue and the error log captured.
	 */
	public function set_up() {
		parent::set_up();
		if ( ! function_exists( 'jlife_scripture_maybe_render_day' ) ) {
			$this->markTestSkipped( 'jlife-scripture is not loaded.' );
		}
		jlife_studies_import_reading_plan( $this->fixture( 'primary' ) );
		jlife_studies_import_reading_plan( $this->fixture( 'supplemental' ) );
		$GLOBALS['wp_styles'] = null; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- a fresh style queue per test.
		$this->log            = wp_tempnam( 'jsr-log' );
		$this->previous_log   = ini_set( 'error_log', $this->log ); // phpcs:ignore WordPress.PHP.IniSet.Risky -- test: capture the log.
	}

	/**
	 * Restore the log, the request and the style queue.
	 */
	public function tear_down() {
		ini_set( 'error_log', (string) $this->previous_log ); // phpcs:ignore WordPress.PHP.IniSet.Risky -- test: restore the log.
		wp_delete_file( $this->log );
		$GLOBALS['wp_styles'] = null; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- leave no queue behind.
		$_GET                 = array();
		wp_set_current_user( 0 );
		parent::tear_down();
	}

	/**
	 * Load a fixture plan.
	 *
	 * @param string $name primary|supplemental.
	 * @return array
	 */
	private function fixture( $name ) {
		$raw = file_get_contents( __DIR__ . "/fixtures/plan-{$name}.json" ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		return json_decode( (string) $raw, true );
	}

	/**
	 * The post ID of a day of the primary plan.
	 *
	 * @param string $day_id d001, d038 or d053.
	 * @return int
	 */
	private function post_of( $day_id ) {
		return (int) jlife_studies_get_reading_day( 'rpl-life-and-teachings-of-jesus-2026', $day_id )['post_id'];
	}

	/**
	 * The day page's markup as the single-page template gets it from the
	 * content filter (called directly, so no other filter reshapes it).
	 *
	 * @param int    $post_id Post ID.
	 * @param string $lang    The page's ?lang= ('' = none).
	 * @return string
	 */
	private function render( $post_id, $lang = '' ) {
		$url = get_permalink( $post_id );
		$this->go_to( '' === $lang ? $url : add_query_arg( 'lang', $lang, $url ) );
		$this->assertTrue( is_singular( JLIFE_READING_DAY_CPT ) );
		$html = '';
		while ( have_posts() ) {
			the_post();
			$html = jlife_studies_render_reading_day( '' );
		}
		return $html;
	}

	/**
	 * Turn the on-page reader on for this site.
	 */
	private function reader_on() {
		update_option( 'jlife_scripture_native_reader', '1' );
	}

	/**
	 * The page as it was before the reader existed, rebuilt from the unchanged
	 * pieces: date, heading, the primary day's passage list and Bible links, the
	 * optional parallel readings and the source line.
	 *
	 * @param int $post_id Post ID.
	 * @return string
	 */
	private function expected_flag_off( $post_id ) {
		$day  = jlife_studies_get_json_meta( $post_id, '_jlife_reading_day' );
		$plan = jlife_studies_get_json_meta( $post_id, '_jlife_plan' );
		$supp = jlife_studies_get_json_meta( $post_id, '_jlife_supplemental' );
		$date = date_i18n( get_option( 'date_format' ), (int) strtotime( (string) $day['date'] . ' 12:00:00' ) );
		$out  = '<div class="jlife-reading-day"><p class="jlife-reading-date">' . esc_html( sprintf( 'Day %1$d · %2$s', (int) $day['day'], $date ) ) . '</p>'
			. '<h2>' . esc_html( "Today's reading" ) . '</h2>'
			. jlife_studies_render_reading_links( $day, $plan['links_config'] );
		if ( is_array( $supp ) && ! empty( $supp['day'] ) ) {
			$out .= '<details class="jlife-supplemental"><summary>Optional: parallel readings for deeper study</summary>'
				. '<p><em>Optional. Reading these does not change your daily points.</em></p>'
				. jlife_studies_render_harmony_sections( $supp['day'] )
				. jlife_studies_render_reading_links( $supp['day'], $supp['plan']['links_config'] )
				. '</details>';
		}
		if ( '' !== (string) ( $plan['source_attribution'] ?? '' ) ) {
			$out .= '<p class="jlife-reading-source"><small>' . esc_html( (string) $plan['source_attribution'] ) . '</small></p>';
		}
		return $out . '</div>';
	}

	/**
	 * What this test wrote to the PHP error log.
	 *
	 * @return string
	 */
	private function logged() {
		return (string) file_get_contents( $this->log ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- test log.
	}

	/**
	 * The option is off by default and then the page is exactly what it was:
	 * the passage list, a Bible Gateway block per language, the parallels and the
	 * source line; no reader, no language links, no Scripture stylesheet.
	 */
	public function test_flag_off_is_byte_for_byte_the_old_page() {
		$this->assertFalse( jlife_scripture_reader_enabled() );
		foreach ( array( 'd001', 'd038', 'd053' ) as $day_id ) {
			$post_id = $this->post_of( $day_id );
			$html    = $this->render( $post_id );
			$this->assertSame( $this->expected_flag_off( $post_id ), $html, $day_id );
			$this->assertStringContainsString( 'class="jlife-bible-links" lang="vi"', $html );
			$this->assertStringContainsString( 'class="jlife-bible-links" lang="en"', $html );
			$this->assertStringNotContainsString( 'jls-reader', $html );
			$this->assertStringNotContainsString( 'jlife-reading-lang', $html );
		}
		// The page's language only matters once the reader is on.
		$this->assertSame( $this->expected_flag_off( $this->post_of( 'd001' ) ), $this->render( $this->post_of( 'd001' ), 'vi' ) );
		$this->assertFalse( wp_style_is( 'jlife-scripture', 'enqueued' ), 'no stylesheet' );
		$this->assertSame( '', $this->logged() );
	}

	/**
	 * Reader on, Vietnamese page (?lang=vi): Kinh Thánh 1925 on the page with
	 * verse numbers and its copyright line; the per-language link blocks of the
	 * main reading are gone, the optional parallels keep their outside links, and
	 * the title, date and source line stay.
	 */
	public function test_vietnamese_page_shows_the_1925_text_with_verse_numbers_and_copyright() {
		$this->reader_on();
		$html = $this->render( $this->post_of( 'd001' ), 'vi' );

		$this->assertStringContainsString( '<section class="jls-reader" lang="vi"', $html );
		$this->assertStringContainsString( '<details class="jls-passage" open><summary>John 1:1-18</summary>', $html );
		$this->assertStringContainsString( 'class="jls-text"', $html );
		$this->assertStringContainsString( '<sup class="jls-num">1</sup>', $html );
		$this->assertStringContainsString( '<sup class="jls-num">18</sup>', $html );
		$this->assertStringContainsString( 'Ngôi Lời', $html );
		$this->assertStringContainsString( '<span class="jls-version-name">Kinh Thánh 1925 (Bản Truyền Thống)</span>', $html );
		$this->assertStringContainsString( 'thuộc phạm vi công cộng', $html, 'the copyright line renders' );
		$this->assertStringNotContainsString( '<iframe', $html );

		$main = substr( $html, 0, (int) strpos( $html, '<details class="jlife-supplemental">' ) );
		$this->assertStringContainsString( 'Day 1', $main );
		$this->assertStringContainsString( 'Today&#039;s reading', $main );
		$this->assertStringNotContainsString( 'jlife-bible-links', $main, 'the per-language link blocks are replaced' );
		$this->assertStringNotContainsString( 'jlife-passages', $main, 'the reader lists each reference itself' );
		$this->assertStringNotContainsString( 'jlife-read-all', $main );

		$supp = substr( $html, (int) strpos( $html, '<details class="jlife-supplemental">' ) );
		$this->assertStringContainsString( 'class="jlife-read-all"', $supp, 'optional parallel readings stay outside links' );
		$this->assertStringContainsString( 'jlife-reading-source', $html, 'source line stays' );
		$this->assertTrue( wp_style_is( 'jlife-scripture', 'enqueued' ), 'the reader stylesheet loads when the reader is used' );
		$this->assertSame( '', $this->logged(), 'nothing logged, no Bible text' );
	}

	/**
	 * The page language links: both languages, the current one marked, each a
	 * plain link to this page with ?lang= (works without JavaScript, cache-safe).
	 */
	public function test_language_links_switch_the_page_language() {
		$this->reader_on();
		$post_id = $this->post_of( 'd001' );
		$html    = $this->render( $post_id, 'vi' );
		$base    = get_permalink( $post_id );
		$this->assertStringContainsString( '<p class="jlife-reading-lang">', $html );
		$this->assertStringContainsString( '<a href="' . esc_url( add_query_arg( 'lang', 'en', $base ) ) . '" hreflang="en" lang="en">English</a>', $html );
		$this->assertStringContainsString( '<a href="' . esc_url( add_query_arg( 'lang', 'vi', $base ) ) . '" hreflang="vi" lang="vi" aria-current="true">Tiếng Việt</a>', $html );
		$html = $this->render( $post_id, 'en' );
		$this->assertStringContainsString( 'hreflang="en" lang="en" aria-current="true">English</a>', $html );
		$this->assertStringNotContainsString( 'hreflang="vi" lang="vi" aria-current', $html );
	}

	/**
	 * English: the default (NIrV) is not licensed yet, so each passage opens the
	 * ERV on Bible Gateway, with ERV's copyright line. No text, no frame.
	 */
	public function test_english_page_shows_the_gateway_link() {
		$this->reader_on();
		foreach ( array( 'en', '' ) as $lang ) { // ?lang=en, and the site language (English) with none.
			$html = $this->render( $this->post_of( 'd001' ), $lang );
			$this->assertStringContainsString( '<section class="jls-reader" lang="en"', $html, "lang {$lang}" );
			$this->assertStringContainsString( 'Open in Bible Gateway (ERV)', $html );
			$this->assertStringContainsString( 'href="https://www.biblegateway.com/passage/?search=John+1:1-18&#038;version=ERV"', $html );
			$this->assertStringContainsString( 'Easy-to-Read Version (ERV) Copyright © 2006 by Bible League International', $html, 'the copyright line renders' );
			$this->assertStringNotContainsString( 'jls-text', $html );
			$this->assertStringNotContainsString( '<iframe', $html );
		}
	}

	/**
	 * The page language is the page's: ?lang=, else the site language (Polylang
	 * and similar set it through the `locale` filter). Never the visitor's
	 * account language, and not the determine_locale() the participant screens use.
	 */
	public function test_page_language_comes_from_the_page_not_the_visitor() {
		$this->assertSame( 'en', jlife_studies_reading_page_language(), 'site language is English' );
		$_GET['lang'] = 'vi';
		$this->assertSame( 'vi', jlife_studies_reading_page_language() );
		$_GET['lang'] = 'VI';
		$this->assertSame( 'vi', jlife_studies_reading_page_language(), 'sanitized' );
		foreach ( array( 'fr', 'vi_VN', '../x', array( 'vi' ) ) as $bad ) {
			$_GET['lang'] = $bad;
			$this->assertSame( 'en', jlife_studies_reading_page_language(), 'an unknown ?lang= is ignored' );
		}

		$_GET = array();
		$site = static fn() => 'vi';
		add_filter( 'locale', $site );
		$this->assertSame( 'vi', jlife_studies_reading_page_language(), 'the site language' );
		$_GET['lang'] = 'en';
		$this->assertSame( 'en', jlife_studies_reading_page_language(), '?lang= wins' );
		remove_filter( 'locale', $site );

		// A signed-in visitor's own language never decides it.
		$_GET = array();
		$user = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		update_user_meta( $user, 'locale', 'vi' );
		wp_set_current_user( $user );
		$user_locale = static fn() => 'vi';
		add_filter( 'determine_locale', $user_locale );
		$this->assertSame( 'en', jlife_studies_reading_page_language() );
		remove_filter( 'determine_locale', $user_locale );
	}

	/**
	 * A signed-in participant with a stored My Bible choice (and Vietnamese
	 * account language) gets exactly the page-language default, byte for byte what
	 * a signed-out visitor gets: public pages may be page-cached, so they carry
	 * no per-user output and never read a stored choice.
	 */
	public function test_a_signed_in_visitors_stored_choice_is_never_used() {
		$this->reader_on();
		$post_id = $this->post_of( 'd001' );
		$user    = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		update_user_meta( $user, 'locale', 'vi' );
		update_user_meta( $user, 'jlife_bible_version', 'erv' );
		update_user_meta( $user, 'jlife_bible_version_2', 'bpt' );

		foreach ( array( 'vi', 'en' ) as $lang ) {
			wp_set_current_user( 0 );
			$signed_out = $this->render( $post_id, $lang );
			wp_set_current_user( $user );
			$signed_in = $this->render( $post_id, $lang );
			$this->assertSame( $signed_out, $signed_in, "{$lang}: identical for everyone" );
			$this->assertStringNotContainsString( 'jls-edition', $signed_in, 'no second Bible' );
		}
		$this->assertStringContainsString( 'Kinh Thánh 1925', $this->render( $post_id, 'vi' ), 'their ERV choice does not replace the 1925 default' );
		$html = $this->render( $post_id, 'en' );
		$this->assertStringContainsString( 'Open in Bible Gateway (ERV)', $html );
		$this->assertStringNotContainsString( 'Open in Bible Gateway (BPT)', $html );
		$this->assertSame( '', $this->logged() );
	}

	/**
	 * A failing source never blocks the page: a short note and the Bible
	 * Gateway link in the passage's place, and everything else is still there.
	 */
	public function test_a_failing_source_falls_back_to_the_link() {
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
		$html = $this->render( $this->post_of( 'd001' ), 'vi' );
		$this->assertStringContainsString( 'The Bible text could not be loaded.', $html );
		$this->assertStringContainsString( 'Open in Bible Gateway (BPT)', $html );
		$this->assertStringContainsString( 'href="https://www.biblegateway.com/passage/?search=John+1:1-18&#038;version=BPT"', $html );
		$this->assertStringNotContainsString( 'jls-text', $html );
		$this->assertStringContainsString( 'Day 1', $html );
		$this->assertStringContainsString( '<details class="jlife-supplemental">', $html, 'the rest of the page is intact' );
		$this->assertStringContainsString( 'jlife-reading-source', $html );
		$log = $this->logged();
		$this->assertStringContainsString( 'jlife-scripture: fetch failed: source=local version=kt1925 passage=JHN.1.1-JHN.1.18 code=other', $log, 'identifiers only' );
		$this->assertStringNotContainsString( 'Unavailable.', $log, 'not the source\'s own message' );
		$this->assertStringNotContainsString( 'Ngôi Lời', $log, 'no Bible text' );
		$this->assertSame( 1, substr_count( trim( $log ), "\n" ) + 1, 'one line' );
	}

	/**
	 * Turning the option back off restores the old page at once.
	 */
	public function test_turning_the_option_off_restores_the_old_page() {
		$post_id = $this->post_of( 'd038' );
		$this->reader_on();
		$this->assertStringContainsString( 'jls-reader', $this->render( $post_id, 'vi' ) );
		update_option( 'jlife_scripture_native_reader', '0' );
		$this->assertSame( $this->expected_flag_off( $post_id ), $this->render( $post_id, 'vi' ) );
	}
}
