<?php
/**
 * Ask the Pastors (#249, docs/challenges-engine-design.md §4k): a participant's
 * private Bible question, the wp-admin queue only site administrators read, the
 * de-identified export, and the guarantee that a question never becomes a
 * share, a point, a log line or an email. Written before the feature.
 *
 * @package jlife-challenges
 */

// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- tests read the custom tables directly.

/**
 * Bible questions tests.
 */
class Test_Bible_Questions extends Jlife_Challenges_TestCase {

	/**
	 * A question long enough to pass, with a marker the leak checks look for.
	 */
	const Q = 'Why did Jesus say the Word became flesh? SECRETMARK';

	/**
	 * Captured error_log lines.
	 *
	 * @var string
	 */
	private $log = '';

	/**
	 * Previous error_log target.
	 *
	 * @var string|false
	 */
	private $old_log = false;

	/**
	 * Mail sent during a test.
	 *
	 * @var array[]
	 */
	private $mails = array();

	/**
	 * More people: a starter, an editor, an author and a minor. The feature is on.
	 */
	public function set_up() {
		parent::set_up();
		update_option( 'jlife_questions_enabled', '1' );
		$this->u['editor']  = self::factory()->user->create( array( 'role' => 'editor' ) );
		$this->u['author']  = self::factory()->user->create( array( 'role' => 'author' ) );
		$this->u['starter'] = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		$this->u['minor']   = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		$this->assertIsInt( jlife_challenges_enroll_solo( $this->u['starter'], $this->campaign, 'adult' ) );
		$this->assertIsArray( jlife_challenges_start_group( $this->u['starter'], 'Started Group' ) );
		$this->assertIsInt( jlife_challenges_enroll_solo( $this->u['minor'], $this->campaign, 'minor' ) );
		$this->log     = '';
		$this->old_log = ini_get( 'error_log' );
		$tmp           = tempnam( sys_get_temp_dir(), 'jlq' );
		ini_set( 'error_log', $tmp ); // phpcs:ignore WordPress.PHP.IniSet.Risky -- capture log lines for the no-logging test.
		$this->mails = array();
		add_filter( 'pre_wp_mail', array( $this, 'capture_mail' ), 10, 2 );
	}

	/**
	 * Back to nobody, no request data.
	 */
	public function tear_down() {
		remove_filter( 'pre_wp_mail', array( $this, 'capture_mail' ), 10 );
		ini_set( 'error_log', (string) $this->old_log ); // phpcs:ignore WordPress.PHP.IniSet.Risky -- restore.
		$_POST = array();
		unset( $_SERVER['REQUEST_METHOD'] );
		wp_set_current_user( 0 );
		parent::tear_down();
	}

	/**
	 * Record an email instead of sending it.
	 *
	 * @param null|bool $pre  Short-circuit.
	 * @param array     $atts Mail arguments.
	 * @return bool
	 */
	public function capture_mail( $pre, $atts ) {
		unset( $pre );
		$this->mails[] = $atts;
		return true;
	}

	/**
	 * Ask as a fixture user.
	 *
	 * @param string $who  Fixture user.
	 * @param string $text Question.
	 * @param string $day  Day key.
	 * @param string $ref  Reference.
	 * @return int|WP_Error
	 */
	private function ask( $who, $text = self::Q, $day = 'd001', $ref = 'John 1:14' ) {
		return jlife_questions_submit( $this->u[ $who ], $day, $ref, $text );
	}

	/**
	 * Every row, as stored.
	 *
	 * @return array[]
	 */
	private function rows() {
		global $wpdb;
		return (array) $wpdb->get_results( 'SELECT * FROM ' . jlife_challenges_table( 'bible_questions' ) . ' ORDER BY id', ARRAY_A );
	}

	/**
	 * The tables a question must never touch, as rows.
	 *
	 * @return array
	 */
	private function other_tables() {
		global $wpdb;
		$out = array();
		foreach ( array( 'soap_entries', 'soap_revisions', 'group_shares', 'score_ledger', 'enrollments', 'group_members' ) as $table ) {
			$out[ $table ] = $wpdb->get_results( 'SELECT * FROM ' . jlife_challenges_table( $table ) . ' ORDER BY id', ARRAY_A );
		}
		return $out;
	}

