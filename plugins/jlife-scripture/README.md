# jlife-scripture

The day's Scripture **on the page** (#139, part 1): a Bible version allowlist, the public-domain **Kinh Thánh 1925** text, a reference parser, a shared server-side passage cache for licensed sources, and a reader with an "Open in Bible Gateway" fallback. **Off by default** (see Settings).

## Why a separate plugin

- Bible text and its licenses are a different concern from participant data: this plugin stores no personal data and needs no privacy gate (it reads only the participant's `locale` to pick a language; see `docs/challenges-engine-design.md` §4f).
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

The participant's `locale` user meta picks the language (`vi*` → Vietnamese, else English); a visitor gets the page's locale. Each language shows its `default_for` version when it can render, otherwise the language's fallback link (today: English NIrV → ERV link; Vietnamese → Kinh Thánh 1925 text).

## Settings

- `jlife_scripture_native_reader` (per-site option, `'0'` default / `'1'`): turns the reader on for the screens that call `jlife_scripture_maybe_render_day()`.
- Defaults and fallbacks: edit `data/bible-versions.json` (`default_for`, `languages.<lang>.fallback`), then `npm run plans:build`. No code change.

## WP-CLI

| Command | What it does |
|---|---|
| `wp jlife scripture import [vie1934] [--force]` | Verify the dataset's checksums and import it; records source URL, edition and checksums; no-op when unchanged |
| `wp jlife scripture status` | Flag, each language's version, dataset provenance (no text) |
| `wp jlife scripture cache-purge [--all]` | Delete expired cached passages |

## Tests

PHPUnit (`tests/`): registry allowlist and defaults, the parser against every reference in both plans, the 1925 text and its provenance, the cache with a fake source (one fetch per passage, max age, lock, verse limit, failures, display tracking), the reader, logs and errors without Bible text or keys, Vietnamese drafts.
