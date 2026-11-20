<?php
/**
 * J-Life → Groups (#64 part 1): the organizer's plain WordPress screen for the
 * pilot's small groups. List groups with member counts, create and rename one,
 * make, replace or revoke its invite code (with an expiry), and add, move or
 * remove a member. Design and access rules: docs/challenges-engine-design.md §4g.
 *
 * - `manage_options` only (organizers and site admins; there is no other organizer
 *   role). Anyone else gets no menu entry and a 403.
 * - Every write is a POST with a nonce bound to its action and group, checked
 *   again with the capability inside jlife_challenges_groups_admin_apply(), which
 *   then calls the audited functions in group-admin.php and groups.php.
 * - A new invite code is shown once, in the response to the POST that made it, and
 *   is never stored in clear. Other writes redirect back with a result code.
 * - Display names only. No SOAP text, shares, email addresses, hashes or tokens.
 *
 * @package jlife-challenges
 */

defined( 'ABSPATH' ) || exit;

/**
 * Page slug, and the operations the page's forms can post.
 */
const JLIFE_CHALLENGES_GROUPS_PAGE = 'jlife-groups';
const JLIFE_CHALLENGES_GROUPS_OPS  = array( 'create', 'rename', 'regenerate', 'revoke', 'add', 'move', 'remove' );

add_action( 'admin_menu', 'jlife_challenges_groups_admin_menu', 20 );

/**
 * Add J-Life → Groups under the J-Life menu that the Reminders page creates.
 */
function jlife_challenges_groups_admin_menu() {
	$hook = add_submenu_page( JLIFE_CHALLENGES_REMINDERS_PAGE, __( 'Small groups', 'jlife-challenges' ), __( 'Groups', 'jlife-challenges' ), 'manage_options', JLIFE_CHALLENGES_GROUPS_PAGE, 'jlife_challenges_groups_admin_page' );
	if ( $hook ) {
		add_action( 'load-' . $hook, 'jlife_challenges_groups_admin_load' );
	}
}

/**
 * The page's address, optionally on one campaign and group.
 *
 * @param int    $campaign_id Campaign (0 = default).
 * @param int    $group_id    Group (0 = none).
 * @param string $result      Result code to show.
 * @return string
 */
function jlife_challenges_groups_admin_url( $campaign_id = 0, $group_id = 0, $result = '' ) {
	$args = array( 'page' => JLIFE_CHALLENGES_GROUPS_PAGE );
	if ( $campaign_id > 0 ) {
		$args['campaign'] = (int) $campaign_id;
	}
	if ( $group_id > 0 ) {
		$args['group'] = (int) $group_id;
	}
	if ( '' !== $result ) {
		$args['jl_result'] = $result;
	}
	return add_query_arg( $args, get_admin_url( get_current_blog_id(), 'admin.php' ) );
}

/**
 * The nonce action for one operation on one group. Binding both means a nonce
 * from another form, or from another group's form, never works.
 *
 * @param string $op       Operation.
 * @param int    $group_id Group the form belongs to (0 for create).
 * @return string
 */
function jlife_challenges_groups_admin_nonce_action( $op, $group_id ) {
	return 'jlife_groups_' . $op . '_' . (int) $group_id;
}

/**
 * The invite link for a code: the code is its only parameter, with no sign-in,
 * session or user token.
 *
 * @param string $code Invite code.
 * @return string
 */
function jlife_challenges_groups_admin_invite_link( $code ) {
	return add_query_arg( 'code', $code, home_url( '/join/' ) );
}

/**
 * The expiry in days from a form value: one of the allowed choices, or false.
 *
 * @param mixed $value Posted value.
 * @return int|false
 */
function jlife_challenges_groups_admin_days( $value ) {
	$days = (int) $value;
	return in_array( $days, JLIFE_CHALLENGES_INVITE_DAYS, true ) ? $days : false;
}

/**
 * Build a result.
 *
 * @param string     $code     Result code.
 * @param int        $group_id Group the page shows next.
 * @param array|null $reveal   A new code to show once: { name, code, expires }.
 * @return array { code, group_id, reveal }
 */
function jlife_challenges_groups_admin_result( $code, $group_id = 0, $reveal = null ) {
	return array(
		'code'     => $code,
		'group_id' => (int) $group_id,
		'reveal'   => $reveal,
	);
}

/**
 * The result code for an error from the group functions.
 *
 * @param WP_Error $error Error.
 * @return string
 */
