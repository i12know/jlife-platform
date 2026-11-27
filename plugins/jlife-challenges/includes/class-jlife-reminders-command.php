<?php
/**
 * WP-CLI: `wp jlife reminders status|preview|run|mode|recipients` (#63 Phase B).
 *
 * Output never contains an email address, a message body for a real person or a
 * credential. Preview writes for a made-up recipient.
 *
 * @package jlife-challenges
 */

defined( 'ABSPATH' ) || exit;

/**
 * Daily reading reminders.
 */
class Jlife_Reminders_Command {

	/**
	 * Show the mode, today's counts by status, and the next scheduled run.
	 *
	 * ## OPTIONS
	 *
	 * [--campaign=<id>]
	 * : Campaign ID. Default: every active campaign.
	 *
	 * @param array $args       Positional args.
	 * @param array $assoc_args Options.
	 */
	public function status( $args, $assoc_args ) {
		$campaigns = $this->campaigns( $assoc_args );
		WP_CLI::log( 'Mode: ' . jlife_challenges_reminders_mode() );
		if ( 'test' === jlife_challenges_reminders_mode() ) {
			WP_CLI::log( sprintf( 'Test recipients defined: %d', count( jlife_challenges_reminder_test_recipients() ) ) );
		}
		$next = (int) wp_next_scheduled( JLIFE_CHALLENGES_REMINDERS_HOOK );
		WP_CLI::log( $next ? 'Next scheduled tick (UTC): ' . gmdate( 'Y-m-d H:i:s', $next ) . ( $next < time() ? ' (overdue: is the server cron running?)' : '' ) : 'Next scheduled tick: none (the event is not scheduled)' );
		foreach ( $campaigns as $campaign ) {
			$s = jlife_challenges_reminders_status( $campaign );
			WP_CLI::log( '' );
			WP_CLI::log( sprintf( 'Campaign %d (%s)', $s['campaign_id'], $s['title'] ) );
			WP_CLI::log( '  Day: ' . ( $s['day'] ? $s['day'] . ' (' . $s['day_key'] . ')' : 'outside the campaign' ) );
			WP_CLI::log( '  Send window open now: ' . ( $s['window_open'] ? 'yes' : 'no' ) . ' (06:00 to before 12:00, ' . $campaign['timezone'] . ')' );
			WP_CLI::log( '  Current recipients: ' . $s['recipients'] );
			foreach ( $s['counts'] as $status => $n ) {
				WP_CLI::log( sprintf( '  %-9s %d', $status, $n ) );
			}
		}
	}

	/**
	 * Print the subject and text for a day, for a made-up recipient. Sends nothing.
	 *
	 * ## OPTIONS
	 *
	 * --day=<n>
	 * : Campaign day, 1 to the last day.
	 *
	 * [--locale=<locale>]
	 * : en_US or vi. Default en_US.
	 *
	 * [--campaign=<id>]
	 * : Campaign ID. Default: the first active campaign.
	 *
	 * [--html]
	 * : Print the HTML part too.
	 *
	 * @param array $args       Positional args.
	 * @param array $assoc_args Options.
	 */
	public function preview( $args, $assoc_args ) {
		$campaigns = $this->campaigns( $assoc_args );
		if ( ! $campaigns ) {
			WP_CLI::error( 'No active campaign.' );
		}
		$day    = isset( $assoc_args['day'] ) ? (int) $assoc_args['day'] : 0;
		$locale = jlife_challenges_reminder_locale( isset( $assoc_args['locale'] ) ? (string) $assoc_args['locale'] : 'en_US' );
		$p      = jlife_challenges_reminder_preview( $campaigns[0], $day, $locale );
		if ( null === $p ) {
			WP_CLI::error( sprintf( 'No reading for day %d (check --day and that the reading plan is imported).', $day ) );
		}
		WP_CLI::log( 'Language: ' . $locale . ' (made-up recipient; nothing is sent or logged)' );
		WP_CLI::log( 'Subject: ' . $p['subject'] );
		WP_CLI::log( '' );
		WP_CLI::log( $p['text'] );
		if ( isset( $assoc_args['html'] ) ) {
			WP_CLI::log( '--- HTML ---' );
			WP_CLI::log( $p['html'] );
		}
	}

