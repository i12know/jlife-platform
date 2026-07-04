<?php
/**
 * Plugin Name:       J-Life Studies
 * Plugin URI:        https://github.com/i12know/jlife-platform
 * Description:       Study content engine for the J-Life platform (STUDY subsite): series/lesson post types, taxonomies, reader, translation workflow, and portable import/export. Scaffold only for now.
 * Version:           0.1.0
 * Requires at least: 6.0
 * Requires PHP:      8.0
 * Author:            J-Life Platform contributors
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       jlife-studies
 * Domain Path:       /languages
 *
 * @package jlife-studies
 */

defined( 'ABSPATH' ) || exit;

require_once __DIR__ . '/includes/content-types.php';
require_once __DIR__ . '/includes/content-io.php';
require_once __DIR__ . '/includes/reader.php';

if ( defined( 'WP_CLI' ) && WP_CLI ) {
	require_once __DIR__ . '/includes/class-jlife-content-command.php';
	WP_CLI::add_command( 'jlife content', 'Jlife_Content_Command' );
}

/**
 * Spike S6 (#13) prototype: series/lesson CPTs, derived taxonomies,
 * WP-CLI import/export, and a rough reader. The portable files in /content
 * remain the source of truth (architecture.md §4); MVP build issues harden
 * this into product code. Responsibilities and boundaries: see README.md
 * and docs/architecture.md §6.
 */
add_action( 'init', 'jlife_studies_load_textdomain' );

/**
 * Load the plugin text domain for translation.
 */
function jlife_studies_load_textdomain() {
	load_plugin_textdomain( 'jlife-studies', false, dirname( plugin_basename( __FILE__ ) ) . '/languages' );
}
