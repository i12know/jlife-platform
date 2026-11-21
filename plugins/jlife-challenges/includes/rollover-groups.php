<?php
/**
 * Rollover, part 2 (docs/challenges-engine-design.md §3d, #182, D2-A): make
 * the destination campaign's groups from the source's, through a plan file the
 * organizer reviews and edits.
 *
 * - Site-scoped, with explicit source and destination IDs (ADR 0001), checked
 *   by jlife_challenges_rollover_campaigns() like part 1.
 * - The plan is a CSV (mode 0600, never printed) with one row per person per
 *   group, editable in a spreadsheet. It holds user IDs and display names for
 *   review, never email addresses.
 * - Groups and memberships are made with jlife_challenges_create_group() and
 *   jlife_challenges_add_membership(), so they are checked and audited as
 *   usual. New invite codes go only to a 0600 file next to the plan.
 * - Reruns find the groups they made by plan key (an option per destination),
 *   never by name: names are not unique.
 * - Results, output and the audit row are counts and error classes only.
 *
 * @package jlife-challenges
 */

defined( 'ABSPATH' ) || exit;

/**
 * The plan file's columns, in order. `age_band` and `name_for_review` are for
 * reading only.
 */
const JLIFE_CHALLENGES_PLAN_COLUMNS = array( 'group_key', 'group_name', 'role', 'user_id', 'age_band', 'name_for_review' );

/**
 * The option that maps plan keys to the destination groups made from them.
 *
 * @param int $to_id Destination campaign.
 * @return string
 */
function jlife_challenges_rollover_groups_option( $to_id ) {
	return 'jlife_rollover_groups_' . (int) $to_id;
}

/**
 * Plan key => destination group ID, for groups an execute has made.
 *
 * @param int $to_id Destination campaign.
 * @return int[]
 */
function jlife_challenges_rollover_groups_map( $to_id ) {
	$map = get_option( jlife_challenges_rollover_groups_option( $to_id ), array() );
	return is_array( $map ) ? array_map( 'intval', $map ) : array();
}

/**
 * The source's active memberships of its active groups, starters first.
 *
 * @param int $from_id Source campaign.
 * @return array[] Rows of { group_id, name, user_id, role, age_band }.
 */
function jlife_challenges_rollover_groups_source_rows( $from_id ) {
	global $wpdb;
	$g = jlife_challenges_table( 'groups' );
	$m = jlife_challenges_table( 'group_members' );
	$e = jlife_challenges_table( 'enrollments' );
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- custom tables; names from $wpdb->prefix.
	return (array) $wpdb->get_results( $wpdb->prepare( "SELECT g.id AS group_id, g.name, m.user_id, m.role, e.age_band FROM {$g} g JOIN {$m} m ON m.group_id = g.id AND m.status = 'active' JOIN {$e} e ON e.id = m.enrollment_id AND e.status = 'active' WHERE g.campaign_id = %d AND g.status = 'active' ORDER BY g.id, (m.role = 'starter') DESC, m.joined_at, m.id", (int) $from_id ), ARRAY_A );
}

/**
 * Open a file for writing with owner-only permissions (0600).
 *
 * @param string $file Path.
 * @param string $mode fopen mode ('x' create only, 'a' append).
 * @return resource|false
 */
