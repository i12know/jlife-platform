<?php
/**
 * Official-Marathon reminder consent during Dec 14–19 onboarding (#181), and
 * the consent record a later join carries (PR #197 decision 4).
 *
 * The story: a pilot (Nov 29–Dec 13) and the official Marathon (Dec 20 – Apr 2),
 * both Pacific time. Pilot participants made different reminder choices; the
 * pilot is closed on Dec 14 and the rollover carries everyone on Dec 15. Mail
 * is captured with a pre_wp_mail filter (nothing leaves the test). 14:00 UTC
 * is 06:00 Pacific in December, inside the 06:00–12:00 send window.
 *
 * @package jlife-challenges
 */

// phpcs:disable WordPress.Security.NonceVerification, WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared -- tests drive the handlers and read the custom tables.

/**
 * Official reminder consent tests.
 */
class Test_Official_Reminder_Consent extends Jlife_Challenges_TestCase {

	/**
	 * Pilot campaign ID.
	 *
	 * @var int
	 */
	private $pilot = 0;

	/**
	 * Official campaign ID.
	 *
	 * @var int
	 */
	private $official = 0;

	/**
	 * People of the story, by their pilot reminder choice: on, off, none; vi (Vietnamese account, no choice).
	 *
	 * @var int[]
	 */
	private $p = array();

	/**
	 * Messages "sent" by wp_mail.
	 *
	 * @var array[]
	 */
	private $sent = array();

	/**
	 * The reading days and the consent version need the studies and auth plugins.
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
	 * The pilot week and the rollover, in Live mode, with mail captured.
	 */
	public function set_up() {
		parent::set_up();
		global $wpdb;
		$wpdb->update( jlife_challenges_table( 'campaigns' ), array( 'status' => 'draft' ), array( 'id' => $this->campaign ) );
		$this->sent = array();
		wp_set_current_user( 0 );
		unload_textdomain( 'jlife-challenges', true ); // Strings follow the signed-in person's language (jlife-auth is loaded here).
		add_filter( 'pre_wp_mail', array( $this, 'capture_mail' ), 5, 2 );
		update_option( 'jlife_reminders_mode', 'live' );
		delete_option( 'jlife_reminders_lock' );

		$this->set_now( '2026-11-29 04:00:00' ); // Nov 28, 8:00 PM Pacific.
		$this->pilot    = (int) jlife_challenges_create_pilot_campaign( $this->u['organizer'] )['campaign_id'];
		$this->official = (int) jlife_challenges_create_official_campaign( $this->u['organizer'] )['campaign_id'];

		foreach ( array( 'on', 'off', 'none', 'vi' ) as $who ) {
			$this->p[ $who ] = self::factory()->user->create(
				array(
					'role'       => 'subscriber',
					'user_email' => $who . '-reader@example.test',
				)
			);
			update_user_meta( $this->p[ $who ], 'locale', 'vi' === $who ? 'vi' : 'en_US' );
			$this->assertIsInt( jlife_challenges_enroll_solo( $this->p[ $who ], $this->pilot, 'adult' ) );
			jlife_auth_record_consent( $this->p[ $who ] ); // Account record and the pilot enrollment's.
		}

		// Pilot week (Live): one opts in, one opts out, two never choose.
		$this->set_now( '2026-12-06 18:00:00' );
		$this->assertTrue( jlife_challenges_set_reminder( $this->p['on'], $this->p['on'], $this->pilot, true ) );
		$this->assertTrue( jlife_challenges_set_reminder( $this->p['off'], $this->p['off'], $this->pilot, false ) );

		// Dec 14: the pilot is closed. Dec 15: the rollover runs.
		$this->set_now( '2026-12-14 18:00:00' );
		$this->assertIsArray( jlife_challenges_close_campaign( $this->u['organizer'], $this->pilot ) );
		$this->set_now( '2026-12-15 18:00:00' );
		$this->assertIsArray( jlife_challenges_rollover_execute( $this->u['organizer'], $this->pilot, $this->official ) );
		foreach ( array( 1, 2 ) as $i ) {
			$this->make_reading_day( $i );
		}
	}

