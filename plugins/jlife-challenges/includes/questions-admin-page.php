<?php
/**
 * J-Life → Bible Questions (#249): the private queue in wp-admin. Site
 * administrators only, through jlife_questions_can_review() on every path.
 *
 * - List: received, day / passage, truncated question, who asked, status; filter
 *   by status, day and text. Open one question for the full text, status, a
 *   private note and (archived only) permanent delete.
 * - Copy selected questions without names for the after-worship Q&A.
 * - The on/off switch for readers, default off.
 * - Writes go through admin-post.php: POST only, nonce per operation and
 *   question, then jlife_questions_apply(). Nothing here prints an email address.
 *
 * @package jlife-challenges
 */

defined( 'ABSPATH' ) || exit;

const JLIFE_QUESTIONS_PAGE   = 'jlife-questions';
const JLIFE_QUESTIONS_ACTION = 'jlife_questions';

add_action( 'admin_menu', 'jlife_questions_admin_menu' );
add_action( 'admin_post_' . JLIFE_QUESTIONS_ACTION, 'jlife_questions_admin_post' );

/**
 * The page's address in the site dashboard.
 *
 * @param array $args Extra query arguments.
 * @return string
 */
function jlife_questions_admin_url( $args = array() ) {
	return add_query_arg( array_merge( array( 'page' => JLIFE_QUESTIONS_PAGE ), $args ), get_admin_url( get_current_blog_id(), 'admin.php' ) );
}

/**
 * Add J-Life → Bible Questions (site administrators).
 */
function jlife_questions_admin_menu() {
	add_submenu_page( JLIFE_CHALLENGES_REMINDERS_PAGE, __( 'Bible Questions', 'jlife-challenges' ), __( 'Bible Questions', 'jlife-challenges' ), 'manage_options', JLIFE_QUESTIONS_PAGE, 'jlife_questions_admin_page' );
}

/**
 * What the page shows. Denied unless the viewer is a reviewer.
 *
 * @param int   $actor Viewer.
 * @param array $args  status, day_key, s, q (a question to open).
 * @return array|WP_Error { enabled, rows, filters, detail }
 */
function jlife_questions_admin_view( $actor, $args ) {
	if ( ! jlife_questions_can_review( $actor ) ) {
		return jlife_challenges_denied();
	}
	$args    = (array) $args;
	$filters = array(
		'status'  => in_array( (string) ( $args['status'] ?? '' ), JLIFE_QUESTIONS_STATUSES, true ) ? (string) $args['status'] : '',
		'day_key' => sanitize_key( (string) ( $args['day_key'] ?? '' ) ),
		's'       => trim( (string) ( $args['s'] ?? '' ) ),
	);
	$detail  = null;
	if ( ! empty( $args['q'] ) ) {
		$found  = jlife_questions_get( $actor, (int) $args['q'] );
		$detail = is_wp_error( $found ) ? null : $found;
	}
	return array(
		'enabled' => jlife_questions_enabled(),
		'rows'    => $detail ? array() : jlife_questions_list( $actor, $filters ),
		'filters' => $filters,
		'detail'  => $detail,
	);
}

/**
 * The label of a status.
 *
 * @param string $status Status.
 * @return string
 */
function jlife_questions_status_label( $status ) {
	$labels = array(
		'new'       => __( 'New', 'jlife-challenges' ),
		'selected'  => __( 'Selected for Q&A', 'jlife-challenges' ),
		'addressed' => __( 'Addressed', 'jlife-challenges' ),
		'archived'  => __( 'Archived', 'jlife-challenges' ),
	);
	return $labels[ $status ] ?? $labels['new'];
}

/**
 * A form that posts one operation to admin-post.php.
 *
 * @param string $op    Operation.
 * @param int    $id    Question ID (0 = not about one).
 * @param string $inner Escaped fields and buttons.
 * @return string Escaped HTML.
 */
function jlife_questions_admin_form( $op, $id, $inner ) {
	return '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">'
		. '<input type="hidden" name="action" value="' . esc_attr( JLIFE_QUESTIONS_ACTION ) . '">'
		. '<input type="hidden" name="jl_op" value="' . esc_attr( $op ) . '">'
		. '<input type="hidden" name="jl_id" value="' . (int) $id . '">'
		. '<input type="hidden" name="jl_nonce" value="' . esc_attr( wp_create_nonce( jlife_questions_nonce_action( $op, $id ) ) ) . '">'
		. $inner . '</form>';
}

