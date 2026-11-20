# jlife-scripture

The day's Scripture **on the page** (#139, part 1): a Bible version allowlist, the public-domain **Kinh Thánh 1925** text, a reference parser, a shared server-side passage cache for licensed sources, and a reader with an "Open in Bible Gateway" fallback. **Off by default** (see Settings).

## Why a separate plugin

- Bible text and its licenses are a different concern from participant data: this plugin stores no personal data and needs no privacy gate (it reads the participant's language and, since #140, their own two-key Bible choice; see `docs/challenges-engine-design.md` §4d and §4f).
- Both `jlife-challenges` (Today, `/soap?day=dNNN`) and `jlife-studies` (public `/reading/` pages, #106) use the same reader; neither owns the other.
- It ships its own data (registry and the 1925 text), so a deploy needs no network and the tests read the real files.

## Owns

- **Version registry** `data/bible-versions.json`: the single source of truth for which versions J-Life offers (key, language, name, short code, `source` `local|youversion|apibible|link`, source ID, `display` `native|link`, copyright text and link, cache rule `max_age_seconds` / `max_consecutive_verses`, `license_evidence`, `enabled`, `default_for`) plus each language's **fallback** Bible Gateway link version and `embed` flag. The reading-plan builder takes `links_config` from it (it replaced `content/reading-plans/bible-links.json`). Only **enabled + native + license evidence + a registered source** renders text (`jlife_scripture_can_render()`); `bin/validate-scripture.js` checks the rules in CI.
- **Kinh Thánh 1925** `data/vie1934/`: eBible.org `vie1934` USFM converted by `bin/build-scripture-vie1934.js` (one TSV per USFM book + `manifest.json` with source URL, edition, source zip SHA-256, per-file SHA-256s). Imported into `{base_prefix}jlife_scripture_verses` by `wp jlife scripture import`.
- **Reference parser** (`includes/references.php`): the PHP twin of `bin/content-lib/scripture.js`; partial verses, ranges, chapter-crossing ranges, comma continuations, multi-passage days; English names, abbreviations and the Vietnamese names → USFM codes.
- **Sources** behind `Jlife_Scripture_Source`: `local` (the table), `youversion` (stub: no network, always unavailable until the owner gates clear). Add API.Bible (#141) by implementing the interface (and `Jlife_Scripture_Display_Tracking` for FUMS) and registering it in `includes/sources.php`.
- **Passage cache** (`includes/cache.php`, `{base_prefix}jlife_scripture_cache`): one shared row per source + version + passage, a lock against concurrent first fetches, the version's cache rule on every read (a source's per-response rule can only shorten it), a verse-limit check before and after a fetch, generic errors, logs with identifiers only. Local text bypasses it. A daily cron and `wp jlife scripture cache-purge` delete expired rows.
- **Reader** (`includes/render.php`, `assets/scripture.css`).

## Using the reader

```php
// Returns null while the flag is off: keep the current reader.
$native = function_exists( 'jlife_scripture_maybe_render_day' )
	? jlife_scripture_maybe_render_day( $day, $user_id ) // $day = reading-plan day array; $user_id 0 = visitor.
	: null;
```

Which Bible a participant reads comes from `jlife_scripture_versions_for_user( $user_id )` → `[ first, second|null ]` (registry entries; #140). Order: the participant's **own choice** (when the registry still lists it as `enabled`), then the registry's `default_for` Bible for **My language**, then the site default. My language is the account's `locale` user meta (`vi*` → Vietnamese, else English) unless the `jlife_scripture_user_language` filter answers otherwise (`jlife-challenges` answers with the reminder-email language chosen on My Marathon); a visitor gets the page's locale and the language's default. A version that cannot show text yet (NIrV until it is licensed) shows the language's fallback link (today: English NIrV → ERV link; Vietnamese → Kinh Thánh 1925 text). A second Bible, when chosen, renders under the first for every passage; the chosen version's own language decides its labels and `lang`. `render_day( …, array( 'language' => 'vi' ) )` (the public `/reading/` pages) shows that language's default and ignores any stored choice.

### A participant's Bible choice (#140)

| Item | Where |
|---|---|
| Storage | Two user-meta values on the person's own account: `jlife_bible_version` (a registry key; absent = follow My language) and `jlife_bible_version_2` (optional second Bible). Registry keys only. Matrix: `docs/challenges-engine-design.md` §4d |
| Save | `jlife_scripture_set_user_choice( $user_id, array( 'primary' => …, 'secondary' => … ) )` → `true` or `jlife_bible_unknown` (a key the registry does not list as `enabled`). A slot left out keeps its value; `''`/`null` clears. A second Bible equal to the first is dropped |
| Read | `jlife_scripture_versions_for_user()`, `jlife_scripture_user_choice()`; callers pass the viewer's own ID only |
| Picker data | `jlife_scripture_selectable_versions()` → language => key => entry, enabled versions only, registry order; `jlife_scripture_can_render()` tells which open Bible Gateway instead of showing text |
| UI | My Marathon (`jlife-challenges`, `includes/bible-choice.php`) |

Wired in: **Today and `/soap`** (`jlife_challenges_render_reader()` in `jlife-challenges` calls `jlife_scripture_maybe_render_day( $day, get_current_user_id() )` when the option below is on; the framed Bible Gateway reader is the flag-off path, and the standalone page prints this plugin's stylesheet), and the **public `/reading/` day pages** (`jlife_studies_render_native_reading()` in `jlife-studies` calls `jlife_scripture_maybe_render_day( $day, 0, array( 'language' => <page language> ) )`: user 0 and a forced language, so it never reads a stored choice and the output is the same for every visitor; the link blocks are the flag-off path). The option is per site: turn it on on each site that serves these pages.

## Settings

- `jlife_scripture_native_reader` (per-site option, `'0'` default / `'1'`): turns the reader on for the screens that call `jlife_scripture_maybe_render_day()` (Today and `/soap`). Turn it on per site with `wp option update jlife_scripture_native_reader 1`; back to `0` restores the framed reader at once. It is never switched on by code or by a deploy.
- Defaults and fallbacks: edit `data/bible-versions.json` (`default_for`, `languages.<lang>.fallback`), then `npm run plans:build`. No code change.

## WP-CLI

| Command | What it does |
|---|---|
| `wp jlife scripture import [vie1934] [--force]` | Verify the dataset's checksums and import it; records source URL, edition and checksums; no-op when unchanged |
| `wp jlife scripture status` | Flag, each language's version, dataset provenance (no text) |
| `wp jlife scripture cache-purge [--all]` | Delete expired cached passages |

## Tests

PHPUnit (`tests/`): registry allowlist and defaults, the parser against every reference in both plans, the 1925 text and its provenance, the cache with a fake source (one fetch per passage, max age, lock, verse limit, failures, display tracking), the reader, logs and errors without Bible text or keys, Vietnamese drafts.