function jlife_challenges_rollover_groups_open_private( $file, $mode ) {
	$old = umask( 077 );
	$h   = @fopen( $file, $mode ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen,WordPress.PHP.NoSilencedErrors.Discouraged -- CLI-only owner file; a failure is reported, not shown.
	umask( $old );
	if ( false !== $h ) {
		chmod( $file, 0600 ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_chmod -- owner-only file.
	}
	return $h;
}

/**
 * Write one CSV line.
 *
 * @param resource $h      Handle.
 * @param array    $fields Cells.
 */
function jlife_challenges_rollover_groups_put( $h, $fields ) {
	fputcsv( $h, $fields, ',', '"', '\\' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fputcsv -- CLI-only owner file.
}

/**
 * Preview: write the plan file (refused if it exists). Returns counts only.
 *
 * @param int    $actor   Organizer.
 * @param int    $from_id Source campaign.
 * @param int    $to_id   Destination campaign.
 * @param string $file    Plan file path (must not exist yet).
 * @return int[]|WP_Error { groups, rows, starters, minor_rows, not_enrolled_rows }
 */
function jlife_challenges_rollover_groups_write_plan( $actor, $from_id, $to_id, $file ) {
	$c = jlife_challenges_rollover_campaigns( $actor, $from_id, $to_id );
	if ( is_wp_error( $c ) ) {
		return $c;
	}
	$file = (string) $file;
	if ( '' === $file || file_exists( $file ) ) {
		return new WP_Error( 'jlife_plan_exists', 'The plan file already exists (an edited plan is never overwritten). Choose a new file name.' );
	}
	$from_id = (int) $c['from']['id'];
	$to_id   = (int) $c['to']['id'];
	$h       = jlife_challenges_rollover_groups_open_private( $file, 'x' );
	if ( false === $h ) {
		return new WP_Error( 'jlife_plan_unwritable', 'The plan file could not be created there.' );
	}
	$counts = array(
		'groups'            => 0,
		'rows'              => 0,
		'starters'          => 0,
		'minor_rows'        => 0,
		'not_enrolled_rows' => 0,
	);
	$groups = array();
	// Byte-order mark (so spreadsheets read Vietnamese names), then the plan's first line, unquoted.
	fwrite( $h, "\xEF\xBB\xBF" . sprintf( "# jlife rollover-groups plan; from=%d; to=%d\n", $from_id, $to_id ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- CLI-only owner file.
	jlife_challenges_rollover_groups_put( $h, JLIFE_CHALLENGES_PLAN_COLUMNS );
	foreach ( jlife_challenges_rollover_groups_source_rows( $from_id ) as $r ) {
		$uid                            = (int) $r['user_id'];
		$groups[ (int) $r['group_id'] ] = true;
		$counts['rows']                += 1;
		$counts['starters']            += 'starter' === $r['role'] ? 1 : 0;
		$counts['minor_rows']          += 'minor' === $r['age_band'] ? 1 : 0;
		$counts['not_enrolled_rows']   += null === jlife_challenges_active_enrollment( $to_id, $uid ) ? 1 : 0;
		jlife_challenges_rollover_groups_put(
			$h,
			array(
				'g' . (int) $r['group_id'],
				jlife_challenges_csv_cell( (string) $r['name'] ),
				(string) $r['role'],
				(string) $uid,
				(string) $r['age_band'],
				jlife_challenges_csv_cell( jlife_challenges_admin_person_name( $uid ) ),
			)
		);
	}
	fclose( $h ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- CLI-only owner file.
	$counts['groups'] = count( $groups );
	return $counts;
}

/**
 * Undo the spreadsheet formula guard on a cell read back from a plan.
 *
 * @param string $value Cell.
 * @return string
 */
function jlife_challenges_rollover_groups_unguard( $value ) {
	$value = (string) $value;
	return ( strlen( $value ) > 1 && "'" === $value[0] && false !== strpos( "=+-@\t\r", $value[1] ) ) ? substr( $value, 1 ) : $value;
}

/**
 * Read a plan file.
 *
 * @param string $file Plan file path.
 * @return array|WP_Error { from: int, to: int, rows: array[] of { group_key, group_name, role, user_id } }
 */
function jlife_challenges_rollover_groups_read_plan( $file ) {
	$file = (string) $file;
	$h    = ( '' !== $file && is_readable( $file ) ) ? fopen( $file, 'r' ) : false; // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- CLI-only owner file.
	if ( false === $h ) {
		return new WP_Error( 'jlife_plan_unreadable', 'The plan file could not be read.' );
	}
	$meta   = null;
	$index  = null;
	$rows   = array();
	$failed = false;
	while ( false !== ( $line = fgetcsv( $h, 0, ',', '"', '\\' ) ) ) { // phpcs:ignore Generic.CodeAnalysis.AssignmentInCondition.FoundInWhileCondition
		$cells    = array_map( static fn( $v ) => trim( (string) $v ), (array) $line );
		$cells[0] = trim( (string) preg_replace( '/^\xEF\xBB\xBF/', '', $cells[0] ), '"' );
		if ( '' === implode( '', $cells ) ) {
			continue;
		}
		if ( null === $meta ) {
			if ( ! preg_match( '/^#.*\bfrom=(\d+)\D+to=(\d+)/', (string) $cells[0], $m ) ) {
				$failed = true;
				break;
			}
			$meta = array( (int) $m[1], (int) $m[2] );
			continue;
		}
		if ( 0 === strpos( (string) $cells[0], '#' ) ) {
			continue;
		}
		if ( null === $index ) {
			$index = array_flip( $cells );
			foreach ( array( 'group_key', 'group_name', 'role', 'user_id' ) as $col ) {
				if ( ! isset( $index[ $col ] ) ) {
					$failed = true;
					break 2;
				}
			}
			continue;
		}
		$rows[] = array(
			'group_key'  => (string) ( $cells[ $index['group_key'] ] ?? '' ),
			'group_name' => jlife_challenges_rollover_groups_unguard( (string) ( $cells[ $index['group_name'] ] ?? '' ) ),
			'role'       => strtolower( (string) ( $cells[ $index['role'] ] ?? '' ) ),
			'user_id'    => (string) ( $cells[ $index['user_id'] ] ?? '' ),
		);
	}
	fclose( $h ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- CLI-only owner file.
	if ( $failed || null === $meta || null === $index ) {
		return new WP_Error( 'jlife_plan_unreadable', 'The plan file is not a rollover-groups plan (its first line or header is missing or changed).' );
	}
	return array(
		'from' => $meta[0],
		'to'   => $meta[1],
		'rows' => $rows,
	);
}

/**
 * Check a plan against the destination. Reads only.
 *
 * @param array $plan  From jlife_challenges_rollover_groups_read_plan().
 * @param int   $to_id Destination campaign.
 * @return array { groups: array<string,array{name:string,rows:array[]}>, counts: int[], errors: int[] by class }
 */
function jlife_challenges_rollover_groups_check( $plan, $to_id ) {
	$errors = array();
	$groups = array();
	$names  = array();
	$seen   = array();
	$out    = 0;
	foreach ( $plan['rows'] as $r ) {
		$class = '';
		$key   = $r['group_key'];
		$name  = jlife_challenges_clean_group_name( $r['group_name'] );
		$e     = null;
		$valid = '' !== $key && ctype_digit( $r['user_id'] ) && (int) $r['user_id'] > 0;
		$again = $valid && isset( $seen[ $key ][ (int) $r['user_id'] ] );
		if ( $valid ) {
			$seen[ $key ][ (int) $r['user_id'] ] = true;
		}
		if ( ! $valid ) {
			$class = 'bad_row';
		} elseif ( $again ) {
			$class = 'duplicate_membership';
		} elseif ( ! in_array( $r['role'], array( 'member', 'starter' ), true ) ) {
			$class = 'unknown_role';
		} elseif ( is_wp_error( $name ) ) {
			$class = 'group_name_invalid';
		} elseif ( isset( $names[ $key ] ) && $names[ $key ] !== $name ) {
			$class = 'group_name_mismatch';
		} else {
			$e = jlife_challenges_active_enrollment( $to_id, (int) $r['user_id'] );
			if ( $e && 'starter' === $r['role'] && 'adult' !== $e['age_band'] ) {
				$class = 'minor_starter';
			}
		}
		if ( '' !== $class ) {
			$errors[ $class ] = ( $errors[ $class ] ?? 0 ) + 1;
			continue;
		}
		$uid           = (int) $r['user_id'];
		$names[ $key ] = (string) $name;
		if ( ! $e ) {
			++$out; // Not (or no longer) in the destination: reported, never added.
			continue;
		}
		$groups[ $key ]['name']   = (string) $name;
		$groups[ $key ]['rows'][] = array(
			'user_id' => $uid,
			'role'    => $r['role'],
		);
	}
	// Per-person limits, counting their destination groups that this plan does not make.
	$map     = jlife_challenges_rollover_groups_map( $to_id );
	$planned = array();
	foreach ( array_keys( $groups ) as $key ) {
		if ( isset( $map[ $key ] ) ) {
			$planned[ $map[ $key ] ] = true;
		}
	}
	$per_user = array();
	foreach ( $groups as $g ) {
		foreach ( $g['rows'] as $row ) {
			$per_user[ $row['user_id'] ]['groups']   = ( $per_user[ $row['user_id'] ]['groups'] ?? 0 ) + 1;
			$per_user[ $row['user_id'] ]['starters'] = ( $per_user[ $row['user_id'] ]['starters'] ?? 0 ) + ( 'starter' === $row['role'] ? 1 : 0 );
		}
	}
	foreach ( $per_user as $uid => $n ) {
		$other = 0;
		foreach ( jlife_challenges_active_memberships( $to_id, $uid ) as $m ) {
			$other += isset( $planned[ (int) $m['group_id'] ] ) ? 0 : 1;
		}
		if ( $n['groups'] + $other > JLIFE_CHALLENGES_MAX_GROUPS ) {
			$errors['group_limit'] = ( $errors['group_limit'] ?? 0 ) + 1;
		}
		if ( $n['starters'] > JLIFE_CHALLENGES_MAX_STARTED_GROUPS ) {
			$errors['start_limit'] = ( $errors['start_limit'] ?? 0 ) + 1;
		}
	}
	ksort( $errors );
	return array(
		'groups' => $groups,
		'counts' => array(
			'groups'             => count( $groups ),
			'rows'               => count( $plan['rows'] ),
			'memberships_to_add' => array_sum( array_map( static fn( $g ) => count( $g['rows'] ), $groups ) ),
			'not_enrolled'       => $out,
		),
		'errors' => $errors,
	);
}

/**
 * Load the campaigns and the plan, and check that the plan was made for them.
 *
 * @param int    $actor   Organizer.
 * @param int    $from_id Source campaign.
 * @param int    $to_id   Destination campaign.
 * @param string $file    Plan file path.
 * @param bool   $strict  As for jlife_challenges_rollover_campaigns().
 * @return array|WP_Error { from, to, plan, check }
 */
function jlife_challenges_rollover_groups_load( $actor, $from_id, $to_id, $file, $strict = true ) {
	$c = jlife_challenges_rollover_campaigns( $actor, $from_id, $to_id, $strict );
	if ( is_wp_error( $c ) ) {
		return $c;
	}
	$plan = jlife_challenges_rollover_groups_read_plan( $file );
	if ( is_wp_error( $plan ) ) {
		return $plan;
	}
	if ( $plan['from'] !== (int) $c['from']['id'] || $plan['to'] !== (int) $c['to']['id'] ) {
		return new WP_Error( 'jlife_plan_mismatch', 'This plan was made for other campaigns (see its first line).' );
	}
	return array_merge(
		$c,
		array(
			'plan'  => $plan,
			'check' => jlife_challenges_rollover_groups_check( $plan, (int) $c['to']['id'] ),
		)
	);
}

/**
 * Validate a plan. Writes nothing.
 *
 * @param int    $actor   Organizer.
 * @param int    $from_id Source campaign.
 * @param int    $to_id   Destination campaign.
 * @param string $file    Plan file path.
 * @return array|WP_Error Counts, errors (class => count) and ok.
 */
function jlife_challenges_rollover_groups_validate( $actor, $from_id, $to_id, $file ) {
	$l = jlife_challenges_rollover_groups_load( $actor, $from_id, $to_id, $file );
	if ( is_wp_error( $l ) ) {
		return $l;
	}
	return array_merge(
		$l['check']['counts'],
		array(
			'errors' => $l['check']['errors'],
			'ok'     => array() === $l['check']['errors'],
		)
	);
}

/**
 * Append a new group's invite code to the 0600 codes file next to the plan.
 *
 * @param string $file Codes file path.
 * @param string $key  Plan key.
 * @param string $name Group name.
 * @param string $code Invite code.
 * @return bool Written.
 */
function jlife_challenges_rollover_groups_save_code( $file, $key, $name, $code ) {
	$new = ! file_exists( $file );
	$h   = jlife_challenges_rollover_groups_open_private( $file, 'a' );
	if ( false === $h ) {
		return false;
	}
	if ( $new ) {
		fwrite( $h, "\xEF\xBB\xBF" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- byte-order mark for spreadsheets.
		jlife_challenges_rollover_groups_put( $h, array( 'group_key', 'group_name', 'invite_code', 'join_link' ) );
	}
	jlife_challenges_rollover_groups_put( $h, array( $key, jlife_challenges_csv_cell( $name ), $code, add_query_arg( 'code', $code, home_url( '/join/' ) ) ) );
	fclose( $h ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- CLI-only owner file.
	return true;
}

/**
 * Execute a valid plan: make the groups and memberships. Safe to run again.
 *
 * @param int    $actor   Organizer.
 * @param int    $from_id Source campaign.
 * @param int    $to_id   Destination campaign.
 * @param string $file    Plan file path (codes go to "<file>.codes.csv").
 * @return int[]|WP_Error Counts.
 */
function jlife_challenges_rollover_groups_execute( $actor, $from_id, $to_id, $file ) {
	$l = jlife_challenges_rollover_groups_load( $actor, $from_id, $to_id, $file );
	if ( is_wp_error( $l ) ) {
		return $l;
	}
	if ( array() !== $l['check']['errors'] ) {
		return new WP_Error( 'jlife_plan_invalid', 'The plan has errors; run --validate. Nothing was written.' );
	}
	$to      = $l['to'];
	$to_id   = (int) $to['id'];
	$from_id = (int) $l['from']['id'];
	$map     = jlife_challenges_rollover_groups_map( $to_id );
	$days    = max( 60, jlife_challenges_days_between( jlife_challenges_local_date( $to, jlife_challenges_now() ), (string) $to['end_date'] ) + 1 );
	$counts  = array(
		'groups_planned'       => $l['check']['counts']['groups'],
		'groups_created'       => 0,
		'groups_existing'      => 0,
		'memberships_planned'  => $l['check']['counts']['memberships_to_add'],
		'memberships_added'    => 0,
		'memberships_existing' => 0,
		'not_enrolled'         => $l['check']['counts']['not_enrolled'],
		'membership_errors'    => 0,
		'codes_not_saved'      => 0,
	);
	global $wpdb;
	foreach ( $l['check']['groups'] as $key => $g ) {
		/**
		 * Fires before each group and each membership step (a fault-injection point for the recovery tests).
		 *
		 * @param int $to_id Destination campaign.
		 */
		do_action( 'jlife_challenges_rollover_groups_step', $to_id );
		$group = isset( $map[ $key ] ) ? jlife_challenges_get_group( $map[ $key ] ) : null;
		if ( $group && (int) $group['campaign_id'] === $to_id ) {
			++$counts['groups_existing'];
		} else {
			$made = jlife_challenges_create_group( $actor, $to_id, $g['name'], $days );
			if ( is_wp_error( $made ) ) {
				return $made;
			}
			$map[ $key ] = (int) $made['id'];
			update_option( jlife_challenges_rollover_groups_option( $to_id ), $map, false );
			++$counts['groups_created'];
			if ( ! jlife_challenges_rollover_groups_save_code( (string) $file . '.codes.csv', (string) $key, $g['name'], (string) $made['invite_code'] ) ) {
				++$counts['codes_not_saved'];
			}
			$group = jlife_challenges_get_group( (int) $made['id'] );
		}
		$starters = wp_list_filter( $g['rows'], array( 'role' => 'starter' ) );
		if ( $starters && 0 === (int) $group['started_by'] ) {
			$wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- custom table; records who leads the group, as "Start a group" does.
				jlife_challenges_table( 'groups' ),
				array( 'started_by' => (int) reset( $starters )['user_id'] ),
				array(
					'id'         => (int) $group['id'],
					'started_by' => 0,
				),
				array( '%d' ),
				array( '%d', '%d' )
			);
		}
		foreach ( $g['rows'] as $row ) {
			do_action( 'jlife_challenges_rollover_groups_step', $to_id );
			$m = jlife_challenges_get_membership( (int) $group['id'], $row['user_id'] );
			if ( $m && 'active' === $m['status'] ) {
				++$counts['memberships_existing'];
				continue;
			}
			$e     = jlife_challenges_active_enrollment( $to_id, $row['user_id'] );
			$added = $e ? jlife_challenges_add_membership( $actor, $e, $group, $row['role'], 'rollover' ) : jlife_challenges_denied();
			if ( is_wp_error( $added ) ) {
				++$counts['membership_errors'];
			} else {
				++$counts['memberships_added'];
			}
		}
	}
	jlife_challenges_audit( $actor, 'campaign_rollover_groups', 'campaign', $from_id, $to_id, $counts );
	return $counts;
}

/**
 * Verify: compare the plan with the destination's groups. Reads only.
 *
 * @param int    $actor   Organizer.
 * @param int    $from_id Source campaign.
 * @param int    $to_id   Destination campaign.
 * @param string $file    Plan file path.
 * @return array|WP_Error Counts, plus ok (bool).
 */
function jlife_challenges_rollover_groups_verify( $actor, $from_id, $to_id, $file ) {
	$l = jlife_challenges_rollover_groups_load( $actor, $from_id, $to_id, $file, false );
	if ( is_wp_error( $l ) ) {
		return $l;
	}
	$to_id  = (int) $l['to']['id'];
	$map    = jlife_challenges_rollover_groups_map( $to_id );
	$counts = array(
		'groups_planned'      => $l['check']['counts']['groups'],
		'groups_found'        => 0,
		'groups_missing'      => 0,
		'memberships_planned' => $l['check']['counts']['memberships_to_add'],
		'memberships_found'   => 0,
		'memberships_missing' => 0,
		'role_mismatch'       => 0,
		'extra_members'       => 0,
		'unplanned_groups'    => 0,
		'not_enrolled'        => $l['check']['counts']['not_enrolled'],
		'plan_errors'         => (int) array_sum( $l['check']['errors'] ),
	);
	global $wpdb;
	$gm = jlife_challenges_table( 'group_members' );
	foreach ( $l['check']['groups'] as $key => $g ) {
		$group = isset( $map[ $key ] ) ? jlife_challenges_get_group( $map[ $key ] ) : null;
		if ( ! $group || (int) $group['campaign_id'] !== $to_id || 'active' !== $group['status'] ) {
			++$counts['groups_missing'];
			$counts['memberships_missing'] += count( $g['rows'] );
			continue;
		}
		++$counts['groups_found'];
		$planned = array();
		foreach ( $g['rows'] as $row ) {
			$planned[ $row['user_id'] ] = true;
			$m                          = jlife_challenges_get_membership( (int) $group['id'], $row['user_id'] );
			if ( ! $m || 'active' !== $m['status'] ) {
				++$counts['memberships_missing'];
			} elseif ( $m['role'] !== $row['role'] ) {
				++$counts['role_mismatch'];
			} else {
				++$counts['memberships_found'];
			}
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- custom table; name from $wpdb->prefix.
		foreach ( (array) $wpdb->get_col( $wpdb->prepare( "SELECT user_id FROM {$gm} WHERE group_id = %d AND status = 'active'", (int) $group['id'] ) ) as $uid ) {
			$counts['extra_members'] += isset( $planned[ (int) $uid ] ) ? 0 : 1;
		}
	}
	$made = array_flip( array_values( $map ) );
	$t    = jlife_challenges_table( 'groups' );
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- custom table; name from $wpdb->prefix.
	foreach ( (array) $wpdb->get_col( $wpdb->prepare( "SELECT id FROM {$t} WHERE campaign_id = %d AND status = 'active'", $to_id ) ) as $gid ) {
		$counts['unplanned_groups'] += isset( $made[ (int) $gid ] ) ? 0 : 1;
	}
	$counts['ok'] = 0 === $counts['plan_errors'] && 0 === $counts['groups_missing'] && 0 === $counts['memberships_missing'] && 0 === $counts['role_mismatch'];
	return $counts;
}
