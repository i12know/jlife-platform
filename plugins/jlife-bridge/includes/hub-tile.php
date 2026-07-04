<?php
/**
 * J-Life progress tile on the Disciple.Tools group record (spike S3, #10).
 *
 * Renders inside HUB for users who can already access the group record —
 * D.T's own permission model is the gate; this tile adds no new read path.
 * It shows aggregate/placeholder progress only: per pilot-context.md and
 * integration-boundaries.md, private participant content (reflection notes,
 * discussion bodies, prayer requests) never crosses into HUB. Real numbers
 * arrive later via the jlife_bridge_group_progress filter, fed from STUDY
 * by the bridge — not by HUB querying STUDY tables directly.
 *
 * @package jlife-bridge
 */

defined( 'ABSPATH' ) || exit;

add_filter( 'dt_details_additional_tiles', 'jlife_bridge_register_group_tile', 10, 2 );

/**
 * Register the J-Life progress tile on group records.
 *
 * @param array  $tiles     Registered tiles.
 * @param string $post_type D.T post type being rendered.
 * @return array
 */
function jlife_bridge_register_group_tile( $tiles, $post_type = '' ) {
	if ( 'groups' === $post_type ) {
		$tiles['jlife_progress'] = array(
			'label'       => __( 'J-Life Progress', 'jlife-bridge' ),
			'description' => __( 'Aggregate lesson progress for this huddle from the STUDY site.', 'jlife-bridge' ),
		);
	}
	return $tiles;
}

add_action( 'dt_details_additional_section', 'jlife_bridge_render_group_tile', 10, 3 );

/**
 * Render the tile body: aggregate counts only, never participant content.
 *
 * @param string $section_id Tile being rendered.
 * @param string $post_type  D.T post type.
 * @param int    $post_id    D.T record ID being rendered.
 */
function jlife_bridge_render_group_tile( $section_id, $post_type, $post_id = 0 ) {
	if ( 'jlife_progress' !== $section_id || 'groups' !== $post_type ) {
		return;
	}

	$group_id     = (int) $post_id;
	$group_id     = $group_id > 0 ? $group_id : (int) get_the_ID();
	$member_count = 0;
	if ( $group_id && class_exists( 'DT_Posts' ) ) {
		$group = DT_Posts::get_post( 'groups', $group_id, true, false );
		if ( ! is_wp_error( $group ) && isset( $group['members'] ) && is_array( $group['members'] ) ) {
			$member_count = count( $group['members'] );
		}
	}

	/**
	 * Aggregate progress for a huddle, supplied by the bridge from STUDY data.
	 *
	 * Values must stay aggregate: counts and flags only. No reflection notes,
	 * discussion text, or prayer content may pass through this filter.
	 *
	 * @param array $progress { lessons_total, lessons_completed_avg, participants_engaged }
	 * @param int   $group_id D.T group ID.
	 */
	$progress_defaults = array(
		'lessons_total'         => 7,
		'lessons_completed_avg' => 0,
		'participants_engaged'  => 0,
	);
	$progress          = apply_filters(
		'jlife_bridge_group_progress',
		$progress_defaults,
		$group_id
	);
	$progress          = is_array( $progress ) ? wp_parse_args( $progress, $progress_defaults ) : $progress_defaults;

	$lessons_total         = max( 0, (int) $progress['lessons_total'] );
	$lessons_completed_avg = max( 0, (float) $progress['lessons_completed_avg'] );
	$participants_engaged  = max( 0, (int) $progress['participants_engaged'] );
	if ( $lessons_total > 0 ) {
		$lessons_completed_avg = min( $lessons_completed_avg, (float) $lessons_total );
	}
	if ( $member_count > 0 ) {
		$participants_engaged = min( $participants_engaged, $member_count );
	}

	?>
	<div class="cell small-12 medium-4">
		<div class="section-subheader"><?php esc_html_e( 'Lessons completed (huddle average)', 'jlife-bridge' ); ?></div>
		<p>
		<?php
		printf(
			/* translators: 1: average completed lessons, 2: total lessons in the series. */
			esc_html__( '%1$s of %2$s', 'jlife-bridge' ),
			esc_html( number_format_i18n( $lessons_completed_avg, 1 ) ),
			esc_html( number_format_i18n( $lessons_total ) )
		);
		?>
		</p>
		<div class="section-subheader"><?php esc_html_e( 'Participants engaged this week', 'jlife-bridge' ); ?></div>
		<p>
		<?php
		printf(
			/* translators: 1: engaged participant count, 2: huddle member count. */
			esc_html__( '%1$s of %2$s members', 'jlife-bridge' ),
			esc_html( number_format_i18n( $participants_engaged ) ),
			esc_html( number_format_i18n( $member_count ) )
		);
		?>
		</p>
		<p><em><?php esc_html_e( 'Placeholder data (spike S3). Live aggregates arrive with the STUDY bridge sync. Private participant content is never shown here.', 'jlife-bridge' ); ?></em></p>
	</div>
	<?php
}
