<?php
/**
 * Rehearsal (#183, part A): the whole pilot close to official launch sequence,
 * played end to end with fake `.test` people. It calls the same PHP functions
 * the WP-CLI commands call, one after the other, the way the owner will on
 * Dec 14-20, 2026:
 *
 * - Dec 14: close the pilot (#179).
 * - Dec 15: rollover part 1, preview, execute, verify (#180).
 * - Dec 16: reminder consent for the official campaign (#181).
 * - Dec 17: rollover part 2, groups: plan, review, execute, verify (#182).
 * - Dec 20: Day 1 of the official Marathon.
 *
 * The clock is the `jlife_challenges_now` filter of the base test case, removed
 * in tear_down(). Nothing here changes the code under test.
 *
 * @package jlife-challenges
 */

// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.WP.AlternativeFunctions -- tests read the custom tables and the temporary plan files.

/**
 * End-to-end rehearsal tests.
 */
class Test_Rehearsal_Dec extends Jlife_Challenges_TestCase {

	/**
	 * The pilot (Dec 5-13, 2026).
	 *
	 * @var int
	 */
	private $pilot = 0;

	/**
	 * The official Marathon (Dec 20, 2026 - Apr 2, 2027).
	 *
	 * @var int
	 */
	private $official = 0;

	/**
	 * People by label: ann, bao, cam, dung, eli, fay.
	 *
	 * @var int[]
	 */
	private $p = array();

	/**
	 * Pilot groups: key => { id, invite_code }.
	 *
	 * @var array[]
	 */
	private $pg = array();

	/**
	 * The Bible each person chose, by label (empty when jlife-scripture is not loaded).
	 *
	 * @var string[]
	 */
	private $bible = array();

	/**
	 * Plan file for this test.
	 *
	 * @var string
	 */
	private $plan = '';

	/**
	 * The consent step and the Bible picker need the auth and scripture plugins.
	 */
	public static function wpSetUpBeforeClass() {
		parent::wpSetUpBeforeClass();
		if ( ! function_exists( 'jlife_auth_record_consent' ) ) {
			require_once dirname( __DIR__, 2 ) . '/jlife-auth/jlife-auth.php';
		}
	}

