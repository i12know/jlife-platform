<?php
/**
 * Reminders admin page (#131, #63 Phase B): who can see and change the sending
 * mode, the Live safeguards, the audit trail, counts-only output and the
 * Plugins-screen link. Written before the feature.
 *
 * @package jlife-challenges
 */

// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- tests read the custom tables directly.

/**
 * Reminders admin page tests.
 */
class Test_Reminders_Admin extends Jlife_Challenges_TestCase {

	/**
	 * What the mail-state filter answers.
	 *
	 * @var string
	 */
	private $mail_state = 'ok';

	/**
	 * Addresses the test-mode filter answers.
	 *
	 * @var string[]
	 */
	private $test_list = array();

	/**
	 * Whether Brevo counts as turned on.
	 *
	 * @var bool
	 */
	private $brevo = true;

	/**
	 * Mode off, mail ready, consent for a, b and c.
	 */
	public function set_up() {
		parent::set_up();
		foreach ( array( 'a', 'b', 'c' ) as $n ) {
			jlife_challenges_record_consent( $this->u[ $n ], $this->campaign, 'test-version' );
		}
		$this->mail_state = 'ok';
		$this->test_list  = array();
		$this->brevo      = true;
		add_filter( 'jlife_challenges_reminder_mail_state', array( $this, 'filter_mail_state' ) );
		add_filter( 'jlife_challenges_reminder_brevo_enabled', array( $this, 'filter_brevo' ) );
		add_filter( 'jlife_challenges_reminder_test_recipients', array( $this, 'filter_test_list' ) );
		delete_option( 'jlife_reminders_mode' );
	}

	/**
	 * Remove the fakes.
	 */
	public function tear_down() {
		remove_filter( 'jlife_challenges_reminder_mail_state', array( $this, 'filter_mail_state' ) );
		remove_filter( 'jlife_challenges_reminder_brevo_enabled', array( $this, 'filter_brevo' ) );
		remove_filter( 'jlife_challenges_reminder_test_recipients', array( $this, 'filter_test_list' ) );
		delete_option( 'jlife_reminders_mode' );
		$_GET = array();
		wp_set_current_user( 0 );
		parent::tear_down();
	}

	/**
	 * Mail-state filter callback.
	 *
	 * @return string
	 */
	public function filter_mail_state() {
		return $this->mail_state;
	}

	/**
	 * Brevo filter callback.
	 *
	 * @return bool
	 */
	public function filter_brevo() {
		return $this->brevo;
	}

	/**
	 * Test-recipient filter callback.
	 *
	 * @return string[]
	 */
	public function filter_test_list() {
		return $this->test_list;
	}

	/**
	 * Apply a change as the given user, with a valid nonce unless told otherwise.
	 *
	 * @param int    $user_id Signed-in user.
	 * @param string $mode    Requested mode.
	 * @param array  $extra   Field overrides (nonce, confirm_live) and method.
	 * @return string Result code.
	 */
	private function apply_as( $user_id, $mode, $extra = array() ) {
		wp_set_current_user( $user_id );
		$method = $extra['method'] ?? 'POST';
		unset( $extra['method'] );
		return jlife_challenges_reminders_admin_apply(
			$method,
			$extra + array(
				'nonce'        => wp_create_nonce( JLIFE_CHALLENGES_REMINDERS_ACTION ),
				'mode'         => $mode,
				'confirm_live' => false,
			)
		);
	}

	/**
	 * Mode-change audit rows, oldest first.
	 *
	 * @return array[]
	 */
	private function mode_audits() {
		global $wpdb;
		$table = jlife_challenges_table( 'audit_log' );
		return (array) $wpdb->get_results( "SELECT * FROM {$table} WHERE action = 'reminders_mode' ORDER BY id", ARRAY_A );
	}

	/**
	 * Two consented, opted-in participants.
	 */
	private function opt_in_two() {
		foreach ( array( 'a', 'b' ) as $n ) {
			$this->assertTrue( jlife_challenges_set_reminder( $this->u[ $n ], $this->u[ $n ], $this->campaign, true, 'en_US' ) );
		}
	}

	/**
	 * The page's overview for the test campaign.
	 *
	 * @return array
	 */
	private function status_for_campaign() {
		$o = jlife_challenges_reminders_admin_overview();
		foreach ( $o['statuses'] as $s ) {
			if ( (int) $s['campaign_id'] === $this->campaign ) {
				return $s;
			}
		}
		$this->fail( 'the active campaign is listed' );
	}

