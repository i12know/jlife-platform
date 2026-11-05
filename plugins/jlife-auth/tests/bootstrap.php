<?php
/**
 * PHPUnit bootstrap: loads the WordPress test suite, jlife-challenges (for
 * the join flow) and this plugin.
 *
 * Run via:
 *   npx wp-env run tests-cli --env-cwd=wp-content/plugins/jlife-auth vendor/bin/phpunit
 *
 * @package jlife-auth
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
		require dirname( __DIR__, 2 ) . '/jlife-challenges/jlife-challenges.php';
		require dirname( __DIR__ ) . '/jlife-auth.php';
	}
);

require "{$_tests_dir}/includes/bootstrap.php";
