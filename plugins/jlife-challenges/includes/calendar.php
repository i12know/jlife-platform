<?php
/**
 * Campaign calendar (docs/challenges-engine-design.md §3).
 *
 * The campaign day comes from the server clock converted to the campaign's
 * timezone, never the device clock. Day arithmetic is done on calendar dates
 * (UTC midnight), so DST changes cannot shift a day.
 *
 * @package jlife-challenges
 */

defined( 'ABSPATH' ) || exit;

/**
 * Current time (overridable in tests through the filter).
 *
 * @return DateTimeImmutable
 */
function jlife_challenges_now() {
	$now = apply_filters( 'jlife_challenges_now', null );
	return $now instanceof DateTimeImmutable ? $now : new DateTimeImmutable( 'now', new DateTimeZone( 'UTC' ) );
}

/**
 * Calendar date (Y-m-d) of a moment in the campaign's timezone.
 *
 * @param array             $campaign Campaign row.
 * @param DateTimeImmutable $moment   Moment.
 * @return string
 */
function jlife_challenges_local_date( $campaign, DateTimeImmutable $moment ) {
	return $moment->setTimezone( new DateTimeZone( (string) $campaign['timezone'] ) )->format( 'Y-m-d' );
}

/**
 * Whole days from one Y-m-d date to another.
 *
 * @param string $from Y-m-d.
 * @param string $to   Y-m-d.
 * @return int
 */
function jlife_challenges_days_between( $from, $to ) {
	$utc = new DateTimeZone( 'UTC' );
	$a   = new DateTimeImmutable( $from . ' 00:00:00', $utc );
	$b   = new DateTimeImmutable( $to . ' 00:00:00', $utc );
	return (int) round( ( $b->getTimestamp() - $a->getTimestamp() ) / DAY_IN_SECONDS );
}

/**
 * Number of days in a campaign.
 *
 * @param array $campaign Campaign row.
 * @return int
 */
function jlife_challenges_day_count( $campaign ) {
	return jlife_challenges_days_between( (string) $campaign['start_date'], (string) $campaign['end_date'] ) + 1;
}

/**
 * Day key (d001…) for a day index.
 *
 * @param int $index 1-based day index.
 * @return string
 */
function jlife_challenges_day_key( $index ) {
	return sprintf( 'd%03d', (int) $index );
}

/**
 * Day index for a day key, or 0 if the key is malformed or outside the campaign.
 *
 * @param array  $campaign Campaign row.
 * @param string $day_key  e.g. 'd038'.
 * @return int
 */
function jlife_challenges_day_index( $campaign, $day_key ) {
	if ( ! preg_match( '/^d([0-9]{3})$/', (string) $day_key, $m ) ) {
		return 0;
	}
	$i = (int) $m[1];
	return ( $i >= 1 && $i <= jlife_challenges_day_count( $campaign ) ) ? $i : 0;
}

/**
 * Calendar date (Y-m-d) of a campaign day.
 *
 * @param array $campaign Campaign row.
 * @param int   $index    1-based day index.
 * @return string
 */
function jlife_challenges_day_date( $campaign, $index ) {
	$start = new DateTimeImmutable( (string) $campaign['start_date'] . ' 00:00:00', new DateTimeZone( 'UTC' ) );
	return $start->modify( '+' . ( (int) $index - 1 ) . ' days' )->format( 'Y-m-d' );
}

/**
 * The campaign day for a moment: 1…day_count, or 0 outside the campaign.
 *
 * @param array                  $campaign Campaign row.
 * @param DateTimeImmutable|null $moment   Moment (default: now).
 * @return int
 */
function jlife_challenges_current_day( $campaign, $moment = null ) {
	$date  = jlife_challenges_local_date( $campaign, $moment ?? jlife_challenges_now() );
	$index = jlife_challenges_days_between( (string) $campaign['start_date'], $date ) + 1;
	return ( $index >= 1 && $index <= jlife_challenges_day_count( $campaign ) ) ? $index : 0;
}

/**
 * Has a campaign day opened yet (its date is today or earlier, campaign time)?
 *
 * @param array                  $campaign Campaign row.
 * @param int                    $index    Day index.
 * @param DateTimeImmutable|null $moment   Moment (default: now).
 * @return bool
 */
function jlife_challenges_day_is_open( $campaign, $index, $moment = null ) {
	$today = jlife_challenges_local_date( $campaign, $moment ?? jlife_challenges_now() );
	return jlife_challenges_days_between( jlife_challenges_day_date( $campaign, $index ), $today ) >= 0;
}

/**
 * Is a submission at this moment on time for the day (D1: by the end of
 * day N + grace_days, campaign time)?
 *
 * @param array                  $campaign Campaign row.
 * @param int                    $index    Day index.
 * @param DateTimeImmutable|null $moment   Submission moment (default: now).
 * @return bool
 */
function jlife_challenges_is_on_time( $campaign, $index, $moment = null ) {
	$today = jlife_challenges_local_date( $campaign, $moment ?? jlife_challenges_now() );
	$late  = jlife_challenges_days_between( jlife_challenges_day_date( $campaign, $index ), $today );
	return $late >= 0 && $late <= (int) $campaign['grace_days'];
}
