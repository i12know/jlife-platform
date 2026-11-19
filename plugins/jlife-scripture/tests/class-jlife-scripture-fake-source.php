<?php
/**
 * Test double for a licensed network source.
 *
 * @package jlife-scripture
 */

/**
 * A network-like source for the cache tests: counts calls, returns made-up
 * text (never real Bible text), can fail, throw, or leak a fake key in its
 * own error message (which must never reach a log or the page).
 */
class Jlife_Scripture_Fake_Source implements Jlife_Scripture_Source, Jlife_Scripture_Display_Tracking {

	/**
	 * Calls to fetch().
	 *
	 * @var int
	 */
	public $calls = 0;

	/**
	 * Displays recorded through track_display(), with their meta.
	 *
	 * @var array[]
	 */
	public $displays = array();

	/**
	 * Meta to return with the verses ({ segments, meta } form), or null.
	 *
	 * @var array|null
	 */
	public $meta = null;

	/**
	 * "ok", "error", "throw" or "malformed".
	 *
	 * @var string
	 */
	public $mode = 'ok';

	/**
	 * A marker that stands for licensed text in every verse returned.
	 */
	const TEXT = 'FAKE-LICENSED-TEXT-7f3a';

	/**
	 * A marker that stands for an API key.
	 */
	const KEY = 'FAKE-APP-KEY-91c2';

	/**
	 * Registry source value.
	 *
	 * @return string
	 */
	public function id() {
		return 'youversion';
	}

	/**
	 * Network sources are cached.
	 *
	 * @return bool
	 */
	public function cacheable() {
		return true;
	}

	/**
	 * One made-up verse per verse asked for.
	 *
	 * @param array $version  Version.
	 * @param array $segments Segments.
	 * @return array|WP_Error
	 * @throws RuntimeException In "throw" mode.
	 */
	public function fetch( array $version, array $segments ) {
		++$this->calls;
		if ( 'error' === $this->mode ) {
			return new WP_Error( 'http_' . self::KEY, 'Upstream said: ' . self::TEXT . ' key=' . self::KEY );
		}
		if ( 'throw' === $this->mode ) {
			throw new RuntimeException( 'boom ' . self::TEXT . ' ' . self::KEY ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- test double.
		}
		if ( 'malformed' === $this->mode ) {
			return array( 'text' => self::TEXT );
		}
		$out = array();
		foreach ( $segments as $s ) {
			$verses = array();
			$end    = null === $s['verse_end'] ? 8 : (int) $s['verse_end'];
			for ( $v = null === $s['verse_start'] ? 1 : (int) $s['verse_start']; $v <= $end; $v++ ) {
				$verses[] = array(
					'chapter' => (int) $s['chapter_end'],
					'verse'   => $v,
					'text'    => self::TEXT . " {$version['key']} {$v}",
				);
			}
			$out[] = $verses;
		}
		return null === $this->meta ? $out : array(
			'segments' => $out,
			'meta'     => $this->meta,
		);
	}

	/**
	 * Record a display.
	 *
	 * @param array $version  Version.
	 * @param array $segments Segments.
	 * @param array $meta     Stored meta.
	 */
	public function track_display( array $version, array $segments, array $meta ) {
		$this->displays[] = array( jlife_scripture_passage_key( $segments ), $meta );
	}
}
