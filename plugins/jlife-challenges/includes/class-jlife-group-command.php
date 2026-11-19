<?php
/**
 * WP-CLI: `wp jlife group list|rename|disable|enable` (#112 part 2).
 *
 * The organizer's oversight of every group, including ones participants
 * started. It never prints invite codes or member names.
 *
 * @package jlife-challenges
 */

defined( 'ABSPATH' ) || exit;

/**
 * Oversee groups: list, rename, disable, enable.
 */
class Jlife_Group_Command {

	/**
	 * List groups with their status, who started them (user ID) and how many
	 * active members they have.
	 *
	 * ## OPTIONS
	 *
	 * [--campaign=<id>]
	 * : Only this campaign's groups.
	 *
	 * [--format=<format>]
	 * : table (default), csv, json.
	 *
	 * ## EXAMPLES
	 *
	 *     wp jlife group list --campaign=1
	 *
	 * @subcommand list
	 *
	 * @param array $args       Positional args.
	 * @param array $assoc_args Options.
	 */
	public function list_( $args, $assoc_args ) {
		$rows = jlife_challenges_list_groups( jlife_challenges_cli_organizer(), (int) ( $assoc_args['campaign'] ?? 0 ) );
		if ( is_wp_error( $rows ) ) {
			WP_CLI::error( $rows->get_error_message() );
		}
		WP_CLI\Utils\format_items( (string) ( $assoc_args['format'] ?? 'table' ), $rows, array( 'id', 'campaign_id', 'name', 'status', 'started_by', 'members', 'created' ) );
	}

	/**
	 * Rename a group (1 to 60 characters).
	 *
	 * ## OPTIONS
	 *
	 * <id>
	 * : Group ID.
	 *
	 * <name>
	 * : New name.
	 *
	 * @param array $args Positional args.
	 */
	public function rename( $args ) {
		$r = jlife_challenges_rename_group( jlife_challenges_cli_organizer(), (int) $args[0], (string) $args[1] );
		is_wp_error( $r ) ? WP_CLI::error( $r->get_error_message() ) : WP_CLI::success( 'Renamed.' );
	}

	/**
	 * Disable a group: its code stops working and its members lose its feed.
	 * Nothing is deleted; `enable` restores it.
	 *
	 * ## OPTIONS
	 *
	 * <id>
	 * : Group ID.
	 *
	 * @param array $args Positional args.
	 */
	public function disable( $args ) {
		$r = jlife_challenges_disable_group( jlife_challenges_cli_organizer(), (int) $args[0] );
		is_wp_error( $r ) ? WP_CLI::error( $r->get_error_message() ) : WP_CLI::success( 'Disabled.' );
	}

	/**
	 * Enable a disabled group (refused if a member would exceed the group limit).
	 *
	 * ## OPTIONS
	 *
	 * <id>
	 * : Group ID.
	 *
	 * @param array $args Positional args.
	 */
	public function enable( $args ) {
		$r = jlife_challenges_enable_group( jlife_challenges_cli_organizer(), (int) $args[0] );
		is_wp_error( $r ) ? WP_CLI::error( $r->get_error_message() ) : WP_CLI::success( 'Enabled.' );
	}
}
