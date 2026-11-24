<?php
/**
 * Ask the Pastors, the reader's side (#249): the entry point on Today and on
 * earlier days, a link on My Marathon, and the separate /ask/ screen.
 *
 * The question form shares nothing with the reflection form: its own screen,
 * its own nonce (`jlife_ask_<day>`), its own fields, no REST draft, no share
 * boxes. A question never changes a draft, a point or a group share.
 *
 * @package jlife-challenges
 */

defined( 'ABSPATH' ) || exit;

/**
 * The passage a day's reading names, as a prefill for the question form.
 *
 * @param array $view Day view.
 * @return string Up to 100 characters; empty when there is no reading.
 */
function jlife_questions_reference_for_view( $view ) {
	$displays = array();
	foreach ( (array) ( $view['reading']['day']['passages'] ?? array() ) as $passage ) {
		if ( '' !== trim( (string) ( $passage['display'] ?? '' ) ) ) {
			$displays[] = trim( (string) $passage['display'] );
		}
	}
	$text = implode( '; ', $displays );
	return function_exists( 'mb_substr' ) ? mb_substr( $text, 0, JLIFE_QUESTIONS_REF_MAX, 'UTF-8' ) : substr( $text, 0, JLIFE_QUESTIONS_REF_MAX );
}

/**
 * The day a view belongs to, or '' when it is not an active day.
 *
 * @param array $view Day view.
 * @return string
 */
function jlife_questions_view_day( $view ) {
	return 'active' === ( $view['state'] ?? '' ) ? (string) $view['day_key'] : '';
}

/**
 * The entry point on a day: one link-button to the separate question screen,
 * shown after the reading and before the reflection editor.
 *
 * @param array $view Day view.
 * @return string Escaped HTML; empty when the feature is off, the campaign is closed or the day is read ahead.
 */
function jlife_questions_entry_html( $view ) {
	if ( ! jlife_questions_enabled() || 'active' !== ( $view['state'] ?? '' ) || ! empty( $view['closed'] ) || ! empty( $view['ahead'] ) ) {
		return '';
	}
	return '<p class="jl-ask-entry"><a class="jl-button" href="' . esc_url( jlife_challenges_screen_url( 'ask', (string) $view['day_key'] ) ) . '">' . esc_html__( 'Have a Bible question? Ask the Pastors', 'jlife-challenges' ) . '</a></p>';
}

/**
 * The link on My Marathon, for a question about no particular day.
 *
 * @return string Escaped HTML; empty when the feature is off.
 */
function jlife_questions_marathon_link_html() {
	if ( ! jlife_questions_enabled() ) {
		return '';
	}
	return '<p class="jl-ask-entry"><a class="jl-button" href="' . esc_url( jlife_challenges_screen_url( 'ask' ) ) . '">' . esc_html__( 'Have a Bible question? Ask the Pastors', 'jlife-challenges' ) . '</a></p>';
}

/**
 * The question form.
 *
 * @param array  $view   Day view (any state; only an active one gives a day).
 * @param array  $values Typed values to keep: jl_question, jl_ref.
 * @param string $error  A refusal to show above the form.
 * @return string Escaped HTML.
 */
function jlife_questions_form_html( $view, $values = array(), $error = '' ) {
	$day = jlife_questions_view_day( $view );
	$ref = array_key_exists( 'jl_ref', $values ) ? (string) $values['jl_ref'] : jlife_questions_reference_for_view( $view );
	return '<section class="jl-ask">'
		. '<h1>' . esc_html__( 'Have a Bible question?', 'jlife-challenges' ) . '</h1>'
		. '<p>' . esc_html__( "Questions are part of growing in God's Word. If you're comfortable, consider discussing your question with your group first—we learn together. You can also ask our pastors privately, whether or not you're in a group.", 'jlife-challenges' ) . '</p>'
		. ( '' !== $error ? '<p class="jl-err" role="alert">' . esc_html( $error ) . '</p>' : '' )
		. '<form method="post" class="jl-soap jl-ask-form" action="' . esc_url( jlife_challenges_screen_url( 'ask', $day ) ) . '">'
		. wp_nonce_field( 'jlife_ask_' . $day, '_jlife_ask', true, false )
		. '<input type="hidden" name="jl_day" value="' . esc_attr( $day ) . '">'
		. '<label for="jl-ask-ref">' . esc_html__( 'Scripture passage (optional)', 'jlife-challenges' ) . '</label>'
		. '<input type="text" id="jl-ask-ref" name="jl_ref" maxlength="' . (int) JLIFE_QUESTIONS_REF_MAX . '" value="' . esc_attr( $ref ) . '" style="width:100%">'
		. '<label for="jl-ask-q">' . esc_html__( 'Your question', 'jlife-challenges' ) . '</label>'
		. '<textarea id="jl-ask-q" name="jl_question" rows="7" maxlength="' . (int) JLIFE_QUESTIONS_MAX_CHARS . '" required style="min-height:9rem">' . esc_textarea( (string) ( $values['jl_question'] ?? '' ) ) . '</textarea>'
		. '<div class="jl-note"><p>' . esc_html__( 'What happens next?', 'jlife-challenges' ) . '</p>'
		. '<p style="font-weight:400">' . esc_html__( 'Your question is visible to authorized pastors, not to your group or other participants. Pastors may choose questions for an optional Bible Q&A after Sunday worship and discuss them without saying who asked. Please do not expect an immediate or personal reply; not every question can be covered.', 'jlife-challenges' ) . '</p></div>'
		. '<p class="jl-soap-def">' . esc_html__( 'This form is for Bible questions. It is not watched for urgent needs.', 'jlife-challenges' ) . '</p>'
		. '<button type="submit" class="jl-button jl-primary">' . esc_html__( 'Submit Question to Pastors', 'jlife-challenges' ) . '</button>'
		. '</form></section>';
}

