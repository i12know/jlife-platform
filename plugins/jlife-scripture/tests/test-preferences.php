<?php
/**
 * A participant's Bible choice (#140): resolution order (explicit choice, then
 * My language default, then site default), a sticky choice, an optional second
 * Bible, registry validation, the grouped list for the picker, and the reader
 * showing the second Bible under the first. Written before the feature.
 *
 * @package jlife-scripture
 */

/**
 * Preference tests.
 */
class Test_Preferences extends Jlife_Scripture_TestCase {

	/**
	 * Enable the NIV (a link-only version in the shipped registry) for a while.
	 *
	 * @return callable The filter, so the test can remove it again.
	 */
	private function enable_niv() {
		$filter = static function ( $doc ) {
			foreach ( $doc['versions'] as $i => $v ) {
				if ( 'niv' === $v['key'] ) {
					$doc['versions'][ $i ]['enabled'] = true;
				}
			}
			return $doc;
		};
		add_filter( 'jlife_scripture_registry', $filter );
		return $filter;
	}

	/**
	 * The keys of a resolved pair, for short assertions.
	 *
	 * @param int $user_id Participant.
	 * @return array{0: string|null, 1: string|null}
	 */
	private function keys( $user_id ) {
		$v = jlife_scripture_versions_for_user( $user_id );
		return array( $v[0]['key'] ?? null, $v[1]['key'] ?? null );
	}

	/**
	 * Until a person picks, the Bible is the registry's default for their
	 * language; no version key is hard-coded in the resolver.
	 */
	public function test_default_follows_the_language_from_the_registry() {
		$this->assertSame( array( 'kt1925', null ), $this->keys( $this->participant( 'vi' ) ) );
		$this->assertSame( array( 'nirv', null ), $this->keys( $this->participant( 'en_US' ) ) );
		$this->assertSame( array( 'nirv', null ), $this->keys( $this->participant( '' ) ), 'no account language: English' );

		// Switching a default is a data edit (default_for), not a code change.
		$this->patch_version( 'nirv', array( 'default_for' => array() ) );
		$this->patch_version( 'niv', array( 'default_for' => array( 'en' ) ) );
		$this->assertSame( array( 'niv', null ), $this->keys( $this->participant( 'en_US' ) ) );
		$this->assertSame( array( 'kt1925', null ), $this->keys( $this->participant( 'vi' ) ), 'the other language is untouched' );
	}

	/**
	 * The language a person reads in can come from another plugin (the reminder
	 * email language on My Marathon) through a filter; the default follows it.
	 */
	public function test_language_default_uses_the_my_language_filter() {
		$user = $this->participant( 'en_US' );
		$this->assertSame( array( 'nirv', null ), $this->keys( $user ) );
		$to_vi = static fn() => 'vi';
		add_filter( 'jlife_scripture_user_language', $to_vi );
		$this->assertSame( array( 'kt1925', null ), $this->keys( $user ), 'My language: Vietnamese' );
		remove_filter( 'jlife_scripture_user_language', $to_vi );
		$this->assertSame( array( 'nirv', null ), $this->keys( $user ) );
	}

	/**
	 * A visitor who is not signed in gets the page's language default; an
	 * explicit language argument wins.
	 */
	public function test_visitor_gets_the_page_language_default() {
		$vi = static fn() => 'vi';
		add_filter( 'determine_locale', $vi );
		$this->assertSame( array( 'kt1925', null ), $this->keys( 0 ) );
		remove_filter( 'determine_locale', $vi );
		$this->assertSame( array( 'nirv', null ), $this->keys( 0 ) );
		$this->assertSame( 'kt1925', jlife_scripture_versions_for_user( 0, 'vi' )[0]['key'] );
	}

	/**
	 * Last resort: a language with no default falls back to the site's default
	 * (the default for the site's own language, then English).
	 */
	public function test_site_default_is_the_last_resort() {
		$this->patch_version( 'kt1925', array( 'default_for' => array() ) );
		$user = $this->participant( 'vi' );
		$this->assertSame( array( 'nirv', null ), $this->keys( $user ), 'no Vietnamese default: the site default (English)' );
		$vi_site = static fn() => 'vi';
		add_filter( 'locale', $vi_site );
		$this->assertSame( array( 'nirv', null ), $this->keys( $user ), 'the site language has no default either: English' );
		remove_filter( 'locale', $vi_site );
	}

