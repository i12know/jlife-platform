<?php
/**
 * The local source: Kinh Thánh 1925 imported from the committed eBible.org
 * `vie1934` dataset with its provenance, read without the cache.
 *
 * @package jlife-scripture
 */

/**
 * Local source tests.
 */
class Test_Local_Source extends Jlife_Scripture_TestCase {

	/**
	 * Temporary dataset folders to remove.
	 *
	 * @var string[]
	 */
	private $dirs = array();

	/**
	 * Remove temporary folders.
	 */
	public function tear_down() {
		foreach ( $this->dirs as $dir ) {
			array_map( 'unlink', (array) glob( $dir . '/*' ) );
			rmdir( $dir ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- test cleanup.
		}
		parent::tear_down();
	}

	/**
	 * Verses for one reference from Kinh Thánh 1925.
	 *
	 * @param string $ref Reference.
	 * @return array|WP_Error
	 */
	private function read( $ref ) {
		return jlife_scripture_get_passage( jlife_scripture_get_version( 'kt1925' ), jlife_scripture_parse_reference( $ref ) );
	}

	/**
	 * The import records the source URL, edition and checksums, and a repeat
	 * with the same data changes nothing.
	 */
	public function test_import_records_provenance_and_is_idempotent() {
		$manifest = json_decode( (string) file_get_contents( jlife_scripture_dataset_dir( 'vie1934' ) . '/manifest.json' ), true ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- plugin data.
		$row      = jlife_scripture_dataset_status( 'vie1934' );
		$this->assertSame( 'https://ebible.org/Scriptures/vie1934_usfm.zip', $row['source_url'] );
		$this->assertSame( $manifest['edition'], $row['edition'] );
		$this->assertSame( $manifest['source_sha256'], $row['source_sha256'] );
		$this->assertSame( $manifest['data_sha256'], $row['data_sha256'] );
		$this->assertSame( 31102, (int) $row['verse_count'] );
		$this->assertSame( 'unchanged', jlife_scripture_import_dataset( 'vie1934' )['status'] );
	}

	/**
	 * Known verses read back exactly, with the legacy VNI capitals repaired.
	 */
	public function test_known_verses() {
		$john = $this->read( 'John 1:1' );
		$this->assertSame( 'Ban đầu có Ngôi Lời, Ngôi Lời ở cùng Đức Chúa Trời, và Ngôi Lời là Đức Chúa Trời.', $john[0][0]['text'] );
		$this->assertStringStartsWith( 'Ấy là Đấng đến sau ta', $this->read( 'John 1:27' )[0][0]['text'] );
		$this->assertStringContainsString( 'Ông là ai?', $this->read( 'John 1:19' )[0][0]['text'] );
		global $wpdb;
		$table = jlife_scripture_table( 'verses' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- test query on the plugin's table.
		$left = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE dataset = 'vie1934' AND ( text LIKE BINARY '%Aáy%' OR text LIKE BINARY '%Oâng%' )" );
		$this->assertSame( 0, $left, 'no VNI leftovers' );
	}

	/**
	 * Partial verses, chapter-crossing ranges and comma continuations.
	 */
	public function test_reference_shapes() {
		$r = $this->read( 'Luke 3:23a' );
		$this->assertSame( array( 23 ), array_column( $r[0], 'verse' ), 'a partial verse reads the whole verse' );

		$r = $this->read( 'John 3:22-4:3' );
		$this->assertCount( 18, $r[0] );
		$this->assertSame( array( 3, 22 ), array( $r[0][0]['chapter'], $r[0][0]['verse'] ) );
		$this->assertSame( array( 4, 3 ), array( $r[0][17]['chapter'], $r[0][17]['verse'] ) );

		$r = $this->read( 'John 11:55-12:1, 9-11' );
		$this->assertCount( 2, $r );
		$this->assertCount( 4, $r[0], '11:55-57 and 12:1' );
		$this->assertSame( array( 9, 10, 11 ), array_column( $r[1], 'verse' ) );

		$r = $this->read( 'John 7:53-8:11' );
		$this->assertCount( 12, $r[0], 'the 1925 text includes John 7:53-8:11' );

		$this->assertWPError( $this->read( 'Acts 29:1' ) );
	}

	/**
	 * Every reference in both plans has its text in the 1925 dataset.
	 */
	public function test_every_plan_reference_has_text() {
		$fixture = json_decode( (string) file_get_contents( __DIR__ . '/fixtures/plan-references.json' ), true ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- test fixture.
		foreach ( $fixture['references'] as $ref ) {
			$segments = jlife_scripture_parse_reference( $ref['display'] );
			$r        = jlife_scripture_get_passage( jlife_scripture_get_version( 'kt1925' ), $segments );
			$this->assertIsArray( $r, $ref['display'] );
			foreach ( $segments as $i => $s ) {
				if ( null === $s['verse_start'] ) {
					continue;
				}
				$first = $r[ $i ][0];
				$last  = end( $r[ $i ] );
				$this->assertSame( array( $s['chapter_start'], $s['verse_start'] ), array( $first['chapter'], $first['verse'] ), $ref['display'] );
				$this->assertSame( array( $s['chapter_end'], $s['verse_end'] ), array( $last['chapter'], $last['verse'] ), $ref['display'] );
			}
		}
	}

	/**
	 * Local text bypasses the cache: nothing is written there.
	 */
	public function test_local_text_is_not_cached() {
		global $wpdb;
		$table = jlife_scripture_table( 'cache' );
		jlife_scripture_render_day( $this->day( 'John 1:1-18; John 1:19-28' ), $this->participant( 'vi' ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- test query on the plugin's table.
		$this->assertSame( 0, (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" ) );
	}

	/**
	 * A dataset whose files do not match the manifest is refused before any
	 * write; a matching one imports.
	 */
	public function test_checksums_are_verified_before_import() {
		$dir = $this->make_dataset( 'testset', "1\t1\tVerse one.\n1\t2\tVerse two.\n" );
		$r   = jlife_scripture_import_dataset( 'testset', array( 'dir' => $dir ) );
		$this->assertSame( 'imported', $r['status'] );
		$this->assertSame( 2, (int) jlife_scripture_dataset_status( 'testset' )['verse_count'] );

		file_put_contents( $dir . '/GEN.tsv', "1\t1\tVerse one, changed.\n1\t2\tVerse two.\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- test fixture.
		$r = jlife_scripture_import_dataset( 'testset', array( 'dir' => $dir, 'force' => true ) ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound -- test.
		$this->assertWPError( $r );
		$this->assertSame( 'jlife_scripture_dataset_invalid', $r->get_error_code() );
		$this->assertStringNotContainsString( 'Verse one', $r->get_error_message(), 'no text in errors' );
		$this->assertSame( 2, (int) jlife_scripture_dataset_status( 'testset' )['verse_count'], 'the earlier import is untouched' );
	}

	/**
	 * Write a one-book dataset with a correct manifest.
	 *
	 * @param string $id   Dataset id.
	 * @param string $body GEN.tsv contents.
	 * @return string Directory.
	 */
	private function make_dataset( $id, $body ) {
		$dir = get_temp_dir() . 'jls-' . wp_generate_password( 8, false, false );
		wp_mkdir_p( $dir );
		$this->dirs[] = $dir;
		file_put_contents( $dir . '/GEN.tsv', $body ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- test fixture.
		$sha      = hash( 'sha256', $body );
		$manifest = array(
			'dataset'       => $id,
			'name'          => 'Test',
			'language'      => 'en',
			'source_url'    => 'https://example.test/data.zip',
			'edition'       => 'test',
			'source_sha256' => str_repeat( '0', 64 ),
			'verse_count'   => 2,
			'books'         => array(
				array(
					'code'   => 'GEN',
					'file'   => 'GEN.tsv',
					'verses' => 2,
					'sha256' => $sha,
				),
			),
			'data_sha256'   => hash( 'sha256', "GEN {$sha}\n" ),
		);
		file_put_contents( $dir . '/manifest.json', wp_json_encode( $manifest ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- test fixture.
		return $dir;
	}
}
