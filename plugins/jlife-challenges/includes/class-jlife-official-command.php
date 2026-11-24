<?php
/**
 * WP-CLI: `wp jlife official create [--key=<key>] [--force]` and
 * `wp jlife official status [--key=<key>]` (the official Marathon, #180).
 *
 * Prints keys, dates and counts only, never people: deploy logs may be public.
 *
 * @package jlife-challenges
 */

defined( 'ABSPATH' ) || exit;

/**
 * Manage the official Marathon campaign.
 */
class Jlife_Official_Command {

	/**
	 * Create the official Marathon (Dec 20, 2026 to Apr 2, 2027, Pacific time,
	 * active, the pilot's reading plan), or report that it exists. Safe to run
	 * again. Creates no groups and enrolls no one. Refused when the site has no
	 * pilot campaign, because new sign-ups would then go straight to Dec 20.
	 *
	 * ## OPTIONS
	 *
	 * [--key=<key>]
	 * : Campaign key. Default: marathon-2026. Never "test-..." or "pilot-...".
	 *
	 * [--force]
	 * : Create it even though the site has no pilot campaign.
	 *
	 * [--scoring-engine=<id>]
	 * : Scoring engine for a NEW campaign: soap-regex (default; the four SOAP parts) or reflection-v1 (free-form or SOAP, 20 own words). An existing campaign keeps its engine; change it before Day 1 with `wp jlife campaign set-engine`.
	 *
	 * [--photo-reflections]
	 * : Let participants add a photo of a handwritten reflection (#244) for a NEW campaign. Off by default; an existing campaign keeps its setting. Change it before Day 1 with `wp jlife campaign set-photos`.
	 *
	 * @param array $args       Positional args.
	 * @param array $assoc_args Options.
	 */
	public function create( $args, $assoc_args ) {
		unset( $args ); // WP-CLI always passes it; create takes no positional arguments.
		$input = array();
		if ( ! empty( $assoc_args['key'] ) ) {
			$input['campaign_key'] = (string) $assoc_args['key'];
		}
		if ( ! empty( $assoc_args['scoring-engine'] ) ) {
			$input['scoring_engine'] = (string) $assoc_args['scoring-engine'];
		}
		if ( ! empty( $assoc_args['photo-reflections'] ) ) {
			$input['accepts_photos'] = 1;
		}
		$r = jlife_challenges_create_official_campaign( jlife_challenges_cli_organizer(), $input, ! empty( $assoc_args['force'] ) );
		if ( is_wp_error( $r ) ) {
			WP_CLI::error( $r->get_error_message() );
		}
		WP_CLI::log(
			$r['created']
				? sprintf( 'Created the official campaign %s (id %d, %s to %s).', $r['campaign_key'], $r['campaign_id'], $r['start_date'], $r['end_date'] )
				: sprintf( 'The official campaign %s already exists (id %d, %s to %s); nothing changed.', $r['campaign_key'], $r['campaign_id'], $r['start_date'], $r['end_date'] )
		);
		WP_CLI::success( 'Official campaign ready.' );
	}

	/**
	 * Show the official campaign: key, dates, status, phase and counts, and
	 * which campaign new sign-ups join right now. Counts only.
	 *
	 * ## OPTIONS
	 *
	 * [--key=<key>]
	 * : Campaign key. Default: marathon-2026.
	 *
	 * @param array $args       Positional args.
	 * @param array $assoc_args Options.
	 */
	public function status( $args, $assoc_args ) {
		unset( $args ); // WP-CLI always passes it; status takes no positional arguments.
		$key = sanitize_key( (string) ( $assoc_args['key'] ?? 'marathon-2026' ) );
		global $wpdb;
		$t = jlife_challenges_table( 'campaigns' );
		$c = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$t} WHERE campaign_key = %s", $key ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- custom table; name from $wpdb->prefix.
		if ( ! $c ) {
			WP_CLI::log( sprintf( 'No campaign %s yet. Run: wp jlife official create', $key ) );
		} else {
			$id = (int) $c['id'];
			WP_CLI::log( sprintf( '%s (id %d): %s to %s, status %s, phase %s, %d days, photo reflections %s.', $c['campaign_key'], $id, $c['start_date'], $c['end_date'], $c['status'], jlife_challenges_campaign_phase( $c ), jlife_challenges_day_count( $c ), jlife_challenges_campaign_accepts_photos( $c ) ? 'on' : 'off' ) );
			$counts = array(
				'enrollments' => jlife_challenges_close_count( 'enrollments', $id, 'active' ),
				'entries'     => jlife_challenges_close_count( 'soap_entries', $id ),
				'ledger_rows' => jlife_challenges_close_count( 'score_ledger', $id ),
				'shares'      => jlife_challenges_close_count( 'group_shares', $id ),
			);
			foreach ( $counts as $what => $n ) {
				WP_CLI::log( sprintf( '  %-20s %d', $what, $n ) );
			}
		}
		$open = jlife_challenges_open_campaign();
		WP_CLI::log( $open ? sprintf( 'New sign-ups join now: %s (id %d).', $open['campaign_key'], (int) $open['id'] ) : 'New sign-ups cannot join right now: no campaign is open.' );
	}
}
