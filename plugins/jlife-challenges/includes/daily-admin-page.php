<?php
/**
 * J-Life → Daily view and the CSV download (#64 part 2, docs/challenges-engine-design.md §4h).
 *
 * - `manage_options` only (organizers and site admins). Everyone else gets no menu
 *   entry, and a 403 on the page and on the download.
 * - The page is read-only: pick a campaign and a day, see each participant's status
 *   and point and each group's members, SOAPs submitted and shares. Counts and
 *   display names only; never SOAP text, share text, email addresses or tokens.
 * - The download is a POST to admin-post.php with a nonce bound to the campaign and
 *   day, checked again with the capability inside jlife_challenges_csv_prepare().
 *
 * @package jlife-challenges
 */

defined( 'ABSPATH' ) || exit;

/**
 * Page slug and the admin-post action for the CSV download.
 */
const JLIFE_CHALLENGES_DAILY_PAGE = 'jlife-daily';
const JLIFE_CHALLENGES_CSV_ACTION = 'jlife_challenges_csv';

add_action( 'admin_menu', 'jlife_challenges_daily_admin_menu', 21 );
add_action( 'admin_post_' . JLIFE_CHALLENGES_CSV_ACTION, 'jlife_challenges_csv_download' );

/**
 * Add J-Life → Daily view under the J-Life menu that the Reminders page creates.
 */
function jlife_challenges_daily_admin_menu() {
	add_submenu_page( JLIFE_CHALLENGES_REMINDERS_PAGE, __( 'Daily view', 'jlife-challenges' ), __( 'Daily view', 'jlife-challenges' ), 'manage_options', JLIFE_CHALLENGES_DAILY_PAGE, 'jlife_challenges_daily_admin_page' );
}

/**
 * The admin-post handler for the CSV: check, then send the file or stop with an error.
 */
function jlife_challenges_csv_download() {
	$method = isset( $_SERVER['REQUEST_METHOD'] ) ? strtoupper( sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) ) : 'GET';
	// phpcs:disable WordPress.Security.NonceVerification.Missing -- verified inside jlife_challenges_csv_prepare().
	$result = jlife_challenges_csv_prepare(
		$method,
		array(
			'nonce'       => isset( $_POST['_wpnonce'] ) ? sanitize_text_field( wp_unslash( $_POST['_wpnonce'] ) ) : '',
			'campaign_id' => isset( $_POST['jl_campaign_id'] ) ? absint( $_POST['jl_campaign_id'] ) : 0,
			'day'         => isset( $_POST['jl_day'] ) ? sanitize_text_field( wp_unslash( $_POST['jl_day'] ) ) : '',
		)
	);
	// phpcs:enable
	if ( in_array( $result['code'], array( 'forbidden', 'bad_method', 'bad_nonce' ), true ) ) {
		wp_die( esc_html__( 'You are not allowed to download this file.', 'jlife-challenges' ), '', array( 'response' => 403 ) );
	}
	if ( 'ok' !== $result['code'] ) {
		wp_die( esc_html__( 'Not found or not allowed.', 'jlife-challenges' ), '', array( 'response' => 404 ) );
	}
	foreach ( jlife_challenges_csv_headers( $result['filename'] ) as $header ) {
		header( $header );
	}
	echo $result['body']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- a CSV file, not HTML: every cell is guarded and quoted.
	exit;
}

/**
 * The label for a status word.
 *
 * @param string $status done_on_time|done_late|draft|none|point_removed.
 * @return string
 */
function jlife_challenges_daily_status_label( $status ) {
	$labels = array(
		'done_on_time'  => __( 'Done on time', 'jlife-challenges' ),
		'done_late'     => __( 'Done late', 'jlife-challenges' ),
		'draft'         => __( 'Draft', 'jlife-challenges' ),
		'none'          => __( 'No entry', 'jlife-challenges' ),
		'point_removed' => __( 'Point removed on review', 'jlife-challenges' ),
	);
	return $labels[ $status ] ?? $labels['none'];
}

/**
 * One CSV download form: hidden campaign and day, a nonce bound to both, one button.
 *
 * @param int    $campaign_id Campaign.
 * @param string $day_key     Day key, or '' for the whole campaign.
 * @param string $label       Button label.
 * @return string Escaped HTML.
 */
