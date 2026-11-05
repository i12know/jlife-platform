<?php
/**
 * Joining by invite code, age bands, and account deletion.
 *
 * @package jlife-challenges
 */

/**
 * Lifecycle tests.
 */
class Test_Lifecycle extends Jlife_Challenges_TestCase {

	/**
	 * Invite codes: valid joins, stored hashed, reset invalidates, no double enrollment.
	 */
	public function test_invite_codes() {
		$group = jlife_challenges_create_group( $this->u['organizer'], $this->campaign, 'Group Three' );
		$code  = $group['invite_code'];
		$this->assertMatchesRegularExpression( '/^[A-HJ-NP-Z2-9]{8}$/', $code );

		global $wpdb;
		$stored = $wpdb->get_var( $wpdb->prepare( 'SELECT invite_hash FROM ' . jlife_challenges_table( 'groups' ) . ' WHERE id = %d', $group['id'] ) ); // phpcs:ignore WordPress.DB
		$this->assertNotSame( $code, $stored, 'codes are stored hashed' );

		$this->assertIsInt( jlife_challenges_join_with_code( $this->u['outsider'], strtolower( $code ), 'adult' ), 'case-insensitive' );
		$this->assertSame( (int) $group['id'], (int) jlife_challenges_get_enrollment( $this->campaign, $this->u['outsider'] )['group_id'] );
		$again = jlife_challenges_join_with_code( $this->u['outsider'], $code, 'adult' );
		$this->assertSame( 'jlife_already_enrolled', $again->get_error_code(), 'one group per person (D14)' );

		$new_code = jlife_challenges_reset_invite( $this->u['organizer'], $group['id'] );
		$late     = self::factory()->user->create();
		$this->assertSame( 'jlife_invite_invalid', jlife_challenges_join_with_code( $late, $code, 'adult' )->get_error_code(), 'old code stops working' );
		$this->assertIsInt( jlife_challenges_join_with_code( $late, $new_code, 'adult' ) );
		$this->assertWPError( jlife_challenges_join_with_code( 0, $new_code, 'adult' ), 'must be signed in' );
	}

	/**
	 * Expired invite codes are refused.
	 */
	public function test_expired_invite() {
		$code = jlife_challenges_reset_invite( $this->u['organizer'], $this->g['g1'], -1 );
		$this->assertSame( 'jlife_invite_invalid', jlife_challenges_join_with_code( $this->u['outsider'], $code, 'adult' )->get_error_code() );
	}

	/**
	 * Only the two age bands exist (participants are 13 or older).
	 */
	public function test_age_bands() {
		$code = jlife_challenges_reset_invite( $this->u['organizer'], $this->g['g1'] );
		$this->assertSame( 'jlife_age_band', jlife_challenges_join_with_code( $this->u['outsider'], $code, 'child' )->get_error_code() );
	}

	/**
	 * Deleting an account removes entries, revisions, shares, points and enrollment;
	 * the audit records the purge without any text.
	 */
	public function test_account_deletion_purges_everything() {
		require_once ABSPATH . 'wp-admin/includes/user.php';
		$entry = jlife_challenges_submit( $this->u['a'], $this->campaign, null, $this->soap( 'gone' ) )['entry_id'];
		jlife_challenges_share( $this->u['a'], $entry, array( 'observation' ) );
		$user_id = $this->u['a'];
		wp_delete_user( $user_id );

		global $wpdb;
		foreach ( array( 'soap_entries', 'group_shares', 'score_ledger', 'enrollments' ) as $table ) {
			$n = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . jlife_challenges_table( $table ) . ' WHERE user_id = %d', $user_id ) ); // phpcs:ignore WordPress.DB
			$this->assertSame( 0, $n, "$table purged" );
		}
		$revs = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . jlife_challenges_table( 'soap_revisions' ) . ' WHERE entry_id = %d', $entry ) ); // phpcs:ignore WordPress.DB
		$this->assertSame( 0, $revs, 'revisions purged' );
		$this->assertSame( array(), jlife_challenges_group_feed( $this->u['b'], $this->campaign ) );

		$audit = $wpdb->get_var( $wpdb->prepare( 'SELECT detail FROM ' . jlife_challenges_table( 'audit_log' ) . " WHERE action = 'user_purged' AND object_id = %d", $user_id ) ); // phpcs:ignore WordPress.DB
		$this->assertNotNull( $audit );
		$this->assertStringNotContainsString( 'gone', (string) $audit );
	}

	/**
	 * Moves and removals are audited without SOAP text.
	 */
	public function test_membership_changes_are_audited() {
		jlife_challenges_move_member( $this->u['organizer'], $this->u['b'], $this->g['g2'] );
		global $wpdb;
		$detail = $wpdb->get_var( "SELECT detail FROM " . jlife_challenges_table( 'audit_log' ) . " WHERE action = 'member_moved' ORDER BY id DESC LIMIT 1" ); // phpcs:ignore WordPress.DB
		$this->assertSame( array( 'from_group' => (int) $this->g['g1'], 'to_group' => (int) $this->g['g2'] ), json_decode( (string) $detail, true ) );
	}
}
