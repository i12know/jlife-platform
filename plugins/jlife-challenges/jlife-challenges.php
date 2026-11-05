<?php
/**
 * Plugin Name:       J-Life Challenges
 * Plugin URI:        https://github.com/i12know/jlife-platform
 * Description:       Bible Marathon engine: campaigns, groups and enrollment, private SOAP entries, one point per person per day, and deliberate group shares. Design: docs/challenges-engine-design.md (#60).
 * Version:           0.1.0
 * Requires at least: 6.0
 * Requires PHP:      8.0
 * Author:            J-Life Platform contributors
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       jlife-challenges
 * Domain Path:       /languages
 *
 * @package jlife-challenges
 */

defined( 'ABSPATH' ) || exit;

require_once __DIR__ . '/includes/schema.php';
require_once __DIR__ . '/includes/calendar.php';
require_once __DIR__ . '/includes/audit.php';
require_once __DIR__ . '/includes/gate.php';
require_once __DIR__ . '/includes/groups.php';
require_once __DIR__ . '/includes/soap.php';
require_once __DIR__ . '/includes/scores.php';
require_once __DIR__ . '/includes/shares.php';
require_once __DIR__ . '/includes/lifecycle.php';
require_once __DIR__ . '/includes/views.php';
require_once __DIR__ . '/includes/screens.php';
require_once __DIR__ . '/includes/rest.php';
require_once __DIR__ . '/includes/test-campaign.php';

if ( defined( 'WP_CLI' ) && WP_CLI ) {
	require_once __DIR__ . '/includes/class-jlife-test-campaign-command.php';
	WP_CLI::add_command( 'jlife test-campaign', 'Jlife_Test_Campaign_Command' );
}

register_activation_hook( __FILE__, 'jlife_challenges_activate' );

/**
 * Create the tables, register the screen routes and flush rewrite rules once.
 */
function jlife_challenges_activate() {
	jlife_challenges_install_tables();
	jlife_challenges_add_rewrites();
	flush_rewrite_rules();
}

add_action( 'init', 'jlife_challenges_load_textdomain' );

/**
 * Load the plugin text domain for translation.
 */
function jlife_challenges_load_textdomain() {
	load_plugin_textdomain( 'jlife-challenges', false, dirname( plugin_basename( __FILE__ ) ) . '/languages' );
}
