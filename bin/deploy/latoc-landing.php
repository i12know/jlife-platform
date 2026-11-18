<?php
/**
 * latoc public landing pages (#110): Polylang configuration and one-time seed.
 *
 * Run by bin/deploy/latoc-remote.sh through `wp eval-file`, after Polylang
 * (pinned) is installed and activated on this site. Idempotent.
 *
 * Two kinds of work, with opposite rules:
 *
 *  1. CONFIGURATION (languages, URL options) is re-asserted on every run,
 *     because URL behaviour must not drift and GitHub is where it is managed.
 *  2. CONTENT is created ONCE and then belongs to the owner, who edits it in
 *     wp-admin. The deploy never updates it. A page the owner deletes or
 *     trashes is NOT recreated: the "seeded" option records that the seed ran.
 *
 * Nothing new is public until the owner publishes:
 *  - both pages are created as DRAFTS (unreviewed copy, machine-assisted
 *    Vietnamese);
 *  - the site's front page is not touched while the English page is a draft.
 *    Once the owner has published it, the first deploy after that makes it the
 *    front page, one time (option jlife_landing_front_done). The owner can do
 *    the same at once in Settings > Reading. After that the deploy never
 *    changes the front page again.
 *
 * Writes a small, non-secret status file (LATOC_LANDING_STATE) that the
 * workflow's smoke test reads. Prints no secrets.
 *
 * @package jlife-platform
 */

// phpcs:disable WordPress -- deploy-time script, not plugin code.

if ( ! function_exists( 'pll_set_post_language' ) || ! function_exists( 'PLL' ) ) {
	WP_CLI::error( 'Polylang is not active on this site.' );
}

// ---------------------------------------------------------------------------
// 1. Configuration: re-asserted on every run.
// ---------------------------------------------------------------------------
$languages = PLL()->model->languages;
$have      = pll_languages_list();
foreach ( array(
	array(
		'locale' => 'en_US',
		'slug'   => 'en',
		'name'   => 'English',
	),
	array(
		'locale' => 'vi',
		'slug'   => 'vi',
		'name'   => 'Tiếng Việt',
	),
) as $lang ) {
	if ( in_array( $lang['slug'], $have, true ) ) {
		continue;
	}
	$added = $languages->add( $lang );
	if ( is_wp_error( $added ) ) {
		WP_CLI::error( 'Could not add language ' . $lang['slug'] . ': ' . $added->get_error_message() );
	}
	WP_CLI::log( 'Polylang language added: ' . $lang['slug'] );
}

$o                  = PLL()->options;
$o['default_lang']  = 'en';
$o['force_lang']    = 1;       // Language from the directory name: /vi/.
$o['hide_default']  = true;    // English has no prefix: /.
$o['redirect_lang'] = true;    // /vi/ serves the VI front page itself (no 301 to its slug).
$o['browser']       = false;   // No browser-language detection or redirect.
$o['rewrite']       = true;    // No /language/ base.
$o['media_support'] = false;
$o['post_types']    = array(); // Only page and post are translated: the jlife post types stay untranslated.
$o['taxonomies']    = array();
$languages->clean_cache();
WP_CLI::log( 'Polylang options asserted (/ English, /vi/ Vietnamese, no browser detection).' );

// Pages and posts that exist without a language (WordPress samples) get English.
$all_statuses = array( 'publish', 'draft', 'pending', 'private', 'future', 'trash' );
foreach ( get_posts(
	array(
		'post_type'        => array( 'page', 'post' ),
		'post_status'      => $all_statuses,
		'numberposts'      => -1,
		'fields'           => 'ids',
		'lang'             => '',
		'suppress_filters' => false,
	)
) as $existing_id ) {
	if ( ! pll_get_post_language( $existing_id ) ) {
		pll_set_post_language( $existing_id, 'en' );
	}
}

// ---------------------------------------------------------------------------
// 2. Content: created once, as drafts, never updated.
// ---------------------------------------------------------------------------
$find = static function ( $key, array $statuses ) {
	$ids = get_posts(
		array(
			'post_type'        => 'page',
			'post_status'      => $statuses,
			'meta_key'         => '_jlife_landing_seed',
			'meta_value'       => $key,
			'numberposts'      => 1,
			'fields'           => 'ids',
			'lang'             => '',
			'suppress_filters' => false,
		)
	);
	return $ids ? (int) $ids[0] : 0;
};

$pages = require __DIR__ . '/latoc-landing-content.php';

