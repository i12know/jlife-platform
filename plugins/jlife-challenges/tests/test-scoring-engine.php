<?php
/**
 * Scoring engines (#98): the soap-regex pass/fail table, the engine registry
 * and per-campaign choice, the entry template (English and Vietnamese), and the
 * migration of the old four-field rows.
 *
 * @package jlife-challenges
 */

/**
 * Scoring-engine tests.
 */
class Test_Scoring_Engine extends Jlife_Challenges_TestCase {

	/**
	 * Run the default engine on a text.
	 *
	 * @param string $text Entry text.
	 * @return array
	 */
	private function score( $text ) {
		return jlife_challenges_score_entry( $text, array( 'campaign' => $this->campaign_row() ) );
	}

	/**
	 * Texts that must pass: S, O, A, P in order, each with content.
	 *
	 * @return array<string,array{0:string}>
	 */
	public function passing_texts() {
		return array(
			'template headings'       => array( "S — Scripture\nJohn 1:14\n\nO — Observation\nThe Word\n\nA — Application\nBe present\n\nP — Prayer\nLord, help me" ),
			'colons'                  => array( "S: John 1:14\nO: The Word\nA: Be present\nP: Lord, help me" ),
			'hyphens'                 => array( "S - a\nO - b\nA - c\nP - d" ),
			'en dashes'               => array( "S – a\nO – b\nA – c\nP – d" ),
			'em dashes, no spaces'    => array( "S—a\nO—b\nA—c\nP—d" ),
			'lower case letters'      => array( "s: a\no: b\na: c\np: d" ),
			'heading word, then text' => array( "S — Scripture John 3:16\nO — Observation b\nA — Application c\nP — Prayer d" ),
			'text on the next line'   => array( "S:\nJohn 3:16\nO:\nb\nA:\nc\nP:\nd" ),
			'several lines each'      => array( "S: a\nmore\nO: b\nmore\nA: c\nmore\nP: d\nmore" ),
			'windows line breaks'     => array( "S: a\r\nO: b\r\nA: c\r\nP: d" ),
			'extra blank lines'       => array( "\n\nS: a\n\n\nO: b\n\n\nA: c\n\n\nP: d\n\n" ),
			'vietnamese headings'     => array( "S — Kinh Thánh\nGa 1:14\n\nO — Quan sát\nb\n\nA — Áp dụng\nc\n\nP — Cầu nguyện\nd" ),
			'vietnamese, lower case'  => array( "s — kinh thánh: Ga 1:14\no — quan sát: b\na — áp dụng: c\np — cầu nguyện: d" ),
		);
	}

	/**
	 * Complete entries pass, in every accepted heading style.
	 *
	 * @dataProvider passing_texts
	 * @param string $text Entry text.
	 */
	public function test_complete_entries_pass( $text ) {
		$r = $this->score( $text );
		$this->assertTrue( $r['passes'], wp_json_encode( $r['reasons'] ) );
		$this->assertSame( array(), $r['reasons'] );
	}

	/**
	 * Texts that must not pass, with a fragment each reason must contain.
	 *
	 * @return array<string,array{0:string,1:string[]}>
	 */
	public function failing_texts() {
		return array(
			'bare template'             => array(
				"S — Scripture\n\nO — Observation\n\nA — Application\n\nP — Prayer",
				array( 'Add something under S — Scripture', 'Add something under O — Observation', 'Add something under A — Application', 'Add something under P — Prayer' ),
			),
			'bare vietnamese template'  => array(
				"S — Kinh Thánh\n\nO — Quan sát\n\nA — Áp dụng\n\nP — Cầu nguyện",
				array( 'Add something under S — Scripture', 'Add something under O — Observation', 'Add something under A — Application', 'Add something under P — Prayer' ),
			),
			'heading words only'        => array(
				"S: Scripture\nO: Observation\nA: Application\nP: Prayer",
				array( 'Add something under S — Scripture', 'Add something under O — Observation', 'Add something under A — Application', 'Add something under P — Prayer' ),
			),
			'missing prayer'            => array(
				"S: a\nO: b\nA: c",
				array( 'Add the P — Prayer part' ),
			),
			'missing application'       => array(
				"S: a\nO: b\nP: d",
				array( 'Add the A — Application part' ),
			),
			'empty observation'         => array(
				"S: a\nO:\n\nA: c\nP: d",
				array( 'Add something under O — Observation' ),
			),
			'whitespace only prayer'    => array(
				"S: a\nO: b\nA: c\nP:   \n   \n",
				array( 'Add something under P — Prayer' ),
			),
			'out of order'              => array(
				"O: b\nS: a\nA: c\nP: d",
				array( 'Put the parts in order' ),
			),
			'prayer first'              => array(
				"P: d\nS: a\nO: b\nA: c",
				array( 'Put the parts in order' ),
			),
			'letters not at line start' => array(
				'Today I read S: John and O: more, A: this, P: that',
				array( 'Add the S — Scripture part', 'Add the O — Observation part', 'Add the A — Application part', 'Add the P — Prayer part' ),
			),
			'free text only'            => array(
				'I loved this passage about John the Baptist.',
				array( 'Add the S — Scripture part', 'Add the O — Observation part', 'Add the A — Application part', 'Add the P — Prayer part' ),
			),
			'empty'                     => array(
				'',
				array( 'Add the S — Scripture part', 'Add the O — Observation part', 'Add the A — Application part', 'Add the P — Prayer part' ),
			),
		);
	}