function jlife_challenges_groups_admin_error_code( WP_Error $error ) {
	$codes = array(
		'jlife_group_name_invalid' => 'name_invalid',
		'jlife_group_limit'        => 'limit',
		'jlife_already_enrolled'   => 'already',
		'jlife_move_same'          => 'same_group',
		'jlife_denied'             => 'not_allowed',
	);
	return $codes[ $error->get_error_code() ] ?? 'failed';
}

/**
 * Apply one write from the page. Pure: the fields are passed in and nothing is
 * printed or redirected, so tests can drive it. The checks run in this order:
 * capability, POST, known operation, nonce for that operation and group.
 *
 * Fields: op, nonce, group_id (the group the form belongs to), user_id,
 * to_group_id, name, days, campaign_id, token (a one-use form token).
 *
 * @param string $method 'GET' or 'POST' (only POST changes anything).
 * @param array  $fields Request fields.
 * @return array { code, group_id, reveal } where code is forbidden|bad_method|bad_op|bad_nonce|duplicate|bad_days|not_allowed|name_invalid|limit|already|same_group|failed or the success code created|renamed|invite_reset|invite_revoked|member_added|member_removed|member_moved.
 */
function jlife_challenges_groups_admin_apply( $method, array $fields ) {
	if ( ! current_user_can( 'manage_options' ) ) {
		return jlife_challenges_groups_admin_result( 'forbidden' );
	}
	if ( 'POST' !== strtoupper( (string) $method ) ) {
		return jlife_challenges_groups_admin_result( 'bad_method' );
	}
	$op = (string) ( $fields['op'] ?? '' );
	if ( ! in_array( $op, JLIFE_CHALLENGES_GROUPS_OPS, true ) ) {
		return jlife_challenges_groups_admin_result( 'bad_op' );
	}
	$group_id = (int) ( $fields['group_id'] ?? 0 );
	if ( ! wp_verify_nonce( (string) ( $fields['nonce'] ?? '' ), jlife_challenges_groups_admin_nonce_action( $op, $group_id ) ) ) {
		return jlife_challenges_groups_admin_result( 'bad_nonce' );
	}
	// A reload of a form that made a group or a code must not make a second one.
	$token = sanitize_key( (string) ( $fields['token'] ?? '' ) );
	if ( '' !== $token && in_array( $op, array( 'create', 'regenerate' ), true ) ) {
		if ( get_transient( 'jlife_chal_gadm_' . $token ) ) {
			return jlife_challenges_groups_admin_result( 'duplicate', $group_id );
		}
		set_transient( 'jlife_chal_gadm_' . $token, 1, DAY_IN_SECONDS );
	}

	$actor   = get_current_user_id();
	$user_id = (int) ( $fields['user_id'] ?? 0 );
	$name    = (string) ( $fields['name'] ?? '' );
	$days    = array_key_exists( 'days', $fields ) ? jlife_challenges_groups_admin_days( $fields['days'] ) : 60;
	if ( in_array( $op, array( 'create', 'regenerate' ), true ) && false === $days ) {
		return jlife_challenges_groups_admin_result( 'bad_days', $group_id );
	}

	if ( 'create' === $op ) {
		$campaign_id = (int) ( $fields['campaign_id'] ?? 0 );
		if ( ! jlife_challenges_get_campaign( $campaign_id ) ) {
			return jlife_challenges_groups_admin_result( 'not_allowed' );
		}
		$made = jlife_challenges_create_group( $actor, $campaign_id, $name, (int) $days );
		if ( is_wp_error( $made ) ) {
			return jlife_challenges_groups_admin_result( jlife_challenges_groups_admin_error_code( $made ), 0 );
		}
		$group = jlife_challenges_get_group( (int) $made['id'] );
		return jlife_challenges_groups_admin_result(
			'created',
			(int) $made['id'],
			array(
				'name'    => (string) ( $group['name'] ?? '' ),
				'code'    => (string) $made['invite_code'],
				'expires' => (string) ( $group['invite_expires'] ?? '' ),
			)
		);
	}

	$done = array(
		'rename'     => 'renamed',
		'revoke'     => 'invite_revoked',
		'add'        => 'member_added',
		'remove'     => 'member_removed',
		'move'       => 'member_moved',
		'regenerate' => 'invite_reset',
	);
	if ( 'rename' === $op ) {
		$r = jlife_challenges_rename_group( $actor, $group_id, $name );
	} elseif ( 'revoke' === $op ) {
		$r = jlife_challenges_revoke_invite( $actor, $group_id );
	} elseif ( 'add' === $op ) {
		$r = jlife_challenges_admin_add_member( $actor, $user_id, $group_id );
	} elseif ( 'remove' === $op ) {
		$r = jlife_challenges_remove_from_group( $actor, $user_id, $group_id );
	} elseif ( 'move' === $op ) {
		$r = jlife_challenges_move_member( $actor, $user_id, (int) ( $fields['to_group_id'] ?? 0 ), $group_id );
	} else {
		$r = jlife_challenges_reset_invite( $actor, $group_id, (int) $days );
	}
	if ( is_wp_error( $r ) ) {
		return jlife_challenges_groups_admin_result( jlife_challenges_groups_admin_error_code( $r ), $group_id );
	}
	if ( 'regenerate' === $op ) {
		$group = jlife_challenges_get_group( $group_id );
		return jlife_challenges_groups_admin_result(
			$done[ $op ],
			$group_id,
			array(
				'name'    => (string) ( $group['name'] ?? '' ),
				'code'    => (string) $r,
				'expires' => (string) ( $group['invite_expires'] ?? '' ),
			)
		);
	}
	return jlife_challenges_groups_admin_result( $done[ $op ], $group_id );
}

