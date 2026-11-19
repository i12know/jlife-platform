<?php
/**
 * A place Bible text comes from (#139). One implementation per registry
 * `source` value: local (an imported public-domain dataset), youversion,
 * apibible (#141). Link-only versions have no source.
 *
 * Rules for every implementation:
 * - Return verse text only, as plain text (no markup); the renderer escapes it.
 * - Never log, echo or put Bible text or credentials in a WP_Error message.
 * - Network sources say cacheable() = true; the shared passage cache
 *   (includes/cache.php) then fetches each passage once and honors the
 *   version's cache rule. Local text bypasses the cache.
 *
 * @package jlife-scripture
 */

defined( 'ABSPATH' ) || exit;

interface Jlife_Scripture_Source {

	/**
	 * The registry `source` value this implements, e.g. "local".
	 *
	 * @return string
	 */
	public function id();

	/**
	 * Should passages go through the shared cache? False for local text.
	 *
	 * @return bool
	 */
	public function cacheable();

	/**
	 * Fetch one passage.
	 *
	 * @param array $version  Registry version entry.
	 * @param array $segments Parsed segments (jlife_scripture_parse_reference()).
	 * @return array|WP_Error One list per segment of
	 *                        array{ chapter: int, verse: int, text: string }, in order;
	 *                        or { segments: <those lists>, meta: array } to keep
	 *                        extra data with the cached passage (e.g. FUMS ids;
	 *                        meta.cacheable = false or meta.max_age = seconds
	 *                        can only shorten the version's cache rule).
	 */
	public function fetch( array $version, array $segments );
}
