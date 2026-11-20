<?php
/**
 * Organizer daily view and CSV rows (#64 part 2). Docs: challenges-engine-design.md §4h.
 *
 * Counts, statuses, points and display names only. Nothing here reads an entry's
 * text or a share's text: the queries select ids, statuses and counts, never a
 * `body`, and a test pins that. Organizers only (manage_options); a share is
 * reachable by an organizer only through the audited hide action (D17), which is
 * in shares.php and not on any screen.
 *
 * @package jlife-challenges
 */

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.DB.DirectDatabaseQuery -- custom tables are this plugin's data layer; every query goes through the gate; WordPress has no API for them.

/**
 * The CSV columns, in order. Fixed English words: the file is for spreadsheets.
 */
const JLIFE_CHALLENGES_CSV_HEADER = array( 'participant_id', 'display_name', 'groups', 'day', 'point', 'status' );

/**
 * The one status word for a person's day, from the ledger row and whether an entry exists.
 *
 * @param string $ledger_status Ledger status ('' = no row): earned, late_no_point, removed_by_review.
 * @param bool   $has_entry     Whether an entry row exists for the day.
 * @return string done_on_time|done_late|point_removed|draft|none
 */
function jlife_challenges_day_status_code( $ledger_status, $has_entry ) {
	if ( 'earned' === $ledger_status ) {
		return 'done_on_time';
	}
	if ( 'late_no_point' === $ledger_status ) {
		return 'done_late';
	}
	if ( 'removed_by_review' === $ledger_status ) {
		return 'point_removed';
	}
	return $has_entry ? 'draft' : 'none';
}

/**
 * Each person's active group names in a campaign, as "A; B" (empty = solo). Only
 * active memberships of an active enrollment in an active group.
 *
 * @param int $campaign_id Campaign.
 * @return array<int,string> User ID => group names.
 */
function jlife_challenges_groups_by_user( $campaign_id ) {
	global $wpdb;
	$m = jlife_challenges_table( 'group_members' );
	$e = jlife_challenges_table( 'enrollments' );
	$g = jlife_challenges_table( 'groups' );
	// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- custom tables; names from $wpdb->prefix.
	$rows = (array) $wpdb->get_results( $wpdb->prepare( "SELECT m.user_id, g.name FROM {$m} m JOIN {$e} e ON e.id = m.enrollment_id AND e.status = 'active' JOIN {$g} g ON g.id = m.group_id AND g.status = 'active' WHERE m.campaign_id = %d AND m.status = 'active' ORDER BY m.joined_at, m.id", (int) $campaign_id ), ARRAY_A );
	$out  = array();
	foreach ( $rows as $r ) {
		$out[ (int) $r['user_id'] ][] = (string) $r['name'];
	}
	return array_map(
		static function ( $names ) {
			return implode( '; ', $names );
		},
		$out
	);
}

/**
 * The roster for one day: every active participant, plus anyone with a ledger row that
 * day. No gate: callers check the organizer first.
 *
 * @param int    $campaign_id Campaign.
 * @param string $day_key     Day.
 * @return array[] Rows of { user_id, name, groups, status, point }, by name.
 */
function jlife_challenges_daily_roster( $campaign_id, $day_key ) {
	global $wpdb;
	$e = jlife_challenges_table( 'enrollments' );
	$l = jlife_challenges_table( 'score_ledger' );
	$s = jlife_challenges_table( 'soap_entries' );
	// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- custom tables; names from $wpdb->prefix.
	$active  = array_map( 'intval', $wpdb->get_col( $wpdb->prepare( "SELECT user_id FROM {$e} WHERE campaign_id = %d AND status = 'active' ORDER BY id", (int) $campaign_id ) ) );
	$ledger  = array_column( (array) $wpdb->get_results( $wpdb->prepare( "SELECT user_id, points, status FROM {$l} WHERE campaign_id = %d AND day_key = %s", (int) $campaign_id, (string) $day_key ), ARRAY_A ), null, 'user_id' );
	$entries = array_column( (array) $wpdb->get_results( $wpdb->prepare( "SELECT user_id, status FROM {$s} WHERE campaign_id = %d AND day_key = %s", (int) $campaign_id, (string) $day_key ), ARRAY_A ), 'status', 'user_id' );
	// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	$ids    = array_values( array_unique( array_merge( $active, array_map( 'intval', array_keys( $ledger ) ) ) ) );
	$groups = jlife_challenges_groups_by_user( (int) $campaign_id );
	$rows   = array();
	foreach ( $ids as $id ) {
		$row    = $ledger[ $id ] ?? null;
		$rows[] = array(
			'user_id' => $id,
			'name'    => jlife_challenges_admin_person_name( $id ),
			'groups'  => $groups[ $id ] ?? '',
			'status'  => jlife_challenges_day_status_code( $row ? (string) $row['status'] : '', isset( $entries[ $id ] ) ),
			'point'   => $row ? (int) $row['points'] : 0,
		);
	}
	usort(
		$rows,
		static function ( $x, $y ) {
			$by_name = strcasecmp( $x['name'], $y['name'] );
			return 0 !== $by_name ? $by_name : $x['user_id'] <=> $y['user_id'];
		}
	);
	return $rows;
}

