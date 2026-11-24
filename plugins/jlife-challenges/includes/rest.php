<?php
/**
 * REST endpoints for SOAP draft autosave (M6, #61), the daily reading email
 * checkbox (#135), the My Bible choice (#140) and the Language setting (#159).
 * Signed-in users only; nonce-checked by WordPress's REST
 * cookie authentication (without the nonce the request is treated as signed
 * out). Submitting stays a plain form POST so it works without JavaScript. A
 * draft is one text (#98); it is never shared and never scored.
 *
 * @package jlife-challenges
 */

defined( 'ABSPATH' ) || exit;

add_action( 'rest_api_init', 'jlife_challenges_register_rest' );

/**
 * Register routes.
 */
function jlife_challenges_register_rest() {
	register_rest_route(
		'jlife/v1',
		'/soap/draft',
		array(
			'methods'             => 'POST',
			'permission_callback' => static fn() => is_user_logged_in(),
			'args'                => array(
				'campaign_id' => array(
					'type'     => 'integer',
					'required' => true,
				),
				'day_key'     => array(
					'type'     => 'string',
					'required' => true,
				),
				'text'        => array(
					'type'     => 'string',
					'required' => false,
				),
				'fields'      => array(
					'type'     => 'object',
					'required' => false,
				),
			),
			'callback'            => 'jlife_challenges_rest_save_draft',
		)
	);
	// The photo of a handwritten reflection, saved with the draft as soon as it is chosen (#244). A multipart POST; signed in and nonce-checked like the draft route.
	foreach ( array(
		'/soap/photo'        => 'jlife_challenges_rest_save_photo',
		'/soap/photo/remove' => 'jlife_challenges_rest_remove_photo',
	) as $route => $callback ) {
		register_rest_route(
			'jlife/v1',
			$route,
			array(
				'methods'             => 'POST',
				'permission_callback' => static fn() => is_user_logged_in(),
				'args'                => array(
					'campaign_id' => array(
						'type'     => 'integer',
						'required' => true,
					),
					'day_key'     => array(
						'type'     => 'string',
						'required' => true,
					),
				),
				'callback'            => $callback,
			)
		);
	}
	register_rest_route(
		'jlife/v1',
		'/reminder',
		array(
			'methods'             => 'POST',
			'permission_callback' => static fn() => is_user_logged_in(),
			'args'                => array(
				'on' => array(
					'type'     => 'boolean',
					'required' => false,
				),
			),
			'callback'            => 'jlife_challenges_rest_save_reminder',
		)
	);
	register_rest_route(
		'jlife/v1',
		'/language',
		array(
			'methods'             => 'POST',
			'permission_callback' => static fn() => is_user_logged_in(),
			'args'                => array(
				'locale' => array(
					'type'     => 'string',
					'required' => true,
				),
			),
			'callback'            => 'jlife_challenges_rest_save_language',
		)
	);
	register_rest_route(
		'jlife/v1',
		'/bible',
		array(
			'methods'             => 'POST',
			'permission_callback' => static fn() => is_user_logged_in(),
			'args'                => array(
				'bible_version'   => array(
					'type'     => array( 'string', 'null' ),
					'required' => false,
				),
				'bible_version_2' => array(
					'type'     => array( 'string', 'null' ),
					'required' => false,
				),
			),
			'callback'            => 'jlife_challenges_rest_save_bible',
		)
	);
}

/**
 * The HTTP status for a refused photo request.
 *
 * @param WP_Error $error Error from the photo functions.
 * @return int
 */
function jlife_challenges_photo_status( $error ) {
	$code = $error->get_error_code();
	if ( 'jlife_denied' === $code ) {
		return 403;
	}
	return 'jlife_photo_big' === $code ? 413 : ( 'jlife_photo_failed' === $code || 'jlife_photo_unsupported' === $code ? 500 : 400 );
}

/**
 * Add or replace the draft photo. Never scores.
 *
 * @param WP_REST_Request $request Request (multipart, file field "photo").
 * @return WP_REST_Response|WP_Error
 */
function jlife_challenges_rest_save_photo( WP_REST_Request $request ) {
	$files = $request->get_file_params();
	$r     = jlife_challenges_photo_attach(
		get_current_user_id(),
		(int) $request['campaign_id'],
		(string) $request['day_key'],
		isset( $files['photo'] ) && is_array( $files['photo'] ) ? $files['photo'] : array()
	);
	if ( is_wp_error( $r ) ) {
		$r->add_data( array( 'status' => jlife_challenges_photo_status( $r ) ) );
		return $r;
	}
	return new WP_REST_Response(
		array(
			'saved'    => true,
			'replaced' => $r['replaced'],
			'url'      => jlife_challenges_photo_url( $r['photo_id'] ),
			'thumb'    => jlife_challenges_photo_url( $r['photo_id'], true ),
		),
		200
	);
}

/**
 * Remove the draft (or submitted entry's) photo.
 *
 * @param WP_REST_Request $request Request.
 * @return WP_REST_Response|WP_Error
 */
function jlife_challenges_rest_remove_photo( WP_REST_Request $request ) {
	$r = jlife_challenges_photo_remove( get_current_user_id(), (int) $request['campaign_id'], (string) $request['day_key'] );
	if ( is_wp_error( $r ) ) {
		$r->add_data( array( 'status' => jlife_challenges_photo_status( $r ) ) );
		return $r;
	}
	return new WP_REST_Response( array( 'removed' => true ), 200 );
}

/**
 * Save a draft.
 *
 * @param WP_REST_Request $request Request.
 * @return WP_REST_Response|WP_Error
 */
function jlife_challenges_rest_save_draft( WP_REST_Request $request ) {
	$text = $request['text'];
	if ( ! is_string( $text ) ) {
		// A page open from before #98 still sends the four parts: join them.
		$parts = (array) $request['fields'];
		if ( ! $parts ) {
			return new WP_Error( 'jlife_text_missing', 'Missing text.', array( 'status' => 400 ) );
		}
		$sections = array();
		foreach ( JLIFE_CHALLENGES_LEGACY_COLUMNS as $column => $letter ) {
			$sections[ $letter ] = is_scalar( $parts[ $column ] ?? null ) ? (string) $parts[ $column ] : '';
		}
		$text = jlife_challenges_join_sections( $sections, jlife_challenges_soap_headings_en() );
	}
	$id = jlife_challenges_save_draft(
		get_current_user_id(),
		(int) $request['campaign_id'],
		(string) $request['day_key'],
		$text
	);
	if ( is_wp_error( $id ) ) {
		$id->add_data( array( 'status' => 'jlife_denied' === $id->get_error_code() ? 403 : 400 ) );
		return $id;
	}
	return new WP_REST_Response(
		array(
			'saved'    => true,
			'entry_id' => $id,
		),
		200
	);
}
