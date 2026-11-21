<?php
/**
 * The reader: the participant's Bible on the page, every passage open in
 * order under a reference heading (#188), verse numbers, one attribution
 * footer with each notice once after the reading; link-only
 * versions and failures show the Bible Gateway link; off by default.
 *
 * @package jlife-scripture
 */

/**
 * Renderer tests.
 */
class Test_Render extends Jlife_Scripture_TestCase {

	/**
	 * Day 10 of the primary plan: four passages, one partial verse.
	 *
	 * @return array
	 */
	private function day10() {
		return $this->day(
			'Matthew 3:13-17; Luke 3:23a; Matthew 4:1-11; John 1:19-28',
			array( 'Ma-thi-ơ 3:13-17', 'Lu-ca 3:23a', 'Ma-thi-ơ 4:1-11', 'Giăng 1:19-28' )
		);
	}

	/**
	 * A Vietnamese participant reads Kinh Thánh 1925 on the page.
	 */
	public function test_vietnamese_participant_reads_kinh_thanh_1925() {
		$html = jlife_scripture_render_day( $this->day10(), $this->participant( 'vi' ) );
		$this->assertStringContainsString( '<section class="jls-reader" lang="vi"', $html );
		$this->assertSame( 4, substr_count( $html, '<section class="jls-passage">' ) );
		$this->assertStringNotContainsString( '<details', $html, 'no box to open' );
		$this->assertStringNotContainsString( '<summary', $html );
		$this->assertStringStartsWith( '<section class="jls-reader" lang="vi" aria-label="Scripture reading"><section class="jls-passage"><h3 class="jls-ref">Ma-thi-ơ 3:13-17</h3>', $html );
		$this->assertStringContainsString( '<h3 class="jls-ref">Lu-ca 3:23a</h3>', $html );
		$this->assertStringContainsString( '<sup class="jls-num">13</sup>', $html, 'verse numbers' );
		$this->assertSame( 4, substr_count( $html, 'jls-text' ), 'text for every passage' );
		$this->assertSame( 1, substr_count( $html, '<span class="jls-version-name">Kinh Thánh 1925 (Bản Truyền Thống)</span>' ), 'the notice once' );
		$this->assertStringContainsString( 'thuộc phạm vi công cộng', $html );
		$this->assertStringContainsString( 'Đức Chúa Jêsus', $html );
		$this->assertStringNotContainsString( 'biblegateway.com', $html, 'no link needed when the text shows' );
		$this->assertStringNotContainsString( '<iframe', $html );
	}

	/**
	 * Verses in a new chapter are numbered chapter:verse.
	 */
	public function test_chapter_change_is_numbered() {
		$html = jlife_scripture_render_day( $this->day( 'John 3:22-4:3' ), $this->participant( 'vi' ) );
		$this->assertStringContainsString( '<sup class="jls-num">22</sup>', $html );
		$this->assertStringContainsString( '<sup class="jls-num">4:1</sup>', $html );
		$this->assertStringContainsString( '<sup class="jls-num">3</sup>', $html );

		$html = jlife_scripture_render_day( $this->day( 'Luke 24:10b, d' ), $this->participant( 'vi' ) );
		$this->assertSame( 1, substr_count( $html, '<sup class="jls-num">10</sup>' ), 'a verse shared by two parts shows once' );
	}

	/**
	 * When the English default is a version that cannot render (here NIrV, as
	 * before NLT became the default), every passage is an "Open in Bible
	 * Gateway" link in ERV, no text and no failure note.
	 */
	public function test_english_participant_gets_bible_gateway_links() {
		$this->patch_version( 'nlt', array( 'default_for' => array() ) );
		$this->patch_version( 'nirv', array( 'default_for' => array( 'en' ) ) );
		$html = jlife_scripture_render_day( $this->day10(), $this->participant( 'en_US' ) );
		$this->assertStringContainsString( 'lang="en"', $html );
		$this->assertStringNotContainsString( 'jls-text', $html );
		$this->assertStringNotContainsString( 'jls-note', $html, 'link-only is not a failure' );
		$this->assertSame( 4, substr_count( $html, 'Open in Bible Gateway (ERV)' ) );
		$this->assertStringContainsString( 'href="https://www.biblegateway.com/passage/?search=Luke+3:23&#038;version=ERV"', $html );
		$this->assertStringContainsString( 'Easy-to-Read Version (ERV) Copyright © 2006 by Bible League International', $html );
		$this->assertStringContainsString( '<h3 class="jls-ref">Matthew 3:13-17</h3>', $html );
		$this->assertSame( 1, substr_count( $html, 'Bible League International' ), 'the link version\'s notice once, in the footer' );
		$this->assertGreaterThan( strrpos( $html, 'Open in Bible Gateway (ERV)' ), strpos( $html, '<footer class="jls-attribution">' ) );
	}

