<?php
/**
 * Post types and taxonomies for portable study content (spike S6, #13).
 *
 * The WordPress DB is a rendering of /content files, never their home
 * (architecture.md §4): the canonical data model is content/schemas/, these
 * CPTs are its projection. Taxonomies are derived from file fields on import
 * and are rebuilt, not authored, in wp-admin.
 *
 * @package jlife-studies
 */

defined( 'ABSPATH' ) || exit;

add_action( 'init', 'jlife_studies_register_content_types' );

/**
 * Register the series/lesson post types and the derived taxonomies.
 */
function jlife_studies_register_content_types() {
	register_post_type(
		'jlife_series',
		array(
			'labels'       => array(
				'name'          => __( 'Series', 'jlife-studies' ),
				'singular_name' => __( 'Series', 'jlife-studies' ),
			),
			'public'       => true,
			'has_archive'  => false,
			'show_in_rest' => false,
			'menu_icon'    => 'dashicons-book',
			'supports'     => array( 'title' ),
			'rewrite'      => array( 'slug' => 'series' ),
		)
	);

	register_post_type(
		'jlife_lesson',
		array(
			'labels'       => array(
				'name'          => __( 'Lessons', 'jlife-studies' ),
				'singular_name' => __( 'Lesson', 'jlife-studies' ),
			),
			'public'       => true,
			'has_archive'  => false,
			'show_in_rest' => false,
			'menu_icon'    => 'dashicons-welcome-learn-more',
			'supports'     => array( 'title' ),
			'rewrite'      => array( 'slug' => 'lessons' ),
		)
	);

	$taxonomies = array(
		'gospel_phase'  => __( 'Gospel Phase', 'jlife-studies' ),
		'gospel_event'  => __( 'Gospel Event', 'jlife-studies' ),
		'scripture_ref' => __( 'Scripture Reference', 'jlife-studies' ),
	);
	foreach ( $taxonomies as $slug => $label ) {
		register_taxonomy(
			$slug,
			array( 'jlife_lesson' ),
			array(
				'labels'       => array( 'name' => $label ),
				'public'       => true,
				'hierarchical' => false,
				'show_in_rest' => false,
			)
		);
	}
}
