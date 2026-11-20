<?php
/**
 * Organizer daily view and CSV export (#64 part 2, docs/challenges-engine-design.md
 * §4h): who can open the page and download, the nonce and ID checks, each day
 * status with the ledger's point, per-group counts, the guarantee that no SOAP text,
 * share text, email address or token reaches either surface, and the CSV formula
 * guard. Written before the feature.
 *
 * @package jlife-challenges
 */

// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- tests read the custom tables directly.

/**
 * Daily view and CSV tests.
 */
class Test_Daily_View extends Jlife_Challenges_TestCase {

	/**
	 * The arguments of the last wp_die() call.
	 *
	 * @var array|null
	 */
	private $die_args = null;

	/**
	 * Shared text and private text used as markers that must never reach an admin surface.
	 *
	 * @var string[]
	 */
	private $markers = array( 'SHARED-A-MARKER', 'DRAFT-B-MARKER', 'LATE-D-MARKER', 'PRIVATE-C-MARKER', 'HIDDEN-MARKER', 'REMOVED-MARKER' );

	/**
	 * The day-1 picture: a on time and shared to Group One, b a draft, c nothing, d (solo) late,
	 * e a group starter with nothing yet. Then it is Day 3.
	 */
	public function set_up() {
		parent::set_up();
		add_filter( 'wp_die_handler', array( $this, 'die_handler_filter' ), 99 );
		foreach ( array( 'd', 'e' ) as $name ) {
			$this->u[ $name ] = self::factory()->user->create(
				array(
					'role'         => 'subscriber',
					'first_name'   => ucfirst( $name ) . 'name',
					'display_name' => ucfirst( $name ) . 'name',
				)
			);
		}
		$this->u['editor'] = self::factory()->user->create( array( 'role' => 'editor' ) );
		jlife_challenges_enroll_solo( $this->u['d'], $this->campaign, 'adult' );
		jlife_challenges_enroll_solo( $this->u['e'], $this->campaign, 'adult' );
		$this->assertIsArray( jlife_challenges_start_group( $this->u['e'], 'Started Group' ) );

		$this->assertIsArray( jlife_challenges_save_and_share( $this->u['a'], $this->campaign, 'd001', $this->soap( 'SHARED-A-MARKER' ), array( $this->g['g1'] ) ) );
		$this->assertIsInt( jlife_challenges_save_draft( $this->u['b'], $this->campaign, 'd001', $this->soap( 'DRAFT-B-MARKER' ) ) );
		$this->set_now( '2026-12-22 18:00:00' ); // Day 3: Day 1 has closed.
		$this->assertIsArray( jlife_challenges_submit( $this->u['d'], $this->campaign, 'd001', $this->soap( 'LATE-D-MARKER' ) ) );
	}

	/**
	 * Back to nobody.
	 */
	public function tear_down() {
		remove_filter( 'wp_die_handler', array( $this, 'die_handler_filter' ), 99 );
		$_GET  = array();
		$_POST = array();
		unset( $_SERVER['REQUEST_METHOD'] );
		wp_set_current_user( 0 );
		parent::tear_down();
	}

	/**
	 * Filter: use this test's wp_die handler.
	 *
	 * @return array
	 */
	public function die_handler_filter() {
		return array( $this, 'die_handler' );
	}

