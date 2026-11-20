<?php
/**
 * Reading plans (#59): one public page per scheduled day, imported from the
 * portable JSON built by bin/build-reading-plans.js (content/reading-plans/).
 *
 * References and external Bible links only; no Bible text is stored in the
 * posts (docs/bible-quotation-permissions.md). The one exception is display
 * only: when the site option jlife_scripture_native_reader is on, the page
 * shows the page language's default Bible through the jlife-scripture plugin
 * (public-domain text, or the Bible Gateway link) instead of the link blocks.
 * The JSON files stay the source of truth; posts are their rendering and are
 * upserted by (plan_id, day_id).
 *
 * A supplemental plan (optional parallel readings) is attached to the
 * primary plan's day of the same day_id; it never creates its own pages.
 *
 * @package jlife-studies
 */

defined( 'ABSPATH' ) || exit;

const JLIFE_READING_DAY_CPT = 'jlife_reading_day';

add_action( 'init', 'jlife_studies_register_reading_day' );

/**
 * Register the reading-day post type (public URL: /reading/<slug>/).
 */
function jlife_studies_register_reading_day() {
	register_post_type(
		JLIFE_READING_DAY_CPT,
		array(
			'labels'       => array(
				'name'          => __( 'Reading days', 'jlife-studies' ),
				'singular_name' => __( 'Reading day', 'jlife-studies' ),
			),
			'public'       => true,
			'has_archive'  => false,
			'show_in_rest' => false,
			'menu_icon'    => 'dashicons-calendar-alt',
			'supports'     => array( 'title' ),
			// with_front false: no "/blog/" prefix on a multisite main site.
			'rewrite'      => array(
				'slug'       => 'reading',
				'with_front' => false,
			),
		)
	);
}

/**
 * Basic shape check for a decoded plan document.
 *
 * @param mixed $doc Decoded JSON.
 * @return true|WP_Error
 */
function jlife_studies_validate_reading_plan( $doc ) {
	if ( ! is_array( $doc ) || ( $doc['schema_version'] ?? '' ) !== '1.0' ) {
		return new WP_Error( 'jlife_plan_version', __( 'Not a reading plan (schema_version 1.0 expected).', 'jlife-studies' ) );
	}
	if ( ! preg_match( '/^rpl-[a-z0-9]+(-[a-z0-9]+)*$/', (string) ( $doc['plan_id'] ?? '' ) ) ) {
		return new WP_Error( 'jlife_plan_id', __( 'Invalid plan_id.', 'jlife-studies' ) );
	}
	if ( ! in_array( $doc['role'] ?? '', array( 'primary', 'supplemental' ), true ) ) {
		return new WP_Error( 'jlife_plan_role', __( 'role must be primary or supplemental.', 'jlife-studies' ) );
	}
	if ( empty( $doc['days'] ) || ! is_array( $doc['days'] ) || count( $doc['days'] ) !== (int) ( $doc['day_count'] ?? -1 ) ) {
		return new WP_Error( 'jlife_plan_days', __( 'days must contain exactly day_count entries.', 'jlife-studies' ) );
	}
	foreach ( $doc['days'] as $day ) {
		if ( ! is_array( $day ) || ! preg_match( '/^d[0-9]{3}$/', (string) ( $day['day_id'] ?? '' ) ) || empty( $day['passages'] ) || empty( $day['links'] ) ) {
			return new WP_Error( 'jlife_plan_day', __( 'Every day needs a day_id (d###), passages and links.', 'jlife-studies' ) );
		}
	}
	return true;
}

/**
 * Find the reading-day post for a plan and day.
 *
 * @param string $plan_id Plan ID.
 * @param string $day_id  Day ID (d###).
 * @return WP_Post|null
 */
