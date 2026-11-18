<?php
/**
 * The third way in, /join/?mode=start "Start a group" (#112, part 2), and the
 * notices /join shows after the emailed code is checked. Adults only: the age
 * check happens before any email is sent and again, on the stored enrollment,
 * when the group is made. Written before the feature.
 *
 * @package jlife-auth
 */

/**
 * Start-mode tests.
 */
class Test_Start_Mode extends WP_UnitTestCase {

	/**
	 * Organizer user.
	 *
	 * @var int
	 */
	private $organizer = 0;

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
	 * One open campaign and a clean mailbox.
	 */
	public function set_up() {
		parent::set_up();
		reset_phpmailer_instance();
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
	}

	/**
	 * The code in the most recent email.
	 *
	 * @return string
	 */
	private function last_code() {
		$sent = tests_retrieve_phpmailer_instance()->mock_sent;
		$this->assertNotEmpty( $sent, 'an email was sent' );
		preg_match( '/\b(\d{6})\b/', end( $sent )['body'], $m );
		return $m[1] ?? '';
	}

	/**
	 * Start-mode join details.
	 *
	 * @param array $over Overrides.
	 * @return array
	 */
	private function input( $over = array() ) {
		return array_merge(
			array(
				'mode'       => 'start',
				'email'      => 'starter@example.test',
				'first_name' => 'Mai',
				'age_band'   => 'adult',
				'locale'     => 'en_US',
				'group_name' => 'Friday friends',
			),
			$over
		);
	}

	/**
	 * Active group IDs of a user.
	 *
	 * @param int $user_id User.
	 * @return int[]
	 */
	private function groups_of( $user_id ) {
		return array_map( 'intval', array_column( jlife_challenges_active_memberships( $this->campaign, $user_id ), 'group_id' ) );
	}

	/**
	 * An adult starts a group from /join: after the emailed code they are signed up as its starter, with a code to share.
	 */
	public function test_an_adult_starts_a_group_from_join() {
		$this->assertSame( 'start', jlife_auth_join_mode( 'start' ) );
		$this->assertTrue( jlife_auth_start_join( $this->input() ) );
		$r = jlife_auth_complete( 'starter@example.test', $this->last_code() );
		$this->assertSame( 'join', $r['purpose'] );
		$this->assertSame( '', $r['notice'] );
		$this->assertSame( 'Friday friends', $r['started']['name'] );
		$this->assertMatchesRegularExpression( '/^[A-Z2-9]{8}$/', $r['started']['invite_code'] );

		$this->assertSame( array( (int) $r['started']['id'] ), $this->groups_of( $r['user_id'] ) );
		$this->assertTrue( jlife_challenges_is_group_starter( $r['user_id'], $r['started']['id'] ) );
		$this->assertSame( 'adult', jlife_challenges_get_enrollment( $this->campaign, $r['user_id'] )['age_band'] );
		$this->assertSame( (int) $r['started']['id'], (int) jlife_challenges_group_for_invite( $r['started']['invite_code'] )['id'] );
	}

	/**
	 * A minor cannot start a group: refused before any email is sent.
	 */
	public function test_a_minor_cannot_start_a_group_from_join() {
		$r = jlife_auth_start_join( $this->input( array( 'age_band' => 'minor' ) ) );
		$this->assertWPError( $r );
		$this->assertSame( 'jlife_auth_minor_cannot_start', $r->get_error_code() );
		$this->assertCount( 0, tests_retrieve_phpmailer_instance()->mock_sent );
		$this->assertFalse( get_user_by( 'email', 'starter@example.test' ), 'no account is made' );
	}

	/**
	 * Someone already enrolled as a 13-17 participant cannot start a group by ticking "18 or older" on the form.
	 */
	public function test_a_minors_stored_age_band_wins_over_the_form() {
		$minor = self::factory()->user->create(
			array(
				'role'       => 'subscriber',
				'user_email' => 'teen@example.test',
			)
		);
		$this->assertIsInt( jlife_challenges_enroll_solo( $minor, $this->campaign, 'minor' ) );

		$this->assertTrue( jlife_auth_start_join( $this->input( array( 'email' => 'teen@example.test' ) ) ), 'the form claims adult' );
		$r = jlife_auth_complete( 'teen@example.test', $this->last_code() );
		$this->assertSame( $minor, $r['user_id'], 'they can still sign in' );
		$this->assertArrayNotHasKey( 'started', $r );
		$this->assertNotSame( '', $r['notice'], 'and are told they cannot start a group' );
		$this->assertSame( array(), $this->groups_of( $minor ) );
		$this->assertSame( 'minor', jlife_challenges_get_enrollment( $this->campaign, $minor )['age_band'], 'the stored age band is untouched' );
	}

	/**
	 * Start mode needs a valid group name and an open campaign before any email is sent.
	 */
	public function test_start_mode_is_validated_before_sending_a_code() {
		foreach ( array( '', '   ', str_repeat( 'x', 61 ) ) as $name ) {
			$r = jlife_auth_start_join( $this->input( array( 'group_name' => $name ) ) );
			$this->assertWPError( $r );
			$this->assertSame( 'jlife_group_name_invalid', $r->get_error_code() );
		}
		$this->assertCount( 0, tests_retrieve_phpmailer_instance()->mock_sent );

		global $wpdb;
		$wpdb->update( jlife_challenges_table( 'campaigns' ), array( 'status' => 'finished' ), array( 'id' => $this->campaign ) ); // phpcs:ignore WordPress.DB
		$this->assertSame( 'jlife_auth_no_campaign', jlife_auth_start_join( $this->input() )->get_error_code() );
		$this->assertCount( 0, tests_retrieve_phpmailer_instance()->mock_sent );
	}

