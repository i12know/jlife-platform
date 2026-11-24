<?php
/**
 * Home-screen launcher (#251): the web app manifest and the head tags that let a
 * participant put LATOC on their phone's home screen.
 *
 * Not a native app: no service worker, no offline mode, no push, and nothing
 * tracked. The standalone pages have no wp_head, so every head that should
 * offer the icon prints jlife_challenges_launcher_head_tags(): one helper, so
 * the pages cannot drift apart.
 *
 * @package jlife-challenges
 */

defined( 'ABSPATH' ) || exit;

const JLIFE_CHALLENGES_LAUNCHER_NAME   = 'LATOC';
const JLIFE_CHALLENGES_LAUNCHER_COLOR  = '#2b5c8a';
const JLIFE_CHALLENGES_LAUNCHER_BG     = '#fcf8f2'; // The LATOC logo's cream, so the launch screen matches the icon.
const JLIFE_CHALLENGES_LAUNCHER_ASSETS = 'assets/launcher/';

add_action( 'init', 'jlife_challenges_launcher_add_rewrite' );

/**
 * Route the manifest. The deploy flushes rewrites.
 */
function jlife_challenges_launcher_add_rewrite() {
	add_rewrite_rule( '^latoc\.webmanifest$', 'index.php?jlife_screen=manifest', 'top' );
}

/**
 * The address of one launcher icon file.
 *
 * @param string $file File name in assets/launcher/.
 * @return string
 */
function jlife_challenges_launcher_icon_url( $file ) {
	return plugins_url( JLIFE_CHALLENGES_LAUNCHER_ASSETS . $file, dirname( __DIR__ ) . '/jlife-challenges.php' );
}

/**
 * The manifest as an array. The id is explicit and stable, so a later change
 * to the launch address does not change the app's identity.
 *
 * @return array
 */
function jlife_challenges_launcher_manifest() {
	return array(
		'id'               => '/today/',
		'name'             => JLIFE_CHALLENGES_LAUNCHER_NAME,
		'short_name'       => JLIFE_CHALLENGES_LAUNCHER_NAME,
		'start_url'        => '/today/',
		'scope'            => '/',
		'display'          => 'standalone',
		'background_color' => JLIFE_CHALLENGES_LAUNCHER_BG,
		'theme_color'      => JLIFE_CHALLENGES_LAUNCHER_COLOR,
		'icons'            => array(
			array(
				'src'     => jlife_challenges_launcher_icon_url( 'icon-192.png' ),
				'sizes'   => '192x192',
				'type'    => 'image/png',
				'purpose' => 'any',
			),
			array(
				'src'     => jlife_challenges_launcher_icon_url( 'icon-512.png' ),
				'sizes'   => '512x512',
				'type'    => 'image/png',
				'purpose' => 'any',
			),
			array(
				'src'     => jlife_challenges_launcher_icon_url( 'icon-maskable-512.png' ),
				'sizes'   => '512x512',
				'type'    => 'image/png',
				'purpose' => 'maskable',
			),
		),
	);
}

/**
 * The head tags: manifest link, iPhone icon and title, theme color. Escaped.
 *
 * @return string
 */
function jlife_challenges_launcher_head_tags() {
	return '<link rel="manifest" href="' . esc_url( home_url( '/latoc.webmanifest' ) ) . '">'
		. '<link rel="apple-touch-icon" href="' . esc_url( jlife_challenges_launcher_icon_url( 'apple-touch-icon.png' ) ) . '">'
		. '<meta name="apple-mobile-web-app-title" content="' . esc_attr( JLIFE_CHALLENGES_LAUNCHER_NAME ) . '">'
		. '<meta name="theme-color" content="' . esc_attr( JLIFE_CHALLENGES_LAUNCHER_COLOR ) . '">';
}

add_action( 'template_redirect', 'jlife_challenges_launcher_serve_manifest', 1 );

/**
 * Send the manifest (public: browsers fetch it without cookies).
 */
function jlife_challenges_launcher_serve_manifest() {
	if ( 'manifest' !== (string) get_query_var( 'jlife_screen' ) ) {
		return;
	}
	header( 'Content-Type: application/manifest+json; charset=utf-8' );
	header( 'X-Robots-Tag: noindex, nofollow' );
	header( 'Cache-Control: public, max-age=3600' );
	echo wp_json_encode( jlife_challenges_launcher_manifest(), JSON_UNESCAPED_SLASHES ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- JSON.
	exit;
}

/**
 * The dismissible hint shown after a successful reflection submission. It is
 * hidden until the script decides: phone-width screens only, never inside the
 * installed app, never again once dismissed (remembered in localStorage only).
 *
 * @return string Escaped HTML.
 */
function jlife_challenges_launcher_hint() {
	$help = function_exists( 'jlife_challenges_help_page_url' ) ? jlife_challenges_help_page_url( 'help', '', 'add-to-home-screen' ) : '';
	return '<div class="jl-launcher-hint" role="note" hidden><p>'
		. esc_html__( 'Tip: add LATOC to your Home Screen to open it in one tap.', 'jlife-challenges' )
		. ( '' !== $help ? ' <a href="' . esc_url( $help ) . '">' . esc_html__( 'How', 'jlife-challenges' ) . '</a>' : '' )
		. '</p><button type="button" class="jl-button jl-launcher-dismiss">' . esc_html__( 'Dismiss', 'jlife-challenges' ) . '</button></div>';
}

add_action( 'wp_head', 'jlife_challenges_launcher_wp_head' );

/**
 * Print the tags on ordinary theme pages (the public landing page and any page
 * someone arrives on from an invitation). Front end only; the standalone screens
 * print the same helper themselves because they have no wp_head.
 */
function jlife_challenges_launcher_wp_head() {
	if ( is_admin() ) {
		return;
	}
	echo jlife_challenges_launcher_head_tags(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built from escaped parts.
}
