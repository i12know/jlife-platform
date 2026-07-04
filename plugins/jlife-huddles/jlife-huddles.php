<?php
/**
 * Plugin Name:       J-Life Huddles
 * Plugin URI:        https://github.com/i12know/jlife-platform
 * Description:       Huddle layer for the J-Life platform (STUDY subsite): membership mirror, privacy-scoped discussion threads, private notes, progress, and invites. Scaffold only for now.
 * Version:           0.1.0
 * Requires at least: 6.0
 * Requires PHP:      8.0
 * Author:            J-Life Platform contributors
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       jlife-huddles
 * Domain Path:       /languages
 *
 * @package jlife-huddles
 */

defined( 'ABSPATH' ) || exit;

require_once __DIR__ . '/includes/schema.php';
require_once __DIR__ . '/includes/gate.php';
require_once __DIR__ . '/includes/data.php';

register_activation_hook( __FILE__, 'jlife_huddles_install_tables' );

/**
 * Spike S5 (#12) data model: privacy-scoped custom tables (schema.php), a
 * single capability gate every access decision flows through (gate.php),
 * and a data API that refuses to touch the DB without the gate (data.php).
 * Access-control tests: tests/test-access-control.php, run by CI (#16).
 * Responsibilities: see README.md and docs/architecture.md §5–§6;
 * boundaries: docs/integration-boundaries.md.
 */
add_action( 'init', 'jlife_huddles_load_textdomain' );

/**
 * Load the plugin text domain for translation.
 */
function jlife_huddles_load_textdomain() {
	load_plugin_textdomain( 'jlife-huddles', false, dirname( plugin_basename( __FILE__ ) ) . '/languages' );
}
