<?php
/**
 * D19 (owner decision 2026-11-05, #55; applied to the pages in #65): with the
 * church's policy configured, which is also the default of a fresh install, the
 * per-minor guardian gate must not block a 13-17 participant from sharing with
 * their own group. Group authorization, private-by-default submission and
 * deliberate sharing all stay. An adult is never affected by the gate.
 *
 * @package jlife-challenges
 */

/**
 * D19 youth-sharing tests.
 */
class Test_Youth_Sharing extends Jlife_Challenges_TestCase {

	/**
	 * A participant in group one, with an entry that earned its point.
	 *
	 * @param string $age_band minor or adult.
	 * @return array { user_id, entry_id }
	 */
	private function participant( $age_band ) {
		$user = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		$this->assertIsInt( jlife_challenges_enroll( $this->u['organizer'], $user, $this->g['g1'], $age_band ) );
		$entry = jlife_challenges_submit( $user, $this->campaign, null, $this->soap() );
		$this->assertSame( 1, $entry['points'] );
		return array(
			'user_id'  => $user,
			'entry_id' => $entry['entry_id'],
		);
	}

	/**
	 * The enrollment row of a participant.
	 *
	 * @param int $user_id User ID.
	 * @return array
	 */
	private function enrollment( $user_id ) {
		return (array) jlife_challenges_get_enrollment( $this->campaign, $user_id );
	}

	/**
	 * Nothing is configured on a fresh install, and that is the D19 setting: the
	 * gate asks nothing of a 13-17 participant or of an adult.
	 */
	public function test_with_no_setting_the_gate_blocks_neither_a_youth_nor_an_adult() {
		delete_option( 'jlife_challenges_require_guardian_consent' );
		$teen  = $this->participant( 'minor' );
		$adult = $this->participant( 'adult' );
		$this->assertFalse( jlife_challenges_needs_guardian_consent( $this->enrollment( $teen['user_id'] ) ), '13-17' );
		$this->assertFalse( jlife_challenges_needs_guardian_consent( $this->enrollment( $adult['user_id'] ) ), 'adult' );
	}

	/**
	 * The church's D19 policy written out as the setting ('0') is the same.
	 */
	public function test_with_the_setting_off_a_youth_shares_with_their_own_group() {
		update_option( 'jlife_challenges_require_guardian_consent', '0' );
		$teen = $this->participant( 'minor' );
		$this->assertFalse( jlife_challenges_needs_guardian_consent( $this->enrollment( $teen['user_id'] ) ) );
		$this->assertIsInt( jlife_challenges_share( $teen['user_id'], $teen['entry_id'], $this->g['g1'] ), 'shares with their own group' );
		$this->assertTrue( jlife_challenges_can_read_feed( $this->u['b'], $this->g['g1'] ), 'the group reads it' );
	}

	/**
	 * An adult shares the same way under the default and when a deployment turns
	 * the guardian requirement on: the requirement is only ever about minors.
	 */
	public function test_an_adult_shares_whatever_the_setting() {
		foreach ( array( '0', '1' ) as $setting ) {
			update_option( 'jlife_challenges_require_guardian_consent', $setting );
			$adult = $this->participant( 'adult' );
			$this->assertFalse( jlife_challenges_needs_guardian_consent( $this->enrollment( $adult['user_id'] ) ), "adult, setting {$setting}" );
			$this->assertIsInt( jlife_challenges_share( $adult['user_id'], $adult['entry_id'], $this->g['g1'] ), "adult shares, setting {$setting}" );
		}
	}

	/**
	 * Sharing is still the author's choice and stays inside the author's own
	 * group: a youth's submission posts nothing, they cannot post to a group they
	 * are not in, other groups never read it, and nobody else reads the entry.
	 */
	public function test_a_youth_keeps_deliberate_group_scoped_sharing() {
		update_option( 'jlife_challenges_require_guardian_consent', '0' );
		$teen = $this->participant( 'minor' );

		$this->assertSame( array(), jlife_challenges_group_view( $this->u['b'] )['posts'], 'submitting shared nothing: private by default' );

		$denied = jlife_challenges_share( $teen['user_id'], $teen['entry_id'], $this->g['g2'] );
		$this->assertWPError( $denied, 'not a member of that group' );
		$this->assertSame( 'jlife_denied', $denied->get_error_code() );

		$this->assertIsInt( jlife_challenges_share( $teen['user_id'], $teen['entry_id'], $this->g['g1'] ) );
		$this->assertFalse( jlife_challenges_can_read_feed( $this->u['outsider'], $this->g['g1'] ), 'a non-member cannot read the group' );
		$this->assertFalse( jlife_challenges_can_read_feed( $teen['user_id'], $this->g['g2'] ), 'another group is closed to them' );
		foreach ( array( 'b', 'organizer' ) as $who ) {
			$this->assertWPError( jlife_challenges_get_entry( $this->u[ $who ], $teen['entry_id'] ), "{$who} cannot open the private entry itself" );
		}
	}

	/**
	 * Only an explicit '1' (or the filter) turns the guardian step on, so a stray
	 * value cannot silently block youth sharing again.
	 */
	public function test_only_an_explicit_opt_in_turns_the_guardian_step_on() {
		$teen = $this->participant( 'minor' );
		$row  = $this->enrollment( $teen['user_id'] );
		foreach ( array( '', '0', 'no', 'off', 'false' ) as $value ) {
			update_option( 'jlife_challenges_require_guardian_consent', $value );
			$this->assertFalse( jlife_challenges_needs_guardian_consent( $row ), "setting '{$value}'" );
		}
		update_option( 'jlife_challenges_require_guardian_consent', '1' );
		$this->assertTrue( jlife_challenges_needs_guardian_consent( $row ), 'the opt-in still works' );
		add_filter( 'jlife_challenges_require_guardian_consent', '__return_false' );
		$this->assertFalse( jlife_challenges_needs_guardian_consent( $row ), 'the filter overrides' );
		remove_filter( 'jlife_challenges_require_guardian_consent', '__return_false' );
	}
}
