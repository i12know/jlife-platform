<?php
/**
 * The Dec 5–13, 2026 pilot ("practice week"): its campaign, the practice banner
 * and the "I'll start Dec 20" flag (#178, part of #174).
 *
 * - The pilot is an ordinary campaign whose key starts with `pilot-` (never
 *   `test-`, so the staging cleanup cannot touch it). Which campaign is current
 *   is still decided only by the one rule in current-campaign.php (#177).
 * - The practice banner shows on the signed-in screens while the pilot is the
 *   person's current campaign.
 * - "I'll start Dec 20" is a per-user flag. It enrolls no one: the rollover
 *   (#180) reads it and enrolls those people in the official campaign.
 *
 * @package jlife-challenges
 */

defined( 'ABSPATH' ) || exit;

const JLIFE_CHALLENGES_PILOT_PREFIX = 'pilot-';

/**
 * User meta: the person chose to wait for the official Marathon (value: UTC
 * time the choice was recorded). Not a consent and not an enrollment.
 */
const JLIFE_CHALLENGES_WAIT_META = 'jlife_start_official';

/**
 * User option (per site, so a choice made on one site of a network never
 * counts on another): the age band given with "I'll start Dec 20", for the
 * rollover (#180), which has no enrollment to read it from.
 */
const JLIFE_CHALLENGES_WAIT_BAND_OPTION = 'jlife_start_official_band';

/**
 * Is this the pilot (key starts with `pilot-`)?
 *
 * @param array $campaign Campaign row.
 * @return bool
 */
function jlife_challenges_is_pilot_campaign( $campaign ) {
	return 0 === strpos( (string) ( $campaign['campaign_key'] ?? '' ), JLIFE_CHALLENGES_PILOT_PREFIX );
}

/**
 * Create the pilot campaign (or find it again): Dec 5–13, 2026, Pacific time,
 * active, same reading plan as the Marathon. Running it again changes nothing.
 * It creates no groups and enrolls no one.
 *
 * @param int   $actor Organizer.
 * @param array $args  Optional campaign_key, plan_id, title, start_date, end_date, scoring_engine (default soap-regex; new campaigns only).
 * @return array|WP_Error { campaign_id, campaign_key, created, start_date, end_date }
 */
function jlife_challenges_create_pilot_campaign( $actor, $args = array() ) {
	if ( ! jlife_challenges_is_organizer( $actor ) ) {
		return jlife_challenges_denied();
	}
	$args = wp_parse_args(
		$args,
		array(
			'campaign_key' => JLIFE_CHALLENGES_PILOT_PREFIX . '2026-12',
			'plan_id'      => 'rpl-life-and-teachings-of-jesus-2026',
			'title'        => 'Bible Marathon practice week',
			'start_date'   => '2026-12-05',
			'end_date'     => '2026-12-13',
		)
	);
	$key  = sanitize_key( (string) $args['campaign_key'] );
	if ( 0 !== strpos( $key, JLIFE_CHALLENGES_PILOT_PREFIX ) || strlen( $key ) <= strlen( JLIFE_CHALLENGES_PILOT_PREFIX ) ) {
		return new WP_Error( 'jlife_campaign_invalid', 'The pilot campaign key must start with "' . JLIFE_CHALLENGES_PILOT_PREFIX . '".' );
	}
	$start = (string) $args['start_date'];
	$end   = (string) $args['end_date'];
	if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $start ) || ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $end ) || $start > $end ) {
		return new WP_Error( 'jlife_campaign_invalid', 'The pilot dates must be YYYY-MM-DD, with the start on or before the end.' );
	}
	$engine = (string) ( $args['scoring_engine'] ?? JLIFE_CHALLENGES_DEFAULT_ENGINE );
	if ( ! isset( jlife_challenges_scoring_engines()[ $engine ] ) ) {
		return new WP_Error( 'jlife_campaign_invalid', 'Unknown scoring engine "' . $engine . '". Known: ' . implode( ', ', array_keys( jlife_challenges_scoring_engines() ) ) . '.' );
	}
	global $wpdb;
	$t = jlife_challenges_table( 'campaigns' );
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- custom table; name from $wpdb->prefix.
	$existing = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$t} WHERE campaign_key = %s", $key ), ARRAY_A );
	if ( $existing ) {
		return array(
			'campaign_id'  => (int) $existing['id'],
			'campaign_key' => $key,
			'created'      => false,
			'start_date'   => (string) $existing['start_date'],
			'end_date'     => (string) $existing['end_date'],
		);
	}
	$id = jlife_challenges_create_campaign(
		$actor,
		array(
			'campaign_key'   => $key,
			'plan_id'        => (string) $args['plan_id'],
			'title'          => (string) $args['title'],
			'start_date'     => (string) $args['start_date'],
			'end_date'       => (string) $args['end_date'],
			'status'         => 'active',
			'scoring_engine' => $engine,
			'accepts_photos' => ! empty( $args['accepts_photos'] ) ? 1 : 0,
		)
	);
	if ( is_wp_error( $id ) ) {
		return $id;
	}
	return array(
		'campaign_id'  => (int) $id,
		'campaign_key' => $key,
		'created'      => true,
		'start_date'   => (string) $args['start_date'],
		'end_date'     => (string) $args['end_date'],
	);
}

