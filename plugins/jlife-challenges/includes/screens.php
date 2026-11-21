<?php
/**
 * Participant screens (M6, #61): /today, /soap, /marathon, and the sign-out
 * confirmation /signout (#89). The public /reading/ day pages (#106) reuse their
 * reading and reader renderers (reading-page.php).
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
	add_rewrite_rule( '^(today|soap|marathon|share|group|signout|unsubscribe)/?$', 'index.php?jlife_screen=$matches[1]', 'top' );
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
 * Format a Y-m-d date for display. A language whose catalog translates the
 * "date format" entry gets its own word order (Vietnamese: 18 tháng 11, 2026,
 * #119); other languages keep the site's date format.
 *
 * @param string $ymd Date.
 * @return string
 */
function jlife_challenges_format_date( $ymd ) {
	/* translators: PHP date() format for a reading day's date. Vietnamese: j \t\h\á\n\g n, Y (18 tháng 11, 2026). */
	$format = _x( 'F j, Y', 'date format', 'jlife-challenges' );
	if ( 'F j, Y' === $format ) {
		$format = (string) get_option( 'date_format' );
	}
	return date_i18n( $format, (int) strtotime( $ymd . ' 12:00:00' ) );
}

/**
 * Send a complete participant page and stop.
 *
 * @param string          $title  Page title.
 * @param string          $body   Escaped HTML.
 * @param string          $active Active nav item.
 * @param string|string[] $script Optional script file(s): 'soap', 'share', 'reminder', 'bible', 'language'.
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
 * The active item 'public' is the signed-out shell of a public page (#106, the
 * /reading/ day pages): the same document, stylesheet and body as Today, but no
 * navigation, sign-out or dialog, only the pilot feedback link as its footer
 * (#171), and its language is the page's.
 *
 * @param string          $title  Page title.
 * @param string          $body   Escaped HTML.
 * @param string          $active Active nav item, or 'public'.
 * @param string|string[] $script Optional script file(s): 'soap', 'share', 'reminder', 'bible', 'language'.
 * @param string          $lang   Page language for the html element ('' = the current locale).
 * @return string
 */
function jlife_challenges_page_html( $title, $body, $active = '', $script = '', $lang = '' ) {
	$base   = plugins_url( 'assets/', dirname( __DIR__ ) . '/jlife-challenges.php' );
	$public = 'public' === $active;
	$nav    = '';
	$dialog = '';
	if ( ! $public ) {
		list( $nav, $dialog ) = jlife_challenges_nav_and_dialog( $active );
	}

	$scripts  = '';
	$versions = array(
		'soap'     => 5,
		'share'    => 4,
		'reminder' => 2,
		'bible'    => 1,
		'language' => 1,
	);
	foreach ( (array) $script as $file ) {
		if ( isset( $versions[ $file ] ) ) {
			$scripts .= '<script src="' . esc_url( $base . $file . '.js?v=' . $versions[ $file ] ) . '"></script>'; // phpcs:ignore WordPress.WP.EnqueuedResources.NonEnqueuedScript -- standalone page without wp_footer.
		}
	}

	// The standalone page has no wp_head: print the Scripture reader's stylesheet when the body used it.
	$reader_css = '';
	if ( wp_style_is( 'jlife-scripture', 'enqueued' ) ) {
		ob_start();
		wp_print_styles( 'jlife-scripture' );
		$reader_css = (string) ob_get_clean();
	}

	$html = '<!doctype html><html lang="' . esc_attr( '' !== $lang ? $lang : str_replace( '_', '-', determine_locale() ) ) . '"><head><meta charset="utf-8">'
		. '<meta name="viewport" content="width=device-width, initial-scale=1"><meta name="robots" content="noindex, nofollow">'
		. '<title>' . esc_html( $title ) . '</title>'
		. '<link rel="stylesheet" href="' . esc_url( $base . 'screens.css?v=19' ) . '">' // phpcs:ignore WordPress.WP.EnqueuedResources.NonEnqueuedStylesheet -- standalone page without wp_head.
		. $reader_css
		. '</head><body>' . ( $public ? '' : '<nav class="jl-nav">' . $nav . '</nav>' ) . '<main>' . ( $public ? '' : jlife_challenges_practice_banner( get_current_user_id(), $active ) ) . $body . '</main>' . ( $public ? jlife_challenges_pilot_feedback_footer() : jlife_challenges_help_footer() ) . $dialog
		. ( '' !== $dialog ? '<script src="' . esc_url( $base . 'nav.js?v=1' ) . '"></script>' : '' ) // phpcs:ignore WordPress.WP.EnqueuedResources.NonEnqueuedScript -- standalone page without wp_footer.
		. $scripts
		. '</body></html>';
	return $html;
}

