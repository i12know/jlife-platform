<?php
/**
 * My Bible (#140): each participant chooses their Bible on My Marathon. Access
 * control first (only your own choice, signed in, nonce, enrolled), then the
 * registry check, the default that follows My language until a person picks,
 * the optional second Bible, and the picker markup. Written before the feature.
 *
 * @package jlife-challenges
 */

// phpcs:disable WordPress.Security.NonceVerification, WordPress.DB.DirectDatabaseQuery -- tests drive the handlers with crafted requests.

/**
 * My Bible tests.
 */
class Test_Bible_Choice extends Jlife_Challenges_TestCase {

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
	 * Capture the PHP error log; the picker needs the Scripture plugin.
	 */
	public function set_up() {
		parent::set_up();
		if ( ! function_exists( 'jlife_scripture_versions_for_user' ) ) {
			$this->markTestSkipped( 'jlife-scripture is not loaded.' );
		}
		$this->log          = wp_tempnam( 'jlb-log' );
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
		$req = new WP_REST_Request( 'POST', '/jlife/v1/bible' );
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
		$_POST    = $post + array(
			'jl_bible'     => 'save',
			'_jlife_bible' => wp_create_nonce( JLIFE_CHALLENGES_BIBLE_NONCE ),
		);
		$_REQUEST = $_POST;
	}

	/**
	 * The stored choice as the reader reads it.
	 *
	 * @param int $user_id Participant.
	 * @return array{0: string|null, 1: string|null}
	 */
	private function keys( $user_id ) {
		$v = jlife_scripture_versions_for_user( $user_id );
		return array( $v[0]['key'] ?? null, $v[1]['key'] ?? null );
	}

	/**
	 * Enable versions for a test (a data edit of the registry).
	 *
	 * @param string[] $keys   Versions to enable.
	 * @param string[] $native Of those, versions to make native with licence evidence.
	 */
	private function enable( $keys, $native = array() ) {
		add_filter(
			'jlife_scripture_registry',
			static function ( $doc ) use ( $keys, $native ) {
				foreach ( $doc['versions'] as $i => $v ) {
					if ( in_array( $v['key'], $keys, true ) ) {
						$doc['versions'][ $i ]['enabled'] = true;
					}
					if ( in_array( $v['key'], $native, true ) ) {
						$doc['versions'][ $i ]['display']          = 'native';
						$doc['versions'][ $i ]['license_evidence'] = 'Test only.';
					}
				}
				return $doc;
			}
		);
	}

	/**
	 * The picker's HTML for a participant (the My Marathon section).
	 *
	 * @param int $user_id Participant.
	 * @return string
	 */
	private function section( $user_id ) {
		return jlife_challenges_render_reminder_settings( $user_id, $this->campaign );
	}

	/**
	 * One option in a select: the text of the option with this value, or null.
	 *
	 * @param string $html  Markup.
	 * @param string $name  Select name.
	 * @param string $value Option value.
	 * @return string|null
	 */
	private function option_text( $html, $name, $value ) {
		if ( ! preg_match( '/<select id="[^"]+" name="' . preg_quote( $name, '/' ) . '">(.*?)<\/select>/s', $html, $select ) ) {
			return null;
		}
		if ( ! preg_match( '/<option value="' . preg_quote( $value, '/' ) . '"[^>]*>([^<]*)<\/option>/', $select[1], $m ) ) {
			return null;
		}
		return html_entity_decode( $m[1], ENT_QUOTES );
	}

	/**
	 * The value selected in a select.
	 *
	 * @param string $html Markup.
	 * @param string $name Select name.
	 * @return string|null
	 */
	private function selected_value( $html, $name ) {
		if ( ! preg_match( '/<select id="[^"]+" name="' . preg_quote( $name, '/' ) . '">(.*?)<\/select>/s', $html, $select ) ) {
			return null;
		}
		return preg_match( '/<option value="([^"]*)" selected=\'selected\'>/', $select[1], $m ) ? $m[1] : null;
	}

