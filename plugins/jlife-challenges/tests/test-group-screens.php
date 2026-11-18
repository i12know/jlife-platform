<?php
/**
 * The /group and /share screens for starting, leaving and resetting (#112 part
 * 2): what a person sees and what each form post does. The access rules
 * themselves are in test-start-group.php.
 *
 * @package jlife-challenges
 */

// phpcs:disable WordPress.Security.NonceVerification, WordPress.DB.DirectDatabaseQuery -- tests drive the handlers with crafted requests.

/**
 * Group screen tests.
 */
class Test_Group_Screens extends Jlife_Challenges_TestCase {

	/**
	 * Make wp_safe_redirect throw instead of sending a header.
	 */
	public function set_up() {
		parent::set_up();
		add_filter(
			'wp_redirect',
			static function ( $location ) {
				throw new RuntimeException( (string) $location ); // A handler that redirects and exits can then be tested.
			}
		);
	}

	/**
	 * Clean request state.
	 */
	public function tear_down() {
		$_POST    = array();
		$_REQUEST = array();
		$_GET     = array();
		wp_set_current_user( 0 );
		parent::tear_down();
	}

	/**
	 * Act as a user and post a form to /group or /share.
	 *
	 * @param int    $user_id Signed-in user.
	 * @param array  $post    Fields.
	 * @param string $nonce   Nonce action.
	 * @param string $field   Nonce field name.
	 */
	private function post_as( $user_id, $post, $nonce, $field ) {
		wp_set_current_user( $user_id );
		$_POST    = $post + array( $field => wp_create_nonce( $nonce ) );
		$_REQUEST = $_POST;
	}

	/**
	 * A fresh adult, solo in the campaign.
	 *
	 * @return int
	 */
	private function adult() {
		$id = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		jlife_challenges_enroll_solo( $id, $this->campaign, 'adult' );
		return $id;
	}

	/**
	 * The code shown on a page that revealed one.
	 *
	 * @param string $html Page body.
	 * @return string
	 */
	private function code_in( $html ) {
		preg_match( '/class="jl-code"[^>]*>([A-Z2-9]{8})</', $html, $m );
		return $m[1] ?? '';
	}

	/**
	 * Starting a group from /group shows the code and a full invite link once, with copy buttons.
	 */
	public function test_start_from_group_shows_the_code_and_link_once() {
		$u = $this->adult();
		$this->post_as(
			$u,
			array(
				'jl_group_action' => 'start',
				'jl_group_name'   => 'Sunday friends',
				'jl_start_token'  => 'tok1',
			),
			'jlife_group_manage',
			'_jlife_group'
		);
		$r    = jlife_challenges_handle_manage_post( $u );
		$code = $this->code_in( $r['body'] );
		$this->assertSame( '', $r['error'] );
		$this->assertNotSame( '', $code );
		$this->assertStringContainsString( 'Sunday friends', $r['body'] );
		$this->assertStringContainsString( esc_html( add_query_arg( 'code', $code, home_url( '/join/' ) ) ), $r['body'], 'the full invite link' );
		$this->assertSame( 2, substr_count( $r['body'], 'data-copy=' ), 'copy the code, copy the link' );
		$this->assertNotNull( jlife_challenges_group_for_invite( $code ) );

		// The same form sent again (a reload or a double tap) never makes a second group.
		global $wpdb;
		$before = (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . jlife_challenges_table( 'groups' ) ); // phpcs:ignore WordPress.DB
		$again  = jlife_challenges_handle_manage_post( $u );
		$this->assertSame( '', $again['body'] );
		$this->assertNotSame( '', $again['error'] );
		$this->assertSame( $before, (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . jlife_challenges_table( 'groups' ) ) ); // phpcs:ignore WordPress.DB

		// The page afterwards never shows the code again: only the hash is stored.
		$view = jlife_challenges_group_body( jlife_challenges_group_view( $u ) );
		$this->assertStringNotContainsString( $code, $view );
	}

	/**
	 * A refused start keeps what was typed and shows the reason in the start form.
	 */
	public function test_a_refused_start_explains_itself_and_keeps_the_name() {
		$u = $this->adult();
		$this->post_as(
			$u,
			array(
				'jl_group_action' => 'start',
				'jl_group_name'   => str_repeat( 'x', 61 ),
				'jl_start_token'  => 'tok2',
			),
			'jlife_group_manage',
			'_jlife_group'
		);
		$r = jlife_challenges_handle_manage_post( $u );
		$this->assertSame( '', $r['body'] );
		$this->assertNotSame( '', $r['error'] );
		$html = jlife_challenges_group_body(
			jlife_challenges_group_view( $u ),
			'',
			'',
			array(
				'start_error' => $r['error'],
				'start_name'  => 'My typed name',
			)
		);
		$this->assertStringContainsString( esc_html( $r['error'] ), $html );
		$this->assertStringContainsString( 'value="My typed name"', $html );
	}

