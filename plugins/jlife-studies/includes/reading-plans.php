<?php
/**
 * Reading plans (#59): one public page per scheduled day, imported from the
 * portable JSON built by bin/build-reading-plans.js (content/reading-plans/).
 *
 * References and external Bible links only; no Bible text is stored or shown
 * (docs/bible-quotation-permissions.md). The JSON files stay the source of
 * truth; posts are their rendering and are upserted by (plan_id, day_id).
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
 * Get a reading day as data (for the Today page and other surfaces).
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
		'day'          => jlife_studies_get_json_meta( $post->ID, '_jlife_reading_day' ),
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

add_filter( 'the_content', 'jlife_studies_render_reading_day' );

/**
 * Render a reading-day page: day and date, passages, Bible links, and the
 * optional parallel readings collapsed below.
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
	$out .= jlife_studies_render_reading_links( $day, $plan['links_config'] ?? array() );

	if ( is_array( $supp ) && ! empty( $supp['day'] ) ) {
		$out .= '<details class="jlife-supplemental"><summary>' . esc_html__( 'Optional: parallel readings for deeper study', 'jlife-studies' ) . '</summary>';
		$out .= '<p><em>' . esc_html__( 'Optional. Reading these does not change your daily points.', 'jlife-studies' ) . '</em></p>';
		$out .= jlife_studies_render_reading_links( $supp['day'], $supp['plan']['links_config'] ?? array() );
		$out .= '</details>';
	}

	if ( '' !== (string) ( $plan['source_attribution'] ?? '' ) ) {
		$out .= '<p class="jlife-reading-source"><small>' . esc_html( (string) $plan['source_attribution'] ) . '</small></p>';
	}
	$out .= '</div>';
	return $content . $out;
}