	/**
	 * A person at the 3-group limit who starts a group from /join is signed in with a notice, and nothing is created.
	 */
	public function test_start_at_the_group_limit_gives_a_notice_and_creates_nothing() {
		$user = self::factory()->user->create(
			array(
				'role'       => 'subscriber',
				'user_email' => 'full@example.test',
			)
		);
		foreach ( array( 'A', 'B', 'C' ) as $name ) {
			$g = jlife_challenges_create_group( $this->organizer, $this->campaign, $name );
			$this->assertIsInt( jlife_challenges_enroll( $this->organizer, $user, $g['id'] ) );
		}
		global $wpdb;
		$before = (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . jlife_challenges_table( 'groups' ) ); // phpcs:ignore WordPress.DB

		$this->assertTrue( jlife_auth_start_join( $this->input( array( 'email' => 'full@example.test' ) ) ) );
		$r = jlife_auth_complete( 'full@example.test', $this->last_code() );
		$this->assertSame( $user, $r['user_id'] );
		$this->assertNotSame( '', $r['notice'] );
		$this->assertArrayNotHasKey( 'started', $r );
		$this->assertCount( 3, $this->groups_of( $user ) );
		$this->assertSame( $before, (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . jlife_challenges_table( 'groups' ) ) ); // phpcs:ignore WordPress.DB
	}

	/**
	 * /join shows the 3-group-limit and already-a-member notices after the code is checked.
	 */
	public function test_join_shows_the_group_limit_and_already_a_member_notices() {
		$groups = array();
		foreach ( array( 'One', 'Two', 'Three', 'Four' ) as $name ) {
			$groups[ $name ] = jlife_challenges_create_group( $this->organizer, $this->campaign, $name );
		}
		$input = array(
			'email'      => 'reader@example.test',
			'first_name' => 'Linh',
			'age_band'   => 'adult',
			'locale'     => 'en_US',
		);
		jlife_auth_start_join( $input + array( 'invite_code' => $groups['One']['invite_code'] ) );
		$user = jlife_auth_complete( 'reader@example.test', $this->last_code() )['user_id'];

		// Already a member.
		jlife_auth_start_join( $input + array( 'invite_code' => $groups['One']['invite_code'] ) );
		$again = jlife_auth_complete( 'reader@example.test', $this->last_code() );
		$this->assertSame( 'jlife_already_enrolled', $again['notice_code'] );
		$html = jlife_auth_join_result_html( $again, home_url( '/today/' ) );
		$this->assertStringContainsString( esc_html( $again['notice'] ), $html );
		$this->assertStringContainsString( 'href="' . esc_url( home_url( '/today/' ) ) . '"', $html, 'a way on' );
		$this->assertStringNotContainsString( 'Group One', $html, 'a group name is never printed on /join' );

		// The group limit.
		jlife_challenges_join_with_code( $user, $groups['Two']['invite_code'], 'adult' );
		jlife_challenges_join_with_code( $user, $groups['Three']['invite_code'], 'adult' );
		jlife_auth_start_join( $input + array( 'invite_code' => $groups['Four']['invite_code'] ) );
		$full = jlife_auth_complete( 'reader@example.test', $this->last_code() );
		$this->assertSame( 'jlife_group_limit', $full['notice_code'] );
		$this->assertStringContainsString( esc_html( $full['notice'] ), jlife_auth_join_result_html( $full, home_url( '/today/' ) ) );
	}

	/**
	 * The page after a started group shows the code and a full invite link, with a copy button.
	 */
	public function test_the_started_group_page_shows_the_code_and_link() {
		jlife_auth_start_join( $this->input() );
		$r    = jlife_auth_complete( 'starter@example.test', $this->last_code() );
		$html = jlife_auth_join_result_html( $r, home_url( '/consent/' ) );
		$code = $r['started']['invite_code'];
		$this->assertStringContainsString( $code, $html );
		$this->assertStringContainsString( esc_html( add_query_arg( 'code', $code, home_url( '/join/' ) ) ), $html, 'the full invite link' );
		$this->assertStringContainsString( 'data-copy=', $html, 'a copy button' );
		$this->assertStringContainsString( 'Friday friends', $html, 'the starter sees their own group name' );
	}

	/**
	 * The three paths link to one another.
	 */
	public function test_the_three_join_paths_link_to_each_other() {
		$start  = esc_url( add_query_arg( 'mode', 'start', home_url( '/join/' ) ) );
		$solo   = esc_url( add_query_arg( 'mode', 'solo', home_url( '/join/' ) ) );
		$invite = esc_url( home_url( '/join/' ) );

		$html = jlife_auth_join_form( 'invite', array(), '' );
		$this->assertStringContainsString( $start, $html );
		$this->assertStringContainsString( $solo, $html );

		$html = jlife_auth_join_form( 'solo', array(), '' );
		$this->assertStringContainsString( $start, $html );
		$this->assertStringContainsString( $invite, $html );
		$this->assertStringNotContainsString( 'name="group_name"', $html );

		$html = jlife_auth_join_form( 'start', array(), '' );
		$this->assertStringContainsString( 'name="group_name"', $html );
		$this->assertStringContainsString( 'maxlength="60"', $html );
		$this->assertStringNotContainsString( 'name="invite_code"', $html );
		$this->assertStringContainsString( 'name="mode" value="start"', $html );
		$this->assertStringContainsString( $solo, $html );
		$this->assertStringContainsString( $invite, $html );
	}
}
