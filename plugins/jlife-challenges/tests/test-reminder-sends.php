<?php
/**
 * Daily reading reminders, part 2 (#63 Phase B): the send queue, the scheduler,
 * the sender, retries and the modes. Written before the feature.
 *
 * Mail is captured with a pre_wp_mail filter (nothing leaves the test), and the
 * clock is fixed through jlife_challenges_now. Campaign time is Pacific:
 * 14:00 UTC is 06:00 in December (PST) and 13:00 UTC is 06:00 in summer (PDT).
 *
 * @package jlife-challenges
 */

// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared -- tests read the custom tables directly.

/**
 * Reminder send tests.
 */
class Test_Reminder_Sends extends Jlife_Challenges_TestCase {

	/**
	 * Messages "sent" by wp_mail: to, subject, message, headers.
	 *
	 * @var array[]
	 */
	private $sent = array();

	/**
	 * What the fake mail transport answers: true, false, or 'throw'.
	 *
	 * @var bool|string
	 */
	private $mail_result = true;

	/**
	 * Test-mode recipient list (lowercase addresses), or null for none defined.
	 *
	 * @var string[]|null
	 */
	private $test_list = null;

	/**
	 * Locale filter (vi tests).
	 *
	 * @var callable|null
	 */
	private $force = null;

	/**
	 * The reading-day fixtures need the studies plugin's functions, and the
	 * localization needs the auth plugin's helper. The challenges suite loads
	 * neither by default.
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
	}

	/**
	 * Consent for a, b and c, three reading days, and a captured mail transport.
	 */
	public function set_up() {
		parent::set_up();
		foreach ( array( 'a', 'b', 'c' ) as $n ) {
			jlife_challenges_record_consent( $this->u[ $n ], $this->campaign, 'test-version' );
		}
		$this->sent        = array();
		$this->mail_result = true;
		$this->test_list   = null;
		add_filter( 'pre_wp_mail', array( $this, 'capture_mail' ), 5, 2 );
		add_filter( 'jlife_challenges_reminder_test_recipients', array( $this, 'filter_test_list' ) );
		update_option( 'jlife_reminders_mode', 'live' );
		delete_option( 'jlife_reminders_lock' );
		foreach ( array( 1, 2, 85 ) as $i ) {
			$this->make_reading_day( $i );
		}
	}

	/**
	 * Remove the fakes.
	 */
	public function tear_down() {
		remove_filter( 'pre_wp_mail', array( $this, 'capture_mail' ), 5 );
		remove_filter( 'jlife_challenges_reminder_test_recipients', array( $this, 'filter_test_list' ) );
		if ( $this->force ) {
			remove_filter( 'determine_locale', $this->force, 99 );
			unload_textdomain( 'jlife-challenges', true );
		}
		delete_option( 'jlife_reminders_mode' );
		delete_option( 'jlife_reminders_lock' );
		parent::tear_down();
	}

	/**
	 * Fake transport (the pre_wp_mail filter): record the message and answer as configured.
	 *
	 * @param null|bool $pre  Short-circuit value.
	 * @param array     $atts wp_mail arguments.
	 * @return bool
	 * @throws RuntimeException When the fake transport is set to throw.
	 */
	public function capture_mail( $pre, $atts ) {
		if ( 'throw' === $this->mail_result ) {
			throw new RuntimeException( 'boom to@example.test secret body' );
		}
		$this->sent[] = $atts;
		return (bool) $this->mail_result;
	}

	/**
	 * Filter callback for the test-mode list.
	 *
	 * @param string[] $addresses Addresses from the constant.
	 * @return string[]
	 */
	public function filter_test_list( $addresses ) {
		return null === $this->test_list ? $addresses : $this->test_list;
	}

	/**
	 * Create a reading-day post for the campaign's plan.
	 *
	 * @param int $i Day index.
	 */
	private function make_reading_day( $i ) {
		$id = self::factory()->post->create(
			array(
				'post_type'   => 'jlife_reading_day',
				'post_status' => 'publish',
				'post_title'  => 'Day ' . $i,
			)
		);
		update_post_meta( $id, '_jlife_plan_id', 'rpl-life-and-teachings-of-jesus-2026' );
		update_post_meta( $id, '_jlife_day_id', jlife_challenges_day_key( $i ) );
		jlife_studies_put_json_meta(
			$id,
			'_jlife_reading_day',
			array(
				'day_id'   => jlife_challenges_day_key( $i ),
				'title'    => 'Jesus and Nicodemus | Jesus and John the Baptizer',
				'titles'   => array( 'Jesus and Nicodemus', 'Jesus and John the Baptizer' ),
				'passages' => array(
					array( 'display' => 'John 3:1-21' ),
					array( 'display' => 'John 3:22-4:3' ),
				),
				'i18n'     => array(
					'vi' => array(
						'title'    => 'Chúa Giê-xu và Ni-cô-đem | Chúa Giê-xu và Giăng Báp-tít',
						'passages' => array( 'Giăng 3:1-21', 'Giăng 3:22-4:3' ),
					),
				),
			)
		);
		jlife_studies_put_json_meta( $id, '_jlife_plan', array() );
	}

