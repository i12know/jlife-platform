<?php
/**
 * WP-CLI command for importing/exporting portable study content (S6, #13).
 *
 * @package jlife-studies
 */

defined( 'ABSPATH' ) || exit;

/**
 * Imports and exports /content series and lesson files.
 */
class Jlife_Content_Command {

	/**
	 * Import series/lesson JSON files into posts.
	 *
	 * ## OPTIONS
	 *
	 * <file>...
	 * : One or more schema JSON files (series or lesson).
	 *
	 * ## EXAMPLES
	 *
	 *     wp jlife content import content/schemas/examples/example-series.json
	 *
	 * @param array $args Positional args: file paths.
	 */
	public function import( $args ) {
		foreach ( $args as $file ) {
			if ( ! file_exists( $file ) ) {
				WP_CLI::error( "File not found: {$file}" );
			}
			$doc = json_decode( file_get_contents( $file ), true ); // phpcs:ignore WordPress.WP.AlternativeFunctions -- local file, not remote.
			if ( null === $doc ) {
				WP_CLI::error( "Invalid JSON: {$file}" );
			}
			$post_id = jlife_studies_import_document( $doc );
			if ( is_wp_error( $post_id ) ) {
				WP_CLI::error( "{$file}: " . $post_id->get_error_message() );
			}
			$type      = jlife_studies_document_type( $doc );
			$stable_id = 'lesson' === $type ? $doc['lesson_id'] : $doc['series_id'];
			WP_CLI::success( "Imported {$type} {$stable_id} as post {$post_id}." );
		}
	}

	/**
	 * Export series/lesson posts back to schema JSON files.
	 *
	 * ## OPTIONS
	 *
	 * --dir=<dir>
	 * : Output directory. Files are named <series_id>.json / <lesson_id>.json.
	 *
	 * [--id=<stable-id>]
	 * : Export only the post with this series_id/lesson_id. Default: all.
	 *
	 * ## EXAMPLES
	 *
	 *     wp jlife content export --dir=/tmp/export
	 *
	 * @param array $args       Positional args (unused).
	 * @param array $assoc_args Named args: dir, id.
	 */
	public function export( $args, $assoc_args ) {
		$dir = rtrim( $assoc_args['dir'], '/' );
		if ( ! is_dir( $dir ) && ! mkdir( $dir, 0755, true ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions -- CLI tooling writing outside WP uploads.
			WP_CLI::error( "Cannot create directory: {$dir}" );
		}

		$posts = get_posts(
			array(
				'post_type'      => array( 'jlife_series', 'jlife_lesson' ),
				'post_status'    => 'any',
				'posts_per_page' => -1,
				'orderby'        => 'ID',
				'order'          => 'ASC',
			)
		);

		$wanted = isset( $assoc_args['id'] ) ? $assoc_args['id'] : null;
		$count  = 0;
		foreach ( $posts as $post ) {
			$doc = jlife_studies_export_document( $post->ID );
			if ( is_wp_error( $doc ) ) {
				WP_CLI::error( $doc->get_error_message() );
			}
			$type      = jlife_studies_document_type( $doc );
			$stable_id = 'lesson' === $type ? $doc['lesson_id'] : $doc['series_id'];
			if ( null !== $wanted && $stable_id !== $wanted ) {
				continue;
			}
			$path = "{$dir}/{$stable_id}.json";
			file_put_contents( $path, jlife_studies_serialize_document( $doc ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions -- CLI tooling writing outside WP uploads.
			WP_CLI::log( "Exported {$type} {$stable_id} -> {$path}" );
			$count++;
		}
		WP_CLI::success( "Exported {$count} document(s)." );
	}
}
