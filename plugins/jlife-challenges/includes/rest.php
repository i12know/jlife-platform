<?php
/**
 * REST endpoint for SOAP draft autosave (M6, #61). Signed-in authors only;
 * nonce-checked by WordPress's REST cookie authentication. Submitting stays a
 * plain form POST so it works without JavaScript.
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
				'fields'      => array(
					'type'     => 'object',
					'required' => true,
				),
			),
			'callback'            => 'jlife_challenges_rest_save_draft',
		)
	);
}

/**
 * Save a draft.
 *
 * @param WP_REST_Request $request Request.
 * @return WP_REST_Response|WP_Error
 */
function jlife_challenges_rest_save_draft( WP_REST_Request $request ) {
	$id = jlife_challenges_save_draft(
		get_current_user_id(),
		(int) $request['campaign_id'],
		(string) $request['day_key'],
		(array) $request['fields']
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
