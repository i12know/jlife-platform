# Polylang on the latoc public landing page
Issue: #110 (parent #49; D13 in #55) · Timebox: 1 day · Actual: ~0.5 day · Run: 2026-11-18

## Verdict: GO-WITH-CONDITIONS

Free Polylang 3.9.2 can safely manage a bilingual public landing page on latoc: English at `/`, Vietnamese at `/vi/`, a visible keyboard-friendly switcher, and pages the owner edits in wp-admin. In a replica of latoc, every app route (`/today /soap /marathon /share /group /signout /join /signin /consent`, `/reading/<plan>-dNNN/`, `/wp-admin`, `/wp-login.php`) gave the same status and redirect chain with Polylang as without it, and uninstalling Polylang left only the two plain pages behind. It is not a drop-in: it works only with the conditions below, several of which silently break it if missed.

**Conditions (all verified below):**

1. **Activate per site, never network-wide** (§1).
2. **Set `redirect_lang` on** (the "front page URL contains the language code" setting). With the defaults, `/vi/` 301-redirects to the VI page's own slug and is never served directly (§2).
3. **Pin 3.9.2** and install it from the deploy, with checksum verification. Install the `vi` language packs for core, the active theme and Polylang, or the VI page's chrome (switcher label, 404s, feeds) stays English (§5, §7).
4. **Keep Polylang's translated content types at the default (`page`, `post` only).** The jlife custom post types and taxonomies are then untranslated, as they must be (§3).
5. **No subsite may ever use the slug `vi` (or `en`)**. A subsite there takes over `/vi/` (§2).
6. **One switcher placement only**: in the page content (what the deploy seeds) or in the header via the Site Editor, not both (§6).
7. **Verify on the real latoc host** the things a PHP built-in server cannot show (§Risks), before launch.
8. The app's Vietnamese-readiness is **separate from Polylang**: `/join/` has no Vietnamese strings today, so the VI page's CTA lands on an English form (§Owner decisions).

Owner decisions and the code/deploy work still needed are listed at the end. Nothing here was run against the real latoc host; no deploy workflow was run.

## Question

