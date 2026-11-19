<?php
/**
 * Daily reading reminders, part 1 (#63 Phase B): who may change a choice, who
 * counts as a recipient, the checkbox on Today and My Marathon with its REST
 * route (#135) and the public one-click unsubscribe. Nothing here sends mail. Written before the
 * feature.
 *
 * @package jlife-challenges
 */

// phpcs:disable WordPress.Security.NonceVerification, WordPress.DB.DirectDatabaseQuery -- tests drive the handlers with crafted requests.

/**
 * Reminder tests.
 */
class Test_Reminders extends Jlife_Challenges_TestCase {

	/**
	 * Participants a, b and c have accepted the consent wording (a recipient needs it).
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
		unset( $_SERVER['HTTP_X_WP_NONCE'] );
		$GLOBALS['wp_rest_auth_cookie'] = null; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- reset what test_rest_needs_the_nonce set.
		wp_set_current_user( 0 );
		parent::tear_down();
	}

	/**
	 * Act as a user and post a reminder form.
	 *
	 * @param int   $user_id Signed-in user.
	 * @param array $post    Fields.
	 */
	private function post_as( $user_id, $post ) {
		wp_set_current_user( $user_id );
		$_POST    = $post + array( '_jlife_reminder' => wp_create_nonce( JLIFE_CHALLENGES_REMINDER_NONCE ) );
		$_REQUEST = $_POST;
	}

