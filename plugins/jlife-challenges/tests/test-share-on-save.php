<?php
/**
 * Share on save (#98, per group since #112): one checkbox per group. Access-control
 * tests for the sensitivity-matrix row in docs/challenges-engine-design.md §4a.
 * They were written before the feature. (The multi-group cases live in
 * test-multi-group-sharing.php; here each person is in one group: a and b in
 * Group One, c in Group Two.)
 *
 * @package jlife-challenges
 */

/**
 * Share-on-save tests.
 */
class Test_Share_On_Save extends Jlife_Challenges_TestCase {

	/**
	 * A fixture user's own group (Group One unless they are c).
	 *
	 * @param string $who Fixture user.
	 * @return int
	 */
	private function home( $who ) {
		return (int) ( 'c' === $who ? $this->g['g2'] : $this->g['g1'] );
	}

	/**
	 * Save (submit) a day for a fixture user, optionally sharing it.
	 *
	 * @param string      $who   Fixture user.
	 * @param string      $text  Entry text.
	 * @param bool|int[]  $share true ticks the user's own group's box, false ticks none, an array ticks those groups.
	 * @param string|null $day   Day key (null = today).
	 * @return array|WP_Error
	 */
	private function save( $who, $text, $share = true, $day = null ) {
		$groups = is_array( $share ) ? $share : ( $share ? array( $this->home( $who ) ) : array() );
		return jlife_challenges_save_and_share( $this->u[ $who ], $this->campaign, $day, $text, $groups );
	}

	/**
	 * The feed of a group (default: the user's own) as a fixture user sees it.
	 *
	 * @param string   $who   Fixture user.
	 * @param int|null $group Group ID.
	 * @return array|WP_Error
	 */
	private function feed( $who, $group = null ) {
		return jlife_challenges_group_feed( $this->u[ $who ], $group ?? $this->home( $who ) );
	}

