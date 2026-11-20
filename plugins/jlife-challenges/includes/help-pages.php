<?php
/**
 * Help, Rules and Privacy pages (#65): where they are, how the participant
 * screens link to them, and how they are seeded once.
 *
 * - The three pages are ordinary WordPress pages that the owner edits in
 *   wp-admin. Their IDs are site options (like jlife_scripture_copyright_page_id);
 *   no URL is hard-coded.
 * - With Polylang, a link goes to the page's translation in the reader's
 *   language (pll_get_post, guarded by function_exists), else to the page itself.
 * - A link is left out, not broken, when the option is unset or the page is not
 *   published.
 * - `wp jlife help-pages seed` creates the pages ONCE, as drafts, in English and
 *   Vietnamese (data/help-pages.json). It never updates a page. The wording is
 *   the owner's: the seed is marked DRAFT.
 *
 * @package jlife-challenges
 */

defined( 'ABSPATH' ) || exit;

const JLIFE_CHALLENGES_HELP_SEEDED_OPTION = 'jlife_challenges_help_pages_seeded';
const JLIFE_CHALLENGES_HELP_SEED_META     = '_jlife_help_seed';
const JLIFE_CHALLENGES_SOAP_HELP_ANCHOR   = 'what-is-soap';

/**
 * The site options that hold the three pages' IDs.
 *
 * @return array<string,string> Page key => option name.
 */
function jlife_challenges_help_page_options() {
	return array(
		'help'    => 'jlife_challenges_help_page_id',
		'rules'   => 'jlife_challenges_rules_page_id',
		'privacy' => 'jlife_challenges_privacy_page_id',
	);
}

/**
 * The language of the links: the page's own (Polylang slug en or vi).
 *
 * @return string
 */
function jlife_challenges_help_language() {
	return 0 === strpos( (string) determine_locale(), 'vi' ) ? 'vi' : 'en';
}

/**
 * Is this ID a published page a participant can open?
 *
 * @param int $page_id Post ID.
 * @return bool
 */
function jlife_challenges_help_page_is_public( $page_id ) {
	return $page_id > 0 && 'page' === get_post_type( $page_id ) && 'publish' === get_post_status( $page_id );
}

/**
 * The address of one of the three pages in the reader's language, or '' when
 * there is nothing to link to. With Polylang the translation is used when it is
 * published, else the page the option names; without Polylang the page itself.
 *
 * @param string $which    help, rules or privacy.
 * @param string $language Polylang language slug; '' = the current page language.
 * @param string $fragment Anchor on the page, without '#'.
 * @return string
 */
function jlife_challenges_help_page_url( $which, $language = '', $fragment = '' ) {
	$options = jlife_challenges_help_page_options();
	if ( ! isset( $options[ $which ] ) ) {
		return '';
	}
	$page_id = (int) get_option( $options[ $which ], 0 );
	if ( $page_id < 1 ) {
		return '';
	}
	$language   = '' !== $language ? $language : jlife_challenges_help_language();
	$candidates = array();
	if ( function_exists( 'pll_get_post' ) ) {
		$candidates[] = (int) pll_get_post( $page_id, $language );
	}
	$candidates[] = $page_id;
	foreach ( $candidates as $candidate ) {
		if ( jlife_challenges_help_page_is_public( $candidate ) ) {
			$url = (string) get_permalink( $candidate );
			return '' !== $fragment ? $url . '#' . rawurlencode( $fragment ) : $url;
		}
	}
	return '';
}

/**
 * The small footer on every participant screen: Help, Rules and Privacy, each
 * only when its page exists and is published. Empty when none is.
 *
 * @return string Escaped HTML.
 */
