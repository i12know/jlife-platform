<?php
/**
 * Daily devotionals (#211): one non-public `jlife_devotional` post per plan,
 * day and language, edited in the block editor.
 *
 * Why its own post type, owned here and not in jlife-studies:
 * - Every deploy re-imports the reading plans and forces each Reading Day to
 *   `publish` (jlife_studies_import_reading_plan()). A devotional kept on that
 *   post would be published or overwritten by an unrelated deploy. As separate
 *   posts, neither importer ever sees the other's rows.
 * - The release rule needs the campaign calendar (current-campaign.php,
 *   calendar.php), and the pilot (Day 1 Dec 5) and the Marathon (Day 1 Dec 20)
 *   share the same Reading Day. So release is decided per campaign day, never by
 *   the post's own date.
 *
 * Draft or Ready is the post's own status (draft, publish). Publishing does not
 * make a devotional visible early: it shows only once its day has opened in the
 * viewer's campaign. Nothing about a participant is read here.
 *
 * Meta (all underscored, so never shown as custom fields):
 * `_jlife_dev_plan` plan id, `_jlife_dev_day` day id (d001), `_jlife_dev_lang`
 * (en|vi), `_jlife_dev_soap_practice` ("1"), `_jlife_dev_src_hash` (hash of the
 * converted Markdown last imported), `_jlife_dev_import_hash` (hash of what the
 * post held right after that import), `_jlife_dev_questions` (lines under the
 * questions heading, one per line, for #210's excluded-text filter).
 *
 * @package jlife-challenges
 */

defined( 'ABSPATH' ) || exit;

const JLIFE_DEVOTIONAL_CPT       = 'jlife_devotional';
const JLIFE_DEVOTIONAL_REST_BASE = 'jlife-devotionals';

add_action( 'init', 'jlife_challenges_register_devotional' );
add_filter( 'rest_pre_dispatch', 'jlife_challenges_devotional_rest_guard', 10, 3 );
add_action( 'add_meta_boxes_' . JLIFE_DEVOTIONAL_CPT, 'jlife_challenges_devotional_add_meta_box' );
add_action( 'save_post_' . JLIFE_DEVOTIONAL_CPT, 'jlife_challenges_devotional_save_meta_box', 10, 2 );
add_filter( 'jlife_challenges_reflection_excluded_text', 'jlife_challenges_devotional_excluded_text', 10, 2 );
foreach ( array( 'et_builder_post_types', 'et_builder_third_party_post_types', 'et_fb_post_types' ) as $jlife_devotional_divi_filter ) {
	add_filter( $jlife_devotional_divi_filter, 'jlife_challenges_devotional_without_divi' );
}
unset( $jlife_devotional_divi_filter );

/**
 * Register the post type: not public, edited in the block editor, and every
 * capability is `manage_options`. Nobody can add one by hand (`create_posts` is
 * denied), so posts come only from the Markdown import.
 */
function jlife_challenges_register_devotional() {
	// The three meta capabilities keep names of their own: giving one the name
	// `manage_options` would make WordPress treat `manage_options` itself as a
	// per-post capability, and nobody (administrators included) would hold it.
	$caps = array(
		'edit_post'   => 'edit_jlife_devotional',
		'read_post'   => 'read_jlife_devotional',
		'delete_post' => 'delete_jlife_devotional',
	);
	foreach ( array(
		'edit_posts',
		'edit_others_posts',
		'delete_posts',
		'publish_posts',
		'read_private_posts',
		'delete_private_posts',
		'delete_published_posts',
		'delete_others_posts',
		'edit_private_posts',
		'edit_published_posts',
	) as $cap ) {
		$caps[ $cap ] = 'manage_options';
	}
	$caps['create_posts'] = 'do_not_allow';
	register_post_type(
		JLIFE_DEVOTIONAL_CPT,
		array(
			'labels'              => array(
				'name'          => __( 'Devotionals', 'jlife-challenges' ),
				'singular_name' => __( 'Devotional', 'jlife-challenges' ),
			),
			'public'              => false,
			'publicly_queryable'  => false,
			'exclude_from_search' => true,
			'show_ui'             => true,
			'show_in_menu'        => false,
			'show_in_nav_menus'   => false,
			'show_in_admin_bar'   => false,
			'show_in_rest'        => true,
			'rest_base'           => JLIFE_DEVOTIONAL_REST_BASE,
			'has_archive'         => false,
			'rewrite'             => false,
			'query_var'           => false,
			'can_export'          => false,
			'map_meta_cap'        => true,
			'capabilities'        => $caps,
			'supports'            => array( 'title', 'editor', 'revisions' ),
		)
	);
}

