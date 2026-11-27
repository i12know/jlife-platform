<?php
/**
 * The recipients listing (`wp jlife reminders recipients`): who the next send
 * uses, who opted in but is left out and why, who unsubscribed. Read-only, and
 * no email address anywhere in the output. The command is a thin wrapper, so the
 * tests use the plain functions it calls.
 *
 * @package jlife-challenges
 */

// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared -- tests read the custom tables directly.

/**
 * Recipients listing tests.
 */
class Test_Reminder_Recipients extends Jlife_Challenges_TestCase {

	/**
	 * Extra participants: d (unsubscribed) and e (will be emailed, no log row).
	 *
	 * @var array<string,int>
	 */
	private $extra = array();

	/**
	 * Mail "sent" during a test (must stay empty).
	 *
	 * @var array[]
	 */
	private $sent = array();

	/**
	 * Scenario on Day 1: a will be emailed (a failed log row today), e will be
	 * emailed (no log row), b left the campaign, c never accepted consent, d
	 * unsubscribed.
	 */
	public function set_up() {
		parent::set_up();
		foreach ( array( 'd', 'e' ) as $n ) {
			$this->extra[ $n ] = self::factory()->user->create(
				array(
					'role'         => 'subscriber',
					'display_name' => ucfirst( $n ) . 'name Shown',
					'user_email'   => $n . '-private@example.test',
				)
			);
			$this->assertIsInt( jlife_challenges_enroll( $this->u['organizer'], $this->extra[ $n ], $this->g['g1'] ) );
		}
		wp_update_user(
			array(
				'ID'         => $this->u['a'],
				'user_email' => 'a-private@example.test',
			)
		);
		foreach ( array( $this->u['a'], $this->u['b'], $this->extra['d'], $this->extra['e'] ) as $id ) {
			jlife_challenges_record_consent( $id, $this->campaign, 'test-version' ); // c has none.
		}
		foreach ( array( $this->u['a'], $this->u['b'], $this->u['c'], $this->extra['d'], $this->extra['e'] ) as $id ) {
			$this->assertTrue( jlife_challenges_set_reminder( $id, $id, $this->campaign, true, $id === $this->u['c'] ? 'vi' : 'en_US' ) );
		}
		jlife_challenges_set_reminder( $this->extra['d'], $this->extra['d'], $this->campaign, false );
		jlife_challenges_remove_member( $this->u['organizer'], $this->campaign, $this->u['b'] );
		$now = current_time( 'mysql', true );
		global $wpdb;
		$wpdb->insert(
			jlife_challenges_table( 'reminder_sends' ),
			array(
				'campaign_id'      => $this->campaign,
				'day_key'          => 'd001',
				'user_id'          => $this->u['a'],
				'status'           => 'failed',
				'attempts'         => 4,
				'next_attempt'     => $now,
				'last_error_class' => 'send_failed',
				'created'          => $now,
				'updated'          => $now,
			)
		);
		$this->sent = array();
		add_filter( 'pre_wp_mail', array( $this, 'capture_mail' ), 5, 2 );
	}

	/**
	 * Remove the fake transport.
	 */
	public function tear_down() {
		remove_filter( 'pre_wp_mail', array( $this, 'capture_mail' ), 5 );
		parent::tear_down();
	}

	/**
	 * Fake transport: record anything sent.
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
	 * The report for the base campaign, sections keyed by their key.
	 *
	 * @param int $user_id Filter (0 = everyone).
	 * @return array<string,array> Sections.
	 */
	private function sections( $user_id = 0 ) {
		$report = jlife_challenges_reminder_recipients_report( $this->campaign_row(), $user_id );
		$by_key = array();
		foreach ( $report['sections'] as $section ) {
			$by_key[ $section['key'] ] = $section;
		}
		return $by_key;
	}

	/**
	 * User IDs of a section's rows.
	 *
	 * @param array $section Section.
	 * @return int[]
	 */
	private function ids( $section ) {
		return array_map( 'intval', wp_list_pluck( $section['rows'], 'user_id' ) );
	}

