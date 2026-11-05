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
}
