<?php
/**
 * Ask the Pastors (#249, docs/challenges-engine-design.md §4k): a participant's
 * private Bible question, kept apart from everything else. The rules this file
 * keeps:
 *
 * - Separate storage. One table, no link to SOAP entries, shares, comments or
 *   scores. A question earns no point, makes no share, sends no email.
 * - One gate. jlife_questions_can_review() is the only reader check: site
 *   administrators (manage_options) today, widened later through its filter.
 *   Every list, detail, write and export goes through it.
 * - Private, not anonymous. Reviewers see who asked; the export never does.
 * - Nothing about a question (text, passage, name) is written to a log, the
 *   audit log or an email. Audit rows hold ids and statuses only.
 * - No retention job. A question stays until an administrator deletes it (an
 *   archived one) or the account is deleted.
 * - Off by default: an administrator switches it on from the Bible Questions
 *   screen.
 *
 * @package jlife-challenges
 */

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.DB.DirectDatabaseQuery -- custom table is this plugin's data layer; every query goes through the gate; WordPress has no API for it.

const JLIFE_QUESTIONS_STATUSES     = array( 'new', 'selected', 'addressed', 'archived' );
const JLIFE_QUESTIONS_MIN_CHARS    = 10;
const JLIFE_QUESTIONS_MAX_CHARS    = 1000;
const JLIFE_QUESTIONS_REF_MAX      = 100;
const JLIFE_QUESTIONS_NOTE_MAX     = 2000;
const JLIFE_QUESTIONS_PER_DAY      = 3;
const JLIFE_QUESTIONS_PER_CAMPAIGN = 15;
const JLIFE_QUESTIONS_LIST_LIMIT   = 500;
const JLIFE_QUESTIONS_OPTION       = 'jlife_questions_enabled';

/**
 * Is the feature switched on? Default off.
 *
 * @return bool
 */
function jlife_questions_enabled() {
	return '1' === (string) get_option( JLIFE_QUESTIONS_OPTION, '0' );
}

/**
 * The one reviewer check. Site administrators only (network super admins count).
 *
 * @param int $user_id User ID.
 * @return bool
 */
function jlife_questions_can_review( $user_id ) {
	$user_id = (int) $user_id;
	$allowed = $user_id > 0 && user_can( $user_id, 'manage_options' );
	/**
	 * Filters who may read and manage Bible questions. Add a reviewer here later
	 * without touching any call site. A reviewer is a staff account, never a
	 * participant account.
	 *
	 * @param bool $allowed Default: manage_options.
	 * @param int  $user_id User ID.
	 */
	return (bool) apply_filters( 'jlife_questions_can_review', $allowed, $user_id );
}

/**
 * Plain text from a form field: no tags, trimmed, line breaks kept.
 *
 * @param mixed $text Raw text.
 * @return string
 */
function jlife_questions_clean( $text ) {
	$text = wp_strip_all_tags( (string) $text );
	$text = str_replace( array( "\r\n", "\r" ), "\n", $text );
	return trim( (string) preg_replace( '/[\x00-\x08\x0B\x0C\x0E-\x1F]/', '', $text ) );
}

/**
 * Number of characters (not bytes).
 *
 * @param string $text Text.
 * @return int
 */
function jlife_questions_length( $text ) {
	return function_exists( 'mb_strlen' ) ? mb_strlen( $text, 'UTF-8' ) : strlen( $text );
}

/**
 * Store a participant's question.
 *
 * Needs the feature on, an active enrollment, a campaign that takes writes and
 * (when a day is given) a day that has opened. A repeat of the same text for the
 * same day within 24 hours returns the first row's ID instead of a second row.
 *
 * @param int    $user_id   Asker.
 * @param string $day_key   Reading day (d###), or '' for a question about no particular day (the /ask/ screen passes the day it was opened for, which defaults to today).
 * @param string $reference Scripture passage the asker typed or accepted.
 * @param string $body      The question.
 * @return int|WP_Error New or existing question ID.
 */
