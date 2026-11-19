<?php
/**
 * WP-CLI: wp jlife scripture import|status|cache-purge (#139). Never prints
 * Bible text.
 *
 * @package jlife-scripture
 */

defined( 'ABSPATH' ) || exit;

/**
 * Manage the on-page Scripture reader's data.
 */
class Jlife_Scripture_Command {

	/**
	 * Import a public-domain dataset into the verses table, verifying every
	 * file against its manifest and recording the source URL, edition and
	 * checksums. Unchanged data is skipped.
	 *
	 * ## OPTIONS
	 *
	 * [<dataset>]
	 * : Dataset id under the plugin's data/ folder.
	 * ---
	 * default: vie1934
	 * ---
	 *
	 * [--dir=<dir>]
	 * : Read the dataset from this folder instead (manifest.json + <BOOK>.tsv).
	 *
	 * [--force]
	 * : Re-import even when the checksum matches the last import.
	 *
	 * ## EXAMPLES
	 *
	 *     wp jlife scripture import
	 *     wp jlife scripture import vie1934 --force
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Options.
	 */
	public function import( $args, $assoc_args ) {
		jlife_scripture_install_tables();
		$dataset = (string) ( $args[0] ?? 'vie1934' );
		$opts    = array( 'force' => ! empty( $assoc_args['force'] ) );
		if ( ! empty( $assoc_args['dir'] ) ) {
			$opts['dir'] = (string) $assoc_args['dir'];
		}
		$r = jlife_scripture_import_dataset( $dataset, $opts );
		if ( is_wp_error( $r ) ) {
			WP_CLI::error( $r->get_error_message() );
		}
		WP_CLI::success( sprintf( '%s: %s (%d verses, dataset sha256 %s).', $dataset, $r['status'], $r['verses'], $r['data_sha256'] ) );
	}

	/**
	 * Show the feature flag, each language's version, and imported datasets.
	 *
	 * ## EXAMPLES
	 *
	 *     wp jlife scripture status
	 *
	 * @subcommand status
	 */
	public function status() {
		WP_CLI::log( 'On-page reader (jlife_scripture_native_reader): ' . ( jlife_scripture_reader_enabled() ? 'on' : 'off' ) );
		foreach ( array_keys( jlife_scripture_registry()['languages'] ) as $lang ) {
			$c = jlife_scripture_reader_choice( $lang );
			WP_CLI::log(
				sprintf(
					'%s: default %s (%s), fallback link %s',
					$lang,
					$c['version'] ? $c['version']['key'] : '-',
					$c['native'] ? 'shows text' : 'link only',
					$c['fallback'] ? $c['fallback']['source_id'] : '-'
				)
			);
		}
		$row = jlife_scripture_dataset_status( 'vie1934' );
		WP_CLI::log( $row ? sprintf( 'vie1934: %d verses, imported %s UTC, edition: %s, source sha256 %s', $row['verse_count'], $row['imported'], $row['edition'], $row['source_sha256'] ) : 'vie1934: not imported' );
	}

	/**
	 * Delete cached passages past their version's max age.
	 *
	 * ## OPTIONS
	 *
	 * [--all]
	 * : Delete every cached passage.
	 *
	 * @subcommand cache-purge
	 *
	 * @param array $args       Positional arguments (none).
	 * @param array $assoc_args Options.
	 */
	public function cache_purge( $args, $assoc_args ) {
		unset( $args );
		WP_CLI::success( sprintf( '%d cached passages deleted.', jlife_scripture_cache_purge( ! empty( $assoc_args['all'] ) ) ) );
	}
}
