<?php
/**
 * Passage fetch with the shared server-side cache (#139 §3).
 *
 * - One shared row per source + version + passage, so many readers of the
 *   same day trigger one fetch, not one each.
 * - A lock (the row's lock_until) so the first concurrent views do not all
 *   fetch: one view fetches, the others wait briefly for its row and
 *   otherwise show the Bible Gateway link.
 * - The version's cache rule is honored on every read: a row older than
 *   max_age_seconds is never served, max_age_seconds = 0 stores nothing, and
 *   a passage longer than max_consecutive_verses is neither fetched (when the
 *   length is known from the reference) nor stored nor shown.
 * - Local text (a source whose cacheable() is false) bypasses the cache.
 * - Errors carry our own generic messages; logs carry the source, version,
 *   passage key and error code only. Never Bible text, never credentials.
 *
 * @package jlife-scripture
 */

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- the plugin's own cache table; the name comes from $wpdb->base_prefix.

/**
 * Current UTC time (filterable so tests can move the clock).
 *
 * @return int Unix timestamp.
 */
function jlife_scripture_now() {
	return (int) apply_filters( 'jlife_scripture_now', time() );
}

/**
 * MySQL DATETIME (UTC) for a timestamp.
 *
 * @param int $ts Unix timestamp.
 * @return string
 */
function jlife_scripture_mysql_time( $ts ) {
	return gmdate( 'Y-m-d H:i:s', (int) $ts );
}

/**
 * Write a line to the PHP error log. Callers pass identifiers only.
 *
 * @param string $message Message without Bible text or credentials.
 */
