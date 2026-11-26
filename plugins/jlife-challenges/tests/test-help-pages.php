<?php
/**
 * Help, Rules and Privacy pages (#65): the one-time seed, the footer links in
 * the reader's language, links that are absent rather than broken, the SOAP
 * help link, and the seeded wording against what the app does.
 *
 * @package jlife-challenges
 */

/**
 * Help-page tests.
 */
class Test_Help_Pages extends Jlife_Challenges_TestCase {

	/**
	 * Locale filter callback.
	 *
	 * @var callable|null
	 */
	private $force = null;

	/**
	 * A clean translation map for every test.
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
		parent::tear_down();
	}

	/**
	 * Read the page in Vietnamese.
	 */
	private function in_vietnamese() {
		$this->force = static fn() => 'vi';
		add_filter( 'determine_locale', $this->force, 99 );
	}

	/**
	 * A page, published unless told otherwise.
	 *
	 * @param string $title  Title.
	 * @param string $status Post status.
	 * @return int
	 */
	private function page( $title, $status = 'publish' ) {
		return (int) self::factory()->post->create(
			array(
				'post_type'   => 'page',
				'post_title'  => $title,
				'post_status' => $status,
			)
		);
	}

	/**
	 * Make one page the Vietnamese translation of another (the Polylang stand-in).
	 *
	 * @param int $en_id English page ID.
	 * @param int $vi_id Vietnamese page ID.
	 */
	private function translate( $en_id, $vi_id ) {
		$GLOBALS['jlife_challenges_test_pll'][ $en_id ] = array(
			'en' => $en_id,
			'vi' => $vi_id,
		);
	}

	/**
	 * Every seed page (any status) with its seed key.
	 *
	 * @return array<string,int>
	 */
	private function seeded_pages() {
		$out = array();
		foreach ( array( 'help', 'rules', 'privacy' ) as $key ) {
			foreach ( array( 'en', 'vi' ) as $language ) {
				$id = jlife_challenges_find_seeded_help_page( $key . '-' . $language );
				if ( $id ) {
					$out[ $key . '-' . $language ] = $id;
				}
			}
		}
		return $out;
	}

	/**
	 * The first seed creates six DRAFT pages (three, in two languages), links each
	 * pair, and stores the English IDs in the options. Nothing is public.
	 */
	public function test_the_seed_creates_six_linked_drafts_and_stores_the_ids() {
		$result = jlife_challenges_seed_help_pages();
		$this->assertIsArray( $result );
		$this->assertFalse( $result['seeded_before'] );
		$this->assertCount( 6, $result['created'] );
		$pages = $this->seeded_pages();
		$this->assertCount( 6, $pages );
		foreach ( $pages as $seed => $id ) {
			$this->assertSame( 'draft', get_post_status( $id ), "{$seed} is a draft" );
			$this->assertSame( 'page', get_post_type( $id ) );
			$this->assertSame( substr( $seed, -2 ), get_post_meta( $id, '_test_pll_language', true ), "{$seed} has its language" );
		}
		foreach ( jlife_challenges_help_page_options() as $key => $option ) {
			$this->assertSame( $pages[ $key . '-en' ], (int) get_option( $option ), "{$option} is the English page" );
			$this->assertSame( $pages[ $key . '-vi' ], pll_get_post( $pages[ $key . '-en' ], 'vi' ), "{$key}: EN and VI are linked" );
		}
		$this->assertSame( '1', get_option( JLIFE_CHALLENGES_HELP_SEEDED_OPTION ) );
	}

