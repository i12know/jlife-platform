<?php
/**
 * Closing a campaign (#179, docs/challenges-engine-design.md §3c): once closed,
 * every write is refused, no reminder is sent, and what each person could read
 * stays exactly as private as before. Written before the feature.
 *
 * @package jlife-challenges
 */

/**
 * Campaign-close tests.
 */
class Test_Campaign_Close extends JLife_Challenges_TestCase {

	/**
	 * The entry a wrote on Day 1, shared with Group One.
	 *
	 * @var array { entry_id, share_id }
	 */
	private $written = array();

	/**
	 * Day 1: a submits and shares with Group One; b keeps a draft.
	 */
	public function set_up() {
		parent::set_up();
		$r = jlife_challenges_save_and_share( $this->u['a'], $this->campaign, null, $this->soap( 'a1' ), array( $this->g['g1'] ) );
		$this->assertIsArray( $r );
		$this->assertSame( 'shared', $r['share']['status'] );
		$this->written = array(
			'entry_id' => (int) $r['entry_id'],
			'share_id' => (int) $r['share']['results'][ $this->g['g1'] ]['share_id'],
		);
		$this->assertIsInt( jlife_challenges_save_draft( $this->u['b'], $this->campaign, null, 'b draft' ) );
	}

	/**
	 * Close the base campaign as the organizer.
	 *
	 * @return array
	 */
	private function close() {
		$r = jlife_challenges_close_campaign( $this->u['organizer'], $this->campaign );
		$this->assertIsArray( $r );
		return $r;
	}

