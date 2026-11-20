<?php
/**
 * Daily reading reminders, part 2 (#63 Phase B): the send queue, the scheduler,
 * the sender, retries and the modes. Part 1 (reminders.php) decides who asked for
 * the email and in which language; this file sends it.
 *
 * - Runs only from a WP-Cron event (every 5 minutes; the host's real cron fires
 *   it). Nothing here is hooked to a page request, and a failure here never
 *   touches reading, SOAP entry or scoring.
 * - Send window: 06:00 to before 12:00 in the campaign's timezone, on a campaign
 *   day 1..N. One row per person per day in the reminder_sends table; its UNIQUE
 *   key is the duplicate guard.
 * - The log holds IDs, a status, counters, times and a fixed error class. Never
 *   an address, subject, body or raw error text.
 *
 * @package jlife-challenges
 */

defined( 'ABSPATH' ) || exit;

/**
 * The cron event that runs one scheduler tick.
 */
const JLIFE_CHALLENGES_REMINDERS_HOOK = 'jlife_challenges_reminders_tick';

/**
 * Hour (campaign time) the daily email is first sent, and the hour it stops.
 */
const JLIFE_CHALLENGES_REMINDER_START_HOUR  = 6;
const JLIFE_CHALLENGES_REMINDER_CUTOFF_HOUR = 12;

/**
 * Most emails one tick sends, and most attempts per person per day.
 */
const JLIFE_CHALLENGES_REMINDER_BATCH        = 25;
const JLIFE_CHALLENGES_REMINDER_MAX_ATTEMPTS = 4;

/**
 * Minutes to wait after the 1st, 2nd and 3rd failed attempt.
 */
const JLIFE_CHALLENGES_REMINDER_RETRY_MINUTES = array( 10, 30, 60 );

/**
 * Minutes a claimed row is held before another tick may take it (a crashed tick).
 */
const JLIFE_CHALLENGES_REMINDER_LEASE_MINUTES = 15;

/**
 * Seconds before a tick's lock is considered stale.
 */
const JLIFE_CHALLENGES_REMINDER_LOCK_SECONDS = 180;

/**
 * Sending modes. Anything else counts as off.
 */
const JLIFE_CHALLENGES_REMINDER_MODES = array( 'off', 'dry_run', 'test', 'live' );

add_filter( 'cron_schedules', 'jlife_challenges_reminders_cron_schedules' ); // phpcs:ignore WordPress.WP.CronInterval -- exactly five minutes, by design.
add_action( JLIFE_CHALLENGES_REMINDERS_HOOK, 'jlife_challenges_reminders_cron' );
add_action( 'init', 'jlife_challenges_reminders_schedule' );

/**
 * Add the five-minute schedule.
 *
 * @param array $schedules Schedules.
 * @return array
 */
function jlife_challenges_reminders_cron_schedules( $schedules ) {
	$schedules['jlife_five_minutes'] = array(
		'interval' => 5 * MINUTE_IN_SECONDS,
		'display'  => 'Every 5 minutes (J-Life reminders)',
	);
	return $schedules;
}

/**
 * Make sure the tick event exists (activation, upgrade, and a cheap check per
 * request in case the cron list was reset). Scheduling sends nothing.
 */
function jlife_challenges_reminders_schedule() {
	if ( ! wp_next_scheduled( JLIFE_CHALLENGES_REMINDERS_HOOK ) ) {
		wp_schedule_event( time() + MINUTE_IN_SECONDS, 'jlife_five_minutes', JLIFE_CHALLENGES_REMINDERS_HOOK );
	}
}

/**
 * The cron event's callback: one tick, result discarded (WP-CLI reads it).
 */
function jlife_challenges_reminders_cron() {
	jlife_challenges_reminders_tick();
}

/**
 * Remove the tick event (plugin deactivation).
 */
function jlife_challenges_reminders_unschedule() {
	wp_clear_scheduled_hook( JLIFE_CHALLENGES_REMINDERS_HOOK );
}

/**
 * The current sending mode.
 *
 * @return string off|dry_run|test|live
 */
