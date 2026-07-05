<?php
/**
 * Custom tables for privacy-scoped huddle data (spike S5, #12).
 *
 * Three tables with deliberately different sensitivity postures
 * (pilot-context.md "Privacy Expectations"):
 *
 * - huddle_threads: huddle-scoped discussion; members/leaders of that huddle.
 * - private_notes: author-only at the application layer — not leaders, not
 *   coaches, not site admins through app code.
 * - progress: self-visible detail; leaders see completion flags; HUB/coach
 *   surfaces see aggregates only. user_id is signed: negative values key
 *   S4 magic-link actors (contact IDs) until the account-claim flow merges
 *   them into real accounts.
 *
 * Every row carries the D.T linkage (`dt_group_id`) per the S3 mapping
 * contract. utf8mb4 via get_charset_collate() (Vietnamese content).
 * WordPress core comments are NOT used — they have no group access model.
 *
 * @package jlife-huddles
 */

defined( 'ABSPATH' ) || exit;

/**
 * Create/upgrade the huddle tables. Idempotent (dbDelta).
 */
function jlife_huddles_install_tables() {
	global $wpdb;
	require_once ABSPATH . 'wp-admin/includes/upgrade.php';

	$charset = $wpdb->get_charset_collate();
	$p       = $wpdb->prefix;

	dbDelta(
		"CREATE TABLE {$p}jlife_huddle_threads (
			id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			dt_group_id BIGINT(20) UNSIGNED NOT NULL,
			lesson_id VARCHAR(64) NOT NULL,
			author_user_id BIGINT(20) UNSIGNED NOT NULL,
			body LONGTEXT NOT NULL,
			created DATETIME NOT NULL,
			updated DATETIME NOT NULL,
			PRIMARY KEY  (id),
			KEY group_lesson (dt_group_id, lesson_id),
			KEY author (author_user_id)
		) {$charset};"
	);

	dbDelta(
		"CREATE TABLE {$p}jlife_private_notes (
			id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			user_id BIGINT(20) UNSIGNED NOT NULL,
			dt_group_id BIGINT(20) UNSIGNED NOT NULL,
			lesson_id VARCHAR(64) NOT NULL,
			body LONGTEXT NOT NULL,
			created DATETIME NOT NULL,
			updated DATETIME NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY user_group_lesson (user_id, dt_group_id, lesson_id),
			KEY group_lesson (dt_group_id, lesson_id),
			KEY user_lesson (user_id, lesson_id)
		) {$charset};"
	);

	dbDelta(
		"CREATE TABLE {$p}jlife_progress (
			id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			dt_group_id BIGINT(20) UNSIGNED NOT NULL,
			user_id BIGINT(20) NOT NULL,
			lesson_id VARCHAR(64) NOT NULL,
			status VARCHAR(20) NOT NULL DEFAULT 'started',
			updated DATETIME NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY user_group_lesson (user_id, dt_group_id, lesson_id),
			KEY group_lesson (dt_group_id, lesson_id)
		) {$charset};"
	);
}

add_action( 'deleted_user', 'jlife_huddles_purge_user_data' );

/**
 * Delete a user's private data outright when their account is deleted.
 *
 * Private notes and progress are removed immediately; DB backups may retain
 * copies until the backup retention window expires (documented in the
 * privacy statement). Huddle thread posts are group-context content and are
 * retained pending a product decision (tracked in the S5 write-up).
 *
 * @param int $user_id Deleted user ID.
 */
function jlife_huddles_purge_user_data( $user_id ) {
	global $wpdb;
	// phpcs:disable WordPress.DB.DirectDatabaseQuery -- custom tables own this data; no WP API exists.
	$wpdb->delete( $wpdb->prefix . 'jlife_private_notes', array( 'user_id' => (int) $user_id ), array( '%d' ) );
	$wpdb->delete( $wpdb->prefix . 'jlife_progress', array( 'user_id' => (int) $user_id ), array( '%d' ) );
	// phpcs:enable
}
