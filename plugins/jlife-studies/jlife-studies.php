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
 */

defined( 'ABSPATH' ) || exit;

/**
 * Scaffold only (issue #14). Feature logic lands via #5 (content schema),
 * #13 (spike S6: CPTs + import/export round-trip), and MVP build issues.
 * Responsibilities and boundaries: see README.md and docs/architecture.md §6.
 */
add_action( 'init', 'jlife_studies_load_textdomain' );

function jlife_studies_load_textdomain() {
	load_plugin_textdomain( 'jlife-studies', false, dirname( plugin_basename( __FILE__ ) ) . '/languages' );
}
