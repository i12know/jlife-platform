<?php
/**
 * WP-CLI: `wp jlife bible usage|apibible-list` (#141).
 *
 * Output never contains the API key or Bible text: usage counts, and Bible
 * metadata (ID, abbreviation, name, language) only.
 *
 * @package jlife-challenges
 */

defined( 'ABSPATH' ) || exit;

/**
 * Scripture sources.
 */
class Jlife_Bible_Command {

	/**
	 * Show this month's API.Bible calls against the plan's 5,000 a month.
	 *
	 * ## EXAMPLES
	 *
	 *     wp jlife bible usage
	 */
	public function usage() {
		$usage = jlife_challenges_apibible_usage();
		$state = jlife_challenges_apibible_usage_state( $usage['calls'] );
		WP_CLI::log( 'Month (UTC): ' . $usage['month'] );
		WP_CLI::log( sprintf( 'API.Bible calls: %d of %d', $usage['calls'], JLIFE_CHALLENGES_APIBIBLE_MONTHLY_LIMIT ) );
		WP_CLI::log( sprintf( 'FUMS reports: %d', $usage['fums'] ) );
		WP_CLI::log( 'Key on this host: ' . ( '' !== jlife_challenges_apibible_key() ? 'yes' : 'no' ) );
		if ( 'over' === $state ) {
			WP_CLI::warning( 'The monthly limit is reached: API.Bible versions show as links until next month.' );
		} elseif ( 'near' === $state ) {
			WP_CLI::warning( sprintf( 'Close to the monthly limit (warning from %d calls). Check that passages are cached.', JLIFE_CHALLENGES_APIBIBLE_WARN_AT ) );
		}
	}

	/**
	 * List the Bibles this API.Bible account can use, to record their bibleIds.
	 * Makes one API call (counted in `wp jlife bible usage`).
	 *
	 * ## OPTIONS
	 *
	 * [--language=<code>]
	 * : ISO 639-3 language code, e.g. eng or vie. Default: all.
	 *
	 * [--format=<format>]
	 * : table, csv or json. Default table.
	 *
	 * ## EXAMPLES
	 *
	 *     wp jlife bible apibible-list --language=eng
	 *
	 * @subcommand apibible-list
	 *
	 * @param array $args       Positional args.
	 * @param array $assoc_args Options.
	 */
	public function apibible_list( $args, $assoc_args ) {
		unset( $args );
		$rows = jlife_challenges_apibible_list_bibles( (string) ( $assoc_args['language'] ?? '' ) );
		if ( is_wp_error( $rows ) ) {
			WP_CLI::error( $rows->get_error_message() );
		}
		$wanted = array( 'NLT', 'NKJV', 'CSB' );
		foreach ( $rows as $i => $row ) {
			$rows[ $i ]['candidate'] = in_array( strtoupper( $row['abbreviation'] ), $wanted, true ) ? 'yes' : '';
		}
		WP_CLI\Utils\format_items( (string) ( $assoc_args['format'] ?? 'table' ), $rows, array( 'id', 'abbreviation', 'name', 'language', 'candidate' ) );
		WP_CLI::log( sprintf( '%d Bibles. Record the id of each NLT/NKJV/CSB edition in content/reading-plans/bible-provider-registry.json.', count( $rows ) ) );
	}
}
