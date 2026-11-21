<?php
/**
 * Groups admin screen (#64 part 1, docs/challenges-engine-design.md §4g):
 * who can open it and write, every write's nonce and audit row, invite expiry
 * and revoking, add / move / remove with the D18 rule in both directions, and
 * the guarantee that membership changes never touch scores or consent. Written
 * before the feature.
 *
 * @package jlife-challenges
 */

// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- tests read the custom tables directly.

/**
 * Groups admin tests.
 */
class Test_Groups_Admin extends Jlife_Challenges_TestCase {

	/**
	 * The arguments of the last wp_die() call.
	 *
	 * @var array|null
	 */
	private $die_args = null;

	/**
	 * More people: d and e (solo readers), a starter, an editor.
	 */
	public function set_up() {
		parent::set_up();
		add_filter( 'wp_die_handler', array( $this, 'die_handler_filter' ), 99 );
		foreach ( array( 'd', 'e' ) as $name ) {
			$this->u[ $name ] = self::factory()->user->create(
				array(
					'role'         => 'subscriber',
					'first_name'   => ucfirst( $name ) . 'name',
					'last_name'    => 'Private',
					'display_name' => ucfirst( $name ) . 'name',
				)
			);
		}
		$this->u['editor']  = self::factory()->user->create( array( 'role' => 'editor' ) );
		$this->u['starter'] = self::factory()->user->create(
			array(
				'role'         => 'subscriber',
				'display_name' => 'Starter',
			)
		);
		$this->assertIsInt( jlife_challenges_enroll_solo( $this->u['starter'], $this->campaign, 'adult' ) );
		$this->assertIsArray( jlife_challenges_start_group( $this->u['starter'], 'Started Group' ) );
		jlife_challenges_enroll_solo( $this->u['d'], $this->campaign, 'adult' );
		jlife_challenges_enroll_solo( $this->u['e'], $this->campaign, 'adult' );
	}

