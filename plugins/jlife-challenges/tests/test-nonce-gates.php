<?php
/**
 * Every form handler refuses a post with a bad or missing nonce, saves nothing
 * and stops with wp_die() (#257): the nonce check is a statement after the
 * "is this our form" test, so it must still end the request.
 *
 * @package jlife-challenges
 */

// phpcs:disable WordPress.Security.NonceVerification -- tests drive the handlers with crafted requests.

/**
 * Nonce gate tests.
 */
class Test_Nonce_Gates extends Jlife_Challenges_TestCase {

	/**
	 * Clean request state.
	 */
	public function tear_down() {
		$_POST    = array();
		$_REQUEST = array();
		wp_set_current_user( 0 );
		parent::tear_down();
	}

	/**
	 * Handlers that take our form marker: callable, form marker field, nonce field.
	 *
	 * @return array<string, array>
	 */
	public function handler_provider() {
		return array(
			'past unshare' => array( 'past_unshare', 'jl_unshare_past', '_jlife_unshare_past' ),
			'old unshare'  => array( 'old_unshare', 'jl_unshare_old', '_jlife_unshare' ),
			'join group'   => array( 'join', 'jl_invite_code', '_jlife_join' ),
			'manage group' => array( 'manage', 'jl_group_action', '_jlife_group' ),
			'bible choice' => array( 'bible', 'jl_bible', '_jlife_bible' ),
			'reminder'     => array( 'reminder', 'jl_reminder', '_jlife_reminder' ),
			'language'     => array( 'language', 'jl_language', '_jlife_language' ),
		);
	}

	/**
	 * Run one handler as a user.
	 *
	 * @param string $which   Handler key.
	 * @param int    $user_id User.
	 */
	private function run_handler( $which, $user_id ) {
		switch ( $which ) {
			case 'past_unshare':
				return jlife_challenges_handle_past_unshare_post( $user_id, $this->campaign );
			case 'old_unshare':
				return jlife_challenges_handle_old_share_post( $user_id );
			case 'join':
				return jlife_challenges_handle_join_post( $user_id );
			case 'manage':
				return jlife_challenges_handle_manage_post( $user_id );
			case 'bible':
				return jlife_challenges_handle_bible_post( $user_id );
			case 'reminder':
				return jlife_challenges_handle_reminder_post( $user_id, $this->campaign );
			default:
				return jlife_challenges_handle_language_post( $user_id );
		}
	}

	/**
	 * A missing or wrong nonce stops the request; no nonce marker means "not our post".
	 *
	 * @dataProvider handler_provider
	 *
	 * @param string $which Handler key.
	 * @param string $field Form marker field.
	 * @param string $nonce Nonce field name.
	 */
	public function test_bad_or_missing_nonce_stops_the_request( $which, $field, $nonce ) {
		$user = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		jlife_challenges_enroll_solo( $user, $this->campaign, 'adult' );
		wp_set_current_user( $user );

		// Not our form: quietly ignored, no die.
		$_POST    = array();
		$_REQUEST = $_POST;
		$this->run_handler( $which, $user );
		$this->addToAssertionCount( 1 );

		// Our form, no nonce, then a wrong nonce: both must die.
		foreach ( array( array(), array( $nonce => 'bad' ) ) as $extra ) {
			$_POST    = array( $field => '1' ) + $extra;
			$_REQUEST = $_POST;
			try {
				$this->run_handler( $which, $user );
				$this->fail( 'a request without a valid nonce must stop' );
			} catch ( WPDieException $e ) {
				$this->assertNotEmpty( $e->getMessage() );
			}
		}
	}

	/**
	 * Rows a participant's SOAP save would write for the campaign.
	 *
	 * @param int $user_id User.
	 * @return array{entries:int,revisions:int,ledger:int,shares:int}
	 */
	private function written( $user_id ) {
		global $wpdb;
		$count = static function ( $table, $where ) use ( $wpdb ) {
			return (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . jlife_challenges_table( $table ) . ' WHERE ' . $where ); // phpcs:ignore WordPress.DB
		};
		$uid   = (int) $user_id;
		$cid   = (int) $this->campaign;
		return array(
			'entries'   => $count( 'soap_entries', "user_id = $uid AND campaign_id = $cid" ),
			'revisions' => $count( 'soap_revisions', "user_id = $uid" ),
			'ledger'    => $count( 'score_ledger', "user_id = $uid AND campaign_id = $cid" ),
			'shares'    => $count( 'group_shares', "campaign_id = $cid" ),
		);
	}

	/**
	 * A missing or wrong nonce stops a SOAP save: nothing is saved, scored or shared.
	 */
	public function test_soap_save_needs_its_nonce() {
		$user = $this->u['a'];
		wp_set_current_user( $user );
		$view = jlife_challenges_day_view( $user, 'd001' );
		$this->assertSame( 'd001', $view['day_key'] );
		$before = $this->written( $user );

		foreach ( array( array(), array( '_jlife_soap' => 'bad' ), array( '_jlife_soap' => wp_create_nonce( 'jlife_soap_d002' ) ) ) as $extra ) {
			$_POST    = array(
				'jl_text'         => $this->soap( 'nonce' ),
				'jl_action'       => 'submit',
				'jl_day'          => 'd001',
				'jl_share_groups' => array( (string) $this->g['g1'] ),
			) + $extra;
			$_REQUEST = $_POST;
			try {
				jlife_challenges_process_soap_request( $user, $view, 'soap' );
				$this->fail( 'a SOAP post without a valid nonce for its day must stop' );
			} catch ( WPDieException $e ) {
				$this->assertSame( $before, $this->written( $user ), 'nothing saved, scored or shared' );
			}
		}

		// Control: the same post with the right nonce is accepted and does write.
		$_POST    = array(
			'jl_text'     => $this->soap( 'nonce' ),
			'jl_action'   => 'draft',
			'jl_day'      => 'd001',
			'_jlife_soap' => wp_create_nonce( 'jlife_soap_d001' ),
		);
		$_REQUEST = $_POST;
		$state    = jlife_challenges_process_soap_request( $user, $view, 'soap' );
		$this->assertSame( '', $state['error'] );
		$this->assertSame( $before['entries'] + 1, $this->written( $user )['entries'], 'the control draft was saved' );
	}

	/**
	 * A missing or wrong nonce stops a share post on the /share screen: no share row is made.
	 *
	 * The screen function is called as a whole: it only sends a page (and exits)
	 * after its nonce line, so a bad nonce is the one path that ends in wp_die().
	 */
	public function test_share_post_needs_its_nonce() {
		$user = $this->u['a'];
		$this->assertIsArray( jlife_challenges_submit( $user, $this->campaign, 'd001', $this->soap( 'share' ) ) );
		wp_set_current_user( $user );
		$before = $this->written( $user );

		foreach ( array( array(), array( '_jlife_share' => 'bad' ), array( '_jlife_share' => wp_create_nonce( 'jlife_soap_d001' ) ) ) as $extra ) {
			$_POST    = array(
				'jl_share_action' => 'post',
				'jl_share_groups' => array( (string) $this->g['g1'] ),
			) + $extra;
			$_REQUEST = $_POST;
			try {
				jlife_challenges_screen_share();
				$this->fail( 'a share post without a valid nonce must stop' );
			} catch ( WPDieException $e ) {
				$this->assertSame( $before, $this->written( $user ), 'no group share was created' );
				$this->assertSame( 0, $before['shares'] );
			}
		}
	}
}
