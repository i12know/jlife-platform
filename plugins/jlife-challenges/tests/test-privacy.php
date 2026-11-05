<?php
/**
 * Privacy matrix (docs/challenges-engine-design.md §4): SOAP entries,
 * scores, counts, export, and the membership source.
 *
 * @package jlife-challenges
 */

/**
 * Privacy tests.
 */
class Test_Privacy extends Jlife_Challenges_TestCase {

	/**
	 * Only the author can read a SOAP entry, including by guessing its ID.
	 */
	public function test_soap_entry_is_author_only() {
		$id = jlife_challenges_submit( $this->u['a'], $this->campaign, null, $this->soap( 'secret' ) )['entry_id'];
		$this->assertIsArray( jlife_challenges_get_entry( $this->u['a'], $id ), 'author' );
		foreach ( array( 'b', 'c', 'outsider', 'organizer' ) as $who ) {
			$this->assertWPError( jlife_challenges_get_entry( $this->u[ $who ], $id ), "$who denied" );
		}
		$this->assertWPError( jlife_challenges_get_entry( 0, $id ), 'anonymous denied' );
		$missing = jlife_challenges_get_entry( $this->u['a'], 999999 );
		$this->assertSame( 'jlife_denied', $missing->get_error_code(), 'missing looks the same as forbidden' );
	}

	/**
	 * Score detail: self or organizer; counts and export: organizer only.
	 */
	public function test_scores_visibility() {
		jlife_challenges_submit( $this->u['a'], $this->campaign, null, $this->soap() );
		$this->assertIsArray( jlife_challenges_progress( $this->u['a'], $this->u['a'], $this->campaign ) );
		$this->assertIsArray( jlife_challenges_progress( $this->u['organizer'], $this->u['a'], $this->campaign ) );
		$this->assertWPError( jlife_challenges_progress( $this->u['b'], $this->u['a'], $this->campaign ), 'co-member denied' );
		$this->assertWPError( jlife_challenges_progress( 0, $this->u['a'], $this->campaign ) );

		$this->assertIsArray( jlife_challenges_day_counts( $this->u['organizer'], $this->campaign ) );
		$this->assertWPError( jlife_challenges_day_counts( $this->u['a'], $this->campaign ) );
		$this->assertWPError( jlife_challenges_export_scores( $this->u['a'], $this->campaign ) );
	}

	/**
	 * The export carries points only: no SOAP text, no email, no tokens.
	 */
	public function test_export_has_no_private_text() {
		jlife_challenges_submit( $this->u['a'], $this->campaign, null, $this->soap( 'very-private-words' ) );
		$rows = jlife_challenges_export_scores( $this->u['organizer'], $this->campaign );
		$this->assertCount( 1, $rows );
		$this->assertSame( array( 'user_id', 'name', 'group', 'day_key', 'points', 'status' ), array_keys( $rows[0] ) );
		$this->assertSame( 'Group One', $rows[0]['group'] );
		$flat = wp_json_encode( $rows );
		$this->assertStringNotContainsString( 'very-private-words', $flat );
		$this->assertStringNotContainsString( '@', $flat, 'no email addresses' );
		$counts = jlife_challenges_day_counts( $this->u['organizer'], $this->campaign );
		$this->assertSame( '1', (string) $counts[0]['points'] );
	}

	/**
	 * Removed members can no longer write campaign entries, but keep reading their own.
	 */
	public function test_removed_member() {
		$id = jlife_challenges_submit( $this->u['a'], $this->campaign, null, $this->soap() )['entry_id'];
		$this->assertTrue( jlife_challenges_remove_member( $this->u['organizer'], $this->campaign, $this->u['a'] ) );
		$this->assertWPError( jlife_challenges_save_draft( $this->u['a'], $this->campaign, null, $this->soap() ) );
		$this->assertIsArray( jlife_challenges_get_entry( $this->u['a'], $id ), 'history stays readable' );
		$this->assertWPError( jlife_challenges_remove_member( $this->u['b'], $this->campaign, $this->u['c'] ), 'participants cannot remove others' );
	}

	/**
	 * History stays readable after the campaign ends (Apr 2).
	 */
	public function test_history_after_campaign_end() {
		$id = jlife_challenges_submit( $this->u['a'], $this->campaign, null, $this->soap() )['entry_id'];
		$this->at( '2027-06-01 18:00:00' );
		$this->assertIsArray( jlife_challenges_get_entry( $this->u['a'], $id ) );
		$this->assertSame( 1, jlife_challenges_progress( $this->u['a'], $this->u['a'], $this->campaign )['points'] );
		$this->assertSame( 0, jlife_challenges_current_day( $this->campaign_row() ) );
	}

	/**
	 * Launch groups feed the shared membership gate (D16), with a confirmed-exists flag (#48).
	 */
	public function test_membership_source_for_shared_gate() {
		$m = apply_filters( 'jlife_huddles_group_membership', null, $this->g['g1'] );
		$this->assertTrue( $m['exists'] );
		$this->assertEqualsCanonicalizing( array( $this->u['a'], $this->u['b'] ), $m['member_user_ids'] );
		$this->assertSame( array(), $m['leader_user_ids'], 'no leaders until P2' );
		$this->assertNull( apply_filters( 'jlife_huddles_group_membership', null, 987654 ), 'unknown group: no answer' );
	}

	/**
	 * Only organizers manage campaigns, groups and enrollment.
	 */
	public function test_management_is_organizer_only() {
		$this->assertWPError( jlife_challenges_create_group( $this->u['a'], $this->campaign, 'Mine' ) );
		$this->assertWPError( jlife_challenges_enroll( $this->u['a'], $this->u['outsider'], $this->g['g1'] ) );
		$this->assertWPError( jlife_challenges_move_member( $this->u['a'], $this->u['b'], $this->g['g2'] ) );
		$this->assertWPError(
			jlife_challenges_create_campaign(
				$this->u['a'],
				array(
					'campaign_key' => 'x',
					'plan_id'      => 'p',
					'title'        => 't',
					'start_date'   => '2026-01-01',
					'end_date'     => '2026-01-02',
				)
			)
		);
	}
}
