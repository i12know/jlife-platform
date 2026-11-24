<?php
/**
 * The official Marathon campaign (docs/challenges-engine-design.md §3d, #180):
 * Dec 20, 2026 – Apr 2, 2027 (104 days), Pacific time, the same reading plan
 * as the pilot. An ordinary campaign: which campaign is current is still
 * decided only by the one rule in current-campaign.php (#177).
 *
 * @package jlife-challenges
 */

defined( 'ABSPATH' ) || exit;

/**
 * Does the site have a pilot campaign that new sign-ups can be routed by (any
 * status but draft)? Without one, an early official campaign takes every new
 * sign-up straight to Dec 20 (§3d, "Timing risk").
 *
 * @return bool
 */
function jlife_challenges_site_has_pilot() {
	global $wpdb;
	$t = jlife_challenges_table( 'campaigns' );
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- custom table; name from $wpdb->prefix.
	return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$t} WHERE campaign_key LIKE %s AND status <> 'draft'", $wpdb->esc_like( JLIFE_CHALLENGES_PILOT_PREFIX ) . '%' ) ) > 0;
}

/**
 * Create the official campaign (or find it again by its key). Running it again
 * changes nothing. It creates no groups and enrolls no one. Refused when the
 * site has no pilot campaign, unless forced (§3d).
 *
 * @param int   $actor Organizer.
 * @param array $args  Optional campaign_key, plan_id, title, start_date, end_date, scoring_engine (default soap-regex; new campaigns only), accepts_photos (default off; new campaigns only, #244).
 * @param bool  $force Create even when the site has no pilot campaign.
 * @return array|WP_Error { campaign_id, campaign_key, created, start_date, end_date }
 */
function jlife_challenges_create_official_campaign( $actor, $args = array(), $force = false ) {
	if ( ! jlife_challenges_is_organizer( $actor ) ) {
		return jlife_challenges_denied();
	}
	$args = wp_parse_args(
		$args,
		array(
			'campaign_key' => 'marathon-2026',
			'plan_id'      => 'rpl-life-and-teachings-of-jesus-2026',
			'title'        => 'Bible Marathon',
			'start_date'   => '2026-12-20',
			'end_date'     => '2027-04-02',
		)
	);
	$key  = sanitize_key( (string) $args['campaign_key'] );
	$bad  = '' === $key || 0 === strpos( $key, JLIFE_CHALLENGES_TEST_PREFIX ) || 0 === strpos( $key, JLIFE_CHALLENGES_PILOT_PREFIX );
	if ( $bad ) {
		return new WP_Error( 'jlife_campaign_invalid', 'The official campaign key must not be empty or start with "' . JLIFE_CHALLENGES_TEST_PREFIX . '" or "' . JLIFE_CHALLENGES_PILOT_PREFIX . '".' );
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
	if ( ! $force && ! jlife_challenges_site_has_pilot() ) {
		return new WP_Error( 'jlife_official_no_pilot', 'This site has no pilot campaign, so an official campaign made now would take every new sign-up straight to its start date. Create the pilot first, or pass --force if this site has no pilot on purpose.' );
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
