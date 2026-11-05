<?php
/**
 * Participant pages: /join, /signin, /consent (#58).
 *
 * Minimal, mobile-first, self-contained pages (styled properly in M6).
 * Every form carries a nonce; every output is escaped; pages are noindex.
 *
 * @package jlife-auth
 */

defined( 'ABSPATH' ) || exit;

add_action( 'init', 'jlife_auth_add_rewrites' );

/**
 * Routes.
 */
function jlife_auth_add_rewrites() {
	add_rewrite_rule( '^(join|signin|consent)/?$', 'index.php?jlife_auth=$matches[1]', 'top' );
}

add_filter( 'query_vars', 'jlife_auth_query_vars' );

/**
 * Register the route query var.
 *
 * @param array $vars Query vars.
 * @return array
 */
function jlife_auth_query_vars( $vars ) {
	$vars[] = 'jlife_auth';
	return $vars;
}

/**
 * Is the visitor inside an app's built-in browser (sessions may not stick)?
 *
 * @return bool
 */
function jlife_auth_in_app_browser() {
	$ua = isset( $_SERVER['HTTP_USER_AGENT'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) ) : '';
	return (bool) preg_match( '/FBAN|FBAV|FB_IAB|Messenger|Instagram|Zalo|Line\/|MicroMessenger/i', $ua );
}

/**
 * Where to go after signing in (validated local path only).
 *
 * @return string
 */
function jlife_auth_redirect_target() {
	// phpcs:ignore WordPress.Security.NonceVerification -- read-only redirect target, validated below.
	$raw = isset( $_REQUEST['redirect_to'] ) ? esc_url_raw( wp_unslash( $_REQUEST['redirect_to'] ) ) : '';
	// wp_validate_redirect() accepts '' as a valid (relative) location, so an
	// empty target must fall back explicitly or the redirect goes nowhere.
	$target = '' === $raw ? '' : wp_validate_redirect( $raw, '' );
	return '' === $target ? home_url( '/' ) : $target;
}

/**
 * Send a complete page and stop.
 *
 * @param string $title Page title.
 * @param string $body  Escaped HTML body.
 */
function jlife_auth_send_page( $title, $body ) {
	nocache_headers();
	header( 'Content-Type: text/html; charset=utf-8' );
	header( 'X-Robots-Tag: noindex, nofollow' );
	$hint = jlife_auth_in_app_browser()
		? '<p class="jl-hint">' . esc_html__( 'Tip: open this page in Safari or Chrome so you stay signed in. (Tap ••• then "Open in browser".)', 'jlife-auth' ) . '</p>'
		: '';
	echo '<!doctype html><html lang="' . esc_attr( str_replace( '_', '-', determine_locale() ) ) . '"><head><meta charset="utf-8">'
		. '<meta name="viewport" content="width=device-width, initial-scale=1"><meta name="robots" content="noindex, nofollow">'
		. '<title>' . esc_html( $title ) . '</title>'
		. '<style>body{font-family:system-ui,-apple-system,sans-serif;max-width:30rem;margin:0 auto;padding:1.25rem;line-height:1.5;color:#1d1d1f;background:#fff}'
		. 'label{display:block;margin:1rem 0 .25rem;font-weight:600}input[type=text],input[type=email],select{width:100%;box-sizing:border-box;padding:.75rem;font-size:1.1rem;border:1px solid #888;border-radius:.5rem}'
		. 'input[inputmode=numeric]{letter-spacing:.3em;font-size:1.4rem}button{margin-top:1.25rem;width:100%;padding:.9rem;font-size:1.1rem;border:0;border-radius:.5rem;background:#2b5cab;color:#fff}'
		. '.jl-err{background:#fde8e8;padding:.75rem;border-radius:.5rem}.jl-ok{background:#e7f5ea;padding:.75rem;border-radius:.5rem}.jl-hint{background:#fff6dc;padding:.75rem;border-radius:.5rem}'
		. 'fieldset{border:0;padding:0;margin:1rem 0 0}.jl-radio label{font-weight:400;margin:.4rem 0}</style></head><body>'
		. $hint . $body . '</body></html>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- body is built from escaped parts.
	exit;
}

