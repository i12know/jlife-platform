<?php
/**
 * View models for sharing (M7, #62): the share page and the group feeds.
 * Pure data, no output. Sharing is always a deliberate choice, per group (one
 * box per group on the SOAP form, or a section per group on /share) and never
 * changes points. The whole entry is shared (#98); there are no per-part
 * choices. A solo reader (no group) is offered no group to share to (#112).
 *
 * @package jlife-challenges
 */

defined( 'ABSPATH' ) || exit;

/**
 * The author's live share of one entry in one group, or null.
 *
 * @param int $user_id  Author.
 * @param int $entry_id Entry.
 * @param int $group_id Group.
 * @return array|null Share row (any status except removed).
 */
function jlife_challenges_current_share( $user_id, $entry_id, $group_id ) {
	global $wpdb;
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.NotPrepared -- custom table; name from $wpdb->prefix.
	$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . jlife_challenges_table( 'group_shares' ) . " WHERE user_id = %d AND entry_id = %d AND group_id = %d AND status <> 'removed'", (int) $user_id, (int) $entry_id, (int) $group_id ), ARRAY_A );
	return $row ? $row : null;
}

/**
 * The groups a person can share to in a campaign, with whether one entry is
 * already shared to each. Empty for a solo reader: no box, no share UI.
 *
 * @param int  $user_id     Author.
 * @param int  $campaign_id Campaign.
 * @param int  $entry_id    Entry (0 = none yet).
 * @param bool $ahead       The day has not opened (#209): only the groups the person starts.
 * @return array[] Rows of { id, name, status, day_key } where status is '' | shared | scheduled | hidden.
 */
function jlife_challenges_share_groups( $user_id, $campaign_id, $entry_id = 0, $ahead = false ) {
	$out = array();
	foreach ( jlife_challenges_active_memberships( (int) $campaign_id, (int) $user_id ) as $m ) {
		if ( $ahead && 'starter' !== $m['role'] ) {
			continue;
		}
		$share = $entry_id > 0 ? jlife_challenges_current_share( $user_id, (int) $entry_id, (int) $m['group_id'] ) : null;
		$out[] = array(
			'id'      => (int) $m['group_id'],
			'name'    => (string) $m['name'],
			'status'  => $share ? (string) $share['status'] : '',
			'day_key' => $share ? (string) $share['day_key'] : '',
		);
	}
	return $out;
}

/**
 * Data for /share?day=dNNN: the author's entry (shareable once it has text) and,
 * for each group the author is in, the existing post and whether posting there
 * is allowed. A solo reader has no destinations (the chat share needs none).
 *
 * @param int         $user_id Author.
 * @param string|null $day_key Day.
 * @return array Day view plus: shareable, destinations [ { group_id, group_name, share, can_post, post_block } ].
 */
function jlife_challenges_share_view( $user_id, $day_key ) {
	$view = jlife_challenges_day_view( $user_id, $day_key );
	if ( 'active' !== $view['state'] ) {
		return $view;
	}
	$entry                = $view['entry'];
	$view['shareable']    = $entry && ( '' !== trim( (string) $entry['body'] ) || ( jlife_challenges_campaign_accepts_photos( $view['campaign'] ) && jlife_challenges_entry_has_photo( (int) $entry['id'] ) ) );
	$view['destinations'] = array();
	if ( ! $view['shareable'] ) {
		return $view;
	}
	$enrollment = jlife_challenges_active_enrollment( (int) $view['campaign']['id'], $user_id );
	if ( ! $enrollment ) {
		return $view;
	}
	foreach ( jlife_challenges_active_memberships( (int) $view['campaign']['id'], $user_id ) as $m ) {
		if ( ! empty( $view['ahead'] ) && 'starter' !== $m['role'] ) {
			continue; // Before the day opens only the groups the person starts (#209).
		}
		$share = jlife_challenges_current_share( $user_id, (int) $entry['id'], (int) $m['group_id'] );
		$block = '';
		if ( jlife_challenges_needs_guardian_consent( $enrollment ) ) {
			$block = 'guardian';
		} elseif ( $share && 'hidden' === $share['status'] ) {
			$block = 'hidden';
		}
		$view['destinations'][] = array(
			'group_id'   => (int) $m['group_id'],
			'group_name' => (string) $m['name'],
			'share'      => $share,
			'can_post'   => '' === $block,
			'post_block' => $block,
		);
	}
	return $view;
}

/**
 * The text for "Send to a chat": the day, the reading references, the author's
 * whole entry and a public link to that day's reading page. Never a sign-in
 * code, session, invite or anyone else's data.
 *
 * @param array $view Share view (active).
 * @return string
 */
function jlife_challenges_chat_text( $view ) {
	$day  = $view['reading']['day'] ?? array();
	$refs = array_map( static fn( $p ) => (string) $p['display'], (array) ( $day['passages'] ?? array() ) );
	/* translators: 1: day number, 2: Bible references. */
	$lines = array( sprintf( __( 'Bible Marathon · Day %1$d · %2$s', 'jlife-challenges' ), (int) $view['index'], implode( '; ', $refs ) ) );
	$body  = $view['entry'] ? trim( (string) $view['entry']['body'] ) : '';
	if ( '' !== $body ) {
		$lines[] = '';
		$lines[] = $body;
	}
	$url = jlife_challenges_public_reading_url( $view );
	if ( '' !== $url ) {
		$lines[] = '';
		/* translators: %s: link to the day's reading. */
		$lines[] = sprintf( __( "Today's reading: %s", 'jlife-challenges' ), $url );
	}
	return implode( "\n", $lines );
}

/**
 * The public, non-personal reading page for the view's day ('' if none).
 *
 * @param array $view Day view.
 * @return string
 */