	/**
	 * Remove the fakes.
	 */
	public function tear_down() {
		remove_filter( 'pre_wp_mail', array( $this, 'capture_mail' ), 5 );
		delete_option( 'jlife_reminders_lock' );
		$_POST    = array();
		$_REQUEST = array();
		$_GET     = array();
		wp_set_current_user( 0 );
		unload_textdomain( 'jlife-challenges', true );
		parent::tear_down();
	}

	/**
	 * Fake transport: record the message.
	 *
	 * @param null|bool $pre  Short-circuit value.
	 * @param array     $atts wp_mail arguments.
	 * @return bool
	 */
	public function capture_mail( $pre, $atts ) {
		$this->sent[] = $atts;
		return true;
	}

	/**
	 * A reading-day post for the shared plan.
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
				'title'    => 'Jesus and Nicodemus',
				'titles'   => array( 'Jesus and Nicodemus' ),
				'passages' => array( array( 'display' => 'John 3:1-21' ) ),
			)
		);
		jlife_studies_put_json_meta( $id, '_jlife_plan', array() );
	}

	/**
	 * Save through the reminder REST route as a person.
	 *
	 * @param int   $user_id Signed-in user.
	 * @param array $body    JSON body.
	 * @return WP_REST_Response
	 */
	private function rest_as( $user_id, $body ) {
		wp_set_current_user( $user_id );
		$req = new WP_REST_Request( 'POST', '/jlife/v1/reminder' );
		$req->set_header( 'Content-Type', 'application/json' );
		$req->set_body( (string) wp_json_encode( $body ) );
		return rest_get_server()->dispatch( $req );
	}

	/**
	 * Today (no reading day yet) and My Marathon for a person.
	 *
	 * @param int $user_id Participant.
	 * @return array{0: string, 1: string}
	 */
	private function screens( $user_id ) {
		wp_set_current_user( $user_id );
		$view  = jlife_challenges_day_view( $user_id );
		$none  = (string) jlife_challenges_render_no_day( $view );
		$today = 'active' === $view['state'] ? jlife_challenges_render_reminder_toggle( $user_id, jlife_challenges_reminder_campaign_id( $user_id ) ) : jlife_challenges_today_no_day_body( $view, $none, $user_id );
		return array( $today, jlife_challenges_render_reminder_settings( $user_id, jlife_challenges_reminder_campaign_id( $user_id ) ) );
	}

	/**
	 * Is the checkbox in this markup ticked? Null when there is none.
	 *
	 * @param string $html Markup.
	 * @return bool|null
	 */
	private function ticked( $html ) {
		if ( ! preg_match( '/<input type="checkbox" id="[^"]+" name="jl_reminder" value="on"([^>]*)>/', $html, $m ) ) {
			return null;
		}
		return str_contains( $m[1], 'checked' );
	}

