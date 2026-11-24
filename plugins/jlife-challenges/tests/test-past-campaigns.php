<?php
/**
 * Earlier reading periods (#174, D4-A): a person's own read-only history of an
 * ended or closed campaign that is no longer their current one, with "Remove
 * my post" for their shares.
 *
 * @package jlife-challenges
 */

/**
 * Past-campaign history tests.
 */
class Test_Past_Campaigns extends JLife_Challenges_TestCase {

	/**
	 * The practice-week campaign.
	 *
	 * @var int
	 */
	private $pilot = 0;

	/**
	 * The practice-week group.
	 *
	 * @var int
	 */
	private $pg = 0;

	/**
	 * Share written by a in the practice week.
	 *
	 * @var int
	 */
	private $share_a = 0;

	/**
	 * A practice week (Nov 29–Dec 13) before the base campaign (Dec 20 on), with a and b
	 * in one practice group; a writes and shares one SOAP, b writes one.
	 */
	public function set_up() {
		parent::set_up();
		$this->pilot = jlife_challenges_create_campaign(
			$this->u['organizer'],
			array(
				'campaign_key' => 'pilot-' . wp_generate_password( 6, false ),
				'plan_id'      => 'rpl-life-and-teachings-of-jesus-2026',
				'title'        => 'Practice week (test)',
				'start_date'   => '2026-11-29',
				'end_date'     => '2026-12-13',
				'status'       => 'active',
			)
		);
		$this->assertIsInt( $this->pilot );
		$this->set_now( '2026-11-30 18:00:00' ); // Practice Day 2, 10:00 AM Pacific.
		$this->pg = jlife_challenges_create_group( $this->u['organizer'], $this->pilot, 'Practice Group' )['id'];
		$this->assertIsInt( jlife_challenges_enroll( $this->u['organizer'], $this->u['a'], $this->pg ) );
		$this->assertIsInt( jlife_challenges_enroll( $this->u['organizer'], $this->u['b'], $this->pg ) );
		$r = jlife_challenges_save_and_share( $this->u['a'], $this->pilot, null, $this->soap( 'pa' ), array( $this->pg ) );
		$this->assertIsArray( $r );
		$this->share_a = (int) $r['share']['results'][ $this->pg ]['share_id'];
		$this->assertGreaterThan( 0, $this->share_a );
		$this->assertIsArray( jlife_challenges_submit( $this->u['b'], $this->pilot, null, $this->soap( 'pb' ) ) );
	}

	/**
	 * Close the practice week and move the clock into the Dec 14-19 gap.
	 */
	private function close_pilot() {
		$this->assertIsArray( jlife_challenges_close_campaign( $this->u['organizer'], $this->pilot ) );
		$this->set_now( '2026-12-15 18:00:00' );
	}

	/**
	 * While the practice week runs it is current, not an earlier period.
	 */
	public function test_a_running_campaign_is_not_past() {
		$this->assertSame( array(), jlife_challenges_past_campaigns( $this->u['a'] ) );
		$this->assertSame( '', jlife_challenges_render_past_section( $this->u['a'] ) );
	}

	/**
	 * After closing, someone also enrolled in the Marathon sees the practice week as
	 * an earlier period; people never in it, or only in it (still current), do not.
	 */
	public function test_who_sees_the_earlier_period() {
		$only_pilot = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		$this->assertIsInt( jlife_challenges_enroll( $this->u['organizer'], $only_pilot, $this->pg ) );
		$this->close_pilot();
		$this->assertSame( array( $this->pilot ), array_map( 'intval', array_column( jlife_challenges_past_campaigns( $this->u['a'] ), 'id' ) ) );
		$this->assertSame( array(), jlife_challenges_past_campaigns( $this->u['c'] ), 'never in the practice week' );
		$this->assertSame( array(), jlife_challenges_past_campaigns( $this->u['outsider'] ), 'not enrolled anywhere' );
		$this->assertSame( array(), jlife_challenges_past_campaigns( $only_pilot ), 'the closed practice week is still their current campaign' );
		$this->assertNull( jlife_challenges_past_campaign_for( $this->u['a'], $this->campaign ), 'the current campaign is not past' );
	}