	/**
	 * Section one is exactly the people the send uses, with language, date and today's log.
	 */
	public function test_will_be_emailed_matches_the_send_list_with_language_and_log_status() {
		$s = $this->sections();
		$this->assertSame( jlife_challenges_reminder_recipients( $this->campaign ), $this->ids( $s['will_be_emailed'] ) );
		$this->assertSame( array( $this->u['a'], $this->extra['e'] ), $this->ids( $s['will_be_emailed'] ) );
		$a = $s['will_be_emailed']['rows'][0];
		$this->assertSame( 'Aname Private', $a['display_name'] );
		$this->assertSame( 'en_US', $a['language'] );
		$this->assertNotSame( '', $a['opted_in_utc'] );
		$this->assertSame( 'failed (send_failed)', $a['today'] );
		$this->assertSame( 'none yet', $s['will_be_emailed']['rows'][1]['today'] );
		$this->assertSame( 'Total, will be emailed (the next 06:00 send): 2', $s['will_be_emailed']['total'] );
	}

	/**
	 * Section two names the reason: a left enrollment, or no consent.
	 */
	public function test_opted_in_but_not_emailed_gives_the_reason() {
		$s      = $this->sections();
		$reason = array();
		foreach ( $s['opted_in_not_emailed']['rows'] as $row ) {
			$reason[ $row['user_id'] ] = $row['reason'];
		}
		$this->assertSame(
			array(
				$this->u['b'] => 'enrollment not active',
				$this->u['c'] => 'consent not accepted',
			),
			$reason
		);
		$this->assertSame( 'vi', $s['opted_in_not_emailed']['rows'][1]['language'] );
		$this->assertStringEndsWith( ': 2', $s['opted_in_not_emailed']['total'] );
	}

	/**
	 * Section three lists the unsubscribed with the opt-out date, and only them.
	 */
	public function test_unsubscribed_shows_the_opt_out_date() {
		$s = $this->sections();
		$this->assertSame( array( $this->extra['d'] ), $this->ids( $s['unsubscribed'] ) );
		$this->assertNotSame( '', $s['unsubscribed']['rows'][0]['opted_out_utc'] );
		$this->assertStringEndsWith( ': 1', $s['unsubscribed']['total'] );
	}

	/**
	 * Nobody who never chose reminders appears in any section.
	 */
	public function test_people_who_never_chose_are_not_listed() {
		$all = array();
		foreach ( $this->sections() as $section ) {
			$all = array_merge( $all, $this->ids( $section ) );
		}
		$this->assertNotContains( $this->u['outsider'], $all );
		$this->assertNotContains( $this->u['organizer'], $all );
	}

	/**
	 * The --email lookup finds the person by address; an unknown address gives 0.
	 */
	public function test_email_lookup() {
		$this->assertSame( $this->u['a'], jlife_challenges_reminder_recipients_user_id( 'a-private@example.test' ) );
		$this->assertSame( $this->u['a'], jlife_challenges_reminder_recipients_user_id( '  a-private@example.test ' ) );
		$this->assertSame( 0, jlife_challenges_reminder_recipients_user_id( 'nobody@example.test' ) );
		$this->assertSame( 0, jlife_challenges_reminder_recipients_user_id( '' ) );
	}

	/**
	 * With a user filter only that person's rows appear, in whichever section they belong.
	 */
	public function test_user_filter_shows_only_that_person() {
		$s = $this->sections( $this->u['c'] );
		$this->assertSame( array(), $this->ids( $s['will_be_emailed'] ) );
		$this->assertSame( array( $this->u['c'] ), $this->ids( $s['opted_in_not_emailed'] ) );
		$this->assertSame( array(), $this->ids( $s['unsubscribed'] ) );
		$this->assertStringEndsWith( ': 0', $s['will_be_emailed']['total'] );
		$s = $this->sections( $this->u['outsider'] );
		$this->assertSame( array(), $this->ids( $s['opted_in_not_emailed'] ), 'a person with no choice has no rows' );
	}

