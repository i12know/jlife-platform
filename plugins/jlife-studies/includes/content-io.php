<?php
/**
 * Import/export core for portable study content (spike S6, #13).
 *
 * Losslessness contract: every schema field is stored as its own post meta
 * entry (`_jlife_<field>`), JSON-encoded so that null, absent-optional-field,
 * arrays, and unicode all survive the trip. Export rebuilds the document
 * from those decomposed metas in canonical schema key order — it never
 * stores or replays the original file as a blob, so a passing round-trip
 * diff proves the DB projection really carries all the data.
 *
 * @package jlife-studies
 */

defined( 'ABSPATH' ) || exit;

/**
 * Canonical field order per document type, matching content/schemas/.
 *
 * @param string $type 'series' or 'lesson'.
 * @return string[] Field names in schema order.
 */
function jlife_studies_field_order( $type ) {
	if ( 'series' === $type ) {
		return array(
			'schema_version',
			'series_id',
			'title',
			'description',
			'source_language',
			'translation_status',
			'source_attribution',
			'rights_note',
			'lessons',
		);
	}
	return array(
		'schema_version',
		'lesson_id',
		'series_id',
		'order',
		'title',
		'source_language',
		'translation_status',
		'source_attribution',
		'rights_note',
		'primary_gospel_event_id',
		'related_gospel_event_ids',
		'phase',
		'sub_phase',
		'phase_mapping_status',
		'scripture_reference',
		'teaching',
		'outside_the_box',
		'reflection_questions',
		'live_it_out',
		'prayer_prompt',
		'huddle_discussion_prompts',
		'leader_notes',
	);
}

/**
 * Detect the document type of a decoded content file.
 *
 * @param array $doc Decoded JSON document.
 * @return string|null 'lesson', 'series', or null if unrecognized.
 */
function jlife_studies_document_type( $doc ) {
	if ( isset( $doc['lesson_id'] ) ) {
		return 'lesson';
	}
	if ( isset( $doc['series_id'] ) ) {
		return 'series';
	}
	return null;
}

/**
 * Find an existing post by its stable content ID.
 *
 * @param string $post_type Post type to search.
 * @param string $stable_id The series_id or lesson_id.
 * @return WP_Post|null
 */
function jlife_studies_find_post( $post_type, $stable_id ) {
	$posts = get_posts(
		array(
			'post_type'      => $post_type,
			'name'           => sanitize_title( $stable_id ),
			'post_status'    => 'any',
			'posts_per_page' => 1,
		)
	);
	return $posts ? $posts[0] : null;
}

/**
 * Import one decoded document, creating or updating its post.
 *
 * @param array $doc Decoded JSON document (series or lesson).
 * @return int|WP_Error Post ID on success.
 */
function jlife_studies_import_document( $doc ) {
	$type = jlife_studies_document_type( $doc );
	if ( null === $type ) {
		return new WP_Error( 'jlife_unrecognized', __( 'Document is neither a series nor a lesson.', 'jlife-studies' ) );
	}

	$post_type = 'lesson' === $type ? 'jlife_lesson' : 'jlife_series';
	$stable_id = (string) ( 'lesson' === $type ? $doc['lesson_id'] : $doc['series_id'] );
	$existing  = jlife_studies_find_post( $post_type, $stable_id );

	$postarr = array(
		'post_type'   => $post_type,
		'post_title'  => (string) $doc['title'],
		'post_name'   => sanitize_title( $stable_id ),
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

	// Store every present field decomposed; remove metas for absent fields.
	foreach ( jlife_studies_field_order( $type ) as $field ) {
		$meta_key = '_jlife_' . $field;
		if ( array_key_exists( $field, $doc ) ) {
			$encoded = wp_json_encode( $doc[ $field ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
			if ( false === $encoded ) {
				return new WP_Error( 'jlife_encode_failed', "Could not JSON-encode field {$field}." );
			}
			update_post_meta( $post_id, $meta_key, wp_slash( $encoded ) );
		} else {
			delete_post_meta( $post_id, $meta_key );
		}
	}

	if ( 'lesson' === $type ) {
		jlife_studies_sync_taxonomies( $post_id, $doc );
	}

	return $post_id;
}

/**
 * Rebuild the derived taxonomies from lesson fields.
 *
 * @param int   $post_id Lesson post ID.
 * @param array $doc     Decoded lesson document.
 */
function jlife_studies_sync_taxonomies( $post_id, $doc ) {
	$events = array_map(
		'strval',
		array_merge(
			array( $doc['primary_gospel_event_id'] ),
			isset( $doc['related_gospel_event_ids'] ) ? (array) $doc['related_gospel_event_ids'] : array()
		)
	);
	wp_set_object_terms( $post_id, $events, 'gospel_event' );

	$phase = isset( $doc['phase'] ) && null !== $doc['phase'] ? array( (string) $doc['phase'] ) : array();
	wp_set_object_terms( $post_id, $phase, 'gospel_phase' );

	$refs = array();
	foreach ( (array) $doc['scripture_reference'] as $ref ) {
		$refs[] = (string) $ref['display'];
	}
	wp_set_object_terms( $post_id, $refs, 'scripture_ref' );
}

/**
 * Rebuild a document array from a post's decomposed metas.
 *
 * @param int $post_id Post ID of a jlife_series or jlife_lesson.
 * @return array|WP_Error Document in canonical field order.
 */
function jlife_studies_export_document( $post_id ) {
	$post = get_post( $post_id );
	if ( ! $post || ! in_array( $post->post_type, array( 'jlife_series', 'jlife_lesson' ), true ) ) {
		return new WP_Error( 'jlife_not_content', __( 'Post is not a series or lesson.', 'jlife-studies' ) );
	}
	$type = 'jlife_lesson' === $post->post_type ? 'lesson' : 'series';

	$doc = array();
	foreach ( jlife_studies_field_order( $type ) as $field ) {
		$encoded = (string) get_post_meta( $post_id, '_jlife_' . $field, true );
		if ( '' === $encoded ) {
			continue; // Field was absent in the source file (optional field).
		}
		$doc[ $field ] = json_decode( $encoded, true );
	}
	return $doc;
}

/**
 * Serialize a document to the on-disk JSON style used by /content files
 * (2-space indent, unescaped unicode/slashes, trailing newline).
 *
 * @param array $doc Document array.
 * @return string JSON text.
 */
function jlife_studies_serialize_document( $doc ) {
	$json = wp_json_encode( $doc, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
	if ( false === $json ) {
		return "null\n";
	}
	// PHP pretty-print uses 4-space indent; content files use 2-space.
	$halved = preg_replace_callback(
		'/^ +/m',
		function ( $m ) {
			return str_repeat( ' ', (int) ( strlen( $m[0] ) / 2 ) );
		},
		$json
	);
	return ( null === $halved ? $json : $halved ) . "\n";
}
