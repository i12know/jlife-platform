<?php
/**
 * The reminder opt-in shows only in saved Live mode (#189, part 1). For every
 * mode (Off, Dry run, Test, Live): the checkbox on Today and My Marathon, what
 * an "on" or an absent field does, that Language and My Bible saves never touch
 * the reminder choice, that unsubscribing works, that switching modes keeps
 * every choice, and that rendering or hiding writes nothing. The join form's
 * box is covered in jlife-auth's Test_Join_Modes.
 *
 * @package jlife-challenges
 */

// phpcs:disable WordPress.Security.NonceVerification, WordPress.DB.DirectDatabaseQuery -- tests drive the handlers with crafted requests.

/**
 * Reminder opt-in visibility tests.
 */
class Test_Reminder_Visibility extends Jlife_Challenges_TestCase {

	/**
	 * Every saved mode.
	 */
	const MODES = array( 'off', 'dry_run', 'test', 'live' );

	/**
	 * Participants a, b and c have accepted the consent wording.
	 */
	public function set_up() {
		parent::set_up();
		foreach ( array( 'a', 'b', 'c' ) as $n ) {
			jlife_challenges_record_consent( $this->u[ $n ], $this->campaign, 'test-version' );
		}
	}

	/**
	 * Clean request state.
	 */
	public function tear_down() {
		$_POST    = array();
		$_REQUEST = array();
		$_GET     = array();
		wp_set_current_user( 0 );
		parent::tear_down();
	}

	/**
	 * Save a mode the way J-Life → Reminders does.
	 *
	 * @param string $mode Mode.
	 */
	private function mode( $mode ) {
		$this->assertTrue( jlife_challenges_reminders_set_mode( $mode, $this->u['organizer'] ) );
		$this->assertSame( $mode, jlife_challenges_reminders_mode() );
	}

	/**
	 * Save through the reminder REST route as a user.
	 *
	 * @param int   $user_id Signed-in user.
	 * @param array $body    JSON body.
	 * @return WP_REST_Response
	 */
	private function rest_as( $user_id, $body ) {
		wp_set_current_user( $user_id );
		$req = new WP_REST_Request( 'POST', '/jlife/v1/reminder' );
		$req->set_header( 'Content-Type', 'application/json' );
		$req->set_body( (string) wp_json_encode( $body ) );
		return rest_get_server()->dispatch( $req );
	}

	/**
	 * Post a form (no JavaScript) as a user, with a nonce for one action.
	 *
	 * @param int    $user_id Signed-in user.
	 * @param array  $post    Fields.
	 * @param string $action  Nonce action.
	 * @param string $field   Nonce field.
	 */
	private function post_as( $user_id, $post, $action, $field ) {
		wp_set_current_user( $user_id );
		$_POST    = $post + array( $field => wp_create_nonce( $action ) );
		$_REQUEST = $_POST;
	}

	/**
	 * A person's reminder row, or null.
	 *
	 * @param string $name Fixture name.
	 * @return array|null
	 */
	private function pref( $name ) {
		return jlife_challenges_reminder_pref( $this->u[ $name ], $this->campaign );
	}

	/**
	 * Today's and My Marathon's markup for a person.
	 *
	 * @param string $name Fixture name.
	 * @return array{0: string, 1: string}
	 */
	private function screens( $name ) {
		$id = $this->u[ $name ];
		wp_set_current_user( $id );
		return array(
			jlife_challenges_render_reminder_toggle( $id, $this->campaign ),
			jlife_challenges_render_reminder_settings( $id, $this->campaign ),
		);
	}