/**
 * A non-public post type with `show_in_rest` still lets anyone read its
 * published posts over REST, which would leak a Ready devotional before its day
 * opens. Every route of this type needs an administrator.
 *
 * @param mixed           $result  Short-circuit result (null = carry on).
 * @param WP_REST_Server  $server  Server.
 * @param WP_REST_Request $request Request.
 * @return mixed
 */
function jlife_challenges_devotional_rest_guard( $result, $server, $request ) {
	unset( $server );
	if ( null !== $result ) {
		return $result;
	}
	if ( 0 === strpos( (string) $request->get_route(), '/wp/v2/' . JLIFE_DEVOTIONAL_REST_BASE ) && ! current_user_can( 'manage_options' ) ) {
		return new WP_Error( 'rest_forbidden', jlife_challenges_denied()->get_error_message(), array( 'status' => is_user_logged_in() ? 403 : 401 ) );
	}
	return $result;
}

/**
 * Keep Divi's page builder off this post type: it is edited in the standard block
 * editor only. (The filters do nothing when Divi is not installed.)
 *
 * @param array $post_types Post types the builder is enabled for.
 * @return array
 */
function jlife_challenges_devotional_without_divi( $post_types ) {
	return array_values( array_diff( (array) $post_types, array( JLIFE_DEVOTIONAL_CPT ) ) );
}

/**
 * The two languages a devotional comes in.
 *
 * @return string[]
 */
function jlife_challenges_devotional_languages() {
	return array( 'en', 'vi' );
}

/**
 * The devotional post for a plan, day and language, in any status (the Trash
 * included), or null. There is one per triple: this returns the oldest.
 *
 * @param string $plan_id Plan ID.
 * @param string $day_id  Day ID (d001).
 * @param string $lang    en or vi.
 * @return WP_Post|null
 */
function jlife_challenges_devotional_find( $plan_id, $day_id, $lang ) {
	$posts = get_posts(
		array(
			'post_type'        => JLIFE_DEVOTIONAL_CPT,
			'post_status'      => array( 'publish', 'draft', 'pending', 'private', 'future', 'trash' ),
			'posts_per_page'   => 1,
			'orderby'          => 'ID',
			'order'            => 'ASC',
			'no_found_rows'    => true,
			'suppress_filters' => true,
			'meta_query'       => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- one post per plan, day and language; a small table.
				'relation' => 'AND',
				array(
					'key'   => '_jlife_dev_plan',
					'value' => (string) $plan_id,
				),
				array(
					'key'   => '_jlife_dev_day',
					'value' => (string) $day_id,
				),
				array(
					'key'   => '_jlife_dev_lang',
					'value' => (string) $lang,
				),
			),
		)
	);
	return $posts ? $posts[0] : null;
}

/**
 * Has this campaign day opened (so its devotional may show)? By the campaign's
 * own calendar, never the post's date: the pilot's Day 1 and the Marathon's Day 1
 * are the same Reading Day, fifteen days apart.
 *
 * @param array|null $campaign Campaign row.
 * @param string     $day_key  Day key (d001).
 * @return bool
 */
function jlife_challenges_devotional_released( $campaign, $day_key ) {
	if ( ! is_array( $campaign ) ) {
		return false;
	}
	$index = jlife_challenges_day_index( $campaign, (string) $day_key );
	return $index >= 1 && jlife_challenges_day_is_open( $campaign, $index );
}