function jlife_challenges_reminders_mode() {
	$mode = get_option( 'jlife_reminders_mode', 'off' );
	return in_array( $mode, JLIFE_CHALLENGES_REMINDER_MODES, true ) ? $mode : 'off';
}

/**
 * Change the sending mode (WP-CLI or the Reminders admin page). Audited without
 * anything personal.
 *
 * @param string $mode  off|dry_run|test|live.
 * @param int    $actor Acting user (0 = WP-CLI / system).
 * @return true|WP_Error
 */
function jlife_challenges_reminders_set_mode( $mode, $actor = 0 ) {
	if ( ! in_array( $mode, JLIFE_CHALLENGES_REMINDER_MODES, true ) ) {
		return new WP_Error( 'jlife_reminder_mode', 'Mode must be one of: ' . implode( ', ', JLIFE_CHALLENGES_REMINDER_MODES ) . '.' );
	}
	$old = jlife_challenges_reminders_mode();
	update_option( 'jlife_reminders_mode', $mode, false );
	jlife_challenges_audit(
		(int) $actor,
		'reminders_mode',
		'reminders',
		0,
		0,
		array(
			'from' => $old,
			'to'   => $mode,
		)
	);
	return true;
}

/**
 * Parse a comma-separated list of email addresses: trimmed, lowercase, valid ones only.
 *
 * @param string|null $raw Raw list.
 * @return string[]
 */
function jlife_challenges_parse_address_list( $raw ) {
	$out = array();
	foreach ( explode( ',', (string) $raw ) as $address ) {
		$address = strtolower( trim( $address ) );
		if ( '' !== $address && false !== is_email( $address ) ) {
			$out[] = $address;
		}
	}
	return array_values( array_unique( $out ) );
}

/**
 * The inboxes test mode may send to: the JLIFE_REMINDER_TEST_RECIPIENTS constant,
 * defined in the server's private configuration (never in the repository).
 *
 * @return string[] Lowercase addresses.
 */
function jlife_challenges_reminder_test_recipients() {
	$list = defined( 'JLIFE_REMINDER_TEST_RECIPIENTS' ) ? jlife_challenges_parse_address_list( JLIFE_REMINDER_TEST_RECIPIENTS ) : array();
	$list = apply_filters( 'jlife_challenges_reminder_test_recipients', $list );
	return array_values( array_map( 'strtolower', array_filter( (array) $list, 'is_string' ) ) );
}

/**
 * Where a campaign stands against the daily send window at a moment.
 *
 * @param array             $campaign Campaign row.
 * @param DateTimeImmutable $moment   Moment.
 * @return array { day: int (0 outside the campaign), day_key: string, open: bool }
 */
function jlife_challenges_reminder_window( $campaign, DateTimeImmutable $moment ) {
	$day  = jlife_challenges_current_day( $campaign, $moment );
	$hour = (int) $moment->setTimezone( new DateTimeZone( (string) $campaign['timezone'] ) )->format( 'G' );
	return array(
		'day'     => $day,
		'day_key' => $day > 0 ? jlife_challenges_day_key( $day ) : '',
		'open'    => $day > 0 && $hour >= JLIFE_CHALLENGES_REMINDER_START_HOUR && $hour < JLIFE_CHALLENGES_REMINDER_CUTOFF_HOUR,
	);
}

/**
 * The moment a campaign day's send window closes (12:00 campaign time that date).
 *
 * @param array $campaign Campaign row.
 * @param int   $index    Day index.
 * @return DateTimeImmutable
 */
function jlife_challenges_reminder_cutoff( $campaign, $index ) {
	return new DateTimeImmutable(
		jlife_challenges_day_date( $campaign, $index ) . ' ' . sprintf( '%02d', JLIFE_CHALLENGES_REMINDER_CUTOFF_HOUR ) . ':00:00',
		new DateTimeZone( (string) $campaign['timezone'] )
	);
}

/**
 * Campaigns the scheduler works on.
 *
 * @return array[] Campaign rows.
 */
