<?php
/**
 * The current campaign (#177, docs/challenges-engine-design.md §3b): one date-
 * and status-aware rule for joining and for what a participant sees. Written
 * before the rule. The Dec 5–13, 2026 pilot and the Dec 20, 2026 – Apr 2, 2027
 * Marathon are both active; America/Los_Angeles is UTC-8 in December.
 *
 * @package jlife-challenges
 */

/**
 * Current-campaign tests.
 */
class Test_Current_Campaign extends JLife_Challenges_TestCase {

	/**
	 * Pilot campaign ID (the base campaign is the Marathon).
	 *
	 * @var int
	 */
	private $pilot = 0;

	/**
	 * A pilot group: { id, invite_code }.
	 *
	 * @var array
	 */
	private $pilot_group = array();

	/**
	 * Add the pilot next to the base Marathon.
	 */
	public function set_up() {
		parent::set_up();
		$this->pilot = $this->make_campaign( 'pilot', '2026-12-05', '2026-12-13' );
		$group       = jlife_challenges_create_group( $this->u['organizer'], $this->pilot, 'Pilot Group' );
		$this->assertIsArray( $group );
		$this->pilot_group = $group;
	}

	/**
	 * Create an active campaign.
	 *
	 * @param string $prefix Key prefix ("test" makes a staging test campaign).
	 * @param string $start  Day 1 (Y-m-d).
	 * @param string $end    Last day (Y-m-d).
	 * @param string $status Campaign status.
	 * @return int
	 */
	private function make_campaign( $prefix, $start, $end, $status = 'active' ) {
		$id = jlife_challenges_create_campaign(
			$this->u['organizer'],
			array(
				'campaign_key' => $prefix . '-' . strtolower( wp_generate_password( 6, false ) ),
				'plan_id'      => 'rpl-life-and-teachings-of-jesus-2026',
				'title'        => ucfirst( $prefix ),
				'start_date'   => $start,
				'end_date'     => $end,
				'status'       => $status,
			)
		);
		$this->assertIsInt( $id );
		return $id;
	}

