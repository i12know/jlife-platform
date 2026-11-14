<?php
/**
 * Group shares (docs/challenges-engine-design.md §2, §4; D6, D17, D18, D19).
 *
 * A share is a deliberate, author-chosen SNAPSHOT of the whole entry (one text,
 * #98), posted to the author's current group. Editing the private entry never
 * changes it on its own; sharing again (the "Share this with my group" box on a
 * save, or /share) replaces it. Shares never affect points.
 *
 * @package jlife-challenges
 */

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.DB.DirectDatabaseQuery -- custom tables are this plugin's data layer; every query goes through the gate; WordPress has no API for them.

/**
 * Read a share row (no gate; internal).
 *
 * @param int $share_id Share ID.
 * @return array|null
 */
function jlife_challenges_find_share( $share_id ) {
	global $wpdb;
	$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . jlife_challenges_table( 'group_shares' ) . ' WHERE id = %d', (int) $share_id ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- table name from $wpdb->prefix.
	return $row ? $row : null;
}

/**
 * Share the whole of one's own campaign entry with one's current group.
 * Repeating it updates the one post (idempotent: UNIQUE entry + group). The
 * snapshot is the text as last saved. Needs some text; the entry doesn't have
 * to have earned its point, since sharing never depends on scoring.
 *
 * @param int $user_id  Author.
 * @param int $entry_id Entry.
 * @return int|WP_Error Share ID.
 */
function jlife_challenges_share( $user_id, $entry_id ) {
	$entry = jlife_challenges_get_entry( $user_id, $entry_id );
	if ( is_wp_error( $entry ) || (int) $entry['campaign_id'] < 1 ) {
		return jlife_challenges_denied();
	}
	$enrollment = jlife_challenges_active_enrollment( (int) $entry['campaign_id'], $user_id );
	if ( ! $enrollment ) {
		return jlife_challenges_denied();
	}
	if ( jlife_challenges_needs_guardian_consent( $enrollment ) ) {
		return new WP_Error( 'jlife_guardian_consent_required', __( 'Sharing with your group starts once a parent or guardian has agreed.', 'jlife-challenges' ) );
	}
	if ( '' === trim( (string) $entry['body'] ) ) {
		return new WP_Error( 'jlife_share_empty', __( 'There is nothing to share yet.', 'jlife-challenges' ) );
	}
	$group_id = (int) $enrollment['group_id'];
	$existing = jlife_challenges_current_share( $user_id, (int) $entry_id, $group_id );
	if ( $existing && 'hidden' === $existing['status'] ) {
		return new WP_Error( 'jlife_share_hidden', __( 'An organizer hid your post for this day, so it was not posted again.', 'jlife-challenges' ) );
	}

	global $wpdb;
	$now = current_time( 'mysql', true );
	$t   = jlife_challenges_table( 'group_shares' );
	// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- custom table; name from $wpdb->prefix; UNIQUE (entry_id, group_id) makes this an upsert.
	$wpdb->query(
		$wpdb->prepare(
			"INSERT INTO {$t} (entry_id, user_id, group_id, campaign_id, day_key, body, status, hidden_reason, created, updated)
			VALUES (%d, %d, %d, %d, %s, %s, 'shared', '', %s, %s)
			ON DUPLICATE KEY UPDATE body = IF(status = 'hidden', body, VALUES(body)), status = IF(status = 'hidden', 'hidden', 'shared'), updated = VALUES(updated)",
			(int) $entry_id,
			(int) $user_id,
			$group_id,
			(int) $entry['campaign_id'],
			(string) $entry['day_key'],
			(string) $entry['body'],
			$now,
			$now
		)
	);
	$id = (int) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$t} WHERE entry_id = %d AND group_id = %d", (int) $entry_id, $group_id ) );
	// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	return $id > 0 ? $id : new WP_Error( 'jlife_db', 'Could not share.' );
}

/**
 * Save an entry and, if the "Share this with my group" box is ticked, post it.
 * The save (and any point) never depends on the share: if sharing fails the
 * result says so and the save stands. Unticked = no post, and a failed save
 * posts nothing.
 *
 * @param int         $user_id     Author.
 * @param int         $campaign_id Campaign.
 * @param string|null $day_key     Day (null = today).
 * @param string      $text        Entry text.
 * @param bool        $share       Whether the box was ticked.
 * @return array|WP_Error jlife_challenges_submit()'s result plus
 *                        share: { requested: bool, status: none|shared|failed, code: string, message: string, share_id: int }.
 */
function jlife_challenges_save_and_share( $user_id, $campaign_id, $day_key, $text, $share ) {
	$result = jlife_challenges_submit( $user_id, $campaign_id, $day_key, $text );
	if ( is_wp_error( $result ) ) {
		return $result;
	}
	$result['share'] = array(
		'requested' => (bool) $share,
		'status'    => 'none',
		'code'      => '',
		'message'   => '',
		'share_id'  => 0,
	);
	if ( ! $share ) {
		return $result;
	}
	$id = jlife_challenges_share( $user_id, $result['entry_id'] );
	if ( is_wp_error( $id ) ) {
		$result['share']['status']  = 'failed';
		$result['share']['code']    = $id->get_error_code();
		$result['share']['message'] = $id->get_error_message();
	} else {
		$result['share']['status']   = 'shared';
		$result['share']['share_id'] = $id;
	}
	return $result;
}