	/**
	 * Opt people in (English unless said).
	 *
	 * @param string[] $names Participant keys.
	 * @param string   $locale en_US or vi.
	 */
	private function opt_in( $names, $locale = 'en_US' ) {
		foreach ( $names as $n ) {
			$this->assertTrue( jlife_challenges_set_reminder( $this->u[ $n ], $this->u[ $n ], $this->campaign, true, $locale ) );
		}
	}

	/**
	 * Rows in the send log for the campaign, oldest first.
	 *
	 * @return array[]
	 */
	private function rows() {
		global $wpdb;
		return (array) $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . jlife_challenges_table( 'reminder_sends' ) . ' WHERE campaign_id = %d ORDER BY id', $this->campaign ), ARRAY_A );
	}

	/**
	 * One person's row for a day.
	 *
	 * @param string $name Participant key.
	 * @param string $day  Day key.
	 * @return array|null
	 */
	private function row_of( $name, $day = 'd001' ) {
		foreach ( $this->rows() as $r ) {
			if ( (int) $r['user_id'] === $this->u[ $name ] && $day === $r['day_key'] ) {
				return $r;
			}
		}
		return null;
	}

	/**
	 * Run one scheduler tick now.
	 */
	private function tick() {
		jlife_challenges_reminders_tick();
	}

	/**
	 * Addresses that received a message, sorted.
	 *
	 * @return string[]
	 */
	private function recipients_mailed() {
		$to = array();
		foreach ( $this->sent as $m ) {
			$to[] = is_array( $m['to'] ) ? $m['to'][0] : $m['to'];
		}
		sort( $to );
		return $to;
	}

	/**
	 * Email of a participant.
	 *
	 * @param string $name Participant key.
	 * @return string
	 */
	private function email( $name ) {
		return get_userdata( $this->u[ $name ] )->user_email;
	}

	/**
	 * Run Vietnamese and English catalogs from the repository (the suite loads the plugin outside WP_PLUGIN_DIR).
	 */
	private function use_catalogs() {
		add_filter(
			'lang_dir_for_domain',
			static fn( $path, $domain ) => 'jlife-challenges' === $domain ? dirname( __DIR__ ) . '/languages/' : $path,
			10,
			2
		);
		unload_textdomain( 'jlife-challenges', true );
		$this->force = static fn() => 'en_US';
		add_filter( 'determine_locale', $this->force, 99 );
	}

	/**
	 * Exactly one email per opted-in person per day, however many ticks run.
	 */
	public function test_one_email_per_person_per_day_across_repeated_ticks() {
		$this->opt_in( array( 'a', 'b' ) ); // c never asked.
		$this->set_now( '2026-12-20 14:00:00' ); // 06:00 PST, day 1.
		$this->tick();
		$this->tick();
		$this->set_now( '2026-12-20 14:05:00' );
		$this->tick();
		$this->set_now( '2026-12-20 17:55:00' );
		$this->tick();
		$expected = array( $this->email( 'a' ), $this->email( 'b' ) );
		sort( $expected );
		$this->assertSame( $expected, $this->recipients_mailed() );
		$this->assertCount( 2, $this->rows() );
		$this->assertSame( 'accepted', $this->row_of( 'a' )['status'] );
		$this->assertSame( '1', $this->row_of( 'a' )['attempts'] );
		$this->assertNull( $this->row_of( 'c' ), 'someone who never opted in has no row' );

		// The next morning is a new day with its own email.
		$this->set_now( '2026-12-21 14:00:00' );
		$this->tick();
		$this->assertCount( 4, $this->sent );
		$this->assertNotNull( $this->row_of( 'a', 'd002' ) );
	}

	/**
	 * Nothing before 06:00 campaign time, nothing from noon on.
	 */
	public function test_send_window_is_six_to_noon_local() {
		$this->opt_in( array( 'a' ) );
		$this->set_now( '2026-12-20 13:59:00' ); // 05:59 PST.
		$this->tick();
		$this->assertCount( 0, $this->sent );
		$this->assertCount( 0, $this->rows(), 'no queue before 06:00' );

		$this->set_now( '2026-12-20 20:00:00' ); // 12:00 PST sharp: closed.
		$this->tick();
		$this->assertCount( 0, $this->sent );
		$this->assertCount( 0, $this->rows(), 'no new queue at noon' );

		$this->set_now( '2026-12-20 19:59:00' ); // 11:59: still open.
		$this->tick();
		$this->assertCount( 1, $this->sent );
	}

	/**
	 * Outside the campaign's days nothing is queued.
	 */
	public function test_no_reminders_outside_the_campaign_days() {
		$this->opt_in( array( 'a' ) );
		$this->set_now( '2026-12-19 15:00:00' ); // The day before day 1.
		$this->tick();
		$this->set_now( '2027-04-03 14:00:00' ); // The day after day 104.
		$this->tick();
		$this->assertCount( 0, $this->sent );
		$this->assertCount( 0, $this->rows() );
		$this->set_now( '2027-04-02 14:00:00' ); // Day 104 itself is fine.
		$this->make_reading_day( 104 );
		$this->tick();
		$this->assertCount( 1, $this->sent );
		$this->assertSame( 'd104', $this->rows()[0]['day_key'] );
	}

	/**
	 * The window follows daylight saving time: 06:00 local both before and after the change.
	 */
	public function test_dst_day_is_still_six_local() {
		$this->opt_in( array( 'a' ) );
		// 2027-03-14 (day 85): clocks go forward at 02:00, so 06:00 is 13:00 UTC, not 14:00.
		$this->set_now( '2027-03-14 12:59:00' ); // 05:59 PDT.
		$this->tick();
		$this->assertCount( 0, $this->sent, 'not yet 06:00 PDT' );
		$this->set_now( '2027-03-14 13:00:00' ); // 06:00 PDT.
		$this->tick();
		$this->assertCount( 1, $this->sent );
		$this->assertSame( 'd085', $this->rows()[0]['day_key'] );
		// The window still closes at 12:00 PDT = 19:00 UTC.
		$this->sent = array();
		$this->set_now( '2027-03-14 19:00:00' );
		$this->tick();
		$this->assertCount( 0, $this->sent );
	}

	/**
	 * Opted-out, left, never-consented and never-enrolled people are skipped;
	 * so is someone who opts out after the queue was built but before their send.
	 */
	public function test_only_current_recipients_get_mail_including_between_queue_and_send() {
		$this->opt_in( array( 'a', 'b', 'c' ) );
		$late = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		jlife_challenges_enroll_solo( $late, $this->campaign, 'adult' );
		jlife_challenges_set_reminder( $late, $late, $this->campaign, true, 'en_US' ); // Ticked, but never consented.

		$this->set_now( '2026-12-20 14:00:00' );
		$campaign = $this->campaign_row();
		jlife_challenges_reminders_enqueue( $campaign, jlife_challenges_now() );
		$this->assertCount( 3, $this->rows(), 'the unconsented person is not even queued' );

		// Between queueing and sending: a opts out, b leaves, c stays.
		jlife_challenges_set_reminder( $this->u['a'], $this->u['a'], $this->campaign, false );
		jlife_challenges_remove_member( $this->u['organizer'], $this->campaign, $this->u['b'] );
		$this->tick();

		$this->assertSame( array( $this->email( 'c' ) ), $this->recipients_mailed() );
		$this->assertSame( 'skipped', $this->row_of( 'a' )['status'] );
		$this->assertSame( 'not_recipient', $this->row_of( 'a' )['last_error_class'] );
		$this->assertSame( 'skipped', $this->row_of( 'b' )['status'] );
		$this->assertSame( 'accepted', $this->row_of( 'c' )['status'] );

		// An unsubscribe link between ticks stops the next day's mail as well.
		$q = array();
		wp_parse_str( (string) wp_parse_url( jlife_challenges_unsubscribe_url( $this->u['c'], $this->campaign ), PHP_URL_QUERY ), $q );
		jlife_challenges_unsubscribe_request( 'POST', $q, array( 'List-Unsubscribe' => 'One-Click' ) );
		$this->sent = array();
		$this->set_now( '2026-12-21 14:00:00' );
		$this->tick();
		$this->assertCount( 0, $this->sent );
	}

	/**
	 * A paused campaign sends nothing, even for rows already queued.
	 */
	public function test_an_inactive_campaign_sends_nothing() {
		global $wpdb;
		$this->opt_in( array( 'a' ) );
		$this->set_now( '2026-12-20 14:00:00' );
		jlife_challenges_reminders_enqueue( $this->campaign_row(), jlife_challenges_now() );
		$wpdb->update( jlife_challenges_table( 'campaigns' ), array( 'status' => 'closed' ), array( 'id' => $this->campaign ) );
		$this->tick();
		$this->assertCount( 0, $this->sent );
		$this->assertSame( 'campaign_inactive', $this->row_of( 'a' )['last_error_class'] );
	}

	/**
	 * A failed send is retried at +10, +30 and +60 minutes without duplicates,
	 * and stops after four attempts.
	 */
	public function test_retries_then_fails_after_four_attempts() {
		$this->opt_in( array( 'a' ) );
		$this->mail_result = false;
		$this->set_now( '2026-12-20 14:00:00' );
		$this->tick();
		$this->assertCount( 1, $this->sent );
		$this->assertSame( 'queued', $this->row_of( 'a' )['status'] );
		$this->assertSame( '2026-12-20 14:10:00', $this->row_of( 'a' )['next_attempt'] );
		$this->assertSame( 'send_failed', $this->row_of( 'a' )['last_error_class'] );

		$this->set_now( '2026-12-20 14:09:00' );
		$this->tick();
		$this->assertCount( 1, $this->sent, 'not due yet' );

		$this->set_now( '2026-12-20 14:10:00' );
		$this->tick();
		$this->assertCount( 2, $this->sent );
		$this->assertSame( '2026-12-20 14:40:00', $this->row_of( 'a' )['next_attempt'], '+30' );

		$this->set_now( '2026-12-20 14:40:00' );
		$this->tick();
		$this->assertCount( 3, $this->sent );
		$this->assertSame( '2026-12-20 15:40:00', $this->row_of( 'a' )['next_attempt'], '+60' );

		$this->set_now( '2026-12-20 15:40:00' );
		$this->tick();
		$this->assertCount( 4, $this->sent );
		$this->assertSame( 'failed', $this->row_of( 'a' )['status'] );
		$this->assertSame( '4', $this->row_of( 'a' )['attempts'] );

		$this->set_now( '2026-12-20 16:30:00' );
		$this->tick();
		$this->assertCount( 4, $this->sent, 'no fifth attempt' );
	}

	/**
	 * After a failure the next tick that works sends once and stops retrying.
	 */
	public function test_a_retry_that_works_is_accepted_once() {
		$this->opt_in( array( 'a', 'b' ) );
		$this->mail_result = false;
		$this->set_now( '2026-12-20 14:00:00' );
		$this->tick();
		$this->assertCount( 2, $this->sent );
		$this->mail_result = true;
		$this->sent        = array();
		$this->set_now( '2026-12-20 14:10:00' );
		$this->tick();
		$this->tick();
		$this->set_now( '2026-12-20 15:30:00' );
		$this->tick();
		$this->assertCount( 2, $this->sent, 'one successful email each, no duplicates' );
		$this->assertSame( 'accepted', $this->row_of( 'a' )['status'] );
		$this->assertSame( '2', $this->row_of( 'a' )['attempts'] );
		$this->assertNull( $this->row_of( 'a' )['last_error_class'] );
	}

	/**
	 * A retry is never scheduled at or past 12:00 campaign time.
	 */
	public function test_retries_never_run_past_noon() {
		$this->opt_in( array( 'a' ) );
		$this->mail_result = false;
		$this->set_now( '2026-12-20 19:20:00' ); // 11:20 PST.
		$this->tick();
		$this->assertSame( '2026-12-20 19:30:00', $this->row_of( 'a' )['next_attempt'] );
		$this->set_now( '2026-12-20 19:30:00' ); // 11:30: the next retry would be 12:00.
		$this->tick();
		$this->assertCount( 2, $this->sent );
		$this->assertSame( 'failed', $this->row_of( 'a' )['status'], 'no retry at or after noon' );
		$this->set_now( '2026-12-20 20:30:00' );
		$this->tick();
		$this->assertCount( 2, $this->sent );
	}

	/**
	 * A row still waiting at noon is skipped as expired and sends nothing.
	 */
	public function test_queued_rows_expire_at_noon() {
		$this->opt_in( array( 'a' ) );
		$this->set_now( '2026-12-20 14:00:00' );
		jlife_challenges_reminders_enqueue( $this->campaign_row(), jlife_challenges_now() );
		$this->set_now( '2026-12-20 20:00:00' ); // Noon.
		$this->tick();
		$this->assertCount( 0, $this->sent );
		$this->assertSame( 'skipped', $this->row_of( 'a' )['status'] );
		$this->assertSame( 'expired', $this->row_of( 'a' )['last_error_class'] );
	}

	/**
	 * Yesterday's unsent row is not sent today.
	 */
	public function test_yesterdays_rows_are_not_sent_today() {
		$this->opt_in( array( 'a' ) );
		$this->set_now( '2026-12-20 14:00:00' );
		jlife_challenges_reminders_enqueue( $this->campaign_row(), jlife_challenges_now() );
		$this->set_now( '2026-12-21 14:00:00' );
		$this->tick();
		$this->assertSame( 'skipped', $this->row_of( 'a', 'd001' )['status'] );
		$this->assertCount( 1, $this->sent, 'only today\'s email' );
		$this->assertSame( 'accepted', $this->row_of( 'a', 'd002' )['status'] );
	}

	/**
	 * A tick sends at most 25 and the rest go on the next tick.
	 */
	public function test_each_tick_sends_at_most_25() {
		$ids = array();
		for ( $i = 0; $i < 27; $i++ ) {
			$id = self::factory()->user->create( array( 'role' => 'subscriber' ) );
			jlife_challenges_enroll_solo( $id, $this->campaign, 'adult' );
			jlife_challenges_record_consent( $id, $this->campaign, 'test-version' );
			jlife_challenges_set_reminder( $id, $id, $this->campaign, true, 'en_US' );
			$ids[] = $id;
		}
		$this->set_now( '2026-12-20 14:00:00' );
		$this->tick();
		$this->assertCount( 25, $this->sent );
		$this->set_now( '2026-12-20 14:05:00' );
		$this->tick();
		$this->assertCount( 27, $this->sent );
		$this->tick();
		$this->assertCount( 27, $this->sent );
	}

	/**
	 * Mode off (the default) does nothing at all.
	 */
	public function test_mode_off_does_nothing() {
		delete_option( 'jlife_reminders_mode' );
		$this->assertSame( 'off', jlife_challenges_reminders_mode() );
		$this->opt_in( array( 'a' ) );
		$this->set_now( '2026-12-20 14:00:00' );
		$this->tick();
		$this->assertCount( 0, $this->sent );
		$this->assertCount( 0, $this->rows() );
		update_option( 'jlife_reminders_mode', 'bogus' );
		$this->assertSame( 'off', jlife_challenges_reminders_mode(), 'an unknown value is off' );
		$this->assertWPError( jlife_challenges_reminders_set_mode( 'bogus' ) );
		$this->assertTrue( jlife_challenges_reminders_set_mode( 'dry_run' ) );
		$this->assertSame( 'dry_run', jlife_challenges_reminders_mode() );
	}

	/**
	 * Dry run builds the queue and marks everything skipped; nothing is sent.
	 */
	public function test_dry_run_sends_nothing() {
		update_option( 'jlife_reminders_mode', 'dry_run' );
		$this->opt_in( array( 'a', 'b' ) );
		$this->set_now( '2026-12-20 14:00:00' );
		$this->tick();
		$this->tick();
		$this->assertCount( 0, $this->sent );
		$this->assertCount( 2, $this->rows() );
		foreach ( $this->rows() as $r ) {
			$this->assertSame( 'skipped', $r['status'] );
			$this->assertSame( 'dry_run', $r['last_error_class'] );
		}
		// Going live the same day does not send what the dry run already logged.
		update_option( 'jlife_reminders_mode', 'live' );
		$this->tick();
		$this->assertCount( 0, $this->sent );
	}

	/**
	 * A dry run still builds the message, so a day with no reading shows up as failed.
	 */
	public function test_dry_run_reports_a_missing_reading() {
		update_option( 'jlife_reminders_mode', 'dry_run' );
		$this->opt_in( array( 'a' ) );
		$this->set_now( '2026-12-22 14:00:00' ); // Day 3 has no reading post in the fixtures.
		$this->tick();
		$this->assertSame( 'failed', $this->row_of( 'a', 'd003' )['status'] );
		$this->assertSame( 'no_reading', $this->row_of( 'a', 'd003' )['last_error_class'] );
		$this->assertCount( 0, $this->sent );
	}

	/**
	 * Test mode sends only to listed account addresses (any letter case); everyone else is skipped.
	 */
	public function test_test_mode_sends_only_to_the_listed_addresses() {
		update_option( 'jlife_reminders_mode', 'test' );
		$this->opt_in( array( 'a', 'b' ) );
		$this->test_list = array( strtolower( $this->email( 'a' ) ) );
		$this->set_now( '2026-12-20 14:00:00' );
		$this->tick();
		$this->assertSame( array( $this->email( 'a' ) ), $this->recipients_mailed() );
		$this->assertSame( 'accepted', $this->row_of( 'a' )['status'] );
		$this->assertSame( 'skipped', $this->row_of( 'b' )['status'] );
		$this->assertSame( 'not_test_recipient', $this->row_of( 'b' )['last_error_class'] );
	}

	/**
	 * Test mode with no list sends to nobody.
	 */
	public function test_test_mode_with_an_empty_list_sends_nothing() {
		update_option( 'jlife_reminders_mode', 'test' );
		$this->opt_in( array( 'a' ) );
		$this->set_now( '2026-12-20 14:00:00' );
		$this->tick();
		$this->assertCount( 0, $this->sent );
		$this->assertSame( 'not_test_recipient', $this->row_of( 'a' )['last_error_class'] );
	}

	/**
	 * The constant is a comma-separated list; spaces, case and junk are tolerated.
	 */
	public function test_the_test_recipient_list_parser() {
		$this->assertSame( array( 'a@x.test', 'b@x.test' ), jlife_challenges_parse_address_list( ' A@x.test, b@X.test ,, not-an-email ' ) );
		$this->assertSame( array(), jlife_challenges_parse_address_list( '' ) );
		$this->assertSame( array(), jlife_challenges_parse_address_list( null ) );
	}

	/**
	 * When Brevo is enabled but not configured, the row fails with a fixed class and nothing falls back to host mail.
	 */
	public function test_missing_mail_configuration_fails_without_sending() {
		$this->opt_in( array( 'a' ) );
		add_filter( 'jlife_challenges_reminder_mail_state', static fn() => 'configuration_missing' );
		$this->set_now( '2026-12-20 14:00:00' );
		$this->tick();
		$this->assertCount( 0, $this->sent );
		$this->assertSame( 'failed', $this->row_of( 'a' )['status'] );
		$this->assertSame( 'configuration_missing', $this->row_of( 'a' )['last_error_class'] );
		$this->assertSame( '1', $this->row_of( 'a' )['attempts'] );
		remove_all_filters( 'jlife_challenges_reminder_mail_state' );
	}

	/**
	 * An account with no usable address fails with no_email.
	 */
	public function test_an_account_without_an_email_fails() {
		global $wpdb;
		$this->opt_in( array( 'a' ) );
		$wpdb->update( $wpdb->users, array( 'user_email' => '' ), array( 'ID' => $this->u['a'] ) );
		clean_user_cache( $this->u['a'] );
		$this->set_now( '2026-12-20 14:00:00' );
		$this->tick();
		$this->assertCount( 0, $this->sent );
		$this->assertSame( 'failed', $this->row_of( 'a' )['status'] );
		$this->assertSame( 'no_email', $this->row_of( 'a' )['last_error_class'] );
	}

	/**
	 * A transport that throws is contained: the tick finishes, the row retries, and no message text is stored.
	 */
	public function test_a_throwing_transport_is_contained_and_never_stored() {
		global $wpdb;
		$this->opt_in( array( 'a', 'b' ) );
		$this->mail_result = 'throw';
		$this->set_now( '2026-12-20 14:00:00' );
		$this->tick();
		$this->assertSame( 'queued', $this->row_of( 'a' )['status'] );
		$this->assertSame( 'send_failed', $this->row_of( 'a' )['last_error_class'] );
		$dump = wp_json_encode( $wpdb->get_results( 'SELECT * FROM ' . jlife_challenges_table( 'reminder_sends' ), ARRAY_A ) );
		$this->assertStringNotContainsString( 'boom', $dump );
		$this->assertStringNotContainsString( 'example.test', $dump );
		$this->assertStringNotContainsString( '@', $dump );
	}

	/**
	 * The log holds no address, subject, body or error text.
	 */
	public function test_the_log_holds_no_personal_data() {
		global $wpdb;
		$columns = $wpdb->get_col( 'SHOW COLUMNS FROM ' . jlife_challenges_table( 'reminder_sends' ) );
		$this->assertSame( array( 'id', 'campaign_id', 'day_key', 'user_id', 'status', 'attempts', 'next_attempt', 'last_error_class', 'created', 'updated' ), $columns );
		$indexes = $wpdb->get_results( 'SHOW INDEX FROM ' . jlife_challenges_table( 'reminder_sends' ), ARRAY_A );
		$unique  = array();
		foreach ( $indexes as $ix ) {
			if ( '0' === (string) $ix['Non_unique'] && 'PRIMARY' !== $ix['Key_name'] ) {
				$unique[ $ix['Key_name'] ][] = $ix['Column_name'];
			}
		}
		$this->assertSame( array( 'campaign_day_user' => array( 'campaign_id', 'day_key', 'user_id' ) ), $unique, 'the duplicate guard' );
	}

	/**
	 * The database itself refuses a second row for the same person and day.
	 */
	public function test_the_unique_key_blocks_a_duplicate_row() {
		$this->opt_in( array( 'a' ) );
		$this->set_now( '2026-12-20 14:00:00' );
		jlife_challenges_reminders_enqueue( $this->campaign_row(), jlife_challenges_now() );
		jlife_challenges_reminders_enqueue( $this->campaign_row(), jlife_challenges_now() );
		$this->assertCount( 1, $this->rows() );
	}

	/**
	 * Two overlapping ticks cannot both send: a held lock stops the second, and a claimed row cannot be claimed again.
	 */
	public function test_overlapping_ticks_do_not_double_send() {
		$this->opt_in( array( 'a' ) );
		$this->set_now( '2026-12-20 14:00:00' );
		update_option( 'jlife_reminders_lock', time() + 60, false ); // Another tick is running.
		$this->tick();
		$this->assertCount( 0, $this->sent );
		delete_option( 'jlife_reminders_lock' );
		jlife_challenges_reminders_enqueue( $this->campaign_row(), jlife_challenges_now() );
		$row = $this->row_of( 'a' );
		$this->assertTrue( jlife_challenges_reminders_claim( $row, jlife_challenges_now() ) );
		$this->assertFalse( jlife_challenges_reminders_claim( $row, jlife_challenges_now() ), 'a second claim of the same row loses' );
		update_option( 'jlife_reminders_lock', time() - 600, false ); // A stale lock from a crashed tick does not block.
		$this->set_now( '2026-12-20 14:20:00' ); // The claim's lease has passed.
		$this->tick();
		$this->assertCount( 1, $this->sent );
	}

	/**
	 * Content in the recipient's language: Vietnamese gets Vietnamese words and "Giăng"; English gets English.
	 */
	public function test_each_email_is_in_the_recipients_language() {
		$this->use_catalogs();
		$this->opt_in( array( 'a' ), 'vi' );
		$this->opt_in( array( 'b' ), 'en_US' );
		$this->set_now( '2026-12-20 14:00:00' );
		$this->tick();
		$by = array();
		foreach ( $this->sent as $m ) {
			$by[ $m['to'] ] = $m;
		}
		$vi = $by[ $this->email( 'a' ) ];
		$en = $by[ $this->email( 'b' ) ];
		$this->assertStringStartsWith( 'Ngày 1', $vi['subject'] );
		$this->assertStringContainsString( 'Giăng Báp-tít', $vi['subject'] );
		$this->assertStringContainsString( 'Giăng 3:1-21', $vi['message'] );
		$this->assertStringContainsString( 'Giăng 3:22-4:3', $vi['message'] );
		$this->assertStringNotContainsString( 'John 3', $vi['message'] );
		$this->assertStringContainsString( 'Ngày 1', $vi['message'] );
		$this->assertStringNotContainsString( 'Open today', $vi['message'] );

		$this->assertStringStartsWith( 'Day 1', $en['subject'] );
		$this->assertStringContainsString( 'Jesus and Nicodemus', $en['subject'] );
		$this->assertStringContainsString( 'John 3:1-21', $en['message'] );
		$this->assertStringNotContainsString( 'Giăng', $en['message'] );
		$this->assertStringNotContainsString( 'Ngày', $en['message'] );
		$this->assertSame( 'en_US', determine_locale(), 'the page language is restored' );
	}

	/**
	 * What the email holds and what it must not: Today link, unsubscribe link, headers; no Bible text, SOAP, sign-in token or login link.
	 */
	public function test_message_content_headers_and_no_secrets() {
		$this->use_catalogs();
		$this->opt_in( array( 'a' ) );
		$this->assertIsInt( jlife_challenges_save_draft( $this->u['a'], $this->campaign, 'd001', $this->soap( 'PRIVATE-SOAP-MARKER' ) ) );
		$this->set_now( '2026-12-20 14:00:00' );
		$this->tick();
		$this->assertCount( 1, $this->sent );
		$m     = $this->sent[0];
		$today = home_url( '/today/' );
		$unsub = jlife_challenges_unsubscribe_url( $this->u['a'], $this->campaign );

		$this->assertStringContainsString( $today, $m['message'] );
		$this->assertStringContainsString( esc_url( $unsub ), $m['message'] );
		$this->assertStringNotContainsString( 'PRIVATE-SOAP-MARKER', $m['message'] . $m['subject'] );
		$this->assertStringNotContainsString( 'Aname', $m['message'], 'no names' );
		$urls = array();
		preg_match_all( '#https?://[^\s"<>\']+#', html_entity_decode( $m['message'] ), $found );
		foreach ( $found[0] as $u ) {
			$urls[ $u ] = true;
		}
		$this->assertSame( array( $today, $unsub ), array_keys( $urls ), 'only the Today link and the unsubscribe link' );
		$this->assertDoesNotMatchRegularExpression( '#signin|sign-in|login|code=|jlife_auth#i', $m['message'] );

		$headers = implode( "\n", (array) $m['headers'] );
		$this->assertStringContainsString( 'List-Unsubscribe: <' . $unsub . '>', $headers );
		$this->assertStringContainsString( 'List-Unsubscribe-Post: List-Unsubscribe=One-Click', $headers );
		$this->assertStringContainsString( 'text/html', $headers );
	}

	/**
	 * Through the real mailer: a multipart message with the plain-text part and the one-click headers.
	 * Sent as SMTP, as Brevo sends it: PHPMailer's mail() transport limits header lines to 63
	 * characters and would Q-encode the long List-Unsubscribe URL, which mail clients cannot read.
	 */
	public function test_real_mailer_message_has_plain_text_and_one_click_headers() {
		remove_filter( 'pre_wp_mail', array( $this, 'capture_mail' ), 5 );
		reset_phpmailer_instance();
		add_filter( 'wp_mail_from', static fn() => 'reminders@example.test' ); // The suite's default sender host is not a valid domain.
		$smtp = static function ( $mailer ) {
			$mailer->Mailer = 'smtp'; // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- PHPMailer's property; the test mailer never connects.
		};
		add_action( 'phpmailer_init', $smtp );
		$this->opt_in( array( 'a' ) );
		$this->set_now( '2026-12-20 14:00:00' );
		$this->tick();
		$mailer = tests_retrieve_phpmailer_instance();
		$sent   = $mailer->get_sent();
		$this->assertNotFalse( $sent, 'the mailer got a message' );
		$this->assertStringContainsString( 'List-Unsubscribe: <' . jlife_challenges_unsubscribe_url( $this->u['a'], $this->campaign ) . '>', $sent->header );
		$this->assertStringContainsString( 'List-Unsubscribe-Post: List-Unsubscribe=One-Click', $sent->header );
		$this->assertStringContainsString( 'multipart/alternative', $sent->header );
		$this->assertStringContainsString( 'John 3:1-21', $sent->body );
		$this->assertStringContainsString( 'Content-Type: text/plain', $sent->body );
		$this->assertStringContainsString( 'Content-Type: text/html', $sent->body );
		$this->assertSame( 'accepted', $this->row_of( 'a' )['status'] );
		remove_action( 'phpmailer_init', $smtp );
		reset_phpmailer_instance();
	}

	/**
	 * Deleting an account removes its reminder send rows.
	 */
	public function test_account_deletion_purges_sends() {
		require_once ABSPATH . 'wp-admin/includes/user.php';
		$this->opt_in( array( 'a', 'b' ) );
		$this->set_now( '2026-12-20 14:00:00' );
		$this->tick();
		$this->assertCount( 2, $this->rows() );
		wp_delete_user( $this->u['a'] );
		$this->assertNull( $this->row_of( 'a' ) );
		$this->assertNotNull( $this->row_of( 'b' ) );
	}

	/**
	 * Status for today: counts by outcome, no addresses.
	 */
	public function test_status_counts_today_by_status() {
		$this->opt_in( array( 'a', 'b' ) );
		$this->set_now( '2026-12-20 14:00:00' );
		$this->tick();
		$s = jlife_challenges_reminders_status( $this->campaign_row() );
		$this->assertSame( 'live', $s['mode'] );
		$this->assertSame( 'd001', $s['day_key'] );
		$this->assertSame( 2, $s['counts']['accepted'] );
		$this->assertSame( 0, $s['counts']['queued'] );
		$this->assertTrue( $s['window_open'] );
		$this->assertStringNotContainsString( '@', wp_json_encode( $s ) );
	}

	/**
	 * The scheduler: a five-minute schedule, one event, cleared on deactivation.
	 */
	public function test_cron_event_is_scheduled_and_cleared() {
		$schedules = wp_get_schedules();
		$this->assertSame( 300, $schedules['jlife_five_minutes']['interval'] );
		wp_clear_scheduled_hook( JLIFE_CHALLENGES_REMINDERS_HOOK );
		jlife_challenges_reminders_schedule();
		jlife_challenges_reminders_schedule();
		$this->assertSame( 'jlife_five_minutes', wp_get_schedule( JLIFE_CHALLENGES_REMINDERS_HOOK ) );
		$this->assertCount( 1, _get_cron_array() ? array_filter( _get_cron_array(), static fn( $e ) => isset( $e[ JLIFE_CHALLENGES_REMINDERS_HOOK ] ) ) : array(), 'one event' );
		jlife_challenges_reminders_unschedule();
		$this->assertFalse( wp_next_scheduled( JLIFE_CHALLENGES_REMINDERS_HOOK ) );
		$this->assertSame( '6', JLIFE_CHALLENGES_DB_VERSION );
		$this->assertTrue( has_action( JLIFE_CHALLENGES_REMINDERS_HOOK, 'jlife_challenges_reminders_cron' ) !== false );
	}

	/**
	 * Nothing about reminders runs on a page request: the scheduler hook is the only trigger.
	 */
	public function test_sending_is_only_wired_to_cron() {
		$src = (string) file_get_contents( dirname( __DIR__ ) . '/includes/reminder-sends.php' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- reading the plugin's own source.
		preg_match_all( '/add_action\(\s*\'([a-z_]+)\'/', $src, $m );
		foreach ( $m[1] as $hook ) {
			$this->assertNotContains( $hook, array( 'wp_footer', 'wp_head', 'template_redirect', 'shutdown' ), 'no page-request trigger' );
		}
		$this->assertStringContainsString( "add_action( JLIFE_CHALLENGES_REMINDERS_HOOK, 'jlife_challenges_reminders_cron' )", $src );
	}

	/**
	 * Preview builds a message for a fake recipient without sending or logging.
	 */
	public function test_preview_builds_without_sending_or_logging() {
		$this->use_catalogs();
		$p = jlife_challenges_reminder_preview( $this->campaign_row(), 1, 'vi' );
		$this->assertStringStartsWith( 'Ngày 1', $p['subject'] );
		$this->assertStringContainsString( 'Giăng 3:1-21', $p['text'] );
		$this->assertCount( 0, $this->sent );
		$this->assertCount( 0, $this->rows() );
		$this->assertNull( jlife_challenges_reminder_preview( $this->campaign_row(), 3, 'vi' ), 'no reading for that day' );
		$this->assertNull( jlife_challenges_reminder_preview( $this->campaign_row(), 500, 'vi' ), 'outside the campaign' );
	}
}
