<?php
/**
 * Pilot feedback link (#171): no link unless the configured post is published,
 * the link goes to that post's permalink (or its published translation) in
 * English and Vietnamese, the label is translated, the link sits in the footer
 * of the participant screens and the public /reading/ shell, signed in and
 * signed out, and the plugin opens comments on no post.
 *
 * @package jlife-challenges
 */

/**
 * Pilot feedback tests.
 */
class Test_Pilot_Feedback extends WP_UnitTestCase {

	/**
	 * Locale filter callback.
	 *
	 * @var callable|null
	 */
	private $force = null;

	/**
	 * A clean translation map and no option.
	 */
	public function set_up() {
		parent::set_up();
		$GLOBALS['jlife_challenges_test_pll'] = array();
		delete_option( JLIFE_CHALLENGES_PILOT_FEEDBACK_OPTION );
	}

	/**
	 * Back to English, an empty map and the English catalog.
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
	 * Render in Vietnamese, with the plugin's own catalog (the suite loads the
	 * plugin from the repository, outside WP_PLUGIN_DIR).
	 */
	private function in_vietnamese() {
		$this->force = static fn() => 'vi';
		add_filter( 'determine_locale', $this->force, 99 );
		add_filter(
			'lang_dir_for_domain',
			static fn( $path, $domain ) => 'jlife-challenges' === $domain ? dirname( __DIR__ ) . '/languages/' : $path,
			10,
			2
		);
		unload_textdomain( 'jlife-challenges', true );
	}

	/**
	 * An ordinary blog post, published unless told otherwise.
	 *
	 * @param string $title  Title.
	 * @param string $status Post status.
	 * @return int
	 */
	private function post( $title, $status = 'publish' ) {
		return (int) self::factory()->post->create(
			array(
				'post_type'   => 'post',
				'post_title'  => $title,
				'post_status' => $status,
			)
		);
	}

	/**
	 * The configured feedback post, published.
	 *
	 * @return int
	 */
	private function configure() {
		$id = $this->post( 'LATOC Pilot Testing - Feedback & Questions' );
		update_option( JLIFE_CHALLENGES_PILOT_FEEDBACK_OPTION, $id );
		return $id;
	}

	/**
	 * No option, an unknown ID, a draft, a private, a scheduled, a
	 * password-protected or a trashed post: no link anywhere, never a guess.
	 */
	public function test_no_link_unless_the_configured_post_is_published() {
		$this->assertSame( '', jlife_challenges_pilot_feedback_url() );
		$this->assertSame( '', jlife_challenges_pilot_feedback_link() );
		$this->assertSame( '', jlife_challenges_pilot_feedback_footer() );
		$this->assertSame( '', jlife_challenges_help_footer() );
		$this->assertStringNotContainsString( 'jl-pilot-feedback', jlife_challenges_page_html( 'X', '<p>x</p>', 'today' ) );
		$this->assertStringNotContainsString( 'jl-pilot-feedback', jlife_challenges_page_html( 'X', '<p>x</p>', 'public' ) );

		update_option( JLIFE_CHALLENGES_PILOT_FEEDBACK_OPTION, 999999 );
		$this->assertSame( '', jlife_challenges_pilot_feedback_url(), 'a missing post' );

		foreach ( array( 'draft', 'private', 'pending' ) as $status ) {
			update_option( JLIFE_CHALLENGES_PILOT_FEEDBACK_OPTION, $this->post( 'Feedback', $status ) );
			$this->assertSame( '', jlife_challenges_pilot_feedback_url(), $status );
			$this->assertSame( '', jlife_challenges_help_footer(), $status );
		}

		$locked = (int) self::factory()->post->create( array( 'post_password' => 'x' ) );
		update_option( JLIFE_CHALLENGES_PILOT_FEEDBACK_OPTION, $locked );
		$this->assertSame( '', jlife_challenges_pilot_feedback_url(), 'password-protected' );

		$id = $this->configure();
		$this->assertNotSame( '', jlife_challenges_pilot_feedback_url() );
		wp_trash_post( $id );
		$this->assertSame( '', jlife_challenges_pilot_feedback_url(), 'trashed' );

		update_option( JLIFE_CHALLENGES_PILOT_FEEDBACK_OPTION, 'not a number' );
		$this->assertSame( '', jlife_challenges_pilot_feedback_url() );
	}

	/**
	 * English and Vietnamese both go to the configured post's own permalink when
	 * it has no translation (one bilingual post, one comment thread), and the
	 * label follows the screen language.
	 */
	public function test_one_bilingual_post_for_both_languages() {
		$id   = $this->configure();
		$link = jlife_challenges_pilot_feedback_link();
		$this->assertSame( '<a class="jl-pilot-feedback" href="' . esc_url( get_permalink( $id ) ) . '">Pilot Feedback</a>', $link );

		$this->in_vietnamese();
		$this->assertSame( '<a class="jl-pilot-feedback" href="' . esc_url( get_permalink( $id ) ) . '">Góp ý thử nghiệm</a>', jlife_challenges_pilot_feedback_link() );
		$this->assertStringContainsString( 'aria-label="Góp ý thử nghiệm"', jlife_challenges_pilot_feedback_footer() );
		$this->assertStringNotContainsString( 'Pilot Feedback', jlife_challenges_help_footer() );
	}

