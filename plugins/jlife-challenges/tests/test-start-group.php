<?php
/**
 * "Start a group" (#112, part 2): an adult names a group and becomes its
 * starter; the starter holds the invite code and nothing more. Access-control
 * and limit tests for the rows in docs/challenges-engine-design.md §4 and §4c.
 * They were written before the feature.
 *
 * @package jlife-challenges
 */

// phpcs:disable WordPress.DB.DirectDatabaseQuery -- tests read the plugin's own tables directly.

/**
 * Start-a-group tests.
 */
class Test_Start_Group extends Jlife_Challenges_TestCase {

	/**
	 * A fresh adult, solo in the campaign.
	 *
	 * @param string $first First name.
	 * @return int User ID.
	 */
	private function adult( $first = 'Starter' ) {
		$id = self::factory()->user->create(
			array(
				'role'       => 'subscriber',
				'first_name' => $first,
			)
		);
		$this->assertIsInt( jlife_challenges_enroll_solo( $id, $this->campaign, 'adult' ) );
		return $id;
	}

	/**
	 * A fresh 13-17 participant, solo in the campaign.
	 *
	 * @return int User ID.
	 */
	private function minor() {
		$id = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		$this->assertIsInt( jlife_challenges_enroll_solo( $id, $this->campaign, 'minor' ) );
		return $id;
	}

	/**
	 * Start a group and fail the test if it is refused.
	 *
	 * @param int    $user_id Starter.
	 * @param string $name    Group name.
	 * @return array { id, invite_code, name }
	 */
	private function start( $user_id, $name = 'Tuesday friends' ) {
		$r = jlife_challenges_start_group( $user_id, $name );
		$this->assertIsArray( $r, is_wp_error( $r ) ? $r->get_error_code() : '' );
		return $r;
	}

	/**
	 * IDs of a user's active groups.
	 *
	 * @param int $user_id User.
	 * @return int[]
	 */
	private function groups_of( $user_id ) {
		return array_map( 'intval', array_column( jlife_challenges_active_memberships( $this->campaign, $user_id ), 'group_id' ) );
	}