/**
 * Message box.
 *
 * @param string $text  Message.
 * @param string $kind  jl-err|jl-ok.
 * @return string
 */
function jlife_auth_box( $text, $kind = 'jl-err' ) {
	return '' === $text ? '' : '<p class="' . esc_attr( $kind ) . '" role="alert">' . esc_html( $text ) . '</p>';
}

/**
 * The code-entry form.
 *
 * @param string $email Email the code went to.
 * @param string $error Error message.
 * @return string
 */
function jlife_auth_code_form( $email, $error = '' ) {
	return '<h1>' . esc_html__( 'Check your email', 'jlife-auth' ) . '</h1>'
		/* translators: %s: email address. */
		. '<p>' . esc_html( sprintf( __( 'If %s can sign in, we just sent it a 6-digit code.', 'jlife-auth' ), $email ) ) . '</p>'
		. jlife_auth_box( $error )
		. '<form method="post">' . wp_nonce_field( 'jlife_auth_verify', '_jlife_auth', true, false )
		. '<input type="hidden" name="step" value="verify"><input type="hidden" name="email" value="' . esc_attr( $email ) . '">'
		. '<input type="hidden" name="redirect_to" value="' . esc_attr( jlife_auth_redirect_target() ) . '">'
		. '<label for="jl-code">' . esc_html__( '6-digit code', 'jlife-auth' ) . '</label>'
		. '<input id="jl-code" type="text" name="code" inputmode="numeric" autocomplete="one-time-code" pattern="[0-9 ]*" maxlength="7" required autofocus>'
		. '<button type="submit">' . esc_html__( 'Sign in', 'jlife-auth' ) . '</button></form>';
}

/**
 * Handle a POSTed code: sign in and continue (to consent if needed).
 */