/**
 * Received date, in the site's date format.
 *
 * @param string $utc 'Y-m-d H:i:s' in UTC.
 * @return string
 */
function jlife_questions_admin_date( $utc ) {
	return wp_date( (string) get_option( 'date_format' ), (int) strtotime( $utc . ' UTC' ) );
}

/**
 * Who asked, for a reviewer: the display name and, for a minor, the badge.
 *
 * @param array $row Question row.
 * @return string Escaped HTML.
 */
function jlife_questions_admin_asker( $row ) {
	$name = '' !== (string) ( $row['display_name'] ?? '' ) ? (string) $row['display_name'] : __( '(account removed)', 'jlife-challenges' );
	return esc_html( $name ) . ( ! empty( $row['minor'] ) ? ' <span class="dashicons dashicons-warning" aria-hidden="true"></span><strong>' . esc_html__( 'Under 18', 'jlife-challenges' ) . '</strong>' : '' );
}

/**
 * The page body.
 *
 * @param array $view jlife_questions_admin_view().
 * @return string Escaped HTML.
 */
function jlife_questions_admin_html( array $view ) {
	$html = '<div class="wrap"><h1>' . esc_html__( 'Bible Questions', 'jlife-challenges' ) . '</h1>'
		. '<p>' . esc_html__( 'Questions readers sent privately to the pastors. Only site administrators can see this screen. Readers are not anonymous to you, so treat who asked as private.', 'jlife-challenges' ) . '</p>';
	$html .= jlife_questions_admin_switch( $view );
	if ( ! empty( $view['detail'] ) ) {
		return $html . jlife_questions_admin_detail( $view['detail'] ) . '</div>';
	}
	return $html . jlife_questions_admin_list( $view ) . '</div>';
}

/**
 * The on/off switch for readers.
 *
 * @param array $view View.
 * @return string Escaped HTML.
 */
function jlife_questions_admin_switch( array $view ) {
	$on = ! empty( $view['enabled'] );
	return '<h2>' . esc_html__( 'Readers can ask', 'jlife-challenges' ) . '</h2>'
		. '<p>' . esc_html( $on ? __( 'On: readers see "Ask the Pastors" on Today and My Marathon.', 'jlife-challenges' ) : __( 'Off: readers do not see "Ask the Pastors".', 'jlife-challenges' ) ) . '</p>'
		. jlife_questions_admin_form(
			'enable',
			0,
			'<input type="hidden" name="jl_enabled" value="' . ( $on ? '0' : '1' ) . '"><button type="submit" class="button ' . ( $on ? 'button-secondary' : 'button-primary' ) . '">' . esc_html( $on ? __( 'Turn off', 'jlife-challenges' ) : __( 'Turn on', 'jlife-challenges' ) ) . '</button>'
		);
}

/**
 * The queue: filters and the table, inside the export form.
 *
 * @param array $view View.
 * @return string Escaped HTML.
 */
