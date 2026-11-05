# YouVersion Platform: research note for J-Life

**Status:** research only, verified 2026-11-05. **Not a launch prerequisite.** The Nov 20 demonstration and the Dec 20 launch use **references plus external Bible links** ([#49](https://github.com/i12know/jlife-platform/issues/49) D12; Day 1 = John 1:1-18). Any YouVersion work is a separate, bounded proof of concept after credentials and licenses are confirmed. No YouVersion code exists in this repository.

Related: [bible-quotation-permissions.md](bible-quotation-permissions.md) (quotation vs. distribution), [content-rights.md](content-rights.md) §2.

**How this was verified:**
- Public developer docs at developers.youversion.com, the Platform homepage and partners page, and the npm registry.
- The consultant's reading of the owner's signed-in dashboard ([#49 comment](https://github.com/i12know/jlife-platform/issues/49#issuecomment-6644896965)). Dashboard-only claims are marked as such.
- Not read: the signed-in Apps and Licenses pages, and any license agreement text.

## 1. What YouVersion provides to developers

**REST API** (`https://api.youversion.com`)
- **Auth:** every request carries the App Key in the **`X-YVP-App-Key`** header; a missing or invalid key returns 401 ([authentication](https://developers.youversion.com/authentication)).
  - The docs' own examples put the key in browser JavaScript and in a font-stylesheet URL. In practice it's an application identifier visible to page visitors, not a server-only secret.
  - **We still keep it out of the repository, issues and screenshots**, and prefer server-side calls from WordPress.
- **Bible endpoints** ([API reference](https://developers.youversion.com/api)):
  - `GET /v1/bibles`, which requires `language_ranges[]`; `all_available=true` lists the whole catalog "regardless of licensing of the provided app key"
  - metadata for books, chapters and verses
  - **`GET /v1/bibles/{id}/passages/{passage}`** with `format=text|html` (headings and notes only in HTML)
- **Licenses:** `GET /v1/licenses` filters to licenses "the developer has agreed to"; license objects include `bible_ids` and `agreed_dt`. Acceptance happens in the Platform portal ([licenses](https://developers.youversion.com/api/licenses)).
- **Search:** `GET /v1/search-verses` and `/v1/search-unified` return **references only, never text** ([search APIs](https://developers.youversion.com/search-apis)).
- **Rate limits:** exist (429 with `Retry-After`), but **no numbers are published**. The dashboard says marking an app "live" may raise limits (dashboard-only; not in the public docs).
- **Caching/storage of text:** **no rule found** in the public docs. Treat as *not permitted* until the license text says otherwise.

**SDKs** ([introduction](https://developers.youversion.com/sdks/introduction)). Packages were at 2.17.0 on npm, published 2026-11-04; the docs give no beta/stable label.
- `@youversion/platform-core`: framework-independent TypeScript client for browsers, Node and serverless. `getPassageDisplay()` returns sanitized HTML plus attribution and stylesheet URLs, and "does not modify the DOM." There's no script-tag/CDN build; it's npm-only.
- `@youversion/platform-react-ui` (+ `platform-react-hooks`), which needs **React 19.1+**. Components:
  - `YouVersionProvider` (required wrapper)
  - `BibleCard`, `BibleTextView`, `VerseOfTheDay`
  - `BibleReader` (Root/Content/Toolbar), with chapter and version pickers and built-in search
  - `YouVersionAuthButton`
- Swift, Kotlin and React Native (Expo) SDKs for native apps.
- **No web embed widget or official iframe** is offered; the documented web paths are the React components, the core SDK, or raw REST.

**Rendering and attribution** ([display Bible HTML](https://developers.youversion.com/guides/display-bible-html), [copyright guide](https://developers.youversion.com/sdks/react/guides/copyright-and-attribution))
- "Always display a Bible Version's copyright attribution in accordance with the license agreement" (`version.copyright`; full notice in `promotional_content`).
- `BibleCard`, `BibleReader.Content` and `VerseOfTheDay` show it automatically; `BibleTextView` and raw HTML leave it to us.
- Use the YouVersion Bible CSS and Fonts API stylesheet, and don't self-host the font. The CSP must allow `cdn.youversion.com` and `api.youversion.com`.

**Verse selection, highlights, sign-in**
- `BibleReader.Root` has **`onVerseSelect`**, which returns `versionId`, `book`, `chapter`, `verses[]` and `passageIds[]` (e.g. `JHN.1.14`). This is the hook J-Life would use to copy a selected reference into the SOAP Scripture field. **That copy is J-Life code; YouVersion has no SOAP, notes or journal feature.**
- **Sign in with YouVersion:** OAuth 2.0 + PKCE; scopes `openid` (required), `profile`, `email`. The only data permission is **`highlights`**; "Notes and bookmarks are **not** supported" ([sign-in APIs](https://developers.youversion.com/sign-in-apis)).
  - **Highlights** are saved to the reader's own YouVersion account. Tapping a color triggers a YouVersion sign-in dialog that can't be suppressed ([highlights guide](https://developers.youversion.com/sdks/react/guides/highlights)).
  - That would be a **second login** next to J-Life's email sign-in (M3); avoid it at launch.

## 2. Licensing: what is and isn't established

- **Dashboard steps** (consultant, owner-authenticated):
  1. create an App Key
  2. obtain an **"Express Bible License"** to display Bible content
  3. optional Sign in with YouVersion
  4. use the APIs/SDKs
  5. mark the app live
- **"Express Bible License" doesn't appear in any public doc read**, and its text, conditions and scope are **not verified**. The public homepage advertises "Fast-track Bible Licensing … from any of YouVersion's approved Partners … No extra negotiation needed," and describes the tools as free.
- **No public developer terms of service were found.** The Platform footer's "Terms of Use" link leads back to the homepage, and `/terms` is effectively empty.
- **Bible.com website terms** ([bible.com/terms](https://www.bible.com/terms), last modified 2020-04-02) are for *users of the site*, not the developer API:
  - they allow "personal and non-commercial use or … the internal use of your non-profit religious organization"
  - they prohibit robots "to access YouVersion for any purpose"
  - they list "contests, sweepstakes, and other sales promotions" among prohibited *uploaded material*
  - they don't address framing
- **Cash prize: unresolved.** Nothing found says whether a free church challenge with an offline cash prize counts as commercial use under the Platform license. **Ask YouVersion in writing** before production use of licensed text.
- **Publisher coverage:** the public [partners page](https://platform.youversion.com/partners) lists Biblica, Lockman, Wycliffe, BroadStreet, MissionAssist, Bible Překlad 21. století, Hawai'i Pidgin Bible and SIL. **Bible League International (ERV), United Bible Societies and Bible Society of Vietnam (VIE2010) are not listed.**

### ERV (Bible.com 406) and VIE2010 (Bible.com 151)

**Not established.** Both are readable on Bible.com, which is *catalog availability on YouVersion's own site*. Whether they're (a) in the Platform API catalog and (b) licensed to *our* app can only be answered with an App Key. Their publishers' absence from the partner list makes (b) doubtful. Platform Bible IDs appear to match Bible.com version IDs (the docs' BSB example is 3034, its Bible.com ID), but verify.

**Steps after the owner creates a J-Life App Key** (no implementation; read-only checks):

1. **Owner:** store the key **only** as a GitHub environment secret (e.g. `LATOC_YVP_APP_KEY` in `latoc-staging`) and, if Claude should run the checks, as a secret in the cloud environment settings. **Never in issues, PRs, commits or screenshots.**
2. **Catalog check:** `GET /v1/bibles?language_ranges[]=en&language_ranges[]=vi&all_available=true`. Are 406 and 151 present?
3. **Entitlement check:** the same call **without** `all_available`, plus `GET /v1/licenses?bible_id=406` and `?bible_id=151`. Are they licensed to our app?
4. **Owner reads the Express Bible License text** in the portal *before accepting*; record its name, version and key conditions in `content-rights.md`.
5. **Owner asks YouVersion in writing:** non-commercial status of a free church challenge with an offline cash prize; caching/storage rules; rate limits; whether ERV/VIE2010 can be made available.
6. If either version is unavailable, keep links for it, and consider an available alternative for in-app text (e.g. BSB 3034, public domain; NIV via Biblica, if licensed).

## 3. Three ways to show the passage in J-Life

| | **A. External Bible links** | **B. iframe of Bible.com** | **C. Official YouVersion integration** |
|---|---|---|---|
| Effort | Smallest: build a URL from the reference | Small to build, high to make usable | Medium–high. The React UI needs a React 19 "island" bundle inside WordPress, or core-SDK/REST rendering with YouVersion CSS, fonts and attribution |
| Licensing exposure | None: nothing reproduced | **Unclear.** Technically possible on 2026-11-05 (no `X-Frame-Options`/`frame-ancestors`; a one-off check rendered John 1:1-18 ERV in a frame). But neither YouVersion's terms nor any license grants framing, and the site terms limit use to personal/non-commercial or internal non-profit use | Covered only for versions licensed to our app, under license text we haven't read; prize question open |
| Reliability | High; depends on Bible.com being up | Low: can break whenever YouVersion adds framing headers; cookie banner and "Get the app" prompts | Depends on the API and an unpublished rate limit; needs a fallback to links |
| Phone UX | Leaves J-Life (new tab or Bible App); the participant switches back | Cramped full Bible.com UI inside the page | Best: passage and SOAP form on one page |
| Authentication | None | None (YouVersion cookies inside the frame) | App Key only. YouVersion sign-in only if we want highlights; avoid at launch |
| Operations | None | Monitoring for breakage | Key management, CSP, rate-limit handling, attribution checks, SDK upgrades |
| Long-term fit | Permanent fallback, and the **launch path** | **Not recommended** | Good, **if** ERV/VIE2010 (or acceptable alternatives) are licensed to us |

**Protecting a half-written SOAP (all options):** M6 already requires **server-side draft autosave**. Additionally:
- Bible links open in a **new tab/app**, so the J-Life page stays where it was.
- Keep a local draft copy as a backup.
- Show a "Draft saved" indicator, so a participant returning from Bible.com or a chat app's in-app browser never loses text.

Option C removes the context switch entirely.

**Selecting a verse into the Scripture field:**
- **Option A:** the Scripture field can offer the day's references as quick-insert chips; no YouVersion needed.
- **Option C:** `onVerseSelect` returns `passageIds` that J-Life converts to a readable reference and inserts. J-Life still never inserts Bible *text* into the field unless that text's license allows it.

## 4. Fit with Option C (WordPress)

- **Feasible but not trivial.** WordPress can call the REST API server-side from PHP (keeping the key off the page and allowing a link fallback), or load a built JS bundle on one page.
- **Server-side rendering with the core SDK needs Node (`jsdom`)**, which shared WordPress hosting doesn't run. So the PHP path would use REST `format=html` directly, plus YouVersion's CSS, fonts and attribution.
- Not a fit for Dec 20 risk, and not needed: links meet the launch goal.

## 5. Recommendation

1. **Immediately (Nov 20, Dec 20):** Option A.
   - Daily references with links in the participant's chosen language (ERV or VIE2010 on Bible.com).
   - Links open in a new tab, plus server-side SOAP draft autosave.
   - No Bible text in J-Life.
2. **Bounded investigation (after launch, or in parallel only if it costs no launch time):**
   - the App Key checks in §2
   - the license text and the prize question in writing
   - then a one-page proof of concept on latoc: John 1:1-18 via REST HTML with attribution, the SOAP form on the same page, a link fallback, and a phone test
3. **Long term:** Option C for in-app reading, **only** for versions actually licensed to J-Life, with links as the fallback. Option B is not pursued.
