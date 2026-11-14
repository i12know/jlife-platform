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
		$this->assertTrue( $r['passes'] );
		$this->assertSame( array(), $r['reasons'] );
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
	 * An incomplete SOAP is saved (nothing typed is lost) but earns no point,
	 * says what to add, and can still earn the point after an edit.
	 */
	public function test_incomplete_soap_is_saved_without_a_point_and_can_be_completed() {
		$text = $this->soap_without_prayer( 'first try' );
		$r    = jlife_challenges_submit( $this->u['a'], $this->campaign, null, $text );
		$this->assertIsArray( $r, 'saved, not refused' );
		$this->assertFalse( $r['passes'] );
		$this->assertSame( array( 'Add something under P — Prayer' ), $r['reasons'] );
		$this->assertSame( 0, $r['points'] );
		$this->assertSame( 'unscored', $r['status'] );
		$this->assertFalse( $r['first_submission'] );
		$this->assertSame( 0, $this->ledger_rows( $this->u['a'] ) );

		$entry = jlife_challenges_get_my_entry( $this->u['a'], $this->campaign );
		$this->assertSame( 'draft', $entry['status'], 'not submitted until it passes' );
		$this->assertSame( trim( $text ), $entry['body'], 'typed text is kept, line breaks included' );
		$this->assertSame( 'draft', jlife_challenges_day_view( $this->u['a'] )['status'] );

		$done = jlife_challenges_submit( $this->u['a'], $this->campaign, null, $this->soap( 'second try' ) );
		$this->assertTrue( $done['passes'] );
		$this->assertTrue( $done['first_submission'] );
		$this->assertSame( 1, $done['points'] );
		$this->assertSame( 1, $this->ledger_rows( $this->u['a'] ) );
		$this->assertSame( 'submitted', jlife_challenges_get_my_entry( $this->u['a'], $this->campaign )['status'] );
	}

	/**
	 * The bare template, an empty text and a text with an empty part earn nothing.
	 */
	public function test_template_and_empty_text_earn_nothing() {
		foreach ( array( jlife_challenges_soap_template(), '', "S: a\nO:\nA: c\nP: d" ) as $text ) {
			$r = jlife_challenges_submit( $this->u['a'], $this->campaign, null, $text );
			$this->assertFalse( $r['passes'] );
			$this->assertNotEmpty( $r['reasons'] );
			$this->assertSame( 0, $r['points'] );
		}
		$this->assertSame( 0, $this->ledger_rows( $this->u['a'] ) );
	}

	/**
	 * Points are only added: an edit that no longer passes keeps the point and
	 * the entry, saves the new text, and says what is missing.
	 */
	public function test_failing_edit_never_takes_the_point_back() {
		jlife_challenges_submit( $this->u['a'], $this->campaign, null, $this->soap( 'v1' ) );
		$r = jlife_challenges_submit( $this->u['a'], $this->campaign, null, jlife_challenges_soap_template() );
		$this->assertFalse( $r['passes'] );
		$this->assertSame( 1, $r['points'], 'the earlier point stands' );
		$this->assertSame( 'earned', $r['status'] );
		$this->assertSame( 1, $this->ledger_rows( $this->u['a'] ) );
		$this->assertSame( 1, jlife_challenges_progress( $this->u['a'], $this->u['a'], $this->campaign )['points'] );
		$entry = jlife_challenges_get_my_entry( $this->u['a'], $this->campaign );
		$this->assertSame( 'submitted', $entry['status'] );
		$this->assertSame( jlife_challenges_soap_template(), $entry['body'], 'the new text is saved' );
	}

	/**
	 * Each awarded point records the engine and version that passed it, and a
	 * later edit never rewrites that.
	 */
	public function test_ledger_records_engine_and_version() {
		jlife_challenges_submit( $this->u['a'], $this->campaign, null, $this->soap( 'v1' ) );
		jlife_challenges_submit( $this->u['a'], $this->campaign, null, $this->soap( 'v2' ) );
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT engine, engine_version FROM ' . jlife_challenges_table( 'score_ledger' ) . ' WHERE campaign_id = %d AND user_id = %d', $this->campaign, $this->u['a'] ), ARRAY_A ); // phpcs:ignore WordPress.DB
		$this->assertSame(
			array(
				'engine'         => 'soap-regex',
				'engine_version' => '1',
			),
			$row
		);
	}

	/**
	 * After the grace window (D1) the entry is stored but earns no point, with a reason.
	 */
	public function test_late_submission_records_no_point_with_reason() {
		$this->set_now( '2026-12-22 08:00:00' ); // Dec 22, 12:00 AM PST = Day 3.
		$r = jlife_challenges_submit( $this->u['a'], $this->campaign, 'd001', $this->soap() );
		$this->assertSame( 0, $r['points'] );
		$this->assertSame( 'late_no_point', $r['status'] );
		$this->assertSame( 'after_grace_window', $r['reason'] );
	}

	/**
	 * The grace day itself still earns the point.
	 */
	public function test_next_day_still_earns_point() {
		$this->set_now( '2026-12-22 07:59:00' ); // Dec 21, 11:59 PM PST.
		$this->assertSame( 1, jlife_challenges_submit( $this->u['a'], $this->campaign, 'd001', $this->soap() )['points'] );
	}

	/**
	 * Backfill (D2) is allowed for formation but scores nothing.
	 */
	public function test_backfill_allowed_without_point() {
		$this->set_now( '2026-12-29 20:00:00' ); // Day 10.
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
		$this->set_now( '2027-01-05 20:00:00' );
		$r = jlife_challenges_submit( $this->u['a'], $this->campaign, 'd001', $this->soap( 'v2' ) );
		$this->assertSame( 1, $r['points'] );
		$this->assertSame( 'earned', $r['status'] );
		$this->assertStringContainsString( 'The Word became flesh (v2).', jlife_challenges_get_my_entry( $this->u['a'], $this->campaign, 'd001' )['body'] );
		global $wpdb;
		$revs = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . jlife_challenges_table( 'soap_revisions' ) . ' WHERE entry_id = %d', $r['entry_id'] ) ); // phpcs:ignore WordPress.DB
		$this->assertSame( 2, $revs );
	}

	/**
	 * Saving a draft over a submitted entry is refused (no silent un-submit).
	 */
	public function test_draft_cannot_overwrite_submitted_entry() {
		jlife_challenges_submit( $this->u['a'], $this->campaign, null, $this->soap() );
		$err = jlife_challenges_save_draft( $this->u['a'], $this->campaign, null, '' );
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
	 * The entry is stored as plain text with its line breaks.
	 */
	public function test_text_is_plain_with_line_breaks() {
		$r     = jlife_challenges_submit( $this->u['a'], $this->campaign, null, str_replace( "\n", "\r\n", $this->soap() ) . '<script>alert(1)</script>' );
		$entry = jlife_challenges_get_entry( $this->u['a'], $r['entry_id'] );
		$this->assertStringNotContainsString( '<script>', $entry['body'] );
		$this->assertStringNotContainsString( "\r", $entry['body'], 'line breaks are stored as \\n' );
		$this->assertStringContainsString( "S — Scripture\nJohn 1:14 (x)\n\nO — Observation\n", $entry['body'] );
		$this->assertSame( jlife_challenges_clean_text( str_repeat( 'a', JLIFE_CHALLENGES_BODY_MAX + 50 ) ), str_repeat( 'a', JLIFE_CHALLENGES_BODY_MAX ), 'capped' );
	}
}
