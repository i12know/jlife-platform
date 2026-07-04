<?php
/**
 * Data API for huddle threads, private notes, and progress (spike S5, #12).
 *
 * Every function checks the gate (includes/gate.php) before touching the
 * database and returns WP_Error on denial — rendering, REST, and CLI code
 * must call these functions and never query the tables directly. Fetch-by-ID
 * paths re-check the gate against the row's own scope, so guessing an ID
 * from another huddle earns the same denial as a list request.
 *
 * All SQL goes through $wpdb->prepare(); tables are utf8mb4 (Vietnamese).
 *
 * @package jlife-huddles
 */

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.DB.DirectDatabaseQuery -- custom privacy-scoped tables; no WP API exists, and caching is deliberately absent while access rules are load-bearing.

/**
 * Deny helper.
 *
 * @return WP_Error
 */
function jlife_huddles_denied() {
	return new WP_Error( 'jlife_denied', __( 'You do not have access to this huddle content.', 'jlife-huddles' ) );
}

/**
 * Post to a huddle's discussion thread.
 *
 * @param int    $user_id     Acting user.
 * @param int    $dt_group_id Huddle.
 * @param string $lesson_id   Lesson.
 * @param string $body        Post body (plain text/Markdown).
 * @return int|WP_Error New row ID.
 */
function jlife_huddles_create_thread_post( $user_id, $dt_group_id, $lesson_id, $body ) {
	if ( ! jlife_huddles_can_write_thread( $user_id, $dt_group_id, $lesson_id ) ) {
		return jlife_huddles_denied();
	}
	global $wpdb;
	$now = current_time( 'mysql', true );
	$ok  = $wpdb->insert(
		$wpdb->prefix . 'jlife_huddle_threads',
		array(
			'dt_group_id'    => (int) $dt_group_id,
			'lesson_id'      => (string) $lesson_id,
			'author_user_id' => (int) $user_id,
			'body'           => (string) $body,
			'created'        => $now,
			'updated'        => $now,
		),
		array( '%d', '%s', '%d', '%s', '%s', '%s' )
	);
	return false === $ok ? new WP_Error( 'jlife_db', 'Insert failed.' ) : (int) $wpdb->insert_id;
}

/**
 * Read a huddle's discussion thread for a lesson.
 *
 * @param int    $user_id     Acting user.
 * @param int    $dt_group_id Huddle.
 * @param string $lesson_id   Lesson.
 * @return array|WP_Error Rows (id, author_user_id, body, created).
 */
function jlife_huddles_get_thread_posts( $user_id, $dt_group_id, $lesson_id ) {
	if ( ! jlife_huddles_can_read_thread( $user_id, $dt_group_id, $lesson_id ) ) {
		return jlife_huddles_denied();
	}
	global $wpdb;
	return (array) $wpdb->get_results(
		$wpdb->prepare(
			"SELECT id, author_user_id, body, created FROM {$wpdb->prefix}jlife_huddle_threads WHERE dt_group_id = %d AND lesson_id = %s ORDER BY id ASC", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from $wpdb->prefix.
			(int) $dt_group_id,
			(string) $lesson_id
		),
		ARRAY_A
	);
}

/**
 * Fetch one thread post by ID — the ID-guessing path.
 *
 * The gate is evaluated against the row's own huddle, not the caller's
 * claim, so a member of another huddle who guesses an ID is denied.
 *
 * @param int $user_id Acting user.
 * @param int $post_id Thread row ID.
 * @return array|WP_Error
 */
function jlife_huddles_get_thread_post( $user_id, $post_id ) {
	global $wpdb;
	$row = $wpdb->get_row(
		$wpdb->prepare(
			"SELECT id, dt_group_id, lesson_id, author_user_id, body, created FROM {$wpdb->prefix}jlife_huddle_threads WHERE id = %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			(int) $post_id
		),
		ARRAY_A
	);
	if ( ! $row ) {
		return jlife_huddles_denied(); // Not found and denied are indistinguishable.
	}
	if ( ! jlife_huddles_can_read_thread( $user_id, (int) $row['dt_group_id'], (string) $row['lesson_id'] ) ) {
		return jlife_huddles_denied();
	}
	return $row;
}

/**
 * Create or update the acting user's private note for a lesson.
 *
 * @param int    $user_id   Acting user (must be the author).
 * @param string $lesson_id Lesson.
 * @param string $body      Note body.
 * @return int|WP_Error Row ID.
 */
function jlife_huddles_save_private_note( $user_id, $lesson_id, $body ) {
	if ( ! jlife_huddles_can_write_private_note( $user_id, $user_id ) ) {
		return jlife_huddles_denied();
	}
	global $wpdb;
	$now      = current_time( 'mysql', true );
	$existing = $wpdb->get_var(
		$wpdb->prepare(
			"SELECT id FROM {$wpdb->prefix}jlife_private_notes WHERE user_id = %d AND lesson_id = %s", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			(int) $user_id,
			(string) $lesson_id
		)
	);
	if ( $existing ) {
		$wpdb->update(
			$wpdb->prefix . 'jlife_private_notes',
			array(
				'body'    => (string) $body,
				'updated' => $now,
			),
			array( 'id' => (int) $existing ),
			array( '%s', '%s' ),
			array( '%d' )
		);
		return (int) $existing;
	}
	$ok = $wpdb->insert(
		$wpdb->prefix . 'jlife_private_notes',
		array(
			'user_id'   => (int) $user_id,
			'lesson_id' => (string) $lesson_id,
			'body'      => (string) $body,
			'created'   => $now,
			'updated'   => $now,
		),
		array( '%d', '%s', '%s', '%s', '%s' )
	);
	return false === $ok ? new WP_Error( 'jlife_db', 'Insert failed.' ) : (int) $wpdb->insert_id;
}