	/**
	 * A participant cannot see the page or change the mode.
	 */
	public function test_a_non_admin_cannot_see_the_page_or_change_the_mode() {
		wp_set_current_user( $this->u['a'] );
		$this->assertSame( 'forbidden', $this->apply_as( $this->u['a'], 'live', array( 'confirm_live' => true ) ) );
		$this->assertSame( 'forbidden', $this->apply_as( $this->u['a'], 'dry_run' ) );
		$this->assertSame( 'off', jlife_challenges_reminders_mode() );
		$this->assertSame( array(), $this->mode_audits() );
		$this->expectException( WPDieException::class );
		jlife_challenges_reminders_admin_page();
	}

	/**
	 * The menu entry needs manage_options, and an admin sees the page.
	 */
	public function test_the_menu_entry_needs_manage_options_and_the_admin_sees_the_page() {
		global $menu, $submenu;
		$menu    = array(); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- a clean admin menu for the test.
		$submenu = array(); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- a clean admin menu for the test.
		wp_set_current_user( $this->u['organizer'] );
		jlife_challenges_reminders_admin_menu();
		$found = array_values(
			array_filter(
				$menu,
				static function ( $item ) {
					return JLIFE_CHALLENGES_REMINDERS_PAGE === $item[2];
				}
			)
		);
		$this->assertCount( 1, $found );
		$this->assertSame( 'manage_options', $found[0][1] );
		$this->assertSame( 'Reminders', $submenu[ JLIFE_CHALLENGES_REMINDERS_PAGE ][0][0] );
		$this->assertSame( 'manage_options', $submenu[ JLIFE_CHALLENGES_REMINDERS_PAGE ][0][1] );

		ob_start();
		jlife_challenges_reminders_admin_page();
		$page = (string) ob_get_clean();
		$this->assertStringContainsString( 'Daily reading reminders', $page );
	}

	/**
	 * A missing, wrong or another action's nonce changes nothing, and so does a GET.
	 */
	public function test_a_missing_or_bad_nonce_or_a_get_changes_nothing() {
		$this->assertSame( 'bad_nonce', $this->apply_as( $this->u['organizer'], 'dry_run', array( 'nonce' => '' ) ) );
		$this->assertSame( 'bad_nonce', $this->apply_as( $this->u['organizer'], 'dry_run', array( 'nonce' => 'nope123456' ) ) );
		$this->assertSame( 'bad_nonce', $this->apply_as( $this->u['organizer'], 'dry_run', array( 'nonce' => wp_create_nonce( 'some_other_action' ) ) ) );
		$this->assertSame( 'bad_method', $this->apply_as( $this->u['organizer'], 'dry_run', array( 'method' => 'GET' ) ) );
		$this->assertSame( 'off', jlife_challenges_reminders_mode() );
		$this->assertSame( array(), $this->mode_audits() );
	}

	/**
	 * An unknown mode is refused.
	 */
	public function test_an_unknown_mode_is_refused() {
		$this->assertSame( 'bad_mode', $this->apply_as( $this->u['organizer'], 'sendnow' ) );
		$this->assertSame( 'bad_mode', $this->apply_as( $this->u['organizer'], '' ) );
		$this->assertSame( 'off', jlife_challenges_reminders_mode() );
	}

	/**
	 * Dry run and test switch with a valid nonce, and each change is audited with the admin's ID.
	 */
	public function test_dry_run_and_test_switch_and_every_change_is_audited() {
		$this->assertSame( 'saved', $this->apply_as( $this->u['organizer'], 'dry_run' ) );
		$this->assertSame( 'dry_run', jlife_challenges_reminders_mode() );
		$this->assertSame( 'saved', $this->apply_as( $this->u['organizer'], 'test' ) );
		$this->assertSame( 'test', jlife_challenges_reminders_mode() );
		$this->assertSame( 'saved', $this->apply_as( $this->u['organizer'], 'off' ) );

		$audits = $this->mode_audits();
		$this->assertCount( 3, $audits );
		$this->assertSame(
			array(
				'from' => 'off',
				'to'   => 'dry_run',
			),
			json_decode( $audits[0]['detail'], true )
		);
		$this->assertSame(
			array(
				'from' => 'dry_run',
				'to'   => 'test',
			),
			json_decode( $audits[1]['detail'], true )
		);
		foreach ( $audits as $row ) {
			$this->assertSame( $this->u['organizer'], (int) $row['actor_user_id'] );
		}
	}

