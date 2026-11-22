<?php
/**
 * Previous / next day arrows around the day heading (#225, part 3).
 *
 * @package jlife-challenges
 */

/**
 * Day arrow tests.
 */
class Test_Day_Arrows extends Jlife_Challenges_TestCase {

	/**
	 * Locale filter callback, set by the Vietnamese test.
	 *
	 * @var callable|null
	 */
	private $force = null;

	/**
	 * Back to English.
	 */
	public function tear_down() {
		if ( $this->force ) {
			remove_filter( 'determine_locale', $this->force, 99 );
			$this->force = null;
		}
		unload_textdomain( 'jlife-challenges', true );
		parent::tear_down();
	}

	/**
	 * The day heading with arrows for a day of participant A.
	 *
	 * @param string|null $day_key Day, or null for today.
	 * @return string
	 */
	private function nav( $day_key = null ) {
		return jlife_challenges_render_day_nav( jlife_challenges_day_view( $this->u['a'], $day_key ) );
	}

	/**
	 * Day 1 has no previous link, and Day 2 is locked on the first morning, so
	 * there is no next link either; the heading itself is unchanged.
	 */
	public function test_day_one_has_no_links_while_day_two_is_locked() {
		$html = $this->nav();
		$this->assertStringNotContainsString( 'Previous day', $html );
		$this->assertStringNotContainsString( 'Next day', $html );
		$this->assertStringNotContainsString( '<a ', $html );
		$this->assertStringContainsString( '<p class="jl-day">Day 1 · December 20, 2026</p>', $html );
		$this->assertSame( 0, array_sum( jlife_challenges_adjacent_days( jlife_challenges_day_view( $this->u['a'] ) ) ) );
	}

	/**
	 * An earlier day stays linked after its scoring deadline: reading is not scoring.
	 */
	public function test_an_earlier_day_links_after_its_deadline() {
		$this->set_now( '2026-12-29 20:00:00' ); // Day 10.
		$view = jlife_challenges_day_view( $this->u['a'], 'd003' );
		$this->assertFalse( $view['on_time'], 'Day 3 is past its window' );
		$html = jlife_challenges_render_day_nav( $view );
		$this->assertStringContainsString( 'aria-label="Previous day: Day 2"', $html );
		$this->assertStringContainsString( 'aria-label="Next day: Day 4"', $html );
		$this->assertStringContainsString( 'href="' . esc_url( jlife_challenges_screen_url( 'soap', 'd002' ) ) . '"', $html );
		$this->assertStringContainsString( 'href="' . esc_url( jlife_challenges_screen_url( 'soap', 'd004' ) ) . '"', $html );
		$this->assertStringContainsString( '<span aria-hidden="true">', $html, 'the arrow glyph is decoration' );
		$this->assertStringContainsString( '<p class="jl-day">Day 3 · December 22, 2026</p>', $html );

		$first = $this->nav( 'd001' );
		$this->assertStringNotContainsString( 'Previous day', $first, 'no previous link before Day 1' );
		$this->assertStringContainsString( 'Next day: Day 2', $first );
	}

	/**
	 * Today's successor is still locked: no next link, no skipping, nothing unlocked.
	 */
	public function test_todays_locked_successor_has_no_next_link() {
		$this->set_now( '2026-12-29 20:00:00' ); // Day 10.
		$html = $this->nav();
		$this->assertStringContainsString( 'aria-label="Previous day: Day 9"', $html );
		$this->assertStringNotContainsString( 'Next day', $html );
		$this->assertStringNotContainsString( 'd011', $html );
		$this->assertStringNotContainsString( 'd012', $html );
		$locked = jlife_challenges_day_view( $this->u['a'], 'd011' );
		$this->assertSame( 'unavailable', $locked['state'], 'a direct request for the locked day is still denied' );
		$this->assertSame( 0, array_sum( jlife_challenges_adjacent_days( $locked ) ) );
	}

	/**
	 * The final day has a previous link and no next link.
	 */
	public function test_the_final_day_has_no_next_link() {
		$this->set_now( '2027-04-05 20:00:00' ); // After the last day.
		$view = jlife_challenges_day_view( $this->u['a'], 'd104' );
		$this->assertSame( 'active', $view['state'] );
		$html = jlife_challenges_render_day_nav( $view );
		$this->assertStringContainsString( 'aria-label="Previous day: Day 103"', $html );
		$this->assertStringNotContainsString( 'Next day', $html );
		$this->assertStringNotContainsString( 'd105', $html );
	}

	/**
	 * Both the Today and the /soap bodies carry the arrows.
	 */
	public function test_both_screens_carry_the_arrows() {
		$this->set_now( '2026-12-29 20:00:00' );
		$view = jlife_challenges_day_view( $this->u['a'], 'd003' );
		$this->assertStringContainsString( 'class="jl-daynav"', jlife_challenges_soap_body( $view ) );
		$this->assertStringContainsString( 'Previous day: Day 2', jlife_challenges_today_body( $view ) );
	}

	/**
	 * The names are in the Vietnamese drafts, with the day number.
	 */
	public function test_accessible_names_in_vietnamese() {
		$this->set_now( '2026-12-29 20:00:00' );
		$this->force = static fn() => 'vi';
		add_filter( 'determine_locale', $this->force, 99 );
		add_filter(
			'lang_dir_for_domain',
			static fn( $path, $domain ) => 'jlife-challenges' === $domain ? dirname( __DIR__ ) . '/languages/' : $path,
			10,
			2
		);
		unload_textdomain( 'jlife-challenges', true );
		$html = $this->nav( 'd003' );
		$this->assertStringContainsString( 'aria-label="Ngày trước: Ngày 2"', $html );
		$this->assertStringContainsString( 'aria-label="Ngày sau: Ngày 4"', $html );
	}

	/**
	 * The script saves before leaving and stops with a message when the save fails;
	 * the styles give 44 px targets and a visible focus ring.
	 */
	public function test_script_and_styles_protect_writing_and_touch_targets() {
		$js = (string) file_get_contents( dirname( __DIR__ ) . '/assets/soap.js' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- plugin asset.
		$this->assertStringContainsString( 'a.jl-daynav-link', $js );
		$this->assertStringContainsString( 'save().then', $js, 'saves first, leaves only on success' );
		$this->assertStringContainsString( 'msg.leave_failed', $js );
		$css = (string) file_get_contents( dirname( __DIR__ ) . '/assets/screens.css' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- plugin asset.
		$this->assertStringContainsString( '.jl-daynav-link:focus-visible', $css );
		$this->assertStringContainsString( 'width:2.75rem;height:2.75rem', $css );
	}
}