	/**
	 * Each person reads only their own entries, with their own shares.
	 */
	public function test_only_own_entries_and_shares() {
		$this->close_pilot();
		$mine = jlife_challenges_past_entries( $this->u['a'], $this->pilot );
		$this->assertIsArray( $mine );
		$this->assertCount( 1, $mine );
		$this->assertSame( 2, $mine[0]['index'] );
		$this->assertStringContainsString( '(pa)', $mine[0]['body'] );
		$this->assertStringNotContainsString( '(pb)', $mine[0]['body'] );
		$this->assertSame( array( $this->share_a ), array_column( $mine[0]['shares'], 'id' ) );
		$this->assertSame( 'Practice Group', $mine[0]['shares'][0]['group_name'] );

		$b = jlife_challenges_past_entries( $this->u['b'], $this->pilot );
		$this->assertCount( 1, $b );
		$this->assertStringContainsString( '(pb)', $b[0]['body'] );
		$this->assertSame( array(), $b[0]['shares'] );

		$this->assertSame( 'jlife_denied', jlife_challenges_past_entries( $this->u['c'], $this->pilot )->get_error_code() );
		$this->assertSame( 'jlife_denied', jlife_challenges_past_entries( $this->u['outsider'], $this->pilot )->get_error_code() );
		$this->assertSame( array(), jlife_challenges_past_share_ids( $this->u['b'], $this->pilot ), 'b cannot reach a\'s share' );
	}

	/**
	 * After closing, the author can remove their own share; nobody else can, a new
	 * share is still refused, and points are untouched.
	 */
	public function test_remove_own_share_after_closing() {
		$this->close_pilot();
		$this->assertSame( array( $this->share_a ), jlife_challenges_past_share_ids( $this->u['a'], $this->pilot ) );
		$this->assertSame( 'jlife_denied', jlife_challenges_unshare( $this->u['b'], $this->share_a )->get_error_code() );
		$points = jlife_challenges_progress( $this->u['a'], $this->u['a'], $this->pilot )['points'];
		$this->assertTrue( jlife_challenges_unshare( $this->u['a'], $this->share_a ) );
		$this->assertSame( 'removed', jlife_challenges_find_share( $this->share_a )['status'] );
		$this->assertSame( $points, jlife_challenges_progress( $this->u['a'], $this->u['a'], $this->pilot )['points'] );
		$this->assertSame( array(), jlife_challenges_past_share_ids( $this->u['a'], $this->pilot ), 'a removed share is not offered again' );
		$entry = jlife_challenges_past_entries( $this->u['a'], $this->pilot )[0];
		$this->assertStringContainsString( '(pa)', $entry['body'], 'the SOAP itself stays' );
		$entry_b = jlife_challenges_past_entries( $this->u['b'], $this->pilot );
		$this->assertCount( 1, $entry_b );
	}

