<?php
/**
 * Plugin Name:       J-Life Sign-in
 * Plugin URI:        https://github.com/i12know/jlife-platform
 * Description:       Passwordless sign-in for participants: a 6-digit code by email (no sign-in links), joining a group with an invite code, age band (13+), language choice, and the consent step (#58).
 * Version:           0.1.0
 * Requires at least: 6.0
 * Requires PHP:      8.0
 * Requires Plugins:  jlife-challenges
 * Author:            J-Life Platform contributors
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       jlife-auth
 * Domain Path:       /languages
 *
 * @package jlife-auth
 */

defined( 'ABSPATH' ) || exit;

require_once __DIR__ . '/includes/codes.php';
require_once __DIR__ . '/includes/accounts.php';
require_once __DIR__ . '/includes/session.php';
require_once __DIR__ . '/includes/pages.php';

register_activation_hook( __FILE__, 'jlife_auth_activate' );

/**
 * Register the routes and flush rewrite rules once.
 */
function jlife_auth_activate() {
	jlife_auth_add_rewrites();
	flush_rewrite_rules();
}

add_action( 'init', 'jlife_auth_load_textdomain' );

/**
 * Load the plugin text domain for translation.
 */
function jlife_auth_load_textdomain() {
	load_plugin_textdomain( 'jlife-auth', false, dirname( plugin_basename( __FILE__ ) ) . '/languages' );
}
