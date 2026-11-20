<?php
/**
 * A stand-in for Polylang's pll_get_post(), for tests: the translations come
 * from the $GLOBALS['jlife_scripture_test_pll'] map { post ID: { language: translated post ID } }.
 *
 * @package jlife-scripture
 */

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals -- this is Polylang's own function name.

if ( ! function_exists( 'pll_get_post' ) ) {
	/**
	 * The translation of a post in a language, like Polylang's.
	 *
	 * @param int    $post_id  Post ID.
	 * @param string $language Language slug.
	 * @return int|false The translated post ID, or false.
	 */
	function pll_get_post( $post_id, $language = '' ) {
		$map = $GLOBALS['jlife_scripture_test_pll'] ?? array();
		$id  = (int) ( $map[ (int) $post_id ][ (string) $language ] ?? 0 );
		return $id > 0 ? $id : false;
	}
}
