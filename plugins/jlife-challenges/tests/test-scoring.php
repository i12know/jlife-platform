<?php
/**
 * Scoring: one point per person per day, deadlines, edits, backfill.
 *
 * @package jlife-challenges
 */

/**
 * Scoring tests.
 */
class Test_Scoring extends Jlife_Challenges_TestCase {

	/**
	 * A complete, on-time SOAP earns exactly one point.
	 */
	public function test_on_time_submission_earns_one_point() {
		$r = jlife_challenges_submit( $this->u['a'], $this->campaign, null, $this->soap() );
		$this->assertIsArray( $r );
		$this->assertSame( 'd001', $r['day_key'] );
		$this->assertSame( 1, $r['points'] );
		$this->assertSame( 'earned', $r['status'] );
		$this->assertTrue( $r['first_submission'] );
		$p = jlife_challenges_progress( $this->u['a'], $this->u['a'], $this->campaign );
		$this->assertSame( 1, $p['points'] );
		$this->assertSame( 104, $p['day_count'] );
	}

	/**
	 * Double taps, retries and a second device never create a second point.
	 */
	public function test_repeat_submission_is_idempotent() {
		jlife_challenges_submit( $this->u['a'], $this->campaign, null, $this->soap( 'first' ) );
		$r2 = jlife_challenges_submit( $this->u['a'], $this->campaign, null, $this->soap( 'first' ) );
		$r3 = jlife_challenges_submit( $this->u['a'], $this->campaign, 'd001', $this->soap( 'phone two' ) );
		$this->assertSame( 1, $r3['points'] );
		$this->assertFalse( $r2['first_submission'] );
		$this->assertSame( 3, $r3['revision'], 'each save keeps a revision' );
		$this->assertSame( 1, $this->ledger_rows( $this->u['a'] ) );
		$this->assertSame( 1, jlife_challenges_progress( $this->u['a'], $this->u['a'], $this->campaign )['points'] );
	}

	/**
	 * An incomplete SOAP is refused, earns nothing, and the draft is kept.
	 */
	public function test_incomplete_soap_is_refused_and_draft_kept() {
		$fields           = $this->soap();
		$fields['prayer'] = '   ';
		$this->assertIsInt( jlife_challenges_save_draft( $this->u['a'], $this->campaign, null, $fields ) );
		$err = jlife_challenges_submit( $this->u['a'], $this->campaign, null, $fields );
		$this->assertWPError( $err );
		$this->assertSame( 'jlife_soap_incomplete', $err->get_error_code() );
		$this->assertSame( array( 'prayer' ), $err->get_error_data()['missing'] );
		$this->assertSame( 0, $this->ledger_rows( $this->u['a'] ) );
		$entry = jlife_challenges_get_my_entry( $this->u['a'], $this->campaign );
		$this->assertSame( 'draft', $entry['status'] );
		$this->assertSame( $fields['observation'], $entry['observation'], 'typed text is not lost' );
	}

	/**
	 * After the grace window (D1) the entry is stored but earns no point, with a reason.
	 */
	public function test_late_submission_records_no_point_with_reason() {
		$this->at( '2026-12-22 08:00:00' ); // Dec 22, 12:00 AM PST = Day 3.
		$r = jlife_challenges_submit( $this->u['a'], $this->campaign, 'd001', $this->soap() );
		$this->assertSame( 0, $r['points'] );
		$this->assertSame( 'late_no_point', $r['status'] );
		$this->assertSame( 'after_grace_window', $r['reason'] );
	}

	/**
	 * The grace day itself still earns the point.
	 */
	public function test_next_day_still_earns_point() {
		$this->at( '2026-12-22 07:59:00' ); // Dec 21, 11:59 PM PST.
		$this->assertSame( 1, jlife_challenges_submit( $this->u['a'], $this->campaign, 'd001', $this->soap() )['points'] );
	}

	/**
	 * Backfill (D2) is allowed for formation but scores nothing.
	 */
	public function test_backfill_allowed_without_point() {
		$this->at( '2026-12-29 20:00:00' ); // Day 10.
		$r = jlife_challenges_submit( $this->u['a'], $this->campaign, 'd003', $this->soap() );
		$this->assertIsArray( $r );
		$this->assertSame( 0, $r['points'] );
		$this->assertSame( 'late_no_point', jlife_challenges_progress( $this->u['a'], $this->u['a'], $this->campaign )['days']['d003'] );
	}

	/**
	 * Future days cannot be written.
	 */
	public function test_future_day_is_not_available() {
		$err = jlife_challenges_submit( $this->u['a'], $this->campaign, 'd002', $this->soap() );
		$this->assertWPError( $err );
		$this->assertSame( 'jlife_day_invalid', $err->get_error_code() );
		$this->assertWPError( jlife_challenges_save_draft( $this->u['a'], $this->campaign, 'd104', $this->soap() ) );
	}

	/**
	 * Edits after the deadline keep the point earned on time (D3), with history.
	 */
	public function test_late_edit_keeps_point() {
		jlife_challenges_submit( $this->u['a'], $this->campaign, null, $this->soap( 'v1' ) );
		$this->at( '2027-01-05 20:00:00' );
		$r = jlife_challenges_submit( $this->u['a'], $this->campaign, 'd001', $this->soap( 'v2' ) );
		$this->assertSame( 1, $r['points'] );
		$this->assertSame( 'earned', $r['status'] );
		$this->assertSame( 'The Word became flesh (v2).', jlife_challenges_get_my_entry( $this->u['a'], $this->campaign, 'd001' )['observation'] );
		global $wpdb;
		$revs = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . jlife_challenges_table( 'soap_revisions' ) . ' WHERE entry_id = %d', $r['entry_id'] ) ); // phpcs:ignore WordPress.DB
		$this->assertSame( 2, $revs );
	}

	/**
	 * Saving a draft over a submitted entry is refused (no silent un-submit).
	 */
	public function test_draft_cannot_overwrite_submitted_entry() {
		jlife_challenges_submit( $this->u['a'], $this->campaign, null, $this->soap() );
		$err = jlife_challenges_save_draft( $this->u['a'], $this->campaign, null, array( 'scripture' => '' ) );
		$this->assertSame( 'jlife_already_submitted', $err->get_error_code() );
	}

	/**
	 * Not enrolled: no campaign entries. Personal (unscored) SOAP still works, one per day.
	 */
	public function test_unenrolled_and_personal_entries() {
		$this->assertWPError( jlife_challenges_submit( $this->u['outsider'], $this->campaign, null, $this->soap() ) );
		$r = jlife_challenges_submit( $this->u['outsider'], 0, null, $this->soap() );
		$this->assertSame( 'unscored', $r['status'] );
		$this->assertSame( 0, $r['points'] );
		$again = jlife_challenges_submit( $this->u['outsider'], 0, null, $this->soap( 'again' ) );
		$this->assertSame( $r['entry_id'], $again['entry_id'], 'one personal entry per calendar day' );
		$this->assertSame( 0, $this->ledger_rows( $this->u['outsider'] ) );
	}

	/**
	 * Only the four SOAP fields are stored, as plain text.
	 */
	public function test_fields_are_plain_text() {
		$fields                = $this->soap();
		$fields['observation'] = '<script>alert(1)</script>Seen';
		$fields['extra']       = 'ignored';
		$r                     = jlife_challenges_submit( $this->u['a'], $this->campaign, null, $fields );
		$entry                 = jlife_challenges_get_entry( $this->u['a'], $r['entry_id'] );
		$this->assertStringNotContainsString( '<script>', $entry['observation'] );
		$this->assertArrayNotHasKey( 'extra', $entry );
	}
}
