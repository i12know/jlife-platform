<?php
/**
 * WP-CLI: `wp jlife photos export --user=<id> [--campaign=<id>]` (#244, owner
 * decision 4): the owner's review of photo-earned days is a download, not a screen.
 *
 * Prints counts and the zip's path only, never a name, an email or what is in a
 * photo: deploy logs may be public.
 *
 * @package jlife-challenges
 */

defined( 'ABSPATH' ) || exit;

/**
 * Review photo reflections by download.
 */
class Jlife_Photos_Command {

	/**
	 * Write one participant's photo-earned days to a zip in the owner-only folder
	 * ~/.latoc/ (mode 0600): dNNN.jpg for each day plus days.csv (campaign, day,
	 * date, upload time, submission type, file). Download it with cPanel File
	 * Manager, then delete it. Prints counts and the path only.
	 *
	 * ## OPTIONS
	 *
	 * --user=<id>
	 * : The participant's user ID on this site.
	 *
	 * [--campaign=<id>]
	 * : Only this campaign (default: every campaign the person earned a photo day in).
	 *
	 * [--dir=<folder>]
	 * : Write the zip here instead of ~/.latoc.
	 *
	 * @param array $args       Positional args.
	 * @param array $assoc_args Options.
	 */
	public function export( $args, $assoc_args ) {
		unset( $args ); // WP-CLI always passes it; export takes no positional arguments.
		$user_id = (int) ( $assoc_args['user'] ?? 0 );
		if ( $user_id < 1 ) {
			WP_CLI::error( 'Give --user=<id>.' );
		}
		$r = jlife_challenges_photos_export( jlife_challenges_cli_organizer(), $user_id, (int) ( $assoc_args['campaign'] ?? 0 ), (string) ( $assoc_args['dir'] ?? '' ) );
		if ( is_wp_error( $r ) ) {
			WP_CLI::error( $r->get_error_message() );
		}
		WP_CLI::log( sprintf( '  %-20s %d', 'photo_days', $r['days'] ) );
		WP_CLI::log( sprintf( '  %-20s %d', 'photos_in_zip', $r['photos'] ) );
		WP_CLI::log( sprintf( '  %-20s %d', 'photos_removed', $r['missing'] ) );
		WP_CLI::success( 'Wrote ' . $r['path'] . ' (mode 0600). Download it, then delete it.' );
	}
}