	/**
	 * Audit rows for an action.
	 *
	 * @param string $action Action.
	 * @return array[]
	 */
	private function audits( $action ) {
		global $wpdb;
		return (array) $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . jlife_challenges_table( 'audit_log' ) . ' WHERE action = %s ORDER BY id', $action ), ARRAY_A );
	}

	/**
	 * Apply an admin operation as a fixture user, with a valid nonce unless overridden.
	 *
	 * @param string $op     Operation.
	 * @param array  $fields Fields.
	 * @param string $who    Fixture user.
	 * @param string $method HTTP method.
	 * @return array
	 */
	private function apply( $op, array $fields = array(), $who = 'organizer', $method = 'POST' ) {
		wp_set_current_user( $this->u[ $who ] );
		$id      = (int) ( $fields['id'] ?? 0 );
		$fields += array(
			'op'    => $op,
			'id'    => $id,
			'nonce' => wp_create_nonce( jlife_questions_nonce_action( $op, $id ) ),
		);
		return jlife_questions_apply( $method, $fields );
	}

	/**
	 * Who may review: only site administrators, through one filterable helper.
	 */
	public function test_only_site_administrators_pass_the_review_gate() {
		$this->assertTrue( jlife_questions_can_review( $this->u['organizer'] ) );
		foreach ( array( 'a', 'b', 'c', 'outsider', 'starter', 'minor', 'editor', 'author' ) as $who ) {
			$this->assertFalse( jlife_questions_can_review( $this->u[ $who ] ), $who );
		}
		$this->assertFalse( jlife_questions_can_review( 0 ) );
	}

	/**
	 * The filter can add a reviewer later; no call site changes.
	 */
	public function test_the_gate_filter_can_widen_and_narrow_access() {
		$editor = $this->u['editor'];
		$this->ask( 'a' );
		$widen = static fn( $ok, $user_id ) => $ok || (int) $user_id === $editor;
		add_filter( 'jlife_questions_can_review', $widen, 10, 2 );
		$this->assertTrue( jlife_questions_can_review( $editor ) );
		$this->assertIsArray( jlife_questions_list( $editor ) );
		remove_filter( 'jlife_questions_can_review', $widen, 10 );
		$this->assertFalse( jlife_questions_can_review( $editor ) );
		$this->assertTrue( is_wp_error( jlife_questions_list( $editor ) ) );
	}

	/**
	 * No role or capability is added: a participant account stays a participant.
	 */
	public function test_no_new_role_or_capability_exists() {
		$this->assertNull( get_role( 'jlife_pastor' ) );
		foreach ( wp_roles()->role_objects as $role ) {
			$this->assertFalse( $role->has_cap( 'jlife_pastoral_review' ) );
		}
		if ( function_exists( 'jlife_auth_email_code_allowed' ) ) {
			$this->assertTrue( jlife_auth_email_code_allowed( $this->u['a'] ) );
		}
	}

	/**
	 * A signed-in enrolled participant, solo or in a group, can ask.
	 */
	public function test_solo_group_and_minor_participants_can_ask() {
		foreach ( array( 'a', 'c', 'starter', 'minor' ) as $who ) {
			$id = $this->ask( $who, self::Q . ' ' . $who );
			$this->assertIsInt( $id, $who );
		}
		$this->assertCount( 4, $this->rows() );
		$minor = array_values( array_filter( $this->rows(), fn( $r ) => (int) $r['user_id'] === $this->u['minor'] ) );
		$this->assertSame( '1', (string) $minor[0]['minor'] );
	}

	/**
	 * Anonymous, not enrolled, an unknown day and a day that has not opened are refused.
	 */
	public function test_only_enrolled_readers_on_an_open_day_can_ask() {
		$this->assertTrue( is_wp_error( jlife_questions_submit( 0, 'd001', '', self::Q ) ) );
		$this->assertTrue( is_wp_error( $this->ask( 'outsider' ) ) );
		$this->assertTrue( is_wp_error( $this->ask( 'a', self::Q, 'd999' ) ) );
		$this->assertTrue( is_wp_error( $this->ask( 'a', self::Q, 'd050' ) ), 'a day that has not opened' );
		$this->assertTrue( is_wp_error( $this->ask( 'starter', self::Q, 'd050' ) ), 'a starter reading ahead still cannot ask about a day not open' );
		$this->assertSame( array(), $this->rows() );
		$this->assertIsInt( $this->ask( 'a', self::Q, '' ), 'a general question without a day' );
	}

	/**
	 * The feature is off until an administrator switches it on.
	 */
	public function test_nothing_is_stored_while_the_feature_is_off() {
		delete_option( 'jlife_questions_enabled' );
		$this->assertFalse( jlife_questions_enabled() );
		$r = $this->ask( 'a' );
		$this->assertTrue( is_wp_error( $r ) );
		$this->assertSame( 'jlife_questions_off', $r->get_error_code() );
		$this->assertSame( array(), $this->rows() );
	}

	/**
	 * A closed campaign takes no questions.
	 */
	public function test_a_closed_campaign_takes_no_question() {
		global $wpdb;
		$wpdb->update( jlife_challenges_table( 'campaigns' ), array( 'status' => 'closed' ), array( 'id' => $this->campaign ) );
		$this->assertTrue( is_wp_error( $this->ask( 'a' ) ) );
		$this->assertSame( array(), $this->rows() );
	}

	/**
	 * Length: 10 to 1000 characters after trimming; tags are stripped.
	 */
	public function test_length_limits_and_plain_text() {
		$short = $this->ask( 'a', '  too short ' );
		$this->assertSame( 'jlife_question_short', $short->get_error_code() );
		$this->assertSame( 'jlife_question_short', $this->ask( 'a', "<b></b>   \n  <i> </i>" )->get_error_code() );
		$long = $this->ask( 'a', str_repeat( 'x', 1001 ) );
		$this->assertSame( 'jlife_question_long', $long->get_error_code() );
		$this->assertIsInt( $this->ask( 'a', str_repeat( 'y', 1000 ), 'd001' ) );
		$id = $this->ask( 'b', '<script>alert(1)</script>What does this verse mean?' );
		$this->assertIsInt( $id );
		$rows = $this->rows();
		$this->assertStringNotContainsString( '<script', end( $rows )['body'] );
		$this->assertSame( 'jlife_question_long', $this->ask( 'a', 'ok question here', 'd001', str_repeat( 'r', 101 ) )->get_error_code() );
	}

	/**
	 * Three a day and fifteen a campaign.
	 */
	public function test_rate_limits_per_day_and_per_campaign() {
		for ( $i = 1; $i <= 3; $i++ ) {
			$this->assertIsInt( $this->ask( 'a', "Question number $i about the reading" ) );
		}
		$fourth = $this->ask( 'a', 'Question number 4 about the reading' );
		$this->assertSame( 'jlife_question_rate_day', $fourth->get_error_code() );
		$this->assertIsInt( $this->ask( 'b' ), 'another reader is not limited by a' );
		$day = 20;
		for ( $n = 4; $n <= 15; $n++ ) { // Twelve more on later days, three a day: 15 in all.
			if ( 0 === ( $n - 1 ) % 3 ) {
				++$day;
				$this->set_now( '2026-12-' . $day . ' 18:00:00' );
			}
			$this->assertIsInt( jlife_questions_submit( $this->u['a'], '', '', "Question number $n about the reading" ), "n=$n" );
		}
		$this->set_now( '2026-12-27 18:00:00' );
		$over = jlife_questions_submit( $this->u['a'], '', '', 'Question number 16 about the reading' );
		$this->assertSame( 'jlife_question_rate_campaign', $over->get_error_code() );
	}

	/**
	 * A double submit stores one row.
	 */
	public function test_a_repeated_question_is_stored_once() {
		$first  = $this->ask( 'a' );
		$second = $this->ask( 'a' );
		$this->assertSame( $first, $second );
		$this->assertCount( 1, $this->rows() );
		$this->assertSame( 1, count( $this->audits( 'question_submitted' ) ) );
	}

	/**
	 * A question earns nothing and touches no journal, share, point or membership.
	 */
	public function test_a_question_creates_no_points_shares_or_entries_and_keeps_drafts() {
		jlife_challenges_save_draft( $this->u['a'], $this->campaign, 'd001', $this->soap( 'draft' ) );
		$before = $this->other_tables();
		$this->assertIsInt( $this->ask( 'a' ) );
		$this->assertSame( $before, $this->other_tables() );
		$entry = jlife_challenges_find_entry( $this->u['a'], $this->campaign, 'd001' );
		$this->assertStringContainsString( '(draft)', (string) $entry['body'] );
		$this->assertSame( 0, $this->ledger_rows( $this->u['a'] ) );
	}

	/**
	 * Admin reads: identity and question for an administrator, nothing for anyone else.
	 */
	public function test_an_administrator_sees_identity_and_question() {
		$id   = $this->ask( 'a' );
		$list = jlife_questions_list( $this->u['organizer'] );
		$this->assertCount( 1, $list );
		$this->assertSame( self::Q, $list[0]['body'] );
		$this->assertSame( 'Aname Private', $list[0]['display_name'] );
		$this->assertSame( 'new', $list[0]['status'] );
		$one = jlife_questions_get( $this->u['organizer'], $id );
		$this->assertSame( 'John 1:14', $one['reference'] );
	}

	/**
	 * Every non-administrator is refused on list and detail, same as "not found".
	 */
	public function test_nobody_else_can_list_or_read() {
		$id = $this->ask( 'a' );
		foreach ( array( 'a', 'b', 'c', 'outsider', 'starter', 'minor', 'editor', 'author' ) as $who ) {
			$this->assertTrue( is_wp_error( jlife_questions_list( $this->u[ $who ] ) ), "$who list" );
			$this->assertTrue( is_wp_error( jlife_questions_get( $this->u[ $who ], $id ) ), "$who get" );
		}
		$this->assertTrue( is_wp_error( jlife_questions_list( 0 ) ) );
		$this->assertTrue( is_wp_error( jlife_questions_get( 0, $id ) ) );
		$missing = jlife_questions_get( $this->u['organizer'], 99999 );
		$denied  = jlife_questions_get( $this->u['a'], $id );
		$this->assertSame( $missing->get_error_code(), $denied->get_error_code(), 'denied looks like not found' );
	}

	/**
	 * The question is never in the asker's group feed, share data or share views.
	 */
	public function test_the_asker_s_group_cannot_see_the_question() {
		$this->ask( 'a' );
		$view = jlife_challenges_group_view( $this->u['b'], $this->g['g1'] );
		$this->assertStringNotContainsString( 'SECRETMARK', (string) wp_json_encode( $view ) );
		global $wpdb;
		$this->assertSame( 0, (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . jlife_challenges_table( 'group_shares' ) . " WHERE body LIKE '%SECRETMARK%'" ) );
		$this->assertSame( 0, (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . jlife_challenges_table( 'soap_entries' ) . " WHERE body LIKE '%SECRETMARK%'" ) );
	}

	/**
	 * Admin writes: status, note, delete, export; each denied to non-administrators.
	 */
	public function test_every_admin_write_is_refused_to_everyone_but_administrators() {
		$id = $this->ask( 'a' );
		foreach ( array( 'a', 'b', 'starter', 'editor', 'author', 'outsider' ) as $who ) {
			foreach ( array(
				'status' => array(
					'id'     => $id,
					'status' => 'selected',
				),
				'note'   => array(
					'id'   => $id,
					'note' => 'hello',
				),
				'delete' => array( 'id' => $id ),
				'export' => array( 'ids' => array( $id ) ),
				'enable' => array( 'enabled' => '0' ),
			) as $op => $fields ) {
				$r = $this->apply( $op, $fields, $who );
				$this->assertSame( 'forbidden', $r['code'], "$who $op" );
			}
		}
		$row = $this->rows()[0];
		$this->assertSame( 'new', $row['status'] );
		$this->assertSame( '', (string) $row['pastor_note'] );
		$this->assertSame( '1', (string) get_option( 'jlife_questions_enabled' ) );
		$this->assertSame( array(), $this->audits( 'question_status' ) );
	}

	/**
	 * A request needs POST and a nonce bound to the action and the question.
	 */
	public function test_requests_need_post_and_a_nonce_for_that_action_and_question() {
		$id    = $this->ask( 'a' );
		$other = $this->ask( 'b' );
		$get   = $this->apply(
			'status',
			array(
				'id'     => $id,
				'status' => 'selected',
			),
			'organizer',
			'GET'
		);
		$this->assertSame( 'bad_method', $get['code'] );
		$none = $this->apply(
			'status',
			array(
				'id'     => $id,
				'status' => 'selected',
				'nonce'  => '',
			)
		);
		$this->assertSame( 'bad_nonce', $none['code'] );
		$wrong = $this->apply(
			'status',
			array(
				'id'     => $id,
				'status' => 'selected',
				'nonce'  => wp_create_nonce( jlife_questions_nonce_action( 'status', $other ) ),
			)
		);
		$this->assertSame( 'bad_nonce', $wrong['code'], 'a nonce for another question' );
		$cross = $this->apply(
			'delete',
			array(
				'id'    => $id,
				'nonce' => wp_create_nonce( jlife_questions_nonce_action( 'status', $id ) ),
			)
		);
		$this->assertSame( 'bad_nonce', $cross['code'], 'a nonce for another action' );
		$this->assertSame( 'bad_op', $this->apply( 'drop', array( 'id' => $id ) )['code'] );
		$this->assertSame( 'new', $this->rows()[0]['status'] );
	}

	/**
	 * Statuses: the four, nothing else, each change audited without text.
	 */
	public function test_status_changes_are_audited_without_the_question() {
		$id = $this->ask( 'a' );
		foreach ( array( 'selected', 'addressed', 'archived', 'new' ) as $status ) {
			$r = $this->apply(
				'status',
				array(
					'id'     => $id,
					'status' => $status,
				)
			);
			$this->assertSame( 'saved', $r['code'], $status );
			$this->assertSame( $status, jlife_questions_get( $this->u['organizer'], $id )['status'] );
		}
		$bad = $this->apply(
			'status',
			array(
				'id'     => $id,
				'status' => 'deleted',
			)
		);
		$this->assertSame( 'bad_status', $bad['code'] );
		$rows = $this->audits( 'question_status' );
		$this->assertCount( 4, $rows );
		$this->assertStringNotContainsString( 'SECRETMARK', (string) wp_json_encode( $rows ) );
		$missing = $this->apply(
			'status',
			array(
				'id'     => 99999,
				'status' => 'selected',
			)
		);
		$this->assertSame( 'not_found', $missing['code'] );
	}

	/**
	 * The private pastor note is saved, trimmed and kept out of the export.
	 */
	public function test_the_pastor_note_is_saved_and_never_exported() {
		$id = $this->ask( 'a' );
		$r  = $this->apply(
			'note',
			array(
				'id'   => $id,
				'note' => "  Follow up with John 1 <b>context</b> NOTEMARK  \n",
			)
		);
		$this->assertSame( 'saved', $r['code'] );
		$this->assertSame( 'Follow up with John 1 context NOTEMARK', jlife_questions_get( $this->u['organizer'], $id )['pastor_note'] );
		$ex = $this->apply( 'export', array( 'ids' => array( $id ) ) );
		$this->assertSame( 'exported', $ex['code'] );
		$this->assertStringNotContainsString( 'NOTEMARK', $ex['text'] );
		$this->assertStringNotContainsString( 'NOTEMARK', (string) wp_json_encode( $this->audits( 'question_note' ) ) );
		$long = $this->apply(
			'note',
			array(
				'id'   => $id,
				'note' => str_repeat( 'n', 2001 ),
			)
		);
		$this->assertSame( 'bad_note', $long['code'] );
	}

	/**
	 * Delete: archived rows only, an administrator only, audited by id.
	 */
	public function test_only_archived_questions_can_be_deleted() {
		$id = $this->ask( 'a' );
		$this->assertSame( 'not_archived', $this->apply( 'delete', array( 'id' => $id ) )['code'] );
		$this->assertCount( 1, $this->rows() );
		$this->apply(
			'status',
			array(
				'id'     => $id,
				'status' => 'archived',
			)
		);
		$this->assertSame( 'deleted', $this->apply( 'delete', array( 'id' => $id ) )['code'] );
		$this->assertSame( array(), $this->rows() );
		$rows = $this->audits( 'question_deleted' );
		$this->assertCount( 1, $rows );
		$this->assertStringNotContainsString( 'SECRETMARK', (string) wp_json_encode( $rows ) );
		$this->assertSame( 'not_found', $this->apply( 'delete', array( 'id' => $id ) )['code'] );
	}

	/**
	 * The export: question text and passage only, no one who asked, no date, no note.
	 */
	public function test_the_export_is_de_identified() {
		$a  = $this->ask( 'a', 'First question about creation SECRETMARK', 'd001', 'Genesis 1' );
		$m  = $this->ask( 'minor', 'Second question about light SECRETMARK', 'd001', 'John 1:5' );
		$ex = $this->apply( 'export', array( 'ids' => array( $a, $m ) ) );
		$this->assertSame( 'exported', $ex['code'] );
		$text = $ex['text'];
		$this->assertStringContainsString( 'First question about creation', $text );
		$this->assertStringContainsString( 'Second question about light', $text );
		$this->assertStringContainsString( 'Genesis 1', $text );
		foreach ( array( 'Aname', 'Private', gmdate( 'Y' ), 'new', 'minor', 'under 18', '@' ) as $leak ) {
			$this->assertStringNotContainsString( $leak, $text, $leak );
		}
		$rows = $this->audits( 'questions_exported' );
		$this->assertCount( 1, $rows );
		$this->assertStringNotContainsString( 'SECRETMARK', (string) wp_json_encode( $rows ) );
		$this->assertSame( 'no_selection', $this->apply( 'export', array( 'ids' => array() ) )['code'] );
		$this->assertSame( 'no_selection', $this->apply( 'export', array( 'ids' => array( 99999 ) ) )['code'] );
	}

	/**
	 * Marking a question Selected for Q&A does not publish or copy it anywhere.
	 */
	public function test_selecting_publishes_nothing() {
		$id     = $this->ask( 'a' );
		$before = $this->other_tables();
		$this->apply(
			'status',
			array(
				'id'     => $id,
				'status' => 'selected',
			)
		);
		$this->assertSame( $before, $this->other_tables() );
		$this->assertSame( array(), $this->mails );
	}

	/**
	 * The switch: a nonce'd administrator action, default off.
	 */
	public function test_the_switch_is_an_administrator_action_default_off() {
		delete_option( 'jlife_questions_enabled' );
		$this->assertFalse( jlife_questions_enabled() );
		$this->assertSame( 'enabled', $this->apply( 'enable', array( 'enabled' => '1' ) )['code'] );
		$this->assertTrue( jlife_questions_enabled() );
		$bad = $this->apply(
			'enable',
			array(
				'enabled' => '0',
				'nonce'   => 'nope',
			)
		);
		$this->assertSame( 'bad_nonce', $bad['code'] );
		$this->assertTrue( jlife_questions_enabled() );
		$this->assertSame( 'disabled', $this->apply( 'enable', array( 'enabled' => '0' ) )['code'] );
		$this->assertFalse( jlife_questions_enabled() );
		$this->assertCount( 2, $this->audits( 'questions_switch' ) );
	}

	/**
	 * Search and filters on the queue.
	 */
	public function test_the_queue_filters_by_status_day_and_text() {
		$one = $this->ask( 'a', 'Question about the Word becoming flesh', 'd001', 'John 1:14' );
		$this->set_now( '2026-12-21 18:00:00' );
		$two = $this->ask( 'b', 'Question about the baptism of Jesus', 'd002', 'Matthew 3' );
		$this->apply(
			'status',
			array(
				'id'     => $two,
				'status' => 'selected',
			)
		);
		$me = $this->u['organizer'];
		$this->assertCount( 2, jlife_questions_list( $me ) );
		$this->assertSame( array( $two ), array_map( 'intval', array_column( jlife_questions_list( $me, array( 'status' => 'selected' ) ), 'id' ) ) );
		$this->assertSame( array( $one ), array_map( 'intval', array_column( jlife_questions_list( $me, array( 'day_key' => 'd001' ) ), 'id' ) ) );
		$this->assertSame( array( $two ), array_map( 'intval', array_column( jlife_questions_list( $me, array( 's' => 'baptism' ) ), 'id' ) ) );
		$this->assertSame( array( $one ), array_map( 'intval', array_column( jlife_questions_list( $me, array( 's' => 'John 1' ) ), 'id' ) ) );
		$this->assertSame( array(), jlife_questions_list( $me, array( 's' => '100%_' ) ), 'LIKE wildcards are literal' );
	}

	/**
	 * The admin page: forbidden for everyone else, escaped for the administrator, shows an under-18 badge.
	 */
	public function test_the_admin_page_is_gated_escaped_and_marks_minors() {
		$this->ask( 'a', '<img src=x onerror=alert(1)> what is this verse about?' );
		$this->ask( 'minor', 'A question from a young reader about prayer' );
		wp_set_current_user( $this->u['editor'] );
		$this->assertTrue( is_wp_error( jlife_questions_admin_view( $this->u['editor'], array() ) ) );
		wp_set_current_user( $this->u['organizer'] );
		$view = jlife_questions_admin_view( $this->u['organizer'], array() );
		$html = jlife_questions_admin_html( $view );
		$this->assertStringNotContainsString( '<img src=x', $html );
		$this->assertStringContainsString( 'Aname Private', $html );
		$this->assertStringContainsString( 'Under 18', $html );
		$this->assertStringContainsString( 'name="jl_nonce"', $html );
		$this->assertStringNotContainsString( 'user_email', $html );
	}

	/**
	 * Nothing about a question reaches the log, the audit detail or an email.
	 */
	public function test_no_question_text_in_logs_audit_or_mail() {
		$id = $this->ask( 'a' );
		$this->apply(
			'status',
			array(
				'id'     => $id,
				'status' => 'selected',
			)
		);
		$this->apply( 'export', array( 'ids' => array( $id ) ) );
		global $wpdb;
		$audit = (string) wp_json_encode( $wpdb->get_results( 'SELECT * FROM ' . jlife_challenges_table( 'audit_log' ), ARRAY_A ) );
		$this->assertStringNotContainsString( 'SECRETMARK', $audit );
		$this->assertStringNotContainsString( 'John 1:14', $audit );
		$this->assertSame( array(), $this->mails, 'no email on submit or on any admin action' );
		$logfile = (string) ini_get( 'error_log' );
		$this->assertStringNotContainsString( 'SECRETMARK', (string) file_get_contents( $logfile ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- the capture file.
	}

	/**
	 * A deleted account takes its questions with it.
	 */
	public function test_account_deletion_removes_the_questions() {
		$this->ask( 'a' );
		$this->ask( 'b' );
		require_once ABSPATH . 'wp-admin/includes/user.php';
		wp_delete_user( $this->u['a'] );
		$rows = $this->rows();
		$this->assertCount( 1, $rows );
		$this->assertSame( $this->u['b'], (int) $rows[0]['user_id'] );
	}

	/**
	 * Removing a test campaign removes its questions; there is no retention cron.
	 */
	public function test_no_retention_cron_and_test_campaign_removal_covers_the_table() {
		$this->assertContains( 'bible_questions', JLIFE_CHALLENGES_TEST_TABLES );
		$this->assertFalse( wp_next_scheduled( 'jlife_questions_purge' ) );
		$this->assertFalse( has_action( 'jlife_questions_purge' ) );
	}

	/**
	 * The reader's form: group-first copy, accurate privacy words, no promise, no SOAP fields, its own nonce.
	 */
	public function test_the_form_is_separate_and_says_what_is_true() {
		$view            = jlife_challenges_day_view( $this->u['a'], 'd001' );
		$view['reading'] = array( 'day' => array( 'passages' => array( array( 'display' => 'John 1:14' ) ) ) );
		$html            = jlife_questions_form_html( $view );
		$this->assertStringContainsString( 'discussing your question with your group first', $html );
		$this->assertStringContainsString( 'not to your group or other participants', $html );
		$this->assertStringContainsString( 'immediate or personal reply', $html );
		$this->assertStringContainsString( 'not watched for urgent', $html );
		$this->assertStringContainsString( 'name="_jlife_ask"', $html );
		$this->assertStringContainsString( 'name="jl_question"', $html );
		$this->assertStringNotContainsString( 'name="jl_text"', $html );
		$this->assertStringNotContainsString( '_jlife_soap', $html );
		$this->assertStringNotContainsString( 'rest/draft', $html );
		$this->assertStringNotContainsString( 'name="jl_share', $html );
		$this->assertStringNotContainsString( 'anonymous', strtolower( $html ) );
		$this->assertStringNotContainsString( 'mailto:', $html );
		$this->assertStringContainsString( 'name="jl_ref" maxlength="100" value="John 1:14"', $html, 'the passage is prefilled but editable' );
	}

	/**
	 * Processing the reader's form: nonce, redirect, receipt, typed text kept on error.
	 */
	public function test_the_form_post_needs_its_nonce_and_keeps_typed_text_on_error() {
		$view = jlife_challenges_day_view( $this->u['a'], 'd001' );
		$ok   = jlife_questions_process_request(
			$this->u['a'],
			$view,
			array(
				'_jlife_ask'  => wp_create_nonce( 'jlife_ask_d001' ),
				'jl_question' => self::Q,
				'jl_ref'      => 'John 1:14',
			)
		);
		$this->assertSame( 'sent', $ok['result'] );
		$this->assertCount( 1, $this->rows() );
		$forged = jlife_questions_process_request(
			$this->u['a'],
			$view,
			array(
				'_jlife_ask'  => wp_create_nonce( 'jlife_soap_d001' ),
				'jl_question' => 'A different question for the pastors',
				'jl_ref'      => '',
			)
		);
		$this->assertSame( 'bad_nonce', $forged['result'] );
		$short = jlife_questions_process_request(
			$this->u['a'],
			$view,
			array(
				'_jlife_ask'  => wp_create_nonce( 'jlife_ask_d001' ),
				'jl_question' => 'short',
				'jl_ref'      => '',
			)
		);
		$this->assertSame( 'error', $short['result'] );
		$this->assertSame( 'short', $short['values']['jl_question'] );
		$this->assertCount( 1, $this->rows() );
	}

	/**
	 * The entry point shows on Today and on an earlier day when the feature is on, and never when it is off or the campaign is closed.
	 */
	public function test_the_entry_point_on_today_and_earlier_days() {
		$view = jlife_challenges_day_view( $this->u['a'], 'd001' );
		$html = jlife_challenges_today_body( $view );
		$this->assertStringContainsString( 'Ask the Pastors', $html );
		$this->assertStringContainsString( '/ask/?day=d001', $html );
		$this->assertLessThan( strpos( $html, 'class="jl-soap"' ), strpos( $html, 'Ask the Pastors' ), 'before the reflection editor' );
		$this->set_now( '2026-12-22 18:00:00' );
		$old = jlife_challenges_soap_body( jlife_challenges_day_view( $this->u['a'], 'd001' ) );
		$this->assertStringContainsString( '/ask/?day=d001', $old );
		update_option( 'jlife_questions_enabled', '0' );
		$this->assertStringNotContainsString( 'Ask the Pastors', jlife_challenges_today_body( jlife_challenges_day_view( $this->u['a'], 'd001' ) ) );
	}

	/**
	 * The My Marathon link shows only when on.
	 */
	public function test_the_marathon_link() {
		$this->assertStringContainsString( '/ask/', jlife_questions_marathon_link_html() );
		update_option( 'jlife_questions_enabled', '0' );
		$this->assertSame( '', jlife_questions_marathon_link_html() );
	}

	/**
	 * Every new string reads in Vietnamese, including the privacy line.
	 */
	public function test_the_strings_have_vietnamese_drafts() {
		$catalog = include dirname( __DIR__ ) . '/languages/jlife-challenges-vi.l10n.php';
		foreach ( array(
			'Have a Bible question? Ask the Pastors',
			'Submit Question to Pastors',
			'Bible Questions',
			'Under 18',
		) as $english ) {
			$this->assertArrayHasKey( $english, $catalog['messages'], $english );
		}
		$this->assertStringNotContainsString( 'contact church leadership', implode( ' ', array_keys( $catalog['messages'] ) ) );
	}
}
