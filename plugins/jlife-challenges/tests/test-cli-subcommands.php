<?php
/**
 * WP-CLI subcommand names that differ from their method names.
 *
 * WP-CLI derives a subcommand from the method name (underscores kept) unless a
 * `@subcommand` tag says otherwise, so `set_engine` would not answer to the
 * documented `wp jlife campaign set-engine`.
 *
 * @package jlife-challenges
 */

/**
 * CLI subcommand registration tests.
 */
class Test_Cli_Subcommands extends WP_UnitTestCase {

	/**
	 * `set_engine` is registered as `set-engine`.
	 */
	public function test_set_engine_subcommand_is_named_with_a_hyphen() {
		require_once dirname( __DIR__ ) . '/includes/class-jlife-campaign-command.php';
		$doc = ( new ReflectionMethod( 'Jlife_Campaign_Command', 'set_engine' ) )->getDocComment();
		$this->assertIsString( $doc );
		$this->assertMatchesRegularExpression( '/^\s*\*\s*@subcommand\s+set-engine\s*$/m', $doc );
	}
}