	/**
	 * Once a person picks, it stays put even if their language changes; a
	 * second pick replaces it and clearing goes back to following the language.
	 */
	public function test_an_explicit_choice_is_sticky() {
		$user = $this->participant( 'en_US' );
		$this->assertTrue( jlife_scripture_set_user_choice( $user, array( 'primary' => 'kt1925' ) ) );
		$this->assertSame( array( 'kt1925', null ), $this->keys( $user ) );
		$to_vi = static fn() => 'vi';
		add_filter( 'jlife_scripture_user_language', $to_vi );
		$this->assertSame( array( 'kt1925', null ), $this->keys( $user ), 'language changes do not move an explicit choice' );
		$this->assertTrue( jlife_scripture_set_user_choice( $user, array( 'primary' => 'erv' ) ) );
		$this->assertSame( array( 'erv', null ), $this->keys( $user ), 'even a Vietnamese reader can read an English version' );
		$this->assertTrue( jlife_scripture_set_user_choice( $user, array( 'primary' => '' ) ) );
		$this->assertSame( array( 'kt1925', null ), $this->keys( $user ), 'cleared: follows My language again (now vi)' );
		remove_filter( 'jlife_scripture_user_language', $to_vi );
		$this->assertSame( array( 'nirv', null ), $this->keys( $user ) );
		$this->assertSame( '', get_user_meta( $user, 'jlife_bible_version', true ), 'null = no meta value' );
	}

	/**
	 * A choice the registry later disables falls back to the language default,
	 * quietly (no error, no log); the stored value is kept for if it returns.
	 */
	public function test_a_disabled_choice_falls_back_quietly() {
		$user   = $this->participant( 'vi' );
		$enable = $this->enable_niv();
		$this->assertTrue( jlife_scripture_set_user_choice( $user, array( 'primary' => 'niv' ) ) );
		$this->assertSame( array( 'niv', null ), $this->keys( $user ) );
		remove_filter( 'jlife_scripture_registry', $enable );
		$this->assertSame( array( 'kt1925', null ), $this->keys( $user ), 'disabled: the language default' );
		$this->assertSame( 'niv', jlife_scripture_user_choice( $user )['primary'], 'the stored choice is not deleted' );
		$this->assertSame( '', $this->logged(), 'nothing alarming' );
		$this->assertStringContainsString( 'Ngôi Lời', jlife_scripture_render_day( $this->day( 'John 1:1-5' ), $user ), 'the reader shows the language default' );
		add_filter( 'jlife_scripture_registry', $enable );
		$this->assertSame( array( 'niv', null ), $this->keys( $user ), 'enabled again: their choice is back' );

		// Same for a second Bible.
		$this->assertTrue( jlife_scripture_set_user_choice( $user, array( 'secondary' => 'niv' ) ) );
		remove_filter( 'jlife_scripture_registry', $enable );
		$this->assertSame( array( 'kt1925', null ), $this->keys( $user ) );
	}

	/**
	 * Only keys the registry lists as enabled can be saved; nothing changes on a refusal.
	 */
	public function test_unknown_and_disabled_keys_are_refused() {
		$user = $this->participant( 'en_US' );
		jlife_scripture_set_user_choice( $user, array( 'primary' => 'kt1925' ) );
		foreach ( array( 'bogus', 'NIV ', '../kt1925', 'nirv', 'niv', 'kthd', str_repeat( 'a', 500 ) ) as $key ) {
			foreach ( array( 'primary', 'secondary' ) as $slot ) {
				$r = jlife_scripture_set_user_choice( $user, array( $slot => $key ) );
				$this->assertWPError( $r, $slot . ' ' . $key );
				$this->assertSame( 'jlife_bible_unknown', $r->get_error_code() );
				$this->assertStringNotContainsString( 'bogus', $r->get_error_message(), 'the error does not echo the value' );
			}
		}
		$this->assertSame(
			array(
				'primary'   => 'kt1925',
				'secondary' => '',
			),
			jlife_scripture_user_choice( $user ),
			'nothing changed'
		);
		$this->assertWPError( jlife_scripture_set_user_choice( 0, array( 'primary' => 'kt1925' ) ), 'nobody' );
		$this->assertWPError( jlife_scripture_set_user_choice( 999999, array( 'primary' => 'kt1925' ) ), 'no such user' );
		$this->assertWPError( jlife_scripture_set_user_choice( $user, array( 'primary' => array( 'kt1925' ) ) ), 'not a string' );
		$this->assertTrue( jlife_scripture_set_user_choice( $user, array() ), 'no change is fine' );
	}

