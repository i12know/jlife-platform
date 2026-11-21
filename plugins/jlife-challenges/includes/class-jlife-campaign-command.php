<?php
/**
 * WP-CLI: `wp jlife campaign close <id> [--dry-run]` (#179) and
 * `wp jlife campaign rollover --from=<id> --to=<id> --preview|--execute|--verify` (#180).
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

	/**
	 * Carry the people of one campaign (normally the closed pilot) and everyone
	 * who chose "I'll start Dec 20" into a campaign that has not started yet
	 * (the official Marathon), on this site. Keeps each person's user ID, age
	 * band and consent record; carries no entries, points, shares, groups or
	 * reminder choices. Safe to run again. Prints counts only.
	 *
	 * ## OPTIONS
	 *
	 * --from=<id>
	 * : Source campaign ID on this site.
	 *
	 * --to=<id>
	 * : Destination campaign ID on this site (active, not started, no entries or points).
	 *
	 * [--preview]
	 * : Show the counts and change nothing.
	 *
	 * [--execute]
	 * : Enroll the people the preview counts. Run it again to finish an interrupted run.
	 *
	 * [--verify]
	 * : Check the result. Exits with an error if anything is wrong.
	 *
	 * @param array $args       Positional args.
	 * @param array $assoc_args Options.
	 */
	public function rollover( $args, $assoc_args ) {
		unset( $args ); // WP-CLI always passes it; rollover takes no positional arguments.
		$modes = array_values( array_intersect( array( 'preview', 'execute', 'verify' ), array_keys( array_filter( (array) $assoc_args ) ) ) );
		if ( 1 !== count( $modes ) ) {
			WP_CLI::error( 'Give exactly one of --preview, --execute or --verify.' );
		}
		$actor = jlife_challenges_cli_organizer();
		$from  = (int) ( $assoc_args['from'] ?? 0 );
		$to    = (int) ( $assoc_args['to'] ?? 0 );
		$mode  = $modes[0];
		if ( 'preview' === $mode ) {
			$r = jlife_challenges_rollover_preview( $actor, $from, $to );
		} elseif ( 'execute' === $mode ) {
			$r = jlife_challenges_rollover_execute( $actor, $from, $to );
		} else {
			$r = jlife_challenges_rollover_verify( $actor, $from, $to );
		}
		if ( is_wp_error( $r ) ) {
			WP_CLI::error( $r->get_error_message() );
		}
		$this->print_campaigns( $from, $to );
		foreach ( $r as $what => $n ) {
			if ( is_int( $n ) ) {
				WP_CLI::log( sprintf( '  %-32s %d', $what, $n ) );
			}
		}
		if ( 'preview' === $mode ) {
			WP_CLI::success( 'Preview: nothing was changed.' );
		} elseif ( 'execute' === $mode ) {
			WP_CLI::success( sprintf( 'Enrolled %d; %d were already enrolled.', $r['enrolled_now'], $r['already_enrolled'] ) );
		} elseif ( $r['ok'] ) {
			WP_CLI::success( 'Verified: every expected person is enrolled once, the destination has no entries, points or shares, and the run left the source unchanged.' );
		} else {
			WP_CLI::error( 'Verify failed: ' . implode( ', ', $this->verify_problems( $r ) ) . '.' );
		}
	}

	/**
	 * Print the two campaigns (keys, phases and dates only).
	 *
	 * @param int $from Source campaign ID.
	 * @param int $to   Destination campaign ID.
	 */
	private function print_campaigns( $from, $to ) {
		$f = (array) jlife_challenges_get_campaign( $from );
		$t = (array) jlife_challenges_get_campaign( $to );
		WP_CLI::log( sprintf( 'From: %s (id %d), %s.', $f['campaign_key'], $from, jlife_challenges_campaign_phase( $f ) ) );
		WP_CLI::log( sprintf( 'To:   %s (id %d), %s, %s to %s.', $t['campaign_key'], $to, jlife_challenges_campaign_phase( $t ), $t['start_date'], $t['end_date'] ) );
		if ( 'closed' !== jlife_challenges_campaign_phase( $f ) ) {
			WP_CLI::log( 'Note: the source is not closed yet. People who join it later are carried only by another --execute.' );
		}
	}

	/**
	 * What a failed verify found, as short phrases (no people).
	 *
	 * @param array $r Verify result.
	 * @return string[]
	 */
	private function verify_problems( $r ) {
		$checks = array(
			'executed'                => 'no execute has been run for these two campaigns',
			'missing'                 => 'expected people not enrolled',
			'duplicate_enrollments'   => 'duplicate enrollments',
			'destination_ledger_rows' => 'the destination has points',
			'destination_entries'     => 'the destination has SOAP entries',
			'destination_shares'      => 'the destination has shares',
			'source_changed_by_run'   => 'the last run changed the source',
		);
		$out    = array();
		foreach ( $checks as $key => $phrase ) {
			$bad = 'executed' === $key ? 0 === $r[ $key ] : $r[ $key ] > 0;
			if ( $bad ) {
				$out[] = $phrase;
			}
		}
		return $out;
	}
}
