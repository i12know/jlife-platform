<?php
/**
 * Bridge-owned magic-link flow (spike S4, #11).
 *
 * A magic link is a bearer token: whoever holds the URL acts as the scoped
 * participant. This module keeps the blast radius small by construction:
 *
 * - The token is scoped to one D.T contact + one huddle (dt_group_id) + one
 *   lesson, with an expiry — never broad contact access.
 * - The participant lands on STUDY; HUB is never exposed to participants
 *   (pilot-context.md). Token state lives on the HUB contact record
 *   (D.T-style meta) and is read through this bridge only.
 * - The flow is cookie-independent: the token travels as a query parameter /
 *   form field, so in-app browsers (Zalo, Messenger) that drop cookies or
 *   third-party storage still work.
 * - Only low-sensitivity, leader-visible responses may flow through a link.
 *   Private reflection notes require accounts (S4/S5 shared rule).
 *
 * Spike note: responses are stored in a STUDY option for the PoC; real
 * storage with access control is S5 (#12) territory.
 *
 * @package jlife-bridge
 */

defined( 'ABSPATH' ) || exit;

const JLIFE_MAGIC_KEY_META  = 'jlife_magic_key';
const JLIFE_MAGIC_DATA_META = 'jlife_magic_link_data';

/**
 * Find the HUB blog ID (the subsite running Disciple.Tools).
 *
 * @return int Blog ID, 0 if not found.
 */
function jlife_bridge_hub_blog_id() {
	$sites = get_sites(
		array(
			'path__in' => array( '/hub/' ),
			'number'   => 1,
		)
	);
	$blog_id = $sites ? (int) $sites[0]->blog_id : 0;

	/**
	 * Filter the blog ID treated as HUB.
	 *
	 * @param int $blog_id Detected HUB blog ID.
	 */
	return (int) apply_filters( 'jlife_bridge_hub_blog_id', $blog_id );
}

/**
 * Find the STUDY blog ID (the participant-facing root subsite).
 *
 * @return int Blog ID, 0 if not found.
 */
function jlife_bridge_study_blog_id() {
	$sites = get_sites(
		array(
			'path__in' => array( '/' ),
			'number'   => 1,
		)
	);
	$blog_id = $sites ? (int) $sites[0]->blog_id : 0;

	/**
	 * Filter the blog ID treated as STUDY.
	 *
	 * @param int $blog_id Detected STUDY blog ID.
	 */
	return (int) apply_filters( 'jlife_bridge_study_blog_id', $blog_id );
}

/**
 * Create (or regenerate) a scoped magic link for a participant contact.
 *
 * Regeneration replaces the stored key, which invalidates any previously
 * issued URL for this contact.
 *
 * @param int    $dt_contact_id D.T contact post ID on HUB.
 * @param int    $dt_group_id   D.T group (huddle) the link is scoped to.
 * @param string $lesson_id     STUDY lesson_id the link may open.
 * @param int    $ttl           Lifetime in seconds.
 * @return string|WP_Error Absolute STUDY URL.
 */
function jlife_bridge_create_magic_link( $dt_contact_id, $dt_group_id, $lesson_id, $ttl = WEEK_IN_SECONDS ) {
	$hub   = jlife_bridge_hub_blog_id();
	$study = jlife_bridge_study_blog_id();
	if ( ! $hub ) {
		return new WP_Error( 'jlife_no_hub', __( 'HUB subsite not found.', 'jlife-bridge' ) );
	}
	if ( ! $study ) {
		return new WP_Error( 'jlife_no_study', __( 'STUDY subsite not found.', 'jlife-bridge' ) );
	}

	$token = function_exists( 'dt_create_unique_key' ) ? dt_create_unique_key() : wp_generate_password( 32, false );
	$data  = array(
		'dt_group_id' => (int) $dt_group_id,
		'lesson_id'   => (string) $lesson_id,
		'expires'     => time() + (int) $ttl,
		'revoked'     => false,
		'created'     => time(),
		'last_used'   => null,
		'use_count'   => 0,
	);

	switch_to_blog( $hub );
	update_post_meta( $dt_contact_id, JLIFE_MAGIC_KEY_META, $token );
	update_post_meta( $dt_contact_id, JLIFE_MAGIC_DATA_META, wp_slash( wp_json_encode( $data ) ) );
	restore_current_blog();

	return get_home_url( $study, '/?jlife_token=' . rawurlencode( $token ) );
}

