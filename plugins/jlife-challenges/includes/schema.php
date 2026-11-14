<?php
/**
 * Custom tables for the Bible Marathon engine (docs/challenges-engine-design.md §2).
 *
 * Uniqueness that protects scoring and privacy is enforced by the database:
 * one enrollment per person per campaign, one entry per person per day, one
 * ledger row per person per day, one share per entry per group.
 *
 * @package jlife-challenges
 */

defined( 'ABSPATH' ) || exit;

const JLIFE_CHALLENGES_DB_VERSION = '2';

/**
 * Table name helper.
 *
 * @param string $name Short name, e.g. 'soap_entries'.
 * @return string
 */
function jlife_challenges_table( $name ) {
	global $wpdb;
	return $wpdb->prefix . 'jlife_' . $name;
}

/**
 * Create or upgrade the engine's tables (idempotent).
 */
function jlife_challenges_install_tables() {
	global $wpdb;
	require_once ABSPATH . 'wp-admin/includes/upgrade.php';
	$c = $wpdb->get_charset_collate();
	$t = 'jlife_challenges_table';

	dbDelta(
		'CREATE TABLE ' . $t( 'campaigns' ) . " (
			id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			campaign_key VARCHAR(64) NOT NULL,
			plan_id VARCHAR(100) NOT NULL,
			title VARCHAR(200) NOT NULL,
			start_date DATE NOT NULL,
			end_date DATE NOT NULL,
			timezone VARCHAR(64) NOT NULL,
			grace_days TINYINT(3) UNSIGNED NOT NULL DEFAULT 1,
			status VARCHAR(20) NOT NULL DEFAULT 'draft',
			scoring_engine VARCHAR(40) NOT NULL DEFAULT 'soap-regex',
			created DATETIME NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY campaign_key (campaign_key)
		) {$c};"
	);

	dbDelta(
		'CREATE TABLE ' . $t( 'groups' ) . " (
			id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			campaign_id BIGINT(20) UNSIGNED NOT NULL,
			name VARCHAR(100) NOT NULL,
			invite_hash CHAR(64) NOT NULL DEFAULT '',
			invite_expires DATETIME NULL,
			status VARCHAR(20) NOT NULL DEFAULT 'active',
			created DATETIME NOT NULL,
			PRIMARY KEY  (id),
			KEY campaign (campaign_id),
			KEY invite_hash (invite_hash)
		) {$c};"
	);

	dbDelta(
		'CREATE TABLE ' . $t( 'enrollments' ) . " (
			id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			campaign_id BIGINT(20) UNSIGNED NOT NULL,
			user_id BIGINT(20) UNSIGNED NOT NULL,
			group_id BIGINT(20) UNSIGNED NOT NULL,
			status VARCHAR(20) NOT NULL DEFAULT 'active',
			age_band VARCHAR(10) NOT NULL DEFAULT 'adult',
			guardian_consent DATETIME NULL,
			consent_version VARCHAR(20) NOT NULL DEFAULT '',
			consented DATETIME NULL,
			joined DATETIME NOT NULL,
			left_at DATETIME NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY campaign_user (campaign_id, user_id),
			KEY group_status (group_id, status)
		) {$c};"
	);

	dbDelta(
		'CREATE TABLE ' . $t( 'soap_entries' ) . " (
			id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			user_id BIGINT(20) UNSIGNED NOT NULL,
			campaign_id BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
			day_key VARCHAR(20) NOT NULL,
			body LONGTEXT NOT NULL,
			status VARCHAR(20) NOT NULL DEFAULT 'draft',
			revision INT(10) UNSIGNED NOT NULL DEFAULT 0,
			submitted DATETIME NULL,
			created DATETIME NOT NULL,
			updated DATETIME NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY user_campaign_day (user_id, campaign_id, day_key),
			KEY campaign_day (campaign_id, day_key)
		) {$c};"
	);

	dbDelta(
		'CREATE TABLE ' . $t( 'soap_revisions' ) . " (
			id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			entry_id BIGINT(20) UNSIGNED NOT NULL,
			revision INT(10) UNSIGNED NOT NULL,
			body LONGTEXT NOT NULL,
			saved DATETIME NOT NULL,
			PRIMARY KEY  (id),
			KEY entry (entry_id)
		) {$c};"
	);

	dbDelta(
		'CREATE TABLE ' . $t( 'score_ledger' ) . " (
			id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			campaign_id BIGINT(20) UNSIGNED NOT NULL,
			user_id BIGINT(20) UNSIGNED NOT NULL,
			day_key VARCHAR(20) NOT NULL,
			entry_id BIGINT(20) UNSIGNED NOT NULL,
			points TINYINT(3) UNSIGNED NOT NULL DEFAULT 0,
			status VARCHAR(20) NOT NULL,
			reason VARCHAR(100) NOT NULL DEFAULT '',
			source VARCHAR(20) NOT NULL DEFAULT 'platform',
			engine VARCHAR(40) NOT NULL DEFAULT '',
			engine_version VARCHAR(20) NOT NULL DEFAULT '',
			created DATETIME NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY campaign_user_day (campaign_id, user_id, day_key),
			KEY campaign_day (campaign_id, day_key)
		) {$c};"
	);

	dbDelta(
		'CREATE TABLE ' . $t( 'group_shares' ) . " (
			id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			entry_id BIGINT(20) UNSIGNED NOT NULL,
			user_id BIGINT(20) UNSIGNED NOT NULL,
			group_id BIGINT(20) UNSIGNED NOT NULL,
			campaign_id BIGINT(20) UNSIGNED NOT NULL,
			day_key VARCHAR(20) NOT NULL,
			body LONGTEXT NULL,
			status VARCHAR(20) NOT NULL DEFAULT 'shared',
			hidden_reason VARCHAR(200) NOT NULL DEFAULT '',
			created DATETIME NOT NULL,
			updated DATETIME NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY entry_group (entry_id, group_id),
			KEY group_status (group_id, status)
		) {$c};"
	);

	dbDelta(
		'CREATE TABLE ' . $t( 'audit_log' ) . " (
			id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			actor_user_id BIGINT(20) UNSIGNED NOT NULL,
			action VARCHAR(50) NOT NULL,
			object_type VARCHAR(30) NOT NULL,
			object_id BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
			campaign_id BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
			detail TEXT NOT NULL,
			created DATETIME NOT NULL,
			PRIMARY KEY  (id),
			KEY campaign (campaign_id),
			KEY object (object_type, object_id)
		) {$c};"
	);

	// Move rows written before the single-text entry (#98) into the new column,
	// then record the version. Idempotent: a no-op once the old columns are gone.
	jlife_challenges_upgrade_data();
	update_option( 'jlife_challenges_db_version', JLIFE_CHALLENGES_DB_VERSION, false );
}

add_action( 'plugins_loaded', 'jlife_challenges_maybe_upgrade' );

/**
 * Upgrade an already-installed site on its first request after a deploy (the
 * activation hook doesn't run again). Sites without the version option are
 * left to activation.
 */
function jlife_challenges_maybe_upgrade() {
	$installed = get_option( 'jlife_challenges_db_version' );
	if ( false === $installed || version_compare( (string) $installed, JLIFE_CHALLENGES_DB_VERSION, '>=' ) ) {
		return;
	}
	// One request upgrades; a stale lock (a crashed upgrade) expires after a minute.
	$held = (int) get_option( 'jlife_challenges_upgrading', 0 );
	if ( $held > time() - MINUTE_IN_SECONDS ) {
		return;
	}
	update_option( 'jlife_challenges_upgrading', time(), false );
	jlife_challenges_install_tables();
	delete_option( 'jlife_challenges_upgrading' );
}
