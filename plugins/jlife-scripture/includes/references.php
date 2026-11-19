<?php
/**
 * Scripture reference parser (#139), the PHP twin of
 * bin/content-lib/scripture.js. Parses the forms the reading plans use:
 *
 *   John 1:1-18          Mark 6:45            John 14 (whole chapter)
 *   Luke 3:23a           Matthew 4:13b-17a    John 3:22-4:3 (crosses a chapter)
 *   John 15:18-16:4b     John 11:55-12:1, 9-11 (comma continues the last chapter)
 *   Luke 24:10b, d       (comma adds another part of the same verse)
 *
 * and a semicolon-separated list of them (one plan day). Book names are the
 * English names in the plans, common abbreviations, and the Vietnamese names
 * the plans' translated references use; each maps to a USFM book code.
 * References only; never Bible text.
 *
 * @package jlife-scripture
 */

defined( 'ABSPATH' ) || exit;

/**
 * USFM book codes keyed by English book name (the names the plans use).
 *
 * @return array<string, string>
 */
function jlife_scripture_usfm_books() {
	return array(
		'Genesis'         => 'GEN',
		'Exodus'          => 'EXO',
		'Leviticus'       => 'LEV',
		'Numbers'         => 'NUM',
		'Deuteronomy'     => 'DEU',
		'Joshua'          => 'JOS',
		'Judges'          => 'JDG',
		'Ruth'            => 'RUT',
		'1 Samuel'        => '1SA',
		'2 Samuel'        => '2SA',
		'1 Kings'         => '1KI',
		'2 Kings'         => '2KI',
		'1 Chronicles'    => '1CH',
		'2 Chronicles'    => '2CH',
		'Ezra'            => 'EZR',
		'Nehemiah'        => 'NEH',
		'Esther'          => 'EST',
		'Job'             => 'JOB',
		'Psalms'          => 'PSA',
		'Proverbs'        => 'PRO',
		'Ecclesiastes'    => 'ECC',
		'Song of Songs'   => 'SNG',
		'Isaiah'          => 'ISA',
		'Jeremiah'        => 'JER',
		'Lamentations'    => 'LAM',
		'Ezekiel'         => 'EZK',
		'Daniel'          => 'DAN',
		'Hosea'           => 'HOS',
		'Joel'            => 'JOL',
		'Amos'            => 'AMO',
		'Obadiah'         => 'OBA',
		'Jonah'           => 'JON',
		'Micah'           => 'MIC',
		'Nahum'           => 'NAM',
		'Habakkuk'        => 'HAB',
		'Zephaniah'       => 'ZEP',
		'Haggai'          => 'HAG',
		'Zechariah'       => 'ZEC',
		'Malachi'         => 'MAL',
		'Matthew'         => 'MAT',
		'Mark'            => 'MRK',
		'Luke'            => 'LUK',
		'John'            => 'JHN',
		'Acts'            => 'ACT',
		'Romans'          => 'ROM',
		'1 Corinthians'   => '1CO',
		'2 Corinthians'   => '2CO',
		'Galatians'       => 'GAL',
		'Ephesians'       => 'EPH',
		'Philippians'     => 'PHP',
		'Colossians'      => 'COL',
		'1 Thessalonians' => '1TH',
		'2 Thessalonians' => '2TH',
		'1 Timothy'       => '1TI',
		'2 Timothy'       => '2TI',
		'Titus'           => 'TIT',
		'Philemon'        => 'PHM',
		'Hebrews'         => 'HEB',
		'James'           => 'JAS',
		'1 Peter'         => '1PE',
		'2 Peter'         => '2PE',
		'1 John'          => '1JN',
		'2 John'          => '2JN',
		'3 John'          => '3JN',
		'Jude'            => 'JUD',
		'Revelation'      => 'REV',
	);
}

/**
 * Other spellings that map to a USFM code: common English abbreviations and
 * the Vietnamese names used in the plans' translated references
 * (bin/content-lib/book-names.js). Keys are lowercase.
 *
 * @return array<string, string>
 */
