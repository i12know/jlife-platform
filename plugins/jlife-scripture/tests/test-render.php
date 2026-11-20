<?php
/**
 * The reader: the participant's Bible on the page, first passage open, the
 * rest collapsed, verse numbers, version name and copyright line; link-only
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
		$this->assertSame( 4, substr_count( $html, '<details class="jls-passage"' ) );
		$this->assertSame( 1, substr_count( $html, '<details class="jls-passage" open>' ), 'only the first is open' );
		$this->assertStringStartsWith( '<section class="jls-reader" lang="vi" aria-label="Scripture reading"><details class="jls-passage" open><summary>Ma-thi-ơ 3:13-17</summary>', $html );
		$this->assertStringContainsString( '<summary>Lu-ca 3:23a</summary>', $html );
		$this->assertStringContainsString( '<sup class="jls-num">13</sup>', $html, 'verse numbers' );
		$this->assertSame( 4, substr_count( $html, 'jls-text' ), 'text for every passage' );
		$this->assertSame( 4, substr_count( $html, '<span class="jls-version-name">Kinh Thánh 1925 (Bản Truyền Thống)</span>' ) );
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
		$this->assertStringContainsString( '<summary>Matthew 3:13-17</summary>', $html );
		$this->assertSame( 1, substr_count( $html, ' open>' ) );
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
