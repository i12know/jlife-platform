<?php
/**
 * Reminders admin page (#131, #63 Phase B): the sending mode switch and a status
 * panel in the site dashboard, so going live is a deliberate click by a site
 * admin. Sending stays off by default; nothing here changes how mail is sent.
 *
 * - J-Life → Reminders, and a "Settings" link on the Plugins screen. The mode is a
 *   per-site option, so the page lives in the site admin (also reachable from the
 *   network Plugins screen when the plugin is network-activated).
 * - Counts only: no participant name or email address is ever printed.
 * - Changes go through admin-post.php: POST only, nonce, manage_options, then a
 *   redirect, and through jlife_challenges_reminders_set_mode() so each is audited.
 *
 * @package jlife-challenges
 */

defined( 'ABSPATH' ) || exit;

/**
 * Admin page slug, form action and nonce action.
 */
const JLIFE_CHALLENGES_REMINDERS_PAGE   = 'jlife-reminders';
const JLIFE_CHALLENGES_REMINDERS_ACTION = 'jlife_reminders_mode';

/**
 * Brevo's free plan sends at most this many emails a day.
 */
const JLIFE_CHALLENGES_REMINDER_DAILY_LIMIT = 300;

/**
 * The tick runs every 5 minutes; warn when its last due run was over 15 minutes ago.
 */
const JLIFE_CHALLENGES_REMINDER_TICK_SECONDS  = 300;
const JLIFE_CHALLENGES_REMINDER_STALE_SECONDS = 900;

add_action( 'admin_menu', 'jlife_challenges_reminders_admin_menu' );
add_action( 'admin_post_' . JLIFE_CHALLENGES_REMINDERS_ACTION, 'jlife_challenges_reminders_admin_post' );
add_filter( 'plugin_action_links_' . plugin_basename( dirname( __DIR__ ) . '/jlife-challenges.php' ), 'jlife_challenges_reminders_action_links' );
add_filter( 'network_admin_plugin_action_links_' . plugin_basename( dirname( __DIR__ ) . '/jlife-challenges.php' ), 'jlife_challenges_reminders_action_links' );

/**
 * The page's address in the site dashboard.
 *
 * @return string
 */
function jlife_challenges_reminders_admin_url() {
	return get_admin_url( get_current_blog_id(), 'admin.php?page=' . JLIFE_CHALLENGES_REMINDERS_PAGE );
}

/**
 * Whether this site's mail goes through Brevo. Live needs it: only the Brevo
 * (SMTP) path keeps the one-click List-Unsubscribe header intact (#129).
 *
 * @return bool
 */
function jlife_challenges_reminders_brevo_enabled() {
	$on = function_exists( 'jlife_mail_is_enabled' ) && jlife_mail_is_enabled();
	return (bool) apply_filters( 'jlife_challenges_reminder_brevo_enabled', $on );
}

/**
 * Add J-Life → Reminders (site admins only).
 */
function jlife_challenges_reminders_admin_menu() {
	add_menu_page( 'J-Life', 'J-Life', 'manage_options', JLIFE_CHALLENGES_REMINDERS_PAGE, 'jlife_challenges_reminders_admin_page', 'dashicons-book-alt', 58 );
	// The first submenu item replaces the top-level label, so the menu reads J-Life → Reminders.
	add_submenu_page( JLIFE_CHALLENGES_REMINDERS_PAGE, __( 'Daily reading reminders', 'jlife-challenges' ), __( 'Reminders', 'jlife-challenges' ), 'manage_options', JLIFE_CHALLENGES_REMINDERS_PAGE, 'jlife_challenges_reminders_admin_page' );
}

/**
 * A "Settings" link next to Deactivate on the Plugins screen (site and network).
 *
 * @param string[] $links Action links.
 * @return string[]
 */
function jlife_challenges_reminders_action_links( $links ) {
	if ( ! current_user_can( 'manage_options' ) ) {
		return $links;
	}
	$link = '<a href="' . esc_url( jlife_challenges_reminders_admin_url() ) . '">' . esc_html__( 'Settings', 'jlife-challenges' ) . '</a>';
	return array_merge( array( 'jlife-reminders-settings' => $link ), (array) $links );
}

