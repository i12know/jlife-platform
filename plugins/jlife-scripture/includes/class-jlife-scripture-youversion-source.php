<?php
/**
 * YouVersion Platform source (#139): a stub until the owner gates clear
 * (Biblica license terms read and recorded; app key on the host). It makes
 * no network calls and always reports the source as unavailable, so the
 * reader shows the Bible Gateway link. The real client (REST `format=html`
 * from PHP, YouVersion attribution, `X-YVP-App-Key` read from a private file
 * on the host, never logged) replaces fetch() in a follow-up; it plugs in
 * behind the same interface and the shared cache.
 *
 * @package jlife-scripture
 */

defined( 'ABSPATH' ) || exit;

/**
 * Stub: no client yet.
 */
class Jlife_Scripture_Youversion_Source implements Jlife_Scripture_Source {

	/**
	 * Registry source value.
	 *
	 * @return string
	 */
	public function id() {
		return 'youversion';
	}

	/**
	 * Licensed network text goes through the shared cache.
	 *
	 * @return bool
	 */
	public function cacheable() {
		return true;
	}

	/**
	 * Not implemented yet: always unavailable, no network.
	 *
	 * @param array $version  Registry version entry.
	 * @param array $segments Parsed segments.
	 * @return WP_Error
	 */
	public function fetch( array $version, array $segments ) {
		unset( $version, $segments );
		return new WP_Error( 'jlife_scripture_source_unavailable', __( 'This Bible version is not available here yet.', 'jlife-scripture' ) );
	}
}