/**
 * A result that must be shown by the page that handled the POST (a new code),
 * kept for the rest of this request only. Never stored.
 *
 * @param array|false|null $set A result to keep, false to forget it, or null to read.
 * @return array|null
 */
function jlife_challenges_groups_admin_pending( $set = null ) {
	static $pending = null;
	if ( false === $set ) {
		$pending = null;
	} elseif ( null !== $set ) {
		$pending = $set;
	}
	return $pending;
}

/**
 * Handle a POST to the page before anything is printed (the `load-` hook).
 */
function jlife_challenges_groups_admin_load() {
	$method = isset( $_SERVER['REQUEST_METHOD'] ) ? strtoupper( sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) ) : 'GET';
	if ( 'POST' !== $method ) {
		return;
	}
	// phpcs:disable WordPress.Security.NonceVerification -- verified inside jlife_challenges_groups_admin_apply().
	$result = jlife_challenges_groups_admin_apply(
		$method,
		array(
			'op'          => isset( $_POST['jl_op'] ) ? sanitize_key( wp_unslash( $_POST['jl_op'] ) ) : '',
			'nonce'       => isset( $_POST['_wpnonce'] ) ? sanitize_text_field( wp_unslash( $_POST['_wpnonce'] ) ) : '',
			'group_id'    => isset( $_POST['jl_group_id'] ) ? absint( $_POST['jl_group_id'] ) : 0,
			'user_id'     => isset( $_POST['jl_user_id'] ) ? absint( $_POST['jl_user_id'] ) : 0,
			'to_group_id' => isset( $_POST['jl_to_group_id'] ) ? absint( $_POST['jl_to_group_id'] ) : 0,
			'name'        => isset( $_POST['jl_name'] ) ? sanitize_text_field( wp_unslash( $_POST['jl_name'] ) ) : '',
			'days'        => isset( $_POST['jl_days'] ) ? absint( $_POST['jl_days'] ) : 60,
			'campaign_id' => isset( $_POST['jl_campaign_id'] ) ? absint( $_POST['jl_campaign_id'] ) : 0,
			'token'       => isset( $_POST['jl_token'] ) ? sanitize_key( wp_unslash( $_POST['jl_token'] ) ) : '',
		)
	);
	// phpcs:enable
	if ( in_array( $result['code'], array( 'forbidden', 'bad_method', 'bad_op', 'bad_nonce' ), true ) ) {
		wp_die( esc_html__( 'You are not allowed to change groups.', 'jlife-challenges' ), '', array( 'response' => 403 ) );
	}
	if ( $result['reveal'] ) {
		jlife_challenges_groups_admin_pending( $result ); // The page shows the code once; no redirect, nothing stored.
		return;
	}
	$group       = $result['group_id'] > 0 ? jlife_challenges_get_group( $result['group_id'] ) : null;
	$campaign_id = $group ? (int) $group['campaign_id'] : ( isset( $_POST['jl_campaign_id'] ) ? absint( $_POST['jl_campaign_id'] ) : 0 ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- a display choice after the checked write above.
	wp_safe_redirect( jlife_challenges_groups_admin_url( $campaign_id, $group ? (int) $group['id'] : 0, $result['code'] ) );
	exit;
}