	/**
	 * Back to nobody, no request data, no hook.
	 */
	public function tear_down() {
		remove_filter( 'wp_die_handler', array( $this, 'die_handler_filter' ), 99 );
		$_GET  = array();
		$_POST = array();
		unset( $_SERVER['REQUEST_METHOD'] );
		jlife_challenges_groups_admin_pending( false );
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
	 * Apply an operation as a fixture user, with a valid nonce for it unless overridden.
	 *
	 * @param string $op     Operation.
	 * @param array  $fields Field values (group_id, user_id, to_group_id, name, days, campaign_id, nonce).
	 * @param string $who    Fixture user.
	 * @param string $method HTTP method.
	 * @return array { code, group_id, reveal }
	 */
	private function apply( $op, array $fields = array(), $who = 'organizer', $method = 'POST' ) {
		wp_set_current_user( $this->u[ $who ] );
		$group_id = (int) ( $fields['group_id'] ?? 0 );
		$fields  += array(
			'op'       => $op,
			'group_id' => $group_id,
			'nonce'    => wp_create_nonce( jlife_challenges_groups_admin_nonce_action( $op, $group_id ) ),
		);
		return jlife_challenges_groups_admin_apply( $method, $fields );
	}

	/**
	 * Request fields for apply(), leaving out what is not given. A user is a fixture key or an ID.
	 *
	 * @param int             $group    Group the form belongs to.
	 * @param string|int|null $user     Participant.
	 * @param int|null        $to_group Destination group (a move).
	 * @param string|null     $name     Group name.
	 * @param int|null        $days     Invite expiry in days.
	 * @param int|null        $campaign Campaign (a create).
	 * @return array
	 */
	private function fields( $group = 0, $user = null, $to_group = null, $name = null, $days = null, $campaign = null ) {
		$out = array( 'group_id' => (int) $group );
		if ( null !== $user ) {
			$out['user_id'] = is_string( $user ) ? $this->u[ $user ] : (int) $user;
		}
		foreach ( array(
			'to_group_id' => $to_group,
			'name'        => $name,
			'days'        => $days,
			'campaign_id' => $campaign,
		) as $key => $value ) {
			if ( null !== $value ) {
				$out[ $key ] = $value;
			}
		}
		return $out;
	}

	/**
	 * How many audit rows an action has.
	 *
	 * @param string $action Action.
	 * @return int
	 */
	private function audit_count( $action ) {
		return count( $this->audits( $action ) );
	}

	/**
	 * Every table a write could touch, as rows.
	 *
	 * @return array
	 */
	private function snapshot() {
		global $wpdb;
		$out = array();
		foreach ( array( 'groups', 'group_members', 'enrollments', 'score_ledger', 'group_shares', 'audit_log' ) as $table ) {
			$out[ $table ] = $wpdb->get_results( 'SELECT * FROM ' . jlife_challenges_table( $table ) . ' ORDER BY id', ARRAY_A );
		}
		return $out;
	}

	/**
	 * Audit rows for an action, oldest first.
	 *
	 * @param string $action Action.
	 * @return array[]
	 */
	private function audits( $action ) {
		global $wpdb;
		return (array) $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . jlife_challenges_table( 'audit_log' ) . ' WHERE action = %s ORDER BY id', $action ), ARRAY_A );
	}

	/**
	 * A user's active group IDs in this campaign.
	 *
	 * @param string $who Fixture user.
	 * @return int[]
	 */
	private function groups_of( $who ) {
		return array_map( 'intval', array_column( jlife_challenges_active_memberships( $this->campaign, $this->u[ $who ] ), 'group_id' ) );
	}

	/**
	 * Submit a day and post it to the given groups.
	 *
	 * @param string $who    Fixture user.
	 * @param int[]  $groups Groups to post to.
	 * @param string $tag    Marker in the text.
	 * @param string $day    Day key.
	 * @return array
	 */
	private function post( $who, array $groups, $tag, $day = 'd001' ) {
		$r = jlife_challenges_save_and_share( $this->u[ $who ], $this->campaign, $day, $this->soap( $tag ), $groups );
		$this->assertIsArray( $r );
		return $r;
	}

	/**
	 * A feed as one string, for "does this text appear" checks.
	 *
	 * @param string $who   Fixture user.
	 * @param int    $group Group.
	 * @return string
	 */
	private function feed_text( $who, $group ) {
		$feed = jlife_challenges_group_feed( $this->u[ $who ], $group );
		$this->assertIsArray( $feed );
		return (string) wp_json_encode( $feed );
	}

	/**
	 * The screen's HTML for an organizer.
	 *
	 * @param int    $campaign_id Campaign (0 = default).
	 * @param int    $group_id    Group (0 = none).
	 * @param string $result      Result code.
	 * @param array  $reveal      A code reveal, or empty.
	 * @return string
	 */
	private function page_html( $campaign_id = 0, $group_id = 0, $result = '', array $reveal = array() ) {
		$view = jlife_challenges_groups_admin_view( $this->u['organizer'], $campaign_id, $group_id );
		$this->assertIsArray( $view );
		return jlife_challenges_groups_admin_html( $view, $result, $reveal ? $reveal : null );
	}

	/**
	 * A second campaign with a group of its own.
	 *
	 * @return array { campaign, group }
	 */
	private function other_campaign() {
		$campaign = jlife_challenges_create_campaign(
			$this->u['organizer'],
			array(
				'campaign_key' => 'other-' . wp_generate_password( 6, false ),
				'plan_id'      => 'rpl-life-and-teachings-of-jesus-2026',
				'title'        => 'Other Campaign',
				'start_date'   => '2027-06-01',
				'end_date'     => '2027-09-01',
				'status'       => 'draft',
			)
		);
		$this->assertIsInt( $campaign );
		$group = jlife_challenges_create_group( $this->u['organizer'], $campaign, 'Other Group' );
		$this->assertIsArray( $group );
		return array(
			'campaign' => $campaign,
			'group'    => (int) $group['id'],
		);
	}

	/**
	 * Who may open the screen: organizers and site admins, nobody else.
	 */
	public function test_only_organizers_see_the_menu_and_the_page() {
		global $menu, $submenu;
		// Nobody but an organizer gets a Groups entry (add_submenu_page refuses it), and the J-Life menu needs the same capability.
		foreach ( array( 'a', 'starter', 'editor', 'outsider' ) as $who ) {
			$menu    = array(); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- a clean admin menu for the test.
			$submenu = array(); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- a clean admin menu for the test.
			wp_set_current_user( $this->u[ $who ] );
			jlife_challenges_reminders_admin_menu();
			jlife_challenges_groups_admin_menu();
			$this->assertSame( array(), (array) ( $submenu[ JLIFE_CHALLENGES_REMINDERS_PAGE ] ?? array() ), "{$who}: no J-Life submenu" );
			foreach ( $menu as $item ) { // The top entry exists but WordPress hides it from anyone without its capability.
				$this->assertSame( 'manage_options', $item[1], "{$who}: the J-Life menu needs manage_options" );
			}
		}
		$menu    = array(); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- a clean admin menu for the test.
		$submenu = array(); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- a clean admin menu for the test.
		wp_set_current_user( $this->u['organizer'] );
		jlife_challenges_reminders_admin_menu();
		jlife_challenges_groups_admin_menu();
		$entries = array_values(
			array_filter(
				(array) $submenu[ JLIFE_CHALLENGES_REMINDERS_PAGE ],
				static function ( $item ) {
					return JLIFE_CHALLENGES_GROUPS_PAGE === $item[2];
				}
			)
		);
		$this->assertCount( 1, $entries, 'one Groups entry under J-Life' );
		$this->assertSame( 'manage_options', $entries[0][1] );

		foreach ( array( 'a', 'starter', 'editor', 'outsider' ) as $who ) {
			$this->assertFalse( user_can( $this->u[ $who ], 'manage_options' ), "{$who} has no menu entry" );
			wp_set_current_user( $this->u[ $who ] );
			$this->die_args = null;
			try {
				jlife_challenges_groups_admin_page();
				$this->fail( "{$who} saw the page" );
			} catch ( WPDieException $e ) {
				$this->assertSame( 403, $this->die_args['response'] ?? 0, "{$who} gets a 403" );
			}
		}
		wp_set_current_user( 0 );
		$this->die_args = null;
		try {
			jlife_challenges_groups_admin_page();
			$this->fail( 'anonymous saw the page' );
		} catch ( WPDieException $e ) {
			$this->assertSame( 403, $this->die_args['response'] ?? 0 );
		}

		// A second administrator (a site admin) gets the same screen as the organizer.
		$this->u['second_admin'] = self::factory()->user->create( array( 'role' => 'administrator' ) );
		foreach ( array( 'organizer', 'second_admin' ) as $who ) {
			wp_set_current_user( $this->u[ $who ] );
			ob_start();
			jlife_challenges_groups_admin_page();
			$html = (string) ob_get_clean();
			$this->assertStringContainsString( 'Group One', $html, "{$who} sees the groups" );
		}
	}

	/**
	 * An organizer's scope is this site's campaigns, one at a time; a site admin gets the same all.
	 */
	public function test_the_screen_lists_every_campaign_and_each_campaigns_own_groups() {
		$other = $this->other_campaign();
		$view  = jlife_challenges_groups_admin_view( $this->u['organizer'], 0, 0 );
		$this->assertCount( 2, $view['campaigns'] );
		$this->assertSame( $this->campaign, (int) $view['campaign']['id'], 'the open campaign is the default' );
		$this->assertSame( array( 'Group One', 'Group Two', 'Started Group' ), array_column( $view['groups'], 'name' ) );
		$view = jlife_challenges_groups_admin_view( $this->u['organizer'], $other['campaign'], 0 );
		$this->assertSame( array( 'Other Group' ), array_column( $view['groups'], 'name' ) );
		$this->assertStringNotContainsString( 'Group One', $this->page_html( $other['campaign'] ) );
		$view = jlife_challenges_groups_admin_view( $this->u['organizer'], 999999, 0 );
		$this->assertSame( $this->campaign, (int) $view['campaign']['id'], 'an unknown campaign falls back to the default' );
		// A group that belongs to another campaign than the one in view is not opened.
		$view = jlife_challenges_groups_admin_view( $this->u['organizer'], $this->campaign, $other['group'] );
		$this->assertNull( $view['group'] );
	}

	/**
	 * The member counts, status and invite state of every group are listed.
	 */
	public function test_the_list_shows_member_counts_status_and_invite_state() {
		$view = jlife_challenges_groups_admin_view( $this->u['organizer'], $this->campaign, 0 );
		$by   = array_column( $view['groups'], null, 'name' );
		$this->assertSame( 2, (int) $by['Group One']['members'] );
		$this->assertSame( 1, (int) $by['Group Two']['members'] );
		$this->assertSame( 1, (int) $by['Started Group']['members'] );
		$this->assertSame( 'active', $by['Group One']['invite']['state'] );
		jlife_challenges_disable_group( $this->u['organizer'], $this->g['g2'] );
		$html = $this->page_html( $this->campaign );
		$this->assertMatchesRegularExpression( '#Group One.*?<td[^>]*>2</td>#s', $html );
		$this->assertStringContainsString( 'Disabled', $html );
	}

	/**
	 * Every write is refused for anyone without manage_options, and changes nothing.
	 */
	public function test_every_write_is_refused_for_non_organizers() {
		$before   = $this->snapshot();
		$g1       = (int) $this->g['g1'];
		$requests = array(
			array( 'create', $this->fields( 0, null, null, 'Sneaky', null, $this->campaign ) ),
			array( 'rename', $this->fields( $g1, null, null, 'Renamed' ) ),
			array( 'regenerate', $this->fields( $g1, null, null, null, 30 ) ),
			array( 'revoke', $this->fields( $g1 ) ),
			array( 'add', $this->fields( $g1, 'd' ) ),
			array( 'move', $this->fields( $g1, 'a', $this->g['g2'] ) ),
			array( 'remove', $this->fields( $g1, 'a' ) ),
		);
		foreach ( array( 'a', 'starter', 'editor', 'outsider' ) as $who ) {
			foreach ( $requests as $req ) {
				$r = $this->apply( $req[0], $req[1], $who );
				$this->assertSame( 'forbidden', $r['code'], "{$who} {$req[0]}" );
				$this->assertNull( $r['reveal'] );
			}
		}
		wp_set_current_user( 0 );
		$this->assertSame( 'forbidden', jlife_challenges_groups_admin_apply( 'POST', array( 'op' => 'revoke' ) )['code'] );
		$this->assertSame( $before, $this->snapshot(), 'nothing changed' );
	}

	/**
	 * A missing, wrong or borrowed nonce, a GET and an unknown operation all change nothing.
	 */
	public function test_nonces_are_required_bound_to_the_action_and_group() {
		$before = $this->snapshot();
		$g1     = (int) $this->g['g1'];
		$g2     = (int) $this->g['g2'];
		$cases  = array(
			array( 'rename', $this->fields( $g1, null, null, 'Nope' ) ),
			array( 'revoke', $this->fields( $g1 ) ),
			array( 'regenerate', $this->fields( $g1, null, null, null, 7 ) ),
			array( 'add', $this->fields( $g1, 'd' ) ),
			array( 'remove', $this->fields( $g1, 'a' ) ),
			array( 'move', $this->fields( $g1, 'a', $g2 ) ),
			array( 'create', $this->fields( 0, null, null, 'Nope', null, $this->campaign ) ),
		);
		foreach ( $cases as $c ) {
			list( $op, $base ) = $c;
			$group_id          = (int) $base['group_id'];
			$this->assertSame( 'bad_nonce', $this->apply( $op, $base + array( 'nonce' => '' ) )['code'], "{$op}: none" );
			$this->assertSame( 'bad_nonce', $this->apply( $op, $base + array( 'nonce' => 'nope123456' ) )['code'], "{$op}: junk" );
			$this->assertSame( 'bad_nonce', $this->apply( $op, $base + array( 'nonce' => wp_create_nonce( 'some_other_action' ) ) )['code'], "{$op}: other action" );
			$another_op = 'revoke' === $op ? 'rename' : 'revoke';
			$this->assertSame( 'bad_nonce', $this->apply( $op, $base + array( 'nonce' => wp_create_nonce( jlife_challenges_groups_admin_nonce_action( $another_op, $group_id ) ) ) )['code'], "{$op}: another operation's nonce" );
			$this->assertSame( 'bad_nonce', $this->apply( $op, $base + array( 'nonce' => wp_create_nonce( jlife_challenges_groups_admin_nonce_action( $op, $group_id + 1 ) ) ) )['code'], "{$op}: another group's nonce" );
			$this->assertSame( 'bad_method', $this->apply( $op, $base, 'organizer', 'GET' )['code'], "{$op}: GET" );
		}
		$this->assertSame( 'bad_op', $this->apply( 'delete_everything', $this->fields( $g1 ) )['code'] );
		$this->assertSame( $before, $this->snapshot(), 'nothing changed' );
	}

	/**
	 * The domain functions deny a non-organizer on their own, not only the screen.
	 */
	public function test_the_functions_deny_non_organizers_and_unknown_groups() {
		$g1 = (int) $this->g['g1'];
		foreach ( array( 'a', 'starter', 'editor' ) as $who ) {
			$u = $this->u[ $who ];
			$this->assertWPError( jlife_challenges_create_group( $u, $this->campaign, 'X' ), $who );
			$this->assertWPError( jlife_challenges_rename_group( $u, $g1, 'X' ), $who );
			$this->assertWPError( jlife_challenges_revoke_invite( $u, $g1 ), $who );
			$this->assertWPError( jlife_challenges_group_members( $u, $g1 ), $who );
			$this->assertWPError( jlife_challenges_group_add_candidates( $u, $g1 ), $who );
			$this->assertWPError( jlife_challenges_admin_add_member( $u, $this->u['d'], $g1 ), $who );
			$this->assertWPError( jlife_challenges_remove_from_group( $u, $this->u['a'], $g1 ), $who );
			$this->assertWPError( jlife_challenges_move_member( $u, $this->u['a'], $this->g['g2'], $g1 ), $who );
			$this->assertWPError( jlife_challenges_groups_admin_view( $u, $this->campaign, 0 ), $who );
		}
		$org = $this->u['organizer'];
		$this->assertWPError( jlife_challenges_revoke_invite( $org, 999999 ) );
		$this->assertWPError( jlife_challenges_group_members( $org, 999999 ) );
		$this->assertWPError( jlife_challenges_admin_add_member( $org, $this->u['d'], 999999 ) );
		$this->assertWPError( jlife_challenges_remove_from_group( $org, $this->u['a'], 999999 ) );
		$this->assertSame( 'jlife_denied', jlife_challenges_revoke_invite( $org, 999999 )->get_error_code(), 'same denial as forbidden' );
	}

	/**
	 * Create a group: the code is shown once and works; the name is cleaned and capped; audited.
	 */
	public function test_creating_a_group_names_it_shows_the_code_once_and_is_audited() {
		$made_before = $this->audit_count( 'group_created' );
		$r           = $this->apply( 'create', $this->fields( 0, null, null, '  <b>Alpha</b> Team ', 30, $this->campaign ) );
		$this->assertSame( 'created', $r['code'] );
		$group = jlife_challenges_get_group( $r['group_id'] );
		$this->assertSame( 'Alpha Team', $group['name'] );
		$this->assertSame( 'active', $group['status'] );
		$this->assertSame( 0, (int) $group['started_by'], 'an organizer-made group' );
		$this->assertMatchesRegularExpression( '/^[A-Z2-9]{8}$/', $r['reveal']['code'] );
		$this->assertSame( 'Alpha Team', $r['reveal']['name'] );
		$this->assertEqualsWithDelta( time() + 30 * DAY_IN_SECONDS, strtotime( $group['invite_expires'] . ' UTC' ), 60 );
		$this->assertSame( (int) $group['id'], (int) jlife_challenges_group_for_invite( $r['reveal']['code'] )['id'] );
		$this->assertSame( $made_before + 1, $this->audit_count( 'group_created' ) );

		foreach ( array( '', '   ', '<i></i>', str_repeat( 'x', 61 ) ) as $bad ) {
			$this->assertSame( 'name_invalid', $this->apply( 'create', $this->fields( 0, null, null, $bad, null, $this->campaign ) )['code'], 'name: ' . $bad );
		}
		$this->assertSame( 'not_allowed', $this->apply( 'create', $this->fields( 0, null, null, 'Lost', null, 999999 ) )['code'] );
		$this->assertCount( 4, jlife_challenges_list_groups( $this->u['organizer'], $this->campaign ), 'only the one new group was made' );
	}

	/**
	 * Rename keeps everything else and is audited.
	 */
	public function test_renaming_a_group_is_audited_and_keeps_members_and_code() {
		$g1   = (int) $this->g['g1'];
		$code = jlife_challenges_reset_invite( $this->u['organizer'], $g1 );
		$r    = $this->apply( 'rename', $this->fields( $g1, null, null, 'Friday Group' ) );
		$this->assertSame( 'renamed', $r['code'] );
		$this->assertSame( 'Friday Group', jlife_challenges_get_group( $g1 )['name'] );
		$this->assertSame( array( $g1 ), $this->groups_of( 'a' ) );
		$this->assertSame( $g1, (int) jlife_challenges_group_for_invite( $code )['id'] );
		$audit = $this->audits( 'group_renamed' );
		$this->assertCount( 1, $audit );
		$this->assertSame( $this->u['organizer'], (int) $audit[0]['actor_user_id'] );
		$this->assertSame( 'name_invalid', $this->apply( 'rename', $this->fields( $g1, null, null, str_repeat( 'y', 61 ) ) )['code'] );
		$this->assertSame( 'Friday Group', jlife_challenges_get_group( $g1 )['name'] );
	}

	/**
	 * Regenerate: a chosen expiry, the old code stops at once, the new one joins, members stay.
	 */
	public function test_regenerating_an_invite_replaces_the_old_code_with_a_chosen_expiry() {
		$g1  = (int) $this->g['g1'];
		$old = jlife_challenges_reset_invite( $this->u['organizer'], $g1 );
		$r   = $this->apply( 'regenerate', $this->fields( $g1, null, null, null, 14 ) );
		$this->assertSame( 'invite_reset', $r['code'] );
		$new = $r['reveal']['code'];
		$this->assertNotSame( $old, $new );
		$this->assertNull( jlife_challenges_group_for_invite( $old ), 'the old code stops at once' );
		$this->assertSame( $g1, (int) jlife_challenges_group_for_invite( $new )['id'] );
		$state = jlife_challenges_invite_state( jlife_challenges_get_group( $g1 ) );
		$this->assertSame( 'active', $state['state'] );
		$this->assertEqualsWithDelta( time() + 14 * DAY_IN_SECONDS, strtotime( $state['expires'] . ' UTC' ), 60 );
		$this->assertSame( array( $g1 ), $this->groups_of( 'a' ), 'members untouched' );
		$audit = $this->audits( 'invite_reset' );
		$this->assertSame( 'organizer', json_decode( end( $audit )['detail'], true )['by'] );

		foreach ( array( 0, 3, 365, 10000, -5 ) as $days ) {
			$this->assertSame( 'bad_days', $this->apply( 'regenerate', $this->fields( $g1, null, null, null, $days ) )['code'], "days {$days}" );
		}
		$this->assertSame( $g1, (int) jlife_challenges_group_for_invite( $new )['id'], 'a refused request leaves the code alone' );
	}

	/**
	 * An expired code is shown as expired and does not join.
	 */
	public function test_an_expired_code_shows_as_expired() {
		global $wpdb;
		$g1   = (int) $this->g['g1'];
		$code = jlife_challenges_reset_invite( $this->u['organizer'], $g1 );
		$wpdb->update( jlife_challenges_table( 'groups' ), array( 'invite_expires' => gmdate( 'Y-m-d H:i:s', time() - HOUR_IN_SECONDS ) ), array( 'id' => $g1 ) );
		$this->assertSame( 'expired', jlife_challenges_invite_state( jlife_challenges_get_group( $g1 ) )['state'] );
		$this->assertNull( jlife_challenges_group_for_invite( $code ) );
		$this->assertStringContainsString( 'Expired', $this->page_html( $this->campaign, $g1 ) );
	}

	/**
	 * Revoke: no working code, members untouched, audited, idempotent.
	 */
	public function test_revoking_an_invite_leaves_no_working_code() {
		$g1   = (int) $this->g['g1'];
		$code = jlife_challenges_reset_invite( $this->u['organizer'], $g1 );
		$r    = $this->apply( 'revoke', $this->fields( $g1 ) );
		$this->assertSame( 'invite_revoked', $r['code'] );
		$this->assertNull( $r['reveal'] );
		$this->assertNull( jlife_challenges_group_for_invite( $code ) );
		$this->assertSame( 'none', jlife_challenges_invite_state( jlife_challenges_get_group( $g1 ) )['state'] );
		$this->assertSame( '', jlife_challenges_get_group( $g1 )['invite_hash'] );
		$joined = jlife_challenges_join_with_code( $this->u['d'], $code, 'adult' );
		$this->assertWPError( $joined );
		$this->assertSame( 'jlife_invite_invalid', $joined->get_error_code() );
		$this->assertSame( array( $g1 ), $this->groups_of( 'a' ), 'members untouched' );
		$this->assertCount( 1, $this->audits( 'invite_revoked' ) );
		$this->assertSame( 'invite_revoked', $this->apply( 'revoke', $this->fields( $g1 ) )['code'], 'revoking twice is fine' );
		$this->assertCount( 1, $this->audits( 'invite_revoked' ), 'and writes no second row' );
		$again = $this->apply( 'regenerate', $this->fields( $g1, null, null, null, 7 ) );
		$this->assertSame( $g1, (int) jlife_challenges_group_for_invite( $again['reveal']['code'] )['id'], 'a fresh code can be made afterwards' );
	}

	/**
	 * The page shows a new code once, never a hash, and nothing after a reload.
	 */
	public function test_the_code_is_shown_once_and_the_hash_never() {
		$g1   = (int) $this->g['g1'];
		$r    = $this->apply( 'regenerate', $this->fields( $g1, null, null, null, 30 ) );
		$code = $r['reveal']['code'];
		$with = $this->page_html( $this->campaign, $g1, $r['code'], $r['reveal'] );
		$this->assertStringContainsString( $code, $with );
		$after = $this->page_html( $this->campaign, $g1 );
		$this->assertStringNotContainsString( $code, $after, 'a reload does not show it again' );
		$hash = jlife_challenges_get_group( $g1 )['invite_hash'];
		$this->assertNotSame( '', $hash );
		$this->assertStringNotContainsString( $hash, $with . $after );
		$this->assertStringContainsString( 'Active until', $after );
	}

	/**
	 * Invite links carry no sign-in, session or user token: the only parameter is the code.
	 * (The daily reminder's links are pinned in test-reminder-sends.php.)
	 */
	public function test_invite_links_hold_only_the_code() {
		$r     = $this->apply( 'regenerate', $this->fields( $this->g['g1'], null, null, null, 30 ) );
		$code  = $r['reveal']['code'];
		$pages = array(
			'admin screen' => jlife_challenges_groups_admin_html( jlife_challenges_groups_admin_view( $this->u['organizer'], $this->campaign, (int) $this->g['g1'] ), 'invite_reset', $r['reveal'] ),
			'starter page' => jlife_challenges_invite_reveal_html( 'Name', $code ),
		);
		foreach ( $pages as $where => $text ) {
			preg_match_all( '#https?://[^\s"<>\']+#', html_entity_decode( $text ), $found );
			$joins = array_values(
				array_filter(
					$found[0],
					static function ( $url ) {
						return false !== strpos( $url, '/join/' );
					}
				)
			);
			$this->assertNotEmpty( $joins, "{$where}: has the invite link" );
			foreach ( $joins as $url ) {
				$parts = wp_parse_url( $url );
				parse_str( (string) ( $parts['query'] ?? '' ), $query );
				$this->assertSame( array( 'code' ), array_keys( $query ), "{$where}: only the code" );
				$this->assertSame( $code, $query['code'] );
				$this->assertSame( '/join/', $parts['path'] );
				$this->assertArrayNotHasKey( 'fragment', $parts );
				$this->assertDoesNotMatchRegularExpression( '#token|session|nonce|login|signin|cookie|auth|user#i', $url, "{$where}: no secret in the link" );
			}
		}
		$this->assertSame( home_url( '/join/?code=' . $code ), jlife_challenges_groups_admin_invite_link( $code ) );
	}

	/**
	 * Add a member: only a participant already enrolled in the group's campaign.
	 */
	public function test_adding_a_member_needs_an_existing_enrollment() {
		$g1 = (int) $this->g['g1'];
		$r  = $this->apply( 'add', $this->fields( $g1, 'd' ) );
		$this->assertSame( 'member_added', $r['code'] );
		$this->assertSame( array( $g1 ), $this->groups_of( 'd' ) );
		$audit = $this->audits( 'group_joined' );
		$last  = json_decode( end( $audit )['detail'], true );
		$this->assertSame( $g1, (int) $last['group_id'] );
		$this->assertSame( 'organizer', $last['source'] );

		$before = $this->snapshot();
		$this->assertSame( 'not_allowed', $this->apply( 'add', $this->fields( $g1, 'outsider' ) )['code'], 'not enrolled: the screen never enrolls' );
		$this->assertNull( jlife_challenges_get_enrollment( $this->campaign, $this->u['outsider'] ) );
		$this->assertSame( 'not_allowed', $this->apply( 'add', $this->fields( $g1, 999999 ) )['code'] );
		$this->assertSame( 'already', $this->apply( 'add', $this->fields( $g1, 'a' ) )['code'] );
		$this->assertSame( $before, $this->snapshot() );
	}

	/**
	 * A person who left the Marathon cannot be added; a fourth group and a disabled group are refused.
	 */
	public function test_adding_refuses_removed_people_a_fourth_group_and_a_disabled_group() {
		$three = (int) jlife_challenges_create_group( $this->u['organizer'], $this->campaign, 'Group Three' )['id'];
		$four  = (int) jlife_challenges_create_group( $this->u['organizer'], $this->campaign, 'Group Four' )['id'];
		jlife_challenges_remove_member( $this->u['organizer'], $this->campaign, $this->u['e'] );
		$this->assertSame( 'not_allowed', $this->apply( 'add', $this->fields( $this->g['g1'], 'e' ) )['code'], 'a removed enrollment is not enrolled' );

		foreach ( array( $this->g['g1'], $this->g['g2'], $three ) as $gid ) {
			$this->assertSame( 'member_added', $this->apply( 'add', $this->fields( $gid, 'd' ) )['code'] );
		}
		$this->assertSame( 'limit', $this->apply( 'add', $this->fields( $four, 'd' ) )['code'] );
		$this->assertCount( 3, $this->groups_of( 'd' ) );

		jlife_challenges_disable_group( $this->u['organizer'], $four );
		$this->assertSame( 'not_allowed', $this->apply( 'add', $this->fields( $four, 'starter' ) )['code'] );
	}

	/**
	 * The add list holds enrolled people who are not yet in the group, by display name, with no email.
	 */
	public function test_the_add_list_has_display_names_only() {
		$g1   = (int) $this->g['g1'];
		$view = jlife_challenges_groups_admin_view( $this->u['organizer'], $this->campaign, $g1 );
		$ids  = array_map( 'intval', array_column( $view['group']['candidates'], 'user_id' ) );
		sort( $ids );
		$want = array( $this->u['c'], $this->u['d'], $this->u['e'], $this->u['starter'] );
		sort( $want );
		$this->assertSame( $want, $ids, 'enrolled, not already in Group One' );
		$this->assertNotContains( $this->u['outsider'], $ids );
		$this->assertNotContains( $this->u['a'], $ids );
		$html = $this->page_html( $this->campaign, $g1 );
		foreach ( array( 'a', 'b', 'c', 'd', 'e', 'starter' ) as $who ) {
			$this->assertStringNotContainsString( get_userdata( $this->u[ $who ] )->user_email, $html, "{$who}'s email" );
			$this->assertStringNotContainsString( get_userdata( $this->u[ $who ] )->user_login, $html, "{$who}'s login" );
		}
		$this->assertStringContainsString( 'Dname', $html );
	}

	/**
	 * A display name that looks like an email address is never printed.
	 */
	public function test_a_display_name_that_is_an_email_address_is_replaced() {
		$u = self::factory()->user->create(
			array(
				'role'         => 'subscriber',
				'display_name' => 'someone@example.test',
			)
		);
		$this->assertSame( "Participant #{$u}", jlife_challenges_admin_person_name( $u ) );
		$this->assertSame( 'Dname', jlife_challenges_admin_person_name( $this->u['d'] ) );
		$this->assertSame( 'Participant #0', jlife_challenges_admin_person_name( 0 ) );
	}

	/**
	 * Remove from one group: that membership only; the enrollment, points and other groups stay.
	 */
	public function test_removing_from_a_group_ends_that_membership_only() {
		$g1 = (int) $this->g['g1'];
		$g2 = (int) $this->g['g2'];
		$this->assertSame( 'member_added', $this->apply( 'add', $this->fields( $g2, 'a' ) )['code'] );
		$this->assertIsArray( jlife_challenges_submit( $this->u['a'], $this->campaign, 'd001', $this->soap( 'pt' ) ) );
		$points = jlife_challenges_progress( $this->u['a'], $this->u['a'], $this->campaign )['points'];
		$this->assertSame( 1, $points );

		$this->assertSame( 'member_removed', $this->apply( 'remove', $this->fields( $g1, 'a' ) )['code'] );
		$this->assertSame( array( $g2 ), $this->groups_of( 'a' ) );
		$this->assertSame( 'removed', jlife_challenges_get_membership( $g1, $this->u['a'] )['status'] );
		$this->assertNotNull( jlife_challenges_active_enrollment( $this->campaign, $this->u['a'] ), 'still enrolled' );
		$this->assertSame( $points, jlife_challenges_progress( $this->u['a'], $this->u['a'], $this->campaign )['points'] );
		$audit = $this->audits( 'group_member_removed' );
		$this->assertCount( 1, $audit );
		$this->assertSame( array( 'group_id' => $g1 ), json_decode( $audit[0]['detail'], true ) );

		$this->assertSame( 'not_allowed', $this->apply( 'remove', $this->fields( $g1, 'a' ) )['code'], 'no longer a member' );
		$this->assertSame( 'not_allowed', $this->apply( 'remove', $this->fields( $g1, 'outsider' ) )['code'] );
		$this->assertCount( 1, $this->audits( 'group_member_removed' ) );
	}

	/**
	 * Move: the same campaign only, from the group in view; refused moves change nothing.
	 */
	public function test_moving_swaps_one_membership_and_refuses_bad_targets() {
		$g1    = (int) $this->g['g1'];
		$g2    = (int) $this->g['g2'];
		$other = $this->other_campaign();
		$this->assertSame( 'member_moved', $this->apply( 'move', $this->fields( $g1, 'a', $g2 ) )['code'] );
		$this->assertSame( array( $g2 ), $this->groups_of( 'a' ) );
		$audit = $this->audits( 'member_moved' );
		$this->assertCount( 1, $audit );
		$this->assertSame(
			array(
				'from_group' => $g1,
				'to_group'   => $g2,
			),
			json_decode( $audit[0]['detail'], true )
		);

		$before = $this->snapshot();
		$this->assertSame( 'not_allowed', $this->apply( 'move', $this->fields( $g2, 'a', $other['group'] ) )['code'], 'another campaign' );
		$this->assertSame( 'same_group', $this->apply( 'move', $this->fields( $g2, 'a', $g2 ) )['code'] );
		$this->assertSame( 'not_allowed', $this->apply( 'move', $this->fields( $g1, 'a', $g2 ) )['code'], 'a is no longer in Group One' );
		$this->assertSame( 'not_allowed', $this->apply( 'move', $this->fields( $g1, 'outsider', $g2 ) )['code'], 'a stranger' );
		$this->assertSame( 'not_allowed', $this->apply( 'move', $this->fields( $g2, 'a', 999999 ) )['code'], 'an unknown group' );
		$this->assertSame( $before, $this->snapshot(), 'refused moves change nothing' );

		jlife_challenges_disable_group( $this->u['organizer'], $g1 );
		$before = $this->snapshot();
		$this->assertSame( 'not_allowed', $this->apply( 'move', $this->fields( $g2, 'c', $g1 ) )['code'], 'a disabled target' );
		$this->assertSame( $before['group_members'], $this->snapshot()['group_members'] );
		$this->assertSame( array( $g2 ), $this->groups_of( 'c' ) );
	}

	/**
	 * D18, a move, both directions: the old group keeps the old shares and never sees the new
	 * ones; the new group never sees the old ones; the mover loses the old feed; private
	 * entries (theirs and others') reach no feed.
	 */
	public function test_d18_move_leaks_nothing_in_either_direction() {
		$g1 = (int) $this->g['g1'];
		$g2 = (int) $this->g['g2'];
		$this->post( 'a', array( $g1 ), 'OLD-A-SHARE' );
		$this->post( 'b', array(), 'B-PRIVATE-ONLY' );
		$this->post( 'c', array( $g2 ), 'C-IN-G2' );

		$this->assertSame( 'member_moved', $this->apply( 'move', $this->fields( $g1, 'a', $g2 ) )['code'] );
		$this->set_now( '2026-12-21 18:00:00' ); // Day 2.
		$this->post( 'a', array( $g2 ), 'NEW-A-SHARE', 'd002' );
		$this->set_now( '2026-12-22 18:00:00' ); // Day 3.
		$denied = jlife_challenges_save_and_share( $this->u['a'], $this->campaign, 'd003', $this->soap( 'A-PRIVATE-DAY-3' ), array( $g1 ) );
		$this->assertSame( 'failed', $denied['share']['status'], 'the mover cannot post to the old group' );

		$old = $this->feed_text( 'b', $g1 );
		$this->assertStringContainsString( 'OLD-A-SHARE', $old, 'the old group keeps the old share' );
		foreach ( array( 'NEW-A-SHARE', 'C-IN-G2', 'B-PRIVATE-ONLY', 'A-PRIVATE-DAY-3' ) as $absent ) {
			$this->assertStringNotContainsString( $absent, $old, "old group: {$absent}" );
		}
		$new = $this->feed_text( 'c', $g2 );
		$this->assertStringContainsString( 'NEW-A-SHARE', $new );
		$this->assertStringContainsString( 'C-IN-G2', $new );
		foreach ( array( 'OLD-A-SHARE', 'B-PRIVATE-ONLY', 'A-PRIVATE-DAY-3' ) as $absent ) {
			$this->assertStringNotContainsString( $absent, $new, "new group: {$absent}" );
		}
		$this->assertWPError( jlife_challenges_group_feed( $this->u['a'], $g1 ), 'the mover lost the old feed' );
		$this->assertFalse( jlife_challenges_can_read_feed( $this->u['a'], $g1 ) );
		$left = jlife_challenges_left_group_shares( $this->u['a'], $this->campaign );
		$this->assertSame( array( $g1 ), array_map( 'intval', wp_list_pluck( $left, 'group_id' ) ), 'the mover still sees their old share' );
		$this->assertTrue( jlife_challenges_unshare( $this->u['a'], (int) $left[0]['id'] ), 'and can remove it' );
		$this->assertStringNotContainsString( 'OLD-A-SHARE', $this->feed_text( 'b', $g1 ) );
		$this->assertWPError( jlife_challenges_group_feed( $this->u['organizer'], $g1 ), 'an organizer reads no feed' );
		$this->assertWPError( jlife_challenges_group_feed( $this->u['organizer'], $g2 ) );
	}

	/**
	 * D18, a removal, both directions: the old group keeps what was shared and never sees what
	 * the person shares later with their other group.
	 */
	public function test_d18_removal_leaks_nothing_in_either_direction() {
		$g1 = (int) $this->g['g1'];
		$g2 = (int) $this->g['g2'];
		$this->assertSame( 'member_added', $this->apply( 'add', $this->fields( $g2, 'a' ) )['code'] );
		$this->post( 'a', array( $g1, $g2 ), 'BOTH-GROUPS' );
		$this->post( 'b', array(), 'B-PRIVATE-ONLY' );

		$this->assertSame( 'member_removed', $this->apply( 'remove', $this->fields( $g1, 'a' ) )['code'] );
		$this->set_now( '2026-12-21 18:00:00' ); // Day 2.
		$this->post( 'a', array( $g2 ), 'AFTER-REMOVAL', 'd002' );
		$this->set_now( '2026-12-22 18:00:00' ); // Day 3.
		$retry = jlife_challenges_save_and_share( $this->u['a'], $this->campaign, 'd003', $this->soap( 'RETRY' ), array( $g1 ) );
		$this->assertSame( 'failed', $retry['share']['status'] );

		$g1_feed = $this->feed_text( 'b', $g1 );
		$this->assertStringContainsString( 'BOTH-GROUPS', $g1_feed, 'past shares stay with the old group' );
		foreach ( array( 'AFTER-REMOVAL', 'RETRY', 'B-PRIVATE-ONLY' ) as $absent ) {
			$this->assertStringNotContainsString( $absent, $g1_feed, "old group: {$absent}" );
		}
		$g2_feed = $this->feed_text( 'c', $g2 );
		$this->assertStringContainsString( 'AFTER-REMOVAL', $g2_feed );
		$this->assertStringContainsString( 'BOTH-GROUPS', $g2_feed, 'the share to the other group is its own row' );
		$this->assertStringNotContainsString( 'B-PRIVATE-ONLY', $g2_feed );
		$this->assertStringNotContainsString( 'RETRY', $g2_feed );
		$this->assertWPError( jlife_challenges_group_feed( $this->u['a'], $g1 ), 'the removed person loses the feed' );
		$left = jlife_challenges_left_group_shares( $this->u['a'], $this->campaign );
		$this->assertSame( array( $g1 ), array_map( 'intval', wp_list_pluck( $left, 'group_id' ) ) );
		$this->assertTrue( jlife_challenges_unshare( $this->u['a'], (int) $left[0]['id'] ) );
		$this->assertStringNotContainsString( 'BOTH-GROUPS', $this->feed_text( 'b', $g1 ) );
		$this->assertStringContainsString( 'BOTH-GROUPS', $this->feed_text( 'c', $g2 ), 'removing it from one group leaves the other' );
	}

	/**
	 * The admin screen never carries SOAP text, shared text or email addresses.
	 */
	public function test_the_screen_never_shows_soap_text_or_email() {
		$g1 = (int) $this->g['g1'];
		$this->post( 'a', array( $g1 ), 'SHARED-MARKER' );
		$this->post( 'b', array(), 'PRIVATE-MARKER' );
		jlife_challenges_save_draft( $this->u['c'], $this->campaign, 'd001', $this->soap( 'DRAFT-MARKER' ) );
		$html = $this->page_html( $this->campaign, $g1 ) . $this->page_html( $this->campaign, (int) $this->g['g2'] ) . $this->page_html( $this->campaign );
		foreach ( array( 'SHARED-MARKER', 'PRIVATE-MARKER', 'DRAFT-MARKER', 'Scripture', 'Prayer', 'Observation' ) as $text ) {
			$this->assertStringNotContainsString( $text, $html, $text );
		}
		foreach ( array( 'a', 'b', 'c', 'd', 'e', 'starter', 'organizer' ) as $who ) {
			$this->assertStringNotContainsString( get_userdata( $this->u[ $who ] )->user_email, $html, "{$who}'s email" );
			$this->assertStringNotContainsString( get_userdata( $this->u[ $who ] )->user_login, $html, "{$who}'s login" );
		}
		$this->assertDoesNotMatchRegularExpression( '/[A-Za-z0-9._%+-]+@[A-Za-z0-9.-]+\.[a-z]{2,}/', $html, 'no email-shaped text' );
		$members = jlife_challenges_group_members( $this->u['organizer'], $g1 );
		$this->assertSame( array( 'user_id', 'name', 'role', 'joined_at' ), array_keys( $members[0] ) );
	}

	/**
	 * Membership changes never touch the ledger, consent, enrollment status or the export.
	 */
	public function test_membership_changes_never_touch_scores_eligibility_or_prize_standing() {
		global $wpdb;
		$g1 = (int) $this->g['g1'];
		$g2 = (int) $this->g['g2'];
		foreach ( array( 'a', 'b', 'c' ) as $who ) {
			jlife_challenges_record_consent( $this->u[ $who ], $this->campaign, 'v1' );
			$this->post( $who, array(), 'score-' . $who );
		}
		$this->set_now( '2026-12-22 18:00:00' ); // Day 3: Day 1 is closed, so this one is late.
		$this->post( 'd', array(), 'late-d' );
		$this->post( 'a', array( $g1 ), 'shared-a', 'd003' );
		$grab   = function () use ( $wpdb ) {
			$out                = array();
			$out['ledger']      = $wpdb->get_results( 'SELECT * FROM ' . jlife_challenges_table( 'score_ledger' ) . ' ORDER BY id', ARRAY_A );
			$out['enrollments'] = $wpdb->get_results( 'SELECT id, user_id, status, age_band, guardian_consent, consent_version, consented, joined, left_at FROM ' . jlife_challenges_table( 'enrollments' ) . ' ORDER BY id', ARRAY_A );
			$out['export']      = array_map(
				static function ( $row ) {
					unset( $row['group'] );
					return $row;
				},
				jlife_challenges_export_scores( $this->u['organizer'], $this->campaign )
			);
			$out['progress']    = array_map(
				function ( $who ) {
					return jlife_challenges_progress( $this->u[ $who ], $this->u[ $who ], $this->campaign );
				},
				array( 'a', 'b', 'c', 'd' )
			);
			return $out;
		};
		$before = $grab();
		$this->assertContains( 'late_no_point', array_column( $before['ledger'], 'status' ), 'a late row is in the mix' );
		$this->assertContains( 'earned', array_column( $before['ledger'], 'status' ) );

		$new = $this->apply( 'create', $this->fields( 0, null, null, 'Brand New', null, $this->campaign ) );
		$this->assertSame( 'created', $new['code'] );
		$n     = (int) $new['group_id'];
		$steps = array(
			array( 'add', $this->fields( $n, 'a' ) ),
			array( 'add', $this->fields( $n, 'd' ) ),
			array( 'move', $this->fields( $g1, 'b', $g2 ) ),
			array( 'remove', $this->fields( $g2, 'c' ) ),
			array( 'rename', $this->fields( $g1, null, null, 'Renamed' ) ),
			array( 'regenerate', $this->fields( $g1, null, null, null, 7 ) ),
			array( 'revoke', $this->fields( $g1 ) ),
			array( 'remove', $this->fields( $n, 'a' ) ),
		);
		foreach ( $steps as $s ) {
			$this->assertNotContains( $this->apply( $s[0], $s[1] )['code'], array( 'forbidden', 'bad_nonce', 'bad_op', 'not_allowed', 'failed' ), $s[0] );
			$this->assertSame( $before, $grab(), "after {$s[0]}: ledger, enrollments, export and progress are unchanged" );
		}
		foreach ( array( 'a', 'b', 'c', 'd' ) as $who ) {
			$this->assertNotNull( jlife_challenges_active_enrollment( $this->campaign, $this->u[ $who ] ), "{$who} is still enrolled" );
		}
	}

	/**
	 * Every write leaves one audit row naming the organizer, and no row holds a person's name,
	 * an email address, an invite code or its hash.
	 */
	public function test_every_change_is_audited_without_personal_data() {
		global $wpdb;
		$audit_table = jlife_challenges_table( 'audit_log' );
		$g1          = (int) $this->g['g1'];
		$g2          = (int) $this->g['g2'];
		$start       = (int) $wpdb->get_var( "SELECT COALESCE(MAX(id), 0) FROM {$audit_table}" );
		$made        = $this->apply( 'create', $this->fields( 0, null, null, 'Audited', null, $this->campaign ) );
		$n           = (int) $made['group_id'];
		$this->assertSame( 'created', $made['code'] );
		$this->assertSame( $this->u['organizer'], (int) $this->audits( 'group_created' )[2]['actor_user_id'] );
		$expect = array(
			array( 'rename', $this->fields( $n, null, null, 'Audited Two' ), 'group_renamed' ),
			array( 'regenerate', $this->fields( $n, null, null, null, 30 ), 'invite_reset' ),
			array( 'revoke', $this->fields( $n ), 'invite_revoked' ),
			array( 'add', $this->fields( $n, 'd' ), 'group_joined' ),
			array( 'move', $this->fields( $g1, 'a', $g2 ), 'member_moved' ),
			array( 'remove', $this->fields( $n, 'd' ), 'group_member_removed' ),
		);
		foreach ( $expect as $e ) {
			$count = $this->audit_count( $e[2] );
			$this->assertNotContains( $this->apply( $e[0], $e[1] )['code'], array( 'forbidden', 'bad_nonce', 'not_allowed', 'failed' ), $e[0] );
			$this->assertSame( $count + 1, $this->audit_count( $e[2] ), "{$e[0]} writes exactly one {$e[2]} row" );
			$rows = $this->audits( $e[2] );
			$this->assertSame( $this->u['organizer'], (int) end( $rows )['actor_user_id'], $e[2] );
		}
		$all = (string) wp_json_encode( $wpdb->get_results( $wpdb->prepare( "SELECT detail FROM {$audit_table} WHERE id > %d", $start ), ARRAY_A ) );
		foreach ( array( 'a', 'b', 'c', 'd', 'e', 'starter' ) as $who ) {
			$u = get_userdata( $this->u[ $who ] );
			foreach ( array( $u->user_email, $u->display_name, $u->user_login ) as $secret ) {
				$this->assertStringNotContainsString( (string) $secret, $all, "audit leaks {$who}" );
			}
		}
		$this->assertDoesNotMatchRegularExpression( '/@|[a-f0-9]{64}|[A-Z2-9]{8}/', preg_replace( '/Audited( Two)?/', '', $all ), 'no email, hash or invite code' );
	}

	/**
	 * Acceptance (#64): two test groups, several people in by invite and by admin, each sees the
	 * correct My Group, one is moved and one removed, and nothing leaks either way.
	 */
	public function test_acceptance_two_groups_invite_and_admin_enrolment_move_and_remove() {
		$alpha = $this->apply( 'create', $this->fields( 0, null, null, 'Test Alpha', 30, $this->campaign ) );
		$beta  = $this->apply( 'create', $this->fields( 0, null, null, 'Test Beta', 30, $this->campaign ) );
		$this->assertSame( 'created', $alpha['code'] );
		$this->assertSame( 'created', $beta['code'] );
		$ga = (int) $alpha['group_id'];
		$gb = (int) $beta['group_id'];

		foreach ( array( 'p1', 'p2', 'p3', 'p4', 'p5', 'p6' ) as $n ) {
			$this->u[ $n ] = self::factory()->user->create(
				array(
					'role'         => 'subscriber',
					'display_name' => ucfirst( $n ),
				)
			);
		}
		// By invite: p1 and p2 into Alpha, p3 into Beta.
		foreach ( array(
			'p1' => $alpha,
			'p2' => $alpha,
			'p3' => $beta,
		) as $who => $group ) {
			$this->assertIsInt( jlife_challenges_join_with_code( $this->u[ $who ], $group['reveal']['code'], 'adult' ), $who );
		}
		// By admin: p4 into Alpha, p5 and p6 into Beta (each signed up on their own first).
		foreach ( array( 'p4', 'p5', 'p6' ) as $who ) {
			$this->assertIsInt( jlife_challenges_enroll_solo( $this->u[ $who ], $this->campaign, 'adult' ) );
		}
		$this->assertSame( 'member_added', $this->apply( 'add', $this->fields( $ga, 'p4' ) )['code'] );
		$this->assertSame( 'member_added', $this->apply( 'add', $this->fields( $gb, 'p5' ) )['code'] );
		$this->assertSame( 'member_added', $this->apply( 'add', $this->fields( $gb, 'p6' ) )['code'] );

		foreach ( array(
			'p1' => 'Test Alpha',
			'p2' => 'Test Alpha',
			'p4' => 'Test Alpha',
			'p3' => 'Test Beta',
			'p5' => 'Test Beta',
			'p6' => 'Test Beta',
		) as $who => $name ) {
			$body  = jlife_challenges_group_body( jlife_challenges_group_view( $this->u[ $who ] ) );
			$other = 'Test Alpha' === $name ? 'Test Beta' : 'Test Alpha';
			$this->assertStringContainsString( $name, $body, "{$who} sees {$name}" );
			$this->assertStringNotContainsString( $other, $body, "{$who} does not see {$other}" );
		}
		$counts = array_column( jlife_challenges_groups_admin_view( $this->u['organizer'], $this->campaign, 0 )['groups'], 'members', 'name' );
		$this->assertSame( 3, (int) $counts['Test Alpha'] );
		$this->assertSame( 3, (int) $counts['Test Beta'] );

		foreach ( array( 'p1', 'p2', 'p4' ) as $who ) {
			$this->post( $who, array( $ga ), "ALPHA-{$who}" );
		}
		foreach ( array( 'p3', 'p5', 'p6' ) as $who ) {
			$this->post( $who, array( $gb ), "BETA-{$who}" );
		}
		$alpha_feed = $this->feed_text( 'p1', $ga );
		$beta_feed  = $this->feed_text( 'p3', $gb );
		foreach ( array( 'ALPHA-p1', 'ALPHA-p2', 'ALPHA-p4' ) as $t ) {
			$this->assertStringContainsString( $t, $alpha_feed );
			$this->assertStringNotContainsString( $t, $beta_feed );
		}
		foreach ( array( 'BETA-p3', 'BETA-p5', 'BETA-p6' ) as $t ) {
			$this->assertStringContainsString( $t, $beta_feed );
			$this->assertStringNotContainsString( $t, $alpha_feed );
		}

		// Move p2 from Alpha to Beta; remove p4 from Alpha.
		$this->assertSame( 'member_moved', $this->apply( 'move', $this->fields( $ga, 'p2', $gb ) )['code'] );
		$this->assertSame( 'member_removed', $this->apply( 'remove', $this->fields( $ga, 'p4' ) )['code'] );
		$this->set_now( '2026-12-21 18:00:00' ); // Day 2.
		$this->post( 'p2', array( $gb ), 'P2-NEW-IN-BETA', 'd002' );
		$this->post( 'p1', array(), 'P1-PRIVATE-D2', 'd002' );

		$alpha_feed = $this->feed_text( 'p1', $ga );
		$beta_feed  = $this->feed_text( 'p3', $gb );
		$this->assertStringContainsString( 'ALPHA-p2', $alpha_feed, 'the old group keeps the old share' );
		$this->assertStringContainsString( 'ALPHA-p4', $alpha_feed );
		$this->assertStringNotContainsString( 'P2-NEW-IN-BETA', $alpha_feed );
		$this->assertStringNotContainsString( 'ALPHA-', $beta_feed, 'the new group never sees old-group shares' );
		$this->assertStringContainsString( 'P2-NEW-IN-BETA', $beta_feed );
		$this->assertStringNotContainsString( 'P1-PRIVATE-D2', $alpha_feed . $beta_feed );
		foreach ( array( 'p2', 'p4' ) as $gone ) {
			$this->assertWPError( jlife_challenges_group_feed( $this->u[ $gone ], $ga ), "{$gone} lost Alpha" );
		}
		$this->assertStringContainsString( 'Test Beta', jlife_challenges_group_body( jlife_challenges_group_view( $this->u['p2'] ) ) );
		$this->assertStringContainsString( 'reading on your own', jlife_challenges_group_body( jlife_challenges_group_view( $this->u['p4'] ) ) );
	}

	/**
	 * Reloading a form that made a group or a code does not make a second one.
	 */
	public function test_a_reloaded_form_does_not_make_a_second_group_or_code() {
		$fields          = $this->fields( 0, null, null, 'Once Only', 30, $this->campaign );
		$fields['token'] = 'tok' . wp_generate_password( 8, false );
		$first           = $this->apply( 'create', $fields );
		$second          = $this->apply( 'create', $fields );
		$this->assertSame( 'created', $first['code'] );
		$this->assertSame( 'duplicate', $second['code'] );
		$this->assertNull( $second['reveal'] );
		$this->assertCount( 4, jlife_challenges_list_groups( $this->u['organizer'], $this->campaign ), 'one new group, not two' );

		$g1              = (int) $this->g['g1'];
		$fields          = $this->fields( $g1, null, null, null, 30 );
		$fields['token'] = 'tok' . wp_generate_password( 8, false );
		$one             = $this->apply( 'regenerate', $fields );
		$again           = $this->apply( 'regenerate', $fields );
		$this->assertSame( 'invite_reset', $one['code'] );
		$this->assertSame( 'duplicate', $again['code'] );
		$this->assertSame( $g1, (int) jlife_challenges_group_for_invite( $one['reveal']['code'] )['id'], 'the first code still works' );
	}

	/**
	 * Post a form to the page's load handler the way the browser does.
	 *
	 * @param string $who    Fixture user.
	 * @param string $op     Operation.
	 * @param array  $fields Form fields (jl_*), without the operation and nonce.
	 * @param string $nonce  Nonce override, or '' for a valid one.
	 */
	private function post_form( $who, $op, array $fields, $nonce = '' ) {
		wp_set_current_user( $this->u[ $who ] );
		$_SERVER['REQUEST_METHOD'] = 'POST';
		$fields                   += array(
			'jl_op'       => $op,
			'jl_group_id' => 0,
		);
		$fields['_wpnonce']        = '' !== $nonce ? $nonce : wp_create_nonce( jlife_challenges_groups_admin_nonce_action( $op, (int) $fields['jl_group_id'] ) );
		$_POST                     = $fields;
		jlife_challenges_groups_admin_load();
	}

	/**
	 * A write redirects back with a result code and no secret; a new code is kept for the page, once.
	 */
	public function test_the_post_handler_redirects_and_shows_a_new_code_once() {
		$g1       = (int) $this->g['g1'];
		$location = '';
		$catch    = static function ( $url ) use ( &$location ) {
			$location = (string) $url;
			throw new RuntimeException( 'redirected' );
		};
		add_filter( 'wp_redirect', $catch );
		try {
			$this->post_form(
				'organizer',
				'rename',
				array(
					'jl_group_id' => $g1,
					'jl_name'     => 'Via Handler',
				)
			);
			$this->fail( 'a write redirects' );
		} catch ( RuntimeException $e ) {
			$this->assertSame( 'redirected', $e->getMessage() );
		}
		remove_filter( 'wp_redirect', $catch );
		$this->assertSame( 'Via Handler', jlife_challenges_get_group( $g1 )['name'] );
		parse_str( (string) wp_parse_url( $location, PHP_URL_QUERY ), $query );
		$this->assertSame(
			array(
				'page'      => JLIFE_CHALLENGES_GROUPS_PAGE,
				'campaign'  => (string) $this->campaign,
				'group'     => (string) $g1,
				'jl_result' => 'renamed',
			),
			$query
		);
		$this->assertNull( jlife_challenges_groups_admin_pending(), 'nothing to show on a redirect' );

		// A new code is not redirected with: the same request shows it, once.
		$this->post_form(
			'organizer',
			'create',
			array(
				'jl_campaign_id' => $this->campaign,
				'jl_name'        => 'Shown Once',
				'jl_days'        => 30,
			)
		);
		$pending = jlife_challenges_groups_admin_pending();
		$this->assertSame( 'created', $pending['code'] );
		$_SERVER['REQUEST_METHOD'] = 'GET';
		$_POST                     = array();
		ob_start();
		jlife_challenges_groups_admin_page();
		$html = (string) ob_get_clean();
		$this->assertSame( 1, substr_count( $html, '<code>' . $pending['reveal']['code'] . '</code>' ), 'the code is on the page once' );
		$this->assertStringContainsString( 'Shown Once', $html );
		jlife_challenges_groups_admin_pending( false );
		ob_start();
		jlife_challenges_groups_admin_page();
		$later = (string) ob_get_clean();
		$this->assertStringNotContainsString( $pending['reveal']['code'], $later, 'the next visit shows no code' );
	}

	/**
	 * A forged or unauthorised POST gets a 403 and changes nothing; a GET to the handler does nothing.
	 */
	public function test_the_post_handler_refuses_forged_and_unauthorised_requests() {
		$g1     = (int) $this->g['g1'];
		$before = $this->snapshot();
		foreach ( array(
			array( 'organizer', 'bad123nonce' ),
			array( 'a', '' ),
			array( 'starter', '' ),
			array( 'editor', '' ),
		) as $case ) {
			$this->die_args = null;
			try {
				$this->post_form(
					$case[0],
					'revoke',
					array( 'jl_group_id' => $g1 ),
					$case[1]
				);
				$this->fail( "{$case[0]} was not refused" );
			} catch ( WPDieException $e ) {
				$this->assertSame( 403, $this->die_args['response'] ?? 0, $case[0] );
			}
		}
		wp_set_current_user( $this->u['organizer'] );
		$_SERVER['REQUEST_METHOD'] = 'GET';
		$_POST                     = array( 'jl_op' => 'revoke' );
		jlife_challenges_groups_admin_load();
		$this->assertSame( $before, $this->snapshot(), 'nothing changed' );
	}

	/**
	 * #158: the group's detail view shows only the button that applies, and
	 * Disable carries a one-line explanation and a required "I understand" box.
	 */
	public function test_the_detail_view_shows_disable_or_enable() {
		$g1   = (int) $this->g['g1'];
		$html = $this->page_html( $this->campaign, $g1 );
		$this->assertStringContainsString( '<h3>Disable this group</h3>', $html );
		$this->assertStringContainsString( 'Disabling stops the invite code and closes the group feed to its members. Nothing is deleted, and you can enable it again.', $html );
		$this->assertStringContainsString( '<input type="checkbox" name="jl_confirm" value="1" required>', $html, 'confirmation without JavaScript' );
		$this->assertStringContainsString( 'name="jl_op" value="disable"', $html );
		$this->assertStringContainsString( '>Disable group</button>', $html );
		$this->assertStringNotContainsString( 'Enable group', $html );
		$this->assertStringContainsString( 'value="' . wp_create_nonce( jlife_challenges_groups_admin_nonce_action( 'disable', $g1 ) ) . '"', $html, 'a nonce for this operation and group' );
		$this->assertStringNotContainsString( 'Disable group', $this->page_html( $this->campaign ), 'only on a group\'s detail view' );

		jlife_challenges_disable_group( $this->u['organizer'], $g1 );
		$html = $this->page_html( $this->campaign, $g1 );
		$this->assertStringContainsString( '<h3>Enable this group</h3>', $html );
		$this->assertStringContainsString( 'name="jl_op" value="enable"', $html );
		$this->assertStringContainsString( '>Enable group</button>', $html );
		$this->assertStringNotContainsString( 'Disable group', $html );
		$this->assertStringNotContainsString( 'jl_confirm', $html, 'enable needs no confirmation' );
	}

	/**
	 * #158: an organizer disables a group from the screen (with the box ticked):
	 * audited, its code refused, its feed closed, nothing deleted. Enabling
	 * restores the code and the feed, audited too. Each shows a success notice.
	 */
	public function test_an_organizer_disables_and_enables_a_group() {
		$g1   = (int) $this->g['g1'];
		$code = jlife_challenges_reset_invite( $this->u['organizer'], $g1 );
		$this->assertIsString( $code );
		$this->post( 'a', array( $g1 ), 'feed-before' );
		$this->assertStringContainsString( 'feed-before', $this->feed_text( 'b', $g1 ) );
		global $wpdb;
		$members = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . jlife_challenges_table( 'group_members' ) . " WHERE group_id = %d AND status = 'active'", $g1 ) );
		$shares  = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . jlife_challenges_table( 'group_shares' ) . " WHERE group_id = %d AND status = 'shared'", $g1 ) );

		// Without the box: nothing changes.
		$before = $this->snapshot();
		$this->assertSame( 'confirm_needed', $this->apply( 'disable', $this->fields( $g1 ) )['code'] );
		$this->assertSame( $before, $this->snapshot(), 'unconfirmed: nothing changed' );

		$disabled = $this->audit_count( 'group_disabled' );
		$r        = $this->apply( 'disable', $this->fields( $g1 ) + array( 'confirm' => true ) );
		$this->assertSame( 'group_disabled', $r['code'] );
		$this->assertSame( $g1, $r['group_id'] );
		$this->assertSame( 'disabled', jlife_challenges_get_group( $g1 )['status'] );
		$this->assertSame( $disabled + 1, $this->audit_count( 'group_disabled' ), 'one audit row' );
		$rows = $this->audits( 'group_disabled' );
		$this->assertSame( $this->u['organizer'], (int) end( $rows )['actor_user_id'] );
		$this->assertSame( $g1, (int) end( $rows )['object_id'] );

		$this->assertNull( jlife_challenges_group_for_invite( $code ), 'the code is refused' );
		$this->assertSame( 'jlife_invite_invalid', jlife_challenges_join_with_code( $this->u['d'], $code, 'adult' )->get_error_code() );
		$this->assertFalse( jlife_challenges_can_read_feed( $this->u['a'], $g1 ), 'the feed is closed' );
		$this->assertWPError( jlife_challenges_group_feed( $this->u['b'], $g1 ) );
		$this->assertSame( $shares, (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . jlife_challenges_table( 'group_shares' ) . " WHERE group_id = %d AND status = 'shared'", $g1 ) ), 'no post deleted' );
		$this->assertStringContainsString( 'notice-success"><p>Group disabled. Its invite code no longer works and its members cannot read its feed. Nothing was deleted.</p>', $this->page_html( $this->campaign, $g1, 'group_disabled' ) );
		$this->assertStringContainsString( 'Disabled', $this->page_html( $this->campaign ), 'the Status column' );

		$enabled = $this->audit_count( 'group_enabled' );
		$r       = $this->apply( 'enable', $this->fields( $g1 ) );
		$this->assertSame( 'group_enabled', $r['code'] );
		$this->assertSame( 'active', jlife_challenges_get_group( $g1 )['status'] );
		$this->assertSame( $enabled + 1, $this->audit_count( 'group_enabled' ), 'one audit row' );
		$rows = $this->audits( 'group_enabled' );
		$this->assertSame( $this->u['organizer'], (int) end( $rows )['actor_user_id'] );
		$this->assertSame( $members, (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . jlife_challenges_table( 'group_members' ) . " WHERE group_id = %d AND status = 'active'", $g1 ) ), 'the members are back' );
		$this->assertStringContainsString( 'feed-before', $this->feed_text( 'b', $g1 ), 'the feed is restored with its posts' );
		$this->assertNotNull( jlife_challenges_group_for_invite( $code ), 'the code works again' );
		$this->assertIsInt( jlife_challenges_join_with_code( $this->u['d'], $code, 'adult' ), 'and someone new can join with it' );
		$this->assertStringContainsString( 'notice-success"><p>Group enabled.', $this->page_html( $this->campaign, $g1, 'group_enabled' ) );
	}

	/**
	 * #158: Disable and Enable are refused for a non-organizer, a wrong, missing
	 * or borrowed nonce, and a GET; nothing changes and nothing is audited.
	 */
	public function test_disable_and_enable_are_refused_without_rights_nonce_or_post() {
		$g1 = (int) $this->g['g1'];
		$g2 = (int) $this->g['g2'];
		jlife_challenges_disable_group( $this->u['organizer'], $g2 );
		$before = $this->snapshot();
		$cases  = array(
			array( 'disable', $this->fields( $g1 ) + array( 'confirm' => true ) ),
			array( 'enable', $this->fields( $g2 ) ),
		);
		foreach ( $cases as $c ) {
			list( $op, $base ) = $c;
			$group_id          = (int) $base['group_id'];
			foreach ( array( 'a', 'starter', 'editor', 'outsider' ) as $who ) {
				$this->assertSame( 'forbidden', $this->apply( $op, $base, $who )['code'], "{$op}: {$who}" );
			}
			$this->assertSame( 'bad_nonce', $this->apply( $op, $base + array( 'nonce' => '' ) )['code'], "{$op}: none" );
			$this->assertSame( 'bad_nonce', $this->apply( $op, $base + array( 'nonce' => 'nope123456' ) )['code'], "{$op}: junk" );
			$other = 'disable' === $op ? 'enable' : 'disable';
			$this->assertSame( 'bad_nonce', $this->apply( $op, $base + array( 'nonce' => wp_create_nonce( jlife_challenges_groups_admin_nonce_action( $other, $group_id ) ) ) )['code'], "{$op}: the other operation's nonce" );
			$this->assertSame( 'bad_nonce', $this->apply( $op, $base + array( 'nonce' => wp_create_nonce( jlife_challenges_groups_admin_nonce_action( $op, $group_id + 1 ) ) ) )['code'], "{$op}: another group's nonce" );
			$this->assertSame( 'bad_method', $this->apply( $op, $base, 'organizer', 'GET' )['code'], "{$op}: GET" );
		}
		$this->assertSame( $before, $this->snapshot(), 'nothing changed, nothing audited' );
	}

	/**
	 * #158: enabling a group that would put a member over the group limit is
	 * refused, as with the CLI, with a message that says why; the group stays
	 * disabled and nothing is audited.
	 */
	public function test_enabling_over_the_group_limit_is_refused_with_its_message() {
		$g1 = (int) $this->g['g1'];
		$this->assertSame( 'group_disabled', $this->apply( 'disable', $this->fields( $g1 ) + array( 'confirm' => true ) )['code'] );
		foreach ( array( 'X', 'Y', 'Z' ) as $name ) {
			$g = jlife_challenges_create_group( $this->u['organizer'], $this->campaign, 'Limit ' . $name );
			$this->assertIsInt( jlife_challenges_join_with_code( $this->u['a'], $g['invite_code'], 'adult' ) );
		}
		$this->assertCount( JLIFE_CHALLENGES_MAX_GROUPS, $this->groups_of( 'a' ) );
		$this->assertWPError( jlife_challenges_enable_group( $this->u['organizer'], $g1 ), 'the CLI function refuses too' );
		$enabled = $this->audit_count( 'group_enabled' );

		$r = $this->apply( 'enable', $this->fields( $g1 ) );
		$this->assertSame( 'enable_limit', $r['code'] );
		$this->assertSame( 'disabled', jlife_challenges_get_group( $g1 )['status'] );
		$this->assertSame( $enabled, $this->audit_count( 'group_enabled' ) );
		$this->assertStringContainsString( 'notice-error"><p>Not enabled: someone in this group is already in the most groups they can join (3). Move or remove them first.</p>', $this->page_html( $this->campaign, $g1, 'enable_limit' ) );
	}

	/**
	 * #158 through the real POST handler: a disable with the box ticked redirects
	 * with a success code; without it, with confirm_needed and no change. A GET
	 * does nothing.
	 */
	public function test_the_post_handler_disables_only_when_confirmed() {
		$g1       = (int) $this->g['g1'];
		$location = '';
		$catch    = static function ( $url ) use ( &$location ) {
			$location = (string) $url;
			throw new RuntimeException( 'redirected' );
		};
		add_filter( 'wp_redirect', $catch );
		foreach ( array(
			array( array( 'jl_group_id' => $g1 ), 'confirm_needed', 'active' ),
			array(
				array(
					'jl_group_id' => $g1,
					'jl_confirm'  => '1',
				),
				'group_disabled',
				'disabled',
			),
		) as $case ) {
			try {
				$this->post_form( 'organizer', 'disable', $case[0] );
				$this->fail( 'a write redirects' );
			} catch ( RuntimeException $e ) {
				$this->assertSame( 'redirected', $e->getMessage() );
			}
			parse_str( (string) wp_parse_url( $location, PHP_URL_QUERY ), $query );
			$this->assertSame( $case[1], $query['jl_result'] ?? '' );
			$this->assertSame( (string) $g1, $query['group'] ?? '' );
			$this->assertSame( $case[2], jlife_challenges_get_group( $g1 )['status'] );
		}
		try {
			$this->post_form( 'organizer', 'enable', array( 'jl_group_id' => $g1 ) );
			$this->fail( 'a write redirects' );
		} catch ( RuntimeException $e ) {
			$this->assertSame( 'redirected', $e->getMessage() );
		}
		remove_filter( 'wp_redirect', $catch );
		$this->assertSame( 'active', jlife_challenges_get_group( $g1 )['status'] );

		$before = $this->snapshot();
		wp_set_current_user( $this->u['organizer'] );
		$_SERVER['REQUEST_METHOD'] = 'GET';
		$_POST                     = array(
			'jl_op'       => 'disable',
			'jl_group_id' => $g1,
			'jl_confirm'  => '1',
		);
		jlife_challenges_groups_admin_load();
		$this->assertSame( $before, $this->snapshot(), 'a GET changes nothing' );
	}

	/**
	 * #158 in a closed campaign (#179): disabling still works (it only reduces
	 * what members see), and enabling follows the existing function: it restores
	 * the members' read access to the feed, but opens no write. The code still
	 * cannot be used to join and nobody can share to the group.
	 */
	public function test_in_a_closed_campaign_disable_works_and_enable_opens_no_writes() {
		$g1   = (int) $this->g['g1'];
		$code = jlife_challenges_reset_invite( $this->u['organizer'], $g1 );
		$this->post( 'a', array( $g1 ), 'before-close' );
		$this->assertIsArray( jlife_challenges_close_campaign( $this->u['organizer'], $this->campaign ) );

		$this->assertSame( 'group_disabled', $this->apply( 'disable', $this->fields( $g1 ) + array( 'confirm' => true ) )['code'] );
		$this->assertFalse( jlife_challenges_can_read_feed( $this->u['b'], $g1 ) );

		$this->assertSame( 'group_enabled', $this->apply( 'enable', $this->fields( $g1 ) )['code'] );
		$this->assertStringContainsString( 'before-close', $this->feed_text( 'b', $g1 ), 'members can read what was shared' );
		$this->assertWPError( jlife_challenges_join_with_code( $this->u['d'], $code, 'adult' ), 'nobody joins a closed campaign\'s group' );
		$this->assertNotContains( $g1, $this->groups_of( 'd' ) );
		$shared = jlife_challenges_save_and_share( $this->u['b'], $this->campaign, 'd001', $this->soap( 'after-close' ), array( $g1 ) );
		$this->assertWPError( $shared, 'no new share in a closed campaign' );
		$this->assertStringNotContainsString( 'after-close', $this->feed_text( 'a', $g1 ) );
	}
}
