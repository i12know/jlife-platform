<?php
/**
 * The devotional Markdown importer (#211): convert, preview (no writes), import.
 *
 * A file is named `D001-EN_Any-Title.md` or `D001-VI_Any-Title.md`; the number is
 * the day of the reading plan. Markdown is converted with Parsedown in safe mode
 * (raw HTML is escaped, unsafe link schemes are dropped) and then wp_kses_post().
 * Images are not supported in this version: they are removed and reported.
 *
 * The structure the converter knows: `#` the title (becomes the post title),
 * `##` the Scripture line, paragraphs, bold, italic, links, lists, and a `###`
 * heading whose words say reflection, questions or discussion (English or
 * Vietnamese) starts the questions section. Its lines are recorded for #210's
 * excluded-text filter. All of it is one rich body.
 *
 * Statuses: a new post is a Draft and import never changes a post's status. An
 * existing post whose current title and content differ from what the last import
 * left in it was edited in the block editor, so it is skipped unless the person
 * ticks "Overwrite my edits". This code never reads or writes a Reading Day post,
 * and the reading-plan import never reads or writes a devotional.
 *
 * Nothing here logs a file name or any content; the audit log records counts.
 *
 * @package jlife-challenges
 */

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'Parsedown' ) ) {
	require_once dirname( __DIR__ ) . '/lib/parsedown/Parsedown.php';
}

const JLIFE_DEVOTIONAL_MAX_BYTES = 204800;
const JLIFE_DEVOTIONAL_MAX_FILES = 250;

/**
 * Words (English or Vietnamese) that make a `###` heading the questions heading.
 */
const JLIFE_DEVOTIONAL_QUESTIONS_HEADING = '/question|reflect|discussion|suy\s*ngẫm|suy\s*gẫm|câu\s*hỏi|thảo\s*luận/iu';

/**
 * The reading plan devotionals belong to: the plan of the campaign an organizer
 * screen shows by default (the current one). Filterable.
 *
 * @return string Plan ID, or '' when there is none.
 */
function jlife_challenges_devotional_plan_id() {
	$plan      = '';
	$campaigns = jlife_challenges_admin_campaigns();
	$chosen    = jlife_challenges_admin_default_campaign( $campaigns, 0 );
	if ( $chosen ) {
		$campaign = jlife_challenges_get_campaign( (int) $chosen['id'] );
		$plan     = is_array( $campaign ) ? (string) $campaign['plan_id'] : '';
	}
	/**
	 * Filters the reading plan devotionals are imported for.
	 *
	 * @param string $plan Plan ID.
	 */
	return (string) apply_filters( 'jlife_challenges_devotional_plan_id', $plan );
}

/**
 * The plan's days, in order, for the grid and for checking a file's day.
 *
 * @param string $plan_id Plan ID.
 * @return array<string,string> Day ID => the day's English title.
 */
function jlife_challenges_devotional_plan_days( $plan_id ) {
	if ( '' === (string) $plan_id || ! defined( 'JLIFE_READING_DAY_CPT' ) ) {
		return array();
	}
	$posts = get_posts(
		array(
			'post_type'        => JLIFE_READING_DAY_CPT,
			'post_status'      => 'any',
			'posts_per_page'   => -1,
			'orderby'          => 'meta_value_num',
			'meta_key'         => '_jlife_day', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- one small query for the grid; orders by the imported day number.
			'order'            => 'ASC',
			'suppress_filters' => true,
			'meta_query'       => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- as above.
				array(
					'key'   => '_jlife_plan_id',
					'value' => (string) $plan_id,
				),
			),
		)
	);
	$days  = array();
	foreach ( $posts as $post ) {
		$day_id = (string) get_post_meta( $post->ID, '_jlife_day_id', true );
		if ( '' !== $day_id ) {
			$days[ $day_id ] = (string) $post->post_title;
		}
	}
	return $days;
}

/**
 * Day and language from a file name such as D001-EN_Any-Title.md.
 *
 * @param string $name File name as uploaded.
 * @return array|null { day_id: string, lang: string }, or null when the name does not fit.
 */