	/**
	 * A snapshot of everything a render or a hidden field could change: the
	 * reminder rows, the consent column of the enrollments and the audit log.
	 *
	 * @return array
	 */
	private function snapshot() {
		global $wpdb;
		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- custom tables; names from $wpdb->prefix.
		$prefs = $wpdb->get_results( 'SELECT * FROM ' . jlife_challenges_table( 'reminder_prefs' ) . ' ORDER BY id', ARRAY_A );
		$enrol = $wpdb->get_results( 'SELECT id, consented FROM ' . jlife_challenges_table( 'enrollments' ) . ' ORDER BY id', ARRAY_A );
		$audit = $wpdb->get_var( 'SELECT COUNT(*) FROM ' . jlife_challenges_table( 'audit_log' ) );
		// phpcs:enable
		return array( $prefs, $enrol, (int) $audit );
	}

	/**
	 * The saved mode decides: the checkbox is on Today and My Marathon only in
	 * Live. Language and My Bible stay in every mode; outside Live the section
	 * that holds My Bible is headed "Bible".
	 */
	public function test_checkbox_shows_only_in_saved_live_mode() {
		jlife_challenges_set_reminder( $this->u['a'], $this->u['a'], $this->campaign, true );
		foreach ( self::MODES as $mode ) {
			$this->mode( $mode );
			list( $today, $marathon ) = $this->screens( 'a' );
			$live                     = 'live' === $mode;
			$this->assertSame( $live, str_contains( $today, 'name="jl_reminder"' ), "Today, {$mode}" );
			$this->assertSame( $live, str_contains( $marathon, 'name="jl_reminder"' ), "My Marathon, {$mode}" );
			$this->assertSame( $live, str_contains( $marathon, 'Email me each morning' ), "label, {$mode}" );
			$this->assertSame( $live, str_contains( $marathon, '<h2>Daily reading email</h2>' ), "heading, {$mode}" );
			$this->assertStringContainsString( '<select id="jl-language" name="jl_language">', $marathon, "Language stays, {$mode}" );
			if ( function_exists( 'jlife_scripture_versions_for_user' ) ) {
				$this->assertStringContainsString( 'class="jl-bible-picker"', $marathon, "My Bible stays, {$mode}" );
				$this->assertSame( ! $live, str_contains( $marathon, '<section class="jl-reminders"><h2>Bible</h2>' ), "Bible heading, {$mode}" );
			}
			$this->assertTrue( jlife_challenges_reminder_is_on( $this->pref( 'a' ) ), "the choice is kept while hidden, {$mode}" );
		}
		$this->assertSame( 'live', jlife_challenges_reminders_mode() );
		$this->assertSame( true, jlife_challenges_reminder_optin_offered() );
	}

	/**
	 * "Live" is the saved option, nothing else: the default is Off, and an
	 * unknown stored value counts as Off.
	 */
	public function test_only_the_saved_option_counts() {
		delete_option( 'jlife_reminders_mode' );
		$this->assertFalse( jlife_challenges_reminder_optin_offered(), 'default Off' );
		$this->assertSame( '', $this->screens( 'a' )[0] );
		update_option( 'jlife_reminders_mode', 'LIVE' );
		$this->assertFalse( jlife_challenges_reminder_optin_offered(), 'an unknown value is Off' );
		update_option( 'jlife_reminders_mode', 'live' );
		$this->assertTrue( jlife_challenges_reminder_optin_offered() );
	}