/**
 * The one gate for showing a devotional: a Ready (published) post of the
 * campaign's plan, for a day that has opened in that campaign. A Draft, a day not
 * yet open, a day outside the campaign and a missing translation all give null;
 * there is no fallback to the other language.
 *
 * @param array|null $campaign Campaign row.
 * @param string     $day_key  Day key (d001).
 * @param string     $lang     en or vi.
 * @return WP_Post|null
 */
function jlife_challenges_devotional_for_day( $campaign, $day_key, $lang ) {
	if ( ! jlife_challenges_devotional_released( $campaign, $day_key ) ) {
		return null;
	}
	$post = jlife_challenges_devotional_find( (string) $campaign['plan_id'], (string) $day_key, (string) $lang );
	return $post && JLIFE_DEVOTIONAL_CPT === $post->post_type && 'publish' === $post->post_status ? $post : null;
}

/**
 * The campaign a public reading page belongs to: the current-campaign rule's pick
 * among this plan's campaigns that are not drafts.
 *
 * @param string $plan_id Plan ID.
 * @return array|null Campaign row.
 */
function jlife_challenges_devotional_public_campaign( $plan_id ) {
	if ( '' === (string) $plan_id ) {
		return null;
	}
	global $wpdb;
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.NotPrepared -- custom table; name from $wpdb->prefix.
	$rows = (array) $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . jlife_challenges_table( 'campaigns' ) . " WHERE plan_id = %s AND status <> 'draft'", (string) $plan_id ), ARRAY_A );
	return jlife_challenges_pick_campaign( $rows, 'view' );
}

/**
 * The language a signed-in participant reads in: "vi" or "en".
 *
 * @param int $user_id Participant.
 * @return string
 */
function jlife_challenges_devotional_user_language( $user_id ) {
	return 0 === strpos( (string) jlife_challenges_account_language( (int) $user_id ), 'vi' ) ? 'vi' : 'en';
}

/**
 * The devotional body as readers see it: block-rendered and filtered with
 * wp_kses_post() (so `section` and `aria-labelledby` must survive it, #225).
 *
 * Classic-editor content (#228) has no block markup: the Classic editor stores
 * paragraphs as bare blank-line-separated text and relies on wpautop() (what
 * `the_content` applies) to add the `<p>` tags. Content with no blocks gets the
 * same treatment here; block content is left to do_blocks(). The stored post is
 * never changed.
 *
 * @param WP_Post $post Devotional post.
 * @return string Escaped HTML.
 */
function jlife_challenges_devotional_body_html( $post ) {
	$content = (string) $post->post_content;
	if ( ! has_blocks( $content ) ) {
		$content = wpautop( $content );
	}
	return wp_kses_post( do_blocks( $content ) );
}

/**
 * The marked questions section of a devotional body (#225): the first
 * `<section class="jl-reflection-questions" aria-labelledby="…">`, which the
 * importer writes around the questions heading. The marker is the class, never
 * the heading's words or its position, so it works in any language.
 *
 * @param string $html Body HTML.
 * @return array|null { heading: string, html: string, lines: string[] }, or null when the body has no marked section.
 */
function jlife_challenges_devotional_marked_section( $html ) {
	if ( ! preg_match( '/<section\b[^>]*\bclass="[^"]*\bjl-reflection-questions\b[^"]*"[^>]*>(.*?)<\/section>/is', (string) $html, $m ) ) {
		return null;
	}
	$inner   = (string) $m[1];
	$heading = '';
	if ( preg_match( '/^\s*<h[1-6]\b[^>]*>(.*?)<\/h[1-6]>/is', $inner, $h ) ) {
		$heading = trim( html_entity_decode( wp_strip_all_tags( $h[1] ), ENT_QUOTES, 'UTF-8' ) );
		$inner   = (string) substr( $inner, strlen( $h[0] ) );
	}
	$text  = html_entity_decode( wp_strip_all_tags( (string) preg_replace( '/<\/(?:p|li|div|h[1-6])>|<br\s*\/?>/i', "\n", $inner ) ), ENT_QUOTES, 'UTF-8' );
	$lines = array();
	foreach ( explode( "\n", $text ) as $line ) {
		$line = trim( (string) preg_replace( '/\s+/u', ' ', $line ) );
		if ( '' !== $line ) {
			$lines[] = $line;
		}
	}
	return array(
		'heading' => $heading,
		'html'    => trim( $inner ),
		'lines'   => $lines,
	);
}

