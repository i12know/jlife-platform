<?php
/**
 * Vietnamese drafts: every string in the plugin has one, and the reader
 * renders in Vietnamese.
 *
 * @package jlife-scripture
 */

/**
 * Translation tests.
 */
class Test_Translations extends Jlife_Scripture_TestCase {

	/**
	 * Locale filter callback.
	 *
	 * @var callable|null
	 */
	private $force = null;

	/**
	 * Render in Vietnamese: point WordPress's loader at the plugin's languages folder.
	 */
	public function set_up() {
		parent::set_up();
		$this->force = static fn() => 'vi';
		add_filter( 'determine_locale', $this->force, 99 );
		add_filter(
			'lang_dir_for_domain',
			static fn( $path, $domain ) => 'jlife-scripture' === $domain ? dirname( __DIR__ ) . '/languages/' : $path,
			10,
			2
		);
		unload_textdomain( 'jlife-scripture', true );
	}

	/**
	 * Back to English.
	 */
	public function tear_down() {
		remove_filter( 'determine_locale', $this->force, 99 );
		unload_textdomain( 'jlife-scripture', true );
		parent::tear_down();
	}

	/**
	 * The catalog is a marked draft and covers every translatable string.
	 */
	public function test_every_string_has_a_vietnamese_draft() {
		$file    = dirname( __DIR__ ) . '/languages/jlife-scripture-vi.l10n.php';
		$catalog = include $file;
		$head    = substr( (string) file_get_contents( $file ), 0, 1500 ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- reading the plugin's own source.
		$this->assertStringContainsString( 'DRAFT', $head );
		$this->assertSame( 'jlife-scripture', $catalog['domain'] );
		$found = array();
		foreach ( glob( dirname( __DIR__ ) . '/includes/*.php' ) as $file ) {
			$src = (string) file_get_contents( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- reading the plugin's own source.
			preg_match_all( '/\b(?:esc_html__|esc_attr__|esc_html_e|esc_attr_e|__|_e)\(\s*\'((?:[^\'\\\\]|\\\\.)*)\'\s*,\s*\'([a-z-]+)\'/s', $src, $m, PREG_SET_ORDER );
			foreach ( $m as $hit ) {
				$this->assertSame( 'jlife-scripture', $hit[2], "text domain of: {$hit[1]}" );
				$found[] = str_replace( "\\'", "'", $hit[1] );
			}
		}
		$this->assertGreaterThanOrEqual( 6, count( array_unique( $found ) ) );
		$missing = array_values( array_diff( array_unique( $found ), array_keys( $catalog['messages'] ) ) );
		$this->assertSame( array(), $missing, 'untranslated: ' . implode( ' | ', $missing ) );
		foreach ( $catalog['messages'] as $english => $vi ) {
			$this->assertNotSame( '', trim( $vi ) );
			$this->assertSame( substr_count( $english, '%s' ), substr_count( $vi, '%s' ), "placeholders kept: {$english}" );
		}
	}

	/**
	 * The reader's own words are Vietnamese for a Vietnamese reader.
	 */
	public function test_reader_renders_in_vietnamese() {
		$this->patch_version( 'kt1925', array( 'enabled' => false ) );
		$html = jlife_scripture_render_day( $this->day( 'John 1:1-18', array( 'Giăng 1:1-18' ) ), $this->participant( 'vi' ) );
		$this->assertStringContainsString( 'aria-label="Bài đọc Kinh Thánh"', $html );
		$this->assertStringContainsString( 'Mở trên Bible Gateway (BPT)', $html );
		$this->assertStringContainsString( '<h3 class="jls-ref">Giăng 1:1-18</h3>', $html );
	}
}