	/**
	 * Rows of a table for a campaign.
	 *
	 * @param string $table       Table suffix.
	 * @param int    $campaign_id Campaign.
	 * @return array[]
	 */
	private function rows( $table, $campaign_id ) {
		global $wpdb;
		return (array) $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . jlife_challenges_table( $table ) . ' WHERE campaign_id = %d ORDER BY id', $campaign_id ), ARRAY_A );
	}

	/**
	 * Run one scheduler tick at a moment (UTC).
	 *
	 * @param string $utc 'Y-m-d H:i:s'.
	 */
	private function tick_at( $utc ) {
		$this->set_now( $utc );
		jlife_challenges_reminders_tick();
	}

	/**
	 * Dec 15–19, Live: the official-enrolled see an unticked choice naming the
	 * start date on Today ("Almost time") and My Marathon; the wording follows
	 * the campaign's own start date.
	 */
	public function test_onboarding_choice_names_the_start_date_and_is_unticked() {
		foreach ( array( '2026-12-15 18:00:00', '2026-12-20 07:59:59' ) as $moment ) { // Dec 15, and Dec 19 23:59:59 Pacific.
			$this->set_now( $moment );
			foreach ( $this->p as $who => $id ) {
				$this->assertSame( $this->official, jlife_challenges_reminder_campaign_id( $id ), "{$who}: the official campaign is the target" );
				list( $today, $marathon ) = $this->screens( $id );
				$this->assertStringContainsString( '<h1>Almost time</h1>', $today );
				foreach ( array( $today, $marathon ) as $html ) {
					$this->assertStringContainsString( 'Email me each morning&#039;s Marathon reading starting December 20, 2026?', $html, "{$who} at {$moment}" );
					$this->assertFalse( $this->ticked( $html ), "{$who}: unticked" );
				}
			}
		}
		// The wording uses the campaign's own date, not a fixed one.
		global $wpdb;
		$wpdb->update( jlife_challenges_table( 'campaigns' ), array( 'start_date' => '2026-12-27' ), array( 'id' => $this->official ) );
		$this->set_now( '2026-12-21 18:00:00' );
		$this->assertStringContainsString( 'starting December 27, 2026?', jlife_challenges_render_reminder_toggle( $this->p['on'], $this->official ) );
	}

	/**
	 * Vietnamese: the onboarding choice has a draft, with the date in Vietnamese order.
	 */
	public function test_onboarding_choice_in_vietnamese() {
		$force = static fn() => 'vi';
		add_filter( 'determine_locale', $force, 99 );
		add_filter( 'lang_dir_for_domain', static fn( $path, $domain ) => 'jlife-challenges' === $domain ? dirname( __DIR__ ) . '/languages/' : $path, 10, 2 );
		unload_textdomain( 'jlife-challenges', true );
		$html = jlife_challenges_render_reminder_toggle( $this->p['vi'], $this->official );
		remove_filter( 'determine_locale', $force, 99 );
		unload_textdomain( 'jlife-challenges', true );
		$this->assertStringContainsString( 'Gửi email bài đọc Marathon cho tôi mỗi sáng, bắt đầu từ ', $html );
		$this->assertStringContainsString( '20 tháng 12, 2026?', $html );
	}

	/**
	 * Not in Off, Dry run or Test; not for someone outside the official
	 * campaign (whose campaign is the closed pilot); not for a closed pilot.
	 */
	public function test_hidden_outside_live_and_outside_the_official_campaign() {
		foreach ( array( 'off', 'dry_run', 'test' ) as $mode ) {
			update_option( 'jlife_reminders_mode', $mode );
			list( $today, $marathon ) = $this->screens( $this->p['on'] );
			$this->assertNull( $this->ticked( $today ), "{$mode}: Today" );
			$this->assertNull( $this->ticked( $marathon ), "{$mode}: My Marathon" );
			$this->assertStringContainsString( '<h1>Almost time</h1>', $today, "{$mode}: the page is otherwise the same" );
		}
		update_option( 'jlife_reminders_mode', 'live' );

		// Pilot only (removed from the official campaign before the choice): the closed pilot offers no choice.
		jlife_challenges_remove_member( $this->u['organizer'], $this->official, $this->p['none'] );
		$this->assertSame( $this->pilot, jlife_challenges_reminder_campaign_id( $this->p['none'] ) );
		list( $today, $marathon ) = $this->screens( $this->p['none'] );
		$this->assertNull( $this->ticked( $today ) );
		$this->assertNull( $this->ticked( $marathon ) );
		$this->assertSame( '', jlife_challenges_render_reminder_toggle( $this->p['on'], $this->pilot ), 'a closed campaign offers no choice' );

		// And no "on" reaches the closed pilot, through REST or the form.
		$r = $this->rest_as( $this->p['none'], array( 'on' => true ) );
		$this->assertSame( 403, $r->get_status() );
		$this->assertSame( 'jlife_reminders_not_offered', $r->get_data()['code'] );
		wp_set_current_user( $this->p['none'] );
		$_POST    = array(
			'jl_reminder'     => 'on',
			'_jlife_reminder' => wp_create_nonce( JLIFE_CHALLENGES_REMINDER_NONCE ),
		);
		$_REQUEST = $_POST;
		$this->assertSame( '', jlife_challenges_handle_reminder_post( $this->p['none'], $this->pilot ) );
		$this->assertNull( jlife_challenges_reminder_pref( $this->p['none'], $this->pilot ) );
	}

	/**
	 * Nothing is carried: after the rollover the official campaign has no
	 * reminder rows and no recipients; the pilot opt-in is not pre-ticked, nor
	 * is the pilot opt-out; the pilot rows are untouched.
	 */
	public function test_no_consent_is_carried_and_nothing_is_pre_ticked() {
		$pilot_rows = $this->rows( 'reminder_prefs', $this->pilot );
		$this->assertCount( 2, $pilot_rows );
		$this->assertSame( array(), $this->rows( 'reminder_prefs', $this->official ), 'no official row for anyone' );
		$this->assertSame( array(), jlife_challenges_reminder_recipients( $this->official ) );
		foreach ( array( 'on', 'off', 'none' ) as $who ) {
			list( $today, $marathon ) = $this->screens( $this->p[ $who ] );
			$this->assertFalse( $this->ticked( $today ), "{$who}: unticked on Today" );
			$this->assertFalse( $this->ticked( $marathon ), "{$who}: unticked on My Marathon" );
		}
		$this->assertSame( array(), $this->rows( 'reminder_prefs', $this->official ), 'rendering writes nothing' );
		$this->assertSame( $pilot_rows, $this->rows( 'reminder_prefs', $this->pilot ), 'pilot rows untouched' );
	}

	/**
	 * Ticking it records the choice against the official campaign, with the
	 * consent wording version, in the account's language; the pilot row is
	 * not touched. Unticking works too.
	 */
	public function test_choice_is_recorded_against_the_official_campaign() {
		$pilot_rows = $this->rows( 'reminder_prefs', $this->pilot );
		$r          = $this->rest_as( $this->p['vi'], array( 'on' => true ) );
		$this->assertSame( 200, $r->get_status() );
		$pref = jlife_challenges_reminder_pref( $this->p['vi'], $this->official );
		$this->assertTrue( jlife_challenges_reminder_is_on( $pref ) );
		$this->assertSame( JLIFE_AUTH_CONSENT_VERSION, $pref['consent_version'] );
		$this->assertSame( 'vi', $pref['locale'], 'the account language' );
		$this->assertSame( array( $this->p['vi'] ), jlife_challenges_reminder_recipients( $this->official ) );
		$this->assertTrue( $this->ticked( $this->screens( $this->p['vi'] )[0] ), 'ticked on the next visit' );

		// The pilot opt-out person chooses yes for the Marathon: only the official row changes.
		wp_set_current_user( $this->p['off'] );
		$_POST    = array(
			'jl_reminder'     => 'on',
			'_jlife_reminder' => wp_create_nonce( JLIFE_CHALLENGES_REMINDER_NONCE ),
		);
		$_REQUEST = $_POST;
		$this->assertSame( 'on', jlife_challenges_handle_reminder_post( $this->p['off'], jlife_challenges_reminder_campaign_id( $this->p['off'] ) ) );
		$this->assertTrue( jlife_challenges_reminder_is_on( jlife_challenges_reminder_pref( $this->p['off'], $this->official ) ) );
		$this->assertSame( 'en_US', jlife_challenges_reminder_pref( $this->p['off'], $this->official )['locale'] );
		$this->assertSame( $pilot_rows, $this->rows( 'reminder_prefs', $this->pilot ), 'pilot rows untouched' );

		$this->assertFalse( $this->rest_as( $this->p['vi'], array( 'on' => false ) )->get_data()['on'], 'unticking works' );
		$this->assertSame( array( $this->p['off'] ), jlife_challenges_reminder_recipients( $this->official ) );
	}

	/**
	 * Nothing is sent before Day 1; the closed pilot sends nothing (even a row
	 * left queued); Day 1 sends once per person, however often the scheduler runs.
	 */
	public function test_no_send_before_day_1_and_day_1_once() {
		foreach ( array( 'on', 'vi' ) as $who ) {
			$this->assertSame( 200, $this->rest_as( $this->p[ $who ], array( 'on' => true ) )->get_status() );
		}
		// A pilot row someone left queued: never sent.
		global $wpdb;
		$wpdb->insert(
			jlife_challenges_table( 'reminder_sends' ),
			array(
				'campaign_id'  => $this->pilot,
				'day_key'      => 'd009',
				'user_id'      => $this->p['on'],
				'status'       => 'queued',
				'attempts'     => 0,
				'next_attempt' => '2026-12-13 14:00:00',
				'created'      => '2026-12-13 14:00:00',
				'updated'      => '2026-12-13 14:00:00',
			)
		);

		foreach ( array( '2026-12-15 14:00:00', '2026-12-16 17:00:00', '2026-12-19 15:00:00', '2026-12-20 07:59:00' ) as $moment ) {
			$this->tick_at( $moment );
		}
		$this->assertSame( array(), $this->sent, 'nothing before Day 1' );
		$this->assertSame( array(), $this->rows( 'reminder_sends', $this->official ) );
		$pilot_sends = $this->rows( 'reminder_sends', $this->pilot );
		$this->assertCount( 1, $pilot_sends, 'no new pilot rows' );
		$this->assertNotSame( 'accepted', $pilot_sends[0]['status'], 'the queued pilot row is not sent' );

		// Day 1, 06:00 and 06:05 Pacific, and again at 11:55.
		foreach ( array( '2026-12-20 14:00:00', '2026-12-20 14:05:00', '2026-12-20 19:55:00' ) as $moment ) {
			$this->tick_at( $moment );
		}
		$to = array_map( static fn( $m ) => is_array( $m['to'] ) ? $m['to'][0] : $m['to'], $this->sent );
		sort( $to );
		$this->assertSame( array( 'on-reader@example.test', 'vi-reader@example.test' ), $to, 'one Day 1 email each, only to who chose' );
		$rows = $this->rows( 'reminder_sends', $this->official );
		$this->assertCount( 2, $rows );
		foreach ( $rows as $row ) {
			$this->assertSame( 'd001', $row['day_key'] );
			$this->assertSame( 'accepted', $row['status'] );
		}
		$this->assertCount( 1, $this->rows( 'reminder_sends', $this->pilot ), 'still no pilot send' );
	}

	/**
	 * From Day 1, someone who has not chosen (or joins late) sees the everyday
	 * checkbox, Live only; choosing then sends from the next morning.
	 */
	public function test_late_joiner_after_day_1_sees_the_everyday_checkbox() {
		$this->set_now( '2026-12-21 18:00:00' );
		$late = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		update_user_meta( $late, 'jlife_consent_version', 'consent-late' );
		update_user_meta( $late, 'jlife_consent_at', '2026-11-29 20:00:00' );
		$this->assertIsInt( jlife_challenges_enroll_solo( $late, (int) jlife_challenges_open_campaign()['id'], 'adult' ) );
		$this->assertSame( $this->official, jlife_challenges_reminder_campaign_id( $late ) );
		foreach ( array( $late, $this->p['none'] ) as $id ) {
			wp_set_current_user( $id );
			$html = jlife_challenges_render_reminder_toggle( $id, $this->official );
			$this->assertStringContainsString( 'Email me each morning&#039;s reading.', $html );
			$this->assertStringNotContainsString( 'starting', $html );
			$this->assertFalse( $this->ticked( $html ) );
		}
		update_option( 'jlife_reminders_mode', 'test' );
		$this->assertSame( '', jlife_challenges_render_reminder_toggle( $late, $this->official ), 'Live only' );
		update_option( 'jlife_reminders_mode', 'live' );

		$this->assertSame( 200, $this->rest_as( $late, array( 'on' => true ) )->get_status() );
		$this->assertContains( $late, jlife_challenges_reminder_recipients( $this->official ), 'a recipient: the join carried the consent record' );
		$this->make_reading_day( 3 );
		$this->tick_at( '2026-12-22 14:00:00' ); // Day 3, 06:00 Pacific.
		$this->assertCount( 1, $this->sent );
	}

	/**
	 * The consent gap (PR #197 decision 4): someone who already accepted the
	 * participation consent and joins another campaign carries the account's
	 * record into it, through every way in; someone without one does not; the
	 * record is never invented; a re-joined enrollment keeps its own.
	 */
	public function test_a_join_carries_the_accounts_existing_consent_record() {
		// The official campaign is the one open to join (Dec 15, the pilot closed).
		$this->assertSame( $this->official, (int) jlife_challenges_open_campaign()['id'] );
		$with    = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		$without = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		$half    = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		$coded   = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		foreach ( array( $with, $coded ) as $id ) {
			update_user_meta( $id, 'jlife_consent_version', 'consent-earlier' );
			update_user_meta( $id, 'jlife_consent_at', '2026-11-29 20:00:00' );
		}
		update_user_meta( $half, 'jlife_consent_version', 'consent-earlier' ); // A version without a time is not a record.

		$this->assertIsInt( jlife_challenges_enroll_solo( $with, $this->official, 'adult' ) );
		$this->assertIsInt( jlife_challenges_enroll_solo( $without, $this->official, 'adult' ) );
		$this->assertIsInt( jlife_challenges_enroll_solo( $half, $this->official, 'adult' ) );
		$group = jlife_challenges_create_group( $this->u['organizer'], $this->official, 'Official Group' );
		$this->assertIsInt( jlife_challenges_join_with_code( $coded, $group['invite_code'], 'adult' ) );

		$e = jlife_challenges_get_enrollment( $this->official, $with );
		$this->assertSame( 'consent-earlier', $e['consent_version'] );
		$this->assertSame( '2026-11-29 20:00:00', $e['consented'], 'the account record, as written' );
		$this->assertSame( '2026-11-29 20:00:00', jlife_challenges_get_enrollment( $this->official, $coded )['consented'], 'invite-code join too' );
		$this->assertNull( jlife_challenges_get_enrollment( $this->official, $without )['consented'], 'no record: none' );
		$this->assertNull( jlife_challenges_get_enrollment( $this->official, $half )['consented'], 'never invented' );

		// The carried pilot people still have their own (from the rollover), unchanged by a later join.
		$before = jlife_challenges_get_enrollment( $this->official, $this->p['on'] );
		jlife_challenges_enroll_solo( $this->p['on'], $this->official, 'adult' );
		$this->assertSame( $before, jlife_challenges_get_enrollment( $this->official, $this->p['on'] ) );

		// A re-joined enrollment keeps its own record, not the account's newer one.
		jlife_challenges_record_consent( $without, $this->official, 'consent-own' );
		$own = jlife_challenges_get_enrollment( $this->official, $without )['consented'];
		$this->assertTrue( jlife_challenges_remove_member( $this->u['organizer'], $this->official, $without ) );
		update_user_meta( $without, 'jlife_consent_version', 'consent-later' );
		update_user_meta( $without, 'jlife_consent_at', '2026-12-15 20:00:00' );
		$this->assertIsInt( jlife_challenges_enroll_solo( $without, $this->official, 'adult' ) );
		$e = jlife_challenges_get_enrollment( $this->official, $without );
		$this->assertSame( 'consent-own', $e['consent_version'] );
		$this->assertSame( $own, $e['consented'] );

		// The joiner with a record can be a recipient once they choose; without one they cannot.
		$this->assertSame( 200, $this->rest_as( $with, array( 'on' => true ) )->get_status() );
		$this->assertSame( 200, $this->rest_as( $half, array( 'on' => true ) )->get_status() );
		$recipients = jlife_challenges_reminder_recipients( $this->official );
		$this->assertContains( $with, $recipients );
		$this->assertNotContains( $half, $recipients, 'asked at sign-in first' );
	}
}