/**
 * The printed questions of a devotional: the one place that decides what they
 * are, so what the writing box shows and what #210 leaves out of the word count
 * (jlife_challenges_reflection_excluded_text) always agree, also after the post
 * is edited in wp-admin.
 *
 * A marked section (#225) is authoritative and is read from the post as it is now.
 * Older content without the marker falls back to the lines recorded at import
 * (`_jlife_dev_questions`). No heading wording, position or bold text is guessed.
 *
 * @param WP_Post $post Devotional post.
 * @return array|null { heading: string, html: string, lines: string[] }, or null when there are no questions.
 */
function jlife_challenges_devotional_questions( $post ) {
	$marked = jlife_challenges_devotional_marked_section( jlife_challenges_devotional_body_html( $post ) );
	if ( null !== $marked ) {
		return $marked['lines'] ? $marked : null;
	}
	$lines = array();
	foreach ( explode( "\n", (string) get_post_meta( $post->ID, '_jlife_dev_questions', true ) ) as $line ) {
		if ( '' !== trim( $line ) ) {
			$lines[] = trim( $line );
		}
	}
	if ( ! $lines ) {
		return null;
	}
	return array(
		'heading' => '',
		'html'    => '<ul><li>' . implode( '</li><li>', array_map( 'esc_html', $lines ) ) . '</li></ul>',
		'lines'   => $lines,
	);
}

/**
 * The guidance this participant has for a campaign day in their language (#225):
 * a list of sources, each `{ id: string, attribution: string, questions: array|null }`
 * where `questions` is { heading, html, lines } or null (guidance without marked
 * questions). Today there is one source, the site-wide devotional (Ready, released
 * for the campaign's day, in the reader's language). Group-leader guidance (#209)
 * adds its own sources through the filter, each with its own attribution; it must
 * apply the same membership, release and language rules itself.
 *
 * Whether a panel is open or collapsed plays no part: this reads content, not the
 * page. A Draft, an unreleased day or a missing translation gives no source.
 *
 * @param array|null $campaign Campaign row.
 * @param string     $day_key  Day key (d001).
 * @param string     $lang     en or vi.
 * @return array[]
 */
function jlife_challenges_guidance_sources( $campaign, $day_key, $lang ) {
	$sources = array();
	$post    = jlife_challenges_devotional_for_day( $campaign, $day_key, $lang );
	if ( $post ) {
		$sources[] = array(
			'id'          => 'devotional-' . (int) $post->ID,
			'attribution' => __( 'Devotional', 'jlife-challenges' ),
			'questions'   => jlife_challenges_devotional_questions( $post ),
		);
	}
	/**
	 * Further guidance sources for this participant, campaign day and language.
	 *
	 * @param array[]    $sources  Sources: { id, attribution, questions }.
	 * @param array|null $campaign Campaign row.
	 * @param string     $day_key  Day key.
	 * @param string     $lang     en or vi.
	 */
	$sources = (array) apply_filters( 'jlife_challenges_guidance_sources', $sources, $campaign, $day_key, $lang );
	return array_values(
		array_filter(
			$sources,
			static function ( $source ) {
				return isset( $source['id'], $source['attribution'] );
			}
		)
	);
}

/**
 * The guidance sources for the signed-in participant's day view.
 *
 * @param array $view Day view (needs campaign and day_key).
 * @return array[] See jlife_challenges_guidance_sources().
 */