function jlife_challenges_help_footer() {
	$links = array();
	foreach ( array(
		'help'    => __( 'Help', 'jlife-challenges' ),
		'rules'   => __( 'Rules', 'jlife-challenges' ),
		'privacy' => __( 'Privacy', 'jlife-challenges' ),
	) as $which => $label ) {
		$url = jlife_challenges_help_page_url( $which );
		if ( '' !== $url ) {
			$links[] = '<a href="' . esc_url( $url ) . '">' . esc_html( $label ) . '</a>';
		}
	}
	if ( ! $links ) {
		return '';
	}
	return '<footer class="jl-help-links"><nav aria-label="' . esc_attr__( 'Help, rules and privacy', 'jlife-challenges' ) . '">' . implode( ' · ', $links ) . '</nav></footer>';
}

/**
 * The seed copy: pages keyed help, rules and privacy, each with en and vi.
 *
 * @return array<string,array<string,array>>
 */
function jlife_challenges_help_pages_data() {
	$data = wp_json_file_decode( dirname( __DIR__ ) . '/data/help-pages.json', array( 'associative' => true ) );
	$out  = array();
	foreach ( array_keys( jlife_challenges_help_page_options() ) as $key ) {
		if ( is_array( $data ) && isset( $data[ $key ] ) && is_array( $data[ $key ] ) ) {
			$out[ $key ] = $data[ $key ];
		}
	}
	return $out;
}

/**
 * Plain text to HTML: escapes, then **bold**.
 *
 * @param string $text Text.
 * @return string
 */
function jlife_challenges_help_inline( $text ) {
	return (string) preg_replace( '/\*\*(.+?)\*\*/u', '<strong>$1</strong>', esc_html( $text ) );
}

/**
 * Block-editor markup for a page's blocks (h2, p, ul), as the editor itself
 * saves them, so the owner can edit every block without a recovery prompt.
 *
 * @param array[] $blocks Rows of { type, text or items, optional anchor }.
 * @return string
 */
function jlife_challenges_help_blocks_html( $blocks ) {
	$out = array();
	foreach ( $blocks as $block ) {
		$type = (string) $block[0];
		if ( 'h2' === $type ) {
			$id    = isset( $block[2] ) ? ' id="' . esc_attr( (string) $block[2] ) . '"' : '';
			$out[] = "<!-- wp:heading -->\n<h2 class=\"wp-block-heading\"" . $id . '>' . jlife_challenges_help_inline( (string) $block[1] ) . "</h2>\n<!-- /wp:heading -->";
		} elseif ( 'ul' === $type ) {
			$items = array();
			foreach ( (array) $block[1] as $item ) {
				$items[] = "<!-- wp:list-item -->\n<li>" . jlife_challenges_help_inline( (string) $item ) . "</li>\n<!-- /wp:list-item -->";
			}
			$out[] = "<!-- wp:list -->\n<ul class=\"wp-block-list\">" . implode( "\n\n", $items ) . "</ul>\n<!-- /wp:list -->";
		} else {
			$out[] = "<!-- wp:paragraph -->\n<p>" . jlife_challenges_help_inline( (string) $block[1] ) . "</p>\n<!-- /wp:paragraph -->";
		}
	}
	return implode( "\n\n", $out ) . "\n";
}

/**
 * Is Polylang ready to give the pages a language and link translations?
 *
 * @return bool
 */
function jlife_challenges_help_polylang_ready() {
	$ready = function_exists( 'pll_set_post_language' ) && function_exists( 'pll_save_post_translations' ) && function_exists( 'pll_get_post' );
	return (bool) apply_filters( 'jlife_challenges_help_polylang_ready', $ready );
}

/**
 * The seeded page for a key such as help-en, in ANY status (trash included), or 0.
 *
 * @param string $seed_key Key stored in the page's seed meta.
 * @return int
 */
function jlife_challenges_find_seeded_help_page( $seed_key ) {
	$ids = get_posts(
		array(
			'post_type'   => 'page',
			'post_status' => array( 'publish', 'draft', 'pending', 'private', 'future', 'trash' ),
			'meta_key'    => JLIFE_CHALLENGES_HELP_SEED_META, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- a one-time lookup of three pages.
			'meta_value'  => $seed_key, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- a one-time lookup of three pages.
			'numberposts' => 1,
			'fields'      => 'ids',
		)
	);
	return $ids ? (int) $ids[0] : 0;
}