function jlife_studies_find_reading_day( $plan_id, $day_id ) {
	$posts = get_posts(
		array(
			'post_type'      => JLIFE_READING_DAY_CPT,
			'post_status'    => 'any',
			'posts_per_page' => 1,
			'meta_query'     => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- small, indexed by import; reads are by post ID.
				array(
					'key'   => '_jlife_plan_id',
					'value' => $plan_id,
				),
				array(
					'key'   => '_jlife_day_id',
					'value' => $day_id,
				),
			),
		)
	);
	return $posts ? $posts[0] : null;
}

/**
 * Store a value as JSON post meta (strings such as "4.40" stay strings).
 *
 * @param int    $post_id Post ID.
 * @param string $key     Meta key.
 * @param mixed  $value   Value to encode.
 */
function jlife_studies_put_json_meta( $post_id, $key, $value ) {
	update_post_meta( $post_id, $key, wp_slash( (string) wp_json_encode( $value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) ) );
}

/**
 * Read JSON post meta.
 *
 * @param int    $post_id Post ID.
 * @param string $key     Meta key.
 * @return mixed|null
 */
function jlife_studies_get_json_meta( $post_id, $key ) {
	$raw = get_post_meta( $post_id, $key, true );
	return is_string( $raw ) && '' !== $raw ? json_decode( $raw, true ) : null;
}

/**
 * Import one reading plan document (upsert by plan_id + day_id).
 *
 * @param array $doc Decoded plan JSON.
 * @return int|WP_Error Number of days imported.
 */
function jlife_studies_import_reading_plan( $doc ) {
	$valid = jlife_studies_validate_reading_plan( $doc );
	if ( is_wp_error( $valid ) ) {
		return $valid;
	}

	$plan_meta = array(
		'plan_id'            => $doc['plan_id'],
		'title'              => (string) ( $doc['title'] ?? '' ),
		'source_attribution' => (string) ( $doc['source_attribution'] ?? '' ),
		'links_config'       => $doc['links_config'] ?? array(),
	);

	if ( 'supplemental' === $doc['role'] ) {
		$primary_id = (string) ( $doc['primary_plan_id'] ?? '' );
		$targets    = array();
		foreach ( $doc['days'] as $day ) {
			$post = jlife_studies_find_reading_day( $primary_id, $day['day_id'] );
			if ( ! $post ) {
				/* translators: 1: primary plan ID, 2: day ID. */
				return new WP_Error( 'jlife_plan_primary_missing', sprintf( __( 'Import the primary plan %1$s first (missing %2$s).', 'jlife-studies' ), $primary_id, $day['day_id'] ) );
			}
			$targets[] = array( $post->ID, $day );
		}
		foreach ( $targets as list( $post_id, $day ) ) {
			jlife_studies_put_json_meta(
				$post_id,
				'_jlife_supplemental',
				array(
					'plan' => $plan_meta,
					'day'  => $day,
				)
			);
		}
		return count( $targets );
	}

	$slug_base = preg_replace( '/^rpl-/', '', $doc['plan_id'] );
	foreach ( $doc['days'] as $day ) {
		$existing = jlife_studies_find_reading_day( $doc['plan_id'], $day['day_id'] );
		$postarr  = array(
			'post_type'   => JLIFE_READING_DAY_CPT,
			'post_title'  => (string) $day['title'],
			'post_name'   => sanitize_title( $slug_base . '-' . $day['day_id'] ),
			'post_status' => 'publish',
		);
		if ( $existing ) {
			$postarr['ID'] = $existing->ID;
			$post_id       = wp_update_post( wp_slash( $postarr ), true );
		} else {
			$post_id = wp_insert_post( wp_slash( $postarr ), true );
		}
		if ( is_wp_error( $post_id ) ) {
			return $post_id;
		}
		update_post_meta( $post_id, '_jlife_plan_id', $doc['plan_id'] );
		update_post_meta( $post_id, '_jlife_day_id', $day['day_id'] );
		update_post_meta( $post_id, '_jlife_day', (int) $day['day'] );
		update_post_meta( $post_id, '_jlife_date', (string) $day['date'] );
		jlife_studies_put_json_meta( $post_id, '_jlife_reading_day', $day );
		jlife_studies_put_json_meta( $post_id, '_jlife_plan', $plan_meta );
	}
	return count( $doc['days'] );
}

