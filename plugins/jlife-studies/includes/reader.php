<?php
/**
 * Rough lesson reader (spike S6, #13): renders an imported lesson's sections
 * on the STUDY front end. Deliberately unstyled — layout/theming is MVP work;
 * this only proves imported content renders.
 *
 * @package jlife-studies
 */

defined( 'ABSPATH' ) || exit;

add_filter( 'the_content', 'jlife_studies_render_lesson' );

/**
 * Append the lesson sections to the (empty) post content on lesson pages.
 *
 * Markdown is rendered as escaped plain text for now — a proper Markdown
 * pipeline is an MVP decision, not a spike concern.
 *
 * @param string $content Post content.
 * @return string
 */
function jlife_studies_render_lesson( $content ) {
	if ( ! is_singular( 'jlife_lesson' ) || ! in_the_loop() || ! is_main_query() ) {
		return $content;
	}

	$post_id = get_the_ID();
	if ( ! $post_id ) {
		return $content;
	}
	$doc = jlife_studies_export_document( $post_id );
	if ( is_wp_error( $doc ) ) {
		return $content;
	}

	$out = '';

	$out .= '<section class="jlife-scripture"><h2>' . esc_html__( 'Scripture', 'jlife-studies' ) . '</h2><ul>';
	foreach ( (array) $doc['scripture_reference'] as $ref ) {
		$display = esc_html( (string) $ref['display'] );
		if ( ! empty( $ref['deep_link'] ) ) {
			$out .= '<li><a href="' . esc_url( (string) $ref['deep_link'] ) . '">' . $display . '</a></li>';
		} else {
			$out .= '<li>' . $display . '</li>';
		}
	}
	$out .= '</ul></section>';

	$prose_sections = array(
		'teaching'        => __( 'Teaching', 'jlife-studies' ),
		'outside_the_box' => __( 'Outside the Box', 'jlife-studies' ),
		'live_it_out'     => __( 'Live It Out', 'jlife-studies' ),
		'prayer_prompt'   => __( 'Prayer', 'jlife-studies' ),
	);
	foreach ( $prose_sections as $field => $heading ) {
		$out .= '<section class="jlife-' . esc_attr( str_replace( '_', '-', $field ) ) . '">';
		$out .= '<h2>' . esc_html( $heading ) . '</h2>';
		$out .= wpautop( esc_html( (string) $doc[ $field ] ) );
		$out .= '</section>';
	}

	$out .= '<section class="jlife-reflection"><h2>' . esc_html__( 'Reflection Questions', 'jlife-studies' ) . '</h2><ol>';
	foreach ( (array) $doc['reflection_questions'] as $q ) {
		$out .= '<li>' . esc_html( (string) $q['text'] ) . '</li>';
	}
	$out .= '</ol></section>';

	// Huddle prompts and leader notes are leader-facing: HUB is their real
	// home; on STUDY they render only for users who can edit posts.
	if ( current_user_can( 'edit_others_posts' ) ) {
		$out .= '<section class="jlife-huddle-prompts"><h2>' . esc_html__( 'Huddle Discussion Prompts (leader)', 'jlife-studies' ) . '</h2><ol>';
		foreach ( (array) $doc['huddle_discussion_prompts'] as $p ) {
			$out .= '<li>' . esc_html( (string) $p['text'] ) . '</li>';
		}
		$out .= '</ol></section>';
		$out .= '<section class="jlife-leader-notes"><h2>' . esc_html__( 'Leader Notes', 'jlife-studies' ) . '</h2>';
		$out .= wpautop( esc_html( (string) $doc['leader_notes'] ) );
		$out .= '</section>';
	}

	return $content . $out;
}