	/**
	 * REST: only a signed-in, enrolled person, and only for their own choice. A
	 * user ID in the body is ignored, an organizer cannot set it for someone
	 * else, and there is no way to read anyone's choice.
	 */
	public function test_rest_saves_only_the_signed_in_persons_choice() {
		$this->assertSame( 401, $this->rest_as( 0, array( 'bible_version' => 'kt1925' ) )->get_status(), 'signed out' );
		$r = $this->rest_as( $this->u['outsider'], array( 'bible_version' => 'kt1925' ) );
		$this->assertSame( 403, $r->get_status(), 'not enrolled' );
		$this->assertSame( 'jlife_denied', $r->get_data()['code'] );
		$this->assertSame( '', (string) get_user_meta( $this->u['outsider'], 'jlife_bible_version', true ) );

		$r = $this->rest_as(
			$this->u['a'],
			array(
				'bible_version' => 'kt1925',
				'user_id'       => $this->u['b'],
				'campaign_id'   => 999999,
			)
		);
		$this->assertSame( 200, $r->get_status() );
		$this->assertSame( 'kt1925', get_user_meta( $this->u['a'], 'jlife_bible_version', true ) );
		$this->assertSame( '', (string) get_user_meta( $this->u['b'], 'jlife_bible_version', true ), 'a user ID in the body is ignored' );
		$this->assertSame( 'kt1925', $r->get_data()['bible_version'] );

		// An organizer is not a participant here and cannot act for anyone.
		$r = $this->rest_as(
			$this->u['organizer'],
			array(
				'bible_version' => 'erv',
				'user_id'       => $this->u['a'],
			)
		);
		$this->assertSame( 403, $r->get_status() );
		$this->assertSame( 'kt1925', get_user_meta( $this->u['a'], 'jlife_bible_version', true ), 'unchanged' );
		$this->assertSame( '', (string) get_user_meta( $this->u['organizer'], 'jlife_bible_version', true ) );

		jlife_challenges_remove_member( $this->u['organizer'], $this->campaign, $this->u['c'] );
		$this->assertSame( 403, $this->rest_as( $this->u['c'], array( 'bible_version' => 'erv' ) )->get_status(), 'left: denied' );
		$this->assertSame( '', (string) get_user_meta( $this->u['c'], 'jlife_bible_version', true ) );

		wp_set_current_user( $this->u['a'] );
		$this->assertSame( 404, rest_get_server()->dispatch( new WP_REST_Request( 'GET', '/jlife/v1/bible' ) )->get_status(), 'no route reads a choice' );
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
		$req = new WP_REST_Request( 'POST', '/jlife/v1/bible' );
		$req->set_body_params( array( 'bible_version' => 'kt1925' ) );
		$this->assertSame( 401, rest_get_server()->dispatch( $req )->get_status() );
		$this->assertSame( '', (string) get_user_meta( $this->u['a'], 'jlife_bible_version', true ) );

		wp_set_current_user( $this->u['a'] );
		$_SERVER['HTTP_X_WP_NONCE'] = 'not-a-nonce';
		$this->assertSame( 'rest_cookie_invalid_nonce', rest_cookie_check_errors( null )->get_error_code(), 'a wrong nonce is refused' );
		unset( $_SERVER['HTTP_X_WP_NONCE'] );
		$wp_rest_auth_cookie = null; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- restore.

		// The picker carries a valid wp_rest nonce for its owner, and only for them.
		$this->assertSame( 1, preg_match( '/class="jl-bible-picker"[^>]*data-nonce="([^"]+)"/', $this->section( $this->u['a'] ), $m ) );
		$this->assertNotFalse( wp_verify_nonce( $m[1], 'wp_rest' ) );
		wp_set_current_user( $this->u['b'] );
		$this->assertFalse( wp_verify_nonce( $m[1], 'wp_rest' ) );
	}

