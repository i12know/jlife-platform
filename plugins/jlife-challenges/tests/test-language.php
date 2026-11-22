<?php
/**
 * One Language setting on My Marathon (#159): access control first (only your
 * own language, signed in, nonce, enrolled), then the account language (the
 * screens), the email language kept in step, the default Bible against an
 * explicit choice, the audit row, the markup and its script. Signed-out pages
 * are covered in jlife-auth's Test_Vietnamese (it loads both plugins).
 *
 * @package jlife-challenges
 */

// phpcs:disable WordPress.Security.NonceVerification, WordPress.DB.DirectDatabaseQuery -- tests drive the handlers with crafted requests.

/**
 * Language setting tests.
 */
class Test_Language extends Jlife_Challenges_TestCase {

	/**
	 * Captured PHP error log for this test.
	 *
	 * @var string
	 */
	private $log = '';

	/**
	 * Previous error_log setting.
	 *
	 * @var string|false
	 */
	private $previous_log = false;

	/**
	 * Participants a, b and c have accepted the consent wording; capture the PHP error log.
	 */
	public function set_up() {
		parent::set_up();
		foreach ( array( 'a', 'b', 'c' ) as $n ) {
			jlife_challenges_record_consent( $this->u[ $n ], $this->campaign, 'test-version' );
		}
		$this->log          = wp_tempnam( 'jll-log' );
		$this->previous_log = ini_set( 'error_log', $this->log ); // phpcs:ignore WordPress.PHP.IniSet.Risky -- test: capture the log.
	}

	/**
	 * Clean request state and restore the log.
	 */
	public function tear_down() {
		ini_set( 'error_log', (string) $this->previous_log ); // phpcs:ignore WordPress.PHP.IniSet.Risky -- test: restore the log.
		wp_delete_file( $this->log );
		$_POST    = array();
		$_REQUEST = array();
		$_GET     = array();
		unset( $_SERVER['HTTP_X_WP_NONCE'] );
		$GLOBALS['wp_rest_auth_cookie'] = null; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- reset what test_rest_needs_the_nonce set.
		wp_set_current_user( 0 );
		parent::tear_down();
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
		$req = new WP_REST_Request( 'POST', '/jlife/v1/language' );
		$req->set_header( 'Content-Type', 'application/json' );
		$req->set_body( (string) wp_json_encode( $body ) );
		return rest_get_server()->dispatch( $req );
	}

	/**
	 * Act as a user and post the no-JavaScript form.
	 *
	 * @param int   $user_id Signed-in user.
	 * @param array $post    Fields.
	 */
	private function post_as( $user_id, $post ) {
		wp_set_current_user( $user_id );
		$_POST    = $post + array( '_jlife_language' => wp_create_nonce( JLIFE_CHALLENGES_LANGUAGE_NONCE ) );
		$_REQUEST = $_POST;
	}

	/**
	 * A person's account language (what the screens use).
	 *
	 * @param int $user_id Participant.
	 * @return string
	 */
	private function locale_of( $user_id ) {
		return (string) get_user_meta( $user_id, 'locale', true );
	}

	/**
	 * A person's reminder language in the test campaign.
	 *
	 * @param int $user_id Participant.
	 * @return string|null
	 */
	private function email_language_of( $user_id ) {
		$pref = jlife_challenges_reminder_pref( $user_id, $this->campaign );
		return $pref ? (string) $pref['locale'] : null;
	}

