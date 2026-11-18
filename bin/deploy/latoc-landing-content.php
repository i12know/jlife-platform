<?php
/**
 * Starting copy for the latoc public landing pages (#110): block markup.
 *
 * Returns the two page definitions (en, vi) that bin/deploy/latoc-landing.php
 * seeds ONCE, as DRAFTS. After that the owner edits the live pages in wp-admin
 * and nothing here is ever applied again (docs/staging-latoc.md).
 *
 * Source of the text: the "Page copy" and "CTA block" sections of
 * content/latoc/landing-page.md and landing-page.vi.md. Reviewer notes,
 * PENDING/decision blockquotes, translator notes and the unapproved
 * acknowledgement are NOT included. The Vietnamese is an unreviewed,
 * machine-assisted draft until a human translator approves it.
 *
 * Rules for this file:
 * - Core blocks only, plus polylang/language-switcher (a dynamic block that
 *   renders nothing without Polylang). Inline brand colours, no theme CSS, no
 *   images (the logo is not checked in yet; the owner adds it in the editor).
 * - The theme prints the page title as the only h1, so content starts at h2.
 * - Links are root-relative (/join/, /signin/); Polylang does not prefix them.
 *   The Vietnamese page adds lang=vi so the app screens open in Vietnamese
 *   (jlife_auth_visitor_locale). Query strings are escaped for the attribute.
 * - Markup mirrors what the block editor serialises, so the owner can edit
 *   every block without a "block recovery" prompt (checked in the editor).
 *
 * Run through `wp eval-file` after WordPress and Polylang are loaded; returns
 * the array and defines no global functions or constants.
 *
 * @package jlife-platform
 */

// phpcs:disable WordPress -- deploy-time seed file, not plugin code.

$red   = '#a12b24'; // Accent / primary action.
$brown = '#4a2e1f'; // Text and dark surfaces.
$cream = '#f7efe0'; // Page background.
$tan   = '#e3d2b4'; // Secondary surface.

/**
 * Wraps content in a block's comment delimiters, as the block editor does.
 */
$blk = static function ( $name, array $attrs, $html ) {
	$json = $attrs ? ' ' . serialize_block_attributes( $attrs ) : '';
	return "<!-- wp:{$name}{$json} -->\n{$html}\n<!-- /wp:{$name} -->";
};

/**
 * Plain text to HTML: escapes, then **bold** and *italic*.
 */
$inline = static function ( $text ) {
	$text = htmlspecialchars( $text, ENT_NOQUOTES, 'UTF-8' );
	$text = preg_replace( '/\*\*(.+?)\*\*/u', '<strong>$1</strong>', $text );
	return preg_replace( '/\*(.+?)\*/u', '<em>$1</em>', $text );
};

/** Paragraph. $small renders the quiet text under a button. */
$p = static function ( $text, $small = false ) use ( $blk, $inline ) {
	if ( $small ) {
		return $blk(
			'paragraph',
			array( 'style' => array( 'typography' => array( 'fontSize' => '0.9rem' ) ) ),
			'<p style="font-size:0.9rem">' . $inline( $text ) . '</p>'
		);
	}
	return $blk( 'paragraph', array(), '<p>' . $inline( $text ) . '</p>' );
};

/** Heading in the accent colour. */
$h = static function ( $level, $text ) use ( $blk, $inline, $red ) {
	$attrs = array(
		'level' => $level,
		'style' => array( 'color' => array( 'text' => $red ) ),
	);
	if ( 2 === $level ) {
		unset( $attrs['level'] ); // 2 is the default and is not serialised.
	}
	return $blk(
		'heading',
		$attrs,
		"<h{$level} class=\"wp-block-heading has-text-color\" style=\"color:{$red}\">" . $inline( $text ) . "</h{$level}>"
	);
};

/** Bulleted list. */
$ul = static function ( array $items ) use ( $blk, $inline ) {
	$lis = array();
	foreach ( $items as $item ) {
		$lis[] = $blk( 'list-item', array(), '<li>' . $inline( $item ) . '</li>' );
	}
	return $blk( 'list', array(), '<ul class="wp-block-list">' . implode( "\n\n", $lis ) . '</ul>' );
};

/** Escapes a URL for a double-quoted attribute (& becomes &amp;, as the editor saves it). */
$attr = static function ( $url ) {
	return htmlspecialchars( $url, ENT_QUOTES, 'UTF-8' );
};

