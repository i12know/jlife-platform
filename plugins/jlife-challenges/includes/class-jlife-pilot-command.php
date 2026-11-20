<?php
/**
 * WP-CLI: `wp jlife pilot create [--key=<key>]` and `wp jlife pilot status`
 * (the Dec 5–13 pilot campaign, #178).
 *
 * Prints counts and dates only, never people: deploy logs may be public.
 *
 * @package jlife-challenges
 */

defined( 'ABSPATH' ) || exit;

/**
 * Manage the pilot campaign.
 */
class Jlife_Pilot_Command {

	/**
	 * Create the pilot campaign (Dec 5–13, 2026, Pacific time, active), or
	 * report that it already exists. Safe to run again. Creates no groups and
	 * enrolls no one. The key must start with "pilot-" (never "test-").
	 *
	 * ## OPTIONS
	 *
	 * [--key=<key>]
	 * : Campaign key. Default: pilot-2026-12.
	 *
	 * @param array $args       Positional args.
	 * @param array $assoc_args Options.
	 */
	public function create( $args, $assoc_args ) {
		$input = array();
		if ( ! empty( $assoc_args['key'] ) ) {
			$input['campaign_key'] = (string) $assoc_args['key'];
		}
		$r = jlife_challenges_create_pilot_campaign( $this->organizer(), $input );
		if ( is_wp_error( $r ) ) {
			WP_CLI::error( $r->get_error_message() );
		}
		WP_CLI::log(
			$r['created']
				? sprintf( 'Created the pilot campaign %s (%s to %s).', $r['campaign_key'], $r['start_date'], $r['end_date'] )
				: sprintf( 'The pilot campaign %s already exists (%s to %s); nothing changed.', $r['campaign_key'], $r['start_date'], $r['end_date'] )
		);
		WP_CLI::success( 'Pilot campaign ready.' );
	}

	/**
	 * Show the pilot campaign(s): key, dates, phase, enrollments and how many
	 * people chose "I'll start Dec 20". Counts only.
	 *
	 * @param array $args       Positional args.
	 * @param array $assoc_args Options.
	 */
	public function status( $args, $assoc_args ) {
		unset( $args, $assoc_args ); // WP-CLI always passes them; status takes no options.
		global $wpdb;
		$t    = jlife_challenges_table( 'campaigns' );
		$e    = jlife_challenges_table( 'enrollments' );
		$rows = (array) $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$t} WHERE campaign_key LIKE %s ORDER BY id", $wpdb->esc_like( JLIFE_CHALLENGES_PILOT_PREFIX ) . '%' ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- custom table; name from $wpdb->prefix.
		if ( ! $rows ) {
			WP_CLI::log( 'No pilot campaign yet. Run: wp jlife pilot create' );
		}
		foreach ( $rows as $c ) {
			$n = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$e} WHERE campaign_id = %d", (int) $c['id'] ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- custom table; name from $wpdb->prefix.
			WP_CLI::log( sprintf( '%s: %s to %s, status %s, phase %s, enrollments %d.', $c['campaign_key'], $c['start_date'], $c['end_date'], $c['status'], jlife_challenges_campaign_phase( $c ), $n ) );
		}
		WP_CLI::log( sprintf( 'Chose "I\'ll start Dec 20": %d.', count( jlife_challenges_waiting_for_official_ids() ) ) );
	}

	/**
	 * The acting organizer.
	 *
	 * @return int
	 */
	private function organizer() {
		return jlife_challenges_cli_organizer();
	}
}