	/**
	 * The language-change audit rows, oldest first.
	 *
	 * @return array[]
	 */
	private function audit_rows() {
		global $wpdb;
		return (array) $wpdb->get_results( 'SELECT actor_user_id, object_type, object_id, campaign_id, detail FROM ' . jlife_challenges_table( 'audit_log' ) . " WHERE action = 'language_change' ORDER BY id", ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	}

	/**
	 * The default Bible and the second one, as the reader resolves them.
	 *
	 * @param int $user_id Participant.
	 * @return array{0: string|null, 1: string|null}
	 */
	private function bibles( $user_id ) {
		$v = jlife_scripture_versions_for_user( $user_id );
		return array( $v[0]['key'] ?? null, $v[1]['key'] ?? null );
	}

	/**
	 * Saving the language changes the account's `locale` (what the screens
	 * read), and the REST answer says so.
	 */
	public function test_saving_changes_the_account_locale() {
		$a = $this->u['a'];
		update_user_meta( $a, 'locale', 'en_US' );
		$r = $this->rest_as( $a, array( 'locale' => 'vi' ) );
		$this->assertSame( 200, $r->get_status() );
		$this->assertSame( array( 'locale' => 'vi' ), $r->get_data() );
		$this->assertSame( 'vi', $this->locale_of( $a ) );
		$this->assertSame( 'vi', jlife_challenges_account_language( $a ) );

		$r = $this->rest_as( $a, array( 'locale' => 'en_US' ) );
		$this->assertSame( 'en_US', $r->get_data()['locale'] );
		$this->assertSame( 'en_US', $this->locale_of( $a ) );

		// "en" is accepted as en_US; anything else is refused and changes nothing.
		$this->assertTrue( jlife_challenges_set_language( $a, $a, 'en' ) );
		$this->assertSame( 'en_US', $this->locale_of( $a ) );
		foreach ( array( 'fr_FR', '', 'vi_VN', '<script>' ) as $bad ) {
			$r = $this->rest_as( $a, array( 'locale' => $bad ) );
			$this->assertSame( 400, $r->get_status(), "refused: {$bad}" );
			$this->assertSame( 'en_US', $this->locale_of( $a ) );
		}
		$this->assertSame( 400, $this->rest_as( $a, array() )->get_status(), 'the language is required' );
		$this->assertSame( 'jlife_language_unknown', jlife_challenges_set_language( $a, $a, 'fr_FR' )->get_error_code() );
	}

	/**
	 * The reminder email language follows: every reminder row of the person is
	 * set to the same code, and on/off and consent stay exactly as they were.
	 */
	public function test_email_language_is_kept_in_step() {
		$a = $this->u['a'];
		$this->assertTrue( jlife_challenges_set_reminder( $a, $a, $this->campaign, true, 'en_US' ) );
		$before = jlife_challenges_reminder_pref( $a, $this->campaign );

		$this->assertTrue( jlife_challenges_set_language( $a, $a, 'vi' ) );
		$after = jlife_challenges_reminder_pref( $a, $this->campaign );
		$this->assertSame( 'vi', $after['locale'] );
		$this->assertSame( $before['opted_in'], $after['opted_in'], 'still on, same opt-in time' );
		$this->assertNull( $after['opted_out'] );
		$this->assertSame( $before['consent_version'], $after['consent_version'] );
		$this->assertSame( array( $a ), jlife_challenges_reminder_recipients( $this->campaign ), 'still a recipient' );

		// Off stays off.
		$b = $this->u['b'];
		jlife_challenges_set_reminder( $b, $b, $this->campaign, false, 'vi' );
		jlife_challenges_set_language( $b, $b, 'en_US' );
		$this->assertSame( 'en_US', $this->email_language_of( $b ) );
		$this->assertFalse( jlife_challenges_reminder_is_on( jlife_challenges_reminder_pref( $b, $this->campaign ) ) );

		// No row yet: none is made (no reminder is ever turned on by a language change);
		// a later first choice uses the account language.
		$c = $this->u['c'];
		jlife_challenges_set_language( $c, $c, 'vi' );
		$this->assertNull( jlife_challenges_reminder_pref( $c, $this->campaign ) );
		jlife_challenges_set_reminder( $c, $c, $this->campaign, true );
		$this->assertSame( 'vi', $this->email_language_of( $c ) );

		// A row in another campaign follows too.
		$other = jlife_challenges_create_campaign(
			$this->u['organizer'],
			array(
				'campaign_key' => 'other-' . wp_generate_password( 6, false ),
				'plan_id'      => 'rpl-life-and-teachings-of-jesus-2026',
				'title'        => 'Earlier marathon (test)',
				'start_date'   => '2025-12-20',
				'end_date'     => '2026-04-02',
				'status'       => 'active',
			)
		);
		jlife_challenges_write_reminder_pref( $a, $other, false, 'en_US' );
		jlife_challenges_set_language( $a, $a, 'en_US' );
		jlife_challenges_set_language( $a, $a, 'vi' );
		$this->assertSame( 'vi', jlife_challenges_reminder_pref( $a, $other )['locale'] );

		// Somebody else's rows are untouched.
		$this->assertSame( 'en_US', $this->email_language_of( $b ) );
	}

	/**
	 * Migration rule (owner confirmed 2026-11-20, #159): an account whose saved email
	 * language differs from its screen language shows the screen language in
	 * the setting, and keeps its email language until the next save.
	 */
	public function test_a_different_saved_email_language_is_kept_until_the_next_save() {
		$a = $this->u['a'];
		update_user_meta( $a, 'locale', 'en_US' );
		jlife_challenges_set_reminder( $a, $a, $this->campaign, true, 'vi' );
		$html = jlife_challenges_render_language_setting( $a, $this->campaign );
		$this->assertMatchesRegularExpression( '/<option value="en_US" lang="en-US" selected=\'selected\'>English<\/option>/', $html, 'the screen language is shown' );
		$this->assertSame( 'vi', $this->email_language_of( $a ), 'the email language is not migrated by rendering' );
		jlife_challenges_set_reminder( $a, $a, $this->campaign, false );
		$this->assertSame( 'vi', $this->email_language_of( $a ), 'nor by the reminder checkbox' );

		$this->rest_as( $a, array( 'locale' => 'en_US' ) );
		$this->assertSame( 'en_US', $this->email_language_of( $a ), 'the next save brings them together' );
	}

	/**
	 * The default Bible follows the language; an explicit My Bible choice
	 * always stays, and "Default" follows the new language again.
	 */
	public function test_default_bible_follows_but_an_explicit_choice_is_kept() {
		if ( ! function_exists( 'jlife_scripture_versions_for_user' ) ) {
			$this->markTestSkipped( 'jlife-scripture is not loaded.' );
		}
		$a = $this->u['a'];
		$this->assertSame( array( 'nlt', null ), $this->bibles( $a ) );
		$this->rest_as( $a, array( 'locale' => 'vi' ) );
		$this->assertSame( array( 'kt1925', null ), $this->bibles( $a ), 'Tiếng Việt: Kinh Thánh 1925' );
		$this->rest_as( $a, array( 'locale' => 'en_US' ) );
		$this->assertSame( array( 'nlt', null ), $this->bibles( $a ), 'English: NLT' );

		$this->assertTrue( jlife_challenges_save_bible_choice( $a, array( 'primary' => 'kt1925' ) ) );
		foreach ( array( 'vi', 'en_US' ) as $language ) {
			$this->rest_as( $a, array( 'locale' => $language ) );
			$this->assertSame( array( 'kt1925', null ), $this->bibles( $a ), "the explicit choice stays after {$language}" );
			$this->assertSame( 'kt1925', (string) get_user_meta( $a, 'jlife_bible_version', true ) );
		}
		$this->assertTrue( jlife_challenges_save_bible_choice( $a, array( 'secondary' => 'erv' ) ) );
		$this->rest_as( $a, array( 'locale' => 'vi' ) );
		$this->assertSame( array( 'kt1925', 'erv' ), $this->bibles( $a ), 'the second Bible stays too' );

		// Back to "Default for my language": follows the language now in force.
		$this->assertTrue( jlife_challenges_save_bible_choice( $a, array( 'primary' => '' ) ) );
		$this->assertSame( 'kt1925', $this->bibles( $a )[0] );
		$this->rest_as( $a, array( 'locale' => 'en_US' ) );
		$this->assertSame( 'nlt', $this->bibles( $a )[0] );
	}

	/**
	 * Only the signed-in person's own language, ever: another participant and
	 * an organizer are refused, a user ID in the body is ignored, and nobody
	 * signed out or not enrolled can save.
	 */
	public function test_only_your_own_language() {
		$a = $this->u['a'];
		$b = $this->u['b'];
		update_user_meta( $b, 'locale', 'en_US' );

		$this->assertSame( 'jlife_denied', jlife_challenges_set_language( $a, $b, 'vi' )->get_error_code(), 'another participant' );
		$this->assertSame( 'jlife_denied', jlife_challenges_set_language( $this->u['organizer'], $b, 'vi' )->get_error_code(), 'not even an organizer' );
		$this->assertSame( 'jlife_denied', jlife_challenges_set_language( 0, $b, 'vi' )->get_error_code(), 'nobody' );
		$this->assertSame( 'en_US', $this->locale_of( $b ) );

		$r = $this->rest_as(
			$a,
			array(
				'locale'  => 'vi',
				'user_id' => $b,
			)
		);
		$this->assertSame( 200, $r->get_status() );
		$this->assertSame( 'vi', $this->locale_of( $a ) );
		$this->assertSame( 'en_US', $this->locale_of( $b ), 'a user ID in the body is ignored' );

		$this->assertSame( 401, $this->rest_as( 0, array( 'locale' => 'vi' ) )->get_status(), 'signed out' );
		$o = $this->u['outsider'];
		$r = $this->rest_as( $o, array( 'locale' => 'vi' ) );
		$this->assertSame( 403, $r->get_status(), 'not enrolled' );
		$this->assertSame( 'jlife_denied', $r->get_data()['code'] );
		$this->assertSame( '', $this->locale_of( $o ) );

		jlife_challenges_remove_member( $this->u['organizer'], $this->campaign, $this->u['c'] );
		$this->assertSame( 403, $this->rest_as( $this->u['c'], array( 'locale' => 'vi' ) )->get_status(), 'left: denied' );
		$this->assertSame( array(), array_filter( $this->audit_rows(), static fn( $row ) => (int) $row['object_id'] !== $a ), 'refusals are not audited as changes' );
	}

	/**
	 * Cookie-authenticated REST calls need the wp_rest nonce: without it the
	 * request counts as signed out and nothing changes; a wrong one is refused.
	 */
	public function test_rest_needs_the_nonce() {
		global $wp_rest_auth_cookie;
		$a = $this->u['a'];
		update_user_meta( $a, 'locale', 'en_US' );
		$wp_rest_auth_cookie = true; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- the test drives cookie authentication.
		wp_set_current_user( $a );
		unset( $_SERVER['HTTP_X_WP_NONCE'] );
		$_REQUEST = array();
		$this->assertTrue( rest_cookie_check_errors( null ) );
		$this->assertSame( 0, get_current_user_id(), 'no nonce: treated as signed out' );
		$req = new WP_REST_Request( 'POST', '/jlife/v1/language' );
		$req->set_body_params( array( 'locale' => 'vi' ) );
		$this->assertSame( 401, rest_get_server()->dispatch( $req )->get_status() );
		$this->assertSame( 'en_US', $this->locale_of( $a ) );

		wp_set_current_user( $a );
		$_SERVER['HTTP_X_WP_NONCE'] = 'not-a-nonce';
		$this->assertSame( 'rest_cookie_invalid_nonce', rest_cookie_check_errors( null )->get_error_code(), 'a wrong nonce is refused' );
		unset( $_SERVER['HTTP_X_WP_NONCE'] );
		$wp_rest_auth_cookie = null; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- restore.

		// The form carries a valid wp_rest nonce for its owner, and only for them.
		$this->assertSame( 1, preg_match( '/data-nonce="([^"]+)"/', jlife_challenges_render_language_setting( $a, $this->campaign ), $m ) );
		$this->assertNotFalse( wp_verify_nonce( $m[1], 'wp_rest' ) );
		wp_set_current_user( $this->u['b'] );
		$this->assertFalse( wp_verify_nonce( $m[1], 'wp_rest' ) );
	}

	/**
	 * Without JavaScript the form posts: it needs the form's own nonce, applies
	 * to the signed-in person whatever user ID the post names, and refuses an
	 * unknown language.
	 */
	public function test_form_post_without_javascript() {
		$a = $this->u['a'];
		$b = $this->u['b'];
		update_user_meta( $a, 'locale', 'en_US' );
		update_user_meta( $b, 'locale', 'en_US' );

		$this->post_as(
			$a,
			array(
				'jl_language' => 'vi',
				'user_id'     => (string) $b,
				'jl_user_id'  => (string) $b,
			)
		);
		$this->assertTrue( jlife_challenges_handle_language_post( $a ) );
		$this->assertSame( 'vi', $this->locale_of( $a ) );
		$this->assertSame( 'en_US', $this->locale_of( $b ), 'a crafted user ID is ignored' );

		$this->post_as( $a, array( 'jl_language' => 'fr_FR' ) );
		$this->assertFalse( jlife_challenges_handle_language_post( $a ) );
		$this->post_as( $a, array( 'jl_language' => array( 'vi' ) ) );
		$this->assertFalse( jlife_challenges_handle_language_post( $a ) );
		$this->assertSame( 'vi', $this->locale_of( $a ) );

		// Not enrolled: nothing.
		$o = $this->u['outsider'];
		$this->post_as( $o, array( 'jl_language' => 'vi' ) );
		$this->assertFalse( jlife_challenges_handle_language_post( $o ) );
		$this->assertSame( '', $this->locale_of( $o ) );

		// No form marker: not our post.
		$_POST    = array( 'jl_reminder' => 'on' );
		$_REQUEST = $_POST;
		$this->assertFalse( jlife_challenges_handle_language_post( $a ) );

		// A bad nonce, or another form's nonce, stops the request.
		foreach ( array( 'bad', wp_create_nonce( JLIFE_CHALLENGES_REMINDER_NONCE ), wp_create_nonce( JLIFE_CHALLENGES_BIBLE_NONCE ) ) as $nonce ) {
			wp_set_current_user( $a );
			$_POST    = array(
				'jl_language'     => 'en_US',
				'_jlife_language' => $nonce,
			);
			$_REQUEST = $_POST;
			try {
				jlife_challenges_handle_language_post( $a );
				$this->fail( 'a wrong nonce must stop the request' );
			} catch ( WPDieException $e ) {
				$this->assertSame( 'vi', $this->locale_of( $a ) );
			}
		}
	}

	/**
	 * Each change is audited like the reminder opt-in: who, whose, which
	 * campaign, and the old and new language code; no log line, no personal data.
	 */
	public function test_changes_are_audited_without_personal_data() {
		$a = $this->u['a'];
		update_user_meta( $a, 'locale', 'en_US' );
		$this->rest_as( $a, array( 'locale' => 'vi' ) );
		$this->rest_as( $a, array( 'locale' => 'bogus' ) );
		$this->rest_as( $this->u['outsider'], array( 'locale' => 'vi' ) );

		$rows = $this->audit_rows();
		$this->assertCount( 1, $rows, 'only the change' );
		$this->assertSame( (string) $a, (string) $rows[0]['actor_user_id'] );
		$this->assertSame( 'account', $rows[0]['object_type'] );
		$this->assertSame( (string) $a, (string) $rows[0]['object_id'] );
		$this->assertSame( (string) $this->campaign, (string) $rows[0]['campaign_id'] );
		$this->assertSame(
			array(
				'from'   => 'en_US',
				'locale' => 'vi',
			),
			json_decode( $rows[0]['detail'], true )
		);
		$user = get_userdata( $a );
		$this->assertStringNotContainsString( (string) $user->user_email, $rows[0]['detail'] );
		$this->assertSame( '', (string) file_get_contents( $this->log ), 'nothing in the PHP error log' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- test log.
	}

	/**
	 * My Marathon shows one Language select (English / Tiếng Việt, each in its
	 * own language) with its REST route, nonce, reload target, status line and
	 * a Save button without JavaScript; "Language of the email" is gone.
	 */
	public function test_markup_and_script() {
		$a = $this->u['a'];
		update_user_meta( $a, 'locale', 'vi' );
		$html = jlife_challenges_render_reminder_settings( $a, $this->campaign );
		$this->assertSame( 1, substr_count( $html, '<select id="jl-language" name="jl_language">' ) );
		$this->assertStringNotContainsString( 'jl_reminder_locale', $html );
		$this->assertStringNotContainsString( 'Language of the email', $html );
		$this->assertStringContainsString( '<option value="en_US" lang="en-US">English</option>', $html );
		$this->assertStringContainsString( '<option value="vi" lang="vi" selected=\'selected\'>Tiếng Việt</option>', $html );
		$this->assertStringContainsString( '<label for="jl-language">Language for screens and emails</label>', $html );
		$this->assertStringContainsString( 'data-rest="' . esc_url( rest_url( 'jlife/v1/language' ) ) . '"', $html );
		$this->assertStringContainsString( 'data-done="' . esc_url( add_query_arg( 'language', 'saved', home_url( '/marathon/' ) ) ) . '"', $html );
		$this->assertStringContainsString( 'name="_jlife_language"', $html );
		$this->assertStringContainsString( '<noscript><button type="submit" class="jl-button jl-small">Save</button></noscript>', $html );
		$this->assertMatchesRegularExpression( '/<p class="jl-reminder-status jl-language-status" role="status" aria-live="polite"><\/p>/', $html );
		$this->assertStringContainsString( 'aria-live="polite">Saved.</p>', jlife_challenges_render_language_setting( $a, $this->campaign, true ) );

		// Nothing for nobody, nothing without a campaign, nothing on Today.
		$this->assertSame( '', jlife_challenges_render_language_setting( 0, $this->campaign ) );
		$this->assertSame( '', jlife_challenges_render_language_setting( $a, 0 ) );
		$this->assertStringNotContainsString( 'jl-language', jlife_challenges_render_reminder_toggle( $a, $this->campaign ) );

		$page = jlife_challenges_page_html( 'My Marathon', $html, 'marathon', array( 'language', 'reminder', 'bible' ) );
		$this->assertStringContainsString( 'assets/language.js?v=1', $page );
		$this->assertStringContainsString( 'assets/reminder.js?v=2', $page );
		$this->assertStringContainsString( 'assets/screens.css?v=23', $page );
		$this->assertStringNotContainsString( 'language.js', jlife_challenges_page_html( 'Today', '', 'today', array( 'soap', 'reminder' ) ) );
		$js = (string) file_get_contents( dirname( __DIR__ ) . '/assets/language.js' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- plugin asset.
		$this->assertStringContainsString( 'window.location.replace(form.dataset.done)', $js, 'reloads in the new language' );
	}

	/**
	 * Signed-out pages are untouched by the setting: nothing renders for a
	 * visitor, and a person's change never reaches anyone else's account.
	 */
	public function test_signed_out_and_other_accounts_are_unaffected() {
		$a = $this->u['a'];
		$this->rest_as( $a, array( 'locale' => 'vi' ) );
		wp_set_current_user( 0 );
		$this->assertSame( '', jlife_challenges_render_language_setting( 0, $this->campaign ) );
		$this->assertSame( 'en_US', jlife_challenges_account_language( 0 ), 'a visitor gets the page language' );
		foreach ( array( 'b', 'c', 'outsider', 'organizer' ) as $n ) {
			$this->assertSame( '', $this->locale_of( $this->u[ $n ] ), "{$n} untouched" );
		}
	}
}
