<?php
/**
 * Audit log. Records who did what to which object. Never stores SOAP text.
 *
 * @package jlife-challenges
 */

defined( 'ABSPATH' ) || exit;

/**
 * Write an audit entry.
 *
 * @param int    $actor_user_id Acting user (0 = system).
 * @param string $action        e.g. 'member_moved'.
 * @param string $object_type   e.g. 'enrollment'.
 * @param int    $object_id     Object ID.
 * @param int    $campaign_id   Campaign ID (0 if none).
 * @param array  $detail        Small, non-sensitive details (IDs, statuses). Never SOAP text.
 */
function jlife_challenges_audit( $actor_user_id, $action, $object_type, $object_id, $campaign_id = 0, $detail = array() ) {
	global $wpdb;
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- custom table; no WP API exists.
	$wpdb->insert(
		jlife_challenges_table( 'audit_log' ),
		array(
			'actor_user_id' => (int) $actor_user_id,
			'action'        => (string) $action,
			'object_type'   => (string) $object_type,
			'object_id'     => (int) $object_id,
			'campaign_id'   => (int) $campaign_id,
			'detail'        => (string) wp_json_encode( $detail ),
			'created'       => current_time( 'mysql', true ),
		),
		array( '%d', '%s', '%s', '%d', '%d', '%s', '%s' )
	);
}
