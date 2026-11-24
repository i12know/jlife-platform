<?php
/**
 * Custom tables for the Bible Marathon engine (docs/challenges-engine-design.md §2).
 *
 * Uniqueness that protects scoring and privacy is enforced by the database:
 * one enrollment per person per campaign, one membership per person per group,
 * one entry per person per day, one ledger row per person per day, one share per
 * entry per group.
 *
 * @package jlife-challenges
 */

defined( 'ABSPATH' ) || exit;

const JLIFE_CHALLENGES_DB_VERSION = '8';

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
			accepts_photos TINYINT(1) UNSIGNED NOT NULL DEFAULT 0,
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
			started_by BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
			created DATETIME NOT NULL,
			PRIMARY KEY  (id),
			KEY campaign (campaign_id),
			KEY invite_hash (invite_hash),
			KEY started_by (started_by, campaign_id)
		) {$c};"
	);

	dbDelta(
		'CREATE TABLE ' . $t( 'enrollments' ) . " (
			id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			campaign_id BIGINT(20) UNSIGNED NOT NULL,
			user_id BIGINT(20) UNSIGNED NOT NULL,
			group_id BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
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

	// Group membership (#112, D14 changed): a participant is solo (no active row)
	// or in up to JLIFE_CHALLENGES_MAX_GROUPS groups per campaign. The enrollment
	// above stays one per person per campaign; its group_id column is retired
	// (kept, default 0, read only by the one-time migration; see upgrade.php).
	dbDelta(
		'CREATE TABLE ' . $t( 'group_members' ) . " (
			id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			campaign_id BIGINT(20) UNSIGNED NOT NULL,
			enrollment_id BIGINT(20) UNSIGNED NOT NULL,
			user_id BIGINT(20) UNSIGNED NOT NULL,
			group_id BIGINT(20) UNSIGNED NOT NULL,
			role VARCHAR(20) NOT NULL DEFAULT 'member',
			status VARCHAR(20) NOT NULL DEFAULT 'active',
			joined_at DATETIME NOT NULL,
			left_at DATETIME NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY group_user (group_id, user_id),
			KEY user_campaign (user_id, campaign_id, status),
			KEY campaign_status (campaign_id, status),
			KEY enrollment (enrollment_id)
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
			submission_type VARCHAR(10) NOT NULL DEFAULT 'text',
			early TINYINT(1) UNSIGNED NOT NULL DEFAULT 0,
			early_hours SMALLINT(5) UNSIGNED NOT NULL DEFAULT 0,
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
			advance TINYINT(1) UNSIGNED NOT NULL DEFAULT 0,
			hidden_reason VARCHAR(200) NOT NULL DEFAULT '',
			created DATETIME NOT NULL,
			updated DATETIME NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY entry_group (entry_id, group_id),
			KEY group_status (group_id, status)
		) {$c};"
	);

	// Photos of handwritten reflections (#244): one private draft photo per entry
	// (kind 'entry') and one frozen copy per group share (kind 'share'). The files
	// live outside the Media Library under random names (see photos.php); a row
	// holds only those names, never a path.
	dbDelta(
		'CREATE TABLE ' . $t( 'photos' ) . " (
			id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			kind VARCHAR(10) NOT NULL DEFAULT 'entry',
			entry_id BIGINT(20) UNSIGNED NOT NULL,
			share_id BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
			user_id BIGINT(20) UNSIGNED NOT NULL,
			campaign_id BIGINT(20) UNSIGNED NOT NULL,
			day_key VARCHAR(20) NOT NULL,
			file CHAR(32) NOT NULL,
			thumb CHAR(32) NOT NULL,
			bytes INT(10) UNSIGNED NOT NULL DEFAULT 0,
			created DATETIME NOT NULL,
			updated DATETIME NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY kind_entry_share (kind, entry_id, share_id),
			KEY user (user_id),
			KEY campaign (campaign_id),
			KEY share (share_id)
		) {$c};"
	);

	// Daily reading reminder choices (#63 Phase B). One row per person per
	// campaign, written only by the person (or an organizer for them). No email
	// address here: sending looks it up from the account at send time.
	dbDelta(
		'CREATE TABLE ' . $t( 'reminder_prefs' ) . " (
			id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			campaign_id BIGINT(20) UNSIGNED NOT NULL,
			user_id BIGINT(20) UNSIGNED NOT NULL,
			opted_in DATETIME NULL,
			opted_out DATETIME NULL,
			consent_version VARCHAR(20) NOT NULL DEFAULT '',
			locale VARCHAR(10) NOT NULL DEFAULT 'en_US',
			updated DATETIME NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY campaign_user (campaign_id, user_id)
		) {$c};"
	);

	// Daily reminder send log and queue (#63 Phase B, part 2). One row per person
	// per campaign day: the UNIQUE key is the duplicate guard. Only IDs, a status,
	// counters, times and a fixed error class: no email address, subject, body or
	// raw error text.
	dbDelta(
		'CREATE TABLE ' . $t( 'reminder_sends' ) . " (
			id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			campaign_id BIGINT(20) UNSIGNED NOT NULL,
			day_key VARCHAR(20) NOT NULL,
			user_id BIGINT(20) UNSIGNED NOT NULL,
			status VARCHAR(20) NOT NULL DEFAULT 'queued',
			attempts TINYINT(3) UNSIGNED NOT NULL DEFAULT 0,
			next_attempt DATETIME NOT NULL,
			last_error_class VARCHAR(40) NULL,
			created DATETIME NOT NULL,
			updated DATETIME NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY campaign_day_user (campaign_id, day_key, user_id),
			KEY status_due (status, next_attempt),
			KEY user (user_id)
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
	// and enrollments' single group into membership rows (#112), then record the
	// version. Both are idempotent.
	jlife_challenges_upgrade_data();
	jlife_challenges_upgrade_memberships();
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
	// A deploy may add a route (v5: /unsubscribe/); regenerate the rewrite rules on the next request.
	delete_option( 'rewrite_rules' );
	// v8 (#244): campaigns.accepts_photos (default 0: every existing campaign stays text-only), score_ledger.submission_type (default 'text': every existing point was typed) and the photos table are added by dbDelta above; and the /photo/ route needs the rewrite flush just above.
	// v7 (#209): score_ledger.early / early_hours and group_shares.advance are added by dbDelta above; existing rows default to 0, so nothing already shared or scored changes.
	// v6: the reminder cron event (the activation hook does not run on a deploy).
	jlife_challenges_reminders_schedule();
	delete_option( 'jlife_challenges_upgrading' );
}
