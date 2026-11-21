<?php
/**
 * Daily devotionals (#211): the Markdown converter, the preview (no writes), the
 * import (idempotent; skips posts edited in the editor), release per campaign
 * day, display above the writing box, and access to the non-public post type.
 *
 * Fixtures: tests/fixtures/devotionals/*.md and days 1, 38 and 53 of the plan
 * (plugins/jlife-studies/tests/fixtures), loaded through the Studies importer.
 * Everything is made up.
 *
 * @package jlife-challenges
 */

// phpcs:disable WordPress.Security.NonceVerification, WordPress.Security.ValidatedSanitizedInput -- tests set request globals like a request and check the nonce paths themselves.

/**
 * Devotional tests.
 */
class Test_Devotionals extends Jlife_Challenges_TestCase {

	/**
	 * The plan the fixtures belong to.
	 */
	const PLAN = 'rpl-life-and-teachings-of-jesus-2026';

	/**
	 * Temporary files to remove.
	 *
	 * @var string[]
	 */
	private $temp = array();

	/**
	 * The Studies plugin sits beside this one in production but is not loaded by
	 * this suite.
	 */
	public static function wpSetUpBeforeClass() {
		parent::wpSetUpBeforeClass();
		if ( ! function_exists( 'jlife_studies_get_reading_day' ) ) {
			require_once dirname( __DIR__, 2 ) . '/jlife-studies/jlife-studies.php';
		}
	}

	/**
	 * The primary plan imported, a clean request.
	 */
	public function set_up() {
		parent::set_up();
		jlife_studies_register_reading_day();
		jlife_challenges_register_devotional(); // The suite resets post types for each test.
		jlife_studies_import_reading_plan( $this->plan_fixture() );
		wp_set_current_user( $this->u['organizer'] );
		$this->reset_request();
	}

	/**
	 * Remove temporary files and reset the request globals.
	 */
	public function tear_down() {
		foreach ( $this->temp as $path ) {
			wp_delete_file( $path );
		}
		$this->temp = array();
		$this->reset_request();
		wp_set_current_user( 0 );
		parent::tear_down();
	}

	/**
	 * Clear the request globals and the REST server.
	 */
	private function reset_request() {
		$_FILES   = array();
		$_POST    = array();
		$_REQUEST = array();
		unset( $GLOBALS['wp_rest_server'] );
	}

	/**
	 * Put a valid nonce for an action in the request.
	 *
	 * @param string $action Nonce action.
	 */
	private function set_nonce( $action ) {
		$_REQUEST['_wpnonce'] = wp_create_nonce( $action );
	}

	/**
	 * A devotional post's identity and content, to compare before and after.
	 *
	 * @param string $lang Language.
	 * @return array
	 */
	private function snapshot_of( $lang ) {
		$p = $this->post_for( 'd001', $lang );
		return array( $p->ID, $p->post_status, $p->post_content, $p->post_title, $p->post_modified_gmt );
	}

	/**
	 * The IDs of all reading-day posts.
	 *
	 * @return int[]
	 */
	private function reading_day_ids() {
		return get_posts(
			array(
				'post_type'      => JLIFE_READING_DAY_CPT,
				'post_status'    => 'any',
				'posts_per_page' => -1,
				'fields'         => 'ids',
			)
		);
	}