	/**
	 * Incomplete entries fail, with kind reasons naming what to add.
	 *
	 * @dataProvider failing_texts
	 * @param string   $text      Entry text.
	 * @param string[] $fragments Fragments the reasons must contain, in order.
	 */
	public function test_incomplete_entries_fail_with_reasons( $text, $fragments ) {
		$r = $this->score( $text );
		$this->assertFalse( $r['passes'] );
		$this->assertCount( count( $fragments ), $r['reasons'], wp_json_encode( $r['reasons'] ) );
		foreach ( $fragments as $i => $fragment ) {
			$this->assertStringContainsString( $fragment, $r['reasons'][ $i ] );
		}
	}

	/**
	 * The result has the contract's shape, names the engine and version, and is
	 * deterministic.
	 */
	public function test_result_shape_and_determinism() {
		$text = $this->soap_without_prayer();
		$a    = $this->score( $text );
		$b    = $this->score( $text );
		$this->assertSame( $a, $b );
		$this->assertSame( array( 'passes', 'reasons', 'engine', 'version' ), array_keys( $a ) );
		$this->assertSame( 'soap-regex', $a['engine'] );
		$this->assertSame( '1', $a['version'] );
		$this->assertIsBool( $a['passes'] );
		$this->assertContainsOnly( 'string', $a['reasons'] );
	}

	/**
	 * A campaign picks its engine; unknown or missing falls back to soap-regex;
	 * engines are added with a filter and never need the default to change.
	 */
	public function test_campaign_chooses_its_engine() {
		$this->assertSame( 'soap-regex', jlife_challenges_campaign_engine( $this->campaign_row() ), 'the default' );
		$this->assertSame( 'soap-regex', jlife_challenges_campaign_engine( array( 'scoring_engine' => 'no-such-engine' ) ) );
		$this->assertSame( 'soap-regex', jlife_challenges_campaign_engine( null ), 'personal entries' );

		add_filter(
			'jlife_challenges_scoring_engines',
			static function ( $engines ) {
				$engines['always'] = array(
					'label'    => 'Always',
					'version'  => '9',
					'callback' => static fn( $text ) => array(
						'passes'  => '' !== trim( (string) $text ),
						'reasons' => array(),
					),
				);
				return $engines;
			}
		);
		$this->assertWPError(
			jlife_challenges_create_campaign(
				$this->u['organizer'],
				array(
					'campaign_key'   => 'bad-engine',
					'plan_id'        => 'p',
					'title'          => 't',
					'start_date'     => '2026-12-20',
					'end_date'       => '2027-04-02',
					'scoring_engine' => 'nope',
				)
			)
		);
		$other = jlife_challenges_create_campaign(
			$this->u['organizer'],
			array(
				'campaign_key'   => 'engine-test-' . wp_generate_password( 6, false ),
				'plan_id'        => 'rpl-life-and-teachings-of-jesus-2026',
				'title'          => 'Other engine (test)',
				'start_date'     => '2026-12-20',
				'end_date'       => '2027-04-02',
				'status'         => 'active',
				'scoring_engine' => 'always',
			)
		);
		$this->assertIsInt( $other );
		$group = jlife_challenges_create_group( $this->u['organizer'], $other, 'Other Group' )['id'];
		$this->assertIsInt( jlife_challenges_enroll( $this->u['organizer'], $this->u['outsider'], $group ) );

		// The same plain sentence passes under the campaign that chose "always", not under soap-regex.
		$r = jlife_challenges_submit( $this->u['outsider'], $other, null, 'Just a sentence.' );
		$this->assertTrue( $r['passes'] );
		$this->assertSame( 1, $r['points'] );
		$this->assertSame( 'always', $r['engine'] );
		$this->assertFalse( jlife_challenges_submit( $this->u['a'], $this->campaign, null, 'Just a sentence.' )['passes'] );

		// The ledger records which engine and version awarded the point.
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT engine, engine_version FROM ' . jlife_challenges_table( 'score_ledger' ) . ' WHERE campaign_id = %d AND user_id = %d', $other, $this->u['outsider'] ), ARRAY_A ); // phpcs:ignore WordPress.DB
		$this->assertSame(
			array(
				'engine'         => 'always',
				'engine_version' => '9',
			),
			$row
		);
	}

