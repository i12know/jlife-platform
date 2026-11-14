<?php
/**
 * Sharing screens (M7, #62): share page data, chat text, My Group feed.
 *
 * @package jlife-challenges
 */

/**
 * Share-screen tests.
 */
class Test_Share_Screens extends Jlife_Challenges_TestCase {

	/**
	 * Submit a's Day 1 and return the entry ID.
	 *
	 * @param string $user Fixture user name.
	 * @return int
	 */
	private function submit_day1( $user = 'a' ) {
		return (int) jlife_challenges_submit( $this->u[ $user ], $this->campaign, 'd001', $this->soap( $user ) )['entry_id'];
	}

	/**
	 * Nothing written (no entry, or an empty one) is not shareable; a draft with
	 * text can be shared on purpose but is not posted by itself.
	 */
	public function test_nothing_written_is_not_shareable() {
		$v = jlife_challenges_share_view( $this->u['a'], 'd001' );
		$this->assertFalse( $v['shareable'] );
		jlife_challenges_save_draft( $this->u['a'], $this->campaign, 'd001', '' );
		$this->assertFalse( jlife_challenges_share_view( $this->u['a'], 'd001' )['shareable'] );

		jlife_challenges_save_draft( $this->u['a'], $this->campaign, 'd001', 'S: a half-written thought' );
		$v = jlife_challenges_share_view( $this->u['a'], 'd001' );
		$this->assertTrue( $v['shareable'] );
		$this->assertNull( $v['share'] );
		$this->assertSame( array(), jlife_challenges_group_view( $this->u['b'] )['posts'], 'no post until the author posts it' );
	}

	/**
	 * The share page knows whether a post exists. There are no per-part choices.
	 */
	public function test_existing_post_is_known() {
		$entry = $this->submit_day1();
		$v     = jlife_challenges_share_view( $this->u['a'], 'd001' );
		$this->assertTrue( $v['shareable'] );
		$this->assertTrue( $v['can_post'] );
		$this->assertNull( $v['share'] );
		$this->assertArrayNotHasKey( 'checked', $v, 'the whole entry is shared; no parts to choose' );

		jlife_challenges_share( $this->u['a'], $entry );
		$v = jlife_challenges_share_view( $this->u['a'], 'd001' );
		$this->assertSame( 'shared', $v['share']['status'] );
		$this->assertSame( 'shared', jlife_challenges_day_view( $this->u['a'], 'd001' )['share_status'] );
	}

	/**
	 * Double taps and re-posts keep one post; removing it keeps the point.
	 */
	public function test_repost_replaces_and_remove_keeps_points() {
		$entry = $this->submit_day1();
		$id1   = jlife_challenges_share( $this->u['a'], $entry );
		$id2   = jlife_challenges_share( $this->u['a'], $entry );
		$id3   = jlife_challenges_share( $this->u['a'], $entry );
		$this->assertSame( $id1, $id2 );
		$this->assertSame( $id1, $id3 );
		$posts = jlife_challenges_group_view( $this->u['b'] )['posts'];
		$this->assertCount( 1, $posts );
		$this->assertSame( $this->soap( 'a' ), $posts[0]['body'] );

		$this->assertTrue( jlife_challenges_unshare( $this->u['a'], $id1 ) );
		$this->assertSame( array(), jlife_challenges_group_view( $this->u['b'] )['posts'] );
		$this->assertNull( jlife_challenges_share_view( $this->u['a'], 'd001' )['share'] );
		$this->assertSame( 1, jlife_challenges_progress( $this->u['a'], $this->u['a'], $this->campaign )['points'] );
	}

	/**
	 * Only the group sees a post; nobody can post or remove another's.
	 */
	public function test_feed_visibility_and_ownership() {
		$entry = $this->submit_day1();
		$id    = jlife_challenges_share( $this->u['a'], $entry );

		$b = jlife_challenges_group_view( $this->u['b'] );
		$this->assertSame( 'ok', $b['state'] );
		$this->assertSame( 'Group One', $b['group_name'] );
		$this->assertSame( 'Aname', $b['posts'][0]['author'], 'first name only' );
		$this->assertSame( 1, $b['posts'][0]['index'] );
		$this->assertFalse( $b['posts'][0]['mine'] );
		$this->assertStringContainsString( 'Lord, help me', $b['posts'][0]['body'], 'the whole entry' );
		$this->assertTrue( jlife_challenges_group_view( $this->u['a'] )['posts'][0]['mine'] );

		$this->assertSame( array(), jlife_challenges_group_view( $this->u['c'] )['posts'], 'other group sees nothing' );
		$this->assertSame( 'not_enrolled', jlife_challenges_group_view( $this->u['outsider'] )['state'] );

		$this->assertSame( 'jlife_denied', jlife_challenges_share( $this->u['b'], $entry )->get_error_code() );
		$this->assertSame( 'jlife_denied', jlife_challenges_unshare( $this->u['b'], $id )->get_error_code() );
		$this->assertCount( 1, jlife_challenges_group_view( $this->u['b'] )['posts'] );
	}

	/**
	 * Minors without guardian consent cannot post to the group (D19).
	 */
	public function test_guardian_block() {
		$minor = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		jlife_challenges_enroll( $this->u['organizer'], $minor, $this->g['g1'], 'minor' );
		jlife_challenges_submit( $minor, $this->campaign, 'd001', $this->soap( 'minor' ) );
		$v = jlife_challenges_share_view( $minor, 'd001' );
		$this->assertFalse( $v['can_post'] );
		$this->assertSame( 'guardian', $v['post_block'] );
	}

	/**
	 * A post hidden by an organizer cannot be re-posted by the author.
	 */
	public function test_hidden_post_block() {
		$id = jlife_challenges_share( $this->u['a'], $this->submit_day1() );
		jlife_challenges_hide_share( $this->u['organizer'], $id, 'Reported' );
		$v = jlife_challenges_share_view( $this->u['a'], 'd001' );
		$this->assertSame( 'hidden', $v['post_block'] );
		$this->assertFalse( $v['can_post'] );
		$this->assertSame( array(), jlife_challenges_group_view( $this->u['b'] )['posts'] );
	}

	/**
	 * Chat text: the whole entry, the day and a public reading link only —
	 * never a token, sign-in or invite link.
	 */
	public function test_chat_text_is_non_personal() {
		$this->submit_day1();
		$v            = jlife_challenges_share_view( $this->u['a'], 'd001' );
		$v['reading'] = array(
			'url' => home_url( '/reading/demo-d001/?jlife_token=secret#x' ),
			'day' => array( 'passages' => array( array( 'display' => 'John 1:1-18' ) ) ),
		);
		$text         = jlife_challenges_chat_text( $v );
		$this->assertStringContainsString( 'Day 1 · John 1:1-18', $text );
		$this->assertStringContainsString( 'The Word became flesh (a).', $text );
		$this->assertStringContainsString( 'Lord, help me', $text, 'the whole entry' );
		$this->assertStringContainsString( home_url( '/reading/demo-d001/' ), $text );
		foreach ( array( 'jlife_token', 'secret', '/join', '/signin', 'code=', 'Private' ) as $bad ) {
			$this->assertStringNotContainsString( $bad, $text );
		}

		$v['reading']['url'] = 'https://evil.example.test/x';
		$this->assertStringNotContainsString( 'evil', jlife_challenges_chat_text( $v ), 'only links on this site' );
	}
}
