<?php
/**
 * Group membership (#112, D14 changed): a participant is solo or in up to 3
 * groups per campaign; the enrollment stays one per person per campaign.
 * Access-control and limit tests for the rows in docs/challenges-engine-design.md
 * §4 and §4b. They were written before the feature.
 *
 * @package jlife-challenges
 */

/**
 * Membership tests.
 */
class Test_Memberships extends Jlife_Challenges_TestCase {

	/**
	 * Create a group and return { id, invite_code }.
	 *
	 * @param string $name Group name.
	 * @return array
	 */
	private function new_group( $name ) {
		$g = jlife_challenges_create_group( $this->u['organizer'], $this->campaign, $name );
		$this->assertIsArray( $g );
		return $g;
	}

	/**
	 * IDs of a user's active groups in this campaign, in join order.
	 *
	 * @param string $who Fixture user.
	 * @return int[]
	 */
	private function groups_of( $who ) {
		return array_map( 'intval', array_column( jlife_challenges_active_memberships( $this->campaign, $this->u[ $who ] ), 'group_id' ) );
	}

	/**
	 * Count rows of a table for a user in this campaign.
	 *
	 * @param string $table Short table name.
	 * @param int    $user  User ID.
	 * @return int
	 */
	private function rows( $table, $user ) {
		global $wpdb;
		return (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . jlife_challenges_table( $table ) . ' WHERE user_id = %d AND campaign_id = %d', $user, $this->campaign ) ); // phpcs:ignore WordPress.DB
	}

	/**
	 * Enrolling still gives one enrollment and one active membership.
	 */
	public function test_enroll_creates_one_enrollment_and_one_membership() {
		$this->assertSame( array( (int) $this->g['g1'] ), $this->groups_of( 'a' ) );
		$m = jlife_challenges_active_memberships( $this->campaign, $this->u['a'] )[0];
		$this->assertSame( 'member', $m['role'] );
		$this->assertSame( 'Group One', $m['name'] );
		$this->assertSame( 1, $this->rows( 'enrollments', $this->u['a'] ) );
		$this->assertTrue( jlife_challenges_is_group_member( $this->u['a'], $this->g['g1'] ) );
		$this->assertFalse( jlife_challenges_is_group_member( $this->u['a'], $this->g['g2'] ) );
		$this->assertFalse( jlife_challenges_is_group_member( $this->u['outsider'], $this->g['g1'] ) );
		$this->assertFalse( jlife_challenges_is_group_member( 0, $this->g['g1'] ) );
	}

	/**
	 * Up to three groups per campaign; the enrollment stays one; the fourth is refused
	 * and leaves the first three untouched.
	 */
	public function test_up_to_three_groups_and_a_fourth_is_refused() {
		$g3 = $this->new_group( 'Group Three' );
		$g4 = $this->new_group( 'Group Four' );

		$this->assertIsInt( jlife_challenges_join_with_code( $this->u['a'], jlife_challenges_reset_invite( $this->u['organizer'], $this->g['g2'] ), 'adult' ) );
		$this->assertIsInt( jlife_challenges_join_with_code( $this->u['a'], $g3['invite_code'], 'adult' ) );
		$this->assertCount( 3, $this->groups_of( 'a' ) );

		$fourth = jlife_challenges_join_with_code( $this->u['a'], $g4['invite_code'], 'adult' );
		$this->assertWPError( $fourth );
		$this->assertSame( 'jlife_group_limit', $fourth->get_error_code() );
		$this->assertSame( array( (int) $this->g['g1'], (int) $this->g['g2'], (int) $g3['id'] ), $this->groups_of( 'a' ), 'the first three are untouched' );
		$this->assertFalse( jlife_challenges_is_group_member( $this->u['a'], $g4['id'] ) );
		$this->assertSame( 1, $this->rows( 'enrollments', $this->u['a'] ), 'still one enrollment' );
	}

	/**
	 * The limit holds for organizers enrolling people too, and for minors, who may also join three.
	 */
	public function test_limit_applies_to_organizer_enrollment_and_minors_may_join_three() {
		$g3    = $this->new_group( 'Group Three' );
		$g4    = $this->new_group( 'Group Four' );
		$minor = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		$this->assertIsInt( jlife_challenges_enroll( $this->u['organizer'], $minor, $this->g['g1'], 'minor' ) );
		$this->assertIsInt( jlife_challenges_enroll( $this->u['organizer'], $minor, $this->g['g2'], 'minor' ) );
		$this->assertIsInt( jlife_challenges_enroll( $this->u['organizer'], $minor, $g3['id'], 'minor' ) );
		$this->assertSame( 'jlife_group_limit', jlife_challenges_enroll( $this->u['organizer'], $minor, $g4['id'], 'minor' )->get_error_code() );
		$this->assertSame( 'jlife_group_limit', jlife_challenges_join_with_code( $minor, $g4['invite_code'], 'minor' )->get_error_code() );
		$this->assertCount( 3, jlife_challenges_active_memberships( $this->campaign, $minor ) );
		$this->assertSame( 'minor', jlife_challenges_get_enrollment( $this->campaign, $minor )['age_band'] );
	}

	/**
	 * Joining the same group twice is refused, with no duplicate row.
	 */
	public function test_already_a_member_is_refused() {
		$code  = jlife_challenges_reset_invite( $this->u['organizer'], $this->g['g1'] );
		$again = jlife_challenges_join_with_code( $this->u['a'], $code, 'adult' );
		$this->assertSame( 'jlife_already_enrolled', $again->get_error_code() );
		$this->assertCount( 1, $this->groups_of( 'a' ) );
		$this->assertSame( 'jlife_already_enrolled', jlife_challenges_enroll( $this->u['organizer'], $this->u['a'], $this->g['g1'] )->get_error_code() );
	}

	/**
	 * A later join keeps the enrollment's age band and consent, and is audited (D18).
	 */
	public function test_later_join_keeps_enrollment_and_is_audited() {
		$this->assertTrue( jlife_challenges_record_consent( $this->u['a'], $this->campaign, 'v-test' ) );
		$code = jlife_challenges_reset_invite( $this->u['organizer'], $this->g['g2'] );
		$this->assertIsInt( jlife_challenges_join_with_code( $this->u['a'], $code, 'minor' ), 'the typed age band does not rewrite an existing enrollment' );
		$e = jlife_challenges_get_enrollment( $this->campaign, $this->u['a'] );
		$this->assertSame( 'adult', $e['age_band'] );
		$this->assertSame( 'v-test', $e['consent_version'] );

		global $wpdb;
		$rows = $wpdb->get_results( 'SELECT actor_user_id, detail FROM ' . jlife_challenges_table( 'audit_log' ) . " WHERE action = 'group_joined' ORDER BY id", ARRAY_A ); // phpcs:ignore WordPress.DB
		$last = end( $rows );
		$this->assertSame( $this->u['a'], (int) $last['actor_user_id'] );
		$detail = json_decode( (string) $last['detail'], true );
		$this->assertSame( (int) $this->g['g2'], $detail['group_id'] );
		$this->assertSame( 'member', $detail['role'] );
	}

	/**
	 * Leaving frees a slot, ends access to that group's feed, and is audited.
	 */
	public function test_leave_group_frees_a_slot() {
		$g3 = $this->new_group( 'Group Three' );
		$g4 = $this->new_group( 'Group Four' );
		jlife_challenges_enroll( $this->u['organizer'], $this->u['a'], $this->g['g2'] );
		jlife_challenges_enroll( $this->u['organizer'], $this->u['a'], $g3['id'] );
		$this->assertWPError( jlife_challenges_join_with_code( $this->u['a'], $g4['invite_code'], 'adult' ) );

		$this->assertWPError( jlife_challenges_leave_group( $this->u['b'], $this->g['g2'] ), 'a non-member cannot "leave"' );
		$this->assertTrue( jlife_challenges_leave_group( $this->u['a'], $this->g['g2'] ) );
		$this->assertFalse( jlife_challenges_can_read_feed( $this->u['a'], $this->g['g2'] ), 'the old feed is gone' );
		$this->assertSame( array( (int) $this->g['g1'], (int) $g3['id'] ), $this->groups_of( 'a' ) );
		$this->assertIsInt( jlife_challenges_join_with_code( $this->u['a'], $g4['invite_code'], 'adult' ), 'a slot is free again' );

		global $wpdb;
		$this->assertSame( 1, (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . jlife_challenges_table( 'audit_log' ) . " WHERE action = 'group_left'" ) ); // phpcs:ignore WordPress.DB
	}

	/**
	 * A person who left can join the same group again with its code.
	 */
	public function test_rejoin_after_leaving() {
		$this->assertTrue( jlife_challenges_leave_group( $this->u['a'], $this->g['g1'] ) );
		$this->assertSame( array(), $this->groups_of( 'a' ) );
		$code = jlife_challenges_reset_invite( $this->u['organizer'], $this->g['g1'] );
		$this->assertIsInt( jlife_challenges_join_with_code( $this->u['a'], $code, 'adult' ) );
		$this->assertSame( array( (int) $this->g['g1'] ), $this->groups_of( 'a' ) );
		$this->assertSame( 1, $this->rows( 'group_members', $this->u['a'] ), 'the same row is reactivated' );
	}

	/**
	 * "Read on my own": an enrollment with no membership; consent and age band as for any join.
	 */
	public function test_solo_enrollment_has_no_group() {
		$id = jlife_challenges_enroll_solo( $this->u['outsider'], $this->campaign, 'minor' );
		$this->assertIsInt( $id );
		$e = jlife_challenges_get_enrollment( $this->campaign, $this->u['outsider'] );
		$this->assertSame( 'active', $e['status'] );
		$this->assertSame( 'minor', $e['age_band'] );
		$this->assertSame( array(), jlife_challenges_active_memberships( $this->campaign, $this->u['outsider'] ) );
		$this->assertSame( 0, $this->rows( 'group_members', $this->u['outsider'] ) );
		$this->assertTrue( jlife_challenges_record_consent( $this->u['outsider'], $this->campaign, 'v-test' ), 'consent is recorded on the solo enrollment' );

		$this->assertSame( $id, jlife_challenges_enroll_solo( $this->u['outsider'], $this->campaign, 'adult' ), 'idempotent: the same enrollment' );
		$this->assertSame( 'minor', jlife_challenges_get_enrollment( $this->campaign, $this->u['outsider'] )['age_band'] );

		$this->assertSame( 'jlife_age_band', jlife_challenges_enroll_solo( $this->u['c'], $this->campaign + 999, 'child' )->get_error_code() );
		$this->assertSame( 'jlife_denied', jlife_challenges_enroll_solo( 0, $this->campaign, 'adult' )->get_error_code() );
		$this->assertSame( 'jlife_denied', jlife_challenges_enroll_solo( $this->u['outsider'], 999999, 'adult' )->get_error_code(), 'unknown campaign' );

		// A solo reader still writes and scores like anyone else.
		$r = jlife_challenges_submit( $this->u['outsider'], $this->campaign, null, $this->soap( 'solo' ) );
		$this->assertSame( 1, $r['points'] );
	}

	/**
	 * A solo reader can join a group later; the enrollment (and any entries) carry over, private.
	 */
	public function test_solo_reader_joins_a_group_later_and_old_entries_stay_private() {
		$solo = $this->u['outsider'];
		jlife_challenges_enroll_solo( $solo, $this->campaign, 'adult' );
		$entry = jlife_challenges_submit( $solo, $this->campaign, null, $this->soap( 'before' ) )['entry_id'];

		$code = jlife_challenges_reset_invite( $this->u['organizer'], $this->g['g1'] );
		$this->assertIsInt( jlife_challenges_join_with_code( $solo, $code, 'adult' ) );
		$this->assertSame( 1, $this->rows( 'enrollments', $solo ) );
		$this->assertSame( array( (int) $this->g['g1'] ), $this->groups_of( 'outsider' ) );

		$this->assertSame( array(), jlife_challenges_group_feed( $this->u['a'], $this->g['g1'] ), 'saved before joining: not shared' );
		$this->assertIsArray( jlife_challenges_get_entry( $solo, $entry ) );
		$this->assertWPError( jlife_challenges_get_entry( $this->u['a'], $entry ), 'still private' );
		$this->assertSame( 1, jlife_challenges_progress( $solo, $solo, $this->campaign )['points'], 'points are unchanged by joining' );
	}

	/**
	 * The enrollment is one per person per campaign: removing it ends every membership.
	 */
	public function test_remove_member_ends_all_memberships() {
		jlife_challenges_enroll( $this->u['organizer'], $this->u['a'], $this->g['g2'] );
		$this->assertCount( 2, $this->groups_of( 'a' ) );
		$this->assertTrue( jlife_challenges_remove_member( $this->u['organizer'], $this->campaign, $this->u['a'] ) );
		$this->assertSame( array(), $this->groups_of( 'a' ) );
		$this->assertFalse( jlife_challenges_can_read_feed( $this->u['a'], $this->g['g1'] ) );
		$this->assertFalse( jlife_challenges_can_read_feed( $this->u['a'], $this->g['g2'] ) );
	}

	/**
	 * An organizer's move swaps one membership for another, keeps the rest, and is audited.
	 */
	public function test_move_member_swaps_one_membership() {
		$g3 = $this->new_group( 'Group Three' );
		jlife_challenges_enroll( $this->u['organizer'], $this->u['a'], $this->g['g2'] );

		$this->assertWPError( jlife_challenges_move_member( $this->u['organizer'], $this->u['a'], $g3['id'] ), 'two groups: which one moves?' );
		$this->assertWPError( jlife_challenges_move_member( $this->u['a'], $this->u['a'], $g3['id'], $this->g['g1'] ), 'organizers only' );
		$this->assertWPError( jlife_challenges_move_member( $this->u['organizer'], $this->u['a'], $g3['id'], $this->g['g1'] + 999 ), 'not from a group they are not in' );
		$this->assertTrue( jlife_challenges_move_member( $this->u['organizer'], $this->u['a'], $g3['id'], $this->g['g1'] ) );
		$this->assertSame( array( (int) $this->g['g2'], (int) $g3['id'] ), $this->groups_of( 'a' ) );

		global $wpdb;
		$detail = json_decode( (string) $wpdb->get_var( 'SELECT detail FROM ' . jlife_challenges_table( 'audit_log' ) . " WHERE action = 'member_moved' ORDER BY id DESC LIMIT 1" ), true ); // phpcs:ignore WordPress.DB
		$this->assertSame( (int) $this->g['g1'], $detail['from_group'] );
		$this->assertSame( (int) $g3['id'], $detail['to_group'] );
	}

	/**
	 * The shared membership gate answers from membership rows, for every group a person is in.
	 */
	public function test_membership_source_lists_members_of_each_group() {
		jlife_challenges_enroll( $this->u['organizer'], $this->u['a'], $this->g['g2'] );
		$m2 = apply_filters( 'jlife_huddles_group_membership', null, $this->g['g2'] );
		$this->assertEqualsCanonicalizing( array( $this->u['c'], $this->u['a'] ), $m2['member_user_ids'] );
		$m1 = apply_filters( 'jlife_huddles_group_membership', null, $this->g['g1'] );
		$this->assertEqualsCanonicalizing( array( $this->u['a'], $this->u['b'] ), $m1['member_user_ids'] );
		jlife_challenges_leave_group( $this->u['a'], $this->g['g1'] );
		$m1 = apply_filters( 'jlife_huddles_group_membership', null, $this->g['g1'] );
		$this->assertSame( array( $this->u['b'] ), $m1['member_user_ids'], 'a person who left is no longer a member' );
	}

	/**
	 * The gate never trusts the retired enrollments.group_id column.
	 */
	public function test_gate_ignores_the_legacy_enrollment_group_column() {
		global $wpdb;
		jlife_challenges_enroll_solo( $this->u['outsider'], $this->campaign, 'adult' );
		$wpdb->update( jlife_challenges_table( 'enrollments' ), array( 'group_id' => (int) $this->g['g1'] ), array( 'user_id' => $this->u['outsider'] ) );
		$this->assertFalse( jlife_challenges_can_read_feed( $this->u['outsider'], $this->g['g1'] ) );
		$this->assertWPError( jlife_challenges_group_feed( $this->u['outsider'], $this->g['g1'] ) );
		$this->assertNotContains( $this->u['outsider'], apply_filters( 'jlife_huddles_group_membership', null, $this->g['g1'] )['member_user_ids'] );
	}

	/**
	 * Guessing codes while signed in is rate limited.
	 */
	public function test_wrong_codes_are_rate_limited() {
		for ( $i = 0; $i < 10; $i++ ) {
			$this->assertSame( 'jlife_invite_invalid', jlife_challenges_join_with_code( $this->u['outsider'], 'WRONG00' . $i, 'adult' )->get_error_code() );
		}
		$code = jlife_challenges_reset_invite( $this->u['organizer'], $this->g['g1'] );
		$this->assertSame( 'jlife_join_rate', jlife_challenges_join_with_code( $this->u['outsider'], $code, 'adult' )->get_error_code(), 'even the right code waits' );
		$this->assertSame( array(), $this->groups_of( 'outsider' ) );
	}

	/**
	 * Migration: existing single-group enrollments become membership rows. Idempotent,
	 * never resurrects a left membership, and carries removed enrollments as removed.
	 */
	public function test_migration_of_single_group_enrollments() {
		global $wpdb;
		$e    = jlife_challenges_table( 'enrollments' );
		$m    = jlife_challenges_table( 'group_members' );
		$old  = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		$gone = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		$solo = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		$base = array(
			'campaign_id' => $this->campaign,
			'age_band'    => 'adult',
			'joined'      => '2026-12-01 08:00:00',
		);
		// Rows as the previous version wrote them: the group lives on the enrollment.
		$wpdb->insert(
			$e,
			array_merge(
				$base,
				array(
					'user_id'  => $old,
					'group_id' => (int) $this->g['g2'],
					'status'   => 'active',
				)
			)
		);
		$old_enrollment = (int) $wpdb->insert_id;
		$wpdb->insert(
			$e,
			array_merge(
				$base,
				array(
					'user_id'  => $gone,
					'group_id' => (int) $this->g['g1'],
					'status'   => 'removed',
					'left_at'  => '2026-12-05 09:00:00',
				)
			)
		);
		$wpdb->insert(
			$e,
			array_merge(
				$base,
				array(
					'user_id'  => $solo,
					'group_id' => 0,
					'status'   => 'active',
				)
			)
		);

		$before = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$m}" ); // phpcs:ignore WordPress.DB
		$this->assertSame( 2, jlife_challenges_upgrade_memberships(), 'a solo enrollment (group 0) migrates nothing' );
		$this->assertSame( $before + 2, (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$m}" ) ); // phpcs:ignore WordPress.DB

		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$m} WHERE user_id = %d", $old ), ARRAY_A ); // phpcs:ignore WordPress.DB
		$this->assertSame( (int) $this->g['g2'], (int) $row['group_id'] );
		$this->assertSame( $old_enrollment, (int) $row['enrollment_id'] );
		$this->assertSame( $this->campaign, (int) $row['campaign_id'] );
		$this->assertSame( 'member', $row['role'] );
		$this->assertSame( 'active', $row['status'] );
		$this->assertSame( '2026-12-01 08:00:00', $row['joined_at'] );
		$this->assertNull( $row['left_at'] );
		$this->assertTrue( jlife_challenges_can_read_feed( $old, $this->g['g2'] ), 'migrated members keep their feed' );
		$this->assertFalse( jlife_challenges_can_read_feed( $old, $this->g['g1'] ) );

		$removed = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$m} WHERE user_id = %d", $gone ), ARRAY_A ); // phpcs:ignore WordPress.DB
		$this->assertSame( 'removed', $removed['status'] );
		$this->assertSame( '2026-12-05 09:00:00', $removed['left_at'] );
		$this->assertFalse( jlife_challenges_can_read_feed( $gone, $this->g['g1'] ) );
		$this->assertSame( array(), jlife_challenges_active_memberships( $this->campaign, $solo ) );

		// Idempotent, and a later leave is not undone by running the upgrade again.
		$this->assertSame( 0, jlife_challenges_upgrade_memberships() );
		$this->assertTrue( jlife_challenges_leave_group( $old, $this->g['g2'] ) );
		jlife_challenges_install_tables();
		$this->assertSame( 0, jlife_challenges_upgrade_memberships() );
		$this->assertSame( array(), jlife_challenges_active_memberships( $this->campaign, $old ), 'the upgrade never resurrects a left membership' );
		$this->assertSame( $before + 2, (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$m}" ) ); // phpcs:ignore WordPress.DB
	}

	/**
	 * The installer itself (run on every deploy) migrates and stays idempotent.
	 */
	public function test_installer_runs_the_migration() {
		global $wpdb;
		$user = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		$wpdb->insert(
			jlife_challenges_table( 'enrollments' ),
			array(
				'campaign_id' => $this->campaign,
				'user_id'     => $user,
				'group_id'    => (int) $this->g['g1'],
				'status'      => 'active',
				'age_band'    => 'adult',
				'joined'      => '2026-12-01 08:00:00',
			)
		);
		jlife_challenges_install_tables();
		$this->assertSame( array( (int) $this->g['g1'] ), array_map( 'intval', array_column( jlife_challenges_active_memberships( $this->campaign, $user ), 'group_id' ) ) );
		jlife_challenges_install_tables();
		$this->assertCount( 1, jlife_challenges_active_memberships( $this->campaign, $user ) );
	}

	/**
	 * Deleting an account removes its memberships with the enrollment.
	 */
	public function test_account_deletion_purges_memberships() {
		require_once ABSPATH . 'wp-admin/includes/user.php';
		jlife_challenges_enroll( $this->u['organizer'], $this->u['a'], $this->g['g2'] );
		$user = $this->u['a'];
		wp_delete_user( $user );
		$this->assertSame( 0, $this->rows( 'group_members', $user ) );
		$this->assertSame( array( $this->u['b'] ), apply_filters( 'jlife_huddles_group_membership', null, $this->g['g1'] )['member_user_ids'] );
	}

	/**
	 * The test-campaign create/remove command still cleans up, memberships included, and
	 * a real campaign's memberships are not touched.
	 */
	public function test_test_campaign_removal_cleans_up_memberships() {
		global $wpdb;
		$this->set_now( '2026-11-05 20:00:00' );
		$r      = jlife_challenges_create_test_campaign( $this->u['organizer'] );
		$tester = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		$solo   = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		$this->assertIsInt( jlife_challenges_join_with_code( $tester, $r['invite_code'], 'adult' ) );
		$this->assertIsInt( jlife_challenges_join_with_code( $this->u['a'], $r['invite_code'], 'adult' ) ); // Also in the real campaign.
		$this->assertIsInt( jlife_challenges_enroll_solo( $solo, $r['campaign_id'], 'adult' ) );
		$this->assertSame( 2, (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . jlife_challenges_table( 'group_members' ) . ' WHERE campaign_id = %d', $r['campaign_id'] ) ) ); // phpcs:ignore WordPress.DB

		$out = jlife_challenges_remove_test_campaigns( $this->u['organizer'] );
		$this->assertSame( 1, $out['campaigns'] );
		$this->assertSame( 2, $out['users'], 'the tester and the solo reader were test-only accounts' );
		$this->assertSame( 0, (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . jlife_challenges_table( 'group_members' ) . ' WHERE campaign_id = %d', $r['campaign_id'] ) ) ); // phpcs:ignore WordPress.DB
		$this->assertSame( array( (int) $this->g['g1'] ), $this->groups_of( 'a' ), 'real memberships stay' );
		$this->assertTrue( jlife_challenges_can_read_feed( $this->u['b'], $this->g['g1'] ) );
	}

	/**
	 * Scoring never depends on groups: solo, one group and three groups earn the same point.
	 */
	public function test_points_do_not_depend_on_groups() {
		$g3   = $this->new_group( 'Group Three' );
		$solo = $this->u['outsider'];
		jlife_challenges_enroll_solo( $solo, $this->campaign, 'adult' );
		jlife_challenges_enroll( $this->u['organizer'], $this->u['a'], $this->g['g2'] );
		jlife_challenges_enroll( $this->u['organizer'], $this->u['a'], $g3['id'] );

		foreach ( array( $solo, $this->u['a'], $this->u['c'] ) as $user ) {
			$this->assertSame( 1, jlife_challenges_submit( $user, $this->campaign, null, $this->soap( 'p' ) )['points'] );
			$this->assertSame( 1, jlife_challenges_progress( $user, $user, $this->campaign )['points'] );
			$this->assertSame( 1, $this->rows( 'score_ledger', $user ) );
		}
	}

	/**
	 * The export names every active group (one participant-day per row), and counts stay per group.
	 */
	public function test_export_and_counts_with_several_groups_and_solo() {
		jlife_challenges_enroll( $this->u['organizer'], $this->u['a'], $this->g['g2'] );
		jlife_challenges_enroll_solo( $this->u['outsider'], $this->campaign, 'adult' );
		jlife_challenges_submit( $this->u['a'], $this->campaign, null, $this->soap() );
		jlife_challenges_submit( $this->u['outsider'], $this->campaign, null, $this->soap() );

		$rows = jlife_challenges_export_scores( $this->u['organizer'], $this->campaign );
		$this->assertCount( 2, $rows, 'one row per participant and day, however many groups' );
		$by = array_column( $rows, 'group', 'user_id' );
		$this->assertSame( 'Group One; Group Two', $by[ $this->u['a'] ] );
		$this->assertSame( '', $by[ $this->u['outsider'] ], 'solo: no group' );

		$counts = jlife_challenges_day_counts( $this->u['organizer'], $this->campaign );
		$by_gid = array();
		foreach ( $counts as $c ) {
			$by_gid[ (int) $c['group_id'] ] = (int) $c['submitted'];
		}
		$this->assertSame( 1, $by_gid[ (int) $this->g['g1'] ] );
		$this->assertSame( 1, $by_gid[ (int) $this->g['g2'] ] );
		$this->assertSame( 1, $by_gid[0], 'solo readers are counted under group 0' );
	}
}
