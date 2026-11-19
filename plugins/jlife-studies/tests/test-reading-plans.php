<?php
/**
 * Reading-plan import and rendering (#59).
 *
 * Fixtures are days 1, 38 and 53 cut from the real generated plans
 * (content/reading-plans/*.json), so the edge cases are real data:
 * Day 38's multi-passage Bible Gateway link, Day 53's "4.40" section ID.
 *
 * @package jlife-studies
 */

/**
 * Reading-plan tests.
 */
class Test_Reading_Plans extends WP_UnitTestCase {

	/**
	 * Load a fixture plan.
	 *
	 * @param string $name primary|supplemental.
	 * @return array
	 */
	private function fixture( $name ) {
		$raw = file_get_contents( __DIR__ . "/fixtures/plan-{$name}.json" ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		return json_decode( (string) $raw, true );
	}

	/**
	 * Render a reading day's content as a front-end single page would.
	 *
	 * @param int $post_id Post ID.
	 * @return string
	 */
	private function render( $post_id ) {
		$this->go_to( get_permalink( $post_id ) );
		$this->assertTrue( is_singular( JLIFE_READING_DAY_CPT ) );
		$html = '';
		while ( have_posts() ) {
			the_post();
			$html = apply_filters( 'the_content', '' );
		}
		return $html;
	}

	/**
	 * Import creates one page per day; re-import updates instead of duplicating.
	 */
	public function test_import_creates_one_page_per_day_and_is_idempotent() {
		$this->assertSame( 3, jlife_studies_import_reading_plan( $this->fixture( 'primary' ) ) );
		$this->assertSame( 3, jlife_studies_import_reading_plan( $this->fixture( 'primary' ) ) );
		$count = count(
			get_posts(
				array(
					'post_type'      => JLIFE_READING_DAY_CPT,
					'posts_per_page' => -1,
				)
			)
		);
		$this->assertSame( 3, $count, 're-import must update, not duplicate' );
	}

	/**
	 * Section IDs keep trailing zeros ("4.40") and dates stay calendar dates.
	 */
	public function test_section_ids_survive_as_strings() {
		jlife_studies_import_reading_plan( $this->fixture( 'primary' ) );
		$day53 = jlife_studies_get_reading_day( 'rpl-life-and-teachings-of-jesus-2026', 'd053' );
		$this->assertSame( array( '4.40' ), $day53['day']['plusnothing_sections'] );
		$day38 = jlife_studies_get_reading_day( 'rpl-life-and-teachings-of-jesus-2026', 'd038' );
		$this->assertSame( array( '4.4', '4.5', '4.6' ), $day38['day']['plusnothing_sections'] );
		$this->assertSame( '2027-02-10', $day53['day']['date'] );
	}

	/**
	 * The supplemental plan needs its primary plan and attaches to the same days without pages of its own.
	 */
	public function test_supplemental_requires_primary_and_attaches_to_its_days() {
		$err = jlife_studies_import_reading_plan( $this->fixture( 'supplemental' ) );
		$this->assertWPError( $err );
		$this->assertSame( 'jlife_plan_primary_missing', $err->get_error_code() );

		jlife_studies_import_reading_plan( $this->fixture( 'primary' ) );
		$this->assertSame( 3, jlife_studies_import_reading_plan( $this->fixture( 'supplemental' ) ) );
		$day1 = jlife_studies_get_reading_day( 'rpl-life-and-teachings-of-jesus-2026', 'd001' );
		$this->assertSame( 'rpl-harmony-parallel-2026', $day1['supplemental']['plan']['plan_id'] );
		$this->assertSame( 'Luke 1:1-4', $day1['supplemental']['day']['passages'][0]['display'] );
		$this->assertNull( jlife_studies_find_reading_day( 'rpl-harmony-parallel-2026', 'd001' ), 'supplemental plans create no pages of their own' );
	}

	/**
	 * Malformed plan documents are rejected.
	 */
	public function test_invalid_documents_are_rejected() {
		$doc            = $this->fixture( 'primary' );
		$doc['plan_id'] = 'Not An ID';
		$this->assertWPError( jlife_studies_import_reading_plan( $doc ) );

		$doc              = $this->fixture( 'primary' );
		$doc['day_count'] = 104;
		$this->assertWPError( jlife_studies_import_reading_plan( $doc ), 'day_count must match the days present' );

		$this->assertWPError( jlife_studies_import_reading_plan( array( 'schema_version' => '2.0' ) ) );
	}

	/**
	 * The day page shows source references and the owner-tested links, opening in new tabs.
	 */
	public function test_day_page_shows_references_and_links_in_new_tabs() {
		jlife_studies_import_reading_plan( $this->fixture( 'primary' ) );
		jlife_studies_import_reading_plan( $this->fixture( 'supplemental' ) );
		$day38 = jlife_studies_get_reading_day( 'rpl-life-and-teachings-of-jesus-2026', 'd038' );
		$html  = $this->render( $day38['post_id'] );

		$this->assertStringContainsString( 'Day 38', $html );
		$this->assertStringContainsString( 'John 6:8-15a', $html, 'source reference shown exactly, half-verse letter kept' );
		$this->assertStringContainsString(
			esc_url( 'https://www.biblegateway.com/passage/?search=Mark+6:30-37;John+6:8-15;Mark+6:45;Matthew+14:23-33;Mark+6:53-56&version=ERV' ),
			$html,
			'the owner-tested combined English link'
		);
		$this->assertStringContainsString(
			esc_url( 'https://www.biblegateway.com/passage/?search=Mark+6:30-37;John+6:8-15;Mark+6:45;Matthew+14:23-33;Mark+6:53-56&version=BPT' ),
			$html,
			'one Vietnamese BPT link with every passage, like English (#91)'
		);
		$this->assertStringNotContainsString( 'bible.com', $html );
		$supp = substr( $html, (int) strpos( $html, '<details class="jlife-supplemental">' ) );
		$this->assertMatchesRegularExpression( '/class="jlife-read-all" href="[^"]*version=BPT"/', $supp, 'optional parallels get their own BPT button' );
		$this->assertStringContainsString( 'target="_blank" rel="noopener"', $html );
		$this->assertStringContainsString( '<details class="jlife-supplemental">', $html, 'parallel readings collapsed and optional' );
	}

	/**
	 * Optional parallel readings list their Harmony Bible sections (#90).
	 */
	public function test_day_page_links_harmony_sections_in_the_optional_readings() {
		jlife_studies_import_reading_plan( $this->fixture( 'primary' ) );
		jlife_studies_import_reading_plan( $this->fixture( 'supplemental' ) );
		$day1 = jlife_studies_get_reading_day( 'rpl-life-and-teachings-of-jesus-2026', 'd001' );
		$html = $this->render( $day1['post_id'] );

		$this->assertStringNotContainsString( 'harmony-bible.com', substr( $html, 0, (int) strpos( $html, '<details class="jlife-supplemental">' ) ), 'only inside the optional section' );
		$this->assertStringContainsString( '<a href="https://harmony-bible.com/sections/1/" target="_blank" rel="noopener">Harmony&#039;s section 1: In the Dedication Luke Explains His Method of Research</a>', $html );
		$this->assertSame( 1, substr_count( $html, 'harmony-bible.com/sections/2/' ) );
		$this->assertLessThan( strpos( $html, 'sections/2/' ), strpos( $html, 'sections/1/' ), 'reading order' );
		$this->assertStringContainsString( 'version=BPT', $html, 'parallel Scripture and Bible links stay' );
	}

	/**
	 * Imported text is escaped on output.
	 */
	public function test_day_page_escapes_titles_and_references() {
		$doc                                      = $this->fixture( 'primary' );
		$doc['days'][0]['passages'][0]['display'] = '<script>alert(1)</script>';
		jlife_studies_import_reading_plan( $doc );
		$day1 = jlife_studies_get_reading_day( 'rpl-life-and-teachings-of-jesus-2026', 'd001' );
		$html = $this->render( $day1['post_id'] );
		$this->assertStringNotContainsString( '<script>', $html );
		$this->assertStringContainsString( '&lt;script&gt;', $html );
	}
	/**
	 * A Vietnamese reader gets the plan's Vietnamese title and Protestant book
	 * names; English and other languages keep the English day (#119).
	 */
	public function test_reading_day_is_localized_for_vietnamese() {
		$day = array(
			'title'    => 'Jesus and Nicodemus | Jesus and John the Baptizer',
			'titles'   => array( 'Jesus and Nicodemus', 'Jesus and John the Baptizer' ),
			'passages' => array(
				array(
					'display' => 'John 3:1-21',
					'refs'    => array( array( 'book' => 'John' ) ),
				),
				array(
					'display' => 'John 3:22-4:3',
					'refs'    => array( array( 'book' => 'John' ) ),
				),
			),
			'i18n'     => array(
				'vi' => array(
					'title'    => 'Chúa Giê-xu và Ni-cô-đem | Chúa Giê-xu và Giăng Báp-tít',
					'passages' => array( 'Giăng 3:1-21', 'Giăng 3:22-4:3' ),
				),
			),
		);

		$vi = jlife_studies_localize_day( $day, 'vi' );
		$this->assertSame( 'Chúa Giê-xu và Ni-cô-đem | Chúa Giê-xu và Giăng Báp-tít', $vi['title'] );
		$this->assertSame( array( 'Chúa Giê-xu và Ni-cô-đem', 'Chúa Giê-xu và Giăng Báp-tít' ), $vi['titles'] );
		$this->assertSame( array( 'Giăng 3:1-21', 'Giăng 3:22-4:3' ), array_column( $vi['passages'], 'display' ) );
		$this->assertSame( 'John', $vi['passages'][0]['refs'][0]['book'], 'parsed references are untouched' );

		$this->assertSame( $day, jlife_studies_localize_day( $day, 'en_US' ) );
		$this->assertSame( $day, jlife_studies_localize_day( $day, 'fr_FR' ), 'no entry: English' );

		$short = $day;

		$short['i18n']['vi']['passages'] = array( 'Giăng 3:1-21' );
		$this->assertSame( array( 'John 3:1-21', 'John 3:22-4:3' ), array_column( jlife_studies_localize_day( $short, 'vi' )['passages'], 'display' ), 'a mismatched list is ignored' );
		$this->assertNull( jlife_studies_localize_day( null, 'vi' ) );
	}

	/**
	 * The day from jlife_studies_get_reading_day() follows the current locale.
	 */
	public function test_get_reading_day_follows_the_locale() {
		$doc = $this->fixture( 'primary' );
		foreach ( $doc['days'] as &$d ) {
			$d['i18n'] = array(
				'vi' => array(
					'title'    => 'Tiêu đề ' . $d['day_id'],
					'passages' => array_fill( 0, count( $d['passages'] ), 'Giăng 1:1' ),
				),
			);
		}
		unset( $d );
		jlife_studies_import_reading_plan( $doc );
		$day_id = $doc['days'][0]['day_id'];

		$this->assertSame( $doc['days'][0]['title'], jlife_studies_get_reading_day( $doc['plan_id'], $day_id )['day']['title'] );
		$vi = static fn() => 'vi';
		add_filter( 'determine_locale', $vi );
		$this->assertSame( 'Tiêu đề ' . $day_id, jlife_studies_get_reading_day( $doc['plan_id'], $day_id )['day']['title'] );
		remove_filter( 'determine_locale', $vi );
	}
}