function jlife_scripture_book_aliases() {
	return array(
		'gen'       => 'GEN',
		'ex'        => 'EXO',
		'exod'      => 'EXO',
		'lev'       => 'LEV',
		'num'       => 'NUM',
		'deut'      => 'DEU',
		'josh'      => 'JOS',
		'judg'      => 'JDG',
		'1 sam'     => '1SA',
		'2 sam'     => '2SA',
		'1 kgs'     => '1KI',
		'2 kgs'     => '2KI',
		'1 chr'     => '1CH',
		'2 chr'     => '2CH',
		'neh'       => 'NEH',
		'esth'      => 'EST',
		'ps'        => 'PSA',
		'psalm'     => 'PSA',
		'prov'      => 'PRO',
		'eccl'      => 'ECC',
		'song'      => 'SNG',
		'isa'       => 'ISA',
		'jer'       => 'JER',
		'lam'       => 'LAM',
		'ezek'      => 'EZK',
		'dan'       => 'DAN',
		'hos'       => 'HOS',
		'obad'      => 'OBA',
		'mic'       => 'MIC',
		'nah'       => 'NAM',
		'hab'       => 'HAB',
		'zeph'      => 'ZEP',
		'hag'       => 'HAG',
		'zech'      => 'ZEC',
		'mal'       => 'MAL',
		'matt'      => 'MAT',
		'mt'        => 'MAT',
		'mk'        => 'MRK',
		'mrk'       => 'MRK',
		'lk'        => 'LUK',
		'luk'       => 'LUK',
		'jn'        => 'JHN',
		'jhn'       => 'JHN',
		'rom'       => 'ROM',
		'1 cor'     => '1CO',
		'2 cor'     => '2CO',
		'gal'       => 'GAL',
		'eph'       => 'EPH',
		'phil'      => 'PHP',
		'col'       => 'COL',
		'1 thess'   => '1TH',
		'2 thess'   => '2TH',
		'1 tim'     => '1TI',
		'2 tim'     => '2TI',
		'tit'       => 'TIT',
		'phlm'      => 'PHM',
		'heb'       => 'HEB',
		'jas'       => 'JAS',
		'1 pet'     => '1PE',
		'2 pet'     => '2PE',
		'1 jn'      => '1JN',
		'2 jn'      => '2JN',
		'3 jn'      => '3JN',
		'rev'       => 'REV',
		'ma-thi-ơ'  => 'MAT',
		'mác'       => 'MRK',
		'lu-ca'     => 'LUK',
		'giăng'     => 'JHN',
		'công-vụ'   => 'ACT',
		'công vụ'   => 'ACT',
		'1 cô-rinh' => '1CO',
		'2 cô-rinh' => '2CO',
	);
}

/**
 * USFM code for a book name or abbreviation.
 *
 * @param string $name Book as written, e.g. "John", "Jn", "Giăng".
 * @return string|null
 */
function jlife_scripture_book_code( $name ) {
	$name  = trim( preg_replace( '/\s+/u', ' ', (string) $name ) );
	$books = jlife_scripture_usfm_books();
	if ( isset( $books[ $name ] ) ) {
		return $books[ $name ];
	}
	$lower = mb_strtolower( rtrim( $name, '.' ), 'UTF-8' );
	foreach ( $books as $full => $code ) {
		if ( strtolower( $full ) === $lower ) {
			return $code;
		}
	}
	return jlife_scripture_book_aliases()[ $lower ] ?? null;
}

/**
 * Verses per chapter (standard English versification) for the books the
 * plans read, so a reference can be checked and a verse count estimated
 * before any fetch.
 *
 * @param string $usfm    USFM code.
 * @param int    $chapter Chapter.
 * @return int|null Null when the book or chapter is not in the table.
 */