function jlife_challenges_devotional_parse_filename( $name ) {
	if ( ! preg_match( '/^D([0-9]{1,3})-(EN|VI)(?:[_ -].*)?\.md$/i', wp_basename( (string) $name ), $m ) || (int) $m[1] < 1 ) {
		return null;
	}
	return array(
		'day_id' => sprintf( 'd%03d', (int) $m[1] ),
		'lang'   => strtolower( $m[2] ),
	);
}

/**
 * Hash of a devotional's title and body, to tell whether it was edited.
 *
 * @param string $title Post title.
 * @param string $html  Post content.
 * @return string
 */
function jlife_challenges_devotional_hash( $title, $html ) {
	return hash( 'sha256', (string) $title . "\n" . (string) $html );
}

/**
 * A Parsedown in safe mode: raw HTML is escaped and unsafe link schemes are
 * neutralised.
 *
 * @return Parsedown
 */
function jlife_challenges_devotional_parsedown() {
	$parser = new Parsedown();
	$parser->setSafeMode( true );
	return $parser;
}

/**
 * Plain text of one Markdown line (emphasis, links and markers removed).
 *
 * @param Parsedown $parser Parser.
 * @param string    $line   A line of Markdown.
 * @return string
 */
function jlife_challenges_devotional_plain_line( $parser, $line ) {
	$line = (string) preg_replace( '/^\s*(?:[-*+]|[0-9]+[.)])\s+/u', '', $line );
	$text = html_entity_decode( wp_strip_all_tags( $parser->line( $line ) ), ENT_QUOTES, 'UTF-8' );
	return trim( (string) preg_replace( '/\s+/u', ' ', $text ) );
}

/**
 * Turn the converter's two placeholder paragraphs into the marked questions
 * section (#225): `<section class="jl-reflection-questions" aria-labelledby="ID">`
 * around the heading and its lines, with a unique id on the heading. The section
 * is wrapped in a Custom HTML block comment so the block editor keeps it as is.
 * Placeholders that cannot be paired (a heading inside a code block) are removed
 * and the content stays unmarked, which falls back to the stored questions.
 *
 * @param string   $html      Parsedown's HTML, holding the placeholder paragraphs.
 * @param string   $tok_open  Opening placeholder.
 * @param string   $tok_close Closing placeholder.
 * @param string   $title     Devotional title (to make the id differ per devotional).
 * @param string[] $questions Question lines found.
 * @return string Filtered HTML.
 */
function jlife_challenges_devotional_mark_questions( $html, $tok_open, $tok_close, $title, array $questions ) {
	$open  = '<p>' . $tok_open . '</p>';
	$close = '<p>' . $tok_close . '</p>';
	if ( $questions && 1 === substr_count( $html, $open ) && 1 === substr_count( $html, $close ) && strpos( $html, $open ) < strpos( $html, $close ) ) {
		$id   = 'jl-rq-' . substr( hash( 'sha256', (string) $title . implode( '|', $questions ) ), 0, 10 );
		$html = str_replace( $open, '<!-- wp:html --><section class="jl-reflection-questions" aria-labelledby="' . $id . '">', $html );
		$html = str_replace( $close, '</section><!-- /wp:html -->', $html );
		$html = (string) preg_replace( '/(<section class="jl-reflection-questions"[^>]*>\s*)<(h[1-6])>/', '$1<$2 id="' . $id . '">', $html, 1 );
	} else {
		$html = str_replace( array( $open, $close ), '', $html );
	}
	return trim( wp_kses_post( $html ) );
}

/**
 * Convert one Markdown document.
 *
 * @param string $markdown UTF-8 Markdown.
 * @return array { title: string, html: string, questions: string[], warnings: array[] } where each warning is { code: string, count: int }.
 */
