<?php
/**
 * Home-screen launcher (#251): the manifest, the head tags on every kind of
 * page, the hint after a submission, and the Help instructions.
 *
 * @package jlife-challenges
 */

/**
 * Launcher tests.
 */
class Test_Launcher extends WP_UnitTestCase {

	/**
	 * The manifest is valid JSON with a stable id, the Today launch address and the icons.
	 */
	public function test_the_manifest_is_valid_and_complete() {
		$json = wp_json_encode( jlife_challenges_launcher_manifest(), JSON_UNESCAPED_SLASHES );
		$m    = json_decode( (string) $json, true );
		$this->assertIsArray( $m );
		$this->assertSame( '/today/', $m['id'] );
		$this->assertSame( '/today/', $m['start_url'], 'no tracking parameter' );
		$this->assertSame( '/', $m['scope'] );
		$this->assertSame( 'standalone', $m['display'] );
		$this->assertSame( 'LATOC', $m['name'] );
		$this->assertSame( 'LATOC', $m['short_name'] );
		$this->assertSame( '#2b5c8a', $m['theme_color'] );
		$sizes    = array();
		$purposes = array();
		foreach ( $m['icons'] as $icon ) {
			$sizes[]    = $icon['sizes'];
			$purposes[] = $icon['purpose'];
			$this->assertSame( 'image/png', $icon['type'] );
			$this->assertFileExists( dirname( __DIR__ ) . '/assets/launcher/' . basename( $icon['src'] ) );
		}
		$this->assertContains( '192x192', $sizes );
		$this->assertContains( '512x512', $sizes );
		$this->assertContains( 'maskable', $purposes );
		$this->assertStringNotContainsString( 'source=', (string) $json );
		$this->assertFileExists( dirname( __DIR__ ) . '/assets/launcher/apple-touch-icon.png' );
	}

	/**
	 * The route exists and the page is not otherwise mistaken for a screen.
	 */
	public function test_the_manifest_route_is_registered() {
		jlife_challenges_launcher_add_rewrite();
		global $wp_rewrite;
		$rules = (array) $wp_rewrite->extra_rules_top;
		$this->assertArrayHasKey( '^latoc\.webmanifest$', $rules );
		$this->assertSame( 'index.php?jlife_screen=manifest', $rules['^latoc\.webmanifest$'] );
	}

	/**
	 * Participant pages, the public shell and the unsubscribe-style pages carry the tags.
	 */
	public function test_participant_and_public_pages_carry_the_head_tags() {
		foreach ( array( 'today', 'marathon', 'public' ) as $active ) {
			$html = jlife_challenges_page_html( 'X', '<p>body</p>', $active );
			$this->assertSame( 1, substr_count( $html, '<link rel="manifest" href="' . esc_url( home_url( '/latoc.webmanifest' ) ) . '">' ), $active );
			$this->assertStringContainsString( '<link rel="apple-touch-icon" href="', $html );
			$this->assertStringContainsString( 'assets/launcher/apple-touch-icon.png', $html );
			$this->assertStringContainsString( '<meta name="apple-mobile-web-app-title" content="LATOC">', $html );
			$this->assertStringContainsString( '<meta name="theme-color" content="#2b5c8a">', $html );
			$this->assertLessThan( strpos( $html, '</head>' ), strpos( $html, 'rel="manifest"' ), 'in the head' );
		}
	}

	/**
	 * The hint shows only after a submission, is hidden by default, and its script is loaded.
	 */
	public function test_the_hint_appears_only_after_a_submission() {
		$view = array(
			'status'       => 'earned',
			'points'       => 1,
			'day_count'    => 104,
			'entry'        => array( 'body' => '' ),
			'campaign'     => null,
			'day_key'      => 'd001',
			'on_time'      => true,
			'ahead'        => false,
			'share_groups' => array(),
		);
		$sent = jlife_challenges_render_soap_feedback( $view, array( 'flags' => array( 'done' => true ) ) );
		$this->assertStringContainsString( 'class="jl-launcher-hint" role="note" hidden', $sent );
		$this->assertStringContainsString( 'Add LATOC to your Home Screen', $sent );
		$this->assertStringContainsString( 'jl-launcher-dismiss', $sent );

		$not_done = jlife_challenges_render_soap_feedback( $view, array() );
		$this->assertStringNotContainsString( 'jl-launcher-hint', $not_done, 'a plain visit shows no hint' );
		$view['status'] = 'draft';
		$draft          = jlife_challenges_render_soap_feedback( $view, array( 'flags' => array( 'done' => true ) ) );
		$this->assertStringNotContainsString( 'jl-launcher-hint', $draft, 'a draft is not a submission' );

		$page = jlife_challenges_page_html( 'X', $sent, 'today' );
		$this->assertStringContainsString( 'assets/launcher.js?v=1', $page );
		$this->assertStringNotContainsString( 'launcher.js', jlife_challenges_page_html( 'X', '<p>body</p>', 'today' ) );
	}

	/**
	 * The hint's script is local-only: no network, no server storage.
	 */
	public function test_the_hint_script_keeps_the_choice_in_this_browser_only() {
		$js = (string) file_get_contents( dirname( __DIR__ ) . '/assets/launcher.js' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- plugin asset.
		$this->assertStringContainsString( 'localStorage', $js );
		$this->assertStringContainsString( 'display-mode: standalone', $js );
		$this->assertStringContainsString( 'max-width: 600px', $js );
		foreach ( array( 'fetch(', 'XMLHttpRequest', 'sendBeacon', 'document.cookie' ) as $needle ) {
			$this->assertStringNotContainsString( $needle, $js );
		}
	}

	/**
	 * The Help copy explains iPhone and Android in both languages.
	 */
	public function test_the_help_copy_has_the_home_screen_section() {
		$data = json_decode( (string) file_get_contents( dirname( __DIR__ ) . '/data/help-pages.json' ), true ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- plugin data.
		$en   = jlife_challenges_help_blocks_html( $data['help']['en']['blocks'] );
		$vi   = jlife_challenges_help_blocks_html( $data['help']['vi']['blocks'] );
		$this->assertStringContainsString( 'id="add-to-home-screen"', $en );
		$this->assertStringContainsString( 'id="add-to-home-screen"', $vi );
		$this->assertStringContainsString( 'Open as Web App', $en );
		$this->assertStringContainsString( 'iOS 16.4', $en );
		$this->assertStringContainsString( 'Install app', $en );
		$this->assertStringContainsString( 'You may need to sign in the first time you open it.', $en );
		$this->assertStringContainsString( 'Lần đầu mở, bạn có thể cần đăng nhập.', $vi );
	}

	/**
	 * Ordinary theme pages (the landing page) get the same tags through wp_head.
	 */
	public function test_theme_pages_get_the_tags_through_wp_head() {
		ob_start();
		do_action( 'wp_head' );
		$head = (string) ob_get_clean();
		$this->assertStringContainsString( '<link rel="manifest" href="' . esc_url( home_url( '/latoc.webmanifest' ) ) . '">', $head );
		$this->assertStringContainsString( 'name="apple-mobile-web-app-title"', $head );
	}
}
