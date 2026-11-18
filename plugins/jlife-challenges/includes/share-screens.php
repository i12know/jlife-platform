<?php
/**
 * Sharing screens (M7, #62): /share?day=dNNN and /group (and /group?g=ID).
 *
 * One share flow, two destinations: "Post to a group" (a stored snapshot of the
 * whole entry, #98, per group: a person in several groups posts to each one
 * separately) and "Send to a chat" (the phone's share sheet or copy; nothing
 * stored). Never automatic, never scored. The SOAP form's one box per group is
 * the quick way to the first destination; this page is where a post is
 * previewed, updated or removed. A solo reader has no group to post to and is
 * offered "Have a code? Join a group" instead.
 *
 * @package jlife-challenges
 */

defined( 'ABSPATH' ) || exit;

/**
 * Act on one group's post for the shared entry: "post-<group id>" or
 * "remove-<group id>". Only a group the author is in (one of the view's
 * destinations) can be acted on; anything else is denied like "not found".
 *
 * @param int    $user_id Author.
 * @param array  $view    Share view (see jlife_challenges_share_view()).
 * @param string $action  post-<id> or remove-<id>.
 * @return true|WP_Error
 */
function jlife_challenges_share_group_action( $user_id, $view, $action ) {
	if ( 1 !== preg_match( '/^(post|remove)-(\d+)$/', (string) $action, $m ) ) {
		return jlife_challenges_denied();
	}
	$group_id = (int) $m[2];
	foreach ( (array) ( $view['destinations'] ?? array() ) as $dest ) {
		if ( (int) $dest['group_id'] !== $group_id ) {
			continue;
		}
		if ( 'remove' === $m[1] ) {
			return $dest['share'] ? jlife_challenges_unshare( $user_id, (int) $dest['share']['id'] ) : jlife_challenges_denied();
		}
		$r = jlife_challenges_share( $user_id, (int) $view['entry']['id'], $group_id );
		return is_wp_error( $r ) ? $r : true;
	}
	return jlife_challenges_denied();
}

/**
 * Handle a share-page POST (post or remove, for one group). Redirects on success.
 *
 * @param int   $user_id Author.
 * @param array $view    Share view.
 * @return string Error message.
 */
