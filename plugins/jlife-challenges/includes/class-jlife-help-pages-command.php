<?php
/**
 * WP-CLI: `wp jlife help-pages seed|status` (#65).
 *
 * Prints page IDs and statuses only: no page content, no personal data.
 *
 * @package jlife-challenges
 */

defined( 'ABSPATH' ) || exit;

/**
 * The Help, Rules and Privacy pages.
 */
class Jlife_Help_Pages_Command {

	/**
	 * Create the Help, Rules and Privacy pages ONCE, as drafts, in English and
	 * Vietnamese (needs Polylang). Never updates or recreates a page, so the
	 * owner's edits in wp-admin are safe. Safe to run on every deploy.
	 *
	 * ## EXAMPLES
	 *
	 *     wp jlife help-pages seed
	 */
	public function seed() {
		$result = jlife_challenges_seed_help_pages();
		if ( is_wp_error( $result ) ) {
			WP_CLI::error( $result->get_error_message() );
		}
		if ( $result['seeded_before'] ) {
			WP_CLI::log( 'Help, Rules and Privacy pages were seeded before: content left untouched (a deleted or trashed page is not recreated).' );
			return;
		}
		foreach ( $result['created'] as $key ) {
			WP_CLI::log( "Page {$key}: created as a draft." );
		}
		foreach ( $result['existing'] as $key ) {
			WP_CLI::log( "Page {$key}: exists, not touched." );
		}
		WP_CLI::success( 'Seeded. The pages are drafts: the owner edits and publishes them in wp-admin.' );
	}

	/**
	 * Show which pages the participant screens link to (IDs and statuses).
	 *
	 * ## EXAMPLES
	 *
	 *     wp jlife help-pages status
	 */
	public function status() {
		foreach ( jlife_challenges_help_page_options() as $key => $option ) {
			$id = (int) get_option( $option, 0 );
			WP_CLI::log( sprintf( '%s: option %s = %d, status %s', $key, $option, $id, $id > 0 ? (string) get_post_status( $id ) : 'not set' ) );
		}
		WP_CLI::log( 'Seeded: ' . ( get_option( JLIFE_CHALLENGES_HELP_SEEDED_OPTION ) ? 'yes' : 'no' ) );
	}
}
