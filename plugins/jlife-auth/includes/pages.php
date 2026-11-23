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
	// A seam for tests, which cannot survive the exit below: a callback may throw to stop here.
	do_action( 'jlife_auth_page_will_send', $title, $body );
	nocache_headers();
	header( 'Content-Type: text/html; charset=utf-8' );
	header( 'X-Robots-Tag: noindex, nofollow' );
	echo jlife_auth_page_html( $title, $body ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- body is built from escaped parts.
	exit;
}

/**
 * A complete sign-in page: language links, the body and the Help, Rules and
 * Privacy links at the foot.
 *
 * @param string $title Page title.
 * @param string $body  Escaped HTML body.
 * @return string
 */
function jlife_auth_page_html( $title, $body ) {
	$hint = jlife_auth_in_app_browser()
		? '<p class="jl-hint">' . esc_html__( 'Tip: open this page in Safari or Chrome so you stay signed in. (Tap ••• then "Open in browser".)', 'jlife-auth' ) . '</p>'
		: '';
	$lang = jlife_auth_language_links( false !== strpos( $body, 'id="jl-form"' ) );
	return '<!doctype html><html lang="' . esc_attr( str_replace( '_', '-', determine_locale() ) ) . '"><head><meta charset="utf-8">'
		. '<meta name="viewport" content="width=device-width, initial-scale=1"><meta name="robots" content="noindex, nofollow">'
		. '<title>' . esc_html( $title ) . '</title>'
		. '<style>body{font-family:system-ui,-apple-system,sans-serif;max-width:30rem;margin:0 auto;padding:1.25rem;line-height:1.5;color:#1d1d1f;background:#fff;display:flex;flex-direction:column}'
		. 'h1{overflow-wrap:anywhere}a:focus-visible,button:focus-visible,input:focus-visible,select:focus-visible{outline:3px solid #b45309;outline-offset:2px}'
		. 'label{display:block;margin:1rem 0 .25rem;font-weight:600}input[type=text],input[type=email],select{width:100%;box-sizing:border-box;padding:.75rem;font-size:1.1rem;border:1px solid #888;border-radius:.5rem}'
		. 'input[inputmode=numeric]{letter-spacing:.3em;font-size:1.4rem}button{margin-top:1.25rem;width:100%;padding:.9rem;font-size:1.1rem;border:0;border-radius:.5rem;background:#2b5cab;color:#fff}'
		. '.jl-err{background:#fde8e8;padding:.75rem;border-radius:.5rem}.jl-ok{background:#e7f5ea;padding:.75rem;border-radius:.5rem}.jl-hint{background:#fff6dc;padding:.75rem;border-radius:.5rem}'
		. 'button.jl-secondary{min-height:44px;background:#fff;color:#2b5cab;border:2px solid #2b5cab;font-size:1rem;margin-top:.75rem}'
		. 'fieldset{border:0;padding:0;margin:1rem 0 0}.jl-radio label{font-weight:400;margin:.4rem 0}'
		. '.jl-lang{order:-1;text-align:right;font-size:.9rem;margin:0}.jl-lang button{display:inline-block;width:auto;min-width:44px;min-height:44px;margin:0;padding:.5rem .6rem;font-size:.9rem;background:none;color:#2b5cab;text-decoration:underline;cursor:pointer}'
		. '.jl-lang a{display:inline-block;padding:.6rem .5rem}.jl-lang [aria-current]{font-weight:700;color:#1d1d1f;text-decoration:none}'
		. 'a.jl-btn{display:block;margin-top:1.25rem;padding:.9rem;text-align:center;border-radius:.5rem;background:#2b5cab;color:#fff;text-decoration:none;font-size:1.1rem}'
		. '.jl-help-links{margin-top:2rem;text-align:center;font-size:.875rem}.jl-help-links a{display:inline-block;padding:.5rem .4rem}</style></head><body>'
		// The language switch comes last in the markup so Enter in a field still presses the form's own button; CSS shows it first.
		. $hint . $body . jlife_auth_help_footer() . $lang . '</body></html>';
}

/**
 * The Help, Rules and Privacy links for the foot of the page (#65): the pages and
 * their language belong to jlife-challenges, so this asks it. Empty when none of
 * the three pages is set up and published.
 *
 * @return string Escaped HTML.
 */
