<?php
/**
 * Sharing screens (M7, #62): /share?day=dNNN and /group.
 *
 * One share flow, two destinations: "Post to My Group" (a stored snapshot of
 * the chosen fields) and "Send to a chat" (the phone's share sheet or copy;
 * nothing stored). Never automatic, never part of Submit, never scored.
 *
 * @package jlife-challenges
 */

defined( 'ABSPATH' ) || exit;

/**
 * A "Share" link for a submitted day ('' otherwise).
 *
 * @param array $view Day view.
 * @return string
 */
function jlife_challenges_share_button( $view ) {
	if ( ! in_array( $view['status'] ?? '', array( 'earned', 'late_no_point', 'submitted' ), true ) ) {
		return '';
	}
	return '<a class="jl-button" href="' . esc_url( add_query_arg( 'day', $view['day_key'], home_url( '/share/' ) ) ) . '">'
		. esc_html__( 'Share with my group or a chat', 'jlife-challenges' ) . '</a>';
}

/**
 * Handle a share-page POST (post or remove). Redirects on success.
 *
 * @param int   $user_id Author.
 * @param array $view    Share view.
 * @return string Error message.
 */
function jlife_challenges_handle_share_post( $user_id, $view ) {
	// phpcs:disable WordPress.Security.NonceVerification.Missing -- verified by the caller.
	$action = isset( $_POST['jl_share_action'] ) ? sanitize_key( wp_unslash( $_POST['jl_share_action'] ) ) : '';
	$fields = isset( $_POST['fields'] ) ? array_map( 'sanitize_key', (array) wp_unslash( $_POST['fields'] ) ) : array();
	// phpcs:enable WordPress.Security.NonceVerification.Missing
	$back = add_query_arg( 'day', $view['day_key'], home_url( '/share/' ) );
	if ( 'remove' === $action ) {
		$r = $view['share'] ? jlife_challenges_unshare( $user_id, (int) $view['share']['id'] ) : jlife_challenges_denied();
		if ( true === $r ) {
			wp_safe_redirect( add_query_arg( 'removed', 1, $back ) );
			exit;
		}
	} else {
		$r = jlife_challenges_share( $user_id, (int) $view['entry']['id'], $fields );
		if ( ! is_wp_error( $r ) ) {
			wp_safe_redirect( add_query_arg( 'posted', 1, $back ) );
			exit;
		}
	}
	return $r->get_error_message();
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
	/* translators: %d: day number. */
	$body = '<h1>' . esc_html( sprintf( __( 'Share Day %d', 'jlife-challenges' ), $view['index'] ) ) . '</h1>'
		. '<p>' . esc_html__( 'Sharing is optional and never changes your points. Choose what to share; the rest stays private.', 'jlife-challenges' ) . '</p>';
	if ( ! $view['shareable'] ) {
		$body .= '<p>' . esc_html__( 'Submit your SOAP for this day first, then you can share part of it.', 'jlife-challenges' ) . '</p>'
			. '<a class="jl-button jl-primary" href="' . esc_url( add_query_arg( 'day', $view['day_key'], home_url( '/soap/' ) ) ) . '">' . esc_html__( 'Go to my SOAP', 'jlife-challenges' ) . '</a>';
		jlife_challenges_send_page( __( 'Share', 'jlife-challenges' ), $body, 'group' );
	}

	$error = '';
	if ( isset( $_POST['jl_share_action'] ) && check_admin_referer( 'jlife_share_' . $view['day_key'], '_jlife_share' ) ) {
		$error = jlife_challenges_handle_share_post( $user_id, $view );
	}
	// phpcs:disable WordPress.Security.NonceVerification.Recommended -- display flags after a redirect.
	if ( isset( $_GET['posted'] ) && '' === $error ) {
		$body .= '<p class="jl-ok" role="status">' . esc_html__( 'Posted to your group.', 'jlife-challenges' ) . ' <a href="' . esc_url( home_url( '/group/' ) ) . '">' . esc_html__( 'See My Group', 'jlife-challenges' ) . '</a></p>';
	} elseif ( isset( $_GET['removed'] ) && '' === $error ) {
		$body .= '<p class="jl-ok" role="status">' . esc_html__( 'Removed from your group. Your SOAP and points are unchanged.', 'jlife-challenges' ) . '</p>';
	}
	// phpcs:enable WordPress.Security.NonceVerification.Recommended
	if ( '' !== $error ) {
		$body .= '<p class="jl-err" role="alert">' . esc_html( $error ) . '</p>';
	}

	// Field choices with the exact text, then the preview.
	$labels = jlife_challenges_field_labels();
	$parts  = array();
	$form   = '<form method="post" class="jl-share" id="jl-share-form">' . wp_nonce_field( 'jlife_share_' . $view['day_key'], '_jlife_share', true, false )
		. '<fieldset><legend>' . esc_html__( '1. Choose what to share', 'jlife-challenges' ) . '</legend>';
	foreach ( JLIFE_CHALLENGES_FIELDS as $f ) {
		$text = trim( (string) $view['entry'][ $f ] );
		if ( '' === $text ) {
			continue;
		}
		$parts[ $f ] = array( $labels[ $f ][0], $text );
		$form       .= '<label class="jl-pick"><input type="checkbox" name="fields[]" value="' . esc_attr( $f ) . '"' . checked( in_array( $f, $view['checked'], true ), true, false ) . '> '
			. '<span><strong>' . esc_html( $labels[ $f ][0] ) . '</strong><span class="jl-excerpt">' . esc_html( $text ) . '</span></span></label>';
	}
	$form .= '</fieldset>';

	$chat  = jlife_challenges_chat_text( $view, $view['checked'] );
	$form .= '<h2>' . esc_html__( '2. Preview', 'jlife-challenges' ) . '</h2>'
		. '<pre class="jl-preview" id="jl-preview" aria-live="polite">' . esc_html( $chat ) . '</pre>';

	// Destination 1: My Group.
	$form .= '<h2>' . esc_html__( '3. Share it', 'jlife-challenges' ) . '</h2><section class="jl-dest"><h3>' . esc_html__( 'Post to My Group', 'jlife-challenges' ) . '</h3>';
	if ( 'guardian' === $view['post_block'] ) {
		$form .= '<p>' . esc_html__( 'Posting to your group starts once a parent or guardian has agreed.', 'jlife-challenges' ) . '</p>';
	} elseif ( 'hidden' === $view['post_block'] ) {
		/* translators: %s: reason given by the organizer. */
		$form .= '<p>' . esc_html( sprintf( __( 'An organizer hid this post from your group: %s', 'jlife-challenges' ), (string) $view['share']['hidden_reason'] ) ) . '</p>';
	} else {
		if ( $view['share'] ) {
			$form .= '<p>' . esc_html__( 'Your group can see the parts you posted for this day. Posting again replaces it.', 'jlife-challenges' ) . '</p>';
		}
		$form .= '<button type="submit" name="jl_share_action" value="post" class="jl-button jl-primary">'
			. esc_html( $view['share'] ? __( 'Update my group post', 'jlife-challenges' ) : __( 'Post to My Group', 'jlife-challenges' ) ) . '</button>';
	}
	if ( $view['share'] ) {
		$form .= '<button type="submit" name="jl_share_action" value="remove" class="jl-button" formnovalidate>' . esc_html__( 'Remove from my group', 'jlife-challenges' ) . '</button>';
	}
	$form .= '</section>';

	// Destination 2: an existing chat (nothing is stored).
	$form .= '<section class="jl-dest"><h3>' . esc_html__( 'Send to a chat', 'jlife-challenges' ) . '</h3>'
		. '<p>' . esc_html__( 'Opens your phone\'s share menu (Messages, Messenger, Zalo…). The link only opens the day\'s reading.', 'jlife-challenges' ) . '</p>'
		. '<button type="button" class="jl-button" id="jl-chat" hidden>' . esc_html__( 'Send to a chat…', 'jlife-challenges' ) . '</button>'
		. '<button type="button" class="jl-button" id="jl-copy" hidden>' . esc_html__( 'Copy text', 'jlife-challenges' ) . '</button>'
		. '<p class="jl-saved" id="jl-copied" aria-live="polite"></p>'
		. '<noscript><p>' . esc_html__( 'Select and copy the preview text above.', 'jlife-challenges' ) . '</p></noscript></section></form>';

	$url  = jlife_challenges_public_reading_url( $view );
	$refs = implode( '; ', array_map( static fn( $p ) => (string) $p['display'], (array) ( $view['reading']['day']['passages'] ?? array() ) ) );
	/* translators: 1: day number, 2: Bible references. */
	$head = sprintf( __( 'Bible Marathon · Day %1$d · %2$s', 'jlife-challenges' ), (int) $view['index'], $refs );
	/* translators: %s: link to the day's reading. */
	$foot  = '' !== $url ? sprintf( __( "Today's reading: %s", 'jlife-challenges' ), $url ) : '';
	$data  = array(
		'head'   => $head,
		'parts'  => $parts,
		'foot'   => $foot,
		'copied' => __( 'Copied. Paste it into your chat.', 'jlife-challenges' ),
	);
	$body .= '<div id="jl-share-data" data-share="' . esc_attr( (string) wp_json_encode( $data ) ) . '"></div>' . $form;
	jlife_challenges_send_page( __( 'Share', 'jlife-challenges' ), $body, 'group', 'share' );
}