	/**
	 * New entries start with the four headings, a blank line apart, and the
	 * untouched template earns nothing.
	 */
	public function test_template_is_the_four_headings_and_does_not_pass() {
		$template = jlife_challenges_soap_template();
		$this->assertSame( "S — Scripture\n\nO — Observation\n\nA — Application\n\nP — Prayer", $template );
		$this->assertFalse( $this->score( $template )['passes'] );
		$this->assertSame( $template, jlife_challenges_join_sections( array(), jlife_challenges_soap_headings() ), 'four empty sections are exactly the template' );
		$this->assertSame( $template, jlife_challenges_entry_text( jlife_challenges_day_view( $this->u['a'] ) ), 'a new entry is prefilled with it' );

		jlife_challenges_save_draft( $this->u['a'], $this->campaign, null, "S — Scripture\nJohn 1\n\nO — Observation" );
		$this->assertSame( "S — Scripture\nJohn 1\n\nO — Observation", jlife_challenges_entry_text( jlife_challenges_day_view( $this->u['a'] ) ), 'a saved draft is shown as saved' );
	}

	/**
	 * The Vietnamese translation covers the four headings, and a Vietnamese
	 * participant's filled-in template passes while the bare one does not.
	 */
	public function test_vietnamese_template_translation() {
		$catalog = require dirname( __DIR__ ) . '/languages/jlife-challenges-vi.l10n.php';
		$this->assertSame( 'vi', $catalog['language'] );
		$vi = array();
		foreach ( jlife_challenges_soap_headings_en() as $letter => $english ) {
			$this->assertArrayHasKey( $english, $catalog['messages'], "$english is translated" );
			$vi[ $letter ] = $catalog['messages'][ $english ];
			$this->assertStringStartsWith( $letter . ' — ', $vi[ $letter ], 'the letter stays' );
		}
		$bare = jlife_challenges_join_sections( array(), $vi );
		$this->assertFalse( $this->score( $bare )['passes'], 'the bare Vietnamese template does not pass' );
		$filled = jlife_challenges_join_sections(
			array(
				'S' => 'Ga 1:14',
				'O' => 'Ngôi Lời trở nên xác thịt',
				'A' => 'Ở bên gia đình',
				'P' => 'Lạy Chúa, xin giúp con',
			),
			$vi
		);
		$this->assertTrue( $this->score( $filled )['passes'], 'filled in, it passes' );
		$this->assertArrayHasKey( 'Add something under %s', $catalog['messages'], 'the reasons are translated too' );
	}

	/**
	 * Create a scratch table shaped like the pre-#98 tables (a temporary table:
	 * no implicit commit, gone at the end of the connection).
	 *
	 * @param string $name  Short name.
	 * @param bool   $shares True for the group-share shape (nullable text, a fields column).
	 * @return string Full table name.
	 */
	private function legacy_table( $name, $shares = false ) {
		global $wpdb;
		$table = $wpdb->prefix . 'jlife_probe_' . $name;
		$null  = $shares ? 'NULL' : 'NOT NULL';
		// phpcs:disable WordPress.DB
		$wpdb->query( "DROP TEMPORARY TABLE IF EXISTS {$table}" );
		$wpdb->query( "CREATE TEMPORARY TABLE {$table} (id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY, fields VARCHAR(60) NOT NULL DEFAULT '', scripture LONGTEXT {$null}, observation LONGTEXT {$null}, application LONGTEXT {$null}, prayer LONGTEXT {$null}, body LONGTEXT {$null})" );
		// phpcs:enable WordPress.DB
		return $table;
	}