/**
 * What the reader sees after a question was sent.
 *
 * @return string Escaped HTML.
 */
function jlife_questions_receipt_html() {
	return '<section class="jl-ask"><p class="jl-ok" role="status">' . esc_html__( "Thank you. Your question was sent privately to the pastors. You don't need to wait here for an answer.", 'jlife-challenges' ) . '</p>'
		. '<p><a class="jl-button" href="' . esc_url( jlife_challenges_screen_url( 'today' ) ) . '">' . esc_html__( 'Back to Today', 'jlife-challenges' ) . '</a></p></section>';
}

/**
 * Process one posted question form. Pure: nothing is printed or redirected.
 *
 * @param int   $user_id Asker.
 * @param array $view    Day view the form belongs to.
 * @param array $post    Posted fields: _jlife_ask, jl_question, jl_ref.
 * @return array { result: sent|bad_nonce|error, values: array, error: string }
 */
function jlife_questions_process_request( $user_id, $view, $post ) {
	$day    = jlife_questions_view_day( $view );
	$values = array(
		'jl_question' => (string) ( $post['jl_question'] ?? '' ),
		'jl_ref'      => (string) ( $post['jl_ref'] ?? '' ),
	);
	if ( ! wp_verify_nonce( (string) ( $post['_jlife_ask'] ?? '' ), 'jlife_ask_' . $day ) ) {
		return array(
			'result' => 'bad_nonce',
			'values' => $values,
			'error'  => __( 'Sorry, that did not go through. Please try again.', 'jlife-challenges' ),
		);
	}
	$r = jlife_questions_submit( (int) $user_id, $day, $values['jl_ref'], $values['jl_question'] );
	if ( is_wp_error( $r ) ) {
		return array(
			'result' => 'error',
			'values' => $values,
			'error'  => $r->get_error_message(),
		);
	}
	return array(
		'result' => 'sent',
		'values' => array(),
		'error'  => '',
	);
}

/**
 * /ask/ (optionally ?day=d###): the question form, then a receipt.
 */
function jlife_questions_screen_ask() {
	$user_id = jlife_challenges_require_participant();
	if ( ! jlife_questions_enabled() ) {
		wp_safe_redirect( home_url( '/today/' ) );
		exit;
	}
	// phpcs:disable WordPress.Security.NonceVerification -- the day only picks which form to show; the POST is verified in jlife_questions_process_request().
	$posting = isset( $_SERVER['REQUEST_METHOD'] ) && 'POST' === strtoupper( sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) ) && isset( $_POST['jl_question'] );
	if ( $posting ) {
		$day = isset( $_POST['jl_day'] ) ? sanitize_key( wp_unslash( $_POST['jl_day'] ) ) : '';
	} else {
		$day = isset( $_GET['day'] ) ? sanitize_key( wp_unslash( $_GET['day'] ) ) : '';
	}
	$sent = isset( $_GET['sent'] );
	// phpcs:enable
	$title = __( 'Ask the Pastors', 'jlife-challenges' );
	$view  = jlife_challenges_day_view( $user_id, '' !== $day ? $day : null );
	if ( 'not_enrolled' === $view['state'] || 'unavailable' === $view['state'] ) {
		jlife_challenges_send_page( $title, (string) jlife_challenges_render_no_day( $view ), 'today' );
	}
	if ( $sent ) {
		jlife_challenges_send_page( $title, jlife_questions_receipt_html(), 'today' );
	}
	if ( ! empty( $view['closed'] ) ) {
		jlife_challenges_send_page( $title, '<p class="jl-note" role="status">' . esc_html( jlife_challenges_campaign_closed_error()->get_error_message() ) . '</p>', 'today' );
	}
	$error  = '';
	$values = array();
	if ( $posting ) {
		// phpcs:disable WordPress.Security.NonceVerification.Missing,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- verified and cleaned in jlife_questions_process_request() and jlife_questions_submit().
		$done = jlife_questions_process_request(
			$user_id,
			$view,
			array(
				'_jlife_ask'  => isset( $_POST['_jlife_ask'] ) ? wp_unslash( $_POST['_jlife_ask'] ) : '',
				'jl_question' => isset( $_POST['jl_question'] ) ? wp_unslash( $_POST['jl_question'] ) : '',
				'jl_ref'      => isset( $_POST['jl_ref'] ) ? wp_unslash( $_POST['jl_ref'] ) : '',
			)
		);
		// phpcs:enable
		if ( 'sent' === $done['result'] ) {
			wp_safe_redirect( add_query_arg( 'sent', '1', jlife_challenges_screen_url( 'ask', jlife_questions_view_day( $view ) ) ) );
			exit;
		}
		$error  = $done['error'];
		$values = $done['values'];
	}
	jlife_challenges_send_page( $title, jlife_questions_form_html( $view, $values, $error ), 'today' );
}
