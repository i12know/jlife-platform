<?php
/**
 * Multi-group sharing (#112, D14 changed): one share choice per group, a share
 * reaches only the ticked groups, solo readers have no share route, and every
 * feed shows only the shares made to that group. Access-control tests for the
 * rows in docs/challenges-engine-design.md §4 and §4a. They were written before
 * the feature.
 *
 * Fixture: a is in Group One and Group Two; b only in Group One; c only in
 * Group Two; d only in Group Three; "solo" reads on their own; outsider is not
 * enrolled at all.
 *
 * @package jlife-challenges
 */

/**
 * Multi-group sharing tests.
 */
class Test_Multi_Group_Sharing extends Jlife_Challenges_TestCase {

	/**
	 * Group Three's ID.
	 *
	 * @var int
	 */
	private $g3 = 0;

	/**
	 * Extra fixtures: a in two groups, d in Group Three, a solo reader.
	 */
	public function set_up() {
		parent::set_up();
		$this->g3        = (int) jlife_challenges_create_group( $this->u['organizer'], $this->campaign, 'Group Three' )['id'];
		$this->u['d']    = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		$this->u['solo'] = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		$this->assertIsInt( jlife_challenges_enroll( $this->u['organizer'], $this->u['a'], $this->g['g2'] ) );
		$this->assertIsInt( jlife_challenges_enroll( $this->u['organizer'], $this->u['d'], $this->g3 ) );
		$this->assertIsInt( jlife_challenges_enroll_solo( $this->u['solo'], $this->campaign, 'adult' ) );
	}

	/**
	 * Save (submit) a day for a fixture user, ticking the given groups.
	 *
	 * @param string $who    Fixture user.
	 * @param string $text   Entry text.
	 * @param array  $groups Group IDs ticked.
	 * @return array|WP_Error
	 */
	private function save( $who, $text, $groups = array() ) {
		return jlife_challenges_save_and_share( $this->u[ $who ], $this->campaign, null, $text, $groups );
	}

	/**
	 * Feed bodies a viewer sees in a group (or the error).
	 *
	 * @param string $who   Fixture user.
	 * @param int    $group Group ID.
	 * @return array|WP_Error
	 */
	private function feed( $who, $group ) {
		return jlife_challenges_group_feed( $this->u[ $who ], $group );
	}

