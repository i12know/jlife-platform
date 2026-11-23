	$index = null === $day_key ? $today : jlife_challenges_day_index( $campaign, $day_key );
	// A day that has not opened is readable only by an active starter of an active group (#209).
	$ahead = $index >= 1 && ! jlife_challenges_day_is_open( $campaign, $index );
	if ( $index < 1 || ( $ahead && ! jlife_challenges_can_read_ahead( (int) $user_id, $campaign ) ) ) {
		return array_merge( $base, array( 'state' => 'unavailable' ) );
	}
<?php
/**
 * View models for the participant screens (M6, #61). Pure data, no output,
 * so each screen's content is testable. Every value comes through the gate.
 *
 * @package jlife-challenges
 */

defined( 'ABSPATH' ) || exit;

/**
 * Languages in display order: the given language first, the others after it.
 *
 * @param array  $links_config Language => config from the reading plan.
 * @param string $first        The language to put first ("en" or "vi").
 * @return string[] Language keys.
 */
function jlife_challenges_order_languages( $links_config, $first ) {
	$langs = array_keys( (array) $links_config );
	usort(
		$langs,
		static fn( $a, $b ) => ( $b === $first ) <=> ( $a === $first )
	);
	return $langs;
}

/**
 * Languages in display order: the participant's language first.
 *
 * @param int   $user_id      Participant.
 * @param array $links_config Language => config from the reading plan.
 * @return string[] Language keys.
 */
function jlife_challenges_language_order( $user_id, $links_config ) {
	$locale = (string) get_user_meta( $user_id, 'locale', true );
	$first  = 0 === strpos( $locale, 'vi' ) ? 'vi' : 'en';
	return jlife_challenges_order_languages( $links_config, $first );
}

/**
 * One day's screen data: the reading, the participant's entry and status.
 *
 * @param int         $user_id Participant.
 * @param string|null $day_key Day (null = today).
 * @return array { state: not_enrolled|before|after|active|unavailable, ... }
 */
function jlife_challenges_day_view( $user_id, $day_key = null ) {
	$pc = jlife_challenges_participant_campaign( $user_id );
	if ( ! $pc ) {
		return array( 'state' => 'not_enrolled' );
	}
	$campaign = $pc['campaign'];
	$today    = jlife_challenges_current_day( $campaign );
	$base     = array(
		'campaign'  => $campaign,
		'today'     => $today,
		'day_count' => jlife_challenges_day_count( $campaign ),
		'points'    => (int) jlife_challenges_progress( $user_id, $user_id, (int) $campaign['id'] )['points'],
		'closed'    => ! jlife_challenges_campaign_accepts_writes( $campaign ),
		'can_ahead' => jlife_challenges_can_read_ahead( (int) $user_id, $campaign ),
	);
	if ( null === $day_key && 0 === $today ) {
		$local = jlife_challenges_local_date( $campaign, jlife_challenges_now() );
		return array_merge(
			$base,
			array(
				'state'      => $local < $campaign['start_date'] ? 'before' : 'after',
				'start_date' => $campaign['start_date'],
			)
		);
	}
	$index = null === $day_key ? $today : jlife_challenges_day_index( $campaign, $day_key );
	// A day that has not opened is readable only by an active starter of an active group (#209).
	$ahead = $index >= 1 && ! jlife_challenges_day_is_open( $campaign, $index );
	if ( $index < 1 || ( $ahead && ! jlife_challenges_can_read_ahead( (int) $user_id, $campaign ) ) ) {
		return array_merge( $base, array( 'state' => 'unavailable' ) );
	}
	$key     =jlife_challenges_day_key( $index );
	$reading = function_exists( 'jlife_studies_get_reading_day' ) ? jlife_studies_get_reading_day( (string) $campaign['plan_id'], $key ) : null;
	$entry   = jlife_challenges_find_entry( $user_id, (int) $campaign['id'], $key );
	$groups  = jlife_challenges_share_groups( $user_id, (int) $campaign['id'], $entry ? (int) $entry['id'] : 0, $ahead );
	$ledger  = jlife_challenges_progress( $user_id, $user_id, (int) $campaign['id'] )['days'][ $key ] ?? '';
	return array_merge(
		$base,
		array(
			'state'           => 'active',
			'index'           => $index,
			'day_key'         => $key,
			'date'            => jlife_challenges_day_date( $campaign, $index ),
			'is_today'        => $index === $today,
			'on_time'         => $ahead || jlife_challenges_is_on_time( $campaign, $index ),
			'ahead'           => $ahead,
			'release_label'   => $ahead ? jlife_challenges_release_label( $campaign, $index ) : '',
			'leader_thoughts' => $ahead ? array() : jlife_challenges_leader_thoughts( $user_id, $campaign, $key ),
			'reading'         => $reading,
			'languages'       => $reading ? jlife_challenges_language_order( $user_id, $reading['plan']['links_config'] ?? array() ) : array(),
			'entry'           => $entry,
			'share_groups'    => $groups,
			'status'          => $entry ? ( 'submitted' === $entry['status'] ? ( $ledger ? $ledger : 'submitted' ) : 'draft' ) : 'not_started',
		)
	);
}