	/**
	 * Audit actions recorded for reminders, newest last.
	 *
	 * @return string[]
	 */
	private function audit_actions() {
		global $wpdb;
		return (array) $wpdb->get_col( 'SELECT action FROM ' . jlife_challenges_table( 'audit_log' ) . " WHERE action LIKE 'reminder_%' ORDER BY id" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	}

	/**
	 * The link's query parameters as /unsubscribe reads them.
	 *
	 * @param string $url A URL from jlife_challenges_unsubscribe_url().
	 * @return array
	 */
	private function query_of( $url ) {
		wp_parse_str( (string) wp_parse_url( $url, PHP_URL_QUERY ), $q );
		return $q;
	}

	/**
	 * A person may choose for themself; nobody else may, except an organizer.
	 */
	public function test_only_the_person_or_an_organizer_sets_a_choice() {
		$r = jlife_challenges_set_reminder( $this->u['b'], $this->u['a'], $this->campaign, true, 'vi' );
		$this->assertSame( 'jlife_denied', $r->get_error_code(), 'another participant is denied' );
		$this->assertNull( jlife_challenges_reminder_pref( $this->u['a'], $this->campaign ) );

		$this->assertTrue( jlife_challenges_set_reminder( $this->u['a'], $this->u['a'], $this->campaign, true, 'vi' ) );
		$pref = jlife_challenges_reminder_pref( $this->u['a'], $this->campaign );
		$this->assertNotEmpty( $pref['opted_in'] );
		$this->assertNull( $pref['opted_out'] );
		$this->assertSame( 'vi', $pref['locale'] );

		$this->assertTrue( jlife_challenges_set_reminder( $this->u['organizer'], $this->u['a'], $this->campaign, false ), 'an organizer may' );
		$this->assertNotEmpty( jlife_challenges_reminder_pref( $this->u['a'], $this->campaign )['opted_out'] );
		$this->assertSame( 'vi', jlife_challenges_reminder_pref( $this->u['a'], $this->campaign )['locale'], 'the language is kept' );
		$this->assertSame( 'jlife_denied', jlife_challenges_set_reminder( 0, $this->u['a'], $this->campaign, true )->get_error_code(), 'signed out is denied' );
	}

	/**
	 * No choice without an active enrollment in that campaign.
	 */
	public function test_a_choice_needs_an_active_enrollment() {
		$this->assertSame( 'jlife_denied', jlife_challenges_set_reminder( $this->u['outsider'], $this->u['outsider'], $this->campaign, true )->get_error_code(), 'not enrolled' );
		$this->assertSame( 'jlife_denied', jlife_challenges_set_reminder( $this->u['organizer'], $this->u['outsider'], $this->campaign, true )->get_error_code(), 'not even for an organizer' );
		$this->assertSame( 'jlife_denied', jlife_challenges_set_reminder( $this->u['a'], $this->u['a'], 999999, true )->get_error_code(), 'unknown campaign' );
		jlife_challenges_remove_member( $this->u['organizer'], $this->campaign, $this->u['b'] );
		$this->assertSame( 'jlife_denied', jlife_challenges_set_reminder( $this->u['b'], $this->u['b'], $this->campaign, true )->get_error_code(), 'left' );
		$this->assertNull( jlife_challenges_reminder_pref( $this->u['outsider'], $this->campaign ) );
		$this->assertNull( jlife_challenges_reminder_pref( $this->u['b'], $this->campaign ) );
	}

	/**
	 * Only supported languages are stored; anything else falls back to the account's, then English.
	 */
	public function test_language_is_en_us_or_vi() {
		update_user_meta( $this->u['a'], 'locale', 'vi' );
		jlife_challenges_set_reminder( $this->u['a'], $this->u['a'], $this->campaign, true, 'fr_FR' );
		$this->assertSame( 'vi', jlife_challenges_reminder_pref( $this->u['a'], $this->campaign )['locale'], 'account language' );
		jlife_challenges_set_reminder( $this->u['b'], $this->u['b'], $this->campaign, true, 'fr_FR' );
		$this->assertSame( 'en_US', jlife_challenges_reminder_pref( $this->u['b'], $this->campaign )['locale'], 'English' );
		jlife_challenges_set_reminder( $this->u['b'], $this->u['b'], $this->campaign, true, 'vi' );
		$this->assertSame( 'vi', jlife_challenges_reminder_pref( $this->u['b'], $this->campaign )['locale'], 'language can change' );
	}

	/**
	 * Opt-in and opt-out are audited; the row holds no email address.
	 */
	public function test_choices_are_audited_and_hold_no_email() {
		jlife_challenges_set_reminder( $this->u['a'], $this->u['a'], $this->campaign, true, 'en_US' );
		jlife_challenges_set_reminder( $this->u['a'], $this->u['a'], $this->campaign, false );
		$this->assertSame( array( 'reminder_opt_in', 'reminder_opt_out' ), $this->audit_actions() );
		global $wpdb;
		$columns = $wpdb->get_col( 'SHOW COLUMNS FROM ' . jlife_challenges_table( 'reminder_prefs' ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$this->assertSame( array( 'id', 'campaign_id', 'user_id', 'opted_in', 'opted_out', 'consent_version', 'locale', 'updated' ), $columns );
	}

	/**
	 * Recipients: opted in, not opted out, active enrollment; nobody else.
	 */
	public function test_recipients_are_only_opted_in_active_enrolled_users() {
		$this->assertSame( array(), jlife_challenges_reminder_recipients( $this->campaign ), 'nobody asked' );
		foreach ( array( 'a', 'b', 'c' ) as $n ) {
			jlife_challenges_set_reminder( $this->u[ $n ], $this->u[ $n ], $this->campaign, true, 'en_US' );
		}
		jlife_challenges_set_reminder( $this->u['c'], $this->u['c'], $this->campaign, false ); // Opted out again.
		jlife_challenges_remove_member( $this->u['organizer'], $this->campaign, $this->u['b'] ); // Left.
		// A row for someone never enrolled (stale data) is not a recipient either.
		global $wpdb;
		$wpdb->insert(
			jlife_challenges_table( 'reminder_prefs' ),
			array(
				'campaign_id' => $this->campaign,
				'user_id'     => $this->u['outsider'],
				'opted_in'    => current_time( 'mysql', true ),
				'locale'      => 'en_US',
				'updated'     => current_time( 'mysql', true ),
			)
		);
		$this->assertSame( array( $this->u['a'] ), jlife_challenges_reminder_recipients( $this->campaign ) );
		$this->assertSame( array(), jlife_challenges_reminder_recipients( 999999 ), 'other campaign' );
		// Opting back in returns the person.
		jlife_challenges_set_reminder( $this->u['c'], $this->u['c'], $this->campaign, true );
		$this->assertSame( array( $this->u['a'], $this->u['c'] ), jlife_challenges_reminder_recipients( $this->campaign ) );
	}

	/**
	 * The token is the keyed hash from the brief and the URL carries c, u and t.
	 */
	public function test_unsubscribe_url_shape() {
		$url = jlife_challenges_unsubscribe_url( $this->u['a'], $this->campaign );
		$q   = $this->query_of( $url );
		$this->assertStringStartsWith( home_url( '/unsubscribe/' ), $url );
		$this->assertSame( (string) $this->campaign, $q['c'] );
		$this->assertSame( (string) $this->u['a'], $q['u'] );
		$this->assertSame( hash_hmac( 'sha256', 'unsub|' . $this->campaign . '|' . $this->u['a'], wp_salt( 'auth' ) ), $q['t'] );
		$this->assertStringNotContainsString( '@', $url, 'no email address in the link' );
	}

	/**
	 * A GET never unsubscribes (link scanners): it only asks to confirm.
	 */
	public function test_get_never_unsubscribes() {
		jlife_challenges_set_reminder( $this->u['a'], $this->u['a'], $this->campaign, true, 'en_US' );
		$q   = $this->query_of( jlife_challenges_unsubscribe_url( $this->u['a'], $this->campaign ) );
		$req = jlife_challenges_unsubscribe_request( 'GET', $q, array() );
		$this->assertSame( 'confirm', $req['state'] );
		$this->assertContains( $this->u['a'], jlife_challenges_reminder_recipients( $this->campaign ) );
		// A POST without the confirm field or the one-click body does nothing either.
		$this->assertSame( 'confirm', jlife_challenges_unsubscribe_request( 'POST', $q, array() )['state'] );
		$this->assertContains( $this->u['a'], jlife_challenges_reminder_recipients( $this->campaign ) );
		$this->assertStringContainsString( 'name="jl_unsubscribe"', jlife_challenges_unsubscribe_body( $req, $q ) );
	}

	/**
	 * The confirm button and the RFC 8058 one-click POST both unsubscribe, and say so.
	 */
	public function test_post_unsubscribes_by_button_or_one_click() {
		foreach ( array( 'a', 'b' ) as $n ) {
			jlife_challenges_set_reminder( $this->u[ $n ], $this->u[ $n ], $this->campaign, true, 'vi' );
		}
		$q = $this->query_of( jlife_challenges_unsubscribe_url( $this->u['a'], $this->campaign ) );
		$this->assertSame( 'done', jlife_challenges_unsubscribe_request( 'POST', $q, array( 'jl_unsubscribe' => '1' ) )['state'] );
		$this->assertSame( array( $this->u['b'] ), jlife_challenges_reminder_recipients( $this->campaign ) );

		$q = $this->query_of( jlife_challenges_unsubscribe_url( $this->u['b'], $this->campaign ) );
		$this->assertSame( 'done', jlife_challenges_unsubscribe_request( 'POST', $q, array( 'List-Unsubscribe' => 'One-Click' ) )['state'] );
		$this->assertSame( array(), jlife_challenges_reminder_recipients( $this->campaign ) );
		$this->assertContains( 'reminder_opt_out', $this->audit_actions() );
		$this->assertSame( 'vi', jlife_challenges_reminder_pref( $this->u['b'], $this->campaign )['locale'], 'the language is kept' );
	}

	/**
	 * Forged, other-user, other-campaign, missing and malformed tokens do nothing, and look the same.
	 */
	public function test_bad_tokens_change_nothing_and_look_alike() {
		jlife_challenges_set_reminder( $this->u['a'], $this->u['a'], $this->campaign, true, 'en_US' );
		$good  = $this->query_of( jlife_challenges_unsubscribe_url( $this->u['a'], $this->campaign ) );
		$other = $this->query_of( jlife_challenges_unsubscribe_url( $this->u['b'], $this->campaign ) );
		$bad   = array(
			'forged'         => array_merge( $good, array( 't' => str_repeat( 'a', 64 ) ) ),
			'other user'     => array_merge( $good, array( 't' => $other['t'] ) ),
			'other campaign' => array_merge( $good, array( 'c' => (string) ( $this->campaign + 1 ) ) ),
			'other id'       => array_merge( $good, array( 'u' => (string) $this->u['b'] ) ),
			'missing token'  => array(
				'c' => $good['c'],
				'u' => $good['u'],
			),
			'empty'          => array(),
			'array token'    => array_merge( $good, array( 't' => array( 'x' ) ) ),
		);
		$pages = array();
		foreach ( $bad as $why => $q ) {
			foreach ( array( 'GET', 'POST' ) as $method ) {
				$req = jlife_challenges_unsubscribe_request( $method, $q, array( 'jl_unsubscribe' => '1' ) );
				$this->assertSame( 'invalid', $req['state'], "$why via $method" );
				$pages[ md5( jlife_challenges_unsubscribe_body( $req, $q ) ) ] = true;
			}
		}
		$this->assertCount( 1, $pages, 'one neutral page for every failure' );
		$this->assertSame( array( $this->u['a'] ), jlife_challenges_reminder_recipients( $this->campaign ), 'nothing changed' );
		$this->assertSame( array( 'reminder_opt_in' ), $this->audit_actions() );
	}

	/**
	 * Unsubscribing touches only the reminder row: sign-in state and codes are untouched.
	 */
	public function test_unsubscribe_leaves_sign_in_and_the_account_alone() {
		jlife_challenges_set_reminder( $this->u['a'], $this->u['a'], $this->campaign, true, 'en_US' );
		update_user_meta( $this->u['a'], 'jlife_consent_version', 'x' );
		$before = get_userdata( $this->u['a'] )->user_email;
		$q      = $this->query_of( jlife_challenges_unsubscribe_url( $this->u['a'], $this->campaign ) );
		jlife_challenges_unsubscribe_request( 'POST', $q, array( 'jl_unsubscribe' => '1' ) );
		$this->assertSame( $before, get_userdata( $this->u['a'] )->user_email );
		$this->assertSame( 'x', get_user_meta( $this->u['a'], 'jlife_consent_version', true ) );
		$this->assertSame( 'active', jlife_challenges_get_enrollment( $this->campaign, $this->u['a'] )['status'] );
		$this->assertNotFalse( get_userdata( $this->u['a'] ) );
	}

	/**
	 * A route exists for /unsubscribe/ and needs no sign-in.
	 */
	public function test_unsubscribe_route_is_registered_and_public() {
		jlife_challenges_add_rewrites();
		global $wp_rewrite;
		$rules = $wp_rewrite->extra_rules_top;
		$hit   = false;
		foreach ( array_keys( $rules ) as $regex ) {
			if ( 1 === preg_match( '#' . $regex . '#', 'unsubscribe/' ) ) {
				$hit = true;
			}
		}
		$this->assertTrue( $hit, 'unsubscribe/ matches a challenges rewrite rule' );
		$src = (string) file_get_contents( dirname( __DIR__ ) . '/includes/reminders.php' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- reading the plugin's own source.
		$fn  = substr( $src, (int) strpos( $src, 'function jlife_challenges_screen_unsubscribe' ), 600 );
		$this->assertStringNotContainsString( 'require_participant', $fn, 'no login' );
	}

	/**
	 * Is the reminder checkbox in this markup ticked?
	 *
	 * @param string $html Rendered checkbox form.
	 * @return bool
	 */
	private function ticked( $html ) {
		$this->assertSame( 1, preg_match( '/<input type="checkbox" id="[^"]+" name="jl_reminder" value="on"([^>]*)>/', $html, $m ), 'one checkbox' );
		return false !== strpos( $m[1], 'checked' );
	}

	/**
	 * Is the checkbox ticked on Today and on My Marathon (they must agree)?
	 *
	 * @param int $user_id Participant.
	 * @return bool
	 */
	private function ticked_on_both( $user_id ) {
		$today    = $this->ticked( jlife_challenges_render_reminder_toggle( $user_id, $this->campaign ) );
		$marathon = $this->ticked( jlife_challenges_render_reminder_settings( $user_id, $this->campaign ) );
		$this->assertSame( $today, $marathon, 'Today and My Marathon show the same choice' );
		return $today;
	}

	/**
	 * Save through the REST route as a user.
	 *
	 * @param int   $user_id Signed-in user (0 = signed out).
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
	 * One checkbox, the same on both screens (#135): the label, the REST route and
	 * its nonce, a hidden "off" before the box for the no-JavaScript post, a live
	 * status line; the old Yes / No thanks card and Turn on / Turn off buttons are gone.
	 */
	public function test_checkbox_markup_is_the_same_on_both_screens() {
		$today    = jlife_challenges_render_reminder_toggle( $this->u['a'], $this->campaign );
		$marathon = jlife_challenges_render_reminder_settings( $this->u['a'], $this->campaign );
		foreach ( array( $today, $marathon ) as $html ) {
			$this->assertStringContainsString( 'Email me each morning&#039;s reading.</label>', $html );
			$this->assertStringContainsString( 'data-rest="' . esc_url( rest_url( 'jlife/v1/reminder' ) ) . '"', $html );
			$this->assertStringContainsString( 'data-nonce="', $html );
			$this->assertMatchesRegularExpression( '/<input type="hidden" name="jl_reminder" value="off"><label class="jl-check" for="([^"]+)"><input type="checkbox" id="\1"/', $html, 'clickable label; unticked posts off' );
			$this->assertStringContainsString( 'aria-live="polite"', $html );
			$this->assertStringContainsString( 'Not saved. Please try again.', html_entity_decode( $html ) );
			$this->assertStringNotContainsString( 'Turn on', $html );
			$this->assertStringNotContainsString( 'Turn off', $html );
			$this->assertStringNotContainsString( 'No thanks', $html );
			$this->assertStringNotContainsString( 'type="submit" name="jl_reminder"', $html );
		}
		$this->assertStringNotContainsString( 'jl_reminder_locale', $today, 'Today has only the checkbox' );
		$this->assertStringContainsString( '<select id="jl-reminder-locale" name="jl_reminder_locale">', $marathon, 'My Marathon keeps the language' );
		$this->assertStringContainsString( 'Daily reading email</h2>', $marathon );
		$this->assertFalse( function_exists( 'jlife_challenges_render_reminder_prompt' ), 'the top-of-Today card is gone' );
		$this->assertSame( '', jlife_challenges_render_reminder_toggle( 0, $this->campaign ), 'nothing for nobody' );
		$this->assertSame( '', jlife_challenges_render_reminder_toggle( $this->u['a'], 0 ), 'nothing without a campaign' );
	}

	/**
	 * The box shows the saved choice on every visit, including earlier Yes / No
	 * thanks answers and the join form's box, and an unsubscribe unticks it on both screens.
	 */
	public function test_checkbox_shows_the_saved_choice_and_unsubscribe() {
		$this->assertFalse( $this->ticked_on_both( $this->u['a'] ), 'no choice yet: unticked' );
		jlife_challenges_set_reminder( $this->u['a'], $this->u['a'], $this->campaign, false, 'en_US' ); // An earlier "No thanks".
		$this->assertFalse( $this->ticked_on_both( $this->u['a'] ) );
		jlife_challenges_set_reminder( $this->u['b'], $this->u['b'], $this->campaign, true, 'vi' ); // An earlier "Yes" or the join form's box.
		$this->assertTrue( $this->ticked_on_both( $this->u['b'] ) );

		jlife_challenges_unsubscribe( $this->u['b'], $this->campaign );
		$this->assertFalse( $this->ticked_on_both( $this->u['b'] ), 'unsubscribed: unticked on both screens' );
		$this->assertSame( 'vi', jlife_challenges_reminder_pref( $this->u['b'], $this->campaign )['locale'] );
	}

	/**
	 * REST: only a signed-in, enrolled person, and only for their own choice.
	 * ON → reload → ON; OFF → reload → OFF; the same row the sender reads.
	 */
	public function test_rest_saves_only_the_signed_in_persons_choice() {
		$this->assertSame( 401, $this->rest_as( 0, array( 'on' => true ) )->get_status(), 'signed out' );
		$r = $this->rest_as( $this->u['outsider'], array( 'on' => true ) );
		$this->assertSame( 403, $r->get_status(), 'not enrolled' );
		$this->assertSame( 'jlife_denied', $r->get_data()['code'] );
		$this->assertNull( jlife_challenges_reminder_pref( $this->u['outsider'], $this->campaign ) );

		$r = $this->rest_as(
			$this->u['a'],
			array(
				'on'          => true,
				'user_id'     => $this->u['b'],
				'campaign_id' => 999999,
			)
		);
		$this->assertSame( 200, $r->get_status() );
		$this->assertTrue( $r->get_data()['on'] );
		$this->assertNull( jlife_challenges_reminder_pref( $this->u['b'], $this->campaign ), 'a user ID in the body is ignored' );
		$this->assertTrue( $this->ticked_on_both( $this->u['a'] ), 'ON → reload → ON' );
		$this->assertSame( array( $this->u['a'] ), jlife_challenges_reminder_recipients( $this->campaign ) );

		$r = $this->rest_as( $this->u['a'], array( 'on' => false ) );
		$this->assertSame( 200, $r->get_status() );
		$this->assertFalse( $r->get_data()['on'] );
		$this->assertFalse( $this->ticked_on_both( $this->u['a'] ), 'OFF → reload → OFF' );
		$this->assertSame( array(), jlife_challenges_reminder_recipients( $this->campaign ) );
		$this->assertSame( array( 'reminder_opt_in', 'reminder_opt_out' ), $this->audit_actions(), 'audited like every other path' );

		jlife_challenges_remove_member( $this->u['organizer'], $this->campaign, $this->u['c'] );
		$this->assertSame( 403, $this->rest_as( $this->u['c'], array( 'on' => true ) )->get_status(), 'left: denied' );
	}

	/**
	 * Cookie-authenticated REST calls need the wp_rest nonce: without it the
	 * request counts as signed out and nothing changes; a wrong one is refused.
	 */
	public function test_rest_needs_the_nonce() {
		global $wp_rest_auth_cookie;
		$wp_rest_auth_cookie = true; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- the test drives cookie authentication.
		wp_set_current_user( $this->u['a'] );
		unset( $_SERVER['HTTP_X_WP_NONCE'] );
		$_REQUEST = array();
		$this->assertTrue( rest_cookie_check_errors( null ) );
		$this->assertSame( 0, get_current_user_id(), 'no nonce: treated as signed out' );
		$req = new WP_REST_Request( 'POST', '/jlife/v1/reminder' );
		$req->set_body_params( array( 'on' => '1' ) );
		$this->assertSame( 401, rest_get_server()->dispatch( $req )->get_status() );
		$this->assertNull( jlife_challenges_reminder_pref( $this->u['a'], $this->campaign ) );

		wp_set_current_user( $this->u['a'] );
		$_SERVER['HTTP_X_WP_NONCE'] = 'not-a-nonce';
		$this->assertSame( 'rest_cookie_invalid_nonce', rest_cookie_check_errors( null )->get_error_code(), 'a wrong nonce is refused' );
		unset( $_SERVER['HTTP_X_WP_NONCE'] );
		$wp_rest_auth_cookie = null; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- restore.

		// The checkbox carries a valid wp_rest nonce for its owner, and only for them.
		$this->assertSame( 1, preg_match( '/data-nonce="([^"]+)"/', jlife_challenges_render_reminder_toggle( $this->u['a'], $this->campaign ), $m ) );
		$this->assertNotFalse( wp_verify_nonce( $m[1], 'wp_rest' ) );
		wp_set_current_user( $this->u['b'] );
		$this->assertFalse( wp_verify_nonce( $m[1], 'wp_rest' ) );
	}

	/**
	 * Toggling keeps the saved language; a language change alone keeps on/off.
	 */
	public function test_toggling_keeps_the_language_and_language_saves_alone() {
		$this->rest_as(
			$this->u['a'],
			array(
				'on'     => true,
				'locale' => 'vi',
			)
		);
		$this->rest_as( $this->u['a'], array( 'on' => false ) );
		$this->assertSame( 'vi', jlife_challenges_reminder_pref( $this->u['a'], $this->campaign )['locale'], 'off keeps vi' );
		$r = $this->rest_as( $this->u['a'], array( 'on' => true ) );
		$this->assertSame( 'vi', $r->get_data()['locale'], 'on keeps vi' );
		$this->assertMatchesRegularExpression( '/<option value="vi" selected=\'selected\'>/', jlife_challenges_render_reminder_settings( $this->u['a'], $this->campaign ) );

		$r = $this->rest_as( $this->u['a'], array( 'locale' => 'en_US' ) );
		$this->assertSame( 200, $r->get_status() );
		$this->assertTrue( $r->get_data()['on'], 'a language change leaves it on' );
		$this->assertSame( 'en_US', jlife_challenges_reminder_pref( $this->u['a'], $this->campaign )['locale'] );

		$this->rest_as( $this->u['a'], array( 'on' => false ) );
		$r = $this->rest_as( $this->u['a'], array( 'locale' => 'vi' ) );
		$this->assertFalse( $r->get_data()['on'], 'and leaves it off' );
		$this->assertSame( 'vi', jlife_challenges_reminder_pref( $this->u['a'], $this->campaign )['locale'] );
		$this->assertSame( array(), jlife_challenges_reminder_recipients( $this->campaign ) );

		// A first choice from Today (no language sent) uses the account's language, as My Marathon shows it.
		update_user_meta( $this->u['b'], 'locale', 'vi' );
		$this->assertMatchesRegularExpression( '/<option value="vi" selected=\'selected\'>/', jlife_challenges_render_reminder_settings( $this->u['b'], $this->campaign ), 'account language is the default' );
		$this->rest_as( $this->u['b'], array( 'on' => true ) );
		$this->assertSame( 'vi', jlife_challenges_reminder_pref( $this->u['b'], $this->campaign )['locale'] );
	}

	/**
	 * Without JavaScript the form posts: an unticked box sends only the hidden
	 * "off", a ticked one "off" then "on" (the last wins); the reminder nonce is required.
	 */
	public function test_form_post_without_javascript() {
		$this->post_as( $this->u['a'], array( 'jl_reminder' => 'on' ) );
		$this->assertSame( 'on', jlife_challenges_handle_reminder_post( $this->u['a'], $this->campaign ) );
		$this->assertTrue( $this->ticked_on_both( $this->u['a'] ) );
		$this->post_as(
			$this->u['a'],
			array(
				'jl_reminder'        => 'off',
				'jl_reminder_locale' => 'vi',
			)
		);
		$this->assertSame( 'off', jlife_challenges_handle_reminder_post( $this->u['a'], $this->campaign ) );
		$this->assertFalse( $this->ticked_on_both( $this->u['a'] ) );
		$this->assertSame( 'vi', jlife_challenges_reminder_pref( $this->u['a'], $this->campaign )['locale'] );
		$this->assertStringContainsString( 'Saved.', jlife_challenges_render_reminder_settings( $this->u['a'], $this->campaign, true ) );

		wp_set_current_user( $this->u['b'] );
		$_POST    = array(
			'jl_reminder'     => 'on',
			'_jlife_reminder' => 'bad',
		);
		$_REQUEST = $_POST;
		try {
			jlife_challenges_handle_reminder_post( $this->u['b'], $this->campaign );
			$this->fail( 'a bad nonce must stop the request' );
		} catch ( WPDieException $e ) {
			$this->assertNull( jlife_challenges_reminder_pref( $this->u['b'], $this->campaign ) );
		}
	}

	/**
	 * The form always applies to the signed-in person, whatever user ID a crafted post names.
	 */
	public function test_form_post_cannot_name_another_user() {
		$this->post_as(
			$this->u['a'],
			array(
				'jl_reminder' => 'on',
				'jl_user_id'  => (string) $this->u['b'],
				'user_id'     => (string) $this->u['b'],
			)
		);
		jlife_challenges_handle_reminder_post( $this->u['a'], $this->campaign );
		$this->assertNull( jlife_challenges_reminder_pref( $this->u['b'], $this->campaign ) );
		$this->assertNotNull( jlife_challenges_reminder_pref( $this->u['a'], $this->campaign ) );
		// A value that is not on or off changes nothing.
		$this->post_as( $this->u['b'], array( 'jl_reminder' => 'maybe' ) );
		$this->assertSame( '', jlife_challenges_handle_reminder_post( $this->u['b'], $this->campaign ) );
		$this->assertNull( jlife_challenges_reminder_pref( $this->u['b'], $this->campaign ) );
	}

	/**
	 * A ticked box before the consent step does not make a recipient; recorded consent does.
	 */
	public function test_recipients_need_recorded_consent() {
		$id = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		jlife_challenges_enroll_solo( $id, $this->campaign, 'adult' );
		$this->assertTrue( jlife_challenges_set_reminder( $id, $id, $this->campaign, true, 'en_US' ) );
		$this->assertNotContains( $id, jlife_challenges_reminder_recipients( $this->campaign ), 'no consent yet' );
		jlife_challenges_record_consent( $id, $this->campaign, 'test-version' );
		$this->assertContains( $id, jlife_challenges_reminder_recipients( $this->campaign ) );
	}
}
