<?php
/**
 * The on-page Scripture reader (#139). Given a plan day and a participant,
 * shows all of the day's references in the participant's Bible, in order and
 * open (#188): each under a plain reference heading, with verse numbers. The
 * version names and copyright lines are not repeated under every passage:
 * each distinct notice for the text actually shown is listed once, in one
 * attribution footer after the last passage. A version that may not render
 * text (link-only, not yet licensed), or any failure, shows an "Open in Bible
 * Gateway" link in that passage's place, so reading and SOAP are never
 * blocked; the link version's notice goes to the footer too.
 *
 * Which Bible(s) a participant reads comes from
 * jlife_scripture_versions_for_user() (#140): their own choice, else the
 * default for their language. A second Bible, when they chose one, shows under
 * the first. The chosen version's own language decides the labels and the
 * `lang` of its text.
 *
 * Off by default: jlife_scripture_maybe_render_day() returns null until the
 * site option jlife_scripture_native_reader is "1", and the screens keep
 * their current reader.
 *
 * @package jlife-scripture
 */

defined( 'ABSPATH' ) || exit;

/**
 * Is the on-page reader turned on for this site?
 *
 * @return bool
 */
function jlife_scripture_reader_enabled() {
	return '1' === (string) get_option( 'jlife_scripture_native_reader', '0' );
}

/**
 * The reader for a day when the feature flag is on; null when it is off (the
 * caller keeps its current reader).
 *
 * @param mixed $day     Plan day (reading-plan JSON day: passages, i18n, links).
 * @param int   $user_id Participant (0 = not signed in: the page's language).
 * @param array $args    See jlife_scripture_render_day().
 * @return string|null
 */
function jlife_scripture_maybe_render_day( $day, $user_id = 0, $args = array() ) {
	if ( ! jlife_scripture_reader_enabled() || ! is_array( $day ) || empty( $day['passages'] ) ) {
		return null;
	}
	return jlife_scripture_render_day( $day, (int) $user_id, $args );
}

/**
 * What the reader needs for one version: whether it can show text, and its
 * language's link fallback.
 *
 * @param array|null $version Registry entry, or null when the registry has none.
 * @param string     $lang    Language to use when there is no version.
 * @return array{version: array|null, native: bool, fallback: array|null, language: string}
 */
function jlife_scripture_reader_choice_for( $version, $lang ) {
	$lang = null !== $version ? (string) $version['language'] : (string) $lang;
	return array(
		'version'  => $version,
		'native'   => null !== $version && jlife_scripture_can_render( $version ),
		'fallback' => jlife_scripture_fallback_version( $lang ),
		'language' => $lang,
	);
}

/**
 * The version a language reads in by default, and its link fallback.
 *
 * @param string $lang Language code.
 * @return array{version: array|null, native: bool, fallback: array|null, language: string}
 */
function jlife_scripture_reader_choice( $lang ) {
	return jlife_scripture_reader_choice_for( jlife_scripture_default_version( $lang ), $lang );
}

/**
 * Render a day's passages.
 *
 * @param mixed $day     Plan day: passages[] { display }, optional i18n.<lang>.passages[] labels.
 * @param int   $user_id Participant whose Bible(s) to show (0 = not signed in: the page's language default).
 * @param array $args    { language?: "en"|"vi" shows that language's default Bible and ignores the participant's choice (the public /reading/ pages) }.
 * @return string Markup (empty when the day has no passages).
 */
function jlife_scripture_render_day( $day, $user_id = 0, $args = array() ) {
	$passages = is_array( $day ) ? (array) ( $day['passages'] ?? array() ) : array();
	if ( array() === $passages ) {
		return '';
	}
	$forced  = in_array( $args['language'] ?? '', array( 'en', 'vi' ), true ) ? $args['language'] : '';
	$user_id = (int) $user_id;
	$pair    = jlife_scripture_versions_for_user( $user_id, $forced );
	$lang    = '' !== $forced ? $forced : jlife_scripture_language_for_user( $user_id );
	$choices = array( jlife_scripture_reader_choice_for( $pair[0], $lang ) );
	if ( null !== $pair[1] ) {
		$choices[] = jlife_scripture_reader_choice_for( $pair[1], $lang );
	}
	$reader  = $lang; // The language the participant reads in (My language, or the page's), for translated links.
	$lang    = $choices[0]['language'];
	$labels  = (array) ( $day['i18n'][ $lang ]['passages'] ?? array() );
	$out     = '';
	$notices = array();
	foreach ( array_values( $passages ) as $i => $passage ) {
		$display  = (string) ( is_array( $passage ) ? ( $passage['display'] ?? '' ) : $passage );
		$label    = is_string( $labels[ $i ] ?? null ) && '' !== $labels[ $i ] ? $labels[ $i ] : $display;
		$segments = jlife_scripture_parse_reference( $display );
		$out     .= '<section class="jls-passage">'
			. '<h3 class="jls-ref">' . esc_html( $label ) . '</h3>'
			. '<div class="jls-body">' . jlife_scripture_render_editions( $choices, $segments, $day, $reader, $notices ) . '</div>'
			. '</section>';
	}
	jlife_scripture_enqueue_style();
	return '<section class="jls-reader" lang="' . esc_attr( $lang ) . '" aria-label="' . esc_attr__( 'Scripture reading', 'jlife-scripture' ) . '">'
		. $out . jlife_scripture_render_attribution_footer( $notices ) . '</section>';
}