/** One button (a link styled as a button), with the given fill. $href is escaped here (& becomes &amp;). */
$btn = static function ( $label, $href, $bg ) use ( $blk, $inline, $cream, $attr ) {
	$pad   = array(
		'top'    => '0.75rem',
		'bottom' => '0.75rem',
		'left'   => '1.4rem',
		'right'  => '1.4rem',
	);
	$attrs = array(
		'style' => array(
			'color'   => array(
				'background' => $bg,
				'text'       => $cream,
			),
			'spacing' => array( 'padding' => $pad ),
		),
	);
	$style = "color:{$cream};background-color:{$bg};padding-top:0.75rem;padding-right:1.4rem;padding-bottom:0.75rem;padding-left:1.4rem";
	return $blk(
		'button',
		$attrs,
		'<div class="wp-block-button"><a class="wp-block-button__link has-text-color has-background wp-element-button" href="' . $attr( $href ) . '" style="' . $style . '">' . $inline( $label ) . '</a></div>'
	);
};

/** A row of buttons (wraps on a narrow phone). */
$buttons = static function ( array $btns ) use ( $blk ) {
	return $blk( 'buttons', array(), '<div class="wp-block-buttons">' . implode( "\n\n", $btns ) . '</div>' );
};

/** Section panel: brand background and text colour, brand-coloured links. */
$section = static function ( $bg, array $children ) use ( $blk, $brown, $red ) {
	$attrs = array(
		'style'  => array(
			'color'    => array(
				'background' => $bg,
				'text'       => $brown,
			),
			'elements' => array( 'link' => array( 'color' => array( 'text' => $red ) ) ),
			'spacing'  => array(
				'padding' => array(
					'top'    => '2rem',
					'bottom' => '2rem',
					'left'   => '1.25rem',
					'right'  => '1.25rem',
				),
			),
		),
		'layout' => array( 'type' => 'constrained' ),
	);
	$style = "color:{$brown};background-color:{$bg};padding-top:2rem;padding-right:1.25rem;padding-bottom:2rem;padding-left:1.25rem";
	return $blk(
		'group',
		$attrs,
		'<div class="wp-block-group has-text-color has-background has-link-color" style="' . $style . '">' . implode( "\n\n", $children ) . '</div>'
	);
};

/** Language switcher, in the page content (owner decision, #110). */
$switcher = '<!-- wp:polylang/language-switcher {"layout":"horizontal"} /-->';

/**
 * Builds one page from its translated strings.
 */
$build = static function ( array $t, $suffix ) use ( $p, $h, $ul, $btn, $buttons, $section, $switcher, $attr, $red, $brown, $cream, $tan, $inline, $blk ) {
	// The real #112 routes. $suffix is '' (English) or 'lang=vi' (Vietnamese).
	$url    = static function ( $path, array $query = array() ) use ( $suffix ) {
		if ( '' !== $suffix ) {
			$query[] = $suffix;
		}
		return $path . ( $query ? '?' . implode( '&', $query ) : '' );
	};
	$solo   = $url( '/join/', array( 'mode=solo' ) );
	$start  = $url( '/join/', array( 'mode=start' ) );
	$code   = $url( '/join/' );
	$signin_url = $url( '/signin/' );
	$join   = static function () use ( $btn, $t, $red, $brown, $solo, $start, $code ) {
		return array(
			$btn( $t['btn_solo'], $solo, $red ),
			$btn( $t['btn_group'], $start, $red ),
			$btn( $t['btn_code'], $code, $brown ),
		);
	};
	$signin = $blk(
		'paragraph',
		array(),
		'<p>' . $inline( $t['signin_lead'] ) . ' <a href="' . $attr( $signin_url ) . '">' . $inline( $t['signin_link'] ) . '</a></p>'
	);

	// Hero: the three ways in, compactly (buttons only, no small text).
	$hero = $section(
		$cream,
		array(
			$h( 2, $t['hero_h'] ),
			$p( $t['hero_p'] ),
			$buttons( $join() ),
			$signin,
		)
	);

	$what = $section(
		$cream,
		array_merge( array( $h( 2, $t['what_h'] ) ), array_map( $p, $t['what_p'] ) )
	);

	$milestones = $section(
		$tan,
		array_merge( array( $h( 2, $t['mile_h'] ) ), array_map( $p, $t['mile_p'] ) )
	);

	$daily = $section(
		$cream,
		array_merge(
			array( $h( 2, $t['day_h'] ) ),
			array_map( $p, $t['day_p'] ),
			array( $h( 3, $t['soap_h'] ), $p( $t['soap_lead'] ), $ul( $t['soap_li'] ), $p( $t['soap_end'] ) )
		)
	);

	$together = $section(
		$tan,
		array_merge( array( $h( 2, $t['with_h'] ) ), array_map( $p, $t['with_p'] ) )
	);

	// Final call: the full CTA block (each way in with its small text).
	$options = array();
	$routes  = array(
		array( $t['btn_solo'], $red, $t['small_solo'], $solo ),
		array( $t['btn_group'], $red, $t['small_group'], $start ),
		array( $t['btn_code'], $brown, $t['small_code'], $code ),
	);
	foreach ( $routes as $r ) {
		$options[] = $buttons( array( $btn( $r[0], $r[3], $r[1] ) ) );
		$options[] = $p( $r[2], true );
	}
	$final = $section(
		$cream,
		array_merge(
			array( $h( 2, $t['cta_h'] ) ),
			$options,
			array( $p( $t['cta_note'] ), $signin, $p( $t['closing'] ) )
		)
	);

	return $switcher . "\n\n" . implode( "\n\n", array( $hero, $what, $milestones, $daily, $together, $final ) ) . "\n";
};

