<?php
/**
 * Bible text and API keys never reach logs, error messages or the page's
 * error paths; the reader reads only the participant's language.
 *
 * @package jlife-scripture
 */

/**
 * Log and error hygiene tests.
 */
class Test_Privacy extends Jlife_Scripture_TestCase {

	/**
	 * Every failure path: the log names the source, version, passage and an
	 * error code, and nothing else; errors and the page carry no text or key.
	 */
	public function test_failures_never_leak_text_or_keys() {
		$fake = $this->use_fake_nirv( 3600, 5 );
		$user = $this->participant( 'en_US' );
		foreach ( array( 'error', 'throw', 'malformed', 'ok' ) as $mode ) {
			$fake->mode = $mode;
			$ref        = 'ok' === $mode ? '1 Corinthians 13' : 'John 1:1-3'; // "ok" + an unknown-length chapter: over the verse limit after the fetch.
			$r          = jlife_scripture_get_passage( jlife_scripture_get_version( 'nirv' ), jlife_scripture_parse_reference( $ref ) );
			$this->assertWPError( $r, $mode );
			foreach ( $r->get_error_messages() as $message ) {
				$this->assertStringNotContainsString( Jlife_Scripture_Fake_Source::TEXT, $message );
				$this->assertStringNotContainsString( Jlife_Scripture_Fake_Source::KEY, $message );
			}
			$html = jlife_scripture_render_day( $this->day( $ref ), $user );
			$this->assertStringNotContainsString( Jlife_Scripture_Fake_Source::TEXT, $html, $mode );
			$this->assertStringNotContainsString( Jlife_Scripture_Fake_Source::KEY, $html, $mode );
		}
		$log = $this->logged();
		$this->assertStringContainsString( 'jlife-scripture: fetch failed: source=youversion version=nirv passage=JHN.1.1-JHN.1.3 code=other', $log );
		$this->assertStringContainsString( 'over the license verse limit', $log );
		$this->assertStringNotContainsString( Jlife_Scripture_Fake_Source::TEXT, $log );
		$this->assertStringNotContainsString( strtolower( Jlife_Scripture_Fake_Source::KEY ), strtolower( $log ), 'not even inside an error code' );
	}

	/**
	 * Reading the 1925 text logs nothing, and an unavailable passage's error
	 * holds no verse text.
	 */
	public function test_local_reading_logs_nothing() {
		$html = jlife_scripture_render_day( $this->day( 'John 1:1-18; Acts 29:1' ), $this->participant( 'vi' ) );
		$this->assertStringContainsString( 'Ngôi Lời', $html );
		$this->assertSame( '', $this->logged() );
		$r = jlife_scripture_get_passage( jlife_scripture_get_version( 'kt1925' ), jlife_scripture_parse_reference( 'John 1:1, 99' ) );
		$this->assertWPError( $r );
		$this->assertStringNotContainsString( 'Ngôi Lời', implode( ' ', $r->get_error_messages() ) );
	}

	/**
	 * The plugin source never logs or prints a source's own error message,
	 * an exception message or an API key.
	 */
	public function test_source_code_logs_identifiers_only() {
		foreach ( glob( dirname( __DIR__ ) . '/includes/*.php' ) as $file ) {
			$src = (string) file_get_contents( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- reading the plugin's own source.
			$this->assertStringNotContainsString( 'getMessage()', $src, basename( $file ) );
			$this->assertStringNotContainsString( 'get_error_message()', str_replace( 'WP_CLI::error( $r->get_error_message() )', '', $src ), basename( $file ) );
			$this->assertDoesNotMatchRegularExpression( '/print_r|var_export|var_dump/', $src, basename( $file ) );
			$this->assertSame( basename( $file ) === 'cache.php' ? 1 : 0, substr_count( $src, 'error_log(' ), basename( $file ) . ': one logging function' );
		}
	}
}
