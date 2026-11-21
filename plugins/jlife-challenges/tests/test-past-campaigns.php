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
	 * A practice week (Dec 5-13) before the base campaign (Dec 20 on), with a and b
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
				'start_date'   => '2026-12-05',
				'end_date'     => '2026-12-13',
				'status'       => 'active',
			)
		);
		$this->assertIsInt( $this->pilot );
		$this->set_now( '2026-12-06 18:00:00' ); // Practice Day 2, 10:00 AM Pacific.
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
