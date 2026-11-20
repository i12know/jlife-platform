<?php
/**
 * The Dec 5–13 pilot campaign, its practice banner and the "I'll start Dec 20"
 * flag (#178). Written before the code. The pilot's key does not start with
 * `test-`, so the staging cleanup never touches it (#174).
 *
 * @package jlife-challenges
 */

/**
 * Pilot campaign tests.
 */
class Test_Pilot_Campaign extends Jlife_Challenges_TestCase {

	/**
	 * The pilot created through the supported step.
	 *
	 * @var int
	 */
	private $pilot = 0;

	/**
	 * Create the pilot the documented way.
	 */
	public function set_up() {
		parent::set_up();
		$made = jlife_challenges_create_pilot_campaign( $this->u['organizer'] );
		$this->assertIsArray( $made );
		$this->pilot = (int) $made['campaign_id'];
	}

	/**
	 * The banner text as a person reads it.
	 *
	 * @param string $html Escaped HTML.
	 * @return string
	 */
	private function text( $html ) {
		return html_entity_decode( wp_strip_all_tags( $html ), ENT_QUOTES, 'UTF-8' );
	}

	/**
	 * Enroll a participant in the pilot on a date during sign-up.
	 *
	 * @param string $who Fixture user key.
	 */
	private function enroll_pilot( $who ) {
		$this->assertIsInt( jlife_challenges_enroll_solo( $this->u[ $who ], $this->pilot, 'adult' ) );
	}

	/**
	 * The pilot is Dec 5–13, 2026 (Pacific), active, with a key that is not a test key.
	 */
	public function test_creates_the_pilot_with_the_right_shape() {
		$c = jlife_challenges_get_campaign( $this->pilot );
		$this->assertSame( 'pilot-2026-12', $c['campaign_key'] );
		$this->assertSame( '2026-12-05', $c['start_date'] );
		$this->assertSame( '2026-12-13', $c['end_date'] );
		$this->assertSame( 'America/Los_Angeles', $c['timezone'] );
		$this->assertSame( 'active', $c['status'] );
		$this->assertFalse( jlife_challenges_is_test_campaign( $c ) );
		$this->assertTrue( jlife_challenges_is_pilot_campaign( $c ) );
		$this->assertNotContains( $this->pilot, jlife_challenges_test_campaign_ids(), 'the staging cleanup never sees the pilot' );
		$this->assertFalse( jlife_challenges_is_pilot_campaign( $this->campaign_row() ), 'the Marathon is not a pilot' );
	}