/**
 * /group
 */
function jlife_challenges_screen_group() {
	$user_id = jlife_challenges_require_participant();
	$view    = jlife_challenges_group_view( $user_id );
	if ( 'ok' !== $view['state'] ) {
		jlife_challenges_send_page( __( 'My Group', 'jlife-challenges' ), (string) jlife_challenges_render_no_day( array( 'state' => 'not_enrolled' ) ), 'group' );
	}
	$labels = jlife_challenges_field_labels();
	$body   = '<h1>' . esc_html__( 'My Group', 'jlife-challenges' ) . '</h1>'
		. ( '' !== $view['group_name'] ? '<p class="jl-day">' . esc_html( $view['group_name'] ) . '</p>' : '' )
		. '<p>' . esc_html__( 'What members chose to share from their daily reading. Only your group can see this. Points are personal and never shown here.', 'jlife-challenges' ) . '</p>';
	if ( ! $view['posts'] ) {
		$body .= '<p class="jl-empty">' . esc_html__( 'Nothing shared yet. After you submit a SOAP, you can choose to share part of it here.', 'jlife-challenges' ) . '</p>';
	}
	foreach ( $view['posts'] as $p ) {
		/* translators: 1: first name, 2: day number. */
		$meta  = sprintf( __( '%1$s · Day %2$d', 'jlife-challenges' ), $p['author'], (int) $p['index'] );
		$body .= '<article class="jl-post' . ( $p['mine'] ? ' jl-mine' : '' ) . '"><p class="jl-post-meta"><strong>' . esc_html( $meta ) . '</strong>'
			. ( '' !== $p['refs'] ? ' · ' . esc_html( $p['refs'] ) : '' ) . '</p>';
		foreach ( $p['fields'] as $f => $text ) {
			$body .= '<h3>' . esc_html( $labels[ $f ][0] ?? $f ) . '</h3><p class="jl-excerpt">' . esc_html( $text ) . '</p>';
		}
		if ( $p['mine'] ) {
			$body .= '<p><a href="' . esc_url( add_query_arg( 'day', $p['day_key'], home_url( '/share/' ) ) ) . '">' . esc_html__( 'Yours — change or remove', 'jlife-challenges' ) . '</a></p>';
		}
		$body .= '</article>';
	}
	jlife_challenges_send_page( __( 'My Group', 'jlife-challenges' ), $body, 'group' );
}
