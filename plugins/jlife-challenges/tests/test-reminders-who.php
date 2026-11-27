<?php
/**
 * The "Who gets reminders" section of J-Life → Reminders (#268): who may see it,
 * the nonce on the email search, no email address anywhere in the page, and that
 * looking writes and sends nothing. Written before the screen.
 *
 * @package jlife-challenges
 */

// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.Security.NonceVerification -- tests read the custom tables and set request globals directly.

/**
 * Who-gets-reminders section tests.
 */
class Test_Reminders_Who extends Jlife_Challenges_TestCase {

	/**
	 * Extra participants: d (unsubscribed) and e (will be emailed, no log row).
	 *
	 * @var array<string,int>
	 */
	private $extra = array();

	/**
	 * Mail "sent" during a test (must stay empty).
	 *
	 * @var array[]
	 */
	private $sent = array();

	/**
	 * Same scenario as the CLI listing on Day 1: a and e will be emailed (a has a
	 * failed log row), b left the campaign, c has no consent, d unsubscribed.
	 */
	public function set_up() {
		parent::set_up();
		$this->set_now( '2026-12-20 18:00:00' );
		foreach ( array( 'd', 'e' ) as $n ) {
			$this->extra[ $n ] = self::factory()->user->create(
				array(
					'role'         => 'subscriber',
					'display_name' => ucfirst( $n ) . 'name Shown',
					'user_email'   => $n . '-private@example.test',
				)
			);
			$this->assertIsInt( jlife_challenges_enroll( $this->u['organizer'], $this->extra[ $n ], $this->g['g1'] ) );
		}
		wp_update_user(
			array(
				'ID'         => $this->u['a'],
				'user_email' => 'a-private@example.test',
			)
		);
		// Another account whose display name is its own email address.
		wp_update_user(
			array(
				'ID'           => $this->extra['e'],
				'display_name' => 'e-private@example.test',
			)
		);
		foreach ( array( $this->u['a'], $this->u['b'], $this->extra['d'], $this->extra['e'] ) as $id ) {
			jlife_challenges_record_consent( $id, $this->campaign, 'test-version' );
		}
		foreach ( array( $this->u['a'], $this->u['b'], $this->u['c'], $this->extra['d'], $this->extra['e'] ) as $id ) {
			$this->assertTrue( jlife_challenges_set_reminder( $id, $id, $this->campaign, true, $id === $this->u['c'] ? 'vi' : 'en_US' ) );
		}
		jlife_challenges_set_reminder( $this->extra['d'], $this->extra['d'], $this->campaign, false );
		jlife_challenges_remove_member( $this->u['organizer'], $this->campaign, $this->u['b'] );
		global $wpdb;
		$wpdb->update(
			jlife_challenges_table( 'reminder_prefs' ),
			array( 'opted_in' => '2026-12-20 03:30:00' ),
			array(
				'campaign_id' => $this->campaign,
				'user_id'     => $this->u['a'],
			)
		);
		$now = current_time( 'mysql', true );
		$wpdb->insert(
			jlife_challenges_table( 'reminder_sends' ),
			array(
				'campaign_id'      => $this->campaign,
				'day_key'          => 'd001',
				'user_id'          => $this->u['a'],
				'status'           => 'failed',
				'attempts'         => 4,
				'next_attempt'     => $now,
				'last_error_class' => 'send_failed',
				'created'          => $now,
				'updated'          => $now,
			)
		);
		$this->sent = array();
		add_filter( 'pre_wp_mail', array( $this, 'capture_mail' ), 5, 2 );
	}

	/**
	 * Remove the fake transport and the request globals.
	 */
	public function tear_down() {
		remove_filter( 'pre_wp_mail', array( $this, 'capture_mail' ), 5 );
		$_GET  = array();
		$_POST = array();
		unset( $_SERVER['REQUEST_METHOD'] );
		wp_set_current_user( 0 );
		parent::tear_down();
	}

	/**
	 * Fake transport: record anything sent.
	 *
	 * @param null|bool $pre  Short-circuit value.
	 * @param array     $atts wp_mail arguments.
	 * @return bool
	 */
	public function capture_mail( $pre, $atts ) {
		$this->sent[] = $atts;
		return true;
	}

	/**
	 * The section's HTML for a request, as the given user.
	 *
	 * @param int    $viewer Signed-in user (0 = signed out).
	 * @param string $method GET or POST.
	 * @param array  $in     Request inputs (nonce, email, name, page).
	 * @return string
	 */
	private function who_html( $viewer, $method = 'GET', $in = array() ) {
		wp_set_current_user( $viewer );
		$query = jlife_challenges_reminders_who_query( $method, $in );
		return jlife_challenges_reminders_who_html( jlife_challenges_reminders_active_campaigns(), $query );
	}