	/**
	 * Once NIrV clears its gates and becomes the English default (a registry
	 * edit), English shows text with NIrV's name and copyright line.
	 */
	public function test_native_english_once_enabled() {
		$this->use_fake_nirv();
		$this->patch_version( 'nlt', array( 'default_for' => array() ) );
		$this->patch_version( 'nirv', array( 'default_for' => array( 'en' ) ) );
		$html = jlife_scripture_render_day( $this->day( 'John 1:1-5' ), $this->participant( 'en' ) );
		$this->assertStringContainsString( '<span class="jls-version-name">New International Reader&#039;s Version</span>', $html );
		$this->assertStringContainsString( 'Biblica', $html );
		$this->assertStringContainsString( '<sup class="jls-num">5</sup>', $html );
	}

	/**
	 * A visitor who is not signed in (the public /reading/ pages) gets the
	 * page's language; an explicit language wins.
	 */
	public function test_visitor_uses_the_page_language() {
		$vi = static fn() => 'vi';
		add_filter( 'determine_locale', $vi );
		$this->assertStringContainsString( 'lang="vi"', jlife_scripture_render_day( $this->day( 'John 1:1-5' ), 0 ) );
		remove_filter( 'determine_locale', $vi );
		$this->assertStringContainsString( 'lang="en"', jlife_scripture_render_day( $this->day( 'John 1:1-5' ), 0 ) );
		$this->assertStringContainsString( 'lang="vi"', jlife_scripture_render_day( $this->day( 'John 1:1-5' ), 0, array( 'language' => 'vi' ) ) );
	}

	/**
	 * A reference that cannot be parsed still offers the day's plan link.
	 */
	public function test_unreadable_reference_uses_the_plan_link() {
		$day          = $this->day( 'John 99:1' );
		$day['links'] = array(
			'vi' => array(
				'version'  => 'BPT',
				'combined' => array( 'url' => 'https://www.biblegateway.com/passage/?search=John+1:1&version=BPT' ),
			),
		);
		$html         = jlife_scripture_render_day( $day, $this->participant( 'vi' ) );
		$this->assertStringContainsString( 'search=John+1:1&#038;version=BPT', $html );
		$this->assertStringNotContainsString( 'jls-text', $html );
	}

	/**
	 * Off by default: the screens keep their reader. On: markup.
	 */
	public function test_feature_flag_defaults_off() {
		$user = $this->participant( 'vi' );
		$this->assertFalse( jlife_scripture_reader_enabled() );
		$this->assertNull( jlife_scripture_maybe_render_day( $this->day10(), $user ) );
		update_option( 'jlife_scripture_native_reader', '1' );
		$this->assertTrue( jlife_scripture_reader_enabled() );
		$this->assertStringContainsString( 'jls-reader', (string) jlife_scripture_maybe_render_day( $this->day10(), $user ) );
		$this->assertNull( jlife_scripture_maybe_render_day( array( 'passages' => array() ), $user ) );
		$this->assertNull( jlife_scripture_maybe_render_day( null, $user ) );
	}

	/**
	 * Text from a source is escaped, never trusted as markup.
	 */
	public function test_text_is_escaped() {
		$html = jlife_scripture_render_verses(
			array(
				array(
					array(
						'chapter' => 1,
						'verse'   => 1,
						'text'    => '<script>alert(1)</script>',
					),
				),
			)
		);
		$this->assertStringNotContainsString( '<script>', $html );
		$this->assertStringContainsString( '&lt;script&gt;', $html );
	}

