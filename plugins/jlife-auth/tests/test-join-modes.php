<?php
/**
 * Join modes (#112): an invite code (as before) or "Read on my own" (no code, no
 * group), keeping consent and the age band; joining a second group later with a
 * code. Written before the feature.
 *
 * @package jlife-auth
 */

/**
 * Join-mode tests.
 */
class Test_Join_Modes extends WP_UnitTestCase {

	/**
	 * Organizer user.
	 *
	 * @var int
	 */
	private $organizer = 0;

	/**
	 * Campaign ID.
	 *
	 * @var int
	 */
	private $campaign = 0;

	/**
	 * Groups created: id and invite code.
	 *
	 * @var array[]
	 */
	private $groups = array();

	/**
	 * Create the engine's tables once.
	 */
	public static function wpSetUpBeforeClass() {
		jlife_challenges_install_tables();
	}

	/**
	 * One open campaign with four groups, and a clean mailbox.
	 */
	public function set_up() {
		parent::set_up();
		update_option( 'jlife_reminders_mode', 'live' ); // The join form's reminder box is offered only in saved Live mode (#189).
		reset_phpmailer_instance();
		add_filter( 'wp_mail_from', static fn() => 'jlife@example.test' );
		$this->organizer = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$this->campaign  = jlife_challenges_create_campaign(
			$this->organizer,
			array(
				'campaign_key' => 'auth-' . wp_generate_password( 6, false ),
				'plan_id'      => 'rpl-test',
				'title'        => 'Test',
				'start_date'   => '2026-12-20',
				'end_date'     => '2027-04-02',
				'status'       => 'active',
			)
		);
		foreach ( array( 'One', 'Two', 'Three', 'Four' ) as $name ) {
			$this->groups[ $name ] = jlife_challenges_create_group( $this->organizer, $this->campaign, 'Group ' . $name );
		}
	}

	/**
	 * The code in the most recent email.
	 *
	 * @return string
	 */
	private function last_code() {
		$sent = tests_retrieve_phpmailer_instance()->mock_sent;
		$this->assertNotEmpty( $sent, 'an email was sent' );
		preg_match( '/\b(\d{6})\b/', end( $sent )['body'], $m );
		return $m[1] ?? '';
	}

	/**
	 * Join details.
	 *
	 * @param array $over Overrides.
	 * @return array
	 */
	private function input( $over = array() ) {
		return array_merge(
			array(
				'email'      => 'reader@example.test',
				'first_name' => 'Linh',
				'age_band'   => 'adult',
				'locale'     => 'en_US',
			),
			$over
		);
	}

	/**
	 * Active group IDs of a user in the campaign.
	 *
	 * @param int $user_id User.
	 * @return int[]
	 */
	private function groups_of( $user_id ) {
		return array_map( 'intval', array_column( jlife_challenges_active_memberships( $this->campaign, $user_id ), 'group_id' ) );
	}

	/**
	 * "Read on my own" needs no code: the account and a group-less enrollment are made after the emailed code.
	 */
	public function test_solo_join_needs_no_invite_code() {
		$this->assertTrue( jlife_auth_start_join( $this->input( array( 'mode' => 'solo' ) ) ) );
		$r = jlife_auth_complete( 'reader@example.test', $this->last_code() );
		$this->assertSame( 'join', $r['purpose'] );

		$e = jlife_challenges_get_enrollment( $this->campaign, $r['user_id'] );
		$this->assertSame( 'active', $e['status'] );
		$this->assertSame( 'adult', $e['age_band'] );
		$this->assertSame( array(), $this->groups_of( $r['user_id'] ), 'no group' );
		$this->assertSame( 'Linh', get_userdata( $r['user_id'] )->first_name );
		$this->assertSame( array( 'subscriber' ), get_userdata( $r['user_id'] )->roles );
	}

	/**
	 * Consent and the age band work exactly as for a group join.
	 */
	public function test_solo_join_keeps_consent_and_age_band() {
		$this->assertTrue(
			jlife_auth_start_join(
				$this->input(
					array(
						'mode'     => 'solo',
						'age_band' => 'minor',
					)
				)
			)
		);
		$r = jlife_auth_complete( 'reader@example.test', $this->last_code() );
		$this->assertSame( 'minor', jlife_challenges_get_enrollment( $this->campaign, $r['user_id'] )['age_band'] );
		$this->assertTrue( jlife_auth_needs_consent( $r['user_id'] ) );
		jlife_auth_record_consent( $r['user_id'] );
		$this->assertFalse( jlife_auth_needs_consent( $r['user_id'] ) );
		$this->assertSame( JLIFE_AUTH_CONSENT_VERSION, jlife_challenges_get_enrollment( $this->campaign, $r['user_id'] )['consent_version'] );
	}