	/**
	 * Old four-field entries become one text under the four headings, line
	 * breaks kept; empty rows are left alone; rows with a body are not touched;
	 * running it again changes nothing; and the result scores.
	 */
	public function test_migration_joins_the_four_fields_under_headings() {
		global $wpdb;
		$table = $this->legacy_table( 'entries' );
		$rows  = array(
			array( 'John 1:14', "Line one\nLine two\n\nAfter a blank", 'Be present', 'Lord, help me', '' ),
			array( 'x', '', 'y', 'z', '' ),
			array( '', '', '', '', '' ),
			array( 'a', 'b', 'c', 'd', 'already new' ),
		);
		foreach ( $rows as $r ) {
			$wpdb->insert( // phpcs:ignore WordPress.DB
				$table,
				array(
					'scripture'   => $r[0],
					'observation' => $r[1],
					'application' => $r[2],
					'prayer'      => $r[3],
					'body'        => $r[4],
				)
			);
		}
		$result = jlife_challenges_copy_legacy_to_body( $table );
		$this->assertSame(
			array(
				'migrated' => 2,
				'failed'   => 0,
			),
			$result
		);
		$bodies = $wpdb->get_col( "SELECT body FROM {$table} ORDER BY id" ); // phpcs:ignore WordPress.DB
		$this->assertSame( "S — Scripture\nJohn 1:14\n\nO — Observation\nLine one\nLine two\n\nAfter a blank\n\nA — Application\nBe present\n\nP — Prayer\nLord, help me", $bodies[0] );
		$this->assertSame( "S — Scripture\nx\n\nO — Observation\n\nA — Application\ny\n\nP — Prayer\nz", $bodies[1], 'an empty part keeps its heading' );
		$this->assertSame( '', $bodies[2], 'nothing to move' );
		$this->assertSame( 'already new', $bodies[3], 'a row with a body is left alone' );
		$this->assertTrue( $this->score( $bodies[0] )['passes'], 'migrated entries score' );

		$this->assertSame(
			array(
				'migrated' => 0,
				'failed'   => 0,
			),
			jlife_challenges_copy_legacy_to_body( $table ),
			'idempotent: a second run has nothing left to move'
		);
	}

	/**
	 * Old group shares only held the parts that were chosen; the joined body
	 * keeps just those, under their headings.
	 */
	public function test_migration_of_shares_keeps_only_the_chosen_parts() {
		global $wpdb;
		$table = $this->legacy_table( 'shares', true );
		$wpdb->insert( // phpcs:ignore WordPress.DB
			$table,
			array(
				'fields'      => 'observation,prayer',
				'scripture'   => null,
				'observation' => "Seen\nTwice",
				'application' => null,
				'prayer'      => 'Amen',
				'body'        => null,
			)
		);
		$wpdb->insert( // phpcs:ignore WordPress.DB
			$table,
			array(
				'fields'      => 'observation',
				'scripture'   => null,
				'observation' => null,
				'application' => null,
				'prayer'      => null,
				'body'        => null,
			)
		);
		$this->assertSame( 1, jlife_challenges_copy_legacy_to_body( $table, true )['migrated'], 'a removed share has no text to move' );
		$bodies = $wpdb->get_col( "SELECT body FROM {$table} ORDER BY id" ); // phpcs:ignore WordPress.DB
		$this->assertSame( "O — Observation\nSeen\nTwice\n\nP — Prayer\nAmen", $bodies[0] );
		$this->assertNull( $bodies[1] );
	}

	/**
	 * On the current schema there is nothing to upgrade, and running it does no harm.
	 */
	public function test_upgrade_is_a_no_op_on_the_current_schema() {
		$this->assertSame( 0, jlife_challenges_upgrade_data() );
		$this->assertContains( 'body', jlife_challenges_table_columns( jlife_challenges_table( 'soap_entries' ) ) );
		$this->assertNotContains( 'prayer', jlife_challenges_table_columns( jlife_challenges_table( 'soap_entries' ) ) );
		$this->assertContains( 'engine_version', jlife_challenges_table_columns( jlife_challenges_table( 'score_ledger' ) ) );
		$this->assertSame( array(), jlife_challenges_table_columns( 'bad name; DROP TABLE x' ), 'unsafe names are refused' );
	}
}
