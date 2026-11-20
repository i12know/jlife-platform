<?php
/**
 * Stand-ins for the Polylang functions the help pages use, for tests. The
 * translations live in the $GLOBALS['jlife_challenges_test_pll'] map
 * { post ID: { language: translated post ID } } and the language of a post is
 * kept in the post meta _test_pll_language.
 *
 * @package jlife-challenges
 */

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals -- these are Polylang's own function names.

if ( ! function_exists( 'pll_get_post' ) ) {
	/**
	 * The translation of a post in a language, like Polylang's.
	 *
	 * @param int    $post_id  Post ID.
	 * @param string $language Language slug.
	 * @return int|false The translated post ID, or false.
	 */
	function pll_get_post( $post_id, $language = '' ) {
		$map = $GLOBALS['jlife_challenges_test_pll'] ?? array();
		$id  = (int) ( $map[ (int) $post_id ][ (string) $language ] ?? 0 );
		return $id > 0 ? $id : false;
	}
}

if ( ! function_exists( 'pll_set_post_language' ) ) {
	/**
	 * Record a post's language, like Polylang's.
	 *
	 * @param int    $post_id  Post ID.
	 * @param string $language Language slug.
	 */
	function pll_set_post_language( $post_id, $language ) {
		update_post_meta( (int) $post_id, '_test_pll_language', (string) $language );
	}
}

if ( ! function_exists( 'pll_save_post_translations' ) ) {
	/**
	 * Link posts as translations of each other, like Polylang's.
	 *
	 * @param int[] $translations Language slug => post ID.
	 * @return int[]
	 */
	function pll_save_post_translations( $translations ) {
		foreach ( $translations as $id ) {
			$GLOBALS['jlife_challenges_test_pll'][ (int) $id ] = array_map( 'intval', $translations );
		}
		return $translations;
	}
}
