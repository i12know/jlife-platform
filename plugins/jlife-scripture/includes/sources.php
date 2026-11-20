<?php
/**
 * Source lookup (#139): registry `source` value => implementation. Add a
 * source by implementing Jlife_Scripture_Source and adding it here (API.Bible,
 * #141, is the second one); tests swap in fakes with the `jlife_scripture_sources` filter.
 *
 * @package jlife-scripture
 */

defined( 'ABSPATH' ) || exit;

/**
 * All registered sources.
 *
 * @return array<string, Jlife_Scripture_Source>
 */
function jlife_scripture_sources() {
	$sources = apply_filters(
		'jlife_scripture_sources',
		array(
			'local'      => new Jlife_Scripture_Local_Source(),
			'youversion' => new Jlife_Scripture_Youversion_Source(),
			'apibible'   => new Jlife_Scripture_Apibible_Source(),
		)
	);
	return array_filter( (array) $sources, static fn( $s ) => $s instanceof Jlife_Scripture_Source );
}

/**
 * One source by id.
 *
 * @param string $id Registry source value.
 * @return Jlife_Scripture_Source|null
 */
function jlife_scripture_get_source( $id ) {
	return jlife_scripture_sources()[ (string) $id ] ?? null;
}