	/**
	 * Run one scheduler tick now (the same work the cron event does).
	 */
	public function run() {
		$r = jlife_challenges_reminders_tick();
		WP_CLI::success( sprintf( 'Tick done in mode %s: %d queued, %d handled.', $r['mode'], $r['queued'], $r['handled'] ) );
	}

	/**
	 * Set the sending mode: off, dry_run, test or live.
	 *
	 * ## OPTIONS
	 *
	 * <mode>
	 * : off | dry_run | test | live
	 *
	 * @param array $args Positional args.
	 */
	public function mode( $args ) {
		$r = jlife_challenges_reminders_set_mode( (string) ( $args[0] ?? '' ) );
		if ( is_wp_error( $r ) ) {
			WP_CLI::error( $r->get_error_message() );
		}
		WP_CLI::success( 'Mode is now ' . jlife_challenges_reminders_mode() . '.' );
	}

	/**
	 * List who gets the daily reminder, who is left out and why, and who unsubscribed. Read-only.
	 *
	 * Shows user IDs, display names, language and dates. Never an email address,
	 * a reflection, a group name or a score. To look into one complaint, pass
	 * --email: the person is found by address and only their rows are shown.
	 *
	 * ## OPTIONS
	 *
	 * [--campaign=<id>]
	 * : Campaign ID. Default: every active campaign.
	 *
	 * [--email=<address>]
	 * : Show only the person with this account email (the address is not printed).
	 *
	 * [--format=<format>]
	 * : table (default, one table per section), csv or json (one list with a section column).
	 *
	 * @param array $args       Positional args.
	 * @param array $assoc_args Options.
	 */
	public function recipients( $args, $assoc_args ) {
		$campaigns = $this->campaigns( $assoc_args );
		$format    = isset( $assoc_args['format'] ) ? (string) $assoc_args['format'] : 'table';
		if ( ! in_array( $format, array( 'table', 'csv', 'json' ), true ) ) {
			WP_CLI::error( 'Format must be table, csv or json.' );
		}
		$user_id = 0;
		if ( isset( $assoc_args['email'] ) ) {
			$user_id = jlife_challenges_reminder_recipients_user_id( (string) $assoc_args['email'] );
			if ( $user_id < 1 ) {
				WP_CLI::error( 'No account has that email address.' );
			}
		}
		$items = array();
		foreach ( $campaigns as $campaign ) {
			$report = jlife_challenges_reminder_recipients_report( $campaign, $user_id );
			if ( 'table' !== $format ) {
				$items = array_merge( $items, jlife_challenges_reminder_recipients_items( $report ) );
				continue;
			}
			WP_CLI::log( sprintf( 'Campaign %d (%s). Mode: %s. Today: %s.', $report['campaign_id'], $report['title'], $report['mode'], '' !== $report['day_key'] ? $report['day_key'] : 'outside the campaign' ) );
			foreach ( $report['sections'] as $section ) {
				WP_CLI::log( '' );
				WP_CLI::log( $section['title'] );
				if ( $section['rows'] ) {
					\WP_CLI\Utils\format_items( 'table', $section['rows'], $section['fields'] );
				}
				WP_CLI::log( $section['total'] );
			}
			WP_CLI::log( '' );
		}
		if ( 'table' !== $format ) {
			\WP_CLI\Utils\format_items( $format, $items, array( 'campaign_id', 'section', 'user_id', 'display_name', 'language', 'opted_in_utc', 'opted_out_utc', 'today', 'reason' ) );
		}
	}

	/**
	 * Campaigns to report on.
	 *
	 * @param array $assoc_args Options (campaign).
	 * @return array[] Campaign rows.
	 */
	private function campaigns( $assoc_args ) {
		if ( isset( $assoc_args['campaign'] ) ) {
			$c = jlife_challenges_get_campaign( (int) $assoc_args['campaign'] );
			if ( ! $c ) {
				WP_CLI::error( 'No such campaign.' );
			}
			return array( $c );
		}
		return jlife_challenges_reminders_active_campaigns();
	}
}
