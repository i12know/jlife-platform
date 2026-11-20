<?php
/**
 * Pilot feedback link (#171): a small "Pilot Feedback" link at the foot of the
 * participant screens, to one ordinary WordPress post where testers leave
 * moderated comments.
 *
 * - The post is the owner's: it is written, published and moderated in
 *   wp-admin. This code creates no post content and never changes the post or
 *   its comment settings.
 * - The post's ID is a site option (like jlife_challenges_help_page_id); no URL
 *   is hard-coded. The address comes from get_permalink().
 * - With Polylang, a published translation in the reader's language is used
 *   when one exists; otherwise every language goes to the same (bilingual) post.
 * - No link at all when the option is unset or the post is missing or not
 *   published. Ending the pilot is a config change: delete the option.
 * - Commenting is for signed-in accounts only (owner decision; the owner sets
 *   it in Settings > Discussion). On this post alone, WordPress's "log in to
 *   comment" link goes to /signin instead of wp-login.php.
 * - Reads no participant data.
 *
 * @package jlife-challenges
 */

defined( 'ABSPATH' ) || exit;

const JLIFE_CHALLENGES_PILOT_FEEDBACK_OPTION = 'jlife_pilot_feedback_post_id';

/**
 * Is this ID a published post or page anyone can open?
 *
 * @param int $post_id Post ID.
 * @return bool
 */
function jlife_challenges_pilot_feedback_is_public( $post_id ) {
	return $post_id > 0
		&& in_array( get_post_type( $post_id ), array( 'post', 'page' ), true )
		&& 'publish' === get_post_status( $post_id )
		&& '' === (string) get_post_field( 'post_password', $post_id );
}

/**
 * The feedback post's address in the reader's language, or '' when there is
 * nothing to link to.
 *
 * @param string $language Polylang language slug; '' = the current page language.
 * @return string
 */
function jlife_challenges_pilot_feedback_url( $language = '' ) {
	$post_id = (int) get_option( JLIFE_CHALLENGES_PILOT_FEEDBACK_OPTION, 0 );
	if ( $post_id < 1 ) {
		return '';
	}
	$language   = '' !== $language ? $language : jlife_challenges_help_language();
	$candidates = array();
	if ( function_exists( 'pll_get_post' ) ) {
		$candidates[] = (int) pll_get_post( $post_id, $language );
	}
	$candidates[] = $post_id;
	foreach ( $candidates as $candidate ) {
		if ( jlife_challenges_pilot_feedback_is_public( $candidate ) ) {
			return (string) get_permalink( $candidate );
		}
	}
	return '';
}

/**
 * The link itself, labelled in the screen's language, or ''.
 *
 * @return string Escaped HTML.
 */
function jlife_challenges_pilot_feedback_link() {
	$url = jlife_challenges_pilot_feedback_url();
	if ( '' === $url ) {
		return '';
	}
	return '<a class="jl-pilot-feedback" href="' . esc_url( $url ) . '">' . esc_html__( 'Pilot Feedback', 'jlife-challenges' ) . '</a>';
}

/**
 * Is this address the feedback post (or its published translation)? Query
 * string and fragment are ignored.
 *
 * @param string $url Address.
 * @return bool
 */
function jlife_challenges_is_pilot_feedback_url( $url ) {
	$url = strtok( (string) $url, '?#' );
	if ( false === $url ) {
		return false;
	}
	foreach ( array( 'en', 'vi' ) as $language ) {
		$feedback = jlife_challenges_pilot_feedback_url( $language );
		if ( '' !== $feedback && untrailingslashit( $feedback ) === untrailingslashit( $url ) ) {
			return true;
		}
	}
	return false;
}

add_filter( 'login_url', 'jlife_challenges_pilot_feedback_login_url', 10, 2 );

/**
 * Comments on the feedback post are for signed-in accounts only (owner
 * decision, #171). WordPress's "log in to post a comment" link goes to
 * wp-login.php, where participants cannot sign in (they use emailed codes), so
 * for that one post the link goes to /signin and comes back to the post. Every
 * other login link is unchanged.
 *
 * @param string $login_url Login address.
 * @param string $redirect  Where to return after signing in.
 * @return string
 */
function jlife_challenges_pilot_feedback_login_url( $login_url, $redirect ) {
	if ( '' === (string) $redirect || ! jlife_challenges_is_pilot_feedback_url( $redirect ) ) {
		return $login_url;
	}
	return add_query_arg( 'redirect_to', rawurlencode( strtok( (string) $redirect, '#' ) . '#respond' ), home_url( '/signin/' ) );
}

/**
 * A footer holding only the feedback link, for the public /reading/ pages,
 * which have no Help, Rules and Privacy footer. Empty when there is no link.
 *
 * @return string Escaped HTML.
 */
function jlife_challenges_pilot_feedback_footer() {
	$link = jlife_challenges_pilot_feedback_link();
	if ( '' === $link ) {
		return '';
	}
	return '<footer class="jl-help-links"><nav aria-label="' . esc_attr__( 'Pilot feedback', 'jlife-challenges' ) . '">' . $link . '</nav></footer>';
}