/**
 * A reading day in the reader's language (#119). The plan build stores each
 * language's day title and passage references under `i18n.<lang>` (for
 * Vietnamese: draft titles and Protestant book names, "Giăng 4:4-26"). For a
 * language with an entry, the day's `title`, `titles` and each passage's
 * `display` are swapped; the parsed references and Bible links never change.
 * A missing or incomplete entry leaves the day in English.
 *
 * @param array|null $day    Day data.
 * @param string     $locale WordPress locale (vi, en_US, ...).
 * @return array|null
 */
function jlife_studies_localize_day( $day, $locale ) {
	$lang = strtolower( substr( (string) $locale, 0, 2 ) );
	if ( ! is_array( $day ) || 'en' === $lang || empty( $day['i18n'][ $lang ] ) || ! is_array( $day['i18n'][ $lang ] ) ) {
		return $day;
	}
	$tr = $day['i18n'][ $lang ];
	if ( isset( $tr['title'] ) && is_string( $tr['title'] ) && '' !== trim( $tr['title'] ) ) {
		$day['title']  = $tr['title'];
		$day['titles'] = array_values( array_filter( array_map( 'trim', explode( '|', $tr['title'] ) ), static fn( $part ) => '' !== $part ) );
	}
	$displays = isset( $tr['passages'] ) && is_array( $tr['passages'] ) ? array_values( $tr['passages'] ) : array();
	$passages = isset( $day['passages'] ) && is_array( $day['passages'] ) ? $day['passages'] : array();
	if ( count( $displays ) === count( $passages ) ) {
		foreach ( array_keys( $passages ) as $i => $key ) {
			if ( is_string( $displays[ $i ] ) && '' !== trim( $displays[ $i ] ) && is_array( $passages[ $key ] ) ) {
				$day['passages'][ $key ]['display'] = $displays[ $i ];
			}
		}
	}
	return $day;
}

/**
 * Get a reading day as data (for the Today page and other surfaces), in the
 * current reader's language where the plan has it.
 *
 * @param string $plan_id Plan ID.
 * @param string $day_id  Day ID (d###).
 * @return array|null { day, plan, supplemental, post_id, url }
 */
function jlife_studies_get_reading_day( $plan_id, $day_id ) {
	$post = jlife_studies_find_reading_day( $plan_id, $day_id );
	if ( ! $post ) {
		return null;
	}
	return array(
		'post_id'      => $post->ID,
		'url'          => get_permalink( $post ),
		'day'          => jlife_studies_localize_day( jlife_studies_get_json_meta( $post->ID, '_jlife_reading_day' ), determine_locale() ),
		'plan'         => jlife_studies_get_json_meta( $post->ID, '_jlife_plan' ),
		'supplemental' => jlife_studies_get_json_meta( $post->ID, '_jlife_supplemental' ),
	);
}

/**
 * Render the passage list and Bible links for one day.
 *
 * @param array $day          Day data.
 * @param array $links_config Language => provider config.
 * @return string HTML.
 */
