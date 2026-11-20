<?php
/**
 * WP-CLI: `wp jlife campaign close <id> [--dry-run]` (#179).
 *
 * Prints counts only, never people: deploy logs may be public.
 *
 * @package jlife-challenges
 */

defined( 'ABSPATH' ) || exit;

/**
 * Manage campaigns on this site.
 */
class Jlife_Campaign_Command {

	/**
	 * Close a campaign: no more entries, shares, points, group joins or
	 * reminder emails; queued reminders are cancelled. Everyone keeps reading
	 * what they could read before. Safe to run twice.
	 *
	 * ## OPTIONS
	 *
	 * <id>
	 * : The campaign ID on this site.
	 *
	 * [--dry-run]
	 * : Show the counts and change nothing.
	 *
	 * @param array $args       Positional args.
	 * @param array $assoc_args Options.
	 */
	public function close( $args, $assoc_args ) {
		$actor       = jlife_challenges_cli_organizer();
		$campaign_id = (int) ( $args[0] ?? 0 );
		$plan        = jlife_challenges_close_preview( $actor, $campaign_id );
		if ( is_wp_error( $plan ) ) {
			WP_CLI::error( $plan->get_error_message() );
		}
		foreach ( $plan as $what => $n ) {
			WP_CLI::log( sprintf( '  %-20s %d', $what, $n ) );
		}
		if ( ! empty( $assoc_args['dry-run'] ) ) {
			WP_CLI::success( 'Dry run: nothing was changed.' );
			return;
		}
		$r = jlife_challenges_close_campaign( $actor, $campaign_id );
		if ( is_wp_error( $r ) ) {
			WP_CLI::error( $r->get_error_message() );
		}
		WP_CLI::success(
			$r['changed']
				? sprintf( 'Closed campaign %d; cancelled %d queued reminder(s).', $campaign_id, $r['reminders_cancelled'] )
				: sprintf( 'Campaign %d was already closed; cancelled %d queued reminder(s).', $campaign_id, $r['reminders_cancelled'] )
		);
	}
}
