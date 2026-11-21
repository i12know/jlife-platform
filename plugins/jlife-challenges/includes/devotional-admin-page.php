<?php
/**
 * J-Life → Devotionals (#211): upload Markdown files, preview with no writes,
 * import, and see every day's English and Vietnamese devotional at a glance.
 *
 * Site administrators only (`manage_options`), a nonce on every action. Only
 * `.md` files, with a size limit; the uploaded files are not kept (a preview keeps
 * the converted text for an hour, for the Import button, and nothing else). The
 * audit log records counts only: never a file name or any text. Converting,
 * previewing and importing are in devotional-import.php.
 *
 * @package jlife-challenges
 */

defined( 'ABSPATH' ) || exit;

const JLIFE_DEVOTIONALS_PAGE = 'jlife-devotionals';

add_action( 'admin_menu', 'jlife_challenges_devotionals_admin_menu', 22 );

/**
 * Add J-Life → Devotionals under the J-Life menu that the Reminders page creates.
 */
function jlife_challenges_devotionals_admin_menu() {
	add_submenu_page( JLIFE_CHALLENGES_REMINDERS_PAGE, __( 'Devotionals', 'jlife-challenges' ), __( 'Devotionals', 'jlife-challenges' ), 'manage_options', JLIFE_DEVOTIONALS_PAGE, 'jlife_challenges_devotionals_admin_page' );
}

/**
 * The uploaded files of the form as one flat list.
 *
 * @return array[] Rows of { name, tmp_name, size, error }.
 */
function jlife_challenges_devotional_uploaded_files() {
	// phpcs:disable WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput -- the caller checked the nonce; the names are only displayed (escaped) and matched against a pattern, and the temporary paths come from PHP.
	if ( empty( $_FILES['jl_files'] ) || ! is_array( $_FILES['jl_files'] ) || ! isset( $_FILES['jl_files']['name'] ) || ! is_array( $_FILES['jl_files']['name'] ) ) {
		return array();
	}
	$raw   = $_FILES['jl_files'];
	$files = array();
	foreach ( array_keys( $raw['name'] ) as $i ) {
		$files[] = array(
			'name'     => (string) $raw['name'][ $i ],
			'tmp_name' => (string) ( $raw['tmp_name'][ $i ] ?? '' ),
			'size'     => (int) ( $raw['size'][ $i ] ?? 0 ),
			'error'    => (int) ( $raw['error'][ $i ] ?? UPLOAD_ERR_NO_FILE ),
		);
	}
	// phpcs:enable
	return $files;
}

/**
 * The plain-words label of a preview state or an import result.
 *
 * @param string $state new|changed|unchanged|skipped_edited|skipped_trash|unreadable|created|updated|failed.
 * @return string
 */
function jlife_challenges_devotional_state_label( $state ) {
	$labels = array(
		'new'            => __( 'New (will be created as a Draft)', 'jlife-challenges' ),
		'changed'        => __( 'Changed (will be updated)', 'jlife-challenges' ),
		'unchanged'      => __( 'Unchanged', 'jlife-challenges' ),
		'skipped_edited' => __( 'Skipped: edited in wp-admin since the last import', 'jlife-challenges' ),
		'skipped_trash'  => __( 'Skipped: it is in the Trash', 'jlife-challenges' ),
		'unreadable'     => __( 'Cannot be read', 'jlife-challenges' ),
		'created'        => __( 'Created as a Draft', 'jlife-challenges' ),
		'updated'        => __( 'Updated', 'jlife-challenges' ),
		'failed'         => __( 'Could not be saved', 'jlife-challenges' ),
	);
	return $labels[ $state ] ?? $labels['unreadable'];
}

/**
 * Why a file cannot be read, in plain words.
 *
 * @param string $code bad_name|not_md|too_large|upload_error|not_utf8|empty|unknown_day|duplicate.
 * @return string
 */