function jlife_challenges_handle_share_post( $user_id, $view ) {
	$action = isset( $_POST['jl_share_action'] ) ? sanitize_key( wp_unslash( $_POST['jl_share_action'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified by the caller.
	$back   = add_query_arg( 'day', $view['day_key'], home_url( '/share/' ) );
	$r      = jlife_challenges_share_group_action( $user_id, $view, $action );
	if ( true === $r ) {
		$flag = 0 === strpos( $action, 'remove-' ) ? 'removed' : 'posted';
		wp_safe_redirect( add_query_arg( $flag, (int) substr( $action, strpos( $action, '-' ) + 1 ), $back ) );
		exit;
	}
	return $r->get_error_message();
}

/**
 * "Have a code? Join a group": a signed-in person enters another group's code
 * (up to the limit). Posts to /group.
 *
 * @param string $error Error from the last try.
 * @return string
 */
function jlife_challenges_join_prompt( $error = '' ) {
	return '<section class="jl-join"><h2>' . esc_html__( 'Have a code? Join a group', 'jlife-challenges' ) . '</h2>'
		. '<p>' . esc_html__( 'Groups are optional. Entries you wrote before joining stay private unless you choose to share them.', 'jlife-challenges' ) . '</p>'
		. ( '' !== $error ? '<p class="jl-err" role="alert">' . esc_html( $error ) . '</p>' : '' )
		. '<form method="post" action="' . esc_url( home_url( '/group/' ) ) . '">' . wp_nonce_field( 'jlife_join_group', '_jlife_join', true, false )
		. '<label for="jl-invite">' . esc_html__( 'Group invite code', 'jlife-challenges' ) . '</label>'
		. '<input id="jl-invite" type="text" name="jl_invite_code" autocapitalize="characters" autocomplete="off" required>'
		. '<button type="submit" class="jl-button">' . esc_html__( 'Join a group', 'jlife-challenges' ) . '</button></form></section>';
}

/**
 * The /share page body for a shareable day: preview, one section per group
 * (post, update, remove), the chat destination; for a solo reader, the join
 * prompt in place of the groups.
 *
 * @param array  $view  Share view (active, shareable).
 * @param string $error Error message from the last action.
 * @return string Escaped HTML.
 */
function jlife_challenges_share_body( $view, $error = '' ) {
	/* translators: %d: day number. */
	$body = '<h1>' . esc_html( sprintf( __( 'Share Day %d', 'jlife-challenges' ), $view['index'] ) ) . '</h1>'
		. '<p>' . esc_html__( 'Sharing is optional and never changes your points. It shares your whole entry, including your prayer.', 'jlife-challenges' ) . '</p>';

	$dests = (array) ( $view['destinations'] ?? array() );
	// phpcs:disable WordPress.Security.NonceVerification.Recommended -- display flags after a redirect; names come only from the viewer's own groups.
	$names = array_column( $dests, 'group_name', 'group_id' );
	if ( '' === $error && isset( $_GET['posted'] ) && isset( $names[ (int) $_GET['posted'] ] ) ) {
		/* translators: %s: group name. */
		$body .= '<p class="jl-ok" role="status">' . esc_html( sprintf( __( 'Posted to %s.', 'jlife-challenges' ), $names[ (int) $_GET['posted'] ] ) ) . ' <a href="' . esc_url( add_query_arg( 'g', (int) $_GET['posted'], home_url( '/group/' ) ) ) . '">' . esc_html__( 'See My Group', 'jlife-challenges' ) . '</a></p>';
	} elseif ( '' === $error && isset( $_GET['removed'] ) && isset( $names[ (int) $_GET['removed'] ] ) ) {
		/* translators: %s: group name. */
		$body .= '<p class="jl-ok" role="status">' . esc_html( sprintf( __( 'Removed from %s. Your SOAP and points are unchanged.', 'jlife-challenges' ), $names[ (int) $_GET['removed'] ] ) ) . '</p>';
	}
	// phpcs:enable WordPress.Security.NonceVerification.Recommended
	if ( '' !== $error ) {
		$body .= '<p class="jl-err" role="alert">' . esc_html( $error ) . '</p>';
	}

	// The exact text, then the destinations.
	$chat = jlife_challenges_chat_text( $view );
	$form = '<form method="post" class="jl-share" id="jl-share-form">' . wp_nonce_field( 'jlife_share_' . $view['day_key'], '_jlife_share', true, false )
		. '<h2>' . esc_html__( '1. Preview', 'jlife-challenges' ) . '</h2>'
		. '<pre class="jl-preview" id="jl-preview" aria-live="polite">' . esc_html( $chat ) . '</pre>'
		. '<h2>' . esc_html__( '2. Share it', 'jlife-challenges' ) . '</h2>';

	// Destination 1: each group, on its own.
	foreach ( $dests as $d ) {
		$gid   = (int) $d['group_id'];
		$name  = (string) $d['group_name'];
		$form .= '<section class="jl-dest"><h3>' . esc_html( $name ) . '</h3>';
		if ( 'guardian' === $d['post_block'] ) {
			$form .= '<p>' . esc_html__( 'Posting to this group starts once a parent or guardian has agreed.', 'jlife-challenges' ) . '</p>';
		} elseif ( 'hidden' === $d['post_block'] ) {
			/* translators: %s: reason given by the organizer. */
			$form .= '<p>' . esc_html( sprintf( __( 'An organizer hid this post from this group: %s', 'jlife-challenges' ), (string) $d['share']['hidden_reason'] ) ) . '</p>';
		} else {
			$form .= '<p>' . esc_html(
				$d['share']
					? __( 'This group can see this entry for this day. Posting again updates it with your latest text.', 'jlife-challenges' )
					: __( 'This group will see the whole entry above, from your latest saved text.', 'jlife-challenges' )
			) . '</p>'
				. '<button type="submit" name="jl_share_action" value="post-' . esc_attr( (string) $gid ) . '" class="jl-button jl-primary">'
				/* translators: %s: group name. */
				. esc_html( sprintf( $d['share'] ? __( 'Update my post in %s', 'jlife-challenges' ) : __( 'Post to %s', 'jlife-challenges' ), $name ) ) . '</button>';
		}
		if ( $d['share'] ) {
			/* translators: %s: group name. */
			$form .= '<button type="submit" name="jl_share_action" value="remove-' . esc_attr( (string) $gid ) . '" class="jl-button" formnovalidate>' . esc_html( sprintf( __( 'Remove from %s', 'jlife-challenges' ), $name ) ) . '</button>';
		}
		$form .= '</section>';
	}

	// Destination 2: an existing chat (nothing is stored).
	$form .= '<section class="jl-dest"><h3>' . esc_html__( 'Send to a chat', 'jlife-challenges' ) . '</h3>'
		. '<p>' . esc_html__( 'Opens your phone\'s share menu (Messages, Messenger, Zalo…). The link only opens the day\'s reading.', 'jlife-challenges' ) . '</p>'
		. '<button type="button" class="jl-button" id="jl-chat" hidden>' . esc_html__( 'Send to a chat…', 'jlife-challenges' ) . '</button>'
		. '<button type="button" class="jl-button" id="jl-copy" hidden>' . esc_html__( 'Copy text', 'jlife-challenges' ) . '</button>'
		. '<p class="jl-saved" id="jl-copied" aria-live="polite"></p>'
		. '<noscript><p>' . esc_html__( 'Select and copy the preview text above.', 'jlife-challenges' ) . '</p></noscript></section></form>';

	$data  = array(
		'text'   => $chat,
		'copied' => __( 'Copied. Paste it into your chat.', 'jlife-challenges' ),
	);
	$body .= '<div id="jl-share-data" data-share="' . esc_attr( (string) wp_json_encode( $data ) ) . '"></div>' . $form;
	if ( ! $dests ) {
		$body .= jlife_challenges_join_prompt();
	}
	return $body;
}

/**
 * /share?day=d###
 */
function jlife_challenges_screen_share() {
	$user_id = jlife_challenges_require_participant();
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only day selector.
	$day_key = isset( $_GET['day'] ) ? sanitize_key( wp_unslash( $_GET['day'] ) ) : null;
	$view    = jlife_challenges_share_view( $user_id, $day_key );
	$none    = jlife_challenges_render_no_day( $view );
	if ( null !== $none ) {
		jlife_challenges_send_page( __( 'Share', 'jlife-challenges' ), $none, 'group' );
	}
	if ( ! $view['shareable'] ) {
		/* translators: %d: day number. */
		$body = '<h1>' . esc_html( sprintf( __( 'Share Day %d', 'jlife-challenges' ), $view['index'] ) ) . '</h1>'
			. '<p>' . esc_html__( 'Write your SOAP for this day first, then you can share it.', 'jlife-challenges' ) . '</p>'
			. '<a class="jl-button jl-primary" href="' . esc_url( jlife_challenges_screen_url( 'soap', $view['day_key'] ) ) . '">' . esc_html__( 'Go to my SOAP', 'jlife-challenges' ) . '</a>';
		jlife_challenges_send_page( __( 'Share', 'jlife-challenges' ), $body, 'group' );
	}

	$error = '';
	if ( isset( $_POST['jl_share_action'] ) && check_admin_referer( 'jlife_share_' . $view['day_key'], '_jlife_share' ) ) {
		$error = jlife_challenges_handle_share_post( $user_id, $view );
	}
	jlife_challenges_send_page( __( 'Share', 'jlife-challenges' ), jlife_challenges_share_body( $view, $error ), 'group', 'share' );
}

/**
 * The /group page body: the group picker when the person is in more than one
 * group, one group's feed at a time, and "Have a code? Join a group" for a solo
 * reader (and for a member with a free slot).
 *
 * @param array  $view   Group view (see jlife_challenges_group_view()).
 * @param string $error  Join error.
 * @param string $joined Name of the group just joined ('' = none).
 * @return string Escaped HTML.
 */
function jlife_challenges_group_body( $view, $error = '', $joined = '' ) {
	if ( 'not_enrolled' === $view['state'] ) {
		return (string) jlife_challenges_render_no_day( array( 'state' => 'not_enrolled' ) );
	}
	$body = '<h1>' . esc_html__( 'My Group', 'jlife-challenges' ) . '</h1>';
	if ( '' !== $joined ) {
		/* translators: %s: group name. */
		$body .= '<p class="jl-ok" role="status">' . esc_html( sprintf( __( 'You joined %s. Entries you saved before joining stay private unless you share them.', 'jlife-challenges' ), $joined ) ) . '</p>';
	}
	if ( 'solo' === $view['state'] ) {
		return $body . '<p>' . esc_html__( 'You are reading on your own. When you join a group, what its members choose to share shows here.', 'jlife-challenges' ) . '</p>'
			. jlife_challenges_join_prompt( $error );
	}

	$groups = (array) ( $view['groups'] ?? array() );
	$picker = '';
	if ( count( $groups ) > 1 ) {
		$picker = '<nav class="jl-chips" aria-label="' . esc_attr__( 'Your groups', 'jlife-challenges' ) . '">';
		foreach ( $groups as $g ) {
			$picker .= '<a class="jl-chip" href="' . esc_url( add_query_arg( 'g', (int) $g['id'], home_url( '/group/' ) ) ) . '"' . ( $g['selected'] && 'ok' === $view['state'] ? ' aria-current="page"' : '' ) . '>' . esc_html( (string) $g['name'] ) . '</a>';
		}
		$picker .= '</nav>';
	}

	if ( 'denied' === $view['state'] ) {
		return $body . $picker . '<p class="jl-err" role="alert">' . esc_html( jlife_challenges_denied()->get_error_message() ) . '</p>';
	}

	$body .= $picker
		. ( '' !== $view['group_name'] ? '<p class="jl-day">' . esc_html( $view['group_name'] ) . '</p>' : '' )
		. '<p>' . esc_html__( 'What members chose to share from their daily reading. Only this group can see this. Points are personal and never shown here.', 'jlife-challenges' ) . '</p>';
	if ( ! $view['posts'] ) {
		$body .= '<p class="jl-empty">' . esc_html__( 'Nothing shared with this group yet. When you save your SOAP, tick this group\'s box to post it here.', 'jlife-challenges' ) . '</p>';
	}
	foreach ( $view['posts'] as $p ) {
		/* translators: 1: first name, 2: day number. */
		$meta  = sprintf( __( '%1$s · Day %2$d', 'jlife-challenges' ), $p['author'], (int) $p['index'] );
		$body .= '<article class="jl-post' . ( $p['mine'] ? ' jl-mine' : '' ) . '"><p class="jl-post-meta"><strong>' . esc_html( $meta ) . '</strong>'
			. ( '' !== $p['refs'] ? ' · ' . esc_html( $p['refs'] ) : '' ) . '</p>'
			. '<p class="jl-excerpt">' . esc_html( $p['body'] ) . '</p>';
		if ( $p['mine'] ) {
			$body .= '<p><a href="' . esc_url( add_query_arg( 'day', $p['day_key'], home_url( '/share/' ) ) ) . '">' . esc_html__( 'Yours — change or remove', 'jlife-challenges' ) . '</a></p>';
		}
		$body .= '</article>';
	}
	if ( count( $groups ) < JLIFE_CHALLENGES_MAX_GROUPS ) {
		$body .= jlife_challenges_join_prompt( $error );
	}
	return $body;
}

/**
 * Handle the "Join a group" POST on /group. Redirects to the new group on success.
 *
 * @param int $user_id Participant.
 * @return string Error message ('' when there was no attempt).
 */
function jlife_challenges_handle_join_post( $user_id ) {
	if ( ! isset( $_POST['jl_invite_code'] ) || ! check_admin_referer( 'jlife_join_group', '_jlife_join' ) ) {
		return '';
	}
	$code = sanitize_text_field( wp_unslash( $_POST['jl_invite_code'] ) );
	$pc   = jlife_challenges_participant_campaign( $user_id );
	if ( ! $pc ) {
		return __( 'Please join the Marathon first.', 'jlife-challenges' );
	}
	// Only a group in the campaign the person is in; their enrollment's age band is kept.
	$group = jlife_challenges_group_for_invite( $code );
	if ( $group && (int) $group['campaign_id'] !== (int) $pc['campaign']['id'] ) {
		$group = null;
		$code  = ''; // Counts as a wrong guess, like any other code that opens nothing here.
	}
	$r = jlife_challenges_join_with_code( $user_id, $code, (string) $pc['enrollment']['age_band'] );
	if ( is_wp_error( $r ) || ! $group ) {
		return is_wp_error( $r ) ? $r->get_error_message() : jlife_challenges_denied()->get_error_message();
	}
	wp_safe_redirect(
		add_query_arg(
			array(
				'g'      => (int) $group['id'],
				'joined' => 1,
			),
			home_url( '/group/' )
		)
	);
	exit;
}

/**
 * /group, or /group?g=ID for one of several groups.
 */
function jlife_challenges_screen_group() {
	$user_id = jlife_challenges_require_participant();
	$error   = jlife_challenges_handle_join_post( $user_id );
	// phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only group selector; the gate decides what the person may read.
	$group_id = isset( $_GET['g'] ) ? absint( wp_unslash( $_GET['g'] ) ) : 0;
	$view     = jlife_challenges_group_view( $user_id, $group_id );
	$joined   = '';
	if ( isset( $_GET['joined'] ) && 'ok' === $view['state'] ) {
		$joined = (string) $view['group_name'];
	}
	// phpcs:enable WordPress.Security.NonceVerification.Recommended
	jlife_challenges_send_page( __( 'My Group', 'jlife-challenges' ), jlife_challenges_group_body( $view, $error, $joined ), 'group' );
}
