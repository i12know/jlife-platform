<?php
/**
 * Optional source-specific attribution for the reader (#141).
 *
 * @package jlife-scripture
 */

defined( 'ABSPATH' ) || exit;

/**
 * Optional: a source whose text needs more under it than the registry's
 * copyright line, such as the notice the API returned with the passage and a
 * required link (API.Bible). The reader prints this instead of the registry
 * line, for a passage shown from this source, fetched or cached.
 */
interface Jlife_Scripture_Source_Attribution {

	/**
	 * The attribution paragraph for one shown passage.
	 *
	 * @param array $version Registry version entry.
	 * @param array $meta    The meta stored with the passage.
	 * @return string Escaped HTML (a <p class="jls-version"> element).
	 */
	public function attribution_html( array $version, array $meta );
}