if ( get_option( 'jlife_landing_seeded' ) ) {
	WP_CLI::log( 'Landing pages were seeded before: content left untouched (a deleted or trashed page is not recreated).' );
} else {
	$ids     = array();
	$created = array();
	foreach ( $pages as $code => $page ) {
		// A page that exists in ANY state (including trash) is not recreated.
		$id = $find( $page['key'], $all_statuses );
		if ( $id ) {
			WP_CLI::log( "Landing page {$code}: exists, not touched." );
		} else {
			// The content is trusted code from this repository: keep it byte-exact
			// (WP-CLI runs without a user, so kses would otherwise strip styles).
			kses_remove_filters();
			$admins = get_super_admins();
			$author = $admins ? get_user_by( 'login', $admins[0] ) : false;
			$id     = wp_insert_post(
				array(
					'post_type'    => 'page',
					'post_status'  => 'draft', // Nothing is public until the owner publishes.
					'post_title'   => $page['title'],
					'post_name'    => $page['slug'],
					'post_content' => $page['content'],
					'post_author'  => $author ? (int) $author->ID : 1,
				),
				true
			);
			kses_init();
			if ( is_wp_error( $id ) ) {
				WP_CLI::error( 'Could not create the ' . $code . ' landing page: ' . $id->get_error_message() );
			}
			update_post_meta( $id, '_jlife_landing_seed', $page['key'] );
			pll_set_post_language( $id, $code );
			$created[] = $code;
			WP_CLI::log( "Landing page {$code}: created as a draft." );
		}
		$ids[ $code ] = (int) $id;
	}
	// Link the pair as translations (also finishes a partial first run).
	if ( count( $ids ) === count( $pages ) && pll_get_post( $ids['en'], 'vi' ) !== $ids['vi'] ) {
		pll_save_post_translations( $ids );
		WP_CLI::log( 'Landing pages linked as EN/VI translations.' );
	}
	update_option( 'jlife_landing_seeded', '1', false );
}

// ---------------------------------------------------------------------------
// 3. Front page: adopted once, and only after the owner published the EN page.
// ---------------------------------------------------------------------------
$en_id = $find( 'latoc-landing-en', array( 'publish', 'draft', 'pending', 'private', 'future' ) );
if ( ! get_option( 'jlife_landing_front_done' ) ) {
	if ( $en_id && 'publish' === get_post_status( $en_id ) ) {
		if ( 'page' !== get_option( 'show_on_front' ) || ! (int) get_option( 'page_on_front' ) ) {
			update_option( 'show_on_front', 'page' );
			update_option( 'page_on_front', $en_id );
			WP_CLI::log( 'The published English landing page is now the front page (set once).' );
		} else {
			WP_CLI::log( 'A front page is already chosen: left as it is.' );
		}
		update_option( 'jlife_landing_front_done', '1', false );
	} else {
		WP_CLI::log( 'English landing page is not published yet: the current front page is unchanged.' );
	}
}
$languages->clean_cache();
flush_rewrite_rules();

// ---------------------------------------------------------------------------
// 4. Status for the smoke test (no secrets, no content).
// ---------------------------------------------------------------------------
$state_file = getenv( 'LATOC_LANDING_STATE' );
if ( $state_file ) {
	$status_of = static function ( $key ) use ( $find, $all_statuses ) {
		$id = $find( $key, $all_statuses );
		return $id ? get_post_status( $id ) : 'missing';
	};
	$slug_of   = static function ( $key ) use ( $find, $all_statuses ) {
		$id = $find( $key, $all_statuses );
		return $id ? get_post_field( 'post_name', $id ) : '';
	};
	$front     = 'posts';
	if ( 'page' === get_option( 'show_on_front' ) && (int) get_option( 'page_on_front' ) ) {
		$front = ( (int) get_option( 'page_on_front' ) === $en_id ) ? 'en-landing' : 'other-page';
	}
	$lines = array(
		'en_status=' . $status_of( 'latoc-landing-en' ),
		'vi_status=' . $status_of( 'latoc-landing-vi' ),
		'en_slug=' . $slug_of( 'latoc-landing-en' ),
		'vi_slug=' . $slug_of( 'latoc-landing-vi' ),
		'front=' . $front,
	);
	file_put_contents( $state_file, implode( "\n", $lines ) . "\n" );
	WP_CLI::log( 'Landing status: ' . implode( ' ', $lines ) );
}