/**
 * Everything the page shows for an organizer. Display names, counts and states only.
 *
 * @param int $actor       Organizer.
 * @param int $campaign_id Campaign to show (0 or unknown = the open one).
 * @param int $group_id    Group to open (0 = none; must belong to the campaign in view).
 * @return array|WP_Error { campaigns[], campaign|null, groups[], group|null }
 */
function jlife_challenges_groups_admin_view( $actor, $campaign_id = 0, $group_id = 0 ) {
	if ( ! jlife_challenges_is_organizer( $actor ) ) {
		return jlife_challenges_denied();
	}
	$campaigns = jlife_challenges_admin_campaigns();
	$by_id     = array_column( $campaigns, null, 'id' );
	$chosen    = null;
	$asked     = jlife_challenges_get_group( (int) $group_id );
	if ( (int) $campaign_id < 1 && $asked ) {
		$campaign_id = (int) $asked['campaign_id'];
	}
	foreach ( $campaigns as $c ) {
		if ( (int) $c['id'] === (int) $campaign_id ) {
			$chosen = $c;
		}
	}
	if ( ! $chosen ) {
		$open   = jlife_challenges_open_campaign();
		$chosen = $open && isset( $by_id[ $open['id'] ] ) ? $by_id[ $open['id'] ] : ( $campaigns ? $campaigns[0] : null );
	}
	$view = array(
		'campaigns' => $campaigns,
		'campaign'  => $chosen,
		'groups'    => array(),
		'group'     => null,
	);
	if ( ! $chosen ) {
		return $view;
	}
	$groups = jlife_challenges_list_groups( $actor, (int) $chosen['id'] );
	foreach ( is_wp_error( $groups ) ? array() : $groups as $g ) {
		$row              = jlife_challenges_get_group( (int) $g['id'] );
		$g['invite']      = jlife_challenges_invite_state( (array) $row );
		$g['starter']     = (int) $g['started_by'] > 0 ? jlife_challenges_admin_person_name( (int) $g['started_by'] ) : '';
		$view['groups'][] = $g;
	}
	if ( $asked && (int) $asked['campaign_id'] === (int) $chosen['id'] ) {
		$targets = array();
		foreach ( $view['groups'] as $g ) {
			if ( (int) $g['id'] !== (int) $asked['id'] && 'active' === $g['status'] ) {
				$targets[] = array(
					'id'   => (int) $g['id'],
					'name' => (string) $g['name'],
				);
			}
		}
		$view['group'] = array(
			'row'        => $asked,
			'invite'     => jlife_challenges_invite_state( $asked ),
			'members'    => jlife_challenges_group_members( $actor, (int) $asked['id'] ),
			'candidates' => jlife_challenges_group_add_candidates( $actor, (int) $asked['id'] ),
			'targets'    => $targets,
		);
	}
	return $view;
}

/**
 * The sentence for a result code.
 *
 * @param string $code Result code.
 * @return array|null { type: success|error, text }
 */
function jlife_challenges_groups_admin_notice_text( $code ) {
	$ok     = array(
		'created'        => __( 'Group created. Copy its invite code below now: it is shown only once.', 'jlife-challenges' ),
		'renamed'        => __( 'Group renamed.', 'jlife-challenges' ),
		'invite_reset'   => __( 'New invite code made. The old one no longer works. Copy it now: it is shown only once.', 'jlife-challenges' ),
		'invite_revoked' => __( 'Invite code revoked. Nobody can join this group with a code until you make a new one.', 'jlife-challenges' ),
		'member_added'   => __( 'Member added.', 'jlife-challenges' ),
		'member_removed' => __( 'Member removed from this group. Their entries, points and other groups are unchanged.', 'jlife-challenges' ),
		'member_moved'   => __( 'Member moved. What they shared before stays with their old group.', 'jlife-challenges' ),
	);
	$errors = array(
		'name_invalid' => jlife_challenges_clean_group_name( '' )->get_error_message(),
		'bad_days'     => __( 'Choose one of the expiry times in the list.', 'jlife-challenges' ),
		'not_allowed'  => __( 'Not found or not allowed.', 'jlife-challenges' ),
		/* translators: %d: the most groups one person can be in. */
		'limit'        => sprintf( __( 'That person is already in the most groups they can join (%d).', 'jlife-challenges' ), JLIFE_CHALLENGES_MAX_GROUPS ),
		'already'      => __( 'That person is already in that group.', 'jlife-challenges' ),
		'same_group'   => __( 'Choose a different group to move them to.', 'jlife-challenges' ),
		'duplicate'    => __( 'That request was already handled. Nothing was changed.', 'jlife-challenges' ),
		'failed'       => __( 'Something went wrong. Nothing was changed.', 'jlife-challenges' ),
	);
	if ( isset( $ok[ $code ] ) ) {
		return array(
			'type' => 'success',
			'text' => $ok[ $code ],
		);
	}
	if ( isset( $errors[ $code ] ) ) {
		return array(
			'type' => 'error',
			'text' => $errors[ $code ],
		);
	}
	return null;
}

