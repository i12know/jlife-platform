<?php
/**
 * Staging test campaign: create starting today, remove without touching
 * the real campaign.
 *
 * @package jlife-challenges
 */

/**
 * Test-campaign tests.
 */
class Test_Test_Campaign extends Jlife_Challenges_TestCase {

	/**
	 * Only organizers can create or remove.
	 */
	public function test_requires_organizer() {
		$this->assertSame( 'jlife_denied', jlife_challenges_create_test_campaign( $this->u['a'] )->get_error_code() );
		$this->assertSame( 'jlife_denied', jlife_challenges_remove_test_campaigns( $this->u['a'] )->get_error_code() );
		$this->assertSame( 'jlife_denied', jlife_challenges_test_cleanup_preview( $this->u['a'] )->get_error_code() );
	}

	/**
	 * Day 1 is today (Pacific); a second create reuses it with a new code.
	 */
	public function test_create_starts_today_and_is_reused() {
		$this->set_now( '2026-11-06 06:30:00' ); // Nov 5, 10:30 PM Pacific.
		$r = jlife_challenges_create_test_campaign( $this->u['organizer'] );
		$this->assertTrue( $r['created'] );
		$this->assertSame( '2026-11-05', $r['start_date'] );
		$c = jlife_challenges_get_campaign( $r['campaign_id'] );
		$this->assertSame( 104, jlife_challenges_day_count( $c ) );
		$this->assertSame( 1, jlife_challenges_current_day( $c ) );

		$r2 = jlife_challenges_create_test_campaign( $this->u['organizer'] );
		$this->assertFalse( $r2['created'] );
		$this->assertSame( $r['campaign_id'], $r2['campaign_id'] );
		$this->assertNotSame( $r['invite_code'], $r2['invite_code'] );
		$this->assertNull( jlife_challenges_group_for_invite( $r['invite_code'] ), 'old code stops working' );
		$this->assertSame( $r['group_id'], (int) jlife_challenges_group_for_invite( $r2['invite_code'] )['id'] );
		$this->assertCount( 1, jlife_challenges_test_campaign_ids() );
	}

	/**
	 * Remove deletes test data and test-only accounts, and nothing else.
	 */
	public function test_remove_only_touches_test_data() {
		$this->set_now( '2026-11-05 20:00:00' );
		$r      = jlife_challenges_create_test_campaign( $this->u['organizer'] );
		$tester = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		$this->assertIsInt( jlife_challenges_join_with_code( $tester, $r['invite_code'], 'adult' ) );
		$this->assertIsInt( jlife_challenges_join_with_code( $this->u['a'], $r['invite_code'], 'adult' ) ); // Also in the real campaign.
		$this->assertIsArray( jlife_challenges_submit( $tester, $r['campaign_id'], null, $this->soap( 'test' ) ) );
		$this->assertIsArray( jlife_challenges_submit( $this->u['a'], $r['campaign_id'], null, $this->soap( 'test' ) ) );

		// Real campaign data for a.
		$this->set_now( '2026-12-20 18:00:00' );
		$this->assertIsArray( jlife_challenges_submit( $this->u['a'], $this->campaign, null, $this->soap( 'real' ) ) );

		$out = jlife_challenges_remove_test_campaigns( $this->u['organizer'] );
		$this->assertSame(
			array(
				'campaigns' => 1,
				'entries'   => 2,
				'users'     => 1,
				'kept'      => 1,
			),
			$out
		);
		$this->assertSame( array(), jlife_challenges_test_campaign_ids() );
		$this->assertFalse( get_userdata( $tester ), 'test-only account deleted' );
		$this->assertInstanceOf( WP_User::class, get_userdata( $this->u['a'] ), 'real participant kept' );
		$this->assertInstanceOf( WP_User::class, get_userdata( $this->u['organizer'] ) );
		$this->assertIsArray( jlife_challenges_get_campaign( $this->campaign ) );
		$this->assertSame( 1, jlife_challenges_progress( $this->u['a'], $this->u['a'], $this->campaign )['points'] );
		$this->assertSame( 1, $this->ledger_rows( $this->u['a'] ), 'only the real point remains' );
		$this->assertNull( jlife_challenges_group_for_invite( $r['invite_code'] ) );

		$this->assertSame( 0, jlife_challenges_remove_test_campaigns( $this->u['organizer'] )['campaigns'], 'idempotent' );
	}