	/**
	 * Record how wp_die() was called, then stop like the suite's handler does.
	 *
	 * @param string       $message Message.
	 * @param string       $title   Title.
	 * @param array|string $args    Arguments.
	 * @throws WPDieException Always.
	 */
	public function die_handler( $message, $title = '', $args = array() ) {
		$this->die_args = (array) $args;
		throw new WPDieException( (string) $message ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- a test double.
	}

	/**
	 * The daily view for the organizer.
	 *
	 * @param string $day Day key ('' = default).
	 * @return array
	 */
	private function view( $day = 'd001' ) {
		$view = jlife_challenges_daily_view( $this->u['organizer'], $this->campaign, $day );
		$this->assertIsArray( $view );
		return $view;
	}

	/**
	 * Roster rows keyed by fixture user name.
	 *
	 * @param array $view Daily view.
	 * @return array<string,array>
	 */
	private function roster( array $view ) {
		$by_id = array_column( $view['rows'], null, 'user_id' );
		$out   = array();
		foreach ( $this->u as $name => $id ) {
			if ( isset( $by_id[ $id ] ) ) {
				$out[ $name ] = $by_id[ $id ];
			}
		}
		return $out;
	}

	/**
	 * Group count rows keyed by group name ('' = solo readers).
	 *
	 * @param array $view Daily view.
	 * @return array<string,array>
	 */
	private function group_counts( array $view ) {
		return array_column( $view['groups'], null, 'name' );
	}

	/**
	 * Prepare a CSV request as a fixture user, with a valid nonce unless overridden.
	 *
	 * @param string $who      Fixture user.
	 * @param int    $campaign Campaign.
	 * @param string $day      Day key or '' for the whole campaign.
	 * @param array  $override Field overrides (nonce).
	 * @param string $method   HTTP method.
	 * @return array { code, filename, body }
	 */
	private function csv( $who, $campaign, $day = '', array $override = array(), $method = 'POST' ) {
		wp_set_current_user( $this->u[ $who ] );
		return jlife_challenges_csv_prepare(
			$method,
			$override + array(
				'nonce'       => wp_create_nonce( jlife_challenges_csv_nonce_action( $campaign, $day ) ),
				'campaign_id' => $campaign,
				'day'         => $day,
			)
		);
	}

	/**
	 * A downloaded CSV as rows keyed by its header.
	 *
	 * @param string $body File body.
	 * @return array[]
	 */
	private function parse_csv( $body ) {
		$this->assertStringStartsWith( "\xEF\xBB\xBF", $body, 'UTF-8 byte-order mark for Excel' );
		$h = fopen( 'php://memory', 'r+' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- an in-memory test stream.
		fwrite( $h, substr( $body, 3 ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- an in-memory test stream.
		rewind( $h );
		$header = fgetcsv( $h, 0, ',', '"', '\\' );
		$rows   = array();
		while ( true ) {
			$line = fgetcsv( $h, 0, ',', '"', '\\' );
			if ( false === $line ) {
				break;
			}
			$rows[] = array_combine( $header, $line );
		}
		fclose( $h ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- an in-memory test stream.
		return $rows;
	}

	/**
	 * Every admin surface for this campaign as one string: both pages' days and each CSV.
	 *
	 * @return string
	 */
	private function everything() {
		$all = '';
		foreach ( array( 'd001', 'd002', 'd003' ) as $day ) {
			$all .= jlife_challenges_daily_admin_html( $this->view( $day ) );
			$all .= $this->csv( 'organizer', $this->campaign, $day )['body'];
		}
		$all .= $this->csv( 'organizer', $this->campaign, '' )['body'];
		return $all;
	}

	/**
	 * Who may open the page and download: organizers and site admins, nobody else.
	 */
	public function test_only_organizers_see_the_menu_the_page_and_the_download() {
		global $menu, $submenu;
		foreach ( array( 'a', 'e', 'editor', 'outsider' ) as $who ) {
			$menu    = array(); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- a clean admin menu for the test.
			$submenu = array(); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- a clean admin menu for the test.
			wp_set_current_user( $this->u[ $who ] );
			jlife_challenges_reminders_admin_menu();
			jlife_challenges_daily_admin_menu();
			$this->assertSame( array(), (array) ( $submenu[ JLIFE_CHALLENGES_REMINDERS_PAGE ] ?? array() ), "{$who}: no J-Life submenu" );
			$this->assertFalse( user_can( $this->u[ $who ], 'manage_options' ) );
			$this->die_args = null;
			try {
				jlife_challenges_daily_admin_page();
				$this->fail( "{$who} saw the page" );
			} catch ( WPDieException $e ) {
				$this->assertSame( 403, $this->die_args['response'] ?? 0, "{$who}: page 403" );
			}
			$this->assertSame( 'forbidden', $this->csv( $who, $this->campaign )['code'], "{$who}: no download" );
			$this->die_args            = null;
			$_SERVER['REQUEST_METHOD'] = 'POST';
			$_POST                     = array(
				'jl_campaign_id' => (string) $this->campaign,
				'jl_day'         => '',
				'_wpnonce'       => wp_create_nonce( jlife_challenges_csv_nonce_action( $this->campaign, '' ) ),
			);
			try {
				jlife_challenges_csv_download();
				$this->fail( "{$who} downloaded" );
			} catch ( WPDieException $e ) {
				$this->assertSame( 403, $this->die_args['response'] ?? 0, "{$who}: download 403" );
			}
		}
		wp_set_current_user( 0 );
		$this->assertSame( 'forbidden', jlife_challenges_csv_prepare( 'POST', array( 'campaign_id' => $this->campaign ) )['code'] );
		$this->die_args = null;
		try {
			jlife_challenges_daily_admin_page();
			$this->fail( 'anonymous saw the page' );
		} catch ( WPDieException $e ) {
			$this->assertSame( 403, $this->die_args['response'] ?? 0 );
		}

		$menu    = array(); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- a clean admin menu for the test.
		$submenu = array(); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- a clean admin menu for the test.
		wp_set_current_user( $this->u['organizer'] );
		jlife_challenges_reminders_admin_menu();
		jlife_challenges_daily_admin_menu();
		$entries = array_values(
			array_filter(
				(array) $submenu[ JLIFE_CHALLENGES_REMINDERS_PAGE ],
				static function ( $item ) {
					return JLIFE_CHALLENGES_DAILY_PAGE === $item[2];
				}
			)
		);
		$this->assertCount( 1, $entries, 'one Daily view entry under J-Life' );
		$this->assertSame( 'manage_options', $entries[0][1] );
		$this->u['second_admin'] = self::factory()->user->create( array( 'role' => 'administrator' ) );
		foreach ( array( 'organizer', 'second_admin' ) as $who ) {
			wp_set_current_user( $this->u[ $who ] );
			ob_start();
			jlife_challenges_daily_admin_page();
			$this->assertStringContainsString( 'Daily view', (string) ob_get_clean(), "{$who} sees the page" );
			$this->assertSame( 'ok', $this->csv( $who, $this->campaign )['code'], "{$who} downloads" );
		}
	}

	/**
	 * The data functions deny a non-organizer on their own, not only the page.
	 */
	public function test_the_functions_deny_non_organizers_and_unknown_campaigns_and_days() {
		foreach ( array( 'a', 'e', 'editor', 'outsider' ) as $who ) {
			$this->assertWPError( jlife_challenges_daily_view( $this->u[ $who ], $this->campaign, 'd001' ), $who );
			$this->assertWPError( jlife_challenges_csv_rows( $this->u[ $who ], $this->campaign, '' ), $who );
		}
		$this->assertWPError( jlife_challenges_daily_view( 0, $this->campaign, 'd001' ) );
		$org = $this->u['organizer'];
		$this->assertWPError( jlife_challenges_daily_view( $org, 999999, 'd001' ) );
		$this->assertWPError( jlife_challenges_csv_rows( $org, 999999, '' ) );
		foreach ( array( 'd999', 'd000', 'x', '../d001', 'D001', '2026-12-20' ) as $bad ) {
			$this->assertSame( 'jlife_denied', jlife_challenges_daily_view( $org, $this->campaign, $bad )->get_error_code(), $bad );
			$this->assertWPError( jlife_challenges_csv_rows( $org, $this->campaign, $bad ), $bad );
		}
	}

	/**
	 * Each status shows, with the ledger's point.
	 */
	public function test_each_status_shows_with_the_ledgers_point() {
		$r = $this->roster( $this->view( 'd001' ) );
		$this->assertSame( 'done_on_time', $r['a']['status'] );
		$this->assertSame( 1, (int) $r['a']['point'] );
		$this->assertSame( 'draft', $r['b']['status'] );
		$this->assertSame( 0, (int) $r['b']['point'] );
		$this->assertSame( 'none', $r['c']['status'] );
		$this->assertSame( 0, (int) $r['c']['point'] );
		$this->assertSame( 'done_late', $r['d']['status'] );
		$this->assertSame( 0, (int) $r['d']['point'] );
		$this->assertSame( 'none', $r['e']['status'] );
		$this->assertArrayNotHasKey( 'outsider', $r, 'someone who never enrolled is not a participant' );
		$view = $this->view( 'd001' );
		$this->assertSame(
			array(
				'participants'  => 5,
				'done_on_time'  => 1,
				'done_late'     => 1,
				'draft'         => 1,
				'none'          => 2,
				'point_removed' => 0,
				'points'        => 1,
			),
			$view['totals']
		);
		$this->assertSame( 'd001', $view['day']['key'] );
		$this->assertSame( 1, $view['day']['index'] );
		$this->assertSame( '2026-12-20', $view['day']['date'] );
		// Another day has nobody done.
		$this->assertSame( 5, $this->view( 'd002' )['totals']['none'] );
	}

	/**
	 * A point removed on review is its own status, never "done on time".
	 */
	public function test_a_point_removed_on_review_is_its_own_status() {
		global $wpdb;
		$wpdb->update( jlife_challenges_table( 'score_ledger' ), array( 'status' => 'removed_by_review', 'points' => 0 ), array( 'user_id' => $this->u['a'] ) ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound -- two simple keys.
		$r = $this->roster( $this->view( 'd001' ) );
		$this->assertSame( 'point_removed', $r['a']['status'] );
		$this->assertSame( 0, (int) $r['a']['point'] );
		$this->assertSame( 1, $this->view( 'd001' )['totals']['point_removed'] );
		$all = $this->parse_csv( $this->csv( 'organizer', $this->campaign )['body'] );
		$this->assertSame( 'point_removed', array_column( $all, 'status', 'participant_id' )[ (string) $this->u['a'] ] );
	}

	/**
	 * Per group: members, SOAPs submitted and shares; solo readers in their own row; counts only.
	 */
	public function test_group_counts_members_submitted_and_shares() {
		$counts = $this->group_counts( $this->view( 'd001' ) );
		$this->assertSame( 2, (int) $counts['Group One']['members'] );
		$this->assertSame( 1, (int) $counts['Group One']['submitted'] );
		$this->assertSame( 1, (int) $counts['Group One']['shares'] );
		$this->assertSame( 1, (int) $counts['Group Two']['members'] );
		$this->assertSame( 0, (int) $counts['Group Two']['submitted'] );
		$this->assertSame( 0, (int) $counts['Group Two']['shares'] );
		$this->assertSame( 1, (int) $counts['Started Group']['members'] );
		$this->assertSame( 1, (int) $counts['']['members'], 'solo readers: d' );
		$this->assertSame( 1, (int) $counts['']['submitted'], 'd submitted, late' );
		$this->assertSame( 0, (int) $counts['']['shares'] );
		foreach ( $counts as $row ) {
			$this->assertSame( array( 'group_id', 'name', 'status', 'members', 'submitted', 'shares' ), array_keys( $row ) );
		}
		// A person in two groups is counted in both.
		jlife_challenges_admin_add_member( $this->u['organizer'], $this->u['a'], $this->g['g2'] );
		$counts = $this->group_counts( $this->view( 'd001' ) );
		$this->assertSame( 2, (int) $counts['Group Two']['members'] );
		$this->assertSame( 1, (int) $counts['Group Two']['submitted'] );
		$this->assertSame( 1, (int) $counts['Group One']['submitted'] );
		$this->assertSame( 'Group One; Group Two', $this->roster( $this->view( 'd001' ) )['a']['groups'] );
		// Another day's shares are not mixed in.
		$this->assertSame( 0, (int) $this->group_counts( $this->view( 'd002' ) )['Group One']['shares'] );
	}

	/**
	 * Removed, unshared and hidden posts leave the share count; the points never move.
	 */
	public function test_shares_count_only_live_posts_and_a_hide_is_audited() {
		$g1 = (int) $this->g['g1'];
		$this->assertSame( 1, (int) $this->group_counts( $this->view( 'd001' ) )['Group One']['shares'] );
		$share = (int) jlife_challenges_my_shares( $this->u['a'], $this->campaign )[0]['id'];
		$this->assertTrue( jlife_challenges_hide_share( $this->u['organizer'], $share, 'HIDDEN-MARKER reason' ) );
		$this->assertSame( 0, (int) $this->group_counts( $this->view( 'd001' ) )['Group One']['shares'], 'hidden posts are not counted' );
		$hide = (array) $this->audits_of( 'share_hidden' );
		$this->assertCount( 1, $hide, 'D17: the hide is audited' );
		$this->assertSame( $this->u['organizer'], (int) $hide[0]['actor_user_id'] );
		$this->assertNotEmpty( get_user_meta( $this->u['a'], 'jlife_challenges_notices', true ), 'D17: the author is told' );
		$this->assertSame( 1, (int) $this->roster( $this->view( 'd001' ) )['a']['point'], 'hiding a share never changes a point' );
		// The hide is the organizer's only route to a share: no feed, and nothing prints the reason or text.
		$this->assertWPError( jlife_challenges_group_feed( $this->u['organizer'], $g1 ) );
		$this->assertStringNotContainsString( 'HIDDEN-MARKER', $this->everything() );
		$this->assertTrue( jlife_challenges_unshare( $this->u['a'], $share ) );
		$this->assertSame( 0, (int) $this->group_counts( $this->view( 'd001' ) )['Group One']['shares'] );
	}

	/**
	 * Audit rows for an action.
	 *
	 * @param string $action Action.
	 * @return array[]
	 */
	private function audits_of( $action ) {
		global $wpdb;
		return (array) $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . jlife_challenges_table( 'audit_log' ) . ' WHERE action = %s ORDER BY id', $action ), ARRAY_A );
	}

	/**
	 * Nothing private reaches the page or the CSV: no SOAP, draft or share text, no email, login,
	 * hash or nonce.
	 */
	public function test_no_text_email_or_token_reaches_the_page_or_the_csv() {
		$this->assertIsArray( jlife_challenges_save_and_share( $this->u['c'], $this->campaign, 'd001', $this->soap( 'PRIVATE-C-MARKER' ), array() ) );
		$share = (int) jlife_challenges_my_shares( $this->u['a'], $this->campaign )[0]['id'];
		jlife_challenges_hide_share( $this->u['organizer'], $share, 'HIDDEN-MARKER' );
		$this->set_now( '2026-12-21 18:00:00' );
		$this->assertIsArray( jlife_challenges_save_and_share( $this->u['b'], $this->campaign, 'd002', $this->soap( 'REMOVED-MARKER' ), array( $this->g['g1'] ) ) );
		$removed = (int) jlife_challenges_my_shares( $this->u['b'], $this->campaign )[0]['id'];
		jlife_challenges_unshare( $this->u['b'], $removed );
		$this->set_now( '2026-12-22 18:00:00' );

		$all = $this->everything();
		foreach ( $this->markers as $marker ) {
			$this->assertStringNotContainsString( $marker, $all, $marker );
		}
		foreach ( array( 'Scripture', 'Observation', 'Application', 'Prayer', 'John 1:14' ) as $word ) {
			$this->assertStringNotContainsString( $word, $all, $word );
		}
		foreach ( array( 'a', 'b', 'c', 'd', 'e', 'organizer' ) as $who ) {
			$user = get_userdata( $this->u[ $who ] );
			$this->assertStringNotContainsString( $user->user_email, $all, "{$who}'s email" );
			$this->assertStringNotContainsString( $user->user_login, $all, "{$who}'s login" );
		}
		$this->assertDoesNotMatchRegularExpression( '/[A-Za-z0-9._%+-]+@[A-Za-z0-9.-]+\.[a-z]{2,}/', preg_replace( '/admin@example\.org/', '', $all ), 'no email-shaped text' );
		$this->assertDoesNotMatchRegularExpression( '/[a-f0-9]{64}/', $all, 'no hash' );
		// The group's invite hash and code are not on the page.
		foreach ( jlife_challenges_list_groups( $this->u['organizer'], $this->campaign ) as $g ) {
			$hash = (string) jlife_challenges_get_group( (int) $g['id'] )['invite_hash'];
			$this->assertStringNotContainsString( $hash, $all );
		}
		// The queries never select a body column, or everything.
		$source = (string) file_get_contents( dirname( __DIR__ ) . '/includes/daily-view.php' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- reading the plugin's own source.
		$this->assertDoesNotMatchRegularExpression( '/SELECT\s+(\*|[^\'"]*\bbody\b)/', $source, 'daily-view.php never selects a body column' );
		$this->assertMatchesRegularExpression( '/SELECT\s+/', $source, 'the check can see the queries' );
	}

	/**
	 * The CSV header, one row per participant and day, individual points, status words.
	 */
	public function test_the_csv_has_the_columns_individual_points_and_status() {
		$out = $this->csv( 'organizer', $this->campaign, '' );
		$this->assertSame( 'ok', $out['code'] );
		$rows = $this->parse_csv( $out['body'] );
		$this->assertSame( array( 'participant_id', 'display_name', 'groups', 'day', 'point', 'status' ), array_keys( $rows[0] ) );
		$by = array_column( $rows, null, 'participant_id' );
		$this->assertCount( 2, $rows, 'whole campaign: one row per participant and day with a result' );
		$a = $by[ (string) $this->u['a'] ];
		$this->assertSame( 'Aname Private', $a['display_name'] );
		$this->assertSame( 'Group One', $a['groups'] );
		$this->assertSame( 'd001', $a['day'] );
		$this->assertSame( '1', $a['point'] );
		$this->assertSame( 'done_on_time', $a['status'] );
		$d = $by[ (string) $this->u['d'] ];
		$this->assertSame( '', $d['groups'], 'a solo reader has no group' );
		$this->assertSame( '0', $d['point'] );
		$this->assertSame( 'done_late', $d['status'] );
		$this->assertStringEndsWith( '.csv', $out['filename'] );
		$this->assertMatchesRegularExpression( '/^jlife-[a-z0-9-]+-all-days-\d{4}-\d{2}-\d{2}\.csv$/', $out['filename'] );

		$day = $this->csv( 'organizer', $this->campaign, 'd001' );
		$one = $this->parse_csv( $day['body'] );
		$this->assertCount( 5, $one, 'one day: every participant, drafts and none included' );
		$statuses = array_column( $one, 'status', 'participant_id' );
		$this->assertSame( 'draft', $statuses[ (string) $this->u['b'] ] );
		$this->assertSame( 'none', $statuses[ (string) $this->u['c'] ] );
		$this->assertMatchesRegularExpression( '/-d001-\d{4}/', $day['filename'] );
		$this->assertSame( array( '1' ), array_values( array_filter( array_column( $one, 'point' ), static fn( $point ) => '0' !== $point ) ), 'exactly one person has a point' );
	}

	/**
	 * A cell that could run as a formula in a spreadsheet gets a leading apostrophe; points stay numbers.
	 */
	public function test_the_formula_guard_covers_every_dangerous_first_character() {
		foreach ( array( '=1+1', '+1', '-1', '@SUM(A1)', "\t=1", "\r=1", '=HYPERLINK("http://x.test","y")', '-Ann' ) as $bad ) {
			$this->assertSame( "'" . $bad, jlife_challenges_csv_cell( $bad ), wp_json_encode( $bad ) );
		}
		foreach ( array( 'Ann', 'A=B', 'a+b', "'quoted", 'Nguyễn Văn', '' ) as $ok ) {
			$this->assertSame( $ok, jlife_challenges_csv_cell( $ok ), $ok );
		}
		$this->assertSame( '1', jlife_challenges_csv_cell( 1 ), 'a point is a plain number' );
		$this->assertSame( '0', jlife_challenges_csv_cell( 0 ) );

		// Through the real export: display names and group names that start with a dangerous character.
		$names = array(
			'f' => '=2+2',
			'g' => '+cmd',
			'h' => '-x',
		);
		foreach ( $names as $key => $name ) {
			$this->u[ $key ] = self::factory()->user->create(
				array(
					'role'         => 'subscriber',
					'display_name' => $name,
				)
			);
			jlife_challenges_enroll_solo( $this->u[ $key ], $this->campaign, 'adult' );
			$this->assertIsArray( jlife_challenges_submit( $this->u[ $key ], $this->campaign, 'd001', $this->soap( 'x' ) ), $name );
		}
		$evil = jlife_challenges_create_group( $this->u['organizer'], $this->campaign, '=HYPERLINK("http://x.test")' );
		$at   = jlife_challenges_create_group( $this->u['organizer'], $this->campaign, '@SUM(A1)' );
		$this->assertIsArray( $evil );
		$this->assertIsArray( $at );
		$this->assertIsInt( jlife_challenges_admin_add_member( $this->u['organizer'], $this->u['f'], (int) $evil['id'] ) );
		$this->assertIsInt( jlife_challenges_admin_add_member( $this->u['organizer'], $this->u['g'], (int) $at['id'] ) );
		// A display name that starts with @ looks like an address, so it is not printed at all.
		$this->u['i'] = self::factory()->user->create(
			array(
				'role'         => 'subscriber',
				'display_name' => '@SUM(A1)',
			)
		);
		jlife_challenges_enroll_solo( $this->u['i'], $this->campaign, 'adult' );
		$this->assertIsArray( jlife_challenges_submit( $this->u['i'], $this->campaign, 'd001', $this->soap( 'x' ) ) );
		foreach ( array( '', 'd001' ) as $day ) {
			$rows = $this->parse_csv( $this->csv( 'organizer', $this->campaign, $day )['body'] );
			$by   = array_column( $rows, null, 'participant_id' );
			foreach ( $names as $key => $name ) {
				$this->assertSame( "'" . $name, $by[ (string) $this->u[ $key ] ]['display_name'], "display name {$name} ({$day})" );
			}
			$this->assertStringStartsWith( "'=HYPERLINK", $by[ (string) $this->u['f'] ]['groups'], 'a group name that starts with =' );
			$this->assertSame( "'@SUM(A1)", $by[ (string) $this->u['g'] ]['groups'], 'a group name that starts with @' );
			$this->assertSame( 'Participant #' . $this->u['i'], $by[ (string) $this->u['i'] ]['display_name'] );
			foreach ( $rows as $row ) {
				foreach ( array( 'display_name', 'groups' ) as $column ) {
					$this->assertDoesNotMatchRegularExpression( '/^[=+\-@\t\r]/', $row[ $column ], "{$column} is guarded: " . $row[ $column ] );
				}
			}
			$this->assertSame( 'Aname Private', $by[ (string) $this->u['a'] ]['display_name'], 'a safe name is unchanged' );
		}
	}

	/**
	 * The download needs the capability, a POST, and a nonce for that campaign and day;
	 * an unknown campaign or day sends nothing and writes no audit row.
	 */
	public function test_the_download_needs_a_post_a_nonce_and_valid_ids() {
		$before = count( $this->audits_of( 'scores_exported' ) );
		$this->assertSame( 'bad_nonce', $this->csv( 'organizer', $this->campaign, '', array( 'nonce' => '' ) )['code'] );
		$this->assertSame( 'bad_nonce', $this->csv( 'organizer', $this->campaign, '', array( 'nonce' => 'nope123456' ) )['code'] );
		$this->assertSame( 'bad_nonce', $this->csv( 'organizer', $this->campaign, '', array( 'nonce' => wp_create_nonce( 'some_other_action' ) ) )['code'] );
		wp_set_current_user( $this->u['organizer'] );
		$this->assertSame( 'bad_nonce', $this->csv( 'organizer', $this->campaign, '', array( 'nonce' => wp_create_nonce( jlife_challenges_csv_nonce_action( $this->campaign, 'd001' ) ) ) )['code'], "another day's nonce" );
		$this->assertSame( 'bad_nonce', $this->csv( 'organizer', $this->campaign, 'd001', array( 'nonce' => wp_create_nonce( jlife_challenges_csv_nonce_action( $this->campaign, '' ) ) ) )['code'], 'the whole-campaign nonce' );
		$this->assertSame( 'bad_nonce', $this->csv( 'organizer', $this->campaign, '', array( 'nonce' => wp_create_nonce( jlife_challenges_csv_nonce_action( $this->campaign + 1, '' ) ) ) )['code'], "another campaign's nonce" );
		$this->assertSame( 'bad_method', $this->csv( 'organizer', $this->campaign, '', array(), 'GET' )['code'] );
		// Unknown IDs, even with a nonce made for them: not found, nothing sent.
		foreach ( array( array( 999999, '' ), array( $this->campaign, 'd999' ), array( $this->campaign, '../d001' ), array( $this->campaign, 'x' ) ) as $c ) {
			$r = $this->csv( 'organizer', $c[0], $c[1] );
			$this->assertSame( 'not_found', $r['code'], wp_json_encode( $c ) );
			$this->assertSame( '', $r['body'] );
		}
		$this->assertSame( $before, count( $this->audits_of( 'scores_exported' ) ), 'nothing was exported, so nothing was audited' );
		$ok = $this->csv( 'organizer', $this->campaign, 'd001' );
		$this->assertSame( 'ok', $ok['code'] );
		$this->assertSame( $before + 1, count( $this->audits_of( 'scores_exported' ) ) );
	}

	/**
	 * The response headers: a CSV attachment, never cached, never sniffed.
	 */
	public function test_the_download_headers() {
		$headers = implode( "\n", jlife_challenges_csv_headers( 'jlife-test-all-days-2026-12-22.csv' ) );
		$this->assertStringContainsString( 'Content-Type: text/csv; charset=utf-8', $headers );
		$this->assertStringContainsString( 'Content-Disposition: attachment; filename="jlife-test-all-days-2026-12-22.csv"', $headers );
		$this->assertStringContainsString( 'no-store', $headers );
		$this->assertStringContainsString( 'X-Content-Type-Options: nosniff', $headers );
	}

	/**
	 * Every export is audited with counts only: the organizer, the row count and the day; no names.
	 */
	public function test_an_export_is_audited_with_counts_only() {
		$this->csv( 'organizer', $this->campaign, '' );
		$this->csv( 'organizer', $this->campaign, 'd001' );
		$rows = $this->audits_of( 'scores_exported' );
		$this->assertCount( 2, $rows );
		foreach ( $rows as $row ) {
			$this->assertSame( $this->u['organizer'], (int) $row['actor_user_id'] );
			$this->assertSame( $this->campaign, (int) $row['campaign_id'] );
		}
		$this->assertSame( 2, json_decode( $rows[0]['detail'], true )['rows'] );
		$this->assertSame(
			array(
				'rows' => 5,
				'day'  => 'd001',
			),
			json_decode( $rows[1]['detail'], true )
		);
		$detail = implode( ' ', array_column( $rows, 'detail' ) );
		foreach ( array( 'Aname', 'Bname', 'Group One', '@' ) as $secret ) {
			$this->assertStringNotContainsString( $secret, $detail );
		}
	}

	/**
	 * Viewing and exporting are read-only: no ledger, enrollment, membership or share changes.
	 */
	public function test_viewing_and_exporting_change_nothing_but_the_audit_log() {
		global $wpdb;
		$tables = array( 'score_ledger', 'enrollments', 'group_members', 'group_shares', 'soap_entries', 'groups' );
		$grab   = static function () use ( $wpdb, $tables ) {
			$out = array();
			foreach ( $tables as $t ) {
				$out[ $t ] = $wpdb->get_results( 'SELECT * FROM ' . jlife_challenges_table( $t ) . ' ORDER BY id', ARRAY_A );
			}
			return $out;
		};
		$before = $grab();
		$this->everything();
		$this->assertSame( $before, $grab() );
	}

	/**
	 * The page: day picker, group table, roster, totals and the two download forms with nonces.
	 */
	public function test_the_page_shows_the_day_picker_counts_roster_and_download_forms() {
		wp_set_current_user( $this->u['organizer'] );
		$_GET = array(
			'campaign' => (string) $this->campaign,
			'day'      => 'd001',
		);
		ob_start();
		jlife_challenges_daily_admin_page();
		$html = (string) ob_get_clean();
		$this->assertStringContainsString( 'Daily view', $html );
		$this->assertStringContainsString( '<option value="d001" selected', $html );
		$this->assertStringContainsString( 'value="d104"', $html, 'every campaign day can be picked' );
		$this->assertStringNotContainsString( 'value="d105"', $html );
		$this->assertMatchesRegularExpression( '#Group One.*?<td[^>]*>2</td><td[^>]*>1</td><td[^>]*>1</td>#s', $html, 'Group One: 2 members, 1 submitted, 1 shared' );
		$this->assertStringContainsString( 'Done on time', $html );
		$this->assertStringContainsString( 'Done late', $html );
		$this->assertStringContainsString( 'Draft', $html );
		$this->assertStringContainsString( 'Aname Private', $html );
		$this->assertMatchesRegularExpression( '#<tr><td>Aname Private</td><td>Group One</td><td>Done on time</td><td>1</td></tr>#', $html );
		$this->assertStringContainsString( 'action="' . admin_url( 'admin-post.php' ) . '"', $html );
		$this->assertStringContainsString( 'name="action" value="' . JLIFE_CHALLENGES_CSV_ACTION . '"', $html );
		$this->assertSame( 2, substr_count( $html, 'name="_wpnonce"' ), 'one form for the day, one for all days' );
		$this->assertStringContainsString( 'name="jl_day" value="d001"', $html );
		$this->assertStringContainsString( 'name="jl_day" value=""', $html );
		// The forms' nonces are the ones the download accepts.
		preg_match_all( '#name="_wpnonce" value="([a-f0-9]+)"#', $html, $m );
		$this->assertTrue( (bool) wp_verify_nonce( $m[1][0], jlife_challenges_csv_nonce_action( $this->campaign, 'd001' ) ) );
		$this->assertTrue( (bool) wp_verify_nonce( $m[1][1], jlife_challenges_csv_nonce_action( $this->campaign, '' ) ) );
		// A bad day in the address falls back to a sensible one rather than failing.
		$_GET = array( 'day' => 'd999' );
		ob_start();
		jlife_challenges_daily_admin_page();
		$this->assertStringContainsString( 'Daily view', (string) ob_get_clean() );
	}

	/**
	 * Acceptance (#64): two groups, individual points in the CSV, no reflections.
	 */
	public function test_acceptance_the_csv_has_individual_points_and_no_reflections() {
		$alpha = jlife_challenges_create_group( $this->u['organizer'], $this->campaign, 'Test Alpha' );
		$beta  = jlife_challenges_create_group( $this->u['organizer'], $this->campaign, 'Test Beta' );
		$ids   = array();
		foreach ( array( 'p1', 'p2', 'p3', 'p4' ) as $n ) {
			$ids[ $n ]     = self::factory()->user->create(
				array(
					'role'         => 'subscriber',
					'display_name' => ucfirst( $n ),
				)
			);
			$this->u[ $n ] = $ids[ $n ];
		}
		$this->assertIsInt( jlife_challenges_join_with_code( $ids['p1'], $alpha['invite_code'], 'adult' ) );
		$this->assertIsInt( jlife_challenges_join_with_code( $ids['p2'], $alpha['invite_code'], 'adult' ) );
		$this->assertIsInt( jlife_challenges_join_with_code( $ids['p3'], $beta['invite_code'], 'adult' ) );
		jlife_challenges_enroll( $this->u['organizer'], $ids['p4'], (int) $beta['id'] );
		$this->set_now( '2026-12-20 18:00:00' ); // Day 1, on time.
		foreach ( array( 'p1', 'p3' ) as $n ) {
			$this->assertIsArray( jlife_challenges_save_and_share( $ids[ $n ], $this->campaign, 'd001', $this->soap( "REFLECTION-{$n}" ), array( 'p1' === $n ? $alpha['id'] : $beta['id'] ) ) );
		}
		$this->assertIsArray( jlife_challenges_submit( $ids['p2'], $this->campaign, 'd001', $this->soap( 'REFLECTION-p2' ) ) );
		$this->assertIsInt( jlife_challenges_save_draft( $ids['p4'], $this->campaign, 'd001', $this->soap( 'REFLECTION-p4' ) ) );
		jlife_challenges_move_member( $this->u['organizer'], $ids['p2'], (int) $beta['id'] );

		foreach ( array( '', 'd001' ) as $day ) {
			$out = $this->csv( 'organizer', $this->campaign, $day );
			$this->assertStringNotContainsString( 'REFLECTION', $out['body'] );
			$this->assertStringNotContainsString( 'Scripture', $out['body'] );
			$by = array_column( $this->parse_csv( $out['body'] ), null, 'participant_id' );
			foreach ( array( 'p1', 'p2', 'p3' ) as $n ) {
				$this->assertSame( '1', $by[ (string) $ids[ $n ] ]['point'], "{$n} has their own point ({$day})" );
				$this->assertSame( 'done_on_time', $by[ (string) $ids[ $n ] ]['status'] );
			}
			$this->assertSame( 'Test Alpha', $by[ (string) $ids['p1'] ]['groups'] );
			$this->assertSame( 'Test Beta', $by[ (string) $ids['p2'] ]['groups'], 'moved, and the CSV follows the membership' );
		}
		$one = array_column( $this->parse_csv( $this->csv( 'organizer', $this->campaign, 'd001' )['body'] ), null, 'participant_id' );
		$this->assertSame( 'Test Beta', $one[ (string) $ids['p4'] ]['groups'], 'enrolled by an organizer into Beta' );
		$this->assertSame( 'draft', $one[ (string) $ids['p4'] ]['status'] );
		$this->assertSame( '0', $one[ (string) $ids['p4'] ]['point'] );
	}
}