	/**
	 * Outside Live nobody can be turned on (REST or form, stale page or crafted
	 * request); turning off always works; in Live both work.
	 */
	public function test_no_new_opt_in_outside_live_but_opt_out_always_works() {
		foreach ( array( 'off', 'dry_run', 'test' ) as $mode ) {
			$this->mode( $mode );
			$r = $this->rest_as( $this->u['b'], array( 'on' => true ) );
			$this->assertSame( 403, $r->get_status(), "REST on, {$mode}" );
			$this->assertSame( 'jlife_reminders_not_offered', $r->get_data()['code'] );
			$this->post_as( $this->u['b'], array( 'jl_reminder' => 'on' ), JLIFE_CHALLENGES_REMINDER_NONCE, '_jlife_reminder' );
			$this->assertSame( '', jlife_challenges_handle_reminder_post( $this->u['b'], $this->campaign ), "form on, {$mode}" );
			$this->assertNull( $this->pref( 'b' ), "nobody turned on, {$mode}" );
			$this->assertSame( array(), jlife_challenges_reminder_recipients( $this->campaign ) );
		}

		$this->mode( 'live' );
		$this->assertSame( 200, $this->rest_as( $this->u['a'], array( 'on' => true ) )->get_status() );
		$this->assertSame( 200, $this->rest_as( $this->u['c'], array( 'on' => true ) )->get_status() );
		$this->assertSame( array( $this->u['a'], $this->u['c'] ), jlife_challenges_reminder_recipients( $this->campaign ), 'Live: both work' );
		$this->mode( 'off' );
		$r = $this->rest_as( $this->u['a'], array( 'on' => false ) );
		$this->assertSame( 200, $r->get_status(), 'REST off while Off' );
		$this->assertFalse( $r->get_data()['on'] );
		$this->post_as( $this->u['c'], array( 'jl_reminder' => 'off' ), JLIFE_CHALLENGES_REMINDER_NONCE, '_jlife_reminder' );
		$this->assertSame( 'off', jlife_challenges_handle_reminder_post( $this->u['c'], $this->campaign ), 'form off while Off' );
		$this->assertSame( array(), jlife_challenges_reminder_recipients( $this->campaign ) );
	}

	/**
	 * A post without the reminder field (Language, My Bible or a REST body with
	 * no "on") never changes the reminder choice, in any mode: no opt-out, no
	 * opt-in, no rewrite of the row (so no new consent version or audit row).
	 */
	public function test_absent_field_changes_nothing_in_any_mode() {
		$this->mode( 'live' );
		jlife_challenges_set_reminder( $this->u['a'], $this->u['a'], $this->campaign, true );
		jlife_challenges_set_reminder( $this->u['b'], $this->u['b'], $this->campaign, false );
		foreach ( self::MODES as $mode ) {
			$this->mode( $mode );
			foreach ( array( 'a', 'b', 'c' ) as $n ) {
				$id     = $this->u[ $n ];
				$before = $this->snapshot();

				// Language, without JavaScript and through REST.
				$this->post_as( $id, array( 'jl_language' => 'vi' ), JLIFE_CHALLENGES_LANGUAGE_NONCE, '_jlife_language' );
				$this->assertSame( '', jlife_challenges_handle_reminder_post( $id, $this->campaign ), "Language form is not a reminder post, {$mode}" );
				$this->assertTrue( jlife_challenges_handle_language_post( $id ) );
				$this->assertTrue( jlife_challenges_set_language( $id, $id, 'en_US' ) );
				// My Bible, without JavaScript.
				if ( function_exists( 'jlife_scripture_versions_for_user' ) ) {
					$this->post_as(
						$id,
						array(
							'jl_bible'           => 'save',
							'jl_bible_version'   => '',
							'jl_bible_version_2' => '',
						),
						JLIFE_CHALLENGES_BIBLE_NONCE,
						'_jlife_bible'
					);
					$this->assertSame( '', jlife_challenges_handle_reminder_post( $id, $this->campaign ), "Bible form is not a reminder post, {$mode}" );
					$this->assertTrue( jlife_challenges_handle_bible_post( $id ) );
				}
				// The reminder route with no "on".
				$r = $this->rest_as( $id, array() );
				$this->assertSame( 200, $r->get_status() );

				$after = $this->snapshot();
				$this->assertSame( $before[0], $this->rows_without_language( $after[0], $before[0] ), "reminder rows unchanged for {$n}, {$mode}" );
				$this->assertSame( $before[1], $after[1], "consent unchanged for {$n}, {$mode}" );
			}
			$this->assertTrue( jlife_challenges_reminder_is_on( $this->pref( 'a' ) ), "a stays on, {$mode}" );
			$this->assertFalse( jlife_challenges_reminder_is_on( $this->pref( 'b' ) ), "b stays off, {$mode}" );
			$this->assertNull( $this->pref( 'c' ), "c still has no row, {$mode}" );
		}
	}

