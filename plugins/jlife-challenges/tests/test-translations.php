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
	 * Every translatable string in the plugin's code (single and plural forms)
	 * has a Vietnamese draft, so a participant who chose Vietnamese never meets
	 * English on a screen (#119). A new English-only string fails here.
	 */
	public function test_every_participant_string_has_a_vietnamese_translation() {
		$catalog = include dirname( __DIR__ ) . '/languages/jlife-challenges-vi.l10n.php';
		$this->assertSame( 'jlife-challenges', $catalog['domain'] );
		$quoted  = '(\'(?:[^\'\\\\]|\\\\.)*\'|"(?:[^"\\\\]|\\\\.)*")';
		$unquote = static function ( $q ) {
			$body = substr( $q, 1, -1 );
			return "'" === $q[0] ? str_replace( array( "\\'", '\\\\' ), array( "'", '\\' ), $body ) : stripcslashes( $body );
		};
		$found   = array();
		foreach ( glob( dirname( __DIR__ ) . '/includes/*.php' ) as $file ) {
			$src = (string) file_get_contents( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- reading the plugin's own source.
			preg_match_all( '/\b(?:esc_html__|esc_attr__|esc_html_e|esc_attr_e|__|_e)\(\s*' . $quoted . '/s', $src, $m );
			foreach ( $m[1] as $q ) {
				$found[] = $unquote( $q );
			}
			preg_match_all( '/\b_n\(\s*' . $quoted . '\s*,\s*' . $quoted . '/s', $src, $m, PREG_SET_ORDER );
			foreach ( $m as $pair ) {
				$found[] = $unquote( $pair[1] ) . "\0" . $unquote( $pair[2] );
			}
			preg_match_all( '/\b_x\(\s*' . $quoted . '\s*,\s*' . $quoted . '/s', $src, $m, PREG_SET_ORDER );
			foreach ( $m as $pair ) {
				$found[] = $unquote( $pair[2] ) . "\4" . $unquote( $pair[1] );
			}
		}
		$this->assertGreaterThan( 150, count( array_unique( $found ) ), 'the scan finds the plugin strings' );
		$missing = array_values( array_diff( array_unique( $found ), array_keys( $catalog['messages'] ) ) );
		$this->assertSame( array(), $missing, 'untranslated: ' . implode( ' | ', $missing ) );
	}

	/**
	 * The navigation and a reading day's date read in Vietnamese (#119): the date
	 * in Vietnamese word order, 18 tháng 11, 2026, not "Tháng 11 18, 2026".
	 */
	public function test_navigation_and_dates_read_in_vietnamese() {
		$page = jlife_challenges_page_html( 'X', '', 'today' );
		foreach ( array( 'Hôm nay', 'Nhóm của tôi', 'Marathon của tôi', 'Đăng xuất' ) as $vi ) {
			$this->assertStringContainsString( $vi, $page );
		}
		$this->assertStringNotContainsString( 'My Marathon', $page );
		$this->assertSame( '18 tháng 11, 2026', jlife_challenges_format_date( '2026-11-18' ) );
		$this->assertSame( 'Ngày 14 · 18 tháng 11, 2026', sprintf( __( 'Day %1$d · %2$s', 'jlife-challenges' ), 14, jlife_challenges_format_date( '2026-11-18' ) ) ); // phpcs:ignore WordPress.WP.I18n.MissingTranslatorsComment -- test.
		$this->assertSame( 'Chưa bắt đầu.', __( 'Not started yet.', 'jlife-challenges' ) );
		$this->assertSame( 'Đọc các đoạn Kinh Thánh hôm nay', __( "Read today's passages", 'jlife-challenges' ) );
	}

	/**
	 * English keeps the site's own date format.
	 */
	public function test_english_dates_keep_the_site_format() {
		remove_filter( 'determine_locale', $this->force, 99 );
		unload_textdomain( 'jlife-challenges', true );
		update_option( 'date_format', 'F j, Y' );
		$this->assertSame( 'November 18, 2026', jlife_challenges_format_date( '2026-11-18' ) );
		update_option( 'date_format', 'Y-m-d' );
		$this->assertSame( '2026-11-18', jlife_challenges_format_date( '2026-11-18' ) );
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

	/**
	 * The organizer's Groups screen (#64) reads in Vietnamese, with no English left in its labels.
	 */
	public function test_the_groups_admin_screen_reads_in_vietnamese() {
		$view = jlife_challenges_groups_admin_view( $this->u['organizer'], $this->campaign, (int) $this->g['g1'] );
		$html = jlife_challenges_groups_admin_html( $view, 'member_moved', null );
		foreach ( array(
			'Các nhóm nhỏ',
			'Lập một nhóm',
			'Thu hồi mã mời',
			'Tạo mã mời mới',
			'Thêm vào nhóm này',
			'Chuyển sang nhóm khác',
			'Đã chuyển thành viên.',
			'>30 ngày<',
		) as $vi ) {
			$this->assertStringContainsString( $vi, $html, $vi );
		}
		foreach ( array( 'Small groups', 'Make a group', 'Revoke the invite code', 'Add a member', 'Members' ) as $en ) {
			$this->assertStringNotContainsString( $en, $html, $en );
		}
		$shown = jlife_challenges_groups_admin_html(
			$view,
			'created',
			array(
				'name'    => 'Nhóm Thử',
				'code'    => 'ABCDEFGH',
				'expires' => '2026-12-31 00:00:00',
			)
		);
		$this->assertStringContainsString( 'Mã mời của', $shown );
		$this->assertStringContainsString( 'Đường dẫn mời:', $shown );
	}

	/**
	 * The organizer's Daily view (#64 part 2) reads in Vietnamese, with no English left in its labels.
	 */
	public function test_the_daily_view_reads_in_vietnamese() {
		$html = jlife_challenges_daily_admin_html( jlife_challenges_daily_view( $this->u['organizer'], $this->campaign, 'd001' ) );
		foreach ( array(
			'Xem theo ngày',
			'Đọc một mình',
			'SOAP đã nộp',
			'Bài đã chia sẻ',
			'Chưa viết',
			'Tải ngày này (CSV)',
			'Tải tất cả các ngày (CSV)',
			'Chỉ có số đếm',
		) as $vi ) {
			$this->assertStringContainsString( $vi, $html, $vi );
		}
		foreach ( array( 'Daily view', 'Done on time', 'Download', 'Shared posts', 'Participants' ) as $en ) {
			$this->assertStringNotContainsString( $en, $html, $en );
		}
	}

	/**
	 * The My Bible picker on My Marathon (#140) reads in Vietnamese, including the
	 * "(opens Bible Gateway)" label.
	 */
	public function test_my_bible_picker_reads_in_vietnamese() {
		if ( ! function_exists( 'jlife_scripture_versions_for_user' ) ) {
			$this->markTestSkipped( 'jlife-scripture is not loaded.' );
		}
		$html = jlife_challenges_render_reminder_settings( $this->u['a'], $this->campaign );
		foreach ( array(
			'Bản Kinh Thánh của tôi',
			'Hiển thị thêm…',
			'Mặc định theo ngôn ngữ của tôi',
			'>Không</option>',
			'ERV — Easy-to-Read Version (mở trên Bible Gateway)',
			'Bản Kinh Thánh của bạn theo ngôn ngữ ở trên cho đến khi bạn tự chọn.',
		) as $vi ) {
			$this->assertStringContainsString( $vi, $html );
		}
		$this->assertStringNotContainsString( 'My Bible', $html );
		$this->assertStringNotContainsString( 'opens Bible Gateway', $html );
	}
}