function jlife_scripture_chapter_length( $usfm, $chapter ) {
	static $table = array(
		'MAT' => array( 25, 23, 17, 25, 48, 34, 29, 34, 38, 42, 30, 50, 58, 36, 39, 28, 27, 35, 30, 34, 46, 46, 39, 51, 46, 75, 66, 20 ),
		'MRK' => array( 45, 28, 35, 41, 43, 56, 37, 38, 50, 52, 33, 44, 37, 72, 47, 20 ),
		'LUK' => array( 80, 52, 38, 44, 39, 49, 50, 56, 62, 42, 54, 59, 35, 35, 32, 31, 37, 43, 48, 47, 38, 71, 56, 53 ),
		'JHN' => array( 51, 25, 36, 54, 47, 71, 53, 59, 41, 42, 57, 50, 38, 31, 27, 33, 26, 40, 42, 31, 25 ),
		'ACT' => array( 26, 47, 26, 37, 42, 15, 60, 40, 43, 48, 30, 25, 52, 28, 41, 40, 34, 28, 41, 38, 40, 30, 35, 27, 27, 32, 44, 31 ),
	);
	$chapters     = $table[ $usfm ] ?? null;
	return ( $chapters && $chapter >= 1 && $chapter <= count( $chapters ) ) ? $chapters[ $chapter - 1 ] : null;
}

/**
 * Canonical display for one segment ("John 3:22-4:3", "Luke 3:23a", "John 14").
 *
 * @param array $s Segment.
 * @return string
 */
function jlife_scripture_format_segment( $s ) {
	if ( null === $s['verse_start'] ) {
		return $s['chapter_start'] === $s['chapter_end']
			? "{$s['book']} {$s['chapter_start']}"
			: "{$s['book']} {$s['chapter_start']}-{$s['chapter_end']}";
	}
	$start = $s['chapter_start'] . ':' . $s['verse_start'] . ( $s['verse_start_part'] ?? '' );
	if ( $s['chapter_start'] === $s['chapter_end'] && $s['verse_start'] === $s['verse_end'] && $s['verse_start_part'] === $s['verse_end_part'] ) {
		return "{$s['book']} {$start}";
	}
	$end = $s['chapter_start'] === $s['chapter_end']
		? $s['verse_end'] . ( $s['verse_end_part'] ?? '' )
		: $s['chapter_end'] . ':' . $s['verse_end'] . ( $s['verse_end_part'] ?? '' );
	return "{$s['book']} {$start}-{$end}";
}

/**
 * Build one segment.
 *
 * @param string      $book Book as written.
 * @param string      $usfm USFM code.
 * @param int         $cs   Start chapter.
 * @param int|null    $vs   Start verse (null = whole chapters).
 * @param string|null $vsp  Start verse part letter.
 * @param int         $ce   End chapter.
 * @param int|null    $ve   End verse.
 * @param string|null $vep  End verse part letter.
 * @return array
 */
function jlife_scripture_segment( $book, $usfm, $cs, $vs, $vsp, $ce, $ve, $vep ) {
	$s            = array(
		'book'             => $book,
		'usfm'             => $usfm,
		'chapter_start'    => $cs,
		'verse_start'      => $vs,
		'verse_start_part' => '' === (string) $vsp ? null : (string) $vsp,
		'chapter_end'      => $ce,
		'verse_end'        => $ve,
		'verse_end_part'   => '' === (string) $vep ? null : (string) $vep,
	);
	$s['display'] = jlife_scripture_format_segment( $s );
	return $s;
}

/**
 * A problem with a segment's numbers, if any.
 *
 * @param array $s Segment.
 * @return string|null
 */
