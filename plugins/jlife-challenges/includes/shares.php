<?php
/**
 * Group shares (docs/challenges-engine-design.md §2, §4; D6, D17, D18, D19).
 *
 * A share is a deliberate, author-chosen SNAPSHOT of the whole entry (one text,
 * #98), posted to ONE group the author is in (a person may be in up to 3, #112,
 * and chooses per group; there is no "share with all"). Editing the private
 * entry never changes it on its own; sharing again to that group (its box on a
 * save, or /share) replaces it. A share to group A is never visible in group B.
 * Shares never affect points, and a solo reader (no group) has nothing to share
 * to.
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
 * Share the whole of one's own campaign entry with ONE of one's groups.
 * Repeating it updates that group's one post (idempotent: UNIQUE entry + group).
 * The snapshot is the text as last saved. Needs some text; the entry doesn't
 * have to have earned its point, since sharing never depends on scoring.
 *
 * The group must be one the author is an active member of, in the entry's
 * campaign: a guessed ID, another campaign's group, a group they left and a solo
 * reader's attempt are all denied the same way.
 *
 * @param int $user_id  Author.
 * @param int $entry_id Entry.
 * @param int $group_id Group to post to.
 * @return int|WP_Error Share ID.
 */
function jlife_challenges_share( $user_id, $entry_id, $group_id ) {
	$entry = jlife_challenges_get_entry( $user_id, $entry_id );
	if ( is_wp_error( $entry ) || (int) $entry['campaign_id'] < 1 ) {
		return jlife_challenges_denied();
	}
	$enrollment = jlife_challenges_active_enrollment( (int) $entry['campaign_id'], $user_id );
	$group      = jlife_challenges_get_group( $group_id );
	if ( ! $enrollment || ! $group || (int) $group['campaign_id'] !== (int) $entry['campaign_id'] || ! jlife_challenges_is_group_member( $user_id, $group_id ) ) {
		return jlife_challenges_denied();
	}
	if ( ! jlife_challenges_campaign_accepts_writes( (int) $entry['campaign_id'] ) ) {
		return jlife_challenges_campaign_closed_error();
	}
	if ( jlife_challenges_needs_guardian_consent( $enrollment ) ) {
		return new WP_Error( 'jlife_guardian_consent_required', __( 'Sharing with your group starts once a parent or guardian has agreed.', 'jlife-challenges' ) );
	}
	if ( '' === trim( (string) $entry['body'] ) ) {
		return new WP_Error( 'jlife_share_empty', __( 'There is nothing to share yet.', 'jlife-challenges' ) );
	}
	$group_id = (int) $group_id;
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
 * Share one entry with each of the named groups, independently: one group's
 * refusal (hidden there, not a member, guardian wait) never stops another.
 *
 * @param int   $user_id   Author.
 * @param int   $entry_id  Entry.
 * @param int[] $group_ids Groups to post to (duplicates and non-positive IDs are dropped).
 * @return array<int,int|WP_Error> Share ID or error, by group ID.
 */
function jlife_challenges_share_to_groups( $user_id, $entry_id, $group_ids ) {
	$out = array();
	foreach ( jlife_challenges_clean_group_ids( $group_ids ) as $group_id ) {
		$out[ $group_id ] = jlife_challenges_share( $user_id, $entry_id, $group_id );
	}
	return $out;
}

/**
 * Normalize a list of requested group IDs: positive, unique integers, at most
 * twice the group limit (anything beyond could only be guesses).
 *
 * @param mixed $group_ids Requested IDs (anything but an array means none).
 * @return int[]
 */
function jlife_challenges_clean_group_ids( $group_ids ) {
	if ( ! is_array( $group_ids ) ) {
		return array();
	}
	$ids = array();
	foreach ( $group_ids as $id ) {
		$id = is_scalar( $id ) ? (int) $id : 0;
		if ( $id > 0 ) {
			$ids[ $id ] = $id;
		}
	}
	return array_slice( array_values( $ids ), 0, JLIFE_CHALLENGES_MAX_GROUPS * 2 );
}

/**
 * Save an entry and post it to each group whose box is ticked. The save (and any
 * point) never depends on the shares: if one fails the result says so and the
 * save stands. No ticked group = no post (there is no "share with all": a bare
 * `true` from the old single box means nothing), and a failed save posts nothing.
 *
 * @param int         $user_id     Author.
 * @param int         $campaign_id Campaign.
 * @param string|null $day_key     Day (null = today).
 * @param string      $text        Entry text.
 * @param int[]       $group_ids   Groups whose box was ticked.
 * @return array|WP_Error jlife_challenges_submit()'s result plus
 *                        share: { requested: int[], status: none|shared|partial|failed,
 *                        results: { group_id: { status: shared|failed, code, message, share_id } } }.
 */
function jlife_challenges_save_and_share( $user_id, $campaign_id, $day_key, $text, $group_ids = array() ) {
	$result = jlife_challenges_submit( $user_id, $campaign_id, $day_key, $text );
	if ( is_wp_error( $result ) ) {
		return $result;
	}
	$requested       = jlife_challenges_clean_group_ids( $group_ids );
	$result['share'] = array(
		'requested' => $requested,
		'status'    => 'none',
		'results'   => array(),
	);
	if ( ! $requested ) {
		return $result;
	}
	$shared = 0;
	foreach ( jlife_challenges_share_to_groups( $user_id, (int) $result['entry_id'], $requested ) as $group_id => $id ) {
		if ( is_wp_error( $id ) ) {
			$result['share']['results'][ $group_id ] = array(
				'status'   => 'failed',
				'code'     => $id->get_error_code(),
				'message'  => $id->get_error_message(),
				'share_id' => 0,
			);
		} else {
			++$shared;
			$result['share']['results'][ $group_id ] = array(
				'status'   => 'shared',
				'code'     => '',
				'message'  => '',
				'share_id' => $id,
			);
		}
	}
	$result['share']['status'] = count( $requested ) === $shared ? 'shared' : ( $shared > 0 ? 'partial' : 'failed' );
	return $result;
}

/**
 * Remove one's own share (the text is cleared; points are untouched). Allowed
 * in a closed campaign too: removing only reduces what others can see (owner
 * decision 2026-11-20, docs/challenges-engine-design.md §3c). Removing a share
 * that is already gone is a no-op (true, no second audit row); a failed
 * database write is an error and is not audited.
 *
 * @param int $user_id  Author.
 * @param int $share_id Share.
 * @return true|WP_Error `jlife_denied`, or `jlife_unshare_failed` when the write failed.
 */
function jlife_challenges_unshare( $user_id, $share_id ) {
	$share = jlife_challenges_find_share( $share_id );
	if ( ! $share || $user_id < 1 || (int) $share['user_id'] !== (int) $user_id ) {
		return jlife_challenges_denied();
	}
	// Already gone from the group (removed, or hidden with its text cleared):
	// nothing left to clear, so no second write and no second audit row.
	if ( 'removed' === $share['status'] || ( 'hidden' === $share['status'] && null === $share['body'] ) ) {
		return true;
	}
	global $wpdb;
	// An organizer-hidden post stays hidden (D17): the author's text is cleared,
	// but removing it must not let them post again by sidestepping the hide.
	$updated = $wpdb->update(
		jlife_challenges_table( 'group_shares' ),
		array(
			'status'  => 'hidden' === $share['status'] ? 'hidden' : 'removed',
			'body'    => null,
			'updated' => current_time( 'mysql', true ),
		),
		array( 'id' => (int) $share_id )
	);
	if ( false === $updated ) {
		// The snapshot may still be visible: never audit or report it as removed.
		return new WP_Error( 'jlife_unshare_failed', __( 'Your post could not be removed. Please try again.', 'jlife-challenges' ) );
	}
	jlife_challenges_audit( $user_id, 'share_removed', 'group_share', (int) $share_id, (int) $share['campaign_id'], array( 'group_id' => (int) $share['group_id'] ) );
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
 * One group's feed: shared posts only, newest first. Only an active member of
 * THAT group may read it; the posts are the ones made to that group and nothing
 * made to any of the viewer's other groups.
 *
 * @param int $viewer_user_id Viewer.
 * @param int $group_id       Group.
 * @param int $limit          Max posts.
 * @return array|WP_Error rows of { id, author, day_key, body, updated, mine }
 */
function jlife_challenges_group_feed( $viewer_user_id, $group_id, $limit = 50 ) {
	if ( ! jlife_challenges_can_read_feed( $viewer_user_id, (int) $group_id ) ) {
		return jlife_challenges_denied();
	}
	global $wpdb;
	$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . jlife_challenges_table( 'group_shares' ) . " WHERE group_id = %d AND status = 'shared' ORDER BY day_key DESC, updated DESC LIMIT %d", (int) $group_id, max( 1, (int) $limit ) ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- table name from $wpdb->prefix.
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
 * removable after leaving or a move), including hidden ones with the reason.
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

/**
 * The author's own live shares in groups they are no longer an active member of
 * (they left, an organizer removed them, or the group was disabled), oldest day
 * last, with the group's name: the "Groups you left" list on /share (D18). The
 * author was in these groups, so they may see the name; nobody else is shown
 * anything here. Hidden posts are listed too (removing one clears its text and
 * keeps it hidden).
 *
 * @param int $user_id     Author.
 * @param int $campaign_id Campaign.
 * @return array[] Rows of { id, group_id, group_name, group_status, day_key, index, status, hidden_reason, updated }.
 */
function jlife_challenges_left_group_shares( $user_id, $campaign_id ) {
	$mine = jlife_challenges_my_shares( (int) $user_id, (int) $campaign_id );
	if ( is_wp_error( $mine ) || ! $mine ) {
		return array();
	}
	$campaign = jlife_challenges_get_campaign( $campaign_id );
	$active   = array_map( 'intval', array_column( jlife_challenges_active_memberships( (int) $campaign_id, (int) $user_id ), 'group_id' ) );
	$out      = array();
	foreach ( $mine as $s ) {
		if ( in_array( (int) $s['group_id'], $active, true ) ) {
			continue;
		}
		$group = jlife_challenges_get_group( (int) $s['group_id'] );
		$out[] = array(
			'id'            => (int) $s['id'],
			'group_id'      => (int) $s['group_id'],
			'group_name'    => $group ? (string) $group['name'] : '',
			'group_status'  => $group ? (string) $group['status'] : '',
			'day_key'       => (string) $s['day_key'],
			'index'         => $campaign ? (int) jlife_challenges_day_index( $campaign, (string) $s['day_key'] ) : 0,
			'status'        => (string) $s['status'],
			'hidden_reason' => (string) $s['hidden_reason'],
			'updated'       => (string) $s['updated'],
		);
	}
	return $out;
}
