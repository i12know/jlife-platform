<?php
/**
 * WP-CLI: `wp jlife test-campaign create|remove|status` (staging test drive).
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
	 * Remove every test campaign and its data, and delete participant
	 * accounts that were only in test campaigns.
	 */
	public function remove() {
		$r = jlife_challenges_remove_test_campaigns( $this->organizer() );
		if ( is_wp_error( $r ) ) {
			WP_CLI::error( $r->get_error_message() );
		}
		WP_CLI::success( sprintf( 'Removed %d test campaign(s), %d SOAP entries and %d test-only participant account(s).', $r['campaigns'], $r['entries'], $r['users'] ) );
	}

	/**
	 * Show whether a test campaign exists.
	 */
	public function status() {
		$ids = jlife_challenges_test_campaign_ids();
		WP_CLI::log( $ids ? sprintf( 'Test campaigns present: %d.', count( $ids ) ) : 'No test campaign.' );
	}

	/**
	 * An organizer account to act as: the site admin email's user if it can
	 * manage the site, else the first administrator.
	 *
	 * @return int
	 */
	private function organizer() {
		$user = get_user_by( 'email', (string) get_option( 'admin_email' ) );
		if ( $user && jlife_challenges_is_organizer( $user->ID ) ) {
			return (int) $user->ID;
		}
		foreach ( get_users(
			array(
				'role'   => 'administrator',
				'fields' => 'ID',
				'number' => 1,
			)
		) as $id ) {
			return (int) $id;
		}
		foreach ( get_super_admins() as $login ) {
			$u = get_user_by( 'login', $login );
			if ( $u ) {
				return (int) $u->ID;
			}
		}
		WP_CLI::error( 'No administrator account found.' );
		return 0;
	}
}