	/**
	 * A valid email-search request.
	 *
	 * @param string $email Address typed.
	 * @return array
	 */
	private function email_request( $email ) {
		wp_set_current_user( $this->u['organizer'] ); // The nonce belongs to the signed-in admin.
		return array(
			'nonce' => wp_create_nonce( JLIFE_CHALLENGES_REMINDERS_WHO_ACTION ),
			'email' => $email,
		);
	}

	/**
	 * Row counts of every table the screen could be tempted to write.
	 *
	 * @return array<string,int>
	 */
	private function counts() {
		global $wpdb;
		$out = array();
		foreach ( array( 'reminder_prefs', 'reminder_sends', 'enrollments', 'audit_log' ) as $name ) {
			$table        = jlife_challenges_table( $name );
			$out[ $name ] = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" );
		}
		return $out;
	}

	/**
	 * Nobody but a site admin gets the list or a search result, whatever they forge.
	 */
	public function test_only_a_site_admin_gets_anything() {
		$editor  = self::factory()->user->create( array( 'role' => 'editor' ) );
		$author  = self::factory()->user->create( array( 'role' => 'author' ) );
		$viewers = array();

		$viewers['editor']            = $editor;
		$viewers['author']            = $author;
		$viewers['participant']       = $this->u['a'];
		$viewers['other participant'] = $this->u['outsider'];
		$viewers['signed out']        = 0;
		foreach ( $viewers as $who => $id ) {
			$this->assertSame( '', $this->who_html( $id ), "{$who}: no list" );
			$this->assertSame( '', $this->who_html( $id, 'GET', array( 'name' => 'name' ) ), "{$who}: no name search" );
			$this->assertSame( '', $this->who_html( $id, 'GET', array( 'email' => 'a-private@example.test' ) ), "{$who}: no GET email search" );
			wp_set_current_user( $id );
			$forged = $this->email_request( 'a-private@example.test' );
			$this->assertSame( '', $this->who_html( $id, 'POST', $forged ), "{$who}: no forged POST search" );
			$this->assertSame( 'forbidden', jlife_challenges_reminders_who_query( 'POST', $forged )['state'], $who );
		}
	}

	/**
	 * The page callback refuses a non-admin before any of the section is built.
	 */
	public function test_the_page_callback_dies_for_a_non_admin() {
		wp_set_current_user( $this->u['a'] );
		$_SERVER['REQUEST_METHOD'] = 'POST';

		$_POST = array(
			'jl_who_nonce' => wp_create_nonce( JLIFE_CHALLENGES_REMINDERS_WHO_ACTION ),
			'jl_email'     => 'a-private@example.test',
		);
		$this->expectException( WPDieException::class );
		jlife_challenges_reminders_admin_page();
	}

	/**
	 * An admin sees the three lists with the columns, links, local times and statuses.
	 */
	public function test_an_admin_sees_the_three_lists() {
		$html = $this->who_html( $this->u['organizer'] );
		foreach ( array( 'Who gets reminders', 'Will be emailed (the next 06:00 send)', 'Opted in but not emailed', 'Unsubscribed', 'Bible Marathon (test)' ) as $text ) {
			$this->assertStringContainsString( $text, $html, $text );
		}
		$this->assertStringContainsString( 'Total, Will be emailed (the next 06:00 send): 2', $html );
		$this->assertStringContainsString( 'Total, Opted in but not emailed: 2', $html );
		$this->assertStringContainsString( 'Total, Unsubscribed: 1', $html );
		$this->assertStringContainsString( 'user-edit.php?user_id=' . $this->u['a'] . '"', $html );
		$this->assertStringContainsString( 'Aname Private', $html );
		$this->assertStringContainsString( 'Dname Shown', $html );
		$this->assertStringContainsString( 'Failed (send_failed)', $html );
		$this->assertStringContainsString( 'None yet', $html );
		$this->assertStringContainsString( 'Enrollment is not active', $html );
		$this->assertStringContainsString( 'Consent not accepted', $html );
		$this->assertStringContainsString( 'Vietnamese', $html );
		$this->assertStringContainsString( '2026-12-19 19:30 PST', $html, 'the opt-in time is shown in the campaign time zone, not UTC' );
		$this->assertStringNotContainsString( '2026-12-20 03:30', $html );
	}