/**
 * The daily view for one campaign day: a status and point for each participant, and
 * for each group its members, SOAPs submitted and shares that day. Counts only.
 * A person in two groups counts in both; solo readers are one more row (group 0).
 *
 * @param int    $actor       Organizer.
 * @param int    $campaign_id Campaign.
 * @param string $day_key     Day key (d001…), or '' for today's day (or the nearest end of the campaign).
 * @return array|WP_Error { campaigns[], campaign, day{key,index,date}, rows[], groups[], totals{} }
 */
function jlife_challenges_daily_view( $actor, $campaign_id, $day_key = '' ) {
	$campaign = jlife_challenges_get_campaign( (int) $campaign_id );
	if ( ! jlife_challenges_can_read_counts( $actor ) || ! $campaign ) {
		return jlife_challenges_denied();
	}
	if ( '' === (string) $day_key ) {
		$index = jlife_challenges_current_day( $campaign );
		if ( $index < 1 ) {
			$index = jlife_challenges_day_is_open( $campaign, 1 ) ? jlife_challenges_day_count( $campaign ) : 1;
		}
	} else {
		$index = jlife_challenges_day_index( $campaign, (string) $day_key );
		if ( $index < 1 ) {
			return jlife_challenges_denied();
		}
	}
	$key    = jlife_challenges_day_key( $index );
	$rows   = jlife_challenges_daily_roster( (int) $campaign['id'], $key );
	$totals = array(
		'participants'  => count( $rows ),
		'done_on_time'  => 0,
		'done_late'     => 0,
		'draft'         => 0,
		'none'          => 0,
		'point_removed' => 0,
		'points'        => 0,
	);
	foreach ( $rows as $r ) {
		++$totals[ $r['status'] ];
		$totals['points'] += $r['point'];
	}

	global $wpdb;
	$m = jlife_challenges_table( 'group_members' );
	$e = jlife_challenges_table( 'enrollments' );
	$s = jlife_challenges_table( 'soap_entries' );
	$h = jlife_challenges_table( 'group_shares' );
	// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- custom tables; names from $wpdb->prefix.
	$submitted = array_column( (array) $wpdb->get_results( $wpdb->prepare( "SELECT m.group_id, COUNT(*) AS n FROM {$m} m JOIN {$e} e ON e.id = m.enrollment_id AND e.status = 'active' JOIN {$s} s ON s.user_id = m.user_id AND s.campaign_id = m.campaign_id AND s.day_key = %s AND s.status = 'submitted' WHERE m.campaign_id = %d AND m.status = 'active' GROUP BY m.group_id", $key, (int) $campaign['id'] ), ARRAY_A ), 'n', 'group_id' );
	$shares    = array_column( (array) $wpdb->get_results( $wpdb->prepare( "SELECT group_id, COUNT(*) AS n FROM {$h} WHERE campaign_id = %d AND day_key = %s AND status = 'shared' GROUP BY group_id", (int) $campaign['id'], $key ), ARRAY_A ), 'n', 'group_id' );
	$active    = array_map( 'intval', $wpdb->get_col( $wpdb->prepare( "SELECT user_id FROM {$e} WHERE campaign_id = %d AND status = 'active'", (int) $campaign['id'] ) ) );
	$done      = array_map( 'intval', $wpdb->get_col( $wpdb->prepare( "SELECT user_id FROM {$s} WHERE campaign_id = %d AND day_key = %s AND status = 'submitted'", (int) $campaign['id'], $key ) ) );
	// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	$in_group = jlife_challenges_groups_by_user( (int) $campaign['id'] );
	$groups   = array();
	foreach ( (array) jlife_challenges_list_groups( $actor, (int) $campaign['id'] ) as $g ) {
		$groups[] = array(
			'group_id'  => (int) $g['id'],
			'name'      => (string) $g['name'],
			'status'    => (string) $g['status'],
			'members'   => (int) $g['members'],
			'submitted' => (int) ( $submitted[ $g['id'] ] ?? 0 ),
			'shares'    => (int) ( $shares[ $g['id'] ] ?? 0 ),
		);
	}
	$solo     = array_values( array_filter( $active, static fn( $id ) => ! isset( $in_group[ $id ] ) ) );
	$groups[] = array(
		'group_id'  => 0,
		'name'      => '',
		'status'    => 'active',
		'members'   => count( $solo ),
		'submitted' => count( array_intersect( $solo, $done ) ),
		'shares'    => 0,
	);

	return array(
		'campaigns' => jlife_challenges_admin_campaigns(),
		'campaign'  => $campaign,
		'day'       => array(
			'key'   => $key,
			'index' => $index,
			'date'  => jlife_challenges_day_date( $campaign, $index ),
		),
		'rows'      => $rows,
		'groups'    => $groups,
		'totals'    => $totals,
	);
}

