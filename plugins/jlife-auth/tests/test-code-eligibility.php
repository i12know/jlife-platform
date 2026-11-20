<?php
/**
 * Emailed codes are for participant accounts only: staff, super admins and
 * anyone with a role beyond the participant roles on any site of the network
 * sign in on the standard login. Checked when a code is requested and when it
 * is entered; gaining a staff role ends the person's sessions. The screen
 * answers the same way for every address (no account enumeration).
 *
 * The multisite cases also run in CI with WP_MULTISITE=1.
 *
 * @package jlife-auth
 */

/**
 * Code eligibility tests.
 */
class Test_Code_Eligibility extends WP_UnitTestCase {

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
		$organizer    = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$campaign     = jlife_challenges_create_campaign(
			$organizer,
			array(
				'campaign_key' => 'elig-' . wp_generate_password( 6, false ),
				'plan_id'      => 'rpl-test',
				'title'        => 'Test',
				'start_date'   => '2026-12-20',
				'end_date'     => '2027-04-02',
				'status'       => 'active',
			)
		);
		$this->invite = jlife_challenges_create_group( $organizer, $campaign, 'Group One' )['invite_code'];
	}

	/**
	 * Remove test roles.
	 */
	public function tear_down() {
		remove_role( 'jlife_test_manager' );
		remove_role( 'jlife_test_reader' );
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
	 * Was a code created for this address?
	 *
	 * @param string $email Address.
	 * @return bool
	 */
	private function has_code( $email ) {
		return false !== get_transient( jlife_auth_code_key( $email ) );
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
	 * A user with this address and role.
	 *
	 * @param string $email Address.
	 * @param string $role  Role.
	 * @return int
	 */
	private function user( $email, $role = 'subscriber' ) {
		return self::factory()->user->create(
			array(
				'user_email' => $email,
				'role'       => $role,
			)
		);
	}

	/**
	 * Join details for an address.
	 *
	 * @param string $email Address.
	 * @return array
	 */
	private function join_input( $email ) {
		return array(
			'email'       => $email,
			'first_name'  => 'Linh',
			'invite_code' => $this->invite,
			'age_band'    => 'adult',
			'locale'      => 'en_US',
		);
	}

	/**
	 * Assert that a staff address got the notice and no code, on /signin and /join.
	 *
	 * @param string $email Address.
	 */
	private function assert_refused_on_both_routes( $email ) {
		$this->assertTrue( jlife_auth_request_code( $email, 'signin' ), 'same answer as any address' );
		$this->assertFalse( $this->has_code( $email ), 'no sign-in code created' );
		$this->assertTrue( jlife_auth_start_join( $this->join_input( $email ) ), 'join answers the same way' );
		$this->assertFalse( $this->has_code( $email ), 'no join code created' );
		$sent = $this->sent();
		$this->assertCount( 2, $sent, 'a staff notice each time, never a code' );
		foreach ( $sent as $mail ) {
			$this->assertStringContainsString( 'staff', $mail['subject'] );
			$this->assertDoesNotMatchRegularExpression( '/\b\d{6}\b/', $mail['body'], 'the notice carries no code' );
		}
		$this->assertWPError( jlife_auth_complete( $email, '123456' ) );
	}

	/**
	 * A participant still gets a code on both routes and signs in.
	 */
	public function test_participant_signs_in_with_a_code() {
		$uid = $this->user( 'reader@example.test' );
		$this->assertTrue( jlife_auth_email_code_allowed( $uid ) );
		$this->assertTrue( jlife_auth_request_code( 'reader@example.test', 'signin' ) );
		$r = jlife_auth_complete( 'reader@example.test', $this->last_code() );
		$this->assertSame( $uid, $r['user_id'] );
		$this->assertTrue( jlife_auth_start_join( $this->join_input( 'reader@example.test' ) ) );
		$r = jlife_auth_complete( 'reader@example.test', $this->last_code() );
		$this->assertSame( $uid, $r['user_id'] );
	}

	/**
	 * A newcomer's join is unchanged: code, then a participant account.
	 */
	public function test_newcomer_join_is_unchanged() {
		$this->assertTrue( jlife_auth_start_join( $this->join_input( 'brand-new@example.test' ) ) );
		$r = jlife_auth_complete( 'brand-new@example.test', $this->last_code() );
		$this->assertSame( array( 'subscriber' ), get_userdata( $r['user_id'] )->roles );
		$this->assertTrue( jlife_auth_email_code_allowed( $r['user_id'] ) );
	}

	/**
	 * Editors and administrators get the notice, never a code.
	 */
	public function test_editor_and_administrator_are_refused() {
		foreach ( array( 'editor', 'administrator' ) as $role ) {
			reset_phpmailer_instance();
			$email = "{$role}@example.test";
			$uid   = $this->user( $email, $role );
			$this->assertFalse( jlife_auth_email_code_allowed( $uid ), $role );
			$this->assert_refused_on_both_routes( $email );
		}
	}

	/**
	 * A role without edit_posts is still staff: the rule is an allowlist.
	 */
	public function test_custom_role_without_edit_posts_is_refused() {
		add_role(
			'jlife_test_manager',
			'Test manager',
			array(
				'read'       => true,
				'list_users' => true,
			)
		);
		$uid = $this->user( 'manager@example.test', 'jlife_test_manager' );
		$this->assertFalse( user_can( $uid, 'edit_posts' ) );
		$this->assertFalse( jlife_auth_email_code_allowed( $uid ) );
		$this->assert_refused_on_both_routes( 'manager@example.test' );
	}

	/**
	 * A participant given one extra capability is no longer a participant.
	 */
	public function test_extra_capability_is_refused() {
		$uid = $this->user( 'extra@example.test' );
		( new WP_User( $uid ) )->add_cap( 'list_users' );
		$this->assertFalse( jlife_auth_email_code_allowed( $uid ) );
		$this->assert_refused_on_both_routes( 'extra@example.test' );
	}

	/**
	 * A role added on purpose through the filter is treated as a participant role.
	 */
	public function test_participant_roles_filter() {
		add_role( 'jlife_test_reader', 'Test reader', array( 'read' => true ) );
		$uid = $this->user( 'filtered@example.test', 'jlife_test_reader' );
		$this->assertFalse( jlife_auth_email_code_allowed( $uid ), 'not allowed by default' );
		$add = static fn( $roles ) => array_merge( $roles, array( 'jlife_test_reader' ) );
		add_filter( 'jlife_auth_participant_roles', $add );
		$this->assertTrue( jlife_auth_email_code_allowed( $uid ) );
		remove_filter( 'jlife_auth_participant_roles', $add );
	}

	/**
	 * A code requested as a participant cannot be used after a promotion, and is spent.
	 */
	public function test_promotion_after_the_code_voids_it() {
		$uid = $this->user( 'promoted@example.test' );
		jlife_auth_request_code( 'promoted@example.test', 'signin' );
		$code = $this->last_code();
		$user = new WP_User( $uid );
		$user->set_role( 'editor' );
		$this->assertWPError( jlife_auth_complete( 'promoted@example.test', $code ) );
		$user->set_role( 'subscriber' );
		$this->assertWPError( jlife_auth_complete( 'promoted@example.test', $code ), 'the code was spent' );
	}

	/**
	 * The same holds for a join code.
	 */
	public function test_promotion_after_a_join_code_voids_it() {
		$uid = $this->user( 'joiner@example.test' );
		jlife_auth_start_join( $this->join_input( 'joiner@example.test' ) );
		$code = $this->last_code();
		( new WP_User( $uid ) )->set_role( 'administrator' );
		$this->assertWPError( jlife_auth_complete( 'joiner@example.test', $code ) );
	}

	/**
	 * Gaining a staff role ends existing sessions; a participant role keeps them.
	 */
	public function test_staff_role_ends_sessions() {
		$uid    = $this->user( 'sessions@example.test' );
		$tokens = WP_Session_Tokens::get_instance( $uid );
		$tokens->create( time() + DAY_IN_SECONDS );
		$user = new WP_User( $uid );
		$user->set_role( 'subscriber' );
		$this->assertCount( 1, $tokens->get_all(), 'a participant role keeps sessions' );
		$user->add_role( 'editor' );
		$this->assertCount( 0, $tokens->get_all(), 'gaining a staff role ends them' );
		$tokens->create( time() + DAY_IN_SECONDS );
		$user->set_role( 'administrator' );
		$this->assertCount( 0, $tokens->get_all() );
	}

	/**
	 * Staff keep WordPress's default session length.
	 */
	public function test_staff_keep_default_session_length() {
		$staff = $this->user( 'short@example.test', 'editor' );
		$this->assertSame( 14 * DAY_IN_SECONDS, jlife_auth_cookie_expiration( 14 * DAY_IN_SECONDS, $staff, true ) );
	}

	/**
	 * Network super admins are refused, and becoming one ends sessions.
	 */
	public function test_super_admin_is_refused() {
		if ( ! is_multisite() ) {
			$this->markTestSkipped( 'Multisite only; CI runs this class with WP_MULTISITE=1.' );
		}
		$uid    = $this->user( 'network@example.test' );
		$tokens = WP_Session_Tokens::get_instance( $uid );
		$tokens->create( time() + DAY_IN_SECONDS );
		grant_super_admin( $uid );
		$this->assertCount( 0, $tokens->get_all(), 'sessions ended' );
		$this->assertFalse( jlife_auth_email_code_allowed( $uid ) );
		$this->assert_refused_on_both_routes( 'network@example.test' );
		revoke_super_admin( $uid );
	}

	/**
	 * A subscriber here who administers another site is staff here too.
	 */
	public function test_administrator_on_another_site_is_refused() {
		if ( ! is_multisite() ) {
			$this->markTestSkipped( 'Multisite only; CI runs this class with WP_MULTISITE=1.' );
		}
		$uid    = $this->user( 'elsewhere@example.test' );
		$tokens = WP_Session_Tokens::get_instance( $uid );
		$tokens->create( time() + DAY_IN_SECONDS );
		$other = self::factory()->blog->create();
		add_user_to_blog( $other, $uid, 'administrator' );
		$this->assertCount( 0, $tokens->get_all(), 'sessions ended' );
		$this->assertFalse( user_can( $uid, 'edit_posts' ), 'only a subscriber on this site' );
		$this->assertFalse( jlife_auth_email_code_allowed( $uid ) );
		$this->assertSame( 14 * DAY_IN_SECONDS, jlife_auth_cookie_expiration( 14 * DAY_IN_SECONDS, $uid, true ) );
		$this->assert_refused_on_both_routes( 'elsewhere@example.test' );
	}

	/**
	 * A participant on two sites is still a participant.
	 */
	public function test_subscriber_on_another_site_is_allowed() {
		if ( ! is_multisite() ) {
			$this->markTestSkipped( 'Multisite only; CI runs this class with WP_MULTISITE=1.' );
		}
		$uid   = $this->user( 'both@example.test' );
		$other = self::factory()->blog->create();
		add_user_to_blog( $other, $uid, 'subscriber' );
		$this->assertTrue( jlife_auth_email_code_allowed( $uid ) );
	}
}
