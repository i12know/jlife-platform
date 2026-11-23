<?php
/**
 * Group starters read ahead and schedule a group share (#209). The access-control
 * and release-boundary tests for docs/challenges-engine-design.md §4i; they were
 * written before the feature.
 *
 * @package jlife-challenges
 */

// phpcs:disable WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- tests read the plugin's own tables directly.

/**
 * Advance reading and scheduled share tests.
 */
class Test_Advance_Reading extends Jlife_Challenges_TestCase {

	/**
	 * The starter, their group and a plain member of it.
	 *
	 * @var array
	 */
	private $s = array();

	/**
	 * Day 2 opens Dec 21 00:00 Pacific = 08:00 UTC.
	 */
	const DAY2_OPENS = '2026-12-21 08:00:00';

	/**
	 * A starter (own group "Alpha") and a member of it, enrolled in the running campaign.
	 */
	public function set_up() {
		parent::set_up();
		$starter = self::factory()->user->create(
			array(
				'role'       => 'subscriber',
				'first_name' => 'Sam',
			)
		);
		$member  = self::factory()->user->create(
			array(
				'role'       => 'subscriber',
				'first_name' => 'Mia',
			)
		);
		$this->assertIsInt( jlife_challenges_enroll_solo( $starter, $this->campaign, 'adult' ) );
		$made = jlife_challenges_start_group( $starter, 'Alpha' );
		$this->assertIsArray( $made );
		$this->assertIsInt( jlife_challenges_enroll( $this->u['organizer'], $member, (int) $made['id'] ) );
		$this->s = array(
			'starter' => $starter,
			'member'  => $member,
			'group'   => (int) $made['id'],
		);
	}

	/**
	 * Submit the starter's complete entry for a day and return the result.
	 *
	 * @param string $day Day key.
	 * @param string $tag Text marker.
	 * @return array
	 */
	private function submit_starter( $day = 'd002', $tag = 'early' ) {
		$r = jlife_challenges_submit( $this->s['starter'], $this->campaign, $day, $this->soap( $tag ) );
		$this->assertIsArray( $r, is_wp_error( $r ) ? $r->get_error_code() : '' );
		return $r;
	}

	/**
	 * Submit day 2 and schedule it for Alpha.
	 *
	 * @param string $tag Text marker.
	 * @return int Entry ID.
	 */
	private function schedule( $tag = 'early' ) {
		$r = $this->submit_starter( 'd002', $tag );
		$s = jlife_challenges_share( $this->s['starter'], $r['entry_id'], $this->s['group'] );
		$this->assertIsInt( $s, is_wp_error( $s ) ? $s->get_error_code() : '' );
		return (int) $r['entry_id'];
	}

