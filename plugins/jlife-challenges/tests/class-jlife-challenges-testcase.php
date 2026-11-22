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
		$this->set_now( '2026-12-20 18:00:00' ); // Day 1, 10:00 AM Pacific.
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
	protected function set_now( $utc ) {
		$this->now = new DateTimeImmutable( $utc, new DateTimeZone( 'UTC' ) );
	}

	/**
	 * One complete SOAP entry: the four headings, each with some text (#98).
	 *
	 * @param string $tag Text marker.
	 * @return string
	 */
	protected function soap( $tag = 'x' ) {
		return "S — Scripture\nJohn 1:14 ($tag)\n\nO — Observation\nThe Word became flesh ($tag).\n\nA — Application\nBe present with my family ($tag).\n\nP — Prayer\nLord, help me ($tag).";
	}

	/**
	 * A SOAP entry whose Prayer has nothing under it, so the default engine does not pass it.
	 *
	 * @param string $tag Text marker.
	 * @return string
	 */
	protected function soap_without_prayer( $tag = 'x' ) {
		return "S — Scripture\nJohn 1:14 ($tag)\n\nO — Observation\nThe Word became flesh ($tag).\n\nA — Application\nBe present with my family ($tag).\n\nP — Prayer\n";
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

	/**
	 * Make a devotional post for the base campaign's plan (#225 guidance tests).
	 *
	 * @param string $status  Post status (publish = Ready).
	 * @param string $content Post content.
	 * @param string $day     Day ID.
	 * @param string $lang    en or vi.
	 * @param string $stored  Value for `_jlife_dev_questions` (one question per line), or ''.
	 * @return int Post ID.
	 */
	protected function make_devotional( $status = 'publish', $content = '<p>Body</p>', $day = 'd001', $lang = 'en', $stored = '' ) {
		jlife_challenges_register_devotional(); // The suite resets post types for each test.
		$id = wp_insert_post(
			array(
				'post_type'    => JLIFE_DEVOTIONAL_CPT,
				'post_status'  => $status,
				'post_title'   => 'Devotional ' . $day . ' ' . $lang,
				'post_content' => $content,
			)
		);
		update_post_meta( $id, '_jlife_dev_plan', 'rpl-life-and-teachings-of-jesus-2026' );
		update_post_meta( $id, '_jlife_dev_day', $day );
		update_post_meta( $id, '_jlife_dev_lang', $lang );
		if ( '' !== $stored ) {
			update_post_meta( $id, '_jlife_dev_questions', $stored );
		}
		return (int) $id;
	}

	/**
	 * Devotional content with a marked questions section (#225).
	 *
	 * @param string[] $questions Question lines.
	 * @param string   $heading   Heading text.
	 * @return string
	 */
	protected function marked_content( array $questions, $heading = 'Reflection questions' ) {
		return '<p>Lead-in.</p><!-- wp:html --><section class="jl-reflection-questions" aria-labelledby="jl-rq-test"><h3 id="jl-rq-test">' . $heading . '</h3><p>' . implode( '</p><p>', $questions ) . '</p></section><!-- /wp:html -->';
	}

	/**
	 * Set the base campaign's scoring engine (presentation follows guidance, not the engine).
	 *
	 * @param string $engine Engine ID.
	 */
	protected function use_engine( $engine ) {
		global $wpdb;
		$wpdb->update( jlife_challenges_table( 'campaigns' ), array( 'scoring_engine' => $engine ), array( 'id' => $this->campaign ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- test fixture.
	}
}
