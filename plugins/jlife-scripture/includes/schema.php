<?php
/**
 * Tables (#139). Network-wide ($wpdb->base_prefix): the Bible text and the
 * passage cache are the same for every subsite and hold no personal data.
 *
 * - datasets: one row per imported public-domain dataset, with its source
 *   URL, edition and checksums (provenance).
 * - verses:   the imported text (book, chapter, verse, text).
 * - cache:    one shared row per source + version + passage for licensed
 *   sources, with a lock column so concurrent first views fetch once.
 *
 * @package jlife-scripture
 */

defined( 'ABSPATH' ) || exit;

const JLIFE_SCRIPTURE_DB_VERSION = '1';

/**
 * Table name helper.
 *
 * @param string $name Short name: datasets, verses or cache.
 * @return string
 */
function jlife_scripture_table( $name ) {
	global $wpdb;
	return $wpdb->base_prefix . 'jlife_scripture_' . $name;
}

/**
 * Create or upgrade the tables (idempotent).
 */
function jlife_scripture_install_tables() {
	global $wpdb;
	require_once ABSPATH . 'wp-admin/includes/upgrade.php';
	$c = $wpdb->get_charset_collate();

	dbDelta(
		'CREATE TABLE ' . jlife_scripture_table( 'datasets' ) . " (
			dataset VARCHAR(32) NOT NULL,
			name VARCHAR(191) NOT NULL,
			language VARCHAR(10) NOT NULL,
			source_url VARCHAR(255) NOT NULL,
			edition VARCHAR(255) NOT NULL,
			source_sha256 CHAR(64) NOT NULL,
			data_sha256 CHAR(64) NOT NULL,
			verse_count INT(10) UNSIGNED NOT NULL DEFAULT 0,
			imported DATETIME NOT NULL,
			PRIMARY KEY  (dataset)
		) {$c};"
	);

	dbDelta(
		'CREATE TABLE ' . jlife_scripture_table( 'verses' ) . " (
			dataset VARCHAR(32) NOT NULL,
			book CHAR(3) NOT NULL,
			chapter SMALLINT(5) UNSIGNED NOT NULL,
			verse SMALLINT(5) UNSIGNED NOT NULL,
			text TEXT NOT NULL,
			PRIMARY KEY  (dataset,book,chapter,verse)
		) {$c};"
	);

	dbDelta(
		'CREATE TABLE ' . jlife_scripture_table( 'cache' ) . " (
			cache_key CHAR(64) NOT NULL,
			source VARCHAR(20) NOT NULL,
			version_key VARCHAR(40) NOT NULL,
			passage VARCHAR(191) NOT NULL,
			body MEDIUMTEXT NULL,
			fetched_at DATETIME NULL,
			lock_until DATETIME NULL,
			PRIMARY KEY  (cache_key),
			KEY version_fetched (version_key,fetched_at)
		) {$c};"
	);

	update_site_option( 'jlife_scripture_db_version', JLIFE_SCRIPTURE_DB_VERSION );
}

/**
 * Create the tables after an update that changed them (or on a site where
 * the activation hook never ran, e.g. a network-wide copy).
 */
function jlife_scripture_maybe_upgrade() {
	if ( JLIFE_SCRIPTURE_DB_VERSION !== (string) get_site_option( 'jlife_scripture_db_version', '' ) ) {
		jlife_scripture_install_tables();
	}
}