	/**
	 * The Language setting legitimately rewrites a row's language and time
	 * (#159). Put those two back from the earlier snapshot so the comparison is
	 * about on/off and consent only.
	 *
	 * @param array $after  Rows now.
	 * @param array $before Rows before.
	 * @return array
	 */
	private function rows_without_language( $after, $before ) {
		foreach ( $after as $i => $row ) {
			if ( isset( $before[ $i ] ) ) {
				$after[ $i ]['locale']  = $before[ $i ]['locale'];
				$after[ $i ]['updated'] = $before[ $i ]['updated'];
			}
		}
		return $after;
	}

	/**
	 * The signed unsubscribe link works in every mode, Off included.
	 */
	public function test_unsubscribe_works_while_not_live() {
		$this->mode( 'live' );
		foreach ( array( 'a', 'b', 'c' ) as $n ) {
			jlife_challenges_set_reminder( $this->u[ $n ], $this->u[ $n ], $this->campaign, true );
		}
		foreach ( array(
			'a' => 'off',
			'b' => 'dry_run',
			'c' => 'test',
		) as $n => $mode ) {
			$this->mode( $mode );
			$url = jlife_challenges_unsubscribe_url( $this->u[ $n ], $this->campaign );
			wp_parse_str( (string) wp_parse_url( $url, PHP_URL_QUERY ), $q );
			$r = jlife_challenges_unsubscribe_request( 'POST', $q, array( 'List-Unsubscribe' => 'One-Click' ) );
			$this->assertSame( 'done', $r['state'], $mode );
			$this->assertFalse( jlife_challenges_reminder_is_on( $this->pref( $n ) ), "unsubscribed while {$mode}" );
		}
		$this->assertSame( array(), jlife_challenges_reminder_recipients( $this->campaign ) );
	}

	/**
	 * Off → Live → Off → Live keeps every choice exactly (on, off, none), with
	 * no new consent version, audit row or enrollment change from the switching
	 * or from rendering the screens in each mode.
	 */
	public function test_switching_modes_keeps_every_choice_and_writes_nothing() {
		$this->mode( 'live' );
		jlife_challenges_set_reminder( $this->u['a'], $this->u['a'], $this->campaign, true );
		jlife_challenges_set_reminder( $this->u['b'], $this->u['b'], $this->campaign, false );
		$recipients = jlife_challenges_reminder_recipients( $this->campaign );
		$this->assertSame( array( $this->u['a'] ), $recipients );

		$this->mode( 'off' );
		$start = $this->snapshot();
		foreach ( array( 'live', 'off', 'live', 'dry_run', 'test', 'live' ) as $mode ) {
			update_option( 'jlife_reminders_mode', $mode ); // As saved; the mode change's own audit row is not the point here.
			foreach ( array( 'a', 'b', 'c', 'outsider' ) as $n ) {
				$this->screens( $n );
			}
			$this->assertSame( $start, $this->snapshot(), "rendering in {$mode} writes nothing" );
			$this->assertSame( $recipients, jlife_challenges_reminder_recipients( $this->campaign ), "recipients kept, {$mode}" );
		}
		$this->assertTrue( jlife_challenges_reminder_is_on( $this->pref( 'a' ) ) );
		$this->assertFalse( jlife_challenges_reminder_is_on( $this->pref( 'b' ) ) );
		$this->assertNull( $this->pref( 'c' ) );
		list( $today ) = $this->screens( 'a' );
		$this->assertMatchesRegularExpression( '/name="jl_reminder" value="on" checked=\'checked\'/', $today, 'Live again: still ticked' );
		list( $today ) = $this->screens( 'b' );
		$this->assertStringNotContainsString( 'checked', $today, 'Live again: still unticked' );
	}
}