	/**
	 * One share row.
	 *
	 * @param int $entry_id Entry.
	 * @param int $group_id Group.
	 * @return array|null
	 */
	private function share_row( $entry_id, $group_id ) {
		global $wpdb;
		return $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . jlife_challenges_table( 'group_shares' ) . ' WHERE entry_id = %d AND group_id = %d', $entry_id, $group_id ), ARRAY_A );
	}

	/**
	 * All ledger rows of a user.
	 *
	 * @param int $user_id User.
	 * @return array[]
	 */
	private function ledger( $user_id ) {
		global $wpdb;
		return (array) $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . jlife_challenges_table( 'score_ledger' ) . ' WHERE user_id = %d', $user_id ), ARRAY_A );
	}

	/**
	 * Bodies visible in Alpha's feed to a viewer ('' when denied).
	 *
	 * @param int $viewer Viewer.
	 * @return string
	 */
	private function feed_text( $viewer ) {
		$feed = jlife_challenges_group_feed( $viewer, $this->s['group'] );
		return is_wp_error( $feed ) ? 'DENIED' : implode( '|', array_column( $feed, 'body' ) );
	}

	// ----- Who may read ahead -----------------------------------------------

	/**
	 * A member, a solo reader, an outsider and a starter of nothing cannot open a future day by any route.
	 */
	public function test_non_starters_cannot_open_or_write_future_days() {
		$solo = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		$this->assertIsInt( jlife_challenges_enroll_solo( $solo, $this->campaign, 'adult' ) );
		foreach ( array( $this->s['member'], $this->u['a'], $solo, $this->u['outsider'], $this->u['organizer'] ) as $who ) {
			$this->assertContains( jlife_challenges_day_view( $who, 'd002' )['state'], array( 'unavailable', 'not_enrolled' ) );
			$this->assertWPError( jlife_challenges_save_draft( $who, $this->campaign, 'd002', $this->soap() ) );
			$this->assertWPError( jlife_challenges_submit( $who, $this->campaign, 'd002', $this->soap() ) );
			$this->assertWPError( jlife_challenges_get_my_entry( $who, $this->campaign, 'd002' ) );
		}
		$this->assertSame( 0, $this->ledger_rows( $this->s['member'] ) );
	}

	/**
	 * The REST autosave follows the same rule as the form.
	 */
	public function test_rest_draft_for_a_future_day_is_refused_to_a_member_and_allowed_to_a_starter() {
		$request = new WP_REST_Request( 'POST', '/jlife/v1/soap/draft' );
		$request->set_param( 'campaign_id', $this->campaign );
		$request->set_param( 'day_key', 'd005' );
		$request->set_param( 'text', 'Future words' );

		wp_set_current_user( $this->s['member'] );
		$this->assertWPError( jlife_challenges_rest_save_draft( $request ) );

		wp_set_current_user( $this->s['starter'] );
		$ok = jlife_challenges_rest_save_draft( $request );
		$this->assertFalse( is_wp_error( $ok ) );
		$this->assertSame( 'Future words', jlife_challenges_find_entry( $this->s['starter'], $this->campaign, 'd005' )['body'] );
	}

	/**
	 * A starter reads ahead, drafts and submits, including a far day, in the normal flow.
	 */
	public function test_a_starter_can_open_draft_and_submit_future_days() {
		$view = jlife_challenges_day_view( $this->s['starter'], 'd002' );
		$this->assertSame( 'active', $view['state'] );
		$this->assertTrue( $view['ahead'] );
		$this->assertFalse( $view['is_today'] );
		$this->assertNotSame( '', (string) $view['release_label'] );

		$this->assertIsInt( jlife_challenges_save_draft( $this->s['starter'], $this->campaign, 'd050', 'a draft' ) );
		$this->assertSame( 'draft', jlife_challenges_find_entry( $this->s['starter'], $this->campaign, 'd050' )['status'] );
		$this->assertSame( 0, $this->ledger_rows( $this->s['starter'] ), 'A draft earns nothing.' );

		$r = $this->submit_starter( 'd002' );
		$this->assertSame( 1, $r['points'] );
		$this->assertSame( 'earned', $r['status'] );
	}

	/**
	 * An early point is recorded against the reading day with only yes/no and whole hours.
	 */
	public function test_early_submission_records_only_metadata() {
		$r = $this->submit_starter( 'd002', 'secret-words' );
		$this->assertSame( 'd002', $r['day_key'] );
		$rows = $this->ledger( $this->s['starter'] );
		$this->assertCount( 1, $rows );
		$this->assertSame( 'd002', $rows[0]['day_key'] );
		$this->assertSame( '1', (string) $rows[0]['early'] );
		$this->assertSame( '14', (string) $rows[0]['early_hours'], 'Dec 20 18:00 UTC to Dec 21 08:00 UTC.' );
		foreach ( $rows[0] as $value ) {
			$this->assertStringNotContainsString( 'secret-words', (string) $value );
		}
		global $wpdb;
		$audit = implode( ' ', (array) $wpdb->get_col( 'SELECT detail FROM ' . jlife_challenges_table( 'audit_log' ) ) );
		$this->assertStringNotContainsString( 'secret-words', $audit );
	}

	/**
	 * A same-day submission is not "early".
	 */
	public function test_same_day_submission_is_not_early() {
		$this->submit_starter( 'd001', 'today' );
		$rows = $this->ledger( $this->s['starter'] );
		$this->assertSame( '0', (string) $rows[0]['early'] );
		$this->assertSame( '0', (string) $rows[0]['early_hours'] );
	}

	/**
	 * A starter whose campaign has not started yet can read ahead (upcoming campaign).
	 */
	public function test_upcoming_campaign_is_allowed() {
		global $wpdb;
		$wpdb->query( 'DELETE FROM ' . jlife_challenges_table( 'group_members' ) . ' WHERE user_id = ' . (int) $this->s['starter'] );
		$wpdb->query( 'DELETE FROM ' . jlife_challenges_table( 'groups' ) . ' WHERE id = ' . (int) $this->s['group'] );
		$this->set_now( '2026-12-10 12:00:00' );
		$made = jlife_challenges_start_group( $this->s['starter'], 'Early Alpha' );
		$this->assertIsArray( $made );
		$this->assertSame( 'active', jlife_challenges_day_view( $this->s['starter'], 'd003' )['state'] );
		$this->assertSame( 'unavailable', jlife_challenges_day_view( $this->s['member'], 'd003' )['state'] );
		$this->assertIsInt( jlife_challenges_save_draft( $this->s['starter'], $this->campaign, 'd003', 'early draft' ) );
	}

	/**
	 * A closed campaign stays read-only for a starter.
	 */
	public function test_closed_campaign_is_read_only_for_a_starter() {
		$this->submit_starter( 'd002' );
		$this->assertIsArray( jlife_challenges_close_campaign( $this->u['organizer'], $this->campaign ) );
		$this->assertFalse( jlife_challenges_can_read_ahead( $this->s['starter'], jlife_challenges_get_campaign( $this->campaign ) ) );
		$this->assertWPError( jlife_challenges_save_draft( $this->s['starter'], $this->campaign, 'd009', 'no' ) );
		$this->assertWPError( jlife_challenges_submit( $this->s['starter'], $this->campaign, 'd009', $this->soap() ) );
	}

	/**
	 * Losing starter status, leaving, or a disabled group removes the right at once.
	 */
	public function test_a_former_starter_or_disabled_group_cannot_read_ahead() {
		$campaign = jlife_challenges_get_campaign( $this->campaign );
		$this->assertTrue( jlife_challenges_can_read_ahead( $this->s['starter'], $campaign ) );
		$this->assertFalse( jlife_challenges_can_read_ahead( $this->s['member'], $campaign ) );
		$this->assertFalse( jlife_challenges_can_read_ahead( 0, $campaign ) );

		$this->assertTrue( jlife_challenges_disable_group( $this->u['organizer'], $this->s['group'] ) );
		$this->assertFalse( jlife_challenges_can_read_ahead( $this->s['starter'], $campaign ) );
		$this->assertSame( 'unavailable', jlife_challenges_day_view( $this->s['starter'], 'd002' )['state'] );
		$this->assertTrue( jlife_challenges_enable_group( $this->u['organizer'], $this->s['group'] ) );
		$this->assertTrue( jlife_challenges_can_read_ahead( $this->s['starter'], $campaign ) );

		$this->assertTrue( jlife_challenges_leave_group( $this->s['starter'], $this->s['group'] ) );
		$this->assertFalse( jlife_challenges_can_read_ahead( $this->s['starter'], $campaign ) );
	}

	// ----- Scheduling ------------------------------------------------------

	/**
	 * A starter schedules for the group they start, never for one they only belong to.
	 */
	public function test_a_starter_cannot_schedule_into_a_group_they_only_belong_to() {
		$enrollment = jlife_challenges_active_enrollment( $this->campaign, $this->s['starter'] );
		$this->assertIsInt( jlife_challenges_add_membership( $this->u['organizer'], $enrollment, jlife_challenges_get_group( $this->g['g1'] ) ) );
		$r = $this->submit_starter();

		$denied = jlife_challenges_share( $this->s['starter'], $r['entry_id'], $this->g['g1'] );
		$this->assertWPError( $denied );
		$this->assertNull( $this->share_row( $r['entry_id'], $this->g['g1'] ), 'Nothing is copied into the other group.' );

		$this->assertIsInt( jlife_challenges_share( $this->s['starter'], $r['entry_id'], $this->s['group'] ) );
		$this->assertSame( 'scheduled', $this->share_row( $r['entry_id'], $this->s['group'] )['status'] );
		$this->assertNull( $this->share_row( $r['entry_id'], $this->g['g1'] ) );
	}

	/**
	 * Ticking a group on the save form is an explicit, per-group request; unticked groups get nothing.
	 */
	public function test_save_and_share_schedules_only_ticked_started_groups() {
		$enrollment = jlife_challenges_active_enrollment( $this->campaign, $this->s['starter'] );
		$this->assertIsInt( jlife_challenges_add_membership( $this->u['organizer'], $enrollment, jlife_challenges_get_group( $this->g['g1'] ) ) );
		$none = jlife_challenges_save_and_share( $this->s['starter'], $this->campaign, 'd002', $this->soap(), array() );
		$this->assertSame( 'none', $none['share']['status'] );
		$this->assertNull( $this->share_row( $none['entry_id'], $this->s['group'] ) );

		$both = jlife_challenges_save_and_share( $this->s['starter'], $this->campaign, 'd002', $this->soap(), array( $this->s['group'], $this->g['g1'] ) );
		$this->assertSame( 'partial', $both['share']['status'] );
		$this->assertSame( 'shared', $both['share']['results'][ $this->s['group'] ]['status'] );
		$this->assertSame( 'failed', $both['share']['results'][ $this->g['g1'] ]['status'] );
		$this->assertSame( 'scheduled', $this->share_row( $both['entry_id'], $this->s['group'] )['status'] );
	}

	/**
	 * The share boxes for a future day list only the groups the person starts.
	 */
	public function test_future_day_share_groups_are_the_started_groups() {
		$enrollment = jlife_challenges_active_enrollment( $this->campaign, $this->s['starter'] );
		$this->assertIsInt( jlife_challenges_add_membership( $this->u['organizer'], $enrollment, jlife_challenges_get_group( $this->g['g1'] ) ) );
		$view = jlife_challenges_day_view( $this->s['starter'], 'd002' );
		$this->assertSame( array( $this->s['group'] ), array_map( 'intval', array_column( $view['share_groups'], 'id' ) ) );
		$today = jlife_challenges_day_view( $this->s['starter'], 'd001' );
		$this->assertCount( 2, $today['share_groups'], 'An open day keeps every group.' );
	}

	/**
	 * A member of the group never sees an unreleased share through any read path.
	 */
	public function test_a_scheduled_share_is_invisible_to_everyone_but_its_author() {
		$entry = $this->schedule( 'hidden-until-open' );
		$this->set_now( '2026-12-21 07:59:59' );

		foreach ( array( $this->s['member'], $this->s['starter'] ) as $viewer ) {
			$this->assertStringNotContainsString( 'hidden-until-open', $this->feed_text( $viewer ) );
			$view = jlife_challenges_group_view( $viewer, $this->s['group'] );
			$this->assertSame( array(), (array) $view['posts'] );
			$this->assertStringNotContainsString( 'hidden-until-open', jlife_challenges_group_body( $view ) );
		}
		foreach ( array( $this->u['a'], $this->u['outsider'], $this->u['organizer'], 0 ) as $viewer ) {
			$this->assertSame( 'DENIED', $this->feed_text( $viewer ) );
		}
		$this->assertSame( array(), jlife_challenges_leader_thoughts( $this->s['member'], jlife_challenges_get_campaign( $this->campaign ), 'd002' ) );
		$this->assertSame( 0, jlife_challenges_close_count( 'group_shares', $this->campaign, 'shared' ) );
		$this->assertWPError( jlife_challenges_get_entry( $this->s['member'], $entry ) );
		$this->assertWPError( jlife_challenges_unshare( $this->s['member'], $this->share_row( $entry, $this->s['group'] )['id'] ) );
		$this->assertSame( 'unavailable', jlife_challenges_share_view( $this->s['member'], 'd002' )['state'] );
		$this->assertSame( 'scheduled', $this->share_row( $entry, $this->s['group'] )['status'] );

		// The author alone sees it, as scheduled, with a release time.
		$mine = jlife_challenges_share_view( $this->s['starter'], 'd002' );
		$this->assertSame( 'scheduled', $mine['destinations'][0]['share']['status'] );
	}

	/**
	 * The share opens at the campaign-time midnight, first in its day, with no session and no new scoring.
	 */
	public function test_release_at_the_day_boundary_without_a_session() {
		$entry = $this->schedule( 'now-open' );
		$rows  = $this->ledger( $this->s['starter'] );
		$this->set_now( '2026-12-21 07:59:59' );
		$this->assertStringNotContainsString( 'now-open', $this->feed_text( $this->s['member'] ) );

		$this->set_now( self::DAY2_OPENS );
		$this->assertSame( 1, jlife_challenges_release_due_shares(), 'A system sweep releases it.' );
		$this->assertSame( 'shared', $this->share_row( $entry, $this->s['group'] )['status'] );
		$this->assertStringContainsString( 'now-open', $this->feed_text( $this->s['member'] ) );
		$this->assertSame( $rows, $this->ledger( $this->s['starter'] ), 'Release never scores.' );
		$this->assertSame( 0, jlife_challenges_release_due_shares(), 'A second sweep changes nothing.' );
	}

	/**
	 * A member's own first read at the boundary releases it too (no cron needed).
	 */
	public function test_a_members_read_releases_a_due_share() {
		$this->schedule( 'lazy-open' );
		$this->set_now( self::DAY2_OPENS );
		$this->assertStringContainsString( 'lazy-open', $this->feed_text( $this->s['member'] ) );
	}

	/**
	 * Spring DST (Mar 14 2027): the day after the change opens at 00:00 PDT, not 00:00 PST + 24 hours.
	 */
	public function test_release_across_the_dst_change() {
		$r = $this->submit_starter( 'd086', 'dst-day' );
		$this->assertIsInt( jlife_challenges_share( $this->s['starter'], $r['entry_id'], $this->s['group'] ) );
		$this->set_now( '2027-03-15 06:59:59' ); // 23:59:59 PDT on Mar 14: still Day 85.
		$this->assertStringNotContainsString( 'dst-day', $this->feed_text( $this->s['member'] ) );
		$this->set_now( '2027-03-15 07:00:00' ); // 00:00 PDT on Mar 15.
		$this->assertStringContainsString( 'dst-day', $this->feed_text( $this->s['member'] ) );
	}

	/**
	 * Released starter shares sit first within the day and are labelled.
	 */
	public function test_released_starter_share_is_first_and_labelled() {
		$this->schedule( 'starter-words' );
		$this->set_now( '2026-12-20 20:00:00' );
		jlife_challenges_save_and_share( $this->s['member'], $this->campaign, 'd001', $this->soap( 'plain' ), array( $this->s['group'] ) );
		$this->set_now( '2026-12-21 09:00:00' );
		jlife_challenges_save_and_share( $this->s['member'], $this->campaign, 'd002', $this->soap( 'member-words' ), array( $this->s['group'] ) );
		$view = jlife_challenges_group_view( $this->s['member'], $this->s['group'] );
		$day2 = array_values( array_filter( $view['posts'], static fn( $p ) => 'd002' === $p['day_key'] ) );
		$this->assertStringContainsString( 'starter-words', $day2[0]['body'] );
		$this->assertTrue( $day2[0]['leader'] );
		$this->assertFalse( $day2[1]['leader'] );
		$html = jlife_challenges_group_body( $view );
		$this->assertStringContainsString( 'jl-leader-post', $html );
	}

	/**
	 * Today shows a collapsed, labelled pane per eligible group; none when there is nothing.
	 */
	public function test_today_leader_thoughts_pane() {
		$this->schedule( 'pane-words' );
		$campaign = jlife_challenges_get_campaign( $this->campaign );
		$this->set_now( self::DAY2_OPENS );

		$view = jlife_challenges_day_view( $this->s['member'], 'd002' );
		$this->assertCount( 1, $view['leader_thoughts'] );
		$html = jlife_challenges_render_leader_thoughts( $view );
		$this->assertStringContainsString( '<details class="jl-leader-thoughts">', $html );
		$this->assertStringNotContainsString( ' open', $html );
		$this->assertStringContainsString( 'Alpha leader’s thoughts', $html );
		$this->assertStringContainsString( 'pane-words', $html );

		// A member of another group, the author and a day with nothing get no pane.
		$this->assertSame( '', jlife_challenges_render_leader_thoughts( jlife_challenges_day_view( $this->u['a'], 'd002' ) ) );
		$this->assertSame( '', jlife_challenges_render_leader_thoughts( jlife_challenges_day_view( $this->s['starter'], 'd002' ) ) );
		$this->assertSame( array(), jlife_challenges_leader_thoughts( $this->s['member'], $campaign, 'd001' ) );
		$this->assertSame( '', jlife_challenges_render_leader_thoughts( jlife_challenges_day_view( $this->s['member'], 'd001' ) ) );
	}

	/**
	 * The pane is the same row as the My Group post: an update or a removal changes both.
	 */
	public function test_update_and_removal_apply_to_both_screens() {
		$entry = $this->schedule( 'first-version' );
		$this->set_now( self::DAY2_OPENS );
		$this->assertStringContainsString( 'first-version', jlife_challenges_leader_thoughts( $this->s['member'], jlife_challenges_get_campaign( $this->campaign ), 'd002' )[0]['body'] );
		$this->assertTrue( jlife_challenges_unshare( $this->s['starter'], $this->share_row( $entry, $this->s['group'] )['id'] ) );
		$this->assertSame( array(), jlife_challenges_leader_thoughts( $this->s['member'], jlife_challenges_get_campaign( $this->campaign ), 'd002' ) );
		$this->assertStringNotContainsString( 'first-version', $this->feed_text( $this->s['member'] ) );
	}

	// ----- Withdrawal ---------------------------------------------------

	/**
	 * Leaving withdraws an unreleased share; coming back as starter does not revive it.
	 */
	public function test_leaving_withdraws_and_regaining_the_role_does_not_revive() {
		$entry = $this->schedule( 'gone' );
		$this->assertTrue( jlife_challenges_leave_group( $this->s['starter'], $this->s['group'] ) );
		$row = $this->share_row( $entry, $this->s['group'] );
		$this->assertSame( 'removed', $row['status'] );
		$this->assertNull( $row['body'] );

		global $wpdb;
		$wpdb->update(
			jlife_challenges_table( 'group_members' ),
			array(
				'status'  => 'active',
				'role'    => 'starter',
				'left_at' => null,
			),
			array(
				'group_id' => $this->s['group'],
				'user_id'  => $this->s['starter'],
			)
		);
		$this->assertTrue( jlife_challenges_can_read_ahead( $this->s['starter'], jlife_challenges_get_campaign( $this->campaign ) ) );
		$this->set_now( self::DAY2_OPENS );
		jlife_challenges_release_due_shares();
		$this->assertSame( 'removed', $this->share_row( $entry, $this->s['group'] )['status'] );
		$this->assertStringNotContainsString( 'gone', $this->feed_text( $this->s['member'] ) );
	}

	/**
	 * An organizer removing the person withdraws their unreleased shares.
	 */
	public function test_organizer_removal_withdraws() {
		$entry = $this->schedule( 'x1' );
		$this->assertTrue( jlife_challenges_remove_member( $this->u['organizer'], $this->campaign, $this->s['starter'] ) );
		$this->assertSame( 'removed', $this->share_row( $entry, $this->s['group'] )['status'] );
	}

	/**
	 * Disabling the group withdraws; re-enabling does not bring it back.
	 */
	public function test_disabling_the_group_withdraws() {
		$entry = $this->schedule( 'x2' );
		$this->assertTrue( jlife_challenges_disable_group( $this->u['organizer'], $this->s['group'] ) );
		$this->assertSame( 'removed', $this->share_row( $entry, $this->s['group'] )['status'] );
		$this->assertTrue( jlife_challenges_enable_group( $this->u['organizer'], $this->s['group'] ) );
		$this->set_now( self::DAY2_OPENS );
		jlife_challenges_release_due_shares();
		$this->assertStringNotContainsString( 'x2', $this->feed_text( $this->s['member'] ) );
		$this->assertSame( 'removed', $this->share_row( $entry, $this->s['group'] )['status'] );
	}

	/**
	 * Closing the campaign withdraws shares that never opened.
	 */
	public function test_closing_the_campaign_withdraws() {
		$entry = $this->schedule( 'x3' );
		$this->assertIsArray( jlife_challenges_close_campaign( $this->u['organizer'], $this->campaign ) );
		$this->assertSame( 'removed', $this->share_row( $entry, $this->s['group'] )['status'] );
	}

	/**
	 * Losing starter status without any membership event is caught on the next read.
	 */
	public function test_a_demoted_starter_is_withdrawn_on_the_next_read() {
		$entry = $this->schedule( 'x4' );
		global $wpdb;
		$wpdb->update(
			jlife_challenges_table( 'group_members' ),
			array( 'role' => 'member' ),
			array(
				'group_id' => $this->s['group'],
				'user_id'  => $this->s['starter'],
			)
		);
		$this->set_now( self::DAY2_OPENS );
		$this->assertStringNotContainsString( 'x4', $this->feed_text( $this->s['member'] ) );
		$this->assertSame( 'removed', $this->share_row( $entry, $this->s['group'] )['status'] );
	}

	/**
	 * Withdrawing, scheduling again and retrying never duplicate a row or touch the point.
	 */
	public function test_withdraw_reschedule_and_retry_are_idempotent() {
		$entry = $this->schedule( 'v1' );
		$id    = (int) $this->share_row( $entry, $this->s['group'] )['id'];
		$this->assertIsInt( jlife_challenges_share( $this->s['starter'], $entry, $this->s['group'] ) );
		$this->assertTrue( jlife_challenges_unshare( $this->s['starter'], $id ) );
		$this->assertTrue( jlife_challenges_unshare( $this->s['starter'], $id ), 'Withdrawing twice is a no-op.' );
		$this->assertCount( 1, $this->ledger( $this->s['starter'] ), 'Withdrawing keeps the point.' );

		$this->assertIsInt( jlife_challenges_share( $this->s['starter'], $entry, $this->s['group'] ) );
		$this->assertSame( 'scheduled', $this->share_row( $entry, $this->s['group'] )['status'] );
		global $wpdb;
		$this->assertSame( 1, (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . jlife_challenges_table( 'group_shares' ) . ' WHERE entry_id = ' . $entry ) );
	}

	// ----- Scoring ----------------------------------------------------------

	/**
	 * One point per person, campaign and day across formats, groups, retries, release and withdrawal.
	 */
	public function test_one_point_across_formats_groups_and_release() {
		$first = $this->submit_starter( 'd002', 'one' );
		$this->assertSame( 1, $first['points'] );
		$this->use_engine( 'reflection-v1' );
		$again = jlife_challenges_submit( $this->s['starter'], $this->campaign, 'd002', 'Today I noticed how patient the story is with its people and I want to be patient in the same way with my family this week.' );
		$this->assertIsArray( $again );
		$this->assertCount( 1, $this->ledger( $this->s['starter'] ) );
		jlife_challenges_share( $this->s['starter'], $first['entry_id'], $this->s['group'] );
		$this->set_now( self::DAY2_OPENS );
		jlife_challenges_release_due_shares();
		jlife_challenges_unshare( $this->s['starter'], $this->share_row( $first['entry_id'], $this->s['group'] )['id'] );
		$this->assertCount( 1, $this->ledger( $this->s['starter'] ) );
		$this->assertSame( 1, jlife_challenges_progress( $this->s['starter'], $this->s['starter'], $this->campaign )['points'] );
	}

	/**
	 * An advance entry that does not qualify keeps the writing and earns nothing.
	 */
	public function test_a_failed_advance_submission_keeps_the_text() {
		$r = jlife_challenges_submit( $this->s['starter'], $this->campaign, 'd002', $this->soap_without_prayer( 'keep' ) );
		$this->assertFalse( $r['passes'] );
		$this->assertSame( 0, $this->ledger_rows( $this->s['starter'] ) );
		$this->assertStringContainsString( 'keep', jlife_challenges_find_entry( $this->s['starter'], $this->campaign, 'd002' )['body'] );
	}

	// ----- Isolation -----------------------------------------------------

	/**
	 * A share keeps its campaign, day and group: it never shows in another campaign's view.
	 */
	public function test_pilot_and_official_shares_do_not_mix() {
		$entry = $this->schedule( 'pilot-only' );
		$other = jlife_challenges_create_campaign(
			$this->u['organizer'],
			array(
				'campaign_key' => 'official-' . wp_generate_password( 6, false ),
				'plan_id'      => 'rpl-life-and-teachings-of-jesus-2026',
				'title'        => 'Official (test)',
				'start_date'   => '2027-05-01',
				'end_date'     => '2027-08-12',
				'status'       => 'active',
			)
		);
		$this->assertIsInt( $other );
		$this->assertIsInt( jlife_challenges_enroll_solo( $this->s['member'], $other, 'adult' ) );
		$this->set_now( self::DAY2_OPENS );
		jlife_challenges_release_due_shares();
		$row = $this->share_row( $entry, $this->s['group'] );
		$this->assertSame( (string) $this->campaign, (string) $row['campaign_id'] );
		$this->assertSame( array(), jlife_challenges_leader_thoughts( $this->s['member'], jlife_challenges_get_campaign( $other ), 'd002' ) );
		$this->assertCount( 1, jlife_challenges_leader_thoughts( $this->s['member'], jlife_challenges_get_campaign( $this->campaign ), 'd002' ) );
	}

	// ----- Read-ahead content --------------------------------------------

	/**
	 * Only a Ready devotional of a future day is shown to a starter; a Draft never; a member sees none;
	 * the post and its comment window are untouched.
	 */
	public function test_read_ahead_shows_only_ready_devotionals_and_changes_nothing() {
		$ready  = $this->make_devotional( 'publish', '<p>Ready body</p>', 'd002' );
		$draft  = $this->make_devotional( 'draft', '<p>Draft body</p>', 'd003' );
		$before = array( get_post( $ready )->post_status, get_post( $ready )->comment_status, get_post( $ready )->post_date_gmt );

		wp_set_current_user( $this->s['starter'] );
		$html = jlife_challenges_render_devotional( jlife_challenges_day_view( $this->s['starter'], 'd002' ) );
		$this->assertStringContainsString( 'Ready body', $html );
		$this->assertSame( '', jlife_challenges_render_devotional( jlife_challenges_day_view( $this->s['starter'], 'd003' ) ) );

		wp_set_current_user( $this->s['member'] );
		$this->assertSame( '', jlife_challenges_render_devotional( jlife_challenges_day_view( $this->s['member'], 'd002' ) ) );

		$after = array( get_post( $ready )->post_status, get_post( $ready )->comment_status, get_post( $ready )->post_date_gmt );
		$this->assertSame( $before, $after );
		$this->assertSame( 'draft', get_post( $draft )->post_status );
		$this->assertNull( jlife_challenges_devotional_for_day( jlife_challenges_get_campaign( $this->campaign ), 'd002', 'en' ), 'The public gate is unchanged.' );
	}

	/**
	 * Today's other participants' writing never appears on a future day view.
	 */
	public function test_a_future_day_view_has_no_one_elses_writing() {
		$this->schedule( 'private-note' );
		$view = jlife_challenges_day_view( $this->s['starter'], 'd002' );
		$this->assertSame( array(), $view['leader_thoughts'] );
		$this->assertStringNotContainsString( 'private-note', wp_json_encode( jlife_challenges_day_view( $this->s['member'], 'd002' ) ) );
	}

	// ----- Grid and arrows ---------------------------------------------------

	/**
	 * Future days are links only for an eligible starter; the arrows follow the same rule.
	 */
	public function test_grid_and_arrows_follow_the_read_ahead_rule() {
		$starter = jlife_challenges_marathon_view( $this->s['starter'] );
		$member  = jlife_challenges_marathon_view( $this->s['member'] );
		$this->assertTrue( $starter['cells'][2]['ahead'] );
		$this->assertFalse( $member['cells'][2]['ahead'] );
		$this->assertSame( 'future', $member['cells'][2]['state'] );
		$this->assertSame( 2, jlife_challenges_adjacent_days( jlife_challenges_day_view( $this->s['starter'], 'd001' ) )['next'] );
		$this->assertSame( 0, jlife_challenges_adjacent_days( jlife_challenges_day_view( $this->s['member'], 'd001' ) )['next'] );
	}
}
