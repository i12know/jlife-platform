<?php
/**
 * S5 access-control tests (#12): the permission matrix, executable.
 *
 * Matrix source: docs/spikes/S5-privacy-model.md. Actors: member, second
 * member (same huddle), other-huddle member, huddle leader, other huddle's
 * leader, site admin, anonymous — plus the S4 magic-link actor. Membership
 * comes from a test fixture via the jlife_huddles_group_membership filter
 * (production uses the jlife-bridge live provider); an unknown group fails
 * closed, which the fixture-off tests also prove.
 *
 * These are #16's required access-control checks: CI fails on regression.
 *
 * @package jlife-huddles
 */

/**
 * Access-control matrix tests for threads, private notes, and progress.
 */
class Test_Access_Control extends WP_UnitTestCase {

	const G1 = 801; // Huddle One (fixture dt_group_id).
	const G2 = 802; // Huddle Two.

	/**
	 * Fixture users, keyed by role-in-matrix.
	 *
	 * @var array<string,int>
	 */
	protected static $u = array();

	/**
	 * Fixture membership currently served by the filter.
	 *
	 * @var array<int,array>
	 */
	protected static $membership = array();

	/**
	 * Create tables and actors once.
	 *
	 * @param WP_UnitTest_Factory $factory Factory.
	 */
	public static function wpSetUpBeforeClass( $factory ) {
		jlife_huddles_install_tables();

		self::$u = array(
			'member1' => $factory->user->create( array( 'role' => 'subscriber' ) ),
			'member2' => $factory->user->create( array( 'role' => 'subscriber' ) ),
			'other'   => $factory->user->create( array( 'role' => 'subscriber' ) ),
			'leader1' => $factory->user->create( array( 'role' => 'subscriber' ) ),
			'leader2' => $factory->user->create( array( 'role' => 'subscriber' ) ),
			'admin'   => $factory->user->create( array( 'role' => 'administrator' ) ),
		);
	}

	/**
	 * Arm the standard two-huddle fixture before every test.
	 */
	public function set_up() {
		parent::set_up();
		self::$membership = array(
			self::G1 => array(
				'leader_user_ids' => array( self::$u['leader1'] ),
				'member_user_ids' => array( self::$u['member1'], self::$u['member2'] ),
			),
			self::G2 => array(
				'leader_user_ids' => array( self::$u['leader2'] ),
				'member_user_ids' => array( self::$u['other'] ),
			),
		);
		add_filter( 'jlife_huddles_group_membership', array( $this, 'fixture_membership' ), 10, 2 );
	}

	/**
	 * Fixture provider.
	 *
	 * @param array|null $membership Incoming (null).
	 * @param int        $dt_group_id Group.
	 * @return array|null
	 */
	public function fixture_membership( $membership, $dt_group_id ) {
		return isset( self::$membership[ $dt_group_id ] ) ? self::$membership[ $dt_group_id ] : $membership;
	}

	// ── Threads ─────────────────────────────────────────────────────────

	/**
	 * Thread read/write across the full actor matrix.
	 */
	public function test_thread_matrix() {
		$id = jlife_huddles_create_thread_post( self::$u['member1'], self::G1, 'lsn-x', 'Xin chào từ member1 — thảo luận nhóm.' );
		$this->assertIsInt( $id, 'member can post to own huddle thread' );

		// Reads.
		$this->assertIsArray( jlife_huddles_get_thread_posts( self::$u['member1'], self::G1, 'lsn-x' ), 'member reads own huddle' );
		$this->assertIsArray( jlife_huddles_get_thread_posts( self::$u['member2'], self::G1, 'lsn-x' ), 'co-member reads' );
		$this->assertIsArray( jlife_huddles_get_thread_posts( self::$u['leader1'], self::G1, 'lsn-x' ), 'leader reads own huddle' );
		$this->assertIsArray( jlife_huddles_get_thread_posts( self::$u['admin'], self::G1, 'lsn-x' ), 'site admin may read (disclosed)' );
		$this->assertWPError( jlife_huddles_get_thread_posts( self::$u['other'], self::G1, 'lsn-x' ), 'other-huddle member denied' );
		$this->assertWPError( jlife_huddles_get_thread_posts( self::$u['leader2'], self::G1, 'lsn-x' ), 'other huddle leader denied' );
		$this->assertWPError( jlife_huddles_get_thread_posts( 0, self::G1, 'lsn-x' ), 'anonymous denied' );

		// Writes.
		$this->assertIsInt( jlife_huddles_create_thread_post( self::$u['leader1'], self::G1, 'lsn-x', 'leader reply' ), 'leader writes' );
		$this->assertWPError( jlife_huddles_create_thread_post( self::$u['other'], self::G1, 'lsn-x', 'x' ), 'non-member cannot write' );
		$this->assertWPError( jlife_huddles_create_thread_post( self::$u['leader2'], self::G1, 'lsn-x', 'x' ), 'other leader cannot write' );
		$this->assertWPError( jlife_huddles_create_thread_post( self::$u['admin'], self::G1, 'lsn-x', 'x' ), 'admin reads but does not write' );
		$this->assertWPError( jlife_huddles_create_thread_post( 0, self::G1, 'lsn-x', 'x' ), 'anonymous cannot write' );
	}

