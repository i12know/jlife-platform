<?php
/**
 * Campaign calendar: day boundaries in Pacific time, DST, travellers.
 *
 * @package jlife-challenges
 */

/**
 * Calendar tests.
 */
class Test_Calendar extends Jlife_Challenges_TestCase {

	/**
	 * Day index at a UTC moment.
	 *
	 * @param string $utc UTC time.
	 * @return int
	 */
	private function day_at( $utc ) {
		return jlife_challenges_current_day( $this->campaign_row(), new DateTimeImmutable( $utc, new DateTimeZone( 'UTC' ) ) );
	}

	/**
	 * Dec 20 is Day 1 and Apr 2 is Day 104, at Pacific midnight; no Day 0 or 105.
	 */
	public function test_first_and_last_day_at_pacific_midnight() {
		$this->assertSame( 104, jlife_challenges_day_count( $this->campaign_row() ) );
		$this->assertSame( 0, $this->day_at( '2026-12-20 07:59:00' ), 'Dec 19, 11:59 PM PST: before Day 1' );
		$this->assertSame( 1, $this->day_at( '2026-12-20 08:00:00' ), 'Dec 20, 12:00 AM PST: Day 1' );
		$this->assertSame( 104, $this->day_at( '2027-04-03 06:59:00' ), 'Apr 2, 11:59 PM PDT: Day 104' );
		$this->assertSame( 0, $this->day_at( '2027-04-03 07:00:00' ), 'Apr 3, 12:00 AM PDT: no Day 105' );
		$this->assertSame( '2027-04-02', jlife_challenges_day_date( $this->campaign_row(), 104 ) );
	}

	/**
	 * The Mar 14, 2027 clock change does not shift or skip a day.
	 */
	public function test_daylight_saving_change() {
		$this->assertSame( '2027-03-14', jlife_challenges_day_date( $this->campaign_row(), 85 ) );
		$this->assertSame( 84, $this->day_at( '2027-03-14 07:30:00' ), 'Mar 13, 11:30 PM PST' );
		$this->assertSame( 85, $this->day_at( '2027-03-14 08:30:00' ), 'Mar 14, 12:30 AM PST' );
		$this->assertSame( 85, $this->day_at( '2027-03-15 06:30:00' ), 'Mar 14, 11:30 PM PDT' );
		$this->assertSame( 86, $this->day_at( '2027-03-15 07:30:00' ), 'Mar 15, 12:30 AM PDT' );
	}

	/**
	 * A participant abroad gets the campaign's day, not their local day.
	 */
	public function test_traveller_uses_campaign_time() {
		// Dec 21, 6:00 AM in Vietnam (UTC+7) is still Dec 20 in California.
		$vietnam = new DateTimeImmutable( '2026-12-21 06:00:00', new DateTimeZone( 'Asia/Ho_Chi_Minh' ) );
		$this->assertSame( 1, jlife_challenges_current_day( $this->campaign_row(), $vietnam ) );
	}

	/**
	 * D1: on time through 11:59 PM Pacific on day N+1.
	 */
	public function test_grace_window() {
		$c  = $this->campaign_row();
		$at = static fn( $utc ) => new DateTimeImmutable( $utc, new DateTimeZone( 'UTC' ) );
		$this->assertTrue( jlife_challenges_is_on_time( $c, 1, $at( '2026-12-20 18:00:00' ) ), 'same day' );
		$this->assertTrue( jlife_challenges_is_on_time( $c, 1, $at( '2026-12-22 07:59:00' ) ), 'Dec 21, 11:59 PM PST' );
		$this->assertFalse( jlife_challenges_is_on_time( $c, 1, $at( '2026-12-22 08:00:00' ) ), 'Dec 22, 12:00 AM PST' );
		$this->assertFalse( jlife_challenges_is_on_time( $c, 2, $at( '2026-12-20 18:00:00' ) ), 'a future day is never on time' );
	}

	/**
	 * Malformed and out-of-range day keys resolve to nothing.
	 */
	public function test_day_keys() {
		$c = $this->campaign_row();
		$this->assertSame( 'd001', jlife_challenges_day_key( 1 ) );
		$this->assertSame( 38, jlife_challenges_day_index( $c, 'd038' ) );
		$this->assertSame( 0, jlife_challenges_day_index( $c, 'd000' ) );
		$this->assertSame( 0, jlife_challenges_day_index( $c, 'd105' ) );
		$this->assertSame( 0, jlife_challenges_day_index( $c, 'day1' ) );
	}
}