function jlife_challenges_reminders_active_campaigns() {
	global $wpdb;
	return (array) $wpdb->get_results( "SELECT * FROM {$wpdb->prefix}jlife_campaigns WHERE status = 'active' ORDER BY id", ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- custom table; name from $wpdb->prefix.
}

/**
 * A UTC database timestamp for a moment.
 *
 * @param DateTimeImmutable $moment Moment.
 * @return string
 */
function jlife_challenges_reminders_stamp( DateTimeImmutable $moment ) {
	return $moment->setTimezone( new DateTimeZone( 'UTC' ) )->format( 'Y-m-d H:i:s' );
}

/**
 * Queue today's email for every current recipient. INSERT IGNORE on the UNIQUE
 * key: running this twice, or from two overlapping ticks, never makes a second row.
 *
 * @param array             $campaign Campaign row.
 * @param DateTimeImmutable $now      Now.
 * @return int Rows added.
 */
function jlife_challenges_reminders_enqueue( $campaign, DateTimeImmutable $now ) {
	global $wpdb;
	$window = jlife_challenges_reminder_window( $campaign, $now );
	if ( ! $window['open'] ) {
		return 0;
	}
	$table = jlife_challenges_table( 'reminder_sends' );
	$stamp = jlife_challenges_reminders_stamp( $now );
	$added = 0;
	foreach ( jlife_challenges_reminder_recipients( (int) $campaign['id'] ) as $user_id ) {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- custom table; name from $wpdb->prefix.
		$added += (int) $wpdb->query( $wpdb->prepare( "INSERT IGNORE INTO {$table} (campaign_id, day_key, user_id, status, attempts, next_attempt, created, updated) VALUES (%d, %s, %d, 'queued', 0, %s, %s, %s)", (int) $campaign['id'], $window['day_key'], (int) $user_id, $stamp, $stamp, $stamp ) );
	}
	return $added;
}

/**
 * Mark rows still queued from an earlier day, or after the window closed, as expired.
 *
 * @param array             $campaign Campaign row.
 * @param DateTimeImmutable $now      Now.
 */
function jlife_challenges_reminders_expire( $campaign, DateTimeImmutable $now ) {
	global $wpdb;
	$window = jlife_challenges_reminder_window( $campaign, $now );
	$table  = jlife_challenges_table( 'reminder_sends' );
	$stamp  = jlife_challenges_reminders_stamp( $now );
	// phpcs:disable WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- custom table; name from $wpdb->prefix.
	if ( $window['open'] ) {
		$wpdb->query( $wpdb->prepare( "UPDATE {$table} SET status = 'skipped', last_error_class = 'expired', updated = %s WHERE campaign_id = %d AND status = 'queued' AND day_key <> %s", $stamp, (int) $campaign['id'], $window['day_key'] ) );
	} else {
		$wpdb->query( $wpdb->prepare( "UPDATE {$table} SET status = 'skipped', last_error_class = 'expired', updated = %s WHERE campaign_id = %d AND status = 'queued'", $stamp, (int) $campaign['id'] ) );
	}
	// phpcs:enable
}

/**
 * Take a due row for sending: only one caller can win. The attempt is counted
 * and the row is held (leased) so a tick that dies mid-send is retried later
 * rather than at once, and an overlapping tick skips it.
 *
 * @param array             $row Row as selected.
 * @param DateTimeImmutable $now Now.
 * @return bool True when this caller owns the row.
 */
function jlife_challenges_reminders_claim( $row, DateTimeImmutable $now ) {
	global $wpdb;
	$table = jlife_challenges_table( 'reminder_sends' );
	$lease = jlife_challenges_reminders_stamp( $now->modify( '+' . JLIFE_CHALLENGES_REMINDER_LEASE_MINUTES . ' minutes' ) );
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- custom table; name from $wpdb->prefix.
	$won = $wpdb->query( $wpdb->prepare( "UPDATE {$table} SET attempts = attempts + 1, next_attempt = %s, updated = %s WHERE id = %d AND status = 'queued' AND attempts = %d AND attempts < %d AND next_attempt <= %s", $lease, jlife_challenges_reminders_stamp( $now ), (int) $row['id'], (int) $row['attempts'], JLIFE_CHALLENGES_REMINDER_MAX_ATTEMPTS, jlife_challenges_reminders_stamp( $now ) ) );
	return 1 === (int) $won;
}

/**
 * Record a row's outcome.
 *
 * @param int               $id          Row ID.
 * @param string            $status      accepted|failed|skipped|queued.
 * @param string            $error_class Fixed error/skip class, or ''.
 * @param DateTimeImmutable $now         Now.
 * @param string            $next        For queued: UTC time of the next attempt.
 */
function jlife_challenges_reminders_mark( $id, $status, $error_class, DateTimeImmutable $now, $next = '' ) {
	global $wpdb;
	$data = array(
		'status'           => $status,
		'last_error_class' => '' === $error_class ? null : $error_class,
		'updated'          => jlife_challenges_reminders_stamp( $now ),
	);
	if ( '' !== $next ) {
		$data['next_attempt'] = $next;
	}
	$wpdb->update( jlife_challenges_table( 'reminder_sends' ), $data, array( 'id' => (int) $id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- custom table.
}

/**
 * A failed attempt: retry at +10, +30, +60 minutes, but never at or after noon
 * and never beyond four attempts.
 *
 * @param array             $row         Row, with attempts already counted.
 * @param array             $campaign    Campaign row.
 * @param string            $error_class Error class.
 * @param DateTimeImmutable $now         Now.
 */
function jlife_challenges_reminders_after_failure( $row, $campaign, $error_class, DateTimeImmutable $now ) {
	if ( empty( $campaign['id'] ) ) {
		jlife_challenges_reminders_mark( (int) $row['id'], 'failed', $error_class, $now );
		return;
	}
	$attempts = (int) $row['attempts'];
	$index    = jlife_challenges_day_index( $campaign, (string) $row['day_key'] );
	if ( 'send_failed' === $error_class && $attempts < JLIFE_CHALLENGES_REMINDER_MAX_ATTEMPTS && $index > 0 ) {
		$minutes = JLIFE_CHALLENGES_REMINDER_RETRY_MINUTES[ min( $attempts, count( JLIFE_CHALLENGES_REMINDER_RETRY_MINUTES ) ) - 1 ];
		$next    = $now->modify( '+' . $minutes . ' minutes' );
		if ( $next < jlife_challenges_reminder_cutoff( $campaign, $index ) ) {
			jlife_challenges_reminders_mark( (int) $row['id'], 'queued', $error_class, $now, jlife_challenges_reminders_stamp( $next ) );
			return;
		}
	}
	jlife_challenges_reminders_mark( (int) $row['id'], 'failed', $error_class, $now );
}

/**
 * Build one day's message for a language, without sending anything.
 *
 * @param array  $campaign Campaign row.
 * @param int    $index    Day index.
 * @param int    $user_id  Recipient (only names the unsubscribe link).
 * @param string $locale   en_US or vi.
 * @return array|null { subject, text, html, today_url, unsubscribe_url }, or null when the day has no reading.
 */
function jlife_challenges_reminder_build( $campaign, $index, $user_id, $locale ) {
	$build = static function () use ( $campaign, $index, $user_id ) {
		if ( ! function_exists( 'jlife_studies_get_reading_day' ) || $index < 1 ) {
			return null;
		}
		$reading = jlife_studies_get_reading_day( (string) $campaign['plan_id'], jlife_challenges_day_key( $index ) );
		$day     = is_array( $reading ) && isset( $reading['day'] ) && is_array( $reading['day'] ) ? $reading['day'] : null;
		$title   = $day ? trim( (string) ( $day['title'] ?? '' ) ) : '';
		if ( '' === $title ) {
			return null;
		}
		$title    = trim( preg_replace( '/\s*\|\s*/', ' · ', preg_replace( '/[\r\n]+/', ' ', wp_strip_all_tags( $title ) ) ) );
		$passages = array();
		foreach ( (array) ( $day['passages'] ?? array() ) as $passage ) {
			$display = is_array( $passage ) ? trim( wp_strip_all_tags( (string) ( $passage['display'] ?? '' ) ) ) : '';
			if ( '' !== $display ) {
				$passages[] = $display;
			}
		}
		$today = home_url( '/today/' );
		$unsub = jlife_challenges_unsubscribe_url( $user_id, (int) $campaign['id'] );

		/* translators: 1: day number, 2: the day's reading title. */
		$subject = sprintf( __( 'Day %1$d: %2$s', 'jlife-challenges' ), $index, $title );
		$hello   = __( "Good morning! Here is today's reading.", 'jlife-challenges' );
		/* translators: %d: day number. */
		$heading = sprintf( __( 'Day %d', 'jlife-challenges' ), $index );
		$open    = __( "Open today's reading", 'jlife-challenges' );
		$why     = __( 'You get this email because you chose daily reading emails.', 'jlife-challenges' );
		$stop    = __( 'Stop these emails', 'jlife-challenges' );

		$text = $hello . "\n\n" . $heading . ': ' . $title . "\n";
		foreach ( $passages as $p ) {
			$text .= '- ' . $p . "\n";
		}
		/* translators: %s: link. */
		$text .= "\n" . sprintf( __( "Open today's reading: %s", 'jlife-challenges' ), $today ) . "\n\n--\n" . $why . "\n";
		/* translators: %s: link. */
		$text .= sprintf( __( 'Stop these emails: %s', 'jlife-challenges' ), $unsub ) . "\n";

		$html = '<div style="font-family:Arial,Helvetica,sans-serif;font-size:16px;line-height:1.5;color:#1a1a1a;max-width:560px">'
			. '<p>' . esc_html( $hello ) . '</p>'
			. '<h2 style="margin:16px 0 4px;font-size:20px">' . esc_html( $heading ) . '</h2>'
			. '<p style="margin:0 0 8px"><strong>' . esc_html( $title ) . '</strong></p><ul>';
		foreach ( $passages as $p ) {
			$html .= '<li>' . esc_html( $p ) . '</li>';
		}
		$html .= '</ul><p><a href="' . esc_url( $today ) . '">' . esc_html( $open ) . '</a></p>'
			. '<hr style="border:none;border-top:1px solid #ddd;margin:24px 0 12px">'
			. '<p style="font-size:13px;color:#555">' . esc_html( $why ) . ' <a href="' . esc_url( $unsub ) . '">' . esc_html( $stop ) . '</a></p></div>';

		return array(
			'subject'         => $subject,
			'text'            => $text,
			'html'            => $html,
			'today_url'       => $today,
			'unsubscribe_url' => $unsub,
		);
	};
	return function_exists( 'jlife_auth_in_locale' ) ? jlife_auth_in_locale( $locale, $build ) : $build();
}

/**
 * Preview a day's message in a language for a made-up recipient (WP-CLI). Sends and logs nothing.
 *
 * @param array  $campaign Campaign row.
 * @param int    $index    Day index.
 * @param string $locale   en_US or vi.
 * @return array|null As jlife_challenges_reminder_build().
 */
function jlife_challenges_reminder_preview( $campaign, $index, $locale ) {
	if ( $index < 1 || $index > jlife_challenges_day_count( $campaign ) ) {
		return null;
	}
	return jlife_challenges_reminder_build( $campaign, (int) $index, 0, jlife_challenges_reminder_locale( (string) $locale ) );
}

/**
 * Whether the mail transport can send. When Brevo is enabled for this site but
 * its private configuration is incomplete, sending is blocked (never a silent
 * fallback to host mail).
 *
 * @return string 'ok' or 'configuration_missing'.
 */
function jlife_challenges_reminder_mail_state() {
	$state = 'ok';
	if ( function_exists( 'jlife_mail_is_enabled' ) && jlife_mail_is_enabled() && function_exists( 'jlife_mail_is_configured' ) && ! jlife_mail_is_configured() ) {
		$state = 'configuration_missing';
	}
	return (string) apply_filters( 'jlife_challenges_reminder_mail_state', $state );
}

/**
 * Hand one message to wp_mail (Brevo when enabled), as HTML with a plain-text
 * part and the one-click unsubscribe headers.
 *
 * @param string $to      Recipient address.
 * @param array  $message jlife_challenges_reminder_build() result.
 * @return bool True when wp_mail accepted it.
 */
function jlife_challenges_reminders_send_mail( $to, $message ) {
	$alt = static function ( $mailer ) use ( $message ) {
		$mailer->AltBody = $message['text']; // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- PHPMailer's property.
	};
	add_action( 'phpmailer_init', $alt, 5 );
	try {
		$sent = wp_mail(
			$to,
			$message['subject'],
			$message['html'],
			array(
				'Content-Type: text/html; charset=UTF-8',
				'List-Unsubscribe: <' . $message['unsubscribe_url'] . '>',
				'List-Unsubscribe-Post: List-Unsubscribe=One-Click',
			)
		);
	} catch ( Throwable $e ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement -- the message can hold the address or body; record only the class.
		$sent = false;
	} finally {
		remove_action( 'phpmailer_init', $alt, 5 );
	}
	return true === $sent;
}

/**
 * Handle one due row: recheck everything, then send (or skip, or fail).
 *
 * @param array             $row        Row, with the attempt just claimed counted.
 * @param string            $mode       dry_run|test|live.
 * @param DateTimeImmutable $now        Now.
 * @param array             $recipients Campaign ID => user IDs, filled as needed.
 */
function jlife_challenges_reminders_process_row( $row, $mode, DateTimeImmutable $now, array &$recipients ) {
	$id       = (int) $row['id'];
	$user_id  = (int) $row['user_id'];
	$campaign = jlife_challenges_get_campaign( (int) $row['campaign_id'] );
	if ( $campaign && ! jlife_challenges_campaign_accepts_writes( $campaign ) ) {
		jlife_challenges_reminders_mark( $id, 'cancelled', 'campaign_closed', $now );
		return;
	}
	if ( ! $campaign || 'active' !== $campaign['status'] ) {
		jlife_challenges_reminders_mark( $id, 'skipped', 'campaign_inactive', $now );
		return;
	}
	$window = jlife_challenges_reminder_window( $campaign, $now );
	if ( ! $window['open'] || $window['day_key'] !== $row['day_key'] ) {
		jlife_challenges_reminders_mark( $id, 'skipped', 'expired', $now );
		return;
	}
	$cid = (int) $campaign['id'];
	if ( ! isset( $recipients[ $cid ] ) ) {
		$recipients[ $cid ] = jlife_challenges_reminder_recipients( $cid );
	}
	$pref = jlife_challenges_reminder_pref( $user_id, $cid );
	if ( ! in_array( $user_id, $recipients[ $cid ], true ) || ! jlife_challenges_reminder_is_on( $pref ) ) {
		jlife_challenges_reminders_mark( $id, 'skipped', 'not_recipient', $now );
		return;
	}
	$locale  = jlife_challenges_reminder_locale( $pref ? (string) $pref['locale'] : '' );
	$message = jlife_challenges_reminder_build( $campaign, $window['day'], $user_id, $locale );
	if ( null === $message ) {
		jlife_challenges_reminders_mark( $id, 'failed', 'no_reading', $now );
		return;
	}
	if ( 'dry_run' === $mode ) {
		jlife_challenges_reminders_mark( $id, 'skipped', 'dry_run', $now );
		return;
	}
	$user  = get_userdata( $user_id );
	$email = $user ? strtolower( trim( (string) $user->user_email ) ) : '';
	if ( 'test' === $mode && ! in_array( $email, jlife_challenges_reminder_test_recipients(), true ) ) {
		jlife_challenges_reminders_mark( $id, 'skipped', 'not_test_recipient', $now );
		return;
	}
	if ( '' === $email || false === is_email( $email ) ) {
		jlife_challenges_reminders_mark( $id, 'failed', 'no_email', $now );
		return;
	}
	if ( 'ok' !== jlife_challenges_reminder_mail_state() ) {
		jlife_challenges_reminders_mark( $id, 'failed', 'configuration_missing', $now );
		return;
	}
	if ( jlife_challenges_reminders_send_mail( (string) $user->user_email, $message ) ) {
		jlife_challenges_reminders_mark( $id, 'accepted', '', $now );
		return;
	}
	jlife_challenges_reminders_after_failure( $row, $campaign, 'send_failed', $now );
}

/**
 * One scheduler tick (the cron event): queue, expire and send. Does nothing in
 * mode off. Never throws: a problem here must not reach anything else.
 *
 * @return array { mode: string, queued: int, handled: int } for WP-CLI.
 */
function jlife_challenges_reminders_tick() {
	$result = array(
		'mode'    => jlife_challenges_reminders_mode(),
		'queued'  => 0,
		'handled' => 0,
	);
	if ( 'off' === $result['mode'] ) {
		return $result;
	}
	// A short lock so overlapping ticks do not both work; the claim and the UNIQUE key are the real guards.
	$held = (int) get_option( 'jlife_reminders_lock', 0 );
	if ( $held > time() ) {
		return $result;
	}
	update_option( 'jlife_reminders_lock', time() + JLIFE_CHALLENGES_REMINDER_LOCK_SECONDS, false );
	try {
		$now = jlife_challenges_now();
		foreach ( jlife_challenges_reminders_active_campaigns() as $campaign ) {
			$result['queued'] += jlife_challenges_reminders_enqueue( $campaign, $now );
			jlife_challenges_reminders_expire( $campaign, $now );
		}
		global $wpdb;
		$table = jlife_challenges_table( 'reminder_sends' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- custom table; name from $wpdb->prefix.
		$due        = (array) $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE status = 'queued' AND next_attempt <= %s ORDER BY next_attempt, id LIMIT %d", jlife_challenges_reminders_stamp( $now ), JLIFE_CHALLENGES_REMINDER_BATCH ), ARRAY_A );
		$recipients = array();
		foreach ( $due as $row ) {
			if ( ! jlife_challenges_reminders_claim( $row, $now ) ) {
				continue;
			}
			$row['attempts'] = (int) $row['attempts'] + 1;
			try {
				jlife_challenges_reminders_process_row( $row, $result['mode'], $now, $recipients );
			} catch ( Throwable $e ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement -- the message can hold personal data; record only the class.
				jlife_challenges_reminders_after_failure( $row, (array) jlife_challenges_get_campaign( (int) $row['campaign_id'] ), 'send_failed', $now );
			}
			++$result['handled'];
		}
	} catch ( Throwable $e ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement -- never let a reminder problem escape the cron event.
		error_log( 'jlife-reminders: tick_failed' ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- fixed operational string only.
	} finally {
		delete_option( 'jlife_reminders_lock' );
	}
	return $result;
}

/**
 * Today's numbers for a campaign (WP-CLI status). No addresses.
 *
 * @param array $campaign Campaign row.
 * @return array
 */
function jlife_challenges_reminders_status( $campaign ) {
	global $wpdb;
	$window = jlife_challenges_reminder_window( $campaign, jlife_challenges_now() );
	$counts = array_fill_keys( array( 'queued', 'accepted', 'failed', 'skipped', 'cancelled' ), 0 );
	if ( '' !== $window['day_key'] ) {
		$table = jlife_challenges_table( 'reminder_sends' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- custom table; name from $wpdb->prefix.
		$rows = (array) $wpdb->get_results( $wpdb->prepare( "SELECT status, COUNT(*) AS n FROM {$table} WHERE campaign_id = %d AND day_key = %s GROUP BY status", (int) $campaign['id'], $window['day_key'] ), ARRAY_A );
		foreach ( $rows as $r ) {
			$counts[ $r['status'] ] = (int) $r['n'];
		}
	}
	return array(
		'mode'        => jlife_challenges_reminders_mode(),
		'campaign_id' => (int) $campaign['id'],
		'title'       => (string) $campaign['title'],
		'day'         => $window['day'],
		'day_key'     => $window['day_key'],
		'window_open' => $window['open'],
		'recipients'  => count( jlife_challenges_reminder_recipients( (int) $campaign['id'] ) ),
		'counts'      => $counts,
		'next_run'    => (int) wp_next_scheduled( JLIFE_CHALLENGES_REMINDERS_HOOK ),
	);
}