	/**
	 * The pilot week, as the owner will find it on Dec 14: six people, two
	 * groups, entries, shares, points and reminder choices. The base fixture's
	 * campaign is set aside (draft) so it plays no part.
	 *
	 * - ann (English, adult) starts "Morning Readers", opts in to reminders, picks a Bible.
	 * - bao (Vietnamese, adult) joins Morning Readers, moves to "Evening Group", opts out, picks a Bible.
	 * - cam (English, adult) is in Evening Group and never chooses a reminder.
	 * - dung (Vietnamese, minor) is in Morning Readers and writes without sharing.
	 * - eli (English, adult) reads solo.
	 * - fay (English, adult) only said "I'll start Dec 20" and never joined the pilot.
	 */
	public function set_up() {
		parent::set_up();
		global $wpdb;
		$wpdb->update( jlife_challenges_table( 'campaigns' ), array( 'status' => 'draft' ), array( 'id' => $this->campaign ) );
		$org = $this->u['organizer'];

		$this->set_now( '2026-12-01 18:00:00' );
		$this->pilot    = (int) jlife_challenges_create_pilot_campaign( $org )['campaign_id'];
		$this->official = (int) jlife_challenges_create_official_campaign( $org )['campaign_id'];
		$this->assertGreaterThan( 0, $this->pilot );
		$this->assertGreaterThan( 0, $this->official );

		foreach ( array( 'ann', 'bao', 'cam', 'dung', 'eli', 'fay' ) as $who ) {
			$this->p[ $who ] = self::factory()->user->create(
				array(
					'role'         => 'subscriber',
					'user_email'   => $who . '@rehearsal.test',
					'display_name' => 'Rehearsal ' . ucfirst( $who ),
				)
			);
			update_user_meta( $this->p[ $who ], 'locale', in_array( $who, array( 'bao', 'dung' ), true ) ? 'vi' : 'en_US' );
		}

		// Sign-up: ann starts a group, an organizer makes the other, bao moves from one to the other.
		$this->assertIsInt( jlife_challenges_enroll_solo( $this->p['ann'], $this->pilot, 'adult' ) );
		$started = jlife_challenges_start_group( $this->p['ann'], 'Morning Readers' );
		$this->assertIsArray( $started );
		$this->pg['morning'] = array(
			'id'          => (int) $started['id'],
			'invite_code' => (string) $started['invite_code'],
		);
		$made                = jlife_challenges_create_group( $org, $this->pilot, 'Evening Group' );
		$this->pg['evening'] = array(
			'id'          => (int) $made['id'],
			'invite_code' => (string) $made['invite_code'],
		);
		$this->assertIsInt( jlife_challenges_join_with_code( $this->p['bao'], $this->pg['morning']['invite_code'], 'adult' ) );
		$this->assertIsInt( jlife_challenges_join_with_code( $this->p['dung'], $this->pg['morning']['invite_code'], 'minor' ) );
		$this->assertTrue( jlife_challenges_leave_group( $this->p['bao'], $this->pg['morning']['id'] ) );
		$this->assertIsInt( jlife_challenges_join_with_code( $this->p['bao'], $this->pg['evening']['invite_code'], 'adult' ) );
		$this->assertIsInt( jlife_challenges_join_with_code( $this->p['cam'], $this->pg['evening']['invite_code'], 'adult' ) );
		$this->assertIsInt( jlife_challenges_enroll_solo( $this->p['eli'], $this->pilot, 'adult' ) );
		jlife_challenges_set_waiting_for_official( $this->p['fay'], 'adult' );
		foreach ( $this->p as $id ) {
			jlife_auth_record_consent( $id ); // The account's record, and each pilot enrollment's.
		}

		// Explicit Bible choices (only where the Scripture plugin is loaded).
		$keys = $this->bible_keys();
		if ( $keys ) {
			$this->bible = array(
				'ann' => $keys[0],
				'bao' => $keys[ count( $keys ) - 1 ],
			);
			foreach ( $this->bible as $who => $key ) {
				$this->assertTrue( jlife_challenges_save_bible_choice( $this->p[ $who ], array( 'primary' => $key ) ), "{$who} picks a Bible" );
			}
		}

		// Pilot week. Dec 6: ann opts in, bao opts out; four people write, three share.
		$this->set_now( '2026-12-06 18:00:00' );
		$this->assertTrue( jlife_challenges_set_reminder( $this->p['ann'], $this->p['ann'], $this->pilot, true ) );
		$this->assertTrue( jlife_challenges_set_reminder( $this->p['bao'], $this->p['bao'], $this->pilot, false ) );
		$this->write_entry( 'ann', $this->pilot, 'pilot-ann-1', array( $this->pg['morning']['id'] ) );
		$this->write_entry( 'bao', $this->pilot, 'pilot-bao-1', array( $this->pg['evening']['id'] ) );
		$this->write_entry( 'dung', $this->pilot, 'pilot-dung-1', array() );
		$this->write_entry( 'eli', $this->pilot, 'pilot-eli-1', array() );
		// Dec 7: two more entries.
		$this->set_now( '2026-12-07 18:00:00' );
		$this->write_entry( 'ann', $this->pilot, 'pilot-ann-2', array( $this->pg['morning']['id'] ) );
		$this->write_entry( 'cam', $this->pilot, 'pilot-cam-1', array( $this->pg['evening']['id'] ) );
		// Dec 8, 06:00 Pacific: the reminder queue holds ann's email for the day.
		$this->set_now( '2026-12-08 14:00:00' );
		$this->assertSame( 1, jlife_challenges_reminders_enqueue( jlife_challenges_get_campaign( $this->pilot ), $this->now ) );

		$this->set_now( '2026-12-14 18:00:00' );
		$this->plan = trailingslashit( get_temp_dir() ) . 'jlife-rehearsal-' . wp_generate_password( 8, false ) . '.csv';
	}

	/**
	 * Remove the plan files.
	 */
	public function tear_down() {
		foreach ( array( $this->plan, $this->plan . '.codes.csv' ) as $f ) {
			if ( '' !== $f && file_exists( $f ) ) {
				unlink( $f );
			}
		}
		parent::tear_down();
	}

	/**
	 * Every Bible key a person may choose, in registry order (none without the Scripture plugin).
	 *
	 * @return string[]
	 */
	private function bible_keys() {
		if ( ! jlife_challenges_bible_choice_available() ) {
			return array();
		}
		$keys = array();
		foreach ( jlife_scripture_selectable_versions() as $versions ) {
			foreach ( array_keys( $versions ) as $key ) {
				$keys[] = (string) $key;
			}
		}
		return array_values( array_unique( $keys ) );
	}

	/**
	 * One complete entry for a person today, and the shares they tick.
	 *
	 * @param string $who      Label.
	 * @param int    $campaign Campaign.
	 * @param string $tag      Text marker.
	 * @param int[]  $groups   Groups to share to.
	 * @return array The save result.
	 */
	private function write_entry( $who, $campaign, $tag, $groups ) {
		$r = jlife_challenges_save_and_share( $this->p[ $who ], $campaign, null, $this->soap( $tag ), $groups );
		$this->assertIsArray( $r, "{$who} saves {$tag}" );
		$this->assertSame( 1, $r['points'], "{$who}: {$tag} earns the point" );
		$this->assertSame( $groups ? 'shared' : 'none', $r['share']['status'], "{$who}: {$tag} share" );
		return $r;
	}

