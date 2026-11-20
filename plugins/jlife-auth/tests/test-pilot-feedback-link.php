<?php
/**
 * Pilot feedback link (#171) on the sign-in pages: /join, /signin and /consent
 * carry it in their footer, in the page's language, only while the configured
 * feedback post is published.
 *
 * @package jlife-auth
 */

/**
 * Pilot feedback link tests.
 */
class Test_Pilot_Feedback_Link extends WP_UnitTestCase {

	/**
	 * Locale filter callback.
	 *
	 * @var callable|null
	 */
	private $force = null;

	/**
	 * No option, no translations.
	 */
	public function set_up() {
		parent::set_up();
		$GLOBALS['jlife_challenges_test_pll'] = array();
		delete_option( 'jlife_pilot_feedback_post_id' );
		$_SERVER['REQUEST_METHOD'] = 'GET';
	}

	/**
	 * Back to English.
	 */
	public function tear_down() {
		if ( $this->force ) {
			remove_filter( 'determine_locale', $this->force, 99 );
			$this->force = null;
			unload_textdomain( 'jlife-challenges', true );
		}
		$GLOBALS['jlife_challenges_test_pll'] = array();
		parent::tear_down();
	}

	/**
	 * The three sign-in page bodies.
	 *
	 * @return array<string,string>
	 */
	private function bodies() {
		return array(
			'join'    => jlife_auth_join_form( 'invite', array() ),
			'signin'  => jlife_auth_signin_body(),
			'consent' => jlife_auth_consent_body(),
		);
	}

	/**
	 * Unset or unpublished: no link on any sign-in page.
	 */
	public function test_no_link_until_the_post_is_published() {
		foreach ( $this->bodies() as $page => $body ) {
			$this->assertStringNotContainsString( 'jl-pilot-feedback', jlife_auth_page_html( 'X', $body ), $page );
		}
		update_option( 'jlife_pilot_feedback_post_id', self::factory()->post->create( array( 'post_status' => 'draft' ) ) );
		foreach ( $this->bodies() as $page => $body ) {
			$this->assertStringNotContainsString( 'jl-pilot-feedback', jlife_auth_page_html( 'X', $body ), $page );
		}
	}

	/**
	 * Signed out, in English and in Vietnamese, every sign-in page links the same
	 * post after its body, with the label in the page's language.
	 */
	public function test_the_sign_in_pages_link_the_post_in_both_languages() {
		$id = (int) self::factory()->post->create( array( 'post_title' => 'Feedback' ) );
		update_option( 'jlife_pilot_feedback_post_id', $id );
		$href = 'href="' . esc_url( get_permalink( $id ) ) . '"';

		foreach ( $this->bodies() as $page => $body ) {
			$html = jlife_auth_page_html( 'X', $body );
			$this->assertStringContainsString( '<a class="jl-pilot-feedback" ' . $href . '>Pilot Feedback</a>', $html, $page );
			$this->assertGreaterThan( strpos( $html, $body ), strpos( $html, 'jl-pilot-feedback' ), "{$page}: after the body" );
		}

		$this->force = static fn() => 'vi';
		add_filter( 'determine_locale', $this->force, 99 );
		add_filter(
			'lang_dir_for_domain',
			static fn( $path, $domain ) => 'jlife-challenges' === $domain ? dirname( __DIR__, 2 ) . '/jlife-challenges/languages/' : $path,
			10,
			2
		);
		unload_textdomain( 'jlife-challenges', true );
		foreach ( $this->bodies() as $page => $body ) {
			$this->assertStringContainsString( '<a class="jl-pilot-feedback" ' . $href . '>Góp ý thử nghiệm</a>', jlife_auth_page_html( 'X', $body ), $page );
		}
	}
}

// The Polylang stand-ins (shared with the jlife-challenges tests) load with this file, before any test runs.
require_once dirname( __DIR__, 2 ) . '/jlife-challenges/tests/polylang-stub.php';