function jlife_scripture_segment_problem( $s ) {
	if ( $s['chapter_start'] < 1 || $s['chapter_end'] < $s['chapter_start'] ) {
		return 'chapter range runs backwards';
	}
	if ( null !== $s['verse_start'] && ( $s['verse_start'] < 1 || ( $s['chapter_start'] === $s['chapter_end'] && $s['verse_end'] < $s['verse_start'] ) ) ) {
		return 'verse range runs backwards';
	}
	foreach ( array( array( $s['chapter_start'], $s['verse_start'] ), array( $s['chapter_end'], $s['verse_end'] ) ) as $cv ) {
		if ( null === jlife_scripture_chapter_length( $s['usfm'], 1 ) ) {
			break; // Book not in the table: not checked.
		}
		$len = jlife_scripture_chapter_length( $s['usfm'], $cv[0] );
		if ( null === $len ) {
			return 'no such chapter';
		}
		if ( null !== $cv[1] && $cv[1] > $len ) {
			return 'no such verse';
		}
	}
	return null;
}

/**
 * Parse one reference ("John 11:55-12:1, 9-11") into segments.
 *
 * @param string $text Reference.
 * @return array|WP_Error List of segments, or jlife_scripture_bad_reference.
 */
function jlife_scripture_parse_reference( $text ) {
	$text = trim( (string) $text );
	$bad  = new WP_Error( 'jlife_scripture_bad_reference', __( 'That Scripture reference could not be read.', 'jlife-scripture' ) );
	if ( ! preg_match( '/^(.+?)\s+(\d.*)$/u', $text, $m ) ) {
		return $bad;
	}
	$book = $m[1];
	$usfm = jlife_scripture_book_code( $book );
	if ( null === $usfm ) {
		return $bad;
	}
	$parts      = preg_split( '/\s*,\s*/', $m[2] );
	$segments   = array();
	$chapter    = null;
	$last_verse = null;
	foreach ( (array) $parts as $i => $p ) {
		if ( 0 === $i && preg_match( '/^(\d+)$/', $p, $mm ) ) {
			// Whole chapter: "John 14".
			$chapter    = (int) $mm[1];
			$segments[] = jlife_scripture_segment( $book, $usfm, $chapter, null, null, $chapter, null, null );
			continue;
		}
		if ( preg_match( '/^(\d+):(\d+)([a-z])?(?:-(?:(\d+):)?(\d+)([a-z])?)?$/', $p, $mm ) ) {
			// "C:V", "C:V-V", "C:V-C:V" (each verse may carry a part letter).
			$cs         = (int) $mm[1];
			$vs         = (int) $mm[2];
			$vsp        = $mm[3] ?? '';
			$has_end    = isset( $mm[5] ) && '' !== $mm[5];
			$ce         = isset( $mm[4] ) && '' !== $mm[4] ? (int) $mm[4] : $cs;
			$ve         = $has_end ? (int) $mm[5] : $vs;
			$vep        = $has_end ? ( $mm[6] ?? '' ) : $vsp;
			$segments[] = jlife_scripture_segment( $book, $usfm, $cs, $vs, $vsp, $ce, $ve, $vep );
			$chapter    = $ce;
			$last_verse = $ve;
			continue;
		}
		if ( $i > 0 && null !== $chapter && preg_match( '/^(\d+)([a-z])?(?:-(\d+)([a-z])?)?$/', $p, $mm ) ) {
			// Continuation in the last chapter: ", 9-11".
			$vs         = (int) $mm[1];
			$vsp        = $mm[2] ?? '';
			$has_end    = isset( $mm[3] ) && '' !== $mm[3];
			$ve         = $has_end ? (int) $mm[3] : $vs;
			$vep        = $has_end ? ( $mm[4] ?? '' ) : $vsp;
			$segments[] = jlife_scripture_segment( $book, $usfm, $chapter, $vs, $vsp, $chapter, $ve, $vep );
			$last_verse = $ve;
			continue;
		}
		if ( $i > 0 && null !== $last_verse && preg_match( '/^([a-z])$/', $p, $mm ) ) {
			// Another part of the same verse: "Luke 24:10b, d".
			$segments[] = jlife_scripture_segment( $book, $usfm, (int) $chapter, $last_verse, $mm[1], (int) $chapter, $last_verse, $mm[1] );
			continue;
		}
		return $bad;
	}
	foreach ( $segments as $s ) {
		if ( null !== jlife_scripture_segment_problem( $s ) ) {
			return $bad;
		}
	}
	return $segments;
}

