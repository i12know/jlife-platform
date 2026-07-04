<?php
/**
 * Plugin Name:       J-Life Bridge
 * Plugin URI:        https://github.com/i12know/jlife-platform
 * Description:       Network bridge for the J-Life platform: user-to-contact and huddle-to-group ID mapping, onboarding flow, and cross-subsite read API between STUDY and the Disciple.Tools HUB. Scaffold only for now.
 * Version:           0.1.0
 * Requires at least: 6.0
 * Requires PHP:      8.0
 * Author:            J-Life Platform contributors
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       jlife-bridge
 * Domain Path:       /languages
 * Network:           true
 */

defined( 'ABSPATH' ) || exit;

/**
 * Scaffold only (issue #14), following Disciple.Tools starter-plugin
 * conventions (guarded bootstrap, D.T presence check) without its feature
 * boilerplate — feature code is designed in spikes S3 (#10) and S4 (#11)
 * first. Responsibilities: see README.md and docs/architecture.md §6.
 */
add_action( 'init', 'jlife_bridge_load_textdomain' );

function jlife_bridge_load_textdomain() {
	load_plugin_textdomain( 'jlife-bridge', false, dirname( plugin_basename( __FILE__ ) ) . '/languages' );
}

add_action( 'after_setup_theme', 'jlife_bridge_bootstrap', 100 );

function jlife_bridge_bootstrap() {
	/*
	 * The bridge runs network-wide but its Disciple.Tools integrations
	 * (group tile, contact mapping hooks) must only load on the HUB subsite,
	 * where the D.T theme is active. On STUDY this plugin exposes only the
	 * mapping/read API. D.T-dependent code goes below this guard.
	 */
	if ( ! class_exists( 'Disciple_Tools' ) ) {
		return;
	}
}