function jlife_challenges_daily_download_form( $campaign_id, $day_key, $label ) {
	return '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="display:inline-block;margin-right:12px">'
		. '<input type="hidden" name="action" value="' . esc_attr( JLIFE_CHALLENGES_CSV_ACTION ) . '">'
		. '<input type="hidden" name="jl_campaign_id" value="' . (int) $campaign_id . '">'
		. '<input type="hidden" name="jl_day" value="' . esc_attr( $day_key ) . '">'
		. wp_nonce_field( jlife_challenges_csv_nonce_action( $campaign_id, $day_key ), '_wpnonce', false, false )
		. '<button type="submit" class="button">' . esc_html( $label ) . '</button></form>';
}

/**
 * The page body. Counts and display names only; escapes everything.
 *
 * @param array $view jlife_challenges_daily_view() (or { campaigns, campaign: null } when there is no campaign).
 * @return string Escaped HTML.
 */
function jlife_challenges_daily_admin_html( array $view ) {
	$html = '<div class="wrap"><h1>' . esc_html__( 'Daily view', 'jlife-challenges' ) . '</h1>';
	if ( empty( $view['campaign'] ) ) {
		return $html . '<p>' . esc_html__( 'There is no campaign yet.', 'jlife-challenges' ) . '</p></div>';
	}
	$campaign = $view['campaign'];
	$cid      = (int) $campaign['id'];
	$day      = $view['day'];

	$html .= '<form method="get" action="' . esc_url( get_admin_url( get_current_blog_id(), 'admin.php' ) ) . '"><input type="hidden" name="page" value="' . esc_attr( JLIFE_CHALLENGES_DAILY_PAGE ) . '">';
	if ( count( $view['campaigns'] ) > 1 ) {
		$html .= '<label for="jl-campaign">' . esc_html__( 'Campaign', 'jlife-challenges' ) . '</label> <select id="jl-campaign" name="campaign">';
		foreach ( $view['campaigns'] as $c ) {
			$html .= '<option value="' . (int) $c['id'] . '"' . selected( $cid, (int) $c['id'], false ) . '>' . esc_html( (string) $c['title'] ) . '</option>';
		}
		$html .= '</select> ';
	}
	$html .= '<label for="jl-day">' . esc_html__( 'Day', 'jlife-challenges' ) . '</label> <select id="jl-day" name="day">';
	for ( $i = 1, $count = jlife_challenges_day_count( $campaign ); $i <= $count; $i++ ) {
		/* translators: 1: day number, 2: date. */
		$label = sprintf( __( 'Day %1$d · %2$s', 'jlife-challenges' ), $i, jlife_challenges_format_date( jlife_challenges_day_date( $campaign, $i ) ) );
		$html .= '<option value="' . esc_attr( jlife_challenges_day_key( $i ) ) . '"' . selected( (int) $day['index'], $i, false ) . '>' . esc_html( $label ) . '</option>';
	}
	$html .= '</select> <button type="submit" class="button">' . esc_html__( 'Show', 'jlife-challenges' ) . '</button></form>';

	/* translators: 1: day number, 2: date. */
	$html .= '<h2>' . esc_html( (string) $campaign['title'] ) . ' · ' . esc_html( sprintf( __( 'Day %1$d · %2$s', 'jlife-challenges' ), (int) $day['index'], jlife_challenges_format_date( (string) $day['date'] ) ) ) . '</h2>';

	$t     = $view['totals'];
	$rows  = array(
		array( __( 'Participants', 'jlife-challenges' ), $t['participants'] ),
		array( jlife_challenges_daily_status_label( 'done_on_time' ), $t['done_on_time'] ),
		array( jlife_challenges_daily_status_label( 'done_late' ), $t['done_late'] ),
		array( jlife_challenges_daily_status_label( 'draft' ), $t['draft'] ),
		array( jlife_challenges_daily_status_label( 'none' ), $t['none'] ),
		array( __( 'Points that day', 'jlife-challenges' ), $t['points'] ),
	);
	$html .= '<table class="widefat striped" style="max-width:420px"><tbody>';
	foreach ( $rows as $row ) {
		$html .= '<tr><th scope="row">' . esc_html( $row[0] ) . '</th><td>' . (int) $row[1] . '</td></tr>';
	}
	$html .= '</tbody></table>';

	$html .= '<h2>' . esc_html__( 'Groups', 'jlife-challenges' ) . '</h2>'
		. '<table class="wp-list-table widefat striped"><thead><tr>'
		. '<th scope="col">' . esc_html__( 'Group', 'jlife-challenges' ) . '</th>'
		. '<th scope="col">' . esc_html__( 'Members', 'jlife-challenges' ) . '</th>'
		. '<th scope="col">' . esc_html__( 'SOAPs submitted', 'jlife-challenges' ) . '</th>'
		. '<th scope="col">' . esc_html__( 'Shared posts', 'jlife-challenges' ) . '</th></tr></thead><tbody>';
	foreach ( $view['groups'] as $g ) {
		$name = 0 === (int) $g['group_id'] ? __( 'Reading on their own', 'jlife-challenges' ) : (string) $g['name'];
		if ( 'active' !== $g['status'] ) {
			/* translators: %s: a group name. */
			$name = sprintf( __( '%s (disabled)', 'jlife-challenges' ), $name );
		}
		$html .= '<tr><td>' . esc_html( $name ) . '</td><td>' . (int) $g['members'] . '</td><td>' . (int) $g['submitted'] . '</td><td>' . (int) $g['shares'] . '</td></tr>';
	}
	$html .= '</tbody></table><p class="description">' . esc_html__( 'Counts only: no entry or shared post is shown here. A person who is in two groups is counted in both, so do not add the rows up.', 'jlife-challenges' ) . '</p>';

	$html .= '<h2>' . esc_html__( 'Participants', 'jlife-challenges' ) . '</h2>';
	if ( ! $view['rows'] ) {
		$html .= '<p>' . esc_html__( 'Nobody has joined this campaign yet.', 'jlife-challenges' ) . '</p>';
	} else {
		$html .= '<table class="wp-list-table widefat striped"><thead><tr>'
			. '<th scope="col">' . esc_html__( 'Name', 'jlife-challenges' ) . '</th>'
			. '<th scope="col">' . esc_html__( 'Group(s)', 'jlife-challenges' ) . '</th>'
			. '<th scope="col">' . esc_html__( 'Status', 'jlife-challenges' ) . '</th>'
			. '<th scope="col">' . esc_html__( 'Point', 'jlife-challenges' ) . '</th></tr></thead><tbody>';
		foreach ( $view['rows'] as $r ) {
			$groups = '' !== $r['groups'] ? $r['groups'] : __( 'Reading on their own', 'jlife-challenges' );
			$html  .= '<tr><td>' . esc_html( $r['name'] ) . '</td><td>' . esc_html( $groups ) . '</td><td>' . esc_html( jlife_challenges_daily_status_label( $r['status'] ) ) . '</td><td>' . (int) $r['point'] . '</td></tr>';
		}
		$html .= '</tbody></table>';
	}

	return $html . '<h2>' . esc_html__( 'Download', 'jlife-challenges' ) . '</h2>'
		. '<p>' . esc_html__( 'A spreadsheet file with each person, their group(s), the day, the point and the status. It has no entries, no email addresses and no sign-in details.', 'jlife-challenges' ) . '</p>'
		. jlife_challenges_daily_download_form( $cid, (string) $day['key'], __( 'Download this day (CSV)', 'jlife-challenges' ) )
		. jlife_challenges_daily_download_form( $cid, '', __( 'Download all days (CSV)', 'jlife-challenges' ) )
		. '</div>';
}

