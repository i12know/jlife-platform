<?php
/**
 * The "I'll start Dec 20" choice on the join flow (#178). Written before the
 * code. With the Nov 29–Dec 13 pilot and the Dec 20 Marathon both active, a new
 * sign-up from Nov 29 lands in the pilot by default, or can choose to wait for
 * the official Marathon: the account is created as usual, nobody is enrolled,
 * and a per-user flag records the intent for the rollover (#180).
 *
 * @package jlife-auth
 */

/**
 * Wait-choice tests.
 */
class Test_Wait_Choice extends WP_UnitTestCase {

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
	 * The pilot (made the supported way) and the Marathon; a clean mailbox.
	 */
	public function set_up() {
		parent::set_up();
		reset_phpmailer_instance();
		add_filter( 'wp_mail_from', static fn() => 'jlife@example.test' );
		add_filter( 'jlife_challenges_now', array( $this, 'clock' ) );
		$organizer = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$made      = jlife_challenges_create_pilot_campaign( $organizer );
		$this->assertIsArray( $made );
		$this->pilot    = (int) $made['campaign_id'];
		$this->marathon = jlife_challenges_create_campaign(
			$organizer,
			array(
				'campaign_key' => 'marathon-' . strtolower( wp_generate_password( 6, false ) ),
				'plan_id'      => 'rpl-test',
				'title'        => 'Marathon',
				'start_date'   => '2026-12-20',
				'end_date'     => '2027-04-02',
				'status'       => 'active',
			)
		);
		$this->assertIsInt( $this->marathon );
		$this->now = new DateTimeImmutable( '2026-11-29 18:00:00', new DateTimeZone( 'UTC' ) );
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
	 * Set "now" (UTC).
	 *
	 * @param string $utc Y-m-d H:i:s.
	 */
	private function set_now( $utc ) {
		$this->now = new DateTimeImmutable( $utc, new DateTimeZone( 'UTC' ) );
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
				'locale'     => 'vi',
			),
			$over
		);
	}

	/**
	 * Visible text of escaped HTML.
	 *
	 * @param string $html HTML.
	 * @return string
	 */
	private function text( $html ) {
		return html_entity_decode( wp_strip_all_tags( $html ), ENT_QUOTES, 'UTF-8' );
	}

	/**
	 * A sign-up on Nov 29 joins the pilot by default, not the Marathon.
	 */
	public function test_a_nov_29_sign_up_defaults_to_the_pilot() {
		$this->assertTrue( jlife_auth_start_join( $this->input( array( 'mode' => 'solo' ) ) ) );
		$r = jlife_auth_complete( 'reader@example.test', $this->last_code() );
		$this->assertNotNull( jlife_challenges_get_enrollment( $this->pilot, $r['user_id'] ) );
		$this->assertNull( jlife_challenges_get_enrollment( $this->marathon, $r['user_id'] ) );
		$this->assertFalse( jlife_challenges_is_waiting_for_official( $r['user_id'] ), 'no flag unless asked' );
	}

	/**
	 * Choosing to wait creates the account and the flag, enrolls nobody, and keeps the chosen language.
	 */
	public function test_waiting_creates_the_account_and_enrolls_nobody() {
		$this->assertTrue( jlife_auth_start_join( $this->input( array( 'mode' => 'wait' ) ) ) );
		$r = jlife_auth_complete( 'reader@example.test', $this->last_code() );
		$this->assertIsArray( $r );
		$this->assertTrue( jlife_challenges_is_waiting_for_official( $r['user_id'] ) );
		$this->assertSame( 'adult', jlife_challenges_waiting_age_band( $r['user_id'] ), 'the age band is kept for the rollover (#180)' );
		$this->assertSame( array(), jlife_challenges_user_enrollments( $r['user_id'] ), 'no enrollment of any kind' );
		$this->assertSame( 'vi', get_user_meta( $r['user_id'], 'locale', true ) );
		$this->assertSame( 'Linh', get_userdata( $r['user_id'] )->first_name );
		$this->assertSame( '', $r['notice'], 'not an error' );
		$this->assertTrue( ! empty( $r['waiting'] ) );
	}

	/**
	 * Waiting records no reminder choice, even if the old checkbox value is posted.
	 */
	public function test_waiting_records_no_reminder_consent() {
		$this->assertTrue( jlife_auth_start_join( $this->input( array( 'mode' => 'wait', 'reminders' => '1' ) ) ) ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound -- test data.
		$r = jlife_auth_complete( 'reader@example.test', $this->last_code() );
		global $wpdb;
		$t = jlife_challenges_table( 'reminder_prefs' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQL.NotPrepared -- test read; custom table.
		$this->assertSame( 0, (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$t} WHERE user_id = %d", $r['user_id'] ) ) );
	}

	/**
	 * A code and a group name typed with the wait choice are ignored: no group, no join.
	 */
	public function test_waiting_ignores_a_code_and_a_group_name() {
		$organizer = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$group     = jlife_challenges_create_group( $organizer, $this->pilot, 'Pilot Group' );
		$this->assertTrue( jlife_auth_start_join( $this->input( array( 'mode' => 'wait', 'invite_code' => $group['invite_code'], 'group_name' => 'Mine' ) ) ) ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound -- test data.
		$r = jlife_auth_complete( 'reader@example.test', $this->last_code() );
		$this->assertSame( array(), jlife_challenges_user_enrollments( $r['user_id'] ) );
	}

	/**
	 * The choice is offered only while the pilot is what new people join (from creation on Nov 28 evening to Dec 13).
	 */
	public function test_waiting_is_offered_only_while_the_pilot_is_open() {
		foreach ( array( '2026-11-29 04:00:00', '2026-11-29 08:00:00', '2026-12-07 18:00:00', '2026-12-14 07:59:59' ) as $utc ) {
			$this->set_now( $utc );
			$this->assertTrue( jlife_auth_wait_offered(), $utc );
		}
		foreach ( array( '2026-12-14 08:00:00', '2026-12-20 18:00:00' ) as $utc ) {
			$this->set_now( $utc );
			$this->assertFalse( jlife_auth_wait_offered(), $utc );
		}
		$this->set_now( '2026-12-14 08:00:00' );
		$late = jlife_auth_start_join( $this->input( array( 'mode' => 'wait' ) ) );
		$this->assertWPError( $late );
		$this->assertCount( 0, tests_retrieve_phpmailer_instance()->mock_sent, 'no email for a choice that is no longer offered' );
	}

	/**
	 * Without a pilot the choice is not offered (nothing to wait out).
	 */
	public function test_waiting_is_not_offered_without_a_pilot() {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- test setup.
		$wpdb->update( jlife_challenges_table( 'campaigns' ), array( 'status' => 'draft' ), array( 'id' => $this->pilot ) );
		$this->assertFalse( jlife_auth_wait_offered() );
		$this->assertWPError( jlife_auth_start_join( $this->input( array( 'mode' => 'wait' ) ) ) );
	}

	/**
	 * The join form shows the wait link, in every mode, only while it is offered; the wait form has no code, group or reminder fields.
	 */
	public function test_the_join_forms() {
		foreach ( array( 'invite', 'solo', 'start' ) as $mode ) {
			$html = jlife_auth_join_form( $mode, array() );
			$this->assertStringContainsString( 'mode=wait', $html, "link on $mode" );
			$this->assertStringContainsString( "I'll start Dec 20", $this->text( $html ) );
		}
		$wait = jlife_auth_join_form( 'wait', array() );
		$this->assertStringContainsString( 'value="wait"', $wait );
		$this->assertStringNotContainsString( 'name="invite_code"', $wait );
		$this->assertStringNotContainsString( 'name="group_name"', $wait );
		$this->assertStringNotContainsString( 'name="reminders"', $wait, 'no reminder box: consent comes later (#181)' );
		$this->assertStringContainsString( 'name="first_name"', $wait );
		$this->assertStringContainsString( 'name="age_band"', $wait );

		$this->set_now( '2026-12-20 18:00:00' );
		foreach ( array( 'invite', 'solo', 'start' ) as $mode ) {
			$this->assertStringNotContainsString( 'mode=wait', jlife_auth_join_form( $mode, array() ), "no link on $mode after the pilot" );
		}
	}

	/**
	 * An unknown mode still means an invite code, and `wait` is read as a mode.
	 */
	public function test_join_mode_reads_wait() {
		$this->assertSame( 'wait', jlife_auth_join_mode( 'wait' ) );
		$this->assertSame( 'invite', jlife_auth_join_mode( 'other' ) );
	}

	/**
	 * After the code the person is told they are on the list, in their language, and nothing says they joined the pilot.
	 */
	public function test_the_result_page_says_what_happens_next() {
		$this->assertTrue( jlife_auth_start_join( $this->input( array( 'mode' => 'wait', 'locale' => 'en_US' ) ) ) ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound -- test data.
		$r    = jlife_auth_complete( 'reader@example.test', $this->last_code() );
		$html = $this->text( jlife_auth_join_result_html( $r, home_url( '/today/' ) ) );
		$this->assertStringContainsString( 'The Marathon starts Dec 20', $html );
		$this->assertStringNotContainsString( 'Practice period', $html );
		$this->assertStringContainsString( 'Continue', $html );
	}

	/**
	 * Someone who already has an account can choose to wait too; an existing enrollment is left alone.
	 */
	public function test_an_existing_participant_can_wait_without_losing_enrollments() {
		$user_id = self::factory()->user->create( array( 'role' => 'subscriber', 'user_email' => 'reader@example.test' ) ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound -- test data.
		$this->assertIsInt( jlife_challenges_enroll_solo( $user_id, $this->pilot, 'adult' ) );
		$this->assertTrue( jlife_auth_start_join( $this->input( array( 'mode' => 'wait' ) ) ) );
		jlife_auth_complete( 'reader@example.test', $this->last_code() );
		$this->assertTrue( jlife_challenges_is_waiting_for_official( $user_id ) );
		$this->assertNotNull( jlife_challenges_get_enrollment( $this->pilot, $user_id ), 'the pilot enrollment stays' );
	}

	/**
	 * Waiting does not stop a later sign-in, and a waiting person still needs the consent wording.
	 */
	public function test_a_waiting_person_still_goes_through_consent() {
		$this->assertTrue( jlife_auth_start_join( $this->input( array( 'mode' => 'wait' ) ) ) );
		$r = jlife_auth_complete( 'reader@example.test', $this->last_code() );
		$this->assertTrue( jlife_auth_needs_consent( $r['user_id'] ) );
		jlife_auth_record_consent( $r['user_id'] );
		$this->assertFalse( jlife_auth_needs_consent( $r['user_id'] ) );
	}
}
