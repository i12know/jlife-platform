<?php
/**
 * The guided reflection box (#225, parts 1 and 2): the writing area follows the
 * guidance the participant has for the day (a Ready, released devotional in their
 * language), not the campaign's scoring engine; the actual reflection question(s)
 * are prefilled as editable text in a new, empty entry (corrected #225); and the
 * questions prefilled always agree with the text #210 leaves out of the word
 * count. Everything is made up.
 *
 * @package jlife-challenges
 */

/**
 * Guided reflection tests.
 */
class Test_Guided_Reflection extends Jlife_Challenges_TestCase {

	/**
	 * A reflection of at least 20 words, more than 8 of them different.
	 */
	const REFLECTION = 'Today I read about John calling people to turn back to God, and I want to prepare my heart this week by praying more and listening quietly each morning.';

	/**
	 * The English question used in the marked content.
	 */
	const QUESTION = 'Where have you seen God keep a promise this week?';

	/**
	 * Participant A, signed in.
	 */
	public function set_up() {
		parent::set_up();
		wp_set_current_user( $this->u['a'] );
	}

	/**
	 * The writing panel for participant A.
	 *
	 * @param string|null $day_key Day, or null for today.
	 * @return string
	 */
	private function panel( $day_key = null ) {
		return jlife_challenges_render_soap_panel( jlife_challenges_day_view( $this->u['a'], $day_key ) );
	}

	/**
	 * The text of an HTML region between two markers.
	 *
	 * @param string $html  HTML.
	 * @param string $start Start marker.
	 * @param string $end   End marker.
	 * @return string
	 */
	private function between( $html, $start, $end ) {
		$from = strpos( $html, $start );
		$this->assertNotFalse( $from, $start );
		$to = strpos( $html, $end, $from );
		$this->assertNotFalse( $to, $end );
		return substr( $html, $from, $to - $from );
	}

	// -- Part 1: the mode ----------------------------------------------------

	/**
	 * No guidance: My SOAP with the starter headings, on either engine.
	 */
	public function test_no_guidance_shows_my_soap_with_the_headings() {
		foreach ( array( 'soap-regex', 'reflection-v1' ) as $engine ) {
			$this->use_engine( $engine );
			$view = jlife_challenges_day_view( $this->u['a'] );
			$this->assertFalse( jlife_challenges_is_guided( $view ), $engine );
			$this->assertSame( jlife_challenges_soap_template(), jlife_challenges_entry_text( $view ), $engine );
			$form = jlife_challenges_render_soap_panel( $view );
			$this->assertStringContainsString( '>My SOAP</label>', $form );
			$this->assertStringNotContainsString( 'My reflection', $form );
			$this->assertStringNotContainsString( 'jl-response-box', $form );
		}
	}