/**
 * The one attribution footer after the whole reading (#188): each distinct
 * notice for the text shown (or linked) once, in the order first met. Empty
 * when there is none.
 *
 * @param string[] $notices Escaped notice paragraphs (<p class="jls-version">).
 * @return string
 */
function jlife_scripture_render_attribution_footer( $notices ) {
	return array() === $notices ? '' : '<footer class="jls-attribution">' . implode( '', $notices ) . '</footer>';
}

/**
 * Add a notice to the footer's list unless it is already there.
 *
 * @param string[] $notices Notices so far (changed in place).
 * @param string   $notice  Escaped notice paragraph; '' adds nothing.
 */
function jlife_scripture_add_notice( &$notices, $notice ) {
	if ( '' !== $notice && ! in_array( $notice, $notices, true ) ) {
		$notices[] = $notice;
	}
}

/**
 * One passage in each of the person's Bibles, the second under the first.
 * With a single Bible the markup is just that Bible's. Two Bibles that would
 * show the same thing (the same Bible Gateway link) show it once.
 *
 * @param array[]        $choices  jlife_scripture_reader_choice_for() results, first Bible first.
 * @param array|WP_Error $segments Parsed reference, or the parse error.
 * @param array          $day      Plan day.
 * @param string         $reader   The reader's language ("en" or "vi").
 * @param string[]       $notices  The footer's notices so far; each shown edition's are added (changed in place).
 * @return string
 */
function jlife_scripture_render_editions( $choices, $segments, $day, $reader, &$notices ) {
	$editions = array();
	foreach ( $choices as $choice ) {
		$edition = jlife_scripture_render_edition( $choice, $segments, $day, $reader );
		$html    = $edition['html'];
		if ( '' !== $html && ! in_array( $html, array_column( $editions, 'html' ), true ) ) {
			$editions[] = array(
				'lang' => $choice['language'],
				'html' => $html,
			);
			foreach ( $edition['notices'] as $notice ) {
				jlife_scripture_add_notice( $notices, $notice );
			}
		}
	}
	if ( count( $editions ) < 2 ) {
		return $editions ? $editions[0]['html'] : '';
	}
	$out = '';
	foreach ( $editions as $edition ) {
		$out .= '<div class="jls-edition" lang="' . esc_attr( $edition['lang'] ) . '">' . $edition['html'] . '</div>';
	}
	return $out;
}

/**
 * One passage in one Bible: the text, or the Bible Gateway link when the
 * version cannot show text or the text is unavailable; and the notice that
 * text or link needs, for the footer.
 *
 * @param array          $choice   jlife_scripture_reader_choice_for() result.
 * @param array|WP_Error $segments Parsed reference, or the parse error.
 * @param array          $day      Plan day.
 * @param string         $reader   The reader's language ("en" or "vi").
 * @return array{html: string, notices: string[]}
 */
function jlife_scripture_render_edition( $choice, $segments, $day, $reader ) {
	$body    = '';
	$notices = array();
	$shown   = false;
	if ( ! is_wp_error( $segments ) && $choice['native'] ) {
		$result = jlife_scripture_get_passage_entry( $choice['version'], $segments );
		$shown  = ! is_wp_error( $result );
		if ( $shown ) {
			$body      = jlife_scripture_render_verses( $result['segments'] );
			$notices[] = jlife_scripture_render_attribution( $choice['version'], $result['meta'], $reader );
		} else {
			$body = '<p class="jls-note">' . esc_html__( 'The Bible text could not be loaded.', 'jlife-scripture' ) . '</p>';
		}
	}
	if ( ! $shown ) {
		$link  = jlife_scripture_render_link( is_wp_error( $segments ) ? null : $segments, $choice['fallback'], $day, $choice['language'] );
		$body .= $link['html'];
		if ( '' !== $link['notice'] ) {
			$notices[] = $link['notice'];
		}
	}
	return array(
		'html'    => $body,
		'notices' => $notices,
	);
}

