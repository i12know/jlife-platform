<?php
/**
 * Join modes (#112): an invite code (as before) or "Read on my own" (no code, no
 * group), keeping consent and the age band; joining a second group later with a
 * code. Written before the feature.
 *
 * @package jlife-auth
 */

/**
 * Join-mode tests.
 */
class Test_Join_Modes extends WP_UnitTestCase {

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
	 * Groups created: id and invite code.
	 *
	 * @var array[]
	 */
	private $groups = array();

	/**
	 * Create the engine's tables once.
	 */
	public static function wpSetUpBeforeClass() {
		jlife_challenges_install_tables();
	}

	/**
	 * One open campaign with four groups, and a clean mailbox.
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
		foreach ( array( 'One', 'Two', 'Three', 'Four' ) as $name ) {
			$this->groups[ $name ] = jlife_challenges_create_group( $this->organizer, $this->campaign, 'Group ' . $name );
		}
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
	 * Join details.
	 *
	 * @param array $over Overrides.
	 * @return array
	 */
	private function input( $over = array() ) {
		return array_merge(
			array(
				'email'      => 'reader@example.test',
				'first_name' => 'Linh',
				'age_band'   => 'adult',
				'locale'     => 'en_US',
			),
			$over
		);
	}

	/**
	 * Active group IDs of a user in the campaign.
	 *
	 * @param int $user_id User.
	 * @return int[]
	 */
	private function groups_of( $user_id ) {
		return array_map( 'intval', array_column( jlife_challenges_active_memberships( $this->campaign, $user_id ), 'group_id' ) );
	}

	/**
	 * "Read on my own" needs no code: the account and a group-less enrollment are made after the emailed code.
	 */
	public function test_solo_join_needs_no_invite_code() {
		$this->assertTrue( jlife_auth_start_join( $this->input( array( 'mode' => 'solo' ) ) ) );
		$r = jlife_auth_complete( 'reader@example.test', $this->last_code() );
		$this->assertSame( 'join', $r['purpose'] );

		$e = jlife_challenges_get_enrollment( $this->campaign, $r['user_id'] );
		$this->assertSame( 'active', $e['status'] );
		$this->assertSame( 'adult', $e['age_band'] );
		$this->assertSame( array(), $this->groups_of( $r['user_id'] ), 'no group' );
		$this->assertSame( 'Linh', get_userdata( $r['user_id'] )->first_name );
		$this->assertSame( array( 'subscriber' ), get_userdata( $r['user_id'] )->roles );
	}

	/**
	 * Consent and the age band work exactly as for a group join.
	 */
	public function test_solo_join_keeps_consent_and_age_band() {
		$this->assertTrue(
			jlife_auth_start_join(
				$this->input(
					array(
						'mode'     => 'solo',
						'age_band' => 'minor',
					)
				)
			)
		);
		$r = jlife_auth_complete( 'reader@example.test', $this->last_code() );
		$this->assertSame( 'minor', jlife_challenges_get_enrollment( $this->campaign, $r['user_id'] )['age_band'] );
		$this->assertTrue( jlife_auth_needs_consent( $r['user_id'] ) );
		jlife_auth_record_consent( $r['user_id'] );
		$this->assertFalse( jlife_auth_needs_consent( $r['user_id'] ) );
		$this->assertSame( JLIFE_AUTH_CONSENT_VERSION, jlife_challenges_get_enrollment( $this->campaign, $r['user_id'] )['consent_version'] );
	}

	/**
	 * Solo join is validated before any code is sent; under-13s still cannot join.
	 */
	public function test_solo_join_validation() {
		$this->assertSame(
			'jlife_auth_age',
			jlife_auth_start_join(
				$this->input(
					array(
						'mode'     => 'solo',
						'age_band' => 'child',
					)
				)
			)->get_error_code()
		);
		$this->assertSame(
			'jlife_auth_age',
			jlife_auth_start_join(
				$this->input(
					array(
						'mode'     => 'solo',
						'age_band' => '',
					)
				)
			)->get_error_code()
		);
		$this->assertSame(
			'jlife_auth_name',
			jlife_auth_start_join(
				$this->input(
					array(
						'mode'       => 'solo',
						'first_name' => '',
					)
				)
			)->get_error_code()
		);
		$this->assertCount( 0, tests_retrieve_phpmailer_instance()->mock_sent );
	}

	/**
	 * Without the solo choice a code is still required (today's path).
	 */
	public function test_invite_mode_still_requires_a_valid_code() {
		$this->assertSame( 'jlife_invite_invalid', jlife_auth_start_join( $this->input() )->get_error_code(), 'no code, no solo choice' );
		$this->assertSame( 'jlife_invite_invalid', jlife_auth_start_join( $this->input( array( 'invite_code' => 'WRONG123' ) ) )->get_error_code() );
		$this->assertTrue( jlife_auth_start_join( $this->input( array( 'invite_code' => $this->groups['One']['invite_code'] ) ) ) );
		$r = jlife_auth_complete( 'reader@example.test', $this->last_code() );
		$this->assertSame( array( (int) $this->groups['One']['id'] ), $this->groups_of( $r['user_id'] ) );
	}