	/**
	 * Seeded once: an edit in wp-admin survives every later run, whether or not
	 * the "seeded" flag is still there, and no page is duplicated.
	 */
	public function test_an_edited_page_is_never_overwritten() {
		jlife_challenges_seed_help_pages();
		$pages = $this->seeded_pages();
		$id    = $pages['privacy-en'];
		wp_update_post(
			array(
				'ID'           => $id,
				'post_title'   => 'Our privacy promise',
				'post_content' => 'The owner wrote this.',
			)
		);

		$again = jlife_challenges_seed_help_pages();
		$this->assertTrue( $again['seeded_before'] );
		$this->assertSame( array(), $again['created'] );
		$this->assertSame( 'The owner wrote this.', get_post_field( 'post_content', $id ) );

		// Even if the flag were lost, the existing pages are found and left alone.
		delete_option( JLIFE_CHALLENGES_HELP_SEEDED_OPTION );
		$third = jlife_challenges_seed_help_pages();
		$this->assertSame( array(), $third['created'] );
		$this->assertCount( 6, $third['existing'] );
		$this->assertSame( 'The owner wrote this.', get_post_field( 'post_content', $id ) );
		$this->assertSame( 'Our privacy promise', get_post_field( 'post_title', $id ) );
		$this->assertSame( $pages, $this->seeded_pages(), 'no duplicate pages' );
	}

	/**
	 * A page the owner trashed or deleted is not brought back.
	 */
	public function test_a_trashed_or_deleted_page_is_not_recreated() {
		jlife_challenges_seed_help_pages();
		$pages = $this->seeded_pages();
		wp_trash_post( $pages['rules-en'] );
		delete_option( JLIFE_CHALLENGES_HELP_SEEDED_OPTION );
		$result = jlife_challenges_seed_help_pages();
		$this->assertSame( array(), $result['created'], 'a trashed page counts as existing' );
		$this->assertSame( 'trash', get_post_status( $pages['rules-en'] ) );

		wp_delete_post( $pages['help-vi'], true );
		$after = jlife_challenges_seed_help_pages();
		$this->assertTrue( $after['seeded_before'], 'with the flag set nothing is created, even for a deleted page' );
		$this->assertSame( 0, jlife_challenges_find_seeded_help_page( 'help-vi' ) );
	}

	/**
	 * A page option the owner already set is left alone.
	 */
	public function test_an_option_the_owner_set_is_kept() {
		$mine = $this->page( 'My own privacy page' );
		update_option( 'jlife_challenges_privacy_page_id', $mine );
		jlife_challenges_seed_help_pages();
		$this->assertSame( $mine, (int) get_option( 'jlife_challenges_privacy_page_id' ) );
		$this->assertGreaterThan( 0, (int) get_option( 'jlife_challenges_help_page_id' ), 'the others are filled in' );
	}

	/**
	 * Without Polylang nothing is created (the pages are English and Vietnamese).
	 */
	public function test_without_polylang_nothing_is_seeded() {
		add_filter( 'jlife_challenges_help_polylang_ready', '__return_false' );
		$result = jlife_challenges_seed_help_pages();
		remove_filter( 'jlife_challenges_help_polylang_ready', '__return_false' );
		$this->assertWPError( $result );
		$this->assertSame( array(), $this->seeded_pages() );
		$this->assertFalse( get_option( JLIFE_CHALLENGES_HELP_SEEDED_OPTION ) );
	}

	/**
	 * No page option set: no footer, and the participant screens have no link.
	 */
	public function test_links_are_absent_when_no_page_is_set() {
		$this->assertSame( '', jlife_challenges_help_footer() );
		$this->assertSame( '', jlife_challenges_help_page_url( 'help' ) );
		$this->assertSame( '', jlife_challenges_help_page_url( 'nonsense' ) );
		$this->assertSame( '', jlife_challenges_soap_help_page_link() );
		$this->assertStringNotContainsString( 'jl-help-links', jlife_challenges_page_html( 'Today', '<p>x</p>', 'today' ) );
	}