/**
 * Problems an admin should know about before (or while) sending. Pure: every
 * input is passed in.
 *
 * @param array $in Inputs: mode, next_run (0 = not scheduled), now (Unix time), mail_state, brevo_enabled (bool), test_addresses (count), recipients (opted in, all active campaigns).
 * @return string[] Codes: cron_unscheduled, cron_stale, mail_unconfigured, brevo_disabled, test_no_addresses, over_daily_limit.
 */
function jlife_challenges_reminders_health( array $in ) {
	$out  = array();
	$next = (int) $in['next_run'];
	if ( $next < 1 ) {
		$out[] = 'cron_unscheduled';
	} elseif ( (int) $in['now'] - ( $next - JLIFE_CHALLENGES_REMINDER_TICK_SECONDS ) > JLIFE_CHALLENGES_REMINDER_STALE_SECONDS ) {
		$out[] = 'cron_stale';
	}
	if ( 'ok' !== $in['mail_state'] ) {
		$out[] = 'mail_unconfigured';
	}
	if ( empty( $in['brevo_enabled'] ) ) {
		$out[] = 'brevo_disabled';
	}
	if ( 'test' === $in['mode'] && (int) $in['test_addresses'] < 1 ) {
		$out[] = 'test_no_addresses';
	}
	if ( (int) $in['recipients'] > JLIFE_CHALLENGES_REMINDER_DAILY_LIMIT ) {
		$out[] = 'over_daily_limit';
	}
	return $out;
}

/**
 * Everything the page shows, gathered once. Counts only.
 *
 * @return array { mode, statuses[], campaigns[], recipients, mail_state, brevo_enabled, test_addresses, next_run, warnings[] }
 */
function jlife_challenges_reminders_admin_overview() {
	$campaigns  = jlife_challenges_reminders_active_campaigns();
	$statuses   = array_map( 'jlife_challenges_reminders_status', $campaigns );
	$recipients = (int) array_sum( wp_list_pluck( $statuses, 'recipients' ) );
	$mode       = jlife_challenges_reminders_mode();
	$overview   = array(
		'mode'           => $mode,
		'campaigns'      => $campaigns,
		'statuses'       => $statuses,
		'recipients'     => $recipients,
		'mail_state'     => jlife_challenges_reminder_mail_state(),
		'brevo_enabled'  => jlife_challenges_reminders_brevo_enabled(),
		'test_addresses' => count( jlife_challenges_reminder_test_recipients() ),
		'next_run'       => (int) wp_next_scheduled( JLIFE_CHALLENGES_REMINDERS_HOOK ),
	);
	return $overview + array( 'warnings' => jlife_challenges_reminders_health( $overview + array( 'now' => time() ) ) );
}

/**
 * Apply a mode change from the page. No output and no redirect, so it can be
 * tested; the admin-post handler turns the result into a response.
 *
 * @param string $method 'GET' or 'POST' (only POST changes anything).
 * @param array  $fields { nonce: string, mode: string, confirm_live: bool }.
 * @return string forbidden|bad_method|bad_nonce|bad_mode|mail_not_ok|brevo_off|confirm_needed|saved
 */
function jlife_challenges_reminders_admin_apply( $method, array $fields ) {
	if ( ! current_user_can( 'manage_options' ) ) {
		return 'forbidden';
	}
	if ( 'POST' !== strtoupper( (string) $method ) ) {
		return 'bad_method';
	}
	if ( ! wp_verify_nonce( (string) ( $fields['nonce'] ?? '' ), JLIFE_CHALLENGES_REMINDERS_ACTION ) ) {
		return 'bad_nonce';
	}
	$mode = (string) ( $fields['mode'] ?? '' );
	if ( ! in_array( $mode, JLIFE_CHALLENGES_REMINDER_MODES, true ) ) {
		return 'bad_mode';
	}
	if ( 'live' === $mode ) {
		if ( 'ok' !== jlife_challenges_reminder_mail_state() ) {
			return 'mail_not_ok';
		}
		if ( ! jlife_challenges_reminders_brevo_enabled() ) {
			return 'brevo_off';
		}
		if ( empty( $fields['confirm_live'] ) ) {
			return 'confirm_needed';
		}
	}
	return is_wp_error( jlife_challenges_reminders_set_mode( $mode, get_current_user_id() ) ) ? 'bad_mode' : 'saved';
}