/**
 * Parse a semicolon-separated list (one plan day's cell).
 *
 * @param string $cell e.g. "Mark 6:30-37; John 6:8-15a".
 * @return array|WP_Error List of { display, segments }.
 */
function jlife_scripture_parse_reference_list( $cell ) {
	$passages = array();
	foreach ( explode( ';', (string) $cell ) as $raw ) {
		$display = trim( $raw );
		if ( '' === $display ) {
			continue;
		}
		$segments = jlife_scripture_parse_reference( $display );
		if ( is_wp_error( $segments ) ) {
			return $segments;
		}
		$passages[] = array(
			'display'  => $display,
			'segments' => $segments,
		);
	}
	if ( array() === $passages ) {
		return new WP_Error( 'jlife_scripture_bad_reference', __( 'That Scripture reference could not be read.', 'jlife-scripture' ) );
	}
	return $passages;
}

/**
 * Stable, language-independent key for a passage: USFM ranges without the
 * part letters (the text unit is the whole verse), e.g.
 * "JHN.11.55-JHN.12.1,JHN.12.9-JHN.12.11".
 *
 * @param array $segments Segments.
 * @return string
 */
function jlife_scripture_passage_key( $segments ) {
	$keys = array();
	foreach ( $segments as $s ) {
		$keys[] = null === $s['verse_start']
			? "{$s['usfm']}.{$s['chapter_start']}-{$s['usfm']}.{$s['chapter_end']}"
			: "{$s['usfm']}.{$s['chapter_start']}.{$s['verse_start']}-{$s['usfm']}.{$s['chapter_end']}.{$s['verse_end']}";
	}
	return implode( ',', array_values( array_unique( $keys ) ) );
}

/**
 * Upper bound on the verses a passage covers, when it can be worked out
 * from the reference alone (for a version's max_consecutive_verses rule).
 *
 * @param array $segments Segments.
 * @return int|null Null when a chapter length is unknown.
 */
function jlife_scripture_verse_count( $segments ) {
	$verses = array();
	foreach ( $segments as $s ) {
		for ( $c = $s['chapter_start']; $c <= $s['chapter_end']; $c++ ) {
			$len   = jlife_scripture_chapter_length( $s['usfm'], $c );
			$first = ( null !== $s['verse_start'] && $c === $s['chapter_start'] ) ? $s['verse_start'] : 1;
			if ( null !== $s['verse_start'] && $c === $s['chapter_end'] ) {
				$last = $s['verse_end'];
			} elseif ( null === $len ) {
				return null;
			} else {
				$last = $len;
			}
			for ( $v = $first; $v <= $last; $v++ ) {
				$verses[ "{$s['usfm']}.{$c}.{$v}" ] = true;
			}
		}
	}
	return count( $verses );
}

/**
 * Bible Gateway passage URL for one passage in a version (part letters
 * dropped; Bible Gateway shows whole verses).
 *
 * @param array  $segments Segments.
 * @param string $version  Bible Gateway version code, e.g. "ERV".
 * @return string
 */
function jlife_scripture_bible_gateway_url( $segments, $version ) {
	$english = array_flip( jlife_scripture_usfm_books() );
	$ranges  = array();
	foreach ( $segments as $s ) {
		$s['book']             = $english[ $s['usfm'] ] ?? $s['book'];
		$s['verse_start_part'] = null;
		$s['verse_end_part']   = null;
		$ranges[]              = str_replace( ' ', '+', jlife_scripture_format_segment( $s ) );
	}
	return 'https://www.biblegateway.com/passage/?search=' . implode( ';', array_values( array_unique( $ranges ) ) ) . '&version=' . rawurlencode( (string) $version );
}