	/**
	 * English links go to the pages the options name; a missing, draft or trashed
	 * page gives no link rather than a broken one.
	 */
	public function test_only_published_pages_are_linked() {
		$help = $this->page( 'Help' );
		$rule = $this->page( 'Rules', 'draft' );
		$priv = $this->page( 'Privacy' );
		update_option( 'jlife_challenges_help_page_id', $help );
		update_option( 'jlife_challenges_rules_page_id', $rule );
		update_option( 'jlife_challenges_privacy_page_id', $priv );

		$footer = jlife_challenges_help_footer();
		$this->assertStringContainsString( 'href="' . esc_url( get_permalink( $help ) ) . '">Help</a>', $footer );
		$this->assertStringContainsString( 'href="' . esc_url( get_permalink( $priv ) ) . '">Privacy</a>', $footer );
		$this->assertStringNotContainsString( 'Rules', $footer, 'a draft page is not linked' );

		wp_trash_post( $priv );
		update_option( 'jlife_challenges_help_page_id', 999999 );
		$this->assertSame( '', jlife_challenges_help_footer(), 'a missing and a trashed page give no footer at all' );

		update_option( 'jlife_challenges_help_page_id', $priv );
		$this->assertSame( '', jlife_challenges_help_page_url( 'help' ), 'trash is not public' );
		$other = $this->page( 'A post type that is not a page' );
		wp_update_post(
			array(
				'ID'        => $other,
				'post_type' => 'post',
			)
		);
		update_option( 'jlife_challenges_help_page_id', $other );
		$this->assertSame( '', jlife_challenges_help_page_url( 'help' ), 'only pages are linked' );
	}

	/**
	 * The footer follows the reader's language through the Polylang translation,
	 * and falls back to the page itself when there is none or it is unpublished.
	 */
	public function test_the_footer_links_resolve_to_the_readers_language() {
		$en = array(
			'help'    => $this->page( 'How it works' ),
			'rules'   => $this->page( 'Rules' ),
			'privacy' => $this->page( 'Privacy' ),
		);
		$vi = array(
			'help'    => $this->page( 'Cách hoạt động' ),
			'rules'   => $this->page( 'Quy định', 'draft' ),
			'privacy' => 0,
		);
		foreach ( jlife_challenges_help_page_options() as $key => $option ) {
			update_option( $option, $en[ $key ] );
		}
		$this->translate( $en['help'], $vi['help'] );
		$this->translate( $en['rules'], $vi['rules'] );

		// English: the original pages.
		$footer = jlife_challenges_help_footer();
		foreach ( $en as $id ) {
			$this->assertStringContainsString( 'href="' . esc_url( get_permalink( $id ) ) . '"', $footer );
		}

		// Vietnamese: the translation when it is published, else the original.
		$this->in_vietnamese();
		$footer = jlife_challenges_help_footer();
		$this->assertStringContainsString( 'href="' . esc_url( get_permalink( $vi['help'] ) ) . '"', $footer, 'Help in Vietnamese' );
		$this->assertStringNotContainsString( 'href="' . esc_url( get_permalink( $en['help'] ) ) . '"', $footer );
		$this->assertStringContainsString( 'href="' . esc_url( get_permalink( $en['rules'] ) ) . '"', $footer, 'a draft translation falls back to the original' );
		$this->assertStringContainsString( 'href="' . esc_url( get_permalink( $en['privacy'] ) ) . '"', $footer, 'no translation falls back to the original' );
		$this->assertSame( get_permalink( $vi['help'] ) . '#what-is-soap', jlife_challenges_help_page_url( 'help', '', 'what-is-soap' ) );
	}

