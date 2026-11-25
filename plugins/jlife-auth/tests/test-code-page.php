<?php
/**
 * The "Check your email" page: the arrival/spam hint and "Send a new code".
 *
 * @package jlife-auth
 */

/**
 * Code page tests.
 */
class Test_Code_Page extends WP_UnitTestCase {

	/**
	 * Invite code for the test group.
	 *
	 * @var string
	 */
	private $invite = '';

	/**
	 * Create the engine's tables once.
	 */
	public static function wpSetUpBeforeClass() {
		jlife_challenges_install_tables();
	}

	/**
	 * A campaign with one group, and a clean mailbox.
	 */
	public function set_up() {
		parent::set_up();
		reset_phpmailer_instance();
		add_filter( 'wp_mail_from', static fn() => 'jlife@example.test' );
		$admin        = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$campaign     = jlife_challenges_create_campaign(
			$admin,
			array(
				'campaign_key' => 'cp-' . wp_generate_password( 6, false ),
				'plan_id'      => 'rpl-test',
				'title'        => 'Test',
				'start_date'   => '2026-12-20',
				'end_date'     => '2027-04-02',
				'status'       => 'active',
			)
		);
		$this->invite = jlife_challenges_create_group( $admin, $campaign, 'Group One' )['invite_code'];
		$_POST        = array();
		$_REQUEST     = array();
	}

	/**
	 * Restore the request.
	 */
	public function tear_down() {
		$_POST                     = array();
		$_REQUEST                  = array();
		$_SERVER['REQUEST_METHOD'] = 'GET';
		parent::tear_down();
	}

	/**
	 * Emails sent so far.
	 *
	 * @return array
	 */
	private function sent() {
		return tests_retrieve_phpmailer_instance()->mock_sent;
	}

	/**
	 * The code in the most recent email.
	 *
	 * @return string
	 */
	private function last_code() {
		$sent = $this->sent();
		$this->assertNotEmpty( $sent, 'an email was sent' );
		preg_match( '/\b(\d{6})\b/', end( $sent )['body'], $m );
		return $m[1] ?? '';
	}

	/**
	 * Show the page for a visitor in a language.
	 *
	 * @param string $lang en|vi.
	 */
	private function visit( $lang ) {
		$_SERVER['REQUEST_URI']    = '/signin/?lang=' . $lang;
		$_SERVER['REQUEST_METHOD'] = 'GET';
		$_GET                      = array( 'lang' => $lang );
		jlife_auth_refresh_translations();
	}

