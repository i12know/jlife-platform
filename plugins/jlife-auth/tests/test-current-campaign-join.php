<?php
/**
 * Sign-up paths follow the current-campaign rule (#177, docs/challenges-engine-
 * design.md §3b): with the Dec 5–13 pilot and the Dec 20 Marathon both active,
 * "Read on my own" and "Start a group" join the pilot during the pilot and the
 * Marathon afterwards, and a pilot invite code stops once the pilot has ended.
 *
 * @package jlife-auth
 */

/**
 * Join-path routing tests.
 */
class Test_Current_Campaign_Join extends WP_UnitTestCase {

	/**
	 * Pilot campaign ID.
	 *
	 * @var int
	 */
	private $pilot = 0;

	/**
	 * Marathon campaign ID.
	 *
	 * @var int
	 */
	private $marathon = 0;

	/**
	 * A pilot group: { id, invite_code }.
	 *
	 * @var array
	 */
	private $pilot_group = array();

	/**
	 * The engine's clock (UTC).
	 *
	 * @var DateTimeImmutable|null
	 */
	private $now = null;

	/**
	 * Create the engine's tables once.
	 */
	public static function wpSetUpBeforeClass() {
		jlife_challenges_install_tables();
	}

	/**
	 * The pilot and the Marathon, both active; a clean mailbox.
	 */
	public function set_up() {
		parent::set_up();
		reset_phpmailer_instance();
		add_filter( 'wp_mail_from', static fn() => 'jlife@example.test' );
		add_filter( 'jlife_challenges_now', array( $this, 'clock' ) );
		$organizer         = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$this->pilot       = $this->campaign( $organizer, 'pilot', '2026-12-05', '2026-12-13' );
		$this->marathon    = $this->campaign( $organizer, 'marathon', '2026-12-20', '2027-04-02' );
		$this->pilot_group = jlife_challenges_create_group( $organizer, $this->pilot, 'Pilot Group' );
	}

	/**
	 * Remove the clock filter.
	 */
	public function tear_down() {
		remove_filter( 'jlife_challenges_now', array( $this, 'clock' ) );
		parent::tear_down();
	}

	/**
	 * Clock filter callback.
	 *
	 * @return DateTimeImmutable|null
	 */
	public function clock() {
		return $this->now;
	}

	/**
	 * Create an active campaign.
	 *
	 * @param int    $organizer Organizer.
	 * @param string $prefix    Key prefix.
	 * @param string $start     Day 1.
	 * @param string $end       Last day.
	 * @return int
	 */
	private function campaign( $organizer, $prefix, $start, $end ) {
		$id = jlife_challenges_create_campaign(
			$organizer,
			array(
				'campaign_key' => $prefix . '-' . strtolower( wp_generate_password( 6, false ) ),
				'plan_id'      => 'rpl-test',
				'title'        => ucfirst( $prefix ),
				'start_date'   => $start,
				'end_date'     => $end,
				'status'       => 'active',
			)
		);
		$this->assertIsInt( $id );
		return $id;
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
	 * "Read on my own" during the pilot joins the pilot, not the future Marathon.
	 */
	public function test_solo_join_during_the_pilot_joins_the_pilot() {
		$this->now = new DateTimeImmutable( '2026-12-07 18:00:00', new DateTimeZone( 'UTC' ) );
		$this->assertTrue( jlife_auth_start_join( $this->input( array( 'mode' => 'solo' ) ) ) );
		$r = jlife_auth_complete( 'reader@example.test', $this->last_code() );
		$this->assertNotNull( jlife_challenges_get_enrollment( $this->pilot, $r['user_id'] ) );
		$this->assertNull( jlife_challenges_get_enrollment( $this->marathon, $r['user_id'] ) );
	}

	/**
	 * "Start a group" during the pilot makes the group in the pilot.
	 */
	public function test_start_group_during_the_pilot_uses_the_pilot() {
		$this->now = new DateTimeImmutable( '2026-12-07 18:00:00', new DateTimeZone( 'UTC' ) );
		$this->assertTrue(
			jlife_auth_start_join(
				$this->input(
					array(
						'email'      => 'starter@example.test',
						'mode'       => 'start',
						'group_name' => 'Pilot friends',
					)
				)
			)
		);
		$r = jlife_auth_complete( 'starter@example.test', $this->last_code() );
		$this->assertIsArray( $r['started'] );
		$this->assertSame( $this->pilot, (int) jlife_challenges_get_group( $r['started']['id'] )['campaign_id'] );
	}

	/**
	 * From Dec 14 00:00 Pacific new solo joins go to the Marathon, and a pilot code is refused before any email.
	 */
	public function test_after_the_pilot_joins_go_to_the_marathon() {
		$this->now = new DateTimeImmutable( '2026-12-14 08:00:00', new DateTimeZone( 'UTC' ) );
		$this->assertTrue( jlife_auth_start_join( $this->input( array( 'mode' => 'solo' ) ) ) );
		$r = jlife_auth_complete( 'reader@example.test', $this->last_code() );
		$this->assertNotNull( jlife_challenges_get_enrollment( $this->marathon, $r['user_id'] ) );
		$this->assertNull( jlife_challenges_get_enrollment( $this->pilot, $r['user_id'] ) );

		reset_phpmailer_instance();
		$late = jlife_auth_start_join(
			$this->input(
				array(
					'email'       => 'late@example.test',
					'invite_code' => $this->pilot_group['invite_code'],
				)
			)
		);
		$this->assertWPError( $late );
		$this->assertSame( 'jlife_invite_invalid', $late->get_error_code() );
		$this->assertCount( 0, tests_retrieve_phpmailer_instance()->mock_sent );
	}
}