$en = array(
	'hero_h'      => '104 Days. One Life. A Journey Worth Taking.',
	'hero_p'      => 'Walk through the life of Christ from his birth to his resurrection, one short reading at a time. Starting December 20, 2026.',
	'btn_solo'    => 'Read on my own',
	'btn_group'   => 'Start a group',
	'btn_code'    => 'I have an invite code',
	'signin_lead' => 'Already joined?',
	'signin_link' => 'Sign in',
	'what_h'      => 'What is this journey?',
	'what_p'      => array(
		'For 104 days, we read the four Gospels together as one story, in the order things happened.',
		'Matthew, Mark, Luke and John each tell the life of Christ from a different angle. Here they are woven into a single chronological path, so you can follow Christ step by step: his birth, his teaching, his cross, his resurrection.',
		'Each day is a short reading. You can do it in a few minutes.',
		'The goal is not to finish a book. The goal is to know Christ and follow him.',
	),
	'mile_h'      => 'Two milestones along the way',
	'mile_p'      => array(
		'**Christmas.** The journey begins on December 20. By December 24 and 25 you will be reading the story of his birth, in the very days we celebrate it.',
		'**Easter.** Near the end, you will walk through the cross on Friday, March 26, and the resurrection on Sunday, March 28, in the same days we remember them.',
		'The journey ends on April 2, 2027, with the ascension, the coming of the Holy Spirit, and a conclusion.',
	),
	'day_h'       => 'How each day works: Read, Reflect, Respond',
	'day_p'       => array(
		'**Read.** Open the day\'s passage. It is short.',
		'**Reflect.** Ask what this passage shows you about Christ.',
		'**Respond.** Decide one thing you will believe, change, or do today.',
	),
	'soap_h'      => 'SOAP, a simple way to reflect',
	'soap_lead'   => 'SOAP gives your reflection a shape:',
	'soap_li'     => array(
		'**Scripture:** what did you read?',
		'**Observation:** what do you notice?',
		'**Application:** what does this mean for your life?',
		'**Prayer:** talk with God about it.',
	),
	'soap_end'    => 'A few honest sentences are enough. There is no right answer to perform.',
	'with_h'      => 'Come on your own, or with others',
	'with_p'      => array(
		'**On your own.** Read at your own pace each day and keep your reflections.',
		'**With a group.** Many people will walk this journey with a small group from their church or community, sharing what they are learning and praying for one another. A group is where a journey like this tends to stay alive.',
	),
	'cta_h'       => 'How would you like to join?',
	'small_solo'  => 'Read each day at your own pace. Your reflections stay private.',
	'small_group' => 'For adults. Get an invite link to read together with friends, family or the people you mentor.',
	'small_code'  => 'Someone invited you? Enter their code to join their group.',
	'cta_note'    => 'You can belong to up to three groups, for example one you lead and one with your friends. Sharing a reflection with a group is always your choice, one group at a time.',
	'closing'     => '*This journey is not about points or prizes. It is about knowing and following Christ.*',
);