	/**
	 * Guessing a thread row ID from another huddle earns the same denial.
	 */
	public function test_thread_id_guessing_cross_huddle() {
		$id = jlife_huddles_create_thread_post( self::$u['member1'], self::G1, 'lsn-x', 'secret-ish huddle talk' );
		$this->assertIsInt( $id );

		$this->assertIsArray( jlife_huddles_get_thread_post( self::$u['member2'], $id ), 'co-member fetches by ID' );
		$this->assertWPError( jlife_huddles_get_thread_post( self::$u['other'], $id ), 'cross-huddle ID guess denied' );
		$this->assertWPError( jlife_huddles_get_thread_post( self::$u['leader2'], $id ), 'other leader ID guess denied' );
		$this->assertWPError( jlife_huddles_get_thread_post( 0, $id ), 'anonymous ID guess denied' );
		$this->assertWPError( jlife_huddles_get_thread_post( self::$u['member1'], 999999 ), 'missing row indistinguishable from denial' );
	}

	// ── Private notes ───────────────────────────────────────────────────

	/**
	 * Private notes are author-only: not leaders, not admins, not anyone.
	 */
	public function test_private_note_author_only() {
		$note_id = jlife_huddles_save_private_note( self::$u['member1'], self::G1, 'lsn-author', 'Suy ngẫm riêng tư — không ai được đọc.' );
		$this->assertIsInt( $note_id, 'author saves own note' );

		$note = jlife_huddles_get_private_note( self::$u['member1'], $note_id );
		$this->assertIsArray( $note, 'author reads own note' );
		$this->assertSame( self::G1, (int) $note['dt_group_id'], 'note stores huddle scope' );
		$this->assertWPError( jlife_huddles_get_private_note( self::$u['leader1'], $note_id ), 'own huddle leader denied' );
		$this->assertWPError( jlife_huddles_get_private_note( self::$u['member2'], $note_id ), 'co-member denied' );
		$this->assertWPError( jlife_huddles_get_private_note( self::$u['other'], $note_id ), 'other-huddle member denied' );
		$this->assertWPError( jlife_huddles_get_private_note( self::$u['admin'], $note_id ), 'site admin denied at app layer' );
		$this->assertWPError( jlife_huddles_get_private_note( 0, $note_id ), 'anonymous denied' );

		$this->assertWPError( jlife_huddles_save_private_note( 0, self::G1, 'lsn-author', 'x' ), 'anonymous cannot write notes' );
		$this->assertWPError( jlife_huddles_save_private_note( self::$u['other'], self::G1, 'lsn-author', 'x' ), 'other-huddle member cannot write into this note scope' );
	}

	/**
	 * Private notes key by user + huddle + lesson, not user + lesson alone.
	 */
	public function test_private_note_scope_includes_huddle() {
		self::$membership[ self::G2 ]['member_user_ids'][] = self::$u['member1'];

		$g1_note_id = jlife_huddles_save_private_note( self::$u['member1'], self::G1, 'lsn-scope', 'G1 private reflection' );
		$g2_note_id = jlife_huddles_save_private_note( self::$u['member1'], self::G2, 'lsn-scope', 'G2 private reflection' );

		$this->assertIsInt( $g1_note_id );
		$this->assertIsInt( $g2_note_id );
		$this->assertNotSame( $g1_note_id, $g2_note_id, 'same lesson in two huddles creates two note rows' );

		$g1_note = jlife_huddles_get_private_note( self::$u['member1'], $g1_note_id );
		$g2_note = jlife_huddles_get_private_note( self::$u['member1'], $g2_note_id );

		$this->assertSame( self::G1, (int) $g1_note['dt_group_id'] );
		$this->assertSame( self::G2, (int) $g2_note['dt_group_id'] );
		$this->assertSame( 'G1 private reflection', $g1_note['body'] );
		$this->assertSame( 'G2 private reflection', $g2_note['body'] );
	}