	/**
	 * Guidance: My reflection, the question prefilled as text, Submit, optional non-destructive SOAP, no separate panel.
	 */
	public function test_guidance_shows_my_reflection_with_the_question_prefilled() {
		$this->make_devotional( 'publish', $this->marked_content( array( self::QUESTION ) ) );
		foreach ( array( 'soap-regex', 'reflection-v1' ) as $engine ) {
			$this->use_engine( $engine );
			$view = jlife_challenges_day_view( $this->u['a'] );
			$this->assertTrue( jlife_challenges_is_guided( $view ), $engine );
			$this->assertSame( self::QUESTION . "\n\n\n", jlife_challenges_entry_text( $view ), 'a new entry starts with the question on ' . $engine );
			$form = jlife_challenges_render_soap_panel( $view );
			$this->assertStringContainsString( '>My reflection</label>', $form );
			$this->assertStringContainsString( 'Answer the question in the box below in your own words.', $form );
			$this->assertStringNotContainsString( 'questions above', $form );
			$this->assertStringNotContainsString( 'You can use SOAP if you wish', $form );
			$box = $this->between( $form, '<form', '</form>' );
			$this->assertSame( 1, substr_count( $box, 'class="jl-soap-def"' ) - substr_count( $box, '<p class="jl-soap-def">SOAP' ), 'at most one help line outside the SOAP section' );
			$this->assertStringNotContainsString( 'jl-response', $form, 'no separate question panel' );
			$this->assertMatchesRegularExpression( '/<textarea[^>]*>' . preg_quote( self::QUESTION, '/' ) . '\n\n\n<\/textarea>/', $form, 'the question is real text in the box' );
			$this->assertStringNotContainsString( 'placeholder=', $form );
			$this->assertStringContainsString( '<details id="jlife-soap-help" class="jl-optional jl-guidance"><summary>Use SOAP</summary>', $form, 'the guided disclosure is unchanged (#266 only touches the plain form)' );
			$this->assertStringContainsString( '<a href="#jlife-soap-help" class="jl-soap-more">More</a>', $form, 'its More link still opens it' );
			$this->assertStringContainsString( '<li><strong>S — Scripture</strong>', $form, 'its heading list is still inside' );
			$this->assertStringContainsString( 'value="template" class="jl-button jl-soap-template" formnovalidate>Use SOAP template</button>', $form );
			$this->assertStringContainsString( '>Submit</button>', $form );
			$this->assertStringNotContainsString( 'Submit SOAP', $form );
			$this->assertStringNotContainsString( '>My SOAP</label>', $form );
			$this->assertStringNotContainsString( 'Write under each heading', $form, 'no mandatory-heading instruction' );
		}
	}

	/**
	 * Guidance with no marked questions: the fallback help, never a mention of missing questions.
	 */
	public function test_guidance_without_questions_uses_the_fallback_help() {
		$this->make_devotional( 'publish', '<p>Just a lead-in, no questions.</p>' );
		$form = $this->panel();
		$this->assertStringContainsString( '>My reflection</label>', $form );
		$this->assertStringContainsString( esc_html( "Write about today's reading in your own words." ), $form );
		$this->assertStringNotContainsString( 'in the box below', $form );
		$this->assertSame( '', jlife_challenges_entry_text( jlife_challenges_day_view( $this->u['a'] ) ), 'no question: nothing is prefilled' );
		$this->assertStringContainsString( '></textarea>', $form );
	}