/**
 * A UTC database time as a site-formatted date.
 *
 * @param string $utc 'Y-m-d H:i:s' in UTC.
 * @return string
 */
function jlife_challenges_groups_admin_date( $utc ) {
	$time = strtotime( $utc . ' UTC' );
	return $time ? wp_date( (string) get_option( 'date_format' ), $time ) : '';
}

/**
 * A POST form that carries one operation: the hidden operation and group, a nonce
 * bound to both, and a one-use token for the operations that make something new.
 *
 * @param string $action_url Where the form posts (the page itself).
 * @param string $op         Operation.
 * @param int    $group_id   Group the form belongs to.
 * @param string $inner      Escaped HTML inside the form.
 * @return string Escaped HTML.
 */
function jlife_challenges_groups_admin_form( $action_url, $op, $group_id, $inner ) {
	$token = in_array( $op, array( 'create', 'regenerate' ), true ) ? '<input type="hidden" name="jl_token" value="' . esc_attr( wp_generate_password( 16, false ) ) . '">' : '';
	return '<form method="post" action="' . esc_url( $action_url ) . '">'
		. '<input type="hidden" name="jl_op" value="' . esc_attr( $op ) . '">'
		. '<input type="hidden" name="jl_group_id" value="' . (int) $group_id . '">'
		. $token
		. wp_nonce_field( jlife_challenges_groups_admin_nonce_action( $op, $group_id ), '_wpnonce', false, false )
		. $inner . '</form>';
}

/**
 * The expiry select for an invite form.
 *
 * @param string $id Element ID.
 * @return string Escaped HTML.
 */
function jlife_challenges_groups_admin_days_select( $id ) {
	$out = '<label for="' . esc_attr( $id ) . '">' . esc_html__( 'Expires after', 'jlife-challenges' ) . '</label> <select id="' . esc_attr( $id ) . '" name="jl_days">';
	foreach ( JLIFE_CHALLENGES_INVITE_DAYS as $days ) {
		/* translators: %d: number of days. */
		$out .= '<option value="' . (int) $days . '"' . selected( 60, $days, false ) . '>' . esc_html( sprintf( _n( '%d day', '%d days', $days, 'jlife-challenges' ), $days ) ) . '</option>';
	}
	return $out . '</select>';
}

/**
 * The text for one invite state.
 *
 * @param array $invite { state, expires } from jlife_challenges_invite_state().
 * @return string
 */
function jlife_challenges_groups_admin_invite_text( array $invite ) {
	if ( 'none' === $invite['state'] ) {
		return __( 'No invite code', 'jlife-challenges' );
	}
	$date = jlife_challenges_groups_admin_date( $invite['expires'] );
	if ( 'expired' === $invite['state'] ) {
		/* translators: %s: a date. */
		return sprintf( __( 'Expired on %s', 'jlife-challenges' ), $date );
	}
	if ( '' === $date ) {
		return __( 'Active', 'jlife-challenges' );
	}
	/* translators: %s: a date. */
	return sprintf( __( 'Active until %s', 'jlife-challenges' ), $date );
}

/**
 * The page body. Escapes everything.
 *
 * @param array      $view   jlife_challenges_groups_admin_view().
 * @param string     $result Result code to show a notice for, or ''.
 * @param array|null $reveal A new code to show once: { name, code, expires }.
 * @return string Escaped HTML.
 */