	/**
	 * A code typed in solo mode is ignored: solo means no group.
	 */
	public function test_solo_mode_ignores_a_typed_code() {
		$this->assertTrue(
			jlife_auth_start_join(
				$this->input(
					array(
						'mode'        => 'solo',
						'invite_code' => $this->groups['One']['invite_code'],
					)
				)
			)
		);
		$r = jlife_auth_complete( 'reader@example.test', $this->last_code() );
		$this->assertSame( array(), $this->groups_of( $r['user_id'] ) );
	}

	/**
	 * No open campaign: a kind error, no email.
	 */
	public function test_solo_join_with_no_open_campaign() {
		global $wpdb;
		$wpdb->update( jlife_challenges_table( 'campaigns' ), array( 'status' => 'finished' ), array( 'id' => $this->campaign ) ); // phpcs:ignore WordPress.DB
		$this->assertSame( 'jlife_auth_no_campaign', jlife_auth_start_join( $this->input( array( 'mode' => 'solo' ) ) )->get_error_code() );
		$this->assertCount( 0, tests_retrieve_phpmailer_instance()->mock_sent );
	}

	/**
	 * A solo reader who later enters a code joins that group; the enrollment is kept.
	 */
	public function test_solo_reader_joins_a_group_later_with_a_code() {
		jlife_auth_start_join( $this->input( array( 'mode' => 'solo' ) ) );
		$solo = jlife_auth_complete( 'reader@example.test', $this->last_code() )['user_id'];
		$e1   = jlife_challenges_get_enrollment( $this->campaign, $solo );

		jlife_auth_start_join( $this->input( array( 'invite_code' => $this->groups['Two']['invite_code'] ) ) );
		$again = jlife_auth_complete( 'reader@example.test', $this->last_code() );
		$this->assertSame( $solo, $again['user_id'] );
		$this->assertSame( '', $again['notice'] );
		$this->assertSame( array( (int) $this->groups['Two']['id'] ), $this->groups_of( $solo ) );
		$this->assertSame( $e1['id'], jlife_challenges_get_enrollment( $this->campaign, $solo )['id'], 'one enrollment' );
	}

	/**
	 * A member can join more groups by code up to the limit; a fourth is told no, kindly.
	 * (Emailed codes are rate limited per address, so the third join goes straight to the engine.)
	 */
	public function test_joining_a_second_and_third_group_and_a_fourth_notice() {
		jlife_auth_start_join( $this->input( array( 'invite_code' => $this->groups['One']['invite_code'] ) ) );
		$user = jlife_auth_complete( 'reader@example.test', $this->last_code() )['user_id'];

		jlife_auth_start_join( $this->input( array( 'invite_code' => $this->groups['Two']['invite_code'] ) ) );
		$second = jlife_auth_complete( 'reader@example.test', $this->last_code() );
		$this->assertSame( '', $second['notice'] );
		$this->assertIsInt( jlife_challenges_join_with_code( $user, $this->groups['Three']['invite_code'], 'adult' ) );
		$this->assertCount( 3, $this->groups_of( $user ) );

		jlife_auth_start_join( $this->input( array( 'invite_code' => $this->groups['Four']['invite_code'] ) ) );
		$r = jlife_auth_complete( 'reader@example.test', $this->last_code() );
		$this->assertSame( $user, $r['user_id'], 'still signed in as themselves' );
		$this->assertNotSame( '', $r['notice'], 'tells them they are already in 3 groups' );
		$this->assertCount( 3, $this->groups_of( $user ) );
		$this->assertNotContains( (int) $this->groups['Four']['id'], $this->groups_of( $user ) );
	}

	/**
	 * The join page: invite code by default; ?mode=solo drops the code box and says what solo means.
	 */
	public function test_join_form_branches() {
		$code = jlife_auth_join_form( 'invite', array(), '' );
		$this->assertStringContainsString( 'name="invite_code"', $code );
		$this->assertStringContainsString( 'name="mode" value="invite"', $code );
		$this->assertStringContainsString( esc_url( add_query_arg( 'mode', 'solo', home_url( '/join/' ) ) ), $code, 'a way to the solo path' );
		$this->assertStringContainsString( 'Read on my own', $code );

		$solo = jlife_auth_join_form( 'solo', array(), '' );
		$this->assertStringNotContainsString( 'name="invite_code"', $solo );
		$this->assertStringContainsString( 'name="mode" value="solo"', $solo );
		$this->assertStringContainsString( 'name="age_band"', $solo, 'the age band is still asked' );
		$this->assertStringContainsString( 'name="first_name"', $solo );
		$this->assertStringContainsString( esc_url( home_url( '/join/' ) ), $solo, 'a way back to the code path' );
		$this->assertStringContainsString( 'I have an invite code', $solo );
	}

	/**
	 * Mode comes from the request and defaults to the invite path.
	 */
	public function test_join_mode_parsing() {
		$this->assertSame( 'invite', jlife_auth_join_mode( '' ) );
		$this->assertSame( 'invite', jlife_auth_join_mode( 'whatever' ) );
		$this->assertSame( 'solo', jlife_auth_join_mode( 'solo' ) );
	}
}