function jlife_challenges_view_guidance( $view ) {
	$campaign = is_array( $view['campaign'] ?? null ) ? $view['campaign'] : null;
	$day_key  = (string) ( $view['day_key'] ?? '' );
	if ( null === $campaign || '' === $day_key ) {
		return array();
	}
	return jlife_challenges_guidance_sources( $campaign, $day_key, jlife_challenges_devotional_user_language( get_current_user_id() ) );
}

/**
 * Is the writing area in guided mode (My reflection) for this day? True exactly
 * when the participant has guidance for it. The scoring engine plays no part:
 * points are still decided by the campaign's engine on submission.
 *
 * @param array $view Day view.
 * @return bool
 */
function jlife_challenges_is_guided( $view ) {
	return (bool) jlife_challenges_view_guidance( $view );
}

/**
 * The marked questions as read-only markup for the writing box, above the
 * textarea. Separate from the participant's text: never saved, never a
 * placeholder. Several sources each keep their attribution.
 *
 * @param array[] $sources Sources from jlife_challenges_guidance_sources().
 * @param string  $lang    en or vi.
 * @return string Escaped HTML, or '' when no source has questions.
 */
function jlife_challenges_render_response_questions( $sources, $lang ) {
	$shown = array_values(
		array_filter(
			$sources,
			static function ( $source ) {
				return ! empty( $source['questions']['lines'] );
			}
		)
	);
	if ( ! $shown ) {
		return '';
	}
	$out = '<div class="jl-response-questions" lang="' . esc_attr( $lang ) . '">';
	foreach ( $shown as $i => $source ) {
		$id      = 'jl-response-q-' . ( $i + 1 );
		$heading = '' !== (string) $source['questions']['heading'] ? (string) $source['questions']['heading'] : __( 'Reflection questions', 'jlife-challenges' );
		$out    .= '<section class="jl-response-q" aria-labelledby="' . esc_attr( $id ) . '"><h3 id="' . esc_attr( $id ) . '">' . esc_html( $heading ) . '</h3>'
			. ( count( $shown ) > 1 ? '<p class="jl-response-q-from">' . esc_html( (string) $source['attribution'] ) . '</p>' : '' )
			. wp_kses_post( (string) $source['questions']['html'] ) . '</section>';
	}
	return $out . '</div>';
}

/**
 * The devotional's printed questions, for #210's engine, so questions a person
 * copies into their reflection do not count as their words. Only Ready
 * devotionals of days that have opened (what the person could see), in both
 * languages. Same source as the writing box (jlife_challenges_devotional_questions()).
 *
 * @param string[] $excluded Strings already excluded.
 * @param array    $context  { campaign?: array, day_key?: string, index?: int }.
 * @return string[]
 */
function jlife_challenges_devotional_excluded_text( $excluded, $context ) {
	$campaign = is_array( $context['campaign'] ?? null ) ? $context['campaign'] : null;
	$day_key  = (string) ( $context['day_key'] ?? '' );
	$excluded = (array) $excluded;
	if ( null === $campaign || '' === $day_key ) {
		return $excluded;
	}
	foreach ( jlife_challenges_devotional_languages() as $lang ) {
		$post = jlife_challenges_devotional_for_day( $campaign, $day_key, $lang );
		if ( ! $post ) {
			continue;
		}
		$questions = jlife_challenges_devotional_questions( $post );
		if ( $questions ) {
			$excluded = array_merge( $excluded, $questions['lines'] );
		}
	}
	return $excluded;
}

/**
 * The SOAP-practice box on the devotional's edit screen (works in the block
 * editor as a plain meta box; no custom block or editor script).
 */
function jlife_challenges_devotional_add_meta_box() {
	add_meta_box( 'jlife-devotional-soap', __( 'SOAP practice', 'jlife-challenges' ), 'jlife_challenges_devotional_meta_box', JLIFE_DEVOTIONAL_CPT, 'side', 'default' );
}

/**
 * The meta box's body.
 *
 * @param WP_Post $post The devotional.
 */