	/**
	 * Vietnamese text round-trips intact through note storage (utf8mb4).
	 */
	public function test_vietnamese_text_integrity() {
		$body    = 'Đức Chúa Giê-xu phán: «Hãy theo Ta» — ghi chú tiếng Việt đầy đủ dấu.';
		$note_id = jlife_huddles_save_private_note( self::$u['member2'], self::G1, 'lsn-vi', $body );
		$note    = jlife_huddles_get_private_note( self::$u['member2'], $note_id );
		$this->assertIsArray( $note );
		$this->assertSame( $body, $note['body'], 'diacritics survive storage round-trip' );
	}

	// ── Progress ────────────────────────────────────────────────────────

	/**
	 * Progress: self detail; leader flags only; aggregate for leader/admin.
	 */
	public function test_progress_matrix() {
		$this->assertTrue( jlife_huddles_set_progress( self::$u['member1'], self::G1, 'lsn-x', 'completed' ) );
		$this->assertWPError( jlife_huddles_set_progress( self::$u['other'], self::G1, 'lsn-x', 'completed' ), 'non-member cannot set progress in huddle' );

		// Self detail.
		$this->assertTrue( jlife_huddles_can_read_progress( self::$u['member1'], self::$u['member1'], self::G1 ) );
		$this->assertFalse( jlife_huddles_can_read_progress( self::$u['other'], self::$u['other'], self::G1 ), 'self detail still requires current huddle membership' );
		$this->assertFalse( jlife_huddles_can_read_progress( self::$u['member2'], self::$u['member1'], self::G1 ), 'co-member cannot read another member detail' );
		$this->assertFalse( jlife_huddles_can_read_progress( self::$u['leader1'], self::$u['member1'], self::G1 ), 'leader uses flags, not member detail' );

		// Leader flags: status values only — the shape cannot carry bodies.
		$flags = jlife_huddles_get_progress_flags( self::$u['leader1'], self::G1, 'lsn-x' );
		$this->assertIsArray( $flags );
		$this->assertSame( array( 'user_id', 'status' ), array_keys( $flags[0] ), 'flags expose user_id and status only' );
		$this->assertWPError( jlife_huddles_get_progress_flags( self::$u['leader2'], self::G1, 'lsn-x' ), 'other leader denied flags' );
		$this->assertWPError( jlife_huddles_get_progress_flags( self::$u['member1'], self::G1, 'lsn-x' ), 'members do not get member-level flags' );
		$this->assertWPError( jlife_huddles_get_progress_flags( 0, self::G1, 'lsn-x' ), 'anonymous denied flags' );

		// Aggregate: leader and admin only; counts only.
		$agg = jlife_huddles_get_progress_aggregate( self::$u['leader1'], self::G1 );
		$this->assertSame( array( 'completed' => 1, 'started' => 0 ), $agg ); // phpcs:ignore WordPress.Arrays -- compact literal.
		$this->assertIsArray( jlife_huddles_get_progress_aggregate( self::$u['admin'], self::G1 ) );
		$this->assertWPError( jlife_huddles_get_progress_aggregate( self::$u['other'], self::G1 ) );
		$this->assertWPError( jlife_huddles_get_progress_aggregate( self::$u['member1'], self::G1 ), 'members see own detail, not huddle aggregate' );
	}

	// ── Membership dynamics & fail-closed ───────────────────────────────

