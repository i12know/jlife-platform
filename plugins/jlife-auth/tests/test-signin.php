<?php
/**
 * Sign-in and join (#58): codes, enumeration, rate limits, accounts,
 * age band, language, consent, session rules.
 *
 * @package jlife-auth
 */

/**
 * Sign-in tests.
 */
class Test_Signin extends WP_UnitTestCase {

	/**
	 * Organizer user.
	 *
	 * @var int
	 */
	private $organizer = 0;

	/**
	 * Invite code for the test group.
	 *
	 * @var string
	 */
	private $invite = '';

	/**
	 * Campaign ID.
	 *
	 * @var int
	 */
	private $campaign = 0;

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
		// The test site's domain is "localhost", which makes WordPress's default
		// sender (wordpress@localhost) an invalid address.
		add_filter( 'wp_mail_from', static fn() => 'jlife@example.test' );
		$this->organizer = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$this->campaign  = jlife_challenges_create_campaign(
			$this->organizer,
			array(
				'campaign_key' => 'auth-' . wp_generate_password( 6, false ),
				'plan_id'      => 'rpl-test',
				'title'        => 'Test',
				'start_date'   => '2026-12-20',
				'end_date'     => '2027-04-02',
				'status'       => 'active',
			)
		);
		$this->invite    = jlife_challenges_create_group( $this->organizer, $this->campaign, 'Group One' )['invite_code'];
	}

	/**
	 * Emails sent so far.
	 *
	 * @return array
	 */
	private function sent() {
		$mailer = tests_retrieve_phpmailer_instance();
		return $mailer->mock_sent;
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
	 * Join details.
	 *
	 * @param array $over Overrides.
	 * @return array
	 */
	private function join_input( $over = array() ) {
		return array_merge(
			array(
				'email'       => 'newcomer@example.test',
				'first_name'  => 'Linh',
				'invite_code' => $this->invite,
				'age_band'    => 'adult',
				'locale'      => 'vi',
			),
			$over
		);
	}

	/**
	 * Unknown and known addresses get the same answer; only real accounts get mail.
	 */
	public function test_no_account_enumeration() {
		$this->assertTrue( jlife_auth_request_code( 'nobody@example.test', 'signin' ) );
		$this->assertCount( 0, $this->sent(), 'no email for an unknown address' );
		self::factory()->user->create( array( 'user_email' => 'member@example.test' ) );
		$this->assertTrue( jlife_auth_request_code( 'member@example.test', 'signin' ) );
		$this->assertCount( 1, $this->sent() );
	}

	/**
	 * The email carries a 6-digit code and no sign-in link; storage holds only a hash.
	 */
	public function test_code_email_and_storage() {
		self::factory()->user->create( array( 'user_email' => 'member@example.test' ) );
		jlife_auth_request_code( 'Member@Example.test ', 'signin' );
		$code = $this->last_code();
		$this->assertMatchesRegularExpression( '/^\d{6}$/', $code );
		$sent = $this->sent();
		$body = end( $sent )['body'];
		$this->assertStringNotContainsString( 'http', $body, 'no sign-in link in the email' );
		$stored = get_transient( jlife_auth_code_key( 'member@example.test' ) );
		$this->assertStringNotContainsString( $code, wp_json_encode( $stored ), 'only a hash is stored' );
	}

	/**
	 * A correct code signs in once; it cannot be reused.
	 */
	public function test_code_is_single_use() {
		$uid = self::factory()->user->create( array( 'user_email' => 'member@example.test' ) );
		jlife_auth_request_code( 'member@example.test', 'signin' );
		$code = $this->last_code();
		$r    = jlife_auth_complete( 'member@example.test', $code );
		$this->assertSame( $uid, $r['user_id'] );
		$this->assertWPError( jlife_auth_complete( 'member@example.test', $code ) );
	}

	/**
	 * Five wrong guesses void the code, even if the right one follows.
	 */
	public function test_wrong_guesses_void_the_code() {
		self::factory()->user->create( array( 'user_email' => 'member@example.test' ) );
		jlife_auth_request_code( 'member@example.test', 'signin' );
		$code  = $this->last_code();
		$wrong = '000000' === $code ? '111111' : '000000';
		for ( $i = 0; $i < 5; $i++ ) {
			$this->assertWPError( jlife_auth_complete( 'member@example.test', $wrong ) );
		}
		$this->assertWPError( jlife_auth_complete( 'member@example.test', $code ), 'locked after 5 wrong guesses' );
	}

	/**
	 * Expired codes do not work.
	 */
	public function test_expired_code() {
		self::factory()->user->create( array( 'user_email' => 'member@example.test' ) );
		jlife_auth_request_code( 'member@example.test', 'signin' );
		$code             = $this->last_code();
		$key              = jlife_auth_code_key( 'member@example.test' );
		$state            = get_transient( $key );
		$state['expires'] = time() - 1;
		set_transient( $key, $state, 60 );
		$this->assertWPError( jlife_auth_complete( 'member@example.test', $code ) );
	}

	/**
	 * Rate limits apply the same way to known and unknown addresses.
	 */
	public function test_rate_limits() {
		for ( $i = 0; $i < 3; $i++ ) {
			$this->assertTrue( jlife_auth_request_code( 'nobody@example.test', 'signin' ) );
		}
		$this->assertSame( 'jlife_auth_slow_down', jlife_auth_request_code( 'nobody@example.test', 'signin' )->get_error_code() );
		for ( $i = 0; $i < 10; $i++ ) {
			jlife_auth_request_code( "p{$i}@example.test", 'signin', array(), '203.0.113.9' );
		}
		$this->assertSame( 'jlife_auth_slow_down', jlife_auth_request_code( 'p99@example.test', 'signin', array(), '203.0.113.9' )->get_error_code(), 'per-IP limit' );
	}

	/**
	 * Joining creates the account with first name and language, enrolls with the age band,
	 * and requires consent before anything else.
	 */
	public function test_join_creates_participant_and_enrollment() {
		$this->assertTrue( jlife_auth_start_join( $this->join_input( array( 'age_band' => 'minor' ) ) ) );
		$r = jlife_auth_complete( 'newcomer@example.test', $this->last_code() );
		$this->assertSame( 'join', $r['purpose'] );
		$user = get_userdata( $r['user_id'] );
		$this->assertSame( 'Linh', $user->first_name );
		$this->assertSame( array( 'subscriber' ), $user->roles );
		$this->assertSame( 'vi', get_user_meta( $r['user_id'], 'locale', true ) );
		$e = jlife_challenges_get_enrollment( $this->campaign, $r['user_id'] );
		$this->assertSame( 'minor', $e['age_band'] );
		$this->assertTrue( jlife_auth_needs_consent( $r['user_id'] ) );

		jlife_auth_record_consent( $r['user_id'] );
		$this->assertFalse( jlife_auth_needs_consent( $r['user_id'] ) );
		$this->assertSame( JLIFE_AUTH_CONSENT_VERSION, jlife_challenges_get_enrollment( $this->campaign, $r['user_id'] )['consent_version'] );
	}

	/**
	 * Join input is checked before any code is sent.
	 */
	public function test_join_validation() {
		$this->assertSame( 'jlife_invite_invalid', jlife_auth_start_join( $this->join_input( array( 'invite_code' => 'WRONG123' ) ) )->get_error_code() );
		$this->assertSame( 'jlife_auth_age', jlife_auth_start_join( $this->join_input( array( 'age_band' => 'child' ) ) )->get_error_code(), 'under-13s cannot join' );
		$this->assertSame( 'jlife_auth_name', jlife_auth_start_join( $this->join_input( array( 'first_name' => '' ) ) )->get_error_code() );
		$this->assertCount( 0, $this->sent() );
	}

	/**
	 * An existing account joining reuses the account; joining twice keeps one group.
	 */
	public function test_existing_account_join() {
		$uid = self::factory()->user->create( array( 'user_email' => 'newcomer@example.test' ) );
		jlife_auth_start_join( $this->join_input() );
		$r = jlife_auth_complete( 'newcomer@example.test', $this->last_code() );
		$this->assertSame( $uid, $r['user_id'] );
		jlife_auth_start_join( $this->join_input() );
		$again = jlife_auth_complete( 'newcomer@example.test', $this->last_code() );
		$this->assertSame( $uid, $again['user_id'] );
		$this->assertNotSame( '', $again['notice'], 'tells them they are already in a group' );
	}

	/**
	 * Participants stay signed in through the campaign, never reach wp-admin, never see the admin bar.
	 */
	public function test_session_rules() {
		$participant = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		$this->assertSame( 180 * DAY_IN_SECONDS, jlife_auth_cookie_expiration( 14 * DAY_IN_SECONDS, $participant, true ) );
		$this->assertSame( 14 * DAY_IN_SECONDS, jlife_auth_cookie_expiration( 14 * DAY_IN_SECONDS, $this->organizer, true ), 'staff keep the default' );
		$this->assertTrue( jlife_auth_should_block_admin( $participant, 'index.php' ) );
		$this->assertFalse( jlife_auth_should_block_admin( $participant, 'admin-ajax.php' ) );
		$this->assertFalse( jlife_auth_should_block_admin( $this->organizer, 'index.php' ) );
		wp_set_current_user( $participant );
		$this->assertFalse( jlife_auth_show_admin_bar( true ) );
		$this->assertFalse( jlife_auth_needs_consent( $this->organizer ), 'staff are not asked for participant consent' );
	}

	/**
	 * Where sign-in continues: empty or off-site targets go home; on-site targets are kept.
	 */
	public function test_redirect_target() {
		unset( $_REQUEST['redirect_to'] );
		$this->assertSame( home_url( '/' ), jlife_auth_redirect_target(), 'empty target goes home (regression: redirect went nowhere)' );
		$_REQUEST['redirect_to'] = 'https://evil.example/steal';
		$this->assertSame( home_url( '/' ), jlife_auth_redirect_target(), 'off-site target refused' );
		$_REQUEST['redirect_to'] = home_url( '/reading/x/' );
		$this->assertSame( home_url( '/reading/x/' ), jlife_auth_redirect_target() );
		unset( $_REQUEST['redirect_to'] );
	}
}