function jlife_auth_help_footer() {
	return function_exists( 'jlife_challenges_help_footer' ) ? jlife_challenges_help_footer() : '';
}

/**
 * Carry a signed-out visitor's picked language (`?lang=`) onto a link to another
 * sign-in page, so "Already joined? Sign in." from the Vietnamese form stays
 * Vietnamese. Signed-in people see their own chosen language, so nothing is added.
 *
 * @param string $url Link.
 * @return string
 */
function jlife_auth_keep_lang( $url ) {
	if ( is_user_logged_in() ) {
		return $url;
	}
	$lang = jlife_auth_visitor_pick();
	return '' === $lang ? $url : add_query_arg( 'lang', $lang, $url );
}

/**
 * "English | Tiếng Việt" switch for a signed-out visitor, so someone who reads
 * Vietnamese can change the page before signing up. Signed-in people see their own
 * chosen language (D13), so they get none.
 *
 * On a page with a form (`$in_form`) the two are buttons that send that form to
 * itself (POST, `jl_relang`, no validation, nothing saved): the page comes back in
 * the other language with everything typed still in place, and the email address
 * never goes in the address bar. Without a form they are plain `?lang=` links, only
 * on a page view.
 *
 * @param bool $in_form Does the page have the form `#jl-form`.
 * @return string Escaped HTML.
 */
function jlife_auth_language_links( $in_form = false ) {
	if ( is_user_logged_in() ) {
		return '';
	}
	if ( ! $in_form && ( ! isset( $_SERVER['REQUEST_METHOD'] ) || 'GET' !== $_SERVER['REQUEST_METHOD'] ) ) {
		return '';
	}
	$current = 0 === strpos( determine_locale(), 'vi' ) ? 'vi' : 'en';
	$items   = array();
	foreach ( array(
		'en' => 'English',
		'vi' => 'Tiếng Việt',
	) as $code => $label ) {
		$mark = $code === $current ? ' aria-current="true"' : '';
		$url  = esc_url( add_query_arg( 'lang', $code ) );
		if ( $in_form ) {
			$items[] = '<button type="submit" form="jl-form" name="jl_relang" value="' . esc_attr( $code ) . '" formaction="' . $url . '" formnovalidate formmethod="post" lang="' . esc_attr( $code ) . '"' . $mark . '>' . esc_html( $label ) . '</button>';
		} else {
			$items[] = '<a href="' . $url . '" hreflang="' . esc_attr( $code ) . '" lang="' . esc_attr( $code ) . '"' . $mark . '>' . esc_html( $label ) . '</a>';
		}
	}
	return '<p class="jl-lang" role="group" aria-label="' . esc_attr__( 'Language', 'jlife-auth' ) . '">' . implode( ' | ', $items ) . '</p>';
}

/**
 * Is this POST only a language switch (the buttons above)? Nothing is processed or
 * saved then: the page is shown again, in the other language, with the typed values.
 *
 * @return bool
 */
function jlife_auth_is_relang() {
	// phpcs:ignore WordPress.Security.NonceVerification.Missing -- shows the same page again; nothing is read beyond a display language or changed.
	return isset( $_POST['jl_relang'] ) && '' !== jlife_auth_visitor_pick();
}

/**
 * The language to pre-select on the join form: the page's own.
 *
 * @return string en_US|vi
 */