/**
 * The adjacent days this participant may read from an active day view (#225).
 *
 * The same rule as direct ?day= access: the day must exist in the campaign and
 * jlife_challenges_day_is_open(). Reading is not scoring, so an earlier day stays
 * linked after its deadline. A locked neighbor is 0, never skipped over.
 *
 * @param array $view Day view.
 * @return int[] { prev, next }: the day index, or 0 for no link.
 */
function jlife_challenges_adjacent_days( $view ) {
	$none = array(
		'prev' => 0,
		'next' => 0,
	);
	if ( 'active' !== ( $view['state'] ?? '' ) ) {
		return $none;
	}
	$campaign = $view['campaign'];
	$index    = (int) $view['index'];
	$result   = array();
	foreach ( array(
		'prev' => $index - 1,
		'next' => $index + 1,
	) as $side => $other ) {
		$result[ $side ] = ( $other >= 1 && $other <= jlife_challenges_day_count( $campaign ) && ( ! empty( $view['can_ahead'] ) || jlife_challenges_day_is_open( $campaign, $other ) ) ) ? $other : 0;
	}
	return $result;
}

/**
 * The 104-day grid for My Marathon.
 *
 * Cell states: earned, late (submitted after the window), draft, open (not
 * done yet, still earnable), not_done (past and not done — shown neutrally),
 * today, future.
 *
 * @param int $user_id Participant.
 * @return array|null { campaign, points, day_count, today, cells: [ {index, day_key, date, state} ] }
 */
function jlife_challenges_marathon_view( $user_id ) {
	$pc = jlife_challenges_participant_campaign( $user_id );
	if ( ! $pc ) {
		return null;
	}
	$c        = $pc['campaign'];
	$progress = jlife_challenges_progress( $user_id, $user_id, (int) $c['id'] );
	$today    = jlife_challenges_current_day( $c );
	$started  = jlife_challenges_local_date( $c, jlife_challenges_now() ) >= $c['start_date'];
	$count    = jlife_challenges_day_count( $c );
	$last     = $today ? $today : ( $started ? $count : 0 );
	$ahead    = jlife_challenges_can_read_ahead( (int) $user_id, $c ); // #209: an active starter may open days that have not opened.
	$cells    = array();
	for ( $i = 1; $i <= $count; $i++ ) {
		$key    = jlife_challenges_day_key( $i );
		$ledger = $progress['days'][ $key ] ?? '';
		if ( 'earned' === $ledger ) {
			$state = 'earned';
		} elseif ( $ledger ) {
			$state = 'late';
			} elseif ( $i > $last ) {
			$state = $ahead && jlife_challenges_find_entry( $user_id, (int) $c['id'], $key ) ? 'draft' : 'future';
		} elseif ( $i === $today ) {
			$state = 'today';
		} else {
			$entry = jlife_challenges_find_entry( $user_id, (int) $c['id'], $key );
			if ( $entry ) {
				$state = 'draft';
			} else {
				$state = jlife_challenges_is_on_time( $c, $i ) ? 'open' : 'not_done';
			}
		}
		$cells[] = array(
			'index'   => $i,
			'day_key' => $key,
			'date'    => jlife_challenges_day_date( $c, $i ),
			'state'   => $state,
			'ahead'   => $ahead && $i > $last,
		);
	}
	return array(
		'campaign'  => $c,
		'points'    => (int) $progress['points'],
		'day_count' => $count,
		'today'     => $today,
		'cells'     => $cells,
	);
}
