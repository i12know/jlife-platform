<?php
/**
 * The sign-in, join and consent pages refuse a post with a bad or missing
 * nonce before doing anything (#257): the nonce check is a statement after the
 * step test, so it must still end the request with wp_die().
 *
 * @package jlife-auth
 */

// phpcs:disable WordPress.Security.NonceVerification -- tests drive the pages with crafted requests.

/**
 * Nonce gate tests.
 */
class Test_Auth_Nonce_Gates extends WP_UnitTestCase {

	/**
	 * Create the engine's tables once.
	 */
	public static function wpSetUpBeforeClass() {
		jlife_challenges_install_tables();
	}

	/**
	 * Clean request state and mailbox.
	 */
	public function set_up() {
		parent::set_up();
		reset_phpmailer_instance();
		$_POST    = array();
		$_REQUEST = array();
	}

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
	 * Page callable, step and nonce field for each guarded form.
	 *
	 * @return array<string, array>
	 */
	public function form_provider() {
		return array(
			'signin request' => array( 'jlife_auth_page_signin', 'request', '_jlife_auth' ),
			'signin verify'  => array( 'jlife_auth_page_signin', 'verify', '_jlife_auth' ),
			'signin resend'  => array( 'jlife_auth_page_signin', 'resend', '_jlife_resend' ),
			'join request'   => array( 'jlife_auth_page_join', 'request', '_jlife_auth' ),
			'join verify'    => array( 'jlife_auth_page_join', 'verify', '_jlife_auth' ),
			'join resend'    => array( 'jlife_auth_page_join', 'resend', '_jlife_resend' ),
		);
	}

	/**
	 * A missing or wrong nonce stops the request and sends no mail.
	 *
	 * @dataProvider form_provider
	 *
	 * @param string $page  Page function.
	 * @param string $step  Form step.
	 * @param string $nonce Nonce field name.
	 */
	public function test_bad_or_missing_nonce_stops_the_request( $page, $step, $nonce ) {
		foreach ( array( array(), array( $nonce => 'bad' ) ) as $extra ) {
			$_POST    = array(
				'step'  => $step,
				'email' => 'someone@example.test',
			) + $extra;
			$_REQUEST = $_POST;
			try {
				$page();
				$this->fail( 'a request without a valid nonce must stop' );
			} catch ( WPDieException $e ) {
				$this->assertEmpty( tests_retrieve_phpmailer_instance()->mock_sent, 'nothing was sent' );
			}
		}
	}

	/**
	 * A missing or wrong nonce stops the consent page: no consent is recorded.
	 */
	public function test_consent_post_needs_its_nonce() {
		$user = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		wp_set_current_user( $user );

		foreach ( array( array(), array( '_jlife_auth' => 'bad' ), array( '_jlife_auth' => wp_create_nonce( 'jlife_auth_signin' ) ) ) as $extra ) {
			$_POST    = array(
				'step'  => 'agree',
				'agree' => '1',
			) + $extra;
			$_REQUEST = $_POST;
			try {
				jlife_auth_page_consent();
				$this->fail( 'a consent post without a valid nonce must stop' );
			} catch ( WPDieException $e ) {
				$this->assertSame( '', (string) get_user_meta( $user, 'jlife_consent_version', true ), 'no consent version recorded' );
				$this->assertSame( '', (string) get_user_meta( $user, 'jlife_consent_at', true ), 'no consent time recorded' );
				$this->assertTrue( jlife_auth_needs_consent( $user ), 'still needs consent' );
			}
		}
	}
}