	/**
	 * Count rows in an engine table for the base campaign.
	 *
	 * @param string $table Table suffix.
	 * @param string $where Extra SQL condition (constant, test-only).
	 * @return int
	 */
	private function rows( $table, $where = '1=1' ) {
		global $wpdb;
		return (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . jlife_challenges_table( $table ) . " WHERE campaign_id = %d AND {$where}", $this->campaign ) ); // phpcs:ignore WordPress.DB
	}

	/**
	 * Queue a reminder row for a user (as the scheduler would).
	 *
	 * @param string $who     User key.
	 * @param string $day_key Day.
	 */
	private function queue_reminder( $who, $day_key = 'd001' ) {
		global $wpdb;
		$now = current_time( 'mysql', true );
		$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			jlife_challenges_table( 'reminder_sends' ),
			array(
				'campaign_id'  => $this->campaign,
				'day_key'      => $day_key,
				'user_id'      => $this->u[ $who ],
				'status'       => 'queued',
				'attempts'     => 0,
				'next_attempt' => $now,
				'created'      => $now,
				'updated'      => $now,
			)
		);
	}

	/**
	 * Assert a result is the closed-campaign refusal.
	 *
	 * @param mixed  $r   Result.
	 * @param string $msg Message.
	 */
	private function assert_closed( $r, $msg = '' ) {
		$this->assertWPError( $r, $msg );
		$this->assertSame( 'jlife_campaign_closed', $r->get_error_code(), $msg );
	}

	/**
	 * Only an organizer can preview or close; unknown and draft campaigns are refused.
	 */
	public function test_who_can_close() {
		foreach ( array( 'a', 'outsider' ) as $who ) {
			$this->assertWPError( jlife_challenges_close_campaign( $this->u[ $who ], $this->campaign ), $who );
			$this->assertWPError( jlife_challenges_close_preview( $this->u[ $who ], $this->campaign ), $who );
		}
		$this->assertWPError( jlife_challenges_close_campaign( $this->u['organizer'], 987654 ) );
		$draft = jlife_challenges_create_campaign(
			$this->u['organizer'],
			array(
				'campaign_key' => 'draft-' . strtolower( wp_generate_password( 6, false ) ),
				'plan_id'      => 'rpl-life-and-teachings-of-jesus-2026',
				'title'        => 'Draft',
				'start_date'   => '2027-06-01',
				'end_date'     => '2027-09-01',
			)
		);
		$this->assertSame( 'jlife_campaign_not_active', jlife_challenges_close_campaign( $this->u['organizer'], $draft )->get_error_code() );
		$this->assertSame( 'active', jlife_challenges_get_campaign( $this->campaign )['status'], 'nothing changed' );
	}

	/**
	 * The preview is counts only and changes nothing; closing is audited with counts only and is safe to repeat.
	 */
	public function test_preview_close_and_rerun() {
		$this->queue_reminder( 'a' );
		$plan = jlife_challenges_close_preview( $this->u['organizer'], $this->campaign );
		$this->assertIsArray( $plan );
		foreach ( $plan as $what => $n ) {
			$this->assertIsString( $what );
			$this->assertIsInt( $n, "{$what} is a count" );
		}
		$this->assertSame( 1, $plan['reminders_to_cancel'] );
		$this->assertSame( 3, $plan['enrollments'] );
		$this->assertSame( 'active', jlife_challenges_get_campaign( $this->campaign )['status'], 'preview changes nothing' );
		$this->assertSame( 1, $this->rows( 'reminder_sends', "status = 'queued'" ) );

		$r = $this->close();
		$this->assertTrue( $r['changed'] );
		$this->assertSame( 1, $r['reminders_cancelled'] );
		$this->assertSame( 'closed', jlife_challenges_get_campaign( $this->campaign )['status'] );
		$this->assertSame( 'closed', jlife_challenges_campaign_phase( jlife_challenges_get_campaign( $this->campaign ) ) );

		global $wpdb;
		$audit = (array) $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . jlife_challenges_table( 'audit_log' ) . " WHERE action = 'campaign_closed' AND campaign_id = %d", $this->campaign ), ARRAY_A ); // phpcs:ignore WordPress.DB
		$this->assertCount( 1, $audit );
		$detail = json_decode( (string) $audit[0]['detail'], true );
		foreach ( $detail as $what => $n ) {
			$this->assertIsInt( $n, "audit {$what} is a count" );
		}

		$again = $this->close();
		$this->assertFalse( $again['changed'], 'second run changes nothing' );
		$this->assertSame( 0, $again['reminders_cancelled'] );
		$this->assertCount( 1, (array) $wpdb->get_results( $wpdb->prepare( 'SELECT id FROM ' . jlife_challenges_table( 'audit_log' ) . " WHERE action = 'campaign_closed' AND campaign_id = %d", $this->campaign ) ), 'audited once' ); // phpcs:ignore WordPress.DB
	}

	/**
	 * SOAP save, submit and save-and-share are refused; nothing in the entry or the ledger changes.
	 */
	public function test_soap_writes_are_refused() {
		$ledger = $this->rows( 'score_ledger' );
		$before = jlife_challenges_get_entry( $this->u['a'], $this->written['entry_id'] );
		$this->close();
		$this->set_now( '2026-12-21 18:00:00' ); // Day 2.

		$this->assert_closed( jlife_challenges_save_draft( $this->u['b'], $this->campaign, 'd001', 'changed' ), 'draft' );
		$this->assert_closed( jlife_challenges_submit( $this->u['a'], $this->campaign, 'd001', $this->soap( 'edit' ) ), 'edit a submitted entry' );
		$this->assert_closed( jlife_challenges_submit( $this->u['b'], $this->campaign, null, $this->soap( 'b2' ) ), 'new day' );
		$this->assert_closed( jlife_challenges_save_and_share( $this->u['c'], $this->campaign, null, $this->soap( 'c2' ), array( $this->g['g2'] ) ), 'save and share' );

		$this->assertSame( $before['body'], jlife_challenges_get_entry( $this->u['a'], $this->written['entry_id'] )['body'] );
		$this->assertSame( 'b draft', jlife_challenges_get_my_entry( $this->u['b'], $this->campaign, 'd001' )['body'] );
		$this->assertNull( jlife_challenges_find_entry( $this->u['b'], $this->campaign, 'd002' ), 'no new entry row' );
		$this->assertSame( $ledger, $this->rows( 'score_ledger' ), 'no new ledger rows' );

		$this->assertIsInt( jlife_challenges_save_draft( $this->u['a'], 0, null, 'personal' ), 'personal entries are unaffected' );
	}

	/**
	 * Non-members and outsiders still get the plain denial, not a hint that the campaign exists.
	 */
	public function test_outsiders_still_get_the_plain_denial() {
		$this->close();
		$this->assertSame( 'jlife_denied', jlife_challenges_submit( $this->u['outsider'], $this->campaign, null, $this->soap() )->get_error_code() );
		$this->assertSame( 'jlife_denied', jlife_challenges_save_draft( 0, $this->campaign, null, 'x' )->get_error_code() );
	}

	/**
	 * No new score_ledger rows, even through the internal writer.
	 */
	public function test_no_new_ledger_rows() {
		$this->close();
		$before = $this->rows( 'score_ledger' );
		$score  = jlife_challenges_score_entry( $this->soap(), array( 'campaign' => $this->campaign_row() ) );
		$this->assertNull( jlife_challenges_record_score( jlife_challenges_get_campaign( $this->campaign ), $this->u['b'], 1, 1, $score ) );
		$this->assertSame( $before, $this->rows( 'score_ledger' ) );
	}

	/**
	 * Creating, editing and removing a share are refused; the existing share stays exactly as it was.
	 */
	public function test_share_writes_are_refused() {
		$entry_b = jlife_challenges_submit( $this->u['b'], $this->campaign, null, $this->soap( 'b1' ) )['entry_id'];
		$this->close();
		$this->assert_closed( jlife_challenges_share( $this->u['b'], $entry_b, $this->g['g1'] ), 'create' );
		$this->assert_closed( jlife_challenges_share( $this->u['a'], $this->written['entry_id'], $this->g['g1'] ), 'edit (share again)' );
		$this->assert_closed( jlife_challenges_unshare( $this->u['a'], $this->written['share_id'] ), 'remove' );
		$this->assertSame( 'shared', jlife_challenges_find_share( $this->written['share_id'] )['status'] );
		$this->assertSame( 1, $this->rows( 'group_shares' ) );
		// Someone else's share is still the plain denial.
		$this->assertSame( 'jlife_denied', jlife_challenges_unshare( $this->u['b'], $this->written['share_id'] )->get_error_code() );
	}

	/**
	 * Group joins (code, organizer add, move), starts and invite resets are refused.
	 */
	public function test_group_writes_are_refused() {
		$code = jlife_challenges_reset_invite( $this->u['organizer'], $this->g['g1'] );
		$this->assertIsString( $code );
		$this->close();
		$members = $this->rows( 'group_members' );

		$this->assertWPError( jlife_challenges_join_with_code( $this->u['outsider'], $code, 'adult' ), 'code join' );
		$this->assert_closed( jlife_challenges_enroll( $this->u['organizer'], $this->u['outsider'], $this->g['g1'] ), 'organizer add' );
		$this->assert_closed( jlife_challenges_enroll( $this->u['organizer'], $this->u['c'], $this->g['g1'] ), 'organizer add of an enrolled person' );
		$this->assert_closed( jlife_challenges_move_member( $this->u['organizer'], $this->u['c'], $this->g['g1'], $this->g['g2'] ), 'move' );
		$this->assert_closed( jlife_challenges_reset_invite( $this->u['organizer'], $this->g['g1'] ), 'invite reset' );
		$this->assert_closed( jlife_challenges_create_group( $this->u['organizer'], $this->campaign, 'Late group' ), 'new group' );
		$this->assertWPError( jlife_challenges_start_group( $this->u['a'], 'Late start' ), 'start' );
		$this->assertWPError( jlife_challenges_enroll_solo( $this->u['outsider'], $this->campaign, 'adult' ), 'solo' );

		$this->assertSame( $members, $this->rows( 'group_members' ), 'no membership written' );
		$this->assertNull( jlife_challenges_get_enrollment( $this->campaign, $this->u['outsider'] ), 'no enrollment written' );
		$this->assertSame( (int) $this->g['g1'], (int) jlife_challenges_group_for_invite( $code )['id'], 'the code itself is untouched' );
	}

	/**
	 * A starter cannot reset their group's code once the campaign is closed.
	 */
	public function test_starter_invite_reset_is_refused() {
		$this->assertIsInt( jlife_challenges_enroll_solo( $this->u['outsider'], $this->campaign, 'adult' ) );
		$started = jlife_challenges_start_group( $this->u['outsider'], 'Starters' );
		$this->assertIsArray( $started );
		$this->close();
		$this->assert_closed( jlife_challenges_reset_invite( $this->u['outsider'], (int) $started['id'] ) );
	}

	/**
	 * Reminders: opting in is refused, opting out still works, queued rows are cancelled, and the sender never mails a closed campaign.
	 */
	public function test_reminders_stop() {
		$this->assertTrue( jlife_challenges_set_reminder( $this->u['a'], $this->u['a'], $this->campaign, true ) );
		$this->queue_reminder( 'a' );
		$this->queue_reminder( 'b' );
		$this->close();

		$this->assertSame( 2, $this->rows( 'reminder_sends', "status = 'cancelled'" ) );
		$this->assertSame( 0, $this->rows( 'reminder_sends', "status = 'queued'" ) );
		$this->assert_closed( jlife_challenges_set_reminder( $this->u['b'], $this->u['b'], $this->campaign, true ), 'opt in' );
		$this->assertTrue( jlife_challenges_set_reminder( $this->u['a'], $this->u['a'], $this->campaign, false ), 'opt out still works' );
		$this->assertNotContains( $this->campaign, array_map( 'intval', array_column( jlife_challenges_reminders_active_campaigns(), 'id' ) ), 'the cron skips it' );

		// A row queued by a tick that raced the close is cancelled when it is processed, never sent.
		$this->queue_reminder( 'c', 'd002' );
		global $wpdb;
		$row        = (array) $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . jlife_challenges_table( 'reminder_sends' ) . " WHERE campaign_id = %d AND status = 'queued'", $this->campaign ), ARRAY_A ); // phpcs:ignore WordPress.DB
		$recipients = array();
		reset_phpmailer_instance();
		jlife_challenges_reminders_process_row( $row, 'live', jlife_challenges_now(), $recipients );
		$this->assertSame( 'cancelled', $wpdb->get_var( $wpdb->prepare( 'SELECT status FROM ' . jlife_challenges_table( 'reminder_sends' ) . ' WHERE id = %d', (int) $row['id'] ) ) ); // phpcs:ignore WordPress.DB
		$this->assertCount( 0, tests_retrieve_phpmailer_instance()->mock_sent, 'no email' );
	}

	/**
	 * After closing, each person still reads their own entries, members still read their group's feed, and nobody reads more than before.
	 */
	public function test_reads_stay_as_private_as_before() {
		$this->close();
		$this->assertIsArray( jlife_challenges_get_entry( $this->u['a'], $this->written['entry_id'] ), 'author reads own entry' );
		$this->assertIsArray( jlife_challenges_get_my_entry( $this->u['b'], $this->campaign, 'd001' ), 'own draft too' );
		foreach ( array( 'b', 'c', 'outsider', 'organizer' ) as $who ) {
			$this->assertWPError( jlife_challenges_get_entry( $this->u[ $who ], $this->written['entry_id'] ), "{$who} cannot read a's entry" );
		}
		$this->assertCount( 1, jlife_challenges_group_feed( $this->u['b'], $this->g['g1'] ), 'member of Group One still reads its feed' );
		foreach ( array( 'c', 'outsider', 'organizer' ) as $who ) {
			$this->assertWPError( jlife_challenges_group_feed( $this->u[ $who ], $this->g['g1'] ), "{$who} still cannot read Group One" );
		}
		$this->assertSame( 1, jlife_challenges_progress( $this->u['a'], $this->u['a'], $this->campaign )['points'], 'points kept' );
	}

	/**
	 * The day screen of a closed campaign shows the entry read-only: no form, no textarea, no share boxes.
	 */
	public function test_day_screen_is_read_only() {
		$this->close();
		$view = jlife_challenges_day_view( $this->u['a'] );
		$this->assertSame( 'active', $view['state'] );
		$this->assertTrue( $view['closed'] );
		$html = jlife_challenges_render_soap_panel( $view );
		$this->assertStringNotContainsString( '<textarea', $html );
		$this->assertStringNotContainsString( '<form', $html );
		$this->assertStringNotContainsString( 'jl_share_groups', $html );
		$this->assertStringContainsString( esc_html( 'Lord, help me (a1).' ), $html );
		$this->assertStringContainsString( esc_html__( 'This reading period has closed. You can still read what you wrote, but it can no longer be changed or shared.', 'jlife-challenges' ), $html );

		$this->assertFalse( jlife_challenges_day_view( $this->u['outsider'] )['closed'] ?? false, 'no campaign, no flag' );
	}
}
