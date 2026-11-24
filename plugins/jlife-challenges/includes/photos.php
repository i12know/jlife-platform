<?php
/**
 * Photos of handwritten reflections (#244, docs/challenges-engine-design.md §4j).
 *
 * A campaign may accept a photo of a handwritten page as another way to write the
 * day's reflection. The rules this file keeps:
 *
 * - Private files. A photo is re-encoded (EXIF orientation applied, EXIF/GPS and
 *   every other metadata segment removed, longest side 1600 px) and stored under a
 *   random name with no extension in a folder that denies direct requests, never in
 *   the Media Library or uploads. It is read only through /photo/<id>/ after
 *   jlife_challenges_can_read_photo() says yes.
 * - One photo per participant, campaign and day (UNIQUE kind/entry/share), so a
 *   retry or a double submit replaces and never duplicates.
 * - Choosing a file never earns a point. Submit decides (soap.php): typed text that
 *   passes the engine is a text point, otherwise a stored photo is a photo point.
 * - A share copy is a frozen snapshot made when the author ticks a group.
 * - Nothing here writes a photo, its name or its path to a log or the audit log.
 *
 * @package jlife-challenges
 */

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.DB.DirectDatabaseQuery -- custom tables are this plugin's data layer; every query goes through the gate; WordPress has no API for them.
// phpcs:disable WordPress.WP.AlternativeFunctions -- private files are written, copied, read and deleted with plain PHP: the WP_Filesystem layer is for plugin installs and would ask for credentials.

const JLIFE_CHALLENGES_PHOTO_MAX_BYTES  = 12582912;
const JLIFE_CHALLENGES_PHOTO_MAX_PIXELS = 36000000;
const JLIFE_CHALLENGES_PHOTO_SIDE       = 1600;
const JLIFE_CHALLENGES_PHOTO_THUMB_SIDE = 320;
const JLIFE_CHALLENGES_PHOTO_GOAL_BYTES = 450000;
const JLIFE_CHALLENGES_PHOTO_ENGINE     = 'photo-upload';

/**
 * Does this campaign accept photo reflections?
 *
 * @param array|int|null $campaign Campaign row or ID.
 * @return bool
 */
function jlife_challenges_campaign_accepts_photos( $campaign ) {
	if ( ! is_array( $campaign ) ) {
		$campaign = (int) $campaign > 0 ? jlife_challenges_get_campaign( (int) $campaign ) : null;
	}
	return is_array( $campaign ) && ! empty( $campaign['accepts_photos'] );
}

/**
 * Change whether a campaign accepts photo reflections. The same lock as the
 * scoring engine (#210): only before Day 1 and while the campaign has no entries
 * or points, so nobody is ever scored under a rule that later changes. Closed
 * campaigns are refused. Setting what it already is changes nothing.
 *
 * @param int  $actor       Organizer.
 * @param int  $campaign_id Campaign.
 * @param bool $on          Accept photos or not.
 * @return array|WP_Error { changed: bool, accepts_photos: bool }
 */
function jlife_challenges_set_campaign_photos( $actor, $campaign_id, $on ) {
	$campaign = jlife_challenges_get_campaign( (int) $campaign_id );
	if ( ! jlife_challenges_is_organizer( $actor ) || ! $campaign ) {
		return jlife_challenges_denied();
	}
	$on       = (bool) $on;
	$previous = jlife_challenges_campaign_accepts_photos( $campaign );
	if ( $previous === $on ) {
		return array(
			'changed'        => false,
			'accepts_photos' => $on,
		);
	}
	$locked = jlife_challenges_campaign_rule_lock( $campaign, 'photo setting' );
	if ( $locked ) {
		return $locked;
	}
	global $wpdb;
	$ok = $wpdb->update(
		jlife_challenges_table( 'campaigns' ),
		array( 'accepts_photos' => $on ? 1 : 0 ),
		array( 'id' => (int) $campaign_id ),
		array( '%d' ),
		array( '%d' )
	);
	if ( false === $ok ) {
		return new WP_Error( 'jlife_db', 'Could not change the photo setting.' );
	}
	jlife_challenges_audit(
		$actor,
		'campaign_photos_set',
		'campaign',
		(int) $campaign_id,
		(int) $campaign_id,
		array(
			'from' => $previous ? 'on' : 'off',
			'to'   => $on ? 'on' : 'off',
		)
	);
	return array(
		'changed'        => true,
		'accepts_photos' => $on,
	);
}

/**
 * Why a campaign's scoring rule can no longer change, or null when it still can:
 * closed, Day 1 started, or any entry or point exists (#210, #244).
 *
 * @param array  $campaign Campaign row.
 * @param string $what     What would change, for the message ("scoring engine").
 * @return WP_Error|null
 */
function jlife_challenges_campaign_rule_lock( $campaign, $what ) {
	$cid = (int) $campaign['id'];
	if ( 'closed' === $campaign['status'] ) {
		return new WP_Error( 'jlife_engine_locked', "This campaign is closed; its {$what} cannot change." );
	}
	if ( jlife_challenges_local_date( $campaign, jlife_challenges_now() ) >= (string) $campaign['start_date'] ) {
		return new WP_Error( 'jlife_engine_locked', "Day 1 has already started; the {$what} can no longer change." );
	}
	if ( jlife_challenges_close_count( 'soap_entries', $cid ) > 0 || jlife_challenges_close_count( 'score_ledger', $cid ) > 0 ) {
		return new WP_Error( 'jlife_engine_locked', "This campaign already has entries; the {$what} can no longer change." );
	}
	return null;
}