	/**
	 * Live needs the explicit confirmation.
	 */
	public function test_live_needs_the_confirmation() {
		$this->assertSame( 'confirm_needed', $this->apply_as( $this->u['organizer'], 'live' ) );
		$this->assertSame( 'off', jlife_challenges_reminders_mode() );
		$this->assertSame( array(), $this->mode_audits() );
		$this->assertSame( 'saved', $this->apply_as( $this->u['organizer'], 'live', array( 'confirm_live' => true ) ) );
		$this->assertSame( 'live', jlife_challenges_reminders_mode() );
		$this->assertCount( 1, $this->mode_audits() );
	}

	/**
	 * Live is refused while the mail state is not ok, even when confirmed.
	 */
	public function test_live_is_refused_while_the_mail_state_is_not_ok() {
		$this->mail_state = 'configuration_missing';
		$this->assertSame( 'mail_not_ok', $this->apply_as( $this->u['organizer'], 'live', array( 'confirm_live' => true ) ) );
		$this->assertSame( 'off', jlife_challenges_reminders_mode() );
		$this->assertSame( array(), $this->mode_audits() );
	}

	/**
	 * Live is refused while Brevo is off (host mail garbles the List-Unsubscribe
	 * header), and the page disables it; Off still works.
	 */
	public function test_live_is_refused_while_brevo_is_off() {
		$this->brevo = false;
		$this->assertSame( 'brevo_off', $this->apply_as( $this->u['organizer'], 'live', array( 'confirm_live' => true ) ) );
		$this->assertSame( 'off', jlife_challenges_reminders_mode() );
		$this->assertSame( array(), $this->mode_audits() );
		$this->assertStringContainsString( 'notice-error', jlife_challenges_reminders_admin_notice( 'brevo_off' ) );
		$html = jlife_challenges_reminders_admin_html( jlife_challenges_reminders_admin_overview() );
		$this->assertMatchesRegularExpression( '/name="jl_mode" value="live" class="button" disabled/', $html );
		$this->assertSame( 'saved', $this->apply_as( $this->u['organizer'], 'test' ) );
	}

	/**
	 * Off is always allowed: from live, with broken mail, with no confirmation.
	 */
	public function test_off_always_works() {
		update_option( 'jlife_reminders_mode', 'live' );
		$this->mail_state = 'configuration_missing';
		$this->assertSame( 'saved', $this->apply_as( $this->u['organizer'], 'off' ) );
		$this->assertSame( 'off', jlife_challenges_reminders_mode() );
		$audits = $this->mode_audits();
		$this->assertCount( 1, $audits );
		$this->assertSame( 'live', json_decode( $audits[0]['detail'], true )['from'] );
		$this->assertSame( 'off', json_decode( $audits[0]['detail'], true )['to'] );
	}

	/**
	 * The default mode is off and rendering the page changes nothing.
	 */
	public function test_viewing_the_page_does_not_change_the_mode() {
		$this->assertSame( 'off', jlife_challenges_reminders_mode() );
		wp_set_current_user( $this->u['organizer'] );
		ob_start();
		jlife_challenges_reminders_admin_page();
		ob_end_clean();
		$this->assertSame( 'off', jlife_challenges_reminders_mode() );
		$this->assertFalse( get_option( 'jlife_reminders_mode', false ) );
		$this->assertSame( array(), $this->mode_audits() );
	}

	/**
	 * The status panel shows the counts for each active campaign.
	 */
	public function test_the_status_panel_shows_counts_per_campaign() {
		$this->opt_in_two();
		$this->set_now( '2026-12-20 14:00:00' ); // 06:00 Pacific, Day 1: the window is open.
		$campaign = $this->campaign_row();
		$this->assertSame( 2, jlife_challenges_reminders_enqueue( $campaign, $this->now ) );

		$s = $this->status_for_campaign();
		$this->assertSame( 2, $s['recipients'] );
		$this->assertSame( 2, $s['counts']['queued'] );
		$this->assertTrue( $s['window_open'] );

		$html = jlife_challenges_reminders_admin_html( jlife_challenges_reminders_admin_overview() );
		foreach ( array( 'Bible Marathon (test)', 'Recipients who opted in', 'Queued today', 'Accepted today', 'Failed today', 'Skipped today', 'Send window open now', 'Next scheduled check', 'Current mode:' ) as $label ) {
			$this->assertStringContainsString( $label, $html, $label );
		}
		$this->assertStringContainsString( '<th scope="row">Recipients who opted in</th><td>2</td>', $html );
		$this->assertStringContainsString( '<th scope="row">Queued today</th><td>2</td>', $html );
		$this->assertStringContainsString( '<th scope="row">Send window open now (06:00 to before 12:00 campaign time)</th><td>Yes</td>', $html );
	}