	/**
	 * Set a campaign's status directly (closed arrives with #179).
	 *
	 * @param int    $campaign_id Campaign.
	 * @param string $status      Status.
	 */
	private function set_status( $campaign_id, $status ) {
		global $wpdb;
		$wpdb->update( jlife_challenges_table( 'campaigns' ), array( 'status' => $status ), array( 'id' => $campaign_id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	}

	/**
	 * Enroll a user in the pilot with no group.
	 *
	 * @param string $who User key.
	 */
	private function enroll_pilot( $who ) {
		$this->assertIsInt( jlife_challenges_enroll_solo( $this->u[ $who ], $this->pilot, 'adult' ) );
	}

	/**
	 * The participant's current campaign ID, or 0.
	 *
	 * @param string $who User key.
	 * @return int
	 */
	private function current_for( $who ) {
		$pc = jlife_challenges_participant_campaign( $this->u[ $who ] );
		return $pc ? (int) $pc['campaign']['id'] : 0;
	}

	/**
	 * The join target's ID, or 0.
	 *
	 * @return int
	 */
	private function join_target() {
		$c = jlife_challenges_open_campaign();
		return $c ? (int) $c['id'] : 0;
	}

	/**
	 * Moments around every boundary (UTC) and which campaign is current.
	 *
	 * @return array[]
	 */
	public static function overlap_matrix() {
		return array(
			'Nov 29, recruiting opens'   => array( '2026-11-29 20:00:00', 'pilot', 'pilot' ),
			'Dec 4 23:59:59 PT'          => array( '2026-12-05 07:59:59', 'pilot', 'pilot' ),
			'Dec 5 00:00 PT, pilot Day1' => array( '2026-12-05 08:00:00', 'pilot', 'pilot' ),
			'Dec 9, mid-pilot'           => array( '2026-12-09 18:00:00', 'pilot', 'pilot' ),
			'Dec 13 23:59:59 PT'         => array( '2026-12-14 07:59:59', 'pilot', 'pilot' ),
			'Dec 14 00:00 PT, gap'       => array( '2026-12-14 08:00:00', 'marathon', 'marathon' ),
			'Dec 17, gap'                => array( '2026-12-17 18:00:00', 'marathon', 'marathon' ),
			'Dec 19 23:59:59 PT'         => array( '2026-12-20 07:59:59', 'marathon', 'marathon' ),
			'Dec 20 00:00 PT, Day 1'     => array( '2026-12-20 08:00:00', 'marathon', 'marathon' ),
			'Apr 2 23:59:59 PT, Day 104' => array( '2027-04-03 06:59:59', 'marathon', 'marathon' ),
		);
	}

	/**
	 * Someone in both campaigns sees the right one, and new joins go to the right one, at every boundary.
	 *
	 * @dataProvider overlap_matrix
	 * @param string $utc  Moment (UTC).
	 * @param string $view Expected current campaign for a person in both.
	 * @param string $join Expected join target.
	 */
	public function test_overlap_matrix( $utc, $view, $join ) {
		$this->set_now( '2026-11-29 20:00:00' );
		$this->enroll_pilot( 'a' ); // Already in the Marathon (base set-up).
		$ids = array(
			'pilot'    => $this->pilot,
			'marathon' => $this->campaign,
		);
		$this->set_now( $utc );
		$this->assertSame( $ids[ $view ], $this->current_for( 'a' ), 'view' );
		$this->assertSame( $ids[ $join ], $this->join_target(), 'join' );
		$this->assertSame( $ids[ $view ], jlife_challenges_reminder_campaign_id( $this->u['a'] ), 'reminder target' );
	}

	/**
	 * Phases flip at midnight Pacific, not UTC.
	 */
	public function test_phase_boundaries_are_midnight_pacific() {
		$pilot    = jlife_challenges_get_campaign( $this->pilot );
		$marathon = $this->campaign_row();
		$at       = static fn( $utc ) => new DateTimeImmutable( $utc, new DateTimeZone( 'UTC' ) );
		$this->assertSame( 'upcoming', jlife_challenges_campaign_phase( $pilot, $at( '2026-12-05 07:59:59' ) ) );
		$this->assertSame( 'running', jlife_challenges_campaign_phase( $pilot, $at( '2026-12-05 08:00:00' ) ) );
		$this->assertSame( 'running', jlife_challenges_campaign_phase( $pilot, $at( '2026-12-14 07:59:59' ) ) );
		$this->assertSame( 'ended', jlife_challenges_campaign_phase( $pilot, $at( '2026-12-14 08:00:00' ) ) );
		$this->assertSame( 'upcoming', jlife_challenges_campaign_phase( $marathon, $at( '2026-12-20 07:59:59' ) ) );
		$this->assertSame( 'running', jlife_challenges_campaign_phase( $marathon, $at( '2026-12-20 08:00:00' ) ) );
		$this->assertSame( 'running', jlife_challenges_campaign_phase( $marathon, $at( '2027-04-03 06:59:59' ) ), 'Apr 2 23:59:59 PDT' );
		$this->assertSame( 'ended', jlife_challenges_campaign_phase( $marathon, $at( '2027-04-03 07:00:00' ) ), 'Apr 3 00:00 PDT' );
	}

	/**
	 * During the pilot, Today, My Marathon and My Group show the pilot, not the future Marathon.
	 */
	public function test_screens_follow_the_rule_during_the_pilot() {
		$this->set_now( '2026-12-01 18:00:00' );
		$this->enroll_pilot( 'a' );
		$this->set_now( '2026-12-07 18:00:00' ); // Pilot Day 3.

		$today = jlife_challenges_day_view( $this->u['a'] );
		$this->assertSame( 'active', $today['state'] );
		$this->assertSame( $this->pilot, (int) $today['campaign']['id'] );
		$this->assertSame( 3, $today['index'] );

		$grid = jlife_challenges_marathon_view( $this->u['a'] );
		$this->assertSame( $this->pilot, (int) $grid['campaign']['id'] );
		$this->assertCount( 9, $grid['cells'] );

		$group = jlife_challenges_group_view( $this->u['a'] );
		$this->assertSame( 'solo', $group['state'], 'solo in the pilot; the Marathon group is not shown yet' );
		$this->assertSame( $this->pilot, (int) $group['campaign']['id'] );

		$this->set_now( '2026-12-20 18:00:00' );
		$this->assertSame( $this->campaign, (int) jlife_challenges_day_view( $this->u['a'] )['campaign']['id'] );
		$this->assertSame( 'ok', jlife_challenges_group_view( $this->u['a'] )['state'], 'the Marathon group from Dec 20' );
	}

	/**
	 * A future-only enrollment shows "Almost time" for that campaign.
	 */
	public function test_future_only_enrollment() {
		$this->set_now( '2026-12-07 18:00:00' );
		$this->assertSame( $this->campaign, $this->current_for( 'c' ) );
		$this->assertSame( 'before', jlife_challenges_day_view( $this->u['c'] )['state'] );
	}

	/**
	 * A finished-only enrollment is still shown (view-only), but new joins go to the next campaign.
	 */
	public function test_finished_only_enrollment() {
		$this->set_now( '2026-12-07 18:00:00' );
		$this->enroll_pilot( 'outsider' );
		$this->set_now( '2026-12-16 18:00:00' );
		$this->assertSame( $this->pilot, $this->current_for( 'outsider' ) );
		$this->assertSame( 'after', jlife_challenges_day_view( $this->u['outsider'] )['state'] );
		$this->assertSame( $this->campaign, $this->join_target() );
	}

	/**
	 * Solo and start-group joins during the pilot land in the pilot, even though the Marathon is active.
	 */
	public function test_solo_and_start_group_land_in_the_pilot() {
		$this->set_now( '2026-12-07 18:00:00' );
		$open = jlife_challenges_open_campaign();
		$this->assertSame( $this->pilot, (int) $open['id'] );
		$this->assertIsInt( jlife_challenges_enroll_solo( $this->u['outsider'], (int) $open['id'], 'adult' ) );
		$this->assertNull( jlife_challenges_get_enrollment( $this->campaign, $this->u['outsider'] ), 'not enrolled in the Marathon' );

		$started = jlife_challenges_start_group( $this->u['outsider'], 'Pilot Starters' );
		$this->assertIsArray( $started );
		$this->assertSame( $this->pilot, (int) $started['campaign_id'] );
	}

	/**
	 * A code joins its group's campaign while that campaign is joinable; a pilot code stops after the pilot ends.
	 */
	public function test_code_join_lands_in_the_groups_campaign_while_joinable() {
		$this->set_now( '2026-12-07 18:00:00' );
		$this->assertIsInt( jlife_challenges_join_with_code( $this->u['outsider'], $this->pilot_group['invite_code'], 'adult' ) );
		$this->assertNotNull( jlife_challenges_get_enrollment( $this->pilot, $this->u['outsider'] ) );
		$this->assertNull( jlife_challenges_get_enrollment( $this->campaign, $this->u['outsider'] ) );

		$this->set_now( '2026-12-14 08:00:00' ); // Dec 14 00:00 PT: the pilot has ended.
		$late = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		$r    = jlife_challenges_join_with_code( $late, $this->pilot_group['invite_code'], 'adult' );
		$this->assertWPError( $r );
		$this->assertSame( 'jlife_invite_invalid', $r->get_error_code() );
		$this->assertNull( jlife_challenges_get_enrollment( $this->pilot, $late ), 'no enrollment in an ended campaign' );
		$this->assertNull( jlife_challenges_joinable_group_for_invite( $this->pilot_group['invite_code'] ) );
		$this->assertNotNull( jlife_challenges_group_for_invite( $this->pilot_group['invite_code'] ), 'the code itself is unchanged' );
	}

	/**
	 * Staging test campaigns never outrank a real campaign that is running or upcoming.
	 */
	public function test_test_campaigns_rank_after_real_ones() {
		$this->set_now( '2026-11-25 18:00:00' );
		$test = $this->make_campaign( 'test', '2026-11-05', '2027-02-16' ); // Running.
		$this->assertSame( $this->pilot, $this->join_target(), 'a running test campaign does not capture sign-ups' );

		$this->assertIsInt( jlife_challenges_enroll_solo( $this->u['outsider'], $test, 'adult' ) );
		$this->assertSame( $test, $this->current_for( 'outsider' ), 'a tester only in the test campaign sees it' );
		$this->enroll_pilot( 'outsider' );
		$this->assertSame( $this->pilot, $this->current_for( 'outsider' ), 'the real campaign wins for a tester in both' );

		$this->set_status( $this->pilot, 'draft' );
		$this->set_status( $this->campaign, 'draft' );
		$this->assertSame( $test, $this->join_target(), 'with no real campaign open, the test campaign is used' );
	}

	/**
	 * A running test campaign is shown before an ended real one.
	 */
	public function test_running_test_campaign_beats_an_ended_real_one() {
		$this->set_now( '2026-12-07 18:00:00' );
		$this->enroll_pilot( 'outsider' );
		$test = $this->make_campaign( 'test', '2026-12-15', '2027-03-28' );
		$this->assertIsInt( jlife_challenges_enroll_solo( $this->u['outsider'], $test, 'adult' ) );
		$this->set_now( '2026-12-16 18:00:00' );
		$this->assertSame( $test, $this->current_for( 'outsider' ) );
	}

	/**
	 * Draft campaigns are never current; closed ones are view-only, never joinable.
	 */
	public function test_draft_and_closed() {
		$this->set_now( '2026-12-07 18:00:00' );
		$this->enroll_pilot( 'a' );
		$this->set_status( $this->pilot, 'draft' );
		$this->assertSame( $this->campaign, $this->current_for( 'a' ), 'draft pilot skipped' );
		$this->assertSame( $this->campaign, $this->join_target() );

		$this->set_status( $this->pilot, 'closed' );
		$this->set_status( $this->campaign, 'closed' );
		$this->assertSame( 'closed', jlife_challenges_campaign_phase( jlife_challenges_get_campaign( $this->pilot ) ) );
		$this->assertSame( $this->campaign, $this->current_for( 'a' ), 'still viewable: the latest end' );
		$this->assertSame( 0, $this->join_target(), 'nothing joinable' );
		$this->assertWPError( jlife_challenges_join_with_code( $this->u['outsider'], $this->pilot_group['invite_code'], 'adult' ) );
	}

	/**
	 * Ties: two running real campaigns → the later start; two upcoming → the nearest start; two ended → the latest end.
	 */
	public function test_tie_breaks() {
		$early = array(
			'id'           => 1,
			'campaign_key' => 'a',
			'status'       => 'active',
			'timezone'     => 'America/Los_Angeles',
			'start_date'   => '2027-01-01',
			'end_date'     => '2027-01-31',
		);
		$late  = array_merge(
			$early,
			array(
				'id'         => 2,
				'start_date' => '2027-01-10',
				'end_date'   => '2027-02-28',
			)
		);
		$at    = static fn( $utc ) => new DateTimeImmutable( $utc, new DateTimeZone( 'UTC' ) );
		$this->assertSame( 2, (int) jlife_challenges_pick_campaign( array( $early, $late ), 'view', $at( '2027-01-15 20:00:00' ) )['id'], 'both running: later start' );
		$this->assertSame( 1, (int) jlife_challenges_pick_campaign( array( $late, $early ), 'join', $at( '2026-12-01 20:00:00' ) )['id'], 'both upcoming: nearest start' );
		$this->assertSame( 2, (int) jlife_challenges_pick_campaign( array( $early, $late ), 'view', $at( '2027-03-15 20:00:00' ) )['id'], 'both ended: latest end' );
		$this->assertNull( jlife_challenges_pick_campaign( array( $early, $late ), 'join', $at( '2027-03-15 20:00:00' ) ), 'ended: not joinable' );
		$this->assertNull( jlife_challenges_pick_campaign( array(), 'view' ) );
	}

	/**
	 * The jlife_challenges_open_campaign filter still overrides the join target.
	 */
	public function test_open_campaign_filter_still_overrides() {
		$this->set_now( '2026-12-07 18:00:00' );
		$force = fn() => $this->campaign_row();
		add_filter( 'jlife_challenges_open_campaign', $force );
		$this->assertSame( $this->campaign, $this->join_target() );
		remove_filter( 'jlife_challenges_open_campaign', $force );
		$this->assertSame( $this->pilot, $this->join_target() );
	}
}
