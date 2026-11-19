<?php
/**
 * Import a committed public-domain dataset into the verses table (#139).
 *
 * The dataset lives in data/<dataset>/: one "<USFM book>.tsv" per book
 * ("chapter<TAB>verse<TAB>text" per line) and manifest.json with the source
 * URL, edition, the source zip's SHA-256, per-file SHA-256s and a dataset
 * checksum. Every file is verified before anything is written; the dataset's
 * provenance row records what was imported. Re-running with the same data is
 * a no-op. Built by bin/build-scripture-vie1934.js.
 *
 * @package jlife-scripture
 */

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- the plugin's own tables and data files.

/**
 * The shipped directory of a dataset.
 *
 * @param string $dataset Dataset id, e.g. "vie1934".
 * @return string
 */
function jlife_scripture_dataset_dir( $dataset ) {
	return dirname( JLIFE_SCRIPTURE_FILE ) . '/data/' . sanitize_key( (string) $dataset );
}

/**
 * The provenance row of an imported dataset, or null.
 *
 * @param string $dataset Dataset id.
 * @return array|null
 */
function jlife_scripture_dataset_status( $dataset ) {
	global $wpdb;
	$table = jlife_scripture_table( 'datasets' );
	$row   = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE dataset = %s", (string) $dataset ), ARRAY_A );
	return is_array( $row ) ? $row : null;
}

/**
 * Read and verify a dataset directory without writing anything.
 *
 * @param string $dataset Dataset id.
 * @param string $dir     Directory with manifest.json and the book files.
 * @return array|WP_Error { manifest, books: array<string, list<array{0:int,1:int,2:string}>> }
 */
function jlife_scripture_read_dataset( $dataset, $dir ) {
	$fail = static fn( $why ) => new WP_Error( 'jlife_scripture_dataset_invalid', 'Dataset not imported: ' . $why );
	$raw  = is_readable( $dir . '/manifest.json' ) ? file_get_contents( $dir . '/manifest.json' ) : false;
	$m    = is_string( $raw ) ? json_decode( $raw, true ) : null;
	if ( ! is_array( $m ) ) {
		return $fail( 'manifest.json missing or not JSON' );
	}
	foreach ( array( 'dataset', 'name', 'language', 'source_url', 'edition', 'source_sha256', 'data_sha256', 'verse_count', 'books' ) as $field ) {
		if ( empty( $m[ $field ] ) ) {
			return $fail( "manifest has no {$field}" );
		}
	}
	if ( $m['dataset'] !== $dataset ) {
		return $fail( 'manifest is for another dataset' );
	}
	$books = array();
	$sums  = '';
	$total = 0;
	foreach ( (array) $m['books'] as $b ) {
		$code = (string) ( $b['code'] ?? '' );
		if ( ! preg_match( '/^[1-4A-Z]{3}$/', $code ) || ( $b['file'] ?? '' ) !== $code . '.tsv' ) {
			return $fail( 'bad book entry in the manifest' );
		}
		$body = is_readable( $dir . '/' . $b['file'] ) ? file_get_contents( $dir . '/' . $b['file'] ) : false;
		if ( ! is_string( $body ) || hash( 'sha256', $body ) !== ( $b['sha256'] ?? '' ) ) {
			return $fail( "{$b['file']} is missing or does not match its checksum" );
		}
		$rows = array();
		foreach ( explode( "\n", $body ) as $line ) {
			if ( '' === $line ) {
				continue;
			}
			$cols = explode( "\t", $line );
			if ( 3 !== count( $cols ) || ! ctype_digit( $cols[0] ) || ! ctype_digit( $cols[1] ) || '' === $cols[2] ) {
				return $fail( "{$b['file']} has a malformed line" );
			}
			$rows[] = array( (int) $cols[0], (int) $cols[1], $cols[2] );
		}
		if ( count( $rows ) !== (int) ( $b['verses'] ?? -1 ) ) {
			return $fail( "{$b['file']} verse count differs from the manifest" );
		}
		$books[ $code ] = $rows;
		$sums          .= $code . ' ' . $b['sha256'] . "\n";
		$total         += count( $rows );
	}
	if ( hash( 'sha256', $sums ) !== $m['data_sha256'] || $total !== (int) $m['verse_count'] ) {
		return $fail( 'dataset checksum or verse count differs from the manifest' );
	}
	return array(
		'manifest' => $m,
		'books'    => $books,
	);
}

/**
 * Import (or re-import) a dataset into the verses table.
 *
 * @param string $dataset Dataset id, e.g. "vie1934".
 * @param array  $args    { dir?: string (default: the shipped data dir), force?: bool }.
 * @return array|WP_Error { status: imported|unchanged, verses: int, data_sha256: string }
 */
function jlife_scripture_import_dataset( $dataset, $args = array() ) {
	global $wpdb;
	$dataset = sanitize_key( (string) $dataset );
	$dir     = (string) ( $args['dir'] ?? jlife_scripture_dataset_dir( $dataset ) );
	$data    = jlife_scripture_read_dataset( $dataset, $dir );
	if ( is_wp_error( $data ) ) {
		return $data;
	}
	$m       = $data['manifest'];
	$current = jlife_scripture_dataset_status( $dataset );
	if ( empty( $args['force'] ) && $current && $current['data_sha256'] === $m['data_sha256'] && (int) $current['verse_count'] === (int) $m['verse_count'] ) {
		return array(
			'status'      => 'unchanged',
			'verses'      => (int) $m['verse_count'],
			'data_sha256' => (string) $m['data_sha256'],
		);
	}

	$verses = jlife_scripture_table( 'verses' );
	$wpdb->query( $wpdb->prepare( "DELETE FROM {$verses} WHERE dataset = %s", $dataset ) );
	foreach ( $data['books'] as $code => $rows ) {
		foreach ( array_chunk( $rows, 400 ) as $chunk ) {
			$values = array();
			$params = array();
			foreach ( $chunk as $r ) {
				$values[] = '(%s, %s, %d, %d, %s)';
				array_push( $params, $dataset, $code, $r[0], $r[1], $r[2] );
			}
			// phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare,WordPress.DB.PreparedSQL.NotPrepared -- $values holds one placeholder group per row, all prepared here.
			$ok = $wpdb->query( $wpdb->prepare( "INSERT INTO {$verses} (dataset, book, chapter, verse, text) VALUES " . implode( ',', $values ), $params ) );
			if ( false === $ok ) {
				$wpdb->query( $wpdb->prepare( "DELETE FROM {$verses} WHERE dataset = %s", $dataset ) );
				$wpdb->delete( jlife_scripture_table( 'datasets' ), array( 'dataset' => $dataset ) );
				return new WP_Error( 'jlife_scripture_import_failed', 'Dataset not imported: a database insert failed (book ' . $code . ').' );
			}
		}
	}
	$wpdb->replace(
		jlife_scripture_table( 'datasets' ),
		array(
			'dataset'       => $dataset,
			'name'          => (string) $m['name'],
			'language'      => (string) $m['language'],
			'source_url'    => (string) $m['source_url'],
			'edition'       => (string) $m['edition'],
			'source_sha256' => (string) $m['source_sha256'],
			'data_sha256'   => (string) $m['data_sha256'],
			'verse_count'   => (int) $m['verse_count'],
			'imported'      => jlife_scripture_mysql_time( time() ),
		)
	);
	return array(
		'status'      => 'imported',
		'verses'      => (int) $m['verse_count'],
		'data_sha256' => (string) $m['data_sha256'],
	);
}