	/**
	 * Only keys the registry lists as enabled are accepted; a refusal changes
	 * nothing and never echoes the value.
	 */
	public function test_unknown_and_disabled_keys_are_rejected() {
		$this->rest_as( $this->u['a'], array( 'bible_version' => 'kt1925' ) );
		foreach ( array( 'bible_version', 'bible_version_2' ) as $field ) {
			foreach ( array( 'bogus', 'nirv', 'kthd', '../kt1925', 'KT1925 ' ) as $key ) {
				$r = $this->rest_as( $this->u['a'], array( $field => $key ) );
				$this->assertSame( 400, $r->get_status(), "{$field}: {$key}" );
				$this->assertSame( 'jlife_bible_unknown', $r->get_data()['code'] );
				$this->assertStringNotContainsString( 'bogus', (string) wp_json_encode( $r->get_data() ) );
			}
			$r = $this->rest_as( $this->u['a'], array( $field => 5 ) );
			$this->assertSame( 400, $r->get_status(), 'not a string' );
		}
		$this->assertSame( 'kt1925', get_user_meta( $this->u['a'], 'jlife_bible_version', true ) );
		$this->assertSame( '', (string) get_user_meta( $this->u['a'], 'jlife_bible_version_2', true ) );

		$this->enable( array( 'nirv' ) );
		$this->assertSame( 200, $this->rest_as( $this->u['a'], array( 'bible_version_2' => 'nirv' ) )->get_status(), 'once the registry enables it' );
	}

	/**
	 * Until a person picks, the Bible follows My language (since #159 the
	 * account's language, set by the Language setting); once they pick it stays put.
	 */
	public function test_default_follows_my_language_then_the_choice_is_sticky() {
		$a = $this->u['a'];
		$this->assertSame( array( 'nlt', null ), $this->keys( $a ), 'no language chosen: English' );

		$this->assertTrue( jlife_challenges_set_language( $a, $a, 'vi' ) );
		$this->assertSame( array( 'kt1925', null ), $this->keys( $a ), 'Tiếng Việt: Kinh Thánh 1925' );
		$this->rest_as( $a, array() ); // An empty save changes nothing.
		$this->assertTrue( jlife_challenges_set_language( $a, $a, 'en_US' ) );
		$this->assertSame( array( 'nlt', null ), $this->keys( $a ), 'back to English: NLT' );

		// The account language decides, not a saved email language that differs (#159 migration rule).
		update_user_meta( $this->u['b'], 'locale', 'vi' );
		jlife_challenges_set_reminder( $this->u['b'], $this->u['b'], $this->campaign, false, 'en_US' );
		$this->assertSame( array( 'kt1925', null ), $this->keys( $this->u['b'] ) );
		$this->assertFalse( has_filter( 'jlife_scripture_user_language' ), 'nothing overrides the account language' );

		// The language has no say once a person has picked.
		$this->assertSame( 200, $this->rest_as( $a, array( 'bible_version' => 'kt1925' ) )->get_status() );
		foreach ( array( 'vi', 'en_US', 'vi', 'en_US' ) as $language ) {
			jlife_challenges_set_language( $a, $a, $language );
			$this->assertSame( array( 'kt1925', null ), $this->keys( $a ), "after changing the language to {$language}" );
		}
		$this->rest_as( $a, array( 'bible_version' => 'erv' ) );
		jlife_challenges_set_language( $a, $a, 'vi' );
		$this->assertSame( array( 'erv', null ), $this->keys( $a ) );

		// Clearing goes back to following the language.
		$r = $this->rest_as( $a, array( 'bible_version' => null ) );
		$this->assertSame( 200, $r->get_status() );
		$this->assertSame( '', $r->get_data()['bible_version'] );
		$this->assertSame( array( 'kt1925', null ), $this->keys( $a ), 'follows the (Vietnamese) language again' );
		$this->rest_as( $a, array( 'bible_version' => 'erv' ) );
		$this->rest_as( $a, array( 'bible_version' => '' ) );
		$this->assertSame( '', (string) get_user_meta( $a, 'jlife_bible_version', true ), 'an empty value clears too' );
	}