	/**
	 * A forged post to start as a minor is refused by the server, whatever the page showed.
	 */
	public function test_a_forged_start_post_from_a_minor_is_refused() {
		$m = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		jlife_challenges_enroll_solo( $m, $this->campaign, 'minor' );
		$this->post_as(
			$m,
			array(
				'jl_group_action' => 'start',
				'jl_group_name'   => 'Forged',
				'jl_start_token'  => 'tok3',
			),
			'jlife_group_manage',
			'_jlife_group'
		);
		$r = jlife_challenges_handle_manage_post( $m );
		$this->assertSame( '', $r['body'] );
		$this->assertNotSame( '', $r['error'] );
		$this->assertSame( array(), jlife_challenges_active_memberships( $this->campaign, $m ) );
	}

	/**
	 * Making a new code from /group: the starter gets one; anyone else is refused and the code is unchanged.
	 */
	public function test_a_new_code_from_group_is_for_the_starter_only() {
		$u = $this->adult();
		$g = jlife_challenges_start_group( $u, 'Mine' );
		jlife_challenges_join_with_code( $this->u['outsider'], $g['invite_code'], 'adult' );

		$this->post_as(
			$this->u['outsider'],
			array(
				'jl_group_action' => 'reset',
				'jl_group_id'     => $g['id'],
			),
			'jlife_group_manage',
			'_jlife_group'
		);
		$r = jlife_challenges_handle_manage_post( $this->u['outsider'] );
		$this->assertSame( '', $r['body'] );
		$this->assertNotSame( '', $r['error'] );
		$this->assertNotNull( jlife_challenges_group_for_invite( $g['invite_code'] ), 'the code still works' );

		$this->post_as(
			$u,
			array(
				'jl_group_action' => 'reset',
				'jl_group_id'     => $g['id'],
			),
			'jlife_group_manage',
			'_jlife_group'
		);
		$r    = jlife_challenges_handle_manage_post( $u );
		$code = $this->code_in( $r['body'] );
		$this->assertNotSame( '', $code );
		$this->assertNotSame( $g['invite_code'], $code );
		$this->assertNull( jlife_challenges_group_for_invite( $g['invite_code'] ) );
		$this->assertStringContainsString( 'data-copy=', $r['body'] );
	}

	/**
	 * An organizer account cannot use the participant screen to read or reset a group it does not belong to.
	 */
	public function test_the_participant_screen_does_not_hand_organizers_codes() {
		$u = $this->adult();
		$g = jlife_challenges_start_group( $u, 'Mine' );
		$this->post_as(
			$this->u['organizer'],
			array(
				'jl_group_action' => 'reset',
				'jl_group_id'     => $g['id'],
			),
			'jlife_group_manage',
			'_jlife_group'
		);
		$r = jlife_challenges_handle_manage_post( $this->u['organizer'] );
		$this->assertSame( '', $r['body'], 'organizers reset codes with WP-CLI, not here' );
		$this->assertNotNull( jlife_challenges_group_for_invite( $g['invite_code'] ) );
	}

	/**
	 * Leaving asks first (a confirmation page), then redirects; the slot is free and the feed is closed.
	 */
	public function test_leaving_needs_a_confirmation_and_ends_membership() {
		$view = jlife_challenges_group_view( $this->u['a'], (int) $this->g['g1'] );
		$html = jlife_challenges_group_confirm( $view, 'leave' );
		$this->assertStringContainsString( 'Leave Group One?', $html );
		$this->assertStringContainsString( 'name="jl_group_action" value="leave"', $html );
		$this->assertStringContainsString( 'name="_jlife_group"', $html, 'the form carries a nonce' );
		$this->assertStringContainsString( 'Cancel', $html );
		$this->assertStringContainsString( 'stays visible to the group unless you remove it', $html );

		$this->post_as(
			$this->u['a'],
			array(
				'jl_group_action' => 'leave',
				'jl_group_id'     => $this->g['g1'],
			),
			'jlife_group_manage',
			'_jlife_group'
		);
		try {
			jlife_challenges_handle_manage_post( $this->u['a'] );
			$this->fail( 'a successful leave redirects' );
		} catch ( RuntimeException $e ) {
			$this->assertStringContainsString( 'left=1', $e->getMessage() );
		}
		$this->assertFalse( jlife_challenges_is_group_member( $this->u['a'], $this->g['g1'] ) );
	}

	/**
	 * Leaving a group you are not in, or somebody else's, is denied.
	 */
	public function test_leaving_someone_elses_group_is_denied() {
		$this->post_as(
			$this->u['outsider'],
			array(
				'jl_group_action' => 'leave',
				'jl_group_id'     => $this->g['g1'],
			),
			'jlife_group_manage',
			'_jlife_group'
		);
		$r = jlife_challenges_handle_manage_post( $this->u['outsider'] );
		$this->assertNotSame( '', $r['error'] );
		$this->assertTrue( jlife_challenges_is_group_member( $this->u['a'], $this->g['g1'] ), 'the real members are untouched' );
	}