/**
 * Remove one's own share (the text is cleared; points are untouched).
 *
 * @param int $user_id  Author.
 * @param int $share_id Share.
 * @return true|WP_Error
 */
function jlife_challenges_unshare( $user_id, $share_id ) {
	$share = jlife_challenges_find_share( $share_id );
	if ( ! $share || $user_id < 1 || (int) $share['user_id'] !== (int) $user_id ) {
		return jlife_challenges_denied();
	}
	global $wpdb;
	$wpdb->update(
		jlife_challenges_table( 'group_shares' ),
		array(
			'status'  => 'removed',
			'body'    => null,
			'updated' => current_time( 'mysql', true ),
		),
		array( 'id' => (int) $share_id )
	);
	return true;
}

/**
 * Hide a reported share (D17). Organizers only; audited; the author is told.
 * Organizers cannot browse feeds, so this acts on a share ID from a report.
 *
 * @param int    $actor    Organizer.
 * @param int    $share_id Share.
 * @param string $reason   Short reason (shown to the author).
 * @return true|WP_Error
 */
function jlife_challenges_hide_share( $actor, $share_id, $reason ) {
	$share = jlife_challenges_find_share( $share_id );
	if ( ! jlife_challenges_is_organizer( $actor ) || ! $share ) {
		return jlife_challenges_denied();
	}
	$reason = mb_substr( sanitize_text_field( (string) $reason ), 0, 200 );
	global $wpdb;
	$wpdb->update(
		jlife_challenges_table( 'group_shares' ),
		array(
			'status'        => 'hidden',
			'hidden_reason' => $reason,
			'updated'       => current_time( 'mysql', true ),
		),
		array( 'id' => (int) $share_id )
	);
	jlife_challenges_audit( $actor, 'share_hidden', 'group_share', (int) $share_id, (int) $share['campaign_id'], array( 'group_id' => (int) $share['group_id'] ) );
	$notices   = (array) get_user_meta( (int) $share['user_id'], 'jlife_challenges_notices', true );
	$notices[] = array(
		'type'     => 'share_hidden',
		'share_id' => (int) $share_id,
		'reason'   => $reason,
		'at'       => current_time( 'mysql', true ),
	);
	update_user_meta( (int) $share['user_id'], 'jlife_challenges_notices', array_values( array_filter( $notices ) ) );
	return true;
}

/**
 * First name only, for group feeds (#77 default).
 *
 * @param int $user_id User.
 * @return string
 */
function jlife_challenges_feed_name( $user_id ) {
	$u = get_userdata( (int) $user_id );
	if ( ! $u ) {
		return '';
	}
	$first = trim( (string) $u->first_name );
	if ( '' === $first ) {
		$parts = preg_split( '/\s+/', trim( (string) $u->display_name ) );
		$first = (string) ( $parts[0] ?? '' );
	}
	return $first;
}

/**
 * The viewer's current group's feed: shared posts only, newest first.
 *
 * @param int $viewer_user_id Viewer.
 * @param int $campaign_id    Campaign.
 * @param int $limit          Max posts.
 * @return array|WP_Error rows of { id, author, day_key, body, updated, mine }
 */
function jlife_challenges_group_feed( $viewer_user_id, $campaign_id, $limit = 50 ) {
	$e = jlife_challenges_active_enrollment( $campaign_id, $viewer_user_id );
	if ( ! $e || ! jlife_challenges_can_read_feed( $viewer_user_id, (int) $e['group_id'] ) ) {
		return jlife_challenges_denied();
	}
	global $wpdb;
	$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . jlife_challenges_table( 'group_shares' ) . " WHERE group_id = %d AND status = 'shared' ORDER BY day_key DESC, updated DESC LIMIT %d", (int) $e['group_id'], max( 1, (int) $limit ) ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- table name from $wpdb->prefix.
	$out  = array();
	foreach ( $rows as $r ) {
		$out[] = array(
			'id'      => (int) $r['id'],
			'author'  => jlife_challenges_feed_name( (int) $r['user_id'] ),
			'day_key' => $r['day_key'],
			'body'    => (string) $r['body'],
			'updated' => $r['updated'],
			'mine'    => (int) $r['user_id'] === (int) $viewer_user_id,
		);
	}
	return $out;
}

/**
 * The author's own shares in a campaign, in any group (D18: visible and
 * removable after a move), including hidden ones with the reason.
 *
 * @param int $user_id     Author.
 * @param int $campaign_id Campaign.
 * @return array|WP_Error
 */
function jlife_challenges_my_shares( $user_id, $campaign_id ) {
	if ( $user_id < 1 ) {
		return jlife_challenges_denied();
	}
	global $wpdb;
	return $wpdb->get_results( $wpdb->prepare( 'SELECT id, entry_id, group_id, day_key, status, hidden_reason, updated FROM ' . jlife_challenges_table( 'group_shares' ) . " WHERE user_id = %d AND campaign_id = %d AND status <> 'removed' ORDER BY day_key DESC", (int) $user_id, (int) $campaign_id ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- table name from $wpdb->prefix.
}