	/**
	 * No email address anywhere, even when a display name is an address or after an email search.
	 */
	public function test_the_html_never_contains_an_email_address() {
		$typed = 'a-private@example.test';
		foreach ( array( $this->who_html( $this->u['organizer'] ), $this->who_html( $this->u['organizer'], 'POST', $this->email_request( $typed ) ), $this->who_html( $this->u['organizer'], 'POST', $this->email_request( 'nobody@example.test' ) ), $this->who_html( $this->u['organizer'], 'GET', array( 'name' => 'e-private@example.test' ) ) ) as $i => $html ) {
			$this->assertStringNotContainsString( '@', $html, "html {$i}" );
			$this->assertStringNotContainsString( 'private@', $html, "html {$i}" );
		}
		$this->assertStringContainsString( '(name hidden: looks like an email address)', $this->who_html( $this->u['organizer'] ) );
	}

	/**
	 * The email search is a nonced POST, shows only that person, and never echoes the address.
	 */
	public function test_the_email_search_shows_one_person_and_does_not_echo_the_address() {
		$html = $this->who_html( $this->u['organizer'], 'POST', $this->email_request( 'a-private@example.test' ) );
		$this->assertStringContainsString( 'Results for the address you entered', $html );
		$this->assertStringContainsString( 'Aname Private', $html );
		$this->assertStringNotContainsString( 'Dname Shown', $html );
		$this->assertStringNotContainsString( 'a-private', $html );
		$this->assertStringContainsString( 'Total, Will be emailed (the next 06:00 send): 1', $html );
		$this->assertStringContainsString( 'Total, Unsubscribed: 0', $html );
		$this->assertMatchesRegularExpression( '/<input type="email"[^>]*value=""/', $html, 'the search box is empty' );

		$none = $this->who_html( $this->u['organizer'], 'POST', $this->email_request( 'nobody@example.test' ) );
		$this->assertStringContainsString( 'No account has the address you entered.', $none );
		$this->assertStringNotContainsString( 'Aname Private', $none );
		$this->assertStringNotContainsString( 'nobody', $none );
	}

	/**
	 * A bad, missing or another action's nonce gives no result; a GET email is never read.
	 */
	public function test_the_email_search_needs_a_valid_nonce_and_a_post() {
		foreach ( array( '', 'nope123456', wp_create_nonce( 'some_other_action' ) ) as $nonce ) {
			$html = $this->who_html(
				$this->u['organizer'],
				'POST',
				array(
					'nonce' => $nonce,
					'email' => 'a-private@example.test',
				)
			);
			$this->assertStringContainsString( 'could not be checked', $html );
			$this->assertStringNotContainsString( 'Aname Private', $html, 'no result' );
			$this->assertStringNotContainsString( 'Will be emailed', $html );
		}
		wp_set_current_user( $this->u['organizer'] );
		$get = jlife_challenges_reminders_who_query( 'GET', $this->email_request( 'a-private@example.test' ) );
		$this->assertSame( 'all', $get['state'] );
		$this->assertSame( 0, $get['user_id'], 'a GET never searches by email' );
	}

	/**
	 * Through the page callback: a POST search renders one person and the typed address stays out of the page.
	 */
	public function test_the_page_callback_handles_the_post_search() {
		wp_set_current_user( $this->u['organizer'] );
		$_SERVER['REQUEST_METHOD'] = 'POST';

		$_POST = array(
			'jl_who_nonce' => wp_create_nonce( JLIFE_CHALLENGES_REMINDERS_WHO_ACTION ),
			'jl_email'     => 'a-private@example.test',
		);
		ob_start();
		jlife_challenges_reminders_admin_page();
		$page = (string) ob_get_clean();
		$this->assertStringContainsString( 'Results for the address you entered', $page );
		$this->assertStringContainsString( 'Aname Private', $page );
		$this->assertStringNotContainsString( 'Dname Shown', $page );
		$this->assertStringNotContainsString( 'a-private', $page );
		$this->assertStringNotContainsString( 'Bname', $page );
	}

	/**
	 * A name search matches part of the display name, in any case, and never an address.
	 */
	public function test_the_name_search_matches_part_of_a_name() {
		$html = $this->who_html( $this->u['organizer'], 'GET', array( 'name' => 'dNAME' ) );
		$this->assertStringContainsString( 'Dname Shown', $html );
		$this->assertStringNotContainsString( 'Aname Private', $html );
		$this->assertStringContainsString( 'value="dNAME"', $html );

		$at = $this->who_html( $this->u['organizer'], 'GET', array( 'name' => 'e-private@example.test' ) );
		$this->assertStringContainsString( 'Use the email box', $at );
		$this->assertStringNotContainsString( 'Aname Private', $at );
	}