	/**
	 * Solo join is validated before any code is sent; under-13s still cannot join.
	 */
	public function test_solo_join_validation() {
		$this->assertSame(
			'jlife_auth_age',
			jlife_auth_start_join(
				$this->input(
					array(
						'mode'     => 'solo',
						'age_band' => 'child',
					)
				)
			)->get_error_code()
		);
		$this->assertSame(
			'jlife_auth_age',
			jlife_auth_start_join(
				$this->input(
					array(
						'mode'     => 'solo',
						'age_band' => '',
					)
				)
			)->get_error_code()
		);
		$this->assertSame(
			'jlife_auth_name',
			jlife_auth_start_join(
				$this->input(
					array(
						'mode'       => 'solo',
						'first_name' => '',
					)
				)
			)->get_error_code()
		);
		$this->assertCount( 0, tests_retrieve_phpmailer_instance()->mock_sent );
	}

	/**
	 * Without the solo choice a code is still required (today's path).
	 */
	public function test_invite_mode_still_requires_a_valid_code() {
		$this->assertSame( 'jlife_invite_invalid', jlife_auth_start_join( $this->input() )->get_error_code(), 'no code, no solo choice' );
		$this->assertSame( 'jlife_invite_invalid', jlife_auth_start_join( $this->input( array( 'invite_code' => 'WRONG123' ) ) )->get_error_code() );
		$this->assertTrue( jlife_auth_start_join( $this->input( array( 'invite_code' => $this->groups['One']['invite_code'] ) ) ) );
		$r = jlife_auth_complete( 'reader@example.test', $this->last_code() );
		$this->assertSame( array( (int) $this->groups['One']['id'] ), $this->groups_of( $r['user_id'] ) );
	}

	/**
	 * A code typed in solo mode is ignored: solo means no group.
	 */
	public function test_solo_mode_ignores_a_typed_code() {
		$this->assertTrue(
			jlife_auth_start_join(
				$this->input(
					array(
						'mode'        => 'solo',
						'invite_code' => $this->groups['One']['invite_code'],
					)
				)
			)
		);
		$r = jlife_auth_complete( 'reader@example.test', $this->last_code() );
		$this->assertSame( array(), $this->groups_of( $r['user_id'] ) );
	}

	/**
	 * No open campaign: a kind error, no email.
	 */
	public function test_solo_join_with_no_open_campaign() {
		global $wpdb;
		$wpdb->update( jlife_challenges_table( 'campaigns' ), array( 'status' => 'finished' ), array( 'id' => $this->campaign ) ); // phpcs:ignore WordPress.DB
		$this->assertSame( 'jlife_auth_no_campaign', jlife_auth_start_join( $this->input( array( 'mode' => 'solo' ) ) )->get_error_code() );
		$this->assertCount( 0, tests_retrieve_phpmailer_instance()->mock_sent );
	}

	/**
	 * Consent is versioned: someone who agreed to older wording is asked again,
	 * and agreeing records the new version on the user and the enrollment.
	 * Reminders depend on having consented at all, not on the version.
	 */
	public function test_older_consent_version_is_asked_again() {
		jlife_auth_start_join( $this->input( array( 'mode' => 'solo' ) ) );
		$r = jlife_auth_complete( 'reader@example.test', $this->last_code() );
		jlife_auth_record_consent( $r['user_id'] );
		$this->assertFalse( jlife_auth_needs_consent( $r['user_id'] ) );

		update_user_meta( $r['user_id'], 'jlife_consent_version', 'draft-2026-11-05' );
		$this->assertTrue( jlife_auth_needs_consent( $r['user_id'] ), 'older wording: asked again' );

		jlife_auth_record_consent( $r['user_id'] );
		$this->assertFalse( jlife_auth_needs_consent( $r['user_id'] ) );
		$this->assertSame( JLIFE_AUTH_CONSENT_VERSION, get_user_meta( $r['user_id'], 'jlife_consent_version', true ) );
		$this->assertSame( JLIFE_AUTH_CONSENT_VERSION, jlife_challenges_get_enrollment( $this->campaign, $r['user_id'] )['consent_version'] );
	}

