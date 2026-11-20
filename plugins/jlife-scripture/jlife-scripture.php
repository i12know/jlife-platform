<?php
/**
 * Plugin Name:       J-Life Scripture
 * Plugin URI:        https://github.com/i12know/jlife-platform
 * Description:       The day's Scripture on the page (#139): Bible version allowlist, the public-domain Kinh Thánh 1925 text, API.Bible sources (NLT, NKJV, CSB; #141), a reference parser, a shared passage cache for licensed sources, and a reader with an "Open in Bible Gateway" fallback. Off until the jlife_scripture_native_reader option is turned on.
 * Version:           0.1.0
 * Requires at least: 6.0
 * Requires PHP:      8.0
 * Author:            J-Life Platform contributors
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       jlife-scripture
 * Domain Path:       /languages
 *
 * @package jlife-scripture
 */

defined( 'ABSPATH' ) || exit;

const JLIFE_SCRIPTURE_FILE = __FILE__;

require_once __DIR__ . '/includes/schema.php';
require_once __DIR__ . '/includes/registry.php';
require_once __DIR__ . '/includes/references.php';
require_once __DIR__ . '/includes/interface-jlife-scripture-source.php';
require_once __DIR__ . '/includes/interface-jlife-scripture-display-tracking.php';
require_once __DIR__ . '/includes/interface-jlife-scripture-source-attribution.php';
require_once __DIR__ . '/includes/class-jlife-scripture-local-source.php';
require_once __DIR__ . '/includes/class-jlife-scripture-youversion-source.php';
require_once __DIR__ . '/includes/class-jlife-scripture-apibible-source.php';
require_once __DIR__ . '/includes/sources.php';
require_once __DIR__ . '/includes/local-import.php';
require_once __DIR__ . '/includes/cache.php';
require_once __DIR__ . '/includes/apibible.php';
require_once __DIR__ . '/includes/preferences.php';
require_once __DIR__ . '/includes/render.php';

if ( defined( 'WP_CLI' ) && WP_CLI ) {
	require_once __DIR__ . '/includes/class-jlife-scripture-command.php';
	WP_CLI::add_command( 'jlife scripture', 'Jlife_Scripture_Command' );
}

register_activation_hook( __FILE__, 'jlife_scripture_activate' );
register_deactivation_hook( __FILE__, 'jlife_scripture_deactivate' );

/**
 * Create the tables and schedule the daily cache purge.
 */
function jlife_scripture_activate() {
	jlife_scripture_install_tables();
	if ( ! wp_next_scheduled( 'jlife_scripture_cache_purge' ) ) {
		wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', 'jlife_scripture_cache_purge' );
	}
}

/**
 * Stop the daily cache purge (tables and data stay; they hold no personal data).
 */
function jlife_scripture_deactivate() {
	wp_clear_scheduled_hook( 'jlife_scripture_cache_purge' );
}

add_action( 'plugins_loaded', 'jlife_scripture_maybe_upgrade' );
add_action( 'init', 'jlife_scripture_load_textdomain' );
add_action( 'jlife_scripture_cache_purge', 'jlife_scripture_cache_purge_expired' );

/**
 * Load the plugin text domain for translation.
 */
function jlife_scripture_load_textdomain() {
	load_plugin_textdomain( 'jlife-scripture', false, dirname( plugin_basename( __FILE__ ) ) . '/languages' );
}