	/**
	 * Removing a member (D.T removal mirrored by the provider) revokes
	 * access immediately — the stale-membership case.
	 */
	public function test_stale_membership_revokes_access() {
		$this->assertIsArray( jlife_huddles_get_thread_posts( self::$u['member2'], self::G1, 'lsn-x' ) );
		$note_id = jlife_huddles_save_private_note( self::$u['member2'], self::G1, 'lsn-stale', 'private before removal' );
		$this->assertIsInt( $note_id );
		$this->assertTrue( jlife_huddles_set_progress( self::$u['member2'], self::G1, 'lsn-stale', 'started' ) );
		$this->assertIsArray( jlife_huddles_get_private_note( self::$u['member2'], $note_id ) );
		$this->assertTrue( jlife_huddles_can_read_progress( self::$u['member2'], self::$u['member2'], self::G1 ) );

		self::$membership[ self::G1 ]['member_user_ids'] = array( self::$u['member1'] ); // member2 removed in D.T.

		$this->assertWPError( jlife_huddles_get_thread_posts( self::$u['member2'], self::G1, 'lsn-x' ), 'removed member loses read immediately' );
		$this->assertWPError( jlife_huddles_create_thread_post( self::$u['member2'], self::G1, 'lsn-x', 'x' ), 'removed member loses write immediately' );
		$this->assertWPError( jlife_huddles_get_private_note( self::$u['member2'], $note_id ), 'removed member loses note read immediately' );
		$this->assertWPError( jlife_huddles_save_private_note( self::$u['member2'], self::G1, 'lsn-stale', 'x' ), 'removed member loses note write immediately' );
		$this->assertFalse( jlife_huddles_can_read_progress( self::$u['member2'], self::$u['member2'], self::G1 ), 'removed member loses progress read immediately' );
		$this->assertWPError( jlife_huddles_set_progress( self::$u['member2'], self::G1, 'lsn-stale', 'completed' ), 'removed member loses progress write immediately' );
	}

	/**
	 * Unknown group (no fixture, no bridge) fails closed for everyone.
	 */
	public function test_unknown_group_fails_closed() {
		$this->assertWPError( jlife_huddles_get_thread_posts( self::$u['member1'], 999, 'lsn-x' ) );
		$this->assertWPError( jlife_huddles_create_thread_post( self::$u['leader1'], 999, 'lsn-x', 'x' ) );
		$this->assertWPError( jlife_huddles_save_private_note( self::$u['member1'], 999, 'lsn-x', 'x' ) );
		$this->assertFalse( jlife_huddles_can_read_progress( self::$u['member1'], self::$u['member1'], 999 ) );
		$this->assertWPError( jlife_huddles_get_progress_flags( self::$u['leader1'], 999, 'lsn-x' ) );
	}

	// ── S4 magic-link rule ──────────────────────────────────────────────

	/**
	 * Bearer-token actors may touch progress and leader-visible responses
	 * only — never threads or private notes (the S4/S5 shared rule).
	 */
	public function test_magic_link_actor_surfaces() {
		$this->assertTrue( jlife_huddles_link_actor_can( 'progress' ) );
		$this->assertTrue( jlife_huddles_link_actor_can( 'leader_response' ) );
		$this->assertFalse( jlife_huddles_link_actor_can( 'thread' ) );
		$this->assertFalse( jlife_huddles_link_actor_can( 'private_note' ) );

		$this->assertTrue( jlife_huddles_link_set_progress( 5, self::G1, 'lsn-x', 'completed' ) );
		$agg = jlife_huddles_get_progress_aggregate( self::$u['leader1'], self::G1 );
		$this->assertIsArray( $agg );
		$this->assertGreaterThanOrEqual( 1, $agg['completed'], 'link-actor progress lands in the aggregate' );
	}

	// ── Account deletion ────────────────────────────────────────────────

	/**
	 * Deleting an account purges the user's private notes and progress.
	 */
	public function test_account_deletion_purges_private_data() {
		$victim = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		self::$membership[ self::G1 ]['member_user_ids'][] = $victim;

		$note_id = jlife_huddles_save_private_note( $victim, self::G1, 'lsn-delete', 'sẽ bị xóa cùng tài khoản' );
		$this->assertIsInt( $note_id );
		$this->assertTrue( jlife_huddles_set_progress( $victim, self::G1, 'lsn-x', 'started' ) );

		require_once ABSPATH . 'wp-admin/includes/user.php';
		wp_delete_user( $victim );

		$this->assertWPError( jlife_huddles_get_private_note( $victim, $note_id ), 'note gone after account deletion' );
		global $wpdb;
		$left = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}jlife_progress WHERE user_id = %d", $victim ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- direct row-count check in test.
		$this->assertSame( 0, $left, 'progress rows purged' );
	}
}
