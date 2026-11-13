<?php
/**
 * Participant screens (M6, #61): view models and the draft autosave endpoint.
 *
 * @package jlife-challenges
 */

/**
 * Screen tests.
 */
class Test_Screens extends Jlife_Challenges_TestCase {

	/**
	 * Someone not in any group sees the welcome state, not data.
	 */
	public function test_outsider_is_not_enrolled() {
		$this->assertSame( array( 'state' => 'not_enrolled' ), jlife_challenges_day_view( $this->u['outsider'] ) );
		$this->assertNull( jlife_challenges_marathon_view( $this->u['outsider'] ) );
	}

	/**
	 * Before Day 1 and after the last day, Today shows a calm message.
	 */
	public function test_before_and_after_campaign() {
		$this->set_now( '2026-12-19 20:00:00' ); // Dec 19 noon Pacific.
		$v = jlife_challenges_day_view( $this->u['a'] );
		$this->assertSame( 'before', $v['state'] );
		$this->assertSame( '2026-12-20', $v['start_date'] );

		$this->set_now( '2027-04-10 20:00:00' );
		$this->assertSame( 'after', jlife_challenges_day_view( $this->u['a'] )['state'] );
	}

	/**
	 * Today's view follows the participant through not started → draft → earned.
	 */
	public function test_today_status_progression() {
		$v = jlife_challenges_day_view( $this->u['a'] );
		$this->assertSame( 'active', $v['state'] );
		$this->assertSame( 1, $v['index'] );
		$this->assertSame( 'd001', $v['day_key'] );
		$this->assertSame( '2026-12-20', $v['date'] );
		$this->assertTrue( $v['is_today'] );
		$this->assertTrue( $v['on_time'] );
		$this->assertSame( 'not_started', $v['status'] );
		$this->assertSame( 104, $v['day_count'] );

		jlife_challenges_save_draft( $this->u['a'], $this->campaign, 'd001', array( 'scripture' => 'John 1:14' ) );
		$this->assertSame( 'draft', jlife_challenges_day_view( $this->u['a'] )['status'] );

		jlife_challenges_submit( $this->u['a'], $this->campaign, 'd001', $this->soap() );
		$v = jlife_challenges_day_view( $this->u['a'] );
		$this->assertSame( 'earned', $v['status'] );
		$this->assertSame( 1, $v['points'] );
		$this->assertSame( 'John 1:14 (x)', $v['entry']['scripture'] );
	}

	/**
	 * A past day written after the grace window is "done" without a point.
	 */
	public function test_late_day_status() {
		$this->set_now( '2026-12-29 20:00:00' ); // Day 10.
		$v = jlife_challenges_day_view( $this->u['a'], 'd003' );
		$this->assertSame( 'active', $v['state'] );
		$this->assertFalse( $v['is_today'] );
		$this->assertFalse( $v['on_time'] );
		jlife_challenges_submit( $this->u['a'], $this->campaign, 'd003', $this->soap() );
		$this->assertSame( 'late_no_point', jlife_challenges_day_view( $this->u['a'], 'd003' )['status'] );
	}

	/**
	 * Future and nonsense days are unavailable.
	 */
	public function test_future_day_unavailable() {
		$this->assertSame( 'unavailable', jlife_challenges_day_view( $this->u['a'], 'd002' )['state'] );
		$this->assertSame( 'unavailable', jlife_challenges_day_view( $this->u['a'], 'nope' )['state'] );
	}

	/**
	 * Each participant only ever sees their own entry.
	 */
	public function test_view_shows_only_own_entry() {
		jlife_challenges_submit( $this->u['b'], $this->campaign, 'd001', $this->soap( 'b-private' ) );
		$v = jlife_challenges_day_view( $this->u['a'] );
		$this->assertNull( $v['entry'] );
		$this->assertSame( 'not_started', $v['status'] );
		$this->assertSame( 0, $v['points'] );
	}

	/**
	 * The participant's language comes first.
	 */
	public function test_language_order_follows_locale() {
		$config = array(
			'en' => array(),
			'vi' => array(),
		);
		$this->assertSame( array( 'en', 'vi' ), jlife_challenges_language_order( $this->u['a'], $config ) );
		update_user_meta( $this->u['a'], 'locale', 'vi' );
		$this->assertSame( array( 'vi', 'en' ), jlife_challenges_language_order( $this->u['a'], $config ) );
	}

	/**
	 * The grid marks earned, late, draft, open, not done, today and future days.
	 */
	public function test_marathon_grid_states() {
		jlife_challenges_submit( $this->u['a'], $this->campaign, 'd001', $this->soap() );        // Day 1 on time.
		$this->set_now( '2026-12-26 20:00:00' );                                                 // Day 7.
		jlife_challenges_submit( $this->u['a'], $this->campaign, 'd002', $this->soap() );        // Late.
		jlife_challenges_save_draft( $this->u['a'], $this->campaign, 'd004', array( 'prayer' => 'p' ) );

		$v = jlife_challenges_marathon_view( $this->u['a'] );
		$this->assertSame( 1, $v['points'] );
		$this->assertSame( 7, $v['today'] );
		$this->assertCount( 104, $v['cells'] );
		$states = array_column( $v['cells'], 'state', 'day_key' );
		$this->assertSame( 'earned', $states['d001'] );
		$this->assertSame( 'late', $states['d002'] );
		$this->assertSame( 'not_done', $states['d003'] );
		$this->assertSame( 'draft', $states['d004'] );
		$this->assertSame( 'open', $states['d006'], 'yesterday is still earnable (grace day)' );
		$this->assertSame( 'today', $states['d007'] );
		$this->assertSame( 'future', $states['d008'] );
		$this->assertSame( 'future', $states['d104'] );
	}