	/**
	 * One passage: open under its heading, then the footer with the notice once.
	 * Several: all open, in the plan's order, and still one footer, after the
	 * last passage, with the notice once (#188).
	 */
	public function test_one_footer_after_one_or_several_passages() {
		$one = jlife_scripture_render_day( $this->day( 'John 1:1-5', array( 'Giăng 1:1-5' ) ), $this->participant( 'vi' ) );
		$this->assertSame( 1, substr_count( $one, '<section class="jls-passage">' ) );
		$this->assertSame( 1, substr_count( $one, '<footer class="jls-attribution">' ) );
		$this->assertSame( 1, substr_count( $one, 'thuộc phạm vi công cộng' ) );
		$this->assertStringEndsWith( '</div></section><footer class="jls-attribution"><p class="jls-version"><small><span class="jls-version-name">Kinh Thánh 1925 (Bản Truyền Thống)</span> · <a href="https://ebible.org/find/details.php?id=vie1934" target="_blank" rel="noopener">Bản dịch William Cadman (Tân Ước 1923, Cựu Ước 1925), thuộc phạm vi công cộng. Văn bản: eBible.org.</a></small></p></footer></section>', $one );

		$many = jlife_scripture_render_day( $this->day10(), $this->participant( 'vi' ) );
		$this->assertSame( 1, substr_count( $many, '<footer class="jls-attribution">' ) );
		$this->assertSame( 1, substr_count( $many, 'thuộc phạm vi công cộng' ), 'each notice exactly once' );
		$this->assertSame( 1, substr_count( $many, 'class="jls-version"' ), 'no notice inside a passage' );
		$this->assertGreaterThan( strrpos( $many, '<section class="jls-passage">' ), strpos( $many, '<footer class="jls-attribution">' ), 'after the last passage' );
		$positions = array_map( static fn( $ref ) => strpos( $many, '<h3 class="jls-ref">' . $ref . '</h3>' ), array( 'Ma-thi-ơ 3:13-17', 'Lu-ca 3:23a', 'Ma-thi-ơ 4:1-11', 'Giăng 1:19-28' ) );
		$this->assertNotContains( false, $positions, 'every reference is a heading' );
		$sorted = $positions;
		sort( $sorted );
		$this->assertSame( $sorted, $positions, 'in the plan\'s order' );
		foreach ( explode( '<section class="jls-passage">', $many ) as $i => $part ) {
			if ( $i > 0 ) {
				$this->assertStringContainsString( '<sup class="jls-num">', $part, "passage {$i} shows its verses" );
			}
		}
	}

	/**
	 * A passage whose text cannot be shown keeps its note and link in place;
	 * the footer then lists the shown Bible's notice and the link version's,
	 * each once.
	 */
	public function test_a_failed_passage_keeps_its_link_and_the_footer_lists_both_notices() {
		$fake       = $this->use_fake_nirv();
		$fake->mode = 'error';
		$html       = jlife_scripture_render_day( $this->day( 'John 1:1-5; John 1:6-8' ), $this->participant( 'en' ) );
		$this->assertSame( 2, substr_count( $html, 'The Bible text could not be loaded.' ) );
		$this->assertSame( 2, substr_count( $html, 'Open in Bible Gateway (ERV)' ), 'each passage keeps its link' );
		$this->assertSame( 1, substr_count( $html, 'Bible League International' ), 'the link notice once' );

		// Over the license's verse limit, the second passage falls back to its link.
		$this->use_fake_nirv( 3600, 5 );
		$mixed = jlife_scripture_render_day( $this->day( 'John 2:1-5; John 3:1-12' ), $this->participant( 'en' ) );
		$this->assertStringContainsString( Jlife_Scripture_Fake_Source::TEXT, $mixed, 'the first shows text' );
		$this->assertSame( 1, substr_count( $mixed, 'Open in Bible Gateway (ERV)' ), 'the second is a link' );
		$this->assertLessThan( strpos( $mixed, '<footer' ), strpos( $mixed, 'Open in Bible Gateway (ERV)' ), 'the link stays in its passage' );
		$footer = substr( $mixed, (int) strpos( $mixed, '<footer class="jls-attribution">' ) );
		$this->assertStringContainsString( 'New International Reader&#039;s Version', $footer );
		$this->assertSame( 2, substr_count( $footer, 'class="jls-version"' ), 'the shown Bible and the link version, once each' );
	}

	/**
	 * The stylesheet is phone-first: no inner scroll box, dark mode.
	 */
	public function test_stylesheet_is_enqueued_and_phone_first() {
		jlife_scripture_render_day( $this->day( 'John 1:1-5' ), $this->participant( 'vi' ) );
		$this->assertTrue( wp_style_is( 'jlife-scripture', 'enqueued' ) );
		$css = (string) file_get_contents( dirname( __DIR__ ) . '/assets/scripture.css' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- plugin asset.
		$this->assertStringContainsString( 'prefers-color-scheme: dark', $css );
		$this->assertDoesNotMatchRegularExpression( '/overflow(-y)?:\s*(auto|scroll)/', $css );
		$this->assertDoesNotMatchRegularExpression( '/max-height:\s*\d/', $css );
	}
}