/**
 * The practice banner for a signed-in screen: only on Today, My Marathon and My
 * Group (which also frames /share), and only while the pilot is the person's
 * current campaign and has not ended.
 *
 * @param int    $user_id Signed-in user (0 = nobody).
 * @param string $active  Active nav item.
 * @return string Escaped HTML.
 */
function jlife_challenges_practice_banner( $user_id, $active ) {
	if ( $user_id < 1 || ! in_array( $active, array( 'today', 'marathon', 'group' ), true ) ) {
		return '';
	}
	$current = jlife_challenges_participant_campaign( $user_id );
	if ( ! $current || ! jlife_challenges_is_pilot_campaign( $current['campaign'] )
		|| ! in_array( jlife_challenges_campaign_phase( $current['campaign'] ), array( 'running', 'upcoming' ), true ) ) {
		return '';
	}
	return '<p class="jl-hint jl-practice" role="note">' . esc_html__( "Practice week: Dec 5–13. Points don't count for prizes. The Marathon starts Dec 20.", 'jlife-challenges' ) . '</p>';
}

/**
 * Record that the person will join the official Marathon (Dec 20) rather than
 * the pilot, with the age band they gave, on this site. Idempotent (the first
 * time and the first band are kept). It enrolls no one and records no consent.
 *
 * @param int    $user_id  Participant.
 * @param string $age_band adult|minor, as given on the join form ('' = not given).
 */
function jlife_challenges_set_waiting_for_official( $user_id, $age_band = '' ) {
	if ( ! jlife_challenges_is_waiting_for_official( $user_id ) ) {
		update_user_meta( $user_id, JLIFE_CHALLENGES_WAIT_META, current_time( 'mysql', true ) );
	}
	if ( in_array( $age_band, JLIFE_CHALLENGES_AGE_BANDS, true ) && '' === jlife_challenges_waiting_age_band( $user_id ) ) {
		update_user_option( $user_id, JLIFE_CHALLENGES_WAIT_BAND_OPTION, $age_band );
	}
}

/**
 * The age band given with "I'll start Dec 20" on this site, or '' (no choice
 * here, or one recorded before the band was kept).
 *
 * @param int $user_id Participant.
 * @return string adult|minor|''
 */
function jlife_challenges_waiting_age_band( $user_id ) {
	$band = (string) get_user_option( JLIFE_CHALLENGES_WAIT_BAND_OPTION, (int) $user_id );
	return in_array( $band, JLIFE_CHALLENGES_AGE_BANDS, true ) ? $band : '';
}

/**
 * Did the person choose "I'll start Dec 20"?
 *
 * @param int $user_id Participant.
 * @return bool
 */
function jlife_challenges_is_waiting_for_official( $user_id ) {
	return '' !== (string) get_user_meta( $user_id, JLIFE_CHALLENGES_WAIT_META, true );
}

/**
 * Everyone who chose "I'll start Dec 20" (for the rollover, #180).
 *
 * @return int[]
 */
function jlife_challenges_waiting_for_official_ids() {
	return array_map(
		'intval',
		get_users(
			array(
				'meta_key'     => JLIFE_CHALLENGES_WAIT_META, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- a rare, small, owner-run lookup.
				'meta_compare' => 'EXISTS',
				'fields'       => 'ID',
			)
		)
	);
}