	/**
	 * Number of share rows for the campaign (any status).
	 *
	 * @return int
	 */
	private function share_rows() {
		global $wpdb;
		return (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . jlife_challenges_table( 'group_shares' ) . ' WHERE campaign_id = %d', $this->campaign ) ); // phpcs:ignore WordPress.DB
	}

	/**
	 * A member of group A cannot read group B's shares or feed, however they ask.
	 */
	public function test_member_of_a_cannot_read_group_b() {
		$this->save( 'c', $this->soap( 'for-two' ), array( $this->g['g2'] ) );
		$this->save( 'd', $this->soap( 'for-three' ), array( $this->g3 ) );

		$this->assertCount( 1, $this->feed( 'a', $this->g['g2'] ), 'a is in Group Two' );
		foreach ( array(
			'b' => $this->g['g2'],
			'c' => $this->g['g1'],
			'd' => $this->g['g2'],
		) as $who => $group ) {
			$this->assertWPError( $this->feed( $who, $group ), "$who cannot read group $group" );
			$this->assertFalse( jlife_challenges_can_read_feed( $this->u[ $who ], $group ) );
		}
		$this->assertWPError( $this->feed( 'a', $this->g3 ), 'a is not in Group Three' );
		$this->assertWPError( $this->feed( 'b', $this->g3 ), 'b is not in Group Three' );

		$view = jlife_challenges_group_view( $this->u['b'], $this->g['g2'] );
		$this->assertSame( 'denied', $view['state'] );
		$this->assertArrayNotHasKey( 'posts', $view, 'a denied view carries no posts' );
		$this->assertStringNotContainsString( 'for-two', wp_json_encode( $view ) );
	}

	/**
	 * A non-member (not enrolled, solo, organizer, anonymous) cannot read any feed; unknown groups look the same.
	 */
	public function test_non_member_cannot_read_a_feed() {
		$this->save( 'a', $this->soap( 'secret' ), array( $this->g['g1'] ) );
		foreach ( array( 'outsider', 'solo', 'organizer' ) as $who ) {
			$this->assertSame( 'jlife_denied', $this->feed( $who, $this->g['g1'] )->get_error_code(), "$who denied" );
		}
		$this->assertWPError( jlife_challenges_group_feed( 0, $this->g['g1'] ), 'anonymous denied' );
		$this->assertSame( 'jlife_denied', jlife_challenges_group_feed( $this->u['a'], 999999 )->get_error_code(), 'unknown group looks the same' );
		$this->assertSame( 'jlife_denied', jlife_challenges_group_feed( $this->u['a'], 0 )->get_error_code() );
	}

	/**
	 * Sharing to two of three groups reaches exactly those two; each feed shows only its own shares.
	 */
	public function test_sharing_to_two_groups_reaches_exactly_those_two() {
		$this->assertIsInt( jlife_challenges_enroll( $this->u['organizer'], $this->u['a'], $this->g3 ) );
		$r = $this->save( 'a', $this->soap( 'two-of-three' ), array( $this->g['g1'], $this->g3 ) );

		$this->assertSame( 'shared', $r['share']['status'] );
		$this->assertSame( 1, $r['points'] );
		$this->assertSame( 2, $this->share_rows() );
		$this->assertCount( 1, $this->feed( 'b', $this->g['g1'] ), 'Group One: shared' );
		$this->assertCount( 1, $this->feed( 'd', $this->g3 ), 'Group Three: shared' );
		$this->assertSame( array(), $this->feed( 'c', $this->g['g2'] ), 'Group Two: not ticked, so nothing' );
		$this->assertSame( array(), $this->feed( 'a', $this->g['g2'] ), 'not even a sees a post in a group they did not tick' );
		$this->assertStringContainsString( '(two-of-three)', $this->feed( 'd', $this->g3 )[0]['body'] );
		$this->assertSame( 'Aname', $this->feed( 'b', $this->g['g1'] )[0]['author'], 'first name only' );
	}

	/**
	 * Nothing ticked shares nothing; a bare "true" is not a share-with-all shortcut.
	 */
	public function test_unticked_by_default_and_no_share_with_all() {
		$r = $this->save( 'a', $this->soap( 'none' ), array() );
		$this->assertSame( 'none', $r['share']['status'] );
		$this->assertSame( 1, $r['points'], 'the point never depends on sharing' );

		$r = $this->save( 'a', $this->soap( 'legacy-true' ), true );
		$this->assertSame( 'none', $r['share']['status'], 'the old single checkbox value means nothing now' );
		$this->assertSame( 0, $this->share_rows() );
		$this->assertSame( array(), $this->feed( 'b', $this->g['g1'] ) );
		$this->assertSame( array(), $this->feed( 'c', $this->g['g2'] ) );
	}

	/**
	 * A group the author is not in (including a guessed ID) is refused, others still post.
	 */
	public function test_a_group_that_is_not_mine_is_refused() {
		$r = $this->save( 'a', $this->soap( 'mixed' ), array( $this->g['g1'], $this->g3 ) );
		$this->assertSame( 'partial', $r['share']['status'] );
		$this->assertSame( 'shared', $r['share']['results'][ $this->g['g1'] ]['status'] );
		$this->assertSame( 'failed', $r['share']['results'][ $this->g3 ]['status'] );
		$this->assertSame( 'jlife_denied', $r['share']['results'][ $this->g3 ]['code'] );
		$this->assertSame( 1, $this->share_rows() );
		$this->assertSame( array(), $this->feed( 'd', $this->g3 ), 'nothing reached a group a is not in' );

		$entry = jlife_challenges_find_entry( $this->u['a'], $this->campaign, 'd001' )['id'];
		$this->assertSame( 'jlife_denied', jlife_challenges_share( $this->u['a'], $entry, $this->g3 )->get_error_code() );
		$this->assertSame( 'jlife_denied', jlife_challenges_share( $this->u['a'], $entry, 999999 )->get_error_code(), 'guessed group ID' );
		$only = $this->save( 'a', $this->soap( 'only-bad' ), array( $this->g3 ) );
		$this->assertSame( 'failed', $only['share']['status'] );
		$this->assertSame( 1, $only['points'], 'the save and point stand' );
		$this->assertSame( 1, $this->share_rows() );
	}

	/**
	 * A solo reader has no share route and no share effect.
	 */
	public function test_solo_reader_has_no_share_route_or_effect() {
		$solo = $this->u['solo'];
		$r    = jlife_challenges_save_and_share( $solo, $this->campaign, null, $this->soap( 'solo' ), array( $this->g['g1'] ) );
		$this->assertSame( 1, $r['points'], 'a solo reader writes and scores' );
		$this->assertSame( 'failed', $r['share']['status'] );
		$this->assertSame( 'jlife_denied', $r['share']['results'][ $this->g['g1'] ]['code'] );
		$this->assertSame( 0, $this->share_rows() );
		$this->assertSame( array(), $this->feed( 'b', $this->g['g1'] ) );

		$entry = jlife_challenges_find_entry( $solo, $this->campaign, 'd001' )['id'];
		foreach ( array( $this->g['g1'], $this->g['g2'], $this->g3, 0 ) as $group ) {
			$this->assertSame( 'jlife_denied', jlife_challenges_share( $solo, $entry, $group )->get_error_code() );
		}
		$this->assertSame( array(), jlife_challenges_share_groups( $solo, $this->campaign ), 'nothing to offer on the SOAP form' );
		$this->assertSame( array(), jlife_challenges_day_view( $solo )['share_groups'] );

		$view = jlife_challenges_share_view( $solo, 'd001' );
		$this->assertTrue( $view['shareable'], 'the chat share still works: it stores nothing' );
		$this->assertSame( array(), $view['destinations'], 'no group destinations' );
		$this->assertSame( 'solo', jlife_challenges_group_view( $solo )['state'] );
		$this->assertSame( array(), jlife_challenges_my_shares( $solo, $this->campaign ) );
	}

	/**
	 * Re-sharing updates only the groups it names; removal is per group and keeps points.
	 */
	public function test_update_and_remove_are_per_group() {
		$first = $this->save( 'a', $this->soap( 'v1' ), array( $this->g['g1'], $this->g['g2'] ) );
		$this->assertSame( 2, $this->share_rows() );
		$s1 = $first['share']['results'][ $this->g['g1'] ]['share_id'];
		$s2 = $first['share']['results'][ $this->g['g2'] ]['share_id'];
		$this->assertNotSame( $s1, $s2 );

		$second = $this->save( 'a', $this->soap( 'v2' ), array( $this->g['g2'] ) );
		$this->assertSame( $s2, $second['share']['results'][ $this->g['g2'] ]['share_id'], 'the same post, updated' );
		$this->assertStringContainsString( '(v1)', $this->feed( 'b', $this->g['g1'] )[0]['body'], 'Group One keeps its snapshot' );
		$this->assertStringContainsString( '(v2)', $this->feed( 'c', $this->g['g2'] )[0]['body'] );
		$this->assertSame( 2, $this->share_rows(), 'never duplicated' );

		$this->assertTrue( jlife_challenges_unshare( $this->u['a'], $s1 ) );
		$this->assertSame( array(), $this->feed( 'b', $this->g['g1'] ) );
		$this->assertCount( 1, $this->feed( 'c', $this->g['g2'] ), 'the other group keeps its post' );
		$this->assertSame( 'jlife_denied', jlife_challenges_unshare( $this->u['b'], $s2 )->get_error_code(), 'only the author removes' );
		$this->assertSame( 1, jlife_challenges_progress( $this->u['a'], $this->u['a'], $this->campaign )['points'] );
	}

	/**
	 * D17: a post hidden in one group is not re-posted there, and does not block the author's other groups.
	 */
	public function test_hidden_in_one_group_does_not_block_the_other() {
		$first = $this->save( 'a', $this->soap( 'v1' ), array( $this->g['g1'], $this->g['g2'] ) );
		$this->assertTrue( jlife_challenges_hide_share( $this->u['organizer'], $first['share']['results'][ $this->g['g1'] ]['share_id'], 'Off-topic' ) );

		$again = $this->save( 'a', $this->soap( 'v2' ), array( $this->g['g1'], $this->g['g2'] ) );
		$this->assertSame( 'partial', $again['share']['status'] );
		$this->assertSame( 'jlife_share_hidden', $again['share']['results'][ $this->g['g1'] ]['code'] );
		$this->assertSame( 'shared', $again['share']['results'][ $this->g['g2'] ]['status'] );
		$this->assertSame( array(), $this->feed( 'b', $this->g['g1'] ), 'still hidden' );
		$this->assertStringContainsString( '(v2)', $this->feed( 'c', $this->g['g2'] )[0]['body'] );
	}

	/**
	 * D17: removing a post an organizer hid does not lift the hide, so the author cannot post again.
	 */
	public function test_removing_a_hidden_post_does_not_lift_the_hide() {
		$first = $this->save( 'a', $this->soap( 'v1' ), array( $this->g['g1'] ) );
		$id    = $first['share']['results'][ $this->g['g1'] ]['share_id'];
		$this->assertTrue( jlife_challenges_hide_share( $this->u['organizer'], $id, 'Off-topic' ) );
		$this->assertTrue( jlife_challenges_unshare( $this->u['a'], $id ) );
		$this->assertNull( jlife_challenges_find_share( $id )['body'], 'the text is cleared' );

		$again = $this->save( 'a', $this->soap( 'v2' ), array( $this->g['g1'] ) );
		$this->assertSame( 'jlife_share_hidden', $again['share']['results'][ $this->g['g1'] ]['code'] );
		$this->assertSame( array(), $this->feed( 'b', $this->g['g1'] ) );
	}

	/**
	 * D19 per group: a minor in several groups shares like anyone else, only to the groups they tick.
	 */
	public function test_minor_shares_per_group_by_default() {
		$teen = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		$this->assertIsInt( jlife_challenges_enroll( $this->u['organizer'], $teen, $this->g['g1'], 'minor' ) );
		$this->assertIsInt( jlife_challenges_enroll( $this->u['organizer'], $teen, $this->g['g2'], 'minor' ) );
		$this->assertIsInt( jlife_challenges_enroll( $this->u['organizer'], $teen, $this->g3, 'minor' ) );

		$r = jlife_challenges_save_and_share( $teen, $this->campaign, null, $this->soap( 'teen' ), array( $this->g['g2'] ) );
		$this->assertSame( 'shared', $r['share']['status'] );
		$this->assertCount( 1, $this->feed( 'c', $this->g['g2'] ) );
		$this->assertSame( array(), $this->feed( 'b', $this->g['g1'] ) );
		$this->assertSame( array(), $this->feed( 'd', $this->g3 ) );
	}

	/**
	 * With the opt-in guardian setting on, a minor's shares to every group wait for consent.
	 */
	public function test_guardian_requirement_blocks_every_group() {
		update_option( 'jlife_challenges_require_guardian_consent', '1' );
		$teen = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		jlife_challenges_enroll( $this->u['organizer'], $teen, $this->g['g1'], 'minor' );
		jlife_challenges_enroll( $this->u['organizer'], $teen, $this->g['g2'], 'minor' );

		$r = jlife_challenges_save_and_share( $teen, $this->campaign, null, $this->soap( 'teen' ), array( $this->g['g1'], $this->g['g2'] ) );
		$this->assertSame( 1, $r['points'], 'private SOAP and points work at once' );
		$this->assertSame( 'failed', $r['share']['status'] );
		foreach ( array( $this->g['g1'], $this->g['g2'] ) as $group ) {
			$this->assertSame( 'jlife_guardian_consent_required', $r['share']['results'][ $group ]['code'] );
		}
		$this->assertSame( 0, $this->share_rows() );
		$view = jlife_challenges_share_view( $teen, 'd001' );
		$this->assertCount( 2, $view['destinations'] );
		foreach ( $view['destinations'] as $dest ) {
			$this->assertSame( 'guardian', $dest['post_block'] );
			$this->assertFalse( $dest['can_post'] );
		}
	}

	/**
	 * Entries saved before joining a group stay private to it until the author shares them.
	 */
	public function test_entry_saved_before_joining_stays_private() {
		$entry = $this->save( 'b', $this->soap( 'early' ), array() )['entry_id'];
		$code  = jlife_challenges_reset_invite( $this->u['organizer'], $this->g3 );
		$this->assertIsInt( jlife_challenges_join_with_code( $this->u['b'], $code, 'adult' ) );
		$this->assertSame( array(), $this->feed( 'd', $this->g3 ), 'joining shares nothing' );
		$this->assertSame( array(), $this->feed( 'a', $this->g['g1'] ) );

		$this->assertIsInt( jlife_challenges_share( $this->u['b'], $entry, $this->g3 ), 'the author can share it deliberately later' );
		$this->assertCount( 1, $this->feed( 'd', $this->g3 ) );
		$this->assertSame( array(), $this->feed( 'a', $this->g['g1'] ), 'and only with the group they chose' );
	}

	/**
	 * D18 per group: after leaving, the old feed is gone but the old shares stay there and stay removable.
	 */
	public function test_leaving_a_group_keeps_old_shares_with_it() {
		$r  = $this->save( 'a', $this->soap( 'old' ), array( $this->g['g1'], $this->g['g2'] ) );
		$s1 = $r['share']['results'][ $this->g['g1'] ]['share_id'];
		$this->assertTrue( jlife_challenges_leave_group( $this->u['a'], $this->g['g1'] ) );

		$this->assertWPError( $this->feed( 'a', $this->g['g1'] ), 'the leaver loses the old feed' );
		$this->assertCount( 1, $this->feed( 'b', $this->g['g1'] ), 'the old group still has the post' );
		$this->assertCount( 1, $this->feed( 'a', $this->g['g2'] ), 'the other group is unaffected' );
		$this->assertCount( 2, jlife_challenges_my_shares( $this->u['a'], $this->campaign ), 'the author still lists both' );

		$entry = jlife_challenges_find_entry( $this->u['a'], $this->campaign, 'd001' )['id'];
		$this->assertSame( 'jlife_denied', jlife_challenges_share( $this->u['a'], $entry, $this->g['g1'] )->get_error_code(), 'no new post to a group they left' );
		$this->assertTrue( jlife_challenges_unshare( $this->u['a'], $s1 ), 'but they can still remove their old one' );
		$this->assertSame( array(), $this->feed( 'b', $this->g['g1'] ) );
	}

	/**
	 * Nobody can share someone else's entry to any group, theirs or not.
	 */
	public function test_nobody_can_share_someone_elses_entry() {
		$entry = $this->save( 'a', $this->soap( 'mine' ), array() )['entry_id'];
		foreach ( array( 'b', 'c', 'd', 'solo', 'outsider', 'organizer' ) as $who ) {
			foreach ( array( $this->g['g1'], $this->g['g2'], $this->g3 ) as $group ) {
				$this->assertSame( 'jlife_denied', jlife_challenges_share( $this->u[ $who ], $entry, $group )->get_error_code() );
			}
		}
		$this->assertSame( 0, $this->share_rows() );
	}

	/**
	 * The SOAP form: one unticked checkbox per group, labelled with its name, no "share with all"; solo readers see none.
	 */
	public function test_form_has_one_unticked_box_per_group() {
		$form = $this->form_for( 'a' );
		$this->assertSame( 2, substr_count( $form, 'type="checkbox"' ) );
		$this->assertSame( 2, substr_count( $form, 'name="jl_share_groups[]"' ) );
		$this->assertStringContainsString( 'name="jl_share_groups[]" value="' . $this->g['g1'] . '">', $form, 'unticked' );
		$this->assertStringContainsString( 'name="jl_share_groups[]" value="' . $this->g['g2'] . '">', $form, 'unticked' );
		$this->assertStringNotContainsString( 'checked', $form );
		$this->assertStringContainsString( 'Group One', $form );
		$this->assertStringContainsString( 'Group Two', $form );
		$this->assertStringNotContainsString( 'Group Three', $form, 'only the person\'s own groups are ever named' );
		$this->assertStringNotContainsString( 'name="jl_share"', $form, 'the old single box is gone' );
		$this->assertDoesNotMatchRegularExpression( '/share (this )?with all|all my groups|everyone/i', $form );
		$this->assertLessThan( strpos( $form, 'jl_share_groups' ), strpos( $form, '</textarea>' ) );
		$this->assertLessThan( strpos( $form, 'Submit SOAP' ), strpos( $form, 'jl_share_groups' ) );

		$one = $this->form_for( 'b' );
		$this->assertSame( 1, substr_count( $one, 'type="checkbox"' ), 'one group, one box' );
		$this->assertStringContainsString( 'Group One', $one );
		$this->assertStringNotContainsString( 'checked', $one );

		$solo = $this->form_for( 'solo' );
		$this->assertSame( 0, substr_count( $solo, 'type="checkbox"' ), 'a solo reader sees no share box' );
		$this->assertStringNotContainsString( 'jl_share', $solo );
		$this->assertStringNotContainsString( 'Share this with', $solo );
		$this->assertStringNotContainsString( 'My Group', $solo );
		$this->assertStringContainsString( 'Submit SOAP', $solo, 'writing is unchanged' );
	}

	/**
	 * When every group already has the day, the "unticked groups" sentence is gone.
	 */
	public function test_unticked_sentence_absent_when_every_group_has_the_day() {
		$this->save( 'a', $this->soap( 'v1' ), array( $this->g['g1'], $this->g['g2'] ) );
		$form = $this->form_for( 'a' );
		$this->assertSame( 2, substr_count( $form, 'Already shared with' ) );
		$this->assertStringNotContainsString( 'leave unticked', $form );
		$this->assertStringContainsString( 'including your Prayer', $form );
	}

	/**
	 * Per-group "already shared, tick and save to update"; the ticks survive a failed save.
	 */
	public function test_form_notes_which_groups_already_have_the_day() {
		$this->save( 'a', $this->soap( 'v1' ), array( $this->g['g2'] ) );
		$view = jlife_challenges_day_view( $this->u['a'] );
		$this->assertSame(
			array(
				(int) $this->g['g1'] => '',
				(int) $this->g['g2'] => 'shared',
			),
			array_column( $view['share_groups'], 'status', 'id' )
		);
		$form = $this->form_for( 'a' );
		$this->assertSame( 1, substr_count( $form, 'Already shared with' ), 'only Group Two is noted' );
		$this->assertStringContainsString( 'Already shared with Group Two — tick and save to update', $form );
		$this->assertSame( 1, substr_count( $form, 'Change or remove on the share page' ) );
		$this->assertStringContainsString( 'href="' . home_url( '/share/' ) . '"', $form );
		$this->assertStringContainsString( "Groups you leave unticked don't see this entry.", $form, 'Group One has no share yet' );
		$this->assertStringNotContainsString( 'keep it private', $form );

		$ticked = jlife_challenges_today_body( $view, array( 'share_groups' => array( $this->g['g1'] ) ) );
		$this->assertSame( 1, substr_count( $ticked, "checked='checked'" ), 'a re-rendered form keeps the ticks the person made' );
		$this->assertStringContainsString( 'value="' . $this->g['g1'] . "\" checked='checked'>", $ticked );
	}

	/**
	 * The SOAP POST: only ticked boxes post, an unknown group in the POST is refused, the redirect names the groups.
	 */
	public function test_soap_post_reaches_only_ticked_groups() {
		$this->post_soap( $this->u['a'], 'submit', $this->soap( 'post' ), array( $this->g['g2'], $this->g3 ) );
		$state = jlife_challenges_process_soap_request( $this->u['a'], jlife_challenges_day_view( $this->u['a'] ), 'today' );
		$this->assertSame( '', $state['error'] );
		$this->assertStringContainsString( 'shared=' . $this->g['g2'], $state['redirect'] );
		$this->assertStringNotContainsString( 'shared=' . $this->g['g2'] . '%2C', $state['redirect'] );
		$this->assertStringContainsString( 'share_failed', $state['redirect'], 'Group Three was not a\'s: refused, and the page says so' );
		$this->assertCount( 1, $this->feed( 'c', $this->g['g2'] ) );
		$this->assertSame( array(), $this->feed( 'b', $this->g['g1'] ), 'unticked: nothing' );
		$this->assertSame( array(), $this->feed( 'd', $this->g3 ), 'not a\'s group: nothing' );
		$this->clear_request();
	}

	/**
	 * /group: a member of one group sees that feed; of several, a picker with each feed on its own; solo, the join prompt.
	 */
	public function test_group_view_picker_and_feeds_are_separate() {
		$this->save( 'a', $this->soap( 'to-one' ), array( $this->g['g1'] ) );
		$this->save( 'c', $this->soap( 'to-two' ), array( $this->g['g2'] ) );

		$b = jlife_challenges_group_view( $this->u['b'] );
		$this->assertSame( 'ok', $b['state'] );
		$this->assertCount( 1, $b['groups'], 'one group: no picker needed' );
		$this->assertCount( 1, $b['posts'] );

		$a_default = jlife_challenges_group_view( $this->u['a'] );
		$this->assertCount( 2, $a_default['groups'], 'two groups: a picker' );
		$this->assertSame( (int) $this->g['g1'], $a_default['group_id'], 'defaults to the first group joined' );
		$this->assertStringContainsString( '(to-one)', $a_default['posts'][0]['body'] );

		$a_two = jlife_challenges_group_view( $this->u['a'], $this->g['g2'] );
		$this->assertSame( 'ok', $a_two['state'] );
		$this->assertSame( 'Group Two', $a_two['group_name'] );
		$this->assertCount( 1, $a_two['posts'] );
		$this->assertStringContainsString( '(to-two)', $a_two['posts'][0]['body'] );
		$this->assertStringNotContainsString( '(to-one)', wp_json_encode( $a_two['posts'] ), 'Group One\'s shares never appear in Group Two\'s feed' );

		$this->assertSame( 'denied', jlife_challenges_group_view( $this->u['a'], $this->g3 )['state'] );
		$this->assertSame( 'solo', jlife_challenges_group_view( $this->u['solo'] )['state'] );
		$this->assertSame( 'not_enrolled', jlife_challenges_group_view( $this->u['outsider'] )['state'] );
	}

	/**
	 * /group page text: the picker links, one feed at a time, "Have a code? Join a group" for solo readers.
	 */
	public function test_group_page_body() {
		$this->save( 'a', $this->soap( 'to-one' ), array( $this->g['g1'] ) );
		$this->save( 'c', $this->soap( 'to-two' ), array( $this->g['g2'] ) );

		$body = jlife_challenges_group_body( jlife_challenges_group_view( $this->u['a'], $this->g['g2'] ) );
		$this->assertStringContainsString( esc_url( add_query_arg( 'g', $this->g['g1'], home_url( '/group/' ) ) ), $body, 'a link to the other group' );
		$this->assertStringContainsString( 'Group One', $body );
		$this->assertStringContainsString( 'Group Two', $body );
		$this->assertStringContainsString( '(to-two)', $body );
		$this->assertStringNotContainsString( '(to-one)', $body );

		$single = jlife_challenges_group_body( jlife_challenges_group_view( $this->u['b'] ) );
		$this->assertStringNotContainsString( 'class="jl-chips"', $single, 'no picker for one group' );

		$solo = jlife_challenges_group_body( jlife_challenges_group_view( $this->u['solo'] ) );
		$this->assertStringContainsString( 'Have a code? Join a group', $solo );
		$this->assertStringNotContainsString( '(to-one)', $solo );
		$this->assertStringNotContainsString( 'Group One', $solo, 'no group names for a solo reader' );
		$this->assertStringContainsString( 'name="jl_invite_code"', $solo, 'a signed-in code box' );

		$denied = jlife_challenges_group_body( jlife_challenges_group_view( $this->u['a'], $this->g3 ) );
		$this->assertStringNotContainsString( 'Group Three', $denied );
		$this->assertStringNotContainsString( '(to-one)', $denied );
	}

	/**
	 * /share: one section per group with its own post / update / remove buttons; solo readers get the join prompt and no group buttons.
	 */
	public function test_share_page_body_is_per_group() {
		$this->save( 'a', $this->soap( 'share' ), array( $this->g['g2'] ) );
		$view = jlife_challenges_share_view( $this->u['a'], 'd001' );
		$this->assertCount( 2, $view['destinations'] );
		$by = array();
		foreach ( $view['destinations'] as $d ) {
			$by[ $d['group_id'] ] = $d;
		}
		$this->assertNull( $by[ (int) $this->g['g1'] ]['share'] );
		$this->assertSame( 'shared', $by[ (int) $this->g['g2'] ]['share']['status'] );

		$body = jlife_challenges_share_body( $view );
		$this->assertStringContainsString( 'value="post-' . $this->g['g1'] . '"', $body );
		$this->assertStringContainsString( 'value="post-' . $this->g['g2'] . '"', $body );
		$this->assertStringContainsString( 'value="remove-' . $this->g['g2'] . '"', $body );
		$this->assertStringNotContainsString( 'value="remove-' . $this->g['g1'] . '"', $body, 'nothing to remove in Group One' );
		$this->assertStringNotContainsString( 'Group Three', $body );

		$this->save( 'solo', $this->soap( 'solo-share' ), array() );
		$solo = jlife_challenges_share_body( jlife_challenges_share_view( $this->u['solo'], 'd001' ) );
		$this->assertStringNotContainsString( 'value="post-', $solo );
		$this->assertStringContainsString( 'Have a code? Join a group', $solo );
	}

	/**
	 * Handling a /share post: it acts on one group, and only a group the author is in.
	 */
	public function test_share_post_acts_on_one_group() {
		$this->save( 'a', $this->soap( 'x' ), array() );
		$view = jlife_challenges_share_view( $this->u['a'], 'd001' );
		$this->assertSame( 'jlife_denied', jlife_challenges_share_group_action( $this->u['a'], $view, 'post-' . $this->g3 )->get_error_code() );
		$this->assertSame( 'jlife_denied', jlife_challenges_share_group_action( $this->u['a'], $view, 'remove-' . $this->g['g1'] )->get_error_code(), 'nothing to remove' );
		$this->assertTrue( jlife_challenges_share_group_action( $this->u['a'], $view, 'post-' . $this->g['g2'] ) );
		$this->assertCount( 1, $this->feed( 'c', $this->g['g2'] ) );
		$this->assertSame( array(), $this->feed( 'b', $this->g['g1'] ) );
		$view = jlife_challenges_share_view( $this->u['a'], 'd001' );
		$this->assertTrue( jlife_challenges_share_group_action( $this->u['a'], $view, 'remove-' . $this->g['g2'] ) );
		$this->assertSame( array(), $this->feed( 'c', $this->g['g2'] ) );

		$solo_view = jlife_challenges_share_view( $this->u['solo'], 'd001' );
		$this->assertSame( 'jlife_denied', jlife_challenges_share_group_action( $this->u['solo'], $solo_view, 'post-' . $this->g['g1'] )->get_error_code() );
	}

	/**
	 * The organizer's score export and counts never reveal share text, whatever the groups.
	 */
	public function test_organizer_still_cannot_browse_feeds() {
		$this->save( 'a', $this->soap( 'organizer-blind' ), array( $this->g['g1'], $this->g['g2'] ) );
		foreach ( array( $this->g['g1'], $this->g['g2'], $this->g3 ) as $group ) {
			$this->assertWPError( $this->feed( 'organizer', $group ) );
		}
		$this->assertStringNotContainsString( 'organizer-blind', wp_json_encode( jlife_challenges_export_scores( $this->u['organizer'], $this->campaign ) ) );
	}

	/**
	 * The SOAP <form> for a fixture user's Today page.
	 *
	 * @param string $who Fixture user.
	 * @return string
	 */
	private function form_for( $who ) {
		$view = jlife_challenges_day_view( $this->u[ $who ] );
		$body = jlife_challenges_today_body( $view );
		$this->assertSame( 1, preg_match( '#<form method="post" class="jl-soap".*?</form>#s', $body, $m ), 'exactly one SOAP form' );
		return $m[0];
	}

	/**
	 * Simulate a signed-in participant posting the SOAP form for Day 1.
	 *
	 * @param int    $user_id User.
	 * @param string $action  submit|draft.
	 * @param string $text    Text.
	 * @param array  $groups  Group IDs ticked.
	 */
	private function post_soap( $user_id, $action, $text, $groups ) {
		$this->clear_request();
		wp_set_current_user( $user_id );
		$data = array(
			'jl_text'         => $text,
			'jl_action'       => $action,
			'jl_day'          => 'd001',
			'_jlife_soap'     => wp_create_nonce( 'jlife_soap_d001' ),
			'jl_share_groups' => array_map( 'strval', $groups ),
		);
		foreach ( $data as $key => $value ) {
			$_POST[ $key ]    = $value;
			$_REQUEST[ $key ] = $value;
		}
	}

	/**
	 * Remove the simulated form POST.
	 */
	private function clear_request() {
		foreach ( array_keys( $_POST ) as $key ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing -- test helper clearing a simulated POST.
			unset( $_POST[ $key ], $_REQUEST[ $key ] );
		}
	}

	/**
	 * Always clear the simulated request.
	 */
	public function tear_down() {
		$this->clear_request();
		parent::tear_down();
	}
}
