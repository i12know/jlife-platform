<?php
/**
 * Group shares: deliberate snapshots, own group only, never affecting points;
 * moderation (D17), group moves (D18) and minors' consent (D19).
 *
 * @package jlife-challenges
 */

/**
 * Share tests.
 */
class Test_Shares extends Jlife_Challenges_TestCase {

	/**
	 * Submit for a user and return the entry ID.
	 *
	 * @param string $who Fixture user.
	 * @param string $tag Text marker.
	 * @return int
	 */
	private function submitted( $who, $tag = 'x' ) {
		return jlife_challenges_submit( $this->u[ $who ], $this->campaign, null, $this->soap( $tag ) )['entry_id'];
	}

	/**
	 * Feed authors for a viewer.
	 *
	 * @param string $who Fixture user.
	 * @return array|WP_Error
	 */
	private function feed( $who ) {
		return jlife_challenges_group_feed( $this->u[ $who ], $this->campaign );
	}

	/**
	 * The whole entry reaches the group; only the same group sees it; first names only.
	 */
	public function test_share_reaches_own_group_only() {
		$entry = $this->submitted( 'a', 'shared' );
		$this->assertIsInt( jlife_challenges_share( $this->u['a'], $entry ) );

		$feed = $this->feed( 'b' );
		$this->assertCount( 1, $feed );
		$this->assertSame( 'Aname', $feed[0]['author'], 'first name only' );
		$this->assertSame( $this->soap( 'shared' ), $feed[0]['body'], 'the whole entry, Prayer included' );

		$this->assertSame( array(), $this->feed( 'c' ), 'other group sees nothing' );
		$this->assertWPError( $this->feed( 'outsider' ), 'not enrolled: denied' );
		$this->assertWPError( $this->feed( 'organizer' ), 'organizers cannot browse feeds (D17)' );
	}

	/**
	 * Submitting never shares by itself; a draft saved on its own is never shared;
	 * an entry with nothing in it cannot be shared.
	 */
	public function test_unshared_and_draft_entries_stay_private() {
		$this->submitted( 'a' );
		$this->assertSame( array(), $this->feed( 'b' ) );
		jlife_challenges_save_draft( $this->u['b'], $this->campaign, null, $this->soap() );
		$this->assertSame( array(), $this->feed( 'a' ), 'a draft is not posted' );
		$empty = jlife_challenges_save_draft( $this->u['c'], $this->campaign, null, '' );
		$this->assertSame( 'jlife_share_empty', jlife_challenges_share( $this->u['c'], $empty )->get_error_code() );
	}

	/**
	 * Nobody can share someone else's entry.
	 */
	public function test_cannot_share_someone_elses_entry() {
		$entry = $this->submitted( 'a' );
		$this->assertWPError( jlife_challenges_share( $this->u['b'], $entry ) );
		$this->assertWPError( jlife_challenges_share( $this->u['organizer'], $entry ) );
		$this->assertSame( array(), $this->feed( 'b' ) );
	}

	/**
	 * A share is a snapshot; sharing again replaces it; duplicates collapse.
	 */
	public function test_snapshot_replace_and_idempotence() {
		$entry = $this->submitted( 'a', 'v1' );
		$id1   = jlife_challenges_share( $this->u['a'], $entry );
		jlife_challenges_submit( $this->u['a'], $this->campaign, null, $this->soap( 'v2' ) );
		$this->assertStringContainsString( '(v1)', $this->feed( 'b' )[0]['body'], 'editing the SOAP does not change the share' );

		$id2 = jlife_challenges_share( $this->u['a'], $entry );
		$id3 = jlife_challenges_share( $this->u['a'], $entry );
		$this->assertSame( $id1, $id2 );
		$this->assertSame( $id1, $id3 );
		$feed = $this->feed( 'b' );
		$this->assertCount( 1, $feed );
		$this->assertStringContainsString( '(v2)', $feed[0]['body'], 'sharing again replaces' );
	}

	/**
	 * Sharing and unsharing never change points.
	 */
	public function test_sharing_does_not_affect_points() {
		$entry = $this->submitted( 'a' );
		$share = jlife_challenges_share( $this->u['a'], $entry );
		$this->assertSame( 1, jlife_challenges_progress( $this->u['a'], $this->u['a'], $this->campaign )['points'] );
		$this->assertTrue( jlife_challenges_unshare( $this->u['a'], $share ) );
		$this->assertSame( array(), $this->feed( 'b' ), 'removed shares disappear' );
		$this->assertSame( 1, jlife_challenges_progress( $this->u['a'], $this->u['a'], $this->campaign )['points'] );
		$this->assertWPError( jlife_challenges_unshare( $this->u['b'], $share ), 'only the author removes' );
	}

