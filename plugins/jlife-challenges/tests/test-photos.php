<?php
/**
 * Photo of a handwritten reflection (#244): the matrix in
 * docs/challenges-engine-design.md §4j and the rules around it. The access-control
 * tests come first, as the matrix requires: unauthenticated, another participant, a
 * group member before and after sharing, after the author removes the share, after
 * leaving the group, a guessed id, a wrong day or campaign, and a campaign without
 * photos.
 *
 * @package jlife-challenges
 */

/**
 * Photo reflection tests.
 */
class Test_Photo_Reflections extends Jlife_Challenges_TestCase {

	/**
	 * The private base folder for this test (the filter points the plugin at it).
	 *
	 * @var string
	 */
	private $base = '';

	/**
	 * A private folder per test, and photos switched on for the base campaign.
	 */
	public function set_up() {
		parent::set_up();
		$this->base = trailingslashit( get_temp_dir() ) . 'jlife-photos-' . wp_generate_password( 10, false );
		add_filter( 'jlife_challenges_photo_dir', array( $this, 'photo_base' ) );
		$this->photos_on( true );
		wp_set_current_user( $this->u['a'] );
	}

	/**
	 * Remove the private folder and any simulated request.
	 */
	public function tear_down() {
		remove_filter( 'jlife_challenges_photo_dir', array( $this, 'photo_base' ) );
		$this->rrmdir( $this->base );
		foreach ( array_keys( $_POST ) as $key ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing -- test helper clearing a simulated POST.
			unset( $_POST[ $key ], $_REQUEST[ $key ] );
		}
		unset( $_FILES['jl_photo'] );
		wp_set_current_user( 0 );
		parent::tear_down();
	}

	/**
	 * Filter callback: where the photos go in tests.
	 *
	 * @return string
	 */
	public function photo_base() {
		return $this->base;
	}

