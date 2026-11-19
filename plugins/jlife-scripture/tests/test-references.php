<?php
/**
 * The reference parser against every reference in the 104-day plans (the
 * fixture is checked in sync with the plan JSON by bin/validate-scripture.js)
 * and the forms the plans use.
 *
 * @package jlife-scripture
 */

/**
 * Parser tests.
 */
class Test_References extends Jlife_Scripture_TestCase {

	/**
	 * Fields the PHP and Node parsers share.
	 */
	const FIELDS = array( 'book', 'chapter_start', 'verse_start', 'verse_start_part', 'chapter_end', 'verse_end', 'verse_end_part', 'display' );

	/**
	 * Every reference in both plans parses to the same segments as the
	 * build's Node parser, with a known USFM book.
	 */
	public function test_every_plan_reference_parses_like_the_build() {
		$fixture = json_decode( (string) file_get_contents( __DIR__ . '/fixtures/plan-references.json' ), true ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- test fixture.
		$this->assertGreaterThan( 700, count( $fixture['references'] ) );
		foreach ( $fixture['references'] as $ref ) {
			$segments = jlife_scripture_parse_reference( $ref['display'] );
			$this->assertIsArray( $segments, $ref['display'] );
			$this->assertCount( count( $ref['refs'] ), $segments, $ref['display'] );
			foreach ( $ref['refs'] as $i => $expected ) {
				foreach ( self::FIELDS as $field ) {
					$this->assertSame( $expected[ $field ], $segments[ $i ][ $field ], "{$ref['display']} {$field}" );
				}
				$this->assertMatchesRegularExpression( '/^[1-4A-Z]{3}$/', $segments[ $i ]['usfm'] );
			}
		}
	}

	/**
	 * The unusual forms, one by one.
	 */
	public function test_plan_forms() {
		$s = jlife_scripture_parse_reference( 'Luke 3:23a' );
		$this->assertSame( array( 'LUK', 3, 23, 'a', 3, 23, 'a' ), array( $s[0]['usfm'], $s[0]['chapter_start'], $s[0]['verse_start'], $s[0]['verse_start_part'], $s[0]['chapter_end'], $s[0]['verse_end'], $s[0]['verse_end_part'] ) );

		$s = jlife_scripture_parse_reference( 'Matthew 4:13b-17a' );
		$this->assertSame( array( 13, 'b', 17, 'a' ), array( $s[0]['verse_start'], $s[0]['verse_start_part'], $s[0]['verse_end'], $s[0]['verse_end_part'] ) );

		$s = jlife_scripture_parse_reference( 'John 3:22-4:3' );
		$this->assertSame( array( 3, 22, 4, 3 ), array( $s[0]['chapter_start'], $s[0]['verse_start'], $s[0]['chapter_end'], $s[0]['verse_end'] ) );
		$this->assertSame( 18, jlife_scripture_verse_count( $s ), '15 verses of John 3 and 3 of John 4' );

		$s = jlife_scripture_parse_reference( 'John 11:55-12:1, 9-11' );
		$this->assertSame( array( 'John 11:55-12:1', 'John 12:9-11' ), array_column( $s, 'display' ) );
		$this->assertSame( 'JHN.11.55-JHN.12.1,JHN.12.9-JHN.12.11', jlife_scripture_passage_key( $s ) );

		$s = jlife_scripture_parse_reference( 'Luke 24:10b, d' );
		$this->assertSame( array( 'Luke 24:10b', 'Luke 24:10d' ), array_column( $s, 'display' ) );
		$this->assertSame( 'LUK.24.10-LUK.24.10', jlife_scripture_passage_key( $s ), 'one text unit: the whole verse' );
		$this->assertSame( 1, jlife_scripture_verse_count( $s ) );

		$s = jlife_scripture_parse_reference( 'John 14' );
		$this->assertNull( $s[0]['verse_start'] );
		$this->assertSame( 31, jlife_scripture_verse_count( $s ) );
	}

	/**
	 * A multi-passage day.
	 */
	public function test_multi_passage_day() {
		$p = jlife_scripture_parse_reference_list( 'Matthew 3:13-17; Luke 3:23a; Matthew 4:1-11; John 1:19-28' );
		$this->assertSame( array( 'Matthew 3:13-17', 'Luke 3:23a', 'Matthew 4:1-11', 'John 1:19-28' ), array_column( $p, 'display' ) );
		$this->assertSame( 'LUK', $p[1]['segments'][0]['usfm'] );
	}

	/**
	 * Book names, abbreviations and the Vietnamese names map to USFM codes.
	 */
	public function test_book_names_and_abbreviations() {
		$cases = array(
			'Matthew'       => 'MAT',
			'Matt'          => 'MAT',
			'Mt.'           => 'MAT',
			'Mk'            => 'MRK',
			'Lk'            => 'LUK',
			'Jn'            => 'JHN',
			'john'          => 'JHN',
			'Acts'          => 'ACT',
			'1 Corinthians' => '1CO',
			'1 Cor'         => '1CO',
			'Song of Songs' => 'SNG',
			'Ma-thi-ơ'      => 'MAT',
			'Mác'           => 'MRK',
			'Lu-ca'         => 'LUK',
			'Giăng'         => 'JHN',
			'Công-vụ'       => 'ACT',
		);
		foreach ( $cases as $name => $code ) {
			$this->assertSame( $code, jlife_scripture_book_code( $name ), $name );
		}
		$this->assertCount( 66, array_unique( jlife_scripture_usfm_books() ) );
		$this->assertSame( 'JHN', jlife_scripture_parse_reference( 'Giăng 4:4-26' )[0]['usfm'] );
	}

	/**
	 * Malformed or impossible references are refused, never guessed.
	 */
	public function test_bad_references_are_refused() {
		foreach ( array( '', 'John', 'Hezekiah 1:1', 'John 22:1', 'John 1:52', 'John 1:18-1', 'John 2:1-1:3', 'John 1:1-', 'John 1:1; ', 'John a:1' ) as $bad ) {
			$r = jlife_scripture_parse_reference( $bad );
			$this->assertWPError( $r, $bad );
			$this->assertSame( 'jlife_scripture_bad_reference', $r->get_error_code() );
		}
		$this->assertWPError( jlife_scripture_parse_reference_list( ' ; ' ) );
	}

	/**
	 * Bible Gateway fallback links drop part letters and keep every range.
	 */
	public function test_bible_gateway_links() {
		$this->assertSame(
			'https://www.biblegateway.com/passage/?search=Luke+3:23&version=ERV',
			jlife_scripture_bible_gateway_url( jlife_scripture_parse_reference( 'Luke 3:23a' ), 'ERV' )
		);
		$this->assertSame(
			'https://www.biblegateway.com/passage/?search=John+11:55-12:1;John+12:9-11&version=BPT',
			jlife_scripture_bible_gateway_url( jlife_scripture_parse_reference( 'John 11:55-12:1, 9-11' ), 'BPT' )
		);
		$this->assertSame(
			'https://www.biblegateway.com/passage/?search=John+4:4-26&version=BPT',
			jlife_scripture_bible_gateway_url( jlife_scripture_parse_reference( 'Giăng 4:4-26' ), 'BPT' ),
			'a Vietnamese label still links with the English book name'
		);
	}
}
