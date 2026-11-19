# J-Life Scripture provider entitlement registry

**Updated:** November 19, 2026 (API.Bible section, #141). This document records what J-Life's YouVersion app **can see in its entitled catalog**, what has passed passage smoke tests, and what remains an unapproved candidate. **It does not authorize native Bible-text rendering or change live translation defaults.**

- Machine-readable record: [`bible-provider-registry.json`](../content/reading-plans/bible-provider-registry.json).
- Current active external-link configuration: [`bible-links.json`](../content/reading-plans/bible-links.json). It still targets English **ERV** and Vietnamese **BPT** at Bible Gateway; do not silently change it.
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
| Full copyright page | Terms §7.1–7.2: link a full copyright page from the app; "Each page on which a citation is used must also include a link to the full copyright information" | [Terms](https://api.bible/terms-and-conditions) | **Not built here**: #139's reader page needs the link (copyright metadata comes from `GET /v1/bibles/{id}`) |
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

### The client

`plugins/jlife-challenges/includes/bible-apibible.php`:
- `jlife_challenges_apibible_fetch_passage( $bible_id, $passage_id, array( 'version' => 'nlt', 'format' => 'html'|'text' ) )` calls `GET https://rest.api.bible/v1/bibles/{bibleId}/passages/{passageId}` with verse numbers, without notes or titles. It returns the sanitized text, `copyright`, `reference`, `verse_count`, `fums` (token) and `cache` (the rule above), plus an `outcome`: `ok`, `link_only` or `fallback`. It stores no Bible text: caching is #139's shared passage cache.
- `jlife_challenges_apibible_render( $result )` prints the text, the copyright line and the API.Bible link, and sends the FUMS report.
- The key is `JLIFE_APIBIBLE_KEY`, defined in `~/.latoc/bible.php` (mode 0600) on the host; the deploy includes that file from `wp-config.php`, as it does `mail.php`. Template: [`config/latoc-bible.example.php`](../config/latoc-bible.example.php). The key goes only in the `api-key` header, never in a URL, log line or error.
- Log lines carry only the version key, an outcome code and the HTTP status. After a 401/403 the Bible is held as link-only for an hour; after a 429 for 15 minutes; after an outage for 5 minutes, so failures don't spend the quota.
- Usage: `wp jlife bible usage` and **J-Life → Scripture** in the site admin show this month's calls (UTC month, network-wide) out of 5,000.

### Owner steps

1. Consider regenerating the key first (it was shown on screen during setup). Copy `config/latoc-bible.example.php` to `~/.latoc/bible.php` on the latoc host, set the key privately, `chmod 600`. Never in the repo, issues or chat.
2. Run `wp jlife bible apibible-list --language=eng` on the host (one API call) and record the `id` of the NLT, NKJV and CSB editions in `api_bible.versions[].source_id`.
3. Read the account's accepted terms and confirm the rules above (cache age, FUMS, attribution, the full-copyright-page link).
4. After #139 wires the source into its registry and cache, smoke-test one passage per version, then set `enabled: true`.

## Bible League: explicit permission pending

The owner sent Bible League International a request on November 18, 2026 for hosted display of **ERV** and **BPT** (covering the 104-day primary passages). This is a **pending request, not permission**. Do not upload translation text, store it in the public repository, or treat Bible Gateway framing as licensed hosting.

## Implementation gates and change procedure

- Keep **secrets** only in the actual runtime's protected secret store; do not commit API keys or licensed Bible text.
- Keep `bible-links.json` unchanged until the owner explicitly approves a new live reader/translation selection.
- Implement a provider adapter that exposes a verified version registry and native renderer; ensure that only reviewed, authorized versions can be enabled.
- For each provider/version: review written terms; verify a real passage request; display full attribution; handle unavailable/unlicensed/429 outcomes; enforce privacy and proper view tracking (FUMS for API.Bible); prevent unlicensed persistent caching; retain external links as a fallback.
- For **LATOC**: preserve daily reading order, partial verses, optional Harmony readings, and publicly accessible reading-page distinctions.
- Use a **central server-side per-provider/version/passage cache** with a request lock to avoid duplicate first-use calls; honor each license's cache TTL and max stored span. Do not let a global caching policy override publisher restrictions.
- Record the exact entitlement and license approval evidence in the JSON registry (but **not** private credentials or agreements). Add tests and a bounded proof of concept; don't automatically merge, deploy, or modify public reading screens from a research-only registry.

This registry is **not currently read by WordPress runtime**. The next engineering step is to wire a validated, allowlisted subset into the new native Scripture reader, with owner approval and license gates.

## Next owner actions

1. API.Bible: put the key on the host and record the NLT/NKJV/CSB `bibleId`s (see **API.Bible → Owner steps**).
2. Await Bible League's written ERV/BPT response.
3. Confirm whether the owner wants the previously recorded **NIrV + KTHD** native-reader selection or a newly proposed **NIV + KTHD** default.
4. Complete publisher-specific terms review and proof-of-concept before exposing licensed text publicly.