/**
 * The admin-post.php handler: read the form, apply it, redirect back to the page.
 */
function jlife_challenges_reminders_admin_post() {
	$method = isset( $_SERVER['REQUEST_METHOD'] ) ? strtoupper( sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) ) : 'GET';
	// phpcs:disable WordPress.Security.NonceVerification -- verified inside jlife_challenges_reminders_admin_apply().
	$code = jlife_challenges_reminders_admin_apply(
		$method,
		array(
			'nonce'        => isset( $_POST['_wpnonce'] ) ? sanitize_text_field( wp_unslash( $_POST['_wpnonce'] ) ) : '',
			'mode'         => isset( $_POST['jl_mode'] ) ? sanitize_key( wp_unslash( $_POST['jl_mode'] ) ) : '',
			'confirm_live' => ! empty( $_POST['jl_confirm_live'] ),
		)
	);
	// phpcs:enable
	if ( in_array( $code, array( 'forbidden', 'bad_method', 'bad_nonce' ), true ) ) {
		wp_die( esc_html__( 'You are not allowed to change the reminder mode.', 'jlife-challenges' ), '', array( 'response' => 403 ) );
	}
	wp_safe_redirect( add_query_arg( 'jl_result', $code, jlife_challenges_reminders_admin_url() ) );
	exit;
}

/**
 * Label for a mode.
 *
 * @param string $mode off|dry_run|test|live.
 * @return string
 */
function jlife_challenges_reminders_mode_label( $mode ) {
	$labels = array(
		'off'     => __( 'Off', 'jlife-challenges' ),
		'dry_run' => __( 'Dry run', 'jlife-challenges' ),
		'test'    => __( 'Test', 'jlife-challenges' ),
		'live'    => __( 'Live', 'jlife-challenges' ),
	);
	return $labels[ $mode ] ?? $labels['off'];
}

/**
 * The sentence for a warning code.
 *
 * @param string $code From jlife_challenges_reminders_health().
 * @param array  $o    Overview.
 * @return string
 */
function jlife_challenges_reminders_warning_text( $code, array $o ) {
	switch ( $code ) {
		case 'cron_unscheduled':
			return __( 'The reminder check is not scheduled, so nothing will be sent. Deactivating and reactivating the plugin schedules it again.', 'jlife-challenges' );
		case 'cron_stale':
			return __( 'The reminder check has not run for more than 15 minutes. Make sure the server cron job that runs WP-Cron every 5 minutes is working.', 'jlife-challenges' );
		case 'mail_unconfigured':
			return __( 'Brevo is turned on but its private settings are incomplete, so reminders cannot be sent.', 'jlife-challenges' );
		case 'brevo_disabled':
			return __( 'Brevo is not turned on for this site. The one-click unsubscribe header is only added when mail goes through Brevo, so do not go live yet.', 'jlife-challenges' );
		case 'test_no_addresses':
			return __( 'Test mode is selected but no test addresses are set up (JLIFE_REMINDER_TEST_RECIPIENTS is empty), so nobody will be emailed.', 'jlife-challenges' );
		case 'over_daily_limit':
			/* translators: 1: number of recipients, 2: Brevo's daily limit. */
			return sprintf( __( '%1$d people have asked for reminders, more than the %2$d emails a day on Brevo\'s free plan. Some emails would not be sent.', 'jlife-challenges' ), (int) $o['recipients'], JLIFE_CHALLENGES_REMINDER_DAILY_LIMIT );
	}
	return '';
}

/**
 * A moment in a campaign's timezone.
 *
 * @param int    $timestamp Unix time.
 * @param string $timezone  IANA timezone.
 * @return string
 */
function jlife_challenges_reminders_admin_when( $timestamp, $timezone ) {
	if ( $timestamp < 1 ) {
		return __( 'Not scheduled', 'jlife-challenges' );
	}
	return wp_date( 'Y-m-d H:i T', $timestamp, new DateTimeZone( '' !== $timezone ? $timezone : 'UTC' ) );
}