	/**
	 * No email address, group name or reflection text anywhere in the output; a
	 * display name that is an address is hidden.
	 */
	public function test_no_email_address_or_group_name_in_any_output() {
		wp_update_user(
			array(
				'ID'           => $this->extra['e'],
				'display_name' => 'e-private@example.test',
			)
		);
		$report = jlife_challenges_reminder_recipients_report( $this->campaign_row(), 0 );
		$text   = wp_json_encode( $report ) . wp_json_encode( jlife_challenges_reminder_recipients_items( $report ) );
		$filter = wp_json_encode( jlife_challenges_reminder_recipients_report( $this->campaign_row(), $this->u['a'] ) );
		foreach ( array( $text, $filter ) as $out ) {
			$this->assertStringNotContainsString( '@', $out );
			$this->assertStringNotContainsString( 'example.test', $out );
			$this->assertStringNotContainsString( 'private@', $out );
			$this->assertStringNotContainsString( 'Group One', $out );
			$this->assertStringNotContainsString( 'Group Two', $out );
		}
		$this->assertStringContainsString( 'name hidden', $text );
	}

	/**
	 * The flat list for csv and json: one row per person with a section column,
	 * no extra keys, and it encodes as valid JSON.
	 */
	public function test_flat_items_for_csv_and_json() {
		$report = jlife_challenges_reminder_recipients_report( $this->campaign_row(), 0 );
		$items  = jlife_challenges_reminder_recipients_items( $report );
		$this->assertCount( 5, $items );
		$expected = array( 'campaign_id', 'section', 'user_id', 'display_name', 'language', 'opted_in_utc', 'opted_out_utc', 'today', 'reason' );
		foreach ( $items as $item ) {
			$this->assertSame( $expected, array_keys( $item ) );
		}
		$by_section = array_count_values( wp_list_pluck( $items, 'section' ) );
		$this->assertSame(
			array(
				'will_be_emailed'      => 2,
				'opted_in_not_emailed' => 2,
				'unsubscribed'         => 1,
			),
			$by_section
		);
		$decoded = json_decode( (string) wp_json_encode( $items ), true );
		$this->assertSame( $items[0]['display_name'], $decoded[0]['display_name'] );
		$this->assertSame( $this->campaign, $decoded[0]['campaign_id'] );
	}

	/**
	 * Outside the campaign dates there is no day, so today's status reads "none yet".
	 */
	public function test_outside_the_campaign_today_is_none_yet() {
		$this->set_now( '2026-12-01 18:00:00' );
		$report = jlife_challenges_reminder_recipients_report( $this->campaign_row(), 0 );
		$this->assertSame( '', $report['day_key'] );
		$this->assertSame( 'none yet', $report['sections'][0]['rows'][0]['today'] );
	}

	/**
	 * Read-only: no row is written in the choices, the send log or the audit log, and nothing is sent.
	 */
	public function test_it_writes_and_sends_nothing() {
		global $wpdb;
		$count  = static function () use ( $wpdb ) {
			$n = array();
			foreach ( array( 'reminder_prefs', 'reminder_sends', 'audit_log', 'enrollments' ) as $t ) {
				$n[ $t ] = (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . jlife_challenges_table( $t ) );
			}
			return $n;
		};
		$before = $count();
		$hash   = md5( (string) wp_json_encode( $wpdb->get_results( 'SELECT * FROM ' . jlife_challenges_table( 'reminder_prefs' ) . ' ORDER BY id', ARRAY_A ) ) );
		jlife_challenges_reminder_recipients_report( $this->campaign_row(), 0 );
		jlife_challenges_reminder_recipients_report( $this->campaign_row(), $this->u['a'] );
		jlife_challenges_reminder_recipients_items( jlife_challenges_reminder_recipients_report( $this->campaign_row(), 0 ) );
		jlife_challenges_reminder_recipients_user_id( 'a-private@example.test' );
		$this->assertSame( $before, $count() );
		$this->assertSame( $hash, md5( (string) wp_json_encode( $wpdb->get_results( 'SELECT * FROM ' . jlife_challenges_table( 'reminder_prefs' ) . ' ORDER BY id', ARRAY_A ) ) ), 'prefs rows unchanged' );
		$this->assertSame( array(), $this->sent );
	}
}
