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
 */

defined( 'ABSPATH' ) || exit;

/**
 * Scaffold only (issue #14). This plugin is privacy-critical: its data model
 * and capability checks are designed and tested in spike S5 (#12) before any
 * feature code lands here. Responsibilities: see README.md and
 * docs/architecture.md §5–§6; boundaries: docs/integration-boundaries.md.
 */
add_action( 'init', 'jlife_huddles_load_textdomain' );

function jlife_huddles_load_textdomain() {
	load_plugin_textdomain( 'jlife-huddles', false, dirname( plugin_basename( __FILE__ ) ) . '/languages' );
}