	/**
	 * The consent page has the photo bullet and a points line that fits every campaign's rule.
	 */
	public function test_consent_text_has_photo_bullet_and_neutral_points_line() {
		$html = jlife_auth_consent_body();
		$this->assertStringContainsString( 'If your campaign accepts photos, a photo of your handwritten page is private to you', $html );
		$this->assertStringContainsString( 'shown to a group only if you tick that group', $html );
		$this->assertStringContainsString( 'prize finalists', $html );
		$this->assertStringContainsString( 'Organizers see points and counts, not your words', $html );
		$this->assertStringContainsString( 'meets your campaign&#039;s rule', $html );
		$this->assertStringNotContainsString( 'all four parts of SOAP', $html );
	}

	/**
	 * Accepting a newer version keeps the earlier acceptance in the audit log, once per
	 * level (account and enrollment); a first acceptance writes none.
	 */
	public function test_reaccepting_keeps_the_earlier_acceptance_in_the_audit_log() {
		global $wpdb;
		$count = function ( $action ) use ( $wpdb ) {
			return (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . jlife_challenges_table( 'audit_log' ) . ' WHERE action = %s', $action ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery
		};
		jlife_auth_start_join( $this->input( array( 'mode' => 'solo' ) ) );
		$r = jlife_auth_complete( 'reader@example.test', $this->last_code() );
		jlife_auth_record_consent( $r['user_id'] );
		$this->assertSame( 0, $count( 'consent_superseded' ), 'a first acceptance writes none' );

		update_user_meta( $r['user_id'], 'jlife_consent_version', 'draft-2026-11-18' );
		update_user_meta( $r['user_id'], 'jlife_consent_at', '2026-11-20 10:00:00' );
		$wpdb->update( jlife_challenges_table( 'enrollments' ), array( 'consent_version' => 'draft-2026-11-18', 'consented' => '2026-11-20 10:00:00' ), array( 'campaign_id' => $this->campaign, 'user_id' => $r['user_id'] ) ); // phpcs:ignore WordPress.DB,WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
		jlife_auth_record_consent( $r['user_id'] );

		$rows = $wpdb->get_results( 'SELECT object_type, detail FROM ' . jlife_challenges_table( 'audit_log' ) . " WHERE action = 'consent_superseded' ORDER BY id", ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery
		$this->assertCount( 2, $rows, 'one row per level' );
		$this->assertSame( array( 'user', 'enrollment' ), wp_list_pluck( $rows, 'object_type' ) );
		foreach ( $rows as $row ) {
			$detail = json_decode( $row['detail'], true );
			$this->assertSame( 'draft-2026-11-18', $detail['old_version'] );
			$this->assertSame( '2026-11-20 10:00:00', $detail['old_at'] );
			$this->assertSame( JLIFE_AUTH_CONSENT_VERSION, $detail['new_version'] );
		}

		jlife_auth_record_consent( $r['user_id'] );
		$this->assertSame( 2, $count( 'consent_superseded' ), 'the same version again writes none' );
	}

	/**
	 * The consent text says sharing posts the whole entry, Prayer included.
	 */
	public function test_consent_text_says_whole_entry_including_prayer() {
		$html = jlife_auth_consent_body();
		$this->assertStringContainsString( 'including your Prayer', $html );
		$this->assertStringContainsString( '/share', $html );
		$this->assertStringNotContainsString( 'parts you picked', $html );
	}

	/**
	 * The reminder box is on the join form for every way in, and it is unticked.
	 */
	public function test_join_form_has_an_unticked_reminder_box() {
		foreach ( array( 'invite', 'solo', 'start' ) as $mode ) {
			$html = jlife_auth_join_form( $mode, array() );
			$this->assertMatchesRegularExpression( '/<input type="checkbox" name="reminders" value="1"\s*>/', $html, "$mode: unticked" );
			$this->assertStringContainsString( 'each morning', $html );
		}
		$this->assertMatchesRegularExpression( '/name="reminders" value="1" checked/', jlife_auth_join_form( 'solo', array( 'reminders' => '1' ) ), 'kept ticked after an error' );
	}

	/**
	 * #189: the join form's box is offered only in saved Live mode. In Off, Dry
	 * run and Test it is gone from every way in (EN and VI), a crafted "reminders"
	 * field opts in nobody, and joining records no reminder row; the consent
	 * page has no opt-in control in any mode.
	 */
	public function test_join_reminder_box_only_in_saved_live_mode() {
		$n = 0;
		foreach ( array( 'off', 'dry_run', 'test', 'live' ) as $mode ) {
			$this->assertTrue( jlife_challenges_reminders_set_mode( $mode, $this->organizer ) );
			$live = 'live' === $mode;
			foreach ( array( 'en_US', 'vi' ) as $locale ) {
				$force = static fn() => $locale;
				add_filter( 'determine_locale', $force, 99 );
				jlife_auth_refresh_translations();
				foreach ( array( 'invite', 'solo', 'start' ) as $way ) {
					$html = jlife_auth_join_form( $way, array( 'reminders' => '1' ) );
					$this->assertSame( $live, str_contains( $html, 'name="reminders"' ), "{$mode}, {$locale}, {$way}" );
				}
				$this->assertStringNotContainsString( 'name="reminders"', jlife_auth_join_form( 'wait', array() ), "{$mode}: never on the wait form" );
				$consent = jlife_auth_consent_body();
				$this->assertStringNotContainsString( 'name="reminders"', $consent, "{$mode}: no opt-in on the consent page" );
				$this->assertStringNotContainsString( 'name="jl_reminder"', $consent );
				remove_filter( 'determine_locale', $force, 99 );
				jlife_auth_refresh_translations();
			}

			$email = 'crafted' . ( ++$n ) . '@example.test';
			jlife_auth_start_join(
				$this->input(
					array(
						'email'     => $email,
						'mode'      => 'solo',
						'reminders' => '1',
					)
				)
			);
			$id = jlife_auth_complete( $email, $this->last_code() )['user_id'];
			jlife_auth_record_consent( $id );
			$pref = jlife_challenges_reminder_pref( $id, $this->campaign );
			if ( $live ) {
				$this->assertTrue( jlife_challenges_reminder_is_on( $pref ), 'Live: the ticked box opts in' );
			} else {
				$this->assertNull( $pref, "{$mode}: a crafted field opts in nobody and writes no row" );
				$this->assertNotContains( $id, jlife_challenges_reminder_recipients( $this->campaign ) );
			}
		}
	}

	/**
	 * Unticked: no reminders. Ticked: opted in, in the language chosen on the form.
	 */
	public function test_join_reminder_box_opts_in_only_when_ticked() {
		jlife_auth_start_join( $this->input( array( 'mode' => 'solo' ) ) );
		$plain = jlife_auth_complete( 'reader@example.test', $this->last_code() )['user_id'];
		$this->assertNull( jlife_challenges_reminder_pref( $plain, $this->campaign ), 'unticked: no row' );
		$this->assertSame( array(), jlife_challenges_reminder_recipients( $this->campaign ) );

		jlife_auth_start_join(
			$this->input(
				array(
					'email'     => 'ticked@example.test',
					'mode'      => 'solo',
					'locale'    => 'vi',
					'reminders' => '1',
				)
			)
		);
		$ticked = jlife_auth_complete( 'ticked@example.test', $this->last_code() )['user_id'];
		$pref   = jlife_challenges_reminder_pref( $ticked, $this->campaign );
		$this->assertNotEmpty( $pref['opted_in'] );
		$this->assertSame( 'vi', $pref['locale'] );
		$this->assertSame( array(), jlife_challenges_reminder_recipients( $this->campaign ), 'not before the consent step' );
		jlife_auth_record_consent( $ticked );
		$this->assertSame( array( $ticked ), jlife_challenges_reminder_recipients( $this->campaign ) );
	}

	/**
	 * The choice rides on a group join and a start-a-group too, and an English form gives English.
	 */
	public function test_reminder_box_works_for_invite_and_start_joins() {
		jlife_auth_start_join(
			$this->input(
				array(
					'invite_code' => $this->groups['One']['invite_code'],
					'reminders'   => '1',
				)
			)
		);
		$a = jlife_auth_complete( 'reader@example.test', $this->last_code() )['user_id'];
		$this->assertSame( 'en_US', jlife_challenges_reminder_pref( $a, $this->campaign )['locale'] );

		jlife_auth_start_join(
			$this->input(
				array(
					'email'      => 'starter@example.test',
					'mode'       => 'start',
					'group_name' => 'Starters',
					'reminders'  => '1',
				)
			)
		);
		$b = jlife_auth_complete( 'starter@example.test', $this->last_code() )['user_id'];
		$this->assertTrue( jlife_challenges_reminder_is_on( jlife_challenges_reminder_pref( $b, $this->campaign ) ) );
	}

	/**
	 * A solo reader who later enters a code joins that group; the enrollment is kept.
	 */
	public function test_solo_reader_joins_a_group_later_with_a_code() {
		jlife_auth_start_join( $this->input( array( 'mode' => 'solo' ) ) );
		$solo = jlife_auth_complete( 'reader@example.test', $this->last_code() )['user_id'];
		$e1   = jlife_challenges_get_enrollment( $this->campaign, $solo );

		jlife_auth_start_join( $this->input( array( 'invite_code' => $this->groups['Two']['invite_code'] ) ) );
		$again = jlife_auth_complete( 'reader@example.test', $this->last_code() );
		$this->assertSame( $solo, $again['user_id'] );
		$this->assertSame( '', $again['notice'] );
		$this->assertSame( array( (int) $this->groups['Two']['id'] ), $this->groups_of( $solo ) );
		$this->assertSame( $e1['id'], jlife_challenges_get_enrollment( $this->campaign, $solo )['id'], 'one enrollment' );
	}

	/**
	 * A member can join more groups by code up to the limit; a fourth is told no, kindly.
	 * (Emailed codes are rate limited per address, so the third join goes straight to the engine.)
	 */
	public function test_joining_a_second_and_third_group_and_a_fourth_notice() {
		jlife_auth_start_join( $this->input( array( 'invite_code' => $this->groups['One']['invite_code'] ) ) );
		$user = jlife_auth_complete( 'reader@example.test', $this->last_code() )['user_id'];

		jlife_auth_start_join( $this->input( array( 'invite_code' => $this->groups['Two']['invite_code'] ) ) );
		$second = jlife_auth_complete( 'reader@example.test', $this->last_code() );
		$this->assertSame( '', $second['notice'] );
		$this->assertIsInt( jlife_challenges_join_with_code( $user, $this->groups['Three']['invite_code'], 'adult' ) );
		$this->assertCount( 3, $this->groups_of( $user ) );

		jlife_auth_start_join( $this->input( array( 'invite_code' => $this->groups['Four']['invite_code'] ) ) );
		$r = jlife_auth_complete( 'reader@example.test', $this->last_code() );
		$this->assertSame( $user, $r['user_id'], 'still signed in as themselves' );
		$this->assertNotSame( '', $r['notice'], 'tells them they are already in 3 groups' );
		$this->assertCount( 3, $this->groups_of( $user ) );
		$this->assertNotContains( (int) $this->groups['Four']['id'], $this->groups_of( $user ) );
	}

	/**
	 * The join page: invite code by default; ?mode=solo drops the code box and says what solo means.
	 */
	public function test_join_form_branches() {
		$code = jlife_auth_join_form( 'invite', array(), '' );
		$this->assertStringContainsString( 'name="invite_code"', $code );
		$this->assertStringContainsString( 'name="mode" value="invite"', $code );
		$this->assertStringContainsString( esc_url( add_query_arg( 'mode', 'solo', home_url( '/join/' ) ) ), $code, 'a way to the solo path' );
		$this->assertStringContainsString( 'Read on my own', $code );

		$solo = jlife_auth_join_form( 'solo', array(), '' );
		$this->assertStringNotContainsString( 'name="invite_code"', $solo );
		$this->assertStringContainsString( 'name="mode" value="solo"', $solo );
		$this->assertStringContainsString( 'name="age_band"', $solo, 'the age band is still asked' );
		$this->assertStringContainsString( 'name="first_name"', $solo );
		$this->assertStringContainsString( esc_url( home_url( '/join/' ) ), $solo, 'a way back to the code path' );
		$this->assertStringContainsString( 'I have an invite code', $solo );
	}

	/**
	 * Mode comes from the request and defaults to the invite path.
	 */
	public function test_join_mode_parsing() {
		$this->assertSame( 'invite', jlife_auth_join_mode( '' ) );
		$this->assertSame( 'invite', jlife_auth_join_mode( 'whatever' ) );
		$this->assertSame( 'solo', jlife_auth_join_mode( 'solo' ) );
	}
}