function jlife_questions_admin_list( array $view ) {
	$f    = $view['filters'];
	$html = '<h2>' . esc_html__( 'Questions', 'jlife-challenges' ) . '</h2>'
		. '<form method="get" action="' . esc_url( get_admin_url( get_current_blog_id(), 'admin.php' ) ) . '"><input type="hidden" name="page" value="' . esc_attr( JLIFE_QUESTIONS_PAGE ) . '">'
		. '<label for="jl-q-status">' . esc_html__( 'Status', 'jlife-challenges' ) . '</label> <select id="jl-q-status" name="status"><option value="">' . esc_html__( 'All', 'jlife-challenges' ) . '</option>';
	foreach ( JLIFE_QUESTIONS_STATUSES as $status ) {
		$html .= '<option value="' . esc_attr( $status ) . '"' . selected( $f['status'], $status, false ) . '>' . esc_html( jlife_questions_status_label( $status ) ) . '</option>';
	}
	$html .= '</select> <label for="jl-q-day">' . esc_html__( 'Day', 'jlife-challenges' ) . '</label> <input id="jl-q-day" type="text" name="day_key" size="6" value="' . esc_attr( $f['day_key'] ) . '" placeholder="d001"> '
		. '<label for="jl-q-s">' . esc_html__( 'Search', 'jlife-challenges' ) . '</label> <input id="jl-q-s" type="search" name="s" value="' . esc_attr( $f['s'] ) . '"> '
		. '<button type="submit" class="button">' . esc_html__( 'Filter', 'jlife-challenges' ) . '</button></form>';
	if ( empty( $view['rows'] ) ) {
		return $html . '<p>' . esc_html__( 'No questions yet.', 'jlife-challenges' ) . '</p>';
	}
	$rows = '';
	foreach ( $view['rows'] as $row ) {
		$short = jlife_questions_length( (string) $row['body'] ) > 90 ? mb_substr( (string) $row['body'], 0, 90, 'UTF-8' ) . '…' : (string) $row['body'];
		$day   = trim( ( '' !== $row['day_key'] ? $row['day_key'] : '' ) . ' ' . $row['reference'] );
		$rows .= '<tr>'
			. '<th scope="row" class="check-column"><input type="checkbox" name="jl_ids[]" value="' . (int) $row['id'] . '" aria-label="' . esc_attr__( 'Select this question', 'jlife-challenges' ) . '"></th>'
			. '<td>' . esc_html( jlife_questions_admin_date( (string) $row['created'] ) ) . '</td>'
			. '<td>' . esc_html( $day ) . '</td>'
			. '<td><a href="' . esc_url( jlife_questions_admin_url( array( 'q' => (int) $row['id'] ) ) ) . '">' . esc_html( $short ) . '</a></td>'
			. '<td>' . jlife_questions_admin_asker( $row ) . '</td>'
			. '<td>' . esc_html( jlife_questions_status_label( (string) $row['status'] ) ) . '</td>'
			. '</tr>';
	}
	$table = '<table class="widefat striped"><thead><tr><td class="check-column"></td><th>' . esc_html__( 'Received', 'jlife-challenges' ) . '</th><th>' . esc_html__( 'Day / passage', 'jlife-challenges' ) . '</th><th>' . esc_html__( 'Question', 'jlife-challenges' ) . '</th><th>' . esc_html__( 'Asked by', 'jlife-challenges' ) . '</th><th>' . esc_html__( 'Status', 'jlife-challenges' ) . '</th></tr></thead><tbody>' . $rows . '</tbody></table>';
	return $html . jlife_questions_admin_form(
		'export',
		0,
		$table
		. '<p>' . esc_html__( 'Questions can still identify the person who asked. Paraphrase them, and never name or point to the asker without their separate consent.', 'jlife-challenges' ) . '</p>'
		. '<p><button type="submit" class="button button-primary">' . esc_html__( 'Copy selected questions (names removed)', 'jlife-challenges' ) . '</button> '
		. '<button type="submit" name="jl_format" value="txt" class="button">' . esc_html__( 'Download selected as a text file', 'jlife-challenges' ) . '</button></p>'
	);
}

/**
 * One question: full text, status, private note, delete (archived only).
 *
 * @param array $row Question row.
 * @return string Escaped HTML.
 */