function jlife_challenges_devotional_error_label( $code ) {
	$labels = array(
		'bad_name'     => __( 'The file name must look like D001-EN_Title.md or D001-VI_Title.md.', 'jlife-challenges' ),
		'not_md'       => __( 'Only .md (Markdown) files are accepted.', 'jlife-challenges' ),
		'too_large'    => __( 'The file is too large (the limit is 200 KB).', 'jlife-challenges' ),
		'upload_error' => __( 'The upload failed. Try this file again.', 'jlife-challenges' ),
		'not_utf8'     => __( 'The file is not UTF-8 text. Export it again as Markdown.', 'jlife-challenges' ),
		'empty'        => __( 'The file has no text.', 'jlife-challenges' ),
		'unknown_day'  => __( 'This day number is not in the reading plan.', 'jlife-challenges' ),
		'duplicate'    => __( 'Another file in this upload is for the same day and language.', 'jlife-challenges' ),
	);
	return $labels[ $code ] ?? $labels['upload_error'];
}

/**
 * A warning from the converter, in plain words.
 *
 * @param array $warning { code: string, count: int }.
 * @return string
 */
function jlife_challenges_devotional_warning_label( $warning ) {
	switch ( $warning['code'] ) {
		case 'images_dropped':
			/* translators: %d: number of images that were removed. */
			return sprintf( __( 'Images are not supported yet; %d removed.', 'jlife-challenges' ), (int) $warning['count'] );
		case 'no_questions':
			return __( 'No questions section found (a ### heading such as "Reflection questions").', 'jlife-challenges' );
		default:
			return __( 'No # title found; a placeholder title was used.', 'jlife-challenges' );
	}
}

/**
 * Handle the Preview button: read and convert the files, write nothing.
 *
 * @param string $plan_id Reading plan.
 * @return array { mode: 'preview', items: array[], token: string }
 */
function jlife_challenges_devotionals_handle_preview( $plan_id ) {
	check_admin_referer( 'jlife_dev_preview' );
	$items = jlife_challenges_devotional_read_files( jlife_challenges_devotional_uploaded_files(), $plan_id );
	$ready = array_filter(
		$items,
		static function ( $item ) {
			return '' === $item['error'];
		}
	);
	return array(
		'mode'  => 'preview',
		'items' => jlife_challenges_devotional_preview( $items, $plan_id, false ),
		'token' => $ready ? jlife_challenges_devotional_preview_store( get_current_user_id(), $items ) : '',
	);
}

/**
 * Handle the Import button: apply the previewed files.
 *
 * @param string $plan_id Reading plan.
 * @return array { mode: 'import'|'expired', items: array[] }
 */
function jlife_challenges_devotionals_handle_import( $plan_id ) {
	check_admin_referer( 'jlife_dev_import' );
	$token     = isset( $_POST['jl_token'] ) ? sanitize_text_field( wp_unslash( $_POST['jl_token'] ) ) : '';
	$overwrite = ! empty( $_POST['jl_overwrite'] );
	$items     = jlife_challenges_devotional_preview_take( get_current_user_id(), $token );
	$result    = null === $items ? null : jlife_challenges_devotional_import( get_current_user_id(), $items, $plan_id, $overwrite );
	if ( null === $result || is_wp_error( $result ) ) {
		return array(
			'mode'  => 'expired',
			'items' => array(),
		);
	}
	return array(
		'mode'  => 'import',
		'items' => $result,
	);
}

/**
 * Handle Mark Ready / Back to Draft for one devotional.
 *
 * @return bool Whether the status was changed.
 */