function jlife_studies_render_reading_links( $day, $links_config ) {
	$out = '<ul class="jlife-passages">';
	foreach ( (array) $day['passages'] as $passage ) {
		$out .= '<li>' . esc_html( (string) $passage['display'] ) . '</li>';
	}
	$out .= '</ul>';

	foreach ( (array) $links_config as $lang => $cfg ) {
		$links = $day['links'][ $lang ] ?? null;
		if ( ! is_array( $links ) ) {
			continue;
		}
		$out .= '<div class="jlife-bible-links" lang="' . esc_attr( (string) $lang ) . '">';
		/* translators: 1: language name, 2: Bible version code. */
		$out .= '<h3>' . esc_html( sprintf( __( '%1$s (%2$s)', 'jlife-studies' ), (string) ( $cfg['label'] ?? $lang ), (string) $links['version'] ) ) . '</h3>';
		if ( ! empty( $links['combined']['url'] ) ) {
			$out .= '<p><a class="jlife-read-all" href="' . esc_url( $links['combined']['url'] ) . '" target="_blank" rel="noopener">'
				. esc_html__( "Read all of today's passages", 'jlife-studies' ) . '</a></p>';
		}
		$out .= '<ul>';
		foreach ( (array) $links['passages'] as $link ) {
			$out .= '<li><a href="' . esc_url( (string) $link['url'] ) . '" target="_blank" rel="noopener">' . esc_html( (string) $link['label'] ) . '</a></li>';
		}
		$out .= '</ul></div>';
	}
	return $out;
}

/**
 * Harmony Bible sections for a day's optional parallel readings (#90): number
 * and Robertson title, each linking to the matching harmony-bible.com page.
 * Links open in a new tab so the participant's J-Life page stays where it was.
 *
 * @param array $day Supplemental day data.
 * @return string HTML, or '' when the day has no sections.
 */
function jlife_studies_render_harmony_sections( $day ) {
	$items = '';
	foreach ( (array) ( $day['harmony_sections'] ?? array() ) as $section ) {
		$url = (string) ( $section['url'] ?? '' );
		if ( 0 !== strpos( $url, 'https://harmony-bible.com/sections/' ) ) {
			continue;
		}
		/* translators: 1: Harmony Bible section number, 2: section title. */
		$label  = sprintf( __( "Harmony's section %1\$s: %2\$s", 'jlife-studies' ), (string) $section['number'], (string) $section['title'] );
		$items .= '<li><a href="' . esc_url( $url ) . '" target="_blank" rel="noopener">' . esc_html( $label ) . '</a></li>';
	}
	if ( '' === $items ) {
		return '';
	}
	return '<div class="jlife-harmony-sections"><h3>' . esc_html__( 'Harmony Bible sections', 'jlife-studies' ) . '</h3><ul>' . $items . '</ul></div>';
}

/**
 * The language a public reading page shows its Bible in: the page's `?lang=`
 * ("en" or "vi", like the sign-in pages), else the site's language (Polylang
 * and similar set it through the `locale` filter). Never the visitor's account
 * language and not determine_locale(), which follows the signed-in person: a
 * public page may be page-cached, so it must look the same for everyone.
 *
 * @return string "en" or "vi".
 */