function jlife_challenges_groups_admin_html( array $view, $result = '', $reveal = null ) {
	$html   = '<div class="wrap"><h1>' . esc_html__( 'Small groups', 'jlife-challenges' ) . '</h1>';
	$notice = jlife_challenges_groups_admin_notice_text( (string) $result );
	if ( $notice ) {
		$html .= '<div class="notice notice-' . ( 'success' === $notice['type'] ? 'success' : 'error' ) . '"><p>' . esc_html( $notice['text'] ) . '</p></div>';
	}
	if ( is_array( $reveal ) && '' !== (string) ( $reveal['code'] ?? '' ) ) {
		$html .= '<div class="notice notice-info"><p>'
			/* translators: %s: group name. */
			. sprintf( esc_html__( 'Invite code for %s:', 'jlife-challenges' ), '<strong>' . esc_html( (string) $reveal['name'] ) . '</strong>' )
			. ' <code>' . esc_html( (string) $reveal['code'] ) . '</code></p>'
			. '<p>' . esc_html__( 'Invite link:', 'jlife-challenges' ) . ' <code>' . esc_html( jlife_challenges_groups_admin_invite_link( (string) $reveal['code'] ) ) . '</code></p>'
			. '<p>' . esc_html( sprintf( /* translators: %s: a date. */ __( 'It works until %s. This is the only time the code is shown: we do not keep it. Send it only to the people you want in this group.', 'jlife-challenges' ), jlife_challenges_groups_admin_date( (string) ( $reveal['expires'] ?? '' ) ) ) ) . '</p></div>';
	}

	$campaign = $view['campaign'];
	if ( ! $campaign ) {
		return $html . '<p>' . esc_html__( 'There is no campaign yet.', 'jlife-challenges' ) . '</p></div>';
	}
	$cid  = (int) $campaign['id'];
	$base = jlife_challenges_groups_admin_url( $cid, $view['group'] ? (int) $view['group']['row']['id'] : 0 );

	if ( count( $view['campaigns'] ) > 1 ) {
		$html .= '<form method="get" action="' . esc_url( get_admin_url( get_current_blog_id(), 'admin.php' ) ) . '"><input type="hidden" name="page" value="' . esc_attr( JLIFE_CHALLENGES_GROUPS_PAGE ) . '">'
			. '<label for="jl-campaign">' . esc_html__( 'Campaign', 'jlife-challenges' ) . '</label> <select id="jl-campaign" name="campaign">';
		foreach ( $view['campaigns'] as $c ) {
			$html .= '<option value="' . (int) $c['id'] . '"' . selected( $cid, (int) $c['id'], false ) . '>' . esc_html( (string) $c['title'] ) . '</option>';
		}
		$html .= '</select> <button type="submit" class="button">' . esc_html__( 'Show', 'jlife-challenges' ) . '</button></form>';
	}

	$html .= '<h2>' . esc_html( (string) $campaign['title'] ) . '</h2>';
	if ( ! $view['groups'] ) {
		$html .= '<p>' . esc_html__( 'No groups yet. Make the first one below.', 'jlife-challenges' ) . '</p>';
	} else {
		$html .= '<table class="wp-list-table widefat striped"><thead><tr>'
			. '<th scope="col">' . esc_html__( 'Group', 'jlife-challenges' ) . '</th>'
			. '<th scope="col">' . esc_html__( 'Status', 'jlife-challenges' ) . '</th>'
			. '<th scope="col">' . esc_html__( 'Members', 'jlife-challenges' ) . '</th>'
			. '<th scope="col">' . esc_html__( 'Invite code', 'jlife-challenges' ) . '</th>'
			. '<th scope="col">' . esc_html__( 'Started by', 'jlife-challenges' ) . '</th></tr></thead><tbody>';
		foreach ( $view['groups'] as $g ) {
			$open  = jlife_challenges_groups_admin_url( $cid, (int) $g['id'] );
			$html .= '<tr><td><a href="' . esc_url( $open ) . '"><strong>' . esc_html( (string) $g['name'] ) . '</strong></a></td>'
				. '<td>' . esc_html( 'active' === $g['status'] ? __( 'Active', 'jlife-challenges' ) : __( 'Disabled', 'jlife-challenges' ) ) . '</td>'
				. '<td>' . (int) $g['members'] . '</td>'
				. '<td>' . esc_html( jlife_challenges_groups_admin_invite_text( $g['invite'] ) ) . '</td>'
				. '<td>' . esc_html( '' !== $g['starter'] ? $g['starter'] : __( 'An organizer', 'jlife-challenges' ) ) . '</td></tr>';
		}
		$html .= '</tbody></table>';
	}

	$html .= '<h2>' . esc_html__( 'Make a group', 'jlife-challenges' ) . '</h2>'
		. jlife_challenges_groups_admin_form(
			$base,
			'create',
			0,
			'<input type="hidden" name="jl_campaign_id" value="' . $cid . '">'
			. '<p><label for="jl-new-name">' . esc_html__( 'Group name', 'jlife-challenges' ) . '</label> '
			. '<input id="jl-new-name" type="text" name="jl_name" maxlength="' . (int) JLIFE_CHALLENGES_GROUP_NAME_MAX . '" class="regular-text" required> '
			. jlife_challenges_groups_admin_days_select( 'jl-new-days' )
			. ' <button type="submit" class="button button-primary">' . esc_html__( 'Make group and invite code', 'jlife-challenges' ) . '</button></p>'
		);

	if ( $view['group'] ) {
		$html .= jlife_challenges_groups_admin_group_html( $view['group'], $base );
	}
	return $html . '</div>';
}

