<?php
/**
 * Rollover, part 2 (#182, docs/challenges-engine-design.md §3d): prepare the
 * official groups from the pilot's, through a plan file the organizer reviews
 * and edits, then create them with new invite codes. Written before the code.
 *
 * @package jlife-challenges
 */

/**
 * Group rollover tests.
 */
class Test_Rollover_Groups extends JLife_Challenges_TestCase {

	/**
	 * The pilot.
	 *
	 * @var int
	 */
	private $pilot = 0;

	/**
	 * The official Marathon.
	 *
	 * @var int
	 */
	private $official = 0;

	/**
	 * People by role in the story.
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
	 * Plan file for this test.
	 *
	 * @var string
	 */
	private $plan = '';

	/**
	 * The Dec 2026 story up to rollover part 1, run on Dec 15:
	 * - "Friday Friends", started by an adult, with an adult and a minor;
	 * - "Youth Group" (organizer-made) with the multi-group adult, a minor and someone later removed from the Marathon;
	 * - "Evening" (organizer-made) with the multi-group adult and another adult;
	 * - a disabled pilot group, a solo reader, and someone who left a group.
	 */
	public function set_up() {
		parent::set_up();
		global $wpdb;
		$wpdb->update( jlife_challenges_table( 'campaigns' ), array( 'status' => 'draft' ), array( 'id' => $this->campaign ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$org = $this->u['organizer'];

		$this->set_now( '2026-11-29 04:00:00' ); // Nov 28, 8:00 PM Pacific.
		$this->pilot    = (int) jlife_challenges_create_pilot_campaign( $org )['campaign_id'];
		$this->official = (int) jlife_challenges_create_official_campaign( $org )['campaign_id'];
		foreach ( array( 'starter', 'multi', 'youth1', 'youth2', 'removed', 'evening', 'solo', 'leaver' ) as $who ) {
			$this->p[ $who ] = self::factory()->user->create(
				array(
					'role'         => 'subscriber',
					'display_name' => 'Review ' . ucfirst( $who ),
				)
			);
		}
		$this->assertIsInt( jlife_challenges_enroll_solo( $this->p['starter'], $this->pilot, 'adult' ) );
		$started = jlife_challenges_start_group( $this->p['starter'], 'Friday Friends' );
		$this->assertIsArray( $started );
		$this->pg['friday'] = array(
			'id'          => (int) $started['id'],
			'invite_code' => (string) $started['invite_code'],
		);

		$made = array(
			'youth'    => 'Youth Group',
			'evening'  => 'Evening',
			'disabled' => 'Old Group',
		);
		foreach ( $made as $key => $name ) {
			$g                = jlife_challenges_create_group( $org, $this->pilot, $name );
			$this->pg[ $key ] = array(
				'id'          => (int) $g['id'],
				'invite_code' => (string) $g['invite_code'],
			);
		}
		$joins = array(
			array( 'multi', 'friday', 'adult' ),
			array( 'youth1', 'friday', 'minor' ),
			array( 'multi', 'youth', 'adult' ),
			array( 'youth2', 'youth', 'minor' ),
			array( 'removed', 'youth', 'adult' ),
			array( 'multi', 'evening', 'adult' ),
			array( 'evening', 'evening', 'adult' ),
			array( 'leaver', 'evening', 'adult' ),
			array( 'evening', 'disabled', 'adult' ),
		);
		foreach ( $joins as $j ) {
			$this->assertIsInt( jlife_challenges_join_with_code( $this->p[ $j[0] ], $this->pg[ $j[1] ]['invite_code'], $j[2] ), "{$j[0]} joins {$j[1]}" );
		}
		$this->assertIsInt( jlife_challenges_enroll_solo( $this->p['solo'], $this->pilot, 'adult' ) );
		$this->assertTrue( jlife_challenges_leave_group( $this->p['leaver'], $this->pg['evening']['id'] ) );
		$this->assertTrue( jlife_challenges_disable_group( $org, $this->pg['disabled']['id'] ) );

		// Pilot week: a shared entry with a point, in Friday Friends.
		$this->set_now( '2026-12-06 18:00:00' );
		$r = jlife_challenges_save_and_share( $this->p['multi'], $this->pilot, null, $this->soap( 'pilot' ), array( $this->pg['friday']['id'] ) );
		$this->assertSame( 1, $r['points'] );

		// Dec 14 close; Dec 15 rollover part 1; one carried person is then removed from the Marathon.
		$this->set_now( '2026-12-14 18:00:00' );
		jlife_challenges_close_campaign( $org, $this->pilot );
		$this->set_now( '2026-12-15 18:00:00' );
		jlife_challenges_rollover_execute( $org, $this->pilot, $this->official );
		$this->assertTrue( jlife_challenges_remove_member( $org, $this->official, $this->p['removed'] ) );

		$this->plan = trailingslashit( get_temp_dir() ) . 'jlife-plan-' . wp_generate_password( 8, false ) . '.csv';
	}

	/**
	 * Remove the plan files.
	 */
	public function tear_down() {
		foreach ( array( $this->plan, $this->plan . '.codes.csv' ) as $f ) {
			if ( '' !== $f && file_exists( $f ) ) {
				unlink( $f ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- test temp file.
			}
		}
		parent::tear_down();
	}

	/**
	 * Write the plan as the organizer.
	 *
	 * @return array
	 */
	private function write_plan() {
		$r = jlife_challenges_rollover_groups_write_plan( $this->u['organizer'], $this->pilot, $this->official, $this->plan );
		$this->assertIsArray( $r );
		return $r;
	}

	/**
	 * The plan's lines as arrays (meta line, header, rows).
	 *
	 * @param string $file File.
	 * @return array[]
	 */
	private function read_lines( $file ) {
		$out = array();
		$h   = fopen( $file, 'r' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- test reads its temp file.
		while ( false !== ( $row = fgetcsv( $h, 0, ',', '"', '\\' ) ) ) { // phpcs:ignore Generic.CodeAnalysis.AssignmentInCondition.FoundInWhileCondition
			$out[] = $row;
		}
		fclose( $h ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		return $out;
	}

	/**
	 * Rewrite the plan, as an organizer editing it in a spreadsheet would.
	 *
	 * @param array[] $lines Lines (meta, header, rows).
	 */
	private function write_lines( $lines ) {
		$h = fopen( $this->plan, 'w' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- test edits its temp file.
		foreach ( $lines as $line ) {
			fputcsv( $h, $line, ',', '"', '\\' );
		}
		fclose( $h ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
	}

	/**
	 * The plan's data rows keyed by column name.
	 *
	 * @return array[]
	 */
	private function plan_rows() {
		$lines  = $this->read_lines( $this->plan );
		$header = $lines[1];
		$rows   = array();
		foreach ( array_slice( $lines, 2 ) as $line ) {
			$rows[] = array_combine( $header, $line );
		}
		return $rows;
	}

	/**
	 * Replace the plan's data rows (keeping the meta line and header).
	 *
	 * @param array[] $rows Rows keyed by column name.
	 */
	private function replace_rows( $rows ) {
		$lines = array_slice( $this->read_lines( $this->plan ), 0, 2 );
		foreach ( $rows as $r ) {
			$line = array();
			foreach ( $lines[1] as $col ) {
				$line[] = $r[ $col ] ?? '';
			}
			$lines[] = $line;
		}
		$this->write_lines( $lines );
	}

	/**
	 * The plan key of a pilot group's rows.
	 *
	 * @param string $pilot_key Fixture key.
	 * @return string
	 */
	private function key_of( $pilot_key ) {
		foreach ( $this->plan_rows() as $r ) {
			if ( (int) $r['user_id'] === $this->p['starter'] && 'friday' === $pilot_key ) {
				return $r['group_key'];
			}
		}
		$name = array(
			'youth'   => 'Youth Group',
			'evening' => 'Evening',
		)[ $pilot_key ];
		foreach ( $this->plan_rows() as $r ) {
			if ( $name === $r['group_name'] ) {
				return $r['group_key'];
			}
		}
		return '';
	}

	/**
	 * Active official groups: name => group ID (names are unique in this fixture only).
	 *
	 * @return int[]
	 */
	private function official_groups() {
		global $wpdb;
		$rows = (array) $wpdb->get_results( $wpdb->prepare( 'SELECT id, name FROM ' . jlife_challenges_table( 'groups' ) . " WHERE campaign_id = %d AND status = 'active' ORDER BY id", $this->official ), ARRAY_A ); // phpcs:ignore WordPress.DB
		return array_map( 'intval', array_column( $rows, 'id', 'name' ) );
	}

	/**
	 * Group names, sorted.
	 *
	 * @param int[] $groups Name => group ID.
	 * @return string[]
	 */
	private function sorted_names( $groups ) {
		$names = array_keys( $groups );
		sort( $names );
		return $names;
	}

	/**
	 * A person's official group names, sorted.
	 *
	 * @param string $who Fixture key.
	 * @return string[]
	 */
	private function groups_of( $who ) {
		$names = array_column( jlife_challenges_active_memberships( $this->official, $this->p[ $who ] ), 'name' );
		sort( $names );
		return $names;
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
	 * The plan file is 0600, lists every active pilot membership for review, and the result (what the CLI prints) is counts only.
	 */
	public function test_plan_file_is_private_and_output_is_counts_only() {
		$r = $this->write_plan();
		foreach ( $r as $what => $n ) {
			$this->assertIsInt( $n, "{$what} is a count" );
		}
		$this->assertSame( 3, $r['groups'], 'the disabled pilot group is not proposed' );
		$this->assertSame( 8, $r['rows'] );
		$this->assertSame( 1, $r['not_enrolled_rows'] );
		$this->assertSame( 0600, fileperms( $this->plan ) & 0777 );

		$text = (string) file_get_contents( $this->plan ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- test reads its temp file.
		$this->assertStringContainsString( 'Review Starter', $text, 'display names are in the file, for review' );
		$this->assertStringNotContainsString( '@', $text, 'never an email address' );
		$this->assertStringNotContainsString( 'Review Leaver', $text, 'someone who left a group is not listed' );
		$this->assertStringNotContainsString( 'Review Solo', $text, 'a solo reader is not listed' );
		$this->assertStringNotContainsString( 'Old Group', $text );
		$this->assertSame( 'starter', array_values( wp_list_filter( $this->plan_rows(), array( 'user_id' => (string) $this->p['starter'] ) ) )[0]['role'] );
		$this->assertSame( 'minor', array_values( wp_list_filter( $this->plan_rows(), array( 'user_id' => (string) $this->p['youth1'] ) ) )[0]['age_band'] );

		$this->assertSame( 'jlife_plan_exists', jlife_challenges_rollover_groups_write_plan( $this->u['organizer'], $this->pilot, $this->official, $this->plan )->get_error_code(), 'an edited plan is never overwritten' );
	}

	/**
	 * Organizers only, for every step.
	 */
	public function test_non_organizer_is_refused() {
		$this->write_plan();
		$who = $this->p['starter'];
		$this->assertSame( 'jlife_denied', jlife_challenges_rollover_groups_write_plan( $who, $this->pilot, $this->official, $this->plan . '2' )->get_error_code() );
		$this->assertSame( 'jlife_denied', jlife_challenges_rollover_groups_validate( $who, $this->pilot, $this->official, $this->plan )->get_error_code() );
		$this->assertSame( 'jlife_denied', jlife_challenges_rollover_groups_execute( $who, $this->pilot, $this->official, $this->plan )->get_error_code() );
		$this->assertSame( 'jlife_denied', jlife_challenges_rollover_groups_verify( $who, $this->pilot, $this->official, $this->plan )->get_error_code() );
		$this->assertSame( array(), $this->official_groups() );
	}

	/**
	 * Execute creates the planned groups and memberships with new codes; solo readers stay solo; nothing else carries.
	 */
	public function test_execute_creates_groups_and_memberships() {
		$this->write_plan();
		$v = jlife_challenges_rollover_groups_validate( $this->u['organizer'], $this->pilot, $this->official, $this->plan );
		$this->assertTrue( $v['ok'] );
		$this->assertSame( array(), $v['errors'] );
		$this->assertSame( 1, $v['not_enrolled'], 'removed from the Marathon: reported, not added' );
		$this->assertSame( array(), $this->official_groups(), 'validate writes nothing' );

		$r = jlife_challenges_rollover_groups_execute( $this->u['organizer'], $this->pilot, $this->official, $this->plan );
		$this->assertIsArray( $r );
		$this->assertSame( 3, $r['groups_created'] );
		$this->assertSame( 7, $r['memberships_added'] );
		$this->assertSame( 0, $r['membership_errors'] );

		$groups = $this->official_groups();
		$this->assertSame( array( 'Evening', 'Friday Friends', 'Youth Group' ), $this->sorted_names( $groups ) );
		$this->assertSame( array( 'Friday Friends' ), $this->groups_of( 'starter' ), 'one group' );
		$this->assertTrue( jlife_challenges_is_group_starter( $this->p['starter'], $groups['Friday Friends'] ), 'adult starter keeps the starter role' );
		$this->assertSame( $this->p['starter'], (int) jlife_challenges_get_group( $groups['Friday Friends'] )['started_by'] );
		$this->assertSame( array( 'Evening', 'Friday Friends', 'Youth Group' ), $this->groups_of( 'multi' ), 'three groups' );
		$this->assertSame( array( 'Friday Friends' ), $this->groups_of( 'youth1' ), 'youth member' );
		$this->assertFalse( jlife_challenges_is_group_starter( $this->p['youth1'], $groups['Friday Friends'] ) );
		$this->assertSame( array(), $this->groups_of( 'solo' ), 'solo stays solo' );
		$this->assertSame( array(), $this->groups_of( 'leaver' ) );
		$this->assertNull( jlife_challenges_get_membership( $groups['Youth Group'], $this->p['removed'] ) );

		// New codes, in a private file next to the plan; each opens its official group.
		$codes = $this->plan . '.codes.csv';
		$this->assertSame( 0600, fileperms( $codes ) & 0777 );
		$lines = $this->read_lines( $codes );
		$this->assertCount( 4, $lines, 'header and three codes' );
		foreach ( array_slice( $lines, 1 ) as $line ) {
			$row   = array_combine( $lines[0], $line );
			$group = jlife_challenges_joinable_group_for_invite( $row['invite_code'] );
			$this->assertSame( $groups[ $row['group_name'] ], (int) $group['id'] );
		}

		// Old pilot codes never open an official group.
		$late = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		foreach ( array( 'friday', 'youth', 'evening' ) as $k ) {
			$this->assertNull( jlife_challenges_joinable_group_for_invite( $this->pg[ $k ]['invite_code'] ) );
			$found = jlife_challenges_group_for_invite( $this->pg[ $k ]['invite_code'] );
			$this->assertTrue( null === $found || (int) $found['campaign_id'] === $this->pilot, 'a pilot code is a pilot group only' );
			$this->assertWPError( jlife_challenges_join_with_code( $late, $this->pg[ $k ]['invite_code'], 'adult' ) );
		}
		$this->assertNull( jlife_challenges_get_enrollment( $this->official, $late ) );

		foreach ( array( 'soap_entries', 'score_ledger', 'group_shares' ) as $t ) {
			$this->assertSame( 0, $this->rows( $t, $this->official ), "no official {$t}" );
		}
	}

	/**
	 * A second execute with the same plan adds nothing and issues no new code.
	 */
	public function test_rerun_adds_nothing() {
		$this->write_plan();
		jlife_challenges_rollover_groups_execute( $this->u['organizer'], $this->pilot, $this->official, $this->plan );
		$again = jlife_challenges_rollover_groups_execute( $this->u['organizer'], $this->pilot, $this->official, $this->plan );
		$this->assertSame( 0, $again['groups_created'] );
		$this->assertSame( 3, $again['groups_existing'] );
		$this->assertSame( 0, $again['memberships_added'] );
		$this->assertSame( 7, $again['memberships_existing'] );
		$this->assertCount( 3, $this->official_groups() );
		$this->assertSame( 7, $this->rows( 'group_members', $this->official ) );
		$this->assertCount( 4, $this->read_lines( $this->plan . '.codes.csv' ), 'no new codes' );
		$this->assertTrue( jlife_challenges_rollover_groups_verify( $this->u['organizer'], $this->pilot, $this->official, $this->plan )['ok'] );
	}

	/**
	 * A run that stops part-way is finished by running it again.
	 */
	public function test_interrupted_run_finishes_on_rerun() {
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
			jlife_challenges_rollover_groups_execute( $this->u['organizer'], $this->pilot, $this->official, $this->plan );
			$this->fail( 'the simulated failure should stop the run' );
		} catch ( RuntimeException $e ) {
			$this->assertSame( 'simulated failure', $e->getMessage() );
		}
		remove_action( 'jlife_challenges_rollover_groups_step', $fail );
		$this->assertFalse( jlife_challenges_rollover_groups_verify( $this->u['organizer'], $this->pilot, $this->official, $this->plan )['ok'] );

		$r = jlife_challenges_rollover_groups_execute( $this->u['organizer'], $this->pilot, $this->official, $this->plan );
		$this->assertSame( 0, $r['membership_errors'] );
		$this->assertCount( 3, $this->official_groups(), 'no duplicate group' );
		$this->assertSame( 7, $this->rows( 'group_members', $this->official ) );
		$this->assertTrue( jlife_challenges_rollover_groups_verify( $this->u['organizer'], $this->pilot, $this->official, $this->plan )['ok'] );
	}

	/**
	 * The organizer's edits are honored: rename a group, merge two, drop one, move a person.
	 */
	public function test_edited_plan_is_honored() {
		$this->write_plan();
		$friday = $this->key_of( 'friday' );
		$youth  = $this->key_of( 'youth' );
		$rows   = array();
		foreach ( $this->plan_rows() as $r ) {
			if ( $r['group_key'] === $friday ) {
				$r['group_name'] = 'Friday Friends 2027'; // Rename.
				if ( (int) $r['user_id'] === $this->p['youth1'] ) {
					$r['group_key']  = $youth; // Move a person.
					$r['group_name'] = 'Youth Group';
				}
			} elseif ( 'Evening' === $r['group_name'] ) {
				if ( (int) $r['user_id'] === $this->p['multi'] ) {
					continue; // Merge Evening into Youth Group: multi is there already.
				}
				$r['group_key']  = $youth;
				$r['group_name'] = 'Youth Group';
			}
			$rows[] = $r;
		}
		$this->replace_rows( $rows );
		$this->assertTrue( jlife_challenges_rollover_groups_validate( $this->u['organizer'], $this->pilot, $this->official, $this->plan )['ok'] );
		jlife_challenges_rollover_groups_execute( $this->u['organizer'], $this->pilot, $this->official, $this->plan );

		$this->assertSame( array( 'Friday Friends 2027', 'Youth Group' ), $this->sorted_names( $this->official_groups() ), 'Evening is gone (merged)' );
		$this->assertSame( array( 'Friday Friends 2027', 'Youth Group' ), $this->groups_of( 'multi' ) );
		$this->assertSame( array( 'Youth Group' ), $this->groups_of( 'youth1' ), 'moved' );
		$this->assertSame( array( 'Youth Group' ), $this->groups_of( 'evening' ), 'merged' );
		$this->assertTrue( jlife_challenges_rollover_groups_verify( $this->u['organizer'], $this->pilot, $this->official, $this->plan )['ok'] );

		// Dropping a group: remove its rows.
		unlink( $this->plan ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- test temp file.
		$this->write_plan();
		$evening = $this->key_of( 'evening' );
		$this->replace_rows( array_values( array_filter( $this->plan_rows(), static fn( $r ) => $r['group_key'] !== $evening ) ) );
		$v = jlife_challenges_rollover_groups_validate( $this->u['organizer'], $this->pilot, $this->official, $this->plan );
		$this->assertSame( 2, $v['groups'] );
	}

	/**
	 * A plan with errors is refused as a whole, and nothing is written.
	 */
	public function test_validation_errors_write_nothing() {
		$this->write_plan();
		$rows   = $this->plan_rows();
		$youth  = $this->key_of( 'youth' );
		$friday = $this->key_of( 'friday' );
		foreach ( $rows as &$r ) {
			if ( (int) $r['user_id'] === $this->p['youth2'] ) {
				$r['role'] = 'starter'; // A minor cannot be a starter.
			}
			if ( (int) $r['user_id'] === $this->p['evening'] ) {
				$r['role'] = 'leader'; // Not a role.
			}
		}
		unset( $r );
		$rows[] = array(
			'group_key'  => 'new-1',
			'group_name' => 'Extra',
			'role'       => 'member',
			'user_id'    => (string) $this->p['multi'], // A fourth group.
		);
		$rows[] = array(
			'group_key'  => $youth,
			'group_name' => 'Youth Group',
			'role'       => 'member',
			'user_id'    => (string) $this->p['youth2'], // Twice in one group.
		);
		$rows[] = array(
			'group_key'  => $friday,
			'group_name' => 'Another Name', // Not the name the other Friday rows use.
			'role'       => 'member',
			'user_id'    => (string) $this->p['solo'],
		);
		$rows[] = array(
			'group_key'  => 'new-2',
			'group_name' => str_repeat( 'x', 61 ), // Too long.
			'role'       => 'member',
			'user_id'    => (string) $this->p['solo'],
		);
		$rows[] = array(
			'group_key'  => 'new-3',
			'group_name' => 'Bad user',
			'role'       => 'member',
			'user_id'    => 'abc',
		);
		$this->replace_rows( $rows );

		$v = jlife_challenges_rollover_groups_validate( $this->u['organizer'], $this->pilot, $this->official, $this->plan );
		$this->assertFalse( $v['ok'] );
		foreach ( array( 'minor_starter', 'unknown_role', 'group_limit', 'duplicate_membership', 'group_name_mismatch', 'group_name_invalid', 'bad_row' ) as $class ) {
			$this->assertGreaterThan( 0, $v['errors'][ $class ] ?? 0, $class );
		}
		foreach ( $v['errors'] as $class => $n ) {
			$this->assertIsString( $class );
			$this->assertIsInt( $n );
		}
		$this->assertSame( 'jlife_plan_invalid', jlife_challenges_rollover_groups_execute( $this->u['organizer'], $this->pilot, $this->official, $this->plan )->get_error_code() );
		$this->assertSame( array(), $this->official_groups() );
		$this->assertSame( 0, $this->rows( 'group_members', $this->official ) );
		$this->assertFalse( file_exists( $this->plan . '.codes.csv' ) );
	}

	/**
	 * A plan made for other campaigns, or unknown campaigns, is refused before anything is written.
	 */
	public function test_plan_for_other_campaigns_is_refused() {
		$this->write_plan();
		$other = jlife_challenges_create_official_campaign(
			$this->u['organizer'],
			array(
				'campaign_key' => 'marathon-2027',
				'start_date'   => '2027-06-01',
				'end_date'     => '2027-09-12',
			)
		);
		$this->assertSame( 'jlife_plan_mismatch', jlife_challenges_rollover_groups_execute( $this->u['organizer'], $this->pilot, (int) $other['campaign_id'], $this->plan )->get_error_code() );
		$this->assertSame( 'jlife_rollover_same', jlife_challenges_rollover_groups_execute( $this->u['organizer'], $this->pilot, $this->pilot, $this->plan )->get_error_code() );
		$this->assertSame( 'jlife_rollover_not_found', jlife_challenges_rollover_groups_write_plan( $this->u['organizer'], 987654, $this->official, $this->plan . '2' )->get_error_code() );
		$this->assertSame( 'jlife_plan_unreadable', jlife_challenges_rollover_groups_validate( $this->u['organizer'], $this->pilot, $this->official, $this->plan . '-missing' )->get_error_code() );
		$this->assertSame( array(), $this->official_groups() );
	}

	/**
	 * Verify reports differences between the plan and the database.
	 */
	public function test_verify_reports_differences() {
		$this->write_plan();
		jlife_challenges_rollover_groups_execute( $this->u['organizer'], $this->pilot, $this->official, $this->plan );
		$v = jlife_challenges_rollover_groups_verify( $this->u['organizer'], $this->pilot, $this->official, $this->plan );
		$this->assertTrue( $v['ok'] );
		$this->assertSame( 3, $v['groups_found'] );
		$this->assertSame( 7, $v['memberships_found'] );
		$this->assertSame( 0, $v['memberships_missing'] );

		$groups = $this->official_groups();
		$this->assertTrue( jlife_challenges_leave_group( $this->p['evening'], $groups['Evening'] ) );
		jlife_challenges_create_group( $this->u['organizer'], $this->official, 'Made by hand' );
		$v = jlife_challenges_rollover_groups_verify( $this->u['organizer'], $this->pilot, $this->official, $this->plan );
		$this->assertFalse( $v['ok'] );
		$this->assertSame( 1, $v['memberships_missing'] );
		$this->assertSame( 1, $v['unplanned_groups'] );
		foreach ( $v as $what => $n ) {
			if ( 'ok' !== $what ) {
				$this->assertIsInt( $n, "{$what} is a count" );
			}
		}
	}

	/**
	 * The pilot is only read.
	 */
	public function test_pilot_is_untouched() {
		$before = array();
		foreach ( array( 'groups', 'group_members', 'group_shares', 'soap_entries', 'score_ledger', 'enrollments' ) as $t ) {
			$before[ $t ] = $this->rows( $t, $this->pilot );
		}
		$this->write_plan();
		jlife_challenges_rollover_groups_execute( $this->u['organizer'], $this->pilot, $this->official, $this->plan );
		foreach ( $before as $t => $n ) {
			$this->assertSame( $n, $this->rows( $t, $this->pilot ), "pilot {$t}" );
		}
		$this->assertSame( 'closed', jlife_challenges_get_campaign( $this->pilot )['status'] );
	}
}