/**
 * The notice shown after a change.
 *
 * @param string $code The jl_result code.
 * @return string Escaped HTML, or ''.
 */
function jlife_challenges_reminders_admin_notice( $code ) {
	if ( 'saved' === $code ) {
		/* translators: %s: the mode now in use. */
		return '<div class="notice notice-success"><p>' . esc_html( sprintf( __( 'Saved. Reminder mode is now: %s.', 'jlife-challenges' ), jlife_challenges_reminders_mode_label( jlife_challenges_reminders_mode() ) ) ) . '</p></div>';
	}
	$errors = array(
		'bad_mode'       => __( 'That mode is not available.', 'jlife-challenges' ),
		'mail_not_ok'    => __( 'Live was not turned on: the mail settings are not ready. Fix the warning on this page first.', 'jlife-challenges' ),
		'brevo_off'      => __( 'Live was not turned on: Brevo is not turned on for this site.', 'jlife-challenges' ),
		'confirm_needed' => __( 'Live was not turned on: tick the box to confirm first.', 'jlife-challenges' ),
	);
	return isset( $errors[ $code ] ) ? '<div class="notice notice-error"><p>' . esc_html( $errors[ $code ] ) . '</p></div>' : '';
}

/**
 * The page body. Counts only; escapes everything.
 *
 * @param array  $o      jlife_challenges_reminders_admin_overview().
 * @param string $result The jl_result code from the redirect, or ''.
 * @return string Escaped HTML.
 */