/**
 * One group's details: rename, invite code and members.
 *
 * @param array  $detail The view's `group` block.
 * @param string $base   The page address on this group.
 * @return string Escaped HTML.
 */
function jlife_challenges_groups_admin_group_html( array $detail, $base ) {
	$group = $detail['row'];
	$gid   = (int) $group['id'];
	$html  = '<hr><h2>' . esc_html( (string) $group['name'] ) . '</h2>';
	if ( 'active' !== $group['status'] ) {
		$html .= '<p><em>' . esc_html__( 'This group is disabled: its invite code does not work and its members cannot read its feed.', 'jlife-challenges' ) . '</em></p>';
	}

	$html .= '<h3>' . esc_html__( 'Name', 'jlife-challenges' ) . '</h3>'
		. jlife_challenges_groups_admin_form(
			$base,
			'rename',
			$gid,
			'<p><label for="jl-rename">' . esc_html__( 'Group name', 'jlife-challenges' ) . '</label> '
			. '<input id="jl-rename" type="text" name="jl_name" value="' . esc_attr( (string) $group['name'] ) . '" maxlength="' . (int) JLIFE_CHALLENGES_GROUP_NAME_MAX . '" class="regular-text" required> '
			. '<button type="submit" class="button">' . esc_html__( 'Rename', 'jlife-challenges' ) . '</button></p>'
		);

	$html .= '<h3>' . esc_html__( 'Invite code', 'jlife-challenges' ) . '</h3>'
		. '<p>' . esc_html( jlife_challenges_groups_admin_invite_text( $detail['invite'] ) ) . '. '
		. esc_html__( 'For safety, a code is shown only when it is made. Making a new one stops the old one at once. Members already in the group are not affected.', 'jlife-challenges' ) . '</p>'
		. jlife_challenges_groups_admin_form(
			$base,
			'regenerate',
			$gid,
			'<p>' . jlife_challenges_groups_admin_days_select( 'jl-regen-days' ) . ' <button type="submit" class="button">' . esc_html__( 'Make a new invite code', 'jlife-challenges' ) . '</button></p>'
		);
	if ( 'none' !== $detail['invite']['state'] ) {
		$html .= jlife_challenges_groups_admin_form( $base, 'revoke', $gid, '<p><button type="submit" class="button">' . esc_html__( 'Revoke the invite code', 'jlife-challenges' ) . '</button></p>' );
	}

	$html   .= '<h3>' . esc_html__( 'Members', 'jlife-challenges' ) . '</h3>';
	$members = is_wp_error( $detail['members'] ) ? array() : $detail['members'];
	if ( ! $members ) {
		$html .= '<p>' . esc_html__( 'Nobody is in this group yet.', 'jlife-challenges' ) . '</p>';
	} else {
		$html .= '<table class="wp-list-table widefat striped"><thead><tr>'
			. '<th scope="col">' . esc_html__( 'Name', 'jlife-challenges' ) . '</th>'
			. '<th scope="col">' . esc_html__( 'Role', 'jlife-challenges' ) . '</th>'
			. '<th scope="col">' . esc_html__( 'Joined', 'jlife-challenges' ) . '</th>'
			. '<th scope="col">' . esc_html__( 'Move to another group', 'jlife-challenges' ) . '</th>'
			. '<th scope="col">' . esc_html__( 'Remove from this group', 'jlife-challenges' ) . '</th></tr></thead><tbody>';
		foreach ( $members as $m ) {
			$uid  = (int) $m['user_id'];
			$move = '';
			if ( $detail['targets'] ) {
				$move = '<input type="hidden" name="jl_user_id" value="' . $uid . '"><select name="jl_to_group_id" aria-label="' . esc_attr__( 'Move to', 'jlife-challenges' ) . '">';
				foreach ( $detail['targets'] as $t ) {
					$move .= '<option value="' . (int) $t['id'] . '">' . esc_html( (string) $t['name'] ) . '</option>';
				}
				$move = jlife_challenges_groups_admin_form( $base, 'move', $gid, $move . '</select> <button type="submit" class="button">' . esc_html__( 'Move', 'jlife-challenges' ) . '</button>' );
			}
			$html .= '<tr><td>' . esc_html( (string) $m['name'] ) . '</td>'
				. '<td>' . esc_html( 'starter' === $m['role'] ? __( 'Started this group', 'jlife-challenges' ) : __( 'Member', 'jlife-challenges' ) ) . '</td>'
				. '<td>' . esc_html( jlife_challenges_groups_admin_date( (string) $m['joined_at'] ) ) . '</td>'
				. '<td>' . $move . '</td><td>'
				. jlife_challenges_groups_admin_form( $base, 'remove', $gid, '<input type="hidden" name="jl_user_id" value="' . $uid . '"><button type="submit" class="button">' . esc_html__( 'Remove', 'jlife-challenges' ) . '</button>' )
				. '</td></tr>';
		}
		$html .= '</tbody></table>';
	}
	$html .= '<p class="description">' . esc_html__( 'Moving or removing someone keeps what they shared with this group here, and they can still see and remove their own posts. It never changes their entries, points or eligibility. A person you remove can join again with a valid invite code, so make a new code or revoke it if you do not want that.', 'jlife-challenges' ) . '</p>';

	$html .= '<h3>' . esc_html__( 'Add a member', 'jlife-challenges' ) . '</h3>';
	if ( 'active' !== $group['status'] ) {
		$html .= '<p>' . esc_html__( 'A disabled group cannot take new members.', 'jlife-challenges' ) . '</p>';
	} elseif ( is_wp_error( $detail['candidates'] ) || ! $detail['candidates'] ) {
		$html .= '<p>' . esc_html__( 'Everyone who has joined the Marathon is already in this group.', 'jlife-challenges' ) . '</p>';
	} else {
		$options = '';
		foreach ( $detail['candidates'] as $c ) {
			$options .= '<option value="' . (int) $c['user_id'] . '">' . esc_html( (string) $c['name'] ) . '</option>';
		}
		$html .= '<p>' . esc_html__( 'Only people who have already signed up and joined this Marathon are listed. Someone who has not signed up yet joins with an invite code.', 'jlife-challenges' ) . '</p>'
			. jlife_challenges_groups_admin_form(
				$base,
				'add',
				$gid,
				'<p><label for="jl-add">' . esc_html__( 'Participant', 'jlife-challenges' ) . '</label> <select id="jl-add" name="jl_user_id">' . $options . '</select> '
				. '<button type="submit" class="button button-primary">' . esc_html__( 'Add to this group', 'jlife-challenges' ) . '</button></p>'
			);
	}
	return $html;
}