	/**
	 * The page prints counts only: no participant name or email address, nor a test address.
	 */
	public function test_the_page_html_contains_no_participant_email_or_name() {
		$this->opt_in_two();
		$this->set_now( '2026-12-20 14:00:00' );
		jlife_challenges_reminders_enqueue( $this->campaign_row(), $this->now );
		$secret_test_address = 'owner-secret-test@example.test';
		$this->test_list     = array( $secret_test_address );
		update_option( 'jlife_reminders_mode', 'test' );

		$html = jlife_challenges_reminders_admin_html( jlife_challenges_reminders_admin_overview(), 'saved' );
		foreach ( array( 'a', 'b', 'c', 'outsider', 'organizer' ) as $n ) {
			$user = get_userdata( $this->u[ $n ] );
			$this->assertStringNotContainsString( $user->user_email, $html, "no email of {$n}" );
			$this->assertStringNotContainsString( $user->user_login, $html, "no login of {$n}" );
			$this->assertStringNotContainsString( $user->display_name, $html, "no name of {$n}" );
		}
		$this->assertStringNotContainsString( $secret_test_address, $html );
		$this->assertStringNotContainsString( 'secret-test', $html );
		$this->assertDoesNotMatchRegularExpression( '/[A-Za-z0-9._%+-]+@[A-Za-z0-9.-]+\.[A-Za-z]{2,}/', wp_strip_all_tags( $html ), 'no email address of any kind' );
		$this->assertStringContainsString( 'Test addresses set up: 1', $html, 'the number of addresses only' );
	}

	/**
	 * The form posts to admin-post.php with a nonce, and Live is unavailable when mail is not ready.
	 */
	public function test_the_form_is_a_nonced_post_and_live_is_disabled_when_mail_is_not_ready() {
		$html = jlife_challenges_reminders_admin_html( jlife_challenges_reminders_admin_overview() );
		$this->assertStringContainsString( '<form method="post" action="', $html );
		$this->assertStringContainsString( 'admin-post.php', $html );
		$this->assertStringContainsString( 'name="action" value="' . JLIFE_CHALLENGES_REMINDERS_ACTION . '"', $html );
		$this->assertStringContainsString( 'name="_wpnonce"', $html );
		foreach ( array( 'off', 'dry_run', 'test', 'live' ) as $mode ) {
			$this->assertStringContainsString( 'name="jl_mode" value="' . $mode . '"', $html, $mode );
		}
		$this->assertStringContainsString( 'name="jl_confirm_live"', $html );
		$this->assertStringContainsString( 'email 0 people every morning at 06:00 campaign time (America/Los_Angeles)', $html );
		$this->assertDoesNotMatchRegularExpression( '/name="jl_mode" value="live" class="button" disabled/', $html );

		$this->mail_state = 'configuration_missing';
		$html             = jlife_challenges_reminders_admin_html( jlife_challenges_reminders_admin_overview() );
		$this->assertMatchesRegularExpression( '/name="jl_mode" value="live" class="button" disabled/', $html );
		$this->assertStringContainsString( 'Live is unavailable until the mail settings are ready.', $html );
		$this->assertMatchesRegularExpression( '/name="jl_mode" value="off" class="button button-primary">/', $html, 'Off is never disabled' );
	}

	/**
	 * The Live confirmation names the number of recipients and the 06:00 campaign-time send.
	 */
	public function test_the_live_confirmation_states_the_recipients_and_the_time() {
		$this->opt_in_two();
		$html = jlife_challenges_reminders_admin_html( jlife_challenges_reminders_admin_overview() );
		$this->assertStringContainsString( 'email 2 people every morning at 06:00 campaign time (America/Los_Angeles)', $html );
		$this->assertStringContainsString( 'uses up that day', $html, 'the same-morning warning' );
	}

	/**
	 * Result notices are a fixed set; an unknown code shows nothing.
	 */
	public function test_notices_are_a_fixed_set() {
		$this->assertSame( '', jlife_challenges_reminders_admin_notice( '<script>' ) );
		$this->assertStringContainsString( 'notice-error', jlife_challenges_reminders_admin_notice( 'mail_not_ok' ) );
		$this->assertStringContainsString( 'notice-error', jlife_challenges_reminders_admin_notice( 'confirm_needed' ) );
		update_option( 'jlife_reminders_mode', 'dry_run' );
		$this->assertStringContainsString( 'Dry run', jlife_challenges_reminders_admin_notice( 'saved' ) );
	}