/**
 * Verses with their numbers; a new segment starts a new paragraph, and a
 * verse in a new chapter is numbered "chapter:verse". A verse that two
 * segments share ("Luke 24:10b, d") is shown once.
 *
 * @param array $result One list of verses per segment.
 * @return string
 */
function jlife_scripture_render_verses( $result ) {
	$html    = '';
	$seen    = array();
	$chapter = null;
	foreach ( $result as $verses ) {
		$para = '';
		foreach ( $verses as $v ) {
			$id = $v['chapter'] . ':' . $v['verse'];
			if ( isset( $seen[ $id ] ) ) {
				continue;
			}
			$seen[ $id ] = true;
			$number      = ( null !== $chapter && $v['chapter'] !== $chapter ) ? $id : (string) $v['verse'];
			$chapter     = $v['chapter'];
			$para       .= '<span class="jls-verse"><sup class="jls-num">' . esc_html( $number ) . '</sup> ' . esc_html( $v['text'] ) . '</span> ';
		}
		if ( '' !== $para ) {
			$html .= '<p>' . rtrim( $para ) . '</p>';
		}
	}
	return '<div class="jls-text">' . $html . '</div>';
}

/**
 * The version name and its copyright line, for the footer. A source that
 * needs more under its text than the registry's line (the notice the API
 * returned, a required link: Jlife_Scripture_Source_Attribution) prints that
 * instead.
 *
 * @param array  $version  Version entry.
 * @param array  $meta     The meta stored with the passage (a source's own).
 * @param string $language The reader's language ("en" or "vi"; '' = unknown).
 * @return string
 */
function jlife_scripture_render_attribution( $version, $meta = array(), $language = '' ) {
	$source = jlife_scripture_get_source( (string) $version['source'] );
	if ( $source instanceof Jlife_Scripture_Source_Attribution ) {
		return $source->attribution_html( $version, (array) $meta, (string) $language );
	}
	$notice = esc_html( (string) $version['copyright']['text'] );
	$url    = (string) ( $version['copyright']['url'] ?? '' );
	if ( '' !== $url ) {
		$notice = '<a href="' . esc_url( $url ) . '" target="_blank" rel="noopener">' . $notice . '</a>';
	}
	return '<p class="jls-version"><small><span class="jls-version-name">' . esc_html( (string) $version['name'] ) . '</span> · ' . $notice . '</small></p>';
}

/**
 * The "Open in Bible Gateway" link for a passage in the language's link
 * version, and that version's notice (for the footer). Without a parsed
 * reference, the plan's own link for the day is used.
 *
 * @param array|null $segments Segments, or null when the reference did not parse.
 * @param array|null $fallback Link version.
 * @param array      $day      Plan day (for its pre-built links).
 * @param string     $lang     Language code.
 * @return array{html: string, notice: string} Both '' when there is no link.
 */
function jlife_scripture_render_link( $segments, $fallback, $day, $lang ) {
	$code = $fallback ? (string) $fallback['source_id'] : (string) ( $day['links'][ $lang ]['version'] ?? '' );
	if ( $fallback && null !== $segments ) {
		$url = jlife_scripture_bible_gateway_url( $segments, $code );
	} else {
		$url = (string) ( $day['links'][ $lang ]['combined']['url'] ?? '' );
	}
	if ( '' === $url || '' === $code ) {
		return array(
			'html'   => '',
			'notice' => '',
		);
	}
	/* translators: %s: Bible version code, e.g. "ERV". */
	$text = sprintf( __( 'Open in Bible Gateway (%s)', 'jlife-scripture' ), $code );
	return array(
		'html'   => '<p class="jls-link"><a href="' . esc_url( $url ) . '" target="_blank" rel="noopener">' . esc_html( $text ) . '</a></p>',
		'notice' => $fallback ? '<p class="jls-version"><small>' . esc_html( (string) $fallback['copyright']['text'] ) . '</small></p>' : '',
	);
}

/**
 * The reader's stylesheet (phone-first, no inner scroll box, dark mode).
 */
function jlife_scripture_enqueue_style() {
	wp_enqueue_style( 'jlife-scripture', plugins_url( 'assets/scripture.css', JLIFE_SCRIPTURE_FILE ), array(), '0.3.0' );
}