function jlife_challenges_devotional_convert( $markdown ) {
	$text = str_replace( array( "\r\n", "\r" ), "\n", (string) $markdown );
	$text = (string) preg_replace( '/^\xEF\xBB\xBF/', '', $text );
	if ( class_exists( 'Normalizer' ) ) {
		$normal = Normalizer::normalize( $text, Normalizer::FORM_C );
		$text   = is_string( $normal ) ? $normal : $text;
	}

	// Images are not supported yet: drop them (and Google Docs' embedded image data) and say so.
	$images = 0;
	$text   = (string) preg_replace( array( '/!\[[^\]]*\]\([^)]*\)/u', '/!\[[^\]]*\]\[[^\]]*\]/u', '/<img\b[^>]*>/iu' ), '', $text, -1, $images );
	$text   = (string) preg_replace( '/^\[[^\]]+\]:\s*<?data:image[^\n]*$/mu', '', $text );

	$parser    = jlife_challenges_devotional_parsedown();
	$title     = '';
	$body      = array();
	$questions = array();
	$in_q      = false;
	$marked    = false;
	$in_marked = false;
	$tok_open  = 'JLRQOPEN' . wp_generate_password( 12, false );
	$tok_close = 'JLRQCLOSE' . wp_generate_password( 12, false );
	foreach ( explode( "\n", $text ) as $line ) {
		if ( preg_match( '/^(#{1,6})[ \t]+(.+?)[ \t]*#*[ \t]*$/u', $line, $h ) ) {
			$level = strlen( $h[1] );
			if ( 1 === $level && '' === $title ) {
				$title = jlife_challenges_devotional_plain_line( $parser, $h[2] );
				continue;
			}
			if ( $level <= 3 ) {
				if ( $in_marked ) {
					array_push( $body, '', $tok_close, '' );
					$in_marked = false;
				}
				$in_q = 3 === $level && (bool) preg_match( JLIFE_DEVOTIONAL_QUESTIONS_HEADING, $h[2] );
				if ( $in_q && ! $marked ) {
					// The first questions heading is the marked section (#225).
					$in_marked = true;
					$marked    = true;
					array_push( $body, '', $tok_open, '' );
				}
			}
		} elseif ( $in_q && '' !== trim( $line ) ) {
			$plain = jlife_challenges_devotional_plain_line( $parser, $line );
			if ( '' !== $plain ) {
				$questions[] = $plain;
			}
		}
		$body[] = $line;
	}
	if ( $in_marked ) {
		array_push( $body, '', $tok_close, '' );
	}

	$html     = jlife_challenges_devotional_mark_questions( $parser->text( implode( "\n", $body ) ), $tok_open, $tok_close, $title, $questions );
	$warnings = array();
	if ( $images > 0 ) {
		$warnings[] = array(
			'code'  => 'images_dropped',
			'count' => $images,
		);
	}
	if ( ! $questions ) {
		$warnings[] = array(
			'code'  => 'no_questions',
			'count' => 0,
		);
	}
	if ( '' === $title ) {
		$warnings[] = array(
			'code'  => 'no_title',
			'count' => 0,
		);
	}
	return array(
		'title'     => $title,
		'html'      => $html,
		'questions' => $questions,
		'warnings'  => $warnings,
	);
}

/**
 * An item with the converted text filled in.
 *
 * @param array $item      Item with a name, day and language.
 * @param array $converted Result of jlife_challenges_devotional_convert().
 * @param array $id        { day_id, lang } from the file name.
 * @return array
 */
function jlife_challenges_devotional_with_text( $item, $converted, $id ) {
	$title = '' !== $converted['title'] ? $converted['title'] : $id['day_id'] . ' ' . strtoupper( $id['lang'] );
	return array_merge(
		$item,
		array(
			'title'     => $title,
			'html'      => $converted['html'],
			'questions' => $converted['questions'],
			'warnings'  => $converted['warnings'],
			'src_hash'  => jlife_challenges_devotional_hash( $title, $converted['html'] ),
		)
	);
}