	/**
	 * Count rows in an engine table for a campaign.
	 *
	 * @param string $table       Table suffix.
	 * @param int    $campaign_id Campaign.
	 * @return int
	 */
	private function rows( $table, $campaign_id ) {
		return jlife_challenges_close_count( $table, $campaign_id );
	}

	/**
	 * Every row count of both campaigns, and the groups map, for before/after comparisons.
	 *
	 * @return array
	 */
	private function snapshot() {
		$out = array();
		foreach ( array(
			'pilot'    => $this->pilot,
			'official' => $this->official,
		) as $name => $id ) {
			foreach ( array( 'enrollments', 'groups', 'group_members', 'soap_entries', 'score_ledger', 'group_shares', 'reminder_prefs', 'reminder_sends' ) as $t ) {
				$out[ $name ][ $t ] = $this->rows( $t, $id );
			}
		}
		$out['groups_map'] = jlife_challenges_rollover_groups_map( $this->official );
		return $out;
	}

	/**
	 * The number of active enrollment rows a person has in a campaign (never more than one).
	 *
	 * @param int $campaign_id Campaign.
	 * @param int $user_id     Participant.
	 * @return int
	 */
	private function enrollment_count( $campaign_id, $user_id ) {
		global $wpdb;
		return (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . jlife_challenges_table( 'enrollments' ) . " WHERE campaign_id = %d AND user_id = %d AND status = 'active'", $campaign_id, $user_id ) );
	}

	/**
	 * The official campaign's state that must be the same however the run went,
	 * by person label and group name (never by ID).
	 *
	 * @return array
	 */
	private function end_state() {
		global $wpdb;
		$labels = array_flip( $this->p );
		$out    = array(
			'enrolled'  => array(),
			'reminders' => array(),
			'groups'    => array(),
			'data'      => array(
				'soap_entries' => $this->rows( 'soap_entries', $this->official ),
				'score_ledger' => $this->rows( 'score_ledger', $this->official ),
				'group_shares' => $this->rows( 'group_shares', $this->official ),
			),
		);
		foreach ( $this->p as $label => $id ) {
			$pref = jlife_challenges_reminder_pref( $id, $this->official );
			$out['enrolled'][ $label ]  = $this->enrollment_count( $this->official, $id );
			$out['reminders'][ $label ] = null === $pref ? 'none' : ( jlife_challenges_reminder_is_on( $pref ) ? 'on' : 'off' );
		}
		$groups = (array) $wpdb->get_results( $wpdb->prepare( 'SELECT id, name FROM ' . jlife_challenges_table( 'groups' ) . " WHERE campaign_id = %d AND status = 'active'", $this->official ), ARRAY_A );
		foreach ( $groups as $g ) {
			$members = (array) $wpdb->get_results( $wpdb->prepare( 'SELECT user_id, role FROM ' . jlife_challenges_table( 'group_members' ) . " WHERE group_id = %d AND status = 'active'", (int) $g['id'] ), ARRAY_A );
			$list    = array();
			foreach ( $members as $m ) {
				$list[] = $labels[ (int) $m['user_id'] ] . ':' . $m['role'];
			}
			sort( $list );
			$out['groups'][ $g['name'] ] = $list;
		}
		ksort( $out['groups'] );
		return $out;
	}

	/**
	 * The end state of an uninterrupted run, written out by hand.
	 *
	 * @return array
	 */
	private function expected_end_state() {
		return array(
			'enrolled'  => array(
				'ann'  => 1,
				'bao'  => 1,
				'cam'  => 1,
				'dung' => 1,
				'eli'  => 1,
				'fay'  => 1,
			),
			'reminders' => array(
				'ann'  => 'on',
				'bao'  => 'off',
				'cam'  => 'none',
				'dung' => 'none',
				'eli'  => 'none',
				'fay'  => 'on',
			),
			'groups'    => array(
				'Evening Readers' => array( 'bao:member', 'cam:member' ),
				'Morning Readers' => array( 'ann:starter', 'dung:member' ),
			),
			'data'      => array(
				'soap_entries' => 0,
				'score_ledger' => 0,
				'group_shares' => 0,
			),
		);
	}

	/**
	 * Dec 14: close the pilot, as `wp jlife campaign close` does.
	 *
	 * @return array
	 */
	private function close_pilot() {
		$r = jlife_challenges_close_campaign( $this->u['organizer'], $this->pilot );
		$this->assertIsArray( $r );
		return $r;
	}

	/**
	 * Dec 15: rollover part 1, preview then execute then verify.
	 *
	 * @return array { preview, executed, verified }
	 */
	private function rollover_part_one() {
		$org     = $this->u['organizer'];
		$preview = jlife_challenges_rollover_preview( $org, $this->pilot, $this->official );
		$this->assertIsArray( $preview );
		$executed = jlife_challenges_rollover_execute( $org, $this->pilot, $this->official );
		$this->assertIsArray( $executed );
		$verified = jlife_challenges_rollover_verify( $org, $this->pilot, $this->official );
		$this->assertIsArray( $verified );
		return array(
			'preview'  => $preview,
			'executed' => $executed,
			'verified' => $verified,
		);
	}

	/**
	 * Dec 16: the official reminder choices people make once they are in. ann
	 * and fay opt in, bao opts out, nobody else chooses.
	 */
	private function consent_step() {
		$this->assertTrue( jlife_challenges_set_reminder( $this->p['ann'], $this->p['ann'], $this->official, true ) );
		$this->assertTrue( jlife_challenges_set_reminder( $this->p['bao'], $this->p['bao'], $this->official, false ) );
		$this->assertTrue( jlife_challenges_set_reminder( $this->p['fay'], $this->p['fay'], $this->official, true ) );
	}

	/**
	 * Dec 17: write the groups plan, and the organizer's review edit (a group is renamed).
	 *
	 * @return array The plan's counts.
	 */
	private function write_plan() {
		$counts = jlife_challenges_rollover_groups_write_plan( $this->u['organizer'], $this->pilot, $this->official, $this->plan );
		$this->assertIsArray( $counts );
		$text = (string) file_get_contents( $this->plan );
		$this->assertStringContainsString( 'Evening Group', $text );
		file_put_contents( $this->plan, str_replace( 'Evening Group', 'Evening Readers', $text ) );
		return $counts;
	}

	/**
	 * Dec 17: rollover part 2, validate then execute then verify.
	 *
	 * @return array { validated, executed, verified }
	 */
	private function rollover_part_two() {
		$org       = $this->u['organizer'];
		$validated = jlife_challenges_rollover_groups_validate( $org, $this->pilot, $this->official, $this->plan );
		$this->assertIsArray( $validated );
		$executed = jlife_challenges_rollover_groups_execute( $org, $this->pilot, $this->official, $this->plan );
		$this->assertIsArray( $executed );
		$verified = jlife_challenges_rollover_groups_verify( $org, $this->pilot, $this->official, $this->plan );
		$this->assertIsArray( $verified );
		return array(
			'validated' => $validated,
			'executed'  => $executed,
			'verified'  => $verified,
		);
	}

	/**
	 * Dec 14 to Dec 17, every step once.
	 */
	private function run_all() {
		$this->set_now( '2026-12-14 18:00:00' );
		$this->close_pilot();
		$this->set_now( '2026-12-15 18:00:00' );
		$this->rollover_part_one();
		$this->set_now( '2026-12-16 18:00:00' );
		$this->consent_step();
		$this->set_now( '2026-12-17 18:00:00' );
		$this->write_plan();
		$this->rollover_part_two();
	}

	/**
	 * The new official groups by name.
	 *
	 * @return int[] Name => group ID.
	 */
	private function official_groups() {
		global $wpdb;
		$out  = array();
		$rows = (array) $wpdb->get_results( $wpdb->prepare( 'SELECT id, name FROM ' . jlife_challenges_table( 'groups' ) . " WHERE campaign_id = %d AND status = 'active'", $this->official ), ARRAY_A );
		foreach ( $rows as $r ) {
			$out[ $r['name'] ] = (int) $r['id'];
		}
		return $out;
	}

	/**
	 * The lines of the new-codes file (the first is the header).
	 *
	 * @return array[]
	 */
	private function code_lines() {
		$lines = array();
		$h     = fopen( $this->plan . '.codes.csv', 'r' );
		$this->assertNotFalse( $h );
		while ( false !== ( $line = fgetcsv( $h, 0, ',', '"', '\\' ) ) ) { // phpcs:ignore Generic.CodeAnalysis.AssignmentInCondition.FoundInWhileCondition
			$line[0] = (string) preg_replace( '/^\xEF\xBB\xBF/', '', (string) $line[0] );
			$lines[] = $line;
		}
		fclose( $h );
		return $lines;
	}

	/**
	 * Point 1: the pilot is seeded as described (Dec 5-13, two groups, six people).
	 */
	public function test_pilot_is_seeded() {
		$c = jlife_challenges_get_campaign( $this->pilot );
		$this->assertSame( '2026-12-05', $c['start_date'] );
		$this->assertSame( '2026-12-13', $c['end_date'] );
		$this->assertSame( 'active', $c['status'] );
		$this->assertSame( 5, jlife_challenges_close_count( 'enrollments', $this->pilot, 'active' ), 'fay never joined the pilot' );
		$this->assertSame( 2, jlife_challenges_close_count( 'groups', $this->pilot, 'active' ) );
		$this->assertSame( 4, jlife_challenges_close_count( 'group_members', $this->pilot, 'active' ), 'ann, dung, bao (moved) and cam' );
		$this->assertSame( 'left', jlife_challenges_get_membership( $this->pg['morning']['id'], $this->p['bao'] )['status'], 'bao left the first group' );
		$this->assertSame( 'starter', jlife_challenges_get_membership( $this->pg['morning']['id'], $this->p['ann'] )['role'] );
		$this->assertSame( 6, $this->rows( 'soap_entries', $this->pilot ) );
		$this->assertSame( 6, $this->rows( 'score_ledger', $this->pilot ) );
		$this->assertSame( 4, $this->rows( 'group_shares', $this->pilot ) );
		$this->assertSame( 2, $this->rows( 'reminder_prefs', $this->pilot ), 'one opt-in, one opt-out' );
		$this->assertSame( 1, jlife_challenges_close_count( 'reminder_sends', $this->pilot, 'queued' ) );
		$this->assertSame( array( $this->p['fay'] ), jlife_challenges_waiting_for_official_ids() );
		$this->assertSame( 'vi', get_user_meta( $this->p['bao'], 'locale', true ) );
		$this->assertSame( 'en_US', get_user_meta( $this->p['ann'], 'locale', true ) );
		foreach ( $this->bible as $who => $key ) {
			$this->assertSame( $key, jlife_scripture_user_choice( $this->p[ $who ] )['primary'], "{$who}'s Bible" );
		}
		$this->assertSame( 0, $this->rows( 'enrollments', $this->official ), 'nobody is in the official campaign yet' );
	}

	/**
	 * Point 2: Dec 14 the pilot closes; Dec 15 rollover part 1 previews, executes and verifies.
	 */
	public function test_close_and_rollover_part_one() {
		$this->set_now( '2026-12-14 18:00:00' );
		$closed = $this->close_pilot();
		$this->assertTrue( $closed['changed'] );
		$this->assertSame( 1, $closed['reminders_cancelled'], 'the queued reminder is cancelled' );
		$this->assertSame( 'closed', jlife_challenges_get_campaign( $this->pilot )['status'] );
		$this->assertSame( 0, jlife_challenges_close_count( 'reminder_sends', $this->pilot, 'queued' ) );
		$this->assertSame( 6, $this->rows( 'score_ledger', $this->pilot ), 'closing deletes nothing' );
		$this->assertWPError( jlife_challenges_save_and_share( $this->p['ann'], $this->pilot, null, $this->soap( 'late' ), array() ), 'a closed pilot takes no entry' );

		$this->set_now( '2026-12-15 18:00:00' );
		$before = $this->snapshot();
		$r      = $this->rollover_part_one();
		$this->assertSame( 5, $r['preview']['source_enrollments'] );
		$this->assertSame( 1, $r['preview']['waiting_flags'] );
		$this->assertSame( 6, $r['preview']['to_enroll'], 'five from the pilot and fay' );
		$this->assertSame( 0, $r['preview']['already_enrolled'] );
		$this->assertSame( 6, $r['executed']['enrolled_now'] );
		$this->assertSame( 0, $r['executed']['source_changed_by_run'] );
		$this->assertTrue( (bool) $r['verified']['ok'] );
		$this->assertSame( 6, $r['verified']['enrolled_once'] );
		$this->assertSame( 0, $r['verified']['missing'] );
		$this->assertSame( 0, $r['verified']['duplicate_enrollments'] );
		$after = $this->snapshot();
		$this->assertSame( $before['pilot'], $after['pilot'], 'the pilot is untouched' );
		$this->assertSame( 6, $after['official']['enrollments'] );
		foreach ( array( 'groups', 'group_members', 'soap_entries', 'score_ledger', 'group_shares', 'reminder_prefs', 'reminder_sends' ) as $t ) {
			$this->assertSame( 0, $after['official'][ $t ], "no official {$t} yet" );
		}
	}

	/**
	 * Point 3: reminder consent for the official campaign, then rollover part 2
	 * (plan, review, execute, verify).
	 */
	public function test_reminder_consent_and_groups() {
		$this->set_now( '2026-12-14 18:00:00' );
		$this->close_pilot();
		$this->set_now( '2026-12-15 18:00:00' );
		$this->rollover_part_one();

		// Nothing is carried: no official choice for anybody, and no recipient.
		$this->set_now( '2026-12-16 18:00:00' );
		$this->assertSame( 0, $this->rows( 'reminder_prefs', $this->official ) );
		$this->assertSame( array(), jlife_challenges_reminder_recipients( $this->official ) );
		$pilot_prefs = $this->rows( 'reminder_prefs', $this->pilot );
		$this->consent_step();
		$this->assertSame( 3, $this->rows( 'reminder_prefs', $this->official ), 'only the three who chose' );
		$this->assertSame( array( $this->p['ann'], $this->p['fay'] ), jlife_challenges_reminder_recipients( $this->official ), 'opt-ins with consent; the opt-out is not a recipient' );
		$this->assertSame( $pilot_prefs, $this->rows( 'reminder_prefs', $this->pilot ), 'the pilot rows are untouched' );

		// Groups: plan, the organizer's review edit, validate, execute, verify.
		$this->set_now( '2026-12-17 18:00:00' );
		$plan = $this->write_plan();
		$this->assertSame( 2, $plan['groups'] );
		$this->assertSame( 4, $plan['rows'] );
		$this->assertSame( 1, $plan['starters'] );
		$this->assertSame( 1, $plan['minor_rows'] );
		$this->assertSame( 0, $plan['not_enrolled_rows'] );
		$r = $this->rollover_part_two();
		$this->assertTrue( $r['validated']['ok'] );
		$this->assertSame( 4, $r['validated']['memberships_to_add'] );
		$this->assertSame( 2, $r['executed']['groups_created'] );
		$this->assertSame( 4, $r['executed']['memberships_added'] );
		$this->assertSame( 0, $r['executed']['membership_errors'] );
		$this->assertSame( 0, $r['executed']['codes_not_saved'] );
		$this->assertTrue( (bool) $r['verified']['ok'] );
		$this->assertSame( 0, $r['verified']['extra_members'] );
		$this->assertSame( 0, $r['verified']['unplanned_groups'] );
		$this->assertSame( $this->expected_end_state(), $this->end_state() );
		$this->assertCount( 3, $this->code_lines(), 'a header and one new code per group' );
	}

	/**
	 * Point 4: Dec 20, Day 1. Everyone carried over is on Day 1 with 0 of 104,
	 * then 1 of 104 after a valid entry; nothing of the pilot shows; the groups
	 * are the official ones; language and Bible are kept; the reminder queue
	 * holds one row per opted-in person.
	 */
	public function test_dec_20_is_a_clean_day_one() {
		$this->run_all();
		$this->assertSame( $this->expected_end_state(), $this->end_state() );
		$pilot_before = $this->snapshot()['pilot'];

		$this->set_now( '2026-12-20 16:00:00' ); // 08:00 Pacific on Dec 20.
		$official = jlife_challenges_get_campaign( $this->official );
		$this->assertSame( 1, jlife_challenges_current_day( $official ), 'Day 1' );
		$this->assertSame( 104, jlife_challenges_day_count( $official ) );
		foreach ( $this->p as $who => $id ) {
			$pc = jlife_challenges_participant_campaign( $id );
			$this->assertSame( $this->official, (int) $pc['campaign']['id'], "{$who}: the official campaign is theirs" );
			$this->assertSame( $this->official, jlife_challenges_reminder_campaign_id( $id ) );
			$progress = jlife_challenges_progress( $id, $id, $this->official );
			$this->assertSame( 0, $progress['points'], "{$who}: 0 points" );
			$this->assertSame( 104, $progress['day_count'], "{$who}: of 104" );
			$this->assertSame( array(), $progress['days'], "{$who}: no pilot day shows" );
		}

		// Nothing of the pilot is in the official campaign.
		foreach ( array( 'soap_entries', 'score_ledger', 'group_shares' ) as $t ) {
			$this->assertSame( 0, $this->rows( $t, $this->official ), "no official {$t}" );
		}
		$groups = $this->official_groups();
		$this->assertSame( array( 'Evening Readers', 'Morning Readers' ), array_keys( $groups ) );
		foreach ( $this->pg as $g ) {
			$this->assertNotContains( $g['id'], $groups, 'the official groups are new' );
		}
		$this->assertSame( array(), jlife_challenges_group_feed( $this->p['ann'], $groups['Morning Readers'] ), 'no pilot share in the official feed' );
		$this->assertSame( array(), jlife_challenges_group_feed( $this->p['bao'], $groups['Evening Readers'] ) );
		$this->assertSame( array(), jlife_challenges_my_shares( $this->p['ann'], $this->official ) );
		$this->assertNotEmpty( jlife_challenges_my_shares( $this->p['ann'], $this->pilot ), 'the pilot shares stay in the pilot' );

		// Old pilot codes never open an official group; the new codes do.
		$late = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		foreach ( $this->pg as $key => $g ) {
			$this->assertNull( jlife_challenges_joinable_group_for_invite( $g['invite_code'] ), "pilot {$key} code" );
			$this->assertWPError( jlife_challenges_join_with_code( $late, $g['invite_code'], 'adult' ), "pilot {$key} code does not join" );
		}
		$this->assertNull( jlife_challenges_get_enrollment( $this->official, $late ), 'the pilot codes enrolled nobody' );
		$codes = $this->code_lines();
		foreach ( array_slice( $codes, 1 ) as $line ) {
			$row = array_combine( $codes[0], $line );
			$this->assertSame( $groups[ $row['group_name'] ], (int) jlife_challenges_joinable_group_for_invite( $row['invite_code'] )['id'], 'a new code opens its official group' );
		}

		// Language and Bible choices are kept.
		$this->assertSame( 'vi', jlife_challenges_account_language( $this->p['bao'] ) );
		$this->assertSame( 'vi', jlife_challenges_account_language( $this->p['dung'] ) );
		$this->assertSame( 'en_US', jlife_challenges_account_language( $this->p['ann'] ) );
		foreach ( $this->bible as $who => $key ) {
			$this->assertSame( $key, jlife_challenges_bible_state( $this->p[ $who ] )['bible_version'], "{$who}'s Bible is kept" );
		}

		// The reminder queue: one row per opted-in person, none for the opt-out or for people who never chose.
		$active = jlife_challenges_reminders_active_campaigns();
		$this->assertSame( array( $this->official ), array_map( static fn( $c ) => (int) $c['id'], $active ), 'only the official campaign is scheduled' );
		$this->assertSame( 2, jlife_challenges_reminders_enqueue( $official, $this->now ) );
		$this->assertSame( 0, jlife_challenges_reminders_enqueue( $official, $this->now ), 'a second tick adds none' );
		global $wpdb;
		$queued = array_map( 'intval', (array) $wpdb->get_col( $wpdb->prepare( 'SELECT user_id FROM ' . jlife_challenges_table( 'reminder_sends' ) . ' WHERE campaign_id = %d ORDER BY user_id', $this->official ) ) );
		$this->assertSame( array( $this->p['ann'], $this->p['fay'] ), $queued );
		$this->assertSame( 0, jlife_challenges_close_count( 'reminder_sends', $this->pilot, 'queued' ), 'nothing queued for the closed pilot' );

		// The first official entries: 1 of 104, in the official campaign only.
		$r = $this->write_entry( 'eli', $this->official, 'official-eli-1', array() );
		$this->assertSame( jlife_challenges_day_key( 1 ), $r['day_key'], 'Day 1' );
		$progress = jlife_challenges_progress( $this->p['eli'], $this->p['eli'], $this->official );
		$this->assertSame( 1, $progress['points'], '1 of 104' );
		$this->assertSame( 104, $progress['day_count'] );
		$this->write_entry( 'ann', $this->official, 'official-ann-1', array( $groups['Morning Readers'] ) );
		$feed = jlife_challenges_group_feed( $this->p['dung'], $groups['Morning Readers'] );
		$this->assertCount( 1, $feed, 'only the official share is in the official feed' );
		$this->assertStringContainsString( 'official-ann-1', $feed[0]['body'] );
		$this->assertSame( 0, jlife_challenges_progress( $this->p['cam'], $this->p['cam'], $this->official )['points'], 'others are still at 0' );
		$this->assertSame( $pilot_before, $this->snapshot()['pilot'], 'the pilot did not change' );

		// After launch the rollover refuses to run again and changes nothing.
		$frozen = $this->snapshot();
		$this->assertWPError( jlife_challenges_rollover_execute( $this->u['organizer'], $this->pilot, $this->official ) );
		$this->assertWPError( jlife_challenges_rollover_groups_execute( $this->u['organizer'], $this->pilot, $this->official, $this->plan ) );
		$this->assertSame( $frozen, $this->snapshot() );
	}

	/**
	 * Point 5: every step run a second time changes no count.
	 */
	public function test_every_step_twice_changes_nothing() {
		$this->run_all();
		$this->set_now( '2026-12-19 18:00:00' );
		$before = $this->snapshot();
		$codes  = $this->code_lines();
		$org    = $this->u['organizer'];

		$closed = $this->close_pilot();
		$this->assertFalse( $closed['changed'], 'a closed pilot is left as it is' );
		$this->assertSame( 0, $closed['reminders_cancelled'] );

		$one = $this->rollover_part_one();
		$this->assertSame( 0, $one['preview']['to_enroll'] );
		$this->assertSame( 6, $one['preview']['already_enrolled'] );
		$this->assertSame( 0, $one['executed']['enrolled_now'] );
		$this->assertSame( 0, $one['executed']['source_changed_by_run'] );
		$this->assertTrue( (bool) $one['verified']['ok'] );
		$this->assertSame( 0, $one['verified']['duplicate_enrollments'] );

		$this->consent_step();

		$two = $this->rollover_part_two();
		$this->assertTrue( $two['validated']['ok'] );
		$this->assertSame( 0, $two['executed']['groups_created'] );
		$this->assertSame( 2, $two['executed']['groups_existing'] );
		$this->assertSame( 0, $two['executed']['memberships_added'] );
		$this->assertSame( 4, $two['executed']['memberships_existing'] );
		$this->assertTrue( (bool) $two['verified']['ok'] );

		$this->assertSame( $before, $this->snapshot(), 'no count changed' );
		$this->assertSame( $codes, $this->code_lines(), 'no new invite code was issued' );
		$this->assertSame( $this->expected_end_state(), $this->end_state() );
		$this->assertSame( array( $this->p['ann'], $this->p['fay'] ), jlife_challenges_reminder_recipients( $this->official ) );
		$this->assertTrue( jlife_challenges_rollover_verify( $org, $this->pilot, $this->official )['ok'] );
	}

	/**
	 * Point 6: a run that stops part-way (in rollover part 1, then in part 2) and
	 * is rerun ends in the same state as an uninterrupted run. The seams are the
	 * actions the code already fires before each write; the callbacks throw.
	 */
	public function test_interrupted_rollover_ends_like_a_clean_run() {
		$org = $this->u['organizer'];
		$this->set_now( '2026-12-14 18:00:00' );
		$this->close_pilot();
		$pilot_before = $this->snapshot()['pilot'];

		// Part 1 stops on its third enrollment: two are written.
		$this->set_now( '2026-12-15 18:00:00' );
		$calls = 0;
		$fail  = static function () use ( &$calls ) {
			++$calls;
			if ( 3 === $calls ) {
				throw new RuntimeException( 'simulated failure' );
			}
		};
		add_action( 'jlife_challenges_rollover_enroll', $fail );
		try {
			jlife_challenges_rollover_execute( $org, $this->pilot, $this->official );
			$this->fail( 'the simulated failure should stop part 1' );
		} catch ( RuntimeException $e ) {
			$this->assertSame( 'simulated failure', $e->getMessage() );
		}
		remove_action( 'jlife_challenges_rollover_enroll', $fail );
		$this->assertSame( 2, $this->rows( 'enrollments', $this->official ), 'two written before the failure' );
		$half = jlife_challenges_rollover_verify( $org, $this->pilot, $this->official );
		$this->assertFalse( (bool) $half['ok'], 'verify shows it is not finished' );
		$this->assertSame( 4, $half['missing'] );
		$again = jlife_challenges_rollover_execute( $org, $this->pilot, $this->official );
		$this->assertSame( 4, $again['enrolled_now'], 'the rerun finishes the other four' );
		$this->assertTrue( (bool) jlife_challenges_rollover_verify( $org, $this->pilot, $this->official )['ok'] );
		$this->assertSame( 6, $this->rows( 'enrollments', $this->official ), 'no duplicate enrollment' );

		$this->set_now( '2026-12-16 18:00:00' );
		$this->consent_step();

		// Part 2 stops on its fifth step: the second group is made, its first member is not.
		$this->set_now( '2026-12-17 18:00:00' );
		$this->write_plan();
		$calls = 0;
		$fail  = static function () use ( &$calls ) {
			++$calls;
			if ( 5 === $calls ) {
				throw new RuntimeException( 'simulated failure' );
			}
		};
		add_action( 'jlife_challenges_rollover_groups_step', $fail );
		try {
			jlife_challenges_rollover_groups_execute( $org, $this->pilot, $this->official, $this->plan );
			$this->fail( 'the simulated failure should stop part 2' );
		} catch ( RuntimeException $e ) {
			$this->assertSame( 'simulated failure', $e->getMessage() );
		}
		remove_action( 'jlife_challenges_rollover_groups_step', $fail );
		$this->assertFalse( (bool) jlife_challenges_rollover_groups_verify( $org, $this->pilot, $this->official, $this->plan )['ok'], 'verify shows it is not finished' );
		$r = $this->rollover_part_two();
		$this->assertSame( 0, $r['executed']['membership_errors'] );
		$this->assertTrue( (bool) $r['verified']['ok'] );
		$this->assertCount( 2, $this->official_groups(), 'no duplicate group' );
		$this->assertSame( 4, $this->rows( 'group_members', $this->official ) );
		$this->assertCount( 3, $this->code_lines(), 'one code per group, none twice' );

		$this->assertSame( $this->expected_end_state(), $this->end_state(), 'the same end state as an uninterrupted run' );
		$this->assertSame( $pilot_before, $this->snapshot()['pilot'], 'the pilot did not change' );
		$this->assertSame( array( $this->p['ann'], $this->p['fay'] ), jlife_challenges_reminder_recipients( $this->official ) );
	}
}