/**
 * The CSV rows for a campaign: with a day, every participant that day (drafts and
 * "none" included); with no day, one row per participant and day that has a result
 * in the ledger. Keys are the CSV columns. Organizers only. Does not audit: see
 * jlife_challenges_csv_prepare().
 *
 * @param int    $actor       Organizer.
 * @param int    $campaign_id Campaign.
 * @param string $day_key     Day key, or '' for the whole campaign.
 * @return array[]|WP_Error
 */
function jlife_challenges_csv_rows( $actor, $campaign_id, $day_key = '' ) {
	$campaign = jlife_challenges_get_campaign( (int) $campaign_id );
	if ( ! jlife_challenges_can_read_counts( $actor ) || ! $campaign ) {
		return jlife_challenges_denied();
	}
	$out = array();
	if ( '' !== (string) $day_key ) {
		if ( jlife_challenges_day_index( $campaign, (string) $day_key ) < 1 ) {
			return jlife_challenges_denied();
		}
		foreach ( jlife_challenges_daily_roster( (int) $campaign['id'], (string) $day_key ) as $r ) {
			$out[] = array(
				'participant_id' => $r['user_id'],
				'display_name'   => $r['name'],
				'groups'         => $r['groups'],
				'day'            => (string) $day_key,
				'point'          => $r['point'],
				'status'         => $r['status'],
			);
		}
		return $out;
	}
	global $wpdb;
	// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- custom table; name from $wpdb->prefix.
	$ledger = (array) $wpdb->get_results( $wpdb->prepare( 'SELECT user_id, day_key, points, status FROM ' . jlife_challenges_table( 'score_ledger' ) . ' WHERE campaign_id = %d ORDER BY user_id, day_key', (int) $campaign['id'] ), ARRAY_A );
	$groups = jlife_challenges_groups_by_user( (int) $campaign['id'] );
	foreach ( $ledger as $r ) {
		$id    = (int) $r['user_id'];
		$out[] = array(
			'participant_id' => $id,
			'display_name'   => jlife_challenges_admin_person_name( $id ),
			'groups'         => $groups[ $id ] ?? '',
			'day'            => (string) $r['day_key'],
			'point'          => (int) $r['points'],
			'status'         => jlife_challenges_day_status_code( (string) $r['status'], true ),
		);
	}
	return $out;
}

/**
 * Make one CSV cell safe for a spreadsheet: a value that begins with =, +, -, @, a
 * tab or a carriage return would be run as a formula, so it gets a leading apostrophe.
 * Display names and group names are typed by people, so every cell goes through this.
 *
 * @param mixed $value Cell value.
 * @return string
 */
function jlife_challenges_csv_cell( $value ) {
	$text = (string) $value;
	if ( '' !== $text && false !== strpos( "=+-@\t\r", $text[0] ) ) {
		return "'" . $text;
	}
	return $text;
}