function jlife_questions_submit( $user_id, $day_key, $reference, $body ) {
	global $wpdb;
	$user_id = (int) $user_id;
	if ( ! jlife_questions_enabled() ) {
		return new WP_Error( 'jlife_questions_off', __( 'Questions are not open right now.', 'jlife-challenges' ) );
	}
	$pc = $user_id > 0 ? jlife_challenges_participant_campaign( $user_id ) : null;
	if ( ! $pc ) {
		return jlife_challenges_denied();
	}
	$campaign = $pc['campaign'];
	if ( ! jlife_challenges_campaign_accepts_writes( $campaign ) ) {
		return jlife_challenges_campaign_closed_error();
	}
	$day_key = sanitize_key( (string) $day_key );
	if ( '' !== $day_key ) {
		$index = jlife_challenges_day_index( $campaign, $day_key );
		if ( $index < 1 || ! jlife_challenges_day_is_open( $campaign, $index ) ) {
			return jlife_challenges_denied();
		}
	}
	$reference = jlife_questions_clean( $reference );
	$body      = jlife_questions_clean( $body );
	$length    = jlife_questions_length( $body );
	if ( $length < JLIFE_QUESTIONS_MIN_CHARS ) {
		return new WP_Error( 'jlife_question_short', __( 'Please write a question of 10 to 1,000 characters.', 'jlife-challenges' ) );
	}
	if ( $length > JLIFE_QUESTIONS_MAX_CHARS ) {
		return new WP_Error( 'jlife_question_long', __( 'Please write a question of 10 to 1,000 characters.', 'jlife-challenges' ) );
	}
	if ( jlife_questions_length( $reference ) > JLIFE_QUESTIONS_REF_MAX ) {
		return new WP_Error( 'jlife_question_long', __( 'The passage is too long. Please shorten it.', 'jlife-challenges' ) );
	}

	$table       = jlife_challenges_table( 'bible_questions' );
	$campaign_id = (int) $campaign['id'];
	$now         = jlife_challenges_now();
	$cutoff      = $now->modify( '-24 hours' )->format( 'Y-m-d H:i:s' );
	// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from $wpdb->prefix.
	$same = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$table} WHERE user_id = %d AND campaign_id = %d AND day_key = %s AND body = %s AND created > %s ORDER BY id LIMIT 1", $user_id, $campaign_id, $day_key, $body, $cutoff ) );
	if ( $same ) {
		return (int) $same;
	}
	$today = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE user_id = %d AND campaign_id = %d AND created > %s", $user_id, $campaign_id, $cutoff ) );
	if ( $today >= JLIFE_QUESTIONS_PER_DAY ) {
		return new WP_Error( 'jlife_question_rate_day', __( 'You have asked several questions today; please try again tomorrow.', 'jlife-challenges' ) );
	}
	$total = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE user_id = %d AND campaign_id = %d", $user_id, $campaign_id ) );
	// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	if ( $total >= JLIFE_QUESTIONS_PER_CAMPAIGN ) {
		return new WP_Error( 'jlife_question_rate_campaign', __( 'You have reached the limit of questions for this reading period.', 'jlife-challenges' ) );
	}
	$created = $now->format( 'Y-m-d H:i:s' );
	$ok      = $wpdb->insert(
		$table,
		array(
			'user_id'        => $user_id,
			'campaign_id'    => $campaign_id,
			'day_key'        => $day_key,
			'reference'      => $reference,
			'body'           => $body,
			'minor'          => 'minor' === ( $pc['enrollment']['age_band'] ?? '' ) ? 1 : 0,
			'status'         => 'new',
			'pastor_note'    => '',
			'created'        => $created,
			'status_changed' => $created,
			'status_by'      => 0,
		),
		array( '%d', '%d', '%s', '%s', '%s', '%d', '%s', '%s', '%s', '%s', '%d' )
	);
	if ( ! $ok ) {
		return new WP_Error( 'jlife_question_failed', __( 'Sorry, that did not go through. Please try again.', 'jlife-challenges' ) );
	}
	$id = (int) $wpdb->insert_id;
	jlife_challenges_audit( $user_id, 'question_submitted', 'question', $id, $campaign_id, array() ); // Ids only: never the text, the passage or the name.
	return $id;
}

/**
 * Questions for a reviewer, newest first.
 *
 * @param int   $actor Viewer.
 * @param array $args  status, day_key, s (text in the question or the passage).
 * @return array[]|WP_Error Rows with display_name; denied unless the viewer is a reviewer.
 */