function jlife_challenges_reminders_admin_html( array $o, $result = '' ) {
	$html  = '<div class="wrap"><h1>' . esc_html__( 'Daily reading reminders', 'jlife-challenges' ) . '</h1>';
	$html .= jlife_challenges_reminders_admin_notice( $result );

	foreach ( $o['warnings'] as $code ) {
		$html .= '<div class="notice notice-warning"><p>' . esc_html( jlife_challenges_reminders_warning_text( $code, $o ) ) . '</p></div>';
	}
	if ( ! $o['warnings'] ) {
		$html .= '<div class="notice notice-info"><p>' . esc_html__( 'No problems found with the reminder check, the mail settings or the recipient count.', 'jlife-challenges' ) . '</p></div>';
	}

	$html .= '<h2>' . esc_html__( 'Status', 'jlife-challenges' ) . '</h2>';
	$html .= '<p>' . esc_html__( 'Current mode:', 'jlife-challenges' ) . ' <strong>' . esc_html( jlife_challenges_reminders_mode_label( $o['mode'] ) ) . '</strong>';
	if ( 'test' === $o['mode'] ) {
		/* translators: %d: number of test addresses. */
		$html .= ' &mdash; ' . esc_html( sprintf( __( 'Test addresses set up: %d', 'jlife-challenges' ), (int) $o['test_addresses'] ) );
	}
	$html .= '</p>';

	if ( ! $o['statuses'] ) {
		$html .= '<p>' . esc_html__( 'There is no active campaign.', 'jlife-challenges' ) . '</p>';
	}
	foreach ( $o['statuses'] as $i => $s ) {
		$tz    = (string) $o['campaigns'][ $i ]['timezone'];
		$html .= '<h3>' . esc_html( $s['title'] ) . '</h3><table class="widefat striped" style="max-width:640px"><tbody>';
		$rows  = array(
			array( __( 'Mode', 'jlife-challenges' ), jlife_challenges_reminders_mode_label( $s['mode'] ) ),
			array( __( 'Recipients who opted in', 'jlife-challenges' ), (string) (int) $s['recipients'] ),
			array( __( 'Campaign day', 'jlife-challenges' ), $s['day'] ? (string) (int) $s['day'] : __( 'Outside the campaign dates', 'jlife-challenges' ) ),
			array( __( 'Queued today', 'jlife-challenges' ), (string) (int) $s['counts']['queued'] ),
			array( __( 'Accepted today', 'jlife-challenges' ), (string) (int) $s['counts']['accepted'] ),
			array( __( 'Failed today', 'jlife-challenges' ), (string) (int) $s['counts']['failed'] ),
			array( __( 'Skipped today', 'jlife-challenges' ), (string) (int) $s['counts']['skipped'] ),
			array( __( 'Send window open now (06:00 to before 12:00 campaign time)', 'jlife-challenges' ), $s['window_open'] ? __( 'Yes', 'jlife-challenges' ) : __( 'No', 'jlife-challenges' ) ),
			array( __( 'Next scheduled check', 'jlife-challenges' ), jlife_challenges_reminders_admin_when( (int) $s['next_run'], $tz ) ),
		);
		foreach ( $rows as $row ) {
			$html .= '<tr><th scope="row">' . esc_html( $row[0] ) . '</th><td>' . esc_html( $row[1] ) . '</td></tr>';
		}
		$html .= '</tbody></table>';
	}

	$html .= '<h2>' . esc_html__( 'Change the mode', 'jlife-challenges' ) . '</h2>'
		. '<p>' . esc_html__( 'Off sends nothing. Dry run builds every message and sends nothing. Test sends only to the test addresses. Live emails everyone who opted in.', 'jlife-challenges' ) . '</p>'
		. '<p>' . esc_html__( 'A dry run or test run uses up that day\'s row for each person. If you switch to Live the same morning, people who were already skipped will not be emailed until tomorrow.', 'jlife-challenges' ) . '</p>'
		. '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">'
		. '<input type="hidden" name="action" value="' . esc_attr( JLIFE_CHALLENGES_REMINDERS_ACTION ) . '">'
		. wp_nonce_field( JLIFE_CHALLENGES_REMINDERS_ACTION, '_wpnonce', true, false )
		. '<p><button type="submit" name="jl_mode" value="off" class="button button-primary">' . esc_html__( 'Turn off', 'jlife-challenges' ) . '</button> '
		. '<button type="submit" name="jl_mode" value="dry_run" class="button">' . esc_html__( 'Dry run', 'jlife-challenges' ) . '</button> '
		. '<button type="submit" name="jl_mode" value="test" class="button">' . esc_html__( 'Test', 'jlife-challenges' ) . '</button></p>';

	$zones = array();
	foreach ( $o['campaigns'] as $campaign ) {
		$zones[ (string) $campaign['timezone'] ] = true;
	}
	$people  = (int) $o['recipients'];
	$confirm = sprintf(
		/* translators: 1: number of people, 2: time zone name(s). */
		_n(
			"Yes, email %1\$d person every morning at 06:00 campaign time (%2\$s). If the send window is already open, today's emails start within a few minutes.",
			"Yes, email %1\$d people every morning at 06:00 campaign time (%2\$s). If the send window is already open, today's emails start within a few minutes.",
			$people,
			'jlife-challenges'
		),
		$people,
		implode( ', ', array_keys( $zones ) )
	);
	$ready = 'ok' === $o['mail_state'] && ! empty( $o['brevo_enabled'] );
	$html .= '<fieldset style="border:1px solid #c3c4c7;padding:8px 16px;max-width:640px"><legend><strong>' . esc_html__( 'Go live', 'jlife-challenges' ) . '</strong></legend>'
		. '<p><label><input type="checkbox" name="jl_confirm_live" value="1"' . ( $ready ? '' : ' disabled' ) . '> ' . esc_html( $confirm ) . '</label></p>'
		. ( $ready ? '' : '<p>' . esc_html__( 'Live is unavailable until the mail settings are ready.', 'jlife-challenges' ) . '</p>' )
		. '<p><button type="submit" name="jl_mode" value="live" class="button"' . ( $ready ? '' : ' disabled' ) . '>' . esc_html__( 'Switch to Live', 'jlife-challenges' ) . '</button></p></fieldset></form></div>';
	return $html;
}

/**
 * The J-Life → Reminders page.
 */
function jlife_challenges_reminders_admin_page() {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( esc_html__( 'You are not allowed to see this page.', 'jlife-challenges' ), '', array( 'response' => 403 ) );
	}
	$result = isset( $_GET['jl_result'] ) ? sanitize_key( wp_unslash( $_GET['jl_result'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification -- display only; changes nothing.
	echo jlife_challenges_reminders_admin_html( jlife_challenges_reminders_admin_overview(), $result ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- every part is escaped.
}
