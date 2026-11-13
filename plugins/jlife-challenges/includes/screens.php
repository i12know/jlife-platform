<?php
/**
 * Participant screens (M6, #61): /today, /soap, /marathon, and the sign-out
 * confirmation /signout (#89).
 *
 * Read → SOAP → Submit → Repeat. Scripture comes first; the score is
 * secondary; missed days are shown without shame. Mobile-first, works
 * without JavaScript (autosave is an enhancement). Every output is escaped.
 *
 * @package jlife-challenges
 */

defined( 'ABSPATH' ) || exit;

add_action( 'init', 'jlife_challenges_add_rewrites' );

/**
 * Routes.
 */
function jlife_challenges_add_rewrites() {
	add_rewrite_rule( '^(today|soap|marathon|signout)/?$', 'index.php?jlife_screen=$matches[1]', 'top' );
}

add_filter( 'query_vars', 'jlife_challenges_query_vars' );

/**
 * Register the route query var.
 *
 * @param array $vars Query vars.
 * @return array
 */
function jlife_challenges_query_vars( $vars ) {
	$vars[] = 'jlife_screen';
	return $vars;
}

/**
 * Field labels and short guidance, in SOAP order.
 *
 * @return array<string, array{0:string,1:string}>
 */
function jlife_challenges_field_labels() {
	return array(
		'scripture'   => array( __( 'S — Scripture', 'jlife-challenges' ), __( 'Write the verse or reference that stood out to you.', 'jlife-challenges' ) ),
		'observation' => array( __( 'O — Observation', 'jlife-challenges' ), __( 'What does it say? What do you notice about Jesus?', 'jlife-challenges' ) ),
		'application' => array( __( 'A — Application', 'jlife-challenges' ), __( 'What will you believe, change, or do today?', 'jlife-challenges' ) ),
		'prayer'      => array( __( 'P — Prayer', 'jlife-challenges' ), __( 'Talk to God about what you read.', 'jlife-challenges' ) ),
	);
}

/**
 * Format a Y-m-d date for display.
 *
 * @param string $ymd Date.
 * @return string
 */
function jlife_challenges_format_date( $ymd ) {
	return date_i18n( (string) get_option( 'date_format' ), (int) strtotime( $ymd . ' 12:00:00' ) );
}

/**
 * Send a complete participant page and stop.
 *
 * @param string $title  Page title.
 * @param string $body   Escaped HTML.
 * @param string $active Active nav item.
 * @param string $script Optional script handle file ('soap').
 */