/**
 * Revoke a contact's magic link.
 *
 * @param int $dt_contact_id D.T contact post ID on HUB.
 * @return bool
 */
function jlife_bridge_revoke_magic_link( $dt_contact_id ) {
	$hub = jlife_bridge_hub_blog_id();
	if ( ! $hub ) {
		return false;
	}
	switch_to_blog( $hub );
	$raw  = get_post_meta( $dt_contact_id, JLIFE_MAGIC_DATA_META, true );
	$data = $raw ? json_decode( (string) $raw, true ) : null;
	if ( is_array( $data ) ) {
		$data['revoked'] = true;
		update_post_meta( $dt_contact_id, JLIFE_MAGIC_DATA_META, wp_slash( wp_json_encode( $data ) ) );
	}
	restore_current_blog();
	return is_array( $data );
}

/**
 * Resolve a token to its contact + scope, enforcing expiry and revocation.
 *
 * @param string $token Bearer token from the URL/form.
 * @return array|null { dt_contact_id, dt_group_id, lesson_id } or null.
 */
function jlife_bridge_resolve_magic_token( $token ) {
	$hub = jlife_bridge_hub_blog_id();
	if ( ! $hub || '' === $token ) {
		return null;
	}

	switch_to_blog( $hub );
	$contacts = get_posts(
		array(
			'post_type'      => 'contacts',
			'post_status'    => 'any',
			'posts_per_page' => 1,
			'meta_key'       => JLIFE_MAGIC_KEY_META, // phpcs:ignore WordPress.DB.SlowDBQuery -- single exact-match token lookup, indexed by meta_key.
			'meta_value'     => $token, // phpcs:ignore WordPress.DB.SlowDBQuery
		)
	);
	$resolved = null;
	if ( $contacts ) {
		$contact_id = $contacts[0]->ID;
		$raw        = get_post_meta( $contact_id, JLIFE_MAGIC_DATA_META, true );
		$data       = $raw ? json_decode( (string) $raw, true ) : null;
		if ( is_array( $data ) && empty( $data['revoked'] ) && time() < (int) $data['expires'] ) {
			// Audit trail: record use.
			$data['last_used'] = time();
			$data['use_count'] = (int) $data['use_count'] + 1;
			update_post_meta( $contact_id, JLIFE_MAGIC_DATA_META, wp_slash( wp_json_encode( $data ) ) );

			$resolved = array(
				'dt_contact_id' => $contact_id,
				'dt_group_id'   => (int) $data['dt_group_id'],
				'lesson_id'     => (string) $data['lesson_id'],
			);
		}
	}
	restore_current_blog();
	return $resolved;
}

/**
 * Build the response key for a token scope.
 *
 * Scope includes the huddle ID so one participant can answer the same lesson
 * in separate huddles without cross-huddle reads or overwrites.
 *
 * @param array $scope Resolved token scope.
 * @return string
 */
function jlife_bridge_magic_response_key( $scope ) {
	return implode(
		':',
		array(
			(int) $scope['dt_contact_id'],
			(int) $scope['dt_group_id'],
			(string) $scope['lesson_id'],
		)
	);
}

add_action( 'template_redirect', 'jlife_bridge_magic_link_route' );

/**
 * STUDY route: render the scoped lesson + response form for a valid token.
 *
 * Cookie-independent by design; the token authenticates every request.
 */