/**
 * The J-Life → Daily view page.
 */
function jlife_challenges_daily_admin_page() {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( esc_html__( 'You are not allowed to see this page.', 'jlife-challenges' ), '', array( 'response' => 403 ) );
	}
	// phpcs:disable WordPress.Security.NonceVerification.Recommended -- display only: these choose what to show and change nothing.
	$campaign_id = isset( $_GET['campaign'] ) ? absint( $_GET['campaign'] ) : 0;
	$day_key     = isset( $_GET['day'] ) ? sanitize_text_field( wp_unslash( $_GET['day'] ) ) : '';
	// phpcs:enable
	$actor     = get_current_user_id();
	$campaigns = jlife_challenges_admin_campaigns();
	$chosen    = jlife_challenges_admin_default_campaign( $campaigns, $campaign_id );
	$view      = array(
		'campaigns' => $campaigns,
		'campaign'  => null,
	);
	if ( $chosen ) {
		$found = jlife_challenges_daily_view( $actor, (int) $chosen['id'], $day_key );
		if ( is_wp_error( $found ) ) { // A day that is not in the address: fall back to the default day.
			$found = jlife_challenges_daily_view( $actor, (int) $chosen['id'], '' );
		}
		if ( ! is_wp_error( $found ) ) {
			$view = $found;
		}
	}
	echo jlife_challenges_daily_admin_html( $view ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- every part is escaped.
}
