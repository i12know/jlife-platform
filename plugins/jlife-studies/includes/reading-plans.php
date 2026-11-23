<?php
/**
 * Reading plans (#59): one public page per scheduled day, imported from the
 * portable JSON built by bin/build-reading-plans.js (content/reading-plans/).
 *
 * References and external Bible links only; no Bible text is stored in the
 * posts (docs/bible-quotation-permissions.md). The JSON files stay the source
 * of truth; posts are their data and are upserted by (plan_id, day_id).
 *
 * This plugin holds the data only. What a day looks like (Today, /soap and the
 * public /reading/ page, which is a signed-out Today, #106) is rendered by
 * jlife-challenges from jlife_studies_get_reading_day(), one renderer for all.
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
		'i18n'               => $doc['i18n'] ?? array(),
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
 * A plan's reading line in the reader's language (#119): the translated
 * `source_attribution` from `i18n.<lang>`. English, or a plan without that
 * language, stays as it is.
 *
 * @param array|null $plan   Plan data (plan_id, title, source_attribution, i18n).
 * @param string     $locale WordPress locale.
 * @return array|null
 */
function jlife_studies_localize_plan( $plan, $locale ) {
	$lang = strtolower( substr( (string) $locale, 0, 2 ) );
	if ( ! is_array( $plan ) || 'en' === $lang ) {
		return $plan;
	}
	$line = $plan['i18n'][ $lang ]['source_attribution'] ?? '';
	if ( is_string( $line ) && '' !== trim( $line ) ) {
		$plan['source_attribution'] = $line;
	}
	return $plan;
}

/**
 * The optional parallel readings in the reader's language (#119): each Harmony
 * section's `title` becomes its `i18n.<lang>.title`, falling back to English
 * for a section without one. Section numbers, links and references never change.
 *
 * @param array|null $supplemental { plan, day } as stored at import.
 * @param string     $locale       WordPress locale.
 * @return array|null
 */
function jlife_studies_localize_supplemental( $supplemental, $locale ) {
	$lang = strtolower( substr( (string) $locale, 0, 2 ) );
	if ( ! is_array( $supplemental ) || 'en' === $lang ) {
		return $supplemental;
	}
	if ( isset( $supplemental['plan'] ) ) {
		$supplemental['plan'] = jlife_studies_localize_plan( $supplemental['plan'], $locale );
	}
	if ( isset( $supplemental['day']['harmony_sections'] ) && is_array( $supplemental['day']['harmony_sections'] ) ) {
		foreach ( $supplemental['day']['harmony_sections'] as $i => $section ) {
			$title = is_array( $section ) ? ( $section['i18n'][ $lang ]['title'] ?? '' ) : '';
			if ( is_string( $title ) && '' !== trim( $title ) ) {
				$supplemental['day']['harmony_sections'][ $i ]['title'] = $title;
			}
		}
	}
	return $supplemental;
}

/**
 * A reading-day post as data, in a language (for the Today page, the public
 * day page and other surfaces).
 *
 * @param WP_Post $post   Reading-day post.
 * @param string  $locale WordPress locale (vi, en_US, ...) the day is shown in.
 * @return array { day, plan, supplemental, post_id, url }
 */
function jlife_studies_reading_day_for_post( $post, $locale ) {
	return array(
		'post_id'      => $post->ID,
		'url'          => get_permalink( $post ),
		'day'          => jlife_studies_localize_day( jlife_studies_get_json_meta( $post->ID, '_jlife_reading_day' ), $locale ),
		'plan'         => jlife_studies_localize_plan( jlife_studies_get_json_meta( $post->ID, '_jlife_plan' ), $locale ),
		'supplemental' => jlife_studies_localize_supplemental( jlife_studies_get_json_meta( $post->ID, '_jlife_supplemental' ), $locale ),
	);
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
	return $post ? jlife_studies_reading_day_for_post( $post, determine_locale() ) : null;
}