function jlife_bridge_magic_link_route() {
	if ( ! isset( $_GET['jlife_token'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- the bearer token IS the credential for this no-login flow.
		return;
	}
	if ( is_admin() ) {
		return;
	}

	$token = sanitize_text_field( wp_unslash( $_GET['jlife_token'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
	$scope = jlife_bridge_resolve_magic_token( $token );

	if ( null === $scope ) {
		status_header( 403 );
		nocache_headers();
		// Generic denial: no lesson data, no participant data, no hint whether
		// the token ever existed (revoked and expired look identical).
		echo '<!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>' . esc_html__( 'Link not available', 'jlife-bridge' ) . '</title></head><body>';
		echo '<p>' . esc_html__( 'This link is no longer available. Please ask your huddle leader for a new one.', 'jlife-bridge' ) . '</p>';
		echo '</body></html>';
		exit;
	}

	// Handle a submitted response (low-sensitivity, leader-visible only).
	$notice = '';
	if ( isset( $_POST['jlife_response'] ) && isset( $_POST['jlife_token'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing -- bearer-token flow; token re-validated above and must match the posted one.
		$posted_token = sanitize_text_field( wp_unslash( $_POST['jlife_token'] ) );
		if ( $posted_token === $token ) {
			$responses = get_option( 'jlife_s4_responses', array() );
			$response_key = jlife_bridge_magic_response_key( $scope );
			$responses[ $response_key ] = array(
				'dt_contact_id' => $scope['dt_contact_id'],
				'dt_group_id'   => $scope['dt_group_id'],
				'lesson_id'     => $scope['lesson_id'],
				'response'      => sanitize_textarea_field( wp_unslash( $_POST['jlife_response'] ) ),
				'visibility'    => 'leader',
				'submitted'     => time(),
			);
			update_option( 'jlife_s4_responses', $responses, false );
			$notice = __( 'Response received. Your huddle leader can see it.', 'jlife-bridge' );
		}
	}

	jlife_bridge_render_magic_lesson( $scope, $token, $notice );
	exit;
}

/**
 * Minimal mobile-first lesson view for the magic-link flow.
 *
 * Deliberately generic <title> and no Open Graph tags: chat-app link
 * previews fetch this page anonymously and must not leak participant or
 * lesson specifics.
 *
 * @param array  $scope  Resolved token scope.
 * @param string $token  Bearer token (re-embedded in the form).
 * @param string $notice Post-submit notice, if any.
 */
function jlife_bridge_render_magic_lesson( $scope, $token, $notice ) {
	$lesson_post = function_exists( 'jlife_studies_find_post' )
		? jlife_studies_find_post( 'jlife_lesson', $scope['lesson_id'] )
		: null;
	$doc         = null;
	if ( $lesson_post && function_exists( 'jlife_studies_export_document' ) ) {
		$maybe = jlife_studies_export_document( $lesson_post->ID );
		$doc   = is_wp_error( $maybe ) ? null : $maybe;
	}

	$responses = get_option( 'jlife_s4_responses', array() );
	$response_key = jlife_bridge_magic_response_key( $scope );
	$existing     = isset( $responses[ $response_key ] )
		? $responses[ $response_key ]['response']
		: '';

	nocache_headers();
	echo '<!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">';
	echo '<title>' . esc_html__( 'Lesson', 'jlife-bridge' ) . '</title></head><body>';

	if ( $notice ) {
		echo '<p><strong>' . esc_html( $notice ) . '</strong></p>';
	}

	if ( $doc ) {
		echo '<h1>' . esc_html( (string) $doc['title'] ) . '</h1>';
		echo '<h2>' . esc_html__( 'Scripture', 'jlife-bridge' ) . '</h2><ul>';
		foreach ( (array) $doc['scripture_reference'] as $ref ) {
			echo '<li>' . esc_html( (string) $ref['display'] ) . '</li>';
		}
		echo '</ul>';
		echo '<h2>' . esc_html__( 'Teaching', 'jlife-bridge' ) . '</h2>';
		echo wp_kses_post( wpautop( esc_html( (string) $doc['teaching'] ) ) );
		echo '<h2>' . esc_html__( 'Reflection Questions', 'jlife-bridge' ) . '</h2><ol>';
		foreach ( (array) $doc['reflection_questions'] as $q ) {
			echo '<li>' . esc_html( (string) $q['text'] ) . '</li>';
		}
		echo '</ol>';
	} else {
		echo '<p>' . esc_html__( 'Lesson is not available yet.', 'jlife-bridge' ) . '</p>';
	}

	echo '<h2>' . esc_html__( 'Your response (shared with your huddle leader)', 'jlife-bridge' ) . '</h2>';
	echo '<form method="post" action="">';
	echo '<input type="hidden" name="jlife_token" value="' . esc_attr( $token ) . '">';
	echo '<textarea name="jlife_response" rows="4" style="width:100%">' . esc_textarea( (string) $existing ) . '</textarea>';
	echo '<p><button type="submit">' . esc_html__( 'Send to my leader', 'jlife-bridge' ) . '</button></p>';
	echo '</form>';
	echo '<p><em>' . esc_html__( 'Anyone with this link can read and change this response — do not forward it. Private notes are not available through links.', 'jlife-bridge' ) . '</em></p>';
	echo '</body></html>';
}