	/**
	 * D17: organizers hide a reported share; audited; the author is told; re-sharing cannot undo it.
	 */
	public function test_hide_reported_share() {
		$entry = $this->submitted( 'a' );
		$share = jlife_challenges_share( $this->u['a'], $entry );
		$this->assertWPError( jlife_challenges_hide_share( $this->u['b'], $share, 'x' ), 'members cannot hide' );
		$this->assertTrue( jlife_challenges_hide_share( $this->u['organizer'], $share, 'Reported as off-topic' ) );
		$this->assertSame( array(), $this->feed( 'b' ) );

		$mine = jlife_challenges_my_shares( $this->u['a'], $this->campaign );
		$this->assertSame( 'hidden', $mine[0]['status'] );
		$this->assertSame( 'Reported as off-topic', $mine[0]['hidden_reason'] );
		$notices = get_user_meta( $this->u['a'], 'jlife_challenges_notices', true );
		$this->assertSame( 'share_hidden', $notices[0]['type'] );

		$again = jlife_challenges_share( $this->u['a'], $entry );
		$this->assertSame( 'jlife_share_hidden', $again->get_error_code(), 're-sharing is refused, and says why' );
		$this->assertSame( array(), $this->feed( 'b' ), 're-sharing does not unhide' );
	}

	/**
	 * D18: after a move, old shares stay with the old group, out of the new group's sight;
	 * the mover loses the old feed but can still remove their old shares.
	 */
	public function test_group_move_keeps_shares_with_old_group() {
		$entry = $this->submitted( 'a', 'old' );
		$share = jlife_challenges_share( $this->u['a'], $entry );
		$this->assertTrue( jlife_challenges_move_member( $this->u['organizer'], $this->u['a'], $this->g['g2'] ) );

		$this->assertCount( 1, $this->feed( 'b' ), 'old group still sees it' );
		$this->assertSame( array(), $this->feed( 'c' ), 'new group does not' );
		$a_feed = $this->feed( 'a' );
		$this->assertSame( array(), $a_feed, 'mover now reads only the new group feed' );
		$this->assertFalse( jlife_challenges_can_read_feed( $this->u['a'], $this->g['g1'] ), 'mover lost the old feed' );

		$mine = jlife_challenges_my_shares( $this->u['a'], $this->campaign );
		$this->assertSame( (int) $this->g['g1'], (int) $mine[0]['group_id'] );
		$this->assertTrue( jlife_challenges_unshare( $this->u['a'], $share ) );
		$this->assertSame( array(), $this->feed( 'b' ) );
	}

	/**
	 * D19: a minor's shares wait for guardian consent; private SOAP and points work at once.
	 */
	public function test_minor_needs_guardian_consent_to_share() {
		$teen = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		$this->assertIsInt( jlife_challenges_enroll( $this->u['organizer'], $teen, $this->g['g1'], 'minor' ) );
		$r = jlife_challenges_submit( $teen, $this->campaign, null, $this->soap() );
		$this->assertSame( 1, $r['points'], 'private SOAP and points work immediately' );

		$err = jlife_challenges_share( $teen, $r['entry_id'] );
		$this->assertSame( 'jlife_guardian_consent_required', $err->get_error_code() );

		$this->assertTrue( jlife_challenges_record_guardian_consent( $this->u['organizer'], $this->campaign, $teen ) );
		$this->assertIsInt( jlife_challenges_share( $teen, $r['entry_id'] ) );
	}

	/**
	 * D19 option (b): turning the consent requirement off lets minors share.
	 */
	public function test_guardian_consent_requirement_is_a_setting() {
		update_option( 'jlife_challenges_require_guardian_consent', '0' );
		$teen = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		jlife_challenges_enroll( $this->u['organizer'], $teen, $this->g['g1'], 'minor' );
		$entry = jlife_challenges_submit( $teen, $this->campaign, null, $this->soap() )['entry_id'];
		$this->assertIsInt( jlife_challenges_share( $teen, $entry ) );
	}
}
