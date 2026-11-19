<?php
/**
 * Optional display tracking for sources (#139, for API.Bible FUMS in #141).
 *
 * @package jlife-scripture
 */

defined( 'ABSPATH' ) || exit;

/**
 * Optional: a source that must record every display of a passage, including
 * displays served from the shared cache (API.Bible FUMS, #141). Called with
 * the meta the source returned when the passage was fetched.
 */
interface Jlife_Scripture_Display_Tracking {

	/**
	 * Record one display. Must not throw for network errors; never log text.
	 *
	 * @param array $version  Registry version entry.
	 * @param array $segments Parsed segments.
	 * @param array $meta     The meta stored with the passage.
	 */
	public function track_display( array $version, array $segments, array $meta );
}