	/**
	 * A choice that the registry disables later falls back to the language
	 * default; the participant sees nothing alarming (no error, nothing logged).
	 */
	public function test_a_disabled_choice_falls_back_cleanly() {
		$a      = $this->u['a'];
		$filter = static function ( $doc ) {
			foreach ( $doc['versions'] as $i => $v ) {
				if ( 'niv' === $v['key'] ) {
					$doc['versions'][ $i ]['enabled'] = true;
				}
			}
			return $doc;
		};
		add_filter( 'jlife_scripture_registry', $filter );
		$this->assertSame( 200, $this->rest_as( $a, array( 'bible_version' => 'niv' ) )->get_status() );
		$this->assertSame( array( 'niv', null ), $this->keys( $a ) );
		remove_filter( 'jlife_scripture_registry', $filter );

		$this->assertSame( array( 'nlt', null ), $this->keys( $a ), 'disabled: the language default' );
		$html = $this->section( $a );
		$this->assertNull( $this->option_text( $html, 'jl_bible_version', 'niv' ), 'no longer offered' );
		$this->assertSame( '', $this->selected_value( $html, 'jl_bible_version' ), 'shown as the language default' );
		$this->assertSame( '', (string) file_get_contents( $this->log ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- test log.
	}

	/**
	 * The second Bible is optional, saved on its own, clearable, and the first
	 * one's save keeps it.
	 */
	public function test_second_bible_is_optional_and_clearable() {
		$a = $this->u['a'];
		$r = $this->rest_as( $a, array( 'bible_version_2' => 'erv' ) );
		$this->assertSame( 200, $r->get_status() );
		$this->assertSame( 'erv', $r->get_data()['bible_version_2'] );
		$this->assertSame( '', $r->get_data()['bible_version'], 'the first still follows the language' );
		$this->assertSame( array( 'nlt', 'erv' ), $this->keys( $a ) );

		$r = $this->rest_as( $a, array( 'bible_version' => 'kt1925' ) );
		$this->assertSame( 'erv', $r->get_data()['bible_version_2'], 'saving the first keeps the second' );
		$this->assertSame( array( 'kt1925', 'erv' ), $this->keys( $a ) );
		$this->assertSame( 'erv', $this->selected_value( $this->section( $a ), 'jl_bible_version_2' ) );

		$r = $this->rest_as( $a, array( 'bible_version_2' => null ) );
		$this->assertSame( '', $r->get_data()['bible_version_2'] );
		$this->assertSame( array( 'kt1925', null ), $this->keys( $a ), 'cleared' );
		$this->assertSame( '', $this->selected_value( $this->section( $a ), 'jl_bible_version_2' ), '"None" is selected' );

		// The same Bible twice makes no sense: the second is dropped and the answer says so.
		$this->rest_as( $a, array( 'bible_version_2' => 'erv' ) );
		$r = $this->rest_as( $a, array( 'bible_version' => 'erv' ) );
		$this->assertSame( '', $r->get_data()['bible_version_2'] );
		$this->assertSame( array( 'erv', null ), $this->keys( $a ) );
	}

	/**
	 * The picker sits in the Daily reading email section, below the Language
	 * section (#159): "My Bible" grouped by language, "Also show…" with a None
	 * choice, the REST route and its nonce, a status line, and a Save button
	 * without JavaScript.
	 */
	public function test_picker_markup_is_in_the_reminder_section_under_the_language() {
		update_option( 'jlife_reminders_mode', 'live' ); // The checkbox and its heading show only in saved Live mode (#189).
		$html = $this->section( $this->u['a'] );
		$this->assertStringStartsWith( '<section class="jl-reminders jl-language"><h2>Language</h2>', $html );
		$this->assertStringEndsWith( '</section>', $html );
		$language = strpos( $html, 'id="jl-language"' );
		$reminder = strpos( $html, '<section class="jl-reminders"><h2>Daily reading email</h2>' );
		$picker   = strpos( $html, 'class="jl-bible-picker"' );
		$this->assertNotFalse( $language );
		$this->assertNotFalse( $reminder );
		$this->assertGreaterThan( $language, $reminder, 'the Language section comes first' );
		$this->assertGreaterThan( $reminder, $picker, 'in the Daily reading email section' );
		$this->assertSame( 1, substr_count( $html, 'class="jl-bible-picker"' ) );

		$this->assertStringContainsString( 'data-rest="' . esc_url( rest_url( 'jlife/v1/bible' ) ) . '"', $html );
		$this->assertStringContainsString( 'data-nonce="', $html );
		$this->assertStringContainsString( '<label for="jl-bible-version">My Bible</label>', $html );
		$this->assertStringContainsString( '<label for="jl-bible-version-2">Also show…</label>', $html );
		$this->assertStringContainsString( '<select id="jl-bible-version" name="jl_bible_version">', $html );
		$this->assertStringContainsString( '<select id="jl-bible-version-2" name="jl_bible_version_2">', $html );
		$this->assertStringContainsString( 'name="_jlife_bible"', $html );
		$this->assertStringContainsString( '<noscript><button type="submit" class="jl-button jl-small">Save</button></noscript>', $html );
		$this->assertMatchesRegularExpression( '/<p class="jl-reminder-status jl-bible-status" role="status" aria-live="polite"><\/p>/', $html );
		$this->assertStringContainsString( 'Not saved. Please try again.', html_entity_decode( $html ) );

		$this->assertSame( 'Default for my language', $this->option_text( $html, 'jl_bible_version', '' ) );
		$this->assertSame( 'None', $this->option_text( $html, 'jl_bible_version_2', '' ) );
		$this->assertSame( '', $this->selected_value( $html, 'jl_bible_version' ), 'follows the language: the default is selected' );
		$this->assertSame( '', $this->selected_value( $html, 'jl_bible_version_2' ) );

		// Not on Today, not for nobody.
		$this->assertStringNotContainsString( 'jl-bible', jlife_challenges_render_reminder_toggle( $this->u['a'], $this->campaign ) );
		$this->assertStringNotContainsString( 'jl-bible-picker', jlife_challenges_render_reminder_settings( 0, $this->campaign ) );
		$this->assertSame( '', jlife_challenges_render_bible_picker( 0, $this->campaign ) );
		$this->assertSame( '', jlife_challenges_render_bible_picker( $this->u['a'], 0 ) );
	}

	/**
	 * Every enabled version, grouped by language in the registry's order: NLT,
	 * NKJV and CSB (text on the page through API.Bible, #141) with ERV (a link);
	 * versions whose text cannot be shown say "(opens Bible Gateway)". Disabled
	 * ones are not there.
	 */
	public function test_picker_lists_enabled_versions_grouped_with_gateway_labels() {
		$html = $this->section( $this->u['a'] );
		$this->assertMatchesRegularExpression( '/<optgroup label="English">.*?<\/optgroup><optgroup label="Tiếng Việt">.*?<\/optgroup>/s', $html );
		foreach ( array( 'jl_bible_version', 'jl_bible_version_2' ) as $name ) {
			$this->assertSame( 'ERV — Easy-to-Read Version (opens Bible Gateway)', $this->option_text( $html, $name, 'erv' ), $name );
			$this->assertSame( 'BPT — Vietnamese Bible: Easy-to-Read Version (opens Bible Gateway)', $this->option_text( $html, $name, 'bpt' ) );
			$this->assertSame( 'KT1925 — Kinh Thánh 1925 (Bản Truyền Thống)', $this->option_text( $html, $name, 'kt1925' ), 'text on the page: no label' );
			$this->assertSame( 'NLT — New Living Translation', $this->option_text( $html, $name, 'nlt' ), 'text on the page: no label' );
			$this->assertSame( 'NKJV — New King James Version', $this->option_text( $html, $name, 'nkjv' ) );
			$this->assertSame( 'CSB — Christian Standard Bible', $this->option_text( $html, $name, 'csb' ) );
			foreach ( array( 'nirv', 'niv', 'kthd' ) as $disabled ) {
				$this->assertNull( $this->option_text( $html, $name, $disabled ), "{$disabled} is not enabled" );
			}
		}
		$this->assertSame( 1, preg_match( '/<optgroup label="English">(.*?)<\/optgroup>/s', $html, $english ) );
		preg_match_all( '/<option value="([a-z0-9]+)"/', $english[1], $keys );
		$this->assertSame( array( 'nlt', 'nkjv', 'csb', 'erv' ), $keys[1], 'English: the enabled versions, in registry order' );
		$this->assertStringNotContainsString( 'kt1925', $english[1], 'grouped by the version\'s own language' );

		// An owner enables NIrV (text on the page) and NIV (link only): both appear in English.
		$this->enable( array( 'nirv', 'niv' ), array( 'nirv' ) );
		$html = $this->section( $this->u['a'] );
		$this->assertSame( "NIrV — New International Reader's Version", $this->option_text( $html, 'jl_bible_version', 'nirv' ) );
		$this->assertSame( 'NIV — New International Version (opens Bible Gateway)', $this->option_text( $html, 'jl_bible_version', 'niv' ) );
		$this->assertLessThan( strpos( $html, 'value="niv"' ), strpos( $html, 'value="nirv"' ), 'registry order' );

		// The saved choices are the selected options.
		$this->rest_as(
			$this->u['a'],
			array(
				'bible_version'   => 'niv',
				'bible_version_2' => 'kt1925',
			)
		);
		$html = $this->section( $this->u['a'] );
		$this->assertSame( 'niv', $this->selected_value( $html, 'jl_bible_version' ) );
		$this->assertSame( 'kt1925', $this->selected_value( $html, 'jl_bible_version_2' ) );
		$this->assertSame( '', $this->selected_value( $this->section( $this->u['b'] ), 'jl_bible_version' ), 'another person sees their own' );
	}

	/**
	 * Without JavaScript the form posts both selects; the choice applies to the
	 * signed-in person, needs the form's nonce and a registry key.
	 */
	public function test_form_post_without_javascript() {
		$a = $this->u['a'];
		$this->post_as(
			$a,
			array(
				'jl_bible_version'   => 'erv',
				'jl_bible_version_2' => 'kt1925',
				'user_id'            => (string) $this->u['b'],
				'jl_user_id'         => (string) $this->u['b'],
			)
		);
		$this->assertTrue( jlife_challenges_handle_bible_post( $a ) );
		$this->assertSame( array( 'erv', 'kt1925' ), $this->keys( $a ) );
		$this->assertSame( '', (string) get_user_meta( $this->u['b'], 'jlife_bible_version', true ), 'a crafted user ID is ignored' );
		$this->assertStringNotContainsString( 'aria-live="polite">Saved.</p>', jlife_challenges_render_bible_picker( $a, $this->campaign ) );
		$this->assertStringContainsString( 'aria-live="polite">Saved.</p>', jlife_challenges_render_bible_picker( $a, $this->campaign, true ) );

		// Empty selections clear (the "Default" and "None" options post an empty value).
		$this->post_as(
			$a,
			array(
				'jl_bible_version'   => '',
				'jl_bible_version_2' => '',
			)
		);
		$this->assertTrue( jlife_challenges_handle_bible_post( $a ) );
		$this->assertSame( array( 'nlt', null ), $this->keys( $a ) );

		// An unknown key saves nothing.
		$this->post_as( $a, array( 'jl_bible_version' => 'bogus' ) );
		$this->assertFalse( jlife_challenges_handle_bible_post( $a ) );
		$this->assertSame( array( 'nlt', null ), $this->keys( $a ) );

		// Not enrolled: nothing.
		$o = $this->u['outsider'];
		$this->post_as( $o, array( 'jl_bible_version' => 'erv' ) );
		$this->assertFalse( jlife_challenges_handle_bible_post( $o ) );
		$this->assertSame( '', (string) get_user_meta( $o, 'jlife_bible_version', true ) );

		// No form marker: not our post.
		$_POST    = array( 'jl_bible_version' => 'erv' );
		$_REQUEST = $_POST;
		wp_set_current_user( $a );
		$this->assertFalse( jlife_challenges_handle_bible_post( $a ) );

		// A bad nonce stops the request.
		$_POST    = array(
			'jl_bible'         => 'save',
			'jl_bible_version' => 'erv',
			'_jlife_bible'     => 'bad',
		);
		$_REQUEST = $_POST;
		try {
			jlife_challenges_handle_bible_post( $a );
			$this->fail( 'a bad nonce must stop the request' );
		} catch ( WPDieException $e ) {
			$this->assertSame( array( 'nlt', null ), $this->keys( $a ) );
		}

		// The reminder form's nonce does not open this form.
		$_POST    = array(
			'jl_bible'         => 'save',
			'jl_bible_version' => 'erv',
			'_jlife_bible'     => wp_create_nonce( JLIFE_CHALLENGES_REMINDER_NONCE ),
		);
		$_REQUEST = $_POST;
		try {
			jlife_challenges_handle_bible_post( $a );
			$this->fail( 'another form\'s nonce must not work' );
		} catch ( WPDieException $e ) {
			$this->assertSame( array( 'nlt', null ), $this->keys( $a ) );
		}
	}

	/**
	 * A save, a refusal and a read leave no log line and no audit row: no
	 * person, no key, no value.
	 */
	public function test_no_personal_data_in_logs() {
		global $wpdb;
		$before = (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . jlife_challenges_table( 'audit_log' ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$this->rest_as(
			$this->u['a'],
			array(
				'bible_version'   => 'erv',
				'bible_version_2' => 'kt1925',
			)
		);
		$this->rest_as( $this->u['a'], array( 'bible_version' => 'bogus' ) );
		$this->rest_as( $this->u['outsider'], array( 'bible_version' => 'erv' ) );
		$this->section( $this->u['a'] );
		$this->assertSame( '', (string) file_get_contents( $this->log ), 'nothing in the PHP error log' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- test log.
		$this->assertSame( $before, (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . jlife_challenges_table( 'audit_log' ) ), 'a preference is not audited' ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	}

	/**
	 * Clearing the second Bible (#204), by REST and by the no-JavaScript form,
	 * clears only that slot: My Bible, Language and the reminder consent stay.
	 */
	public function test_clearing_the_second_bible_leaves_the_other_preferences_alone() {
		update_option( 'jlife_reminders_mode', 'live' );
		$a = $this->u['a'];
		jlife_challenges_set_reminder( $a, $a, $this->campaign, true, 'en_US' );
		$language = jlife_challenges_account_language( $a );
		$pref     = jlife_challenges_reminder_pref( $a, $this->campaign );

		// REST (the immediate save): pick a second Bible, then None.
		$this->rest_as(
			$a,
			array(
				'bible_version'   => 'kt1925',
				'bible_version_2' => 'erv',
			)
		);
		$r = $this->rest_as( $a, array( 'bible_version_2' => null ) );
		$this->assertSame( 200, $r->get_status() );
		$this->assertSame( array( 'kt1925', null ), $this->keys( $a ) );
		$this->assertSame( '', (string) get_user_meta( $a, 'jlife_bible_version_2', true ), 'nothing stored' );
		$this->assertSame( 'kt1925', get_user_meta( $a, 'jlife_bible_version', true ), 'My Bible unchanged' );

		// Without JavaScript: both selects post; "None" posts an empty value.
		$this->rest_as( $a, array( 'bible_version_2' => 'erv' ) );
		$this->post_as(
			$a,
			array(
				'jl_bible_version'   => 'kt1925',
				'jl_bible_version_2' => '',
			)
		);
		$this->assertTrue( jlife_challenges_handle_bible_post( $a ) );
		$this->assertSame( array( 'kt1925', null ), $this->keys( $a ), 'cleared after the reload too' );
		$this->assertSame( '', $this->selected_value( $this->section( $a ), 'jl_bible_version_2' ) );

		// The reminder form does not see this post, and Language and consent are as before.
		$this->assertSame( '', jlife_challenges_handle_reminder_post( $a, $this->campaign ) );
		$this->assertSame( $language, jlife_challenges_account_language( $a ) );
		$this->assertEquals( $pref, jlife_challenges_reminder_pref( $a, $this->campaign ), 'reminder consent unchanged' );
		$this->assertTrue( jlife_challenges_reminder_is_on( jlife_challenges_reminder_pref( $a, $this->campaign ) ) );
	}

	/**
	 * Today and the SOAP reader resolve the same pair, so after clearing they
	 * show the primary Bible only (#204).
	 */
	public function test_cleared_second_bible_leaves_one_bible_for_the_readers() {
		$a = $this->u['a'];
		$this->rest_as(
			$a,
			array(
				'bible_version'   => 'kt1925',
				'bible_version_2' => 'erv',
			)
		);
		$this->assertSame( 'erv', $this->keys( $a )[1] );
		$this->rest_as( $a, array( 'bible_version_2' => null ) );
		$pair = jlife_scripture_versions_for_user( $a );
		$this->assertSame( 'kt1925', $pair[0]['key'] );
		$this->assertNull( $pair[1], 'no second Bible for Today or the SOAP reader' );
	}

	/**
	 * My Marathon prints the settings before the score and the grid, one copy of
	 * each control; the reminder checkbox is only in saved Live mode (#189, #204).
	 */
	public function test_settings_come_before_the_grid_and_the_reminder_follows_live_mode() {
		$src = (string) file_get_contents( dirname( __DIR__ ) . '/includes/screens.php' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- reading the plugin's own source.
		$fn  = substr( $src, (int) strpos( $src, 'function jlife_challenges_screen_marathon' ) );
		$fn  = substr( $fn, 0, (int) strpos( $fn, "\n}\n" ) );
		$this->assertSame( 1, substr_count( $fn, 'jlife_challenges_render_reminder_settings(' ), 'one copy of the settings' );
		$h1       = strpos( $fn, "'<h1>'" );
		$settings = strpos( $fn, 'jlife_challenges_render_reminder_settings(' );
		$this->assertNotFalse( $h1 );
		$this->assertGreaterThan( $h1, $settings, 'under the heading' );
		$this->assertLessThan( (int) strpos( $fn, 'jl-score' ), $settings, 'before the score' );
		$this->assertLessThan( (int) strpos( $fn, 'jl-grid' ), $settings, 'before the grid' );

		foreach ( array( 'off', 'dry_run', 'test' ) as $mode ) {
			update_option( 'jlife_reminders_mode', $mode );
			$html = $this->section( $this->u['a'] );
			$this->assertStringNotContainsString( 'name="jl_reminder"', $html, $mode );
			$this->assertSame( 1, substr_count( $html, 'class="jl-bible-picker"' ), $mode );
			$this->assertSame( 1, substr_count( $html, 'id="jl-language"' ), $mode );
		}
		update_option( 'jlife_reminders_mode', 'live' );
		$html = $this->section( $this->u['a'] );
		$this->assertSame( 2, substr_count( $html, 'name="jl_reminder"' ), 'hidden off + checkbox, once' );
		$this->assertStringContainsString( 'To show only one Bible, choose None', $html );
	}

	/**
	 * A hidden reminder checkbox is never an opt-out: the Bible and Language
	 * posts carry no reminder field, so a saved opt-in survives them (#189, #204).
	 */
	public function test_saving_bible_outside_live_mode_keeps_the_reminder_opt_in() {
		update_option( 'jlife_reminders_mode', 'live' );
		$a = $this->u['a'];
		jlife_challenges_set_reminder( $a, $a, $this->campaign, true, 'en_US' );
		update_option( 'jlife_reminders_mode', 'off' );
		$this->post_as( $a, array( 'jl_bible_version_2' => '' ) );
		$this->assertTrue( jlife_challenges_handle_bible_post( $a ) );
		$this->assertSame( '', jlife_challenges_handle_reminder_post( $a, $this->campaign ) );
		$this->assertTrue( jlife_challenges_reminder_is_on( jlife_challenges_reminder_pref( $a, $this->campaign ) ) );
	}

	/**
	 * The picker's script and the new stylesheet version load on My Marathon.
	 */
	public function test_my_marathon_loads_the_script_and_stylesheet_version() {
		$html = jlife_challenges_page_html( 'My Marathon', $this->section( $this->u['a'] ), 'marathon', array( 'reminder', 'bible' ) );
		$this->assertStringContainsString( 'assets/bible.js?v=1', $html );
		$this->assertStringContainsString( 'assets/reminder.js?v=2', $html );
		$this->assertStringContainsString( 'assets/screens.css?v=25', $html );
		$this->assertStringNotContainsString( 'bible.js', jlife_challenges_page_html( 'Today', '', 'today', array( 'soap', 'reminder' ) ) );
		$css = (string) file_get_contents( dirname( __DIR__ ) . '/assets/screens.css' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- plugin asset.
		$this->assertStringContainsString( '.jl-bible-picker', $css );
	}
}