function jlife_questions_admin_detail( array $row ) {
	$id   = (int) $row['id'];
	$html = '<p><a href="' . esc_url( jlife_questions_admin_url() ) . '">&larr; ' . esc_html__( 'All questions', 'jlife-challenges' ) . '</a></p>'
		. '<h2>' . esc_html__( 'Question', 'jlife-challenges' ) . '</h2>'
		. '<table class="form-table" role="presentation"><tbody>'
		. '<tr><th scope="row">' . esc_html__( 'Received', 'jlife-challenges' ) . '</th><td>' . esc_html( jlife_questions_admin_date( (string) $row['created'] ) ) . '</td></tr>'
		. '<tr><th scope="row">' . esc_html__( 'Asked by', 'jlife-challenges' ) . '</th><td>' . jlife_questions_admin_asker( $row ) . '</td></tr>'
		. '<tr><th scope="row">' . esc_html__( 'Day / passage', 'jlife-challenges' ) . '</th><td>' . esc_html( trim( $row['day_key'] . ' ' . $row['reference'] ) ) . '</td></tr>'
		. '<tr><th scope="row">' . esc_html__( 'Status', 'jlife-challenges' ) . '</th><td>' . esc_html( jlife_questions_status_label( (string) $row['status'] ) ) . '</td></tr>'
		. '</tbody></table>'
		. '<div class="card" style="max-width:48rem"><p style="white-space:pre-wrap">' . esc_html( (string) $row['body'] ) . '</p></div>';
	if ( ! empty( $row['minor'] ) ) {
		$html .= '<p><strong>' . esc_html__( 'Under 18', 'jlife-challenges' ) . '</strong>: ' . esc_html__( 'Questions can still identify the person who asked. Paraphrase them, and never name or point to the asker without their separate consent.', 'jlife-challenges' ) . '</p>';
	}
	$options = '';
	foreach ( JLIFE_QUESTIONS_STATUSES as $status ) {
		$options .= '<option value="' . esc_attr( $status ) . '"' . selected( (string) $row['status'], $status, false ) . '>' . esc_html( jlife_questions_status_label( $status ) ) . '</option>';
	}
	$html .= '<h3>' . esc_html__( 'Status', 'jlife-challenges' ) . '</h3>'
		. jlife_questions_admin_form( 'status', $id, '<select name="jl_status" aria-label="' . esc_attr__( 'Status', 'jlife-challenges' ) . '">' . $options . '</select> <button type="submit" class="button button-primary">' . esc_html__( 'Save status', 'jlife-challenges' ) . '</button>' )
		. '<h3>' . esc_html__( 'Private note', 'jlife-challenges' ) . '</h3>'
		. '<p>' . esc_html__( 'Only administrators see this note. It is never part of the copied or downloaded list.', 'jlife-challenges' ) . '</p>'
		. jlife_questions_admin_form( 'note', $id, '<textarea name="jl_note" rows="4" cols="60" maxlength="' . (int) JLIFE_QUESTIONS_NOTE_MAX . '" aria-label="' . esc_attr__( 'Private note', 'jlife-challenges' ) . '">' . esc_textarea( (string) $row['pastor_note'] ) . '</textarea><p><button type="submit" class="button">' . esc_html__( 'Save note', 'jlife-challenges' ) . '</button></p>' );
	if ( 'archived' === $row['status'] ) {
		$html .= '<h3>' . esc_html__( 'Delete', 'jlife-challenges' ) . '</h3>'
			. '<p>' . esc_html__( 'Deleting removes the question for good. It cannot be undone.', 'jlife-challenges' ) . '</p>'
			. jlife_questions_admin_form( 'delete', $id, '<p><label><input type="checkbox" name="jl_confirm" value="1" required> ' . esc_html__( 'I understand', 'jlife-challenges' ) . '</label></p><p><button type="submit" class="button button-secondary">' . esc_html__( 'Delete question', 'jlife-challenges' ) . '</button></p>' );
	} else {
		$html .= '<p>' . esc_html__( 'To delete a question, archive it first.', 'jlife-challenges' ) . '</p>';
	}
	return $html;
}

/**
 * The sentence for a result code shown after a redirect.
 *
 * @param string $code Result code.
 * @return string
 */
function jlife_questions_admin_message( $code ) {
	$map = array(
		'saved'        => __( 'Saved.', 'jlife-challenges' ),
		'deleted'      => __( 'Question deleted.', 'jlife-challenges' ),
		'enabled'      => __( 'Readers can now ask questions.', 'jlife-challenges' ),
		'disabled'     => __( 'Readers can no longer ask questions.', 'jlife-challenges' ),
		'not_archived' => __( 'Only archived questions can be deleted. Archive it first.', 'jlife-challenges' ),
		'no_selection' => __( 'Select at least one question first.', 'jlife-challenges' ),
		'bad_note'     => __( 'That note is too long.', 'jlife-challenges' ),
		'bad_status'   => __( 'That is not a status.', 'jlife-challenges' ),
		'not_found'    => __( 'That question was not found.', 'jlife-challenges' ),
	);
	return $map[ $code ] ?? '';
}

/**
 * The page.
 */
function jlife_questions_admin_page() {
	if ( ! jlife_questions_can_review( get_current_user_id() ) ) {
		wp_die( esc_html__( 'You are not allowed to see this page.', 'jlife-challenges' ), '', array( 'response' => 403 ) );
	}
	// phpcs:disable WordPress.Security.NonceVerification.Recommended -- display only: these choose what to show and change nothing.
	$args = array(
		'status'  => isset( $_GET['status'] ) ? sanitize_key( wp_unslash( $_GET['status'] ) ) : '',
		'day_key' => isset( $_GET['day_key'] ) ? sanitize_key( wp_unslash( $_GET['day_key'] ) ) : '',
		's'       => isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : '',
		'q'       => isset( $_GET['q'] ) ? absint( $_GET['q'] ) : 0,
	);
	$code = isset( $_GET['jl_result'] ) ? sanitize_key( wp_unslash( $_GET['jl_result'] ) ) : '';
	// phpcs:enable
	$view = jlife_questions_admin_view( get_current_user_id(), $args );
	if ( is_wp_error( $view ) ) {
		wp_die( esc_html__( 'You are not allowed to see this page.', 'jlife-challenges' ), '', array( 'response' => 403 ) );
	}
	$message = jlife_questions_admin_message( $code );
	$html    = jlife_questions_admin_html( $view );
	if ( '' !== $message ) {
		$html = preg_replace( '/<h1>.*?<\/h1>/s', '$0<div class="notice notice-info"><p>' . esc_html( $message ) . '</p></div>', $html, 1 );
	}
	echo $html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- every part is escaped.
}

