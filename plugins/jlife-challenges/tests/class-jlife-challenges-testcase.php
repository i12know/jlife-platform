<?php
/**
 * Shared fixtures for the engine tests: the real campaign calendar
 * (Dec 20, 2026 – Apr 2, 2027, Pacific time, 1 grace day), two groups,
 * participants, an organizer, and a controllable clock.
 *
 * @package jlife-challenges
 */

/**
 * Base test case.
 */
abstract class Jlife_Challenges_TestCase extends WP_UnitTestCase {

	/**
	 * Users by role name.
	 *
	 * @var array<string,int>
	 */
	protected $u = array();

	/**
	 * Campaign ID.
	 *
	 * @var int
	 */
	protected $campaign = 0;

	/**
	 * Group IDs: g1, g2.
	 *
	 * @var array<string,int>
	 */
	protected $g = array();

	/**
	 * The fixed "now" (UTC), or null for the real clock.
	 *
	 * @var DateTimeImmutable|null
	 */
	protected $now = null;

	/**
	 * Create tables once.
	 */
	public static function wpSetUpBeforeClass() {
		jlife_challenges_install_tables();
	}

	/**
	 * Fresh users, campaign and groups for every test.
	 */
	public function set_up() {
		parent::set_up();
		add_filter( 'jlife_challenges_now', array( $this, 'clock' ) );
		foreach ( array( 'a', 'b', 'c', 'outsider' ) as $name ) {
			$this->u[ $name ] = self::factory()->user->create(
				array(
					'role'         => 'subscriber',
					'first_name'   => ucfirst( $name ) . 'name',
					'last_name'    => 'Private',
					'display_name' => ucfirst( $name ) . 'name Private',
				)
			);
		}
		$this->u['organizer'] = self::factory()->user->create( array( 'role' => 'administrator' ) );

		$this->campaign = jlife_challenges_create_campaign(
			$this->u['organizer'],
			array(
				'campaign_key' => 'marathon-' . wp_generate_password( 6, false ),
				'plan_id'      => 'rpl-life-and-teachings-of-jesus-2026',
				'title'        => 'Bible Marathon (test)',
				'start_date'   => '2026-12-20',
				'end_date'     => '2027-04-02',
				'status'       => 'active',
			)
		);
		$this->assertIsInt( $this->campaign );
		$this->g['g1'] = jlife_challenges_create_group( $this->u['organizer'], $this->campaign, 'Group One' )['id'];
		$this->g['g2'] = jlife_challenges_create_group( $this->u['organizer'], $this->campaign, 'Group Two' )['id'];
		// a and b in Group One; c in Group Two; outsider not enrolled.
		$this->assertIsInt( jlife_challenges_enroll( $this->u['organizer'], $this->u['a'], $this->g['g1'] ) );
		$this->assertIsInt( jlife_challenges_enroll( $this->u['organizer'], $this->u['b'], $this->g['g1'] ) );
		$this->assertIsInt( jlife_challenges_enroll( $this->u['organizer'], $this->u['c'], $this->g['g2'] ) );
		$this->at( '2026-12-20 18:00:00' ); // Day 1, 10:00 AM Pacific.
	}

	/**
	 * Remove the clock filter.
	 */
	public function tear_down() {
		remove_filter( 'jlife_challenges_now', array( $this, 'clock' ) );
		parent::tear_down();
	}

	/**
	 * Clock filter callback.
	 *
	 * @return DateTimeImmutable|null
	 */
	public function clock() {
		return $this->now;
	}

	/**
	 * Set "now" to a UTC timestamp.
	 *
	 * @param string $utc 'Y-m-d H:i:s' in UTC.
	 */
	protected function at( $utc ) {
		$this->now = new DateTimeImmutable( $utc, new DateTimeZone( 'UTC' ) );
	}

	/**
	 * Four complete SOAP fields.
	 *
	 * @param string $tag Text marker.
	 * @return array
	 */
	protected function soap( $tag = 'x' ) {
		return array(
			'scripture'   => "John 1:14 ($tag)",
			'observation' => "The Word became flesh ($tag).",
			'application' => "Be present with my family ($tag).",
			'prayer'      => "Lord, help me ($tag).",
		);
	}

	/**
	 * The campaign row.
	 *
	 * @return array
	 */
	protected function campaign_row() {
		return jlife_challenges_get_campaign( $this->campaign );
	}

	/**
	 * Count ledger rows for a user.
	 *
	 * @param int $user_id User.
	 * @return int
	 */
	protected function ledger_rows( $user_id ) {
		global $wpdb;
		return (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . jlife_challenges_table( 'score_ledger' ) . ' WHERE user_id = %d', $user_id ) ); // phpcs:ignore WordPress.DB
	}
}