	/**
	 * Running the step again changes nothing, and it never makes a second campaign.
	 */
	public function test_creating_again_is_safe() {
		$again = jlife_challenges_create_pilot_campaign( $this->u['organizer'] );
		$this->assertIsArray( $again );
		$this->assertFalse( $again['created'] );
		$this->assertSame( $this->pilot, (int) $again['campaign_id'] );
		global $wpdb;
		$t = jlife_challenges_table( 'campaigns' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- test read; custom table.
		$this->assertSame( 1, (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$t} WHERE campaign_key LIKE 'pilot-%'" ) );
	}

	/**
	 * Only an organizer can create it; a `test-` key or one without the pilot prefix is refused.
	 */
	public function test_creating_needs_an_organizer_and_a_pilot_key() {
		$this->assertWPError( jlife_challenges_create_pilot_campaign( $this->u['a'] ) );
		foreach ( array( 'test-pilot', 'marathon-2026', '' ) as $key ) {
			$this->assertWPError( jlife_challenges_create_pilot_campaign( $this->u['organizer'], array( 'campaign_key' => $key ) ), "key '$key'" );
		}
		$odd = jlife_challenges_create_pilot_campaign( $this->u['organizer'], array( 'start_date' => '2026-12-13', 'end_date' => '2026-12-05' ) ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound -- test data.
		$this->assertWPError( $odd, 'reversed dates' );
	}

	/**
	 * The pilot is joinable from Nov 29, and while it is, nothing else is the join target.
	 */
	public function test_sign_ups_from_nov_29_join_the_pilot() {
		$this->set_now( '2026-11-29 18:00:00' );
		$open = jlife_challenges_open_campaign();
		$this->assertSame( $this->pilot, (int) $open['id'], 'upcoming pilot beats the later Marathon' );
		$this->set_now( '2026-12-14 08:00:00' );
		$this->assertSame( $this->campaign, (int) jlife_challenges_open_campaign()['id'], 'from Dec 14 new joins go to the Marathon' );
	}

	/**
	 * An invite code for a pilot group joins the pilot, and only while the pilot is joinable.
	 */
	public function test_pilot_invite_codes_join_the_pilot_only() {
		$group = jlife_challenges_create_group( $this->u['organizer'], $this->pilot, 'Pilot Group' );
		$this->assertIsArray( $group );
		$this->set_now( '2026-12-07 18:00:00' );
		$joined = jlife_challenges_join_with_code( $this->u['outsider'], $group['invite_code'], 'adult' );
		$this->assertNotWPError( $joined );
		$this->assertNotNull( jlife_challenges_get_enrollment( $this->pilot, $this->u['outsider'] ) );
		$this->assertNull( jlife_challenges_get_enrollment( $this->campaign, $this->u['outsider'] ), 'never the Marathon' );

		$this->set_now( '2026-12-14 08:00:00' );
		$this->assertNull( jlife_challenges_joinable_group_for_invite( $group['invite_code'] ), 'the code stops at Dec 14 00:00 PT' );
	}

	/**
	 * The practice banner shows on Today, My Marathon, My Group and /share while the pilot is current.
	 */
	public function test_banner_shows_on_the_signed_in_screens_while_the_pilot_is_current() {
		$this->set_now( '2026-12-07 18:00:00' );
		$this->enroll_pilot( 'outsider' );
		wp_set_current_user( $this->u['outsider'] );
		// Today, My Marathon, My Group and /share render with these nav items ("group" covers both My Group and /share).
		foreach ( array( 'today', 'marathon', 'group' ) as $active ) {
			$html = jlife_challenges_page_html( 'T', '<h1>Body</h1>', $active );
			$this->assertStringContainsString(
				"Practice week: Dec 5–13. Points don't count for prizes. The Marathon starts Dec 20.",
				$this->text( $html ),
				$active
			);
			$this->assertLessThan( strpos( $html, '<h1>Body</h1>' ), strpos( $html, 'Practice week' ), 'above the screen content' );
		}
		$this->assertStringNotContainsString( 'Practice week', jlife_challenges_page_html( 'T', 'x', 'signout' ), 'not on the sign-out confirmation' );
		$this->assertStringNotContainsString( 'Practice week', jlife_challenges_page_html( 'T', 'x', 'public' ), 'not on the public reading page' );
	}

	/**
	 * The banner is for the pilot only: not for a Marathon participant, not for a signed-out visitor, and not once the pilot is no longer current.
	 */
	public function test_banner_only_while_the_pilot_is_current() {
		$this->set_now( '2026-12-07 18:00:00' );
		wp_set_current_user( $this->u['a'] ); // Enrolled in the Marathon only.
		$this->assertStringNotContainsString( 'Practice week', jlife_challenges_page_html( 'T', 'x', 'today' ) );
		wp_set_current_user( 0 );
		$this->assertStringNotContainsString( 'Practice week', jlife_challenges_page_html( 'T', 'x', 'today' ) );

		$this->enroll_pilot( 'outsider' );
		wp_set_current_user( $this->u['outsider'] );
		$this->assertStringContainsString( 'Practice week', jlife_challenges_page_html( 'T', 'x', 'today' ) );
		$this->set_now( '2026-12-20 18:00:00' ); // Both enrolled would show the Marathon; the pilot-only reader still sees the (ended) pilot, so no practice banner on an ended pilot.
		$this->assertStringNotContainsString( 'Practice week', jlife_challenges_page_html( 'T', 'x', 'today' ) );
	}

	/**
	 * The Vietnamese draft is in the catalog, and the banner is built from one translatable string.
	 */
	public function test_banner_has_a_vietnamese_draft() {
		$catalog = require dirname( __DIR__ ) . '/languages/jlife-challenges-vi.l10n.php';
		$key     = "Practice week: Dec 5–13. Points don't count for prizes. The Marathon starts Dec 20.";
		$this->assertArrayHasKey( $key, $catalog['messages'] );
		$this->assertNotSame( '', $catalog['messages'][ $key ] );
	}

	/**
	 * "I'll start Dec 20": a per-user flag that enrolls no one.
	 */
	public function test_start_dec_20_flag() {
		$user = $this->u['outsider'];
		$this->assertFalse( jlife_challenges_is_waiting_for_official( $user ) );
		jlife_challenges_set_waiting_for_official( $user );
		$this->assertTrue( jlife_challenges_is_waiting_for_official( $user ) );
		$this->assertContains( $user, jlife_challenges_waiting_for_official_ids() );
		$this->assertNull( jlife_challenges_get_enrollment( $this->pilot, $user ), 'no pilot enrollment' );
		$this->assertNull( jlife_challenges_get_enrollment( $this->campaign, $user ), 'no Marathon enrollment either; the rollover (#180) does that' );
		$this->assertNotContains( $this->u['a'], jlife_challenges_waiting_for_official_ids() );
		jlife_challenges_set_waiting_for_official( $user );
		$this->assertCount( 1, array_keys( jlife_challenges_waiting_for_official_ids(), $user, true ), 'setting it twice adds nothing' );
	}

	/**
	 * The flag is not a consent: setting it records none and starts no reminder.
	 */
	public function test_the_flag_records_no_consent_and_no_reminder() {
		$user = $this->u['outsider'];
		jlife_challenges_set_waiting_for_official( $user );
		$this->assertSame( '', (string) get_user_meta( $user, 'jlife_consent_version', true ) );
		global $wpdb;
		$t = jlife_challenges_table( 'reminder_prefs' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQL.NotPrepared -- test read; custom table.
		$this->assertSame( 0, (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$t} WHERE user_id = %d", $user ) ) );
	}
}