/**
 * The admin-post.php handler: read the form, apply it, then redirect, or show
 * the de-identified list for a copy or download.
 */
function jlife_questions_admin_post() {
	$method = isset( $_SERVER['REQUEST_METHOD'] ) ? strtoupper( sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) ) : 'GET';
	// phpcs:disable WordPress.Security.NonceVerification -- verified inside jlife_questions_apply().
	$ids    = isset( $_POST['jl_ids'] ) && is_array( $_POST['jl_ids'] ) ? array_map( 'absint', wp_unslash( $_POST['jl_ids'] ) ) : array();
	$op     = isset( $_POST['jl_op'] ) ? sanitize_key( wp_unslash( $_POST['jl_op'] ) ) : '';
	$id     = isset( $_POST['jl_id'] ) ? absint( $_POST['jl_id'] ) : 0;
	$format = isset( $_POST['jl_format'] ) ? sanitize_key( wp_unslash( $_POST['jl_format'] ) ) : '';
	$result = jlife_questions_apply(
		$method,
		array(
			'op'      => $op,
			'id'      => $id,
			'nonce'   => isset( $_POST['jl_nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['jl_nonce'] ) ) : '',
			'status'  => isset( $_POST['jl_status'] ) ? sanitize_key( wp_unslash( $_POST['jl_status'] ) ) : '',
			'note'    => isset( $_POST['jl_note'] ) ? wp_unslash( $_POST['jl_note'] ) : '', // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- cleaned by jlife_questions_clean().
			'ids'     => $ids,
			'enabled' => isset( $_POST['jl_enabled'] ) ? sanitize_key( wp_unslash( $_POST['jl_enabled'] ) ) : '',
		)
	);
	// phpcs:enable
	$code = (string) $result['code'];
	if ( in_array( $code, array( 'forbidden', 'bad_method', 'bad_nonce', 'bad_op' ), true ) ) {
		wp_die( esc_html__( 'You are not allowed to do that.', 'jlife-challenges' ), '', array( 'response' => 403 ) );
	}
	if ( 'exported' === $code ) {
		nocache_headers();
		header( 'X-Robots-Tag: noindex, nofollow' );
		if ( 'txt' === $format ) {
			header( 'Content-Type: text/plain; charset=utf-8' );
			header( 'Content-Disposition: attachment; filename="bible-questions.txt"' );
			echo $result['text']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- plain text download, not HTML.
			exit;
		}
		header( 'Content-Type: text/html; charset=utf-8' );
		echo '<!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>' . esc_html__( 'Bible Questions', 'jlife-challenges' ) . '</title></head><body style="font-family:sans-serif;max-width:48rem;margin:2rem auto;padding:0 1rem">'
			. '<h1>' . esc_html__( 'Questions for the Q&A (names removed)', 'jlife-challenges' ) . '</h1>'
			. '<p>' . esc_html__( 'Questions can still identify the person who asked. Paraphrase them, and never name or point to the asker without their separate consent.', 'jlife-challenges' ) . '</p>'
			. '<textarea readonly rows="16" style="width:100%" aria-label="' . esc_attr__( 'Questions', 'jlife-challenges' ) . '" onfocus="this.select()">' . esc_textarea( (string) $result['text'] ) . '</textarea>'
			. '<p><a href="' . esc_url( jlife_questions_admin_url() ) . '">&larr; ' . esc_html__( 'All questions', 'jlife-challenges' ) . '</a></p></body></html>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- every part is escaped.
		exit;
	}
	$back = array( 'jl_result' => $code );
	if ( $id > 0 && in_array( $op, array( 'status', 'note' ), true ) ) {
		$back['q'] = $id;
	}
	wp_safe_redirect( jlife_questions_admin_url( $back ) );
	exit;
}