/**
 * The J-Life → Groups page.
 */
function jlife_challenges_groups_admin_page() {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( esc_html__( 'You are not allowed to see this page.', 'jlife-challenges' ), '', array( 'response' => 403 ) );
	}
	// phpcs:disable WordPress.Security.NonceVerification.Recommended -- display only: these choose what to show and change nothing.
	$campaign_id = isset( $_GET['campaign'] ) ? absint( $_GET['campaign'] ) : 0;
	$group_id    = isset( $_GET['group'] ) ? absint( $_GET['group'] ) : 0;
	$result      = isset( $_GET['jl_result'] ) ? sanitize_key( wp_unslash( $_GET['jl_result'] ) ) : '';
	// phpcs:enable
	$pending = jlife_challenges_groups_admin_pending();
	$reveal  = null;
	if ( is_array( $pending ) ) {
		$result      = (string) $pending['code'];
		$group_id    = (int) $pending['group_id'];
		$reveal      = $pending['reveal'];
		$campaign_id = 0;
	}
	$view = jlife_challenges_groups_admin_view( get_current_user_id(), $campaign_id, $group_id );
	if ( is_wp_error( $view ) ) {
		wp_die( esc_html__( 'You are not allowed to see this page.', 'jlife-challenges' ), '', array( 'response' => 403 ) );
	}
	echo jlife_challenges_groups_admin_html( $view, $result, $reveal ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- every part is escaped.
}
