<?php
/**
 * The public /reading/<plan>-dNNN/ day pages (#106): a signed-out version of
 * Today. The page is the standalone participant shell (screens.css, no theme)
 * with the same day line, title, reference list, reader and optional parallel
 * readings as Today, built by the same functions in screens.php, then a short
 * invitation and the plan's source line.
 *
 * It is public: it reads the reading-day post and nothing else. Whether
 * someone is signed in decides one thing, the link at the bottom (to write
 * their SOAP for this day); no SOAP, points, status, group or any other
 * participant data is read or shown. There is no frame: Bible Gateway is only
 * ever a link (docs/content-rights.md). The posts, slugs and the "Send to a
 * chat" link (jlife_challenges_public_reading_url()) are unchanged.
 *
 * @package jlife-challenges
 */

defined( 'ABSPATH' ) || exit;

/**
 * The language a public reading page shows: the page's `?lang=` ("en" or "vi",
 * the English | Tiếng Việt switch), else a signed-in person's account language
 * (as Today does), else the site's language (Polylang and similar set it
 * through the `locale` filter).
 *
 * @return string "en" or "vi".
 */
function jlife_challenges_reading_page_language() {
	if ( isset( $_GET['lang'] ) && is_string( $_GET['lang'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- a display language only; type-tested, then sanitized.
		$lang = sanitize_key( wp_unslash( $_GET['lang'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- a display language only.
		if ( in_array( $lang, array( 'en', 'vi' ), true ) ) {
			return $lang;
		}
	}
	if ( is_user_logged_in() ) {
		$account = (string) get_user_meta( get_current_user_id(), 'locale', true );
		if ( '' !== $account ) {
			return 0 === strpos( strtolower( $account ), 'vi' ) ? 'vi' : 'en';
		}
	}
	return 0 === strpos( strtolower( (string) get_locale() ), 'vi' ) ? 'vi' : 'en';
}

/**
 * Links to this page in each language (plain `?lang=` links: no JavaScript,
 * and the page stays cacheable per URL). Shown with the on-page reader, which
 * shows one language's Bible at a time.
 *
 * @param string $url     The reading-day page's address.
 * @param string $current The page's language ("en" or "vi").
 * @return string
 */
function jlife_challenges_render_reading_language_links( $url, $current ) {
	$links = array();
	foreach ( array(
		'en' => 'English',
		'vi' => 'Tiếng Việt',
	) as $lang => $name ) {
		$links[] = '<a href="' . esc_url( add_query_arg( 'lang', $lang, $url ) ) . '" hreflang="' . esc_attr( $lang ) . '" lang="' . esc_attr( $lang ) . '"'
			. ( $lang === $current ? ' aria-current="true"' : '' ) . '>' . esc_html( $name ) . '</a>';
	}
	return '<p class="jl-reading-lang">' . implode( ' | ', $links ) . '</p>';
}

/**
 * The public page's day view: the same shape as Today's (reading, languages,
 * index, date), built from the reading-day post alone, in the page's language.
 *
 * @param WP_Post $post Reading-day post.
 * @param string  $lang The page's language ("en" or "vi").
 * @return array|null Null when the post has no imported day.
 */
function jlife_challenges_public_reading_view( $post, $lang ) {
	$reading = jlife_studies_reading_day_for_post( $post, $lang );
	if ( ! is_array( $reading['day'] ) || ! is_array( $reading['plan'] ) ) {
		return null;
	}
	return array(
		'public'    => true,
		'language'  => $lang,
		'reading'   => $reading,
		'languages' => jlife_challenges_order_languages( $reading['plan']['links_config'] ?? array(), $lang ),
		'index'     => (int) $reading['day']['day'],
		'date'      => (string) $reading['day']['date'],
		'day_key'   => sanitize_key( (string) ( $reading['day']['day_id'] ?? '' ) ),
	);
}

/**
 * The short invitation at the foot of a public reading page. A visitor gets
 * links to join and to sign in (the existing pages, no code or personal data
 * in the address). Someone signed in gets one link, to write their SOAP for
 * this day; that is all the page knows about them.
 *
 * @param array $view Public day view.
 * @return string
 */
function jlife_challenges_render_public_invite( $view ) {
	if ( is_user_logged_in() ) {
		return '<section class="jl-invite"><a class="jl-button jl-primary" href="' . esc_url( jlife_challenges_screen_url( 'soap', (string) $view['day_key'] ) ) . '">'
			. esc_html__( 'Write my SOAP for this day', 'jlife-challenges' ) . '</a></section>';
	}
	// The forms open in the page's language (they read `?lang=` for visitors);
	// the language is the only thing added to the address.
	$lang = 'vi' === ( $view['language'] ?? '' ) ? 'vi' : 'en';
	return '<section class="jl-invite"><p>' . esc_html__( 'Read along with us and write your own SOAP.', 'jlife-challenges' ) . '</p>'
		. '<a class="jl-button jl-primary" href="' . esc_url( add_query_arg( 'lang', $lang, home_url( '/join/' ) ) ) . '">' . esc_html__( 'Join the Bible Marathon', 'jlife-challenges' ) . '</a>'
		. '<a class="jl-button" href="' . esc_url( add_query_arg( 'lang', $lang, home_url( '/signin/' ) ) ) . '">' . esc_html__( 'Sign in', 'jlife-challenges' ) . '</a></section>';
}

/**
 * The public page's body, in Today's order: day and date, title and
 * references, the reader, the optional parallel readings, the invitation and
 * the plan's source line.
 *
 * @param array $view Public day view (jlife_challenges_public_reading_view()).
 * @return string
 */
function jlife_challenges_render_public_reading( $view ) {
	$out = jlife_challenges_render_day_head( $view )
		. '<section class="jl-reading">' . jlife_challenges_render_reading_head( $view ) . '</section>'
		. jlife_challenges_render_reader( $view )
		. jlife_challenges_render_devotional( $view )
		. jlife_challenges_render_optional_readings( $view['reading'] )
		. jlife_challenges_render_public_invite( $view );

	$source = (string) ( $view['reading']['plan']['source_attribution'] ?? '' );
	return '' === $source ? $out : $out . '<p class="jl-source"><small>' . esc_html( $source ) . '</small></p>';
}

/**
 * The whole public page for a reading-day post, in the page's language.
 *
 * @param int $post_id Reading-day post.
 * @return string|null The page, or null when the post is not an imported reading day.
 */
function jlife_challenges_public_reading_page_html( $post_id ) {
	$post = (int) $post_id > 0 ? get_post( (int) $post_id ) : null;
	if ( ! $post || ! defined( 'JLIFE_READING_DAY_CPT' ) || JLIFE_READING_DAY_CPT !== $post->post_type || ! function_exists( 'jlife_studies_reading_day_for_post' ) ) {
		return null;
	}
	$lang  = jlife_challenges_reading_page_language();
	$build = static function () use ( $post, $lang ) {
		$view = jlife_challenges_public_reading_view( $post, $lang );
		if ( null === $view ) {
			return null;
		}
		/* translators: 1: day number, 2: the day's title. */
		$title = sprintf( __( 'Day %1$d · %2$s', 'jlife-challenges' ), $view['index'], (string) $view['reading']['day']['title'] );
		return jlife_challenges_page_html( $title, jlife_challenges_render_public_reading( $view ), 'public', '', $lang );
	};
	// The plugins' own strings follow the page's language, not only the site's.
	return function_exists( 'jlife_auth_in_locale' ) ? jlife_auth_in_locale( 'vi' === $lang ? 'vi' : 'en_US', $build ) : $build();
}

/**
 * Send the public page for the reading-day post being viewed and stop. A post
 * with no imported day is left to the theme, as before.
 */
function jlife_challenges_screen_reading_day() {
	$html = jlife_challenges_public_reading_page_html( (int) get_queried_object_id() );
	if ( null === $html ) {
		return;
	}
	// The page is the same for every visitor; only the signed-in link differs, so a signed-in view is never cached.
	if ( is_user_logged_in() ) {
		nocache_headers();
	}
	header( 'Content-Type: text/html; charset=utf-8' );
	header( 'X-Robots-Tag: noindex, nofollow' );
	echo $html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built from escaped parts.
	exit;
}