/**
 * Turn CSV rows into the file's text: a UTF-8 byte-order mark (so Excel reads
 * Vietnamese names), the header, then each row with every cell guarded.
 *
 * @param array[] $rows Rows keyed by JLIFE_CHALLENGES_CSV_HEADER.
 * @return string
 */
function jlife_challenges_csv_string( array $rows ) {
	$handle = fopen( 'php://temp', 'r+' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- an in-memory stream, not the filesystem.
	fputcsv( $handle, JLIFE_CHALLENGES_CSV_HEADER, ',', '"', '\\' );
	foreach ( $rows as $row ) {
		$line = array();
		foreach ( JLIFE_CHALLENGES_CSV_HEADER as $column ) {
			$line[] = jlife_challenges_csv_cell( $row[ $column ] ?? '' );
		}
		fputcsv( $handle, $line, ',', '"', '\\' );
	}
	rewind( $handle );
	$text = (string) stream_get_contents( $handle );
	fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- an in-memory stream.
	return "\xEF\xBB\xBF" . $text;
}

/**
 * The nonce action for one download: bound to the campaign and the day (or all days).
 *
 * @param int    $campaign_id Campaign.
 * @param string $day_key     Day key, or '' for the whole campaign.
 * @return string
 */
function jlife_challenges_csv_nonce_action( $campaign_id, $day_key = '' ) {
	return 'jlife_csv_' . (int) $campaign_id . '_' . ( '' === (string) $day_key ? 'all' : (string) $day_key );
}

/**
 * Check a download request and build the file. Pure: nothing is sent, so tests can
 * drive it. Checks in order: capability, POST, nonce for this campaign and day, then
 * that the campaign and day exist. Only a file that is actually built is audited
 * (`scores_exported`: row count and day, never a name).
 *
 * Fields: nonce, campaign_id, day ('' = the whole campaign).
 *
 * @param string $method 'GET' or 'POST' (only POST downloads).
 * @param array  $fields Request fields.
 * @return array { code: ok|forbidden|bad_method|bad_nonce|not_found, filename, body }
 */
function jlife_challenges_csv_prepare( $method, array $fields ) {
	$none = array(
		'code'     => 'forbidden',
		'filename' => '',
		'body'     => '',
	);
	if ( ! current_user_can( 'manage_options' ) ) {
		return $none;
	}
	if ( 'POST' !== strtoupper( (string) $method ) ) {
		return array( 'code' => 'bad_method' ) + $none;
	}
	$campaign_id = (int) ( $fields['campaign_id'] ?? 0 );
	$day_key     = (string) ( $fields['day'] ?? '' );
	if ( ! wp_verify_nonce( (string) ( $fields['nonce'] ?? '' ), jlife_challenges_csv_nonce_action( $campaign_id, $day_key ) ) ) {
		return array( 'code' => 'bad_nonce' ) + $none;
	}
	$actor = get_current_user_id();
	$rows  = jlife_challenges_csv_rows( $actor, $campaign_id, $day_key );
	if ( is_wp_error( $rows ) ) {
		return array( 'code' => 'not_found' ) + $none;
	}
	$campaign = (array) jlife_challenges_get_campaign( $campaign_id );
	$detail   = array( 'rows' => count( $rows ) );
	if ( '' !== $day_key ) {
		$detail['day'] = $day_key;
	}
	jlife_challenges_audit( $actor, 'scores_exported', 'campaign', $campaign_id, $campaign_id, $detail );
	return array(
		'code'     => 'ok',
		'filename' => sanitize_file_name( sprintf( 'jlife-%1$s-%2$s-%3$s.csv', sanitize_title( (string) $campaign['campaign_key'] ), '' === $day_key ? 'all-days' : $day_key, gmdate( 'Y-m-d' ) ) ),
		'body'     => jlife_challenges_csv_string( $rows ),
	);
}

/**
 * The response headers for a download: a CSV attachment, never cached or sniffed.
 *
 * @param string $filename A file name from jlife_challenges_csv_prepare().
 * @return string[]
 */
function jlife_challenges_csv_headers( $filename ) {
	return array(
		'Content-Type: text/csv; charset=utf-8',
		'Content-Disposition: attachment; filename="' . sanitize_file_name( $filename ) . '"',
		'Cache-Control: no-store, no-cache, must-revalidate, max-age=0',
		'Pragma: no-cache',
		'X-Content-Type-Options: nosniff',
	);
}
