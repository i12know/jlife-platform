<?php
/**
 * Vietnamese for the sign-in pages (#112 part 2, D13): /join, /signin and
 * /consent render in Vietnamese for a signed-in person whose chosen language is
 * Vietnamese and for a visitor who picks it; every string has a (draft)
 * translation.
 *
 * @package jlife-auth
 */

// phpcs:disable WordPress.Security.NonceVerification -- tests set request globals.

/**
 * Vietnamese tests.
 */
class Test_Vietnamese extends WP_UnitTestCase {

	/**
	 * Create the engine's tables once.
	 */
	public static function wpSetUpBeforeClass() {
		jlife_challenges_install_tables();
	}

	/**
	 * A clean mailbox.
	 */
	public function set_up() {
		parent::set_up();
		reset_phpmailer_instance();
		add_filter( 'wp_mail_from', static fn() => 'jlife@example.test' );
		// The suite loads the plugins from the repository, outside WP_PLUGIN_DIR, so point WordPress's
		// translation loader at their languages folders (a real install finds them through the plugin path).
		add_filter(
			'lang_dir_for_domain',
			static function ( $path, $domain ) {
				$dirs = array(
					'jlife-auth'       => dirname( __DIR__ ) . '/languages/',
					'jlife-challenges' => dirname( __DIR__, 2 ) . '/jlife-challenges/languages/',
				);
				return $dirs[ $domain ] ?? $path;
			},
			10,
			2
		);
		jlife_auth_refresh_translations();
	}

	/**
	 * Back to a signed-out English request.
	 */
	public function tear_down() {
		wp_set_current_user( 0 );
		$_GET                      = array();
		$_POST                     = array();
		$_REQUEST                  = array();
		$_SERVER['REQUEST_URI']    = '/';
		$_SERVER['REQUEST_METHOD'] = 'GET';
		jlife_auth_refresh_translations();
		parent::tear_down();
	}

	/**
	 * A participant whose chosen language is Vietnamese, signed in.
	 *
	 * @return int
	 */
	private function vi_user() {
		$id = self::factory()->user->create(
			array(
				'role'   => 'subscriber',
				'locale' => 'vi',
			)
		);
		wp_set_current_user( $id );
		jlife_auth_refresh_translations();
		return $id;
	}

	/**
	 * Visit one of the sign-in pages as a signed-out visitor.
	 *
	 * @param string $path Path, e.g. /join/.
	 * @param array  $get  Query string values.
	 */
	private function visit( $path, $get = array() ) {
		$_SERVER['REQUEST_URI']    = $path . ( $get ? '?' . http_build_query( $get ) : '' );
		$_SERVER['REQUEST_METHOD'] = 'GET';
		$_GET                      = $get;
		jlife_auth_refresh_translations();
	}