function jlife_scripture_log( $message ) {
	error_log( 'jlife-scripture: ' . $message ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- operational failures, identifiers only.
}

/**
 * A source's error code as it may appear in a log: only a known code or an
 * HTTP status, never free text a source chose (it could hold a key or text).
 *
 * @param mixed $code Error code from a source.
 * @return string
 */
function jlife_scripture_loggable_code( $code ) {
	$code  = (string) $code;
	$known = array( 'http_request_failed', 'timeout', 'rate_limited', 'unauthorized', 'forbidden', 'not_found', 'server_error', 'exception', 'jlife_scripture_not_found', 'jlife_scripture_source_unavailable' );
	return ( in_array( $code, $known, true ) || 1 === preg_match( '/^http_[1-5][0-9]{2}$/', $code ) ) ? $code : 'other';
}

/**
 * The generic failure every caller sees (no source detail).
 *
 * @param string $code Error code.
 * @return WP_Error
 */
function jlife_scripture_unavailable( $code = 'jlife_scripture_unavailable' ) {
	return new WP_Error( $code, __( 'The Bible text could not be loaded.', 'jlife-scripture' ) );
}

/**
 * Is this a well-formed fetch result (one list of verses per segment)?
 *
 * @param mixed $result   Fetch result.
 * @param int   $segments Number of segments asked for.
 * @return bool
 */
function jlife_scripture_valid_result( $result, $segments ) {
	if ( ! is_array( $result ) || count( $result ) !== $segments ) {
		return false;
	}
	foreach ( $result as $verses ) {
		if ( ! is_array( $verses ) || array() === $verses ) {
			return false;
		}
		foreach ( $verses as $v ) {
			if ( ! is_array( $v ) || ! is_int( $v['chapter'] ?? null ) || ! is_int( $v['verse'] ?? null ) || ! is_string( $v['text'] ?? null ) ) {
				return false;
			}
		}
	}
	return true;
}

/**
 * Distinct verses in a fetch result.
 *
 * @param array $result Fetch result.
 * @return int
 */
function jlife_scripture_result_verse_count( $result ) {
	$seen = array();
	foreach ( $result as $verses ) {
		foreach ( $verses as $v ) {
			$seen[ $v['chapter'] . ':' . $v['verse'] ] = true;
		}
	}
	return count( $seen );
}

/**
 * A source result as a cache entry: { segments, meta }. A source returns
 * either the verse lists, or { segments: <verse lists>, meta: array } when it
 * has more to say about the passage (API.Bible's FUMS ids, a per-response
 * cache rule: meta.cacheable false = do not store, meta.max_age = seconds,
 * never longer than the registry allows). Null when malformed.
 *
 * @param mixed $result   Fetch result or decoded cache body.
 * @param int   $segments Number of segments asked for.
 * @return array|null
 */
function jlife_scripture_entry( $result, $segments ) {
	if ( is_array( $result ) && array_key_exists( 'segments', $result ) ) {
		$meta   = is_array( $result['meta'] ?? null ) ? $result['meta'] : array();
		$result = $result['segments'];
	} else {
		$meta = array();
	}
	if ( ! jlife_scripture_valid_result( $result, $segments ) ) {
		return null;
	}
	return array(
		'segments' => $result,
		'meta'     => $meta,
	);
}

/**
 * Seconds a cache entry may be kept: the version's rule, shortened (never
 * lengthened) by the source's own per-response rule.
 *
 * @param array $version Version entry.
 * @param array $meta    Entry meta.
 * @return int 0 = do not store.
 */
function jlife_scripture_entry_max_age( $version, $meta ) {
	$age = (int) $version['cache']['max_age_seconds'];
	if ( false === ( $meta['cacheable'] ?? true ) ) {
		return 0;
	}
	if ( isset( $meta['max_age'] ) && is_int( $meta['max_age'] ) ) {
		$age = min( $age, max( 0, $meta['max_age'] ) );
	}
	return $age;
}

/**
 * Every time a passage is shown, fetched or from the cache: the
 * `jlife_scripture_passage_shown` action, and the source's track_display()
 * when it implements Jlife_Scripture_Display_Tracking (API.Bible FUMS).
 * A tracking failure never blocks the reading.
 *
 * @param Jlife_Scripture_Source $source   Source.
 * @param array                  $version  Version entry.
 * @param array                  $segments Segments.
 * @param array                  $meta     Entry meta.
 */
function jlife_scripture_passage_shown( $source, $version, $segments, $meta ) {
	try {
		if ( $source instanceof Jlife_Scripture_Display_Tracking ) {
			$source->track_display( $version, $segments, $meta );
		}
		do_action( 'jlife_scripture_passage_shown', $version, jlife_scripture_passage_key( $segments ), $meta );
	} catch ( Throwable $e ) {
		jlife_scripture_log( sprintf( 'display tracking failed: source=%s version=%s', $source->id(), $version['key'] ) );
	}
}

/**
 * Call a source, turning anything unexpected into our own generic error.
 *
 * @param Jlife_Scripture_Source $source   Source.
 * @param array                  $version  Version entry.
 * @param array                  $segments Segments.
 * @param string                 $key      Passage key (for the log line).
 * @return array|WP_Error Cache entry { segments, meta }.
 */
function jlife_scripture_guarded_fetch( $source, $version, $segments, $key ) {
	try {
		$result = $source->fetch( $version, $segments );
	} catch ( Throwable $e ) {
		$result = new WP_Error( 'exception' );
	}
	if ( is_wp_error( $result ) ) {
		jlife_scripture_log( sprintf( 'fetch failed: source=%s version=%s passage=%s code=%s', $source->id(), $version['key'], $key, jlife_scripture_loggable_code( $result->get_error_code() ) ) );
		return jlife_scripture_unavailable();
	}
	$entry = jlife_scripture_entry( $result, count( $segments ) );
	if ( null === $entry ) {
		jlife_scripture_log( sprintf( 'malformed result: source=%s version=%s passage=%s', $source->id(), $version['key'], $key ) );
		return jlife_scripture_unavailable();
	}
	$max = $version['cache']['max_consecutive_verses'];
	if ( null !== $max && jlife_scripture_result_verse_count( $entry['segments'] ) > $max ) {
		jlife_scripture_log( sprintf( 'over the license verse limit: version=%s passage=%s', $version['key'], $key ) );
		return jlife_scripture_unavailable( 'jlife_scripture_too_long' );
	}
	return $entry;
}

/**
 * Take the passage's lock (creating its row if needed). Atomic: exactly one
 * caller gets true until the lock expires or is released.
 *
 * @param string $key      Cache key.
 * @param array  $version  Version entry.
 * @param string $passage  Passage key.
 * @return bool
 */
function jlife_scripture_cache_lock( $key, $version, $passage ) {
	global $wpdb;
	$table = jlife_scripture_table( 'cache' );
	$now   = jlife_scripture_now();
	$until = jlife_scripture_mysql_time( $now + (int) apply_filters( 'jlife_scripture_lock_seconds', 30 ) );
	$made  = $wpdb->query(
		$wpdb->prepare(
			"INSERT IGNORE INTO {$table} (cache_key, source, version_key, passage, lock_until) VALUES (%s, %s, %s, %s, %s)",
			$key,
			(string) $version['source'],
			(string) $version['key'],
			$passage,
			$until
		)
	);
	if ( 1 === $made ) {
		return true;
	}
	$taken = $wpdb->query(
		$wpdb->prepare(
			"UPDATE {$table} SET lock_until = %s WHERE cache_key = %s AND ( lock_until IS NULL OR lock_until < %s )",
			$until,
			$key,
			jlife_scripture_mysql_time( $now )
		)
	);
	return 1 === $taken;
}

/**
 * A fresh cached result for the key, or null. Rows older than the version's
 * max age are never returned.
 *
 * @param string $key     Cache key.
 * @param array  $version Version entry.
 * @return array|null Decoded entry { segments, meta }.
 */
function jlife_scripture_cache_read( $key, $version ) {
	global $wpdb;
	$table = jlife_scripture_table( 'cache' );
	$row   = $wpdb->get_row( $wpdb->prepare( "SELECT body, fetched_at FROM {$table} WHERE cache_key = %s", $key ), ARRAY_A );
	if ( ! is_array( $row ) || null === $row['body'] || null === $row['fetched_at'] ) {
		return null;
	}
	$data = json_decode( (string) $row['body'], true );
	if ( ! is_array( $data ) || ! is_array( $data['segments'] ?? null ) ) {
		return null;
	}
	$meta = is_array( $data['meta'] ?? null ) ? $data['meta'] : array();
	$age  = jlife_scripture_now() - (int) strtotime( $row['fetched_at'] . ' UTC' );
	if ( $age < 0 || $age >= jlife_scripture_entry_max_age( $version, $meta ) ) {
		return null;
	}
	return $data;
}

/**
 * One passage's verses in a version, through the cache when the source is
 * cacheable. Never throws; any failure is a generic WP_Error so the reader
 * can show the link instead.
 *
 * @param array $version  Version entry (must be renderable).
 * @param mixed $segments Parsed segments.
 * @return array|WP_Error One list of verses per segment.
 */
function jlife_scripture_get_passage( $version, $segments ) {
	$source = jlife_scripture_can_render( $version ) ? jlife_scripture_get_source( (string) $version['source'] ) : null;
	if ( null === $source || ! is_array( $segments ) || array() === $segments ) {
		return jlife_scripture_unavailable();
	}
	$passage = jlife_scripture_passage_key( $segments );
	$max     = $version['cache']['max_consecutive_verses'];
	$known   = jlife_scripture_verse_count( $segments );
	if ( null !== $max && null !== $known && $known > $max ) {
		return jlife_scripture_unavailable( 'jlife_scripture_too_long' ); // Never ask a source for more than the license allows.
	}
	$entry = jlife_scripture_cached_entry( $source, $version, $segments, $passage );
	if ( is_wp_error( $entry ) ) {
		return $entry;
	}
	jlife_scripture_passage_shown( $source, $version, $segments, $entry['meta'] );
	return $entry['segments'];
}

/**
 * The passage's entry: from the cache when fresh, else fetched once (under
 * the lock) and stored if its rules allow.
 *
 * @param Jlife_Scripture_Source $source   Source.
 * @param array                  $version  Version entry.
 * @param array                  $segments Segments.
 * @param string                 $passage  Passage key.
 * @return array|WP_Error Entry { segments, meta }.
 */
function jlife_scripture_cached_entry( $source, $version, $segments, $passage ) {
	global $wpdb;
	if ( ! $source->cacheable() || (int) $version['cache']['max_age_seconds'] <= 0 ) {
		return jlife_scripture_guarded_fetch( $source, $version, $segments, $passage );
	}

	$table = jlife_scripture_table( 'cache' );
	$key   = hash( 'sha256', $version['source'] . '|' . $version['key'] . '|' . $version['source_id'] . '|' . $passage );
	$hit   = jlife_scripture_entry( jlife_scripture_cache_read( $key, $version ), count( $segments ) );
	if ( null !== $hit ) {
		return $hit;
	}
	if ( ! jlife_scripture_cache_lock( $key, $version, $passage ) ) {
		// Someone else is fetching: wait briefly for their row, then give up (link).
		$wait = (array) apply_filters(
			'jlife_scripture_lock_wait',
			array(
				'attempts' => 5,
				'micros'   => 200000,
			)
		);
		for ( $i = 0; $i < (int) ( $wait['attempts'] ?? 0 ); $i++ ) {
			usleep( (int) ( $wait['micros'] ?? 0 ) );
			$hit = jlife_scripture_entry( jlife_scripture_cache_read( $key, $version ), count( $segments ) );
			if ( null !== $hit ) {
				return $hit;
			}
		}
		return jlife_scripture_unavailable( 'jlife_scripture_busy' );
	}
	$entry = jlife_scripture_guarded_fetch( $source, $version, $segments, $passage );
	if ( is_wp_error( $entry ) || jlife_scripture_entry_max_age( $version, $entry['meta'] ) <= 0 ) {
		// Release the lock and keep no text: a failure, or a response that may not be stored.
		$wpdb->query( $wpdb->prepare( "UPDATE {$table} SET body = NULL, fetched_at = NULL, lock_until = NULL WHERE cache_key = %s", $key ) );
		return $entry;
	}
	$wpdb->query(
		$wpdb->prepare(
			"UPDATE {$table} SET body = %s, fetched_at = %s, lock_until = NULL WHERE cache_key = %s",
			(string) wp_json_encode( $entry ),
			jlife_scripture_mysql_time( jlife_scripture_now() ),
			$key
		)
	);
	return $entry;
}

/**
 * Delete cached text past its version's max age (or every row with
 * $all). Rows for versions no longer in the registry, or whose rule is now
 * "do not store", go too.
 *
 * @param bool $all Delete everything.
 * @return int Rows deleted.
 */
function jlife_scripture_cache_purge( $all = false ) {
	global $wpdb;
	$table = jlife_scripture_table( 'cache' );
	if ( $all ) {
		return (int) $wpdb->query( "DELETE FROM {$table}" );
	}
	$deleted = 0;
	$keep    = array();
	foreach ( jlife_scripture_registry()['versions'] as $v ) {
		$age = (int) $v['cache']['max_age_seconds'];
		if ( $age > 0 ) {
			$keep[]   = $v['key'];
			$deleted += (int) $wpdb->query(
				$wpdb->prepare(
					"DELETE FROM {$table} WHERE version_key = %s AND ( fetched_at IS NULL OR fetched_at < %s ) AND ( lock_until IS NULL OR lock_until < %s )",
					$v['key'],
					jlife_scripture_mysql_time( jlife_scripture_now() - $age ),
					jlife_scripture_mysql_time( jlife_scripture_now() )
				)
			);
		}
	}
	if ( array() === $keep ) {
		return $deleted + (int) $wpdb->query( "DELETE FROM {$table}" );
	}
	$in = implode( ',', array_fill( 0, count( $keep ), '%s' ) );
	// phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- $in holds one %s per kept version.
	return $deleted + (int) $wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE version_key NOT IN ({$in})", $keep ) );
}

/**
 * Daily cron: purge expired cache rows.
 */
function jlife_scripture_cache_purge_expired() {
	jlife_scripture_cache_purge( false );
}
