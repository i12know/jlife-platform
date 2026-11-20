<?php
/**
 * PHPUnit bootstrap: loads the WordPress test suite and this plugin.
 *
 * The wp-env instance provides the suite at WP_TESTS_DIR (/wordpress-phpunit
 * in the tests-cli container); run `node bin/ensure-wp-tests.js` after
 * `wp-env start` to guarantee it is populated. Run tests via:
 *   npx wp-env run tests-cli --env-cwd=wp-content/plugins/jlife-studies vendor/bin/phpunit
 *
 * @package jlife-studies
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
		require dirname( __DIR__ ) . '/jlife-studies.php';
		// The on-page Scripture reader on /reading/ (#139) works with jlife-scripture beside this
		// plugin (wp-env maps both into wp-content/plugins). Without it the page is the old one.
		$scripture = dirname( __DIR__, 2 ) . '/jlife-scripture/jlife-scripture.php';
		if ( file_exists( $scripture ) ) {
			require $scripture;
		}
		// The English default is the NLT through API.Bible (#141), whose client lives in
		// jlife-challenges: load only that self-contained file when it sits beside this plugin.
		$client = dirname( __DIR__, 2 ) . '/jlife-challenges/includes/bible-apibible.php';
		if ( file_exists( $client ) ) {
			require_once $client;
		}
	}
);

require "{$_tests_dir}/includes/bootstrap.php";