	/**
	 * The /group message after leaving points to the old-shares list.
	 */
	public function test_the_left_message_links_to_old_shares() {
		jlife_challenges_leave_group( $this->u['a'], $this->g['g1'] );
		$html = jlife_challenges_group_body( jlife_challenges_group_view( $this->u['a'] ), '', '', array( 'left' => true ) );
		$this->assertStringContainsString( 'You left the group', $html );
		$this->assertStringContainsString( esc_url( home_url( '/share/' ) ), $html );
	}

	/**
	 * "Groups you left": lists the author's old shares with a remove button, and nothing for a person with none.
	 */
	public function test_groups_you_left_section_on_share() {
		wp_set_current_user( $this->u['a'] );
		$this->assertSame( '', jlife_challenges_left_groups_section( $this->u['a'] ) );

		$entry = jlife_challenges_submit( $this->u['a'], $this->campaign, 'd001', $this->soap( 'old' ) )['entry_id'];
		$share = jlife_challenges_share( $this->u['a'], $entry, $this->g['g1'] );
		$this->assertSame( '', jlife_challenges_left_groups_section( $this->u['a'] ), 'still in the group' );

		jlife_challenges_leave_group( $this->u['a'], $this->g['g1'] );
		$html = jlife_challenges_left_groups_section( $this->u['a'] );
		$this->assertStringContainsString( 'Groups you left', $html );
		$this->assertStringContainsString( 'Group One · Day 1', $html );
		$this->assertStringContainsString( 'name="jl_unshare_old" value="' . $share . '"', $html );
		$this->assertStringNotContainsString( '(old)', $html, 'never the text itself' );

		// Another person's page has no such section.
		wp_set_current_user( $this->u['b'] );
		$this->assertSame( '', jlife_challenges_left_groups_section( $this->u['b'] ) );
	}

	/**
	 * Removing an old share through the form clears it; a share that is not one of the author's old ones is refused.
	 */
	public function test_removing_an_old_share_through_the_form() {
		$entry_a = jlife_challenges_submit( $this->u['a'], $this->campaign, 'd001', $this->soap( 'a' ) )['entry_id'];
		$entry_b = jlife_challenges_submit( $this->u['b'], $this->campaign, 'd001', $this->soap( 'b' ) )['entry_id'];
		$share_a = jlife_challenges_share( $this->u['a'], $entry_a, $this->g['g1'] );
		$share_b = jlife_challenges_share( $this->u['b'], $entry_b, $this->g['g1'] );
		jlife_challenges_leave_group( $this->u['a'], $this->g['g1'] );

		// A cannot remove B's share, nor one in a group A is still in.
		$this->post_as( $this->u['a'], array( 'jl_unshare_old' => $share_b ), 'jlife_unshare_old', '_jlife_unshare' );
		$this->assertNotSame( '', jlife_challenges_handle_old_share_post( $this->u['a'] ) );
		$this->assertCount( 2, jlife_challenges_group_feed( $this->u['b'], $this->g['g1'] ) );

		$this->post_as( $this->u['a'], array( 'jl_unshare_old' => $share_a ), 'jlife_unshare_old', '_jlife_unshare' );
		try {
			jlife_challenges_handle_old_share_post( $this->u['a'] );
			$this->fail( 'a successful removal redirects' );
		} catch ( RuntimeException $e ) {
			$this->assertStringContainsString( 'removed_old=1', $e->getMessage() );
		}
		$feed = jlife_challenges_group_feed( $this->u['b'], $this->g['g1'] );
		$this->assertCount( 1, $feed );
		$this->assertStringContainsString( '(b)', $feed[0]['body'] );
	}

	/**
	 * The not-enrolled welcome offers all three ways in.
	 */
	public function test_welcome_links_to_all_three_ways_in() {
		$html = (string) jlife_challenges_render_no_day( array( 'state' => 'not_enrolled' ) );
		$this->assertStringContainsString( esc_url( home_url( '/join/' ) ), $html );
		$this->assertStringContainsString( esc_url( add_query_arg( 'mode', 'solo', home_url( '/join/' ) ) ), $html );
		$this->assertStringContainsString( esc_url( add_query_arg( 'mode', 'start', home_url( '/join/' ) ) ), $html );
	}

	/**
	 * Organizers can manage groups from WP-CLI's functions; the participant screens never print a code hash.
	 */
	public function test_no_page_prints_the_invite_hash() {
		$u   = $this->adult();
		$g   = jlife_challenges_start_group( $u, 'Mine' );
		$row = jlife_challenges_get_group( $g['id'] );
		$this->assertNotSame( '', $row['invite_hash'] );
		$html = jlife_challenges_group_body( jlife_challenges_group_view( $u, (int) $g['id'] ) );
		$this->assertStringNotContainsString( $row['invite_hash'], $html );
		$this->assertStringNotContainsString( $g['invite_code'], $html );
	}
}
