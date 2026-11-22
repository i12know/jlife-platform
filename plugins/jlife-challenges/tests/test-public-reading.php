<?php
/**
 * The public /reading/<plan>-dNNN/ day pages (#106): a signed-out version of
 * Today, built by the same renderer, in the same standalone shell. Written
 * before the code.
 *
 * Fixtures are days 1, 38 and 53 of the plan (plugins/jlife-studies/tests/
 * fixtures), loaded through the Studies importer. Participants are fake.
 *
 * @package jlife-challenges
 */

// phpcs:disable WordPress.Security.NonceVerification -- tests set the page's ?lang= like a request.

/**
 * Public reading page tests.
 */
class Test_Public_Reading extends Jlife_Challenges_TestCase {

	/**
	 * A fake key; it must never appear anywhere but the api-key header.
	 */
	const KEY = 'fake-KEY-0123456789abcdef';

	/**
	 * Made-up notice (never real Scripture).
	 */
	const COPYRIGHT = 'FAKE COPYRIGHT NOTICE (c) 2026 Example Publisher.';

	/**
	 * The plan the fixtures belong to.
	 */
	const PLAN = 'rpl-life-and-teachings-of-jesus-2026';

	/**
	 * What the fake API.Bible answers: an HTTP status.
	 *
	 * @var int
	 */
	private $api_status = 200;

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
	 * The Studies plugin (reading-day pages) and the Auth plugin (page
	 * language) sit beside this one in production but are not loaded by this
	 * suite; load them, and the Scripture tables with the committed
	 * public-domain Kinh Thánh 1925 text when that plugin is present.
	 *
	 * @throws RuntimeException When the shipped dataset does not import.
	 */
	public static function wpSetUpBeforeClass() {
		parent::wpSetUpBeforeClass();
		foreach ( array(
			'jlife_studies_get_reading_day' => 'jlife-studies/jlife-studies.php',
			'jlife_auth_in_locale'          => 'jlife-auth/jlife-auth.php',
		) as $fn => $file ) {
			if ( ! function_exists( $fn ) ) {
				require_once dirname( __DIR__, 2 ) . '/' . $file;
			}
		}
		if ( function_exists( 'jlife_scripture_import_dataset' ) ) {
			jlife_scripture_install_tables();
			$r = jlife_scripture_import_dataset( 'vie1934' );
			if ( is_wp_error( $r ) ) {
				throw new RuntimeException( $r->get_error_message() ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- test setup.
			}
		}
	}

	/**
	 * Both plans imported, the Vietnamese catalog findable and the log captured.
	 */
	public function set_up() {
		parent::set_up();
		jlife_studies_register_reading_day();
		jlife_studies_import_reading_plan( $this->fixture( 'primary' ) );
		jlife_studies_import_reading_plan( $this->fixture( 'supplemental' ) );
		add_filter(
			'lang_dir_for_domain',
			static fn( $path, $domain ) => 'jlife-challenges' === $domain ? dirname( __DIR__ ) . '/languages/' : $path,
			10,
			2
		);
		unload_textdomain( 'jlife-challenges', true );
		add_filter( 'jlife_challenges_apibible_key', array( $this, 'filter_key' ) );
		add_filter( 'pre_http_request', array( $this, 'fake_http' ), 10, 3 );
		if ( function_exists( 'jlife_scripture_get_version' ) ) {
			delete_site_option( JLIFE_CHALLENGES_APIBIBLE_USAGE_OPTION );
			delete_site_transient( jlife_challenges_apibible_hold_key( jlife_scripture_get_version( 'nlt' )['source_id'] ) );
		}
		$GLOBALS['wp_styles'] = null; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- a fresh style queue per test.
		$this->log            = wp_tempnam( 'jpr-log' );
		$this->previous_log   = ini_set( 'error_log', $this->log ); // phpcs:ignore WordPress.PHP.IniSet.Risky -- test: capture the log.
		$_GET                 = array();
	}

	/**
	 * Restore the globals.
	 */
	public function tear_down() {
		remove_filter( 'jlife_challenges_apibible_key', array( $this, 'filter_key' ) );
		remove_filter( 'pre_http_request', array( $this, 'fake_http' ), 10 );
		ini_set( 'error_log', (string) $this->previous_log ); // phpcs:ignore WordPress.PHP.IniSet.Risky -- test: restore the log.
		wp_delete_file( $this->log );
		unload_textdomain( 'jlife-challenges', true );
		$GLOBALS['wp_styles'] = null; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- leave no queue behind.
		$_GET                 = array();
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
	 * made-up verses for the passage ID asked.
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
	 * Load a fixture plan from the Studies plugin's tests.
	 *
	 * @param string $name primary|supplemental.
	 * @return array
	 */
	private function fixture( $name ) {
		$raw = file_get_contents( dirname( __DIR__, 2 ) . "/jlife-studies/tests/fixtures/plan-{$name}.json" ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		return json_decode( (string) $raw, true );
	}

	/**
	 * The post ID of a day of the primary plan.
	 *
	 * @param string $day_id d001, d038 or d053.
	 * @return int
	 */
	private function post_of( $day_id = 'd001' ) {
		return (int) jlife_studies_get_reading_day( self::PLAN, $day_id )['post_id'];
	}

	/**
	 * The whole public page for a day, as a request with this ?lang= would get it.
	 *
	 * @param string $day_id d001, d038 or d053.
	 * @param string $lang   The page's ?lang= ('' = none).
	 * @return string
	 */
	private function page( $day_id = 'd001', $lang = '' ) {
		$_GET                 = '' === $lang ? array() : array( 'lang' => $lang );
		$GLOBALS['wp_styles'] = null; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- each page is its own request: the reader's stylesheet prints again.
		$html                 = jlife_challenges_public_reading_page_html( $this->post_of( $day_id ) );
		$this->assertIsString( $html );
		return $html;
	}

	/**
	 * The page body between <main> and </main>.
	 *
	 * @param string $html The whole page.
	 * @return string
	 */
	private function main_of( $html ) {
		$start = strpos( $html, '<main>' );
		$end   = strpos( $html, '</main>' );
		$this->assertNotFalse( $start );
		$this->assertNotFalse( $end );
		return substr( $html, $start + 6, $end - $start - 6 );
	}

	/**
	 * Turn the on-page reader on for this site.
	 */
	private function reader_on() {
		update_option( 'jlife_scripture_native_reader', '1' );
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
	 * Change one day's stored data.
	 *
	 * @param string   $day_id Day.
	 * @param callable $change Gets the day array, returns it changed.
	 */
	private function change_day( $day_id, $change ) {
		$post_id = $this->post_of( $day_id );
		jlife_studies_put_json_meta( $post_id, '_jlife_reading_day', $change( jlife_studies_get_json_meta( $post_id, '_jlife_reading_day' ) ) );
	}

	/**
	 * Standalone page, no theme: Today's shell with the stylesheet, noindex, the
	 * phone viewport, and none of a blog post's chrome or Today's navigation.
	 */
	public function test_the_page_is_a_standalone_document_without_theme_or_participant_chrome() {
		$html = $this->page();
		$this->assertStringStartsWith( '<!doctype html>', $html );
		$this->assertStringContainsString( '<meta name="viewport" content="width=device-width, initial-scale=1">', $html );
		$this->assertStringContainsString( '<meta name="robots" content="noindex, nofollow">', $html );
		$this->assertStringContainsString( 'assets/screens.css?v=22', $html );
		$this->assertSame( 1, substr_count( $html, '<main>' ) );
		foreach ( array(
			'Written by',
			'Hello world',
			'More posts',
			'wp-block',
			'entry-title',
			'post-navigation',
			'jl-nav',
			'jl-signout',
			'<dialog',
			'Sign out',
			'My Group',
			'My Marathon',
			'nav.js',
			'/signout/',
			'wp-content/themes',
		) as $chrome ) {
			$this->assertStringNotContainsString( $chrome, $html, $chrome );
		}
		$this->assertStringContainsString( '<title>Day 1 · The Beginning</title>', $html );
	}

	/**
	 * The layout, in order, signed out: day and date, title, references, the
	 * Bible links, the optional box, the invitation and the source line.
	 */
	public function test_the_layout_is_todays_in_order() {
		$main = $this->main_of( $this->page( 'd001' ) );
		$this->assertStringContainsString( '<p class="jl-day">Day 1 · December 20, 2026</p>', $main );
		$order = array(
			'class="jl-day"',
			'<section class="jl-reading"><h2>The Beginning</h2>',
			'<ul class="jl-passages"><li>Luke 1:1-4</li><li>John 1:1-18</li></ul>',
			'<section class="jl-public-reader">',
			'<details class="jl-optional">',
			'<section class="jl-invite">',
			'<p class="jl-source"><small>Owner-created 104-day schedule',
		);
		$last  = -1;
		foreach ( $order as $needle ) {
			$at = strpos( $main, $needle );
			$this->assertNotFalse( $at, $needle );
			$this->assertGreaterThan( $last, $at, "in order: {$needle}" );
			$last = (int) $at;
		}
		$this->assertStringContainsString( '<summary>Optional: parallel Harmony readings for deeper study</summary><p>Optional. Reading these does not change your points.</p>', $main );
		$this->assertStringContainsString( 'href="https://harmony-bible.com/sections/1/" target="_blank" rel="noopener">Harmony&#039;s section 1:', $main, 'Harmony sections as on Today' );
		$this->assertStringNotContainsString( 'harmony-bible.com', substr( $main, 0, (int) strpos( $main, '<details class="jl-optional">' ) ), 'only inside the optional box' );
		$this->assertSame( 1, substr_count( $main, 'harmony-bible.com/sections/2/' ) );
		$this->assertLessThan( strpos( $main, 'sections/2/' ), strpos( $main, 'sections/1/' ), 'reading order' );
	}

	/**
	 * No frame on a public page (docs/content-rights.md): no iframe, no
	 * sandbox, no data-src; Bible Gateway appears only as links that open in a
	 * new tab.
	 */
	public function test_there_is_no_frame_only_links_in_a_new_tab() {
		foreach ( array( '', 'vi' ) as $lang ) {
			$html = $this->page( 'd038', $lang );
			foreach ( array( '<iframe', 'data-src', 'sandbox=', 'jl-reader-pane', 'data-reader', 'referrerpolicy' ) as $frame ) {
				$this->assertStringNotContainsString( $frame, $html, $frame );
			}
			$mentions = substr_count( $html, 'biblegateway.com' );
			$this->assertGreaterThan( 0, $mentions );
			$this->assertSame( $mentions, preg_match_all( '#<a href="https://www\.biblegateway\.com/[^"]*" target="_blank" rel="noopener">#', $html ), 'every Bible Gateway mention is a new-tab link' );
		}
	}

	/**
	 * Without the on-page reader: one plain link per language that has one,
	 * the visitor's language first, then the other.
	 */
	public function test_the_links_are_one_per_language_in_the_pages_language_order() {
		$en = $this->main_of( $this->page( 'd038' ) );
		$vi = $this->main_of( $this->page( 'd038', 'vi' ) );
		$this->assertStringContainsString( '<h3>Read today&#039;s passages</h3>', $en );
		$this->assertSame( 2, preg_match_all( '#<section class="jl-public-reader">.*?</section>#s', $en . $vi ), 'one reader block per page' );
		foreach ( array( $en, $vi ) as $html ) {
			$this->assertSame( 2, preg_match_all( '#<li lang="(en|vi)"><a href="https://www\.biblegateway\.com/[^"]*version=(ERV|BPT)"#', $html ) );
		}
		$this->assertLessThan( strpos( $en, 'version=BPT' ), strpos( $en, 'version=ERV' ), 'English page: English first' );
		$this->assertLessThan( strpos( $vi, 'version=ERV' ), strpos( $vi, 'version=BPT' ), 'Vietnamese page: Vietnamese first' );
		$this->assertStringContainsString( 'English (ERV)</a>', $en );
		$this->assertStringContainsString( 'Tiếng Việt (BPT)</a>', $en );
		$this->assertStringContainsString( esc_url( 'https://www.biblegateway.com/passage/?search=Mark+6:30-37;John+6:8-15;Mark+6:45;Matthew+14:23-33;Mark+6:53-56&version=ERV' ), $en, 'the owner-tested combined English link, every passage' );
		$this->assertStringContainsString( esc_url( 'https://www.biblegateway.com/passage/?search=Mark+6:30-37;John+6:8-15;Mark+6:45;Matthew+14:23-33;Mark+6:53-56&version=BPT' ), $en, 'one Vietnamese BPT link with every passage, like English (#91)' );
		$this->assertStringNotContainsString( 'bible.com', $en );
	}

	/**
	 * Edge case 1: a language with no link for the day is skipped.
	 */
	public function test_a_language_with_no_link_is_skipped() {
		$this->change_day(
			'd001',
			static function ( $day ) {
				unset( $day['links']['en'] );
				return $day;
			}
		);
		$main = $this->main_of( $this->page( 'd001' ) );
		$this->assertStringNotContainsString( 'version=ERV', $main );
		$this->assertStringNotContainsString( 'English (ERV)', $main );
		$this->assertStringContainsString( 'version=BPT', $main );
		$this->assertSame( 1, substr_count( $main, '<li lang=' ) );

		$this->change_day(
			'd001',
			static function ( $day ) {
				$day['links'] = array();
				return $day;
			}
		);
		$main = $this->main_of( $this->page( 'd001' ) );
		$this->assertStringNotContainsString( 'jl-public-reader', $main, 'no links at all: no empty reader block' );
		$this->assertStringContainsString( '<ul class="jl-passages"><li>Luke 1:1-4</li><li>John 1:1-18</li></ul>', $main, 'the references stay' );
	}

	/**
	 * Edge case 2: no supplemental plan, no optional box.
	 */
	public function test_a_day_without_a_supplemental_plan_has_no_optional_box() {
		$this->assertStringContainsString( 'jl-optional', $this->main_of( $this->page( 'd001' ) ), 'a day with a supplemental plan has the box' );
		delete_post_meta( $this->post_of( 'd038' ), '_jlife_supplemental' );
		$html = $this->main_of( $this->page( 'd038' ) );
		$this->assertStringNotContainsString( 'jl-optional', $html );
		$this->assertStringNotContainsString( 'Optional', $html );
		$this->assertStringContainsString( 'jl-invite', $html );
		$this->assertStringContainsString( 'jl-source', $html );
	}

	/**
	 * A signed-out visitor is invited to join or sign in: plain links to the
	 * existing pages, no invite code or personal data in the URL, no SOAP link.
	 */
	public function test_a_signed_out_visitor_is_invited_to_join_or_sign_in() {
		$main = $this->main_of( $this->page() );
		$this->assertStringContainsString( '<a class="jl-button jl-primary" href="' . esc_url( home_url( '/join/?lang=en' ) ) . '">Join the Bible Marathon</a>', $main );
		$this->assertStringContainsString( '<a class="jl-button" href="' . esc_url( home_url( '/signin/?lang=en' ) ) . '">Sign in</a>', $main );
		$this->assertSame( 2, preg_match_all( '#href="[^"]*/(join|signin)/\?lang=en"#', $main ), 'exact URLs: only the language after the slash' );
		$this->assertStringNotContainsString( '/soap', $main );
		$this->assertStringNotContainsString( 'Write my SOAP', $main );
		$this->assertStringNotContainsString( 'redirect_to', $main );
		$this->assertStringNotContainsString( 'code=', $main );
	}

	/**
	 * #165: a Vietnamese page's invitation opens the Vietnamese join and sign-in
	 * forms (they read `?lang=` for visitors); the language is all it adds.
	 */
	public function test_a_vietnamese_page_invites_to_the_vietnamese_forms() {
		$main = $this->main_of( $this->page( 'd001', 'vi' ) );
		$this->assertStringContainsString( 'href="' . esc_url( home_url( '/join/?lang=vi' ) ) . '"', $main );
		$this->assertStringContainsString( 'href="' . esc_url( home_url( '/signin/?lang=vi' ) ) . '"', $main );
		$this->assertSame( 2, preg_match_all( '#href="[^"]*/(join|signin)/\?lang=vi"#', $main ), 'exact URLs: only the language after the slash' );
		$this->assertStringNotContainsString( '/join/"', $main );
		$this->assertStringNotContainsString( '/signin/"', $main );
	}

	/**
	 * Edge case 3: a signed-in participant who opens the public link gets the
	 * public page plus a clear link to write their SOAP for this day. Nothing
	 * about them appears: not their SOAP, name, email, group, points or status,
	 * and the page is otherwise the same as for everyone.
	 */
	public function test_a_signed_in_participant_gets_the_soap_link_and_nothing_personal() {
		$secret = 'MY-PRIVATE-SOAP-TEXT-xyz';
		$this->assertIsInt( jlife_challenges_save_draft( $this->u['a'], $this->campaign, 'd001', $this->soap( $secret ) ) );
		$this->assertIsInt( jlife_challenges_save_draft( $this->u['b'], $this->campaign, 'd001', $this->soap( 'OTHERS-TEXT-xyz' ) ) );
		$out = $this->page();

		wp_set_current_user( $this->u['a'] );
		$in = $this->page();

		$soap = jlife_challenges_screen_url( 'soap', 'd001' );
		$this->assertStringContainsString( '<a class="jl-button jl-primary" href="' . esc_url( $soap ) . '">Write my SOAP for this day</a>', $in );
		$this->assertStringContainsString( '/soap/?day=d001', $in );
		$this->assertStringNotContainsString( 'Join the Bible Marathon', $in );

		$user = get_userdata( $this->u['a'] );
		foreach ( array( $secret, 'OTHERS-TEXT', 'Group One', 'Group Two', $user->display_name, $user->user_login, $user->user_email, 'Aname', 'Bname', 'Private', ' of 104', 'Draft saved', 'Not started', 'Done', 'data-nonce', '_jlife_soap', 'wp_rest', 'jl_text', '<textarea', '<form', 'jl-signout', 'jl-nav', '/today', '/marathon', '/group', '/share' ) as $personal ) {
			$this->assertStringNotContainsString( $personal, $in, $personal );
		}
		$invite = '#<section class="jl-invite">.*?</section>#s';
		$this->assertSame( preg_replace( $invite, '', $out ), preg_replace( $invite, '', $in ), 'apart from the invitation, signed in or out is the same page' );

		wp_set_current_user( $this->u['outsider'] );
		$this->assertStringContainsString( 'Write my SOAP for this day', $this->page(), 'any signed-in person: the link; the SOAP screen decides what they may do' );
	}

	/**
	 * The page never carries SOAP text, points, group names or participant
	 * data, for a day with entries, scores and shares around it.
	 */
	public function test_the_page_never_contains_soap_points_groups_or_participant_data() {
		$entry = jlife_challenges_save_and_share( $this->u['a'], $this->campaign, 'd001', $this->soap( 'SCORED-SOAP-xyz' ), array( $this->g['g1'] ) );
		$this->assertNotWPError( $entry );
		foreach ( array( '', 'vi' ) as $lang ) {
			foreach ( array( 0, $this->u['a'], $this->u['c'], $this->u['organizer'] ) as $viewer ) {
				wp_set_current_user( $viewer );
				$html = $this->page( 'd001', $lang );
				foreach ( array( 'SCORED-SOAP', 'Group One', 'Group Two', 'Aname', 'Cname', 'Private', ' of 104', 'earned', 'points earned', 'Bible Marathon (test)' ) as $private ) {
					$this->assertStringNotContainsString( $private, $html, "viewer {$viewer}: {$private}" );
				}
			}
		}
	}

	/**
	 * Edge case 4: it works with no JavaScript, because it needs none: no script,
	 * no hidden pane, no noscript fallback, only links.
	 */
	public function test_the_page_needs_no_javascript() {
		foreach ( array( 0, $this->u['a'] ) as $viewer ) {
			wp_set_current_user( $viewer );
			$html = $this->page();
			foreach ( array( '<script', '<noscript', ' hidden', 'data-reader', 'onclick', 'javascript:' ) as $js ) {
				$this->assertStringNotContainsString( $js, $html, $js );
			}
		}
		$this->reader_on();
		$this->assertStringNotContainsString( '<script', $this->page( 'd001', 'vi' ), 'nor with the on-page reader' );
	}

	/**
	 * Edge case 5: Vietnamese. The page's own strings, date order, day title and
	 * references are in Vietnamese on ?lang=vi, and English stays English.
	 */
	public function test_vietnamese_strings_title_and_references() {
		$this->change_day(
			'd001',
			static function ( $day ) {
				$day['i18n'] = array(
					'vi' => array(
						'title'    => 'Khởi đầu',
						'passages' => array( 'Lu-ca 1:1-4', 'Giăng 1:1-18' ),
					),
				);
				return $day;
			}
		);
		$vi = $this->page( 'd001', 'vi' );
		$this->assertStringContainsString( '<html lang="vi">', $vi );
		$main = $this->main_of( $vi );
		$this->assertStringContainsString( '<p class="jl-day">Ngày 1 · 20 tháng 12, 2026</p>', $main );
		$this->assertStringContainsString( '<h2>Khởi đầu</h2>', $main );
		$this->assertStringContainsString( '<ul class="jl-passages"><li>Lu-ca 1:1-4</li><li>Giăng 1:1-18</li></ul>', $main );
		$this->assertStringContainsString( '>Tham gia Marathon Kinh Thánh</a>', $main );
		$this->assertStringContainsString( '>Đăng nhập</a>', $main );
		foreach ( array( 'Join the Bible Marathon', 'Sign in', 'Optional: parallel', 'Harmony Bible sections', "Read today's passages", 'Day 1' ) as $english ) {
			$this->assertStringNotContainsString( $english, $main, "no English left: {$english}" );
		}
		$this->assertStringContainsString( '<title>Ngày 1 · Khởi đầu</title>', $vi );

		$en = $this->main_of( $this->page( 'd001', 'en' ) );
		$this->assertStringContainsString( '<h2>The Beginning</h2>', $en );
		$this->assertStringContainsString( '<ul class="jl-passages"><li>Luke 1:1-4</li><li>John 1:1-18</li></ul>', $en );
		$this->assertStringContainsString( 'Join the Bible Marathon', $en );

		wp_set_current_user( $this->u['a'] );
		$this->assertStringContainsString( 'Viết SOAP của tôi cho ngày này', $this->page( 'd001', 'vi' ) );
	}

	/**
	 * Every new string has a Vietnamese draft in the catalog (the suite's
	 * Test_Translations scans the code for the rest).
	 */
	public function test_the_new_strings_have_vietnamese_drafts() {
		$catalog = include dirname( __DIR__ ) . '/languages/jlife-challenges-vi.l10n.php';
		foreach ( array( 'Join the Bible Marathon', 'Sign in', 'Write my SOAP for this day', 'Read along with us and write your own SOAP.' ) as $key ) {
			$this->assertArrayHasKey( $key, $catalog['messages'], $key );
			$this->assertNotSame( '', trim( $catalog['messages'][ $key ] ) );
		}
	}

	/**
	 * The page's language: ?lang= (en or vi) first, then the signed-in person's
	 * account language, then the site's. An unknown ?lang= is ignored.
	 */
	public function test_page_language_follows_the_switch_then_the_account_then_the_site() {
		$this->assertSame( 'en', jlife_challenges_reading_page_language(), 'site language is English' );
		foreach ( array(
			'vi' => 'vi',
			'VI' => 'vi',
			'en' => 'en',
		) as $asked => $lang ) {
			$_GET['lang'] = $asked;
			$this->assertSame( $lang, jlife_challenges_reading_page_language(), $asked );
		}
		foreach ( array( 'fr', 'vi_VN', '../x', array( 'vi' ) ) as $bad ) {
			$_GET['lang'] = $bad;
			$this->assertSame( 'en', jlife_challenges_reading_page_language(), 'an unknown ?lang= is ignored' );
		}

		$_GET = array();
		$site = static fn() => 'vi';
		add_filter( 'locale', $site );
		$this->assertSame( 'vi', jlife_challenges_reading_page_language(), 'the site language' );
		$_GET['lang'] = 'en';
		$this->assertSame( 'en', jlife_challenges_reading_page_language(), '?lang= wins' );
		$_GET = array();
		wp_set_current_user( $this->u['a'] );
		$this->assertSame( 'vi', jlife_challenges_reading_page_language(), 'a signed-in person with no language set: the site' );
		remove_filter( 'locale', $site );

		update_user_meta( $this->u['a'], 'locale', 'vi' );
		$this->assertSame( 'vi', jlife_challenges_reading_page_language(), 'their account language, as Today does' );
		$_GET['lang'] = 'en';
		$this->assertSame( 'en', jlife_challenges_reading_page_language(), 'the switch still wins' );
		$_GET = array();
		wp_set_current_user( $this->u['b'] );
		update_user_meta( $this->u['b'], 'locale', 'en_US' );
		add_filter( 'locale', $site );
		$this->assertSame( 'en', jlife_challenges_reading_page_language(), 'their English beats a Vietnamese site' );
		remove_filter( 'locale', $site );
	}

	/**
	 * A signed-in participant whose account is Vietnamese gets the Vietnamese
	 * page with no ?lang= at all, and the signed-in link in Vietnamese.
	 */
	public function test_a_vietnamese_account_gets_the_vietnamese_page() {
		update_user_meta( $this->u['a'], 'locale', 'vi' );
		wp_set_current_user( $this->u['a'] );
		$html = $this->page();
		$this->assertStringContainsString( '<html lang="vi">', $html );
		$this->assertStringContainsString( 'Viết SOAP của tôi cho ngày này', $html );
		$this->assertStringContainsString( 'Ngày 1 · 20 tháng 12, 2026', $html );
	}

	/**
	 * The shared renderer: Today, /soap and this page all use the one set of
	 * functions, and the Studies plugin no longer carries a second copy.
	 */
	public function test_there_is_one_renderer_and_no_duplicate_in_studies() {
		foreach ( array( 'jlife_studies_render_reading_day', 'jlife_studies_render_reading_links', 'jlife_studies_render_harmony_sections', 'jlife_studies_render_native_reading', 'jlife_studies_render_reading_language_links', 'jlife_studies_reading_page_language' ) as $gone ) {
			$this->assertFalse( function_exists( $gone ), "{$gone} moved to jlife-challenges" );
		}
		$this->assertFalse( has_filter( 'the_content', 'jlife_studies_render_reading_day' ) );
		$main = $this->main_of( $this->page( 'd001' ) );
		$post = jlife_studies_get_reading_day( self::PLAN, 'd001' );
		$view = array(
			'reading'   => $post,
			'languages' => array( 'en', 'vi' ),
		);
		$this->assertStringContainsString( jlife_challenges_render_harmony_sections( $post['supplemental']['day'] ), $main, 'the Harmony sections come from the same function as Today' );
		$this->assertStringContainsString( jlife_challenges_render_optional_readings( $view['reading'] ), $main );
		$this->assertStringContainsString( jlife_challenges_render_reading_head( $view ), $main );
		$this->assertSame( jlife_challenges_render_reading_head( $view ) . jlife_challenges_render_optional_readings( $view['reading'] ), substr( jlife_challenges_render_reading( $view ), strlen( '<section class="jl-reading">' ), -strlen( '</section>' ) ), 'Today is the same two parts, in one box' );
	}

	/**
	 * Today and /soap look the same as before the shared code: the reading block
	 * and the reader are byte for byte what they were (captured from the code
	 * before this change), for a day with parallel readings, for one without, and
	 * for a day with no reading loaded.
	 */
	public function test_today_and_soap_markup_is_unchanged() {
		$config = array(
			'en' => array(
				'label'  => 'English',
				'notice' => 'ERV notice',
				'embed'  => true,
			),
			'vi' => array(
				'label'  => 'Tiếng Việt',
				'notice' => 'BPT notice',
				'embed'  => false,
			),
			'xx' => array(
				'label' => 'Nolink',
				'embed' => true,
			),
		);
		$day    = array(
			'title'            => 'A | B <i>',
			'passages'         => array( array( 'display' => 'Luke 3:1-6' ), array( 'display' => 'Mark 1:1' ) ),
			'links'            => array(
				'en' => array(
					'version'  => 'ERV',
					'combined' => array( 'url' => 'https://www.biblegateway.com/passage/?search=Luke+3:1-6;Mark+1:1' ),
				),
				'vi' => array(
					'version'  => 'BPT',
					'passages' => array(
						array(
							'label' => 'Luka 3:1-6',
							'url'   => 'https://example.test/a',
						),
						array(
							'label' => 'Luka 3:7',
							'url'   => 'https://example.test/b',
						),
					),
				),
			),
			'harmony_sections' => array(
				array(
					'number' => '20',
					'title'  => 'The Time',
					'url'    => 'https://harmony-bible.com/sections/20/',
				),
				array(
					'number' => '21',
					'title'  => 'Not Harmony',
					'url'    => 'https://evil.test/',
				),
			),
		);
		$full   = array(
			'languages' => array( 'vi', 'en', 'xx' ),
			'reading'   => array(
				'plan'         => array( 'links_config' => $config ),
				'day'          => $day,
				'supplemental' => array( 'day' => $day ),
			),
		);
		$single = array(
			'languages' => array( 'en' ),
			'reading'   => array(
				'plan' => array( 'links_config' => $config ),
				'day'  => $day,
			),
		);
		$none   = array(
			'languages' => array(),
			'reading'   => null,
		);

		$list     = '<ul class="jl-passages"><li>Luke 3:1-6</li><li>Mark 1:1</li></ul>';
		$optional = '<details class="jl-optional"><summary>Optional: parallel Harmony readings for deeper study</summary><p>Optional. Reading these does not change your points.</p><div class="jl-harmony"><h3>Harmony Bible sections</h3><ul><li><a href="https://harmony-bible.com/sections/20/" target="_blank" rel="noopener">Harmony&#039;s section 20: The Time</a></li></ul></div></details>';
		$this->assertSame( '<section class="jl-reading"><h2>A | B &lt;i&gt;</h2>' . $list . $optional . '</section>', jlife_challenges_render_reading( $full ) );
		$this->assertSame( '<section class="jl-reading"><h2>A | B &lt;i&gt;</h2>' . $list . '</section>', jlife_challenges_render_reading( $single ) );
		$this->assertSame( '<p>The reading for this day is not loaded yet.</p>', jlife_challenges_render_reading( $none ) );

		$bg       = 'https://www.biblegateway.com/passage/?search=Luke+3:1-6;Mark+1:1';
		$chips    = '<div class="jl-chips"><a class="jl-chip" href="https://example.test/a" target="_blank" rel="noopener">Luka 3:1-6</a><a class="jl-chip" href="https://example.test/b" target="_blank" rel="noopener">Luka 3:7</a></div>';
		$frame    = '<iframe title="Today&#039;s passages, English (ERV)" data-src="' . $bg . '" sandbox="allow-scripts allow-same-origin allow-popups allow-popups-to-escape-sandbox allow-forms" referrerpolicy="strict-origin-when-cross-origin"></iframe>'
			. '<p class="jl-reader-fail" role="status" hidden>The reader could not load here. Use the Bible Gateway link below; your SOAP stays on this page.</p>'
			. '<p class="jl-reader-note"><small>ERV notice. Shown from Bible Gateway.</small> <a href="' . $bg . '" target="_blank" rel="noopener">Open in Bible Gateway</a></p>';
		$noscript = '<noscript><p>The reader needs JavaScript. Open today&#039;s passages in Bible Gateway instead:</p><ul>%s</ul></noscript></details>';
		$en_item  = '<li lang="en"><a href="' . $bg . '" target="_blank" rel="noopener">English (ERV)</a></li>';
		$this->assertSame(
			'<details class="jl-reader"><summary>Read today&#039;s passages</summary>'
			. '<div class="jl-chips" role="group" aria-label="Bible version"><button type="button" class="jl-chip" data-reader-lang="vi" aria-pressed="true">Tiếng Việt (BPT)</button><button type="button" class="jl-chip" data-reader-lang="en" aria-pressed="false">English (ERV)</button></div>'
			. '<div class="jl-reader-pane" data-reader-pane="vi" lang="vi"><div class="jl-reader-links">' . $chips . '</div></div>'
			. '<div class="jl-reader-pane" data-reader-pane="en" lang="en" hidden>' . $frame . '</div>'
			. sprintf( $noscript, '<li lang="vi">Tiếng Việt (BPT) ' . $chips . '</li>' . $en_item ),
			jlife_challenges_render_reader( $full )
		);
		$this->assertSame(
			'<details class="jl-reader"><summary>Read today&#039;s passages</summary>'
			. '<div class="jl-reader-pane" data-reader-pane="en" lang="en">' . $frame . '</div>'
			. sprintf( $noscript, $en_item ),
			jlife_challenges_render_reader( $single )
		);
		$this->assertSame( '', jlife_challenges_render_reader( $none ) );
	}

	/**
	 * The stylesheet version is 21 everywhere it is linked (#188, #211), and the stylesheet
	 * has what the public page needs (and dark tokens, which the page reuses).
	 */
	public function test_the_stylesheet_is_version_21_everywhere() {
		$files = array_merge( glob( dirname( __DIR__ ) . '/includes/*.php' ), array( dirname( __DIR__ ) . '/jlife-challenges.php' ) );
		$seen  = 0;
		foreach ( $files as $file ) {
			$src = (string) file_get_contents( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- reading the plugin's own source.
			preg_match_all( '/screens\.css\?v=(\d+)/', $src, $m );
			foreach ( $m[1] as $version ) {
				$this->assertSame( '22', $version, basename( $file ) );
				++$seen;
			}
		}
		$this->assertGreaterThanOrEqual( 2, $seen, 'the shell (also the public page) and the unsubscribe page' );
		$css = (string) file_get_contents( dirname( __DIR__ ) . '/assets/screens.css' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- plugin asset.
		foreach ( array( '.jl-invite', '.jl-public-reader', '.jl-reading-lang', '.jl-source', 'prefers-color-scheme:dark' ) as $needle ) {
			$this->assertStringContainsString( $needle, $css, $needle );
		}
	}

	/**
	 * The "Send to a chat" link is unchanged: the day's page, with no query or
	 * fragment, on this site, and the page's address is still /reading/<plan>-dNNN/.
	 */
	public function test_the_share_url_and_slug_are_unchanged() {
		$this->set_permalink_structure( '/%postname%/' );
		jlife_studies_register_reading_day();
		$post = jlife_studies_get_reading_day( self::PLAN, 'd001' );
		$this->assertSame( 'life-and-teachings-of-jesus-2026-d001', get_post_field( 'post_name', $post['post_id'] ) );
		$this->assertSame( home_url( '/reading/life-and-teachings-of-jesus-2026-d001/' ), $post['url'], 'the address people already have' );
		$view = array( 'reading' => $post );
		$url  = jlife_challenges_public_reading_url( $view );
		$this->assertSame( $post['url'], $url );
		$view['reading']['url'] = add_query_arg( 'lang', 'vi', $post['url'] ) . '#x';
		$this->assertSame( $url, jlife_challenges_public_reading_url( $view ), 'a query or fragment is cut' );
		$this->set_permalink_structure( '' );
	}

	/**
	 * Only an imported reading day gets this page; any other post is left alone.
	 */
	public function test_only_reading_days_get_the_page() {
		$other = self::factory()->post->create( array( 'post_title' => 'Hello world!' ) );
		$this->assertNull( jlife_challenges_public_reading_page_html( $other ) );
		$this->assertNull( jlife_challenges_public_reading_page_html( 0 ) );
		$bare = self::factory()->post->create(
			array(
				'post_type'   => JLIFE_READING_DAY_CPT,
				'post_status' => 'publish',
			)
		);
		$this->assertNull( jlife_challenges_public_reading_page_html( $bare ), 'a day with no imported data: the page is not made up' );
	}

	/**
	 * Output is escaped.
	 */
	public function test_titles_and_references_are_escaped() {
		$this->change_day(
			'd001',
			static function ( $day ) {
				$day['title']                  = '<script>alert(1)</script>';
				$day['passages'][0]['display'] = '<b onmouseover=x>John</b>';
				return $day;
			}
		);
		$html = $this->page();
		$this->assertStringNotContainsString( '<script>', $html );
		$this->assertStringNotContainsString( '<b onmouseover', $html );
		$this->assertStringContainsString( '&lt;script&gt;', $html );
	}

	/**
	 * With the on-page reader on, Vietnamese: Kinh Thánh 1925 on the page with
	 * verse numbers and its copyright line, the reader's own passage list instead
	 * of the plain one, the English | Tiếng Việt switch, no frame, no link block.
	 */
	public function test_vietnamese_page_shows_the_1925_text_with_verse_numbers_and_copyright() {
		if ( ! function_exists( 'jlife_scripture_maybe_render_day' ) ) {
			$this->markTestSkipped( 'jlife-scripture is not loaded.' );
		}
		$this->reader_on();
		$html = $this->page( 'd001', 'vi' );
		$main = $this->main_of( $html );

		$this->assertStringContainsString( '<section class="jls-reader" lang="vi"', $main );
		$this->assertStringContainsString( '<section class="jls-passage"><h3 class="jls-ref">Luke 1:1-4</h3>', $main );
		$this->assertStringContainsString( '<section class="jls-passage"><h3 class="jls-ref">John 1:1-18</h3>', $main );
		$this->assertLessThan( strpos( $main, '>John 1:1-18</h3>' ), strpos( $main, '>Luke 1:1-4</h3>' ), 'Luke comes before John (#219)' );
		$this->assertStringNotContainsString( 'jls-passage" open', $main, 'no box to open (#188)' );
		$this->assertStringContainsString( '<sup class="jls-num">1</sup>', $main );
		$this->assertStringContainsString( '<sup class="jls-num">18</sup>', $main );
		$this->assertStringContainsString( 'Ngôi Lời', $main );
		$this->assertStringContainsString( '<span class="jls-version-name">Kinh Thánh 1925 (Bản Truyền Thống)</span>', $main );
		$this->assertStringContainsString( 'thuộc phạm vi công cộng', $main, 'the copyright line renders' );
		$this->assertStringNotContainsString( '<iframe', $html );
		$this->assertStringNotContainsString( 'jl-public-reader', $main, 'the reader replaces the link block' );
		$this->assertStringNotContainsString( 'jl-passages', $main, 'the reader lists each reference itself' );
		$this->assertLessThan( strpos( $main, 'jls-reader' ), strpos( $main, '<h2>' ), 'the title above the reader' );
		$this->assertLessThan( strpos( $main, 'class="jl-optional"' ), strpos( $main, 'jls-reader' ), 'the reader above the optional box' );
		$this->assertStringContainsString( 'class="jl-source"', $main );
		$this->assertTrue( wp_style_is( 'jlife-scripture', 'enqueued' ) );
		$this->assertStringContainsString( 'scripture.css', $html, 'the standalone page prints the reader stylesheet' );
		$this->assertSame( '', $this->logged(), 'nothing logged, no Bible text' );
	}

	/**
	 * The English | Tiếng Việt switch: both languages, the current one marked,
	 * each a plain link to this page with ?lang= (no JavaScript, cache-safe).
	 */
	public function test_language_links_switch_the_page_language() {
		if ( ! function_exists( 'jlife_scripture_maybe_render_day' ) ) {
			$this->markTestSkipped( 'jlife-scripture is not loaded.' );
		}
		$this->reader_on();
		$post_id = $this->post_of( 'd001' );
		$base    = get_permalink( $post_id );
		$main    = $this->main_of( $this->page( 'd001', 'vi' ) );
		$this->assertStringContainsString( '<p class="jl-reading-lang">', $main );
		$this->assertStringContainsString( '<a href="' . esc_url( add_query_arg( 'lang', 'en', $base ) ) . '" hreflang="en" lang="en">English</a>', $main );
		$this->assertStringContainsString( '<a href="' . esc_url( add_query_arg( 'lang', 'vi', $base ) ) . '" hreflang="vi" lang="vi" aria-current="true">Tiếng Việt</a>', $main );
		$main = $this->main_of( $this->page( 'd001', 'en' ) );
		$this->assertStringContainsString( 'hreflang="en" lang="en" aria-current="true">English</a>', $main );
		$this->assertStringNotContainsString( 'hreflang="vi" lang="vi" aria-current', $main );
		$this->assertLessThan( strpos( $main, 'jls-reader' ), strpos( $main, 'jl-reading-lang' ), 'the switch sits above the reader' );
	}

	/**
	 * English: the NLT through API.Bible: its text with verse numbers, the API's
	 * copyright notice and the link to API.Bible; no Bible Gateway link, no frame.
	 */
	public function test_english_page_shows_the_nlt_through_the_api() {
		if ( ! function_exists( 'jlife_scripture_maybe_render_day' ) ) {
			$this->markTestSkipped( 'jlife-scripture is not loaded.' );
		}
		$this->reader_on();
		foreach ( array( 'en', '' ) as $lang ) {
			$html = $this->page( 'd001', $lang );
			$this->assertStringContainsString( '<section class="jls-reader" lang="en"', $html, "lang {$lang}" );
			$this->assertStringContainsString( '<span class="jls-version-name">New Living Translation</span>', $html );
			$this->assertStringContainsString( '<sup class="jls-num">1</sup> FAKE-VERSE-1 for tests only.', $html );
			$this->assertStringContainsString( self::COPYRIGHT, $html, 'the notice the API returned renders' );
			$this->assertStringContainsString( 'href="https://api.bible"', $html, 'the Starter plan link' );
			$this->assertStringNotContainsString( 'Open in Bible Gateway', $html );
			$this->assertStringNotContainsString( '<iframe', $html );
			$this->assertStringNotContainsString( self::KEY, $html );
		}
		$this->assertSame( '', $this->logged(), 'nothing logged' );
	}

	/**
	 * If API.Bible cannot answer, the page keeps the short note and the ERV link
	 * with ERV's copyright line, and the rest of the page is intact.
	 */
	public function test_english_page_falls_back_to_the_erv_link_when_the_api_cannot_answer() {
		if ( ! function_exists( 'jlife_scripture_maybe_render_day' ) ) {
			$this->markTestSkipped( 'jlife-scripture is not loaded.' );
		}
		$this->reader_on();
		$this->api_status = 403;
		$html             = $this->page( 'd001', 'en' );
		$this->assertStringContainsString( 'The Bible text could not be loaded.', $html );
		$this->assertStringContainsString( 'Open in Bible Gateway (ERV)', $html );
		$this->assertStringContainsString( 'href="https://www.biblegateway.com/passage/?search=John+1:1-18&#038;version=ERV"', $html );
		$this->assertStringContainsString( 'Easy-to-Read Version (ERV) Copyright © 2006 by Bible League International', $html );
		$this->assertStringContainsString( 'class="jl-optional"', $html );
		$this->assertStringContainsString( 'class="jl-invite"', $html );
		$this->assertStringNotContainsString( '<iframe', $html );
		$this->assertStringNotContainsString( self::KEY, $this->logged() );
	}

	/**
	 * A signed-in participant's stored My Bible choice is never used: the reader
	 * on the page is the page language's default, the same as for a visitor, so
	 * only the invitation differs between the two.
	 */
	public function test_a_signed_in_visitors_stored_choice_is_never_used() {
		if ( ! function_exists( 'jlife_scripture_maybe_render_day' ) ) {
			$this->markTestSkipped( 'jlife-scripture is not loaded.' );
		}
		$this->reader_on();
		update_user_meta( $this->u['a'], 'jlife_bible_version', 'erv' );
		update_user_meta( $this->u['a'], 'jlife_bible_version_2', 'bpt' );
		$invite = '#<section class="jl-invite">.*?</section>#s';
		foreach ( array( 'vi', 'en' ) as $lang ) {
			wp_set_current_user( 0 );
			$out = $this->page( 'd001', $lang );
			wp_set_current_user( $this->u['a'] );
			$in = $this->page( 'd001', $lang );
			$this->assertSame( preg_replace( $invite, '', $out ), preg_replace( $invite, '', $in ), "{$lang}: the same page for everyone" );
			$this->assertStringNotContainsString( 'jls-edition', $in, 'no second Bible' );
		}
		wp_set_current_user( $this->u['a'] );
		$this->assertStringContainsString( 'Kinh Thánh 1925', $this->page( 'd001', 'vi' ) );
		$this->assertStringContainsString( 'New Living Translation', $this->page( 'd001', 'en' ) );
	}
}