Can Polylang manage a public EN/VI landing page on latoc (subdirectory multisite, STUDY site only at root, with `jlife-studies`, `jlife-huddles`, `jlife-challenges`, `jlife-auth`) without breaking the custom routes, the participant auth redirects, the `/reading/` pages, D13 (the app follows each user's chosen language) or the noindex posture, while the owner edits both pages directly in wp-admin and the deploy only creates them the first time? What is the fallback if it cannot?

## What we did

Replica of latoc, built the way `bin/deploy/latoc-remote.sh` builds it:

| Item | Value |
|---|---|
| WordPress | 7.0 (the version pinned in `.wp-env.json`), subdirectory multisite, one site (STUDY) at `/` |
| Plugins active | `jlife-studies`, `jlife-huddles`, `jlife-challenges`, `jlife-auth` (no `jlife-bridge`, as at launch) |
| Settings copied from the deploy | `blog_public 0`; permalinks `/%postname%/`; both reading plans imported (104 `jlife_reading_day` posts each) |
| Theme | Twenty Twenty-Five 1.5, the default theme the deploy leaves active (it installs no theme) |
| Polylang | free 3.9.2 from wordpress.org (released 2026-11-09, GPL-3.0-or-later, requires WP 6.8 / PHP 7.4; `wp plugin verify-checksums` passed) |
| Browser checks | Google Chrome driven by Playwright 1.63 (keyboard, block editor, Pages list) |

**Environment deviations, stated plainly.** Per docs/dev-environment.md §7a this cloud session cannot finish `wp-env`'s Docker builds, so the network came from `npm run cloud:up` (MariaDB in Docker, PHP 8.3 built-in server with `bin/cloud-dev-router.php`, WP-CLI 2.11.0), and the latoc replica is a second database and WordPress copy beside it, at `http://localhost:8890` (the dev network also has a HUB site, which latoc does not). So: **PHP 8.3, not the host's 8.2; PHP's built-in server, not Apache with the deploy's `.htaccess`.** wordpress.org was reachable, so nothing was faked. Throwaway scaffolding (a probe mu-plugin, a language pack, two test users with `.test`/`example.test` addresses) lived only in the git-ignored `.cloud-dev/` copy. No plugin code was changed.

Method: record a baseline of 35 URLs before Polylang (status, redirect chain, `<html lang>`, canonical, robots), then repeat after each step and diff.

## Findings

### 1. Network-activate vs per-site: per-site is correct

| | Network-activate | Per-site (STUDY only) |
|---|---|---|
| `wp plugin list` on latoc | `active-network` | `active` |
| Same on a scratch `/hub/` subsite | `active-network`; Polylang created its own `polylang` option row there on first load | `inactive`; no Polylang rows |
| `active_sitewide_plugins` | `polylang/polylang.php` | `a:0:{}` |

Network activation switches Polylang on for every current and future subsite, including the future private HUB (Disciple.Tools). Languages and settings are stored per site anyway, so network activation buys nothing here and adds a plugin to HUB that nobody has tested there (not tested with Disciple.Tools in this spike). The deploy already activates the jlife plugins per site; do the same: `wp plugin activate polylang` (no `--network`).

### 2. `/` serves English, `/vi/` serves Vietnamese directly: yes, with one non-default setting

Settings used (exact): `force_lang 1` (language from the directory name), `hide_default true` (no `/en/`), `browser false` (no browser-language detection), `redirect_lang true`, `rewrite true`, `media_support false`, `default_lang en`; languages `en` (locale `en_US`, name "English") and `vi` (locale `vi`, name "Tiếng Việt"); no flags.

With Polylang's *default* `redirect_lang false`, and EN/VI pages linked as the static front page translations:

```
GET /vi/            301 -> /vi/chao-mung/      (the VI page's own slug; /vi/ is never served)
```

Polylang then reports the VI language's home URL as the page permalink. Setting `redirect_lang` on and clearing the cached language list fixes it:

```
GET /               200  lang="en-US"  canonical /
GET /vi/            200  lang="vi"     canonical /vi/
GET /vi             301 -> /vi/
GET /vi/chao-mung/  301 -> /vi/        (VI page slug, canonicalised)
GET /welcome/       301 -> /           (EN page slug)
GET /vi/welcome/    301 -> /
GET /?page_id=<vi>  301 -> /vi/
GET /?lang=vi       301 -> /vi/?lang=vi (200 after; canonical /vi/)
GET /en/            404
```

Gotcha found the hard way: the language list (including each language's front page) is cached in the `pll_languages_list` transient. After creating the pages and setting the front page, `/vi/` still behaved as above until the cache was rebuilt in a **fresh process**. In-process `clean_cache()` was not enough in the `wp eval-file` run; the deploy therefore runs `wp transient delete pll_languages_list` then `wp rewrite flush` after the script (see §7).

**Clash with multisite slugs.** Created a scratch subsite with slug `vi`: `GET /vi/` served that subsite's title and `/vi/chao-mung/` returned 404, so the subsite shadows the language. A subsite at `/hub/` is unaffected (`/hub/` 200, `/hub/vi/` 404, `/hub/wp-admin/` redirects normally). WordPress does not reserve `vi` or `en`. Rule: never create a subsite with a language slug. (The deploy script does not create sites, so this is a documentation rule for whoever provisions tenants later.)

### 3. Regression of the custom routes: identical

Diff of 35 requests, baseline vs Polylang active with both pages and front page set (status, redirect target, final URL, `<html lang>`, canonical, robots): **the only differences are the new `/vi/...` URLs and the front page getting a canonical link**.

| Route | Before Polylang | With Polylang |
|---|---|---|
| `/today /soap /marathon /share /group` (logged out) | 301 to `/x/`, then 302 to `/signin/?redirect_to=http%3A%2F%2F<host>%2Fx%2F` | same chain, same `redirect_to` |
| `/signout` | 301, 302 to `/signin/` | same |
| `/join`, `/signin` (+ `?redirect_to=%2Ftoday%2F`) | 301 to slash form, 200 | same; `redirect_to` preserved |
| `/consent` (logged out) | 301, 302 to `/signin/` | same |
| `/reading/life-and-teachings-of-jesus-2026-d001/` and `-d104/` | 200, canonical self | same (canonical self, no hreflang) |
| `/reading/` | 404 | 404 |
| `/wp-login.php` | 200 | 200 |
| `/wp-admin/`, `/wp-admin/network/` | 302 to `wp-login.php?redirect_to=...&reauth=1` | same |
| `/?p=1`, `/?page_id=2` | 301 to the pretty permalink (`/blog/hello-world/`) | same |
| `/wp-json/`, `/feed/`, `/robots.txt` | 200 | 200 |
| `/vi/today /vi/join /vi/signin /vi/reading/...` | 404 | **404** (`<html lang="vi">`), no redirect, no loop |

- **No loops or redirects** for `/vi/today`, `/vi/join`: they 404, because the app's rewrite rules are `top` rules anchored at the site root and Polylang only rewrites the language prefix for content it manages. The VI landing page must therefore link to the unprefixed routes (`/join/`), which the seed does.
- Logged-in participants (a `vi` and an `en` user) got the same status, `<html lang>`, nav and headings on `/join /signin /signout /today /marathon /` with and without Polylang.
- **Custom post type and taxonomies stay untranslated**: with default settings `pll_is_translated_post_type()` is true only for `post` and `page`; false for `jlife_reading_day`, `jlife_lesson`, `jlife_series` and for `gospel_phase`, `gospel_event`, `scripture_ref` (true for the core `category`/`post_tag`). All 104 reading days have no language. Do not tick them under Languages > Settings.
- **Canonical URLs**: unchanged on every app and reading page. New: `/` and `/vi/` carry self canonicals. One pre-existing edge: unlanguaged pages/posts (WordPress's own Sample Page etc.) were served under `/vi/` with a canonical pointing at the unprefixed URL; assigning them the default language (the deploy does) makes `/vi/sample-page/` 301 to `/sample-page/`.
- **`home_url()`**: unchanged. On a `/vi/` request `home_url('/join/')` and `wp_logout_url(home_url('/signin/'))` still return the unprefixed URLs, so `jlife-auth`'s `redirect_to`/`home_url()` use is unaffected.

### 4. Language leakage into the app (D13): none observed

A scratch probe printed `get_locale()`, `determine_locale()`, `get_user_locale()`, a translated string from `jlife-challenges` (`Save draft` / `Lưu bản nháp`) and a core string (`Log In` / `Đăng nhập`) on app and public URLs for an anonymous visitor, a user whose locale is `vi`, and a user with the default locale, with Polylang on and off (Vietnamese core pack installed so a leak would show).

- On every unprefixed route (`/join/ /signin/ /today/ /`, `/sample-page/`) the output is **identical on and off**, apart from `pll_current` being `en`.
- `/vi/` is the only place where the locale changes: `get_locale` and `determine_locale` are `vi` for everyone, including a user with the default locale. That is the landing page, not an app screen.
- Why D13 holds: Polylang's front end filters `locale`, which would make `get_locale()` return the language of the URL (`en_US` on app routes), but `jlife-auth`'s `determine_locale` filter (`jlife_auth_front_locale`) runs afterwards and still returns the signed-in user's chosen locale (the `vi` user: `get_locale` `en_US`, `determine_locale` `vi`, both with and without Polylang). The app's `<html lang>` and string loading use `determine_locale()`. **This protection is code that exists today, not a Polylang guarantee.** Anything that switches to `get_locale()` would pick up Polylang's value. Worth a small CI test.
- Side effect on public pages only: a signed-in `vi` participant viewing the English landing page `/` gets `determine_locale` `vi` (theme chrome in Vietnamese, page content English). Harmless; noted.
- **Cookie.** Polylang sets `pll_language=<en|vi>` (1 year, `SameSite=Lax`, path `/`) on every front-end response, including `/join/`. With browser detection off it has no effect on later visits: a jar holding `pll_language=vi` still got `/`, `/join/`, `/signin/` in English; a forged `pll_language=vi` plus `Accept-Language: vi-VN` at `/`, `/join/`, `/today/` changed nothing. It is a functional cookie, but it appears on pages that otherwise set none, so mention it in any cookie/privacy note and check the host's page cache (Risks).

### 5. Page output

On `/` and `/vi/` (Twenty Twenty-Five):

```
<html lang="en-US">  /  <html lang="vi">
<link rel="alternate" href="http://<host>/"    hreflang="en" />
<link rel="alternate" href="http://<host>/vi/" hreflang="vi" />
<link rel="canonical" href="http://<host>/" />   (/vi/ on the VI page)
<meta name='robots' content='noindex, nofollow' />      (core, from blog_public 0; unchanged)
```

- Present on both pages and unchanged by Polylang: `noindex, nofollow`, and `X-Robots-Tag: noindex, nofollow` on the app screens. Removing noindex at launch stays the single `blog_public` change.
- hreflang alternates are on both pages. **No `x-default`** is emitted; add one only if wanted (small filter, or not needed while noindex).
- Switcher markup: `<div role="navigation" class="pll-switcher ..." aria-label="Choose a language"><ul>`, links with `lang`, `hreflang` and `aria-current="true"` on the current language; plain `<a>` elements.
- **Keyboard**, tested in Chrome at phone width: Tab order was skip link, site title, menu button, "English", "Tiếng Việt"; every stop had a visible outline (2px solid); Enter on "Tiếng Việt" loaded the VI page (`<html lang="vi">`, VI heading), Tab to "English" and Enter returned to `/`. The CTA is `href="/join/"` on both pages.
- The switcher's `aria-label` follows the page language (`Chọn một ngôn ngữ` on the VI page) only when the Polylang `vi` language pack is installed (`wp language plugin install polylang vi`); without it, it stays English. The same applies to the theme and core packs.
- The document `<title>` of the static front page is the **site title** in both languages ("J-Life (staging)"). Polylang (free) translates it through Languages > Translations (strings); verified via the PHP API that a VI value is then used (`<title>J-Life (bản thử nghiệm)</title>` on `/vi/`). The owner sets it once in wp-admin (or the deploy can).
- Heading order: the theme prints the page title as `<h1>`; seeded content must start at `<h2>` (the spike's placeholder did).

### 6. Owner editing in the block editor: works without code

Driven in a real browser as a super admin:

- **Pages > language columns.** The Pages list gets an "EN" and a "VI" column per row, a pencil to the linked translation, a "+" where one is missing, plus language views ("English (1) | Tiếng Việt (1)") and an admin-bar language filter.
- **Add the VI translation from the EN page.** For an EN page with no translation, clicking "+" in the VI column opened `post-new.php?post_type=page&from_post=<en>&new_lang=vi`; a title and paragraph were entered and the page published. Result: VI page at `/vi/trang-thu-nghiem-vi/`, and the list showed each page with a pencil to the other. No code.
- **Editor sidebar.** The document sidebar has a "Languages" panel with the language select and "Translations" (with "Edit the translation in Tiếng Việt").
- **Editing an existing page.** Real typing into the EN page's paragraph and Ctrl+S saved normally (see §7 for the deploy not undoing it).
- **Switcher without code**: the Polylang "Language Switcher" block (`polylang/language-switcher`, alignment/layout settings) works in two ways, both tested. (a) In the page content: the seed includes the block in each page, so it shows on both pages; the owner can move or restyle it. (b) In the header via the Site Editor: saving the header template part with the block (the same REST call the Site Editor makes) showed the switcher on `/`, `/vi/` and every theme page. **With both, two switchers appear.** The app screens (`/join/` etc.) are standalone pages, not theme templates, and never show it (zero occurrences). A switcher on a page with no translation links to the other language's home page (`no-translation` class).
- Not tested: the Navigation-block variant (`polylang/navigation-language-switcher`), and the Site Editor UI itself (only its REST call).

### 7. Deploy automation with WP-CLI: idempotent, never overwrites

The tested step is below. Run on a clean state (Polylang uninstalled with all data, pages deleted) then re-run; the table shows each run.

| Run | Condition | Result |
|---|---|---|
| 1 | fresh | Polylang 3.9.2 installed and checksum-verified, activated per site; `vi` core/theme/Polylang packs installed; languages and options set; pages 119 (EN) and 120 (VI) created, linked, EN set as front page; `/` and `/vi/` 200 |
| 2 | immediately again | options re-asserted; "already seeded: content left untouched"; page IDs, modified dates, content hashes, front page and link identical to run 1 |
| 3 | after the owner typed into the EN page in the block editor and saved | edit present in the database and on `/` after the run; modified date and hash unchanged by the deploy |
| B | seeded flag deleted, pages exist | both found by marker: "exists (not touched)" |
| A | owner trashed the VI page, flag set | not recreated; the trashed page stays in Trash |
| C | VI permanently deleted and flag deleted | only VI created, linked to the untouched EN page, front page left alone |

Design points:

- **Configuration is re-asserted on every run** (languages, URL options), because URLs must not drift and GitHub is where that is managed. **Content is created only if missing**: a once-only `jlife_landing_seeded` option, plus marker meta `_jlife_landing_seed` on each page so a partial first run can finish. After the flag is set, a deleted page is **not** recreated (trash it, or delete the option deliberately).
- The front page is set only when none is set; an owner's later choice is never replaced.
- Pages and posts that exist without a language (WordPress samples) get `en`.
- The pinned install reinstalls the pinned version when the installed one differs, so a stray update is reverted. Keep auto-updates off.
- Polylang free has no WP-CLI commands for languages; the PHP API (`PLL()->model->languages->add`, `pll_set_post_language`, `pll_save_post_translations`) used through `wp eval-file` is the supported route.

**Step to add to `bin/deploy/latoc-remote.sh`** (after `wp plugin activate "${PLUGINS[@]}"`, before the reading-plan import):

```bash
POLYLANG_VERSION="3.9.2"
log "Polylang ${POLYLANG_VERSION} (pinned)"
current="$(wp plugin get polylang --field=version 2>/dev/null || true)"
if [ "${current}" != "${POLYLANG_VERSION}" ]; then
  wp plugin install polylang --version="${POLYLANG_VERSION}" --force
fi
wp plugin verify-checksums polylang || die "Polylang checksum verification failed."
wp plugin activate polylang # per site: never --network
wp language core install vi
wp language plugin install polylang vi
wp language theme install "$(wp theme list --status=active --field=name)" vi
wp eval-file "${STATE_DIR}/latoc-landing.php"   # uploaded with the plugin bundle (the staging dir is deleted later)
# The language list (with each language's front page) is cached; rebuild it in a fresh process.
wp transient delete pll_languages_list >/dev/null 2>&1 || true
wp rewrite flush >/dev/null
```

**`latoc-landing.php`** (the file that was run, with the two page definitions abbreviated in the comment below; the spike used placeholder copy, the real copy comes from `content/latoc/` when it exists):

```php
<?php
if ( ! function_exists( 'pll_set_post_language' ) ) {
	WP_CLI::error( 'Polylang is not active on this site.' );
}

// ---- Configuration: re-asserted on every run ----
$languages = PLL()->model->languages;
$have      = pll_languages_list();
foreach ( array(
	array( 'locale' => 'en_US', 'slug' => 'en', 'name' => 'English' ),
	array( 'locale' => 'vi', 'slug' => 'vi', 'name' => 'Tiếng Việt' ),
) as $lang ) {
	if ( in_array( $lang['slug'], $have, true ) ) {
		continue;
	}
	$added = $languages->add( $lang );
	if ( is_wp_error( $added ) ) {
		WP_CLI::error( 'Could not add language ' . $lang['slug'] . ': ' . $added->get_error_message() );
	}
	WP_CLI::log( 'language added: ' . $lang['slug'] );
}
$o                  = PLL()->options;
$o['default_lang']  = 'en';
$o['force_lang']    = 1;     // language from the directory name: /vi/
$o['hide_default']  = true;  // English has no prefix: /
$o['redirect_lang'] = true;  // /vi/ serves the VI front page itself
$o['browser']       = false; // no browser-language detection or redirect
$o['rewrite']       = true;  // no /language/ base
$o['media_support'] = false;
$o['post_types']    = array(); // only page and post are translated
$o['taxonomies']    = array();
$languages->clean_cache();
WP_CLI::log( 'polylang options asserted' );

// Existing pages/posts without a language (WordPress samples) get the default.
foreach ( get_posts( array(
	'post_type' => array( 'page', 'post' ), 'post_status' => 'any', 'numberposts' => -1,
	'fields' => 'ids', 'lang' => '', 'suppress_filters' => false,
) ) as $existing_id ) {
	if ( ! pll_get_post_language( $existing_id ) ) {
		pll_set_post_language( $existing_id, 'en' );
	}
}

// ---- Content: create only if missing, never update ----
if ( get_option( 'jlife_landing_seeded' ) ) {
	WP_CLI::log( 'landing pages already seeded once: content left untouched' );
	flush_rewrite_rules();
	return;
}
$find = static function ( $key ) {
	$ids = get_posts( array(
		'post_type' => 'page',
		'post_status' => array( 'publish', 'draft', 'pending', 'private', 'future', 'trash' ),
		'meta_key' => '_jlife_landing_seed', 'meta_value' => $key,
		'numberposts' => 1, 'fields' => 'ids', 'lang' => '', 'suppress_filters' => false,
	) );
	return $ids ? (int) $ids[0] : 0;
};
$pages = array( /* 'en' and 'vi': key, title, slug ('welcome' / 'chao-mung'), block markup incl.
                   <!-- wp:polylang/language-switcher {"layout":"horizontal"} /--> and a CTA to /join/ */ );
$ids = array();
$created = false;
foreach ( $pages as $code => $p ) {
	$id = $find( $p['key'] );
	if ( ! $id ) {
		$id = wp_insert_post( array(
			'post_type' => 'page', 'post_status' => 'publish', 'post_title' => $p['title'],
			'post_name' => $p['slug'], 'post_content' => $p['content'],
		), true );
		if ( is_wp_error( $id ) ) {
			WP_CLI::error( $id->get_error_message() );
		}
		update_post_meta( $id, '_jlife_landing_seed', $p['key'] );
		pll_set_post_language( $id, $code );
		$created = true;
	}
	$ids[ $code ] = (int) $id;
}
if ( $created ) {
	pll_save_post_translations( $ids ); // link EN <-> VI
	if ( 'page' !== get_option( 'show_on_front' ) || ! (int) get_option( 'page_on_front' ) ) {
		update_option( 'show_on_front', 'page' );
		update_option( 'page_on_front', $ids['en'] );
	}
}
update_option( 'jlife_landing_seeded', '1', false );
$languages->clean_cache();
flush_rewrite_rules();
```


### 8. Rollback: clean

State before: both pages, links, front page, languages. Then:

1. `wp plugin deactivate polylang`: `/` still serves the EN page; `/vi/` 404; the VI page now answers at its plain slug (`/chao-mung/`, 200) because it is just a page; the switcher block renders nothing; `/join/ /today/ /reading/... /wp-login.php /wp-admin/` unchanged.
2. `wp plugin uninstall polylang` alone **leaves all Polylang data** (2 language terms, translation groups, the `polylang` option and `pll_*` options, per-post language terms). Polylang's `uninstall.php` only deletes data when the constant `PLL_REMOVE_ALL_DATA` is defined and true.
3. With the data-removal opt-in (a WP-CLI `--require` file that defines the constant, then `wp plugin uninstall polylang`): all language/translation taxonomy rows, options and `_pll*` meta were gone; the 6 pages (and their content) remained.
4. Route probe after full removal vs baseline: identical except `/` now has a canonical link (the static front page). Pages, the front page setting and the owner's edits survive.

Rollback runbook: `wp plugin deactivate polylang`; set the VI page to draft (otherwise it is public at `/<slug>/`); remove the `polylang/language-switcher` block from the EN page (without the plugin it renders nothing, and the editor shows it as unsupported); `wp --require=<file defining PLL_REMOVE_ALL_DATA> plugin uninstall polylang`; `wp rewrite flush`. The database backup the deploy takes first covers the rest.

## Fallback and its cost: two ordinary pages and our own switcher (no Polylang)

Prototyped and run in the replica after the rollback: EN page (slug `home`, set as the front page) at `/`, VI page (slug `vi`) at `/vi/`, and one small site plugin of about 50 lines:

- an option `jlife_landing_pair` (`{"en": <id>, "vi": <id>}`) set by the deploy when it creates the pages;
- a `language_attributes` filter so the VI page prints `<html lang="vi">`;
- a `wp_head` action printing `hreflang` alternates for both pages plus `x-default`;
- a `[jlife_lang_switch]` shortcode (or a block) printing `<nav aria-label="Language / Ngôn ngữ">` with `<a lang hreflang aria-current>` links, which the pages include.

Observed: `/` 200 `lang="en-US"`, `/vi/` 200 `lang="vi"`, `/home/` 301 to `/`, `/vi` 301 to `/vi/`; `hreflang` en, vi and x-default on both; every app route in the 35-URL probe identical to baseline except the new landing URLs; the VI page's `<title>` is its own page title, with no string-translation step. No rewrite rules, no cookie, no cache or locale interaction.

Cost, honestly:

| | Polylang | Fallback |
|---|---|---|
| Code we own | ~130 lines of deploy PHP/bash | ~50 lines plugin + the same deploy seeding (pages, option) |
| Owner experience | Language columns, "+" to add a translation, switcher block, language filter | Two normal pages; the owner must keep the two in step and add the switcher shortcode/block by hand; no language UI |
| Switching between paired pages | automatic (also for later pages) | only the pair we list; any third page needs code |
| Compatibility risk | a third-party plugin on every front-end request; security updates to track (3.9.2 itself fixes a private-post-metadata exposure) | none from third parties |
| Needed to ship | deploy step, language packs, tests | plugin, PHPCS/PHPStan/PHPUnit tests (access-control tests are required by CLAUDE.md for new surfaces), translation-ready strings (the aria-label above is not yet in a text domain) |
| Effort estimate | about half a day to wire into the deploy and test on staging | about one day including CI tests |

It meets the issue's functional needs for exactly two pages; it does not scale to a third language or a larger public site, which Polylang does. Per the issue, any move to it needs the owner's agreement first.

## Risks and open checks

- **Not verified on the real host.** PHP 8.3 vs the host's 8.2; PHP's built-in server vs Apache. Check on staging: no real `vi/` or `en/` directory in the document root; the multisite `.htaccess` rules do not intercept `/vi/`; `/vi/` 200 and `/` 200 in the workflow's smoke check (add a `/vi/` check); the host's page cache (Bluehost) with the `Set-Cookie: pll_language` on public pages; PHP 8.2 loads Polylang cleanly (it requires 7.4).
- **Plugin dependence.** Polylang updates ship regularly (3.9.2 was a security fix). The deploy pins and reinstalls the pin, so updating means a reviewed PR that bumps `POLYLANG_VERSION`, re-checks this spike's route diff, and lets wp.org checksums run. Free version only; Pro features (machine translation, REST/CLI language management, locale fallbacks) are not relied on.
- **Duplicate switcher** if the header and the content both have one (seen, §6).
- **Language list cache**: if `/vi/` ever 301s to the VI page's slug again, rebuild the cache (`wp transient delete pll_languages_list`, `wp rewrite flush`).
- **A pre-set site language other than English** would be overridden on the public front end by Polylang's `locale` filter; keep the site language English.
- **Owner can break routing in the Languages settings screen** (turning off "hide default language", or changing the URL modification). The deploy re-asserts these on each run; tell the owner not to change them in wp-admin.
- **D13 holds only through `jlife_auth_front_locale`** (§4); add a regression test before changing that filter.
- **Hosting and PRD §21.** No participant data, credentials or partner details are involved; the test users used `example.test` addresses and exist only in the scratch copy.

## Parts that still need code or the deploy

1. **Deploy**: the step above in `bin/deploy/latoc-remote.sh`; add `latoc-landing.php` (and the seed copy) to the workflow's bundle in `.github/workflows/deploy-latoc.yml`; add `/vi/` to the smoke test; update `docs/staging-latoc.md` (Pieces, Deploy, Rollback) and the CHANGELOG.
2. **Seed copy**: real block markup for both pages, authored from `content/latoc/landing-page.md` and `landing-page.vi.md` once reviewed (the owner edits the live pages afterwards). Start the content at `<h2>`; use root-relative links (`/join/`); alt text on the logo; the VI text must be human-reviewed.
3. **Logo and styling**: import the logo into the Media Library (master stays in `design/latoc/`); any CSS/pattern work goes through GitHub.
4. **Switcher placement decision** (page content as seeded, or header via the Site Editor): pick one.
5. **VI site title/tagline** via Languages > Translations (or the deploy).
6. **Tests**: a CI route test (the rows of §3 and a locale probe for D13) so a future Polylang bump or jlife route change is caught.
7. **jlife-auth Vietnamese strings** (owner-scheduled, not part of this issue): `plugins/jlife-auth/languages/` is empty and only a few SOAP strings are translated in `jlife-challenges`, so `/join/` and `/signin/` are English even for a Vietnamese reader.

## Owner decisions needed

- **Go ahead with Polylang under these conditions?** (This spike says yes. The fallback costs about a day and gives up the language UI.)
- **Where should the Vietnamese CTA go today?** `/join/` is English-only, and there is no Polylang-managed `/vi/join/`. Either accept an English form for Vietnamese visitors on Dec 20, or schedule Vietnamese strings for `jlife-auth` (and `jlife-challenges`) first. This is a pre-existing gap, not caused by Polylang, but the VI landing page makes it visible.
- **Switcher placement**: in each page's content (default of the seed, editable by the owner) or in the site header.
- **Deleting a seeded page for good**: the deploy will not recreate it (trash or delete the `jlife_landing_seeded` option on purpose). Is that the behaviour the owner wants?
- **Cookie notice**: is a note about Polylang's `pll_language` cookie wanted anywhere?

## Consequences

- Closes the Polylang compatibility question in #110 (acceptance criterion "Polylang compatibility on LATOC staging verified") for the replica; the real-host checks above remain before launch.
- Sets the shape of delivery step 3 in #110: deploy-installed pinned Polylang, create-only-if-missing pages, no site plugin to render stored copy.
- Does not change D13 or the decision to keep Polylang out of the app UI: app screens remain unprefixed, untranslated by Polylang and locale-driven by the participant's choice.