	/**
	 * A second Bible is optional, shown after the first, clearable, and never
	 * the same as the first.
	 */
	public function test_second_bible_is_optional_and_clearable() {
		$user = $this->participant( 'vi' );
		$this->assertSame( array( 'kt1925', null ), $this->keys( $user ), 'none by default' );
		$this->assertTrue( jlife_scripture_set_user_choice( $user, array( 'secondary' => 'erv' ) ) );
		$this->assertSame( array( 'kt1925', 'erv' ), $this->keys( $user ) );
		$this->assertSame( 'erv', get_user_meta( $user, 'jlife_bible_version_2', true ) );

		$this->assertTrue( jlife_scripture_set_user_choice( $user, array( 'secondary' => '' ) ) );
		$this->assertSame( array( 'kt1925', null ), $this->keys( $user ), 'cleared' );
		$this->assertSame( '', get_user_meta( $user, 'jlife_bible_version_2', true ) );

		// Not the same as the first: saving it clears instead of showing twice.
		$this->assertTrue( jlife_scripture_set_user_choice( $user, array( 'secondary' => 'kt1925' ) ) );
		$this->assertSame( array( 'kt1925', null ), $this->keys( $user ) );
		$this->assertSame( '', get_user_meta( $user, 'jlife_bible_version_2', true ) );

		// Picking a first Bible that equals the second drops the second.
		jlife_scripture_set_user_choice( $user, array( 'secondary' => 'bpt' ) );
		jlife_scripture_set_user_choice( $user, array( 'primary' => 'bpt' ) );
		$this->assertSame( array( 'bpt', null ), $this->keys( $user ) );

		// Both at once; leaving one out keeps it.
		jlife_scripture_set_user_choice(
			$user,
			array(
				'primary'   => 'kt1925',
				'secondary' => 'erv',
			)
		);
		jlife_scripture_set_user_choice( $user, array( 'primary' => 'bpt' ) );
		$this->assertSame( array( 'bpt', 'erv' ), $this->keys( $user ), 'the second is kept' );

		// The default also counts as "the first": a follower of the language cannot pick it as the second.
		$follower = $this->participant( 'vi' );
		jlife_scripture_set_user_choice( $follower, array( 'secondary' => 'kt1925' ) );
		$this->assertSame( array( 'kt1925', null ), $this->keys( $follower ) );
		$this->assertSame( '', get_user_meta( $follower, 'jlife_bible_version_2', true ) );
	}

	/**
	 * Choices belong to one account each.
	 */
	public function test_choices_are_per_person_and_go_with_the_account() {
		$a = $this->participant( 'en_US' );
		$b = $this->participant( 'en_US' );
		jlife_scripture_set_user_choice( $a, array( 'primary' => 'kt1925' ) );
		$this->assertSame( array( 'kt1925', null ), $this->keys( $a ) );
		$this->assertSame( array( 'nirv', null ), $this->keys( $b ) );
		$this->assertSame( '', (string) get_user_meta( $b, 'jlife_bible_version', true ) );
		require_once ABSPATH . 'wp-admin/includes/user.php';
		wp_delete_user( $a );
		$this->assertSame( '', (string) get_user_meta( $a, 'jlife_bible_version', true ), 'deleted with the account' );
	}