function jlife_questions_list( $actor, $args = array() ) {
	if ( ! jlife_questions_can_review( $actor ) ) {
		return jlife_challenges_denied();
	}
	global $wpdb;
	$table = jlife_challenges_table( 'bible_questions' );
	$where = array( '1=1' );
	$vals  = array();
	$args  = (array) $args;
	if ( ! empty( $args['status'] ) && in_array( (string) $args['status'], JLIFE_QUESTIONS_STATUSES, true ) ) {
		$where[] = 'q.status = %s';
		$vals[]  = (string) $args['status'];
	}
	if ( ! empty( $args['day_key'] ) ) {
		$where[] = 'q.day_key = %s';
		$vals[]  = sanitize_key( (string) $args['day_key'] );
	}
	if ( isset( $args['s'] ) && '' !== trim( (string) $args['s'] ) ) {
		$like    = '%' . $wpdb->esc_like( trim( (string) $args['s'] ) ) . '%';
		$where[] = '(q.body LIKE %s OR q.reference LIKE %s)';
		$vals[]  = $like;
		$vals[]  = $like;
	}
	$sql  = 'SELECT q.*, u.display_name FROM ' . $table . ' q LEFT JOIN ' . $wpdb->users . ' u ON u.ID = q.user_id WHERE ' . implode( ' AND ', $where ) . ' ORDER BY q.id DESC LIMIT ' . (int) JLIFE_QUESTIONS_LIST_LIMIT;
	$rows = $vals ? $wpdb->get_results( $wpdb->prepare( $sql, $vals ), ARRAY_A ) : $wpdb->get_results( $sql, ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- table names from $wpdb; values prepared.
	return (array) $rows;
}

/**
 * One question for a reviewer.
 *
 * @param int $actor Viewer.
 * @param int $id    Question ID.
 * @return array|WP_Error Row with display_name; denied the same way as "not found".
 */
function jlife_questions_get( $actor, $id ) {
	if ( ! jlife_questions_can_review( $actor ) || (int) $id < 1 ) {
		return jlife_challenges_denied();
	}
	global $wpdb;
	$table = jlife_challenges_table( 'bible_questions' );
	$row   = $wpdb->get_row( $wpdb->prepare( 'SELECT q.*, u.display_name FROM ' . $table . ' q LEFT JOIN ' . $wpdb->users . ' u ON u.ID = q.user_id WHERE q.id = %d', (int) $id ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- table names from $wpdb.
	return $row ? $row : jlife_challenges_denied();
}

/**
 * The nonce action of one admin operation on one question (0 = not about one).
 *
 * @param string $op Operation.
 * @param int    $id Question ID.
 * @return string
 */
function jlife_questions_nonce_action( $op, $id = 0 ) {
	return 'jlife_questions_' . sanitize_key( (string) $op ) . '_' . (int) $id;
}

/**
 * The plain-text export of chosen questions: passage and question only. No
 * name, id, date, status, age marker or pastor note.
 *
 * @param array[] $rows Question rows.
 * @return string
 */
function jlife_questions_export_text( array $rows ) {
	usort(
		$rows,
		static function ( $a, $b ) {
			return strcmp( (string) $a['reference'], (string) $b['reference'] ) ? strcmp( (string) $a['reference'], (string) $b['reference'] ) : (int) $a['id'] - (int) $b['id'];
		}
	);
	$out = '';
	$n   = 0;
	foreach ( $rows as $row ) {
		++$n;
		$ref  = '' !== (string) $row['reference'] ? '(' . $row['reference'] . ') ' : '';
		$out .= $n . '. ' . $ref . str_replace( "\n", "\n   ", (string) $row['body'] ) . "\n\n";
	}
	return rtrim( $out ) . "\n";
}

/**
 * Apply one admin write. Pure: nothing is printed or redirected.
 *
 * Checks in order: reviewer, POST, known operation, nonce for that operation and
 * question. Fields: op, nonce, id, status, note, ids (export), enabled.
 *
 * @param string $method 'GET' or 'POST' (only POST changes anything).
 * @param array  $fields Request fields.
 * @return array { code, id, text } where code is forbidden|bad_method|bad_op|bad_nonce|bad_status|bad_note|not_found|not_archived|no_selection|bad_value or saved|deleted|exported|enabled|disabled.
 */
function jlife_questions_apply( $method, array $fields ) {
	$actor  = get_current_user_id();
	$id     = (int) ( $fields['id'] ?? 0 );
	$result = static function ( $code, $text = '' ) use ( $id ) {
		return array(
			'code' => $code,
			'id'   => $id,
			'text' => $text,
		);
	};
	if ( ! jlife_questions_can_review( $actor ) ) {
		return $result( 'forbidden' );
	}
	if ( 'POST' !== strtoupper( (string) $method ) ) {
		return $result( 'bad_method' );
	}
	$op = (string) ( $fields['op'] ?? '' );
	if ( ! in_array( $op, array( 'status', 'note', 'delete', 'export', 'enable' ), true ) ) {
		return $result( 'bad_op' );
	}
	if ( ! wp_verify_nonce( (string) ( $fields['nonce'] ?? '' ), jlife_questions_nonce_action( $op, $id ) ) ) {
		return $result( 'bad_nonce' );
	}
	global $wpdb;
	$table = jlife_challenges_table( 'bible_questions' );

	if ( 'enable' === $op ) {
		$on = '1' === (string) ( $fields['enabled'] ?? '' );
		update_option( JLIFE_QUESTIONS_OPTION, $on ? '1' : '0', false );
		jlife_challenges_audit( $actor, 'questions_switch', 'questions', 0, 0, array( 'on' => $on ? 1 : 0 ) );
		return $result( $on ? 'enabled' : 'disabled' );
	}

	if ( 'export' === $op ) {
		$ids = array_values( array_filter( array_map( 'intval', (array) ( $fields['ids'] ?? array() ) ) ) );
		if ( ! $ids ) {
			return $result( 'no_selection' );
		}
		$marks = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
		$rows  = (array) $wpdb->get_results( $wpdb->prepare( 'SELECT id, reference, body FROM ' . $table . ' WHERE id IN (' . $marks . ')', $ids ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- the placeholders are built from a count; table name from $wpdb->prefix.
		if ( ! $rows ) {
			return $result( 'no_selection' );
		}
		jlife_challenges_audit( $actor, 'questions_exported', 'questions', 0, 0, array( 'count' => count( $rows ) ) ); // A count only.
		return $result( 'exported', jlife_questions_export_text( $rows ) );
	}

	$row = $wpdb->get_row( $wpdb->prepare( 'SELECT id, status, campaign_id FROM ' . $table . ' WHERE id = %d', $id ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- table name from $wpdb->prefix.
	if ( 'status' === $op ) {
		$status = (string) ( $fields['status'] ?? '' );
		if ( ! in_array( $status, JLIFE_QUESTIONS_STATUSES, true ) ) {
			return $result( 'bad_status' );
		}
		if ( ! $row ) {
			return $result( 'not_found' );
		}
		$wpdb->update(
			$table,
			array(
				'status'         => $status,
				'status_changed' => jlife_challenges_now()->format( 'Y-m-d H:i:s' ),
				'status_by'      => $actor,
			),
			array( 'id' => $id ),
			array( '%s', '%s', '%d' ),
			array( '%d' )
		);
		jlife_challenges_audit( $actor, 'question_status', 'question', $id, (int) $row['campaign_id'], array( 'status' => $status ) );
		return $result( 'saved' );
	}
	if ( 'note' === $op ) {
		$note = jlife_questions_clean( $fields['note'] ?? '' );
		if ( jlife_questions_length( $note ) > JLIFE_QUESTIONS_NOTE_MAX ) {
			return $result( 'bad_note' );
		}
		if ( ! $row ) {
			return $result( 'not_found' );
		}
		$wpdb->update( $table, array( 'pastor_note' => $note ), array( 'id' => $id ), array( '%s' ), array( '%d' ) );
		jlife_challenges_audit( $actor, 'question_note', 'question', $id, (int) $row['campaign_id'], array() );
		return $result( 'saved' );
	}
	// delete.
	if ( ! $row ) {
		return $result( 'not_found' );
	}
	if ( 'archived' !== $row['status'] ) {
		return $result( 'not_archived' );
	}
	$wpdb->delete( $table, array( 'id' => $id ), array( '%d' ) );
	jlife_challenges_audit( $actor, 'question_deleted', 'question', $id, (int) $row['campaign_id'], array() );
	return $result( 'deleted' );
}

/**
 * Remove every question a person asked (account deletion).
 *
 * @param int $user_id User ID.
 */
function jlife_questions_delete_for_user( $user_id ) {
	global $wpdb;
	$wpdb->delete( jlife_challenges_table( 'bible_questions' ), array( 'user_id' => (int) $user_id ), array( '%d' ) );
}