function jlife_challenges_public_reading_url( $view ) {
	$url = (string) ( $view['reading']['url'] ?? '' );
	return 0 === strpos( $url, home_url( '/' ) ) ? strtok( $url, '?#' ) : '';
}

/**
 * Data for /group: one group's feed with day numbers, and the person's groups
 * for the picker. With no group asked for, the first group joined is shown.
 *
 * @param int $user_id  Viewer.
 * @param int $group_id Group asked for (0 = default).
 * @return array { state: not_enrolled|solo|denied|ok, groups?: [ { id, name, role, selected } ], group_id?, group_name?, role? (member|starter, the viewer's own), can_start?, posts? }
 */
function jlife_challenges_group_view( $user_id, $group_id = 0 ) {
	$pc = jlife_challenges_participant_campaign( $user_id );
	if ( ! $pc ) {
		return array( 'state' => 'not_enrolled' );
	}
	$campaign = $pc['campaign'];
	$mine     = jlife_challenges_active_memberships( (int) $campaign['id'], $user_id );
	if ( ! $mine ) {
		return array(
			'state'     => 'solo',
			'campaign'  => $campaign,
			'can_start' => jlife_challenges_can_start_group( $user_id ),
		);
	}
	$ids      = array_map( 'intval', array_column( $mine, 'group_id' ) );
	$group_id = (int) $group_id;
	$chosen   = $group_id > 0 ? $group_id : $ids[0];
	$groups   = array();
	foreach ( $mine as $m ) {
		$groups[] = array(
			'id'       => (int) $m['group_id'],
			'name'     => (string) $m['name'],
			'role'     => (string) $m['role'],
			'selected' => (int) $m['group_id'] === $chosen,
		);
	}
	// A group that is not one of the viewer's own is "not allowed", never named.
	$feed = jlife_challenges_group_feed( $user_id, $chosen );
	if ( is_wp_error( $feed ) || ! in_array( $chosen, $ids, true ) ) {
		return array(
			'state'  => 'denied',
			'groups' => $groups,
		);
	}
	$titles = array();
	foreach ( $feed as &$post ) {
		$post['index'] = jlife_challenges_day_index( $campaign, $post['day_key'] );
		if ( ! array_key_exists( $post['day_key'], $titles ) && function_exists( 'jlife_studies_get_reading_day' ) ) {
			$r                          = jlife_studies_get_reading_day( (string) $campaign['plan_id'], $post['day_key'] );
			$titles[ $post['day_key'] ] = $r ? implode( '; ', array_map( static fn( $p ) => (string) $p['display'], (array) ( $r['day']['passages'] ?? array() ) ) ) : '';
		}
		$post['refs'] = $titles[ $post['day_key'] ] ?? '';
	}
	unset( $post );
	$group = jlife_challenges_get_group( $chosen );
	$role  = '';
	foreach ( $groups as $g ) {
		$role = $g['selected'] ? $g['role'] : $role;
	}
	return array(
		'state'      => 'ok',
		'campaign'   => $campaign,
		'groups'     => $groups,
		'group_id'   => $chosen,
		'group_name' => $group ? (string) $group['name'] : '',
		'role'       => $role,
		'can_start'  => jlife_challenges_can_start_group( $user_id ),
		'posts'      => $feed,
	);
}

/**
 * The released thoughts of a group's starter for one day, for Today (#209): the
 * very same shared posts My Group lists first, one entry per group the viewer is
 * an active member of. Nothing that is still scheduled can appear: the query
 * reads only released (`shared`) posts of an advance share, after the release
 * check, and only for a day that has opened in that campaign. The author's own
 * post is not repeated for them. A group the viewer is not in contributes nothing.
 *
 * @param int    $viewer_user_id Viewer.
 * @param array  $campaign       Campaign row.
 * @param string $day_key        Day key.
 * @return array[] Rows of { group_id, group_name, author, body }, in the order of the viewer's groups.
 */
function jlife_challenges_leader_thoughts( $viewer_user_id, $campaign, $day_key ) {
	$index = jlife_challenges_day_index( $campaign, (string) $day_key );
	if ( (int) $viewer_user_id < 1 || $index < 1 || ! jlife_challenges_day_is_open( $campaign, $index ) ) {
		return array();
	}
	global $wpdb;
	$out = array();
	foreach ( jlife_challenges_active_memberships( (int) $campaign['id'], (int) $viewer_user_id ) as $m ) {
		if ( ! jlife_challenges_can_read_feed( $viewer_user_id, (int) $m['group_id'] ) ) {
			continue;
		}
		jlife_challenges_release_due_shares( (int) $m['group_id'] );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.NotPrepared -- custom table; name from $wpdb->prefix.
		$rows   = (array) $wpdb->get_results( $wpdb->prepare( 'SELECT id, user_id, body FROM ' . jlife_challenges_table( 'group_shares' ) . " WHERE group_id = %d AND campaign_id = %d AND day_key = %s AND status = 'shared' AND advance = 1 AND user_id <> %d ORDER BY updated DESC", (int) $m['group_id'], (int) $campaign['id'], (string) $day_key, (int) $viewer_user_id ), ARRAY_A );
		$photos = jlife_challenges_share_photo_ids( array_column( $rows, 'id' ) );
		foreach ( $rows as $r ) {
			$out[] = array(
				'group_id'   => (int) $m['group_id'],
				'group_name' => (string) $m['name'],
				'author'     => jlife_challenges_feed_name( (int) $r['user_id'] ),
				'body'       => (string) $r['body'],
				'photo_id'   => $photos[ (int) $r['id'] ] ?? 0,
			);
		}
	}
	return $out;
}