	/**
	 * Each health warning has a condition, and a healthy setup has none.
	 */
	public function test_health_warnings() {
		$now  = 1800000000;
		$good = array(
			'mode'           => 'live',
			'next_run'       => $now + 120,
			'now'            => $now,
			'mail_state'     => 'ok',
			'brevo_enabled'  => true,
			'test_addresses' => 0,
			'recipients'     => 10,
		);
		$this->assertSame( array(), jlife_challenges_reminders_health( $good ) );
		$this->assertSame( array(), jlife_challenges_reminders_health( array( 'next_run' => $now - 200 ) + $good ), 'a tick a little late is fine' );
		$this->assertSame( array( 'cron_unscheduled' ), jlife_challenges_reminders_health( array( 'next_run' => 0 ) + $good ) );
		$this->assertSame( array( 'cron_stale' ), jlife_challenges_reminders_health( array( 'next_run' => $now - 20 * 60 ) + $good ) );
		$this->assertSame( array( 'mail_unconfigured' ), jlife_challenges_reminders_health( array( 'mail_state' => 'configuration_missing' ) + $good ) );
		$this->assertSame( array( 'brevo_disabled' ), jlife_challenges_reminders_health( array( 'brevo_enabled' => false ) + $good ) );
		$this->assertSame( array( 'test_no_addresses' ), jlife_challenges_reminders_health( array( 'mode' => 'test' ) + $good ) );
		$test_ok = array(
			'mode'           => 'test',
			'test_addresses' => 2,
		);
		$this->assertSame( array(), jlife_challenges_reminders_health( $test_ok + $good ), 'test mode with addresses is fine' );
		$this->assertSame( array(), jlife_challenges_reminders_health( array( 'recipients' => 300 ) + $good ), '300 is within the limit' );
		$this->assertSame( array( 'over_daily_limit' ), jlife_challenges_reminders_health( array( 'recipients' => 301 ) + $good ) );
	}

	/**
	 * The page shows a warning for a missing mail setup and for test mode with no addresses.
	 */
	public function test_the_page_shows_the_warnings() {
		$this->mail_state = 'configuration_missing';
		$this->brevo      = false;
		update_option( 'jlife_reminders_mode', 'test' );
		$html = jlife_challenges_reminders_admin_html( jlife_challenges_reminders_admin_overview() );
		$this->assertStringContainsString( 'private settings are incomplete', $html );
		$this->assertStringContainsString( 'JLIFE_REMINDER_TEST_RECIPIENTS is empty', $html );
		$this->assertStringContainsString( 'Brevo is not turned on for this site', $html );
		$this->assertStringNotContainsString( 'No problems found', $html );
	}

	/**
	 * Settings appears next to Deactivate on the Plugins screen, for admins only, site and network.
	 */
	public function test_the_settings_link_appears_on_the_plugins_screen() {
		$file  = plugin_basename( dirname( __DIR__ ) . '/jlife-challenges.php' );
		$links = array( 'deactivate' => '<a href="plugins.php?action=deactivate">Deactivate</a>' );

		wp_set_current_user( $this->u['organizer'] );
		foreach ( array( 'plugin_action_links_' . $file, 'network_admin_plugin_action_links_' . $file ) as $hook ) {
			$out = apply_filters( $hook, $links );
			$this->assertCount( 2, $out, $hook );
			$this->assertStringContainsString( 'page=' . JLIFE_CHALLENGES_REMINDERS_PAGE, implode( '', $out ), $hook );
			$this->assertStringContainsString( '>Settings</a>', implode( '', $out ), $hook );
			$this->assertSame( $links['deactivate'], $out['deactivate'], 'Deactivate is kept' );
		}

		wp_set_current_user( $this->u['a'] );
		$this->assertSame( $links, apply_filters( 'plugin_action_links_' . $file, $links ), 'a non-admin gets no link' );
	}

	/**
	 * The mode stays off after the plugin loads, and set_mode still audits as the system for WP-CLI.
	 */
	public function test_wp_cli_path_is_unchanged() {
		$this->assertSame( 'off', jlife_challenges_reminders_mode() );
		$this->assertTrue( jlife_challenges_reminders_set_mode( 'dry_run' ) );
		$audits = $this->mode_audits();
		$this->assertCount( 1, $audits );
		$this->assertSame( 0, (int) $audits[0]['actor_user_id'] );
	}
}