function jlife_auth_handle_verify() {
	$email  = isset( $_POST['email'] ) ? sanitize_email( wp_unslash( $_POST['email'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified by caller.
	$code   = isset( $_POST['code'] ) ? sanitize_text_field( wp_unslash( $_POST['code'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified by caller.
	$result = jlife_auth_complete( $email, $code );
	if ( is_wp_error( $result ) ) {
		jlife_auth_send_page( __( 'Sign in', 'jlife-auth' ), jlife_auth_code_form( $email, $result->get_error_message() ) );
	}
	jlife_auth_log_in( $result['user_id'] );
	$target = jlife_auth_needs_consent( $result['user_id'] )
		? add_query_arg( 'redirect_to', rawurlencode( jlife_auth_redirect_target() ), home_url( '/consent/' ) )
		: jlife_auth_redirect_target();
	wp_safe_redirect( $target );
	exit;
}

/**
 * Requester IP for rate limiting.
 *
 * @return string
 */
function jlife_auth_ip() {
	return isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
}

/**
 * /signin
 */
function jlife_auth_page_signin() {
	$step = isset( $_POST['step'] ) ? sanitize_key( wp_unslash( $_POST['step'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce checked per step below.
	if ( 'verify' === $step && check_admin_referer( 'jlife_auth_verify', '_jlife_auth' ) ) {
		jlife_auth_handle_verify();
	}
	$error = '';
	if ( 'request' === $step && check_admin_referer( 'jlife_auth_signin', '_jlife_auth' ) ) {
		$email = isset( $_POST['email'] ) ? sanitize_email( wp_unslash( $_POST['email'] ) ) : '';
		$sent  = jlife_auth_request_code( $email, 'signin', array(), jlife_auth_ip() );
		if ( ! is_wp_error( $sent ) ) {
			jlife_auth_send_page( __( 'Sign in', 'jlife-auth' ), jlife_auth_code_form( jlife_auth_normalize_email( $email ) ) );
		}
		$error = $sent->get_error_message();
	}
	jlife_auth_send_page(
		__( 'Sign in', 'jlife-auth' ),
		'<h1>' . esc_html__( 'Sign in', 'jlife-auth' ) . '</h1>'
		. '<p>' . esc_html__( 'Enter your email and we will send you a 6-digit code. No password needed.', 'jlife-auth' ) . '</p>'
		. jlife_auth_box( $error )
		. '<form method="post">' . wp_nonce_field( 'jlife_auth_signin', '_jlife_auth', true, false )
		. '<input type="hidden" name="step" value="request"><input type="hidden" name="redirect_to" value="' . esc_attr( jlife_auth_redirect_target() ) . '">'
		. '<label for="jl-email">' . esc_html__( 'Email', 'jlife-auth' ) . '</label>'
		. '<input id="jl-email" type="email" name="email" autocomplete="email" required autofocus>'
		. '<button type="submit">' . esc_html__( 'Send my code', 'jlife-auth' ) . '</button></form>'
		. '<p><a href="' . esc_url( home_url( '/join/' ) ) . '">' . esc_html__( 'New here? Join with your group invite code.', 'jlife-auth' ) . '</a></p>'
	);
}

/**
 * /join
 */
function jlife_auth_page_join() {
	$step = isset( $_POST['step'] ) ? sanitize_key( wp_unslash( $_POST['step'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce checked per step below.
	if ( 'verify' === $step && check_admin_referer( 'jlife_auth_verify', '_jlife_auth' ) ) {
		jlife_auth_handle_verify();
	}
	$error = '';
	$in    = array(
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- prefill from the invite link only.
		'invite_code' => isset( $_GET['code'] ) ? sanitize_text_field( wp_unslash( $_GET['code'] ) ) : '',
		'first_name'  => '',
		'email'       => '',
		'age_band'    => '',
		'locale'      => 'en_US',
	);
	if ( 'request' === $step && check_admin_referer( 'jlife_auth_join', '_jlife_auth' ) ) {
		foreach ( array_keys( $in ) as $k ) {
			$in[ $k ] = isset( $_POST[ $k ] ) ? sanitize_text_field( wp_unslash( $_POST[ $k ] ) ) : '';
		}
		$sent = jlife_auth_start_join( $in, jlife_auth_ip() );
		if ( ! is_wp_error( $sent ) ) {
			jlife_auth_send_page( __( 'Join', 'jlife-auth' ), jlife_auth_code_form( jlife_auth_normalize_email( $in['email'] ) ) );
		}
		$error = $sent->get_error_message();
	}
	$langs = '';
	foreach ( JLIFE_AUTH_LOCALES as $code => $label ) {
		$langs .= '<option value="' . esc_attr( $code ) . '"' . selected( $in['locale'], $code, false ) . '>' . esc_html( $label ) . '</option>';
	}
	jlife_auth_send_page(
		__( 'Join the Bible Marathon', 'jlife-auth' ),
		'<h1>' . esc_html__( 'Join the Bible Marathon', 'jlife-auth' ) . '</h1>'
		. jlife_auth_box( $error )
		. '<form method="post">' . wp_nonce_field( 'jlife_auth_join', '_jlife_auth', true, false ) . '<input type="hidden" name="step" value="request">'
		. '<label for="jl-invite">' . esc_html__( 'Group invite code', 'jlife-auth' ) . '</label>'
		. '<input id="jl-invite" type="text" name="invite_code" value="' . esc_attr( $in['invite_code'] ) . '" autocapitalize="characters" required>'
		. '<label for="jl-first">' . esc_html__( 'First name', 'jlife-auth' ) . '</label>'
		. '<input id="jl-first" type="text" name="first_name" value="' . esc_attr( $in['first_name'] ) . '" autocomplete="given-name" required>'
		. '<label for="jl-email">' . esc_html__( 'Email', 'jlife-auth' ) . '</label>'
		. '<input id="jl-email" type="email" name="email" value="' . esc_attr( $in['email'] ) . '" autocomplete="email" required>'
		. '<fieldset class="jl-radio"><legend><strong>' . esc_html__( 'Your age', 'jlife-auth' ) . '</strong></legend>'
		. '<label><input type="radio" name="age_band" value="adult"' . checked( $in['age_band'], 'adult', false ) . ' required> ' . esc_html__( '18 or older', 'jlife-auth' ) . '</label>'
		. '<label><input type="radio" name="age_band" value="minor"' . checked( $in['age_band'], 'minor', false ) . '> ' . esc_html__( '13 to 17', 'jlife-auth' ) . '</label>'
		. '<small>' . esc_html__( 'J-Life is for ages 13 and up.', 'jlife-auth' ) . '</small></fieldset>'
		. '<label for="jl-lang">' . esc_html__( 'Language', 'jlife-auth' ) . '</label><select id="jl-lang" name="locale">' . $langs . '</select>'
		. '<button type="submit">' . esc_html__( 'Send my code', 'jlife-auth' ) . '</button></form>'
		. '<p><a href="' . esc_url( home_url( '/signin/' ) ) . '">' . esc_html__( 'Already joined? Sign in.', 'jlife-auth' ) . '</a></p>'
	);
}

/**
 * /consent
 */
function jlife_auth_page_consent() {
	$user_id = get_current_user_id();
	if ( $user_id < 1 ) {
		wp_safe_redirect( home_url( '/signin/' ) );
		exit;
	}
	$error = '';
	// phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce checked here.
	if ( isset( $_POST['step'] ) && check_admin_referer( 'jlife_auth_consent', '_jlife_auth' ) ) {
		if ( ! empty( $_POST['agree'] ) ) {
			jlife_auth_record_consent( $user_id );
			wp_safe_redirect( jlife_auth_redirect_target() );
			exit;
		}
		$error = __( 'Please tick the box to continue.', 'jlife-auth' );
	}
	$points = array(
		__( 'Your SOAP entries are private. Only you can read them. Group leaders and organizers cannot see what you write.', 'jlife-auth' ),
		__( 'You choose what to share. When you share with your group, its members see the parts you picked, with your first name. You can remove a share at any time.', 'jlife-auth' ),
		__( 'Points come from participation: one point for each day you complete all four parts of SOAP on time. Points never depend on what you write. Organizers see points and counts, not your words.', 'jlife-auth' ),
		__( 'We will email you each day\'s reading. You can turn reminders off at any time.', 'jlife-auth' ),
		__( 'You can delete your account and everything you have written at any time.', 'jlife-auth' ),
	);
	$list   = '';
	foreach ( $points as $p ) {
		$list .= '<li>' . esc_html( $p ) . '</li>';
	}
	jlife_auth_send_page(
		__( 'Before you start', 'jlife-auth' ),
		'<h1>' . esc_html__( 'Before you start', 'jlife-auth' ) . '</h1><ul>' . $list . '</ul>'
		. jlife_auth_box( $error )
		. '<form method="post">' . wp_nonce_field( 'jlife_auth_consent', '_jlife_auth', true, false )
		. '<input type="hidden" name="step" value="agree"><input type="hidden" name="redirect_to" value="' . esc_attr( jlife_auth_redirect_target() ) . '">'
		. '<label><input type="checkbox" name="agree" value="1" required> ' . esc_html__( 'I am 13 or older, and I agree to take part on these terms.', 'jlife-auth' ) . '</label>'
		. '<button type="submit">' . esc_html__( 'Start the Marathon', 'jlife-auth' ) . '</button></form>'
	);
}

add_action( 'template_redirect', 'jlife_auth_route' );

/**
 * Dispatch the participant pages.
 */
function jlife_auth_route() {
	$page = (string) get_query_var( 'jlife_auth' );
	if ( 'signin' === $page ) {
		jlife_auth_page_signin();
	} elseif ( 'join' === $page ) {
		jlife_auth_page_join();
	} elseif ( 'consent' === $page ) {
		jlife_auth_page_consent();
	}
}
