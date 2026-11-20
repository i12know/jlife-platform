# J-Life Scripture provider entitlement registry

**Updated:** November 19, 2026 (API.Bible section, #141). This document records what J-Life's YouVersion app **can see in its entitled catalog**, what has passed passage smoke tests, and what remains an unapproved candidate. **It does not authorize native Bible-text rendering or change live translation defaults.**

- Machine-readable record: [`bible-provider-registry.json`](../content/reading-plans/bible-provider-registry.json).
- Runtime allowlist (#139): [`bible-versions.json`](../plugins/jlife-scripture/data/bible-versions.json), which replaced `bible-links.json`. Each language's **fallback** link still targets English **ERV** and Vietnamese **BPT** at Bible Gateway; do not silently change it. Its YouVersion entries (NIrV 110, NIV 111, KTHD 1638) stay `enabled: false` / `display: link` until the owner gates on #139 clear, and `bin/validate-scripture.js` refuses native rendering of a YouVersion version this inventory has not marked `ready_for_production`.
- Owner's conditional native-reading selection from [Issue #78](https://github.com/i12know/jlife-platform/issues/78#issuecomment-6879689851): **NIrV** (en, 110) and **KTHD** (vi, 1638). A later discussion suggested NIV as a default, but **no new owner decision to replace this selection** has been recorded.

## Meaning of the statuses

1. **App catalog entitlement:** the application's YouVersion API returned this version as licensed in its catalog on 2026-11-14. This is evidence of availability **to the J-Life YouVersion app**, not unconditional distribution rights.
2. **Passage smoke test passed:** the test also successfully requested John 1:1-18 (without logging Bible text).
3. **Production text permitted:** **not yet established** for any version. Review the exact accepted publisher terms for display, attribution, caching/storage, any AI-use restrictions, and whether J-Life's free church marathon with an offline cash prize qualifies under noncommercial terms.
4. **Candidate:** no claim of a J-Life API.Bible license or deployable text rights. Never configure it as enabled based on a public catalog alone.

## YouVersion: actual J-Life app entitlements

Source: successful [GitHub Actions English/Vietnamese catalog run](https://github.com/i12know/jlife-platform/actions/runs/43537910398) and [Issue #78 evidence](https://github.com/i12know/jlife-platform/issues/78#issuecomment-6869166905). The run returned **20 English** and **1 Vietnamese** Bibles. No Bible League International entitlement was present.

| English version | YouVersion ID | License ID | Verification |
|---|---:|---:|---|
| AMP | 1588 | 3 | Catalog only |
| ASV | 12 | 1 | Catalog only |
| BSB | 3034 | 1 | Catalog only |
| CPDV | 42 | 1 | Catalog only |
| EASY | 2079 | 5 | John 1:1-18 passed |
| FBV | 1932 | 1 | Catalog only |
| GNV | 2163 | 1 | Catalog only |
| LSV | 2660 | 1 | Catalog only |
| NASB1995 | 100 | 3 | Catalog only |
| NASB2020 | 2692 | 3 | Catalog only |
| NIrV | 110 | 2 | John 1:1-18 passed |
| NIV | 111 | 2 | Catalog only |
| NIVUK | 113 | 2 | Catalog only |
| PEV | 2530 | 7 | Catalog only |
| TCENT | 3427 | 1 | Catalog only |
| TOJB2011 | 130 | 1 | Catalog only |
| TPT | 1849 | 4 | Catalog only |
| WEBUS | 206 | 1 | Catalog only |
| WMB | 1209 | 1 | Catalog only |
| WMBBE | 1207 | 1 | Catalog only |

| Vietnamese version | YouVersion ID | License ID | Verification |
|---|---:|---:|---|
| **KTHD / VCB — Kinh Thánh Hiện Đại** | **1638** | **2 (Biblica)** | **John 1:1-18 passed** |

Relevant licenses: 1 Public Domain/Creative Commons, 2 Biblica, 3 Lockman, 4 BroadStreet, 5 MissionAssist, 7 Wycliffe. Other license agreements accepted by the app are documented in Issue #78. Some publisher agreements are not yet independently reviewed for the intended production use. The YouVersion version number is *not* interchangeable with an API.Bible Bible ID.

**ERV** (Bible.com ID 406) returned a 404 from YouVersion Platform's direct metadata endpoint for this app. **BPT** was absent from the Vietnamese catalog. Their availability on Bible Gateway/Bible.com does not establish API developer entitlements.

## API.Bible: account, rules and client (#141)

**Status: owner to confirm against the account's terms.** The rules below come from API.Bible's public docs and terms as read on 2026-11-19; the account's own accepted terms (signed-in dashboard) have not been read.

**Account** (owner, 2026-11-19, [#141](https://github.com/i12know/jlife-platform/issues/141)): app "Jlife", **Starter** plan ($0), **5,000 API calls a month**, with the three premium slots set to **NLT, NKJV and CSB**. The owner treats the free church app as noncommercial. The exact API.Bible `bibleId` of each edition is **not yet recorded**: the registry entries (`api_bible.versions` in [`bible-provider-registry.json`](../content/reading-plans/bible-provider-registry.json)) hold placeholder IDs and `enabled: false` until the owner runs `wp jlife bible apibible-list` on the host. An API.Bible `bibleId` is not a YouVersion version number.

### Rules and their sources

| Rule | What the source says | Source | What the client does |
|---|---|---|---|
| Copyright notice | "please include the copyright information along side all of the Scripture you display" | [FAQ](https://docs.api.bible/common-questions/) | Keeps `data.copyright` from every passage response and prints it under the text |
| Full copyright page | Terms §7.1–7.2: a "copyright" page of the app, linked from each citation, naming the translation, its abbreviation and the IP holder, with a link to the holder's website; "Each page on which a citation is used must also include a link to the full copyright information". The terms say the information is provided in the Bible-version metadata. The documented Bible object (`GET /v1/bibles/{id}`: `name`, `abbreviation`, `copyright`, `info`, …) has **no URL field** | [Terms](https://api.bible/terms-and-conditions), [Bibles guide](https://docs.api.bible/guides/bibles) | The link goes to the **site's own** copyright page. `[jlife_bible_copyrights]` builds it from the cached metadata (one `GET /v1/bibles/{id}` per version, kept 14 days); the owner creates the page and sets the site option `jlife_scripture_copyright_page_url`; each citation links to `<page>#<version key>`. No link is shown until the option is set. **Owner to confirm** the page meets §7.1 (set each version's `copyright.url` in the registry to the IP holder's website, Appendix B) |
| Starter attribution | "Users on the Starter Plan are required to include a visible citation and hyperlink to https://api.bible" | [Terms, Acceptable Use](https://api.bible/terms-and-conditions) | Every rendered passage ends with "Scripture text provided by API.Bible", linked |
| FUMS | "we require all API developers to use [FUMS] in web apps"; terms §3.3 "any webapp must implement FUMs" | [Licensing and access](https://docs.api.bible/quick-start/licensing-and-access), [Fair use](https://docs.api.bible/resources/fair-use), [Terms](https://api.bible/terms-and-conditions) | Reads `meta.fumsToken`; reports every display, cached or not (see below) |
| Consecutive verses | "limit it to fewer than 500 consecutive verses" | [FAQ](https://docs.api.bible/common-questions/) | Results over 499 verses are marked not cacheable; J-Life passages are far shorter |
| Cache age | FAQ: "clear your cache every 14 days or less" (a recommendation). Terms: "All cached content from API.Bible must be updated at least once every 30 days" | [FAQ](https://docs.api.bible/common-questions/), [Terms](https://api.bible/terms-and-conditions) | `max_age` 14 days (the stricter of the two) |
| Passage size | Passages return at most the first 200 verses | [Passages guide](https://docs.api.bible/guides/passages) | n/a (daily readings are short) |
| Call limit | Starter: 5,000 calls a month, no overage; "your service will be disrupted beyond your monthly limit" | [Rate limiting](https://docs.api.bible/quick-start/rate-limiting) | Counts every request; warns from 4,000; stops calling at 5,000 and shows links |
| Status codes | 401 "We couldn't authenticate you"; 403 "requesting a Bible that you don't have access to". 429 is not documented | [Errors](https://docs.api.bible/quick-start/errors) | 401/403 → link-only; 429, 5xx, timeouts → link fallback |
| Noncommercial | Terms §9.3; NKJV on Starter/Pro only for apps designated noncommercial | [Terms](https://api.bible/terms-and-conditions) | Owner decision: noncommercial (not re-opened) |

Not found in the public docs: whether FUMS reports count toward the 5,000 calls (the client counts them separately), and a published meaning or `Retry-After` for 429.

### FUMS, privacy-preserving

API.Bible's FUMS v3 accepts a server-side report: `GET https://fums.api.bible/f3?t=<fumsToken>&dId=<device>&sId=<session>&uId=<user>`, with `t` repeatable ([fair use](https://docs.api.bible/resources/fair-use)). The docs say the device ID is "an anonymous representation of one specific device", the session ID is "regenerated at the start of every session", and the optional user ID "should never contain any PII".

J-Life reports **from the server**, so API.Bible never receives a participant's IP address or browser details, and:
- `t`: the passage's FUMS token, once per display (a cached passage reuses the token it was fetched with);
- `dId`: one random ID for the whole J-Life install, made once; it identifies no person or device;
- `sId`: a random ID replaced every day;
- no `uId`.

The view counts per passage stay accurate; device and session counts understate real readers. **Owner to confirm** that this meets the account's FUMS terms (terms §14), and that reporting cached views with the original token is acceptable.

### The native reader's `apibible` source (#141 part 2)

`plugins/jlife-scripture/includes/class-jlife-scripture-apibible-source.php` turns the client below into a `Jlife_Scripture_Source` for the native reader. It calls the client only through its functions, each guarded with `function_exists`, so with `jlife-challenges` inactive it is simply unavailable and the reader shows the Bible Gateway link. It does not talk to the network, hold the key or count calls itself.

- **Fetch:** per parsed part of a reference, `jlife_challenges_apibible_passage_id()` builds the ID and `jlife_challenges_apibible_fetch_passage()` fetches it as plain text (`content-type=text`, verse numbers on, titles and notes off). The text is split on its `[n]` verse markers into numbered verses; a number that does not increase starts the next chapter. A partial verse (`Luke 3:23a`) fetches the whole verse. A verse shared by two parts of a reference is one request.
- **Cache (#148's shared cache):** one row per source + version + bibleId + passage, storing the verses, the copyright notice the API sent and the FUMS token(s), for at most 14 days (the entry's `max_age`, never longer than the registry's `max_age_seconds` of 1,209,600). A passage over 499 consecutive verses is never asked for when its length is known, and a response the API marks as over the limit is shown once but not stored. `link_only` and `fallback` outcomes (401/403, 429, 5xx, timeouts, a bad answer) are never stored: the reader shows the Bible Gateway link, and a 401/403 holds the Bible for an hour so a busy page does not keep calling.
- **FUMS:** every display, fetched or served from the cache, reports through `Jlife_Scripture_Display_Tracking::track_display()` with the stored token. A cache hit makes **no** REST call and does **not** move the monthly counter; the FUMS report is counted apart.
- **Under the text:** the notice the API returned (the registry's line only if it returned none), the Starter plan's visible link to https://api.bible, and, when the site option is set, the link to the full copyright information.

### Enable NLT/NKJV/CSB

The three bibleIds (not secret) are recorded in `plugins/jlife-scripture/data/bible-versions.json` and `content/reading-plans/bible-provider-registry.json`: NLT `d6e14a625393b4da-01`, NKJV `63097d2a0a2f7db3-01`, CSB `a556c5305ee15c3f-01`. All three stay `enabled: false` and `display: link` until the owner has tried them:

1. **Try each version on the host** (run from the WordPress directory, with `--url=<the site's URL>` on a multisite). Each uncached run is **one API call**; a repeat run is a cache hit and costs none (`wp jlife scripture cache-purge --all` forces a fresh fetch). The command works while the version is disabled, prints the text, the copyright notice, the cache status and the HTTP status, and never prints the key:

   ```
   wp jlife scripture try nlt "John 1:1-5"
   wp jlife scripture try nkjv "John 1:1-5"
   wp jlife scripture try csb "John 1:1-5"
   wp jlife scripture try nlt "Luke 3:23" --metadata
   ```

   The last one also checks a partial verse and fetches the Bible's metadata (`--metadata` is one more API call). `wp jlife bible usage` shows the month's count.
2. **Confirm** for each version: HTTP status 200; the copyright line is the publisher's notice; the verse numbers and text read correctly; the passage's own verse count looks right.
3. **Make the copyright page** (terms §7.1): a WordPress page containing `[jlife_bible_copyrights all="yes"]`, then `wp option update jlife_scripture_copyright_page_url "<the page's URL>"` on the site that shows readers. Check each version's section, and set its `copyright.url` in the registry to the IP holder's website.
4. **A follow-up PR** sets, for each version you accept, `enabled: true`, `display: native` and `license_evidence` (the date and what was confirmed, for example "2026-12-01: `wp jlife scripture try` returned 200 with the publisher's notice; copyright page published; terms of the account reviewed"). `bin/validate-scripture.js` then checks the allowlist rules. Turn the reader on per site with `jlife_scripture_native_reader` if it is not on yet.
5. Watch **J-Life → Scripture** (or `wp jlife bible usage`): cached use stays in the hundreds of calls a month; uncached use would pass 5,000.

### The client

`plugins/jlife-challenges/includes/bible-apibible.php`:
- `jlife_challenges_apibible_fetch_passage( $bible_id, $passage_id, array( 'version' => 'nlt', 'format' => 'html'|'text' ) )` calls `GET https://rest.api.bible/v1/bibles/{bibleId}/passages/{passageId}` with verse numbers, without notes or titles. It returns the sanitized text, `copyright`, `reference`, `verse_count`, `fums` (token) and `cache` (the rule above), plus an `outcome`: `ok`, `link_only` or `fallback`. It stores no Bible text: caching is #139's shared passage cache.
- `jlife_challenges_apibible_fetch_bible( $bible_id, array( 'version' => 'nlt' ) )` calls `GET https://rest.api.bible/v1/bibles/{bibleId}` and returns the Bible's `name`, `abbreviation`, `copyright`, `info` (as text) and `language`, with the same outcome codes, hold, counter and log lines as a passage. The copyright page uses it (cached by jlife-scripture for 14 days).
- `jlife_challenges_apibible_render( $result )` prints the text, the copyright line and the API.Bible link, and sends the FUMS report.
- The key is `JLIFE_APIBIBLE_KEY`, defined in `~/.latoc/bible.php` (mode 0600) on the host; the deploy includes that file from `wp-config.php`, as it does `mail.php`. Template: [`config/latoc-bible.example.php`](../config/latoc-bible.example.php). The key goes only in the `api-key` header, never in a URL, log line or error.
- Log lines carry only the version key, an outcome code and the HTTP status. After a 401/403 the Bible is held as link-only for an hour; after a 429 for 15 minutes; after an outage for 5 minutes, so failures don't spend the quota.
- Usage: `wp jlife bible usage` and **J-Life → Scripture** in the site admin show this month's calls (UTC month, network-wide) out of 5,000.

### Owner steps

1. Consider regenerating the key first (it was shown on screen during setup). Copy `config/latoc-bible.example.php` to `~/.latoc/bible.php` on the latoc host, set the key privately, `chmod 600`. Never in the repo, issues or chat.
2. Run `wp jlife bible apibible-list --language=eng` on the host (one API call) and record the `id` of the NLT, NKJV and CSB editions in `api_bible.versions[].source_id`.
3. Read the account's accepted terms and confirm the rules above (cache age, FUMS, attribution, the full-copyright-page link).
4. Done 2026-11-20: the bibleIds are recorded and #139's registry, source and cache are wired (#141 part 2). Next: **Enable NLT/NKJV/CSB** above (run `wp jlife scripture try`, make the copyright page, then a follow-up PR sets `enabled: true`).

## Bible League: explicit permission pending

The owner sent Bible League International a request on November 18, 2026 for hosted display of **ERV** and **BPT** (covering the 104-day primary passages). This is a **pending request, not permission**. Do not upload translation text, store it in the public repository, or treat Bible Gateway framing as licensed hosting.

## Implementation gates and change procedure

- Keep **secrets** only in the actual runtime's protected secret store; do not commit API keys or licensed Bible text.
- Keep the `languages` fallbacks and `default_for` values in `plugins/jlife-scripture/data/bible-versions.json` unchanged until the owner explicitly approves a new live reader/translation selection (the 2026-11-19 decision on #139 set the defaults to NIrV / Kinh Thánh 1925).
- Implement a provider adapter that exposes a verified version registry and native renderer; ensure that only reviewed, authorized versions can be enabled.
- For each provider/version: review written terms; verify a real passage request; display full attribution; handle unavailable/unlicensed/429 outcomes; enforce privacy and proper view tracking (FUMS for API.Bible); prevent unlicensed persistent caching; retain external links as a fallback.
- For **LATOC**: preserve daily reading order, partial verses, optional Harmony readings, and publicly accessible reading-page distinctions.
- Use a **central server-side per-provider/version/passage cache** with a request lock to avoid duplicate first-use calls; honor each license's cache TTL and max stored span. Do not let a global caching policy override publisher restrictions.
- Record the exact entitlement and license approval evidence in the JSON registry (but **not** private credentials or agreements). Add tests and a bounded proof of concept; don't automatically merge, deploy, or modify public reading screens from a research-only registry.

This registry is **not currently read by WordPress runtime**. The next engineering step is to wire a validated, allowlisted subset into the new native Scripture reader, with owner approval and license gates.

## Next owner actions

1. API.Bible: the key is on the host and the NLT/NKJV/CSB `bibleId`s are recorded (#141). Run `wp jlife scripture try` for each and enable them (see **API.Bible → Enable NLT/NKJV/CSB**).
2. Await Bible League's written ERV/BPT response.
3. Confirm whether the owner wants the previously recorded **NIrV + KTHD** native-reader selection or a newly proposed **NIV + KTHD** default.
4. Complete publisher-specific terms review and proof-of-concept before exposing licensed text publicly.
