<?php
/**
 * My Group groups shared messages into one collapsible section per reading day,
 * newest day first, with only the newest populated day open (#203).
 *
 * @package jlife-challenges
 */

/**
 * Group day-section tests.
 */
class Test_Group_Day_Sections extends Jlife_Challenges_TestCase {

	/**
	 * Share a day with the given groups at a given clock time.
	 *
	 * @param string $who   Fixture user.
	 * @param string $day   Day key.
	 * @param string $tag   Text marker.
	 * @param int[]  $group Groups.
	 * @param string $utc   Clock (UTC).
	 */
	private function share( $who, $day, $tag, array $group, $utc ) {
		$this->set_now( $utc );
		$this->assertIsArray( jlife_challenges_save_and_share( $this->u[ $who ], $this->campaign, $day, $this->soap( $tag ), $group ) );
	}

	/**
	 * The /group body for a viewer.
	 *
	 * @param string $who   Fixture user.
	 * @param int    $group Group (0 = default).
	 * @return string
	 */
	private function body( $who, $group = 0 ) {
		return jlife_challenges_group_body( jlife_challenges_group_view( $this->u[ $who ], $group ) );
	}

	/**
	 * The sections of a body, as [ open?, summary, inner html ].
	 *
	 * @param string $html Body.
	 * @return array[]
	 */
	private function sections( $html ) {
		preg_match_all( '#<details class="jl-day-group"( open)?><summary>(.*?)</summary>(.*?)</details>#s', $html, $m, PREG_SET_ORDER );
		return array_map( static fn( $x ) => array( '' !== $x[1], $x[2], $x[3] ), $m );
	}

	/**
	 * One day: one open section holding every message.
	 */
	public function test_one_day_is_one_open_section() {
		$this->share( 'a', 'd001', 'ONE-A', array( $this->g['g1'] ), '2026-12-20 18:00:00' );
		$this->share( 'b', 'd001', 'ONE-B', array( $this->g['g1'] ), '2026-12-20 19:00:00' );
		$s = $this->sections( $this->body( 'a' ) );
		$this->assertCount( 1, $s );
		$this->assertTrue( $s[0][0] );
		$this->assertStringContainsString( 'Day 1', $s[0][1] );
		$this->assertSame( 2, substr_count( $s[0][2], '<article' ) );
		$this->assertStringContainsString( 'Yours — change or remove', $s[0][2], 'own link kept' );
		$this->assertSame( 1, substr_count( $s[0][2], 'Yours — change or remove' ) );
	}

	/**
	 * Several days: newest first, only the newest open.
	 */
	public function test_several_days_newest_first_only_newest_open() {
		$this->share( 'a', 'd001', 'DAY-ONE', array( $this->g['g1'] ), '2026-12-20 18:00:00' );
		$this->share( 'a', 'd002', 'DAY-TWO', array( $this->g['g1'] ), '2026-12-21 18:00:00' );
		$this->share( 'b', 'd003', 'DAY-THREE', array( $this->g['g1'] ), '2026-12-22 18:00:00' );
		$s = $this->sections( $this->body( 'a' ) );
		$this->assertCount( 3, $s );
		$this->assertSame( array( true, false, false ), array_column( $s, 0 ) );
		$this->assertStringContainsString( 'DAY-THREE', $s[0][2] );
		$this->assertStringContainsString( 'DAY-TWO', $s[1][2] );
		$this->assertStringContainsString( 'DAY-ONE', $s[2][2] );
		$this->assertStringContainsString( 'Day 3', $s[0][1] );
		$this->assertStringContainsString( 'Day 1', $s[2][1] );
	}

	/**
	 * Empty feed: the existing message, no sections.
	 */
	public function test_empty_feed_keeps_the_message() {
		$html = $this->body( 'a' );
		$this->assertStringContainsString( 'Nothing shared with this group yet.', $html );
		$this->assertStringNotContainsString( '<details', $html );
	}

	/**
	 * A late share for an older day stays under that day, whatever it was posted.
	 */
	public function test_late_share_stays_under_its_reading_day() {
		$this->share( 'b', 'd003', 'ON-TIME-3', array( $this->g['g1'] ), '2026-12-22 18:00:00' );
		$this->share( 'a', 'd001', 'LATE-FOR-1', array( $this->g['g1'] ), '2026-12-22 19:00:00' );
		$s = $this->sections( $this->body( 'a' ) );
		$this->assertCount( 2, $s );
		$this->assertStringContainsString( 'ON-TIME-3', $s[0][2] );
		$this->assertStringNotContainsString( 'LATE-FOR-1', $s[0][2] );
		$this->assertStringContainsString( 'LATE-FOR-1', $s[1][2] );
		$this->assertFalse( $s[1][0], 'the older day stays collapsed even though it was posted last' );
	}

	/**
	 * Two groups: separate feeds, each opening its own newest day.
	 */
	public function test_two_groups_do_not_mix() {
		$this->assertIsInt( jlife_challenges_enroll( $this->u['organizer'], $this->u['a'], $this->g['g2'] ) );
		$this->share( 'a', 'd001', 'G1-DAY1', array( $this->g['g1'] ), '2026-12-20 18:00:00' );
		$this->share( 'a', 'd002', 'G1-DAY2', array( $this->g['g1'] ), '2026-12-21 18:00:00' );
		$this->share( 'c', 'd001', 'G2-DAY1', array( $this->g['g2'] ), '2026-12-21 19:00:00' );
		$one = $this->sections( $this->body( 'a', $this->g['g1'] ) );
		$two = $this->sections( $this->body( 'a', $this->g['g2'] ) );
		$this->assertCount( 2, $one );
		$this->assertSame( array( true, false ), array_column( $one, 0 ) );
		$this->assertCount( 1, $two );
		$this->assertTrue( $two[0][0], 'the other group opens its own newest day' );
		$this->assertStringContainsString( 'G2-DAY1', $two[0][2] );
		$this->assertStringNotContainsString( 'G1-', implode( '', array_column( $two, 2 ) ) );
		$this->assertStringNotContainsString( 'G2-', implode( '', array_column( $one, 2 ) ) );
	}
}