	/**
	 * The picker's list: every ENABLED version, grouped by language in the
	 * registry's order, with whether the page can show its text.
	 */
	public function test_selectable_versions_are_enabled_and_grouped_by_language() {
		$groups = jlife_scripture_selectable_versions();
		$this->assertSame( array( 'en', 'vi' ), array_keys( $groups ), 'registry language order' );
		$this->assertSame( array( 'nlt', 'nkjv', 'csb', 'erv' ), array_keys( $groups['en'] ), 'only enabled versions: NLT, NKJV, CSB and ERV; NIrV and NIV are not there yet' );
		foreach ( array( 'nlt', 'nkjv', 'csb' ) as $key ) {
			$this->assertTrue( jlife_scripture_can_render( $groups['en'][ $key ] ), "{$key}: text on the page" );
		}
		$this->assertSame( array( 'kt1925', 'bpt' ), array_keys( $groups['vi'] ) );
		$this->assertTrue( jlife_scripture_can_render( $groups['vi']['kt1925'] ) );
		$this->assertFalse( jlife_scripture_can_render( $groups['en']['erv'] ), 'ERV opens Bible Gateway' );
		$this->assertFalse( jlife_scripture_can_render( $groups['vi']['bpt'] ) );

		// Enabling versions is a data edit; each appears in its language.
		$this->enable_niv();
		$this->patch_version(
			'nirv',
			array(
				'enabled'          => true,
				'display'          => 'native',
				'license_evidence' => 'Test only.',
			)
		);
		$groups = jlife_scripture_selectable_versions();
		$this->assertSame( array( 'nirv', 'niv', 'nlt', 'nkjv', 'csb', 'erv' ), array_keys( $groups['en'] ), 'registry order inside a language' );
		$this->assertTrue( jlife_scripture_can_render( $groups['en']['nirv'] ) );
		$this->assertFalse( jlife_scripture_can_render( $groups['en']['niv'] ), 'enabled but link-only' );

		$this->assertSame( 'kt1925', jlife_scripture_selectable_version( 'kt1925' )['key'] );
		$this->assertNull( jlife_scripture_selectable_version( 'kthd' ), 'disabled' );
		$this->assertSame( 'nlt', jlife_scripture_selectable_version( 'nlt' )['key'], 'enabled' );
		$this->assertNull( jlife_scripture_selectable_version( 'nope' ) );
		$this->assertNull( jlife_scripture_selectable_version( '' ) );
	}