function jlife_challenges_send_page( $title, $body, $active = '', $script = '' ) {
	nocache_headers();
	header( 'Content-Type: text/html; charset=utf-8' );
	header( 'X-Robots-Tag: noindex, nofollow' );
	$base = plugins_url( 'assets/', dirname( __DIR__ ) . '/jlife-challenges.php' );
	$nav  = '';
	foreach ( array(
		'today'    => __( 'Today', 'jlife-challenges' ),
		'marathon' => __( 'My Marathon', 'jlife-challenges' ),
	) as $slug => $label ) {
		$nav .= '<a href="' . esc_url( home_url( '/' . $slug . '/' ) ) . '"' . ( $slug === $active ? ' aria-current="page"' : '' ) . '>' . esc_html( $label ) . '</a>';
	}
	// Sign out asks first (#89): the link opens a confirmation page, which the
	// nav script turns into an in-page dialog when JavaScript is available.
	$day  = isset( $_GET['day'] ) ? sanitize_key( wp_unslash( $_GET['day'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only day selector.
	$back = 'soap' === $script ? 'soap' : $active;
	$here = jlife_challenges_screen_url( $back, $day );
	$ask  = add_query_arg(
		array_filter(
			array(
				'back' => $back,
				'day'  => $day,
			)
		),
		home_url( '/signout/' )
	);
	$nav .= '<a class="jl-signout" href="' . esc_url( $ask ) . '">' . esc_html__( 'Sign out', 'jlife-challenges' ) . '</a>';

	$dialog = 'signout' === $active ? '' : '<dialog class="jl-dialog" id="jl-signout-dialog" aria-labelledby="jl-signout-title"><div class="jl-dialog-body">' . jlife_challenges_signout_prompt( $here, true ) . '</div></dialog>';
	$html   = '<!doctype html><html lang="' . esc_attr( str_replace( '_', '-', determine_locale() ) ) . '"><head><meta charset="utf-8">'
		. '<meta name="viewport" content="width=device-width, initial-scale=1"><meta name="robots" content="noindex, nofollow">'
		. '<title>' . esc_html( $title ) . '</title>'
		. '<link rel="stylesheet" href="' . esc_url( $base . 'screens.css?v=2' ) . '">' // phpcs:ignore WordPress.WP.EnqueuedResources.NonEnqueuedStylesheet -- standalone page without wp_head.
		. '</head><body><nav class="jl-nav">' . $nav . '</nav><main>' . $body . '</main>' . $dialog
		. ( '' !== $dialog ? '<script src="' . esc_url( $base . 'nav.js?v=1' ) . '"></script>' : '' ) // phpcs:ignore WordPress.WP.EnqueuedResources.NonEnqueuedScript -- standalone page without wp_footer.
		. ( 'soap' === $script ? '<script src="' . esc_url( $base . 'soap.js?v=1' ) . '"></script>' : '' ) // phpcs:ignore WordPress.WP.EnqueuedResources.NonEnqueuedScript -- standalone page without wp_footer.
		. '</body></html>';
	echo $html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built from escaped parts.
	exit;
}

/**
 * URL of a participant screen, optionally for one day. Unknown screens give Today.
 *
 * @param string $screen 'today', 'soap' or 'marathon'.
 * @param string $day    Day key, or ''.
 * @return string
 */
function jlife_challenges_screen_url( $screen, $day = '' ) {
	$screen = in_array( $screen, array( 'today', 'soap', 'marathon' ), true ) ? $screen : 'today';
	$url    = home_url( '/' . $screen . '/' );
	return '' !== $day ? add_query_arg( 'day', $day, $url ) : $url;
}

/**
 * The Cancel / Sign out prompt, shared by the in-page dialog and the
 * no-JavaScript confirmation page (#89).
 *
 * @param string $back      Where Cancel returns to.
 * @param bool   $in_dialog True inside <dialog>: Cancel just closes it.
 * @return string
 */
function jlife_challenges_signout_prompt( $back, $in_dialog ) {
	$cancel = $in_dialog
		? '<form method="dialog"><button class="jl-button" value="cancel" autofocus>' . esc_html__( 'Cancel', 'jlife-challenges' ) . '</button></form>'
		: '<a class="jl-button" href="' . esc_url( $back ) . '">' . esc_html__( 'Cancel', 'jlife-challenges' ) . '</a>';
	return '<h2 id="jl-signout-title">' . esc_html__( 'Sign out of J-Life?', 'jlife-challenges' ) . '</h2>'
		. '<p>' . esc_html__( 'You will need a new email code to sign back in.', 'jlife-challenges' ) . '</p>'
		. '<div class="jl-actions">' . $cancel
		. '<a class="jl-button jl-primary" href="' . esc_url( wp_logout_url( home_url( '/signin/' ) ) ) . '">' . esc_html__( 'Sign out', 'jlife-challenges' ) . '</a></div>';
}

/**
 * /signout: confirmation page. Signed-out visitors go to the sign-in screen.
 */
function jlife_challenges_screen_signout() {
	if ( ! is_user_logged_in() ) {
		wp_safe_redirect( home_url( '/signin/' ) );
		exit;
	}
	// phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only return target, rebuilt from a whitelist.
	$back = jlife_challenges_screen_url(
		isset( $_GET['back'] ) ? sanitize_key( wp_unslash( $_GET['back'] ) ) : '',
		isset( $_GET['day'] ) ? sanitize_key( wp_unslash( $_GET['day'] ) ) : ''
	);
	// phpcs:enable WordPress.Security.NonceVerification.Recommended
	jlife_challenges_send_page( __( 'Sign out', 'jlife-challenges' ), '<section class="jl-confirm">' . jlife_challenges_signout_prompt( $back, false ) . '</section>', 'signout' );
}

/**
 * The reading block: passages, Bible links (participant's language first),
 * optional parallels collapsed.
 *
 * @param array $view Day view.
 * @return string
 */
function jlife_challenges_render_reading( $view ) {
	$reading = $view['reading'];
	if ( ! $reading || empty( $reading['day'] ) ) {
		return '<p>' . esc_html__( 'The reading for this day is not loaded yet.', 'jlife-challenges' ) . '</p>';
	}
	$day    = $reading['day'];
	$config = $reading['plan']['links_config'] ?? array();
	$out    = '<section class="jl-reading"><h2>' . esc_html( (string) $day['title'] ) . '</h2><ul class="jl-passages">';
	foreach ( (array) $day['passages'] as $p ) {
		$out .= '<li>' . esc_html( (string) $p['display'] ) . '</li>';
	}
	$out .= '</ul>' . jlife_challenges_render_bible_links( $day, $config, $view['languages'] );
	if ( ! empty( $reading['supplemental']['day'] ) ) {
		$supp = $reading['supplemental'];
		$out .= '<details class="jl-optional"><summary>' . esc_html__( 'Optional: parallel readings for deeper study', 'jlife-challenges' ) . '</summary><p>'
			. esc_html__( 'Optional. Reading these does not change your points.', 'jlife-challenges' ) . '</p><ul>';
		foreach ( (array) $supp['day']['passages'] as $p ) {
			$out .= '<li>' . esc_html( (string) $p['display'] ) . '</li>';
		}
		$out .= '</ul>' . jlife_challenges_render_bible_links( $supp['day'], $supp['plan']['links_config'] ?? $config, $view['languages'] ) . '</details>';
	}
	return $out . '</section>';
}

/**
 * Bible links for one day, one block per language in the participant's order:
 * a single button when the provider opens every passage on one page (Bible
 * Gateway), otherwise one chip per passage.
 *
 * @param array    $day       Day data with 'links'.
 * @param array    $config    Language => provider config (labels).
 * @param string[] $languages Language codes, preferred first.
 * @return string
 */
function jlife_challenges_render_bible_links( $day, $config, $languages ) {
	$out = '';
	foreach ( $languages as $lang ) {
		$links = $day['links'][ $lang ] ?? null;
		if ( ! $links ) {
			continue;
		}
		/* translators: 1: language name, 2: Bible version. */
		$out .= '<div class="jl-links" lang="' . esc_attr( $lang ) . '"><h3>' . esc_html( sprintf( __( 'Read in %1$s (%2$s)', 'jlife-challenges' ), (string) ( $config[ $lang ]['label'] ?? $lang ), (string) $links['version'] ) ) . '</h3>';
		if ( ! empty( $links['combined']['url'] ) ) {
			$out .= '<a class="jl-button" href="' . esc_url( $links['combined']['url'] ) . '" target="_blank" rel="noopener">' . esc_html__( "Open all of today's passages", 'jlife-challenges' ) . '</a>';
		} else {
			$out .= '<div class="jl-chips">';
			foreach ( (array) $links['passages'] as $l ) {
				$out .= '<a class="jl-chip" href="' . esc_url( (string) $l['url'] ) . '" target="_blank" rel="noopener">' . esc_html( (string) $l['label'] ) . '</a>';
			}
			$out .= '</div>';
		}
		$out .= '</div>';
	}
	return $out;
}

/**
 * One-line status for a day.
 *
 * @param array $view Day view.
 * @return string
 */
function jlife_challenges_status_text( $view ) {
	switch ( $view['status'] ) {
		case 'earned':
			return __( 'Done — 1 point earned. Well done!', 'jlife-challenges' );
		case 'late_no_point':
			return __( 'Done. It was after the deadline, so no point this time — your reflection is saved.', 'jlife-challenges' );
		case 'draft':
			return __( 'Draft saved. Finish all four parts and submit.', 'jlife-challenges' );
		default:
			return $view['on_time']
				? __( 'Not started yet.', 'jlife-challenges' )
				: __( 'You can still write this one for your own growth (no point after the deadline).', 'jlife-challenges' );
	}
}

/**
 * Require a signed-in, consented, enrolled participant (or redirect).
 *
 * @return int User ID.
 */
function jlife_challenges_require_participant() {
	$user_id = get_current_user_id();
	$here    = home_url( '/' . (string) get_query_var( 'jlife_screen' ) . '/' );
	if ( isset( $_GET['day'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only day selector.
		$here = add_query_arg( 'day', sanitize_key( wp_unslash( $_GET['day'] ) ), $here ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only day selector.
	}
	if ( $user_id < 1 ) {
		wp_safe_redirect( add_query_arg( 'redirect_to', rawurlencode( $here ), home_url( '/signin/' ) ) );
		exit;
	}
	if ( function_exists( 'jlife_auth_needs_consent' ) && jlife_auth_needs_consent( $user_id ) ) {
		wp_safe_redirect( add_query_arg( 'redirect_to', rawurlencode( $here ), home_url( '/consent/' ) ) );
		exit;
	}
	return $user_id;
}

/**
 * Pages that do not have a day to show.
 *
 * @param array $view Day view.
 * @return string|null HTML, or null when the view has a day.
 */
function jlife_challenges_render_no_day( $view ) {
	switch ( $view['state'] ) {
		case 'not_enrolled':
			return '<h1>' . esc_html__( 'Welcome', 'jlife-challenges' ) . '</h1><p>'
				. esc_html__( 'You are not in a group yet. Open the invite link your group shared with you to join.', 'jlife-challenges' ) . '</p>';
		case 'before':
			/* translators: %s: start date. */
			return '<h1>' . esc_html__( 'Almost time', 'jlife-challenges' ) . '</h1><p>' . esc_html( sprintf( __( 'The Marathon starts on %s. See you on Day 1!', 'jlife-challenges' ), jlife_challenges_format_date( $view['start_date'] ) ) ) . '</p>';
		case 'after':
			return '<h1>' . esc_html__( 'The Marathon is complete', 'jlife-challenges' ) . '</h1><p>'
				. esc_html__( 'Thank you for reading with us. Your SOAP entries stay here for you.', 'jlife-challenges' ) . '</p>';
		case 'unavailable':
			return '<h1>' . esc_html__( 'Not available', 'jlife-challenges' ) . '</h1><p>' . esc_html__( 'That day has not opened yet.', 'jlife-challenges' ) . '</p>';
	}
	return null;
}

/**
 * /today
 */
function jlife_challenges_screen_today() {
	$user_id = jlife_challenges_require_participant();
	$view    = jlife_challenges_day_view( $user_id );
	$none    = jlife_challenges_render_no_day( $view );
	if ( null !== $none ) {
		jlife_challenges_send_page( __( 'Today', 'jlife-challenges' ), $none, 'today' );
	}
	/* translators: 1: day number, 2: date. */
	$head = '<p class="jl-day">' . esc_html( sprintf( __( 'Day %1$d · %2$s', 'jlife-challenges' ), $view['index'], jlife_challenges_format_date( $view['date'] ) ) ) . '</p>';
	$soap = add_query_arg( 'day', $view['day_key'], home_url( '/soap/' ) );
	$cta  = in_array( $view['status'], array( 'earned', 'late_no_point' ), true )
		? __( 'View or edit my SOAP', 'jlife-challenges' )
		: ( 'draft' === $view['status'] ? __( 'Continue my SOAP', 'jlife-challenges' ) : __( 'Write my SOAP', 'jlife-challenges' ) );
	$body = $head . jlife_challenges_render_reading( $view )
		. '<section class="jl-status"><p>' . esc_html( jlife_challenges_status_text( $view ) ) . '</p>'
		. '<a class="jl-button jl-primary" href="' . esc_url( $soap ) . '">' . esc_html( $cta ) . '</a>'
		/* translators: 1: points, 2: number of days. */
		. '<p class="jl-score">' . esc_html( sprintf( __( '%1$d of %2$d', 'jlife-challenges' ), $view['points'], $view['day_count'] ) ) . '</p></section>';
	jlife_challenges_send_page( __( 'Today', 'jlife-challenges' ), $body, 'today' );
}

/**
 * Handle a SOAP form POST. Returns an error message to show, or redirects.
 *
 * @param int   $user_id Participant.
 * @param array $view    Day view.
 * @return string Error message ('' if none).
 */
function jlife_challenges_handle_soap_post( $user_id, $view ) {
	$fields = array();
	foreach ( JLIFE_CHALLENGES_FIELDS as $f ) {
		$fields[ $f ] = isset( $_POST[ $f ] ) ? sanitize_textarea_field( wp_unslash( $_POST[ $f ] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified by the caller.
	}
	$action = isset( $_POST['jl_action'] ) ? sanitize_key( wp_unslash( $_POST['jl_action'] ) ) : 'submit'; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified by the caller.
	$cid    = (int) $view['campaign']['id'];
	if ( 'draft' === $action ) {
		$r = jlife_challenges_save_draft( $user_id, $cid, $view['day_key'], $fields );
		if ( ! is_wp_error( $r ) ) {
			wp_safe_redirect( add_query_arg( 'day', $view['day_key'], home_url( '/soap/' ) ) );
			exit;
		}
	} else {
		$r = jlife_challenges_submit( $user_id, $cid, $view['day_key'], $fields );
		if ( ! is_wp_error( $r ) ) {
			wp_safe_redirect(
				add_query_arg(
					array(
						'day'  => $view['day_key'],
						'done' => 1,
					),
					home_url( '/soap/' )
				)
			);
			exit;
		}
	}
	return $r->get_error_message();
}

/**
 * /soap?day=d###
 */
function jlife_challenges_screen_soap() {
	$user_id = jlife_challenges_require_participant();
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only day selector.
	$day_key = isset( $_GET['day'] ) ? sanitize_key( wp_unslash( $_GET['day'] ) ) : null;
	$view    = jlife_challenges_day_view( $user_id, $day_key );
	$none    = jlife_challenges_render_no_day( $view );
	if ( null !== $none ) {
		jlife_challenges_send_page( __( 'SOAP', 'jlife-challenges' ), $none, 'today' );
	}
	$error  = '';
	$values = array();
	if ( isset( $_POST['jl_action'] ) && check_admin_referer( 'jlife_soap_' . $view['day_key'], '_jlife_soap' ) ) {
		$error = jlife_challenges_handle_soap_post( $user_id, $view );
		foreach ( JLIFE_CHALLENGES_FIELDS as $f ) {
			$values[ $f ] = isset( $_POST[ $f ] ) ? sanitize_textarea_field( wp_unslash( $_POST[ $f ] ) ) : '';
		}
	} elseif ( $view['entry'] ) {
		foreach ( JLIFE_CHALLENGES_FIELDS as $f ) {
			$values[ $f ] = (string) $view['entry'][ $f ];
		}
	}

	/* translators: 1: day number, 2: date. */
	$body = '<p class="jl-day">' . esc_html( sprintf( __( 'Day %1$d · %2$s', 'jlife-challenges' ), $view['index'], jlife_challenges_format_date( $view['date'] ) ) ) . '</p>';
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display flag after a redirect.
	if ( isset( $_GET['done'] ) && '' === $error ) {
		/* translators: 1: points, 2: number of days. */
		$body .= '<p class="jl-ok" role="status">' . esc_html( jlife_challenges_status_text( $view ) ) . ' ' . esc_html( sprintf( __( 'You have %1$d of %2$d.', 'jlife-challenges' ), $view['points'], $view['day_count'] ) ) . '</p>';
	}
	if ( '' !== $error ) {
		$body .= '<p class="jl-err" role="alert">' . esc_html( $error ) . '</p>';
	}
	$body .= jlife_challenges_render_reading( $view );

	$chips = '';
	foreach ( (array) ( $view['reading']['day']['passages'] ?? array() ) as $p ) {
		$chips .= '<button type="button" class="jl-chip" data-insert="' . esc_attr( (string) $p['display'] ) . '">' . esc_html( (string) $p['display'] ) . '</button>';
	}
	$submitted = in_array( $view['status'], array( 'earned', 'late_no_point', 'submitted' ), true );
	$body     .= '<form method="post" class="jl-soap" data-status="' . esc_attr( $submitted ? 'submitted' : 'draft' ) . '"'
		. ' data-rest="' . esc_url( rest_url( 'jlife/v1/soap/draft' ) ) . '" data-nonce="' . esc_attr( wp_create_nonce( 'wp_rest' ) ) . '"'
		. ' data-i18n="' . esc_attr(
			(string) wp_json_encode(
				array(
					'saving'   => __( 'Saving…', 'jlife-challenges' ),
					'saved'    => __( 'Draft saved', 'jlife-challenges' ),
					'local'    => __( 'Saved on this device (offline)', 'jlife-challenges' ),
					'restored' => __( 'Restored your unsaved draft', 'jlife-challenges' ),
				)
			)
		) . '"'
		. ' data-campaign="' . esc_attr( (string) $view['campaign']['id'] ) . '" data-day="' . esc_attr( $view['day_key'] ) . '">'
		. wp_nonce_field( 'jlife_soap_' . $view['day_key'], '_jlife_soap', true, false );
	foreach ( jlife_challenges_field_labels() as $f => $label ) {
		$body .= '<label for="jl-' . esc_attr( $f ) . '">' . esc_html( $label[0] ) . '</label><small>' . esc_html( $label[1] ) . '</small>';
		if ( 'scripture' === $f && '' !== $chips ) {
			$body .= '<div class="jl-chips" aria-label="' . esc_attr__( "Insert today's references", 'jlife-challenges' ) . '">' . $chips . '</div>';
		}
		$body .= '<textarea id="jl-' . esc_attr( $f ) . '" name="' . esc_attr( $f ) . '" rows="' . ( 'scripture' === $f ? 2 : 4 ) . '" maxlength="' . esc_attr( (string) JLIFE_CHALLENGES_FIELD_MAX ) . '">' . esc_textarea( $values[ $f ] ?? '' ) . '</textarea>';
	}
	$body .= '<p class="jl-saved" aria-live="polite"></p>'
		. '<button type="submit" name="jl_action" value="submit" class="jl-button jl-primary">' . esc_html( $submitted ? __( 'Save changes', 'jlife-challenges' ) : __( 'Submit my SOAP', 'jlife-challenges' ) ) . '</button>'
		. ( $submitted ? '' : '<button type="submit" name="jl_action" value="draft" class="jl-button" formnovalidate>' . esc_html__( 'Save draft', 'jlife-challenges' ) . '</button>' )
		. '</form>';
	jlife_challenges_send_page( __( 'SOAP', 'jlife-challenges' ), $body, 'today', 'soap' );
}

/**
 * /marathon
 */
function jlife_challenges_screen_marathon() {
	$user_id = jlife_challenges_require_participant();
	$view    = jlife_challenges_marathon_view( $user_id );
	if ( ! $view ) {
		jlife_challenges_send_page( __( 'My Marathon', 'jlife-challenges' ), (string) jlife_challenges_render_no_day( array( 'state' => 'not_enrolled' ) ), 'marathon' );
	}
	$labels = array(
		'earned'   => __( 'done — 1 point', 'jlife-challenges' ),
		'late'     => __( 'done after the deadline', 'jlife-challenges' ),
		'draft'    => __( 'draft', 'jlife-challenges' ),
		'open'     => __( 'still open', 'jlife-challenges' ),
		'not_done' => __( 'not written', 'jlife-challenges' ),
		'today'    => __( 'today', 'jlife-challenges' ),
		'future'   => __( 'coming up', 'jlife-challenges' ),
	);
	$grid   = '';
	foreach ( $view['cells'] as $c ) {
		/* translators: 1: day number, 2: status. */
		$title = sprintf( __( 'Day %1$d: %2$s', 'jlife-challenges' ), $c['index'], $labels[ $c['state'] ] );
		$inner = esc_html( (string) $c['index'] );
		$grid .= 'future' === $c['state']
			? '<span class="jl-cell jl-' . esc_attr( $c['state'] ) . '" title="' . esc_attr( $title ) . '">' . $inner . '</span>'
			: '<a class="jl-cell jl-' . esc_attr( $c['state'] ) . '" title="' . esc_attr( $title ) . '" aria-label="' . esc_attr( $title ) . '" href="' . esc_url( add_query_arg( 'day', $c['day_key'], home_url( '/soap/' ) ) ) . '">' . $inner . '</a>';
	}
	$legend = '';
	foreach ( array( 'earned', 'late', 'draft', 'today', 'open' ) as $s ) {
		$legend .= '<li><span class="jl-cell jl-' . esc_attr( $s ) . '"></span> ' . esc_html( $labels[ $s ] ) . '</li>';
	}
	$body = '<h1>' . esc_html__( 'My Marathon', 'jlife-challenges' ) . '</h1>'
		/* translators: 1: points, 2: number of days. */
		. '<p class="jl-score">' . esc_html( sprintf( __( '%1$d of %2$d', 'jlife-challenges' ), $view['points'], $view['day_count'] ) ) . '</p>'
		. '<p>' . esc_html__( 'Every day is a fresh start. Tap any day to read it or write your SOAP.', 'jlife-challenges' ) . '</p>'
		. '<div class="jl-grid">' . $grid . '</div><ul class="jl-legend">' . $legend . '</ul>';
	jlife_challenges_send_page( __( 'My Marathon', 'jlife-challenges' ), $body, 'marathon' );
}

add_action( 'template_redirect', 'jlife_challenges_route' );

/**
 * Dispatch the screens; send signed-in participants from the home page to Today.
 */
function jlife_challenges_route() {
	$screen = (string) get_query_var( 'jlife_screen' );
	if ( 'today' === $screen ) {
		jlife_challenges_screen_today();
	} elseif ( 'soap' === $screen ) {
		jlife_challenges_screen_soap();
	} elseif ( 'marathon' === $screen ) {
		jlife_challenges_screen_marathon();
	} elseif ( 'signout' === $screen ) {
		jlife_challenges_screen_signout();
	} elseif ( ( is_front_page() || is_home() ) && ! current_user_can( 'edit_posts' ) && is_user_logged_in() ) {
		wp_safe_redirect( home_url( '/today/' ) );
		exit;
	}
}
