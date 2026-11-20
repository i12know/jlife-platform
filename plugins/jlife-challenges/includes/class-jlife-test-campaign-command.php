<?php
/**
 * WP-CLI: `wp jlife test-campaign create|remove [--dry-run] [--delete-accounts]|status`
 * (staging test drive).
 *
 * The invite code is never printed: it is emailed to the site admin address
 * and, optionally, saved to an owner-only file. Deploy logs may be public.
 *
 * @package jlife-challenges
 */

defined( 'ABSPATH' ) || exit;

/**
 * Manage the staging test campaign.
 */
class Jlife_Test_Campaign_Command {

	/**
	 * Create the test campaign (Day 1 = today) or issue a fresh invite for it,
	 * then email the join link to the site admin address.
	 *
	 * ## OPTIONS
	 *
	 * [--save-to=<file>]
	 * : Also write the join link to this file (mode 0600).
	 *
	 * @param array $args       Positional args.
	 * @param array $assoc_args Options.
	 */
	public function create( $args, $assoc_args ) {
		$actor = $this->organizer();
		$r     = jlife_challenges_create_test_campaign( $actor );
		if ( is_wp_error( $r ) ) {
			WP_CLI::error( $r->get_error_message() );
		}
		$link = add_query_arg( 'code', $r['invite_code'], home_url( '/join/' ) );
		WP_CLI::log(
			$r['created']
				? sprintf( 'Created the test campaign (Day 1 = %s) with a Test Group.', $r['start_date'] )
				: sprintf( 'The test campaign (Day 1 = %s) already exists; issued a new invite code (the old one stops working).', $r['start_date'] )
		);

		$sent = wp_mail(
			(string) get_option( 'admin_email' ),
			'[J-Life staging] Test Bible Marathon invite link',
			"Join the TEST Bible Marathon on this staging site:\n\n{$link}\n\n"
			. "Open it on your phone, join with your own email, and enter the 6-digit code you receive.\n"
			. "This is test data. Remove it before the real Marathon by running the deploy with \"remove test campaign\".\n"
		);
		WP_CLI::log( $sent ? 'Invite link emailed to the site admin address.' : 'WARNING: the invite email could not be sent.' );

		if ( ! empty( $assoc_args['save-to'] ) ) {
			$file = (string) $assoc_args['save-to'];
			$old  = umask( 077 );
			$ok   = file_put_contents( $file, $link . "\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- CLI-only, owner-only file.
			umask( $old );
			WP_CLI::log( false !== $ok ? 'Invite link also saved to the server file ' . basename( $file ) . '.' : 'WARNING: could not save the invite file.' );
		}
		if ( ! $sent && empty( $assoc_args['save-to'] ) ) {
			WP_CLI::error( 'The invite link was not delivered anywhere; run create again to issue a new one.' );
		}
		WP_CLI::success( 'Test campaign ready.' );
	}

	/**
	 * Remove every test campaign and its data, keeping every account (testers
	 * may be real participants, #174). Prints counts only, never people.
	 *
	 * ## OPTIONS
	 *
	 * [--dry-run]
	 * : Show what would be removed and change nothing.
	 *
	 * [--delete-accounts]
	 * : Also delete participant accounts that were only in test campaigns
	 * (staff and anyone in a real campaign are always kept). Only when every
	 * tester is known to be disposable; the latoc deploy never passes it.
	 *
	 * @param array $args       Positional args.
	 * @param array $assoc_args Options.
	 */
	public function remove( $args = array(), $assoc_args = array() ) {
		$actor  = $this->organizer();
		$delete = ! empty( $assoc_args['delete-accounts'] );
		$plan   = jlife_challenges_test_cleanup_preview( $actor, $delete );
		if ( is_wp_error( $plan ) ) {
			WP_CLI::error( $plan->get_error_message() );
		}
		$this->print_counts( $plan );
		if ( ! empty( $assoc_args['dry-run'] ) ) {
			WP_CLI::success( 'Dry run: nothing was changed.' );
			return;
		}
		$r = jlife_challenges_remove_test_campaigns( $actor, $delete );
		if ( is_wp_error( $r ) ) {
			WP_CLI::error( $r->get_error_message() );
		}
		WP_CLI::success( sprintf( 'Removed %d test campaign(s) and %d SOAP entries; deleted %d account(s), kept %d account(s).', $r['campaigns'], $r['entries'], $r['users'], $r['kept'] ) );
	}

	/**
	 * Print preview counts, one per line (no personal information).
	 *
	 * @param array $plan Counts from jlife_challenges_test_cleanup_preview().
	 */
	private function print_counts( $plan ) {
		foreach ( $plan as $what => $n ) {
			WP_CLI::log( sprintf( '  %-18s %d', $what, $n ) );
		}
	}

	/**
	 * Show whether a test campaign exists.
	 */
	public function status() {
		$ids = jlife_challenges_test_campaign_ids();
		WP_CLI::log( $ids ? sprintf( 'Test campaigns present: %d.', count( $ids ) ) : 'No test campaign.' );
		if ( $ids ) {
			$plan = jlife_challenges_test_cleanup_preview( $this->organizer() );
			if ( ! is_wp_error( $plan ) ) {
				WP_CLI::log( '"remove" would affect:' );
				$this->print_counts( $plan );
			}
		}
	}

	/**
	 * An organizer account to act as: the site admin email's user if it can
	 * manage the site, else the first administrator.
	 *
	 * @return int
	 */
	private function organizer() {
		return jlife_challenges_cli_organizer();
	}
}
