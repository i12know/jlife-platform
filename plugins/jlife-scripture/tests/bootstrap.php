<?php
/**
 * PHPUnit bootstrap: loads the WordPress test suite and this plugin.
 *
 * The wp-env instance provides the suite at WP_TESTS_DIR (/wordpress-phpunit
 * in the tests-cli container); run `node bin/ensure-wp-tests.js` after
 * `wp-env start` to guarantee it is populated. Run tests via:
 *   npx wp-env run tests-cli --env-cwd=wp-content/plugins/jlife-scripture vendor/bin/phpunit
 *
 * @package jlife-scripture
 */

$_tests_dir = getenv( 'WP_TESTS_DIR' );

if ( ! $_tests_dir ) {
	$_tests_dir = rtrim( sys_get_temp_dir(), '/\\' ) . '/wordpress-tests-lib';
}

if ( ! file_exists( "{$_tests_dir}/includes/functions.php" ) ) {
	echo "Could not find {$_tests_dir}/includes/functions.php — is WP_TESTS_DIR set? (wp-env sets it in the tests-cli container.)" . PHP_EOL;
	exit( 1 );
}

require_once "{$_tests_dir}/includes/functions.php";

tests_add_filter(
	'muplugins_loaded',
	function () {
		require dirname( __DIR__ ) . '/jlife-scripture.php';
		// The API.Bible source (#141) calls the client in jlife-challenges (#147). Load only that
		// self-contained file when it sits beside this plugin (wp-env maps both): the source then
		// works without the rest of jlife-challenges, as it must when that plugin is inactive.
		$client = dirname( __DIR__, 2 ) . '/jlife-challenges/includes/bible-apibible.php';
		if ( file_exists( $client ) ) {
			require_once $client;
		}
	}
);

require "{$_tests_dir}/includes/bootstrap.php";

// Shared fixtures (needs WP_UnitTestCase, defined by the suite bootstrap above).
require_once __DIR__ . '/class-jlife-scripture-fake-source.php';
require_once __DIR__ . '/class-jlife-scripture-testcase.php';