/**
 * Read and convert uploaded files. Nothing is written and no upload is kept.
 *
 * @param array[] $files   Rows of { name, tmp_name, size, error } (the shape of $_FILES after flattening).
 * @param string  $plan_id Reading plan the day numbers refer to.
 * @return array[] One item per file: { file, day_id, lang, title, html, questions, warnings, src_hash, error } where error is '' when the file is readable, else bad_name, too_large, upload_error, not_utf8, empty, unknown_day or duplicate.
 */
function jlife_challenges_devotional_read_files( array $files, $plan_id ) {
	$items = array();
	$seen  = array();
	$days  = jlife_challenges_devotional_plan_days( $plan_id );
	foreach ( array_slice( $files, 0, JLIFE_DEVOTIONAL_MAX_FILES ) as $f ) {
		$name = wp_basename( (string) ( $f['name'] ?? '' ) );
		$id   = jlife_challenges_devotional_parse_filename( $name );
		$item = array(
			'file'      => $name,
			'day_id'    => '',
			'lang'      => '',
			'title'     => '',
			'html'      => '',
			'questions' => array(),
			'warnings'  => array(),
			'src_hash'  => '',
			'error'     => '',
		);
		if ( UPLOAD_ERR_NO_FILE === (int) ( $f['error'] ?? 0 ) ) {
			continue;
		}
		if ( null === $id ) {
			$item['error'] = '.md' === strtolower( substr( $name, -3 ) ) ? 'bad_name' : 'not_md';
			$items[]       = $item;
			continue;
		}
		$item['day_id'] = $id['day_id'];
		$item['lang']   = $id['lang'];
		$text           = '';
		$key            = $id['day_id'] . '/' . $id['lang'];
		if ( UPLOAD_ERR_OK !== (int) ( $f['error'] ?? 0 ) || '' === (string) ( $f['tmp_name'] ?? '' ) ) {
			$item['error'] = UPLOAD_ERR_INI_SIZE === (int) ( $f['error'] ?? 0 ) || UPLOAD_ERR_FORM_SIZE === (int) ( $f['error'] ?? 0 ) ? 'too_large' : 'upload_error';
		} elseif ( (int) ( $f['size'] ?? 0 ) > JLIFE_DEVOTIONAL_MAX_BYTES || (int) filesize( (string) $f['tmp_name'] ) > JLIFE_DEVOTIONAL_MAX_BYTES ) {
			$item['error'] = 'too_large';
		} else {
			$text = (string) file_get_contents( (string) $f['tmp_name'] ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- an uploaded temporary file, read once and never kept.
			if ( ! mb_check_encoding( $text, 'UTF-8' ) ) {
				$item['error'] = 'not_utf8';
			}
		}
		if ( '' === $item['error'] && ! isset( $days[ $id['day_id'] ] ) ) {
			$item['error'] = 'unknown_day';
		}
		if ( '' === $item['error'] && isset( $seen[ $key ] ) ) {
			$item['error'] = 'duplicate';
		}
		if ( '' === $item['error'] ) {
			$converted = jlife_challenges_devotional_convert( $text );
			if ( '' === $converted['html'] ) {
				$item['error'] = 'empty';
			} else {
				$seen[ $key ] = true;
				$item         = jlife_challenges_devotional_with_text( $item, $converted, $id );
			}
		}
		$items[] = $item;
	}
	return $items;
}

/**
 * Has the post been edited since the last import (its title or content differ
 * from what that import left in it)?
 *
 * @param WP_Post $post Devotional post.
 * @return bool
 */
function jlife_challenges_devotional_is_edited( $post ) {
	return jlife_challenges_devotional_hash( $post->post_title, $post->post_content ) !== (string) get_post_meta( $post->ID, '_jlife_dev_import_hash', true );
}

/**
 * What an import would do with one readable item. Writes nothing.
 *
 * @param array  $item      Item from jlife_challenges_devotional_read_files().
 * @param string $plan_id   Reading plan.
 * @param bool   $overwrite Replace posts edited in the editor.
 * @return array { state: new|changed|unchanged|skipped_edited|skipped_trash, post_id: int }
 */
function jlife_challenges_devotional_item_state( $item, $plan_id, $overwrite = false ) {
	$post = jlife_challenges_devotional_find( $plan_id, $item['day_id'], $item['lang'] );
	if ( ! $post ) {
		return array(
			'state'   => 'new',
			'post_id' => 0,
		);
	}
	$same = (string) get_post_meta( $post->ID, '_jlife_dev_src_hash', true ) === $item['src_hash'];
	if ( 'trash' === $post->post_status ) {
		$state = 'skipped_trash';
	} elseif ( jlife_challenges_devotional_is_edited( $post ) ) {
		$state = $overwrite ? 'changed' : 'skipped_edited';
	} else {
		$state = $same ? 'unchanged' : 'changed';
	}
	return array(
		'state'   => $state,
		'post_id' => (int) $post->ID,
	);
}

/**
 * The preview: every item with the state an import would give it. Writes nothing.
 *
 * @param array[] $items     Items from jlife_challenges_devotional_read_files().
 * @param string  $plan_id   Reading plan.
 * @param bool    $overwrite Replace posts edited in the editor.
 * @return array[] The items, each with `state` ('unreadable' when it has an error) and `post_id`.
 */
function jlife_challenges_devotional_preview( array $items, $plan_id, $overwrite = false ) {
	foreach ( $items as $i => $item ) {
		if ( '' !== $item['error'] ) {
			$items[ $i ]['state']   = 'unreadable';
			$items[ $i ]['post_id'] = 0;
			continue;
		}
		$items[ $i ] = array_merge( $item, jlife_challenges_devotional_item_state( $item, $plan_id, $overwrite ) );
	}
	return $items;
}

/**
 * Write one item's post: a new Draft, or the new title and content of an
 * existing one. The status is never touched on an existing post. Records the
 * hashes from what WordPress actually stored (a user without unfiltered HTML has
 * the content filtered on save).
 *
 * @param array  $item    Readable item.
 * @param string $plan_id Reading plan.
 * @param int    $post_id Existing post, or 0 to create one.
 * @return int|WP_Error Post ID.
 */
function jlife_challenges_devotional_write( $item, $plan_id, $post_id ) {
	$postarr = array(
		'post_type'    => JLIFE_DEVOTIONAL_CPT,
		'post_title'   => $item['title'],
		'post_content' => $item['html'],
	);
	if ( $post_id > 0 ) {
		$postarr['ID'] = $post_id;
		$result        = wp_update_post( wp_slash( $postarr ), true );
	} else {
		$postarr = array_merge(
			$postarr,
			array(
				'post_status' => 'draft',
				'post_author' => get_current_user_id(),
			)
		);
		$result  = wp_insert_post( wp_slash( $postarr ), true );
	}
	if ( is_wp_error( $result ) ) {
		return $result;
	}
	$id = (int) $result;
	update_post_meta( $id, '_jlife_dev_plan', (string) $plan_id );
	update_post_meta( $id, '_jlife_dev_day', $item['day_id'] );
	update_post_meta( $id, '_jlife_dev_lang', $item['lang'] );
	update_post_meta( $id, '_jlife_dev_src_hash', $item['src_hash'] );
	update_post_meta( $id, '_jlife_dev_questions', wp_slash( implode( "\n", $item['questions'] ) ) );
	$stored = get_post( $id );
	update_post_meta( $id, '_jlife_dev_import_hash', jlife_challenges_devotional_hash( (string) $stored->post_title, (string) $stored->post_content ) );
	return $id;
}

/**
 * Apply the readable items that are new or changed, and skip the rest. States
 * are worked out again now, against the posts as they are.
 *
 * @param int     $actor     Administrator.
 * @param array[] $items     Items from jlife_challenges_devotional_read_files().
 * @param string  $plan_id   Reading plan.
 * @param bool    $overwrite Replace posts edited in the editor.
 * @return array[]|WP_Error The items with `result`: created, updated, unchanged, skipped_edited, skipped_trash, unreadable or failed.
 */
function jlife_challenges_devotional_import( $actor, array $items, $plan_id, $overwrite = false ) {
	if ( ! jlife_challenges_is_organizer( $actor ) ) {
		return jlife_challenges_denied();
	}
	$counts = array(
		'created'   => 0,
		'updated'   => 0,
		'unchanged' => 0,
		'skipped'   => 0,
		'failed'    => 0,
	);
	foreach ( jlife_challenges_devotional_preview( $items, $plan_id, $overwrite ) as $i => $item ) {
		$result = 'unreadable';
		if ( in_array( $item['state'], array( 'new', 'changed' ), true ) ) {
			$written = jlife_challenges_devotional_write( $item, $plan_id, (int) $item['post_id'] );
			if ( is_wp_error( $written ) ) {
				$result = 'failed';
				++$counts['failed'];
			} else {
				$result = 'new' === $item['state'] ? 'created' : 'updated';
				++$counts[ $result ];
				// The post the admin page pre-ticks for a bulk Mark Ready (#262).
				$items[ $i ]['written_id'] = (int) $written;
			}
		} elseif ( 'unchanged' === $item['state'] ) {
			$result = 'unchanged';
			++$counts['unchanged'];
		} elseif ( 'unreadable' !== $item['state'] ) {
			$result = $item['state'];
			++$counts['skipped'];
		}
		$items[ $i ]['result'] = $result;
	}
	jlife_challenges_audit( $actor, 'devotionals_imported', 'devotional', 0, 0, $counts );
	return $items;
}

/**
 * Mark a devotional Ready (published) or put it back to Draft. Ready does not
 * make it visible early: the day must also have opened in the reader's campaign.
 *
 * @param int  $actor   Administrator.
 * @param int  $post_id Devotional post.
 * @param bool $ready   True for Ready, false for Draft.
 * @return bool Whether the status was set.
 */
function jlife_challenges_devotional_set_ready( $actor, $post_id, $ready ) {
	$post = (int) $post_id > 0 ? get_post( (int) $post_id ) : null;
	if ( ! jlife_challenges_is_organizer( $actor ) || ! $post || JLIFE_DEVOTIONAL_CPT !== $post->post_type || 'trash' === $post->post_status ) {
		return false;
	}
	$result = wp_update_post(
		array(
			'ID'          => $post->ID,
			'post_status' => $ready ? 'publish' : 'draft',
		),
		true
	);
	if ( is_wp_error( $result ) ) {
		return false;
	}
	jlife_challenges_audit(
		$actor,
		'devotional_status',
		'devotional',
		(int) $post->ID,
		0,
		array( 'ready' => $ready ? 1 : 0 )
	);
	return true;
}

/**
 * How long a preview's converted text is kept for the Import button.
 */
const JLIFE_DEVOTIONAL_PREVIEW_TTL = HOUR_IN_SECONDS;

/**
 * Keep a preview's items for the Import step, for the person who made it only
 * (the uploaded files themselves are not kept).
 *
 * @param int     $user_id Administrator.
 * @param array[] $items   Items from jlife_challenges_devotional_read_files().
 * @return string Token for the Import form.
 */
function jlife_challenges_devotional_preview_store( $user_id, array $items ) {
	$token = wp_generate_password( 20, false );
	set_transient( 'jlife_dev_prev_' . (int) $user_id . '_' . $token, $items, JLIFE_DEVOTIONAL_PREVIEW_TTL );
	return $token;
}

/**
 * Take a preview's items back for import; they are removed so a repeat click
 * cannot apply them twice.
 *
 * @param int    $user_id Administrator.
 * @param string $token   Token from jlife_challenges_devotional_preview_store().
 * @return array[]|null Items, or null when the token is unknown or expired.
 */
function jlife_challenges_devotional_preview_take( $user_id, $token ) {
	if ( ! preg_match( '/^[A-Za-z0-9]{20}$/', (string) $token ) ) {
		return null;
	}
	$key   = 'jlife_dev_prev_' . (int) $user_id . '_' . $token;
	$items = get_transient( $key );
	delete_transient( $key );
	return is_array( $items ) ? $items : null;
}
