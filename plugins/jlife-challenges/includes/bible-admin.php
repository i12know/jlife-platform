<?php
/**
 * J-Life → Scripture (#141): this month's API.Bible usage for site admins, so
 * the team notices before the Starter plan's 5,000 calls a month run out.
 * Read only; counts only. Lives under the J-Life menu the Reminders page adds.
 *
 * @package jlife-challenges
 */

defined( 'ABSPATH' ) || exit;

/**
 * Admin page slug.
 */
const JLIFE_CHALLENGES_SCRIPTURE_PAGE = 'jlife-scripture';

add_action( 'admin_menu', 'jlife_challenges_scripture_admin_menu', 11 );

/**
 * Add J-Life → Scripture (site admins only), after J-Life → Reminders.
 */
function jlife_challenges_scripture_admin_menu() {
	add_submenu_page( JLIFE_CHALLENGES_REMINDERS_PAGE, __( 'Scripture sources', 'jlife-challenges' ), __( 'Scripture', 'jlife-challenges' ), 'manage_options', JLIFE_CHALLENGES_SCRIPTURE_PAGE, 'jlife_challenges_scripture_admin_page' );
}

/**
 * The page body. Escapes everything.
 *
 * @param array $usage jlife_challenges_apibible_usage().
 * @param bool  $keyed Whether a key is set on this host (never the key itself).
 * @return string HTML.
 */
function jlife_challenges_scripture_admin_html( array $usage, $keyed ) {
	$calls = (int) $usage['calls'];
	$state = jlife_challenges_apibible_usage_state( $calls );
	$html  = '<div class="wrap"><h1>' . esc_html__( 'Scripture sources', 'jlife-challenges' ) . '</h1>';
	if ( 'over' === $state ) {
		$html .= '<div class="notice notice-error"><p>' . esc_html__( 'This month\'s API.Bible calls are used up. Readers see the Bible Gateway link until next month.', 'jlife-challenges' ) . '</p></div>';
	} elseif ( 'near' === $state ) {
		/* translators: %d: the monthly call limit. */
		$html .= '<div class="notice notice-warning"><p>' . esc_html( sprintf( __( 'API.Bible calls this month are close to the plan\'s limit of %d. Check that passages are being cached.', 'jlife-challenges' ), JLIFE_CHALLENGES_APIBIBLE_MONTHLY_LIMIT ) ) . '</p></div>';
	}
	if ( ! $keyed ) {
		$html .= '<div class="notice notice-info"><p>' . esc_html__( 'No API.Bible key is set on this server, so API.Bible versions show as links.', 'jlife-challenges' ) . '</p></div>';
	}
	$rows = array(
		array( __( 'Month (UTC)', 'jlife-challenges' ), (string) $usage['month'] ),
		/* translators: 1: calls made this month, 2: the monthly limit. */
		array( __( 'API.Bible calls this month', 'jlife-challenges' ), sprintf( __( '%1$d of %2$d', 'jlife-challenges' ), $calls, JLIFE_CHALLENGES_APIBIBLE_MONTHLY_LIMIT ) ),
		array( __( 'Usage reports sent (FUMS)', 'jlife-challenges' ), (string) (int) $usage['fums'] ),
	);
	$html .= '<table class="widefat striped" style="max-width:640px"><tbody>';
	foreach ( $rows as $row ) {
		$html .= '<tr><th scope="row">' . esc_html( $row[0] ) . '</th><td>' . esc_html( $row[1] ) . '</td></tr>';
	}
	return $html . '</tbody></table></div>';
}

/**
 * The J-Life → Scripture page.
 */
function jlife_challenges_scripture_admin_page() {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( esc_html__( 'You are not allowed to see this page.', 'jlife-challenges' ), '', array( 'response' => 403 ) );
	}
	echo jlife_challenges_scripture_admin_html( jlife_challenges_apibible_usage(), '' !== jlife_challenges_apibible_key() ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- every part is escaped.
}