	/**
	 * Number of group rows (any status) in the campaign.
	 *
	 * @return int
	 */
	private function group_rows() {
		global $wpdb;
		return (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . jlife_challenges_table( 'groups' ) . ' WHERE campaign_id = %d', $this->campaign ) ); // phpcs:ignore WordPress.DB
	}

	/**
	 * Audit actions recorded for a group, oldest first.
	 *
	 * @param int $group_id Group.
	 * @return string[]
	 */
	private function audit_actions( $group_id ) {
		global $wpdb;
		return $wpdb->get_col( $wpdb->prepare( 'SELECT action FROM ' . jlife_challenges_table( 'audit_log' ) . " WHERE object_type = 'group' AND object_id = %d ORDER BY id", $group_id ) ); // phpcs:ignore WordPress.DB
	}

	/**
	 * An adult can start a group: it is in the open campaign, active, and they are its starter.
	 */
	public function test_an_adult_starts_a_group_and_is_its_starter() {
		$u = $this->adult();
		$r = $this->start( $u );

		$this->assertSame( 'Tuesday friends', $r['name'] );
		$this->assertMatchesRegularExpression( '/^[A-Z2-9]{8}$/', $r['invite_code'] );
		$group = jlife_challenges_get_group( $r['id'] );
		$this->assertSame( (string) $this->campaign, (string) $group['campaign_id'] );
		$this->assertSame( 'active', $group['status'] );

		$m = jlife_challenges_get_membership( $r['id'], $u );
		$this->assertSame( 'starter', $m['role'] );
		$this->assertSame( 'active', $m['status'] );
		$this->assertTrue( jlife_challenges_is_group_starter( $u, $r['id'] ) );
		$this->assertTrue( jlife_challenges_is_group_member( $u, $r['id'] ) );
		$this->assertFalse( jlife_challenges_is_group_starter( $this->u['a'], $this->g['g1'] ), 'organizer-made groups have no starter' );
	}

	/**
	 * Someone who is not enrolled yet cannot start a group.
	 */
	public function test_a_person_with_no_enrollment_cannot_start_a_group() {
		$before = $this->group_rows();
		$this->assertWPError( jlife_challenges_start_group( $this->u['outsider'], 'Nope' ) );
		$this->assertSame( $before, $this->group_rows() );
	}

	/**
	 * A minor can never start a group: refused server-side, nothing is created, the attempt is audited.
	 */
	public function test_a_minor_cannot_start_a_group() {
		$m      = $this->minor();
		$before = $this->group_rows();
		$r      = jlife_challenges_start_group( $m, 'Youth hangout' );
		$this->assertWPError( $r );
		$this->assertSame( 'jlife_minor_cannot_start', $r->get_error_code() );
		$this->assertSame( $before, $this->group_rows(), 'no group row' );
		$this->assertSame( array(), $this->groups_of( $m ), 'no membership' );
		$this->assertFalse( jlife_challenges_can_start_group( $m ), 'the UI check agrees' );
	}

	/**
	 * A minor who is already in groups still cannot start one, however often they try.
	 */
	public function test_a_minor_in_a_group_still_cannot_start_one() {
		$m = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		$this->assertIsInt( jlife_challenges_enroll( $this->u['organizer'], $m, $this->g['g1'], 'minor' ) );
		$this->assertFalse( jlife_challenges_can_start_group( $m ) );
		$this->assertSame( 'jlife_minor_cannot_start', jlife_challenges_start_group( $m, 'Mine' )->get_error_code() );
		$this->assertSame( array( (int) $this->g['g1'] ), $this->groups_of( $m ) );
	}

	/**
	 * At most two groups started per person per campaign: the third is refused.
	 */
	public function test_a_third_started_group_is_refused() {
		$u = $this->adult();
		$this->start( $u, 'First' );
		$this->start( $u, 'Second' );
		$before = $this->group_rows();
		$r      = jlife_challenges_start_group( $u, 'Third' );
		$this->assertWPError( $r );
		$this->assertSame( 'jlife_start_limit', $r->get_error_code() );
		$this->assertSame( $before, $this->group_rows() );
		$this->assertFalse( jlife_challenges_can_start_group( $u ) );
	}

	/**
	 * Leaving or losing a started group does not free the person to start another one.
	 */
	public function test_leaving_or_disabling_does_not_reset_the_started_count() {
		$u  = $this->adult();
		$g1 = $this->start( $u, 'First' );
		$g2 = $this->start( $u, 'Second' );
		$this->assertTrue( jlife_challenges_leave_group( $u, $g1['id'] ) );
		$this->assertTrue( jlife_challenges_disable_group( $this->u['organizer'], $g2['id'] ) );
		$this->assertSame( 'jlife_start_limit', jlife_challenges_start_group( $u, 'Third' )->get_error_code() );
	}

	/**
	 * Starting counts toward the 3-group limit; at the limit a start is refused and creates nothing.
	 */
	public function test_starting_counts_toward_the_three_group_limit() {
		$u = $this->adult();
		$this->start( $u, 'Started' );
		$this->assertCount( 1, $this->groups_of( $u ) );
		$this->assertIsInt( jlife_challenges_join_with_code( $u, jlife_challenges_reset_invite( $this->u['organizer'], $this->g['g1'] ), 'adult' ) );
		$this->assertIsInt( jlife_challenges_join_with_code( $u, jlife_challenges_reset_invite( $this->u['organizer'], $this->g['g2'] ), 'adult' ) );
		$this->assertCount( 3, $this->groups_of( $u ) );

		$extra  = jlife_challenges_create_group( $this->u['organizer'], $this->campaign, 'Fourth' );
		$before = $this->group_rows();
		$r      = jlife_challenges_start_group( $u, 'Fifth' );
		$this->assertWPError( $r );
		$this->assertSame( 'jlife_group_limit', $r->get_error_code() );
		$this->assertSame( $before, $this->group_rows(), 'no orphan group is left behind' );
		$this->assertWPError( jlife_challenges_join_with_code( $u, $extra['invite_code'], 'adult' ), 'a started group uses a slot like any other' );
		$this->assertCount( 3, $this->groups_of( $u ) );
	}

	/**
	 * A person already in two groups can start one (the third slot); a free slot reappears after leaving.
	 */
	public function test_a_free_slot_is_needed_and_leaving_frees_one() {
		$u = $this->adult();
		jlife_challenges_join_with_code( $u, jlife_challenges_reset_invite( $this->u['organizer'], $this->g['g1'] ), 'adult' );
		jlife_challenges_join_with_code( $u, jlife_challenges_reset_invite( $this->u['organizer'], $this->g['g2'] ), 'adult' );
		$this->assertTrue( jlife_challenges_can_start_group( $u ) );
		$g = $this->start( $u, 'Third slot' );
		$this->assertFalse( jlife_challenges_can_start_group( $u ), 'no free slot' );
		$this->assertTrue( jlife_challenges_leave_group( $u, $this->g['g1'] ) );
		$this->assertTrue( jlife_challenges_can_start_group( $u ) );
		$this->assertContains( (int) $g['id'], $this->groups_of( $u ) );
	}

	/**
	 * The name is cleaned and capped; bad names are refused and create nothing.
	 */
	public function test_group_names_are_sanitized_and_capped_at_60_characters() {
		$u = $this->adult();
		$r = $this->start( $u, '  <b>Thursday</b> <script>alert(1)</script>night  ' );
		$this->assertStringNotContainsString( '<', $r['name'] );
		$this->assertSame( $r['name'], jlife_challenges_get_group( $r['id'] )['name'] );
		$this->assertStringContainsString( 'Thursday', $r['name'] );

		$before = $this->group_rows();
		foreach ( array( '', '   ', '<b></b>', str_repeat( 'x', 61 ), str_repeat( 'ế', 61 ) ) as $bad ) {
			$e = jlife_challenges_start_group( $u, $bad );
			$this->assertWPError( $e, 'name: ' . $bad );
			$this->assertSame( 'jlife_group_name_invalid', $e->get_error_code() );
		}
		$this->assertSame( $before, $this->group_rows() );
		$ok = $this->start( $u, str_repeat( 'ế', 60 ) );
		$this->assertSame( 60, mb_strlen( $ok['name'] ) );
	}

	/**
	 * Creation attempts are rate limited per person, refused attempts included.
	 */
	public function test_creation_attempts_are_rate_limited() {
		$u = $this->adult();
		for ( $i = 0; $i < JLIFE_CHALLENGES_START_TRIES; $i++ ) {
			$this->assertSame( 'jlife_group_name_invalid', jlife_challenges_start_group( $u, '' )->get_error_code() );
		}
		$r = jlife_challenges_start_group( $u, 'A fine name' );
		$this->assertWPError( $r );
		$this->assertSame( 'jlife_start_rate', $r->get_error_code() );
		$this->assertSame( array(), $this->groups_of( $u ) );
	}

	/**
	 * The invite code works once for friends; the starter is audited.
	 */
	public function test_the_code_joins_friends_and_everything_is_audited() {
		$u = $this->adult();
		$r = $this->start( $u );
		$this->assertIsInt( jlife_challenges_join_with_code( $this->u['outsider'], $r['invite_code'], 'adult' ) );
		$this->assertTrue( jlife_challenges_is_group_member( $this->u['outsider'], $r['id'] ) );
		$this->assertFalse( jlife_challenges_is_group_starter( $this->u['outsider'], $r['id'] ) );
		$this->assertContains( 'group_started', $this->audit_actions( $r['id'] ) );
		$this->assertContains( 'invite_reset', $this->audit_actions( $r['id'] ) );
		global $wpdb;
		$joined = $wpdb->get_var( $wpdb->prepare( 'SELECT detail FROM ' . jlife_challenges_table( 'audit_log' ) . " WHERE action = 'group_joined' AND actor_user_id = %d", $u ) ); // phpcs:ignore WordPress.DB
		$this->assertStringContainsString( 'starter', (string) $joined );
	}

	/**
	 * Only the group's starter or an organizer can reset its code; the old code stops working.
	 */
	public function test_only_the_starter_or_an_organizer_can_reset_the_code() {
		$u = $this->adult();
		$r = $this->start( $u );
		jlife_challenges_join_with_code( $this->u['outsider'], $r['invite_code'], 'adult' ); // A plain member.
		$stranger = $this->adult( 'Stranger' );

		foreach ( array(
			'another member'     => $this->u['outsider'],
			'a non-member'       => $stranger,
			'a member of others' => $this->u['a'],
			'nobody'             => 0,
		) as $who => $actor ) {
			$e = jlife_challenges_reset_invite( $actor, $r['id'] );
			$this->assertWPError( $e, $who );
			$this->assertSame( 'jlife_denied', $e->get_error_code(), $who );
			$this->assertSame( (int) $r['id'], (int) jlife_challenges_group_for_invite( $r['invite_code'] )['id'], "{$who} did not change the code" );
		}

		$new = jlife_challenges_reset_invite( $u, $r['id'] );
		$this->assertIsString( $new );
		$this->assertNull( jlife_challenges_group_for_invite( $r['invite_code'] ), 'the old code stops working' );
		$this->assertSame( (int) $r['id'], (int) jlife_challenges_group_for_invite( $new )['id'] );

		$org = jlife_challenges_reset_invite( $this->u['organizer'], $r['id'] );
		$this->assertIsString( $org, 'an organizer can reset any group' );
		$this->assertNull( jlife_challenges_group_for_invite( $new ) );
	}

	/**
	 * A starter cannot reset the code of a group they only belong to as a member, nor an organizer-made one.
	 */
	public function test_a_starter_has_no_power_over_other_groups() {
		$u     = $this->adult();
		$mine  = $this->start( $u, 'Mine' );
		$other = $this->adult( 'Other' );
		$theirs = $this->start( $other, 'Theirs' );
		jlife_challenges_join_with_code( $u, $theirs['invite_code'], 'adult' );

		$this->assertSame( 'jlife_denied', jlife_challenges_reset_invite( $u, $theirs['id'] )->get_error_code() );
		$this->assertSame( 'jlife_denied', jlife_challenges_reset_invite( $u, $this->g['g1'] )->get_error_code() );
		$this->assertIsString( jlife_challenges_reset_invite( $u, $mine['id'] ) );
	}

	/**
	 * A starter who left no longer holds the code; one whose group was disabled cannot reset it either.
	 */
	public function test_a_former_starter_or_a_disabled_group_cannot_reset() {
		$u = $this->adult();
		$r = $this->start( $u );
		$this->assertTrue( jlife_challenges_leave_group( $u, $r['id'] ) );
		$this->assertSame( 'jlife_denied', jlife_challenges_reset_invite( $u, $r['id'] )->get_error_code() );

		$u2 = $this->adult( 'Second' );
		$r2 = $this->start( $u2, 'Second group' );
		$this->assertTrue( jlife_challenges_disable_group( $this->u['organizer'], $r2['id'] ) );
		$this->assertSame( 'jlife_denied', jlife_challenges_reset_invite( $u2, $r2['id'] )->get_error_code() );
	}

	/**
	 * A starter has NO extra view: members' private entries, progress and other groups stay closed.
	 */
	public function test_a_starter_cannot_read_members_entries_or_progress() {
		$u = $this->adult();
		$r = $this->start( $u );
		jlife_challenges_join_with_code( $this->u['outsider'], $r['invite_code'], 'adult' );
		$member = $this->u['outsider'];

		$saved = jlife_challenges_submit( $member, $this->campaign, 'd001', $this->soap( 'member-private' ) );
		$this->assertIsArray( $saved );
		$entry_id = (int) $saved['entry_id'];

		$this->assertWPError( jlife_challenges_get_entry( $u, $entry_id ), 'private entry is the author only' );
		$this->assertFalse( jlife_challenges_can_read_entry( $u, jlife_challenges_get_entry( $member, $entry_id ) ) );
		$this->assertFalse( jlife_challenges_can_read_progress( $u, $member ) );
		$this->assertFalse( jlife_challenges_can_read_counts( $u ) );
		$this->assertTrue( jlife_challenges_can_read_progress( $member, $member ), 'the member still sees their own' );

		// Same visibility as any member: the group's feed holds only what members shared.
		$this->assertSame( array(), jlife_challenges_group_feed( $u, $r['id'] ), 'nothing shared yet, so nothing to read' );
		$this->assertIsInt( jlife_challenges_share( $member, $entry_id, $r['id'] ) );
		$feed = jlife_challenges_group_feed( $u, $r['id'] );
		$this->assertCount( 1, $feed );
		$this->assertSame( array_column( jlife_challenges_group_feed( $member, $r['id'] ), 'body' ), array_column( $feed, 'body' ), 'a starter sees exactly what a member sees' );

		// Not another group's feed either.
		$this->assertWPError( jlife_challenges_group_feed( $u, $this->g['g1'] ) );
	}

	/**
	 * A starter reads no more than a member of the same group: the group view is the same data.
	 */
	public function test_the_group_view_is_the_same_for_starter_and_member() {
		$u = $this->adult();
		$r = $this->start( $u );
		jlife_challenges_join_with_code( $this->u['outsider'], $r['invite_code'], 'adult' );
		$sv = jlife_challenges_group_view( $u, (int) $r['id'] );
		$mv = jlife_challenges_group_view( $this->u['outsider'], (int) $r['id'] );
		$this->assertSame( 'ok', $sv['state'] );
		$this->assertSame( $mv['posts'], $sv['posts'] );
		$this->assertSame( $mv['group_name'], $sv['group_name'] );
	}

	/**
	 * A disabled group's code stops working, its members lose the feed, and the slot is freed.
	 */
	public function test_a_disabled_groups_code_stops_working() {
		$u = $this->adult();
		$r = $this->start( $u );
		jlife_challenges_join_with_code( $this->u['outsider'], $r['invite_code'], 'adult' );
		$code = jlife_challenges_reset_invite( $u, $r['id'] );
		$this->assertNotNull( jlife_challenges_group_for_invite( $code ) );

		$this->assertWPError( jlife_challenges_disable_group( $u, $r['id'] ), 'a starter cannot disable' );
		$this->assertTrue( jlife_challenges_disable_group( $this->u['organizer'], $r['id'] ) );
		$this->assertNull( jlife_challenges_group_for_invite( $code ) );
		$joiner = $this->adult( 'Late' );
		$e      = jlife_challenges_join_with_code( $joiner, $code, 'adult' );
		$this->assertWPError( $e );
		$this->assertSame( 'jlife_invite_invalid', $e->get_error_code() );
		$this->assertFalse( jlife_challenges_can_read_feed( $u, $r['id'] ) );
		$this->assertFalse( jlife_challenges_can_read_feed( $this->u['outsider'], $r['id'] ) );
		$this->assertSame( array(), $this->groups_of( $u ), 'the slot is free again' );
		$this->assertContains( 'group_disabled', $this->audit_actions( $r['id'] ) );

		$this->assertWPError( jlife_challenges_enable_group( $u, $r['id'] ), 'a starter cannot enable' );
		$this->assertTrue( jlife_challenges_enable_group( $this->u['organizer'], $r['id'] ) );
		$this->assertTrue( jlife_challenges_can_read_feed( $u, $r['id'] ) );
	}

	/**
	 * Enabling a group must not push a member over the 3-group limit.
	 */
	public function test_enabling_a_group_never_exceeds_the_member_limit() {
		$u = $this->adult();
		$r = $this->start( $u );
		$this->assertTrue( jlife_challenges_disable_group( $this->u['organizer'], $r['id'] ) );
		foreach ( array( 'X', 'Y', 'Z' ) as $name ) {
			$g = jlife_challenges_create_group( $this->u['organizer'], $this->campaign, $name );
			$this->assertIsInt( jlife_challenges_join_with_code( $u, $g['invite_code'], 'adult' ) );
		}
		$e = jlife_challenges_enable_group( $this->u['organizer'], $r['id'] );
		$this->assertWPError( $e );
		$this->assertSame( 'jlife_group_limit', $e->get_error_code() );
		$this->assertNull( jlife_challenges_group_for_invite( $r['invite_code'] ) );
	}

	/**
	 * Leaving removes feed access and frees the slot; what was shared stays with the group (D18).
	 */
	public function test_leaving_removes_feed_access_but_keeps_past_shares_with_the_group() {
		$member = $this->u['a'];
		$entry  = jlife_challenges_submit( $member, $this->campaign, 'd001', $this->soap( 'left-behind' ) )['entry_id'];
		$this->assertIsInt( jlife_challenges_share( $member, $entry, $this->g['g1'] ) );
		$this->assertCount( 1, jlife_challenges_group_feed( $this->u['b'], $this->g['g1'] ) );

		$this->assertTrue( jlife_challenges_leave_group( $member, $this->g['g1'] ) );
		$this->assertFalse( jlife_challenges_can_read_feed( $member, $this->g['g1'] ) );
		$this->assertWPError( jlife_challenges_group_feed( $member, $this->g['g1'] ) );
		$this->assertSame( array(), $this->groups_of( $member ) );
		$this->assertWPError( jlife_challenges_share( $member, $entry, $this->g['g1'] ), 'cannot post to a group they left' );

		$feed = jlife_challenges_group_feed( $this->u['b'], $this->g['g1'] );
		$this->assertCount( 1, $feed, 'the past share stays visible to the group' );
		$this->assertContains( 'group_left', $this->audit_actions_for_enrollment( $member ) );
	}

	/**
	 * Audit actions recorded against a user's enrollment.
	 *
	 * @param int $user_id User.
	 * @return string[]
	 */
	private function audit_actions_for_enrollment( $user_id ) {
		global $wpdb;
		$e = jlife_challenges_get_enrollment( $this->campaign, $user_id );
		return $wpdb->get_col( $wpdb->prepare( 'SELECT action FROM ' . jlife_challenges_table( 'audit_log' ) . " WHERE object_type = 'enrollment' AND object_id = %d ORDER BY id", (int) $e['id'] ) ); // phpcs:ignore WordPress.DB
	}

	/**
	 * An author can see and remove an old share in a group they left; nobody else can.
	 */
	public function test_an_author_can_remove_an_old_share_in_a_left_group() {
		$member = $this->u['a'];
		$entry  = jlife_challenges_submit( $member, $this->campaign, 'd001', $this->soap( 'old-share' ) )['entry_id'];
		$share  = jlife_challenges_share( $member, $entry, $this->g['g1'] );
		$this->assertSame( array(), jlife_challenges_left_group_shares( $member, $this->campaign ), 'still in the group' );

		jlife_challenges_leave_group( $member, $this->g['g1'] );
		$old = jlife_challenges_left_group_shares( $member, $this->campaign );
		$this->assertCount( 1, $old );
		$this->assertSame( (int) $share, (int) $old[0]['id'] );
		$this->assertSame( 'Group One', $old[0]['group_name'], 'the author may see the name of a group they were in' );

		$this->assertWPError( jlife_challenges_unshare( $this->u['b'], $share ), 'another member cannot remove it' );
		$this->assertWPError( jlife_challenges_unshare( $this->u['outsider'], $share ) );
		$this->assertTrue( jlife_challenges_unshare( $member, $share ) );
		$this->assertSame( array(), jlife_challenges_group_feed( $this->u['b'], $this->g['g1'] ) );
		$this->assertSame( array(), jlife_challenges_left_group_shares( $member, $this->campaign ), 'nothing left to remove' );
	}

	/**
	 * Shares in groups that are still active are never listed as "left", and other people's never are.
	 */
	public function test_left_group_shares_list_only_the_authors_own_old_shares() {
		$entry_a = jlife_challenges_submit( $this->u['a'], $this->campaign, 'd001', $this->soap( 'a' ) )['entry_id'];
		$entry_b = jlife_challenges_submit( $this->u['b'], $this->campaign, 'd001', $this->soap( 'b' ) )['entry_id'];
		jlife_challenges_share( $this->u['a'], $entry_a, $this->g['g1'] );
		jlife_challenges_share( $this->u['b'], $entry_b, $this->g['g1'] );
		jlife_challenges_leave_group( $this->u['a'], $this->g['g1'] );

		$this->assertCount( 1, jlife_challenges_left_group_shares( $this->u['a'], $this->campaign ) );
		$this->assertSame( array(), jlife_challenges_left_group_shares( $this->u['b'], $this->campaign ), 'b is still in the group' );
		$this->assertSame( array(), jlife_challenges_left_group_shares( $this->u['outsider'], $this->campaign ) );
	}

	/**
	 * A group's name reaches its members and organizers only.
	 */
	public function test_the_group_name_is_visible_only_to_members_and_organizers() {
		$u = $this->adult();
		$r = $this->start( $u, 'Quiet Prayer Circle' );

		// A participant of another group asking for it by ID: denied, name never in the page.
		$view = jlife_challenges_group_view( $this->u['c'], (int) $r['id'] );
		$this->assertSame( 'denied', $view['state'] );
		$html = jlife_challenges_group_body( $view );
		$this->assertStringNotContainsString( 'Quiet Prayer Circle', $html );
		$this->assertStringNotContainsString( 'Quiet Prayer Circle', (string) wp_json_encode( $view ) );

		// Their own share destinations never include it.
		$this->assertSame( array( 'Group Two' ), array_column( jlife_challenges_share_groups( $this->u['c'], $this->campaign ), 'name' ) );

		// Members see it; organizers list it.
		$this->assertSame( 'Quiet Prayer Circle', jlife_challenges_group_view( $u, (int) $r['id'] )['group_name'] );
		$names = array_column( jlife_challenges_list_groups( $this->u['organizer'], $this->campaign ), 'name' );
		$this->assertContains( 'Quiet Prayer Circle', $names );
		$this->assertWPError( jlife_challenges_list_groups( $u, $this->campaign ), 'not even the starter lists groups' );
		$this->assertWPError( jlife_challenges_list_groups( 0, $this->campaign ) );
	}

	/**
	 * Organizers can rename and disable any group, starter-made ones included; nobody else can.
	 */
	public function test_organizers_can_rename_and_disable_any_group() {
		$u = $this->adult();
		$r = $this->start( $u, 'Before' );
		$this->assertWPError( jlife_challenges_rename_group( $u, $r['id'], 'Hijack' ), 'the starter cannot rename through the organizer path' );
		$this->assertWPError( jlife_challenges_rename_group( $this->u['a'], $r['id'], 'Hijack' ) );
		$this->assertSame( 'jlife_group_name_invalid', jlife_challenges_rename_group( $this->u['organizer'], $r['id'], str_repeat( 'x', 61 ) )->get_error_code() );

		$this->assertTrue( jlife_challenges_rename_group( $this->u['organizer'], $r['id'], 'After' ) );
		$this->assertSame( 'After', jlife_challenges_get_group( $r['id'] )['name'] );
		$this->assertTrue( jlife_challenges_rename_group( $this->u['organizer'], $this->g['g1'], 'Renamed One' ), 'organizer-made groups too' );
		$this->assertTrue( jlife_challenges_disable_group( $this->u['organizer'], $this->g['g2'] ) );
		$this->assertContains( 'group_renamed', $this->audit_actions( $r['id'] ) );

		$rows = array_column( jlife_challenges_list_groups( $this->u['organizer'], $this->campaign ), null, 'id' );
		$this->assertSame( 'disabled', $rows[ $this->g['g2'] ]['status'] );
		$this->assertSame( 'Renamed One', $rows[ $this->g['g1'] ]['name'] );
		$this->assertSame( $u, (int) $rows[ $r['id'] ]['started_by'] );
		$this->assertSame( 2, (int) $rows[ $this->g['g1'] ]['members'] );
		$this->assertArrayNotHasKey( 'invite_hash', $rows[ $r['id'] ], 'the list never carries the code hash' );
	}

	/**
	 * Disabling and listing refuse unknown groups the same way as forbidden ones.
	 */
	public function test_unknown_groups_are_denied() {
		$this->assertSame( 'jlife_denied', jlife_challenges_disable_group( $this->u['organizer'], 999999 )->get_error_code() );
		$this->assertSame( 'jlife_denied', jlife_challenges_rename_group( $this->u['organizer'], 999999, 'x' )->get_error_code() );
		$this->assertSame( 'jlife_denied', jlife_challenges_reset_invite( $this->u['organizer'], 999999 )->get_error_code() );
	}

	/**
	 * The group page offers "Start a group" to adults with a free slot and never to minors.
	 */
	public function test_the_start_form_is_hidden_from_minors_and_full_members() {
		$adult = $this->adult();
		$minor = $this->minor();
		$full  = $this->adult( 'Full' );
		foreach ( array( $this->g['g1'], $this->g['g2'] ) as $gid ) {
			jlife_challenges_join_with_code( $full, jlife_challenges_reset_invite( $this->u['organizer'], $gid ), 'adult' );
		}
		$this->start( $full, 'Full third' );

		$html = jlife_challenges_group_body( jlife_challenges_group_view( $adult ) );
		$this->assertStringContainsString( 'name="jl_group_name"', $html, 'an adult sees the form' );
		$this->assertStringContainsString( 'value="start"', $html );

		$html = jlife_challenges_group_body( jlife_challenges_group_view( $minor ) );
		$this->assertStringNotContainsString( 'jl_group_name', $html, 'no form for a minor' );
		$this->assertStringContainsString( 'name="jl_invite_code"', $html, 'a minor may still join with a code' );

		$html = jlife_challenges_group_body( jlife_challenges_group_view( $full ) );
		$this->assertStringNotContainsString( 'jl_group_name', $html, 'no free slot, no form' );
	}

	/**
	 * Only a group's starter sees the reset button on /group; every member sees Leave.
	 */
	public function test_reset_is_offered_to_the_starter_only_and_leave_to_every_member() {
		$u = $this->adult();
		$r = $this->start( $u );
		jlife_challenges_join_with_code( $this->u['outsider'], $r['invite_code'], 'adult' );

		$starter = jlife_challenges_group_body( jlife_challenges_group_view( $u, (int) $r['id'] ) );
		$member  = jlife_challenges_group_body( jlife_challenges_group_view( $this->u['outsider'], (int) $r['id'] ) );
		$this->assertStringContainsString( 'confirm=reset', $starter );
		$this->assertStringNotContainsString( 'confirm=reset', $member );
		$this->assertStringContainsString( 'confirm=leave', $starter );
		$this->assertStringContainsString( 'confirm=leave', $member );
	}
}
