<?php
/**
 * View models for sharing (M7, #62): the share page and the My Group feed.
 * Pure data, no output. Sharing is always a deliberate choice (the box on the
 * SOAP form, or /share) and never changes points. The whole entry is shared
 * (#98); there are no per-part choices.
 *
 * @package jlife-challenges
 */

defined( 'ABSPATH' ) || exit;

/**
 * The author's live share of one entry in their current group, or null.
 *
 * @param int $user_id  Author.
 * @param int $entry_id Entry.
 * @param int $group_id Current group.
 * @return array|null Share row (any status except removed).
 */
function jlife_challenges_current_share( $user_id, $entry_id, $group_id ) {
	global $wpdb;
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.NotPrepared -- custom table; name from $wpdb->prefix.
	$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . jlife_challenges_table( 'group_shares' ) . " WHERE user_id = %d AND entry_id = %d AND group_id = %d AND status <> 'removed'", (int) $user_id, (int) $entry_id, (int) $group_id ), ARRAY_A );
	return $row ? $row : null;
}

/**
 * Data for /share?day=dNNN: the author's entry (shareable once it has text),
 * the existing group post, and whether group posting is allowed.
 *
 * @param int         $user_id Author.
 * @param string|null $day_key Day.
 * @return array Day view plus: shareable, share, can_post, post_block.
 */
function jlife_challenges_share_view( $user_id, $day_key ) {
	$view = jlife_challenges_day_view( $user_id, $day_key );
	if ( 'active' !== $view['state'] ) {
		return $view;
	}
	$entry              = $view['entry'];
	$view['shareable']  = $entry && '' !== trim( (string) $entry['body'] );
	$view['share']      = null;
	$view['can_post']   = false;
	$view['post_block'] = '';
	if ( ! $view['shareable'] ) {
		return $view;
	}
	$enrollment = jlife_challenges_active_enrollment( (int) $view['campaign']['id'], $user_id );
	if ( $enrollment ) {
		$view['share'] = jlife_challenges_current_share( $user_id, (int) $entry['id'], (int) $enrollment['group_id'] );
		if ( jlife_challenges_needs_guardian_consent( $enrollment ) ) {
			$view['post_block'] = 'guardian';
		} elseif ( $view['share'] && 'hidden' === $view['share']['status'] ) {
			$view['post_block'] = 'hidden';
		} else {
			$view['can_post'] = true;
		}
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
 * Data for /group: the viewer's current group feed with day numbers.
 *
 * @param int $user_id Viewer.
 * @return array { state: not_enrolled|denied|ok, group_name?, posts? }
 */
function jlife_challenges_group_view( $user_id ) {
	$pc = jlife_challenges_participant_campaign( $user_id );
	if ( ! $pc ) {
		return array( 'state' => 'not_enrolled' );
	}
	$campaign = $pc['campaign'];
	$feed     = jlife_challenges_group_feed( $user_id, (int) $campaign['id'] );
	if ( is_wp_error( $feed ) ) {
		return array( 'state' => 'denied' );
	}
	$group  = jlife_challenges_get_group( (int) $pc['enrollment']['group_id'] );
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
	return array(
		'state'      => 'ok',
		'campaign'   => $campaign,
		'group_name' => $group ? (string) $group['name'] : '',
		'posts'      => $feed,
	);
}