	/**
	 * The plan fixture.
	 *
	 * @return array
	 */
	private function plan_fixture() {
		$raw = file_get_contents( dirname( __DIR__, 2 ) . '/jlife-studies/tests/fixtures/plan-primary.json' ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		return json_decode( (string) $raw, true );
	}

	/**
	 * An upload row for a Markdown fixture file.
	 *
	 * @param string $name Fixture file name (also the uploaded name).
	 * @return array
	 */
	private function fixture_upload( $name ) {
		$path = __DIR__ . '/fixtures/devotionals/' . $name;
		return array(
			'name'     => $name,
			'tmp_name' => $path,
			'size'     => (int) filesize( $path ),
			'error'    => UPLOAD_ERR_OK,
		);
	}

	/**
	 * An upload row for a temporary file with this content.
	 *
	 * @param string $name    Uploaded name.
	 * @param string $content File content.
	 * @return array
	 */
	private function temp_upload( $name, $content ) {
		$path = wp_tempnam( 'jl-dev' );
		file_put_contents( $path, $content ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		$this->temp[] = $path;
		return array(
			'name'     => $name,
			'tmp_name' => $path,
			'size'     => strlen( $content ),
			'error'    => UPLOAD_ERR_OK,
		);
	}

	/**
	 * Read the two Day 1 fixtures (English and Vietnamese).
	 *
	 * @return array[]
	 */
	private function day_one_items() {
		return jlife_challenges_devotional_read_files(
			array(
				$this->fixture_upload( 'D001-EN_Before-Bethlehem.md' ),
				$this->fixture_upload( 'D001-VI_Truoc-Bethlehem.md' ),
			),
			self::PLAN
		);
	}

	/**
	 * Import items as the organizer.
	 *
	 * @param array[] $items     Items.
	 * @param bool    $overwrite Overwrite edits.
	 * @return array[]
	 */
	private function import( $items, $overwrite = false ) {
		$result = jlife_challenges_devotional_import( $this->u['organizer'], $items, self::PLAN, $overwrite );
		$this->assertIsArray( $result );
		return $result;
	}

	/**
	 * The devotional post for a day and language.
	 *
	 * @param string $day  Day ID.
	 * @param string $lang Language.
	 * @return WP_Post|null
	 */
	private function post_for( $day, $lang ) {
		return jlife_challenges_devotional_find( self::PLAN, $day, $lang );
	}

	/**
	 * Count devotional posts in any status.
	 *
	 * @return int
	 */
	private function devotional_count() {
		return count(
			get_posts(
				array(
					'post_type'      => JLIFE_DEVOTIONAL_CPT,
					'post_status'    => array( 'publish', 'draft', 'pending', 'private', 'future', 'trash' ),
					'posts_per_page' => -1,
					'fields'         => 'ids',
				)
			)
		);
	}

	/**
	 * Make a devotional post directly (not through the importer).
	 *
	 * @param string $day     Day ID.
	 * @param string $lang    Language.
	 * @param string $status  Post status.
	 * @param string $title   Title.
	 * @param string $content Content.
	 * @return int
	 */
	private function make_post( $day, $lang, $status, $title = 'T', $content = '<p>Body</p>' ) {
		$id = wp_insert_post(
			array(
				'post_type'    => JLIFE_DEVOTIONAL_CPT,
				'post_status'  => $status,
				'post_title'   => $title,
				'post_content' => $content,
			)
		);
		update_post_meta( $id, '_jlife_dev_plan', self::PLAN );
		update_post_meta( $id, '_jlife_dev_day', $day );
		update_post_meta( $id, '_jlife_dev_lang', $lang );
		return (int) $id;
	}

	/**
	 * A pilot campaign (Dec 5 to 13) on the same plan as the Marathon.
	 *
	 * @return array Campaign row.
	 */
	private function make_pilot() {
		$id = jlife_challenges_create_campaign(
			$this->u['organizer'],
			array(
				'campaign_key' => 'pilot-' . wp_generate_password( 6, false ),
				'plan_id'      => self::PLAN,
				'title'        => 'Pilot (test)',
				'start_date'   => '2026-12-05',
				'end_date'     => '2026-12-13',
				'status'       => 'active',
			)
		);
		$this->assertIsInt( $id );
		return jlife_challenges_get_campaign( $id );
	}

	// -- The converter -------------------------------------------------------

	/**
	 * Headings, bold, links, lists and the questions section convert; the image is
	 * dropped and warned about.
	 */
	public function test_converter_handles_headings_emphasis_links_lists_and_questions() {
		$item = $this->day_one_items()[0];
		$this->assertSame( '', $item['error'] );
		$this->assertSame( 'd001', $item['day_id'] );
		$this->assertSame( 'en', $item['lang'] );
		$this->assertSame( 'Before Bethlehem', $item['title'] );
		$this->assertStringContainsString( '<h2>Luke 1:1-4</h2>', $item['html'] );
		$this->assertStringContainsString( '<strong>know</strong>', $item['html'] );
		$this->assertStringContainsString( '<a href="https://example.test/plan">the plan</a>', $item['html'] );
		$this->assertStringContainsString( '<li>First point</li>', $item['html'] );
		$this->assertStringContainsString( '<h3>Reflection questions</h3>', $item['html'] );
		$this->assertStringNotContainsString( '<img', $item['html'] );
		$this->assertStringNotContainsString( 'map.png', $item['html'] );
		$this->assertSame(
			array( 'Where have you seen God keep a promise this week?', 'What is one thing you will do today?' ),
			$item['questions']
		);
		$this->assertSame( array( 'images_dropped' ), array_column( $item['warnings'], 'code' ) );
		$this->assertSame( 1, $item['warnings'][0]['count'] );
	}

	/**
	 * Vietnamese letters and tone marks survive, and the Vietnamese questions
	 * heading is recognised.
	 */
	public function test_vietnamese_is_preserved() {
		$item = $this->day_one_items()[1];
		$this->assertSame( '', $item['error'] );
		$this->assertSame( 'vi', $item['lang'] );
		$this->assertSame( 'Trước Bê-lê-hem', $item['title'] );
		$this->assertStringContainsString( 'Lu-ca viết để bạn biết chắc chắn', $item['html'] );
		$this->assertStringContainsString( '<h3>Câu hỏi suy ngẫm</h3>', $item['html'] );
		$this->assertSame( 'Tuần này bạn đã thấy Chúa giữ lời hứa ở đâu?', $item['questions'][0] );
		$this->assertCount( 2, $item['questions'] );
	}

	/**
	 * Raw HTML is escaped, unsafe links are neutralised and both kinds of image are
	 * dropped and counted.
	 */
	public function test_unsafe_markup_is_neutralised_and_images_are_dropped() {
		$items = jlife_challenges_devotional_read_files( array( $this->fixture_upload( 'D038-EN_Unsafe-Markup.md' ) ), self::PLAN );
		$item  = $items[0];
		$this->assertSame( '', $item['error'] );
		$this->assertStringNotContainsString( '<script', $item['html'] );
		$this->assertStringNotContainsString( 'javascript:', $item['html'] );
		$this->assertStringNotContainsString( '<img', $item['html'] );
		$this->assertStringNotContainsString( 'onerror', $item['html'] );
		$this->assertStringContainsString( 'href="https://example.test/a"', $item['html'] );
		$this->assertSame( 2, $item['warnings'][0]['count'] );
		$this->assertSame( 'images_dropped', $item['warnings'][0]['code'] );
	}

	/**
	 * Bad files are reported, not imported: wrong name, not Markdown, unknown day,
	 * not UTF-8, empty, too large, and a repeat of the same day and language.
	 */
	public function test_bad_files_are_reported() {
		$good  = $this->fixture_upload( 'D001-EN_Before-Bethlehem.md' );
		$large = array_merge( $this->temp_upload( 'D001-VI_Large.md', '# X' ), array( 'size' => JLIFE_DEVOTIONAL_MAX_BYTES + 1 ) );
		$items = jlife_challenges_devotional_read_files(
			array(
				$this->temp_upload( 'D001-XX_Bad.md', '# X' ),
				$this->temp_upload( 'notes.txt', '# X' ),
				$this->temp_upload( 'D999-EN_Unknown.md', "# X\n\nText" ),
				$this->temp_upload( 'D038-VI_Latin1.md', "# X\n\nB\xE1d" ),
				$this->temp_upload( 'D053-EN_Empty.md', "  \n" ),
				$large,
				$good,
				array_merge( $good, array( 'name' => 'D001-EN_Again.md' ) ),
			),
			self::PLAN
		);
		$this->assertSame(
			array( 'bad_name', 'not_md', 'unknown_day', 'not_utf8', 'empty', 'too_large', '', 'duplicate' ),
			array_column( $items, 'error' )
		);
	}

	// -- Preview and import --------------------------------------------------

	/**
	 * A preview writes nothing: no devotional post, no change to any reading day.
	 */
	public function test_preview_writes_nothing() {
		$before = $this->devotional_count();
		$days   = $this->reading_day_ids();
		$items  = jlife_challenges_devotional_preview( $this->day_one_items(), self::PLAN );
		$this->assertSame( array( 'new', 'new' ), array_column( $items, 'state' ) );
		$this->assertSame( $before, $this->devotional_count() );
		$this->assertSame( $days, $this->reading_day_ids() );
	}

	/**
	 * Import creates Drafts with the right meta, and importing the same files
	 * again changes nothing and creates no duplicate.
	 */
	public function test_import_creates_drafts_and_is_idempotent() {
		$first = $this->import( $this->day_one_items() );
		$this->assertSame( array( 'created', 'created' ), array_column( $first, 'result' ) );
		$en = $this->post_for( 'd001', 'en' );
		$this->assertSame( 'draft', $en->post_status );
		$this->assertSame( 'Before Bethlehem', $en->post_title );
		$this->assertSame( self::PLAN, get_post_meta( $en->ID, '_jlife_dev_plan', true ) );
		$this->assertSame( 2, $this->devotional_count() );

		$again = $this->import( $this->day_one_items() );
		$this->assertSame( array( 'unchanged', 'unchanged' ), array_column( $again, 'result' ) );
		$this->assertSame( 2, $this->devotional_count() );
		$this->assertSame( $en->ID, $this->post_for( 'd001', 'en' )->ID );
	}

	/**
	 * A post edited in the editor is skipped (even for the same file) unless
	 * "Overwrite my edits" is ticked, and the edit is kept.
	 */
	public function test_an_edited_post_is_skipped_unless_overwrite() {
		$this->import( $this->day_one_items() );
		$en = $this->post_for( 'd001', 'en' );
		wp_update_post(
			array(
				'ID'           => $en->ID,
				'post_content' => '<p>My hand edit</p>',
			)
		);

		$preview = jlife_challenges_devotional_preview( $this->day_one_items(), self::PLAN );
		$this->assertSame( array( 'skipped_edited', 'unchanged' ), array_column( $preview, 'state' ) );
		$kept = $this->import( $this->day_one_items() );
		$this->assertSame( array( 'skipped_edited', 'unchanged' ), array_column( $kept, 'result' ) );
		$this->assertSame( '<p>My hand edit</p>', $this->post_for( 'd001', 'en' )->post_content );

		$over = $this->import( $this->day_one_items(), true );
		$this->assertSame( array( 'updated', 'unchanged' ), array_column( $over, 'result' ) );
		$this->assertStringContainsString( 'Luke 1:1-4', $this->post_for( 'd001', 'en' )->post_content );
		$this->assertSame( 2, $this->devotional_count() );
	}

	/**
	 * Import never changes Draft or Ready. A changed file updates an unedited Ready
	 * post and leaves it Ready; a Draft stays a Draft.
	 */
	public function test_import_never_changes_the_status() {
		$this->import( $this->day_one_items() );
		$this->assertTrue( jlife_challenges_devotional_set_ready( $this->u['organizer'], $this->post_for( 'd001', 'en' )->ID, true ) );

		$items = $this->day_one_items();
		foreach ( array_keys( $items ) as $i ) {
			$items[ $i ]['html']     = '<p>A newer text.</p>';
			$items[ $i ]['src_hash'] = jlife_challenges_devotional_hash( $items[ $i ]['title'], $items[ $i ]['html'] );
		}
		$result = $this->import( $items );
		$this->assertSame( array( 'updated', 'updated' ), array_column( $result, 'result' ) );
		$this->assertSame( 'publish', $this->post_for( 'd001', 'en' )->post_status );
		$this->assertSame( 'draft', $this->post_for( 'd001', 'vi' )->post_status );
		$this->assertStringContainsString( 'A newer text.', $this->post_for( 'd001', 'en' )->post_content );
	}

	/**
	 * The two importers leave each other's posts alone, in both directions.
	 */
	public function test_plan_import_and_devotional_import_do_not_touch_each_other() {
		$this->import( $this->day_one_items() );
		jlife_challenges_devotional_set_ready( $this->u['organizer'], $this->post_for( 'd001', 'en' )->ID, true );
		$snapshot = array();
		foreach ( array( 'en', 'vi' ) as $lang ) {
			$snapshot[ $lang ] = $this->snapshot_of( $lang );
		}

		// A routine deploy: the reading-plan import, twice.
		$this->assertIsInt( jlife_studies_import_reading_plan( $this->plan_fixture() ) );
		$this->assertIsInt( jlife_studies_import_reading_plan( $this->plan_fixture() ) );
		foreach ( array( 'en', 'vi' ) as $lang ) {
			$this->assertSame( $snapshot[ $lang ], $this->snapshot_of( $lang ) );
		}
		$this->assertSame( 2, $this->devotional_count() );

		// And the other way: importing devotionals leaves every reading day as it was.
		$day = jlife_studies_find_reading_day( self::PLAN, 'd001' );
		wp_update_post(
			array(
				'ID'          => $day->ID,
				'post_status' => 'draft',
			)
		);
		$meta = get_post_meta( $day->ID, '_jlife_reading_day', true );
		$this->import( $this->day_one_items() );
		$this->import( $this->day_one_items(), true );
		$after = jlife_studies_find_reading_day( self::PLAN, 'd001' );
		$this->assertSame( 'draft', $after->post_status );
		$this->assertSame( $meta, get_post_meta( $day->ID, '_jlife_reading_day', true ) );
		$this->assertSame( JLIFE_READING_DAY_CPT, $after->post_type );
	}

	/**
	 * Exactly one post per plan, day and language, however often it is imported.
	 */
	public function test_one_post_per_plan_day_and_language() {
		for ( $i = 0; $i < 3; $i++ ) {
			$this->import( $this->day_one_items(), true );
		}
		foreach ( array( 'en', 'vi' ) as $lang ) {
			$found = get_posts(
				array(
					'post_type'   => JLIFE_DEVOTIONAL_CPT,
					'post_status' => 'any',
					'fields'      => 'ids',
					'meta_query'  => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- test.
						array(
							'key'   => '_jlife_dev_day',
							'value' => 'd001',
						),
						array(
							'key'   => '_jlife_dev_lang',
							'value' => $lang,
						),
					),
				)
			);
			$this->assertCount( 1, $found );
		}
	}

	/**
	 * A devotional in the Trash is not recreated or touched by an import.
	 */
	public function test_a_trashed_devotional_is_skipped() {
		$this->import( $this->day_one_items() );
		wp_trash_post( $this->post_for( 'd001', 'en' )->ID );
		$result = $this->import( $this->day_one_items() );
		$this->assertSame( array( 'skipped_trash', 'unchanged' ), array_column( $result, 'result' ) );
		$this->assertSame( 2, $this->devotional_count() );
	}

	/**
	 * Only administrators import, and the audit log keeps counts, never a file
	 * name or any text.
	 */
	public function test_import_needs_an_administrator_and_audits_counts_only() {
		$denied = jlife_challenges_devotional_import( $this->u['a'], $this->day_one_items(), self::PLAN );
		$this->assertWPError( $denied );
		$this->assertSame( 0, $this->devotional_count() );

		$this->import( $this->day_one_items() );
		global $wpdb;
		$detail = (string) $wpdb->get_var( 'SELECT detail FROM ' . jlife_challenges_table( 'audit_log' ) . " WHERE action = 'devotionals_imported' ORDER BY id DESC LIMIT 1" ); // phpcs:ignore WordPress.DB, WordPress.DB.PreparedSQL.NotPrepared -- test.
		$this->assertSame(
			array(
				'created'   => 2,
				'updated'   => 0,
				'unchanged' => 0,
				'skipped'   => 0,
				'failed'    => 0,
			),
			json_decode( $detail, true )
		);
		$this->assertStringNotContainsString( 'Bethlehem', $detail );
		$this->assertStringNotContainsString( '.md', $detail );
	}

	// -- Release and display -------------------------------------------------

	/**
	 * A Draft never shows, a Ready one shows only once its day has opened in the
	 * campaign, and there is no fallback to the other language.
	 */
	public function test_a_draft_never_shows_and_there_is_no_language_fallback() {
		$id       = $this->make_post( 'd001', 'en', 'draft' );
		$campaign = $this->campaign_row();
		$this->set_now( '2026-12-21 18:00:00' ); // Day 2 of the Marathon.
		$this->assertNull( jlife_challenges_devotional_for_day( $campaign, 'd001', 'en' ) );

		wp_update_post(
			array(
				'ID'          => $id,
				'post_status' => 'publish',
			)
		);
		$this->assertSame( $id, jlife_challenges_devotional_for_day( $campaign, 'd001', 'en' )->ID );
		$this->assertNull( jlife_challenges_devotional_for_day( $campaign, 'd001', 'vi' ) );
	}

	/**
	 * Release follows each campaign's own calendar: the pilot's Day 1 (Dec 5) and the
	 * Marathon's Day 1 (Dec 20) are the same Reading Day. The post's own date never
	 * decides it, and a day beyond the pilot's nine does not open there.
	 */
	public function test_release_is_per_campaign_day() {
		$pilot    = $this->make_pilot();
		$official = $this->campaign_row();
		$id       = $this->make_post( 'd001', 'en', 'publish' );
		wp_update_post(
			array(
				'ID'            => $id,
				'post_date'     => '2020-01-01 00:00:00',
				'post_date_gmt' => '2020-01-01 00:00:00',
				'post_status'   => 'publish',
			)
		);
		$this->make_post( 'd038', 'en', 'publish' );

		$this->set_now( '2026-12-01 18:00:00' );
		$this->assertNull( jlife_challenges_devotional_for_day( $pilot, 'd001', 'en' ) );
		$this->assertNull( jlife_challenges_devotional_for_day( $official, 'd001', 'en' ) );

		$this->set_now( '2026-12-06 18:00:00' ); // Pilot Day 2; the Marathon has not started.
		$this->assertNotNull( jlife_challenges_devotional_for_day( $pilot, 'd001', 'en' ) );
		$this->assertNull( jlife_challenges_devotional_for_day( $official, 'd001', 'en' ) );
		$this->assertNull( jlife_challenges_devotional_for_day( $pilot, 'd038', 'en' ) );

		$this->set_now( '2026-12-20 18:00:00' ); // Marathon Day 1.
		$this->assertNotNull( jlife_challenges_devotional_for_day( $official, 'd001', 'en' ) );
		$this->assertNull( jlife_challenges_devotional_for_day( $official, 'd038', 'en' ) );
	}

	/**
	 * Today, /soap?day= and earlier days show the devotional above the writing box,
	 * open and collapsible, in the participant's language; a day that has not
	 * opened shows nothing.
	 */
	public function test_participant_screens_show_it_above_the_writing_box() {
		$this->make_post( 'd001', 'en', 'publish', 'Before Bethlehem', '<h2>Luke 1:1-4</h2><p>The devotional body.</p>' );
		$this->make_post( 'd001', 'vi', 'publish', 'Trước Bê-lê-hem', '<p>Nội dung suy niệm.</p>' );
		wp_set_current_user( $this->u['a'] );

		$html = jlife_challenges_render_soap_panel( jlife_challenges_day_view( $this->u['a'] ) );
		$this->assertStringContainsString( '<details class="jl-devotional" open>', $html );
		$this->assertStringContainsString( 'The devotional body.', $html );
		$this->assertStringNotContainsString( 'Nội dung suy niệm.', $html );
		$this->assertLessThan( strpos( $html, '<textarea' ), strpos( $html, 'jl-devotional' ) );

		// An earlier day, opened from /soap?day=d001 on Day 2.
		$this->set_now( '2026-12-21 18:00:00' );
		$earlier = jlife_challenges_render_soap_panel( jlife_challenges_day_view( $this->u['a'], 'd001' ) );
		$this->assertStringContainsString( 'The devotional body.', $earlier );
		$this->assertSame( 'unavailable', jlife_challenges_day_view( $this->u['a'], 'd038' )['state'] );

		// A Vietnamese reader sees the Vietnamese one only.
		update_user_meta( $this->u['a'], 'locale', 'vi' );
		$vi = jlife_challenges_render_soap_panel( jlife_challenges_day_view( $this->u['a'], 'd001' ) );
		$this->assertStringContainsString( 'Nội dung suy niệm.', $vi );
		$this->assertStringNotContainsString( 'The devotional body.', $vi );
	}

	/**
	 * A language with no Ready devotional shows nothing: no English fallback.
	 */
	public function test_no_translation_shows_nothing() {
		$this->make_post( 'd001', 'en', 'publish' );
		wp_set_current_user( $this->u['a'] );
		update_user_meta( $this->u['a'], 'locale', 'vi' );
		$this->assertStringNotContainsString( 'jl-devotional', jlife_challenges_render_soap_panel( jlife_challenges_day_view( $this->u['a'] ) ) );
	}

	/**
	 * A public reading page shows it for the plan's current campaign once the day has
	 * opened, in the page's language.
	 */
	public function test_the_public_reading_view_shows_it_once_the_day_has_opened() {
		$pilot = $this->make_pilot();
		$this->assertNotEmpty( $pilot );
		$this->make_post( 'd001', 'en', 'publish', 'Before Bethlehem', '<p>Public body.</p>' );
		$post = jlife_studies_find_reading_day( self::PLAN, 'd001' );
		wp_set_current_user( 0 );

		$this->set_now( '2026-11-30 18:00:00' );
		$view = jlife_challenges_public_reading_view( $post, 'en' );
		$this->assertSame( '', jlife_challenges_render_devotional( $view ) );

		$this->set_now( '2026-12-05 18:00:00' ); // Pilot Day 1.
		$this->assertStringContainsString( 'Public body.', jlife_challenges_render_devotional( $view ) );
		$this->assertSame( '', jlife_challenges_render_devotional( jlife_challenges_public_reading_view( $post, 'vi' ) ) );
	}

	/**
	 * Output is escaped on every surface: the body is filtered again, the title is
	 * escaped, and a participant-visible page carries no script or handler.
	 */
	public function test_output_is_escaped() {
		$this->make_post( 'd001', 'en', 'publish', '<img src=x onerror=alert(1)> Title', '<p onclick="steal()">Hi</p><script>alert(1)</script>' );
		wp_set_current_user( $this->u['a'] );
		$html = jlife_challenges_render_devotional( jlife_challenges_day_view( $this->u['a'] ) );
		$this->assertStringContainsString( 'Hi', $html );
		$this->assertStringNotContainsString( '<script', $html );
		$this->assertStringNotContainsString( 'onclick', $html );
		$this->assertStringNotContainsString( '<img', $html );
	}

	/**
	 * The SOAP-practice suggestion shows on a flagged day of a campaign that starts
	 * blank, and nowhere else; the flag is the post's meta.
	 */
	public function test_soap_practice_suggestion() {
		$id       = $this->make_post( 'd001', 'en', 'publish' );
		$campaign = $this->campaign_row();
		$view     = array(
			'day_key'  => 'd001',
			'campaign' => $campaign,
		);
		wp_set_current_user( $this->u['a'] );
		$this->assertStringNotContainsString( 'jl-devotional-soap', jlife_challenges_render_devotional( $view ) );

		jlife_challenges_devotional_set_soap_practice( $id, true );
		$this->assertStringNotContainsString( 'jl-devotional-soap', jlife_challenges_render_devotional( $view ), 'a SOAP campaign needs no suggestion' );

		$view['campaign']['scoring_engine'] = JLIFE_CHALLENGES_REFLECTION_ENGINE;
		$this->assertStringContainsString( 'jl-devotional-soap', jlife_challenges_render_devotional( $view ) );

		jlife_challenges_devotional_set_soap_practice( $id, false );
		$this->assertStringNotContainsString( 'jl-devotional-soap', jlife_challenges_render_devotional( $view ) );
	}

	/**
	 * The meta box saves the tick for an administrator with the nonce, and
	 * nobody else.
	 */
	public function test_the_meta_box_saves_with_a_nonce_and_capability() {
		$id = $this->make_post( 'd001', 'en', 'draft' );

		$_POST['jl_devotional_soap_practice'] = '1';
		jlife_challenges_devotional_save_meta_box( $id, get_post( $id ) );
		$this->assertSame( '', (string) get_post_meta( $id, '_jlife_dev_soap_practice', true ), 'no nonce, no change' );

		$_POST['_jlife_devotional_soap'] = wp_create_nonce( 'jlife_devotional_soap_' . $id );
		jlife_challenges_devotional_save_meta_box( $id, get_post( $id ) );
		$this->assertSame( '1', get_post_meta( $id, '_jlife_dev_soap_practice', true ) );

		unset( $_POST['jl_devotional_soap_practice'] );
		wp_set_current_user( $this->u['a'] );
		jlife_challenges_devotional_save_meta_box( $id, get_post( $id ) );
		$this->assertSame( '1', get_post_meta( $id, '_jlife_dev_soap_practice', true ), 'a participant cannot clear it' );
	}

	/**
	 * Feed #210's excluded-text filter with the questions of Ready devotionals of a
	 * day that has opened, in both languages; a Draft adds nothing.
	 */
	public function test_the_questions_feed_the_excluded_text_filter() {
		$this->import( $this->day_one_items() );
		$campaign = $this->campaign_row();
		$context  = array(
			'campaign' => $campaign,
			'day_key'  => 'd001',
			'index'    => 1,
		);
		$this->assertSame( array(), apply_filters( 'jlife_challenges_reflection_excluded_text', array(), $context ), 'drafts are not excluded' );

		jlife_challenges_devotional_set_ready( $this->u['organizer'], $this->post_for( 'd001', 'en' )->ID, true );
		jlife_challenges_devotional_set_ready( $this->u['organizer'], $this->post_for( 'd001', 'vi' )->ID, true );
		$excluded = apply_filters( 'jlife_challenges_reflection_excluded_text', array(), $context );
		$this->assertContains( 'Where have you seen God keep a promise this week?', $excluded );
		$this->assertContains( 'Hôm nay bạn sẽ làm một điều gì?', $excluded );

		$own = jlife_challenges_reflection_own_text( 'Where have you seen God keep a promise this week? I saw it at home.', $context );
		$this->assertSame( 'I saw it at home.', $own );

		$this->set_now( '2026-12-19 18:00:00' ); // Before Day 1: not released.
		$this->assertSame( array(), apply_filters( 'jlife_challenges_reflection_excluded_text', array(), $context ) );
	}

	// -- Access --------------------------------------------------------------

	/**
	 * Draft and Ready devotionals are not readable over REST by anyone but an
	 * administrator, and the post type is not public.
	 */
	public function test_no_anonymous_or_participant_rest_read() {
		$draft = $this->make_post( 'd001', 'en', 'draft' );
		$ready = $this->make_post( 'd001', 'vi', 'publish' );
		$this->assertFalse( is_post_type_viewable( JLIFE_DEVOTIONAL_CPT ) );
		$base = '/wp/v2/' . JLIFE_DEVOTIONAL_REST_BASE;

		wp_set_current_user( 0 );
		foreach ( array( $base, $base . '/' . $draft, $base . '/' . $ready ) as $route ) {
			$this->assertSame( 401, rest_do_request( new WP_REST_Request( 'GET', $route ) )->get_status(), $route );
		}
		wp_set_current_user( $this->u['a'] );
		foreach ( array( $base, $base . '/' . $draft, $base . '/' . $ready ) as $route ) {
			$this->assertSame( 403, rest_do_request( new WP_REST_Request( 'GET', $route ) )->get_status(), $route );
		}
		wp_set_current_user( $this->u['organizer'] );
		$this->assertSame( 200, rest_do_request( new WP_REST_Request( 'GET', $base . '/' . $ready ) )->get_status() );
	}

	/**
	 * Only administrators can edit; nobody can add one by hand; the editor is the
	 * block editor (REST on, no theme page builder).
	 */
	public function test_editing_is_for_administrators_only() {
		$id     = $this->make_post( 'd001', 'en', 'publish' );
		$editor = self::factory()->user->create( array( 'role' => 'editor' ) );
		$type   = get_post_type_object( JLIFE_DEVOTIONAL_CPT );
		$this->assertTrue( user_can( $this->u['organizer'], 'edit_post', $id ) );
		$this->assertFalse( user_can( $editor, 'edit_post', $id ) );
		$this->assertFalse( user_can( $this->u['a'], 'edit_post', $id ) );
		$this->assertFalse( user_can( $editor, $type->cap->edit_posts ) );
		$this->assertFalse( user_can( $this->u['organizer'], $type->cap->create_posts ), 'posts come only from the import' );
		$this->assertTrue( $type->show_in_rest );
		$this->assertTrue( post_type_supports( JLIFE_DEVOTIONAL_CPT, 'editor' ) );
		$this->assertTrue( post_type_supports( JLIFE_DEVOTIONAL_CPT, 'revisions' ) );
		$this->assertSame( array( 'post', 'page' ), apply_filters( 'et_builder_post_types', array( 'post', JLIFE_DEVOTIONAL_CPT, 'page' ) ), "Divi's builder is off for devotionals" );
	}

	/**
	 * Mark Ready and Back to Draft: administrators only, and a trashed post is
	 * left alone.
	 */
	public function test_mark_ready_is_for_administrators() {
		$id = $this->make_post( 'd001', 'en', 'draft' );
		$this->assertFalse( jlife_challenges_devotional_set_ready( $this->u['a'], $id, true ) );
		$this->assertSame( 'draft', get_post_status( $id ) );
		$this->assertTrue( jlife_challenges_devotional_set_ready( $this->u['organizer'], $id, true ) );
		$this->assertSame( 'publish', get_post_status( $id ) );
		$this->assertTrue( jlife_challenges_devotional_set_ready( $this->u['organizer'], $id, false ) );
		$this->assertSame( 'draft', get_post_status( $id ) );
		wp_trash_post( $id );
		$this->assertFalse( jlife_challenges_devotional_set_ready( $this->u['organizer'], $id, true ) );
		$this->assertFalse( jlife_challenges_devotional_set_ready( $this->u['organizer'], 0, true ) );
	}

	// -- The admin page ------------------------------------------------------

	/**
	 * The page is for administrators; others are refused.
	 */
	public function test_the_admin_page_is_for_administrators() {
		$this->make_post( 'd001', 'en', 'draft' );
		ob_start();
		jlife_challenges_devotionals_admin_page();
		$html = (string) ob_get_clean();
		$this->assertStringContainsString( 'Devotionals', $html );
		$this->assertStringContainsString( 'name="jl_files[]"', $html );
		$this->assertStringContainsString( 'Mark Ready', $html );

		wp_set_current_user( $this->u['a'] );
		$this->expectException( 'WPDieException' );
		jlife_challenges_devotionals_admin_page();
	}

	/**
	 * Preview, then Import with the token: nothing is written by the preview, the
	 * import creates Drafts, and the token works once.
	 */
	public function test_preview_then_import_through_the_handlers() {
		$_FILES = array(
			'jl_files' => array(
				'name'     => array( 'D001-EN_Before-Bethlehem.md', 'D001-VI_Truoc-Bethlehem.md' ),
				'tmp_name' => array( __DIR__ . '/fixtures/devotionals/D001-EN_Before-Bethlehem.md', __DIR__ . '/fixtures/devotionals/D001-VI_Truoc-Bethlehem.md' ),
				'size'     => array( 400, 400 ),
				'error'    => array( UPLOAD_ERR_OK, UPLOAD_ERR_OK ),
			),
		);
		$this->set_nonce( 'jlife_dev_preview' );
		$report = jlife_challenges_devotionals_handle_preview( self::PLAN );
		$this->assertSame( 'preview', $report['mode'] );
		$this->assertSame( array( 'new', 'new' ), array_column( $report['items'], 'state' ) );
		$this->assertSame( 0, $this->devotional_count() );
		$this->assertMatchesRegularExpression( '/^[A-Za-z0-9]{20}$/', $report['token'] );
		$this->assertStringContainsString( 'Import', jlife_challenges_devotionals_preview_html( $report ) );

		$_POST['jl_token'] = $report['token'];
		$this->set_nonce( 'jlife_dev_import' );
		$done = jlife_challenges_devotionals_handle_import( self::PLAN );
		$this->assertSame( 'import', $done['mode'] );
		$this->assertSame( array( 'created', 'created' ), array_column( $done['items'], 'result' ) );
		$this->assertSame( 2, $this->devotional_count() );

		$again = jlife_challenges_devotionals_handle_import( self::PLAN );
		$this->assertSame( 'expired', $again['mode'] );
		$this->assertSame( 2, $this->devotional_count() );
	}

	/**
	 * A request without a valid nonce does nothing.
	 */
	public function test_the_handlers_check_their_nonce() {
		$this->expectException( 'WPDieException' );
		jlife_challenges_devotionals_handle_preview( self::PLAN );
	}
}