	/**
	 * How many share_removed audit rows a share has.
	 *
	 * @param int $share_id Share.
	 * @return int
	 */
	private function removals( $share_id ) {
		global $wpdb;
		return (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . jlife_challenges_table( 'audit_log' ) . " WHERE action = 'share_removed' AND object_id = %d", (int) $share_id ) ); // phpcs:ignore WordPress.DB
	}

	/**
	 * A shared draft whose SOAP was cleared later is still listed and removable
	 * (review finding 1): the share is a snapshot, independent of the entry.
	 */
	public function test_share_of_a_cleared_draft_stays_removable() {
		$this->set_now( '2026-12-01 18:00:00' ); // Practice Day 3.
		$entry = jlife_challenges_save_draft( $this->u['b'], $this->pilot, null, $this->soap( 'b-draft' ) );
		$this->assertIsInt( $entry );
		$share = jlife_challenges_share( $this->u['b'], $entry, $this->pg );
		$this->assertIsInt( $share );
		$this->assertIsInt( jlife_challenges_save_draft( $this->u['b'], $this->pilot, null, '' ), 'the private draft is cleared' );
		$this->close_pilot();

		$this->assertContains( $share, jlife_challenges_past_share_ids( $this->u['b'], $this->pilot ) );
		$days = jlife_challenges_past_entries( $this->u['b'], $this->pilot );
		$day3 = array_values( array_filter( $days, static fn( $d ) => 3 === $d['index'] ) );
		$this->assertCount( 1, $day3, 'the day is listed for its share' );
		$this->assertSame( '', $day3[0]['body'] );
		$this->assertSame( array( $share ), array_column( $day3[0]['shares'], 'id' ) );
		$this->assertTrue( jlife_challenges_unshare( $this->u['b'], $share ) );
		$this->assertNotContains( $share, jlife_challenges_past_share_ids( $this->u['b'], $this->pilot ) );
	}

	/**
	 * An organizer-hidden share is offered once; after the author removes it, its
	 * text is cleared, the button is gone, and a repeat writes no second audit row
	 * (review finding 2).
	 */
	public function test_hidden_share_is_offered_until_removed() {
		$this->assertTrue( jlife_challenges_hide_share( $this->u['organizer'], $this->share_a, 'test' ) );
		$this->close_pilot();
		$this->assertSame( array( $this->share_a ), jlife_challenges_past_share_ids( $this->u['a'], $this->pilot ) );

		$this->assertTrue( jlife_challenges_unshare( $this->u['a'], $this->share_a ) );
		$row = jlife_challenges_find_share( $this->share_a );
		$this->assertSame( 'hidden', $row['status'], 'a hidden post stays hidden' );
		$this->assertNull( $row['body'] );
		$this->assertSame( array(), jlife_challenges_past_share_ids( $this->u['a'], $this->pilot ), 'no Remove button any more' );
		$this->assertSame( 1, $this->removals( $this->share_a ) );

		$this->assertTrue( jlife_challenges_unshare( $this->u['a'], $this->share_a ), 'a repeat is a harmless no-op' );
		$this->assertSame( 1, $this->removals( $this->share_a ), 'no second audit row' );
	}

	/**
	 * A failed database write is reported as an error, is not audited, and leaves
	 * the share offered for removal (review finding 3).
	 */
	public function test_failed_write_is_not_reported_as_removed() {
		global $wpdb;
		$this->close_pilot();
		$break = static function ( $query ) {
			return ( 0 === stripos( ltrim( $query ), 'UPDATE' ) && false !== strpos( $query, 'group_shares' ) ) ? 'UPDATE jlife_no_such_table SET x = 1' : $query;
		};
		add_filter( 'query', $break );
		$suppress = $wpdb->suppress_errors( true );
		$r        = jlife_challenges_unshare( $this->u['a'], $this->share_a );
		$wpdb->suppress_errors( $suppress );
		remove_filter( 'query', $break );

		$this->assertWPError( $r );
		$this->assertSame( 'jlife_unshare_failed', $r->get_error_code() );
		$this->assertSame( 'shared', jlife_challenges_find_share( $this->share_a )['status'] );
		$this->assertSame( 0, $this->removals( $this->share_a ) );
		$this->assertSame( array( $this->share_a ), jlife_challenges_past_share_ids( $this->u['a'], $this->pilot ) );
	}

	/**
	 * My Marathon's section links each earlier period, and is empty for others.
	 */
	public function test_my_marathon_section() {
		$this->close_pilot();
		$html = jlife_challenges_render_past_section( $this->u['a'] );
		$this->assertStringContainsString( 'past=' . $this->pilot, $html );
		$this->assertStringContainsString( 'Practice week (test)', $html );
		$this->assertStringNotContainsString( '(pa)', $html, 'no entry text on My Marathon' );
		$this->assertSame( '', jlife_challenges_render_past_section( $this->u['c'] ) );
	}
}