function jlife_studies_reading_page_language() {
	if ( isset( $_GET['lang'] ) && is_string( $_GET['lang'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- a display language only; type-tested, then sanitized.
		$lang = sanitize_key( wp_unslash( $_GET['lang'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- a display language only.
		if ( in_array( $lang, array( 'en', 'vi' ), true ) ) {
			return $lang;
		}
	}
	return 0 === strpos( strtolower( (string) get_locale() ), 'vi' ) ? 'vi' : 'en';
}

/**
 * Links to this page in each language (plain `?lang=` links: no JavaScript,
 * and the page stays cacheable per URL). Shown only with the on-page reader,
 * which shows one language's Bible at a time.
 *
 * @param int    $post_id Reading-day post.
 * @param string $current The page's language ("en" or "vi").
 * @return string
 */
function jlife_studies_render_reading_language_links( $post_id, $current ) {
	$url   = get_permalink( $post_id );
	$links = array();
	foreach ( array(
		'en' => 'English',
		'vi' => 'Tiếng Việt',
	) as $lang => $name ) {
		$links[] = '<a href="' . esc_url( add_query_arg( 'lang', $lang, $url ) ) . '" hreflang="' . esc_attr( $lang ) . '" lang="' . esc_attr( $lang ) . '"'
			. ( $lang === $current ? ' aria-current="true"' : '' ) . '>' . esc_html( $name ) . '</a>';
	}
	return '<p class="jlife-reading-lang">' . implode( ' | ', $links ) . '</p>';
}

/**
 * The day's passages shown on the page itself by jlife-scripture, for the
 * public reading page, or null to keep the Bible Gateway link blocks.
 *
 * Null when the Scripture plugin is not active, the site option
 * jlife_scripture_native_reader is off (the default), or the day has no
 * passages. Otherwise the page language's default Bible (Kinh Thánh 1925 for
 * Tiếng Việt; the Bible Gateway ERV link for English until NIrV is licensed),
 * with the same note-and-link fallback as Today when a passage cannot load.
 * The call passes no user: the reader never reads a stored My Bible choice
 * here, so the markup is the same for every visitor.
 *
 * @param int   $post_id Reading-day post.
 * @param array $day     The primary plan's day data.
 * @return string|null Markup, or null.
 */
function jlife_studies_render_native_reading( $post_id, $day ) {
	if ( ! function_exists( 'jlife_scripture_maybe_render_day' ) ) {
		return null;
	}
	$lang   = jlife_studies_reading_page_language();
	$native = jlife_scripture_maybe_render_day( $day, 0, array( 'language' => $lang ) );
	return null === $native ? null : jlife_studies_render_reading_language_links( $post_id, $lang ) . $native;
}

add_filter( 'the_content', 'jlife_studies_render_reading_day' );

/**
 * Render a reading-day page: day and date, passages, Bible links (or the
 * on-page reader, when it is on), and the optional parallel readings collapsed
 * below, which always keep their outside links.
 *
 * @param string $content Post content.
 * @return string
 */
function jlife_studies_render_reading_day( $content ) {
	if ( ! is_singular( JLIFE_READING_DAY_CPT ) || ! in_the_loop() || ! is_main_query() ) {
		return $content;
	}
	$post_id = (int) get_the_ID();
	$day     = jlife_studies_get_json_meta( $post_id, '_jlife_reading_day' );
	$plan    = jlife_studies_get_json_meta( $post_id, '_jlife_plan' );
	if ( ! is_array( $day ) || ! is_array( $plan ) ) {
		return $content;
	}
	$supp = jlife_studies_get_json_meta( $post_id, '_jlife_supplemental' );

	$date = date_i18n( get_option( 'date_format' ), (int) strtotime( (string) $day['date'] . ' 12:00:00' ) );
	$out  = '<div class="jlife-reading-day">';
	/* translators: 1: day number, 2: formatted date. */
	$out .= '<p class="jlife-reading-date">' . esc_html( sprintf( __( 'Day %1$d · %2$s', 'jlife-studies' ), (int) $day['day'], $date ) ) . '</p>';
	$out .= '<h2>' . esc_html__( "Today's reading", 'jlife-studies' ) . '</h2>';
	// With the on-page reader on (jlife_scripture_native_reader), it replaces the main reading's passage list and link blocks.
	$native = jlife_studies_render_native_reading( $post_id, $day );
	$out   .= null !== $native ? $native : jlife_studies_render_reading_links( $day, $plan['links_config'] ?? array() );

	if ( is_array( $supp ) && ! empty( $supp['day'] ) ) {
		$out .= '<details class="jlife-supplemental"><summary>' . esc_html__( 'Optional: parallel readings for deeper study', 'jlife-studies' ) . '</summary>';
		$out .= '<p><em>' . esc_html__( 'Optional. Reading these does not change your daily points.', 'jlife-studies' ) . '</em></p>';
		$out .= jlife_studies_render_harmony_sections( $supp['day'] );
		$out .= jlife_studies_render_reading_links( $supp['day'], $supp['plan']['links_config'] ?? array() );
		$out .= '</details>';
	}

	if ( '' !== (string) ( $plan['source_attribution'] ?? '' ) ) {
		$out .= '<p class="jlife-reading-source"><small>' . esc_html( (string) $plan['source_attribution'] ) . '</small></p>';
	}
	$out .= '</div>';
	return $content . $out;
}