function jlife_auth_default_form_locale() {
	return 0 === strpos( determine_locale(), 'vi' ) ? 'vi' : 'en_US';
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
 * The join details of a code that is waiting, as the join form's fields, so a
 * "Send a new code" from a join keeps them. Empty for a sign-in (or nothing waiting).
 *
 * @param string $email Normalized email.
 * @return array
 */
function jlife_auth_pending_join_fields( $email ) {
	$state = '' === $email ? false : get_transient( jlife_auth_code_key( jlife_auth_normalize_email( $email ) ) );
	if ( ! is_array( $state ) || 'join' !== ( $state['purpose'] ?? '' ) || ! is_array( $state['payload'] ?? null ) ) {
		return array();
	}
	$p = $state['payload'];
	return array(
		'mode'        => (string) ( $p['mode'] ?? '' ),
		'invite_code' => (string) ( $p['invite_code'] ?? '' ),
		'group_name'  => (string) ( $p['group_name'] ?? '' ),
		'first_name'  => (string) ( $p['first_name'] ?? '' ),
		'age_band'    => (string) ( $p['age_band'] ?? '' ),
		'locale'      => (string) ( $p['locale'] ?? '' ),
		'reminders'   => ! empty( $p['reminders'] ) ? '1' : '',
	);
}

/**
 * The "Send a new code" form: its own POST (nonce, email in the body, never in an
 * address). For a join it carries the join details, which are checked again as on
 * the first request.
 *
 * @param string $email Email the code went to.
 * @param array  $join  Join form fields, or empty for a sign-in.
 * @return string Escaped HTML.
 */
function jlife_auth_resend_form( $email, $join ) {
	$out = '<form method="post" id="jl-resend" class="jl-resend" novalidate>' . wp_nonce_field( 'jlife_auth_resend', '_jlife_resend', false, false )
		. '<input type="hidden" name="step" value="resend"><input type="hidden" name="email" value="' . esc_attr( $email ) . '">'
		. '<input type="hidden" name="jl_lang" value="' . esc_attr( 0 === strpos( determine_locale(), 'vi' ) ? 'vi' : 'en' ) . '">'
		. '<input type="hidden" name="redirect_to" value="' . esc_attr( jlife_auth_redirect_target() ) . '">';
	if ( ! empty( $join ) ) {
		$out .= '<input type="hidden" name="jl_join" value="1">';
		foreach ( array( 'mode', 'invite_code', 'group_name', 'first_name', 'age_band', 'locale', 'reminders' ) as $k ) {
			$out .= '<input type="hidden" name="' . esc_attr( $k ) . '" value="' . esc_attr( (string) ( $join[ $k ] ?? '' ) ) . '">';
		}
	}
	return $out . '<button type="submit" class="jl-secondary">' . esc_html__( 'Send a new code', 'jlife-auth' ) . '</button></form>';
}

/**
 * The code-entry form.
 *
 * @param string $email  Email the code went to.
 * @param string $error  Error message.
 * @param array  $join   Join form fields to keep for "Send a new code" (else read from the waiting code).
 * @param string $notice Status line, e.g. after a resend.
 * @return string
 */
function jlife_auth_code_form( $email, $error = '', $join = array(), $notice = '' ) {
	if ( empty( $join ) ) {
		$join = jlife_auth_pending_join_fields( $email );
	}
	return '<h1>' . esc_html__( 'Check your email', 'jlife-auth' ) . '</h1>'
		/* translators: %s: email address. */
		. '<p>' . esc_html( sprintf( __( 'If %s can sign in, we just sent it a 6-digit code.', 'jlife-auth' ), $email ) ) . '</p>'
		. '<p class="jl-hint">' . esc_html(
			sprintf(
				/* translators: %d: minutes the code lasts. */
				__( 'It usually arrives within a minute. Don’t see it? Check your Spam or Junk folder and mark it “Not spam”. The code works once and expires in %d minutes. If you ask for another code, use the newest email.', 'jlife-auth' ),
				(int) ( JLIFE_AUTH_CODE_TTL / MINUTE_IN_SECONDS )
			)
		) . '</p>'
		. jlife_auth_box( $notice, 'jl-ok' )
		. jlife_auth_box( $error )
		. '<form method="post" id="jl-form" novalidate>' . wp_nonce_field( 'jlife_auth_verify', '_jlife_auth', true, false )
		. '<input type="hidden" name="step" value="verify"><input type="hidden" name="email" value="' . esc_attr( $email ) . '">'
		. '<input type="hidden" name="jl_lang" value="' . esc_attr( 0 === strpos( determine_locale(), 'vi' ) ? 'vi' : 'en' ) . '">'
		. '<input type="hidden" name="redirect_to" value="' . esc_attr( jlife_auth_redirect_target() ) . '">'
		. '<label for="jl-code">' . esc_html__( '6-digit code', 'jlife-auth' ) . '</label>'
		. '<input id="jl-code" type="text" name="code" inputmode="numeric" autocomplete="one-time-code" pattern="[0-9 ]*" maxlength="7" required autofocus>'
		. '<button type="submit">' . esc_html__( 'Sign in', 'jlife-auth' ) . '</button></form>'
		. jlife_auth_resend_form( $email, $join );
}

/**
 * Handle "Send a new code": the same request path as the first one (so the same
 * limits, eligibility and non-disclosing page), then the code page again.
 */
function jlife_auth_handle_resend() {
	// phpcs:disable WordPress.Security.NonceVerification.Missing -- verified by caller.
	$email = isset( $_POST['email'] ) ? sanitize_email( wp_unslash( $_POST['email'] ) ) : '';
	$join  = array();
	if ( ! empty( $_POST['jl_join'] ) ) {
		foreach ( array( 'mode', 'invite_code', 'group_name', 'first_name', 'age_band', 'locale', 'reminders' ) as $k ) {
			$join[ $k ] = isset( $_POST[ $k ] ) ? sanitize_text_field( wp_unslash( $_POST[ $k ] ) ) : '';
		}
	}
	// phpcs:enable WordPress.Security.NonceVerification.Missing
	if ( ! empty( $join ) ) {
		$join['email'] = $email;
		$sent          = jlife_auth_start_join( $join, jlife_auth_ip() );
	} else {
		$sent = jlife_auth_request_code( $email, 'signin', array(), jlife_auth_ip() );
	}
	$title = ! empty( $join ) ? __( 'Join', 'jlife-auth' ) : __( 'Sign in', 'jlife-auth' );
	$email = jlife_auth_normalize_email( $email );
	if ( is_wp_error( $sent ) ) {
		jlife_auth_send_page( $title, jlife_auth_code_form( $email, $sent->get_error_message(), $join ) );
	}
	jlife_auth_send_page( $title, jlife_auth_code_form( $email, '', $join, __( 'We sent a new code.', 'jlife-auth' ) ) );
}

/**
 * Handle a POSTed code: sign in and continue (to consent if needed).
 */
function jlife_auth_handle_verify() {
	if ( jlife_auth_is_relang() ) {
		// Only the language button: show the code page again, in the other language. The code is not checked or used up.
		jlife_auth_send_page( __( 'Sign in', 'jlife-auth' ), jlife_auth_code_form( isset( $_POST['email'] ) ? sanitize_email( wp_unslash( $_POST['email'] ) ) : '' ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified by caller.
	}
	$email  = isset( $_POST['email'] ) ? sanitize_email( wp_unslash( $_POST['email'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified by caller.
	$code   = isset( $_POST['code'] ) ? sanitize_text_field( wp_unslash( $_POST['code'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified by caller.
	$result = jlife_auth_complete( $email, $code );
	if ( is_wp_error( $result ) ) {
		jlife_auth_send_page( __( 'Sign in', 'jlife-auth' ), jlife_auth_code_form( $email, $result->get_error_message() ) );
	}
	jlife_auth_log_in( $result['user_id'] );
	jlife_auth_refresh_translations(); // The page was first built for a visitor; from here it is the person's own language.
	$target = jlife_auth_needs_consent( $result['user_id'] )
		? add_query_arg( 'redirect_to', rawurlencode( jlife_auth_redirect_target() ), home_url( '/consent/' ) )
		: jlife_auth_redirect_target();
	if ( 'join' === $result['purpose'] && ( '' !== $result['notice'] || ! empty( $result['started'] ) || ! empty( $result['waiting'] ) ) ) {
		jlife_auth_send_page( __( 'Join', 'jlife-auth' ), jlife_auth_join_result_html( $result, $target ) );
	}
	wp_safe_redirect( $target );
	exit;
}

/**
 * The page between a successful code and the app, when there is something to
 * say: the new group's invite code and link (shown once), and/or why a join did
 * not happen (already in that group, at the 3-group limit, a minor cannot start
 * a group). The person is signed in either way. A group name appears only for
 * the group the person just started.
 *
 * @param array  $result   jlife_auth_complete() result.
 * @param string $next     Where "Continue" goes (consent or the app).
 * @return string Escaped HTML.
 */
function jlife_auth_join_result_html( $result, $next ) {
	$out = '';
	if ( ! empty( $result['waiting'] ) ) {
		$out .= '<h1>' . esc_html__( 'You are on the list', 'jlife-auth' ) . '</h1>'
			. '<p>' . esc_html__( 'Your account is ready. The Marathon starts Dec 20. We will sign you up for it, so there is nothing else to do. Sign in then with an emailed code.', 'jlife-auth' ) . '</p>';
	} elseif ( ! empty( $result['started'] ) ) {
		$out .= '<h1>' . esc_html__( 'Your group is ready', 'jlife-auth' ) . '</h1>'
			. '<p>' . esc_html__( 'Share the code or the link with the people you want to invite.', 'jlife-auth' ) . '</p>'
			. jlife_challenges_invite_reveal_html( (string) $result['started']['name'], (string) $result['started']['invite_code'] );
	} else {
		$out .= '<h1>' . esc_html__( 'You are signed in', 'jlife-auth' ) . '</h1>';
	}
	$out .= jlife_auth_box( (string) ( $result['notice'] ?? '' ), ! empty( $result['started'] ) ? 'jl-hint' : 'jl-err' );
	return $out . '<a class="jl-btn" href="' . esc_url( $next ) . '">' . esc_html__( 'Continue', 'jlife-auth' ) . '</a>';
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
	if ( 'resend' === $step && check_admin_referer( 'jlife_auth_resend', '_jlife_resend' ) ) {
		jlife_auth_handle_resend();
	}
	$error = '';
	$email = isset( $_POST['email'] ) ? sanitize_text_field( wp_unslash( $_POST['email'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- only echoed back into the form, escaped; a real request checks its nonce below.
	if ( 'request' === $step && jlife_auth_is_relang() ) {
		jlife_auth_send_page( __( 'Sign in', 'jlife-auth' ), jlife_auth_signin_body( '', $email ) ); // A language button: same page, other language.
	}
	if ( 'request' === $step && check_admin_referer( 'jlife_auth_signin', '_jlife_auth' ) ) {
		$email = sanitize_email( $email );
		$sent  = jlife_auth_request_code( $email, 'signin', array(), jlife_auth_ip() );
		if ( ! is_wp_error( $sent ) ) {
			jlife_auth_send_page( __( 'Sign in', 'jlife-auth' ), jlife_auth_code_form( jlife_auth_normalize_email( $email ) ) );
		}
		$error = $sent->get_error_message();
	}
	jlife_auth_send_page( __( 'Sign in', 'jlife-auth' ), jlife_auth_signin_body( $error, '' !== $error ? $email : '' ) );
}

/**
 * The /signin page body.
 *
 * @param string $error Error message.
 * @param string $email Email to keep in the field.
 * @return string Escaped HTML.
 */
function jlife_auth_signin_body( $error = '', $email = '' ) {
	return '<h1>' . esc_html__( 'Sign in', 'jlife-auth' ) . '</h1>'
		. '<p>' . esc_html__( 'Enter your email and we will send you a 6-digit code. No password needed.', 'jlife-auth' ) . '</p>'
		. jlife_auth_box( $error )
		. '<form method="post" id="jl-form" novalidate>' . wp_nonce_field( 'jlife_auth_signin', '_jlife_auth', true, false )
		. '<input type="hidden" name="step" value="request"><input type="hidden" name="redirect_to" value="' . esc_attr( jlife_auth_redirect_target() ) . '">'
		. '<input type="hidden" name="jl_lang" value="' . esc_attr( 0 === strpos( determine_locale(), 'vi' ) ? 'vi' : 'en' ) . '">'
		. '<label for="jl-email">' . esc_html__( 'Email', 'jlife-auth' ) . '</label>'
		. '<input id="jl-email" type="email" name="email" value="' . esc_attr( $email ) . '" autocomplete="email" required autofocus>'
		. '<button type="submit">' . esc_html__( 'Send my code', 'jlife-auth' ) . '</button></form>'
		. '<p><a href="' . esc_url( jlife_auth_keep_lang( home_url( '/join/' ) ) ) . '">' . esc_html__( 'New here? Join with a group invite code, read on your own, or start a group.', 'jlife-auth' ) . '</a></p>';
}

/**
 * The join form for one of the three ways in.
 *
 * - invite (default, /join/): a group invite code, then name, email, age, language.
 * - solo (/join/?mode=solo, "Read on my own"): the same without the code; you can
 *   join a group later.
 * - start (/join/?mode=start, "Start a group"): adults only; a group name instead
 *   of a code. The code and link come after the emailed code is checked.
 *
 * @param string $mode  invite|solo|start.
 * @param array  $input Values to keep in the fields.
 * @param string $error Error message.
 * @return string Escaped HTML.
 */
function jlife_auth_join_form( $mode, $input, $error = '' ) {
	$mode  = jlife_auth_join_mode( $mode );
	$in    = wp_parse_args(
		$input,
		array(
			'invite_code' => '',
			'group_name'  => '',
			'first_name'  => '',
			'email'       => '',
			'age_band'    => '',
			'locale'      => jlife_auth_default_form_locale(),
			'reminders'   => '',
		)
	);
	$langs = '';
	foreach ( JLIFE_AUTH_LOCALES as $code => $label ) {
		$langs .= '<option value="' . esc_attr( $code ) . '"' . selected( $in['locale'], $code, false ) . '>' . esc_html( $label ) . '</option>';
	}
	$join = jlife_auth_keep_lang( home_url( '/join/' ) );
	if ( 'solo' === $mode ) {
		$head = '<h1>' . esc_html__( 'Read the Bible Marathon on my own', 'jlife-auth' ) . '</h1>'
			. '<p>' . esc_html__( 'No group needed. Your SOAP entries are private. You can join a group later with an invite code.', 'jlife-auth' ) . '</p>';
	} elseif ( 'wait' === $mode ) {
		$head = '<h1>' . esc_html__( "I'll start Dec 20", 'jlife-auth' ) . '</h1>'
			. '<p>' . esc_html__( 'Skip the practice week. We will create your account now and sign you up for the Marathon that starts Dec 20. Nothing is shared and you will not be emailed until you choose.', 'jlife-auth' ) . '</p>';
	} elseif ( 'start' === $mode ) {
		$head = '<h1>' . esc_html__( 'Start a group', 'jlife-auth' ) . '</h1>'
			. '<p>' . esc_html__( 'For adults (18 or older). Name your group, and you will get an invite code and link to share with friends or the people you mentor. As the starter you can make a new code later; you do not see anyone else\'s SOAP.', 'jlife-auth' ) . '</p>';
	} else {
		$head = '<h1>' . esc_html__( 'Join the Bible Marathon', 'jlife-auth' ) . '</h1>';
	}
	$code  = 'invite' === $mode ? '<label for="jl-invite">' . esc_html__( 'Group invite code', 'jlife-auth' ) . '</label>'
		. '<input id="jl-invite" type="text" name="invite_code" value="' . esc_attr( $in['invite_code'] ) . '" autocapitalize="characters" required>' : '';
	$code .= 'start' === $mode ? '<label for="jl-group">' . esc_html__( 'Group name', 'jlife-auth' ) . '</label>'
		. '<input id="jl-group" type="text" name="group_name" value="' . esc_attr( $in['group_name'] ) . '" maxlength="' . (int) JLIFE_CHALLENGES_GROUP_NAME_MAX . '" autocomplete="off" required>' : '';

	// The other two ways in.
	$links = array(
		'invite' => '<a href="' . esc_url( $join ) . '">' . esc_html__( 'I have an invite code', 'jlife-auth' ) . '</a>',
		'solo'   => '<a href="' . esc_url( add_query_arg( 'mode', 'solo', $join ) ) . '">' . esc_html__( 'Read on my own', 'jlife-auth' ) . '</a>',
		'start'  => '<a href="' . esc_url( add_query_arg( 'mode', 'start', $join ) ) . '">' . esc_html__( 'Start a group', 'jlife-auth' ) . '</a>',
	);

	$links['wait'] = '<a href="' . esc_url( add_query_arg( 'mode', 'wait', $join ) ) . '">' . esc_html__( "I'll start Dec 20", 'jlife-auth' ) . '</a>';
	if ( ! jlife_auth_wait_offered() ) {
		unset( $links['wait'] );
	}
	unset( $links[ $mode ] );
	$swap = '<p>' . implode( ' · ', $links ) . '</p>';
	return $head
		. jlife_auth_box( $error )
		. '<form method="post" id="jl-form" novalidate>' . wp_nonce_field( 'jlife_auth_join', '_jlife_auth', true, false ) . '<input type="hidden" name="step" value="request">'
		. '<input type="hidden" name="mode" value="' . esc_attr( $mode ) . '">'
		. $code
		. '<label for="jl-first">' . esc_html__( 'First name', 'jlife-auth' ) . '</label>'
		. '<input id="jl-first" type="text" name="first_name" value="' . esc_attr( $in['first_name'] ) . '" autocomplete="given-name" required>'
		. '<label for="jl-email">' . esc_html__( 'Email', 'jlife-auth' ) . '</label>'
		. '<input id="jl-email" type="email" name="email" value="' . esc_attr( $in['email'] ) . '" autocomplete="email" required>'
		. '<fieldset class="jl-radio"><legend><strong>' . esc_html__( 'Your age', 'jlife-auth' ) . '</strong></legend>'
		. '<label><input type="radio" name="age_band" value="adult"' . checked( $in['age_band'], 'adult', false ) . ' required> ' . esc_html__( '18 or older', 'jlife-auth' ) . '</label>'
		. '<label><input type="radio" name="age_band" value="minor"' . checked( $in['age_band'], 'minor', false ) . '> ' . esc_html__( '13 to 17', 'jlife-auth' ) . '</label>'
		. '<small>' . esc_html__( 'J-Life is for ages 13 and up.', 'jlife-auth' ) . '</small></fieldset>'
		. '<label for="jl-lang">' . esc_html__( 'Language', 'jlife-auth' ) . '</label><select id="jl-lang" name="locale" aria-describedby="jl-lang-note">' . $langs . '</select>'
		. '<small id="jl-lang-note">' . esc_html__( 'This is the language of this app and of your emails.', 'jlife-auth' ) . '</small>'
		. ( 'wait' === $mode || ! jlife_auth_reminder_optin_offered() ? '' : '<label><input type="checkbox" name="reminders" value="1"' . checked( '' !== (string) $in['reminders'], true, false ) . '> ' . esc_html__( 'Email me each morning\'s reading', 'jlife-auth' ) . '</label>'
		. '<small>' . esc_html__( 'Optional. We write in the language you chose above. You can turn it off at any time.', 'jlife-auth' ) . '</small>' )
		. '<button type="submit">' . esc_html__( 'Send my code', 'jlife-auth' ) . '</button></form>'
		// With JavaScript, choosing a language in the field also switches this page at once (it presses the language button); without it, the page follows after the next step.
		. '<script>(function(){var s=document.getElementById("jl-lang");if(s){s.addEventListener("change",function(){var b=document.querySelector(\'button[name=jl_relang][value="\'+(s.value==="vi"?"vi":"en")+\'"]\');if(b){b.click();}});}})();</script>'
		. $swap
		. '<p><a href="' . esc_url( jlife_auth_keep_lang( home_url( '/signin/' ) ) ) . '">' . esc_html__( 'Already joined? Sign in.', 'jlife-auth' ) . '</a></p>';
}

/**
 * /join (invite code) and /join?mode=solo (Read on my own).
 */
function jlife_auth_page_join() {
	$step = isset( $_POST['step'] ) ? sanitize_key( wp_unslash( $_POST['step'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce checked per step below.
	if ( 'verify' === $step && check_admin_referer( 'jlife_auth_verify', '_jlife_auth' ) ) {
		jlife_auth_handle_verify();
	}
	if ( 'resend' === $step && check_admin_referer( 'jlife_auth_resend', '_jlife_resend' ) ) {
		jlife_auth_handle_resend();
	}
	$error = '';
	$in    = array(
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- prefill from the invite link only.
		'invite_code' => isset( $_GET['code'] ) ? sanitize_text_field( wp_unslash( $_GET['code'] ) ) : '',
		'group_name'  => '',
		'first_name'  => '',
		'email'       => '',
		'age_band'    => '',
		'locale'      => jlife_auth_default_form_locale(),
		'reminders'   => '',
	);
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- a page selector; the POST below re-reads it with its nonce.
	$mode = jlife_auth_join_mode( isset( $_GET['mode'] ) ? sanitize_key( wp_unslash( $_GET['mode'] ) ) : '' );
	if ( 'request' === $step && check_admin_referer( 'jlife_auth_join', '_jlife_auth' ) ) {
		foreach ( array_keys( $in ) as $k ) {
			$in[ $k ] = isset( $_POST[ $k ] ) ? sanitize_text_field( wp_unslash( $_POST[ $k ] ) ) : '';
		}
		$mode       = jlife_auth_join_mode( isset( $_POST['mode'] ) ? sanitize_key( wp_unslash( $_POST['mode'] ) ) : '' );
		$in['mode'] = $mode;
		if ( jlife_auth_is_relang() ) {
			// Only a language button: show the form again as typed; the language field follows the page. Nothing is sent or saved.
			$in['locale'] = jlife_auth_default_form_locale();
		} else {
			$sent = jlife_auth_start_join( $in, jlife_auth_ip() );
			if ( ! is_wp_error( $sent ) ) {
				jlife_auth_send_page( __( 'Join', 'jlife-auth' ), jlife_auth_code_form( jlife_auth_normalize_email( $in['email'] ) ) );
			}
			$error = $sent->get_error_message();
		}
	}
	$titles = array(
		'solo'   => __( 'Read on my own', 'jlife-auth' ),
		'start'  => __( 'Start a group', 'jlife-auth' ),
		'invite' => __( 'Join the Bible Marathon', 'jlife-auth' ),
		'wait'   => __( "I'll start Dec 20", 'jlife-auth' ),
	);
	jlife_auth_send_page( $titles[ $mode ], jlife_auth_join_form( $mode, $in, $error ) );
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
	jlife_auth_send_page( __( 'Before you start', 'jlife-auth' ), jlife_auth_consent_body( $error ) );
}

/**
 * The /consent page body (the wording is versioned: JLIFE_AUTH_CONSENT_VERSION).
 *
 * @param string $error Error message.
 * @return string Escaped HTML.
 */
function jlife_auth_consent_body( $error = '' ) {
	$points = array(
		__( 'Your SOAP entries are private. Only you can read them. Group leaders and organizers cannot see what you write.', 'jlife-auth' ),
		__( 'Entries are private by default. You choose what to share: when you tick a group\'s box as you save, that group\'s members see the whole entry, including your Prayer, with your first name.', 'jlife-auth' ),
		__( 'You can update or remove a share at any time on the share page (/share).', 'jlife-auth' ),
		__( 'Points come from participation: one point for each day you complete all four parts of SOAP on time. Points never depend on what you write. Organizers see points and counts, not your words.', 'jlife-auth' ),
		__( 'If you choose daily reminders, we email you each morning\'s reading. You can turn them off at any time from My Marathon or the link in any reminder.', 'jlife-auth' ),
		__( 'You can delete your account and everything you have written at any time.', 'jlife-auth' ),
	);
	$list   = '';
	foreach ( $points as $p ) {
		$list .= '<li>' . esc_html( $p ) . '</li>';
	}
	// A link to the full privacy summary, only when that page is published (#65).
	$privacy = function_exists( 'jlife_challenges_help_page_url' ) ? jlife_challenges_help_page_url( 'privacy' ) : '';
	$summary = '' === $privacy ? '' : '<p><a href="' . esc_url( $privacy ) . '">' . esc_html__( 'Read the full privacy summary', 'jlife-auth' ) . '</a></p>';
	return '<h1>' . esc_html__( 'Before you start', 'jlife-auth' ) . '</h1><ul>' . $list . '</ul>' . $summary
		. jlife_auth_box( $error )
		. '<form method="post">' . wp_nonce_field( 'jlife_auth_consent', '_jlife_auth', true, false )
		. '<input type="hidden" name="step" value="agree"><input type="hidden" name="redirect_to" value="' . esc_attr( jlife_auth_redirect_target() ) . '">'
		. '<label><input type="checkbox" name="agree" value="1" required> ' . esc_html__( 'I am 13 or older, and I agree to take part on these terms.', 'jlife-auth' ) . '</label>'
		. '<button type="submit">' . esc_html__( 'Start the Marathon', 'jlife-auth' ) . '</button></form>';
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
