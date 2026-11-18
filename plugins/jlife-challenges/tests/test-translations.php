<?php
/**
 * Vietnamese drafts for the group screens (#112): the new and changed strings
 * have a translation, and the group, share and start pages render in it.
 *
 * @package jlife-challenges
 */

/**
 * Translation tests.
 */
class Test_Translations extends Jlife_Challenges_TestCase {

	/**
	 * Locale filter callback.
	 *
	 * @var callable|null
	 */
	private $force = null;

	/**
	 * Render in Vietnamese: the suite loads the plugin from the repository, outside
	 * WP_PLUGIN_DIR, so point WordPress's loader at its languages folder.
	 */
	public function set_up() {
		parent::set_up();
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
	 * Back to English.
	 */
	public function tear_down() {
		remove_filter( 'determine_locale', $this->force, 99 );
		unload_textdomain( 'jlife-challenges', true );
		parent::tear_down();
	}

	/**
	 * The catalog is a marked draft and covers the strings the group features added.
	 */
	public function test_the_catalog_is_a_marked_draft_with_the_group_strings() {
		$file    = dirname( __DIR__ ) . '/languages/jlife-challenges-vi.l10n.php';
		$catalog = include $file;
		$head    = substr( (string) file_get_contents( $file ), 0, 2500 ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- reading the plugin's own source.
		$this->assertStringContainsString( 'DRAFT', $head );
		$this->assertStringContainsString( 'translator', $head );
		foreach ( array(
			'Start a group',
			'Groups you left',
			'Leave this group',
			'Make a new invite code',
			'Have a code? Join a group',
			'Only adults (18 or older) can start a group. You can join a group with an invite code, or read on your own.',
			'Please give your group a name of up to %d characters.',
		) as $key ) {
			$this->assertArrayHasKey( $key, $catalog['messages'], $key );
		}
		foreach ( $catalog['messages'] as $english => $vi ) {
			$english = explode( "\0", $english )[0]; // A plural entry: the singular form.
			$this->assertNotSame( '', trim( $vi ) );
			$this->assertSame( substr_count( $english, '%s' ), substr_count( $vi, '%s' ), "placeholders kept: {$english}" );
			$this->assertSame( substr_count( $english, '%d' ), substr_count( $vi, '%d' ), "placeholders kept: {$english}" );
		}
	}

	/**
	 * The start form, the controls, the confirmation and the old-shares list read in Vietnamese.
	 */
	public function test_group_screens_render_in_vietnamese() {
		$u = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		jlife_challenges_enroll_solo( $u, $this->campaign, 'adult' );
		$solo = jlife_challenges_group_body( jlife_challenges_group_view( $u ) );
		$this->assertStringContainsString( 'Lập nhóm', $solo );
		$this->assertStringContainsString( 'Tên nhóm', $solo );
		$this->assertStringContainsString( 'Bạn có mã? Hãy tham gia một nhóm', $solo );
		$this->assertStringNotContainsString( 'Start a group', $solo );

		$g    = jlife_challenges_start_group( $u, 'Nhóm Thứ Ba' );
		$view = jlife_challenges_group_view( $u, (int) $g['id'] );
		$body = jlife_challenges_group_body( $view );
		$this->assertStringContainsString( 'Rời nhóm này', $body );
		$this->assertStringContainsString( 'Tạo mã mời mới', $body );
		$this->assertStringContainsString( 'Rời khỏi Nhóm Thứ Ba?', jlife_challenges_group_confirm( $view, 'leave' ) );
		$this->assertStringContainsString( 'Mã mời', jlife_challenges_invite_reveal_html( 'Nhóm Thứ Ba', 'ABCDEFGH' ) );
	}

	/**
	 * Refusals and plurals come out in Vietnamese.
	 */
	public function test_refusals_are_in_vietnamese() {
		$m = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		jlife_challenges_enroll_solo( $m, $this->campaign, 'minor' );
		$this->assertStringContainsString( 'Chỉ người từ 18 tuổi trở lên', jlife_challenges_start_group( $m, 'X' )->get_error_message() );
		$this->assertStringContainsString( 'tối đa 60 ký tự', jlife_challenges_clean_group_name( '' )->get_error_message() );
		$this->assertStringContainsString( '(3)', jlife_challenges_group_limit_error()->get_error_message() );
		$this->assertStringContainsString( 'số nhóm tối đa', jlife_challenges_group_limit_error()->get_error_message() );
	}
}