/**
 * The folder the private files live in, for this site. WP_CONTENT_DIR/jlife-private/site-<id>
 * unless the JLIFE_PHOTO_DIR constant or the jlife_challenges_photo_dir filter names another
 * base (best: a folder above the web root). Not created here.
 *
 * @return string No trailing slash.
 */
function jlife_challenges_photo_dir() {
	$base = defined( 'JLIFE_PHOTO_DIR' ) ? (string) JLIFE_PHOTO_DIR : WP_CONTENT_DIR . '/jlife-private';
	$base = (string) apply_filters( 'jlife_challenges_photo_dir', $base );
	return untrailingslashit( wp_normalize_path( $base ) ) . '/site-' . (int) get_current_blog_id();
}

/**
 * Create the private folder (and the one above it) with files that deny direct requests.
 *
 * @return string|false The folder, or false if it cannot be made.
 */
function jlife_challenges_photo_prepare_dir() {
	$dir = jlife_challenges_photo_dir();
	if ( ! wp_mkdir_p( $dir ) ) {
		return false;
	}
	foreach ( array( dirname( $dir ), $dir ) as $folder ) {
		if ( ! is_file( $folder . '/.htaccess' ) ) {
			file_put_contents( $folder . '/.htaccess', "# Private photos: never served directly.\n<IfModule mod_authz_core.c>\nRequire all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\nOrder allow,deny\nDeny from all\n</IfModule>\nOptions -Indexes\n" );
		}
		if ( ! is_file( $folder . '/index.php' ) ) {
			file_put_contents( $folder . '/index.php', "<?php\n// Silence is golden.\n" );
		}
	}
	return is_dir( $dir ) && is_writable( $dir ) ? $dir : false;
}

/**
 * The path of a stored file, only for a well-formed random name.
 *
 * @param string $name 32 hex characters.
 * @return string '' if the name is not one of ours.
 */
function jlife_challenges_photo_path( $name ) {
	return 1 === preg_match( '/^[a-f0-9]{32}$/', (string) $name ) ? jlife_challenges_photo_dir() . '/' . $name : '';
}

/**
 * A plain error for the photo paths, with the wording the participant sees.
 *
 * @param string $code Error code.
 * @return WP_Error
 */
function jlife_challenges_photo_error( $code ) {
	$messages = array(
		'jlife_photos_off'        => __( 'Photo reflections are not turned on for this reading period.', 'jlife-challenges' ),
		'jlife_photo_type'        => __( 'Please choose a JPEG or PNG photo.', 'jlife-challenges' ),
		'jlife_photo_big'         => __( 'That photo is too large. Please choose a smaller one (under 12 MB).', 'jlife-challenges' ),
		'jlife_photo_none'        => __( 'No photo was chosen.', 'jlife-challenges' ),
		'jlife_photo_unsupported' => __( 'Photos cannot be saved on this site right now.', 'jlife-challenges' ),
		'jlife_photo_failed'      => __( 'The photo could not be saved. Please try again.', 'jlife-challenges' ),
	);
	return new WP_Error( $code, $messages[ $code ] ?? $messages['jlife_photo_failed'] );
}

/**
 * Take a JPEG apart and drop every APPn (EXIF, XMP, ICC, JFIF) and comment segment,
 * so no location or camera data can survive whatever image library re-encoded it.
 * The picture data is untouched.
 *
 * @param string $data JPEG bytes.
 * @return string|null Stripped bytes, or null if this is not a well-formed JPEG.
 */
function jlife_challenges_photo_strip_markers( $data ) {
	$data = (string) $data;
	$len  = strlen( $data );
	if ( $len < 4 || "\xFF\xD8" !== substr( $data, 0, 2 ) ) {
		return null;
	}
	$out = "\xFF\xD8";
	$pos = 2;
	while ( $pos < $len ) {
		if ( "\xFF" !== $data[ $pos ] ) {
			return null;
		}
		while ( $pos < $len && "\xFF" === $data[ $pos ] ) {
			++$pos; // Fill bytes.
		}
		if ( $pos >= $len ) {
			return null;
		}
		$marker = ord( $data[ $pos ] );
		++$pos;
		if ( 0xD9 === $marker ) {
			return $out . "\xFF\xD9";
		}
		if ( 0x01 === $marker || ( $marker >= 0xD0 && $marker <= 0xD8 ) ) {
			$out .= "\xFF" . chr( $marker );
			continue;
		}
		if ( $pos + 2 > $len ) {
			return null;
		}
		$size = ( ord( $data[ $pos ] ) << 8 ) | ord( $data[ $pos + 1 ] );
		if ( $size < 2 || $pos + $size > $len ) {
			return null;
		}
		$segment = substr( $data, $pos, $size );
		$pos    += $size;
		if ( ( $marker >= 0xE0 && $marker <= 0xEF ) || 0xFE === $marker ) {
			continue; // Metadata: dropped.
		}
		$out .= "\xFF" . chr( $marker ) . $segment;
		if ( 0xDA === $marker ) {
			// Scan data runs to the end of the file (with the closing marker): copy it as it is.
			return $out . substr( $data, $pos );
		}
	}
	return null;
}