	/**
	 * Resolving and saving a choice never logs: no user, no key, no text.
	 */
	public function test_no_personal_data_in_logs() {
		$user = $this->participant( 'vi' );
		jlife_scripture_set_user_choice(
			$user,
			array(
				'primary'   => 'erv',
				'secondary' => 'bpt',
			)
		);
		jlife_scripture_set_user_choice( $user, array( 'primary' => 'bogus' ) );
		jlife_scripture_versions_for_user( $user );
		jlife_scripture_render_day( $this->day( 'John 1:1-5' ), $user );
		$this->assertSame( '', $this->logged() );
		$src = '';
		foreach ( glob( dirname( __DIR__ ) . '/includes/*.php' ) as $file ) {
			if ( 'preferences.php' === basename( $file ) ) {
				$src = (string) file_get_contents( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- reading the plugin's own source.
			}
		}
		$this->assertNotSame( '', $src );
		$this->assertStringNotContainsString( 'error_log(', $src );
	}

	/**
	 * The reader shows the person's own Bible: an explicit link-only choice is
	 * a Bible Gateway link, not the language default, and an explicit language
	 * argument (the public /reading/ pages) still wins over any saved choice.
	 */
	public function test_reader_uses_the_chosen_version() {
		$user = $this->participant( 'vi' );
		$html = jlife_scripture_render_day( $this->day( 'John 1:1-5' ), $user );
		$this->assertStringContainsString( 'Ngôi Lời', $html, 'default: Kinh Thánh 1925' );

		jlife_scripture_set_user_choice( $user, array( 'primary' => 'erv' ) );
		$html = jlife_scripture_render_day( $this->day( 'John 1:1-5' ), $user );
		$this->assertStringContainsString( '<section class="jls-reader" lang="en"', $html, 'the chosen version\'s own language' );
		$this->assertStringContainsString( 'Open in Bible Gateway (ERV)', $html );
		$this->assertStringNotContainsString( 'jls-text', $html );

		$html = jlife_scripture_render_day( $this->day( 'John 1:1-5' ), $user, array( 'language' => 'vi' ) );
		$this->assertStringContainsString( 'Ngôi Lời', $html, 'the page language argument wins (public pages never read a saved choice)' );

		// A Vietnamese Bible chosen by an English reader: Vietnamese text and labels.
		$en = $this->participant( 'en_US' );
		jlife_scripture_set_user_choice( $en, array( 'primary' => 'kt1925' ) );
		$html = jlife_scripture_render_day( $this->day( 'John 1:1-5', array( 'Giăng 1:1-5' ) ), $en );
		$this->assertStringContainsString( '<section class="jls-reader" lang="vi"', $html );
		$this->assertStringContainsString( '<summary>Giăng 1:1-5</summary>', $html );
		$this->assertStringContainsString( 'Ngôi Lời', $html );
	}

	/**
	 * The second Bible renders under the first, for every passage, and
	 * disappears when cleared; the first passage stays the only one open.
	 */
	public function test_second_bible_renders_under_the_first() {
		$user = $this->participant( 'vi' );
		$day  = $this->day( 'John 1:1-5; John 1:6-8', array( 'Giăng 1:1-5', 'Giăng 1:6-8' ) );
		$one  = jlife_scripture_render_day( $day, $user );
		$this->assertSame( 2, substr_count( $one, 'class="jls-text"' ) );
		$this->assertStringNotContainsString( 'jls-edition', $one, 'one Bible: the markup is unchanged' );

		jlife_scripture_set_user_choice( $user, array( 'secondary' => 'erv' ) );
		$two = jlife_scripture_render_day( $day, $user );
		$this->assertSame( 2, substr_count( $two, '<details class="jls-passage"' ) );
		$this->assertSame( 1, substr_count( $two, '<details class="jls-passage" open>' ) );
		$this->assertSame( 2, substr_count( $two, 'class="jls-text"' ), 'the 1925 text for both passages' );
		$this->assertSame( 2, substr_count( $two, 'Open in Bible Gateway (ERV)' ), 'the second Bible for both passages' );
		$this->assertSame( 4, substr_count( $two, 'class="jls-edition"' ), 'two editions per passage' );
		$this->assertLessThan( strpos( $two, 'Open in Bible Gateway (ERV)' ), strpos( $two, 'Ngôi Lời' ), 'under the first' );
		$this->assertStringContainsString( '<div class="jls-edition" lang="en">', $two );
		$this->assertStringContainsString( '<section class="jls-reader" lang="vi"', $two, 'the first Bible sets the reader' );

		jlife_scripture_set_user_choice( $user, array( 'secondary' => '' ) );
		$this->assertSame( $one, jlife_scripture_render_day( $day, $user ), 'cleared: exactly the one-Bible page' );

		// Two text Bibles.
		$this->use_fake_nirv();
		jlife_scripture_set_user_choice( $user, array( 'secondary' => 'nirv' ) );
		$both = jlife_scripture_render_day( $this->day( 'John 1:1-3' ), $user );
		$this->assertStringContainsString( 'Ngôi Lời', $both );
		$this->assertStringContainsString( Jlife_Scripture_Fake_Source::TEXT, $both );
		$this->assertLessThan( strpos( $both, Jlife_Scripture_Fake_Source::TEXT ), strpos( $both, 'Ngôi Lời' ) );
	}

	/**
	 * Two Bibles that both fall back to the same link show it once.
	 */
	public function test_identical_links_are_not_repeated() {
		$user = $this->participant( 'en_US' ); // NIrV is not renderable yet: the ERV link.
		jlife_scripture_set_user_choice( $user, array( 'secondary' => 'erv' ) );
		$html = jlife_scripture_render_day( $this->day( 'John 1:1-5' ), $user );
		$this->assertSame( 1, substr_count( $html, 'Open in Bible Gateway (ERV)' ) );
	}
}