function jlife_challenges_devotionals_handle_status() {
	$post_id = isset( $_POST['jl_post'] ) ? absint( $_POST['jl_post'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- the post ID is part of the nonce's action; it is checked on the next line.
	check_admin_referer( 'jlife_dev_status_' . $post_id );
	$ready = isset( $_POST['jl_dev_action'] ) && 'ready' === sanitize_key( wp_unslash( $_POST['jl_dev_action'] ) );
	return jlife_challenges_devotional_set_ready( get_current_user_id(), $post_id, $ready );
}

/**
 * The upload form.
 *
 * @return string Escaped HTML.
 */
function jlife_challenges_devotionals_upload_form() {
	return '<h2>' . esc_html__( '1. Upload and preview', 'jlife-challenges' ) . '</h2>'
		. '<p>' . esc_html__( 'Choose one or more Markdown (.md) files named like D001-EN_Title.md and D001-VI_Title.md. The number is the day of the reading plan. Nothing is saved until you press Import. The files themselves are not kept.', 'jlife-challenges' ) . '</p>'
		. '<form method="post" enctype="multipart/form-data" action="' . esc_url( admin_url( 'admin.php?page=' . JLIFE_DEVOTIONALS_PAGE ) ) . '">'
		. wp_nonce_field( 'jlife_dev_preview', '_wpnonce', true, false )
		. '<input type="hidden" name="jl_dev_action" value="preview">'
		. '<p><label for="jl-dev-files">' . esc_html__( 'Markdown files', 'jlife-challenges' ) . '</label> '
		. '<input type="file" id="jl-dev-files" name="jl_files[]" accept=".md,text/markdown" multiple></p>'
		. '<p class="description">' . esc_html__( 'Many hosts accept about 20 files at a time. Upload more in several rounds.', 'jlife-challenges' ) . '</p>'
		. '<p><button type="submit" class="button button-primary">' . esc_html__( 'Preview (no changes)', 'jlife-challenges' ) . '</button></p></form>';
}

/**
 * The table of files and what will happen (or happened) to each.
 *
 * @param array[] $items  Items with `state` (preview) or `result` (import).
 * @param string  $column Which key holds the outcome: 'state' or 'result'.
 * @return string Escaped HTML.
 */
function jlife_challenges_devotionals_items_table( array $items, $column ) {
	$html = '<table class="widefat striped"><thead><tr>'
		. '<th scope="col">' . esc_html__( 'File', 'jlife-challenges' ) . '</th>'
		. '<th scope="col">' . esc_html__( 'Day', 'jlife-challenges' ) . '</th>'
		. '<th scope="col">' . esc_html__( 'Language', 'jlife-challenges' ) . '</th>'
		. '<th scope="col">' . esc_html__( 'Result', 'jlife-challenges' ) . '</th>'
		. '<th scope="col">' . esc_html__( 'Notes', 'jlife-challenges' ) . '</th></tr></thead><tbody>';
	foreach ( $items as $item ) {
		$notes = array();
		if ( '' !== $item['error'] ) {
			$notes[] = jlife_challenges_devotional_error_label( $item['error'] );
		}
		foreach ( (array) $item['warnings'] as $warning ) {
			$notes[] = jlife_challenges_devotional_warning_label( $warning );
		}
		$html .= '<tr><td>' . esc_html( $item['file'] ) . '</td>'
			. '<td>' . esc_html( '' !== $item['day_id'] ? $item['day_id'] : '–' ) . '</td>'
			. '<td>' . esc_html( '' !== $item['lang'] ? strtoupper( $item['lang'] ) : '–' ) . '</td>'
			. '<td>' . esc_html( jlife_challenges_devotional_state_label( (string) ( $item[ $column ] ?? 'unreadable' ) ) ) . '</td>'
			. '<td>' . ( $notes ? '<ul style="margin:0">' . implode( '', array_map( static fn( $n ) => '<li>' . esc_html( $n ) . '</li>', $notes ) ) . '</ul>' : '' ) . '</td></tr>';
	}
	return $html . '</tbody></table>';
}

/**
 * The preview: the table and, when something could be imported, the Import form.
 *
 * @param array $report { items: array[], token: string }.
 * @return string Escaped HTML.
 */
function jlife_challenges_devotionals_preview_html( $report ) {
	$html = '<h2>' . esc_html__( 'Preview', 'jlife-challenges' ) . '</h2>';
	if ( ! $report['items'] ) {
		return $html . '<p>' . esc_html__( 'No files were chosen.', 'jlife-challenges' ) . '</p>';
	}
	$html .= jlife_challenges_devotionals_items_table( $report['items'], 'state' );

	$todo = array_filter(
		$report['items'],
		static function ( $item ) {
			return in_array( $item['state'], array( 'new', 'changed', 'skipped_edited' ), true );
		}
	);
	if ( '' === $report['token'] || ! $todo ) {
		return $html . '<p>' . esc_html__( 'There is nothing to import.', 'jlife-challenges' ) . '</p>';
	}
	return $html . '<h2>' . esc_html__( '2. Import', 'jlife-challenges' ) . '</h2>'
		. '<form method="post" action="' . esc_url( admin_url( 'admin.php?page=' . JLIFE_DEVOTIONALS_PAGE ) ) . '">'
		. wp_nonce_field( 'jlife_dev_import', '_wpnonce', true, false )
		. '<input type="hidden" name="jl_dev_action" value="import">'
		. '<input type="hidden" name="jl_token" value="' . esc_attr( $report['token'] ) . '">'
		. '<p><label><input type="checkbox" name="jl_overwrite" value="1"> ' . esc_html__( 'Overwrite my edits (replace devotionals that were changed in wp-admin since the last import)', 'jlife-challenges' ) . '</label></p>'
		. '<p class="description">' . esc_html__( 'New devotionals are created as Drafts. Import never changes whether a devotional is Draft or Ready, and it never touches the reading days.', 'jlife-challenges' ) . '</p>'
		. '<p><button type="submit" class="button button-primary">' . esc_html__( 'Import', 'jlife-challenges' ) . '</button></p></form>';
}

/**
 * One Draft/Ready cell of the grid: the status, an Edit link, and the button that
 * flips the status.
 *
 * @param WP_Post|null $post The devotional, or null when none is imported.
 * @return string Escaped HTML.
 */
function jlife_challenges_devotionals_grid_cell( $post ) {
	if ( ! $post ) {
		return '<span aria-label="' . esc_attr__( 'Not imported', 'jlife-challenges' ) . '">–</span>';
	}
	$status = array(
		'publish' => __( 'Ready', 'jlife-challenges' ),
		'trash'   => __( 'In the Trash', 'jlife-challenges' ),
	);
	$out    = '<strong>' . esc_html( $status[ $post->post_status ] ?? __( 'Draft', 'jlife-challenges' ) ) . '</strong>';
	if ( '1' === (string) get_post_meta( $post->ID, '_jlife_dev_soap_practice', true ) ) {
		$out .= ' · ' . esc_html__( 'SOAP practice', 'jlife-challenges' );
	}
	if ( 'trash' === $post->post_status ) {
		return $out;
	}
	$ready = 'publish' === $post->post_status;
	$edit  = get_edit_post_link( $post->ID );
	return $out . ( $edit ? ' · <a href="' . esc_url( $edit ) . '">' . esc_html__( 'Edit', 'jlife-challenges' ) . '</a>' : '' )
		. '<form method="post" action="' . esc_url( admin_url( 'admin.php?page=' . JLIFE_DEVOTIONALS_PAGE ) ) . '" style="display:inline;margin-left:6px">'
		. wp_nonce_field( 'jlife_dev_status_' . (int) $post->ID, '_wpnonce', true, false )
		. '<input type="hidden" name="jl_post" value="' . (int) $post->ID . '">'
		. '<input type="hidden" name="jl_dev_action" value="' . ( $ready ? 'draft' : 'ready' ) . '">'
		. '<button type="submit" class="button button-small">' . esc_html( $ready ? __( 'Back to Draft', 'jlife-challenges' ) : __( 'Mark Ready', 'jlife-challenges' ) ) . '</button></form>';
}

/**
 * The plan's devotional posts keyed "d001/en", the oldest of each.
 *
 * @param string $plan_id Reading plan.
 * @return array<string,WP_Post>
 */
function jlife_challenges_devotional_posts_by_cell( $plan_id ) {
	$posts = get_posts(
		array(
			'post_type'        => JLIFE_DEVOTIONAL_CPT,
			'post_status'      => array( 'publish', 'draft', 'pending', 'private', 'future', 'trash' ),
			'posts_per_page'   => -1,
			'orderby'          => 'ID',
			'order'            => 'ASC',
			'no_found_rows'    => true,
			'suppress_filters' => true,
			'meta_query'       => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- one small query for the admin grid.
				array(
					'key'   => '_jlife_dev_plan',
					'value' => (string) $plan_id,
				),
			),
		)
	);
	$by    = array();
	foreach ( $posts as $post ) {
		$key = get_post_meta( $post->ID, '_jlife_dev_day', true ) . '/' . get_post_meta( $post->ID, '_jlife_dev_lang', true );
		if ( ! isset( $by[ $key ] ) ) {
			$by[ $key ] = $post;
		}
	}
	return $by;
}

/**
 * The grid: the plan's days against English and Vietnamese.
 *
 * @param string $plan_id Reading plan.
 * @return string Escaped HTML.
 */
function jlife_challenges_devotionals_grid_html( $plan_id ) {
	$days = jlife_challenges_devotional_plan_days( $plan_id );
	$html = '<h2>' . esc_html__( 'Days', 'jlife-challenges' ) . '</h2>';
	if ( ! $days ) {
		return $html . '<p>' . esc_html__( 'The reading plan is not loaded yet, so there are no days to show.', 'jlife-challenges' ) . '</p>';
	}
	$by   = jlife_challenges_devotional_posts_by_cell( $plan_id );
	$rows = '';
	$n    = 0;
	foreach ( $days as $day_id => $title ) {
		++$n;
		$rows .= '<tr><td>' . esc_html( $n . ' · ' . $title ) . '</td>'
			. '<td>' . jlife_challenges_devotionals_grid_cell( $by[ $day_id . '/en' ] ?? null ) . '</td>'
			. '<td>' . jlife_challenges_devotionals_grid_cell( $by[ $day_id . '/vi' ] ?? null ) . '</td></tr>';
	}
	$html .= '<table class="widefat striped"><thead><tr>'
		. '<th scope="col">' . esc_html__( 'Day', 'jlife-challenges' ) . '</th>'
		. '<th scope="col">English</th><th scope="col">Tiếng Việt</th></tr></thead><tbody>' . $rows;
	return $html . '</tbody></table>'
		. '<p class="description">' . esc_html__( "Ready means the devotional may be shown. It still appears only once that day has opened in the reader's campaign. Edit opens the block editor for fixes by hand.", 'jlife-challenges' ) . '</p>';
}

/**
 * The J-Life → Devotionals page.
 */
function jlife_challenges_devotionals_admin_page() {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( esc_html__( 'You are not allowed to see this page.', 'jlife-challenges' ), '', array( 'response' => 403 ) );
	}
	$plan_id = jlife_challenges_devotional_plan_id();
	$report  = null;
	$notice  = '';
	$method  = isset( $_SERVER['REQUEST_METHOD'] ) ? strtoupper( sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) ) : 'GET';

	// phpcs:ignore WordPress.Security.NonceVerification.Missing -- only picks which handler runs; each handler checks its own nonce first.
	$action = 'POST' === $method && isset( $_POST['jl_dev_action'] ) ? sanitize_key( wp_unslash( $_POST['jl_dev_action'] ) ) : '';
	if ( 'preview' === $action ) {
		$report = jlife_challenges_devotionals_handle_preview( $plan_id );
	} elseif ( 'import' === $action ) {
		$report = jlife_challenges_devotionals_handle_import( $plan_id );
	} elseif ( in_array( $action, array( 'ready', 'draft' ), true ) ) {
		$notice = jlife_challenges_devotionals_handle_status()
			? '<div class="notice notice-success"><p>' . esc_html__( 'Saved.', 'jlife-challenges' ) . '</p></div>'
			: '<div class="notice notice-error"><p>' . esc_html__( 'Not found or not allowed.', 'jlife-challenges' ) . '</p></div>';
	}

	$html = '<div class="wrap"><h1>' . esc_html__( 'Devotionals', 'jlife-challenges' ) . '</h1>' . $notice;
	if ( '' === $plan_id ) {
		echo $html . '<p>' . esc_html__( 'There is no campaign yet, so there is no reading plan to attach devotionals to.', 'jlife-challenges' ) . '</p></div>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- every part is escaped.
		return;
	}
	$html .= jlife_challenges_devotionals_upload_form();
	if ( $report && 'preview' === $report['mode'] ) {
		$html .= jlife_challenges_devotionals_preview_html( $report );
	} elseif ( $report && 'import' === $report['mode'] ) {
		$html .= '<h2>' . esc_html__( 'Import finished', 'jlife-challenges' ) . '</h2>' . jlife_challenges_devotionals_items_table( $report['items'], 'result' );
	} elseif ( $report ) {
		$html .= '<div class="notice notice-warning"><p>' . esc_html__( 'That preview has expired. Choose the files and preview them again.', 'jlife-challenges' ) . '</p></div>';
	}
	echo $html . jlife_challenges_devotionals_grid_html( $plan_id ) . '</div>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- every part is escaped.
}
