<?php
/**
 * WP-CLI: wp jlife scripture import|status|cache-purge|try (#139, #141). Never
 * prints Bible text, except `try`, which prints one passage so the owner can
 * check it before a version is enabled.
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
	 * Try one passage of one Bible version before it is enabled (#141): fetch it
	 * through the same source and shared cache the reader uses, and print the
	 * text, the copyright notice, the cache status and the HTTP status. The
	 * version may still be disabled (the override lasts only for this command).
	 * An uncached fetch counts as one API.Bible call; a cached passage costs
	 * none (`wp jlife scripture cache-purge --all` forces a fresh fetch). Nothing
	 * is reported to FUMS, and the API key is never printed.
	 *
	 * ## OPTIONS
	 *
	 * <version>
	 * : Registry version key: nlt, nkjv, csb (or any version with a text source).
	 *
	 * <reference>
	 * : A reference such as "John 3:16-18".
	 *
	 * [--metadata]
	 * : Also fetch the Bible's metadata (name, abbreviation, copyright notice): one more API call.
	 *
	 * ## EXAMPLES
	 *
	 *     wp jlife scripture try nlt "John 1:1-5"
	 *     wp jlife scripture try csb "Luke 3:23" --metadata
	 *
	 * @subcommand try
	 *
	 * @param array $args       Positional arguments: version, reference.
	 * @param array $assoc_args Options.
	 */
	public function try_passage( $args, $assoc_args ) {
		$r = jlife_scripture_try_passage( (string) ( $args[0] ?? '' ), (string) ( $args[1] ?? '' ), array( 'metadata' => ! empty( $assoc_args['metadata'] ) ) );
		if ( is_wp_error( $r ) ) {
			WP_CLI::error( $r->get_error_message() );
		}
		foreach ( $r['lines'] as $line ) {
			WP_CLI::log( $line );
		}
		if ( ! $r['ok'] ) {
			WP_CLI::error( 'The passage could not be loaded.' );
		}
		WP_CLI::success( 'Done.' );
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
