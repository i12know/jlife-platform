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
		$this->assertStringContainsString( 'Gửi mã cho tôi', $signin );
		$this->assertStringNotContainsString( 'No password needed', $signin );

		$consent = jlife_auth_consent_body();
		$this->assertStringContainsString( 'Trước khi bắt đầu', $consent );
		$this->assertStringContainsString( 'riêng tư', $consent );
		$this->assertStringContainsString( 'Bắt đầu Marathon', $consent );
		$this->assertStringNotContainsString( 'Before you start', $consent );
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