	/**
	 * POST a resend and return the page body.
	 *
	 * @param string $handler Page function.
	 * @param array  $fields  POST fields (the step and nonce are added).
	 * @return string
	 */
	private function resend( $handler, $fields ) {
		$_SERVER['REQUEST_METHOD'] = 'POST';
		$_POST                     = array_merge(
			array(
				'step'          => 'resend',
				'_jlife_resend' => wp_create_nonce( 'jlife_auth_resend' ),
			),
			$fields
		);
		$_REQUEST                  = $_POST;  // phpcs:ignore WordPress.Security.NonceVerification.Missing -- test fixture.
		$stop                      = static function ( $title, $body ) {
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
	 * The hint is in English and Vietnamese, and its minutes come from the constant.
	 */
	public function test_hint_in_both_languages_with_minutes_from_the_constant() {
		$minutes = (int) ( JLIFE_AUTH_CODE_TTL / 60 );
		$this->visit( 'en' );
		$en = jlife_auth_code_form( 'a@example.test' );
		$this->assertStringContainsString( 'Check your Spam or Junk folder and mark it “Not spam”.', $en );
		$this->assertStringContainsString( "expires in {$minutes} minutes.", $en );
		$this->assertStringContainsString( 'use the newest email.', $en );
		$this->assertLessThan( strpos( $en, 'It usually arrives' ), strpos( $en, '6-digit code.' ), 'the hint follows the sent line' );
		$this->visit( 'vi' );
		$vi = jlife_auth_code_form( 'a@example.test' );
		$this->assertStringContainsString( 'Hãy xem thư mục Spam (Thư rác)', $vi );
		$this->assertStringContainsString( "hết hạn sau {$minutes} phút.", $vi );
		$this->assertStringContainsString( 'Gửi mã mới', $vi );
		$this->visit( 'en' );
	}

	/**
	 * The resend form posts to the same page with the email in the body, never in an address.
	 */
	public function test_resend_form_posts_the_email_in_the_body() {
		$this->visit( 'en' );
		$html = jlife_auth_code_form( 'a@example.test' );
		$this->assertMatchesRegularExpression( '#<form method="post" id="jl-resend"[^>]*>#', $html );
		$this->assertStringContainsString( 'name="_jlife_resend"', $html );
		$this->assertStringContainsString( 'name="email" value="a@example.test"', $html );
		$this->assertDoesNotMatchRegularExpression( '#(?:href|action|formaction)="[^"]*(?:email=|a%40example|a@example)#', $html );
		$this->assertDoesNotMatchRegularExpression( '#<form[^>]*id="jl-resend"[^>]*action=#', $html );
	}

	/**
	 * A resend sends exactly one email, shows the status and the hint, and the new code works.
	 */
	public function test_resend_sends_one_email_and_shows_status() {
		self::factory()->user->create( array( 'user_email' => 'member@example.test' ) );
		$this->visit( 'en' );
		$page = $this->resend( 'jlife_auth_page_signin', array( 'email' => 'member@example.test' ) );
		$this->assertCount( 1, $this->sent() );
		$this->assertStringContainsString( 'We sent a new code.', $page );
		$this->assertStringContainsString( 'It usually arrives within a minute.', $page );
		$this->assertIsArray( jlife_auth_complete( 'member@example.test', $this->last_code() ) );
	}

	/**
	 * A resend counts against the same limits; past them the existing message shows and no email goes.
	 */
	public function test_resend_respects_the_rate_limit() {
		self::factory()->user->create( array( 'user_email' => 'member@example.test' ) );
		for ( $i = 0; $i < 3; $i++ ) {
			$this->assertTrue( jlife_auth_request_code( 'member@example.test', 'signin' ) );
		}
		reset_phpmailer_instance();
		$this->visit( 'en' );
		$page = $this->resend( 'jlife_auth_page_signin', array( 'email' => 'member@example.test' ) );
		$this->assertStringContainsString( 'Too many code requests.', $page );
		$this->assertStringNotContainsString( 'We sent a new code.', $page );
		$this->assertCount( 0, $this->sent() );
		$this->visit( 'vi' );
		$page = $this->resend( 'jlife_auth_page_signin', array( 'email' => 'member@example.test' ) );
		$this->assertStringContainsString( 'Bạn đã xin mã quá nhiều lần.', $page );
		$this->visit( 'en' );
	}

	/**
	 * A join resend keeps the join details: the new code still creates that person.
	 */
	public function test_join_resend_keeps_the_join_details() {
		$input = array(
			'email'       => 'newcomer@example.test',
			'first_name'  => 'Linh',
			'invite_code' => $this->invite,
			'age_band'    => 'adult',
			'locale'      => 'vi',
		);
		$this->assertTrue( jlife_auth_start_join( $input ) );
		$html = jlife_auth_code_form( 'newcomer@example.test' );
		$this->assertStringContainsString( 'name="jl_join" value="1"', $html, 'the form carries the join details from the waiting code' );
		reset_phpmailer_instance();
		$fields = array(
			'email'       => 'newcomer@example.test',
			'jl_join'     => '1',
			'mode'        => 'invite',
			'invite_code' => $this->invite,
			'group_name'  => '',
			'first_name'  => 'Linh',
			'age_band'    => 'adult',
			'locale'      => 'vi',
			'reminders'   => '',
		);
		$this->visit( 'en' );
		$page = $this->resend( 'jlife_auth_page_join', $fields );
		$this->assertCount( 1, $this->sent() );
		$this->assertStringContainsString( 'We sent a new code.', $page );
		$this->assertStringContainsString( 'name="jl_join" value="1"', $page, 'a second resend still keeps them' );
		$r = jlife_auth_complete( 'newcomer@example.test', $this->last_code() );
		$this->assertSame( 'join', $r['purpose'] );
		$user = get_userdata( $r['user_id'] );
		$this->assertSame( 'Linh', $user->first_name );
		$this->assertSame( 'vi', get_user_meta( $r['user_id'], 'locale', true ) );
	}

	/**
	 * An expired code during a join keeps the join details on the error page, so
	 * "Send a new code" is still a join and not a plain sign-in (R13-01).
	 */
	public function test_expired_code_during_join_keeps_the_join_details() {
		$input = array(
			'email'       => 'latecomer@example.test',
			'first_name'  => 'Linh',
			'invite_code' => $this->invite,
			'age_band'    => 'adult',
			'locale'      => 'vi',
		);
		$this->assertTrue( jlife_auth_start_join( $input ) );
		delete_transient( jlife_auth_code_key( 'latecomer@example.test' ) ); // The code has expired.
		$this->visit( 'en' );
		$_SERVER['REQUEST_METHOD'] = 'POST';
		$_POST                     = array(
			'step'        => 'verify',
			'email'       => 'latecomer@example.test',
			'code'        => '123456',
			'jl_join'     => '1',
			'mode'        => 'invite',
			'invite_code' => $this->invite,
			'group_name'  => '',
			'first_name'  => 'Linh',
			'age_band'    => 'adult',
			'locale'      => 'vi',
			'reminders'   => '',
		);
		$_REQUEST                  = $_POST; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- test fixture.
		$stop                      = static function ( $title, $body ) {
			throw new RuntimeException( $body ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- a test seam, never output.
		};
		add_action( 'jlife_auth_page_will_send', $stop, 10, 2 );
		$page = '';
		try {
			jlife_auth_handle_verify();
			$this->fail( 'the error page should have been sent' );
		} catch ( RuntimeException $e ) {
			$page = $e->getMessage();
		} finally {
			remove_action( 'jlife_auth_page_will_send', $stop, 10 );
		}
		$this->assertStringContainsString( 'id="jl-resend"', $page );
		$this->assertGreaterThanOrEqual( 2, substr_count( $page, 'name="jl_join" value="1"' ), 'both the code form and the resend form keep the join' );
		$this->assertStringContainsString( 'name="first_name" value="Linh"', $page );

		reset_phpmailer_instance();
		$fields = $_REQUEST; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- test fixture (the same fields, resent).
		$page   = $this->resend( 'jlife_auth_page_join', $fields );
		$this->assertCount( 1, $this->sent(), 'the resend sends one code' );
		$this->assertStringContainsString( 'We sent a new code.', $page );
		$r = jlife_auth_complete( 'latecomer@example.test', $this->last_code() );
		$this->assertSame( 'join', $r['purpose'], 'the new code still joins' );
	}

	/**
	 * An unknown or ineligible address gets the same page and no email.
	 */
	public function test_resend_does_not_disclose_unknown_or_ineligible_addresses() {
		$this->visit( 'en' );
		$page = $this->resend( 'jlife_auth_page_signin', array( 'email' => 'nobody@example.test' ) );
		$this->assertCount( 0, $this->sent(), 'unknown address: no email' );
		$this->assertStringContainsString( 'If nobody@example.test can sign in, we just sent it a 6-digit code.', $page );
		$this->assertStringContainsString( 'We sent a new code.', $page );
		$admin = self::factory()->user->create(
			array(
				'role'       => 'administrator',
				'user_email' => 'staff@example.test',
			)
		);
		$this->assertFalse( jlife_auth_email_code_allowed( $admin ) );
		reset_phpmailer_instance();
		$staff = $this->resend( 'jlife_auth_page_signin', array( 'email' => 'staff@example.test' ) );
		$this->assertStringContainsString( 'If staff@example.test can sign in, we just sent it a 6-digit code.', $staff );
		$this->assertStringContainsString( 'We sent a new code.', $staff );
		$this->assertFalse( get_transient( jlife_auth_code_key( 'staff@example.test' ) ), 'no code is stored for staff' );
	}
}