	/**
	 * A Draft, a future-scheduled post, another language and a day that has not opened never trigger guided mode.
	 */
	public function test_draft_future_and_unreleased_content_do_not_guide() {
		$id = $this->make_devotional( 'draft', $this->marked_content( array( self::QUESTION ) ) );
		$this->assertFalse( jlife_challenges_is_guided( jlife_challenges_day_view( $this->u['a'] ) ), 'Draft' );

		global $wpdb;
		$wpdb->update( $wpdb->posts, array( 'post_status' => 'future' ), array( 'ID' => $id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- test fixture: WordPress turns a past-dated scheduled post into a published one.
		clean_post_cache( $id );
		$this->assertSame( 'future', get_post_status( $id ) );
		$this->assertFalse( jlife_challenges_is_guided( jlife_challenges_day_view( $this->u['a'] ) ), 'scheduled' );

		$this->make_devotional( 'publish', $this->marked_content( array( self::QUESTION ) ), 'd002', 'en' );
		$campaign = $this->campaign_row();
		$this->assertSame( array(), jlife_challenges_guidance_sources( $campaign, 'd002', 'en' ), 'Day 2 has not opened on Day 1' );
		$this->set_now( '2026-12-21 18:00:00' );
		$this->assertCount( 1, jlife_challenges_guidance_sources( $campaign, 'd002', 'en' ), 'it opens on Day 2' );

		$this->assertSame( array(), jlife_challenges_guidance_sources( $campaign, 'd002', 'vi' ), 'no fallback to the other language' );
	}

	/**
	 * The mode never depends on a collapsed panel; the devotional keeps its own questions and the box holds the prefill.
	 */
	public function test_collapsing_the_devotional_does_not_change_the_mode() {
		$this->make_devotional( 'publish', $this->marked_content( array( self::QUESTION ) ) );
		$form  = $this->panel();
		$panel = $this->between( $form, '<details class="jl-devotional"', '</details>' );
		$this->assertStringContainsString( '<details class="jl-devotional" open>', $panel );
		$this->assertStringContainsString( self::QUESTION, $panel, 'the full devotional keeps its own questions' );
		$this->assertLessThan( strpos( $form, '<textarea' ), strpos( $form, '<form' ) );
		$this->assertStringContainsString( self::QUESTION, $this->between( $form, '<textarea', '</textarea>' ) );
		$this->assertTrue( jlife_challenges_is_guided( jlife_challenges_day_view( $this->u['a'] ) ), 'the mode reads content, not the page' );
	}

	/**
	 * Guidance appearing, or the language changing, never replaces an existing draft or saved response.
	 */
	public function test_an_existing_draft_is_never_replaced() {
		$draft = "S — Scripture\nJohn 1:1\n\nO — Observation\nIn the beginning.";
		jlife_challenges_save_draft( $this->u['a'], $this->campaign, 'd001', $draft );
		$this->assertSame( $draft, jlife_challenges_entry_text( jlife_challenges_day_view( $this->u['a'] ) ) );

		$this->make_devotional( 'publish', $this->marked_content( array( self::QUESTION ) ) );
		$view = jlife_challenges_day_view( $this->u['a'] );
		$this->assertTrue( jlife_challenges_is_guided( $view ) );
		$this->assertSame( $draft, jlife_challenges_entry_text( $view ), 'guidance appeared: the draft stays' );
		$this->assertStringContainsString( esc_textarea( $draft ) . '</textarea>', jlife_challenges_render_soap_panel( $view ) );

		update_user_meta( $this->u['a'], 'locale', 'vi' ); // No Vietnamese devotional: guidance goes away.
		$view = jlife_challenges_day_view( $this->u['a'] );
		$this->assertFalse( jlife_challenges_is_guided( $view ) );
		$this->assertSame( $draft, jlife_challenges_entry_text( $view ), 'language changed: the draft stays' );
		$this->assertSame( $draft, jlife_challenges_find_entry( $this->u['a'], $this->campaign, 'd001' )['body'], 'nothing was rewritten' );

		jlife_challenges_submit( $this->u['a'], $this->campaign, 'd001', self::REFLECTION );
		update_user_meta( $this->u['a'], 'locale', 'en_US' );
		$this->assertSame( self::REFLECTION, jlife_challenges_entry_text( jlife_challenges_day_view( $this->u['a'] ) ), 'a saved response stays' );
	}

	/**
	 * Presentation follows guidance, scoring follows the engine: on a soap-regex campaign
	 * a free-form reflection still earns no point (owner decision, #225); SOAP does.
	 */
	public function test_guided_soap_regex_campaign_still_scores_by_its_engine() {
		$this->make_devotional( 'publish', $this->marked_content( array( self::QUESTION ) ) );
		$this->assertTrue( jlife_challenges_is_guided( jlife_challenges_day_view( $this->u['a'] ) ) );
		$free = jlife_challenges_submit( $this->u['a'], $this->campaign, 'd001', self::REFLECTION );
		$this->assertFalse( $free['passes'] );
		$this->assertSame( 'soap-regex', $free['engine'] );
		$this->assertSame( 0, $this->ledger_rows( $this->u['a'] ) );
		$soap = jlife_challenges_submit( $this->u['a'], $this->campaign, 'd001', $this->soap() );
		$this->assertTrue( $soap['passes'] );
		$this->assertSame( 1, $this->ledger_rows( $this->u['a'] ) );
	}

	// -- Part 2: the marked questions ------------------------------------------

	/**
	 * The importer marks the questions section in English and Vietnamese, and the marker survives sanitizing.
	 */
	public function test_the_import_marks_the_section_and_it_survives_sanitizing() {
		$headings = array(
			'EN' => 'Reflection questions',
			'VI' => 'Câu hỏi suy ngẫm',
		);
		foreach ( $headings as $lang => $heading ) {
			$files = glob( __DIR__ . '/fixtures/devotionals/D001-' . $lang . '*.md' );
			$this->assertNotEmpty( $files );
			$result = jlife_challenges_devotional_convert( (string) file_get_contents( $files[0] ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- reading a test fixture.
			$html   = $result['html'];
			$this->assertSame( 1, substr_count( $html, '<section class="jl-reflection-questions"' ), $lang );
			$this->assertSame( 1, preg_match( '/<section class="jl-reflection-questions" aria-labelledby="(jl-rq-[a-f0-9]+)">\s*<h3 id="\1">' . preg_quote( $heading, '/' ) . '<\/h3>/u', $html ), $lang . ': ' . $html );
			$this->assertStringContainsString( '<!-- wp:html -->', $html, 'kept as a Custom HTML block for the block editor' );
			// The reader's sanitize pass keeps the marker, the heading id and aria-labelledby.
			$clean = wp_kses_post( do_blocks( $html ) );
			$this->assertStringContainsString( 'class="jl-reflection-questions"', $clean );
			$this->assertStringContainsString( 'aria-labelledby="jl-rq-', $clean );
			$marked = jlife_challenges_devotional_marked_section( $clean );
			$this->assertSame( $result['questions'], $marked['lines'], 'the marked lines are the imported questions' );
			$this->assertSame( $heading, $marked['heading'] );
		}
	}

	/**
	 * Only the actual question is extracted: real-world shapes, English and Vietnamese.
	 */
	public function test_only_the_actual_questions_are_extracted() {
		$en = array(
			'**Where have you seen God keep a promise this week?**',
			'There is no need for an impressive answer. Is it hard? Write a few sentences in your own words (at least 20 words).',
			'- Then press Submit Reflection.',
			'Congratulations!',
		);
		$this->assertSame( array( 'Where have you seen God keep a promise this week?' ), jlife_challenges_extract_questions( $en ) );
		$vi = array(
			'- **Tại sao?**',
			'- Không cần câu trả lời hay. Hãy viết vài câu bằng lời của bạn (ít nhất 20 từ).',
			'- Sau đó nhấn Gửi.',
		);
		$this->assertSame( array( 'Tại sao?' ), jlife_challenges_extract_questions( $vi ) );
		$this->assertSame( array( 'Bạn nghĩ sao？' ), jlife_challenges_extract_questions( array( '1. Bạn nghĩ sao？' ) ), 'full-width mark' );
		$this->assertSame( array(), jlife_challenges_extract_questions( array( 'Just instructions. Why? Because.', 'Congratulations!' ) ), 'a mid-line mark is not a question' );
	}

	/**
	 * Real-shaped marked content: the box holds the question and blank lines only, never the instructions, and showing it saves nothing.
	 */
	public function test_the_prefill_holds_only_the_question_and_saves_nothing() {
		$this->make_devotional(
			'publish',
			$this->marked_content(
				array(
					'<strong>' . self::QUESTION . '</strong>',
					'There is no need for an impressive answer. Write a few sentences (at least 20 words).',
					'Then press Submit Reflection. Congratulations!',
				)
			)
		);
		$form = $this->panel();
		$box  = $this->between( $form, '<textarea', '</textarea>' );
		$this->assertStringContainsString( '>' . self::QUESTION . "\n\n\n", $box );
		$this->assertStringNotContainsString( 'impressive', $box );
		$this->assertStringNotContainsString( 'Congratulations', $box );
		$this->assertStringContainsString( 'data-prefill="' . esc_attr( self::QUESTION . "\n\n\n" ) . '"', $form, 'the script treats the untouched prefill like an untouched box' );
		$this->assertNull( jlife_challenges_find_entry( $this->u['a'], $this->campaign, 'd001' ) );
		$this->assertSame( 0, $this->ledger_rows( $this->u['a'] ) );
	}

	/**
	 * Several questions each get their own line.
	 */
	public function test_several_questions_each_on_their_own_line() {
		$this->make_devotional( 'publish', $this->marked_content( array( self::QUESTION, 'What will you do today?' ) ) );
		$this->assertSame( self::QUESTION . "\nWhat will you do today?\n\n\n", jlife_challenges_entry_text( jlife_challenges_day_view( $this->u['a'] ) ) );
	}

	/**
	 * Older unmarked content falls back to the lines stored at import; nothing is guessed from headings or bold text.
	 */
	public function test_unmarked_content_falls_back_to_the_stored_questions() {
		$stored = "Where was God at work?\nWrite a few sentences.\nWhat will you do?";
		$this->make_devotional( 'publish', '<h3 class="wp-block-heading">Reflection Questions</h3><p><strong>A bold guess?</strong></p>', 'd001', 'en', $stored );
		$view = jlife_challenges_day_view( $this->u['a'] );
		$this->assertSame( "Where was God at work?\nWhat will you do?\n\n\n", jlife_challenges_entry_text( $view ) );
		$excluded = apply_filters( 'jlife_challenges_reflection_excluded_text', array(), $this->context() );
		$this->assertSame( array( 'Where was God at work?', 'What will you do?' ), $excluded, 'exactly the extracted questions' );
	}

	/**
	 * Unmarked content with nothing stored gives guidance with no questions.
	 */
	public function test_unmarked_content_with_nothing_stored_has_no_questions() {
		$this->make_devotional( 'publish', '<h3>Reflection Questions</h3><p><strong>Why?</strong></p>' );
		$sources = jlife_challenges_view_guidance( jlife_challenges_day_view( $this->u['a'] ) );
		$this->assertCount( 1, $sources );
		$this->assertNull( $sources[0]['questions'] );
		$this->assertSame( array(), apply_filters( 'jlife_challenges_reflection_excluded_text', array(), $this->context() ) );
	}

	/**
	 * The engine context for Day 1.
	 *
	 * @return array
	 */
	private function context() {
		return array(
			'campaign' => $this->campaign_row(),
			'day_key'  => 'd001',
			'index'    => 1,
		);
	}

	/**
	 * What is shown and what is excluded from the word count agree, also after an editorial edit.
	 */
	public function test_shown_questions_and_excluded_text_agree_after_an_edit() {
		$id = $this->make_devotional( 'publish', $this->marked_content( array( 'First question?' ) ), 'd001', 'en', 'First question?' );
		$this->assertStringContainsString( 'First question?', $this->between( $this->panel(), '<textarea', '</textarea>' ) );
		$this->assertContains( 'First question?', apply_filters( 'jlife_challenges_reflection_excluded_text', array(), $this->context() ) );

		wp_update_post(
			array(
				'ID'           => $id,
				'post_content' => $this->marked_content( array( 'Edited question?', 'A new second one?' ) ),
			)
		);
		$shown    = $this->between( $this->panel(), '<textarea', '</textarea>' );
		$excluded = apply_filters( 'jlife_challenges_reflection_excluded_text', array(), $this->context() );
		$this->assertStringNotContainsString( 'First question?', $shown );
		foreach ( array( 'Edited question?', 'A new second one?' ) as $q ) {
			$this->assertStringContainsString( $q, $shown );
			$this->assertContains( $q, $excluded );
		}
		$this->assertNotContains( 'First question?', $excluded, 'the stale import record is not used for a marked body' );
	}

	/**
	 * Printed questions do not count as the participant's words; prompts alone earn nothing.
	 */
	public function test_printed_questions_are_left_out_of_the_word_count() {
		$this->use_engine( 'reflection-v1' );
		$this->make_devotional( 'publish', $this->marked_content( array( self::QUESTION, 'What is one thing you will do today?' ) ) );

		$copied = self::QUESTION . ' What is one thing you will do today? Just a few words.';
		$r      = jlife_challenges_submit( $this->u['a'], $this->campaign, 'd001', $copied );
		$this->assertFalse( $r['passes'], 'the copied questions add no words' );
		$this->assertSame( 0, $this->ledger_rows( $this->u['a'] ) );
		$this->assertSame( $copied, jlife_challenges_find_entry( $this->u['a'], $this->campaign, 'd001' )['body'], 'writing is still saved' );

		$r = jlife_challenges_submit( $this->u['a'], $this->campaign, 'd001', self::QUESTION . ' ' . self::REFLECTION );
		$this->assertTrue( $r['passes'] );
		$this->assertSame( 1, $this->ledger_rows( $this->u['a'] ) );
	}

	/**
	 * The untouched prefill earns no point under either engine; the prefill plus 20+ own words earns one under reflection-v1.
	 */
	public function test_the_untouched_prefill_earns_nothing_and_own_words_earn_the_point() {
		$this->make_devotional( 'publish', $this->marked_content( array( self::QUESTION, 'Then press Submit Reflection. Congratulations!' ) ) );
		$prefill = jlife_challenges_entry_text( jlife_challenges_day_view( $this->u['a'] ) );
		foreach ( array( 'soap-regex', 'reflection-v1' ) as $engine ) {
			$this->use_engine( $engine );
			$r = jlife_challenges_submit( $this->u['a'], $this->campaign, 'd001', $prefill );
			$this->assertFalse( $r['passes'], 'untouched prefill on ' . $engine );
			$this->assertSame( 0, $this->ledger_rows( $this->u['a'] ), $engine );
		}
		$this->use_engine( 'reflection-v1' );
		$r = jlife_challenges_submit( $this->u['a'], $this->campaign, 'd001', rtrim( $prefill ) . "\n\n" . self::REFLECTION );
		$this->assertTrue( $r['passes'], 'the prefill plus own words' );
		$this->assertSame( 1, $this->ledger_rows( $this->u['a'] ) );
	}

	/**
	 * The prefill plus the four SOAP parts still needs the parts on soap-regex; the questions add nothing.
	 */
	public function test_soap_regex_with_the_prefill_still_needs_the_four_parts() {
		$this->make_devotional( 'publish', $this->marked_content( array( self::QUESTION ) ) );
		$prefill = jlife_challenges_entry_text( jlife_challenges_day_view( $this->u['a'] ) );
		$this->assertFalse( jlife_challenges_submit( $this->u['a'], $this->campaign, 'd001', $prefill . self::REFLECTION )['passes'] );
		$this->assertTrue( jlife_challenges_submit( $this->u['a'], $this->campaign, 'd001', $prefill . $this->soap() )['passes'] );
	}

	/**
	 * "Use SOAP" without JavaScript adds the headings after the prefilled question and typed text; nothing is removed.
	 */
	public function test_use_soap_keeps_the_prefilled_text() {
		$this->make_devotional( 'publish', $this->marked_content( array( self::QUESTION ) ) );
		$prefill = jlife_challenges_entry_text( jlife_challenges_day_view( $this->u['a'] ) );
		$typed   = $prefill . 'My first thoughts.';
		$out     = jlife_challenges_append_soap_template( $typed );
		$this->assertStringStartsWith( $typed, $out );
		$this->assertStringContainsString( jlife_challenges_soap_template(), $out );
		$js = (string) file_get_contents( dirname( __DIR__ ) . '/assets/soap.js' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- plugin asset.
		$this->assertStringContainsString( 'box.value = before + lead + template + tail + after;', $js, 'the script inserts at the cursor' );
		$this->assertStringContainsString( 'current === prefill.replace', $js, 'a recovered backup never replaces anything but an untouched box' );
	}

	/**
	 * A saved draft of the untouched prefill, a submitted entry and a language change keep what is stored.
	 */
	public function test_the_prefill_is_only_for_a_new_empty_entry() {
		$this->make_devotional( 'publish', $this->marked_content( array( self::QUESTION ) ) );
		$this->make_devotional( 'publish', $this->marked_content( array( 'Bạn đã thấy Chúa giữ lời hứa ở đâu?' ), 'Câu hỏi suy ngẫm' ), 'd001', 'vi' );
		$edited = self::QUESTION . "\n\nMy own answer.";
		jlife_challenges_save_draft( $this->u['a'], $this->campaign, 'd001', $edited );
		$this->assertSame( $edited, jlife_challenges_entry_text( jlife_challenges_day_view( $this->u['a'] ) ) );
		update_user_meta( $this->u['a'], 'locale', 'vi' );
		$view = jlife_challenges_day_view( $this->u['a'] );
		$this->assertSame( $edited, jlife_challenges_entry_text( $view ), 'language changed: the draft stays' );
		$this->assertStringNotContainsString( 'Bạn đã thấy', $this->between( jlife_challenges_render_soap_panel( $view ), '<textarea', '</textarea>' ) );
		update_user_meta( $this->u['a'], 'locale', 'en_US' );
		jlife_challenges_submit( $this->u['a'], $this->campaign, 'd001', self::REFLECTION );
		$this->assertSame( self::REFLECTION, jlife_challenges_entry_text( jlife_challenges_day_view( $this->u['a'] ) ), 'a submitted entry stays' );
	}

	/**
	 * English and Vietnamese: each reader sees their own language's questions only.
	 */
	public function test_each_language_shows_its_own_questions() {
		$this->make_devotional( 'publish', $this->marked_content( array( self::QUESTION ) ), 'd001', 'en' );
		$this->make_devotional( 'publish', $this->marked_content( array( 'Bạn đã thấy Chúa giữ lời hứa ở đâu?' ), 'Câu hỏi suy ngẫm' ), 'd001', 'vi' );

		$en = $this->between( $this->panel(), '<textarea', '</textarea>' );
		$this->assertStringContainsString( self::QUESTION, $en );
		$this->assertStringNotContainsString( 'Bạn đã thấy', $en );

		update_user_meta( $this->u['a'], 'locale', 'vi' );
		$vi = $this->between( $this->panel(), '<textarea', '</textarea>' );
		$this->assertStringContainsString( 'Bạn đã thấy Chúa giữ lời hứa ở đâu?', $vi );
		$this->assertStringNotContainsString( self::QUESTION, $vi );

		// The same question is excluded in both languages: a copy in either language does not count.
		$excluded = apply_filters( 'jlife_challenges_reflection_excluded_text', array(), $this->context() );
		$this->assertContains( self::QUESTION, $excluded );
		$this->assertContains( 'Bạn đã thấy Chúa giữ lời hứa ở đâu?', $excluded );
	}

	/**
	 * Several sources each contribute their actual questions (the seam #209 will use for group leaders).
	 */
	public function test_several_sources_contribute_their_questions() {
		$this->make_devotional( 'publish', $this->marked_content( array( self::QUESTION ) ) );
		$extra = static function ( $sources ) {
			$sources[] = array(
				'id'          => 'group-1',
				'attribution' => 'Leader Pat',
				'questions'   => array(
					'heading' => 'Our questions',
					'html'    => '<p>What did you notice?</p>',
					'lines'   => array( 'What did you notice?', 'Be brief.' ),
				),
			);
			return $sources;
		};
		add_filter( 'jlife_challenges_guidance_sources', $extra );
		$text = jlife_challenges_entry_text( jlife_challenges_day_view( $this->u['a'] ) );
		remove_filter( 'jlife_challenges_guidance_sources', $extra );
		$this->assertSame( self::QUESTION . "\nWhat did you notice?\n\n\n", $text );
	}

	/**
	 * Group-leader style guidance that adds a source alone also switches the mode (no site-wide devotional needed).
	 */
	public function test_an_added_source_alone_makes_the_box_guided() {
		$add = static function ( $sources ) {
			$sources[] = array(
				'id'          => 'group-1',
				'attribution' => 'Leader Pat',
				'questions'   => null,
			);
			return $sources;
		};
		add_filter( 'jlife_challenges_guidance_sources', $add );
		$view = jlife_challenges_day_view( $this->u['a'] );
		$this->assertTrue( jlife_challenges_is_guided( $view ) );
		remove_filter( 'jlife_challenges_guidance_sources', $add );
		$this->assertFalse( jlife_challenges_is_guided( $view ) );
	}
}
