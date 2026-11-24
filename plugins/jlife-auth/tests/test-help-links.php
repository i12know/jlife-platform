<?php
/**
 * Help, Rules and Privacy links on the sign-in pages (#65): the footer on /join,
 * /signin and /consent, the Privacy link on the consent step, links that are
 * absent when a page is not set up, and the consent version, which changes only
 * with the wording.
 *
 * @package jlife-auth
 */

/**
 * Help-link tests.
 */
class Test_Help_Links extends WP_UnitTestCase {

	/**
	 * Locale filter callback.
	 *
	 * @var callable|null
	 */
	private $force = null;

	/**
	 * A clean translation map.
	 */
	public function set_up() {
		parent::set_up();
		$GLOBALS['jlife_challenges_test_pll'] = array();
	}

	/**
	 * Back to English and an empty map.
	 */
	public function tear_down() {
		if ( $this->force ) {
			remove_filter( 'determine_locale', $this->force, 99 );
			$this->force = null;
		}
		$GLOBALS['jlife_challenges_test_pll'] = array();
		$_SERVER['REQUEST_METHOD']            = 'GET';
		parent::tear_down();
	}

	/**
	 * A published page, and its option set.
	 *
	 * @param string $key   help, rules or privacy.
	 * @param string $title Title.
	 * @return int
	 */
	private function publish( $key, $title ) {
		$id = (int) self::factory()->post->create(
			array(
				'post_type'  => 'page',
				'post_title' => $title,
			)
		);
		update_option( jlife_challenges_help_page_options()[ $key ], $id );
		return $id;
	}

	/**
	 * Nothing set up: no footer on any sign-in page and no Privacy link on the
	 * consent step.
	 */
	public function test_links_are_absent_when_the_pages_are_not_set_up() {
		$_SERVER['REQUEST_METHOD'] = 'GET';
		foreach ( array(
			jlife_auth_join_form( 'invite', array() ),
			jlife_auth_signin_body(),
			jlife_auth_consent_body(),
		) as $body ) {
			$html = jlife_auth_page_html( 'X', $body );
			$this->assertStringNotContainsString( 'jl-help-links"', $html );
		}
		$this->assertStringNotContainsString( 'privacy summary', jlife_auth_consent_body() );
	}

	/**
	 * The three pages are linked at the foot of /join, /signin and /consent.
	 */
	public function test_the_footer_is_on_the_join_signin_and_consent_pages() {
		$ids = array(
			'help'    => $this->publish( 'help', 'How it works' ),
			'rules'   => $this->publish( 'rules', 'Rules' ),
			'privacy' => $this->publish( 'privacy', 'Privacy' ),
		);
		foreach ( array(
			'join'    => jlife_auth_join_form( 'invite', array() ),
			'solo'    => jlife_auth_join_form( 'solo', array() ),
			'signin'  => jlife_auth_signin_body(),
			'consent' => jlife_auth_consent_body(),
		) as $page => $body ) {
			$html = jlife_auth_page_html( 'X', $body );
			$this->assertSame( 1, substr_count( $html, 'class="jl-help-links"' ), $page );
			foreach ( $ids as $id ) {
				$this->assertStringContainsString( 'href="' . esc_url( get_permalink( $id ) ) . '"', $html, $page );
			}
			$this->assertGreaterThan( strpos( $html, $body ), strpos( $html, 'class="jl-help-links"' ), "{$page}: after the body" );
		}
	}

	/**
	 * On a page the visitor chose to read in Vietnamese the links go to the
	 * Vietnamese pages, and to the original page where there is none.
	 */
	public function test_the_links_follow_the_pages_language() {
		$help_en = $this->publish( 'help', 'How it works' );
		$priv_en = $this->publish( 'privacy', 'Privacy' );
		$help_vi = (int) self::factory()->post->create(
			array(
				'post_type'  => 'page',
				'post_title' => 'Cách hoạt động',
			)
		);
		pll_save_post_translations(
			array(
				'en' => $help_en,
				'vi' => $help_vi,
			)
		);
		$this->force = static fn() => 'vi';
		add_filter( 'determine_locale', $this->force, 99 );

		$html = jlife_auth_page_html( 'X', jlife_auth_signin_body() );
		$this->assertStringContainsString( 'href="' . esc_url( get_permalink( $help_vi ) ) . '"', $html );
		$this->assertStringNotContainsString( 'href="' . esc_url( get_permalink( $help_en ) ) . '"', $html );
		$this->assertStringContainsString( 'href="' . esc_url( get_permalink( $priv_en ) ) . '"', $html, 'no translation: the original page' );
	}

	/**
	 * The consent step links the full privacy summary, from the page itself, only
	 * when the Privacy page is published.
	 */
	public function test_the_consent_step_links_the_privacy_page() {
		$priv = $this->publish( 'privacy', 'Privacy' );
		$body = jlife_auth_consent_body();
		$this->assertStringContainsString( '<p><a href="' . esc_url( get_permalink( $priv ) ) . '">Read the full privacy summary</a></p>', $body );
		$this->assertLessThan( strpos( $body, '<form' ), strpos( $body, 'Read the full privacy summary' ), 'before the agree box, so it is read first' );

		wp_update_post(
			array(
				'ID'          => $priv,
				'post_status' => 'draft',
			)
		);
		$this->assertStringNotContainsString( 'privacy summary', jlife_auth_consent_body(), 'no link to an unpublished page' );
	}

	/**
	 * The consent version is only bumped when the consent wording changes: asking
	 * everyone to agree again is a deliberate act (#65, #120). This fingerprints
	 * the bullets and the agree label (English) against the version.
	 *
	 * If this fails because the wording changed on purpose: bump
	 * JLIFE_AUTH_CONSENT_VERSION in includes/accounts.php (everyone is asked
	 * again), then update both values here. If it fails and the wording did NOT
	 * change on purpose, undo the accidental edit.
	 */
	public function test_the_consent_version_changes_only_with_the_wording() {
		$html = jlife_auth_consent_body();
		preg_match_all( '#<li>(.*?)</li>#s', $html, $items );
		preg_match( '#name="agree" value="1" required> (.*?)</label>#s', $html, $label );
		$text = html_entity_decode( implode( "\n", array_merge( $items[1], array( $label[1] ) ) ), ENT_QUOTES );

		$this->assertCount( 7, $items[1], 'seven consent bullets' );
		$this->assertSame( 'draft-2026-11-24', JLIFE_AUTH_CONSENT_VERSION, 'the version the wording below belongs to' );
		$this->assertSame( '95ef5c7beebabd70450fab5f0c8a1d1e', md5( $text ), 'the consent wording changed: bump JLIFE_AUTH_CONSENT_VERSION' );

		// The privacy link is a link, not wording: it is outside the bullets and the label.
		$this->assertStringNotContainsString( 'privacy summary', $text );
	}

	/**
	 * A participant who agreed to an older wording is asked again; one who agreed
	 * to the current version is not.
	 */
	public function test_an_older_consent_version_asks_again() {
		$user = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		update_user_meta( $user, 'jlife_consent_version', 'draft-2026-11-05' );
		$this->assertTrue( jlife_auth_needs_consent( $user ), 'the old wording' );
		update_user_meta( $user, 'jlife_consent_version', JLIFE_AUTH_CONSENT_VERSION );
		$this->assertFalse( jlife_auth_needs_consent( $user ), 'the current wording' );
	}
}

// The Polylang stand-ins (shared with the jlife-challenges tests) load with this file, before any test runs.
require_once dirname( __DIR__, 2 ) . '/jlife-challenges/tests/polylang-stub.php';