/**
 * Create one seed page as a draft. The copy is trusted code from this
 * repository, so it is stored byte-exact (kses off for the insert).
 *
 * @param string $seed_key Seed key, such as privacy-vi.
 * @param string $language en or vi.
 * @param array  $page     { title, slug, blocks }.
 * @return int|WP_Error
 */
function jlife_challenges_create_help_page( $seed_key, $language, $page ) {
	$admins = get_super_admins();
	$author = $admins ? get_user_by( 'login', (string) $admins[0] ) : false;
	kses_remove_filters();
	$id = wp_insert_post(
		array(
			'post_type'    => 'page',
			'post_status'  => 'draft', // Nothing is public until the owner publishes.
			'post_title'   => (string) $page['title'],
			'post_name'    => (string) $page['slug'],
			'post_content' => jlife_challenges_help_blocks_html( (array) $page['blocks'] ),
			'post_author'  => $author ? (int) $author->ID : 1,
		),
		true
	);
	kses_init();
	if ( is_wp_error( $id ) ) {
		return $id;
	}
	update_post_meta( $id, JLIFE_CHALLENGES_HELP_SEED_META, $seed_key );
	if ( function_exists( 'pll_set_post_language' ) ) {
		pll_set_post_language( $id, $language );
	}
	return (int) $id;
}

/**
 * Seed the three pages ONCE, as drafts, in English and Vietnamese, linked as
 * translations, and record the English page IDs in the site options.
 *
 * - Once the seed has run (option), nothing is touched again, even if the owner
 *   deleted a page. A page that exists in any status is never recreated or
 *   updated, so an interrupted first run can finish and an edit is never lost.
 * - An option the owner already set (for example to a page they wrote
 *   themselves) is left alone.
 * - Needs Polylang (languages en and vi); creates nothing without it.
 *
 * @return array|WP_Error { created: string[], existing: string[], seeded_before: bool }
 */
function jlife_challenges_seed_help_pages() {
	$result = array(
		'created'       => array(),
		'existing'      => array(),
		'seeded_before' => false,
	);
	if ( get_option( JLIFE_CHALLENGES_HELP_SEEDED_OPTION ) ) {
		$result['seeded_before'] = true;
		return $result;
	}
	if ( ! jlife_challenges_help_polylang_ready() ) {
		return new WP_Error( 'jlife_help_no_polylang', 'Polylang is not active on this site: the pages are English and Vietnamese, so they are not seeded.' );
	}
	$options = jlife_challenges_help_page_options();
	foreach ( jlife_challenges_help_pages_data() as $key => $languages ) {
		$ids = array();
		foreach ( array( 'en', 'vi' ) as $language ) {
			if ( empty( $languages[ $language ] ) ) {
				return new WP_Error( 'jlife_help_data', "The seed copy has no {$language} version of {$key}." );
			}
			$seed_key = $key . '-' . $language;
			$id       = jlife_challenges_find_seeded_help_page( $seed_key );
			if ( $id ) {
				$result['existing'][] = $seed_key;
			} else {
				$id = jlife_challenges_create_help_page( $seed_key, $language, $languages[ $language ] );
				if ( is_wp_error( $id ) ) {
					return $id;
				}
				$result['created'][] = $seed_key;
			}
			$ids[ $language ] = (int) $id;
		}
		if ( function_exists( 'pll_get_post' ) && function_exists( 'pll_save_post_translations' ) && (int) pll_get_post( $ids['en'], 'vi' ) !== $ids['vi'] ) {
			pll_save_post_translations( $ids );
		}
		if ( (int) get_option( $options[ $key ], 0 ) < 1 ) {
			update_option( $options[ $key ], $ids['en'], false );
		}
	}
	update_option( JLIFE_CHALLENGES_HELP_SEEDED_OPTION, '1', false );
	return $result;
}