/**
 * Check an uploaded file by its real content and re-encode it: orientation
 * applied, longest side 1600 px, JPEG about 300 KB, all metadata gone, plus a small
 * thumbnail. Nothing of the original is kept.
 *
 * @param string $tmp Path of the uploaded file (PHP's temporary file).
 * @return array|WP_Error { full: string, thumb: string, bytes: int } JPEG bytes.
 */
function jlife_challenges_photo_process( $tmp ) {
	$tmp = (string) $tmp;
	if ( '' === $tmp || ! is_file( $tmp ) || ! is_readable( $tmp ) ) {
		return jlife_challenges_photo_error( 'jlife_photo_failed' );
	}
	$bytes = (int) filesize( $tmp );
	if ( $bytes < 1 ) {
		return jlife_challenges_photo_error( 'jlife_photo_failed' );
	}
	if ( $bytes > JLIFE_CHALLENGES_PHOTO_MAX_BYTES ) {
		return jlife_challenges_photo_error( 'jlife_photo_big' );
	}
	$info = @getimagesize( $tmp ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- a damaged file only means "not an image".
	if ( ! is_array( $info ) ) {
		return jlife_challenges_photo_error( 'jlife_photo_type' );
	}
	$type = (int) $info[2];
	if ( ! in_array( $type, array( IMAGETYPE_JPEG, IMAGETYPE_PNG ), true ) || (int) $info[0] < 1 || (int) $info[1] < 1 ) {
		return jlife_challenges_photo_error( 'jlife_photo_type' );
	}
	$mime = IMAGETYPE_JPEG === $type ? 'image/jpeg' : 'image/png';
	if ( (int) $info[0] * (int) $info[1] > JLIFE_CHALLENGES_PHOTO_MAX_PIXELS ) {
		return jlife_challenges_photo_error( 'jlife_photo_big' );
	}
	wp_raise_memory_limit( 'image' );
	$work = trailingslashit( get_temp_dir() ) . 'jlife-photo-' . bin2hex( random_bytes( 8 ) );
	// A copy with the right extension, so every image library reads it the same way.
	$src = $work . ( 'image/png' === $mime ? '.png' : '.jpg' );
	if ( ! copy( $tmp, $src ) ) {
		return jlife_challenges_photo_error( 'jlife_photo_failed' );
	}
	$result = jlife_challenges_photo_render( $src, $mime, $work );
	wp_delete_file( $src );
	return $result;
}

/**
 * Re-encode one file (see jlife_challenges_photo_process()).
 *
 * @param string $src  Path of a copy with a .jpg or .png extension.
 * @param string $mime image/jpeg or image/png.
 * @param string $work Base path for temporary output (no extension).
 * @return array|WP_Error
 */
function jlife_challenges_photo_render( $src, $mime, $work ) {
	$editor = wp_get_image_editor( $src, array( 'mime_type' => $mime ) );
	if ( is_wp_error( $editor ) ) {
		return jlife_challenges_photo_error( 'jlife_photo_unsupported' );
	}
	$editor->maybe_exif_rotate(); // Orientation first; a failure only leaves the picture as it came.
	$out   = array();
	$specs = array(
		'full'  => array( JLIFE_CHALLENGES_PHOTO_SIDE, 78 ),
		'thumb' => array( JLIFE_CHALLENGES_PHOTO_THUMB_SIDE, 70 ),
	);
	foreach ( $specs as $name => $spec ) {
		$size = $editor->get_size();
		if ( max( (int) $size['width'], (int) $size['height'] ) > $spec[0] ) {
			if ( is_wp_error( $editor->resize( $spec[0], $spec[0], false ) ) ) {
				return jlife_challenges_photo_error( 'jlife_photo_failed' );
			}
		}
		$quality = $spec[1];
		do {
			$editor->set_quality( $quality );
			$dest  = $work . '-' . $name . '-' . $quality . '.jpg';
			$saved = $editor->save( $dest, 'image/jpeg' );
			if ( is_wp_error( $saved ) || empty( $saved['path'] ) || ! is_file( (string) $saved['path'] ) ) {
				return jlife_challenges_photo_error( 'jlife_photo_failed' );
			}
			$raw = (string) file_get_contents( (string) $saved['path'] );
			wp_delete_file( (string) $saved['path'] );
			$clean = jlife_challenges_photo_strip_markers( $raw );
			if ( null === $clean ) {
				return jlife_challenges_photo_error( 'jlife_photo_failed' );
			}
			$again   = 'full' === $name && strlen( $clean ) > JLIFE_CHALLENGES_PHOTO_GOAL_BYTES && $quality > 70;
			$quality = $again ? 62 : $quality;
		} while ( $again );
		$out[ $name ] = $clean;
	}
	return array(
		'full'  => $out['full'],
		'thumb' => $out['thumb'],
		'bytes' => strlen( $out['full'] ),
	);
}

/**
 * Write the two files under fresh random names.
 *
 * @param string $full  Full-size JPEG bytes.
 * @param string $thumb Thumbnail JPEG bytes.
 * @return array|WP_Error { file: string, thumb: string }
 */
function jlife_challenges_photo_write_files( $full, $thumb ) {
	$dir = jlife_challenges_photo_prepare_dir();
	if ( false === $dir ) {
		return jlife_challenges_photo_error( 'jlife_photo_unsupported' );
	}
	$names = array(
		'file'  => bin2hex( random_bytes( 16 ) ),
		'thumb' => bin2hex( random_bytes( 16 ) ),
	);
	$data  = array(
		'file'  => (string) $full,
		'thumb' => (string) $thumb,
	);
	foreach ( $names as $key => $name ) {
		$path = $dir . '/' . $name;
		if ( false === file_put_contents( $path, $data[ $key ], LOCK_EX ) ) {
			foreach ( $names as $n ) {
				wp_delete_file( $dir . '/' . $n );
			}
			return jlife_challenges_photo_error( 'jlife_photo_failed' );
		}
		chmod( $path, 0600 );
	}
	return $names;
}

/**
 * Delete the two files of a photo row. Missing files are fine.
 *
 * @param array $row Photo row (file, thumb).
 */
function jlife_challenges_photo_delete_files( $row ) {
	foreach ( array( 'file', 'thumb' ) as $key ) {
		$path = jlife_challenges_photo_path( (string) ( $row[ $key ] ?? '' ) );
		if ( '' !== $path && is_file( $path ) ) {
			wp_delete_file( $path );
		}
	}
}

/**
 * Read a photo row (no gate; internal).
 *
 * @param int $photo_id Photo ID.
 * @return array|null
 */
function jlife_challenges_find_photo( $photo_id ) {
	global $wpdb;
	$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . jlife_challenges_table( 'photos' ) . ' WHERE id = %d', (int) $photo_id ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- table name from $wpdb->prefix.
	return $row ? $row : null;
}

/**
 * The private photo of an entry (draft or submitted), or null.
 *
 * @param int $entry_id Entry.
 * @return array|null
 */
function jlife_challenges_entry_photo( $entry_id ) {
	global $wpdb;
	$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . jlife_challenges_table( 'photos' ) . " WHERE kind = 'entry' AND entry_id = %d AND share_id = 0", (int) $entry_id ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- table name from $wpdb->prefix.
	return $row ? $row : null;
}

/**
 * Does the entry have a complete stored photo? A row exists only after both files
 * were written, and the file is checked too, so an interrupted upload never counts.
 *
 * @param int $entry_id Entry.
 * @return bool
 */
function jlife_challenges_entry_has_photo( $entry_id ) {
	$row = jlife_challenges_entry_photo( (int) $entry_id );
	if ( ! $row ) {
		return false;
	}
	$path = jlife_challenges_photo_path( (string) $row['file'] );
	return '' !== $path && is_file( $path ) && filesize( $path ) > 0;
}

/**
 * The photo of the author's own entry for a day, by user, campaign and day (fresh from
 * the database, for the form).
 *
 * @param int    $user_id     Author.
 * @param int    $campaign_id Campaign.
 * @param string $day_key     Day.
 * @return array|null
 */
function jlife_challenges_day_photo( $user_id, $campaign_id, $day_key ) {
	$entry = jlife_challenges_find_entry( (int) $user_id, (int) $campaign_id, (string) $day_key );
	return $entry && jlife_challenges_entry_has_photo( (int) $entry['id'] ) ? jlife_challenges_entry_photo( (int) $entry['id'] ) : null;
}

/**
 * Store a processed photo as the row for (kind, entry, share): insert, or replace the
 * one that is there. The old files are deleted only after the row points at the new
 * ones; if another request won a race, the files written here are deleted instead.
 *
 * @param string $kind     entry|share.
 * @param array  $ctx      { entry_id, share_id, user_id, campaign_id, day_key }.
 * @param string $full     Full-size JPEG bytes.
 * @param string $thumb    Thumbnail JPEG bytes.
 * @return array|WP_Error The stored row (+ replaced: bool).
 */
function jlife_challenges_photo_store( $kind, $ctx, $full, $thumb ) {
	$kind     = 'share' === $kind ? 'share' : 'entry';
	$entry_id = (int) $ctx['entry_id'];
	$share_id = 'share' === $kind ? (int) $ctx['share_id'] : 0;
	$old      = jlife_challenges_photo_row_for( $kind, $entry_id, $share_id );
	$names    = jlife_challenges_photo_write_files( $full, $thumb );
	if ( is_wp_error( $names ) ) {
		return $names;
	}
	global $wpdb;
	$t   = jlife_challenges_table( 'photos' );
	$now = current_time( 'mysql', true );
	// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- custom table; name from $wpdb->prefix; UNIQUE (kind, entry_id, share_id) makes this an upsert.
	$ok = $wpdb->query(
		$wpdb->prepare(
			"INSERT INTO {$t} (kind, entry_id, share_id, user_id, campaign_id, day_key, file, thumb, bytes, created, updated)
			VALUES (%s, %d, %d, %d, %d, %s, %s, %s, %d, %s, %s)
			ON DUPLICATE KEY UPDATE file = VALUES(file), thumb = VALUES(thumb), bytes = VALUES(bytes), updated = VALUES(updated)",
			$kind,
			$entry_id,
			$share_id,
			(int) $ctx['user_id'],
			(int) $ctx['campaign_id'],
			(string) $ctx['day_key'],
			$names['file'],
			$names['thumb'],
			strlen( (string) $full ),
			$now,
			$now
		)
	);
	// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	$row = false === $ok ? null : jlife_challenges_photo_row_for( $kind, $entry_id, $share_id );
	if ( ! $row || $row['file'] !== $names['file'] ) {
		// The write failed, or a concurrent request replaced the row first: keep that one, drop these files.
		jlife_challenges_photo_delete_files( $names );
		return $row ? $row : jlife_challenges_photo_error( 'jlife_photo_failed' );
	}
	if ( $old && $old['file'] !== $row['file'] ) {
		jlife_challenges_photo_delete_files( $old );
	}
	$row['replaced'] = (bool) $old;
	return $row;
}

/**
 * The row for (kind, entry, share), or null.
 *
 * @param string $kind     entry|share.
 * @param int    $entry_id Entry.
 * @param int    $share_id Share (0 for an entry photo).
 * @return array|null
 */
function jlife_challenges_photo_row_for( $kind, $entry_id, $share_id ) {
	global $wpdb;
	$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . jlife_challenges_table( 'photos' ) . ' WHERE kind = %s AND entry_id = %d AND share_id = %d', (string) $kind, (int) $entry_id, (int) $share_id ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- table name from $wpdb->prefix.
	return $row ? $row : null;
}

/**
 * The checks every photo write shares: the person writes in this campaign, it takes
 * writes and accepts photos, and the day is one they may write.
 *
 * @param int         $user_id     Author.
 * @param int         $campaign_id Campaign (0 = personal: no photos).
 * @param string|null $day_key     Day.
 * @param bool        $writes      Require that the campaign still takes writes (false for removal).
 * @return array|WP_Error The resolved day (jlife_challenges_resolve_day()).
 */
function jlife_challenges_photo_context( $user_id, $campaign_id, $day_key, $writes = true ) {
	if ( (int) $campaign_id < 1 || ! jlife_challenges_can_write_entry( $user_id, $campaign_id ) ) {
		return jlife_challenges_denied();
	}
	$campaign = jlife_challenges_get_campaign( (int) $campaign_id );
	if ( ! $campaign ) {
		return jlife_challenges_denied();
	}
	if ( $writes && ! jlife_challenges_campaign_accepts_writes( $campaign ) ) {
		return jlife_challenges_campaign_closed_error();
	}
	if ( ! jlife_challenges_campaign_accepts_photos( $campaign ) ) {
		return jlife_challenges_photo_error( 'jlife_photos_off' );
	}
	return jlife_challenges_resolve_day( $campaign_id, $day_key, $user_id );
}

/**
 * Add or replace the photo of the author's own entry (the draft, or a submitted entry).
 * Never scores: only Submit does (jlife_challenges_submit()).
 *
 * @param int         $user_id     Author.
 * @param int         $campaign_id Campaign.
 * @param string|null $day_key     Day (null = today).
 * @param array       $file        One PHP upload ($_FILES entry: tmp_name, error).
 * @return array|WP_Error { photo_id, replaced }
 */
function jlife_challenges_photo_attach( $user_id, $campaign_id, $day_key, $file ) {
	$day = jlife_challenges_photo_context( $user_id, $campaign_id, $day_key );
	if ( is_wp_error( $day ) ) {
		return $day;
	}
	$error = (int) ( $file['error'] ?? UPLOAD_ERR_NO_FILE );
	if ( UPLOAD_ERR_OK !== $error ) {
		if ( UPLOAD_ERR_NO_FILE === $error ) {
			return jlife_challenges_photo_error( 'jlife_photo_none' );
		}
		return jlife_challenges_photo_error( in_array( $error, array( UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE ), true ) ? 'jlife_photo_big' : 'jlife_photo_failed' );
	}
	$processed = jlife_challenges_photo_process( (string) ( $file['tmp_name'] ?? '' ) );
	if ( is_wp_error( $processed ) ) {
		return $processed;
	}
	$entry = jlife_challenges_ensure_entry( $user_id, $campaign_id, $day['day_key'] );
	if ( ! $entry ) {
		return jlife_challenges_photo_error( 'jlife_photo_failed' );
	}
	$row = jlife_challenges_photo_store(
		'entry',
		array(
			'entry_id'    => (int) $entry['id'],
			'share_id'    => 0,
			'user_id'     => (int) $user_id,
			'campaign_id' => (int) $campaign_id,
			'day_key'     => $day['day_key'],
		),
		$processed['full'],
		$processed['thumb']
	);
	if ( is_wp_error( $row ) ) {
		return $row;
	}
	if ( ! empty( $row['replaced'] ) && 'submitted' === $entry['status'] ) {
		// The point and its source were written at Submit and are never touched; this only records that the picture changed (IDs only).
		jlife_challenges_audit( $user_id, 'photo_replaced', 'photo', (int) $row['id'], (int) $campaign_id, array( 'entry_id' => (int) $entry['id'] ) );
	}
	return array(
		'photo_id' => (int) $row['id'],
		'replaced' => ! empty( $row['replaced'] ),
	);
}

/**
 * Remove the photo of the author's own entry. The point (if the photo earned it)
 * stays: a point is never taken back. A share copy already made is a frozen
 * snapshot and stays until the author removes that share. Allowed in a closed
 * campaign too (it only reduces what is stored).
 *
 * @param int         $user_id     Author.
 * @param int         $campaign_id Campaign.
 * @param string|null $day_key     Day (null = today).
 * @return true|WP_Error
 */
function jlife_challenges_photo_remove( $user_id, $campaign_id, $day_key ) {
	$day = jlife_challenges_photo_context( $user_id, $campaign_id, $day_key, false );
	if ( is_wp_error( $day ) ) {
		return $day;
	}
	$entry = jlife_challenges_find_entry( $user_id, $campaign_id, $day['day_key'] );
	$row   = $entry ? jlife_challenges_entry_photo( (int) $entry['id'] ) : null;
	if ( ! $row ) {
		return true;
	}
	global $wpdb;
	$wpdb->delete( jlife_challenges_table( 'photos' ), array( 'id' => (int) $row['id'] ), array( '%d' ) );
	jlife_challenges_photo_delete_files( $row );
	if ( 'submitted' === $entry['status'] ) {
		jlife_challenges_audit( $user_id, 'photo_removed', 'photo', (int) $row['id'], (int) $campaign_id, array( 'entry_id' => (int) $entry['id'] ) );
	}
	return true;
}

/**
 * Make (or replace) a group share's frozen copy of the entry's photo, or remove the
 * copy when the entry has no photo now. Called by jlife_challenges_share() after the
 * share row is written, so the copy always matches what was shared on that tick.
 *
 * @param int   $share_id Share.
 * @param array $entry    Entry row.
 * @param bool  $with     Share the photo (the campaign accepts photos and the entry has one).
 * @return bool Whether the share now has a photo copy.
 */
function jlife_challenges_photo_sync_share( $share_id, $entry, $with ) {
	if ( ! $with ) {
		jlife_challenges_delete_share_photo( (int) $share_id );
		return false;
	}
	$source = jlife_challenges_entry_photo( (int) $entry['id'] );
	$full   = $source ? jlife_challenges_photo_path( (string) $source['file'] ) : '';
	$thumb  = $source ? jlife_challenges_photo_path( (string) $source['thumb'] ) : '';
	if ( '' === $full || '' === $thumb || ! is_file( $full ) || ! is_file( $thumb ) ) {
		jlife_challenges_delete_share_photo( (int) $share_id );
		return false;
	}
	$row = jlife_challenges_photo_store(
		'share',
		array(
			'entry_id'    => (int) $entry['id'],
			'share_id'    => (int) $share_id,
			'user_id'     => (int) $entry['user_id'],
			'campaign_id' => (int) $entry['campaign_id'],
			'day_key'     => (string) $entry['day_key'],
		),
		(string) file_get_contents( $full ),
		(string) file_get_contents( $thumb )
	);
	return ! is_wp_error( $row );
}

/**
 * Delete a share's photo copy (files and row).
 *
 * @param int $share_id Share.
 * @return int Rows deleted.
 */
function jlife_challenges_delete_share_photo( $share_id ) {
	return jlife_challenges_delete_photos( array( 'share_id' => (int) $share_id ) );
}

/**
 * Delete photos (files first, then rows) for a person, one or more campaigns, one
 * entry or one share. At least one filter is required, so nothing deletes "all".
 * The one function every retention path uses: account deletion, test-campaign
 * removal and a campaign purge (#186).
 *
 * @param array $where Any of: user_id (int), campaign_ids (int[]), entry_id (int), share_id (int).
 * @return int Rows deleted.
 */
function jlife_challenges_delete_photos( $where ) {
	global $wpdb;
	$t      = jlife_challenges_table( 'photos' );
	$parts  = array();
	$values = array();
	if ( ! empty( $where['user_id'] ) ) {
		$parts[]  = 'user_id = %d';
		$values[] = (int) $where['user_id'];
	}
	if ( ! empty( $where['entry_id'] ) ) {
		$parts[]  = 'entry_id = %d';
		$values[] = (int) $where['entry_id'];
	}
	if ( ! empty( $where['share_id'] ) ) {
		$parts[]  = 'share_id = %d';
		$values[] = (int) $where['share_id'];
	}
	$ids = array_values( array_filter( array_map( 'intval', (array) ( $where['campaign_ids'] ?? array() ) ) ) );
	if ( $ids ) {
		$parts[] = 'campaign_id IN (' . implode( ',', $ids ) . ')'; // Integers only.
	}
	if ( ! $parts ) {
		return 0;
	}
	$sql  = "SELECT * FROM {$t} WHERE " . implode( ' AND ', $parts );
	$rows = $values ? $wpdb->get_results( $wpdb->prepare( $sql, $values ), ARRAY_A ) : $wpdb->get_results( $sql, ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from $wpdb->prefix; integers only; values prepared.
	$n    = 0;
	foreach ( (array) $rows as $row ) {
		jlife_challenges_photo_delete_files( $row );
		$wpdb->delete( $t, array( 'id' => (int) $row['id'] ), array( '%d' ) );
		++$n;
	}
	return $n;
}

/**
 * Delete every photo of one campaign: the hook for the pilot purge (#186) and any
 * campaign removal.
 *
 * @param int $campaign_id Campaign.
 * @return int Rows deleted.
 */
function jlife_challenges_delete_campaign_photos( $campaign_id ) {
	return jlife_challenges_delete_photos( array( 'campaign_ids' => array( (int) $campaign_id ) ) );
}

/**
 * The share-copy photo IDs for some shares, keyed by share ID.
 *
 * @param int[] $share_ids Share IDs.
 * @return array<int,int> Share ID => photo ID.
 */
function jlife_challenges_share_photo_ids( $share_ids ) {
	$ids = array_values( array_filter( array_map( 'intval', (array) $share_ids ) ) );
	if ( ! $ids ) {
		return array();
	}
	global $wpdb;
	$rows = (array) $wpdb->get_results( 'SELECT id, share_id FROM ' . jlife_challenges_table( 'photos' ) . " WHERE kind = 'share' AND share_id IN (" . implode( ',', $ids ) . ')', ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from $wpdb->prefix; integers only.
	$out  = array();
	foreach ( $rows as $r ) {
		$out[ (int) $r['share_id'] ] = (int) $r['id'];
	}
	return $out;
}

/**
 * The address of a photo (the access-checked route, never a file URL).
 *
 * @param int  $photo_id Photo.
 * @param bool $thumb    The small version.
 * @return string
 */
function jlife_challenges_photo_url( $photo_id, $thumb = false ) {
	$url = home_url( '/photo/' . (int) $photo_id . '/' );
	return $thumb ? add_query_arg( 'size', 'thumb', $url ) : $url;
}

/**
 * The file a viewer may be sent for a photo, or null for every refusal (no such
 * photo, not allowed, file gone): the caller answers all of them the same way.
 *
 * @param int    $viewer_user_id Viewer.
 * @param int    $photo_id       Photo.
 * @param string $size           'thumb' for the small version, anything else is full size.
 * @return string|null Path.
 */
function jlife_challenges_photo_file_for( $viewer_user_id, $photo_id, $size = '' ) {
	$row = jlife_challenges_find_photo( $photo_id );
	if ( ! $row || ! jlife_challenges_can_read_photo( $viewer_user_id, $row ) ) {
		return null;
	}
	$path = jlife_challenges_photo_path( (string) ( 'thumb' === $size ? $row['thumb'] : $row['file'] ) );
	return '' !== $path && is_file( $path ) ? $path : null;
}

/**
 * /photo/<id>/: send the image if the viewer may read it, otherwise the same bare
 * 404 for everything (signed out, not yours, not your group, gone, never existed).
 * Never cached; never indexed.
 */
function jlife_challenges_screen_photo() {
	$photo_id = absint( get_query_var( 'jlife_photo' ) );
	$size     = isset( $_GET['size'] ) && 'thumb' === sanitize_key( wp_unslash( $_GET['size'] ) ) ? 'thumb' : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only; the gate decides.
	$path     = jlife_challenges_photo_file_for( get_current_user_id(), $photo_id, $size );
	nocache_headers();
	header( 'Cache-Control: private, no-store, no-cache, must-revalidate, max-age=0' );
	header( 'X-Robots-Tag: noindex, noarchive' );
	header( 'X-Content-Type-Options: nosniff' );
	header( 'Referrer-Policy: no-referrer' );
	if ( null === $path ) {
		status_header( 404 );
		header( 'Content-Type: text/plain; charset=utf-8' );
		echo 'Not found';
		exit;
	}
	status_header( 200 );
	header( 'Content-Type: image/jpeg' );
	header( 'Content-Disposition: inline; filename="photo.jpg"' );
	header( 'Content-Length: ' . (int) filesize( $path ) );
	while ( ob_get_level() > 0 ) {
		ob_end_clean();
	}
	readfile( $path );
	exit;
}

/**
 * Photo-earned days of one participant, for the owner's review by download.
 *
 * @param int $user_id     Participant.
 * @param int $campaign_id Campaign (0 = every campaign).
 * @return array[] Rows of { campaign_id, day_key, entry_id, photo_id, file, uploaded } (file/uploaded empty if the photo was removed).
 */
function jlife_challenges_photo_earned_days( $user_id, $campaign_id = 0 ) {
	global $wpdb;
	$l     = jlife_challenges_table( 'score_ledger' );
	$p     = jlife_challenges_table( 'photos' );
	$where = $wpdb->prepare( "l.user_id = %d AND l.submission_type = 'photo' AND l.status = 'earned'", (int) $user_id );
	if ( (int) $campaign_id > 0 ) {
		$where .= $wpdb->prepare( ' AND l.campaign_id = %d', (int) $campaign_id );
	}
	// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- custom tables; names from $wpdb->prefix; values prepared above.
	$rows = $wpdb->get_results( "SELECT l.campaign_id, l.day_key, l.entry_id, p.id AS photo_id, p.file, p.updated AS uploaded FROM {$l} l LEFT JOIN {$p} p ON p.kind = 'entry' AND p.entry_id = l.entry_id AND p.share_id = 0 WHERE {$where} ORDER BY l.campaign_id, l.day_key", ARRAY_A );
	// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	return is_array( $rows ) ? $rows : array();
}

/**
 * Write one participant's photo-earned days to a zip in the owner-only folder (the
 * owner's review, decision 4 on #244): dNNN.jpg per day (c<id>/dNNN.jpg when more
 * than one campaign) and days.csv (campaign, day, date, upload time UTC, submission
 * type, file). Counts and the path are the only things returned or audited.
 *
 * @param int    $actor       Organizer.
 * @param int    $user_id     Participant.
 * @param int    $campaign_id Campaign (0 = all of the person's campaigns).
 * @param string $dir         Folder for the zip (default ~/.latoc, created 0700).
 * @return array|WP_Error { path, days, photos, missing }
 */
function jlife_challenges_photos_export( $actor, $user_id, $campaign_id = 0, $dir = '' ) {
	if ( ! jlife_challenges_is_organizer( $actor ) || ! get_userdata( (int) $user_id ) ) {
		return jlife_challenges_denied();
	}
	if ( (int) $campaign_id > 0 && ! jlife_challenges_get_campaign( (int) $campaign_id ) ) {
		return jlife_challenges_denied();
	}
	if ( ! class_exists( 'ZipArchive' ) ) {
		return new WP_Error( 'jlife_zip_missing', 'This server has no zip support (PHP ZipArchive).' );
	}
	$days = jlife_challenges_photo_earned_days( (int) $user_id, (int) $campaign_id );
	if ( ! $days ) {
		return new WP_Error( 'jlife_photos_none', 'This participant has no photo-earned days.' );
	}
	if ( '' === $dir ) {
		$home = (string) getenv( 'HOME' );
		if ( '' === $home ) {
			return new WP_Error( 'jlife_export_dir', 'Cannot find the home folder; pass --dir.' );
		}
		$dir = $home . '/.latoc';
	}
	$dir = untrailingslashit( $dir );
	if ( ! is_dir( $dir ) && ! mkdir( $dir, 0700, true ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir -- owner-only folder.
		return new WP_Error( 'jlife_export_dir', 'Cannot create the export folder.' );
	}
	$multi = count( array_unique( array_column( $days, 'campaign_id' ) ) ) > 1;
	$path  = $dir . '/photos-u' . (int) $user_id . '-c' . ( (int) $campaign_id > 0 ? (int) $campaign_id : 'all' ) . '-' . gmdate( 'Ymd-His' ) . '.zip';
	$old   = umask( 0077 );
	$zip   = new ZipArchive();
	$open  = $zip->open( $path, ZipArchive::CREATE | ZipArchive::EXCL );
	if ( true !== $open ) {
		umask( $old );
		return new WP_Error( 'jlife_export_dir', 'Cannot create the zip file.' );
	}
	$csv = fopen( 'php://temp', 'w+' );
	if ( false === $csv ) {
		$zip->close();
		wp_delete_file( $path );
		umask( $old );
		return new WP_Error( 'jlife_export_dir', 'Could not write the zip file.' );
	}
	fputcsv( $csv, array( 'campaign_id', 'day', 'date', 'upload_time_utc', 'submission_type', 'file' ), ',', '"', '' );
	$camps   = array();
	$photos  = 0;
	$missing = 0;
	foreach ( $days as $d ) {
		$cid = (int) $d['campaign_id'];
		if ( ! isset( $camps[ $cid ] ) ) {
			$camps[ $cid ] = jlife_challenges_get_campaign( $cid );
		}
		$index  = $camps[ $cid ] ? jlife_challenges_day_index( $camps[ $cid ], (string) $d['day_key'] ) : 0;
		$source = '' !== (string) $d['file'] ? jlife_challenges_photo_path( (string) $d['file'] ) : '';
		$name   = '';
		if ( '' !== $source && is_file( $source ) && 1 === preg_match( '/^d\d{3}$/', (string) $d['day_key'] ) ) {
			$name = ( $multi ? 'c' . $cid . '/' : '' ) . $d['day_key'] . '.jpg';
			$zip->addFile( $source, $name );
			++$photos;
		} else {
			++$missing;
		}
		fputcsv(
			$csv,
			array(
				$cid,
				(string) $d['day_key'],
				$index > 0 ? jlife_challenges_day_date( $camps[ $cid ], $index ) : '',
				'' !== $name ? (string) $d['uploaded'] : '',
				'photo',
				$name,
			),
			',',
			'"',
			''
		);
	}
	rewind( $csv );
	$zip->addFromString( 'days.csv', (string) stream_get_contents( $csv ) );
	fclose( $csv );
	$closed = $zip->close();
	umask( $old );
	if ( ! $closed || ! is_file( $path ) ) {
		return new WP_Error( 'jlife_export_dir', 'Could not write the zip file.' );
	}
	chmod( $path, 0600 );
	jlife_challenges_audit(
		$actor,
		'photos_exported',
		'user',
		(int) $user_id,
		(int) $campaign_id,
		array(
			'days'   => count( $days ),
			'photos' => $photos,
		)
	);
	return array(
		'path'    => $path,
		'days'    => count( $days ),
		'photos'  => $photos,
		'missing' => $missing,
	);
}