function jlife_challenges_devotional_meta_box( $post ) {
	wp_nonce_field( 'jlife_devotional_soap_' . (int) $post->ID, '_jlife_devotional_soap' );
	echo '<label><input type="checkbox" name="jl_devotional_soap_practice" value="1"' . checked( '1', (string) get_post_meta( $post->ID, '_jlife_dev_soap_practice', true ), false ) . '> '
		. esc_html__( 'This is a SOAP practice day', 'jlife-challenges' ) . '</label>'
		. '<p class="description">' . esc_html__( 'Participants see a short suggestion to try the SOAP template. It never changes what they type or how it is scored.', 'jlife-challenges' ) . '</p>';
}

/**
 * Save the SOAP-practice tick (administrators only; not on autosaves).
 *
 * @param int     $post_id Post ID.
 * @param WP_Post $post    The post.
 */
function jlife_challenges_devotional_save_meta_box( $post_id, $post ) {
	if ( JLIFE_DEVOTIONAL_CPT !== $post->post_type || ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) || wp_is_post_revision( $post_id ) ) {
		return;
	}
	if ( ! isset( $_POST['_jlife_devotional_soap'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['_jlife_devotional_soap'] ) ), 'jlife_devotional_soap_' . (int) $post_id ) || ! current_user_can( 'edit_post', $post_id ) ) {
		return;
	}
	jlife_challenges_devotional_set_soap_practice( $post_id, isset( $_POST['jl_devotional_soap_practice'] ) );
}

/**
 * Set or clear the SOAP-practice flag.
 *
 * @param int  $post_id Devotional post.
 * @param bool $on      Flag value.
 */
function jlife_challenges_devotional_set_soap_practice( $post_id, $on ) {
	if ( $on ) {
		update_post_meta( $post_id, '_jlife_dev_soap_practice', '1' );
	} else {
		delete_post_meta( $post_id, '_jlife_dev_soap_practice' );
	}
}

/**
 * The devotional block of a day screen, above the writing box: a collapsible
 * section, open by default, in the reader's language. Empty when there is no
 * Ready devotional for a day that has opened. Participant screens read it for the
 * viewer's own campaign; a public reading page (view has `public`) reads it for
 * the plan's current campaign. The body is block-rendered and filtered with
 * wp_kses_post() again on the way out.
 *
 * @param array $view Day view (see jlife_challenges_day_view(), jlife_challenges_public_reading_view()).
 * @return string Escaped HTML, or ''.
 */
function jlife_challenges_render_devotional( $view ) {
	$day_key = (string) ( $view['day_key'] ?? '' );
	if ( '' === $day_key ) {
		return '';
	}
	$public = ! empty( $view['public'] );
	if ( $public ) {
		$lang     = 'vi' === ( $view['language'] ?? '' ) ? 'vi' : 'en';
		$campaign = jlife_challenges_devotional_public_campaign( (string) ( $view['reading']['plan']['plan_id'] ?? '' ) );
	} else {
		$lang     = jlife_challenges_devotional_user_language( get_current_user_id() );
		$campaign = is_array( $view['campaign'] ?? null ) ? $view['campaign'] : null;
	}
	$post = jlife_challenges_devotional_for_day( $campaign, $day_key, $lang );
	if ( ! $post ) {
		return '';
	}
	$body = jlife_challenges_devotional_body_html( $post );
	$out  = '<details class="jl-devotional" open><summary>' . esc_html__( 'Devotional', 'jlife-challenges' ) . '</summary>'
		. '<div class="jl-devotional-body" lang="' . esc_attr( $lang ) . '">'
		. '<h3 class="jl-devotional-title">' . esc_html( get_the_title( $post ) ) . '</h3>' . $body . '</div>';
	if ( ! $public && '1' === (string) get_post_meta( $post->ID, '_jlife_dev_soap_practice', true ) ) {
		$out .= '<p class="jl-devotional-soap">' . esc_html__( 'Today is a SOAP practice day. If you like, tap "Use SOAP template" below the writing box to add the four SOAP headings. Nothing you write is changed.', 'jlife-challenges' ) . '</p>';
	}
	return $out . '</details>';
}