	/**
	 * Delete a folder and everything in it.
	 *
	 * @param string $dir Folder.
	 */
	private function rrmdir( $dir ) {
		if ( '' === $dir || ! is_dir( $dir ) ) {
			return;
		}
		foreach ( (array) scandir( $dir ) as $name ) {
			if ( '.' === $name || '..' === $name ) {
				continue;
			}
			$path = $dir . '/' . $name;
			is_dir( $path ) ? $this->rrmdir( $path ) : unlink( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- test cleanup of its own temporary folder.
		}
		rmdir( $dir ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- test cleanup of its own temporary folder.
	}

	/**
	 * Turn photos on or off for the base campaign.
	 *
	 * @param bool $on On or off.
	 */
	private function photos_on( $on ) {
		global $wpdb;
		$wpdb->update( jlife_challenges_table( 'campaigns' ), array( 'accepts_photos' => $on ? 1 : 0 ), array( 'id' => $this->campaign ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- test fixture.
	}

	/**
	 * JPEG bytes of a plain picture.
	 *
	 * @param int    $w     Width.
	 * @param int    $h     Height.
	 * @param string $extra Raw segments to put right after the start of image (EXIF and the like).
	 * @return string
	 */
	private function jpeg( $w = 800, $h = 600, $extra = '' ) {
		$im = imagecreatetruecolor( $w, $h );
		imagefilledrectangle( $im, 0, 0, $w, $h, imagecolorallocate( $im, 200, 100, 50 ) );
		imagefilledrectangle( $im, 0, 0, (int) ( $w / 2 ), (int) ( $h / 4 ), imagecolorallocate( $im, 10, 20, 200 ) );
		ob_start();
		imagejpeg( $im, null, 90 );
		$data = (string) ob_get_clean();
		imagedestroy( $im );
		return '' === $extra ? $data : substr( $data, 0, 2 ) . $extra . substr( $data, 2 );
	}

	/**
	 * PNG bytes of a plain picture.
	 *
	 * @return string
	 */
	private function png() {
		$im = imagecreatetruecolor( 300, 200 );
		imagefilledrectangle( $im, 0, 0, 300, 200, imagecolorallocate( $im, 20, 120, 60 ) );
		ob_start();
		imagepng( $im );
		$data = (string) ob_get_clean();
		imagedestroy( $im );
		return $data;
	}

	/**
	 * An APP1 segment of the kind a phone writes: here with a made-up GPS note.
	 *
	 * @param int $orientation EXIF orientation (1 = none).
	 * @return string
	 */
	private function exif( $orientation = 1 ) {
		$tiff    = "MM\x00\x2A\x00\x00\x00\x08\x00\x01\x01\x12\x00\x03\x00\x00\x00\x01" . pack( 'n', $orientation ) . "\x00\x00\x00\x00\x00\x00";
		$payload = "Exif\x00\x00" . $tiff . 'GPSLatitude-SECRET-PLACE';
		return "\xFF\xE1" . pack( 'n', strlen( $payload ) + 2 ) . $payload;
	}

	/**
	 * A PHP upload array for some bytes.
	 *
	 * @param string $bytes File content.
	 * @param int    $error PHP upload error code.
	 * @return array
	 */
	private function upload( $bytes, $error = UPLOAD_ERR_OK ) {
		$path = trailingslashit( get_temp_dir() ) . 'jlife-up-' . wp_generate_password( 8, false );
		file_put_contents( $path, $bytes ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- test fixture.
		return array(
			'name'     => 'reflection.jpg',
			'type'     => 'image/jpeg',
			'tmp_name' => $path,
			'error'    => $error,
			'size'     => strlen( $bytes ),
		);
	}

	/**
	 * Attach a picture as a fixture user (today, base campaign).
	 *
	 * @param string      $who   Fixture user.
	 * @param string|null $bytes JPEG bytes (null = a plain 800x600 one).
	 * @param string|null $day   Day key.
	 * @return array|WP_Error
	 */
	private function attach( $who, $bytes = null, $day = null ) {
		return jlife_challenges_photo_attach( $this->u[ $who ], $this->campaign, $day, $this->upload( null === $bytes ? $this->jpeg() : $bytes ) );
	}

	/**
	 * The private photo row of a fixture user for a day.
	 *
	 * @param string $who Fixture user.
	 * @param string $day Day key.
	 * @return array|null
	 */
	private function photo_of( $who, $day = 'd001' ) {
		return jlife_challenges_day_photo( $this->u[ $who ], $this->campaign, $day );
	}

	/**
	 * Submit text for a fixture user, optionally ticking their group.
	 *
	 * @param string $who   Fixture user.
	 * @param string $text  Entry text.
	 * @param bool   $share Tick the user's own group.
	 * @return array|WP_Error
	 */
	private function submit( $who, $text, $share = false ) {
		$group = 'c' === $who ? $this->g['g2'] : $this->g['g1'];
		return jlife_challenges_save_and_share( $this->u[ $who ], $this->campaign, null, $text, $share ? array( $group ) : array() );
	}

	/**
	 * The stored files on disk (the random names only, not the guard files).
	 *
	 * @return string[]
	 */
	private function stored_files() {
		$dir = jlife_challenges_photo_dir();
		$out = array();
		foreach ( is_dir( $dir ) ? (array) scandir( $dir ) : array() as $name ) {
			if ( 1 === preg_match( '/^[a-f0-9]{32}$/', $name ) ) {
				$out[] = $name;
			}
		}
		return $out;
	}

	/**
	 * The ID of the share copy of a photo that a fixture user shared in their group.
	 *
	 * @param string $author Author fixture user.
	 * @param int    $group  Group ID.
	 * @return int 0 if there is none.
	 */
	private function share_photo_id( $author, $group ) {
		global $wpdb;
		$entry = jlife_challenges_find_entry( $this->u[ $author ], $this->campaign, 'd001' );
		$share = $entry ? jlife_challenges_current_share( $this->u[ $author ], (int) $entry['id'], $group ) : null;
		return $share ? (int) $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM ' . jlife_challenges_table( 'photos' ) . " WHERE kind = 'share' AND share_id = %d", (int) $share['id'] ) ) : 0; // phpcs:ignore WordPress.DB
	}

	/**
	 * The ledger row of a fixture user for a day.
	 *
	 * @param string $who Fixture user.
	 * @param string $day Day key.
	 * @return array|null
	 */
	private function ledger( $who, $day = 'd001' ) {
		global $wpdb;
		return $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . jlife_challenges_table( 'score_ledger' ) . ' WHERE campaign_id = %d AND user_id = %d AND day_key = %s', $this->campaign, $this->u[ $who ], $day ), ARRAY_A ); // phpcs:ignore WordPress.DB
	}

	/**
	 * Whether a viewer (a user ID) can be sent the file for a photo.
	 *
	 * @param int    $viewer   Viewer (0 = signed out).
	 * @param int    $photo_id Photo.
	 * @param string $size     '' or 'thumb'.
	 * @return bool
	 */
	private function can_open( $viewer, $photo_id, $size = '' ) {
		return null !== jlife_challenges_photo_file_for( $viewer, $photo_id, $size );
	}

	// ------------------------------------------------------------------
	// Access control (matrix §4j).
	// ------------------------------------------------------------------

	/**
	 * The author can open their own draft photo, full size and thumbnail; nobody else can.
	 */
	public function test_only_the_author_opens_a_private_photo() {
		$this->assertIsArray( $this->attach( 'a' ) );
		$id = (int) $this->photo_of( 'a' )['id'];
		$this->assertTrue( $this->can_open( $this->u['a'], $id ) );
		$this->assertTrue( $this->can_open( $this->u['a'], $id, 'thumb' ) );
		foreach ( array( 'b', 'c', 'outsider', 'organizer' ) as $who ) {
			$this->assertFalse( $this->can_open( $this->u[ $who ], $id ), "{$who} cannot open it" );
			$this->assertFalse( $this->can_open( $this->u[ $who ], $id, 'thumb' ), "{$who} cannot open the thumbnail" );
		}
		$this->assertFalse( $this->can_open( 0, $id ), 'signed out' );
	}

	/**
	 * A group mate of the author cannot open the private photo before anything is shared, and
	 * never the author's draft photo itself, even after the text is shared.
	 */
	public function test_a_group_member_cannot_open_an_unshared_photo() {
		$this->attach( 'a' );
		$id = (int) $this->photo_of( 'a' )['id'];
		$this->assertFalse( $this->can_open( $this->u['b'], $id ), 'same group, nothing shared' );
		$this->assertSame( 0, $this->share_photo_id( 'a', $this->g['g1'] ) );
		$this->submit( 'a', 'only words, no point yet', true );
		$this->assertFalse( $this->can_open( $this->u['b'], $id ), 'the private photo stays private after a share' );
	}

	/**
	 * A tick on Submit makes a frozen copy that current members of that group (and only
	 * they) can open; other groups, the public and organizers cannot.
	 */
	public function test_a_share_copy_opens_only_for_current_members_of_that_group() {
		$this->attach( 'a' );
		$result = $this->submit( 'a', 'short', true );
		$this->assertSame( 'shared', $result['share']['status'] );
		$copy = $this->share_photo_id( 'a', $this->g['g1'] );
		$this->assertGreaterThan( 0, $copy );
		$private = (int) $this->photo_of( 'a' )['id'];
		$this->assertNotSame( $private, $copy, 'a separate copy, not the private photo' );
		foreach ( array( 'a', 'b' ) as $who ) {
			$this->assertTrue( $this->can_open( $this->u[ $who ], $copy ), "{$who} can open it" );
			$this->assertTrue( $this->can_open( $this->u[ $who ], $copy, 'thumb' ), "{$who} can open the thumbnail" );
		}
		foreach ( array( 'c', 'outsider', 'organizer' ) as $who ) {
			$this->assertFalse( $this->can_open( $this->u[ $who ], $copy ), "{$who} cannot" );
		}
		$this->assertFalse( $this->can_open( 0, $copy ), 'signed out' );
		$this->assertSame( $copy, jlife_challenges_group_feed( $this->u['b'], $this->g['g1'] )[0]['photo_id'], 'the feed carries the copy' );
	}

	/**
	 * Nothing is shared unless a group is ticked: no copy, no share.
	 */
	public function test_an_unticked_group_gets_no_copy() {
		$this->attach( 'a' );
		$this->submit( 'a', 'short', false );
		$this->assertSame( 0, $this->share_photo_id( 'a', $this->g['g1'] ) );
		$this->assertCount( 2, $this->stored_files(), 'only the private photo and its thumbnail' );
	}

	/**
	 * After the author removes the share, the copy is gone from the database and the disk and
	 * nobody (the author's group included) can open it. The private photo stays.
	 */
	public function test_removing_the_share_deletes_the_copy() {
		$this->attach( 'a' );
		$this->submit( 'a', 'short', true );
		$copy  = $this->share_photo_id( 'a', $this->g['g1'] );
		$entry = jlife_challenges_find_entry( $this->u['a'], $this->campaign, 'd001' );
		$share = jlife_challenges_current_share( $this->u['a'], (int) $entry['id'], $this->g['g1'] );
		$this->assertCount( 4, $this->stored_files() );
		$this->assertTrue( jlife_challenges_unshare( $this->u['a'], (int) $share['id'] ) );
		$this->assertFalse( $this->can_open( $this->u['b'], $copy ) );
		$this->assertNull( jlife_challenges_find_photo( $copy ) );
		$this->assertCount( 2, $this->stored_files(), 'the copy and its thumbnail are deleted from disk' );
		$this->assertNotNull( $this->photo_of( 'a' ), 'the private photo is untouched' );
		$this->assertSame( array(), jlife_challenges_group_feed( $this->u['b'], $this->g['g1'] ) );
	}

	/**
	 * A member who leaves the group (or is removed) can no longer open the copy.
	 */
	public function test_leaving_the_group_ends_access_to_the_copy() {
		$this->attach( 'a' );
		$this->submit( 'a', 'short', true );
		$copy = $this->share_photo_id( 'a', $this->g['g1'] );
		$this->assertTrue( $this->can_open( $this->u['b'], $copy ) );
		jlife_challenges_leave_group( $this->u['b'], $this->g['g1'] );
		$this->assertFalse( $this->can_open( $this->u['b'], $copy ), 'a former member' );
		$this->assertTrue( $this->can_open( $this->u['a'], $copy ), 'the author still can' );
	}

	/**
	 * A scheduled (#209), hidden (D17) or removed share opens for no member; an organizer hide
	 * works; the author alone keeps seeing their own copy.
	 */
	public function test_scheduled_hidden_and_removed_shares_do_not_open() {
		global $wpdb;
		$this->attach( 'a' );
		$this->submit( 'a', 'short', true );
		$entry = jlife_challenges_find_entry( $this->u['a'], $this->campaign, 'd001' );
		$share = jlife_challenges_current_share( $this->u['a'], (int) $entry['id'], $this->g['g1'] );
		$table = jlife_challenges_table( 'group_shares' );
		$copy  = $this->share_photo_id( 'a', $this->g['g1'] );

		$wpdb->update( $table, array( 'status' => 'removed' ), array( 'id' => (int) $share['id'] ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- test fixture.
		$this->assertFalse( $this->can_open( $this->u['b'], $copy ), 'a removed share' );

		$this->submit( 'a', 'short', true ); // Shared again: a fresh copy.
		$copy = $this->share_photo_id( 'a', $this->g['g1'] );
		$this->assertTrue( $this->can_open( $this->u['b'], $copy ) );

		// A share that is only scheduled (#209) shows nothing; this author is not the group's starter, so the release check withdraws it and its copy is deleted.
		$wpdb->update( $table, array( 'status' => 'scheduled' ), array( 'id' => (int) $share['id'] ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- test fixture.
		$this->assertFalse( $this->can_open( $this->u['b'], $copy ), 'a scheduled share' );
		$this->assertSame( 0, $this->share_photo_id( 'a', $this->g['g1'] ) );

		$this->submit( 'a', 'short', true );
		$copy = $this->share_photo_id( 'a', $this->g['g1'] );
		$this->assertTrue( $this->can_open( $this->u['b'], $copy ) );
		$this->assertTrue( jlife_challenges_hide_share( $this->u['organizer'], (int) $share['id'], 'reported' ) );
		$this->assertFalse( $this->can_open( $this->u['b'], $copy ), 'hidden by an organizer' );
		$this->assertTrue( $this->can_open( $this->u['a'], $copy ), 'the author still opens their own copy' );
		$this->assertSame( array(), jlife_challenges_group_feed( $this->u['b'], $this->g['g1'] ) );
		$result = $this->submit( 'a', 'short again', true );
		$this->assertSame( 'failed', $result['share']['status'], 'a hidden post is not re-posted' );
		$this->assertFalse( $this->can_open( $this->u['b'], $copy ) );
	}

	/**
	 * Guessed ids, ids that were never photos and the same id with a different size all answer
	 * the same: nothing.
	 */
	public function test_a_guessed_id_opens_nothing() {
		$this->attach( 'a' );
		$id = (int) $this->photo_of( 'a' )['id'];
		foreach ( array( $id + 1, $id + 100, 0, -5, 999999 ) as $guess ) {
			foreach ( array( 'a', 'b', 'outsider' ) as $who ) {
				$this->assertFalse( $this->can_open( $this->u[ $who ], $guess ), "{$who} guessing {$guess}" );
			}
		}
		$this->assertNull( jlife_challenges_photo_file_for( $this->u['b'], $id, 'thumb' ) );
	}

	/**
	 * A wrong day, a day that has not opened and another campaign are refused like a text save.
	 */
	public function test_a_wrong_day_or_campaign_is_refused() {
		foreach ( array( 'd999', 'd005', 'x', '' ) as $day ) {
			$r = $this->attach( 'a', null, $day );
			$this->assertWPError( $r, "day {$day}" );
		}
		$this->assertSame( array(), $this->stored_files() );
		$other = jlife_challenges_create_campaign(
			$this->u['organizer'],
			array(
				'campaign_key'   => 'other-' . wp_generate_password( 5, false ),
				'plan_id'        => 'rpl-life-and-teachings-of-jesus-2026',
				'title'          => 'Other',
				'start_date'     => '2026-12-20',
				'end_date'       => '2027-04-02',
				'status'         => 'active',
				'accepts_photos' => 1,
			)
		);
		$r     = jlife_challenges_photo_attach( $this->u['a'], $other, null, $this->upload( $this->jpeg() ) );
		$this->assertSame( 'jlife_denied', $r->get_error_code(), 'not enrolled there' );
		$r = jlife_challenges_photo_attach( $this->u['outsider'], $this->campaign, null, $this->upload( $this->jpeg() ) );
		$this->assertSame( 'jlife_denied', $r->get_error_code(), 'not enrolled at all' );
		$r = jlife_challenges_photo_attach( $this->u['a'], 0, null, $this->upload( $this->jpeg() ) );
		$this->assertSame( 'jlife_denied', $r->get_error_code(), 'personal entries take no photos' );
		$this->assertSame( array(), $this->stored_files() );
	}

	/**
	 * A campaign without photos: no form control, an upload is refused, and a photo never scores.
	 */
	public function test_a_campaign_without_photos_takes_none() {
		$this->photos_on( false );
		$r = $this->attach( 'a' );
		$this->assertSame( 'jlife_photos_off', $r->get_error_code() );
		$this->assertSame( array(), $this->stored_files() );
		$body = jlife_challenges_render_soap_panel( jlife_challenges_day_view( $this->u['a'] ) );
		$this->assertStringNotContainsString( 'jl_photo', $body );
		$this->assertStringNotContainsString( 'multipart/form-data', $body );
		$this->assertStringNotContainsString( 'handwritten', $body );
		// A photo stored while it was on does not count once the setting is off.
		$this->photos_on( true );
		$this->attach( 'a' );
		$this->photos_on( false );
		$result = $this->submit( 'a', 'short' );
		$this->assertSame( 0, $result['points'] );
		$this->assertNull( $this->ledger( 'a' ) );
	}

	/**
	 * The REST routes need a signed-in user.
	 */
	public function test_the_upload_routes_need_sign_in() {
		wp_set_current_user( 0 );
		foreach ( array( '/jlife/v1/soap/photo', '/jlife/v1/soap/photo/remove' ) as $route ) {
			$request = new WP_REST_Request( 'POST', $route );
			$request->set_param( 'campaign_id', $this->campaign );
			$request->set_param( 'day_key', 'd001' );
			$this->assertSame( 401, rest_do_request( $request )->get_status(), $route );
		}
		$this->assertSame( array(), $this->stored_files() );
	}

	// ------------------------------------------------------------------
	// Storage.
	// ------------------------------------------------------------------

	/**
	 * Files have random names with no extension, in a folder that denies direct requests, outside
	 * the Media Library; the default folder is not under uploads.
	 */
	public function test_files_are_private_and_randomly_named() {
		$this->attach( 'a' );
		$files = $this->stored_files();
		$this->assertCount( 2, $files );
		$dir = jlife_challenges_photo_dir();
		$this->assertStringContainsString( 'Require all denied', (string) file_get_contents( $dir . '/.htaccess' ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- test reads a local file.
		$this->assertStringContainsString( 'Deny from all', (string) file_get_contents( dirname( $dir ) . '/.htaccess' ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- test reads a local file.
		$this->assertFileExists( $dir . '/index.php' );
		$attachments = get_posts(
			array(
				'post_type'   => 'attachment',
				'post_status' => 'any',
				'numberposts' => -1,
			)
		);
		$this->assertCount( 0, $attachments, 'nothing in the Media Library' );
		$row = $this->photo_of( 'a' );
		$this->assertContains( $row['file'], $files );
		$this->assertContains( $row['thumb'], $files );
		$this->assertNotSame( $row['file'], $row['thumb'] );
		remove_filter( 'jlife_challenges_photo_dir', array( $this, 'photo_base' ) );
		$default = jlife_challenges_photo_dir();
		$this->assertStringStartsWith( wp_normalize_path( WP_CONTENT_DIR ) . '/jlife-private/site-', $default );
		$this->assertStringNotContainsString( '/uploads', $default );
		add_filter( 'jlife_challenges_photo_dir', array( $this, 'photo_base' ) );
	}

	/**
	 * The path helper only answers for well-formed random names, so a name from a row can never
	 * climb out of the folder.
	 */
	public function test_the_path_helper_refuses_odd_names() {
		foreach ( array( '../../wp-config.php', 'abc', str_repeat( 'g', 32 ), '' ) as $name ) {
			$this->assertSame( '', jlife_challenges_photo_path( $name ) );
		}
		$this->assertStringEndsWith( '/' . str_repeat( 'a', 32 ), jlife_challenges_photo_path( str_repeat( 'a', 32 ) ) );
	}

	// ------------------------------------------------------------------
	// Upload checks and processing.
	// ------------------------------------------------------------------

	/**
	 * What is not a real JPEG or PNG is refused whatever it is called: an executable renamed
	 * .jpg, a PDF, a script, a file with a JPEG start and nothing behind it, an empty file.
	 */
	public function test_bad_formats_are_refused_by_content() {
		$bad = array(
			'executable' => "MZ\x90\x00\x03\x00\x00\x00" . str_repeat( "\x00", 600 ),
			'pdf'        => "%PDF-1.4\n1 0 obj\n<< /Type /Catalog >>\nendobj\n%%EOF",
			'script'     => "<?php echo 'x';",
			'fake jpeg'  => "\xFF\xD8\xFF\xE0" . str_repeat( 'junk', 100 ),
			'gif'        => "GIF89a\x01\x00\x01\x00\x80\x00\x00\x00\x00\x00\xFF\xFF\xFF!\xF9\x04\x01\x00\x00\x00\x00,\x00\x00\x00\x00\x01\x00\x01\x00\x00\x02\x02D\x01\x00;",
			'empty'      => '',
		);
		foreach ( $bad as $what => $bytes ) {
			$r = $this->attach( 'a', $bytes );
			$this->assertWPError( $r, $what );
			$this->assertContains( $r->get_error_code(), array( 'jlife_photo_type', 'jlife_photo_failed' ), $what );
		}
		$this->assertSame( array(), $this->stored_files(), 'nothing is stored' );
		$this->assertNull( $this->photo_of( 'a' ) );
	}

	/**
	 * Too large, or a PHP upload error: refused, nothing stored, no point.
	 */
	public function test_oversize_and_failed_uploads_are_refused() {
		$r = $this->attach( 'a', $this->jpeg() . str_repeat( "\x00", JLIFE_CHALLENGES_PHOTO_MAX_BYTES ) );
		$this->assertSame( 'jlife_photo_big', $r->get_error_code() );
		$tests = array(
			UPLOAD_ERR_INI_SIZE   => 'jlife_photo_big',
			UPLOAD_ERR_PARTIAL    => 'jlife_photo_failed',
			UPLOAD_ERR_NO_FILE    => 'jlife_photo_none',
			UPLOAD_ERR_NO_TMP_DIR => 'jlife_photo_failed',
		);
		foreach ( $tests as $error => $code ) {
			$r = jlife_challenges_photo_attach( $this->u['a'], $this->campaign, null, $this->upload( $this->jpeg(), $error ) );
			$this->assertSame( $code, $r->get_error_code(), "upload error {$error}" );
		}
		$this->assertSame( array(), $this->stored_files() );
		$result = $this->submit( 'a', 'short' );
		$this->assertSame( 0, $result['points'], 'a failed or incomplete upload never earns a point' );
		$this->assertNull( $this->ledger( 'a' ) );
	}

	/**
	 * A PNG is accepted and stored as a JPEG.
	 */
	public function test_a_png_is_stored_as_a_jpeg() {
		$this->assertIsArray( $this->attach( 'a', $this->png() ) );
		$row  = $this->photo_of( 'a' );
		$data = (string) file_get_contents( jlife_challenges_photo_path( $row['file'] ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- test reads a local file.
		$this->assertSame( "\xFF\xD8", substr( $data, 0, 2 ) );
		$this->assertSame( IMAGETYPE_JPEG, getimagesizefromstring( $data )[2] );
	}

	/**
	 * A big picture is shrunk to a longest side of 1600 px (never enlarged), with a small
	 * thumbnail beside it.
	 */
	public function test_pictures_are_downscaled() {
		$this->attach( 'a', $this->jpeg( 3200, 2400 ) );
		$row   = $this->photo_of( 'a' );
		$full  = getimagesize( jlife_challenges_photo_path( $row['file'] ) );
		$thumb = getimagesize( jlife_challenges_photo_path( $row['thumb'] ) );
		$this->assertSame( 1600, max( $full[0], $full[1] ) );
		$this->assertLessThanOrEqual( 320, max( $thumb[0], $thumb[1] ) );
		$this->attach( 'b', $this->jpeg( 400, 300 ) );
		$small = getimagesize( jlife_challenges_photo_path( $this->photo_of( 'b' )['file'] ) );
		$this->assertSame( array( 400, 300 ), array( $small[0], $small[1] ), 'a small picture is not enlarged' );
	}

	/**
	 * EXIF, GPS and every other metadata segment are gone from what is stored, whatever the image
	 * library did.
	 */
	public function test_exif_and_location_are_stripped() {
		$this->attach( 'a', $this->jpeg( 800, 600, $this->exif() . "\xFF\xFE\x00\x0Ahidden!!" ) );
		$row = $this->photo_of( 'a' );
		foreach ( array( 'file', 'thumb' ) as $key ) {
			$data = (string) file_get_contents( jlife_challenges_photo_path( $row[ $key ] ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- test reads a local file.
			$this->assertStringNotContainsString( 'GPSLatitude', $data, $key );
			$this->assertStringNotContainsString( 'Exif', $data, $key );
			$this->assertStringNotContainsString( 'hidden!!', $data, $key );
			$this->assertSame( "\xFF\xD8", substr( $data, 0, 2 ) );
		}
	}

	/**
	 * The segment stripper keeps the picture data and drops APPn and comment segments.
	 */
	public function test_the_marker_stripper_keeps_pixels_and_drops_metadata() {
		$clean = $this->jpeg( 64, 48 );
		$dirty = substr( $clean, 0, 2 ) . $this->exif() . "\xFF\xFE\x00\x06abcd\xFF\xEE\x00\x04zz" . substr( $clean, 2 );
		$out   = jlife_challenges_photo_strip_markers( $dirty );
		$this->assertNotNull( $out );
		$this->assertStringNotContainsString( 'GPSLatitude', $out );
		$this->assertStringNotContainsString( 'abcd', $out );
		$this->assertSame( IMAGETYPE_JPEG, getimagesizefromstring( $out )[2] );
		$this->assertSame( array( 64, 48 ), array_slice( getimagesizefromstring( $out ), 0, 2 ) );
		$this->assertNull( jlife_challenges_photo_strip_markers( 'not a jpeg' ) );
		$this->assertNull( jlife_challenges_photo_strip_markers( "\xFF\xD8\xFF\xE1\xFF" ), 'a segment that runs past the end' );
	}

	/**
	 * The orientation is applied before the metadata goes: a picture the phone saved sideways is
	 * stored upright.
	 */
	public function test_orientation_is_applied() {
		if ( ! function_exists( 'exif_read_data' ) ) {
			$this->markTestSkipped( 'The PHP exif extension is not loaded.' );
		}
		$this->attach( 'a', $this->jpeg( 800, 600, $this->exif( 6 ) ) );
		$size = getimagesize( jlife_challenges_photo_path( $this->photo_of( 'a' )['file'] ) );
		$this->assertSame( array( 600, 800 ), array( $size[0], $size[1] ), 'turned from landscape to portrait' );
	}

	// ------------------------------------------------------------------
	// Scoring.
	// ------------------------------------------------------------------

	/**
	 * Choosing a file saves it with the draft and earns nothing; the entry stays a draft.
	 */
	public function test_choosing_a_file_never_earns_a_point() {
		$this->assertIsArray( $this->attach( 'a' ) );
		$this->assertNull( $this->ledger( 'a' ) );
		$entry = jlife_challenges_find_entry( $this->u['a'], $this->campaign, 'd001' );
		$this->assertSame( 'draft', $entry['status'] );
		$this->assertSame( 0, jlife_challenges_progress( $this->u['a'], $this->u['a'], $this->campaign )['points'] );
	}

	/**
	 * Typed text that passes is a text point, with or without a photo on the day.
	 */
	public function test_passing_text_is_a_text_point_even_with_a_photo() {
		$this->attach( 'a' );
		$result = $this->submit( 'a', $this->soap( 'a' ) );
		$this->assertSame( 1, $result['points'] );
		$this->assertSame( 'text', $result['submission_type'] );
		$this->assertSame( 'text', $this->ledger( 'a' )['submission_type'] );
		$this->assertSame( 'soap-regex', $this->ledger( 'a' )['engine'] );
	}

	/**
	 * Text that does not pass plus a stored photo is a photo point, recorded on the ledger row.
	 */
	public function test_a_photo_is_a_point_when_the_text_does_not_pass() {
		$this->attach( 'a' );
		$result = $this->submit( 'a', 'I wrote this on paper.' );
		$this->assertSame( 1, $result['points'] );
		$this->assertSame( 'photo', $result['submission_type'] );
		$this->assertSame( 'earned', $result['status'] );
		$row = $this->ledger( 'a' );
		$this->assertSame( 'photo', $row['submission_type'] );
		$this->assertSame( JLIFE_CHALLENGES_PHOTO_ENGINE, $row['engine'] );
		$this->assertSame( 'submitted', jlife_challenges_find_entry( $this->u['a'], $this->campaign, 'd001' )['status'] );
		$this->assertSame( 'photo', jlife_challenges_progress( $this->u['a'], $this->u['a'], $this->campaign )['types']['d001'] );
	}

	/**
	 * With neither passing text nor a photo, no point, and nothing is recorded.
	 */
	public function test_no_text_and_no_photo_is_no_point() {
		$result = $this->submit( 'a', 'short' );
		$this->assertSame( 0, $result['points'] );
		$this->assertFalse( $result['passes'] );
		$this->assertNull( $this->ledger( 'a' ) );
	}

	/**
	 * A reflection-v1 campaign: the same order (own words first, then the photo).
	 */
	public function test_the_order_holds_under_the_reflection_engine() {
		$this->use_engine( 'reflection-v1' );
		$this->attach( 'a' );
		$words  = 'The Word became flesh and lived among us and I want to slow down today and notice the people God puts near me.';
		$result = $this->submit( 'a', $words );
		$this->assertSame( 'text', $result['submission_type'] );
		$this->attach( 'b' );
		$result = $this->submit( 'b', 'too short' );
		$this->assertSame( 'photo', $result['submission_type'] );
	}

	/**
	 * Repeated saves and submits never add a second point, a second ledger row or a second photo,
	 * and a retry of the same upload replaces rather than duplicates.
	 */
	public function test_everything_is_idempotent() {
		$this->attach( 'a' );
		$this->attach( 'a' );
		$this->attach( 'a' );
		$this->assertCount( 2, $this->stored_files(), 'one photo (and its thumbnail) per day' );
		global $wpdb;
		$this->assertSame( 1, (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . jlife_challenges_table( 'photos' ) . ' WHERE user_id = ' . (int) $this->u['a'] ) ); // phpcs:ignore WordPress.DB
		$this->submit( 'a', 'x' );
		$this->submit( 'a', 'x' );
		$this->submit( 'a', $this->soap( 'later' ) );
		$this->assertSame( 1, $this->ledger_rows( $this->u['a'] ) );
		$this->assertSame( 1, jlife_challenges_progress( $this->u['a'], $this->u['a'], $this->campaign )['points'] );
		$this->assertSame( 'photo', $this->ledger( 'a' )['submission_type'], 'provenance is written once and kept' );
	}

	/**
	 * Replacing after submission keeps the original award, adds nothing, deletes the old file, and
	 * is audited with IDs only.
	 */
	public function test_replacing_after_submission_keeps_provenance() {
		global $wpdb;
		$this->attach( 'a' );
		$this->submit( 'a', 'x' );
		$before = $this->photo_of( 'a' );
		$r      = $this->attach( 'a', $this->jpeg( 500, 500 ) );
		$this->assertTrue( $r['replaced'] );
		$after = $this->photo_of( 'a' );
		$this->assertSame( (int) $before['id'], (int) $after['id'], 'one row, replaced in place' );
		$this->assertNotSame( $before['file'], $after['file'] );
		$this->assertCount( 2, $this->stored_files() );
		$this->assertFileDoesNotExist( jlife_challenges_photo_path( $before['file'] ) );
		$this->assertSame( 1, $this->ledger_rows( $this->u['a'] ) );
		$this->assertSame( 'photo', $this->ledger( 'a' )['submission_type'] );
		$this->assertSame( 1, jlife_challenges_progress( $this->u['a'], $this->u['a'], $this->campaign )['points'] );
		$audit = $wpdb->get_row( 'SELECT * FROM ' . jlife_challenges_table( 'audit_log' ) . " WHERE action = 'photo_replaced'", ARRAY_A ); // phpcs:ignore WordPress.DB
		$this->assertNotNull( $audit );
		$entry = jlife_challenges_find_entry( $this->u['a'], $this->campaign, 'd001' );
		$this->assertSame( wp_json_encode( array( 'entry_id' => (int) $entry['id'] ) ), $audit['detail'], 'IDs only' );
	}

	/**
	 * A text point stays a text point when a photo is added later (no new point, same source).
	 */
	public function test_adding_a_photo_after_a_text_point_changes_nothing() {
		$this->submit( 'a', $this->soap( 'a' ) );
		$this->attach( 'a' );
		$this->submit( 'a', 'x' );
		$this->assertSame( 'text', $this->ledger( 'a' )['submission_type'] );
		$this->assertSame( 1, $this->ledger_rows( $this->u['a'] ) );
	}

	/**
	 * A photo submitted after the deadline follows the ordinary late rule: no point, still recorded
	 * as a photo, and not "photo-earned".
	 */
	public function test_a_late_photo_follows_the_deadline_rule() {
		$this->set_now( '2026-12-23 18:00:00' );
		$this->attach( 'a', null, 'd001' );
		$result = jlife_challenges_submit( $this->u['a'], $this->campaign, 'd001', 'x' );
		$this->assertSame( 0, $result['points'] );
		$this->assertSame( 'late_no_point', $result['status'] );
		$this->assertSame( array(), jlife_challenges_photo_earned_days( $this->u['a'] ), 'only earned days are photo-earned' );
	}

	/**
	 * Removing the photo after it earned the point keeps the point (never taken back) and is audited
	 * with IDs only; the file is deleted.
	 */
	public function test_removing_a_photo_after_the_point_keeps_the_point() {
		$this->attach( 'a' );
		$this->submit( 'a', 'x' );
		$row = $this->photo_of( 'a' );
		$this->assertTrue( jlife_challenges_photo_remove( $this->u['a'], $this->campaign, 'd001' ) );
		$this->assertNull( $this->photo_of( 'a' ) );
		$this->assertSame( array(), $this->stored_files() );
		$this->assertFileDoesNotExist( jlife_challenges_photo_path( $row['file'] ) );
		$this->assertSame( 1, jlife_challenges_progress( $this->u['a'], $this->u['a'], $this->campaign )['points'] );
		$this->assertTrue( jlife_challenges_photo_remove( $this->u['a'], $this->campaign, 'd001' ), 'removing twice is a no-op' );
	}

	/**
	 * Only the author can remove their photo: another participant or an outsider is refused.
	 */
	public function test_nobody_else_can_remove_or_replace_a_photo() {
		$this->attach( 'a' );
		$this->assertSame( 'jlife_denied', jlife_challenges_photo_remove( $this->u['outsider'], $this->campaign, 'd001' )->get_error_code() );
		$this->assertTrue( jlife_challenges_photo_remove( $this->u['b'], $this->campaign, 'd001' ), 'b has no photo of their own to remove' );
		$this->assertNotNull( $this->photo_of( 'a' ), "a's photo is untouched" );
	}

	/**
	 * A closed campaign takes no new photo; the author can still remove theirs.
	 */
	public function test_a_closed_campaign_takes_no_photo() {
		$this->attach( 'a' );
		$this->assertIsArray( jlife_challenges_close_campaign( $this->u['organizer'], $this->campaign ) );
		$r = $this->attach( 'a', $this->jpeg( 100, 100 ) );
		$this->assertSame( 'jlife_campaign_closed', $r->get_error_code() );
		$this->assertTrue( jlife_challenges_photo_remove( $this->u['a'], $this->campaign, 'd001' ) );
	}

	// ------------------------------------------------------------------
	// Drafts and retries.
	// ------------------------------------------------------------------

	/**
	 * The photo is saved with the draft: later draft saves of the text keep it, and the REST retry
	 * path (the same upload sent twice) leaves one photo.
	 */
	public function test_the_photo_survives_draft_saves_and_a_retry() {
		wp_set_current_user( $this->u['a'] );
		for ( $i = 0; $i < 2; $i++ ) {
			$request = new WP_REST_Request( 'POST', '/jlife/v1/soap/photo' );
			$request->set_param( 'campaign_id', $this->campaign );
			$request->set_param( 'day_key', 'd001' );
			$request->set_file_params( array( 'photo' => $this->upload( $this->jpeg() ) ) );
			$response = jlife_challenges_rest_save_photo( $request );
			$this->assertInstanceOf( WP_REST_Response::class, $response );
			$this->assertTrue( $response->get_data()['saved'] );
			$this->assertStringContainsString( '/photo/', $response->get_data()['url'] );
			$this->assertStringNotContainsString( 'jlife-private', $response->get_data()['url'] );
		}
		$this->assertCount( 2, $this->stored_files() );
		jlife_challenges_save_draft( $this->u['a'], $this->campaign, 'd001', 'some words typed later' );
		$this->assertNotNull( $this->photo_of( 'a' ), 'a draft save keeps the photo' );
		$this->assertNull( $this->ledger( 'a' ) );
		$request = new WP_REST_Request( 'POST', '/jlife/v1/soap/photo' );
		$request->set_param( 'campaign_id', $this->campaign );
		$request->set_param( 'day_key', 'd001' );
		$request->set_file_params( array( 'photo' => $this->upload( 'not an image' ) ) );
		$response = jlife_challenges_rest_save_photo( $request );
		$this->assertWPError( $response );
		$this->assertSame( 400, $response->get_error_data()['status'] );
		$this->assertNotNull( $this->photo_of( 'a' ), 'a refused retry keeps the earlier photo' );
		$remove = new WP_REST_Request( 'POST', '/jlife/v1/soap/photo/remove' );
		$remove->set_param( 'campaign_id', $this->campaign );
		$remove->set_param( 'day_key', 'd001' );
		$this->assertTrue( jlife_challenges_rest_remove_photo( $remove )->get_data()['removed'] );
		$this->assertSame( array(), $this->stored_files() );
	}

	// ------------------------------------------------------------------
	// Sharing.
	// ------------------------------------------------------------------

	/**
	 * The share copy is a frozen snapshot: replacing or removing the private photo later leaves it
	 * alone until the author ticks the group again, and the share does not depend on the text.
	 */
	public function test_the_shared_copy_is_frozen_until_shared_again() {
		$this->attach( 'a', $this->jpeg( 700, 500 ) );
		$this->submit( 'a', '', true );
		$copy_id  = $this->share_photo_id( 'a', $this->g['g1'] );
		$copy_row = jlife_challenges_find_photo( $copy_id );
		$this->assertGreaterThan( 0, $copy_id, 'a photo alone can be shared' );
		$this->attach( 'a', $this->jpeg( 300, 300 ) );
		$this->assertSame( $copy_row['file'], jlife_challenges_find_photo( $copy_id )['file'], 'replacing the private photo does not change the copy' );
		$this->submit( 'a', '', true );
		$again = jlife_challenges_find_photo( $this->share_photo_id( 'a', $this->g['g1'] ) );
		$this->assertNotSame( $copy_row['file'], $again['file'], 'ticking again updates the copy' );
		$this->assertFileDoesNotExist( jlife_challenges_photo_path( $copy_row['file'] ), 'the old copy is deleted' );
		jlife_challenges_photo_remove( $this->u['a'], $this->campaign, 'd001' );
		$this->assertSame( $again['file'], jlife_challenges_find_photo( (int) $again['id'] )['file'], 'removing the private photo leaves the copy' );
		$this->submit( 'a', 'some words', true );
		$this->assertSame( 0, $this->share_photo_id( 'a', $this->g['g1'] ), 'ticking again with no photo removes the copy' );
	}

	/**
	 * The share copy is for the group ticked only.
	 */
	public function test_a_copy_goes_only_to_the_ticked_group() {
		$this->attach( 'c' );
		$this->submit( 'c', 'short', true );
		$copy = $this->share_photo_id( 'c', $this->g['g2'] );
		$this->assertGreaterThan( 0, $copy );
		$this->assertTrue( $this->can_open( $this->u['c'], $copy ) );
		$this->assertFalse( $this->can_open( $this->u['a'], $copy ), 'a is in Group One' );
		$this->assertFalse( $this->can_open( $this->u['b'], $copy ) );
	}

	/**
	 * The youth rule (D19) applies unchanged: where a deployment requires guardian consent, a minor
	 * without it shares nothing, and no copy is made.
	 */
	public function test_a_minor_awaiting_consent_shares_no_photo() {
		global $wpdb;
		add_filter( 'jlife_challenges_require_guardian_consent', '__return_true' );
		$wpdb->update( jlife_challenges_table( 'enrollments' ), array( 'age_band' => 'minor' ), array( 'campaign_id' => $this->campaign ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- test fixture: every participant of this campaign is a minor for this test.
		$this->attach( 'a' );
		$result = $this->submit( 'a', 'short', true );
		$this->assertSame( 'failed', $result['share']['status'] );
		$this->assertSame( 1, $result['points'], 'the save and point stand' );
		$this->assertSame( 0, $this->share_photo_id( 'a', $this->g['g1'] ) );
		$this->assertCount( 2, $this->stored_files() );
	}

	/**
	 * A scheduled share that is withdrawn (#209) deletes its photo copy.
	 */
	public function test_withdrawing_a_scheduled_share_deletes_the_copy() {
		global $wpdb;
		$this->attach( 'a' );
		$this->submit( 'a', 'short', true );
		$entry = jlife_challenges_find_entry( $this->u['a'], $this->campaign, 'd001' );
		$share = jlife_challenges_current_share( $this->u['a'], (int) $entry['id'], $this->g['g1'] );
		$wpdb->update( jlife_challenges_table( 'group_shares' ), array( 'status' => 'scheduled' ), array( 'id' => (int) $share['id'] ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- test fixture.
		$this->assertTrue( jlife_challenges_withdraw_share_row( (int) $share['id'] ) );
		$this->assertSame( 0, $this->share_photo_id( 'a', $this->g['g1'] ) );
		$this->assertCount( 2, $this->stored_files() );
	}

	/**
	 * My Group shows a small lazy thumbnail that opens full size through the access-checked route;
	 * never a file address.
	 */
	public function test_my_group_shows_a_thumbnail_that_opens_through_the_route() {
		$this->attach( 'a' );
		$this->submit( 'a', 'short', true );
		$copy = $this->share_photo_id( 'a', $this->g['g1'] );
		$view = jlife_challenges_group_view( $this->u['b'], $this->g['g1'] );
		$html = jlife_challenges_group_days_html( $view['posts'] );
		$this->assertStringContainsString( 'href="' . esc_url( home_url( '/photo/' . $copy . '/' ) ) . '"', $html );
		$this->assertStringContainsString( 'size=thumb', $html );
		$this->assertStringContainsString( 'loading="lazy"', $html );
		$this->assertStringNotContainsString( 'jlife-private', $html );
		$this->assertStringNotContainsString( $this->photo_of( 'a' )['file'], $html );
		$alone = jlife_challenges_group_post_html( array_merge( $view['posts'][0], array( 'body' => '' ) ) );
		$this->assertStringNotContainsString( 'jl-excerpt', $alone, 'a photo-only post has no empty text paragraph' );
	}

	// ------------------------------------------------------------------
	// Retention.
	// ------------------------------------------------------------------

	/**
	 * Deleting an account deletes the person's photos and share copies, files included.
	 */
	public function test_account_deletion_deletes_photos() {
		$this->attach( 'a' );
		$this->attach( 'b' );
		$this->submit( 'a', 'short', true );
		$this->assertCount( 6, $this->stored_files() );
		jlife_challenges_purge_user_data( $this->u['a'] );
		$this->assertCount( 2, $this->stored_files(), "only b's photo is left" );
		$this->assertNull( $this->photo_of( 'a' ) );
		$this->assertNotNull( $this->photo_of( 'b' ) );
	}

	/**
	 * Removing the test campaigns deletes their photos; the preview counts them.
	 */
	public function test_test_campaign_removal_deletes_photos() {
		global $wpdb;
		$this->attach( 'a' ); // A real campaign's photo: it must survive.
		$this->assertCount( 2, $this->stored_files() );
		$this->set_now( '2026-11-05 20:00:00' );
		$t = jlife_challenges_create_test_campaign( $this->u['organizer'] );
		$wpdb->update( jlife_challenges_table( 'campaigns' ), array( 'accepts_photos' => 1 ), array( 'id' => $t['campaign_id'] ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- test fixture.
		$this->assertIsInt( jlife_challenges_join_with_code( $this->u['outsider'], $t['invite_code'], 'adult' ) );
		$r = jlife_challenges_photo_attach( $this->u['outsider'], $t['campaign_id'], null, $this->upload( $this->jpeg() ) );
		$this->assertIsArray( $r );
		$this->assertCount( 4, $this->stored_files() );
		$plan = jlife_challenges_test_cleanup_preview( $this->u['organizer'] );
		$this->assertSame( 1, $plan['photos'] );
		jlife_challenges_remove_test_campaigns( $this->u['organizer'] );
		$this->assertCount( 2, $this->stored_files(), 'only the test campaign photo is gone' );
		$this->assertNotNull( $this->photo_of( 'a' ) );
	}

	/**
	 * The retention hook for a campaign purge (#186) removes every photo of one campaign and leaves
	 * other campaigns alone. With no filter it deletes nothing.
	 */
	public function test_the_campaign_hook_deletes_that_campaigns_photos_only() {
		$this->attach( 'a' );
		$this->attach( 'b' );
		$this->assertSame( 0, jlife_challenges_delete_photos( array() ), 'no filter, no deletion' );
		$this->assertCount( 4, $this->stored_files() );
		$this->assertSame( 2, jlife_challenges_delete_campaign_photos( $this->campaign ) );
		$this->assertSame( array(), $this->stored_files() );
		$this->assertSame( 0, jlife_challenges_delete_campaign_photos( 99999 ) );
	}

	/**
	 * Nothing about a photo reaches the audit log: no file name, no path, no image data.
	 */
	public function test_no_photo_detail_reaches_the_audit_log() {
		global $wpdb;
		$this->attach( 'a' );
		$names = array( $this->photo_of( 'a' )['file'], $this->photo_of( 'a' )['thumb'] );
		$this->submit( 'a', 'x', true );
		$this->attach( 'a', $this->jpeg( 90, 90 ) );
		jlife_challenges_photo_remove( $this->u['a'], $this->campaign, 'd001' );
		$rows = (array) $wpdb->get_results( 'SELECT action, object_type, detail FROM ' . jlife_challenges_table( 'audit_log' ), ARRAY_A ); // phpcs:ignore WordPress.DB
		$all  = wp_json_encode( $rows );
		foreach ( array_merge( $names, array( 'jlife-private', 'GPSLatitude', '.jpg', 'tmp_name' ) ) as $needle ) {
			$this->assertStringNotContainsString( $needle, (string) $all, $needle );
		}
		$this->assertContains( 'photo_removed', array_column( $rows, 'action' ) );
	}

	// ------------------------------------------------------------------
	// The setting.
	// ------------------------------------------------------------------

	/**
	 * Off by default, settable at creation, and changeable only before Day 1 with no entries (the
	 * same lock as set-engine); organizers only; audited.
	 */
	public function test_the_setting_is_off_by_default_and_locks_like_the_engine() {
		$make   = function ( $start, $extra = array() ) {
			return jlife_challenges_create_campaign(
				$this->u['organizer'],
				array_merge(
					array(
						'campaign_key' => 'set-' . wp_generate_password( 6, false ),
						'plan_id'      => 'rpl-life-and-teachings-of-jesus-2026',
						'title'        => 'Setting test',
						'start_date'   => $start,
						'end_date'     => gmdate( 'Y-m-d', strtotime( $start . ' +100 days' ) ),
						'status'       => 'active',
					),
					$extra
				)
			);
		};
		$future = $make( '2027-02-01' );
		$this->assertFalse( jlife_challenges_campaign_accepts_photos( $future ), 'off by default' );
		$on = $make( '2027-02-01', array( 'accepts_photos' => 1 ) );
		$this->assertTrue( jlife_challenges_campaign_accepts_photos( $on ), 'on when created on' );

		$this->assertSame( 'jlife_denied', jlife_challenges_set_campaign_photos( $this->u['a'], $future, true )->get_error_code(), 'organizers only' );
		$r = jlife_challenges_set_campaign_photos( $this->u['organizer'], $future, true );
		$this->assertTrue( $r['changed'] );
		$this->assertTrue( jlife_challenges_campaign_accepts_photos( $future ) );
		$this->assertFalse( jlife_challenges_set_campaign_photos( $this->u['organizer'], $future, true )['changed'], 'same value, nothing changed' );
		$this->assertTrue( jlife_challenges_set_campaign_photos( $this->u['organizer'], $future, false )['changed'] );
		global $wpdb;
		$this->assertSame( 2, (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . jlife_challenges_table( 'audit_log' ) . " WHERE action = 'campaign_photos_set'" ) ); // phpcs:ignore WordPress.DB

		// Day 1 has started (the base campaign runs): locked, in both directions.
		$this->photos_on( false );
		$this->assertSame( 'jlife_engine_locked', jlife_challenges_set_campaign_photos( $this->u['organizer'], $this->campaign, true )->get_error_code() );
		$this->assertFalse( jlife_challenges_campaign_accepts_photos( $this->campaign ) );

		// Entries exist before Day 1 (a starter's early draft): locked.
		$early = $make( '2027-02-01' );
		$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- test fixture.
			jlife_challenges_table( 'soap_entries' ),
			array(
				'user_id'     => $this->u['a'],
				'campaign_id' => $early,
				'day_key'     => 'd001',
				'body'        => '',
				'status'      => 'draft',
				'revision'    => 0,
				'created'     => current_time( 'mysql', true ),
				'updated'     => current_time( 'mysql', true ),
			)
		);
		$this->assertSame( 'jlife_engine_locked', jlife_challenges_set_campaign_photos( $this->u['organizer'], $early, true )->get_error_code() );

		// Closed: locked.
		$wpdb->update( jlife_challenges_table( 'campaigns' ), array( 'status' => 'closed' ), array( 'id' => $future ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- test fixture.
		$this->assertSame( 'jlife_engine_locked', jlife_challenges_set_campaign_photos( $this->u['organizer'], $future, true )->get_error_code() );
		$this->assertSame( 'jlife_denied', jlife_challenges_set_campaign_photos( $this->u['organizer'], 999999, true )->get_error_code() );
	}

	/**
	 * The official and the pilot create commands take the setting for a NEW campaign only; the
	 * pilot stays off unless asked.
	 */
	public function test_official_and_pilot_creation_take_the_setting() {
		$org      = $this->u['organizer'];
		$official = jlife_challenges_create_official_campaign(
			$org,
			array(
				'campaign_key'   => 'marathon-photo',
				'accepts_photos' => 1,
			),
			true
		);
		$this->assertTrue( jlife_challenges_campaign_accepts_photos( $official['campaign_id'] ) );
		$again = jlife_challenges_create_official_campaign(
			$org,
			array(
				'campaign_key'   => 'marathon-photo',
				'accepts_photos' => 0,
			),
			true
		);
		$this->assertFalse( $again['created'] );
		$this->assertTrue( jlife_challenges_campaign_accepts_photos( $official['campaign_id'] ), 'an existing campaign keeps its setting' );
		$plain = jlife_challenges_create_official_campaign( $org, array( 'campaign_key' => 'marathon-plain' ), true );
		$this->assertFalse( jlife_challenges_campaign_accepts_photos( $plain['campaign_id'] ) );
		$pilot = jlife_challenges_create_pilot_campaign( $org, array( 'campaign_key' => 'pilot-plain' ) );
		$this->assertFalse( jlife_challenges_campaign_accepts_photos( $pilot['campaign_id'] ), 'the pilot stays text-only' );
		$pilot_on = jlife_challenges_create_pilot_campaign(
			$org,
			array(
				'campaign_key'   => 'pilot-photo',
				'accepts_photos' => 1,
			)
		);
		$this->assertTrue( jlife_challenges_campaign_accepts_photos( $pilot_on['campaign_id'] ) );
	}

	// ------------------------------------------------------------------
	// The form, the no-JavaScript path, the grid.
	// ------------------------------------------------------------------

	/**
	 * The form: one file input (JPEG/PNG, no capture), under the textarea, before the share boxes
	 * and Submit, with a plain multipart POST (so it works without JavaScript).
	 */
	public function test_the_form_offers_one_button_for_camera_and_library() {
		$html = jlife_challenges_render_soap_panel( jlife_challenges_day_view( $this->u['a'] ) );
		$this->assertStringContainsString( '<form method="post" class="jl-soap" enctype="multipart/form-data"', $html );
		$this->assertStringContainsString( 'type="file" id="jl-photo" name="jl_photo" class="jl-photo-input" accept="image/jpeg,image/png"', $html );
		$this->assertStringNotContainsString( 'capture', $html, 'capture would hide the photo library on some phones' );
		$this->assertStringContainsString( 'Add a photo of my handwritten reflection', $html );
		$this->assertSame( 1, substr_count( $html, 'type="file"' ) );
		$text   = (int) strpos( $html, '</textarea>' );
		$photo  = (int) strpos( $html, 'jl-photo-input' );
		$share  = (int) strpos( $html, 'jl-share-groups' );
		$submit = (int) strpos( $html, 'value="submit"' );
		$this->assertTrue( $text < $photo && $photo < $share && $share < $submit, 'textarea, photo, share boxes, Submit' );
		$this->assertStringContainsString( 'name="jl_action" value="photo_remove"', $html, 'Remove works without JavaScript' );
		$this->assertStringContainsString( 'You can also add a photo of your handwritten page.', $html );
	}

	/**
	 * The help line is extended only when photos are on, for a day with a question and one without.
	 */
	public function test_the_help_line_mentions_the_photo() {
		$this->make_devotional( 'publish', $this->marked_content( array( 'What stood out to you today?' ) ) );
		$with = jlife_challenges_render_soap_panel( jlife_challenges_day_view( $this->u['a'] ) );
		$this->assertStringContainsString( 'Answer the question in the box below in your own words, or add a photo of your handwritten page.', $with );
		$this->photos_on( false );
		$without = jlife_challenges_render_soap_panel( jlife_challenges_day_view( $this->u['a'] ) );
		$this->assertStringContainsString( 'Answer the question in the box below in your own words.', $without );
		$this->assertStringNotContainsString( 'photo', $without );
	}

	/**
	 * The "your group will see this photo" reminder sits by the share boxes, hidden until a photo is
	 * attached, and absent when the campaign takes no photos.
	 */
	public function test_the_group_reminder_follows_the_photo() {
		$none = jlife_challenges_render_soap_panel( jlife_challenges_day_view( $this->u['a'] ) );
		$this->assertMatchesRegularExpression( '/jl-share-photo-note"\s+hidden>Your group will see this photo\. Make sure it doesn&#039;t show anything private about other people\./', $none );
		$this->attach( 'a' );
		$have = jlife_challenges_render_soap_panel( jlife_challenges_day_view( $this->u['a'] ) );
		$this->assertStringContainsString( 'jl-share-photo-note">Your group will see this photo.', $have );
		$this->assertStringContainsString( 'class="jl-photo-img" src="' . esc_url( jlife_challenges_photo_url( (int) $this->photo_of( 'a' )['id'], true ) ), $have, 'the preview comes through the route' );
		$this->assertStringContainsString( 'data-has="1"', $have );
		$this->photos_on( false );
		$off = jlife_challenges_render_soap_panel( jlife_challenges_day_view( $this->u['a'] ) );
		$this->assertStringNotContainsString( 'jl-share-photo-note', $off );
	}

	/**
	 * Without JavaScript: a plain POST with the file, then Submit, saves the photo, scores it as a
	 * photo point and shares the copy. A refused file stops the save with a message and keeps the
	 * typed text; Remove deletes the photo and keeps the text; a file sent to a campaign without
	 * photos is ignored.
	 */
	public function test_the_no_javascript_path() {
		wp_set_current_user( $this->u['a'] );
		$post = function ( $action, $text, $file, $groups = array() ) {
			foreach ( array_keys( $_POST ) as $key ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing -- test helper.
				unset( $_POST[ $key ], $_REQUEST[ $key ] );
			}
			unset( $_FILES['jl_photo'] );
			$data = array(
				'jl_text'     => $text,
				'jl_action'   => $action,
				'jl_day'      => 'd001',
				'_jlife_soap' => wp_create_nonce( 'jlife_soap_d001' ),
			);
			if ( $groups ) {
				$data['jl_share_groups'] = array_map( 'strval', $groups );
			}
			foreach ( $data as $key => $value ) {
				$_POST[ $key ]    = $value;
				$_REQUEST[ $key ] = $value;
			}
			if ( null !== $file ) {
				$_FILES['jl_photo'] = $file;
			}
			return jlife_challenges_process_soap_request( $this->u['a'], jlife_challenges_day_view( $this->u['a'] ), 'today' );
		};

		$state = $post( 'submit', 'short note', $this->upload( 'MZ not an image' ) );
		$this->assertNotSame( '', $state['error'], 'a refused file stops the save' );
		$this->assertSame( 'short note', $state['text'], 'the typed text is kept' );
		$this->assertSame( '', $state['redirect'] );
		$this->assertNull( $this->ledger( 'a' ) );
		$this->assertNull( jlife_challenges_find_entry( $this->u['a'], $this->campaign, 'd001' ), 'nothing was saved' );

		$state = $post( 'draft', 'short note', $this->upload( $this->jpeg() ) );
		$this->assertSame( '', $state['error'] );
		$this->assertNotSame( '', $state['redirect'] );
		$this->assertNotNull( $this->photo_of( 'a' ), 'saved with the draft' );
		$this->assertNull( $this->ledger( 'a' ), 'a draft never scores' );

		$state = $post( 'photo_remove', 'unsaved typing', null );
		$this->assertSame( 'unsaved typing', $state['text'] );
		$this->assertSame( '', $state['error'] );
		$this->assertNotSame( '', $state['notice'] );
		$this->assertNull( $this->photo_of( 'a' ) );

		$state = $post( 'submit', 'short note', $this->upload( $this->jpeg() ), array( $this->g['g1'] ) );
		$this->assertSame( '', $state['error'] );
		$this->assertStringContainsString( 'done=1', $state['redirect'] );
		$this->assertSame( 'photo', $this->ledger( 'a' )['submission_type'] );
		$this->assertGreaterThan( 0, $this->share_photo_id( 'a', $this->g['g1'] ) );

		$this->photos_on( false );
		$before = count( $this->stored_files() );
		$post( 'draft', 'short note', $this->upload( $this->jpeg( 90, 90 ) ) );
		$this->assertCount( $before, $this->stored_files(), 'a file sent to a campaign without photos is ignored' );
	}

	/**
	 * The grid: a photo-earned day stays green, gets a camera and the label "Point earned – photo
	 * upload"; a text-earned day does not; the legend explains the camera only when photos are on.
	 */
	public function test_the_grid_marks_photo_earned_days() {
		$this->attach( 'a' );
		$this->submit( 'a', 'x' );
		$cells = jlife_challenges_marathon_view( $this->u['a'] )['cells'];
		$this->assertTrue( $cells[0]['photo'] );
		$this->assertSame( 'earned', $cells[0]['state'], 'green stays green' );
		$html = jlife_challenges_render_grid( jlife_challenges_marathon_view( $this->u['a'] ) );
		$this->assertStringContainsString( '<a class="jl-cell jl-earned jl-has-photo" title="Day 1: Point earned – photo upload" aria-label="Day 1: Point earned – photo upload"', $html );
		$this->assertStringContainsString( 'class="jl-cam" viewBox="0 0 16 16" aria-hidden="true"', $html );
		$this->assertStringContainsString( '<li><span class="jl-cell jl-earned jl-has-photo">', $html, 'the legend' );
		$this->submit( 'b', $this->soap( 'b' ) );
		$text = jlife_challenges_render_grid( jlife_challenges_marathon_view( $this->u['b'] ) );
		$this->assertStringContainsString( '<a class="jl-cell jl-earned" title="Day 1: done — 1 point"', $text, 'a text day has no camera' );
		$this->photos_on( false );
		$off = jlife_challenges_render_grid( jlife_challenges_marathon_view( $this->u['b'] ) );
		$this->assertStringNotContainsString( 'jl-cam', $off, 'no legend entry when the campaign takes no photos' );
	}

	/**
	 * The styles keep the green tile readable in light and dark mode (the camera takes the tile's
	 * own text colour) and show a focus ring on the real file input for keyboard users.
	 */
	public function test_the_styles_cover_dark_mode_and_keyboard() {
		$css = (string) file_get_contents( dirname( __DIR__ ) . '/assets/screens.css' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- plugin asset.
		$this->assertStringContainsString( '.jl-cam{', $css );
		$this->assertStringContainsString( '.jl-has-photo{', $css );
		$this->assertStringContainsString( '.jl-photo-input:focus-visible~.jl-photo-add', $css );
		$this->assertStringContainsString( '.jl-photo [hidden]{display:none!important}', $css );
		$this->assertStringNotContainsString( '.jl-cam{color', $css, 'no fixed colour: the camera inherits the tile text colour in both modes' );
		$this->assertStringContainsString( 'fill="currentColor"', jlife_challenges_camera_icon() );
		$this->assertStringContainsString( 'max-width:100%', $css, 'the preview shrinks on a 320 px screen' );
		$js = (string) file_get_contents( dirname( __DIR__ ) . '/assets/soap.js' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- plugin asset.
		$this->assertStringContainsString( 'photoRest', $js );
		$this->assertStringContainsString( "'X-WP-Nonce'", $js );
	}

	/**
	 * A network failure shows the translated fallback, never the browser's English text; only the server's own message passes through (R13-04).
	 */
	public function test_photo_errors_show_server_text_or_the_translated_fallback() {
		$js = (string) file_get_contents( dirname( __DIR__ ) . '/assets/soap.js' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- plugin asset.
		$this->assertStringContainsString( 'err.fromServer = true', $js, 'only an error built from the server response is marked' );
		$this->assertSame( 2, substr_count( $js, 'err && err.fromServer && err.message ? err.message : msg.photo_failed' ), 'upload and remove both use it' );
		$this->assertStringNotContainsString( 'say(err && err.message ?', $js, 'a TypeError ("Failed to fetch") is never shown' );
	}

	/**
	 * The "Use SOAP" summary is at least 44 px tall and keeps a visible focus ring (R13-07).
	 */
	public function test_use_soap_summary_is_a_44px_tap_target() {
		$css = (string) file_get_contents( dirname( __DIR__ ) . '/assets/screens.css' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- plugin asset.
		$this->assertStringContainsString( '.jl-guidance>summary{display:flex;align-items:center;min-height:2.75rem}', $css );
		$this->assertStringContainsString( '.jl-guidance>summary:focus-visible{outline:3px solid var(--accent);outline-offset:2px}', $css );
	}

	// ------------------------------------------------------------------
	// The owner's review by download.
	// ------------------------------------------------------------------

	/**
	 * The export writes one owner-only zip of a participant's photo-earned days with a CSV, and
	 * reports counts and the path only.
	 */
	public function test_the_export_zip_has_the_photo_days_and_a_csv() {
		if ( ! class_exists( 'ZipArchive' ) ) {
			$this->markTestSkipped( 'ZipArchive is not available.' );
		}
		$this->attach( 'a', $this->jpeg( 640, 480 ) );
		$this->submit( 'a', 'x' );
		$expected = md5_file( jlife_challenges_photo_path( $this->photo_of( 'a' )['file'] ) );
		$this->set_now( '2026-12-21 18:00:00' );
		$this->submit( 'a', $this->soap( 'text day' ) );
		$dir = $this->base . '-export';
		$r   = jlife_challenges_photos_export( $this->u['organizer'], $this->u['a'], $this->campaign, $dir );
		$this->assertIsArray( $r );
		$this->assertSame( array( 'path', 'days', 'photos', 'missing' ), array_keys( $r ), 'counts and a path only' );
		$this->assertSame( array( 1, 1, 0 ), array( $r['days'], $r['photos'], $r['missing'] ) );
		$this->assertStringStartsWith( $dir . '/photos-u' . $this->u['a'] . '-c' . $this->campaign . '-', $r['path'] );
		$this->assertSame( '0600', substr( sprintf( '%o', fileperms( $r['path'] ) ), -4 ) );
		$zip = new ZipArchive();
		$this->assertTrue( $zip->open( $r['path'] ) );
		$this->assertSame( 2, $zip->count() );
		$this->assertSame( $expected, md5( (string) $zip->getFromName( 'd001.jpg' ) ), 'the stored picture, named by day' );
		$csv = (string) $zip->getFromName( 'days.csv' );
		$zip->close();
		$this->assertStringContainsString( 'campaign_id,day,date,upload_time_utc,submission_type,file', $csv );
		$this->assertMatchesRegularExpression( '/^' . $this->campaign . ',d001,2026-12-20,"?\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}"?,photo,d001\.jpg$/m', $csv );
		$this->assertStringNotContainsString( 'd002', $csv, 'a text-earned day is not in the review' );
		$this->assertStringNotContainsString( 'Aname', $csv . $r['path'], 'no names' );
		$this->rrmdir( $dir );
	}

	/**
	 * The export is for organizers; it refuses a person with no photo days, an unknown person or
	 * campaign; a removed photo is listed without a file; the audit holds counts only.
	 */
	public function test_the_export_refuses_the_wrong_people_and_cases() {
		if ( ! class_exists( 'ZipArchive' ) ) {
			$this->markTestSkipped( 'ZipArchive is not available.' );
		}
		$dir = $this->base . '-export';
		$this->attach( 'a' );
		$this->submit( 'a', 'x' );
		$this->assertSame( 'jlife_denied', jlife_challenges_photos_export( $this->u['b'], $this->u['a'], $this->campaign, $dir )->get_error_code(), 'a participant cannot export' );
		$this->assertSame( 'jlife_denied', jlife_challenges_photos_export( $this->u['organizer'], 999999, 0, $dir )->get_error_code() );
		$this->assertSame( 'jlife_denied', jlife_challenges_photos_export( $this->u['organizer'], $this->u['a'], 999999, $dir )->get_error_code() );
		$this->assertSame( 'jlife_photos_none', jlife_challenges_photos_export( $this->u['organizer'], $this->u['b'], 0, $dir )->get_error_code() );
		jlife_challenges_photo_remove( $this->u['a'], $this->campaign, 'd001' );
		$r = jlife_challenges_photos_export( $this->u['organizer'], $this->u['a'], 0, $dir );
		$this->assertSame( array( 1, 0, 1 ), array( $r['days'], $r['photos'], $r['missing'] ) );
		$zip = new ZipArchive();
		$zip->open( $r['path'] );
		$this->assertFalse( $zip->getFromName( 'd001.jpg' ) );
		$this->assertMatchesRegularExpression( '/^' . $this->campaign . ',d001,2026-12-20,,photo,$/m', (string) $zip->getFromName( 'days.csv' ) );
		$zip->close();
		global $wpdb;
		$audit = (string) $wpdb->get_var( 'SELECT detail FROM ' . jlife_challenges_table( 'audit_log' ) . " WHERE action = 'photos_exported' ORDER BY id DESC LIMIT 1" ); // phpcs:ignore WordPress.DB
		$this->assertSame( '{"days":1,"photos":0}', $audit );
		$this->rrmdir( $dir );
	}

	// ------------------------------------------------------------------
	// Strings.
	// ------------------------------------------------------------------

	/**
	 * Every new string has a Vietnamese draft, and the owner's wording is exact.
	 */
	public function test_the_vietnamese_drafts() {
		$catalog = include dirname( __DIR__ ) . '/languages/jlife-challenges-vi.l10n.php';
		$m       = $catalog['messages'];
		$this->assertSame( 'Hãy trả lời câu hỏi trong ô bên dưới bằng lời của chính bạn, hoặc thêm ảnh trang viết tay của bạn.', $m['Answer the question in the box below in your own words, or add a photo of your handwritten page.'] );
		$this->assertSame( 'Nhóm của bạn sẽ thấy ảnh này. Hãy chắc rằng ảnh không có thông tin riêng tư của người khác.', $m["Your group will see this photo. Make sure it doesn't show anything private about other people."] );
		$this->assertSame( 'Đã được điểm – ảnh tải lên', $m['Point earned – photo upload'] );
		foreach ( array( 'Add a photo of my handwritten reflection', 'Replace', 'Photo saved', 'Photo removed.', 'That photo is too large. Please choose a smaller one (under 12 MB).', 'Please choose a JPEG or PNG photo.' ) as $key ) {
			$this->assertArrayHasKey( $key, $m, $key );
		}
	}
}