/**
 * The participant navigation and the sign-out dialog of a signed-in screen.
 *
 * @param string $active Active nav item.
 * @return string[] { nav links, dialog } as escaped HTML; the dialog is empty on the sign-out confirmation itself.
 */
function jlife_challenges_nav_and_dialog( $active ) {
	$nav = '';
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
	return array( $nav, $dialog );
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
 * Will the day's Scripture show on the page itself? True when the Scripture
 * plugin is active, its on-page reader is turned on for this site
 * (jlife_scripture_native_reader) and the day has passages; then
 * jlife_challenges_render_reader() shows the participant's own Bible instead
 * of the framed Bible Gateway reader.
 *
 * @param array $view Day view.
 * @return bool
 */
function jlife_challenges_native_reader_active( $view ) {
	return function_exists( 'jlife_scripture_maybe_render_day' )
		&& function_exists( 'jlife_scripture_reader_enabled' )
		&& jlife_scripture_reader_enabled()
		&& ! empty( $view['reading']['day']['passages'] );
}

/**
 * The reading block: the day's title and reference list (plain text), then the
 * optional parallels collapsed (their note and the Harmony sections, no passage
 * list). It has no Bible links of its own (#103): the Bible text is read in
 * the reader (jlife_challenges_render_reader()), which also lists each
 * reference when it shows the text on the page, so the plain list is left out
 * then.
 *
 * Today, /soap and the public /reading/ pages (#106) are built from the same
 * two parts, jlife_challenges_render_reading_head() and
 * jlife_challenges_render_optional_readings(); the public page puts the reader
 * between them.
 *
 * @param array $view Day view.
 * @return string
 */
function jlife_challenges_render_reading( $view ) {
	$reading = $view['reading'];
	if ( ! $reading || empty( $reading['day'] ) ) {
		return '<p>' . esc_html__( 'The reading for this day is not loaded yet.', 'jlife-challenges' ) . '</p>';
	}
	return '<section class="jl-reading">' . jlife_challenges_render_reading_head( $view ) . jlife_challenges_render_optional_readings( $reading ) . '</section>';
}

/**
 * The top of the reading block: the day's title and its reference list. The
 * list is left out when the reader shows each reference itself.
 *
 * @param array $view Day view, with a reading that has a day.
 * @return string
 */
function jlife_challenges_render_reading_head( $view ) {
	$day = $view['reading']['day'];
	$out = '<h2>' . esc_html( (string) $day['title'] ) . '</h2>';
	if ( ! jlife_challenges_native_reader_active( $view ) ) {
		$out .= '<ul class="jl-passages">';
		foreach ( (array) $day['passages'] as $p ) {
			$out .= '<li>' . esc_html( (string) $p['display'] ) . '</li>';
		}
		$out .= '</ul>';
	}
	return $out;
}

/**
 * The optional parallel readings, collapsed: their note and the Harmony
 * sections, with no passage list.
 *
 * @param array|null $reading The reading (day, plan, supplemental).
 * @return string Empty when the day has no supplemental plan.
 */
function jlife_challenges_render_optional_readings( $reading ) {
	if ( empty( $reading['supplemental']['day'] ) ) {
		return '';
	}
	return '<details class="jl-optional"><summary>' . esc_html__( 'Optional: parallel Harmony readings for deeper study', 'jlife-challenges' ) . '</summary><p>'
		. esc_html__( 'Optional. Reading these does not change your points.', 'jlife-challenges' ) . '</p>'
		. jlife_challenges_render_harmony_sections( $reading['supplemental']['day'] ) . '</details>';
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
 * Plain links to one language's passages for the reader (#103): a single link
 * when the provider opens every passage on one page (Bible Gateway), otherwise
 * one chip per passage. Used where a language is not framed and in the
 * no-JavaScript list.
 *
 * @param array       $links Language's links: 'combined' => url, or 'passages' => label/url pairs.
 * @param string|null $text  Link text for the single link; null names the provider.
 * @return string Empty when there is no usable link.
 */
function jlife_challenges_render_passage_links( $links, $text = null ) {
	$url = (string) ( $links['combined']['url'] ?? '' );
	if ( '' !== $url ) {
		if ( null === $text ) {
			$text = 0 === strpos( $url, 'https://www.biblegateway.com/' )
				? __( 'Open in Bible Gateway', 'jlife-challenges' )
				: __( "Open today's passages", 'jlife-challenges' );
		}
		return '<a href="' . esc_url( $url ) . '" target="_blank" rel="noopener">' . esc_html( $text ) . '</a>';
	}
	$chips = '';
	foreach ( (array) ( $links['passages'] ?? array() ) as $l ) {
		$chips .= '<a class="jl-chip" href="' . esc_url( (string) $l['url'] ) . '" target="_blank" rel="noopener">' . esc_html( (string) $l['label'] ) . '</a>';
	}
	return '' === $chips ? '' : '<div class="jl-chips">' . $chips . '</div>';
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
 * The languages the reader can offer for a day: those, in the view's order,
 * that have a usable link.
 *
 * @param array $view Day view.
 * @return array[] Rows of { lang, links, cfg, url (the combined link or ''), plain (the plain link markup), name (e.g. "English (ERV)") }.
 */
function jlife_challenges_reader_entries( $view ) {
	$day     = $view['reading']['day'] ?? null;
	$config  = $view['reading']['plan']['links_config'] ?? array();
	$entries = array();
	foreach ( (array) ( $view['languages'] ?? array() ) as $lang ) {
		$links = (array) ( $day['links'][ $lang ] ?? array() );
		$cfg   = $config[ $lang ] ?? array();
		$plain = jlife_challenges_render_passage_links( $links );
		if ( '' === $plain ) {
			continue;
		}
		$version = (string) ( $links['version'] ?? '' );
		$label   = (string) ( $cfg['label'] ?? $lang );

		$entries[] = array(
			'lang'  => $lang,
			'links' => $links,
			'cfg'   => $cfg,
			'url'   => (string) ( $links['combined']['url'] ?? '' ),
			'plain' => $plain,
			/* translators: 1: language name, 2: Bible version code. */
			'name'  => '' === $version ? $label : sprintf( __( '%1$s (%2$s)', 'jlife-challenges' ), $label, $version ),
		);
	}
	return $entries;
}

/**
 * One language's plain link as a list item (for the no-JavaScript list on
 * Today and the link list on the public page): the version name linking to the
 * combined passage page, or the name with one chip per passage.
 *
 * @param array $entry One row of jlife_challenges_reader_entries().
 * @return string
 */
function jlife_challenges_reader_link_item( $entry ) {
	return '<li lang="' . esc_attr( $entry['lang'] ) . '">'
		. ( '' !== $entry['url'] ? jlife_challenges_render_passage_links( $entry['links'], $entry['name'] ) : esc_html( $entry['name'] ) . ' ' . $entry['plain'] ) . '</li>';
}

/**
 * The expandable Bible reader above the SOAP form (#92) and the only way to
 * read the day's Bible text on Today and the SOAP page (#103). One pane per
 * language that has a link, the participant's first, collapsed by default.
 * A language whose config has "embed": true and whose link is a Bible Gateway
 * passage page gets the page in a frame: nothing is fetched until the
 * participant opens the reader (soap.js sets the frame's src), the frame is
 * sandboxed without top navigation so it can never navigate this page away
 * from a half-written SOAP, and the "Open in Bible Gateway" link stays beside
 * it in case the frame can't load. Any other language (embed off, or not a
 * Bible Gateway passage page) is never framed: its pane is just a plain link.
 * Without JavaScript no frame loads and only the first pane shows, so the
 * <noscript> block lists a link for every language.
 *
 * When the on-page Scripture reader is turned on (jlife_scripture_native_reader,
 * #139) it replaces all of the above: the day's passages in the signed-in
 * participant's own Bible(s), no frame (jlife_scripture_maybe_render_day()).
 *
 * A public view (the /reading/ day pages, #106; the view has 'public' and its
 * 'language') never frames anything and never reads who is looking: the on-page
 * reader shows the page language's default Bible, the same for everyone, under
 * the English | Tiếng Việt switch; without it the page lists one plain link per
 * language that has one.
 *
 * @param array $view Day view.
 * @return string Empty when no language has a link.
 */
function jlife_challenges_render_reader( $view ) {
	$day    = $view['reading']['day'] ?? null;
	$public = ! empty( $view['public'] );
	if ( ! is_array( $day ) ) {
		return '';
	}
	if ( jlife_challenges_native_reader_active( $view ) ) {
		if ( $public ) {
			$lang   = (string) ( $view['language'] ?? 'en' );
			$native = jlife_scripture_maybe_render_day( $day, 0, array( 'language' => $lang ) );
			if ( null !== $native ) {
				return jlife_challenges_render_reading_language_links( (string) ( $view['reading']['url'] ?? '' ), $lang ) . $native;
			}
		} else {
			// The viewer is the participant: these screens only ever show their own day.
			$native = jlife_scripture_maybe_render_day( $day, get_current_user_id() );
			if ( null !== $native ) {
				return $native;
			}
		}
	}
	$entries = jlife_challenges_reader_entries( $view );
	if ( ! $entries ) {
		return '';
	}
	if ( $public ) {
		return '<section class="jl-public-reader"><h3>' . esc_html__( "Read today's passages", 'jlife-challenges' ) . '</h3><ul>'
			. implode( '', array_map( 'jlife_challenges_reader_link_item', $entries ) ) . '</ul></section>';
	}
	$tabs  = '';
	$panes = '';
	$no_js = '';
	$first = true;
	foreach ( $entries as $e ) {
		$lang  = $e['lang'];
		$cfg   = $e['cfg'];
		$name  = $e['name'];
		$tabs .= '<button type="button" class="jl-chip" data-reader-lang="' . esc_attr( $lang ) . '" aria-pressed="' . ( $first ? 'true' : 'false' ) . '">' . esc_html( $name ) . '</button>';
		$pane  = '<div class="jl-reader-pane" data-reader-pane="' . esc_attr( $lang ) . '" lang="' . esc_attr( $lang ) . '"' . ( $first ? '' : ' hidden' ) . '>';
		if ( ! empty( $cfg['embed'] ) && jlife_challenges_is_embeddable_passage_url( $e['url'] ) ) {
			/* translators: %s: Bible version name and code, e.g. "Tiếng Việt (BPT)". */
			$title = sprintf( __( "Today's passages, %s", 'jlife-challenges' ), $name );
			$pane .= '<iframe title="' . esc_attr( $title ) . '" data-src="' . esc_url( $e['url'] ) . '"'
				. ' sandbox="allow-scripts allow-same-origin allow-popups allow-popups-to-escape-sandbox allow-forms" referrerpolicy="strict-origin-when-cross-origin"></iframe>'
				. '<p class="jl-reader-fail" role="status" hidden>' . esc_html__( 'The reader could not load here. Use the Bible Gateway link below; your SOAP stays on this page.', 'jlife-challenges' ) . '</p>'
				. '<p class="jl-reader-note"><small>' . esc_html( (string) ( $cfg['notice'] ?? $name ) ) . '. ' . esc_html__( 'Shown from Bible Gateway.', 'jlife-challenges' ) . '</small> '
				. $e['plain'] . '</p>';
		} else {
			$pane .= '<div class="jl-reader-links">' . $e['plain'] . '</div>';
		}
		$panes .= $pane . '</div>';
		$no_js .= jlife_challenges_reader_link_item( $e );
		$first  = false;
	}
	return '<details class="jl-reader"><summary>' . esc_html__( "Read today's passages", 'jlife-challenges' ) . '</summary>'
		. ( count( $entries ) > 1 ? '<div class="jl-chips" role="group" aria-label="' . esc_attr__( 'Bible version', 'jlife-challenges' ) . '">' . $tabs . '</div>' : '' )
		. $panes
		. '<noscript><p>' . esc_html__( "The reader needs JavaScript. Open today's passages in Bible Gateway instead:", 'jlife-challenges' ) . '</p><ul>' . $no_js . '</ul></noscript>'
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
			// A draft row holding nothing but the headings is not a saved draft yet (#142).
			if ( jlife_challenges_has_writing( (string) ( $view['entry']['body'] ?? '' ) ) ) {
				return __( 'Draft saved. Write all four parts (S, O, A, P), then tap Submit SOAP.', 'jlife-challenges' );
			}
			break;
	}
	return $view['on_time']
		? __( 'Not started yet.', 'jlife-challenges' )
		: __( 'You can still write this one for your own growth (no point after the deadline).', 'jlife-challenges' );
}

/**
 * Whether a SOAP text holds anything beyond its headings: blank, the untouched
 * template, or bare "S:" lines are not writing (#142).
 *
 * @param string $text Entry text.
 * @return bool
 */
function jlife_challenges_has_writing( $text ) {
	foreach ( explode( "\n", str_replace( array( "\r\n", "\r" ), "\n", $text ) ) as $line ) {
		if ( '' === trim( $line ) ) {
			continue;
		}
		$heading = jlife_challenges_parse_heading( $line );
		if ( null === $heading || '' !== $heading['rest'] ) {
			return true;
		}
	}
	return false;
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
				. esc_html__( 'You have not joined the Marathon yet. Open the invite link your group shared with you, or read on your own.', 'jlife-challenges' ) . '</p>'
				. '<p><a class="jl-button jl-primary" href="' . esc_url( home_url( '/join/' ) ) . '">' . esc_html__( 'I have an invite code', 'jlife-challenges' ) . '</a> '
				. '<a class="jl-button" href="' . esc_url( add_query_arg( 'mode', 'solo', home_url( '/join/' ) ) ) . '">' . esc_html__( 'Read on my own', 'jlife-challenges' ) . '</a> '
				. '<a class="jl-button" href="' . esc_url( add_query_arg( 'mode', 'start', home_url( '/join/' ) ) ) . '">' . esc_html__( 'Start a group', 'jlife-challenges' ) . '</a></p>';
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
 * Submit saves the text, scores it and shares it to each group whose box is
 * ticked (one box per group; none by default). The result is shown after a
 * redirect (?done=1 or ?incomplete=1, plus ?shared=ID,ID and
 * ?share_failed[ID]=code), so a refresh never re-posts. A draft save never shares.
 *
 * @param int    $user_id Participant.
 * @param array  $view    Day view.
 * @param string $screen  'today' or 'soap': where the form lives.
 * @return array { error: string, text: string, share_groups: int[], redirect: string }
 *               With a redirect, send the participant there. Otherwise show
 *               "error" and keep the typed "text". Without a POST, "text" is
 *               the saved entry (or the template).
 */
function jlife_challenges_process_soap_request( $user_id, $view, $screen ) {
	$state = array(
		'text'         => jlife_challenges_entry_text( $view ),
		'share_groups' => array(),
		'redirect'     => '',
		'error'        => '',
	);
	if ( ! isset( $_POST['jl_action'] ) || ! check_admin_referer( 'jlife_soap_' . $view['day_key'], '_jlife_soap' ) ) {
		return $state;
	}
	$text          = jlife_challenges_posted_text();
	$state['text'] = $text;
	// One box per group; only the ticked ones arrive. IDs are checked against the author's own groups by the gate.
	$state['share_groups'] = isset( $_POST['jl_share_groups'] ) ? jlife_challenges_clean_group_ids( array_map( 'sanitize_text_field', (array) wp_unslash( $_POST['jl_share_groups'] ) ) ) : array();
	$return                = jlife_challenges_soap_return_url( $view, $screen );
	$cid                   = (int) $view['campaign']['id'];
	if ( 'draft' === sanitize_key( wp_unslash( $_POST['jl_action'] ) ) ) {
		$r = jlife_challenges_save_draft( $user_id, $cid, $view['day_key'], $text );
		if ( is_wp_error( $r ) ) {
			$state['error'] = $r->get_error_message();
		} else {
			$state['redirect'] = $return;
		}
		return $state;
	}
	$r = jlife_challenges_save_and_share( $user_id, $cid, $view['day_key'], $text, $state['share_groups'] );
	if ( is_wp_error( $r ) ) {
		$state['error'] = $r->get_error_message();
		return $state;
	}
	$key    = $r['passes'] ? 'done' : 'incomplete';
	$flags  = array( $key => 1 );
	$shared = array();
	$failed = array();
	foreach ( $r['share']['results'] as $group_id => $result ) {
		if ( 'shared' === $result['status'] ) {
			$shared[] = (int) $group_id;
		} else {
			$failed[ (int) $group_id ] = $result['code'];
		}
	}
	if ( $shared ) {
		$flags['shared'] = implode( ',', $shared );
	}
	if ( $failed ) {
		$flags['share_failed'] = $failed;
	}
	$state['redirect'] = add_query_arg( $flags, $return );
	return $state;
}

/**
 * What the page says after a save, from the redirect's display flags.
 *
 * @return array { done: bool, incomplete: bool, shared: int[] (group IDs), share_failed: array<int,string> (group ID => error code) }
 */
function jlife_challenges_return_flags() {
	// phpcs:disable WordPress.Security.NonceVerification.Recommended -- display flags after a redirect; nothing is changed by them (and group names are only ever read from the viewer's own groups).
	$shared = isset( $_GET['shared'] ) && is_string( $_GET['shared'] ) ? jlife_challenges_clean_group_ids( explode( ',', sanitize_text_field( wp_unslash( $_GET['shared'] ) ) ) ) : array();
	$failed = array();
	if ( isset( $_GET['share_failed'] ) && is_array( $_GET['share_failed'] ) ) {
		foreach ( wp_unslash( $_GET['share_failed'] ) as $group_id => $code ) { // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- the key is cast to int and the value goes through sanitize_key() below.
			if ( (int) $group_id > 0 && is_string( $code ) ) {
				$failed[ (int) $group_id ] = sanitize_key( $code );
			}
		}
	}
	return array(
		'done'         => isset( $_GET['done'] ),
		'incomplete'   => isset( $_GET['incomplete'] ),
		'shared'       => $shared,
		'share_failed' => $failed,
	);
	// phpcs:enable WordPress.Security.NonceVerification.Recommended
}

/**
 * Fill in a page state (see jlife_challenges_process_soap_request() and
 * jlife_challenges_return_flags()) with its defaults.
 *
 * @param array $state Partial state.
 * @return array { error: string, text: string|null, share_groups: int[] (boxes to show ticked), flags: array }
 */
function jlife_challenges_page_state( $state ) {
	$state          = wp_parse_args(
		$state,
		array(
			'error'        => '',
			'text'         => null,
			'share_groups' => array(),
			'flags'        => array(),
		)
	);
	$state['flags'] = wp_parse_args(
		(array) $state['flags'],
		array(
			'done'         => false,
			'incomplete'   => false,
			'shared'       => array(),
			'share_failed' => array(),
		)
	);
	return $state;
}

/**
 * Plain words for why sharing to one group didn't happen after a good save.
 *
 * @param string $code       Share error code from the redirect.
 * @param string $group_name The group's name (one of the viewer's own).
 * @return string
 */
function jlife_challenges_share_failed_message( $code, $group_name ) {
	switch ( $code ) {
		case 'jlife_guardian_consent_required':
			/* translators: %s: group name. */
			return sprintf( __( 'It was not shared with %s: sharing with a group starts once a parent or guardian has agreed. Your entry is saved.', 'jlife-challenges' ), $group_name );
		case 'jlife_share_hidden':
			/* translators: %s: group name. */
			return sprintf( __( 'It was not shared with %s: an organizer hid your post for this day, so it was not posted again. Your entry is saved.', 'jlife-challenges' ), $group_name );
		default:
			/* translators: %s: group name. */
			return sprintf( __( 'Your entry is saved, but it could not be shared with %s this time. Tap Save changes with its box ticked to try again.', 'jlife-challenges' ), $group_name );
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
	// Group names come only from the viewer's own active groups, never from the URL.
	$names = array_column( (array) ( $view['share_groups'] ?? array() ), 'name', 'id' );
	$ok    = array();
	foreach ( (array) $flags['shared'] as $group_id ) {
		if ( isset( $names[ (int) $group_id ] ) ) {
			$ok[] = $names[ (int) $group_id ];
		}
	}
	if ( $ok ) {
		/* translators: %l: a list of group names, e.g. "Group One and Group Two". */
		$out .= '<p class="jl-ok" role="status">' . esc_html( wp_sprintf( __( 'Shared with %l.', 'jlife-challenges' ), $ok ) ) . ' <a href="' . esc_url( home_url( '/group/' ) ) . '">' . esc_html__( 'See My Group', 'jlife-challenges' ) . '</a></p>';
	}
	foreach ( (array) $flags['share_failed'] as $group_id => $code ) {
		if ( isset( $names[ (int) $group_id ] ) ) {
			$out .= '<p class="jl-err" role="alert">' . esc_html( jlife_challenges_share_failed_message( (string) $code, $names[ (int) $group_id ] ) ) . '</p>';
		}
	}
	return $out;
}

/**
 * A link to the SOAP section of the Help page, for the one-line SOAP definition,
 * or '' when the Help page is not set or not published.
 *
 * @return string Escaped HTML, starting with a separator.
 */
function jlife_challenges_soap_help_page_link() {
	$url = jlife_challenges_help_page_url( 'help', '', JLIFE_CHALLENGES_SOAP_HELP_ANCHOR );
	return '' === $url ? '' : ' · <a href="' . esc_url( $url ) . '" class="jl-soap-more">' . esc_html__( 'How SOAP works', 'jlife-challenges' ) . '</a>';
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
	if ( ! empty( $view['closed'] ) ) {
		// A closed campaign (#179): the reading and the person's own words, read-only; no form, no share boxes.
		$body = ! empty( $view['entry'] ) ? trim( (string) $view['entry']['body'] ) : '';
		return jlife_challenges_render_reader( $view )
			. '<h2>' . esc_html__( 'My SOAP', 'jlife-challenges' ) . '</h2>'
			. '<p class="jl-note" role="status">' . esc_html( jlife_challenges_campaign_closed_error()->get_error_message() ) . '</p>'
			. ( '' !== $body ? '<div class="jl-preview">' . esc_html( $body ) . '</div>' : '' );
	}
	$state     = jlife_challenges_page_state( $state );
	$text      = null === $state['text'] ? jlife_challenges_entry_text( $view ) : (string) $state['text'];
	$submitted = jlife_challenges_is_submitted( $view );
	$headings  = jlife_challenges_soap_headings();
	$groups    = (array) ( $view['share_groups'] ?? array() );
	$out       = jlife_challenges_render_reader( $view )
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
		. '<p class="jl-soap-def">' . esc_html__( 'SOAP = Scripture, Observation, Application, Prayer.', 'jlife-challenges' ) . ' <a href="#jlife-soap-help" class="jl-soap-more">' . esc_html__( 'More', 'jlife-challenges' ) . '</a>' . jlife_challenges_soap_help_page_link() . '</p>'
		. '<small>' . esc_html__( 'Write under each heading. Keep the headings: they help you and your group read it.', 'jlife-challenges' ) . '</small>'
		. '<details id="jlife-soap-help" class="jl-optional jl-guidance"><summary>' . esc_html__( 'What goes under each heading?', 'jlife-challenges' ) . '</summary><ul>';
	foreach ( jlife_challenges_soap_guidance() as $letter => $guidance ) {
		$out .= '<li><strong>' . esc_html( $headings[ $letter ] ) . '</strong> ' . esc_html( $guidance ) . '</li>';
	}
	$out .= '</ul></details>';
	// No reference chips here (#188): the reference is typed under S.
	$out .= '<textarea id="jl-text" name="jl_text" rows="16" maxlength="' . esc_attr( (string) JLIFE_CHALLENGES_BODY_MAX ) . '">' . esc_textarea( $text ) . '</textarea>'
		. '<p class="jl-saved" aria-live="polite"></p>'
		. jlife_challenges_render_share_boxes( $groups, (array) $state['share_groups'] );
	return $out
		. '<button type="submit" name="jl_action" value="submit" class="jl-button jl-primary">' . esc_html( $submitted ? __( 'Save changes', 'jlife-challenges' ) : __( 'Submit SOAP', 'jlife-challenges' ) ) . '</button>'
		. ( $submitted ? '' : '<button type="submit" name="jl_action" value="draft" class="jl-button" formnovalidate>' . esc_html__( 'Save draft', 'jlife-challenges' ) . '</button>' )
		. '</form>';
}

/**
 * The share choice on the SOAP form: one checkbox per group the person is in,
 * labelled with the group's name, unticked unless the person ticked it on the
 * last try. No "share with all". A solo reader (no groups) gets nothing at all.
 *
 * @param array[] $groups Rows of { id, name, status } (see jlife_challenges_share_groups()).
 * @param int[]   $ticked Group IDs to show ticked.
 * @return string
 */
function jlife_challenges_render_share_boxes( $groups, $ticked ) {
	if ( ! $groups ) {
		return '';
	}
	$ticked    = array_map( 'intval', $ticked );
	$any_fresh = false;
	$out       = '<fieldset class="jl-share-groups"><legend>' . esc_html__( 'Share this with', 'jlife-challenges' ) . '</legend>';
	foreach ( $groups as $g ) {
		$shared    = 'shared' === $g['status'];
		$any_fresh = $any_fresh || ! $shared;
		if ( $shared ) {
			/* translators: %s: group name. */
			$label = sprintf( __( 'Already shared with %s — tick and save to update', 'jlife-challenges' ), (string) $g['name'] );
		} else {
			$label = (string) $g['name'];
		}
		$out .= '<label class="jl-share-opt"><input type="checkbox" name="jl_share_groups[]" value="' . esc_attr( (string) (int) $g['id'] ) . '"' . checked( in_array( (int) $g['id'], $ticked, true ), true, false ) . '> <span>' . esc_html( $label ) . '</span></label>';
		if ( $shared ) {
			$out .= '<small class="jl-share-note"><a href="' . esc_url( home_url( '/share/' ) ) . '">' . esc_html__( 'Change or remove on the share page', 'jlife-challenges' ) . '</a></small>';
		}
	}
	$note = __( 'This shares the whole entry, including your Prayer, with each group you tick.', 'jlife-challenges' );
	if ( $any_fresh ) {
		$note .= ' ' . __( "Groups you leave unticked don't see this entry.", 'jlife-challenges' );
	}
	return $out . '</fieldset><small class="jl-share-note">' . esc_html( $note ) . '</small>';
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
		// The running score lives on My Marathon only (#137).
		$body .= '<section class="jl-status"><p>' . esc_html( jlife_challenges_status_text( $view ) ) . '</p></section>';
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
	if ( 'today' !== $screen ) {
		jlife_challenges_send_page( $title, jlife_challenges_soap_body( $view, $state ), 'today', 'soap' );
	}
	$saved = isset( $_GET['reminders'] ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display flag after a redirect.
	jlife_challenges_send_page( $title, jlife_challenges_today_page_body( $view, $state, $user_id, $saved ), 'today', array( 'soap', 'reminder' ) );
}

/**
 * Today's whole body: the reading and SOAP form, then the daily reading email
 * checkbox directly under Save draft, outside the SOAP form so it saves on its
 * own (#135).
 *
 * @param array $view    Day view.
 * @param array $state   Page state.
 * @param int   $user_id Participant.
 * @param bool  $saved   Show "Saved." under the checkbox (after a no-JavaScript save).
 * @return string
 */
function jlife_challenges_today_page_body( $view, $state, $user_id, $saved = false ) {
	return jlife_challenges_today_body( $view, $state )
		. jlife_challenges_render_reminder_toggle( $user_id, jlife_challenges_reminder_campaign_id( $user_id ), false, $saved );
}

/**
 * /today: the reading and the SOAP form, with its reader, on one page.
 */
function jlife_challenges_screen_today() {
	$user_id = jlife_challenges_require_participant();
	// The daily reading email checkbox without JavaScript (#135): saved, then back to Today.
	if ( '' !== jlife_challenges_handle_reminder_post( $user_id, jlife_challenges_reminder_campaign_id( $user_id ) ) ) {
		wp_safe_redirect( add_query_arg( 'reminders', 'saved', jlife_challenges_screen_url( 'today' ) ) );
		exit;
	}
	$view = jlife_challenges_day_view( $user_id, jlife_challenges_posted_day_key() );
	$none = jlife_challenges_render_no_day( $view );
	if ( null !== $none ) {
		$saved = isset( $_GET['reminders'] ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display flag after a redirect.
		$body  = jlife_challenges_today_no_day_body( $view, $none, $user_id, $saved );
		jlife_challenges_send_page( __( 'Today', 'jlife-challenges' ), $body, 'today', $body !== $none ? 'reminder' : '' );
	}
	jlife_challenges_run_soap_screen( 'today', __( 'Today', 'jlife-challenges' ), $view, $user_id );
}

/**
 * Today with no reading day. Before Day 1 ("Almost time"), the reminder
 * choice for that upcoming campaign sits under the message (#181: the official
 * Marathon's choice during Dec 14–19 onboarding). It shows only when
 * jlife_challenges_render_reminder_toggle() does (saved Live mode, #189).
 *
 * @param array  $view    Day view.
 * @param string $none    jlife_challenges_render_no_day() for the view.
 * @param int    $user_id Participant.
 * @param bool   $saved   Show "Saved." (after a no-JavaScript save).
 * @return string Escaped HTML.
 */
function jlife_challenges_today_no_day_body( $view, $none, $user_id, $saved = false ) {
	if ( 'before' !== ( $view['state'] ?? '' ) ) {
		return $none;
	}
	return $none . jlife_challenges_render_reminder_toggle( $user_id, (int) $view['campaign']['id'], false, $saved );
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
	if ( isset( $_GET['past'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only selector; past-campaigns.php checks ownership.
		jlife_challenges_screen_past( $user_id, absint( wp_unslash( $_GET['past'] ) ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- as above.
	}
	$view = jlife_challenges_marathon_view( $user_id );
	$cid  = jlife_challenges_reminder_campaign_id( $user_id );
	if ( $view && '' !== jlife_challenges_handle_reminder_post( $user_id, $cid ) ) {
		wp_safe_redirect( add_query_arg( 'reminders', 'saved', jlife_challenges_screen_url( 'marathon' ) ) );
		exit;
	}
	// Language (#159) without JavaScript: saved, then My Marathon again, now in the new language.
	if ( $view && jlife_challenges_handle_language_post( $user_id ) ) {
		wp_safe_redirect( add_query_arg( 'language', 'saved', jlife_challenges_screen_url( 'marathon' ) ) );
		exit;
	}
	// My Bible without JavaScript (#140): saved, then back to My Marathon.
	if ( $view && jlife_challenges_handle_bible_post( $user_id ) ) {
		wp_safe_redirect( add_query_arg( 'bible', 'saved', jlife_challenges_screen_url( 'marathon' ) ) );
		exit;
	}
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
	// Settings first (#204): Language, Bible and, in Live mode only (#189), the reminder checkbox.
	$body = '<h1>' . esc_html__( 'My Marathon', 'jlife-challenges' ) . '</h1>'
		. jlife_challenges_render_reminder_settings( $user_id, $cid, isset( $_GET['reminders'] ), isset( $_GET['bible'] ), isset( $_GET['language'] ) ) // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display flags after a redirect.
		/* translators: 1: points, 2: number of days. */
		. '<p class="jl-score">' . esc_html( sprintf( __( '%1$d of %2$d', 'jlife-challenges' ), $view['points'], $view['day_count'] ) ) . '</p>'
		. '<p>' . esc_html__( 'Every day is a fresh start. Tap any day to read it or write your SOAP.', 'jlife-challenges' ) . '</p>'
		. '<div class="jl-grid">' . $grid . '</div><ul class="jl-legend">' . $legend . '</ul>'
		. jlife_challenges_render_past_section( $user_id ); // Earlier reading periods (#174, D4-A).
	jlife_challenges_send_page( __( 'My Marathon', 'jlife-challenges' ), $body, 'marathon', array( 'language', 'reminder', 'bible' ) );
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
	} elseif ( 'unsubscribe' === $screen ) {
		jlife_challenges_screen_unsubscribe(); // Public: no sign-in; the signed link is the authority.
	} elseif ( defined( 'JLIFE_READING_DAY_CPT' ) && is_singular( JLIFE_READING_DAY_CPT ) ) {
		jlife_challenges_screen_reading_day(); // Public page (#106): a signed-out Today instead of the post body.
	} elseif ( ( is_front_page() || is_home() ) && ! current_user_can( 'edit_posts' ) && is_user_logged_in() ) {
		wp_safe_redirect( home_url( '/today/' ) );
		exit;
	}
}
