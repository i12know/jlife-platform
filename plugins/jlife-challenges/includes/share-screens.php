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
 * Below the day's groups, "Groups you left" lists the author's old shares in
 * groups they are no longer in, each removable (D18). "Start a group", leaving a
 * group and a new invite code are on /group (#112 part 2).
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
 * "Groups you left" on /share (D18): the author's own old shares in groups they
 * are no longer in, each with a Remove button. What they shared stays visible to
 * that group until they remove it. Empty (no section) when there is nothing.
 *
 * @param int $user_id Author.
 * @return string Escaped HTML.
 */
function jlife_challenges_left_groups_section( $user_id ) {
	$pc = jlife_challenges_participant_campaign( $user_id );
	if ( ! $pc ) {
		return '';
	}
	$rows = jlife_challenges_left_group_shares( $user_id, (int) $pc['campaign']['id'] );
	$flag = isset( $_GET['removed_old'] ) // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display flag after a redirect.
		? '<p class="jl-ok" role="status">' . esc_html__( 'Removed. Your SOAP and points are unchanged.', 'jlife-challenges' ) . '</p>'
		: '';
	if ( ! $rows ) {
		return '' === $flag ? '' : '<section class="jl-left">' . $flag . '</section>';
	}
	$out = '<section class="jl-left"><h2>' . esc_html__( 'Groups you left', 'jlife-challenges' ) . '</h2>' . $flag
		. '<p>' . esc_html__( 'You can no longer see these groups. What you shared there is still visible to their members until you remove it. Removing a post does not change your SOAP or points.', 'jlife-challenges' ) . '</p>'
		. '<form method="post">' . wp_nonce_field( 'jlife_unshare_old', '_jlife_unshare', true, false ) . '<ul class="jl-passages">';
	foreach ( $rows as $r ) {
		/* translators: 1: group name, 2: day number. */
		$label = sprintf( __( '%1$s · Day %2$d', 'jlife-challenges' ), '' !== $r['group_name'] ? $r['group_name'] : __( 'A group', 'jlife-challenges' ), (int) $r['index'] );
		$out  .= '<li><strong>' . esc_html( $label ) . '</strong>'
			. ( 'hidden' === $r['status'] ? ' · ' . esc_html__( 'hidden by an organizer', 'jlife-challenges' ) : '' )
			. '<button type="submit" name="jl_unshare_old" value="' . esc_attr( (string) $r['id'] ) . '" class="jl-button" formnovalidate>' . esc_html__( 'Remove my post', 'jlife-challenges' ) . '</button></li>';
	}
	return $out . '</ul></form></section>';
}

/**
 * Handle a "Remove my post" POST from "Groups you left". Only the author's own
 * shares in groups they are no longer in can be removed this way. Redirects.
 *
 * @param int $user_id Author.
 * @return string Error message ('' when there was no attempt).
 */
function jlife_challenges_handle_old_share_post( $user_id ) {
	if ( ! isset( $_POST['jl_unshare_old'] ) || ! check_admin_referer( 'jlife_unshare_old', '_jlife_unshare' ) ) {
		return '';
	}
	$share_id = absint( wp_unslash( $_POST['jl_unshare_old'] ) );
	$pc       = jlife_challenges_participant_campaign( $user_id );
	$allowed  = $pc ? array_map( 'intval', array_column( jlife_challenges_left_group_shares( $user_id, (int) $pc['campaign']['id'] ), 'id' ) ) : array();
	$r        = in_array( $share_id, $allowed, true ) ? jlife_challenges_unshare( $user_id, $share_id ) : jlife_challenges_denied();
	if ( is_wp_error( $r ) ) {
		return $r->get_error_message();
	}
	$day = isset( $_GET['day'] ) ? sanitize_key( wp_unslash( $_GET['day'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only day selector; the nonce was checked above.
	wp_safe_redirect(
		add_query_arg(
			array_filter(
				array(
					'day'         => $day,
					'removed_old' => 1,
				)
			),
			home_url( '/share/' )
		)
	);
	exit;
}

/**
 * /share?day=d###
 */
function jlife_challenges_screen_share() {
	$user_id = jlife_challenges_require_participant();
	$old_err = jlife_challenges_handle_old_share_post( $user_id );
	$left    = ( '' !== $old_err ? '<p class="jl-err" role="alert">' . esc_html( $old_err ) . '</p>' : '' ) . jlife_challenges_left_groups_section( $user_id );
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only day selector.
	$day_key = isset( $_GET['day'] ) ? sanitize_key( wp_unslash( $_GET['day'] ) ) : null;
	$view    = jlife_challenges_share_view( $user_id, $day_key );
	$none    = jlife_challenges_render_no_day( $view );
	if ( null !== $none ) {
		jlife_challenges_send_page( __( 'Share', 'jlife-challenges' ), $none . $left, 'group' );
	}
	if ( ! $view['shareable'] ) {
		/* translators: %d: day number. */
		$body = '<h1>' . esc_html( sprintf( __( 'Share Day %d', 'jlife-challenges' ), $view['index'] ) ) . '</h1>'
			. '<p>' . esc_html__( 'Write your SOAP for this day first, then you can share it.', 'jlife-challenges' ) . '</p>'
			. '<a class="jl-button jl-primary" href="' . esc_url( jlife_challenges_screen_url( 'soap', $view['day_key'] ) ) . '">' . esc_html__( 'Go to my SOAP', 'jlife-challenges' ) . '</a>';
		jlife_challenges_send_page( __( 'Share', 'jlife-challenges' ), $body . $left, 'group' );
	}

	$error = '';
	if ( isset( $_POST['jl_share_action'] ) && check_admin_referer( 'jlife_share_' . $view['day_key'], '_jlife_share' ) ) {
		$error = jlife_challenges_handle_share_post( $user_id, $view );
	}
	jlife_challenges_send_page( __( 'Share', 'jlife-challenges' ), jlife_challenges_share_body( $view, $error ) . $left, 'group', 'share' );
}

/**
 * The invite code and link, shown once, with copy buttons. The code is stored
 * hashed, so this is the only time it can be shown; a starter who loses it
 * resets it from /group. Used by /group and by the page after "Start a group"
 * on /join.
 *
 * @param string $group_name Group name (the person's own group).
 * @param string $code       The plain code.
 * @return string Escaped HTML, including the copy script.
 */
function jlife_challenges_invite_reveal_html( $group_name, $code ) {
	$link = add_query_arg( 'code', $code, home_url( '/join/' ) );
	if ( 0 === strpos( determine_locale(), 'vi' ) ) {
		// A group started in Vietnamese shares a Vietnamese link: the join form opens in Vietnamese (#222).
		$link = add_query_arg( 'lang', 'vi', $link );
	}
	$base = plugins_url( 'assets/', dirname( __DIR__ ) . '/jlife-challenges.php' );
	return '<section class="jl-invite"><h2>' . esc_html( $group_name ) . '</h2>'
		. '<p>' . esc_html__( 'Invite code', 'jlife-challenges' ) . '</p>'
		. '<p class="jl-code" style="font-size:1.6rem;font-weight:700;letter-spacing:.15em;margin:.25rem 0">' . esc_html( $code ) . '</p>'
		. '<button type="button" class="jl-button" data-copy="' . esc_attr( $code ) . '" data-done="' . esc_attr__( 'Copied.', 'jlife-challenges' ) . '" hidden>' . esc_html__( 'Copy the code', 'jlife-challenges' ) . '</button>'
		. '<p>' . esc_html__( 'Invite link', 'jlife-challenges' ) . '</p>'
		. '<p class="jl-link" style="word-break:break-all">' . esc_html( $link ) . '</p>'
		. '<button type="button" class="jl-button" data-copy="' . esc_attr( $link ) . '" data-done="' . esc_attr__( 'Copied.', 'jlife-challenges' ) . '" hidden>' . esc_html__( 'Copy the link', 'jlife-challenges' ) . '</button>'
		. '<p class="jl-saved" id="jl-copied" aria-live="polite"></p>'
		. '<p>' . esc_html__( 'This is the only time we show the code. Send it only to people you want in your group. If you lose it, you can reset it from My Group: the old code then stops working.', 'jlife-challenges' ) . '</p></section>'
		. '<script src="' . esc_url( $base . 'invite.js?v=1' ) . '"></script>'; // phpcs:ignore WordPress.WP.EnqueuedResources.NonEnqueuedScript -- standalone page without wp_footer.
}

/**
 * "Start a group" (adults only): a name, then the code and link are shown once.
 * Posts to /group. A fresh token makes a reload or a double tap harmless.
 *
 * @param string $error Error from the last try.
 * @param string $name  Name to keep in the field.
 * @return string
 */
function jlife_challenges_start_form( $error = '', $name = '' ) {
	return '<section class="jl-join jl-start"><h2>' . esc_html__( 'Start a group', 'jlife-challenges' ) . '</h2>'
		. '<p>' . esc_html__( 'Name a group, then share its invite code with friends or the people you mentor. Only its members and the organizers can see the name. Starting a group gives you no extra view of anyone\'s SOAP or progress.', 'jlife-challenges' ) . '</p>'
		. ( '' !== $error ? '<p class="jl-err" role="alert">' . esc_html( $error ) . '</p>' : '' )
		. '<form method="post" action="' . esc_url( home_url( '/group/' ) ) . '">' . wp_nonce_field( 'jlife_group_manage', '_jlife_group', true, false )
		. '<input type="hidden" name="jl_group_action" value="start"><input type="hidden" name="jl_start_token" value="' . esc_attr( wp_generate_password( 16, false ) ) . '">'
		. '<label for="jl-group-name">' . esc_html__( 'Group name', 'jlife-challenges' ) . '</label>'
		. '<input id="jl-group-name" type="text" name="jl_group_name" value="' . esc_attr( $name ) . '" maxlength="' . (int) JLIFE_CHALLENGES_GROUP_NAME_MAX . '" autocomplete="off" required>'
		. '<button type="submit" class="jl-button">' . esc_html__( 'Start a group', 'jlife-challenges' ) . '</button></form></section>';
}

/**
 * The starter's and every member's controls for one group: reset the code
 * (starter only) and leave.
 *
 * @param array $view Group view (state ok).
 * @return string
 */
function jlife_challenges_group_controls( $view ) {
	$gid  = (int) $view['group_id'];
	$out  = '<section class="jl-manage">';
	$base = add_query_arg( 'g', $gid, home_url( '/group/' ) );
	if ( 'starter' === $view['role'] ) {
		$out .= '<h2>' . esc_html__( 'Invite people', 'jlife-challenges' ) . '</h2>'
			. '<p>' . esc_html__( 'You started this group, so you can make a new invite code. We cannot show the current one again. Making a new one stops the old one working.', 'jlife-challenges' ) . '</p>'
			. '<a class="jl-button" href="' . esc_url( add_query_arg( 'confirm', 'reset', $base ) ) . '">' . esc_html__( 'Make a new invite code', 'jlife-challenges' ) . '</a>';
	}
	return $out . '<a class="jl-button" href="' . esc_url( add_query_arg( 'confirm', 'leave', $base ) ) . '">' . esc_html__( 'Leave this group', 'jlife-challenges' ) . '</a></section>';
}

/**
 * The "are you sure" page for leaving a group or making a new code. Works
 * without JavaScript: the buttons are an ordinary form.
 *
 * @param array  $view Group view (state ok).
 * @param string $what leave|reset.
 * @return string
 */
function jlife_challenges_group_confirm( $view, $what ) {
	$gid  = (int) $view['group_id'];
	$back = add_query_arg( 'g', $gid, home_url( '/group/' ) );
	if ( 'reset' === $what ) {
		$title = __( 'Make a new invite code?', 'jlife-challenges' );
		$text  = __( 'The old code and link will stop working. People already in the group stay in it. We will show the new code once.', 'jlife-challenges' );
		$go    = __( 'Make a new code', 'jlife-challenges' );
	} else {
		/* translators: %s: group name. */
		$title = sprintf( __( 'Leave %s?', 'jlife-challenges' ), $view['group_name'] );
		$text  = __( 'You will no longer see what this group shares, and you will not be able to post to it. What you already shared stays visible to the group unless you remove it; you can do that from the Share page. Your SOAP entries and points do not change. You can join again with a code.', 'jlife-challenges' );
		$go    = __( 'Leave this group', 'jlife-challenges' );
	}
	return '<section class="jl-confirm"><h1>' . esc_html( $title ) . '</h1><p>' . esc_html( $text ) . '</p>'
		. '<form method="post" action="' . esc_url( home_url( '/group/' ) ) . '">' . wp_nonce_field( 'jlife_group_manage', '_jlife_group', true, false )
		. '<input type="hidden" name="jl_group_action" value="' . esc_attr( $what ) . '"><input type="hidden" name="jl_group_id" value="' . esc_attr( (string) $gid ) . '">'
		. '<div class="jl-actions"><a class="jl-button" href="' . esc_url( $back ) . '">' . esc_html__( 'Cancel', 'jlife-challenges' ) . '</a>'
		. '<button type="submit" class="jl-button jl-primary">' . esc_html( $go ) . '</button></div></form></section>';
}

/**
 * The group feed as one collapsible section per Marathon reading day (#203).
 * Posts are grouped by their reading-day key (never the posting date), in the
 * order the feed already has: newest day first, then newest message first. Only
 * the first (newest) day is open. Native details/summary, so it works without
 * JavaScript and for screen readers.
 *
 * @param array $posts Feed rows from jlife_challenges_group_view() (day_key, index, refs, author, body, mine).
 * @return string Escaped HTML ('' for an empty feed).
 */
function jlife_challenges_group_days_html( $posts ) {
	$days = array();
	foreach ( $posts as $p ) {
		$days[ (string) $p['day_key'] ][] = $p;
	}
	$out  = '';
	$open = true;
	foreach ( $days as $day_posts ) {
		$first = $day_posts[0];
		if ( '' !== (string) $first['refs'] ) {
			/* translators: 1: day number, 2: Bible references. */
			$label = sprintf( __( 'Day %1$d · %2$s', 'jlife-challenges' ), (int) $first['index'], $first['refs'] );
		} else {
			/* translators: %d: day number. */
			$label = sprintf( __( 'Day %d', 'jlife-challenges' ), (int) $first['index'] );
		}
		$out .= '<details class="jl-day-group"' . ( $open ? ' open' : '' ) . '><summary>' . esc_html( $label ) . '</summary>';
		foreach ( $day_posts as $p ) {
			$out .= jlife_challenges_group_post_html( $p );
		}
		$out .= '</details>';
		$open = false;
	}
	return $out;
}

/**
 * One shared message card.
 *
 * @param array $p Feed row.
 * @return string Escaped HTML.
 */
function jlife_challenges_group_post_html( $p ) {
	/* translators: 1: first name, 2: day number. */
	$meta = sprintf( __( '%1$s · Day %2$d', 'jlife-challenges' ), $p['author'], (int) $p['index'] );
	$out  = '<article class="jl-post' . ( $p['mine'] ? ' jl-mine' : '' ) . '"><p class="jl-post-meta"><strong>' . esc_html( $meta ) . '</strong>'
		. ( '' !== $p['refs'] ? ' · ' . esc_html( $p['refs'] ) : '' ) . '</p>'
		. '<p class="jl-excerpt">' . esc_html( $p['body'] ) . '</p>';
	if ( $p['mine'] ) {
		$out .= '<p><a href="' . esc_url( add_query_arg( 'day', $p['day_key'], home_url( '/share/' ) ) ) . '">' . esc_html__( 'Yours — change or remove', 'jlife-challenges' ) . '</a></p>';
	}
	return $out . '</article>';
}

/**
 * The /group page body: the group picker when the person is in more than one
 * group, one group's feed at a time, "Have a code? Join a group" for a solo
 * reader (and for a member with a free slot), and for an adult with a free slot
 * "Start a group".
 *
 * @param array  $view   Group view (see jlife_challenges_group_view()).
 * @param string $error  Join error.
 * @param string $joined Name of the group just joined ('' = none).
 * @param array  $more   Optional: left (bool), notice (string), start_error (string), start_name (string).
 * @return string Escaped HTML.
 */
function jlife_challenges_group_body( $view, $error = '', $joined = '', $more = array() ) {
	if ( 'not_enrolled' === $view['state'] ) {
		return (string) jlife_challenges_render_no_day( array( 'state' => 'not_enrolled' ) );
	}
	$start = ! empty( $view['can_start'] ) ? jlife_challenges_start_form( (string) ( $more['start_error'] ?? '' ), (string) ( $more['start_name'] ?? '' ) ) : '';
	$body  = '<h1>' . esc_html__( 'My Group', 'jlife-challenges' ) . '</h1>';
	if ( '' !== $joined ) {
		/* translators: %s: group name. */
		$body .= '<p class="jl-ok" role="status">' . esc_html( sprintf( __( 'You joined %s. Entries you saved before joining stay private unless you share them.', 'jlife-challenges' ), $joined ) ) . '</p>';
	}
	if ( ! empty( $more['notice'] ) ) {
		$body .= '<p class="jl-err" role="alert">' . esc_html( (string) $more['notice'] ) . '</p>';
	}
	if ( ! empty( $more['left'] ) ) {
		$body .= '<p class="jl-ok" role="status">' . esc_html__( 'You left the group. Your SOAP entries and points are unchanged. What you shared there stays visible to its members until you remove it.', 'jlife-challenges' )
			. ' <a href="' . esc_url( home_url( '/share/' ) ) . '">' . esc_html__( 'Remove my old shares', 'jlife-challenges' ) . '</a></p>';
	}
	if ( 'solo' === $view['state'] ) {
		return $body . '<p>' . esc_html__( 'You are reading on your own. When you join a group, what its members choose to share shows here.', 'jlife-challenges' ) . '</p>'
			. jlife_challenges_join_prompt( $error ) . $start;
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
	$body .= jlife_challenges_group_days_html( (array) $view['posts'] );
	$body .= jlife_challenges_group_controls( $view );
	if ( count( $groups ) < JLIFE_CHALLENGES_MAX_GROUPS ) {
		$body .= jlife_challenges_join_prompt( $error ) . $start;
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
 * Handle a start / new-code / leave POST on /group (one nonce, one field says
 * which). Starting and a new code answer with the code, shown once; leaving
 * redirects.
 *
 * @param int $user_id Participant.
 * @return array { error: string, body: string (a page to show instead of /group), start_name: string }
 */
function jlife_challenges_handle_manage_post( $user_id ) {
	$out = array(
		'error'      => '',
		'body'       => '',
		'start_name' => '',
	);
	if ( ! isset( $_POST['jl_group_action'] ) || ! check_admin_referer( 'jlife_group_manage', '_jlife_group' ) ) {
		return $out;
	}
	$action   = sanitize_key( wp_unslash( $_POST['jl_group_action'] ) );
	$group_id = isset( $_POST['jl_group_id'] ) ? absint( wp_unslash( $_POST['jl_group_id'] ) ) : 0;

	if ( 'start' === $action ) {
		$token = isset( $_POST['jl_start_token'] ) ? sanitize_key( wp_unslash( $_POST['jl_start_token'] ) ) : '';
		$name  = isset( $_POST['jl_group_name'] ) ? sanitize_text_field( wp_unslash( $_POST['jl_group_name'] ) ) : '';
		if ( '' === $token || get_transient( 'jlife_chal_start_tok_' . $token ) ) {
			// A reload or a second tap of a form that already worked: never a second group.
			$out['error'] = __( 'That group was already started. Open My Group to see it.', 'jlife-challenges' );
			return $out;
		}
		$r = jlife_challenges_start_group( $user_id, $name );
		if ( is_wp_error( $r ) ) {
			$out['error']      = $r->get_error_message();
			$out['start_name'] = $name;
			return $out;
		}
		set_transient( 'jlife_chal_start_tok_' . $token, 1, DAY_IN_SECONDS );
		$out['body'] = '<h1>' . esc_html__( 'Your group is ready', 'jlife-challenges' ) . '</h1>'
			. '<p>' . esc_html__( 'Share the code or the link with the people you want to invite.', 'jlife-challenges' ) . '</p>'
			. jlife_challenges_invite_reveal_html( $r['name'], $r['invite_code'] )
			. '<p><a class="jl-button jl-primary" href="' . esc_url( add_query_arg( 'g', (int) $r['id'], home_url( '/group/' ) ) ) . '">' . esc_html__( 'Go to My Group', 'jlife-challenges' ) . '</a></p>';
	} elseif ( 'reset' === $action ) {
		// Through /group only the group's own starter; organizers use WP-CLI.
		$group = jlife_challenges_get_group( $group_id );
		$code  = $group && jlife_challenges_is_group_starter( $user_id, $group_id ) ? jlife_challenges_reset_invite( $user_id, $group_id ) : jlife_challenges_denied();
		if ( is_wp_error( $code ) ) {
			$out['error'] = $code->get_error_message();
			return $out;
		}
		$out['body'] = '<h1>' . esc_html__( 'New invite code', 'jlife-challenges' ) . '</h1>'
			. '<p>' . esc_html__( 'The old code and link no longer work.', 'jlife-challenges' ) . '</p>'
			. jlife_challenges_invite_reveal_html( (string) $group['name'], $code )
			. '<p><a class="jl-button jl-primary" href="' . esc_url( add_query_arg( 'g', $group_id, home_url( '/group/' ) ) ) . '">' . esc_html__( 'Back to My Group', 'jlife-challenges' ) . '</a></p>';
	} elseif ( 'leave' === $action ) {
		$r = jlife_challenges_leave_group( $user_id, $group_id );
		if ( is_wp_error( $r ) ) {
			$out['error'] = $r->get_error_message();
			return $out;
		}
		wp_safe_redirect( add_query_arg( 'left', 1, home_url( '/group/' ) ) );
		exit;
	}
	return $out;
}

/**
 * /group, or /group?g=ID for one of several groups.
 */
function jlife_challenges_screen_group() {
	$user_id = jlife_challenges_require_participant();
	$error   = jlife_challenges_handle_join_post( $user_id );
	$managed = jlife_challenges_handle_manage_post( $user_id );
	if ( '' !== $managed['body'] ) {
		jlife_challenges_send_page( __( 'My Group', 'jlife-challenges' ), $managed['body'], 'group' );
	}
	// phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only group selector; the gate decides what the person may read.
	$group_id = isset( $_GET['g'] ) ? absint( wp_unslash( $_GET['g'] ) ) : 0;
	$view     = jlife_challenges_group_view( $user_id, $group_id );
	$joined   = '';
	if ( isset( $_GET['joined'] ) && 'ok' === $view['state'] ) {
		$joined = (string) $view['group_name'];
	}
	$confirm = isset( $_GET['confirm'] ) ? sanitize_key( wp_unslash( $_GET['confirm'] ) ) : '';
	$left    = isset( $_GET['left'] );
	// phpcs:enable WordPress.Security.NonceVerification.Recommended
	if ( 'ok' === $view['state'] && ( 'leave' === $confirm || ( 'reset' === $confirm && 'starter' === $view['role'] ) ) ) {
		jlife_challenges_send_page( __( 'My Group', 'jlife-challenges' ), jlife_challenges_group_confirm( $view, $confirm ), 'group' );
	}
	$starting = isset( $_POST['jl_group_action'] ) && 'start' === sanitize_key( wp_unslash( $_POST['jl_group_action'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- the nonce was checked in the handler above.
	$more     = array(
		'left'        => $left,
		'start_error' => $starting ? $managed['error'] : '',
		'notice'      => $starting ? '' : $managed['error'],
		'start_name'  => $managed['start_name'],
	);
	jlife_challenges_send_page( __( 'My Group', 'jlife-challenges' ), jlife_challenges_group_body( $view, $error, $joined, $more ), 'group' );
}
