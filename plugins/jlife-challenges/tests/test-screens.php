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

		jlife_challenges_save_draft( $this->u['a'], $this->campaign, 'd001', "S — Scripture\nJohn 1:14" );
		$this->assertSame( 'draft', jlife_challenges_day_view( $this->u['a'] )['status'] );

		jlife_challenges_submit( $this->u['a'], $this->campaign, 'd001', $this->soap() );
		$v = jlife_challenges_day_view( $this->u['a'] );
		$this->assertSame( 'earned', $v['status'] );
		$this->assertSame( 1, $v['points'] );
		$this->assertStringContainsString( "S — Scripture\nJohn 1:14 (x)\n", $v['entry']['body'] );
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
		jlife_challenges_save_draft( $this->u['a'], $this->campaign, 'd004', 'P: p' );

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
					'text'        => 'autosaved',
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
		$this->assertSame( 'autosaved', jlife_challenges_find_entry( $this->u['a'], $this->campaign, 'd001' )['body'] );
		$this->assertNull( jlife_challenges_find_entry( $this->u['b'], $this->campaign, 'd001' ) );
		$this->assertSame( 0, $this->ledger_rows( $this->u['a'] ), 'a draft never scores' );
		$this->assertSame( 0, $this->share_rows(), 'and never shares' );

		// A page left open from before the single textbox still sends the four parts: they are joined, not lost.
		$legacy = new WP_REST_Request( 'POST', '/jlife/v1/soap/draft' );
		$legacy->set_body_params(
			array(
				'campaign_id' => $this->campaign,
				'day_key'     => 'd001',
				'fields'      => array(
					'scripture'   => 'John 1:14',
					'observation' => "Line one\nLine two",
					'prayer'      => 'Lord',
				),
			)
		);
		$this->assertSame( 200, $server->dispatch( $legacy )->get_status() );
		$this->assertSame( "S — Scripture\nJohn 1:14\n\nO — Observation\nLine one\nLine two\n\nA — Application\n\nP — Prayer\nLord", jlife_challenges_find_entry( $this->u['a'], $this->campaign, 'd001' )['body'] );

		$none = new WP_REST_Request( 'POST', '/jlife/v1/soap/draft' );
		$none->set_body_params(
			array(
				'campaign_id' => $this->campaign,
				'day_key'     => 'd001',
			)
		);
		$this->assertSame( 400, $server->dispatch( $none )->get_status(), 'neither text nor parts' );
	}

	/**
	 * How many share rows exist for the campaign (any status).
	 *
	 * @return int
	 */
	private function share_rows() {
		global $wpdb;
		return (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . jlife_challenges_table( 'group_shares' ) . ' WHERE campaign_id = %d', $this->campaign ) ); // phpcs:ignore WordPress.DB
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

		$this->assertStringNotContainsString( '<li>Mark 1:1</li>', $optional, 'no bulleted passage list in the optional readings (#90)' );
		$this->assertSame( 2, substr_count( $optional, 'class="jl-button"' ) );
		$this->assertStringContainsString( esc_url( 'https://www.biblegateway.com/passage/?search=Mark+1:1;Luke+3:1-2&version=BPT' ), $optional );
	}

	/**
	 * The optional readings list their Harmony Bible sections (#90): number and
	 * title, once each, linking to the matching page in a new tab.
	 */
	public function test_optional_readings_list_harmony_sections() {
		$section = static function ( $n, $title ) {
			return array(
				'number' => (string) $n,
				'title'  => $title,
				'url'    => 'https://harmony-bible.com/sections/' . $n . '/',
			);
		};
		$view    = array(
			'languages' => array( 'en' ),
			'reading'   => array(
				'plan'         => array( 'links_config' => array() ),
				'day'          => array(
					'title'    => 'John the Baptizer Prepares the Way',
					'passages' => array( array( 'display' => 'Luke 3:1-6' ) ),
				),
				'supplemental' => array(
					'plan' => array( 'links_config' => array() ),
					'day'  => array(
						'passages'         => array( array( 'display' => 'Mark 1:1' ) ),
						'harmony_sections' => array(
							$section( 20, 'The Time of the Beginning' ),
							$section( 21, 'The Message and the Messenger' ),
							$section( 22, "A Specimen of John's Preaching" ),
							$section( 23, '<b>Forerunner</b>' ),
							array(
								'number' => '99',
								'title'  => 'Elsewhere',
								'url'    => 'https://example.com/sections/99/',
							),
						),
					),
				),
			),
		);

		$html  = jlife_challenges_render_reading( $view );
		$parts = explode( '<details class="jl-optional">', $html );
		$this->assertCount( 2, $parts );
		$this->assertStringNotContainsString( 'harmony-bible.com', $parts[0], 'sections belong to the optional readings only' );

		$this->assertSame( 4, substr_count( $parts[1], 'href="https://harmony-bible.com/sections/' ), 'one link per section; foreign hosts are dropped' );
		$this->assertStringContainsString( '<a href="https://harmony-bible.com/sections/20/" target="_blank" rel="noopener">Harmony&#039;s section 20: The Time of the Beginning</a>', $parts[1] );
		$this->assertStringContainsString( 'sections/23/', $parts[1] );
		$this->assertLessThan( strpos( $parts[1], 'sections/21/' ), strpos( $parts[1], 'sections/20/' ), 'reading order' );
		$this->assertStringNotContainsString( '<b>Forerunner', $parts[1], 'titles are escaped' );
		$this->assertStringNotContainsString( 'example.com', $parts[1] );
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

	/**
	 * A day view with Bible Gateway links in English and Vietnamese.
	 *
	 * @param bool   $embed  Value of the per-language "embed" flag.
	 * @param string $vi_url Vietnamese combined link.
	 * @return array
	 */
	private function reader_view( $embed = true, $vi_url = 'https://www.biblegateway.com/passage/?search=Luke+3:1-6;Matthew+3:4-10;Luke+3:10-18&version=BPT' ) {
		$config = array(
			'en' => array(
				'label'  => 'English',
				'notice' => 'Easy-to-Read Version (ERV) Copyright © 2006 by Bible League International',
				'embed'  => $embed,
			),
			'vi' => array(
				'label'  => 'Tiếng Việt',
				'notice' => 'Vietnamese Bible: Easy-to-Read Version (BPT) © 2010 Bible League International',
				'embed'  => $embed,
			),
		);
		$links  = array(
			'en' => array(
				'version'  => 'ERV',
				'combined' => array( 'url' => 'https://www.biblegateway.com/passage/?search=Luke+3:1-6;Matthew+3:4-10;Luke+3:10-18&version=ERV' ),
			),
			'vi' => array(
				'version'  => 'BPT',
				'combined' => array( 'url' => $vi_url ),
			),
		);
		return array(
			'languages' => array( 'vi', 'en' ),
			'reading'   => array(
				'plan' => array( 'links_config' => $config ),
				'day'  => array(
					'passages' => array(),
					'links'    => $links,
				),
			),
		);
	}

	/**
	 * The reader (#92): collapsed, participant's language first, nothing loaded
	 * from Bible Gateway until opened, sandboxed without top navigation, with the
	 * publisher notice and a Bible Gateway link always present.
	 */
	public function test_reader_embeds_bible_gateway_lazily_with_link_fallback() {
		$html = jlife_challenges_render_reader( $this->reader_view() );

		$this->assertStringStartsWith( '<details class="jl-reader">', $html, 'collapsed by default' );
		$this->assertStringNotContainsString( ' src=', $html, 'no request to Bible Gateway before the participant opens the reader' );
		$this->assertSame( 2, substr_count( $html, '<iframe' ) );
		$this->assertLessThan( strpos( $html, 'version=ERV' ), strpos( $html, 'version=BPT' ), 'Vietnamese pane first for a Vietnamese participant' );
		$this->assertStringContainsString( 'data-src="' . esc_url( 'https://www.biblegateway.com/passage/?search=Luke+3:1-6;Matthew+3:4-10;Luke+3:10-18&version=BPT' ) . '"', $html );
		$this->assertStringNotContainsString( 'allow-top-navigation', $html, 'the frame can never navigate away from a half-written SOAP' );
		$this->assertSame( 2, substr_count( $html, 'Open in Bible Gateway' ) );
		$this->assertStringContainsString( '© 2010 Bible League International', $html );
		$this->assertStringContainsString( 'Copyright © 2006 by Bible League International', $html );
		$this->assertMatchesRegularExpression( '/data-reader-pane="en"[^>]*hidden/', $html, 'only the first language is shown' );
	}

	/**
	 * Only Bible Gateway passage pages are framed, and only when "embed" is on.
	 */
	public function test_reader_skips_disabled_or_foreign_links() {
		$this->assertSame( '', jlife_challenges_render_reader( $this->reader_view( false ) ) );

		$html = jlife_challenges_render_reader( $this->reader_view( true, 'https://evil.test/passage/?search=Luke+3' ) );
		$this->assertStringNotContainsString( 'evil.test', $html );
		$this->assertSame( 1, substr_count( $html, '<iframe' ) );
		$this->assertStringNotContainsString( 'data-reader-lang', $html, 'no version buttons for a single version' );

		$no_reading = array(
			'languages' => array( 'en' ),
			'reading'   => null,
		);
		$this->assertSame( '', jlife_challenges_render_reader( $no_reading ) );
	}


	/**
	 * Clear any simulated form POST.
	 */
	public function tear_down() {
		$this->clear_request();
		set_query_var( 'jlife_screen', '' );
		parent::tear_down();
	}

	/**
	 * Remove the simulated form POST.
	 */
	private function clear_request() {
		foreach ( array_keys( $_POST ) as $key ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing -- test helper clearing a simulated POST.
			unset( $_POST[ $key ], $_REQUEST[ $key ] );
		}
	}

	/**
	 * Simulate a signed-in participant posting the SOAP form for a day.
	 *
	 * @param int    $user_id Participant.
	 * @param string $day_key Day the form belongs to.
	 * @param string $action  'submit' or 'draft'.
	 * @param string $text    The text in the textbox.
	 * @param bool   $share   Whether "Share this with my group" is ticked.
	 */
	private function post_soap( $user_id, $day_key, $action, $text, $share = false ) {
		$this->clear_request();
		wp_set_current_user( $user_id );
		$data = array(
			'jl_text'     => $text,
			'jl_action'   => $action,
			'jl_day'      => $day_key,
			'_jlife_soap' => wp_create_nonce( 'jlife_soap_' . $day_key ),
		);
		if ( $share ) {
			$data['jl_share'] = '1';
		}
		foreach ( $data as $key => $value ) {
			$_POST[ $key ]    = $value;
			$_REQUEST[ $key ] = $value;
		}
	}

	/**
	 * A participant's day view with a Bible Gateway reading, as the screens get it
	 * when the reading plan is loaded.
	 *
	 * @param int         $user_id Participant.
	 * @param string|null $day_key Day (null = today).
	 * @return array
	 */
	private function view_with_reading( $user_id, $day_key = null ) {
		$reading                    = $this->reader_view()['reading'];
		$reading['day']['title']    = 'John the Baptizer Prepares the Way';
		$reading['day']['passages'] = array( array( 'display' => 'Luke 3:1-6' ) );
		return array_merge(
			jlife_challenges_day_view( $user_id, $day_key ),
			array(
				'reading'   => $reading,
				'languages' => array( 'vi', 'en' ),
			)
		);
	}

	/**
	 * The SOAP <form> element out of a page body.
	 *
	 * @param string $html Page body.
	 * @return string
	 */
	private function form_of( $html ) {
		$this->assertSame( 1, preg_match( '#<form method="post" class="jl-soap".*?</form>#s', $html, $m ), 'exactly one SOAP form' );
		return $m[0];
	}

	/**
	 * Today (#96, #98), new day: the reading, then the collapsed reader directly
	 * above ONE textbox holding the heading template, an unchecked share box
	 * between the textbox and "Submit SOAP", and "Save draft". No Write /
	 * View-edit button, no separate Share button.
	 */
	public function test_today_new_day_shows_reader_textbox_template_and_submit_button() {
		$body = jlife_challenges_today_body( $this->view_with_reading( $this->u['a'] ) );

		$this->assertStringContainsString( 'John the Baptizer Prepares the Way', $body );
		$this->assertLessThan( strpos( $body, '<details class="jl-reader">' ), strpos( $body, 'John the Baptizer Prepares the Way' ), 'the reading comes first' );
		$this->assertStringContainsString( '</details><form method="post" class="jl-soap"', $body, 'the reader sits directly above the form' );
		$this->assertStringContainsString( esc_html( "Read today's passages" ), $body );
		$this->assertStringNotContainsString( '<details class="jl-reader" open', $body, 'collapsed by default' );

		$form = $this->form_of( $body );
		$this->assertSame( 1, substr_count( $form, '<textarea' ), 'one textbox' );
		$this->assertStringContainsString( 'name="jl_text"', $form );
		foreach ( array( 'scripture', 'observation', 'application', 'prayer' ) as $field ) {
			$this->assertStringNotContainsString( 'name="' . $field . '"', $form, 'no separate boxes' );
		}
		$this->assertStringContainsString( '>' . "S — Scripture\n\nO — Observation\n\nA — Application\n\nP — Prayer" . '</textarea>', $form, 'prefilled with the template' );
		$this->assertStringContainsString( 'data-day="d001"', $form );
		$this->assertStringContainsString( 'data-status="draft"', $form, 'autosave stays on for an unsubmitted day' );
		$this->assertStringContainsString( 'name="jl_day" value="d001"', $form );
		$this->assertStringContainsString( 'data-insert="Luke 3:1-6"', $form, 'reference chips still work' );

		// The share box: one, unchecked, after the textbox and before the submit button.
		$this->assertSame( 1, substr_count( $form, 'type="checkbox"' ) );
		$this->assertStringContainsString( 'type="checkbox" name="jl_share" value="1">', $form, 'starts unchecked' );
		$this->assertStringNotContainsString( 'checked', $form );
		$this->assertStringContainsString( 'Share this with my group', $form );
		$this->assertStringContainsString( 'the whole entry, including your Prayer', $form );
		$this->assertLessThan( strpos( $form, 'name="jl_share"' ), strpos( $form, '</textarea>' ) );
		$this->assertLessThan( strpos( $form, 'Submit SOAP' ), strpos( $form, 'name="jl_share"' ) );

		$this->assertStringContainsString( 'Submit SOAP', $form );
		$this->assertStringContainsString( 'Save draft', $form );
		$this->assertStringNotContainsString( 'Save changes', $form );
		foreach ( array( 'Write my SOAP', 'View or edit my SOAP', 'Continue my SOAP', 'Share with my group or a chat' ) as $gone ) {
			$this->assertStringNotContainsString( $gone, $body );
		}
		$this->assertStringContainsString( 'Not started yet.', $body );
		$this->assertStringContainsString( '0 of 104', $body );
	}

	/**
	 * Today shows a saved draft as saved (not the template), still with Submit and Save draft.
	 */
	public function test_today_prefills_a_draft() {
		jlife_challenges_save_draft( $this->u['a'], $this->campaign, 'd001', "S — Scripture\nhalf-written\n\nO — Observation" );
		$body = jlife_challenges_today_body( $this->view_with_reading( $this->u['a'] ) );
		$form = $this->form_of( $body );
		$this->assertStringContainsString( ">S — Scripture\nhalf-written\n\nO — Observation</textarea>", $form );
		$this->assertStringContainsString( 'Submit SOAP', $form );
		$this->assertStringContainsString( 'Save draft', $form );
		$this->assertStringContainsString( 'Draft saved.', $body );
	}

	/**
	 * Today (#96, #98), completed day: the saved entry fills the one textbox, the
	 * button is "Save changes" (no draft button, no autosave over a submitted
	 * entry), and the share box is there, unchecked, with a note if the day is
	 * already shared.
	 */
	public function test_today_completed_day_prefills_and_offers_save_changes() {
		jlife_challenges_submit( $this->u['a'], $this->campaign, 'd001', $this->soap() );
		$body = jlife_challenges_today_body( $this->view_with_reading( $this->u['a'] ) );
		$form = $this->form_of( $body );

		$this->assertStringContainsString( '</details><form method="post" class="jl-soap"', $body );
		$this->assertStringContainsString( 'Save changes', $form );
		$this->assertStringNotContainsString( 'Submit SOAP', $form );
		$this->assertStringNotContainsString( 'Save draft', $form );
		$this->assertStringContainsString( 'data-status="submitted"', $form );
		$this->assertStringContainsString( '>' . $this->soap() . '</textarea>', $form, 'the saved entry, as one text' );
		$this->assertStringContainsString( 'type="checkbox" name="jl_share" value="1">', $form, 'still unchecked' );
		$this->assertStringNotContainsString( 'Your group already has this day', $form );
		$this->assertStringNotContainsString( 'Share with my group or a chat', $body, 'no separate Share button' );
		$this->assertStringContainsString( 'Done — 1 point earned.', $body );
		$this->assertStringContainsString( '1 of 104', $body );

		jlife_challenges_share( $this->u['a'], jlife_challenges_find_entry( $this->u['a'], $this->campaign, 'd001' )['id'] );
		$this->assertStringContainsString( 'Your group already has this day', jlife_challenges_today_body( $this->view_with_reading( $this->u['a'] ) ) );
	}

	/**
	 * What the page says after a save: the green confirmation (no separate
	 * Share button), the share result, the reasons when there is no point yet.
	 */
	public function test_today_feedback_after_a_save() {
		jlife_challenges_submit( $this->u['a'], $this->campaign, 'd001', $this->soap() );
		$view = $this->view_with_reading( $this->u['a'] );

		$done = jlife_challenges_today_body( $view, array( 'flags' => array( 'done' => true ) ) );
		$this->assertStringContainsString( '<p class="jl-ok" role="status">Done — 1 point earned. Well done! You have 1 of 104.</p>', $done );
		$this->assertStringNotContainsString( 'Share with my group or a chat', $done );
		$this->assertStringNotContainsString( 'class="jl-status"', $done, 'the confirmation says where things stand' );
		$this->assertStringContainsString( 'Save changes', $done );
		$this->assertStringNotContainsString( 'Shared with your group', $done );

		$shared = jlife_challenges_today_body(
			$view,
			array(
				'flags' => array(
					'done'   => true,
					'shared' => true,
				),
			)
		);
		$this->assertStringContainsString( 'Shared with your group.', $shared );
		$this->assertStringContainsString( esc_url( home_url( '/group/' ) ), $shared );

		$failed = jlife_challenges_today_body(
			$view,
			array(
				'flags' => array(
					'done'         => true,
					'share_failed' => 'jlife_guardian_consent_required',
				),
			)
		);
		$this->assertStringContainsString( 'Done — 1 point earned.', $failed, 'the point stands' );
		$this->assertStringContainsString( 'It was not shared: sharing with your group starts once a parent or guardian has agreed.', $failed );
		$this->assertStringContainsString( 'could not be shared with your group this time', jlife_challenges_today_body( $view, array( 'flags' => array( 'share_failed' => 'jlife_db' ) ) ) );

		$not_done = jlife_challenges_today_body( $this->view_with_reading( $this->u['b'] ), array( 'flags' => array( 'done' => true ) ) );
		$this->assertStringNotContainsString( 'jl-ok', $not_done, 'a hand-typed ?done=1 on an unsubmitted day confirms nothing' );
	}

	/**
	 * An incomplete submit from Today saves everything typed, earns nothing, and
	 * the page then says kindly what to add; the form still offers Submit SOAP.
	 */
	public function test_today_incomplete_submit_keeps_text_and_shows_reasons() {
		$typed = $this->soap_without_prayer( 'typed' );
		$this->post_soap( $this->u['a'], 'd001', 'submit', $typed );
		$state = jlife_challenges_process_soap_request( $this->u['a'], jlife_challenges_day_view( $this->u['a'] ), 'today' );

		$this->assertSame( '', $state['error'] );
		$this->assertSame( add_query_arg( 'incomplete', 1, home_url( '/today/' ) ), $state['redirect'], 'back to Today' );
		$this->assertSame( 0, $this->ledger_rows( $this->u['a'] ) );
		$this->assertSame( trim( $typed ), jlife_challenges_find_entry( $this->u['a'], $this->campaign, 'd001' )['body'], 'saved before scoring' );

		$body = jlife_challenges_today_body( $this->view_with_reading( $this->u['a'] ), array( 'flags' => array( 'incomplete' => true ) ) );
		$this->assertStringContainsString( '<div class="jl-note" role="status">', $body );
		$this->assertStringContainsString( 'Saved. Everything you typed is kept.', $body );
		$this->assertStringContainsString( '<li>Add something under P — Prayer</li>', $body );
		$this->assertStringNotContainsString( 'jl-ok', $body );
		$this->assertStringContainsString( 'John 1:14 (typed)', $body, 'the typed text is still in the box' );
		$this->assertStringContainsString( 'Submit SOAP', $body );

		// Fix it and submit again: the point is earned.
		$this->post_soap( $this->u['a'], 'd001', 'submit', $this->soap( 'typed' ) );
		$state = jlife_challenges_process_soap_request( $this->u['a'], jlife_challenges_day_view( $this->u['a'] ), 'today' );
		$this->assertSame( add_query_arg( 'done', 1, home_url( '/today/' ) ), $state['redirect'] );
		$this->assertSame( 1, jlife_challenges_day_view( $this->u['a'] )['points'] );
	}

	/**
	 * A day already completed whose edit no longer passes: the page says the point stays.
	 */
	public function test_today_failing_edit_of_a_completed_day_keeps_the_point() {
		jlife_challenges_submit( $this->u['a'], $this->campaign, 'd001', $this->soap( 'v1' ) );
		jlife_challenges_submit( $this->u['a'], $this->campaign, 'd001', $this->soap_without_prayer( 'v2' ) );
		$body = jlife_challenges_today_body( $this->view_with_reading( $this->u['a'] ), array( 'flags' => array( 'incomplete' => true ) ) );
		$this->assertStringContainsString( 'Your changes are saved, and your point for this day stays.', $body );
		$this->assertStringContainsString( 'Add something under P — Prayer', $body );
		$this->assertStringContainsString( 'Save changes', $body );
		$this->assertSame( 1, jlife_challenges_day_view( $this->u['a'] )['points'] );
	}

	/**
	 * Save draft from Today stores a draft (no point), returns to Today, and never
	 * shares, even with the box ticked.
	 */
	public function test_today_save_draft_returns_to_today_and_never_shares() {
		$this->post_soap( $this->u['a'], 'd001', 'draft', "S — Scripture\nLuke 3:1-6\n\nO — Observation\ndraft only", true );
		$state = jlife_challenges_process_soap_request( $this->u['a'], jlife_challenges_day_view( $this->u['a'] ), 'today' );

		$this->assertSame( '', $state['error'] );
		$this->assertSame( home_url( '/today/' ), $state['redirect'] );
		$this->assertSame( 'draft', jlife_challenges_day_view( $this->u['a'] )['status'] );
		$this->assertStringContainsString( 'draft only', jlife_challenges_find_entry( $this->u['a'], $this->campaign, 'd001' )['body'] );
		$this->assertSame( 0, $this->ledger_rows( $this->u['a'] ) );
		$this->assertSame( 0, $this->share_rows() );
	}

	/**
	 * Submit from Today earns one point and returns to Today (not /soap); editing
	 * the day from Today and from /soap keeps the day at exactly one point. The
	 * ledger's one row per person per day is what enforces it.
	 */
	public function test_submit_then_edit_from_today_leaves_one_point() {
		$this->post_soap( $this->u['a'], 'd001', 'submit', $this->soap( 'first' ) );
		$state = jlife_challenges_process_soap_request( $this->u['a'], jlife_challenges_day_view( $this->u['a'] ), 'today' );

		$this->assertSame( '', $state['error'] );
		$this->assertSame( add_query_arg( 'done', 1, home_url( '/today/' ) ), $state['redirect'], 'back to Today with the confirmation, not /soap' );
		$view = jlife_challenges_day_view( $this->u['a'] );
		$this->assertSame( 'earned', $view['status'] );
		$this->assertSame( 1, $view['points'] );
		$this->assertSame( 1, $this->ledger_rows( $this->u['a'] ) );
		$this->assertSame( 0, $this->share_rows(), 'the box was not ticked' );

		// Edit from Today: the form is now "Save changes" with the saved text.
		$this->assertStringContainsString( 'Save changes', $this->form_of( jlife_challenges_today_body( $this->view_with_reading( $this->u['a'] ) ) ) );
		$edited = $this->soap( 'edited' );
		$this->post_soap( $this->u['a'], 'd001', 'submit', $edited );
		$state = jlife_challenges_process_soap_request( $this->u['a'], jlife_challenges_day_view( $this->u['a'] ), 'today' );

		$this->assertSame( '', $state['error'] );
		$this->assertSame( add_query_arg( 'done', 1, home_url( '/today/' ) ), $state['redirect'] );
		$entry = jlife_challenges_find_entry( $this->u['a'], $this->campaign, 'd001' );
		$this->assertSame( $edited, $entry['body'], 'the edit is saved' );
		$this->assertSame( 2, (int) $entry['revision'], 'as a revision of the same entry' );
		$this->assertSame( 1, $this->ledger_rows( $this->u['a'] ), 'still one ledger row for the day' );
		$this->assertSame( 1, jlife_challenges_progress( $this->u['a'], $this->u['a'], $this->campaign )['points'], 'the total stays at one point' );
		$this->assertSame( 1, jlife_challenges_day_view( $this->u['a'] )['points'] );
		$this->assertSame( 'earned', jlife_challenges_day_view( $this->u['a'] )['status'] );

		// Edit again on a later day, from /soap?day=d001 (My Marathon / share links).
		$this->set_now( '2026-12-24 20:00:00' ); // Day 5, past the grace window.
		$later = $this->soap( 'later' );
		$this->post_soap( $this->u['a'], 'd001', 'submit', $later );
		$state = jlife_challenges_process_soap_request( $this->u['a'], jlife_challenges_day_view( $this->u['a'], 'd001' ), 'soap' );

		$this->assertSame( '', $state['error'] );
		$this->assertSame( add_query_arg( 'done', 1, add_query_arg( 'day', 'd001', home_url( '/soap/' ) ) ), $state['redirect'] );
		$this->assertSame( $later, jlife_challenges_find_entry( $this->u['a'], $this->campaign, 'd001' )['body'] );
		$this->assertSame( 1, $this->ledger_rows( $this->u['a'] ) );
		$this->assertSame( 1, jlife_challenges_progress( $this->u['a'], $this->u['a'], $this->campaign )['points'] );
	}

	/**
	 * The share box on Today: ticked = one post for the current group, and a
	 * later ticked edit updates that same post; unticked leaves it alone. The
	 * point stays at one throughout.
	 */
	public function test_share_box_creates_then_updates_one_post() {
		$this->post_soap( $this->u['a'], 'd001', 'submit', $this->soap( 'v1' ), true );
		$state = jlife_challenges_process_soap_request( $this->u['a'], jlife_challenges_day_view( $this->u['a'] ), 'today' );
		$this->assertSame(
			add_query_arg(
				array(
					'done'   => 1,
					'shared' => 1,
				),
				home_url( '/today/' )
			),
			$state['redirect']
		);
		$this->assertSame( 1, $this->share_rows() );
		$this->assertStringContainsString( '(v1)', jlife_challenges_group_feed( $this->u['b'], $this->campaign )[0]['body'], 'a group member reads it' );
		$this->assertSame( array(), jlife_challenges_group_feed( $this->u['c'], $this->campaign ), 'the other group does not' );

		$this->post_soap( $this->u['a'], 'd001', 'submit', $this->soap( 'v2' ), true );
		jlife_challenges_process_soap_request( $this->u['a'], jlife_challenges_day_view( $this->u['a'] ), 'today' );
		$this->assertSame( 1, $this->share_rows(), 'still one post' );
		$this->assertStringContainsString( '(v2)', jlife_challenges_group_feed( $this->u['b'], $this->campaign )[0]['body'], 'updated' );

		$this->post_soap( $this->u['a'], 'd001', 'submit', $this->soap( 'v3' ), false );
		$state = jlife_challenges_process_soap_request( $this->u['a'], jlife_challenges_day_view( $this->u['a'] ), 'today' );
		$this->assertSame( add_query_arg( 'done', 1, home_url( '/today/' ) ), $state['redirect'], 'no share flag when the box is not ticked' );
		$this->assertStringContainsString( '(v2)', jlife_challenges_group_feed( $this->u['b'], $this->campaign )[0]['body'], 'an unticked save leaves the post as it was' );
		$this->assertSame( 1, $this->ledger_rows( $this->u['a'] ) );
		$this->assertSame( 1, jlife_challenges_day_view( $this->u['a'] )['points'] );
	}

	/**
	 * A save that can't be shared still saves: the redirect says so. An
	 * incomplete entry ticked to share is saved, shared and flagged incomplete.
	 */
	public function test_share_failure_and_incomplete_flags_in_the_redirect() {
		$teen = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		jlife_challenges_enroll( $this->u['organizer'], $teen, $this->g['g1'], 'minor' );
		$this->post_soap( $teen, 'd001', 'submit', $this->soap( 'teen' ), true );
		$state = jlife_challenges_process_soap_request( $teen, jlife_challenges_day_view( $teen ), 'today' );
		$this->assertSame(
			add_query_arg(
				array(
					'done'         => 1,
					'share_failed' => 'jlife_guardian_consent_required',
				),
				home_url( '/today/' )
			),
			$state['redirect']
		);
		$this->assertSame( 0, $this->share_rows() );
		$this->assertSame( 1, jlife_challenges_day_view( $teen )['points'] );

		$this->post_soap( $this->u['b'], 'd001', 'submit', $this->soap_without_prayer( 'b' ), true );
		$state = jlife_challenges_process_soap_request( $this->u['b'], jlife_challenges_day_view( $this->u['b'] ), 'today' );
		$this->assertSame(
			add_query_arg(
				array(
					'incomplete' => 1,
					'shared'     => 1,
				),
				home_url( '/today/' )
			),
			$state['redirect']
		);
		$this->assertSame( 1, $this->share_rows() );
		$this->assertSame( 0, jlife_challenges_day_view( $this->u['b'] )['points'] );
	}

	/**
	 * A form opened before midnight and sent after it still saves to the day it
	 * was written for (its nonce and day travel with the form), and returns to
	 * that day's /soap page since Today has moved on.
	 */
	public function test_today_form_sent_after_midnight_saves_to_its_own_day() {
		$typed = $this->soap( 'late-night' );
		$this->post_soap( $this->u['a'], 'd001', 'submit', $typed );
		$this->set_now( '2026-12-21 18:00:00' ); // Day 2, 10:00 AM Pacific.
		$this->assertSame( 'd001', jlife_challenges_posted_day_key() );

		$view  = jlife_challenges_day_view( $this->u['a'], jlife_challenges_posted_day_key() );
		$state = jlife_challenges_process_soap_request( $this->u['a'], $view, 'today' );

		$this->assertSame( '', $state['error'] );
		$this->assertSame( add_query_arg( 'done', 1, add_query_arg( 'day', 'd001', home_url( '/soap/' ) ) ), $state['redirect'] );
		$this->assertSame( $typed, jlife_challenges_find_entry( $this->u['a'], $this->campaign, 'd001' )['body'] );
		$this->assertNull( jlife_challenges_find_entry( $this->u['a'], $this->campaign, 'd002' ) );
		$this->assertSame( 1, $this->ledger_rows( $this->u['a'] ) );

		$this->clear_request();
		$this->assertNull( jlife_challenges_posted_day_key(), 'a plain visit means today' );
	}

	/**
	 * /soap?day=dNNN (My Marathon and share links) still renders the reading, the
	 * reader and the same textbox for that day, with the same markup Today uses.
	 */
	public function test_soap_day_link_still_renders_the_same_form() {
		$this->set_now( '2026-12-29 20:00:00' ); // Day 10.
		$view = $this->view_with_reading( $this->u['a'], 'd003' );
		$this->assertSame( 'active', $view['state'] );
		$this->assertFalse( $view['is_today'] );

		$body = jlife_challenges_soap_body( $view );
		$this->assertStringContainsString( 'Day 3 · ', $body );
		$this->assertStringContainsString( 'John the Baptizer Prepares the Way', $body );
		$this->assertStringContainsString( '</details><form method="post" class="jl-soap"', $body );
		$this->assertStringContainsString( 'data-day="d003"', $body );
		$this->assertStringContainsString( 'Submit SOAP', $body );
		$this->assertStringContainsString( 'name="jl_text"', $body );
		$this->assertStringContainsString( 'name="jl_share"', $body );
		$this->assertStringContainsString( 'You can still write this one', jlife_challenges_status_text( $view ) );

		$today = $this->view_with_reading( $this->u['a'], 'd010' );
		$this->assertSame( $this->form_of( jlife_challenges_soap_body( $today ) ), $this->form_of( jlife_challenges_today_body( $today ) ), 'Today and /soap share one form renderer' );
	}

	/**
	 * Submitting from /soap?day= returns to /soap with the confirmation; a
	 * completed day there is "Save changes" with the saved text.
	 */
	public function test_soap_day_submit_returns_to_soap_with_confirmation() {
		$this->post_soap( $this->u['a'], 'd001', 'submit', $this->soap() );
		$state = jlife_challenges_process_soap_request( $this->u['a'], jlife_challenges_day_view( $this->u['a'], 'd001' ), 'soap' );
		$this->assertSame( add_query_arg( 'done', 1, add_query_arg( 'day', 'd001', home_url( '/soap/' ) ) ), $state['redirect'] );

		$body = jlife_challenges_soap_body( $this->view_with_reading( $this->u['a'], 'd001' ), array( 'flags' => array( 'done' => true ) ) );
		$this->assertStringContainsString( 'Done — 1 point earned. Well done! You have 1 of 104.', $body );
		$this->assertStringContainsString( 'Save changes', $body );
		$this->assertStringContainsString( '>' . $this->soap() . '</textarea>', $body );
	}

	/**
	 * The SOAP page script (autosave, chips, reader loading) is loaded for Today
	 * too, and Sign out on Today returns to Today, not /soap.
	 */
	public function test_page_loads_soap_script_and_signout_returns_to_today() {
		set_query_var( 'jlife_screen', 'today' );
		$html = jlife_challenges_page_html( 'Today', jlife_challenges_today_body( $this->view_with_reading( $this->u['a'] ) ), 'today', 'soap' );
		$this->assertStringContainsString( 'assets/soap.js', $html );
		$this->assertStringContainsString( 'back=today', $html );
		$this->assertStringNotContainsString( 'back=soap', $html );

		set_query_var( 'jlife_screen', 'soap' );
		$html = jlife_challenges_page_html( 'SOAP', '', 'today', 'soap' );
		$this->assertStringContainsString( 'back=soap', $html );
	}
}
