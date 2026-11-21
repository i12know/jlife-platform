<?php
/**
 * Earlier reading periods (#174, D4-A): a participant's own read-only history
 * of a campaign that has ended or been closed and is no longer their current
 * one (for LATOC, the Dec 5-13 practice week once the Marathon is current).
 *
 * - Shown from My Marathon (`/marathon/?past=<campaign id>`), only to the
 *   person themself: their own entries and their own shares, nothing else.
 * - Only campaigns the person has an active enrollment in, whose phase is
 *   `ended` or `closed` (docs/challenges-engine-design.md §3b) and which are
 *   not their current campaign. Anything else looks like "not found".
 * - Each live share has a "Remove my post" button: removing only reduces what
 *   others can see, so it is allowed even in a closed campaign (owner decision
 *   2026-11-20, §3c).
 * - Nothing here writes entries, shares, points or memberships.
 *
 * @package jlife-challenges
 */

defined( 'ABSPATH' ) || exit;

/**
 * Nonce action for "Remove my post" on an earlier reading period.
 */
const JLIFE_CHALLENGES_PAST_UNSHARE_NONCE = 'jlife_unshare_past';

/**
 * The person's earlier reading periods: campaigns of their active enrollments
 * that have ended or been closed and are not their current campaign. Most
 * recently ended first.
 *
 * @param int $user_id Participant.
 * @return array[] Campaign rows.
 */
function jlife_challenges_past_campaigns( $user_id ) {
	if ( $user_id < 1 ) {
		return array();
	}
	$current    = jlife_challenges_participant_campaign( $user_id );
	$current_id = $current ? (int) $current['campaign']['id'] : 0;
	$out        = array();
	foreach ( jlife_challenges_user_enrollments( $user_id ) as $e ) {
		$c = jlife_challenges_get_campaign( (int) $e['campaign_id'] );
		if ( ! $c || (int) $c['id'] === $current_id ) {
			continue;
		}
		if ( in_array( jlife_challenges_campaign_phase( $c ), array( 'ended', 'closed' ), true ) ) {
			$out[] = $c;
		}
	}
	usort(
		$out,
		static function ( $a, $b ) {
			return strcmp( (string) $b['end_date'], (string) $a['end_date'] );
		}
	);
	return $out;
}

/**
 * One of the person's earlier reading periods, or null (not theirs, still
 * current, running, upcoming or a draft).
 *
 * @param int $user_id     Participant.
 * @param int $campaign_id Campaign.
 * @return array|null Campaign row.
 */
function jlife_challenges_past_campaign_for( $user_id, $campaign_id ) {
	foreach ( jlife_challenges_past_campaigns( $user_id ) as $c ) {
		if ( (int) $c['id'] === (int) $campaign_id ) {
			return $c;
		}
	}
	return null;
}

/**
 * The person's own written entries in an earlier reading period, by day, each
 * with their own live or hidden shares (group name included: they were in
 * those groups). Author only; denied for anyone else's or a non-past campaign.
 *
 * @param int $user_id     Participant.
 * @param int $campaign_id Campaign.
 * @return array[]|WP_Error Rows of { day_key, index, date, status, body, shares: [ { id, group_name, status } ] }.
 */