	/**
	 * A test campaign with a test-only tester and participant a (also in the
	 * real campaign), each with a submitted entry and a queued reminder email.
	 *
	 * @return array { campaign_id, invite_code, tester }
	 */
	private function seed_test_campaign() {
		$this->set_now( '2026-11-05 20:00:00' );
		$r      = jlife_challenges_create_test_campaign( $this->u['organizer'] );
		$tester = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		$this->assertIsInt( jlife_challenges_join_with_code( $tester, $r['invite_code'], 'adult' ) );
		$this->assertIsInt( jlife_challenges_join_with_code( $this->u['a'], $r['invite_code'], 'adult' ) );
		$this->assertIsArray( jlife_challenges_submit( $tester, $r['campaign_id'], null, $this->soap( 'test' ) ) );
		$this->assertIsArray( jlife_challenges_submit( $this->u['a'], $r['campaign_id'], null, $this->soap( 'test' ) ) );
		foreach ( array( $tester, $this->u['a'] ) as $user_id ) {
			$this->queue_reminder( $r['campaign_id'], $user_id );
		}
		$this->queue_reminder( $this->campaign, $this->u['a'] );
		return array(
			'campaign_id' => (int) $r['campaign_id'],
			'invite_code' => (string) $r['invite_code'],
			'tester'      => (int) $tester,
		);
	}

	/**
	 * Queue a reminder email row (as the scheduler would).
	 *
	 * @param int $campaign_id Campaign.
	 * @param int $user_id     Recipient.
	 */
	private function queue_reminder( $campaign_id, $user_id ) {
		global $wpdb;
		$now = current_time( 'mysql', true );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- test fixture on a custom table.
		$wpdb->insert(
			jlife_challenges_table( 'reminder_sends' ),
			array(
				'campaign_id'  => (int) $campaign_id,
				'day_key'      => 'day-001',
				'user_id'      => (int) $user_id,
				'status'       => 'queued',
				'next_attempt' => $now,
				'created'      => $now,
				'updated'      => $now,
			)
		);
	}

	/**
	 * Queued reminder rows for a campaign.
	 *
	 * @param int $campaign_id Campaign.
	 * @return int
	 */
	private function queued( $campaign_id ) {
		global $wpdb;
		return (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . jlife_challenges_table( 'reminder_sends' ) . ' WHERE campaign_id = %d', $campaign_id ) ); // phpcs:ignore WordPress.DB
	}

	/**
	 * Removal clears the test campaign's reminder queue, including rows of a
	 * kept account, and leaves the real campaign's queue alone (#174).
	 */
	public function test_remove_clears_the_reminder_queue() {
		$t = $this->seed_test_campaign();
		$this->assertSame( 2, $this->queued( $t['campaign_id'] ) );

		jlife_challenges_remove_test_campaigns( $this->u['organizer'] );
		$this->assertSame( 0, $this->queued( $t['campaign_id'] ), 'no orphan queue rows' );
		$this->assertSame( 1, $this->queued( $this->campaign ), 'real campaign queue untouched' );
	}

	/**
	 * With keep_accounts, the test data goes and every account stays (#174).
	 */
	public function test_remove_can_keep_every_account() {
		$t   = $this->seed_test_campaign();
		$out = jlife_challenges_remove_test_campaigns( $this->u['organizer'], true );
		$this->assertSame(
			array(
				'campaigns' => 1,
				'entries'   => 2,
				'users'     => 0,
				'kept'      => 2,
			),
			$out
		);
		$this->assertInstanceOf( WP_User::class, get_userdata( $t['tester'] ), 'test-only account kept' );
		$this->assertSame( array(), jlife_challenges_test_campaign_ids() );
		$this->assertSame( 0, $this->queued( $t['campaign_id'] ) );
		$this->assertSame( 0, $this->ledger_rows( $t['tester'] ), 'test points gone' );
		$this->assertNull( jlife_challenges_participant_campaign( $t['tester'] ), 'kept account is enrolled nowhere' );
	}

	/**
	 * The preview reports counts only and changes nothing.
	 */
	public function test_preview_counts_without_changing_anything() {
		$t    = $this->seed_test_campaign();
		$plan = jlife_challenges_test_cleanup_preview( $this->u['organizer'] );
		$this->assertSame( 1, $plan['campaigns'] );
		$this->assertSame( 2, $plan['soap_entries'] );
		$this->assertSame( 2, $plan['enrollments'] );
		$this->assertSame( 2, $plan['reminder_sends'] );
		$this->assertSame( 1, $plan['accounts_deleted'] );
		$this->assertSame( 1, $plan['accounts_kept'] );
		foreach ( $plan as $n ) {
			$this->assertIsInt( $n, 'counts only, no people' );
		}

		$keep = jlife_challenges_test_cleanup_preview( $this->u['organizer'], true );
		$this->assertSame( 0, $keep['accounts_deleted'] );
		$this->assertSame( 2, $keep['accounts_kept'] );

		$this->assertSame( array( $t['campaign_id'] ), jlife_challenges_test_campaign_ids(), 'nothing removed' );
		$this->assertInstanceOf( WP_User::class, get_userdata( $t['tester'] ) );
		$this->assertSame( 2, $this->queued( $t['campaign_id'] ) );
	}
}
