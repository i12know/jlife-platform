<?php
/**
 * Rollover, part 1 (#180, docs/challenges-engine-design.md §3d): create the
 * official Marathon and carry pilot participants and "I'll start Dec 20"
 * people into it, on this site, with explicit source and destination IDs.
 * Written before the code.
 *
 * @package jlife-challenges
 */

/**
 * Rollover tests.
 */
class Test_Rollover extends JLife_Challenges_TestCase {

	/**
	 * The pilot (Dec 5–13, 2026).
	 *
	 * @var int
	 */
	private $pilot = 0;

	/**
	 * The official Marathon (Dec 20, 2026 – Apr 2, 2027).
	 *
	 * @var int
	 */
	private $official = 0;

	/**
	 * Fixture people by role in the story.
	 *
	 * @var int[]
	 */
	private $p = array();

	/**
	 * A pilot, an official campaign, and the people of the Dec 2026 story. The
	 * base fixture's campaign is set aside (draft) so it plays no part.
	 */
	public function set_up() {
		parent::set_up();
		global $wpdb;
		$wpdb->update( jlife_challenges_table( 'campaigns' ), array( 'status' => 'draft' ), array( 'id' => $this->campaign ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

		$this->set_now( '2026-11-30 18:00:00' );
		$this->pilot = (int) jlife_challenges_create_pilot_campaign( $this->u['organizer'] )['campaign_id'];
		$made        = jlife_challenges_create_official_campaign( $this->u['organizer'] );
		$this->assertIsArray( $made );
		$this->official = (int) $made['campaign_id'];

		foreach ( array( 'adult', 'minor', 'removed', 'wait', 'wait_minor', 'wait_legacy', 'direct' ) as $who ) {
			$this->p[ $who ] = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		}
		// Pilot participants: an adult in a pilot group, a minor reading solo, one later removed.
		$group = jlife_challenges_create_group( $this->u['organizer'], $this->pilot, 'Pilot Group' );
		$this->assertIsInt( jlife_challenges_join_with_code( $this->p['adult'], $group['invite_code'], 'adult' ) );
		$this->assertIsInt( jlife_challenges_enroll_solo( $this->p['minor'], $this->pilot, 'minor' ) );
		$this->assertIsInt( jlife_challenges_enroll_solo( $this->p['removed'], $this->pilot, 'adult' ) );
		$this->assertTrue( jlife_challenges_record_consent( $this->p['minor'], $this->pilot, 'consent-test-1' ) );
		$this->assertTrue( jlife_challenges_remove_member( $this->u['organizer'], $this->pilot, $this->p['removed'] ) );

		// "I'll start Dec 20": two recorded with their age band, one older flag without it.
		jlife_challenges_set_waiting_for_official( $this->p['wait'], 'adult' );
		jlife_challenges_set_waiting_for_official( $this->p['wait_minor'], 'minor' );
		update_user_meta( $this->p['wait_legacy'], JLIFE_CHALLENGES_WAIT_META, '2026-11-29 20:00:00' );
		update_user_meta( $this->p['wait'], 'jlife_consent_version', 'consent-test-2' );
		update_user_meta( $this->p['wait'], 'jlife_consent_at', '2026-11-29 20:05:00' );

		// Pilot week: the adult writes, shares and earns a point.
		$this->set_now( '2026-12-06 18:00:00' );
		$r = jlife_challenges_save_and_share( $this->p['adult'], $this->pilot, null, $this->soap( 'pilot' ), array( (int) $group['id'] ) );
		$this->assertSame( 1, $r['points'] );
		$this->assertSame( 'shared', $r['share']['status'] );

		// Dec 14: the pilot is closed. Dec 15: the rollover runs.
		$this->set_now( '2026-12-14 18:00:00' );
		$this->assertIsArray( jlife_challenges_close_campaign( $this->u['organizer'], $this->pilot ) );
		$this->set_now( '2026-12-15 18:00:00' );
	}

	/**
	 * Count rows in an engine table for a campaign.
	 *
	 * @param string $table       Table suffix.
	 * @param int    $campaign_id Campaign.
	 * @return int
	 */
	private function rows( $table, $campaign_id ) {
		global $wpdb;
		return (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . jlife_challenges_table( $table ) . ' WHERE campaign_id = %d', $campaign_id ) ); // phpcs:ignore WordPress.DB
	}

	/**
	 * Every row count of a campaign's data, for before/after comparisons.
	 *
	 * @param int $campaign_id Campaign.
	 * @return int[]
	 */
	private function snapshot( $campaign_id ) {
		$out = array();
		foreach ( array( 'enrollments', 'group_members', 'groups', 'soap_entries', 'score_ledger', 'group_shares', 'reminder_prefs', 'reminder_sends' ) as $t ) {
			$out[ $t ] = $this->rows( $t, $campaign_id );
		}
		return $out;
	}

	/**
	 * Assert an error code.
	 *
	 * @param string $code Expected code.
	 * @param mixed  $r    Result.
	 * @param string $msg  Message.
	 */
	private function assert_code( $code, $r, $msg = '' ) {
		$this->assertWPError( $r, $msg );
		$this->assertSame( $code, $r->get_error_code(), $msg );
	}

	/**
	 * The official campaign has the right shape, and creating it again changes nothing.
	 */
	public function test_create_official_is_idempotent() {
		$c = jlife_challenges_get_campaign( $this->official );
		$this->assertSame( 'marathon-2026', $c['campaign_key'] );
		$this->assertSame( '2026-12-20', $c['start_date'] );
		$this->assertSame( '2027-04-02', $c['end_date'] );
		$this->assertSame( 104, jlife_challenges_day_count( $c ) );
		$this->assertSame( 'America/Los_Angeles', $c['timezone'] );
		$this->assertSame( 'active', $c['status'] );
		$this->assertSame( jlife_challenges_get_campaign( $this->pilot )['plan_id'], $c['plan_id'], 'same reading plan as the pilot' );

		$again = jlife_challenges_create_official_campaign( $this->u['organizer'] );
		$this->assertFalse( $again['created'] );
		$this->assertSame( $this->official, (int) $again['campaign_id'] );
		$this->assertWPError( jlife_challenges_create_official_campaign( $this->p['adult'] ), 'organizers only' );
		foreach ( array( 'test-marathon', 'pilot-marathon' ) as $key ) {
			$this->assert_code( 'jlife_campaign_invalid', jlife_challenges_create_official_campaign( $this->u['organizer'], array( 'campaign_key' => $key ) ), $key );
		}
	}

	/**
	 * With no pilot campaign on the site, creating the official one needs --force (new sign-ups would go straight to Dec 20).
	 */
	public function test_create_without_a_pilot_needs_force() {
		global $wpdb;
		$wpdb->update( jlife_challenges_table( 'campaigns' ), array( 'status' => 'draft' ), array( 'id' => $this->pilot ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$args = array( 'campaign_key' => 'marathon-2027' );
		$this->assert_code( 'jlife_official_no_pilot', jlife_challenges_create_official_campaign( $this->u['organizer'], $args ) );
		$made = jlife_challenges_create_official_campaign( $this->u['organizer'], $args, true );
		$this->assertTrue( $made['created'] );
	}

	/**
	 * Under the #177 rule the official campaign is not current before Dec 20, and new joins go to the pilot while it is upcoming or running.
	 */
	public function test_official_waits_for_its_turn() {
		global $wpdb;
		$wpdb->update( jlife_challenges_table( 'campaigns' ), array( 'status' => 'active' ), array( 'id' => $this->pilot ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- reopen the pilot to replay the weeks before Dec 14.
		$both = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		$this->set_now( '2026-11-29 20:00:00' ); // Pilot and official both upcoming.
		$this->assertSame( $this->pilot, (int) jlife_challenges_open_campaign()['id'], 'nearest start: the pilot' );
		$this->assertIsInt( jlife_challenges_enroll_solo( $both, $this->pilot, 'adult' ) );
		$this->assertIsInt( jlife_challenges_enroll_solo( $both, $this->official, 'adult' ) );
		$this->assertSame( $this->pilot, (int) jlife_challenges_participant_campaign( $both )['campaign']['id'] );

		$this->set_now( '2026-12-09 20:00:00' ); // Pilot running.
		$this->assertSame( $this->pilot, (int) jlife_challenges_open_campaign()['id'] );
		$this->assertSame( $this->pilot, (int) jlife_challenges_participant_campaign( $both )['campaign']['id'] );

		$this->set_now( '2026-12-14 08:00:00' ); // Dec 14, 00:00 Pacific: the pilot has ended.
		$this->assertSame( $this->official, (int) jlife_challenges_open_campaign()['id'] );
		$this->assertSame( $this->official, (int) jlife_challenges_participant_campaign( $both )['campaign']['id'] );

		$this->set_now( '2026-12-20 07:59:59' ); // Dec 19, 23:59:59 Pacific.
		$this->assertSame( 'upcoming', jlife_challenges_campaign_phase( jlife_challenges_get_campaign( $this->official ) ) );
		$this->set_now( '2026-12-20 08:00:00' ); // Dec 20, 00:00 Pacific: Day 1.
		$this->assertSame( 'running', jlife_challenges_campaign_phase( jlife_challenges_get_campaign( $this->official ) ) );
	}

	/**
	 * Preview is counts only and changes nothing.
	 */
	public function test_preview_counts_only() {
		$before = $this->snapshot( $this->official );
		$plan   = jlife_challenges_rollover_preview( $this->u['organizer'], $this->pilot, $this->official );
		$this->assertIsArray( $plan );
		foreach ( $plan as $what => $n ) {
			$this->assertIsInt( $n, "{$what} is a count" );
		}
		$this->assertSame( 2, $plan['source_enrollments'], 'adult and minor (the removed one is not active)' );
		$this->assertSame( 3, $plan['waiting_flags'] );
		$this->assertSame( 4, $plan['to_enroll'], 'adult, minor, wait, wait_minor' );
		$this->assertSame( 0, $plan['already_enrolled'] );
		$this->assertSame( 1, $plan['skipped_wait_details_missing'] );
		$this->assertSame( $before, $this->snapshot( $this->official ), 'preview writes nothing' );
	}

	/**
	 * Execute enrolls each person once; a second run adds nothing; each run is audited with counts only.
	 */
	public function test_execute_once_and_rerun_adds_nothing() {
		$r = jlife_challenges_rollover_execute( $this->u['organizer'], $this->pilot, $this->official );
		$this->assertIsArray( $r );
		$this->assertSame( 4, $r['enrolled_now'] );
		foreach ( array( 'adult', 'minor', 'wait', 'wait_minor' ) as $who ) {
			$this->assertNotNull( jlife_challenges_active_enrollment( $this->official, $this->p[ $who ] ), $who );
		}
		foreach ( array( 'removed', 'wait_legacy', 'direct' ) as $who ) {
			$this->assertNull( jlife_challenges_get_enrollment( $this->official, $this->p[ $who ] ), "{$who} not carried" );
		}
		$this->assertSame( 4, $this->rows( 'enrollments', $this->official ) );

		$again = jlife_challenges_rollover_execute( $this->u['organizer'], $this->pilot, $this->official );
		$this->assertSame( 0, $again['enrolled_now'] );
		$this->assertSame( 4, $again['already_enrolled'] );
		$this->assertSame( 4, $this->rows( 'enrollments', $this->official ) );

		global $wpdb;
		$audit = (array) $wpdb->get_results( $wpdb->prepare( 'SELECT detail FROM ' . jlife_challenges_table( 'audit_log' ) . " WHERE action = 'campaign_rollover' AND campaign_id = %d", $this->official ), ARRAY_A ); // phpcs:ignore WordPress.DB
		$this->assertCount( 2, $audit, 'one audit row per execute' );
		foreach ( $audit as $row ) {
			$detail = (array) json_decode( (string) $row['detail'], true );
			array_walk_recursive(
				$detail,
				function ( $n, $what ) {
					$this->assertIsInt( $n, "audit {$what} is a count" );
				}
			);
		}
	}

	/**
	 * A run that stops part-way leaves only whole enrollments, and running it again finishes the job.
	 */
	public function test_interrupted_run_finishes_on_rerun() {
		$calls = 0;
		$fail  = static function () use ( &$calls ) {
			++$calls;
			if ( 3 === $calls ) {
				throw new RuntimeException( 'simulated failure' );
			}
		};
		add_action( 'jlife_challenges_rollover_enroll', $fail );
		try {
			jlife_challenges_rollover_execute( $this->u['organizer'], $this->pilot, $this->official );
			$this->fail( 'the simulated failure should stop the run' );
		} catch ( RuntimeException $e ) {
			$this->assertSame( 'simulated failure', $e->getMessage() );
		}
		remove_action( 'jlife_challenges_rollover_enroll', $fail );
		$this->assertSame( 2, $this->rows( 'enrollments', $this->official ), 'two enrolled before the failure' );
		$this->assertFalse( jlife_challenges_rollover_verify( $this->u['organizer'], $this->pilot, $this->official )['ok'], 'verify sees the gap' );

		$r = jlife_challenges_rollover_execute( $this->u['organizer'], $this->pilot, $this->official );
		$this->assertSame( 2, $r['enrolled_now'] );
		$this->assertSame( 2, $r['already_enrolled'] );
		$this->assertTrue( jlife_challenges_rollover_verify( $this->u['organizer'], $this->pilot, $this->official )['ok'] );
	}

	/**
	 * The age band and the consent record are carried as recorded; guardian consent is not.
	 */
	public function test_age_band_and_consent_are_carried() {
		global $wpdb;
		$wpdb->update( jlife_challenges_table( 'enrollments' ), array( 'guardian_consent' => '2026-12-01 18:00:00' ), array( 'campaign_id' => $this->pilot, 'user_id' => $this->p['minor'] ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
		$pilot_minor = jlife_challenges_get_enrollment( $this->pilot, $this->p['minor'] );
		jlife_challenges_rollover_execute( $this->u['organizer'], $this->pilot, $this->official );

		$minor = jlife_challenges_get_enrollment( $this->official, $this->p['minor'] );
		$this->assertSame( 'minor', $minor['age_band'] );
		$this->assertSame( 'consent-test-1', $minor['consent_version'] );
		$this->assertSame( $pilot_minor['consented'], $minor['consented'] );
		$this->assertNull( $minor['guardian_consent'], 'guardian consent is recorded again for the new campaign' );
		$this->assertSame( 'active', $minor['status'] );
		$this->assertSame( '0', (string) $minor['group_id'] );

		$wait = jlife_challenges_get_enrollment( $this->official, $this->p['wait'] );
		$this->assertSame( 'adult', $wait['age_band'] );
		$this->assertSame( 'consent-test-2', $wait['consent_version'], 'from the account record' );
		$this->assertSame( '2026-11-29 20:05:00', $wait['consented'] );
		$this->assertSame( 'minor', jlife_challenges_get_enrollment( $this->official, $this->p['wait_minor'] )['age_band'] );
		$this->assertNull( jlife_challenges_get_enrollment( $this->official, $this->p['wait_minor'] )['consented'], 'no consent yet: asked at sign-in as for any join' );
	}

	/**
	 * Groups, entries, points, shares and reminder choices never carry over; the official campaign stays at zero.
	 */
	public function test_nothing_but_enrollments_carries_over() {
		jlife_challenges_set_reminder( $this->p['adult'], $this->p['adult'], $this->pilot, false );
		jlife_challenges_rollover_execute( $this->u['organizer'], $this->pilot, $this->official );
		foreach ( array( 'group_members', 'groups', 'soap_entries', 'score_ledger', 'group_shares', 'reminder_prefs', 'reminder_sends' ) as $t ) {
			$this->assertSame( 0, $this->rows( $t, $this->official ), "no official {$t}" );
		}
		$this->assertSame( 0, (int) jlife_challenges_progress( $this->p['adult'], $this->p['adult'], $this->official )['points'] );
		$this->assertSame( array(), jlife_challenges_active_memberships( $this->official, $this->p['adult'] ), 'solo until part 2 (#182)' );
	}

	/**
	 * The closed pilot is read, never written.
	 */
	public function test_pilot_data_is_untouched() {
		$before = $this->snapshot( $this->pilot );
		jlife_challenges_rollover_execute( $this->u['organizer'], $this->pilot, $this->official );
		jlife_challenges_rollover_execute( $this->u['organizer'], $this->pilot, $this->official );
		$this->assertSame( $before, $this->snapshot( $this->pilot ) );
		$this->assertSame( 'closed', jlife_challenges_get_campaign( $this->pilot )['status'] );
		$this->assertSame( 'removed', jlife_challenges_get_enrollment( $this->pilot, $this->p['removed'] )['status'] );
	}

	/**
	 * Someone already in the official campaign is left exactly as they are; someone removed from it is not put back.
	 */
	public function test_existing_destination_enrollments_are_left_alone() {
		$this->assertIsInt( jlife_challenges_enroll_solo( $this->p['adult'], $this->official, 'minor' ) ); // Joined directly on Dec 15.
		$this->assertIsInt( jlife_challenges_enroll_solo( $this->p['wait'], $this->official, 'adult' ) );
		$this->assertTrue( jlife_challenges_remove_member( $this->u['organizer'], $this->official, $this->p['wait'] ) );

		$plan = jlife_challenges_rollover_preview( $this->u['organizer'], $this->pilot, $this->official );
		$this->assertSame( 1, $plan['already_enrolled'] );
		$this->assertSame( 1, $plan['skipped_removed_in_destination'] );
		$r = jlife_challenges_rollover_execute( $this->u['organizer'], $this->pilot, $this->official );
		$this->assertSame( 2, $r['enrolled_now'], 'minor and wait_minor' );
		$this->assertSame( 'minor', jlife_challenges_get_enrollment( $this->official, $this->p['adult'] )['age_band'], 'left as it was' );
		$this->assertSame( 'removed', jlife_challenges_get_enrollment( $this->official, $this->p['wait'] )['status'], 'not put back' );
	}

	/**
	 * Every way the command fails closed, before anything is written.
	 */
	public function test_fails_closed() {
		$org = $this->u['organizer'];
		$this->assert_code( 'jlife_denied', jlife_challenges_rollover_execute( $this->p['adult'], $this->pilot, $this->official ), 'not an organizer' );
		$this->assert_code( 'jlife_rollover_not_found', jlife_challenges_rollover_execute( $org, 987654, $this->official ), 'unknown source' );
		$this->assert_code( 'jlife_rollover_not_found', jlife_challenges_rollover_execute( $org, $this->pilot, 987654 ), 'unknown destination' );
		$this->assert_code( 'jlife_rollover_same', jlife_challenges_rollover_execute( $org, $this->pilot, $this->pilot ) );

		$test = jlife_challenges_create_campaign(
			$org,
			array(
				'campaign_key' => 'test-20261215',
				'plan_id'      => 'rpl-life-and-teachings-of-jesus-2026',
				'title'        => 'TEST',
				'start_date'   => '2027-01-10',
				'end_date'     => '2027-02-10',
				'status'       => 'active',
			)
		);
		$this->assert_code( 'jlife_rollover_source_invalid', jlife_challenges_rollover_execute( $org, $test, $this->official ), 'test source' );
		$this->assert_code( 'jlife_rollover_destination_invalid', jlife_challenges_rollover_execute( $org, $this->pilot, $test ), 'test destination' );
		$this->assert_code( 'jlife_rollover_destination_invalid', jlife_challenges_rollover_execute( $org, $this->official, $this->pilot ), 'pilot destination' );
		$this->assert_code( 'jlife_rollover_source_invalid', jlife_challenges_rollover_execute( $org, $this->campaign, $this->official ), 'draft source' );

		$later = jlife_challenges_create_official_campaign(
			$org,
			array(
				'campaign_key' => 'marathon-2027',
				'start_date'   => '2027-06-01',
				'end_date'     => '2027-09-12',
			)
		);
		global $wpdb;
		$wpdb->update( jlife_challenges_table( 'campaigns' ), array( 'status' => 'draft' ), array( 'id' => (int) $later['campaign_id'] ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$this->assert_code( 'jlife_rollover_destination_invalid', jlife_challenges_rollover_execute( $org, $this->pilot, (int) $later['campaign_id'] ), 'draft destination' );

		$this->set_now( '2026-12-21 18:00:00' ); // The official campaign is running.
		$this->assert_code( 'jlife_rollover_destination_invalid', jlife_challenges_rollover_execute( $org, $this->pilot, $this->official ), 'destination already started' );
		$this->set_now( '2026-12-15 18:00:00' );

		$this->assertSame( 0, $this->rows( 'enrollments', $this->official ), 'nothing written by any refusal' );
	}

	/**
	 * A destination that already has SOAP entries or points is refused.
	 */
	public function test_destination_with_entries_or_points_is_refused() {
		global $wpdb;
		$now = current_time( 'mysql', true );
		$wpdb->insert( jlife_challenges_table( 'soap_entries' ), array( 'user_id' => $this->p['direct'], 'campaign_id' => $this->official, 'day_key' => 'd001', 'body' => 'x', 'status' => 'draft', 'revision' => 0, 'created' => $now, 'updated' => $now ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
		$this->assert_code( 'jlife_rollover_destination_not_empty', jlife_challenges_rollover_execute( $this->u['organizer'], $this->pilot, $this->official ), 'entries' );
		$this->assert_code( 'jlife_rollover_destination_not_empty', jlife_challenges_rollover_preview( $this->u['organizer'], $this->pilot, $this->official ), 'preview too' );
		$wpdb->query( $wpdb->prepare( 'DELETE FROM ' . jlife_challenges_table( 'soap_entries' ) . ' WHERE campaign_id = %d', $this->official ) ); // phpcs:ignore WordPress.DB

		$wpdb->insert( jlife_challenges_table( 'score_ledger' ), array( 'campaign_id' => $this->official, 'user_id' => $this->p['direct'], 'day_key' => 'd001', 'entry_id' => 1, 'points' => 1, 'status' => 'earned', 'reason' => '', 'source' => 'platform', 'engine' => 'soap-regex', 'engine_version' => '1', 'created' => $now ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
		$this->assert_code( 'jlife_rollover_destination_not_empty', jlife_challenges_rollover_execute( $this->u['organizer'], $this->pilot, $this->official ), 'points' );
		$this->assertSame( 0, $this->rows( 'enrollments', $this->official ) );
	}

	/**
	 * Verify passes after a run, and fails when someone is missing or the official campaign has points.
	 */
	public function test_verify() {
		$before = jlife_challenges_rollover_verify( $this->u['organizer'], $this->pilot, $this->official );
		$this->assertFalse( $before['ok'] );
		$this->assertSame( 4, $before['missing'] );

		jlife_challenges_rollover_execute( $this->u['organizer'], $this->pilot, $this->official );
		$v = jlife_challenges_rollover_verify( $this->u['organizer'], $this->pilot, $this->official );
		$this->assertTrue( $v['ok'] );
		$this->assertSame( 4, $v['expected'] );
		$this->assertSame( 4, $v['enrolled_once'] );
		$this->assertSame( 0, $v['missing'] );
		$this->assertSame( 0, $v['destination_ledger_rows'] );
		$this->assertSame( 0, $v['destination_entries'] );
		$this->assertSame( 0, $v['destination_shares'] );
		$this->assertSame( 0, $v['source_changed'] );

		global $wpdb;
		$wpdb->delete( jlife_challenges_table( 'enrollments' ), array( 'campaign_id' => $this->official, 'user_id' => $this->p['minor'] ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
		$v = jlife_challenges_rollover_verify( $this->u['organizer'], $this->pilot, $this->official );
		$this->assertFalse( $v['ok'] );
		$this->assertSame( 1, $v['missing'] );
		$this->assertWPError( jlife_challenges_rollover_verify( $this->p['adult'], $this->pilot, $this->official ), 'organizers only' );

		// Pilot data changing after the run is reported too.
		jlife_challenges_rollover_execute( $this->u['organizer'], $this->pilot, $this->official );
		$this->assertTrue( jlife_challenges_rollover_verify( $this->u['organizer'], $this->pilot, $this->official )['ok'] );
		$wpdb->update( jlife_challenges_table( 'enrollments' ), array( 'status' => 'removed' ), array( 'campaign_id' => $this->pilot, 'user_id' => $this->p['minor'] ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
		$v = jlife_challenges_rollover_verify( $this->u['organizer'], $this->pilot, $this->official );
		$this->assertSame( 1, $v['source_changed'] );
		$this->assertFalse( $v['ok'] );
	}

	/**
	 * After the rollover the official campaign is each person's current one (Dec 15 and Dec 20), and the pilot is an earlier reading period.
	 */
	public function test_after_rollover_the_official_campaign_is_current() {
		jlife_challenges_rollover_execute( $this->u['organizer'], $this->pilot, $this->official );
		foreach ( array( '2026-12-15 18:00:00', '2026-12-20 18:00:00' ) as $utc ) {
			$this->set_now( $utc );
			foreach ( array( 'adult', 'minor', 'wait' ) as $who ) {
				$this->assertSame( $this->official, (int) jlife_challenges_participant_campaign( $this->p[ $who ] )['campaign']['id'], "{$who} at {$utc}" );
			}
			$this->assertSame( array( $this->pilot ), array_map( 'intval', array_column( jlife_challenges_past_campaigns( $this->p['adult'] ), 'id' ) ), "pilot under Earlier reading at {$utc}" );
		}
		$this->set_now( '2026-12-15 18:00:00' );
		$this->assertSame( 'before', jlife_challenges_day_view( $this->p['wait'] )['state'], 'Dec 15: "Almost time" for the Marathon' );
		$this->set_now( '2026-12-20 18:00:00' );
		$this->assertSame( 1, jlife_challenges_day_view( $this->p['wait'] )['index'], 'Dec 20: Day 1' );
	}
}