	/**
	 * How many share rows exist for the campaign (any status).
	 *
	 * @return int
	 */
	private function share_rows() {
		global $wpdb;
		return (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . jlife_challenges_table( 'group_shares' ) . ' WHERE campaign_id = %d', $this->campaign ) ); // phpcs:ignore WordPress.DB
	}

	/**
	 * A checked save posts the whole entry to the author's current group, and only to it.
	 */
	public function test_checked_save_is_visible_to_current_group_only() {
		$text = $this->soap( 'whole' );
		$r    = $this->save( 'a', $text );
		$this->assertSame( 'shared', $r['share']['status'] );

		$feed = $this->feed( 'b' );
		$this->assertCount( 1, $feed, 'same-group member sees it' );
		$this->assertSame( 'Aname', $feed[0]['author'], 'first name only' );
		$this->assertSame( $text, $feed[0]['body'], 'the whole entry, Prayer included' );

		$this->assertSame( array(), $this->feed( 'c' ), 'other group sees nothing' );
		$this->assertWPError( $this->feed( 'outsider' ), 'not enrolled: denied' );
		$this->assertWPError( $this->feed( 'organizer' ), 'organizers cannot browse feeds (D17)' );
		$this->assertWPError( jlife_challenges_group_feed( 0, $this->g['g1'] ), 'anonymous: denied' );
	}

	/**
	 * Unchecked saves and drafts never create or change a post.
	 */
	public function test_unchecked_and_draft_saves_create_no_post() {
		$r = $this->save( 'a', $this->soap(), false );
		$this->assertSame( 'none', $r['share']['status'] );
		$this->assertSame( 1, $r['points'], 'the point does not depend on sharing' );
		$this->assertSame( 0, $this->share_rows() );

		$this->assertIsInt( jlife_challenges_save_draft( $this->u['b'], $this->campaign, null, $this->soap( 'draft' ) ) );
		$this->assertSame( 0, $this->share_rows(), 'a draft save never shares' );
		$this->assertSame( array(), $this->feed( 'a' ) );
	}

	/**
	 * A checked save that fails (closed day, not enrolled) creates no post.
	 */
	public function test_failed_save_creates_no_post() {
		$this->assertWPError( $this->save( 'a', $this->soap(), true, 'd002' ), 'future day' );
		$this->assertWPError( $this->save( 'outsider', $this->soap() ), 'not enrolled' );
		$this->assertSame( 0, $this->share_rows() );
	}

	/**
	 * Nobody can share someone else's entry; sharing only ever touches one's own.
	 */
	public function test_nobody_can_share_someone_elses_entry() {
		$entry = $this->save( 'a', $this->soap( 'mine' ), false )['entry_id'];
		$this->assertSame( 'jlife_denied', jlife_challenges_share( $this->u['b'], $entry, $this->g['g1'] )->get_error_code() );
		$this->assertSame( 'jlife_denied', jlife_challenges_share( $this->u['c'], $entry, $this->g['g1'] )->get_error_code() );
		$this->assertSame( 'jlife_denied', jlife_challenges_share( $this->u['organizer'], $entry, $this->g['g1'] )->get_error_code() );
		$this->assertSame( 'jlife_denied', jlife_challenges_share( $this->u['outsider'], $entry, $this->g['g1'] )->get_error_code() );
		$this->assertSame( 'jlife_denied', jlife_challenges_share( $this->u['a'], 999999, $this->g['g1'] )->get_error_code(), 'unknown entry looks the same' );
		$this->assertSame( 0, $this->share_rows() );

		$this->save( 'b', $this->soap( 'bs' ) );
		$this->assertSame( 1, $this->share_rows(), 'b shared only b\'s own entry' );
		$this->assertNull( jlife_challenges_current_share( $this->u['a'], $entry, $this->g['g1'] ), 'a has no post' );
	}

	/**
	 * D19 (owner decision 2026-11-05): by default a minor's ticked save posts to
	 * their group, with no guardian step in J-Life.
	 */
	public function test_minor_shares_on_save_by_default() {
		$teen = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		$this->assertIsInt( jlife_challenges_enroll( $this->u['organizer'], $teen, $this->g['g1'], 'minor' ) );

		$r = jlife_challenges_save_and_share( $teen, $this->campaign, null, $this->soap( 'teen' ), array( $this->g['g1'] ) );
		$this->assertSame( 1, $r['points'] );
		$this->assertSame( 'shared', $r['share']['status'] );
		$this->assertSame( 1, $this->share_rows() );
	}

	/**
	 * Opt-in setting on: a minor awaiting guardian consent cannot post with the
	 * checkbox; the save and the point stand and the result says sharing did not happen.
	 */
	public function test_minor_awaiting_guardian_consent_cannot_post_when_required() {
		update_option( 'jlife_challenges_require_guardian_consent', '1' );
		$teen = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		$this->assertIsInt( jlife_challenges_enroll( $this->u['organizer'], $teen, $this->g['g1'], 'minor' ) );

		$r = jlife_challenges_save_and_share( $teen, $this->campaign, null, $this->soap( 'teen' ), array( $this->g['g1'] ) );
		$this->assertSame( 1, $r['points'], 'private SOAP and points work at once' );
		$this->assertSame( 'failed', $r['share']['status'] );
		$this->assertSame( 'jlife_guardian_consent_required', $r['share']['results'][ $this->g['g1'] ]['code'] );
		$this->assertNotSame( '', $r['share']['results'][ $this->g['g1'] ]['message'] );
		$this->assertSame( 0, $this->share_rows() );
		$this->assertStringContainsString( '(teen)', jlife_challenges_find_entry( $teen, $this->campaign, 'd001' )['body'], 'the save stands' );

		$this->assertTrue( jlife_challenges_record_guardian_consent( $this->u['organizer'], $this->campaign, $teen ) );
		$again = jlife_challenges_save_and_share( $teen, $this->campaign, null, $this->soap( 'teen' ), array( $this->g['g1'] ) );
		$this->assertSame( 'shared', $again['share']['status'] );
		$this->assertSame( 1, $this->share_rows() );
	}

	/**
	 * D17: an organizer-hidden post is not re-posted, updated or un-hidden by a
	 * later checked save; the save and the point stand.
	 */
	public function test_hidden_post_is_not_reposted() {
		$first = $this->save( 'a', $this->soap( 'v1' ) );
		$this->assertTrue( jlife_challenges_hide_share( $this->u['organizer'], $first['share']['results'][ $this->g['g1'] ]['share_id'], 'Off-topic' ) );
		$this->assertSame( array(), $this->feed( 'b' ) );

		$r = $this->save( 'a', $this->soap( 'v2' ) );
		$this->assertSame( 1, $r['points'] );
		$this->assertSame( 'failed', $r['share']['status'] );
		$this->assertSame( 'jlife_share_hidden', $r['share']['results'][ $this->g['g1'] ]['code'] );
		$this->assertSame( array(), $this->feed( 'b' ), 'still hidden' );
		$share = jlife_challenges_find_share( $first['share']['results'][ $this->g['g1'] ]['share_id'] );
		$this->assertSame( 'hidden', $share['status'] );
		$this->assertStringContainsString( '(v1)', (string) $share['body'], 'the hidden snapshot is not updated' );
		$this->assertSame( 1, $this->share_rows() );
	}

	/**
	 * D18: after a move, a checked save posts to the new group only; the old
	 * post stays with the old group.
	 */
	public function test_post_goes_to_current_group_after_a_move() {
		$this->save( 'a', $this->soap( 'old' ), array( $this->g['g1'] ) );
		$this->assertTrue( jlife_challenges_move_member( $this->u['organizer'], $this->u['a'], $this->g['g2'] ) );
		$r = $this->save( 'a', $this->soap( 'new' ), array( $this->g['g2'] ) );
		$this->assertSame( 'shared', $r['share']['status'] );

		$this->assertCount( 1, $this->feed( 'b' ), 'old group keeps only the old post' );
		$this->assertStringContainsString( '(old)', $this->feed( 'b' )[0]['body'] );
		$c = $this->feed( 'c' );
		$this->assertCount( 1, $c, 'new group sees the new post' );
		$this->assertStringContainsString( '(new)', $c[0]['body'] );
		$this->assertSame( 2, $this->share_rows() );
	}

	/**
	 * A checked re-save updates the one post; retries never duplicate it or add a point.
	 */
	public function test_checked_resave_updates_one_post_and_one_point() {
		$first = $this->save( 'a', $this->soap( 'v1' ) );
		$retry = $this->save( 'a', $this->soap( 'v1' ) );
		$edit  = $this->save( 'a', $this->soap( 'v2' ) );

		$g1 = $this->g['g1'];
		$this->assertSame( $first['share']['results'][ $g1 ]['share_id'], $retry['share']['results'][ $g1 ]['share_id'] );
		$this->assertSame( $first['share']['results'][ $g1 ]['share_id'], $edit['share']['results'][ $g1 ]['share_id'] );
		$this->assertSame( 1, $this->share_rows() );
		$feed = $this->feed( 'b' );
		$this->assertCount( 1, $feed );
		$this->assertStringContainsString( '(v2)', $feed[0]['body'], 'the edit updates the same post' );
		$this->assertSame( 1, $this->ledger_rows( $this->u['a'] ) );
		$this->assertSame( 1, jlife_challenges_progress( $this->u['a'], $this->u['a'], $this->campaign )['points'] );
	}

	/**
	 * Unchecking on a later save does not delete an earlier post (removal has its
	 * own flow), and removing it leaves the point.
	 */
	public function test_unchecked_resave_leaves_post_and_removal_keeps_point() {
		$first = $this->save( 'a', $this->soap( 'v1' ) );
		$this->save( 'a', $this->soap( 'v2' ), false );
		$feed = $this->feed( 'b' );
		$this->assertCount( 1, $feed );
		$this->assertStringContainsString( '(v1)', $feed[0]['body'], 'an unchecked save does not touch the post' );

		$this->assertTrue( jlife_challenges_unshare( $this->u['a'], $first['share']['results'][ $this->g['g1'] ]['share_id'] ) );
		$this->assertSame( array(), $this->feed( 'b' ) );
		$this->assertSame( 1, jlife_challenges_progress( $this->u['a'], $this->u['a'], $this->campaign )['points'] );
	}

	/**
	 * An entry saved without a point (not complete yet) is still saved and, when
	 * the box is checked, shared as written: sharing does not depend on scoring.
	 */
	public function test_checked_save_without_a_point_still_shares_the_saved_text() {
		$text = $this->soap_without_prayer( 'partial' );
		$r    = $this->save( 'a', $text );
		$this->assertFalse( $r['passes'] );
		$this->assertSame( 0, $this->ledger_rows( $this->u['a'] ) );
		$this->assertSame( 'shared', $r['share']['status'] );
		$this->assertSame( trim( $text ), $this->feed( 'b' )[0]['body'] );
	}
}