	/**
	 * A published Vietnamese twin is used for Vietnamese; a draft twin falls back
	 * to the configured post.
	 */
	public function test_a_published_translation_is_used_for_its_language() {
		$en = $this->configure();
		$vi = $this->post( 'Góp ý thử nghiệm' );
		pll_save_post_translations(
			array(
				'en' => $en,
				'vi' => $vi,
			)
		);
		$this->assertSame( get_permalink( $en ), jlife_challenges_pilot_feedback_url() );
		$this->assertSame( get_permalink( $vi ), jlife_challenges_pilot_feedback_url( 'vi' ) );

		$this->in_vietnamese();
		$this->assertStringContainsString( 'href="' . esc_url( get_permalink( $vi ) ) . '"', jlife_challenges_pilot_feedback_link() );

		wp_update_post(
			array(
				'ID'          => $vi,
				'post_status' => 'draft',
			)
		);
		$this->assertStringContainsString( 'href="' . esc_url( get_permalink( $en ) ) . '"', jlife_challenges_pilot_feedback_link(), 'a draft twin: the configured post' );
	}

	/**
	 * The link is in the footer, after the page body, on Today, My Marathon, My
	 * Group, /soap, /share and the sign-out confirmation, signed in and signed
	 * out, alone or after Help, Rules and Privacy, and once on the public
	 * /reading/ shell. The stylesheet is unchanged (the footer's own rules).
	 */
	public function test_the_link_is_in_the_footer_of_every_screen() {
		$id   = $this->configure();
		$href = 'href="' . esc_url( get_permalink( $id ) ) . '"';
		$user = (int) self::factory()->user->create( array( 'role' => 'subscriber' ) );

		foreach ( array( 0, $user ) as $who ) {
			wp_set_current_user( $who );
			foreach ( array( 'today', 'soap', 'marathon', 'group', 'share', 'signout', 'public' ) as $screen ) {
				$html = jlife_challenges_page_html( 'X', '<p>body</p>', $screen );
				$this->assertSame( 1, substr_count( $html, 'class="jl-pilot-feedback"' ), "{$screen}, user {$who}" );
				$this->assertSame( 1, substr_count( $html, 'class="jl-help-links"' ), "{$screen}, user {$who}" );
				$this->assertStringContainsString( $href, $html, $screen );
				$this->assertGreaterThan( strpos( $html, '</main>' ), strpos( $html, 'jl-pilot-feedback' ), "{$screen}: after the page body" );
			}
		}

		$help = (int) self::factory()->post->create( array( 'post_type' => 'page' ) );
		update_option( 'jlife_challenges_help_page_id', $help );
		$footer = jlife_challenges_help_footer();
		$this->assertLessThan( strpos( $footer, 'jl-pilot-feedback' ), strpos( $footer, '>Help</a>' ), 'after Help, Rules and Privacy' );
		$this->assertStringNotContainsString( 'Help</a>', jlife_challenges_page_html( 'X', '', 'public' ), 'the public shell gets only the feedback link' );
		$this->assertStringContainsString( 'screens.css?v=16', jlife_challenges_page_html( 'X', '', 'today' ) );
	}

	/**
	 * The plugin opens comments nowhere: not on other posts, not on pages, and
	 * not even on the configured post (the owner does that in wp-admin). The
	 * site's default comment status is untouched.
	 */
	public function test_comments_are_not_opened_by_the_plugin() {
		$closed = (int) self::factory()->post->create( array( 'comment_status' => 'closed' ) );
		$page   = (int) self::factory()->post->create(
			array(
				'post_type'      => 'page',
				'comment_status' => 'closed',
			)
		);
		$feed   = (int) self::factory()->post->create( array( 'comment_status' => 'closed' ) );
		update_option( 'default_comment_status', 'closed' );
		update_option( JLIFE_CHALLENGES_PILOT_FEEDBACK_OPTION, $feed );
		jlife_challenges_page_html( 'X', '', 'today' );

		$this->assertFalse( comments_open( $closed ) );
		$this->assertFalse( comments_open( $page ) );
		$this->assertFalse( comments_open( $feed ), 'the owner turns comments on for the post' );
		$this->assertSame( 'closed', get_option( 'default_comment_status' ) );

		$open = (int) self::factory()->post->create( array( 'comment_status' => 'open' ) );
		update_option( JLIFE_CHALLENGES_PILOT_FEEDBACK_OPTION, $open );
		$this->assertTrue( comments_open( $open ), 'and nothing in the plugin closes them again' );
	}

	/**
	 * The feedback link reads no participant data and creates no post.
	 */
	public function test_nothing_is_created_or_read_from_participants() {
		$before = (int) wp_count_posts( 'post' )->publish;
		jlife_challenges_pilot_feedback_footer();
		jlife_challenges_help_footer();
		$this->assertSame( $before, (int) wp_count_posts( 'post' )->publish );
		$source = (string) file_get_contents( dirname( __DIR__ ) . '/includes/pilot-feedback.php' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- the plugin's own source.
		foreach ( array( 'wp_insert_post', 'wp_update_post', 'comment_status', '$wpdb', 'get_current_user_id', 'get_user_meta' ) as $call ) {
			$this->assertStringNotContainsString( $call, $source, $call );
		}
	}
}

// The Polylang stand-ins (shared with the help-page tests) load with this file, before any test runs.
require_once __DIR__ . '/polylang-stub.php';
