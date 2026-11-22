<?php
/**
 * The "reflection-v1" scoring engine (#210): free-form reflections and SOAP
 * entries under one rule (20 words of the person's own, 8 of them different),
 * the blank editor with its "Use SOAP template" button, the engine option on
 * the pilot and official campaigns, set-engine, and the unchanged one point
 * per person per campaign per day.
 *
 * @package jlife-challenges
 */

/**
 * Reflection-v1 tests.
 */
class Test_Reflection_V1 extends Jlife_Challenges_TestCase {

	/**
	 * A reflection of at least 20 words, more than 8 of them different.
	 */
	const REFLECTION = 'Today I read about John calling people to turn back to God, and I want to prepare my heart this week by praying more and listening quietly each morning.';

	/**
	 * Score a text with reflection-v1.
	 *
	 * @param string $text    Entry text.
	 * @param array  $context Engine context.
	 * @return array
	 */
	private function score( $text, $context = array() ) {
		return jlife_challenges_score_entry( $text, array_merge( array( 'engine' => 'reflection-v1' ), $context ) );
	}

	/**
	 * Run the base campaign on reflection-v1.
	 */
	private function use_reflection_engine() {
		global $wpdb;
		$wpdb->update( jlife_challenges_table( 'campaigns' ), array( 'scoring_engine' => 'reflection-v1' ), array( 'id' => $this->campaign ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- test fixture.
	}

	/**
	 * N different words, w<start> up to w<start + N - 1>.
	 *
	 * @param int $n     How many.
	 * @param int $start First number.
	 * @return string
	 */
	private function words( $n, $start = 1 ) {
		$out = array();
		for ( $i = $start; $i < $start + $n; $i++ ) {
			$out[] = 'w' . $i;
		}
		return implode( ' ', $out );
	}

	/**
	 * The engine is registered, versioned and starts blank; soap-regex is unchanged.
	 */
	public function test_engine_is_registered_and_versioned() {
		$engines = jlife_challenges_scoring_engines();
		$this->assertArrayHasKey( 'reflection-v1', $engines );
		$this->assertArrayHasKey( 'soap-regex', $engines );
		$this->assertTrue( $engines['reflection-v1']['starts_blank'] );
		$this->assertFalse( $engines['soap-regex']['starts_blank'] );
		$r = $this->score( self::REFLECTION );
		$this->assertSame( array( 'passes', 'reasons', 'engine', 'version' ), array_keys( $r ) );
		$this->assertSame( 'reflection-v1', $r['engine'] );
		$this->assertSame( '1', $r['version'] );
		$this->assertSame( 'soap-regex', JLIFE_CHALLENGES_DEFAULT_ENGINE, 'the default is not changed' );
	}

	/**
	 * Empty, untouched template (both languages) and headings only earn nothing, with the kind message.
	 */
	public function test_empty_template_and_headings_only_fail() {
		$vi_template = "S — Kinh Thánh\n\nO — Quan sát\n\nA — Áp dụng\n\nP — Cầu nguyện";
		foreach ( array(
			'empty'               => '',
			'spaces'              => "  \n\n  ",
			'template'            => jlife_challenges_soap_template(),
			'english headings'    => 'S — Scripture O — Observation A — Application P — Prayer',
			'vietnamese template' => $vi_template,
			'bare letters'        => "S:\nO:\nA:\nP:",
			'punctuation only'    => '... --- !!! ???',
		) as $label => $text ) {
			$r = $this->score( $text );
			$this->assertFalse( $r['passes'], $label );
			$this->assertSame( array( 'Write a few more words of your own.' ), $r['reasons'], $label );
		}
	}

	/**
	 * 19 own words fail and 20 pass.
	 */
	public function test_twenty_word_boundary() {
		$this->assertFalse( $this->score( $this->words( 19 ) )['passes'] );
		$this->assertTrue( $this->score( $this->words( 20 ) )['passes'] );
		$this->assertSame( array(), $this->score( $this->words( 20 ) )['reasons'] );
		$this->assertTrue( $this->score( $this->words( 60 ) )['passes'] );
	}

	/**
	 * At least 8 different words: 7 different in 20 fail, 8 pass; case and end punctuation do not make words different.
	 */
	public function test_eight_different_words_boundary() {
		$seven = 'a b c d e f g a b c d e f g a b c d e f g';
		$this->assertFalse( $this->score( $seven )['passes'], '21 words, 7 different' );

		$eight = 'a b c d e f g h a b c d e f g h a b c d e f g h';
		$this->assertTrue( $this->score( $eight )['passes'], '24 words, 8 different' );

		$cases = 'Amen amen AMEN, amen. amen! Lord lord LORD, lord. lord! Yes yes YES, yes. yes! Good good GOOD, good. good!';
		$this->assertFalse( $this->score( $cases )['passes'], 'case and punctuation are not new words' );
	}

	/**
	 * One repeated word, however long, does not pass.
	 */
	public function test_repeated_word_spam_fails() {
		$this->assertFalse( $this->score( str_repeat( 'amen ', 200 ) )['passes'] );
		$this->assertFalse( $this->score( str_repeat( 'a b ', 100 ) )['passes'] );
	}

	/**
	 * A SOAP entry with 20 or more own words passes; headings never add to the count.
	 */
	public function test_soap_and_free_form_pass_and_headings_do_not_count() {
		$this->assertTrue( $this->score( self::REFLECTION )['passes'], 'free-form' );
		$this->assertTrue( $this->score( $this->soap( 'one' ) . ' ' . $this->words( 12 ) )['passes'], 'a complete SOAP with enough words' );

		$headed = jlife_challenges_join_sections(
			array(
				'S' => 'John 3:16',
				'O' => 'God loved the world',
				'A' => 'I will love too',
				'P' => 'Help me',
			),
			jlife_challenges_soap_headings()
		);
		$this->assertFalse( $this->score( $headed )['passes'], 'a short SOAP is short however many headings it has' );

		$body = "S — Scripture\n" . $this->words( 4, 1 ) . "\n\nO — Observation\n" . $this->words( 4, 5 ) . "\n\nA — Application\n" . $this->words( 4, 9 ) . "\n\nP — Prayer\n" . $this->words( 4, 13 );
		$this->assertFalse( $this->score( $body )['passes'], '16 own words plus 8 heading words must not reach 20' );
		$this->assertTrue( $this->score( $body . ' ' . $this->words( 4, 17 ) )['passes'], '20 own words under headings' );

		$this->assertTrue( $this->score( "S — Kinh Thánh\n" . $this->words( 10, 1 ) . "\n\nO — Quan sát\n" . $this->words( 10, 11 ) )['passes'], 'Vietnamese headings, 20 own words' );
		$this->assertFalse( $this->score( "S — Kinh Thánh\n" . $this->words( 9, 1 ) . "\n\nO — Quan sát\n" . $this->words( 9, 10 ) )['passes'], '18 own words with Vietnamese headings' );
		$this->assertFalse( $this->score( 's: ' . $this->words( 9, 1 ) . "\no: " . $this->words( 9, 10 ) )['passes'], 'letter-and-colon headings do not count' );
	}

	/**
	 * Same input, same result, every time.
	 */
	public function test_determinism() {
		foreach ( array( self::REFLECTION, $this->words( 19 ), $this->soap(), '' ) as $text ) {
			$this->assertSame( $this->score( $text ), $this->score( $text ) );
		}
	}

	/**
	 * The hook for the day's devotional questions (#211): excluded text never counts.
	 */
	public function test_excluded_text_filter_removes_the_days_questions() {
		$question = 'What does this passage show you about who Jesus is and what He asks of you today?';
		$text     = $question . ' ' . $this->words( 6 );
		$this->assertTrue( $this->score( $text )['passes'], 'no filter: the copied question counts, as nothing is known about it yet' );

		$seen = array();
		add_filter(
			'jlife_challenges_reflection_excluded_text',
			static function ( $excluded, $context ) use ( $question, &$seen ) {
				$seen       = $context;
				$excluded[] = $question;
				return $excluded;
			},
			10,
			2
		);
		$context = array(
			'day_key' => 'd003',
			'index'   => 3,
		);
		$this->assertFalse( $this->score( $text, $context )['passes'], 'question plus 6 words is not enough' );
		$this->assertSame( 'd003', $seen['day_key'], 'the filter knows the day' );
		$this->assertSame( 3, $seen['index'] );
		$this->assertFalse( $this->score( strtoupper( $question ) . "\n" . $this->words( 6 ), $context )['passes'], 'case and line breaks do not hide it' );
		$this->assertTrue( $this->score( $question . ' ' . $this->words( 20 ), $context )['passes'], 'the person\'s own 20 words still pass' );
		$this->assertFalse( $this->score( $question, $context )['passes'], 'the copied question alone' );
	}

	/**
	 * A campaign on reflection-v1: any 20-word reflection earns the one point, the ledger records the
	 * engine and version, and a second submission never adds another point.
	 */
	public function test_submit_earns_one_point_and_the_ledger_records_the_engine() {
		$this->use_reflection_engine();
		$short = jlife_challenges_submit( $this->u['a'], $this->campaign, 'd001', 'Just a few words here.' );
		$this->assertFalse( $short['passes'] );
		$this->assertSame( 0, $short['points'] );
		$this->assertSame( array( 'Write a few more words of your own.' ), $short['reasons'] );
		$this->assertSame( 0, $this->ledger_rows( $this->u['a'] ) );
		$this->assertSame( 'Just a few words here.', jlife_challenges_find_entry( $this->u['a'], $this->campaign, 'd001' )['body'], 'writing is always saved' );

		$r = jlife_challenges_submit( $this->u['a'], $this->campaign, 'd001', self::REFLECTION );
		$this->assertTrue( $r['passes'] );
		$this->assertSame( 1, $r['points'] );
		$this->assertSame( 'earned', $r['status'] );
		$this->assertSame( 'reflection-v1', $r['engine'] );

		$again = jlife_challenges_submit( $this->u['a'], $this->campaign, 'd001', self::REFLECTION . ' More.' );
		$this->assertSame( 1, $again['points'] );
		$this->assertSame( 1, $this->ledger_rows( $this->u['a'] ), 'one ledger row per person per day' );

		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT engine, engine_version FROM ' . jlife_challenges_table( 'score_ledger' ) . ' WHERE campaign_id = %d AND user_id = %d', $this->campaign, $this->u['a'] ), ARRAY_A ); // phpcs:ignore WordPress.DB
		$this->assertSame(
			array(
				'engine'         => 'reflection-v1',
				'engine_version' => '1',
			),
			$row
		);

		// A SOAP entry under the same rule earns the same single point.
		$soap = jlife_challenges_submit( $this->u['b'], $this->campaign, 'd001', $this->soap( 'b' ) . ' ' . $this->words( 12 ) );
		$this->assertSame( 1, $soap['points'] );
		$this->assertSame( 'reflection-v1', $soap['engine'] );
	}

	/**
	 * Late entries are still scored by the deadline rules (no point, with the reason), unchanged.
	 */
	public function test_deadline_rules_are_unchanged() {
		$this->use_reflection_engine();
		$this->set_now( '2026-12-23 18:00:00' ); // Day 4; Day 1's grace window (end of Day 2) is over.
		$r = jlife_challenges_submit( $this->u['a'], $this->campaign, 'd001', self::REFLECTION );
		$this->assertTrue( $r['passes'] );
		$this->assertSame( 0, $r['points'] );
		$this->assertSame( 'late_no_point', $r['status'] );
		$this->assertSame( JLIFE_CHALLENGES_LATE_RULE, $r['reason'] );
	}

	/**
	 * Campaigns on soap-regex start with the template and are scored as before.
	 */
	public function test_soap_regex_campaigns_are_unchanged() {
		$view = jlife_challenges_day_view( $this->u['a'] );
		$this->assertSame( jlife_challenges_soap_template(), jlife_challenges_entry_text( $view ) );
		$form = jlife_challenges_render_soap_panel( $view );
		$this->assertStringNotContainsString( 'Use SOAP template', $form );
		$this->assertStringNotContainsString( 'value="template"', $form );
		$this->assertStringContainsString( 'Submit SOAP', $form );
		$this->assertStringContainsString( '>My SOAP</label>', $form );
		$this->assertFalse( jlife_challenges_submit( $this->u['a'], $this->campaign, 'd001', self::REFLECTION )['passes'], 'free text still needs the four parts' );
		$this->assertSame( 'soap-regex', jlife_challenges_submit( $this->u['a'], $this->campaign, 'd001', $this->soap() )['engine'] );
	}

	/**
	 * Without guidance a reflection-v1 campaign shows My SOAP with the starter headings (#225); a saved entry is shown as saved.
	 */
	public function test_reflection_campaign_without_guidance_shows_the_soap_scaffold() {
		$this->use_reflection_engine();
		$view = jlife_challenges_day_view( $this->u['a'] );
		$this->assertSame( jlife_challenges_soap_template(), jlife_challenges_entry_text( $view ) );
		$form = jlife_challenges_render_soap_panel( $view );
		$this->assertStringContainsString( '>My SOAP</label>', $form );
		$this->assertStringNotContainsString( 'value="template"', $form, 'the headings are already there' );
		$this->assertStringContainsString( '>Submit</button>', $form );
		$this->assertStringNotContainsString( 'Submit SOAP', $form );
		$this->assertStringContainsString( 'SOAP headings are optional', $form, 'the engine accepts free writing, so the form says so' );
		$this->assertStringContainsString( 'id="jlife-soap-help"', $form, 'the heading help is still there' );

		jlife_challenges_save_draft( $this->u['a'], $this->campaign, 'd001', 'half a thought' );
		$this->assertSame( 'half a thought', jlife_challenges_entry_text( jlife_challenges_day_view( $this->u['a'] ) ) );
		$this->assertStringContainsString( 'Draft saved. When you are ready, tap Submit.', jlife_challenges_status_text( jlife_challenges_day_view( $this->u['a'] ) ) );
		$this->assertSame( 'Not started yet.', jlife_challenges_status_text( jlife_challenges_day_view( $this->u['b'] ) ) );
	}

	/**
	 * Post the form as a participant.
	 *
	 * @param int    $user_id User.
	 * @param string $action  template, draft or submit.
	 * @param string $text    The box.
	 */
	private function post_form( $user_id, $action, $text ) {
		wp_set_current_user( $user_id );
		$data     = array(
			'jl_text'     => $text,
			'jl_action'   => $action,
			'jl_day'      => 'd001',
			'_jlife_soap' => wp_create_nonce( 'jlife_soap_d001' ),
		);
		$_POST    = $data;
		$_REQUEST = $data;
	}

	/**
	 * Without JavaScript, "Use SOAP template" appends the headings, keeps every typed character, and
	 * saves, scores and shares nothing.
	 */
	public function test_template_button_without_javascript_never_erases_text() {
		$this->use_reflection_engine();
		$typed = "My own thoughts,\nline two.";
		$this->post_form( $this->u['a'], 'template', $typed );
		$state = jlife_challenges_process_soap_request( $this->u['a'], jlife_challenges_day_view( $this->u['a'] ), 'today' );
		$this->assertSame( '', $state['redirect'], 'the form is shown again, no redirect' );
		$this->assertSame( '', $state['error'] );
		$this->assertSame( $typed . "\n\n" . jlife_challenges_soap_template(), $state['text'] );
		$this->assertStringStartsWith( $typed, $state['text'] );
		$this->assertNull( jlife_challenges_find_entry( $this->u['a'], $this->campaign, 'd001' ), 'nothing was saved' );
		$this->assertSame( 0, $this->ledger_rows( $this->u['a'] ) );

		$form = jlife_challenges_render_soap_panel( jlife_challenges_day_view( $this->u['a'] ), $state );
		$this->assertStringContainsString( '>My own thoughts,' . "\n" . 'line two.' . "\n\n" . 'S — Scripture', $form );

		// A blank box gets just the headings; pressing it twice adds them again but removes nothing.
		$this->post_form( $this->u['a'], 'template', '' );
		$state = jlife_challenges_process_soap_request( $this->u['a'], jlife_challenges_day_view( $this->u['a'] ), 'today' );
		$this->assertSame( jlife_challenges_soap_template(), $state['text'] );
		$this->post_form( $this->u['a'], 'template', $state['text'] );
		$again = jlife_challenges_process_soap_request( $this->u['a'], jlife_challenges_day_view( $this->u['a'] ), 'today' );
		$this->assertStringStartsWith( jlife_challenges_soap_template(), $again['text'] );

		// A saved, submitted entry stays as it is: the template is only added to the form.
		jlife_challenges_submit( $this->u['a'], $this->campaign, 'd001', self::REFLECTION );
		$this->post_form( $this->u['a'], 'template', self::REFLECTION );
		jlife_challenges_process_soap_request( $this->u['a'], jlife_challenges_day_view( $this->u['a'] ), 'today' );
		$this->assertSame( self::REFLECTION, jlife_challenges_find_entry( $this->u['a'], $this->campaign, 'd001' )['body'] );
		$this->assertSame( 1, $this->ledger_rows( $this->u['a'] ) );
		$_POST    = array();
		$_REQUEST = array();
	}

	/**
	 * The script inserts at the cursor or the end, never replaces, and the version is bumped.
	 */
	public function test_script_inserts_without_replacing_text() {
		$js = (string) file_get_contents( dirname( __DIR__ ) . '/assets/soap.js' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- plugin asset.
		$this->assertStringContainsString( 'button[value=template]', $js );
		$this->assertStringContainsString( 'box.value = before + lead + template + tail + after;', $js, 'what was typed stays on both sides' );
		$this->assertStringContainsString( 'e.preventDefault()', $js );
		$this->assertStringContainsString( "dispatchEvent(new Event('input'", $js, 'autosave sees the change' );
		$html = jlife_challenges_page_html( 'Today', '', 'today', 'soap' );
		$this->assertStringContainsString( 'assets/soap.js?v=7', $html );
	}

	/**
	 * Vietnamese: the button, the labels and the appended headings read in Vietnamese.
	 */
	public function test_vietnamese_button_and_headings() {
		$force = static fn() => 'vi';
		add_filter( 'determine_locale', $force, 99 );
		add_filter(
			'lang_dir_for_domain',
			static fn( $path, $domain ) => 'jlife-challenges' === $domain ? dirname( __DIR__ ) . '/languages/' : $path,
			10,
			2
		);
		unload_textdomain( 'jlife-challenges', true );
		$this->use_reflection_engine();
		$this->make_devotional( 'publish', $this->marked_content( array( 'A question?' ) ) );
		$this->make_devotional( 'publish', $this->marked_content( array( 'Một câu hỏi?' ), 'Câu hỏi suy ngẫm' ), 'd001', 'vi' );

		$form = jlife_challenges_render_soap_panel( jlife_challenges_day_view( $this->u['a'] ) );
		$this->assertStringContainsString( '>Dùng khung SOAP</button>', $form );
		$this->assertStringContainsString( '>Suy ngẫm của tôi</label>', $form );
		$this->assertStringContainsString( '>Gửi</button>', $form );
		$this->assertStringContainsString( 'S — Kinh Thánh', jlife_challenges_append_soap_template( 'Lời của tôi' ) );
		$this->assertStringStartsWith( 'Lời của tôi', jlife_challenges_append_soap_template( 'Lời của tôi' ) );
		$this->assertSame( array( 'Hãy viết thêm vài từ của chính bạn.' ), $this->score( 'Một hai ba' )['reasons'] );
		$this->assertTrue( $this->score( 'Hôm nay tôi đọc về Giăng kêu gọi mọi người quay về cùng Đức Chúa Trời và tôi muốn dọn lòng mình trong tuần này bằng cách cầu nguyện nhiều hơn' )['passes'], 'Vietnamese syllables count as words' );

		remove_filter( 'determine_locale', $force, 99 );
		unload_textdomain( 'jlife-challenges', true );
	}

	/**
	 * Create the pilot and the official campaign with an engine; a typo is refused; an existing campaign keeps its engine.
	 */
	public function test_pilot_and_official_creation_take_an_engine() {
		global $wpdb;
		$wpdb->update( jlife_challenges_table( 'campaigns' ), array( 'status' => 'draft' ), array( 'id' => $this->campaign ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- test fixture.
		$this->set_now( '2026-11-30 18:00:00' );

		$bad = jlife_challenges_create_pilot_campaign( $this->u['organizer'], array( 'scoring_engine' => 'no-such-engine' ) );
		$this->assertWPError( $bad );
		$this->assertSame( 'jlife_campaign_invalid', $bad->get_error_code() );
		$this->assertStringContainsString( 'reflection-v1', $bad->get_error_message(), 'the message lists the known engines' );
		$this->assertWPError( jlife_challenges_create_official_campaign( $this->u['organizer'], array( 'scoring_engine' => 'nope' ), true ) );

		$default = jlife_challenges_create_pilot_campaign( $this->u['organizer'] );
		$this->assertSame( 'soap-regex', jlife_challenges_get_campaign( $default['campaign_id'] )['scoring_engine'], 'the default stays soap-regex' );

		$pilot = jlife_challenges_create_pilot_campaign( $this->u['organizer'], array( 'scoring_engine' => 'reflection-v1' ) );
		$this->assertFalse( $pilot['created'], 'the pilot already exists: nothing changes' );
		$this->assertSame( 'soap-regex', jlife_challenges_get_campaign( $pilot['campaign_id'] )['scoring_engine'] );

		$fresh = jlife_challenges_create_pilot_campaign(
			$this->u['organizer'],
			array(
				'campaign_key'   => 'pilot-engine-test',
				'scoring_engine' => 'reflection-v1',
			)
		);
		$this->assertTrue( $fresh['created'] );
		$this->assertSame( 'reflection-v1', jlife_challenges_get_campaign( $fresh['campaign_id'] )['scoring_engine'] );

		$official = jlife_challenges_create_official_campaign( $this->u['organizer'], array( 'scoring_engine' => 'reflection-v1' ) );
		$this->assertTrue( $official['created'] );
		$this->assertSame( 'reflection-v1', jlife_challenges_get_campaign( $official['campaign_id'] )['scoring_engine'] );
		$this->assertSame( 'reflection-v1', jlife_challenges_campaign_engine( jlife_challenges_get_campaign( $official['campaign_id'] ) ) );
		$this->assertTrue( jlife_challenges_campaign_starts_blank( jlife_challenges_get_campaign( $official['campaign_id'] ) ) );

		$default_official = jlife_challenges_create_official_campaign(
			$this->u['organizer'],
			array( 'campaign_key' => 'marathon-default' )
		);
		$this->assertSame( 'soap-regex', jlife_challenges_get_campaign( $default_official['campaign_id'] )['scoring_engine'] );
	}

	/**
	 * Set-engine works before Day 1 and while no entry exists, and is refused otherwise.
	 */
	public function test_set_engine_and_its_refusals() {
		$this->set_now( '2026-12-10 18:00:00' ); // Before Day 1 (Dec 20).
		$org = $this->u['organizer'];

		$this->assertWPError( jlife_challenges_set_campaign_engine( $this->u['a'], $this->campaign, 'reflection-v1' ), 'organizers only' );
		$this->assertWPError( jlife_challenges_set_campaign_engine( $org, 999999, 'reflection-v1' ), 'unknown campaign' );
		$bad = jlife_challenges_set_campaign_engine( $org, $this->campaign, 'nope' );
		$this->assertSame( 'jlife_campaign_invalid', $bad->get_error_code() );

		$same = jlife_challenges_set_campaign_engine( $org, $this->campaign, 'soap-regex' );
		$this->assertFalse( $same['changed'] );

		$r = jlife_challenges_set_campaign_engine( $org, $this->campaign, 'reflection-v1' );
		$this->assertTrue( $r['changed'] );
		$this->assertSame( 'soap-regex', $r['previous'] );
		$this->assertSame( 'reflection-v1', jlife_challenges_get_campaign( $this->campaign )['scoring_engine'] );
		$back = jlife_challenges_set_campaign_engine( $org, $this->campaign, 'soap-regex' );
		$this->assertTrue( $back['changed'], 'it can be switched back before anything is scored' );

		// Any entry, even an empty draft row, locks it.
		jlife_challenges_ensure_entry( $this->u['a'], $this->campaign, 'd001' );
		$locked = jlife_challenges_set_campaign_engine( $org, $this->campaign, 'reflection-v1' );
		$this->assertWPError( $locked );
		$this->assertSame( 'jlife_engine_locked', $locked->get_error_code() );
		$this->assertSame( 'soap-regex', jlife_challenges_get_campaign( $this->campaign )['scoring_engine'] );
	}

	/**
	 * Once Day 1 has started (Pacific time), the engine can no longer change, even with no entries.
	 */
	public function test_set_engine_is_refused_once_day_one_has_started() {
		$this->set_now( '2026-12-20 07:59:59' ); // Dec 19, 23:59:59 Pacific.
		$this->assertTrue( jlife_challenges_set_campaign_engine( $this->u['organizer'], $this->campaign, 'reflection-v1' )['changed'] );
		$this->set_now( '2026-12-20 08:00:00' ); // Dec 20, 00:00 Pacific: Day 1.
		$locked = jlife_challenges_set_campaign_engine( $this->u['organizer'], $this->campaign, 'soap-regex' );
		$this->assertWPError( $locked );
		$this->assertSame( 'jlife_engine_locked', $locked->get_error_code() );
		$this->assertSame( 'reflection-v1', jlife_challenges_get_campaign( $this->campaign )['scoring_engine'] );
	}

	/**
	 * A closed campaign's engine is fixed too.
	 */
	public function test_set_engine_is_refused_on_a_closed_campaign() {
		global $wpdb;
		$this->set_now( '2026-12-10 18:00:00' );
		$wpdb->update( jlife_challenges_table( 'campaigns' ), array( 'status' => 'closed' ), array( 'id' => $this->campaign ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- test fixture.
		$this->assertSame( 'jlife_engine_locked', jlife_challenges_set_campaign_engine( $this->u['organizer'], $this->campaign, 'reflection-v1' )->get_error_code() );
	}

	/**
	 * The rollover never changes an engine, on either side.
	 */
	public function test_rollover_leaves_engines_alone() {
		global $wpdb;
		$wpdb->update( jlife_challenges_table( 'campaigns' ), array( 'status' => 'draft' ), array( 'id' => $this->campaign ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- test fixture.
		$this->set_now( '2026-11-30 18:00:00' );
		$pilot    = (int) jlife_challenges_create_pilot_campaign( $this->u['organizer'] )['campaign_id'];
		$official = (int) jlife_challenges_create_official_campaign( $this->u['organizer'], array( 'scoring_engine' => 'reflection-v1' ) )['campaign_id'];
		$this->assertIsInt( jlife_challenges_enroll_solo( $this->u['a'], $pilot, 'adult' ) );
		$this->set_now( '2026-12-14 18:00:00' );
		$this->assertIsArray( jlife_challenges_close_campaign( $this->u['organizer'], $pilot ) );
		$this->set_now( '2026-12-15 18:00:00' );

		$r = jlife_challenges_rollover_execute( $this->u['organizer'], $pilot, $official );
		$this->assertIsArray( $r );
		$this->assertSame( 'soap-regex', jlife_challenges_get_campaign( $pilot )['scoring_engine'] );
		$this->assertSame( 'reflection-v1', jlife_challenges_get_campaign( $official )['scoring_engine'] );
	}
}