/**
 * Fetch a private note by row ID — the ID-guessing path.
 *
 * @param int $viewer_user_id Acting user.
 * @param int $note_id        Note row ID.
 * @return array|WP_Error
 */
function jlife_huddles_get_private_note( $viewer_user_id, $note_id ) {
	global $wpdb;
	$row = $wpdb->get_row(
		$wpdb->prepare(
			"SELECT id, user_id, lesson_id, body, created, updated FROM {$wpdb->prefix}jlife_private_notes WHERE id = %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			(int) $note_id
		),
		ARRAY_A
	);
	if ( ! $row || ! jlife_huddles_can_read_private_note( $viewer_user_id, (int) $row['user_id'] ) ) {
		return jlife_huddles_denied();
	}
	return $row;
}

/**
 * Set the acting user's progress for a lesson in a huddle.
 *
 * Account users must be huddle members. S4 magic-link actors reach this
 * surface through jlife_huddles_link_set_progress() below.
 *
 * @param int    $user_id     Acting user.
 * @param int    $dt_group_id Huddle.
 * @param string $lesson_id   Lesson.
 * @param string $status      'started' | 'completed'.
 * @return bool|WP_Error
 */
function jlife_huddles_set_progress( $user_id, $dt_group_id, $lesson_id, $status ) {
	if ( ! jlife_huddles_is_member( $user_id, $dt_group_id ) ) {
		return jlife_huddles_denied();
	}
	global $wpdb;
	// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from $wpdb->prefix.
	$wpdb->query(
		$wpdb->prepare(
			"INSERT INTO {$wpdb->prefix}jlife_progress (dt_group_id, user_id, lesson_id, status, updated) VALUES (%d, %d, %s, %s, %s) ON DUPLICATE KEY UPDATE status = VALUES(status), updated = VALUES(updated)", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			(int) $dt_group_id,
			(int) $user_id,
			(string) $lesson_id,
			(string) $status,
			current_time( 'mysql', true )
		)
	);
	return true;
}

/**
 * Per-member completion flags for a huddle lesson — leader surface.
 *
 * Returns user IDs and status values only; this query cannot produce note
 * or thread content by construction.
 *
 * @param int    $viewer_user_id Acting user (must be the huddle leader).
 * @param int    $dt_group_id    Huddle.
 * @param string $lesson_id      Lesson.
 * @return array|WP_Error Rows (user_id, status).
 */
function jlife_huddles_get_progress_flags( $viewer_user_id, $dt_group_id, $lesson_id ) {
	if ( ! jlife_huddles_can_read_progress_flags( $viewer_user_id, $dt_group_id ) ) {
		return jlife_huddles_denied();
	}
	global $wpdb;
	return (array) $wpdb->get_results(
		$wpdb->prepare(
			"SELECT user_id, status FROM {$wpdb->prefix}jlife_progress WHERE dt_group_id = %d AND lesson_id = %s", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			(int) $dt_group_id,
			(string) $lesson_id
		),
		ARRAY_A
	);
}

/**
 * Aggregate progress counts for a huddle — the only shape that leaves it
 * (coach/HUB tile via the bridge).
 *
 * @param int $viewer_user_id Acting user.
 * @param int $dt_group_id    Huddle.
 * @return array|WP_Error { completed: int, started: int }
 */
function jlife_huddles_get_progress_aggregate( $viewer_user_id, $dt_group_id ) {
	if ( ! jlife_huddles_can_read_progress_aggregate( $viewer_user_id, $dt_group_id ) ) {
		return jlife_huddles_denied();
	}
	global $wpdb;
	$rows = (array) $wpdb->get_results(
		$wpdb->prepare(
			"SELECT status, COUNT(*) AS n FROM {$wpdb->prefix}jlife_progress WHERE dt_group_id = %d GROUP BY status", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			(int) $dt_group_id
		),
		ARRAY_A
	);
	$agg = array(
		'completed' => 0,
		'started'   => 0,
	);
	foreach ( $rows as $r ) {
		if ( isset( $agg[ $r['status'] ] ) ) {
			$agg[ $r['status'] ] = (int) $r['n'];
		}
	}
	return $agg;
}

/**
 * Progress write for S4 magic-link actors — the only huddle-data surface a
 * bearer token may touch (gate: jlife_huddles_link_actor_can).
 *
 * @param int    $dt_contact_id Link-resolved D.T contact.
 * @param int    $dt_group_id   Link scope huddle.
 * @param string $lesson_id     Link scope lesson.
 * @param string $status        'started' | 'completed'.
 * @return bool|WP_Error
 */
function jlife_huddles_link_set_progress( $dt_contact_id, $dt_group_id, $lesson_id, $status ) {
	if ( ! jlife_huddles_link_actor_can( 'progress' ) ) {
		return jlife_huddles_denied();
	}
	// Spike posture: link-actor progress rows are keyed by negative contact
	// ID to keep them distinct from account user IDs until the account-claim
	// flow (S4 upgrade path) merges them.
	global $wpdb;
	// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from $wpdb->prefix.
	$wpdb->query(
		$wpdb->prepare(
			"INSERT INTO {$wpdb->prefix}jlife_progress (dt_group_id, user_id, lesson_id, status, updated) VALUES (%d, %d, %s, %s, %s) ON DUPLICATE KEY UPDATE status = VALUES(status), updated = VALUES(updated)", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			(int) $dt_group_id,
			-1 * abs( (int) $dt_contact_id ),
			(string) $lesson_id,
			(string) $status,
			current_time( 'mysql', true )
		)
	);
	return true;
}

// phpcs:enable WordPress.DB.DirectDatabaseQuery
