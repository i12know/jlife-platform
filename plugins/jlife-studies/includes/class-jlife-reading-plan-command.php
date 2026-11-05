<?php
/**
 * WP-CLI command for importing reading plans (#59).
 *
 * @package jlife-studies
 */

defined( 'ABSPATH' ) || exit;

/**
 * Imports content/reading-plans/*.json (built by bin/build-reading-plans.js).
 */
class Jlife_Reading_Plan_Command {

	/**
	 * Import reading-plan JSON files. Primary plans are imported before
	 * supplemental ones, whatever order the files are given in.
	 *
	 * ## OPTIONS
	 *
	 * <file>...
	 * : One or more reading-plan JSON files.
	 *
	 * ## EXAMPLES
	 *
	 *     wp jlife reading-plan import content/reading-plans/*.json
	 *
	 * @param array $args Positional args: file paths.
	 */
	public function import( $args ) {
		$docs = array();
		foreach ( $args as $file ) {
			if ( ! file_exists( $file ) ) {
				WP_CLI::error( "File not found: {$file}" );
			}
			$raw = file_get_contents( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions -- local file, not remote.
			$doc = is_string( $raw ) ? json_decode( $raw, true ) : null;
			if ( ! is_array( $doc ) ) {
				WP_CLI::error( "Invalid JSON: {$file}" );
			}
			$docs[] = array( $file, $doc );
		}
		usort(
			$docs,
			static function ( $a, $b ) {
				return ( 'primary' === ( $b[1]['role'] ?? '' ) ) <=> ( 'primary' === ( $a[1]['role'] ?? '' ) );
			}
		);
		foreach ( $docs as list( $file, $doc ) ) {
			$result = jlife_studies_import_reading_plan( $doc );
			if ( is_wp_error( $result ) ) {
				WP_CLI::error( "{$file}: " . $result->get_error_message() );
			}
			WP_CLI::success( "Imported {$doc['role']} plan {$doc['plan_id']}: {$result} days." );
		}
	}
}
