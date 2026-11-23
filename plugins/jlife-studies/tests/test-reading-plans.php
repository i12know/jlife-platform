<?php
/**
 * Reading-plan import and data (#59). The pages that show a day (Today, /soap
 * and the public /reading/ page) are rendered, and tested, in jlife-challenges (#106).
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
	 * An already-imported Day 1 that still holds John alone is updated in place
	 * to Luke 1:1-4 then John 1:1-18 (#219): same post, no duplicate, other days untouched.
	 */
	public function test_day_one_reimport_updates_in_place_to_luke_then_john() {
		$old = $this->fixture( 'primary' );
		// Simulate the plan as imported before #219: Day 1 is John 1:1-18 alone.
		$day1                            = $old['days'][0];
		$day1['passages']                = array_slice( $day1['passages'], 1 );
		$day1['notes']                   = 'John 1:1-18';
		$day1['links']['en']['passages'] = array_slice( $day1['links']['en']['passages'], 1 );
		$old['days'][0]                  = $day1;
		jlife_studies_import_reading_plan( $old );
		$before = jlife_studies_get_reading_day( 'rpl-life-and-teachings-of-jesus-2026', 'd001' );
		$this->assertCount( 1, $before['day']['passages'] );
		$other_before = jlife_studies_get_reading_day( 'rpl-life-and-teachings-of-jesus-2026', 'd038' );

		jlife_studies_import_reading_plan( $this->fixture( 'primary' ) );
		$after = jlife_studies_get_reading_day( 'rpl-life-and-teachings-of-jesus-2026', 'd001' );
		$this->assertSame( $before['post_id'], $after['post_id'], 'Day 1 is updated in place' );
		$this->assertSame(
			array( 'Luke 1:1-4', 'John 1:1-18' ),
			array_column( $after['day']['passages'], 'display' )
		);
		$this->assertSame(
			array( 'Luke 1:1-4', 'John 1:1-18' ),
			array_column( $after['day']['links']['en']['passages'], 'label' )
		);
		$posts = get_posts(
			array(
				'post_type'      => JLIFE_READING_DAY_CPT,
				'posts_per_page' => -1,
				'post_status'    => 'any',
			)
		);
		$this->assertCount( 3, $posts, 'no duplicate Reading Day' );
		$this->assertSame( $other_before, jlife_studies_get_reading_day( 'rpl-life-and-teachings-of-jesus-2026', 'd038' ), 'other days are unchanged' );
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

	/**
	 * A day for a given post and language, whatever language the request is in:
	 * what the public reading page uses for its own ?lang= switch (#106).
	 */
	public function test_reading_day_for_post_takes_its_own_language() {
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
		$post   = jlife_studies_find_reading_day( $doc['plan_id'], $day_id );

		$vi = jlife_studies_reading_day_for_post( $post, 'vi' );
		$en = jlife_studies_reading_day_for_post( $post, 'en' );
		$this->assertSame( 'Tiêu đề ' . $day_id, $vi['day']['title'], 'the request is English; the day is Vietnamese' );
		$this->assertSame( $doc['days'][0]['title'], $en['day']['title'] );
		$this->assertSame( $post->ID, $vi['post_id'] );
		$this->assertSame( get_permalink( $post ), $vi['url'] );
		$this->assertSame( $vi['plan'], $en['plan'] );
	}

	/**
	 * Harmony section titles and the source line follow the language; a
	 * missing translation falls back to English (#119).
	 */
	public function test_supplemental_and_plan_are_localized_for_vietnamese() {
		$supplemental = array(
			'plan' => array(
				'plan_id'            => 'rpl-harmony-parallel-2026',
				'source_attribution' => 'English supplemental line',
				'i18n'               => array( 'vi' => array( 'source_attribution' => 'Dòng nguồn tiếng Việt' ) ),
			),
			'day'  => array(
				'harmony_sections' => array(
					array(
						'number' => '1',
						'title'  => 'English one',
						'url'    => 'https://harmony-bible.com/sections/1/',
						'i18n'   => array( 'vi' => array( 'title' => 'Tiêu đề một' ) ),
					),
					array(
						'number' => '2',
						'title'  => 'English two',
						'url'    => 'https://harmony-bible.com/sections/2/',
					),
				),
			),
		);

		$vi = jlife_studies_localize_supplemental( $supplemental, 'vi' );
		$this->assertSame( array( 'Tiêu đề một', 'English two' ), array_column( $vi['day']['harmony_sections'], 'title' ), 'fallback to English per section' );
		$this->assertSame( 'https://harmony-bible.com/sections/1/', $vi['day']['harmony_sections'][0]['url'] );
		$this->assertSame( 'Dòng nguồn tiếng Việt', $vi['plan']['source_attribution'] );
		$this->assertSame( $supplemental, jlife_studies_localize_supplemental( $supplemental, 'en_US' ), 'English is unchanged' );
		$this->assertSame( $supplemental, jlife_studies_localize_supplemental( $supplemental, 'fr_FR' ), 'no entry: English' );
		$this->assertNull( jlife_studies_localize_supplemental( null, 'vi' ) );

		$plan = array(
			'source_attribution' => 'English line',
			'i18n'               => array( 'vi' => array( 'source_attribution' => '  ' ) ),
		);
		$this->assertSame( 'English line', jlife_studies_localize_plan( $plan, 'vi' )['source_attribution'], 'a blank translation is ignored' );
		$this->assertNull( jlife_studies_localize_plan( null, 'vi' ) );
	}
}