function jlife_challenges_past_entries( $user_id, $campaign_id ) {
	$campaign = jlife_challenges_past_campaign_for( $user_id, $campaign_id );
	if ( ! $campaign ) {
		return jlife_challenges_denied();
	}
	global $wpdb;
	$rows   = (array) $wpdb->get_results( $wpdb->prepare( 'SELECT id, user_id, day_key, status, body FROM ' . jlife_challenges_table( 'soap_entries' ) . ' WHERE user_id = %d AND campaign_id = %d ORDER BY day_key ASC', (int) $user_id, (int) $campaign_id ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.NotPrepared -- custom table; name from $wpdb->prefix.
	$shares = jlife_challenges_my_shares( (int) $user_id, (int) $campaign_id );
	$by_day = array();
	foreach ( is_wp_error( $shares ) ? array() : $shares as $s ) {
		$group = jlife_challenges_get_group( (int) $s['group_id'] );
		$share = array(
			'id'         => (int) $s['id'],
			'group_name' => $group ? (string) $group['name'] : '',
			'status'     => (string) $s['status'],
		);

		$by_day[ (string) $s['day_key'] ][] = $share;
	}
	$out = array();
	foreach ( $rows as $r ) {
		if ( ! jlife_challenges_can_read_entry( $user_id, $r ) || '' === trim( (string) $r['body'] ) ) {
			continue;
		}
		$index = jlife_challenges_day_index( $campaign, (string) $r['day_key'] );
		$out[] = array(
			'day_key' => (string) $r['day_key'],
			'index'   => (int) $index,
			'date'    => $index ? (string) jlife_challenges_day_date( $campaign, (int) $index ) : '',
			'status'  => (string) $r['status'],
			'body'    => (string) $r['body'],
			'shares'  => $by_day[ (string) $r['day_key'] ] ?? array(),
		);
	}
	return $out;
}

/**
 * The share IDs the person may remove from an earlier reading period: their
 * own live or hidden shares there.
 *
 * @param int $user_id     Participant.
 * @param int $campaign_id Campaign.
 * @return int[]
 */
function jlife_challenges_past_share_ids( $user_id, $campaign_id ) {
	$entries = jlife_challenges_past_entries( $user_id, $campaign_id );
	if ( is_wp_error( $entries ) ) {
		return array();
	}
	$ids = array();
	foreach ( $entries as $e ) {
		foreach ( $e['shares'] as $s ) {
			$ids[] = (int) $s['id'];
		}
	}
	return $ids;
}

/**
 * Handle "Remove my post" on an earlier reading period. Only the person's own
 * share in that period. Redirects after a removal.
 *
 * @param int $user_id     Participant.
 * @param int $campaign_id Campaign.
 * @return string Error message ('' when there was no attempt).
 */
function jlife_challenges_handle_past_unshare_post( $user_id, $campaign_id ) {
	if ( ! isset( $_POST['jl_unshare_past'] ) || ! check_admin_referer( JLIFE_CHALLENGES_PAST_UNSHARE_NONCE, '_jlife_unshare_past' ) ) {
		return '';
	}
	$share_id = absint( wp_unslash( $_POST['jl_unshare_past'] ) );
	$r        = in_array( $share_id, jlife_challenges_past_share_ids( $user_id, $campaign_id ), true )
		? jlife_challenges_unshare( $user_id, $share_id )
		: jlife_challenges_denied();
	if ( is_wp_error( $r ) ) {
		return $r->get_error_message();
	}
	wp_safe_redirect(
		add_query_arg(
			array(
				'past'    => (int) $campaign_id,
				'removed' => 1,
			),
			jlife_challenges_screen_url( 'marathon' )
		)
	);
	exit;
}

/**
 * A campaign's date range for display.
 *
 * @param array $campaign Campaign row.
 * @return string
 */
function jlife_challenges_past_dates( $campaign ) {
	/* translators: 1: first day's date, 2: last day's date. */
	return sprintf( __( '%1$s to %2$s', 'jlife-challenges' ), jlife_challenges_format_date( (string) $campaign['start_date'] ), jlife_challenges_format_date( (string) $campaign['end_date'] ) );
}

/**
 * My Marathon's "Earlier reading" section: one link per earlier reading
 * period. Empty when there is none.
 *
 * @param int $user_id Participant.
 * @return string Escaped HTML.
 */
function jlife_challenges_render_past_section( $user_id ) {
	$past = jlife_challenges_past_campaigns( $user_id );
	if ( ! $past ) {
		return '';
	}
	$items = '';
	foreach ( $past as $c ) {
		$url    = add_query_arg( 'past', (int) $c['id'], jlife_challenges_screen_url( 'marathon' ) );
		$items .= '<li><a href="' . esc_url( $url ) . '">' . esc_html( (string) $c['title'] ) . '</a> · ' . esc_html( jlife_challenges_past_dates( $c ) ) . '</li>';
	}
	return '<section class="jl-left jl-past"><h2>' . esc_html__( 'Earlier reading', 'jlife-challenges' ) . '</h2>'
		. '<p>' . esc_html__( 'Your SOAP from an earlier reading period. Only you can see it here.', 'jlife-challenges' ) . '</p>'
		. '<ul>' . $items . '</ul></section>';
}

/**
 * The page for one earlier reading period: the person's own SOAP, read-only,
 * and their shares with "Remove my post". Sends the page and stops.
 *
 * @param int $user_id     Participant.
 * @param int $campaign_id Campaign.
 */
function jlife_challenges_screen_past( $user_id, $campaign_id ) {
	$title    = __( 'Earlier reading', 'jlife-challenges' );
	$back     = '<p><a href="' . esc_url( jlife_challenges_screen_url( 'marathon' ) ) . '">' . esc_html__( 'Back to My Marathon', 'jlife-challenges' ) . '</a></p>';
	$campaign = jlife_challenges_past_campaign_for( $user_id, $campaign_id );
	if ( ! $campaign ) {
		jlife_challenges_send_page( $title, '<h1>' . esc_html( $title ) . '</h1><p>' . esc_html( jlife_challenges_denied()->get_error_message() ) . '</p>' . $back, 'marathon' );
		return;
	}
	$err     = jlife_challenges_handle_past_unshare_post( $user_id, (int) $campaign_id );
	$entries = jlife_challenges_past_entries( $user_id, (int) $campaign_id );
	$entries = is_wp_error( $entries ) ? array() : $entries;
	$body    = '<h1>' . esc_html( (string) $campaign['title'] ) . '</h1>'
		. '<p class="jl-hint">' . esc_html( jlife_challenges_past_dates( $campaign ) ) . '</p>'
		. '<p>' . esc_html__( 'This reading period has ended. Your SOAP is kept here for you to read; it can no longer be changed. You can still remove anything you shared with a group.', 'jlife-challenges' ) . '</p>'
		. ( '' !== $err ? '<p class="jl-err" role="alert">' . esc_html( $err ) . '</p>' : '' )
		. ( isset( $_GET['removed'] ) ? '<p class="jl-ok" role="status">' . esc_html__( 'Removed. Your SOAP and points are unchanged.', 'jlife-challenges' ) . '</p>' : '' ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display flag after a redirect.
	if ( ! $entries ) {
		$body .= '<p>' . esc_html__( 'You did not write any SOAP in this reading period.', 'jlife-challenges' ) . '</p>';
	}
	foreach ( $entries as $e ) {
		/* translators: 1: day number, 2: date. */
		$head  = sprintf( __( 'Day %1$d · %2$s', 'jlife-challenges' ), $e['index'], '' !== $e['date'] ? jlife_challenges_format_date( $e['date'] ) : '' );
		$body .= '<section class="jl-left jl-past-day"><h2>' . esc_html( $head ) . '</h2>'
			. ( 'submitted' === $e['status'] ? '' : '<p class="jl-hint">' . esc_html__( 'draft', 'jlife-challenges' ) . '</p>' )
			. '<div class="jl-preview">' . esc_html( trim( $e['body'] ) ) . '</div>';
		$live  = array_filter(
			$e['shares'],
			static function ( $s ) {
				return in_array( $s['status'], array( 'shared', 'hidden' ), true );
			}
		);
		if ( $live ) {
			$body .= '<form method="post">' . wp_nonce_field( JLIFE_CHALLENGES_PAST_UNSHARE_NONCE, '_jlife_unshare_past', true, false ) . '<ul>';
			foreach ( $live as $s ) {
				/* translators: %s: group name. */
				$label = sprintf( __( 'Shared with %s', 'jlife-challenges' ), '' !== $s['group_name'] ? $s['group_name'] : __( 'A group', 'jlife-challenges' ) );
				$body .= '<li>' . esc_html( $label )
					. ( 'hidden' === $s['status'] ? ' · ' . esc_html__( 'hidden by an organizer', 'jlife-challenges' ) : '' )
					. ' <button type="submit" name="jl_unshare_past" value="' . esc_attr( (string) $s['id'] ) . '" class="jl-button jl-small" formnovalidate>' . esc_html__( 'Remove my post', 'jlife-challenges' ) . '</button></li>';
			}
			$body .= '</ul></form>';
		}
		$body .= '</section>';
	}
	jlife_challenges_send_page( $title, $body . $back, 'marathon' );
}