	/**
	 * The strings a source file passes to the translation functions.
	 *
	 * @param string $dir Plugin includes directory.
	 * @return string[]
	 */
	private function source_strings( $dir ) {
		$found = array();
		foreach ( glob( $dir . '/*.php' ) as $file ) {
			$src = (string) file_get_contents( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- reading the plugin's own source.
			preg_match_all( '/\b(?:esc_html__|esc_attr__|esc_html_e|esc_attr_e|__|_e)\(\s*(\'(?:[^\'\\\\]|\\\\.)*\'|"(?:[^"\\\\]|\\\\.)*")/s', $src, $m );
			foreach ( $m[1] as $quoted ) {
				$body    = substr( $quoted, 1, -1 );
				$found[] = "'" === $quoted[0] ? str_replace( array( "\\'", '\\\\' ), array( "'", '\\' ), $body ) : stripcslashes( $body );
			}
		}
		return array_values( array_unique( $found ) );
	}

	/**
	 * Every user-facing string in jlife-auth has a Vietnamese draft.
	 */
	public function test_every_auth_string_has_a_vietnamese_translation() {
		$catalog = include dirname( __DIR__ ) . '/languages/jlife-auth-vi.l10n.php';
		$this->assertSame( 'jlife-auth', $catalog['domain'] );
		$missing = array_values( array_diff( $this->source_strings( dirname( __DIR__ ) . '/includes' ), array_keys( $catalog['messages'] ) ) );
		$this->assertSame( array(), $missing, 'untranslated: ' . implode( ' | ', $missing ) );
		foreach ( $catalog['messages'] as $english => $vi ) {
			$this->assertNotSame( '', trim( $vi ) );
			$this->assertSame( substr_count( $english, '%s' ), substr_count( $vi, '%s' ), "placeholders kept: {$english}" );
		}
	}

	/**
	 * The catalogs are plainly marked as drafts that need a translator.
	 */
	public function test_the_catalogs_are_marked_as_drafts_for_review() {
		foreach ( array( dirname( __DIR__ ) . '/languages/jlife-auth-vi.l10n.php', dirname( __DIR__, 2 ) . '/jlife-challenges/languages/jlife-challenges-vi.l10n.php' ) as $file ) {
			$head = substr( (string) file_get_contents( $file ), 0, 2500 ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- reading the plugin's own source.
			$this->assertStringContainsString( 'DRAFT', $head, basename( $file ) );
			$this->assertStringContainsString( 'translator', $head, basename( $file ) );
		}
	}

	/**
	 * /join, /signin and /consent render in Vietnamese for a signed-in person who chose it (D13).
	 */
	public function test_pages_render_in_vietnamese_for_a_vietnamese_user() {
		$this->vi_user();
		$this->assertSame( 'vi', determine_locale() );

		$join = jlife_auth_join_form( 'invite', array(), '' );
		$this->assertStringContainsString( 'Mã mời vào nhóm', $join );
		$this->assertStringContainsString( 'Tên của bạn', $join );
		$this->assertStringContainsString( '18 tuổi trở lên', $join );
		$this->assertStringContainsString( 'Đọc một mình', $join );
		$this->assertStringContainsString( 'Lập nhóm', $join );
		$this->assertStringNotContainsString( 'Group invite code', $join );
		$this->assertStringContainsString( '<option value="vi" selected', $join, 'the form pre-selects the page language' );

		$start = jlife_auth_join_form( 'start', array(), '' );
		$this->assertStringContainsString( 'Tên nhóm', $start );
		$this->assertStringContainsString( 'Dành cho người từ 18 tuổi trở lên', $start );

		$signin = jlife_auth_signin_body();
		$this->assertStringContainsString( 'Đăng nhập', $signin );
		$this->assertStringContainsString( 'Gửi mã đăng nhập cho tôi', $signin );
		$this->assertStringNotContainsString( 'No password needed', $signin );

		$consent = jlife_auth_consent_body();
		$this->assertStringContainsString( 'Trước khi bắt đầu', $consent );
		$this->assertStringContainsString( 'riêng tư', $consent );
		$this->assertStringContainsString( 'Bắt đầu Marathon', $consent );
		$this->assertStringNotContainsString( 'Before you start', $consent );
		$this->assertStringContainsString( 'cầu nguyện', $consent, 'sharing includes the Prayer part' );
		$this->assertStringNotContainsString( 'những phần bạn đã chọn', $consent );
		$this->assertStringContainsString( 'Nếu bạn chọn nhận nhắc nhở hằng ngày', $consent, 'reminders are opt-in wording' );
		$this->assertStringNotContainsString( 'Chúng tôi sẽ gửi email bài đọc mỗi ngày', $consent );
	}

	/**
	 * A signed-in person who chose English keeps English, whatever the link says.
	 */
	public function test_an_english_user_stays_in_english_even_with_a_lang_link() {
		$id = self::factory()->user->create(
			array(
				'role'   => 'subscriber',
				'locale' => 'en_US',
			)
		);
		wp_set_current_user( $id );
		$this->visit( '/consent/', array( 'lang' => 'vi' ) );
		$this->assertSame( 'en_US', determine_locale() );
		$this->assertStringContainsString( 'Before you start', jlife_auth_consent_body() );
	}

	/**
	 * The Language setting on My Marathon (#159) switches a participant's screens
	 * from the next request; signed-out pages keep the site language and `?lang=`.
	 */
	public function test_the_my_marathon_language_setting_switches_screens_and_leaves_visitors_alone() {
		$organizer = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$campaign  = jlife_challenges_create_campaign(
			$organizer,
			array(
				'campaign_key' => 'lang-' . wp_generate_password( 6, false ),
				'plan_id'      => 'rpl-life-and-teachings-of-jesus-2026',
				'title'        => 'Bible Marathon (test)',
				'start_date'   => '2026-12-20',
				'end_date'     => '2027-04-02',
				'status'       => 'active',
			)
		);
		$id        = self::factory()->user->create(
			array(
				'role'   => 'subscriber',
				'locale' => 'en_US',
			)
		);
		$this->assertIsInt( jlife_challenges_enroll_solo( $id, $campaign, 'adult' ) );
		wp_set_current_user( $id );
		$this->visit( '/marathon/' );
		$this->assertSame( 'en_US', determine_locale() );

		$this->assertTrue( jlife_challenges_set_language( $id, $id, 'vi' ) );
		$this->visit( '/marathon/' );
		$this->assertSame( 'vi', determine_locale(), 'the screens follow the new language' );
		$this->visit( '/consent/', array( 'lang' => 'en' ) );
		$this->assertSame( 'vi', determine_locale(), 'a signed-in person keeps their language whatever the link says' );

		// Signed out: unchanged behavior.
		wp_set_current_user( 0 );
		$this->visit( '/join/' );
		$this->assertSame( 'en_US', determine_locale(), 'a visitor gets the site language' );
		$this->visit( '/join/', array( 'lang' => 'vi' ) );
		$this->assertSame( 'vi', determine_locale(), '?lang= still picks it on the sign-in pages' );
		$this->visit( '/signin/', array( 'lang' => 'en' ) );
		$this->assertSame( 'en_US', determine_locale() );
		$this->visit( '/today/', array( 'lang' => 'vi' ) );
		$this->assertSame( 'en_US', determine_locale(), 'and only there' );

		// Back to English from Vietnamese.
		wp_set_current_user( $id );
		$this->assertTrue( jlife_challenges_set_language( $id, $id, 'en_US' ) );
		$this->visit( '/marathon/' );
		$this->assertSame( 'en_US', determine_locale() );
	}

	/**
	 * A visitor who is not signed in picks the language with ?lang= on the sign-in pages, and only there.
	 */
	public function test_a_visitor_can_pick_vietnamese_on_the_sign_in_pages() {
		$this->visit( '/join/' );
		$this->assertSame( 'en_US', determine_locale(), 'the site language by default' );
		$this->assertStringContainsString( 'Group invite code', jlife_auth_join_form( 'invite', array(), '' ) );

		foreach ( array( '/join/', '/signin/', '/consent/', '/sub/join' ) as $path ) {
			$this->visit( $path, array( 'lang' => 'vi' ) );
			$this->assertSame( 'vi', determine_locale(), $path );
		}
		$this->visit( '/join/', array( 'lang' => 'vi' ) );
		$this->assertStringContainsString( 'Mã mời vào nhóm', jlife_auth_join_form( 'invite', array(), '' ) );
		$this->assertStringContainsString( 'Đăng nhập', jlife_auth_signin_body() );
		$this->assertStringContainsString( '<option value="vi" selected', jlife_auth_join_form( 'invite', array(), '' ) );

		$this->visit( '/join/', array( 'lang' => 'en' ) );
		$this->assertSame( 'en_US', determine_locale() );
		$this->visit( '/join/', array( 'lang' => '<script>' ) );
		$this->assertSame( 'en_US', determine_locale(), 'anything else is ignored' );

		$this->visit( '/today/', array( 'lang' => 'vi' ) );
		$this->assertSame( 'en_US', determine_locale(), 'not a sign-in page' );
	}

	/**
	 * The language links appear for visitors on a page view, point at ?lang=, and never for signed-in people or after a POST.
	 */
	public function test_language_links() {
		$this->visit( '/join/' );
		$html = jlife_auth_language_links();
		$this->assertStringContainsString( 'lang=en', $html );
		$this->assertStringContainsString( 'lang=vi', $html );
		$this->assertStringContainsString( 'Tiếng Việt', $html );

		$_SERVER['REQUEST_METHOD'] = 'POST';
		$this->assertSame( '', jlife_auth_language_links(), 'a form that was just sent keeps what was typed' );

		$this->vi_user();
		$_SERVER['REQUEST_METHOD'] = 'GET';
		$this->assertSame( '', jlife_auth_language_links(), 'signed-in people see their own language' );
	}

	/**
	 * A language picked with ?lang= carries over to the links between the sign-in
	 * pages (Codex QA 2026-11-19: "Already joined? Sign in." opened English).
	 */
	public function test_the_picked_language_follows_the_links_between_sign_in_pages() {
		$this->visit(
			'/join/',
			array(
				'mode' => 'solo',
				'lang' => 'vi',
			)
		);
		$join = jlife_auth_join_form( 'solo', array(), '' );
		$this->assertStringContainsString( 'Đã tham gia rồi? Đăng nhập.', $join );
		$this->assertMatchesRegularExpression( '#href="[^"]*/signin/\?lang=vi"#', $join, 'sign-in link keeps Vietnamese' );
		$this->assertMatchesRegularExpression( '#href="[^"]*/join/\?lang=vi&\#038;mode=start"#', $join, 'mode links keep Vietnamese' );

		$this->visit( '/signin/', array( 'lang' => 'vi' ) );
		$this->assertMatchesRegularExpression( '#href="[^"]*/join/\?lang=vi"#', jlife_auth_signin_body() );

		$this->visit( '/signin/', array( 'lang' => 'xx' ) );
		$this->assertStringNotContainsString( 'lang=', jlife_auth_keep_lang( home_url( '/join/' ) ), 'only a known language' );

		$this->visit( '/signin/' );
		$this->assertSame( home_url( '/join/' ), jlife_auth_keep_lang( home_url( '/join/' ) ), 'nothing picked, nothing added' );
	}

	/**
	 * Pretend the visitor sent a form on a sign-in page.
	 *
	 * @param string $path Path, e.g. /join/.
	 * @param array  $post Form values.
	 * @param array  $get  Query string values.
	 */
	private function submit( $path, $post, $get = array() ) {
		$this->visit( $path, $get );
		$_SERVER['REQUEST_METHOD'] = 'POST';
		$_POST                     = $post;
		jlife_auth_refresh_translations();
	}

	/**
	 * First render in Vietnamese from a Vietnamese link: the page, its `lang` and all three ways in (#222).
	 */
	public function test_join_opens_in_vietnamese_on_first_load_for_all_three_paths() {
		foreach ( array( 'invite', 'solo', 'start' ) as $mode ) {
			$this->visit(
				'/join/',
				array(
					'mode' => $mode,
					'lang' => 'vi',
				)
			);
			$form = jlife_auth_join_form( $mode, array(), '' );
			$page = jlife_auth_page_html( 'x', $form );
			$this->assertStringContainsString( '<html lang="vi">', $page, $mode );
			$this->assertStringContainsString( 'Ngôn ngữ', $form, $mode );
			$this->assertStringContainsString( 'Độ tuổi của bạn', $form, $mode );
			$this->assertStringContainsString( 'Gửi mã đăng nhập cho tôi', $form, $mode );
			$this->assertStringContainsString( 'Đã tham gia rồi? Đăng nhập.', $form, $mode );
			$this->assertStringNotContainsString( 'Your age', $form, $mode );
			$this->assertStringNotContainsString( 'First name', $form, $mode );
		}
		$this->visit( '/join/' );
		$this->assertStringContainsString( '<html lang="en-US">', jlife_auth_page_html( 'x', jlife_auth_join_form( 'invite', array(), '' ) ), 'English is unchanged' );
	}

	/**
	 * The switch is a set of buttons that send the form to itself: no field is lost, and the email is never in an address.
	 */
	public function test_the_language_switch_keeps_what_was_typed_and_never_puts_the_email_in_the_url() {
		$this->visit( '/join/', array( 'code' => 'ABCD2345' ) );
		$in   = array(
			'invite_code' => 'ABCD2345',
			'first_name'  => 'Linh',
			'email'       => 'linh@example.test',
			'age_band'    => 'minor',
		);
		$page = jlife_auth_page_html( 'x', jlife_auth_join_form( 'invite', $in, '' ) );
		$this->assertStringContainsString( 'id="jl-form"', $page );
		$this->assertMatchesRegularExpression( '#<button type="submit" form="jl-form" name="jl_relang" value="vi" formaction="[^"]*lang=vi[^"]*" formnovalidate#', $page );
		$this->assertStringNotContainsString( 'linh@example.test', (string) preg_replace( '#<input[^>]*>#', '', $page ), 'the email appears only inside its field' );

		// The switch press arrives as a POST: the page is Vietnamese and the typed values come back.
		$this->submit(
			'/join/',
			array_merge(
				$in,
				array(
					'step'      => 'request',
					'mode'      => 'invite',
					'locale'    => 'en_US',
					'jl_relang' => 'vi',
				)
			),
			array( 'lang' => 'vi' )
		);
		$this->assertTrue( jlife_auth_is_relang() );
		$this->assertSame( 'vi', determine_locale() );
		$this->assertSame( 'vi', jlife_auth_default_form_locale(), 'the language field follows the page' );
		$back = jlife_auth_join_form(
			'invite',
			array_merge( $in, array( 'locale' => jlife_auth_default_form_locale() ) ),
			''
		);
		$this->assertStringContainsString( 'value="ABCD2345"', $back );
		$this->assertStringContainsString( 'value="Linh"', $back );
		$this->assertStringContainsString( 'value="linh@example.test"', $back );
		$this->assertMatchesRegularExpression( '#value="minor" checked#', $back );
		$this->assertStringContainsString( '<option value="vi" selected', $back );
		$this->assertStringContainsString( '13 đến 17 tuổi', $back, 'the age choices are in Vietnamese' );

		// And back to English the same way.
		$this->submit(
			'/join/',
			array_merge(
				$in,
				array(
					'step'      => 'request',
					'jl_relang' => 'en',
					'locale'    => 'vi',
				)
			),
			array( 'lang' => 'en' )
		);
		$this->assertSame( 'en_US', determine_locale(), 'the button beats the field' );
		$this->assertTrue( jlife_auth_is_relang() );
	}

	/**
	 * Choosing Tiếng Việt in the Language field makes the answer (errors, the code page) Vietnamese too, and the saved account language equals it.
	 */
	public function test_the_language_field_switches_the_interface_and_is_what_is_saved() {
		$this->submit(
			'/join/',
			array(
				'step'   => 'request',
				'mode'   => 'invite',
				'locale' => 'vi',
			)
		);
		$this->assertSame( 'vi', determine_locale(), 'a field of vi without ?lang= is still Vietnamese' );
		$this->assertFalse( jlife_auth_is_relang(), 'it is a real request, not a switch' );
		$err = jlife_auth_start_join(
			array(
				'mode'       => 'invite',
				'first_name' => 'Linh',
				'locale'     => 'vi',
			)
		);
		$this->assertWPError( $err );
		$this->assertStringContainsString( 'Vui lòng chọn độ tuổi', $err->get_error_message(), 'the age rule answers in Vietnamese' );
		$this->assertStringContainsString( 'Vui lòng chọn độ tuổi', jlife_auth_join_form( 'invite', array(), $err->get_error_message() ), 'the error is shown on the form' );

		// The code page and the sign-in form carry the language on, whatever the address says.
		$this->assertStringContainsString( 'name="jl_lang" value="vi"', jlife_auth_code_form( 'a@example.test' ) );
		$this->submit(
			'/join/',
			array(
				'step'    => 'verify',
				'jl_lang' => 'vi',
			),
			array( 'lang' => 'en' )
		);
		$this->assertSame( 'vi', determine_locale(), 'the form’s own language beats a stale ?lang=' );
		$this->submit(
			'/signin/',
			array(
				'step'    => 'request',
				'jl_lang' => 'vi',
			)
		);
		$this->assertSame( 'vi', determine_locale() );

		// The dropdown only counts on the join request; a spoofed one elsewhere is ignored.
		$this->submit(
			'/signin/',
			array(
				'step'   => 'request',
				'locale' => 'vi',
			)
		);
		$this->assertSame( 'en_US', determine_locale() );

		// A Vietnamese choice is saved as the account language.
		$admin    = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$campaign = jlife_challenges_create_campaign(
			$admin,
			array(
				'campaign_key' => 'vi3-' . wp_generate_password( 6, false ),
				'plan_id'      => 'rpl-test',
				'title'        => 'Test',
				'start_date'   => '2026-12-20',
				'end_date'     => '2027-04-02',
				'status'       => 'active',
			)
		);
		$this->assertIsInt( $campaign );
		$this->visit( '/join/' );
		$this->assertTrue(
			jlife_auth_start_join(
				array(
					'mode'       => 'solo',
					'email'      => 'vi-saved@example.test',
					'first_name' => 'Linh',
					'age_band'   => 'adult',
					'locale'     => 'vi',
				)
			)
		);
		$sent = tests_retrieve_phpmailer_instance()->mock_sent;
		preg_match( '/\b(\d{6})\b/', end( $sent )['body'], $m );
		$r = jlife_auth_complete( 'vi-saved@example.test', $m[1] );
		$this->assertSame( 'vi', get_user_meta( $r['user_id'], 'locale', true ), 'the saved account language equals the choice' );
	}

	/**
	 * Staying on /signin/ or /consent/ and the links between the pages keep a language chosen in a form.
	 */
	public function test_links_keep_a_language_chosen_in_a_form() {
		$this->submit(
			'/join/',
			array(
				'step'   => 'request',
				'locale' => 'vi',
			)
		);
		$this->assertMatchesRegularExpression( '#/signin/\?lang=vi#', jlife_auth_keep_lang( home_url( '/signin/' ) ) );
		$this->vi_user();
		$this->assertSame( home_url( '/signin/' ), jlife_auth_keep_lang( home_url( '/signin/' ) ), 'signed-in people get nothing added' );
	}

	/**
	 * The switch is a set of buttons only where a form exists; the sign-in form keeps the email; a shared Vietnamese invite link is Vietnamese.
	 */
	public function test_sign_in_switch_and_the_shared_invite_link() {
		$this->visit( '/signin/', array( 'lang' => 'vi' ) );
		$body = jlife_auth_signin_body( 'Lỗi', 'linh@example.test' );
		$this->assertStringContainsString( 'value="linh@example.test"', $body );
		$this->assertStringContainsString( 'name="jl_relang"', jlife_auth_page_html( 'x', $body ) );
		$this->assertStringContainsString( 'aria-current="true"', jlife_auth_language_links( true ) );
		$this->assertStringContainsString( 'role="group"', jlife_auth_language_links( true ) );
		$this->assertStringNotContainsString( 'jl_relang', jlife_auth_page_html( 'x', '<p>no form</p>' ), 'plain links without a form' );

		$this->vi_user();
		$this->assertStringContainsString( 'lang=vi', jlife_challenges_invite_reveal_html( 'Nhóm', 'ABCDEFGH' ) );
		wp_set_current_user( 0 );
		$this->visit( '/join/' );
		$this->assertStringNotContainsString( 'lang=vi', jlife_challenges_invite_reveal_html( 'Group', 'ABCDEFGH' ), 'an English starter shares the plain link' );
	}

	/**
	 * Run a page handler that ends in jlife_auth_send_page(), and give back the page it would send.
	 *
	 * @param callable $handler Page function.
	 * @return string The page's body HTML.
	 */
	private function run_page( $handler ) {
		$stop = static function ( $title, $body ) {
			throw new RuntimeException( $body ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- a test seam, never output.
		};
		add_action( 'jlife_auth_page_will_send', $stop, 10, 2 );
		try {
			$handler();
			$this->fail( 'the page should have been sent' );
		} catch ( RuntimeException $e ) {
			return $e->getMessage();
		} finally {
			remove_action( 'jlife_auth_page_will_send', $stop, 10 );
		}
		return '';
	}

	/**
	 * A language button rides on the sign-in POSTs but must do nothing except show the page again: no email, no account, no sign-in, and a code is not used up.
	 */
	public function test_a_language_button_never_sends_a_code_creates_an_account_or_uses_a_code() {
		$admin    = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$campaign = jlife_challenges_create_campaign(
			$admin,
			array(
				'campaign_key' => 'vi4-' . wp_generate_password( 6, false ),
				'plan_id'      => 'rpl-test',
				'title'        => 'Test',
				'start_date'   => '2026-12-20',
				'end_date'     => '2027-04-02',
				'status'       => 'active',
			)
		);
		$this->assertIsInt( $campaign );

		// 1. A complete, valid join request that carries the language button: nothing is sent, nobody is created.
		$fields = array(
			'step'        => 'request',
			'mode'        => 'solo',
			'email'       => 'relang@example.test',
			'first_name'  => 'Linh',
			'age_band'    => 'adult',
			'locale'      => 'en_US',
			'invite_code' => '',
			'_jlife_auth' => wp_create_nonce( 'jlife_auth_join' ),
			'jl_relang'   => 'vi',
		);
		$this->submit( '/join/', $fields, array( 'lang' => 'vi' ) );
		$_REQUEST = $fields;
		reset_phpmailer_instance();
		$page = $this->run_page( 'jlife_auth_page_join' );
		$this->assertSame( array(), tests_retrieve_phpmailer_instance()->mock_sent, 'no email' );
		$this->assertFalse( get_user_by( 'email', 'relang@example.test' ), 'no account' );
		$this->assertStringContainsString( 'value="relang@example.test"', $page, 'the form is shown again with what was typed' );
		$this->assertStringContainsString( 'Độ tuổi của bạn', $page );

		// 2. On the code page: a real code, a language button, then the same code on a normal verify.
		$this->visit( '/join/' );
		$this->assertTrue(
			jlife_auth_start_join(
				array(
					'mode'       => 'solo',
					'email'      => 'relang2@example.test',
					'first_name' => 'Minh',
					'age_band'   => 'adult',
					'locale'     => 'en_US',
				)
			)
		);
		$sent = tests_retrieve_phpmailer_instance()->mock_sent;
		preg_match( '/(\d{6})/', end( $sent )['body'], $m );
		$verify = array(
			'step'        => 'verify',
			'email'       => 'relang2@example.test',
			'code'        => $m[1],
			'_jlife_auth' => wp_create_nonce( 'jlife_auth_verify' ),
		);
		$this->submit( '/join/', array_merge( $verify, array( 'jl_relang' => 'vi' ) ), array( 'lang' => 'vi' ) );
		$_REQUEST = $_POST;
		$page     = $this->run_page( 'jlife_auth_page_join' );
		$this->assertSame( 0, get_current_user_id(), 'nobody is signed in' );
		$this->assertStringContainsString( 'name="jl_lang" value="vi"', $page, 'the code page again, in Vietnamese' );
		$this->assertStringNotContainsString( $m[1], $page, 'the code is not echoed back' );
		$result = jlife_auth_complete( 'relang2@example.test', $m[1] );
		$this->assertNotWPError( $result, 'the code was not used up by the language button' );
	}

	/**
	 * The emailed code is written in the language chosen on the form.
	 */
	public function test_the_code_email_uses_the_language_chosen_on_the_form() {
		$this->visit( '/join/' );
		$admin    = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$campaign = jlife_challenges_create_campaign(
			$admin,
			array(
				'campaign_key' => 'vi-' . wp_generate_password( 6, false ),
				'plan_id'      => 'rpl-test',
				'title'        => 'Test',
				'start_date'   => '2026-12-20',
				'end_date'     => '2027-04-02',
				'status'       => 'active',
			)
		);
		$this->assertIsInt( $campaign );
		$this->assertTrue(
			jlife_auth_start_join(
				array(
					'mode'       => 'solo',
					'email'      => 'vi-reader@example.test',
					'first_name' => 'Linh',
					'age_band'   => 'adult',
					'locale'     => 'vi',
				)
			)
		);
		$sent = tests_retrieve_phpmailer_instance()->mock_sent;
		$this->assertStringContainsString( 'Mã của bạn là', end( $sent )['body'] );
		$this->assertStringContainsString( 'Mã đăng nhập', end( $sent )['subject'] );
		$this->assertSame( 'en_US', determine_locale(), 'the page language is put back' );
	}

	/**
	 * What /join says after the code is checked is in the person's language too.
	 */
	public function test_join_notices_are_in_the_persons_language() {
		$admin    = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$campaign = jlife_challenges_create_campaign(
			$admin,
			array(
				'campaign_key' => 'vi2-' . wp_generate_password( 6, false ),
				'plan_id'      => 'rpl-test',
				'title'        => 'Test',
				'start_date'   => '2026-12-20',
				'end_date'     => '2027-04-02',
				'status'       => 'active',
			)
		);
		$teen     = self::factory()->user->create(
			array(
				'role'       => 'subscriber',
				'locale'     => 'vi',
				'user_email' => 'vi-teen@example.test',
			)
		);
		jlife_challenges_enroll_solo( $teen, $campaign, 'minor' );

		jlife_auth_start_join(
			array(
				'mode'       => 'start',
				'email'      => 'vi-teen@example.test',
				'first_name' => 'Minh',
				'age_band'   => 'adult',
				'locale'     => 'en_US',
				'group_name' => 'Nhóm bạn trẻ',
			)
		);
		$sent = tests_retrieve_phpmailer_instance()->mock_sent;
		preg_match( '/\b(\d{6})\b/', end( $sent )['body'], $m );
		$r = jlife_auth_complete( 'vi-teen@example.test', $m[1] );
		$this->assertSame( 'jlife_minor_cannot_start', $r['notice_code'] );
		$this->assertStringContainsString( 'Chỉ người từ 18 tuổi trở lên', $r['notice'] );
		$this->assertSame( 'en_US', determine_locale(), 'put back after the work' );
	}
}