	/**
	 * Every participant screen carries the footer: Today, SOAP, My Marathon, My
	 * Group, the share page and the sign-out confirmation all go through one page
	 * builder. The footer sits outside the page's <main>.
	 */
	public function test_the_footer_is_on_every_participant_screen() {
		update_option( 'jlife_challenges_help_page_id', $this->page( 'Help' ) );
		foreach ( array( 'today', 'group', 'marathon', 'signout' ) as $screen ) {
			$html = jlife_challenges_page_html( 'X', '<p>body</p>', $screen );
			$this->assertSame( 1, substr_count( $html, 'class="jl-help-links"' ), $screen );
			$this->assertGreaterThan( strpos( $html, '</main>' ), strpos( $html, 'class="jl-help-links"' ), "{$screen}: after the page body" );
		}
		$this->assertStringContainsString( 'screens.css?v=31', jlife_challenges_page_html( 'X', '', 'today' ) );
		$this->assertStringContainsString( '.jl-help-links', (string) file_get_contents( dirname( __DIR__ ) . '/assets/screens.css' ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- plugin asset.
	}

	/**
	 * The SOAP one-liner keeps its "More" link to the in-page help and gains a
	 * link to the SOAP section of the Help page, only when that page is published.
	 */
	public function test_the_soap_one_liner_links_to_the_help_page_section() {
		$this->assertSame( '', jlife_challenges_soap_help_page_link() );
		$help = $this->page( 'Help' );
		update_option( 'jlife_challenges_help_page_id', $help );
		$link = jlife_challenges_soap_help_page_link();
		$this->assertStringContainsString( 'href="' . esc_url( get_permalink( $help ) ) . '#what-is-soap"', $link );
		$this->assertStringContainsString( 'How SOAP works', $link );
		$this->assertSame( 'what-is-soap', JLIFE_CHALLENGES_SOAP_HELP_ANCHOR );
	}

	/**
	 * The seeded copy: marked DRAFT in both languages, a placeholder for the
	 * contact, and no real address, in every page.
	 */
	public function test_the_seed_copy_is_marked_draft_and_has_no_real_contact_details() {
		$data = jlife_challenges_help_pages_data();
		$this->assertSame( array( 'help', 'rules', 'privacy' ), array_keys( $data ) );
		foreach ( $data as $key => $languages ) {
			$this->assertSame( array( 'en', 'vi' ), array_keys( $languages ), $key );
			$this->assertSame(
				wp_list_pluck( $languages['en']['blocks'], 0 ),
				wp_list_pluck( $languages['vi']['blocks'], 0 ),
				"{$key}: both languages have the same blocks"
			);
			foreach ( $languages as $language => $page ) {
				$html = jlife_challenges_help_blocks_html( $page['blocks'] );
				$this->assertDoesNotMatchRegularExpression( '/[A-Za-z0-9._+-]+@[A-Za-z0-9-]+\.[A-Za-z]{2,}/', $html, "{$key}-{$language}: no email address" );
				$this->assertStringNotContainsStringIgnoringCase( 'redemptionpoint', $html );
				$this->assertStringNotContainsString( '/home/', $html, 'no hosting paths' );
				$this->assertStringContainsString( 'en' === $language ? 'DRAFT for the owner to review' : 'BẢN NHÁP (DRAFT)', $html, "{$key}-{$language}: marked draft" );
				if ( 'vi' === $language ) {
					$this->assertStringContainsString( 'người dịch', $html, "{$key}-vi: marked for translator review" );
				}
				if ( 'rules' !== $key ) {
					$this->assertStringContainsString( '[privacy contact email]', $html, "{$key}-{$language}: contact placeholder" );
				}
			}
		}
		$html = jlife_challenges_help_blocks_html( $data['help']['en']['blocks'] );
		$this->assertStringContainsString( '<h2 class="wp-block-heading" id="what-is-soap">', $html, 'the SOAP section anchor' );
		$vi = jlife_challenges_help_blocks_html( $data['help']['vi']['blocks'] );
		$this->assertStringContainsString( 'id="what-is-soap"', $vi );
	}

	/**
	 * The pages describe sharing as it works since #98 and #120: the whole entry,
	 * Prayer included, with the author's first name. The old "only the parts you
	 * select" wording must not come back.
	 */
	public function test_the_pages_describe_whole_entry_sharing() {
		$data = jlife_challenges_help_pages_data();
		foreach ( array( 'help', 'privacy' ) as $key ) {
			$en = jlife_challenges_help_blocks_html( $data[ $key ]['en']['blocks'] );
			$vi = jlife_challenges_help_blocks_html( $data[ $key ]['vi']['blocks'] );
			$this->assertStringContainsString( 'whole entry', $en, $key );
			$this->assertStringContainsString( 'Prayer', $en, $key );
			$this->assertStringContainsString( 'toàn bộ bài viết', $vi, $key );
			$this->assertStringContainsString( 'Cầu nguyện', $vi, $key );
		}
		foreach ( $data as $key => $languages ) {
			foreach ( $languages as $page ) {
				$html = strtolower( jlife_challenges_help_blocks_html( $page['blocks'] ) );
				foreach ( array( 'parts you select', 'parts you picked', 'parts you choose', 'phần bạn đã chọn', 'những phần bạn' ) as $old ) {
					$this->assertStringNotContainsString( $old, $html, "{$key}: outdated sharing wording" );
				}
			}
		}
	}

	/**
	 * The Privacy page carries the two backup policies exactly as the owner
	 * approved them (#65), and the D19 youth paragraph, in both languages.
	 */
	public function test_the_privacy_page_has_the_two_backup_policies_and_the_youth_paragraph() {
		$data = jlife_challenges_help_pages_data();
		$en   = jlife_challenges_help_blocks_html( $data['privacy']['en']['blocks'] );
		$this->assertStringContainsString( 'Bluehost retains emergency backups for one week. J-Life separately keeps its ten most recent deployment backups, which may remain longer. Account deletion does not immediately remove information from existing backup copies.', $en );
		$this->assertStringContainsString( 'there is no separate parent or guardian approval step in the app', $en );
		$this->assertStringContainsString( 'J-Life is for ages 13 and up. Participants aged 13 to 17 normally join a youth group with their youth leaders.', $en );
		$vi = jlife_challenges_help_blocks_html( $data['privacy']['vi']['blocks'] );
		$this->assertStringContainsString( 'Bluehost giữ các bản sao lưu khẩn cấp trong một tuần.', $vi );
		$this->assertStringContainsString( 'mười bản sao lưu triển khai gần nhất', $vi );
		$this->assertStringContainsString( 'không có thêm bước cha mẹ hoặc người giám hộ phê duyệt riêng', $vi );
	}

	/**
	 * The Rules page states what the code does: a day's point lasts to the end of
	 * the NEXT day in Pacific time (grace_days 1, D1), and later is on time no more.
	 */
	public function test_the_rules_grace_window_matches_the_engine() {
		$campaign = jlife_challenges_get_campaign( $this->campaign );
		$this->assertSame( 1, (int) $campaign['grace_days'] );
		$this->assertSame( 'America/Los_Angeles', $campaign['timezone'] );
		$pacific = new DateTimeZone( 'America/Los_Angeles' );
		$this->assertTrue( jlife_challenges_is_on_time( $campaign, 5, new DateTimeImmutable( '2026-12-25 23:59:00', $pacific ) ), 'Day 5 (Dec 24) at 11:59 PM on Dec 25' );
		$this->assertFalse( jlife_challenges_is_on_time( $campaign, 5, new DateTimeImmutable( '2026-12-26 00:00:00', $pacific ) ), 'Day 5 after the grace window' );
		$this->assertSame( 104, jlife_challenges_day_count( $campaign ) );

		$data  = jlife_challenges_help_pages_data();
		$rules = jlife_challenges_help_blocks_html( $data['rules']['en']['blocks'] );
		$this->assertStringContainsString( '11:59 PM Pacific time on the next day', $rules );
		$this->assertStringContainsString( 'Day 5 can earn its point until the end of Day 6', $rules );
		$this->assertStringContainsString( 'equally among everyone tied for the highest score', $rules );
		$this->assertStringContainsString( 'The app takes no payments', $rules );
	}

	/**
	 * Make a post as the WordPress installer leaves it: published, never edited.
	 * Any real default already in the test database is removed first.
	 *
	 * @param string $type Post type.
	 * @param string $slug Post slug.
	 * @param bool   $edited Whether a person has edited it since.
	 * @return int Post ID.
	 */
	private function make_default( $type, $slug, $edited = false ) {
		foreach ( get_posts(
			array(
				'post_type'   => $type,
				'name'        => $slug,
				'post_status' => 'any',
			)
		) as $old ) {
			wp_delete_post( $old->ID, true );
		}
		$id = self::factory()->post->create(
			array(
				'post_type'   => $type,
				'post_name'   => $slug,
				'post_title'  => 'default',
				'post_status' => 'publish',
				'post_date'   => '2026-11-01 10:00:00',
			)
		);
		global $wpdb;
		$wpdb->update(
			$wpdb->posts,
			array(
				'post_modified'     => $edited ? '2026-11-20 09:00:00' : '2026-11-01 10:00:00',
				'post_modified_gmt' => $edited ? get_gmt_from_date( '2026-11-20 09:00:00' ) : get_gmt_from_date( '2026-11-01 10:00:00' ),
			),
			array( 'ID' => $id )
		);
		clean_post_cache( $id );
		return $id;
	}

	/**
	 * The untouched defaults go to the Trash (not deleted) on the first seed.
	 */
	public function test_untouched_wordpress_defaults_are_trashed_by_the_seed() {
		$page = $this->make_default( 'page', 'sample-page' );
		$post = $this->make_default( 'post', 'hello-world' );

		$result = jlife_challenges_seed_help_pages();
		$this->assertEqualsCanonicalizing( array( $page, $post ), $result['trashed'] );
		$this->assertSame( 'trash', get_post_status( $page ) );
		$this->assertSame( 'trash', get_post_status( $post ) );
	}

	/**
	 * A Sample Page the owner edited is left alone.
	 */
	public function test_an_edited_sample_page_is_untouched() {
		$page = $this->make_default( 'page', 'sample-page', true );
		$this->assertSame( array(), jlife_challenges_trash_wordpress_defaults() );
		$this->assertSame( 'publish', get_post_status( $page ) );
	}

	/**
	 * Pages with other slugs, and a post with the page slug, are untouched.
	 */
	public function test_other_pages_are_untouched() {
		$this->make_default( 'page', 'sample-page' );
		$mine  = self::factory()->post->create(
			array(
				'post_type'   => 'page',
				'post_name'   => 'my-own-page',
				'post_status' => 'publish',
			)
		);
		$wrong = self::factory()->post->create(
			array(
				'post_type'   => 'post',
				'post_name'   => 'sample-page-news',
				'post_status' => 'publish',
			)
		);
		jlife_challenges_trash_wordpress_defaults();
		$this->assertSame( 'publish', get_post_status( $mine ) );
		$this->assertSame( 'publish', get_post_status( $wrong ) );
	}

	/**
	 * A second run does nothing and does not error.
	 */
	public function test_running_the_cleanup_twice_is_harmless() {
		$page  = $this->make_default( 'page', 'sample-page' );
		$mine  = self::factory()->post->create(
			array(
				'post_type'   => 'page',
				'post_name'   => 'rules-of-mine',
				'post_status' => 'publish',
			)
		);
		$first = jlife_challenges_trash_wordpress_defaults();
		$this->assertContains( $page, $first );
		$this->assertSame( array(), jlife_challenges_trash_wordpress_defaults() );
		$this->assertSame( 'trash', get_post_status( $page ) );
		$this->assertSame( 'publish', get_post_status( $mine ) );
	}
}

// The Polylang stand-ins are loaded with this file, before any test runs.
require_once __DIR__ . '/polylang-stub.php';