	/**
	 * Paging works on the report and shows previous/next only where there is another page.
	 */
	public function test_paging_slices_each_section() {
		$campaign = $this->campaign_row();
		$all      = jlife_challenges_reminder_recipients_report( $campaign );
		$this->assertSame( 2, $all['sections'][0]['count'] );
		$this->assertCount( 2, $all['sections'][0]['rows'], 'no per_page: every row, as the CLI' );
		$this->assertSame( 1, $all['sections'][0]['pages'] );

		$page2 = jlife_challenges_reminder_recipients_report(
			$campaign,
			0,
			array(
				'per_page' => 1,
				'page'     => 2,
			)
		);
		$this->assertSame( 2, $page2['sections'][0]['pages'] );
		$this->assertSame( 2, $page2['sections'][0]['page'] );
		$this->assertCount( 1, $page2['sections'][0]['rows'] );
		$this->assertSame( $all['sections'][0]['rows'][1]['user_id'], $page2['sections'][0]['rows'][0]['user_id'] );
		$this->assertSame( 'Total, will be emailed (the next 06:00 send): 2', $page2['sections'][0]['total'], 'the total counts every page' );

		$beyond = jlife_challenges_reminder_recipients_report(
			$campaign,
			0,
			array(
				'per_page' => 1,
				'page'     => 9,
			)
		);
		$this->assertSame( 2, $beyond['sections'][0]['page'], 'a page past the end shows the last page' );

		$pager = jlife_challenges_reminders_who_pager(
			array(
				'page'  => 2,
				'pages' => 3,
			),
			array( 'name' => 'Ann' )
		);
		$this->assertStringContainsString( 'Page 2 of 3', $pager );
		$this->assertStringContainsString( 'jl_page=1', $pager );
		$this->assertStringContainsString( 'jl_page=3', $pager );
		$this->assertStringContainsString( 'jl_name=Ann', $pager );
		$this->assertSame(
			'',
			jlife_challenges_reminders_who_pager(
				array(
					'page'  => 1,
					'pages' => 1,
				),
				array( 'name' => '' )
			)
		);
		$this->assertSame( 50, JLIFE_CHALLENGES_REMINDERS_WHO_PER_PAGE );
	}

	/**
	 * The name filter looks at the printed name, so a hidden address cannot be found by name.
	 */
	public function test_the_name_filter_uses_the_printed_name() {
		$report = jlife_challenges_reminder_recipients_report( $this->campaign_row(), 0, array( 'name' => 'private' ) );
		$ids    = array();
		foreach ( $report['sections'] as $section ) {
			$ids = array_merge( $ids, array_map( 'intval', wp_list_pluck( $section['rows'], 'user_id' ) ) );
		}
		$this->assertContains( $this->u['a'], $ids );
		$this->assertNotContains( $this->extra['e'], $ids, 'e shows as "(name hidden)", so "private" does not find it' );
	}

	/**
	 * Looking, searching and paging write nothing and send nothing.
	 */
	public function test_the_screen_is_read_only() {
		$before = $this->counts();
		$this->who_html( $this->u['organizer'] );
		$this->who_html( $this->u['organizer'], 'POST', $this->email_request( 'a-private@example.test' ) );
		$this->who_html( $this->u['organizer'], 'POST', $this->email_request( 'nobody@example.test' ) );
		$this->who_html( $this->u['organizer'], 'GET', array( 'name' => 'name' ) );
		$this->who_html( $this->u['organizer'], 'GET', array( 'page' => 3 ) );
		wp_set_current_user( $this->u['organizer'] );
		ob_start();
		jlife_challenges_reminders_admin_page();
		ob_end_clean();
		$this->assertSame( $before, $this->counts() );
		$this->assertSame( array(), $this->sent );
	}

	/**
	 * The section carries no reflection text, group name or score.
	 */
	public function test_no_reflection_group_or_score_is_shown() {
		$html = $this->who_html( $this->u['organizer'] );
		$this->assertStringNotContainsString( 'Group One', $html );
		$this->assertStringNotContainsString( 'Group Two', $html );
		$this->assertStringNotContainsString( 'score', strtolower( $html ) );
		$this->assertStringNotContainsString( 'reflection', strtolower( $html ) );
	}

	/**
	 * The email search form is a POST with a nonce; the name search is a GET.
	 */
	public function test_the_forms_are_a_nonced_post_and_a_get() {
		$html = $this->who_html( $this->u['organizer'] );
		$this->assertMatchesRegularExpression( '/<form method="post"[^>]*>.*name="jl_who_nonce".*name="jl_email"/s', $html );
		$this->assertMatchesRegularExpression( '/<form method="get"[^>]*>.*name="jl_name"/s', $html );
	}
}