$vi = array(
	'hero_h'      => '104 Ngày. Một Cuộc Đời. Một Hành Trình Đáng Bước Đi.',
	'hero_p'      => 'Cùng đi qua cuộc đời của Đấng Christ, từ lúc Ngài giáng sinh đến khi Ngài sống lại, mỗi ngày một đoạn đọc ngắn. Bắt đầu từ ngày 20 tháng 12 năm 2026.',
	'btn_solo'    => 'Đọc một mình',
	'btn_group'   => 'Lập một nhóm',
	'btn_code'    => 'Tôi có mã mời',
	'signin_lead' => 'Đã tham gia?',
	'signin_link' => 'Đăng nhập',
	'what_h'      => 'Hành trình này là gì?',
	'what_p'      => array(
		'Trong 104 ngày, chúng ta cùng đọc bốn sách Phúc Âm như một câu chuyện duy nhất, theo đúng thứ tự các sự kiện đã xảy ra.',
		'Ma-thi-ơ, Mác, Lu-ca và Giăng mỗi người kể về cuộc đời Đấng Christ từ một góc nhìn khác nhau. Ở đây, bốn sách được nối lại thành một con đường theo trình tự thời gian, để bạn bước theo Đấng Christ từng bước: sự giáng sinh, lời dạy, thập tự giá và sự phục sinh của Ngài.',
		'Mỗi ngày chỉ là một đoạn đọc ngắn. Bạn có thể đọc trong vài phút.',
		'Mục đích không phải là đọc cho hết một cuốn sách. Mục đích là biết Đấng Christ và theo Ngài.',
	),
	'mile_h'      => 'Hai cột mốc trên đường đi',
	'mile_p'      => array(
		'**Lễ Giáng Sinh.** Hành trình bắt đầu ngày 20 tháng 12. Đến ngày 24 và 25 tháng 12, bạn sẽ đọc câu chuyện Chúa giáng sinh, ngay trong những ngày chúng ta kỷ niệm.',
		'**Lễ Phục Sinh.** Gần cuối hành trình, bạn sẽ đi qua thập tự giá vào Thứ Sáu, ngày 26 tháng 3, và sự phục sinh vào Chúa Nhật, ngày 28 tháng 3, đúng vào những ngày chúng ta tưởng niệm.',
		'Hành trình kết thúc ngày 2 tháng 4 năm 2027 với sự thăng thiên, sự giáng lâm của Đức Thánh Linh, và phần kết.',
	),
	'day_h'       => 'Mỗi ngày diễn ra thế nào: Đọc, Suy ngẫm, Đáp ứng',
	'day_p'       => array(
		'**Đọc.** Mở đoạn Kinh Thánh của ngày. Đoạn này ngắn.',
		'**Suy ngẫm.** Tự hỏi: đoạn này cho tôi thấy điều gì về Đấng Christ?',
		'**Đáp ứng.** Quyết định một điều bạn sẽ tin, thay đổi hoặc làm hôm nay.',
	),
	'soap_h'      => 'SOAP, một cách đơn giản để suy ngẫm',
	'soap_lead'   => 'SOAP giúp bạn ghi lại suy ngẫm theo bốn bước:',
	'soap_li'     => array(
		'**Scripture (Kinh Thánh):** bạn đã đọc điều gì?',
		'**Observation (Quan sát):** bạn nhận thấy điều gì?',
		'**Application (Áp dụng):** điều này có ý nghĩa gì cho đời sống bạn?',
		'**Prayer (Cầu nguyện):** thưa chuyện với Đức Chúa Trời về điều đó.',
	),
	'soap_end'    => 'Vài câu chân thật là đủ. Không cần phải viết cho "đúng".',
	'with_h'      => 'Đi một mình hoặc đi cùng người khác',
	'with_p'      => array(
		'**Một mình.** Đọc theo nhịp của bạn mỗi ngày và giữ lại những suy ngẫm của mình.',
		'**Theo nhóm.** Nhiều người sẽ cùng đi hành trình này trong một nhóm nhỏ của hội thánh hoặc cộng đồng, chia sẻ điều mình học được và cầu nguyện cho nhau. Nhóm giúp hành trình này được duy trì.',
	),
	'cta_h'       => 'Bạn muốn tham gia theo cách nào?',
	'small_solo'  => 'Đọc mỗi ngày theo nhịp của bạn. Những suy ngẫm của bạn được giữ riêng tư.',
	'small_group' => 'Dành cho người lớn. Nhận đường dẫn mời để cùng đọc với bạn bè, gia đình hoặc những người bạn đang dìu dắt.',
	'small_code'  => 'Có người mời bạn? Nhập mã của họ để vào nhóm.',
	'cta_note'    => 'Bạn có thể tham gia tối đa ba nhóm, ví dụ một nhóm bạn hướng dẫn và một nhóm với bạn bè. Việc chia sẻ suy ngẫm với nhóm luôn do bạn chọn, từng nhóm một.',
	'closing'     => '*Hành trình này không phải để lấy điểm hay giải thưởng. Đây là để biết Đấng Christ và theo Ngài.*',
);

// The page title is the theme's one h1. The brand name is the same in both
// languages until the translator decides (translator note 1 in the VI draft).
return array(
	'en' => array(
		'key'     => 'latoc-landing-en',
		'title'   => 'Life and Teaching of Christ (LATOC)',
		'slug'    => 'welcome',
		'content' => $build( $en, '' ),
	),
	'vi' => array(
		'key'     => 'latoc-landing-vi',
		'title'   => 'Life and Teaching of Christ (LATOC)',
		'slug'    => 'chao-mung',
		'content' => $build( $vi, 'lang=vi' ),
	),
);
