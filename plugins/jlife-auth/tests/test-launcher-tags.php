<?php
/**
 * Home-screen launcher tags on the signed-out pages (#251): someone who installs
 * LATOC from the sign-in, code or join page gets the same name and icon.
 *
 * @package jlife-auth
 */

/**
 * Launcher tag tests.
 */
class Test_Launcher_Tags extends WP_UnitTestCase {

	/**
	 * Sign-in, code, consent and join pages carry the shared head tags and no hint.
	 */
	public function test_signed_out_pages_carry_the_head_tags() {
		$_SERVER['REQUEST_METHOD'] = 'GET';
		$tags                      = jlife_challenges_launcher_head_tags();
		$this->assertNotSame( '', $tags );
		foreach ( array(
			'signin'  => jlife_auth_signin_body(),
			'join'    => jlife_auth_join_form( 'invite', array() ),
			'consent' => jlife_auth_consent_body(),
		) as $name => $body ) {
			$html = jlife_auth_page_html( 'X', $body );
			$this->assertStringContainsString( $tags, $html, $name );
			$this->assertLessThan( strpos( $html, '</head>' ), strpos( $html, 'rel="manifest"' ), "{$name}: in the head" );
			$this->assertStringContainsString( 'rel="apple-touch-icon"', $html, $name );
			$this->assertStringContainsString( 'name="theme-color"', $html, $name );
			$this->assertStringNotContainsString( 'jl-launcher-hint', $html, "{$name}: no hint for signed-out pages" );
		}
	}
}
