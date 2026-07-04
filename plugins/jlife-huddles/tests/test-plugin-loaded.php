<?php
/**
 * Smoke tests: the plugin loads and registers its hooks.
 *
 * This suite is where the S5 (#12) access-control tests land: every
 * huddle_thread / private_note / progress read and write path gets a test
 * per role (member, non-member, leader, admin, anonymous). CI (#16) runs
 * this whole directory, so those tests become required checks the moment
 * they are committed.
 *
 * @package jlife-huddles
 */

/**
 * Smoke tests for plugin bootstrap.
 */
class Test_Plugin_Loaded extends WP_UnitTestCase {

	/**
	 * The main plugin file loaded and defined its functions.
	 */
	public function test_plugin_bootstraps() {
		$this->assertTrue(
			function_exists( 'jlife_huddles_load_textdomain' ),
			'jlife-huddles.php did not load'
		);
	}

	/**
	 * The textdomain loader is hooked on init.
	 */
	public function test_textdomain_hook_registered() {
		$this->assertNotFalse(
			has_action( 'init', 'jlife_huddles_load_textdomain' ),
			'textdomain loader is not hooked on init'
		);
	}
}