	/**
	 * Autosave: signed-out callers are refused; outsiders get the uniform
	 * denial; a member's draft is saved for themselves only.
	 */
	public function test_rest_draft_autosave() {
		$server = rest_get_server();
		$make   = function ( $user_campaign ) {
			$req = new WP_REST_Request( 'POST', '/jlife/v1/soap/draft' );
			$req->set_body_params(
				array(
					'campaign_id' => $user_campaign,
					'day_key'     => 'd001',
					'fields'      => array( 'observation' => 'autosaved' ),
				)
			);
			return $req;
		};

		wp_set_current_user( 0 );
		$this->assertSame( 401, $server->dispatch( $make( $this->campaign ) )->get_status() );

		wp_set_current_user( $this->u['outsider'] );
		$r = $server->dispatch( $make( $this->campaign ) );
		$this->assertSame( 403, $r->get_status() );
		$this->assertSame( 'jlife_denied', $r->get_data()['code'] );

		wp_set_current_user( $this->u['a'] );
		$r = $server->dispatch( $make( $this->campaign ) );
		$this->assertSame( 200, $r->get_status() );
		$this->assertSame( 'autosaved', jlife_challenges_find_entry( $this->u['a'], $this->campaign, 'd001' )['observation'] );
		$this->assertNull( jlife_challenges_find_entry( $this->u['b'], $this->campaign, 'd001' ) );
		$this->assertSame( 0, $this->ledger_rows( $this->u['a'] ), 'a draft never scores' );
	}

	/**
	 * Every user-facing status line exists for every state.
	 */
	public function test_status_text_covers_states() {
		foreach ( array( 'earned', 'late_no_point', 'draft', 'not_started', 'submitted' ) as $s ) {
			$this->assertNotSame(
				'',
				jlife_challenges_status_text(
					array(
						'status'  => $s,
						'on_time' => true,
					)
				)
			);
		}
	}

	/**
	 * Today / SOAP reading block: one Bible Gateway button per language, in
	 * the participant's order, for the assigned and the optional readings (#91).
	 */
	public function test_reading_block_has_one_button_per_language_for_both_lists() {
		$links = static function ( $search ) {
			$out      = array();
			$versions = array(
				'en' => 'ERV',
				'vi' => 'BPT',
			);
			foreach ( $versions as $lang => $version ) {
				$url          = 'https://www.biblegateway.com/passage/?search=' . $search . '&version=' . $version;
				$out[ $lang ] = array(
					'provider' => 'bible_gateway',
					'version'  => $version,
					'combined' => array(
						'label' => $search,
						'url'   => $url,
					),
					'passages' => array(),
				);
			}
			return $out;
		};

		$config = array(
			'en' => array( 'label' => 'English' ),
			'vi' => array( 'label' => 'Tiếng Việt' ),
		);
		$view   = array(
			'languages' => array( 'vi', 'en' ),
			'reading'   => array(
				'plan'         => array( 'links_config' => $config ),
				'day'          => array(
					'title'    => 'John the Baptizer Prepares the Way',
					'passages' => array( array( 'display' => 'Luke 3:1-6' ) ),
					'links'    => $links( 'Luke+3:1-6;Matthew+3:4-10;Luke+3:10-18' ),
				),
				'supplemental' => array(
					'plan' => array( 'links_config' => $config ),
					'day'  => array(
						'passages' => array( array( 'display' => 'Mark 1:1' ) ),
						'links'    => $links( 'Mark+1:1;Luke+3:1-2' ),
					),
				),
			),
		);

		$html  = jlife_challenges_render_reading( $view );
		$parts = explode( '<details class="jl-optional">', $html );
		$this->assertCount( 2, $parts );
		list( $main, $optional ) = $parts;

		$this->assertSame( 2, substr_count( $main, 'class="jl-button"' ) );
		$this->assertStringContainsString( esc_url( 'https://www.biblegateway.com/passage/?search=Luke+3:1-6;Matthew+3:4-10;Luke+3:10-18&version=BPT' ), $main );
		$this->assertLessThan( strpos( $main, 'version=ERV' ), strpos( $main, 'version=BPT' ), 'Vietnamese first for a Vietnamese participant' );
		$this->assertStringContainsString( 'Read in Tiếng Việt (BPT)', $main );

		$this->assertSame( 2, substr_count( $optional, 'class="jl-button"' ) );
		$this->assertStringContainsString( esc_url( 'https://www.biblegateway.com/passage/?search=Mark+1:1;Luke+3:1-2&version=BPT' ), $optional );
	}

	/**
	 * Sign out asks first (#89): Cancel stays put, Sign out uses WordPress's
	 * nonce-protected logout and lands on the sign-in screen.
	 */
	public function test_signout_prompt() {
		$back   = jlife_challenges_screen_url( 'soap', 'd009' );
		$dialog = jlife_challenges_signout_prompt( $back, true );
		$this->assertStringContainsString( '<form method="dialog">', $dialog, 'Cancel closes the dialog without leaving the page' );
		$this->assertStringContainsString( esc_url( wp_logout_url( home_url( '/signin/' ) ) ), $dialog );
		$this->assertStringContainsString( '_wpnonce=', $dialog );

		$page = jlife_challenges_signout_prompt( $back, false );
		$this->assertStringContainsString( 'href="' . esc_url( $back ) . '"', $page, 'Cancel on the no-JS page returns to the same screen and day' );
		$this->assertSame( add_query_arg( 'day', 'd009', home_url( '/soap/' ) ), $back );
		$this->assertSame( home_url( '/today/' ), jlife_challenges_screen_url( 'https://evil.test/' ), 'only known screens' );
		$this->assertSame( home_url( '/group/' ), jlife_challenges_screen_url( 'group' ), 'My Group (#62) is a known screen' );
	}
}
