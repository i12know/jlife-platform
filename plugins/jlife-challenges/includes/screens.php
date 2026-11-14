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
	add_rewrite_rule( '^(today|soap|marathon|share|group|signout)/?$', 'index.php?jlife_screen=$matches[1]', 'top' );
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
 * @param string $script Optional script file ('soap' or 'share').
 */
function jlife_challenges_send_page( $title, $body, $active = '', $script = '' ) {
	nocache_headers();
	header( 'Content-Type: text/html; charset=utf-8' );
	header( 'X-Robots-Tag: noindex, nofollow' );
	echo jlife_challenges_page_html( $title, $body, $active, $script ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built from escaped parts.
	exit;
}

/**
 * A complete participant page: navigation, the body and the page's script.
 *
 * @param string $title  Page title.
 * @param string $body   Escaped HTML.
 * @param string $active Active nav item.
 * @param string $script Optional script file ('soap' or 'share').
 * @return string
 */
function jlife_challenges_page_html( $title, $body, $active = '', $script = '' ) {
	$base = plugins_url( 'assets/', dirname( __DIR__ ) . '/jlife-challenges.php' );
	$nav  = '';
	foreach ( array(
		'today'    => __( 'Today', 'jlife-challenges' ),
		'group'    => __( 'My Group', 'jlife-challenges' ),
		'marathon' => __( 'My Marathon', 'jlife-challenges' ),
	) as $slug => $label ) {
		$nav .= '<a href="' . esc_url( home_url( '/' . $slug . '/' ) ) . '"' . ( $slug === $active ? ' aria-current="page"' : '' ) . '>' . esc_html( $label ) . '</a>';
	}
	// Sign out asks first (#89): the link opens a confirmation page, which the
	// nav script turns into an in-page dialog when JavaScript is available.
	$day  = isset( $_GET['day'] ) ? sanitize_key( wp_unslash( $_GET['day'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only day selector.
	$back = (string) get_query_var( 'jlife_screen' );
	$back = in_array( $back, array( 'today', 'soap', 'share', 'group', 'marathon' ), true ) ? $back : $active;
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
		. '<link rel="stylesheet" href="' . esc_url( $base . 'screens.css?v=6' ) . '">' // phpcs:ignore WordPress.WP.EnqueuedResources.NonEnqueuedStylesheet -- standalone page without wp_head.
		. '</head><body><nav class="jl-nav">' . $nav . '</nav><main>' . $body . '</main>' . $dialog
		. ( '' !== $dialog ? '<script src="' . esc_url( $base . 'nav.js?v=1' ) . '"></script>' : '' ) // phpcs:ignore WordPress.WP.EnqueuedResources.NonEnqueuedScript -- standalone page without wp_footer.
		. ( in_array( $script, array( 'soap', 'share' ), true ) ? '<script src="' . esc_url( $base . $script . '.js?v=3' ) . '"></script>' : '' ) // phpcs:ignore WordPress.WP.EnqueuedResources.NonEnqueuedScript -- standalone page without wp_footer.
		. '</body></html>';
	return $html;
}

/**
 * URL of a participant screen, optionally for one day. Unknown screens give Today.
 *
 * @param string $screen 'today', 'soap', 'marathon', 'share' or 'group'.
 * @param string $day    Day key, or ''.
 * @return string
 */
function jlife_challenges_screen_url( $screen, $day = '' ) {
	$screen = in_array( $screen, array( 'today', 'soap', 'marathon', 'share', 'group' ), true ) ? $screen : 'today';
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
		$out .= '</ul>' . jlife_challenges_render_harmony_sections( $supp['day'] )
			. jlife_challenges_render_bible_links( $supp['day'], $supp['plan']['links_config'] ?? $config, $view['languages'] ) . '</details>';
	}
	return $out . '</section>';
}

/**
 * Harmony Bible sections for the optional parallel readings (#90): number and
 * Robertson title, each opening the matching harmony-bible.com page in a new
 * tab so the participant keeps their place for the SOAP.
 *
 * @param array $day Supplemental day data.
 * @return string
 */
function jlife_challenges_render_harmony_sections( $day ) {
	$out = '';
	foreach ( (array) ( $day['harmony_sections'] ?? array() ) as $section ) {
		$url = (string) ( $section['url'] ?? '' );
		if ( 0 !== strpos( $url, 'https://harmony-bible.com/sections/' ) ) {
			continue;
		}
		/* translators: 1: Harmony Bible section number, 2: section title. */
		$label = sprintf( __( "Harmony's section %1\$s: %2\$s", 'jlife-challenges' ), (string) $section['number'], (string) $section['title'] );
		$out  .= '<li><a href="' . esc_url( $url ) . '" target="_blank" rel="noopener">' . esc_html( $label ) . '</a></li>';
	}
	if ( '' === $out ) {
		return '';
	}
	return '<div class="jl-harmony"><h3>' . esc_html__( 'Harmony Bible sections', 'jlife-challenges' ) . '</h3><ul>' . $out . '</ul></div>';
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
 * Bible Gateway passage pages may be shown in the reader frame; nothing else.
 *
 * @param string $url Candidate URL.
 * @return bool
 */
function jlife_challenges_is_embeddable_passage_url( $url ) {
	return 0 === strpos( $url, 'https://www.biblegateway.com/passage/?' );
}

/**
 * The expandable Bible reader above the SOAP form (#92): today's assigned
 * passages from Bible Gateway in a frame, one pane per language (the
 * participant's first), collapsed by default. Nothing is fetched from Bible
 * Gateway until the participant opens it (soap.js sets the frame's src), and
 * the "Open in Bible Gateway" link stays visible in case the frame can't load.
 * The frame is sandboxed without top navigation, so it can never navigate
 * this page away from a half-written SOAP. Languages whose config has no
 * "embed": true, or whose link isn't a Bible Gateway passage page, are skipped.
 *
 * @param array $view Day view.
 * @return string Empty when there is nothing to embed.
 */
function jlife_challenges_render_reader( $view ) {
	$day    = $view['reading']['day'] ?? null;
	$config = $view['reading']['plan']['links_config'] ?? array();
	if ( ! is_array( $day ) ) {
		return '';
	}
	$tabs  = '';
	$panes = '';
	$first = true;
	foreach ( $view['languages'] as $lang ) {
		$cfg = $config[ $lang ] ?? array();
		$url = (string) ( $day['links'][ $lang ]['combined']['url'] ?? '' );
		if ( empty( $cfg['embed'] ) || ! jlife_challenges_is_embeddable_passage_url( $url ) ) {
			continue;
		}
		$version = (string) ( $day['links'][ $lang ]['version'] ?? '' );
		/* translators: 1: language name, 2: Bible version code. */
		$name   = sprintf( __( '%1$s (%2$s)', 'jlife-challenges' ), (string) ( $cfg['label'] ?? $lang ), $version );
		$tabs  .= '<button type="button" class="jl-chip" data-reader-lang="' . esc_attr( $lang ) . '" aria-pressed="' . ( $first ? 'true' : 'false' ) . '">' . esc_html( $name ) . '</button>';
		$panes .= '<div class="jl-reader-pane" data-reader-pane="' . esc_attr( $lang ) . '" lang="' . esc_attr( $lang ) . '"' . ( $first ? '' : ' hidden' ) . '>'
			/* translators: %s: Bible version name and code, e.g. "Tiếng Việt (BPT)". */
			. '<iframe title="' . esc_attr( sprintf( __( "Today's passages, %s", 'jlife-challenges' ), $name ) ) . '" data-src="' . esc_url( $url ) . '"'
			. ' sandbox="allow-scripts allow-same-origin allow-popups allow-popups-to-escape-sandbox allow-forms" referrerpolicy="strict-origin-when-cross-origin"></iframe>'
			. '<p class="jl-reader-fail" role="status" hidden>' . esc_html__( 'The reader could not load here. Use the Bible Gateway link below; your SOAP stays on this page.', 'jlife-challenges' ) . '</p>'
			. '<p class="jl-reader-note"><small>' . esc_html( (string) ( $cfg['notice'] ?? $name ) ) . '. ' . esc_html__( 'Shown from Bible Gateway.', 'jlife-challenges' ) . '</small> '
			. '<a href="' . esc_url( $url ) . '" target="_blank" rel="noopener">' . esc_html__( 'Open in Bible Gateway', 'jlife-challenges' ) . '</a></p>'
			. '</div>';
		$first = false;
	}
	if ( '' === $panes ) {
		return '';
	}
	return '<details class="jl-reader"><summary>' . esc_html__( "Read today's passages", 'jlife-challenges' ) . '</summary>'
		. ( substr_count( $tabs, '<button' ) > 1 ? '<div class="jl-chips" role="group" aria-label="' . esc_attr__( 'Bible version', 'jlife-challenges' ) . '">' . $tabs . '</div>' : '' )
		. $panes
		. '<noscript><p>' . esc_html__( 'The reader needs JavaScript. Use the Bible Gateway link above.', 'jlife-challenges' ) . '</p></noscript>'
		. '</details>';
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
		case 'submitted':
			return __( 'Saved.', 'jlife-challenges' );
		case 'draft':
			return __( 'Draft saved. Write all four parts (S, O, A, P), then tap Submit SOAP.', 'jlife-challenges' );
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
 * The day heading, e.g. "Day 9 · December 28, 2026".
 *
 * @param array $view Day view.
 * @return string
 */
function jlife_challenges_render_day_head( $view ) {
	/* translators: 1: day number, 2: date. */
	return '<p class="jl-day">' . esc_html( sprintf( __( 'Day %1$d · %2$s', 'jlife-challenges' ), $view['index'], jlife_challenges_format_date( $view['date'] ) ) ) . '</p>';
}

/**
 * Whether the day's SOAP is done (so the form offers "Save changes").
 *
 * @param array $view Day view.
 * @return bool
 */
function jlife_challenges_is_submitted( $view ) {
	return in_array( $view['status'] ?? '', array( 'earned', 'late_no_point', 'submitted' ), true );
}

/**
 * The text for the textbox: the saved entry, or the heading template for a new one.
 *
 * @param array $view Day view.
 * @return string
 */
function jlife_challenges_entry_text( $view ) {
	$body = ! empty( $view['entry'] ) ? (string) $view['entry']['body'] : '';
	return '' === trim( $body ) ? jlife_challenges_soap_template() : $body;
}

/**
 * The text as typed in a SOAP form POST.
 *
 * @return string
 */
function jlife_challenges_posted_text() {
	return isset( $_POST['jl_text'] ) ? jlife_challenges_clean_text( wp_unslash( $_POST['jl_text'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- verified by the caller; sanitized by jlife_challenges_clean_text().
}

/**
 * The day a SOAP form POST to Today belongs to. The form names its day, so a
 * form opened before midnight still saves to that day if it is sent after
 * midnight instead of failing on the wrong day's nonce.
 *
 * @return string|null Day key, or null (today) when this is not a form POST.
 */
function jlife_challenges_posted_day_key() {
	// phpcs:ignore WordPress.Security.NonceVerification.Missing -- only picks which day's nonce to check; the nonce is checked next.
	return isset( $_POST['jl_action'], $_POST['jl_day'] ) ? sanitize_key( wp_unslash( $_POST['jl_day'] ) ) : null;
}

/**
 * Where to go after a SOAP form POST: back to the screen it came from. Today
 * shows only today, so any other day goes to /soap?day=.
 *
 * @param array  $view   Day view.
 * @param string $screen 'today' or 'soap'.
 * @return string
 */
function jlife_challenges_soap_return_url( $view, $screen ) {
	return 'today' === $screen && ! empty( $view['is_today'] )
		? jlife_challenges_screen_url( 'today' )
		: jlife_challenges_screen_url( 'soap', $view['day_key'] );
}

/**
 * Handle a SOAP form POST (Submit SOAP, Save changes or Save draft) for Today
 * and /soap alike.
 *
 * Submit saves the text, scores it and, with the box ticked, shares it. The
 * result is shown after a redirect (?done=1 or ?incomplete=1, plus ?shared=1 or
 * ?share_failed=code), so a refresh never re-posts. A draft save never shares.
 *
 * @param int    $user_id Participant.
 * @param array  $view    Day view.
 * @param string $screen  'today' or 'soap': where the form lives.
 * @return array { error: string, text: string, share: bool, redirect: string }
 *               With a redirect, send the participant there. Otherwise show
 *               "error" and keep the typed "text". Without a POST, "text" is
 *               the saved entry (or the template).
 */
function jlife_challenges_process_soap_request( $user_id, $view, $screen ) {
	$state = array(
		'error'    => '',
		'text'     => jlife_challenges_entry_text( $view ),
		'share'    => false,
		'redirect' => '',
	);
	if ( ! isset( $_POST['jl_action'] ) || ! check_admin_referer( 'jlife_soap_' . $view['day_key'], '_jlife_soap' ) ) {
		return $state;
	}
	$text           = jlife_challenges_posted_text();
	$state['text']  = $text;
	$state['share'] = isset( $_POST['jl_share'] );
	$return         = jlife_challenges_soap_return_url( $view, $screen );
	$cid            = (int) $view['campaign']['id'];
	if ( 'draft' === sanitize_key( wp_unslash( $_POST['jl_action'] ) ) ) {
		$r = jlife_challenges_save_draft( $user_id, $cid, $view['day_key'], $text );
		if ( is_wp_error( $r ) ) {
			$state['error'] = $r->get_error_message();
		} else {
			$state['redirect'] = $return;
		}
		return $state;
	}
	$r = jlife_challenges_save_and_share( $user_id, $cid, $view['day_key'], $text, $state['share'] );
	if ( is_wp_error( $r ) ) {
		$state['error'] = $r->get_error_message();
		return $state;
	}
	$key   = $r['passes'] ? 'done' : 'incomplete';
	$flags = array( $key => 1 );
	if ( 'shared' === $r['share']['status'] ) {
		$flags['shared'] = 1;
	} elseif ( 'failed' === $r['share']['status'] ) {
		$flags['share_failed'] = $r['share']['code'];
	}
	$state['redirect'] = add_query_arg( $flags, $return );
	return $state;
}

/**
 * What the page says after a save, from the redirect's display flags.
 *
 * @return array { done: bool, incomplete: bool, shared: bool, share_failed: string }
 */
function jlife_challenges_return_flags() {
	// phpcs:disable WordPress.Security.NonceVerification.Recommended -- display flags after a redirect; nothing is changed by them.
	return array(
		'done'         => isset( $_GET['done'] ),
		'incomplete'   => isset( $_GET['incomplete'] ),
		'shared'       => isset( $_GET['shared'] ),
		'share_failed' => isset( $_GET['share_failed'] ) ? sanitize_key( wp_unslash( $_GET['share_failed'] ) ) : '',
	);
	// phpcs:enable WordPress.Security.NonceVerification.Recommended
}

/**
 * Fill in a page state (see jlife_challenges_process_soap_request() and
 * jlife_challenges_return_flags()) with its defaults.
 *
 * @param array $state Partial state.
 * @return array { error: string, text: string|null, share: bool, flags: array }
 */
function jlife_challenges_page_state( $state ) {
	$state          = wp_parse_args(
		$state,
		array(
			'error' => '',
			'text'  => null,
			'share' => false,
			'flags' => array(),
		)
	);
	$state['flags'] = wp_parse_args(
		(array) $state['flags'],
		array(
			'done'         => false,
			'incomplete'   => false,
			'shared'       => false,
			'share_failed' => '',
		)
	);
	return $state;
}

/**
 * Plain words for why sharing didn't happen after a good save.
 *
 * @param string $code Share error code from the redirect.
 * @return string
 */
function jlife_challenges_share_failed_message( $code ) {
	switch ( $code ) {
		case 'jlife_guardian_consent_required':
			return __( 'It was not shared: sharing with your group starts once a parent or guardian has agreed. Your entry is saved.', 'jlife-challenges' );
		case 'jlife_share_hidden':
			return __( 'It was not shared: an organizer hid your post for this day, so it was not posted again. Your entry is saved.', 'jlife-challenges' );
		default:
			return __( 'Your entry is saved, but it could not be shared with your group this time. Tap Save changes with the box ticked to try again.', 'jlife-challenges' );
	}
}

/**
 * Feedback above the page: the error, or what happened after a save: the green
 * confirmation and point (with the share result), or kind reasons why the entry
 * doesn't earn the point yet. Typed text is always kept.
 *
 * @param array $view  Day view.
 * @param array $state Page state.
 * @return string
 */
function jlife_challenges_render_soap_feedback( $view, $state ) {
	$state = jlife_challenges_page_state( $state );
	$flags = $state['flags'];
	if ( '' !== $state['error'] ) {
		return '<p class="jl-err" role="alert">' . esc_html( $state['error'] ) . '</p>';
	}
	$out = '';
	if ( $flags['done'] && jlife_challenges_is_submitted( $view ) ) {
		/* translators: 1: points, 2: number of days. */
		$out .= '<p class="jl-ok" role="status">' . esc_html( jlife_challenges_status_text( $view ) ) . ' ' . esc_html( sprintf( __( 'You have %1$d of %2$d.', 'jlife-challenges' ), $view['points'], $view['day_count'] ) ) . '</p>';
	} elseif ( $flags['incomplete'] && ! empty( $view['entry'] ) ) {
		$reasons = jlife_challenges_score_entry(
			(string) $view['entry']['body'],
			array(
				'campaign' => $view['campaign'],
				'day_key'  => $view['day_key'],
				'index'    => $view['index'],
			)
		)['reasons'];
		if ( $reasons ) {
			$lead = jlife_challenges_is_submitted( $view )
				? __( 'Your changes are saved, and your point for this day stays. To make this entry complete:', 'jlife-challenges' )
				: __( 'Saved. Everything you typed is kept. It does not earn the point yet. To finish:', 'jlife-challenges' );
			$out .= '<div class="jl-note" role="status"><p>' . esc_html( $lead ) . '</p><ul>';
			foreach ( $reasons as $reason ) {
				$out .= '<li>' . esc_html( $reason ) . '</li>';
			}
			$out .= '</ul></div>';
		}
	}
	if ( $flags['shared'] ) {
		$out .= '<p class="jl-ok" role="status">' . esc_html__( 'Shared with your group.', 'jlife-challenges' ) . ' <a href="' . esc_url( home_url( '/group/' ) ) . '">' . esc_html__( 'See My Group', 'jlife-challenges' ) . '</a></p>';
	} elseif ( '' !== $flags['share_failed'] ) {
		$out .= '<p class="jl-err" role="alert">' . esc_html( jlife_challenges_share_failed_message( $flags['share_failed'] ) ) . '</p>';
	}
	return $out;
}

/**
 * Short guidance for each part, shown under the textbox.
 *
 * @return array<string,string> Letter => guidance.
 */
function jlife_challenges_soap_guidance() {
	return array(
		'S' => __( 'Write the verse or reference that stood out to you.', 'jlife-challenges' ),
		'O' => __( 'What does it say? What do you notice about Jesus?', 'jlife-challenges' ),
		'A' => __( 'What will you believe, change, or do today?', 'jlife-challenges' ),
		'P' => __( 'Talk to God about what you read.', 'jlife-challenges' ),
	);
}

/**
 * The Bible reader and the SOAP form: one renderer for Today and /soap. The
 * reader sits directly above the textbox and outside the form, so opening and
 * closing it never touches what is typed. The form posts back to the page it
 * is on.
 *
 * @param array $view  Day view.
 * @param array $state Page state (text null = the saved entry or the template).
 * @return string
 */
function jlife_challenges_render_soap_panel( $view, $state = array() ) {
	$state     = jlife_challenges_page_state( $state );
	$text      = null === $state['text'] ? jlife_challenges_entry_text( $view ) : (string) $state['text'];
	$submitted = jlife_challenges_is_submitted( $view );
	$headings  = jlife_challenges_soap_headings();
	$shared    = $view['share_status'] ?? '';
	$chips     = '';
	foreach ( (array) ( $view['reading']['day']['passages'] ?? array() ) as $p ) {
		$chips .= '<button type="button" class="jl-chip" data-insert="' . esc_attr( (string) $p['display'] ) . '">' . esc_html( (string) $p['display'] ) . '</button>';
	}
	$out = jlife_challenges_render_reader( $view )
		. '<form method="post" class="jl-soap" data-status="' . esc_attr( $submitted ? 'submitted' : 'draft' ) . '"'
		. ' data-rest="' . esc_url( rest_url( 'jlife/v1/soap/draft' ) ) . '" data-nonce="' . esc_attr( wp_create_nonce( 'wp_rest' ) ) . '"'
		. ' data-template="' . esc_attr( jlife_challenges_soap_template() ) . '" data-headings="' . esc_attr( (string) wp_json_encode( array_values( $headings ) ) ) . '"'
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
		. wp_nonce_field( 'jlife_soap_' . $view['day_key'], '_jlife_soap', true, false )
		. '<input type="hidden" name="jl_day" value="' . esc_attr( $view['day_key'] ) . '">'
		. '<label for="jl-text">' . esc_html__( 'My SOAP', 'jlife-challenges' ) . '</label>'
		. '<small>' . esc_html__( 'Write under each heading. Keep the headings: they help you and your group read it.', 'jlife-challenges' ) . '</small>';
	if ( '' !== $chips ) {
		$out .= '<div class="jl-chips" aria-label="' . esc_attr__( "Insert today's references", 'jlife-challenges' ) . '">' . $chips . '</div>';
	}
	$out .= '<textarea id="jl-text" name="jl_text" rows="16" maxlength="' . esc_attr( (string) JLIFE_CHALLENGES_BODY_MAX ) . '">' . esc_textarea( $text ) . '</textarea>'
		. '<details class="jl-optional jl-guidance"><summary>' . esc_html__( 'What goes under each heading?', 'jlife-challenges' ) . '</summary><ul>';
	foreach ( jlife_challenges_soap_guidance() as $letter => $guidance ) {
		$out .= '<li><strong>' . esc_html( $headings[ $letter ] ) . '</strong> ' . esc_html( $guidance ) . '</li>';
	}
	$out .= '</ul></details>'
		. '<p class="jl-saved" aria-live="polite"></p>'
		. '<label class="jl-share-opt"><input type="checkbox" name="jl_share" value="1"' . checked( $state['share'], true, false ) . '> <span>' . esc_html__( 'Share this with my group', 'jlife-challenges' ) . '</span></label>'
		. '<small class="jl-share-note">' . esc_html__( 'This shares the whole entry, including your Prayer, with your group. Leave it unticked to keep it private.', 'jlife-challenges' ) . '</small>';
	if ( 'shared' === $shared ) {
		$out .= '<small class="jl-share-note">' . esc_html__( 'Your group already has this day. Tick the box and save to update it.', 'jlife-challenges' ) . '</small>';
	}
	return $out
		. '<button type="submit" name="jl_action" value="submit" class="jl-button jl-primary">' . esc_html( $submitted ? __( 'Save changes', 'jlife-challenges' ) : __( 'Submit SOAP', 'jlife-challenges' ) ) . '</button>'
		. ( $submitted ? '' : '<button type="submit" name="jl_action" value="draft" class="jl-button" formnovalidate>' . esc_html__( 'Save draft', 'jlife-challenges' ) . '</button>' )
		. '</form>';
}

/**
 * Today's page body: day, feedback, reading, status, then the reader and the SOAP form.
 *
 * @param array $view  Day view.
 * @param array $state Page state.
 * @return string
 */
function jlife_challenges_today_body( $view, $state = array() ) {
	$state = jlife_challenges_page_state( $state );
	$body  = jlife_challenges_render_day_head( $view )
		. jlife_challenges_render_soap_feedback( $view, $state )
		. jlife_challenges_render_reading( $view );
	// After a save, the message above already says where things stand.
	if ( '' !== $state['error'] || ! ( $state['flags']['done'] || $state['flags']['incomplete'] ) ) {
		$body .= '<section class="jl-status"><p>' . esc_html( jlife_challenges_status_text( $view ) ) . '</p>'
			/* translators: 1: points, 2: number of days. */
			. '<p class="jl-score">' . esc_html( sprintf( __( '%1$d of %2$d', 'jlife-challenges' ), $view['points'], $view['day_count'] ) ) . '</p></section>';
	}
	return $body . jlife_challenges_render_soap_panel( $view, $state );
}

/**
 * The /soap page body: day, feedback, reading, then the reader and the SOAP form.
 *
 * @param array $view  Day view.
 * @param array $state Page state.
 * @return string
 */
function jlife_challenges_soap_body( $view, $state = array() ) {
	return jlife_challenges_render_day_head( $view )
		. jlife_challenges_render_soap_feedback( $view, $state )
		. jlife_challenges_render_reading( $view )
		. jlife_challenges_render_soap_panel( $view, $state );
}

/**
 * Run a SOAP screen (Today or /soap): handle a form POST, redirect after a
 * save, or show the page.
 *
 * @param string $screen  'today' or 'soap'.
 * @param string $title   Page title.
 * @param array  $view    Day view (active).
 * @param int    $user_id Participant.
 */
function jlife_challenges_run_soap_screen( $screen, $title, $view, $user_id ) {
	$state = jlife_challenges_process_soap_request( $user_id, $view, $screen );
	if ( '' !== $state['redirect'] ) {
		wp_safe_redirect( $state['redirect'] );
		exit;
	}
	$state['flags'] = jlife_challenges_return_flags();
	$body           = 'today' === $screen ? jlife_challenges_today_body( $view, $state ) : jlife_challenges_soap_body( $view, $state );
	jlife_challenges_send_page( $title, $body, 'today', 'soap' );
}

/**
 * /today: the reading and the SOAP form, with its reader, on one page.
 */
function jlife_challenges_screen_today() {
	$user_id = jlife_challenges_require_participant();
	$view    = jlife_challenges_day_view( $user_id, jlife_challenges_posted_day_key() );
	$none    = jlife_challenges_render_no_day( $view );
	if ( null !== $none ) {
		jlife_challenges_send_page( __( 'Today', 'jlife-challenges' ), $none, 'today' );
	}
	jlife_challenges_run_soap_screen( 'today', __( 'Today', 'jlife-challenges' ), $view, $user_id );
}

/**
 * /soap?day=d###: the same reading, reader and form for any open day.
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
	jlife_challenges_run_soap_screen( 'soap', __( 'SOAP', 'jlife-challenges' ), $view, $user_id );
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
	} elseif ( 'share' === $screen ) {
		jlife_challenges_screen_share();
	} elseif ( 'group' === $screen ) {
		jlife_challenges_screen_group();
	} elseif ( 'signout' === $screen ) {
		jlife_challenges_screen_signout();
	} elseif ( ( is_front_page() || is_home() ) && ! current_user_can( 'edit_posts' ) && is_user_logged_in() ) {
		wp_safe_redirect( home_url( '/today/' ) );
		exit;
	}
}
