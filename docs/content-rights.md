# Content Rights Register

Status: Living document — update whenever a rights conversation advances
Created: 2026-07-03
Related: [PRD.md](PRD.md) §12, [roadmap.md](roadmap.md)

Rule of the repo: **do not publish or commit third-party copyrighted expression without appropriate rights.** Bare Scripture references, factual chronology, bibliographic identifiers, ordinary short headings, and deep links do **not** require a blanket publisher license merely because an existing curriculum helped organize the readings. Original selection and arrangement may receive limited protection where sufficiently creative, so do not copy substantial proprietary expressive compilations without review. For actual reproduced text, translations of copyrighted works, images, maps, video or audio, document the relevant rights before publishing.

**Standing context (2026-07-03):** existing ministry relationships may make several rights conversations relationship-based rather than cold external negotiations. The register is kept anyway, for three reasons: (1) written confirmations protect future volunteers and partners; (2) related organizations still need explicit coordination before content or platform data is reused; (3) Vietnamese Bible text is owned outside the project and requires its own permission path.

## 1. Rights Inventory

| Asset | Holder (believed) | What we want | Path | Status |
|---|---|---|---|---|
| Knowing Him 50-day study text | SonLife (author Mark Edwards) | Approval to translate/adapt into Vietnamese | Existing ministry relationship; obtain written OK for the record | **Written confirmation pending** |
| Knowing Him 42 Israel videos | SonLife (hosted on Vimeo) | Vietnamese subtitles/embed approval (Phase 5 at earliest) | Separate scope from text; obtain written OK for the record | **Written confirmation pending** |
| Harmony Bible chronology, outline, essays, map | Founders Passion / Harmony Bible | Preferred: they add Vietnamese to their platform; fallback: data export/API for our content subsite; default until then: deep links only | Relationship-based coordination path; their platform has no Vietnamese and no export/API today | **Not yet coordinated** |
| **Bible Marathon 104-day reference schedules (PR #50)** | Owner-created 104-day assignments, using PlusNothing section references and Robertson Gospel-harmony references | Publish daily dates, Scripture citations, factual event labels, section IDs, short titles, and links; keep the standard and optional parallel plans distinct | Owner-provided schedule; cite PlusNothing and Robertson as source references; no copied Bible translation passages or substantive third-party study prose in the CSVs | **Reference-only schedule cleared to proceed to technical import; no blanket arrangement-license gate.** Check rights only for newly included protectable expressive material |
| SonLife/J-Life framework language & diagrams (incl. 5-phase model names) | SonLife | Use of phase names/framework vocabulary in Vietnamese materials | Existing ministry relationship | **Likely allowed — document in writing** |
| Vietnamese Bible text | Varies by translation (see §2) | Render verses in lessons, or link out | Per-translation; **VIE2010 (UBS / Bible Society of Vietnam) identified as preferred candidate** | **Candidate chosen — license path open** |
| Original J-Life_VN Vietnamese lessons | Project team / sponsoring organization | Full rights; choose our own license | Internal decision (recommend: keep content © while code is GPL-compatible) | **Decision open** |
| UI translations we contribute to D.T Weblate | Community (D.T license terms) | Contribute upstream freely | translate.disciple.tools | Allowed, no blocker |
| Maps/imagery for lessons | Varies | Only openly licensed or licensed assets (e.g., Mapbox under our key, CC-licensed imagery) | Per-asset log below | Standing rule |

## 1a. Bible Marathon (PR #50): scope of publication review

- **Cleared for development/import:** the owner's 104-day primary Scripture-reference schedule and optional parallel-reference schedule. Scripture citations, chronological biblical events, dates, IDs, and ordinary short labels are not treated as proprietary book text; no permission request is imposed solely for referencing PlusNothing or Robertson. Credit their role as sources and retain provenance.
- **Narrow review if added later:** substantial quotations or translations of third-party study commentary, distinctive narrative explanations, illustrations/maps, or other protected expressive content; and any unusually creative third-party compilation that is substantially reproduced. The protection, if any, is limited to original expression, not the underlying facts or Scripture locations.
- **Bible text remains a separate question:** link to licensed online Bible pages, or secure permission/a suitable license before rendering copyrighted Bible translation verses inside J-Life. Do not infer a Bible-text license from use of reading references.
- **Status:** this is a documented, scope-limited project decision for the data in PR #50. It is **not** a declaration that all possible arrangements are unprotected or that all future content is licensed. No content-rights hold on the Nov 20 proof-of-concept or the existing reference-only schedule. Data/parser testing, hosting and security approvals remain their own milestones.

## 2. Vietnamese Bible Text Options

**Quotation vs. distribution (last verified 2026-11-05).** Publisher quotation allowances, per-translation status (**confirmed / conditional / unverified**), and the five distinct uses are documented in [Bible Translation Quotation Permissions](bible-quotation-permissions.md). Licensed display through an API is covered in the [YouVersion Platform research note](youversion-platform.md). In short:
- A 500- or 1,000-verse allowance is a **conditional publisher permission**, not statutory fair use and **not a Bible-reader license**.
- **References and links need no permission** and remain the launch default ([#49](https://github.com/i12know/jlife-platform/issues/49) D12; [PR #50](https://github.com/i12know/jlife-platform/pull/50) schedules).

| Translation | Status (2026-11-05) | J-Life decision |
|---|---|---|
| **ESV** | **Confirmed:** 500 verses; not more than one-half of a book; under 25% of the work; excludes commentaries/reference works; 2025 notice (Crossway) | Limited attributed quotes within those conditions; links otherwise |
| **NASB** | **Confirmed:** 1,000 verses; no complete book; at most 50%; at most 1,000 verses stored electronically; full notice + clickable lockman.org link on web/apps | Limited attributed quotes within those conditions |
| **ERV** (Easy-to-Read Version) | **Conditional:** 1,000 verses / 50% / no complete book, **but Bible League requires written permission to host ERV on a server or use it on a website** | Links for the marathon. ERV text on a J-Life page needs Bible League's written permission or an authorized API that carries ERV |
| **NIV** (Biblica) | **Unverified:** publisher pages couldn't be read in this review | Don't rely on a NIV allowance until a person confirms it at the source |
| **BPT** (Vietnamese Bible: Easy-to-Read Version, Bible League International) | Same publisher as ERV; assume the same server/website restriction until confirmed | **Links for the marathon (#91, 2026-11-13):** one Bible Gateway link per day, like ERV. No BPT text on J-Life pages without Bible League's permission |
| **VIE2010 / RVV11** | **Unverified:** no public quotation rule; UBS's 500-verse rule is **Spanish-only** | References and links. Ask Bible Society of Vietnam / UBS for the quotation rule and, separately, app display rights (#3) |

The options below are larger-scale ways to *show* Vietnamese text in J-Life, separate from occasional quotation.

| Option | Notes |
|---|---|
| **References only + link out** (to YouVersion/Bible.com or another licensed app) | Zero rights risk; **the launch default.** Weakest reading UX. VIE2010 deep links work today: `bible.com/bible/151/{BOOK}.{CH}.{VS}.VIE2010`. |
| Bản Truyền Thống 1925 (Cadman) — `VIE1925` on YouVersion (id 193) | Published pre-1930, commonly treated as public domain in the US; verify status and text-source quality for Vietnam use. Archaic language may not serve young readers. |
| **Bản Hiệu Đính 2010 (`VIE2010` / RVV11) — preferred candidate** | Full name: *Kinh Thánh Tiếng Việt Bản Hiệu Đính 2010*, also marketed in English as the Revised Vietnamese Version (RVV11). These are **one and the same translation** — YouVersion version id **151** serves both codes. It is the 2010 revision of the 1925 Bible into current Vietnamese, © 2010 **United Bible Societies**, published locally by the **Bible Society of Vietnam**; the copyright notice is "All rights reserved. Used by permission," with no public quotation-limit terms — licensing goes through UBS/Bible Society of Vietnam. Widely trusted, modern language, free to read on YouVersion. **Reviewed 2026-11-05:** no public quotation allowance; UBS's Spanish-language rule does not apply. In-app display needs permission or an authorized API that actually licenses VIE2010 to our app. |
| Bản Dịch 2011 (`BD2011`, YouVersion id 19) / Bản Dịch Mới (NVB) / Bản Phổ Thông | Independent modern translations with individual license holders; fallback candidates if UBS terms stall. |
| **API.Bible (American Bible Society) or similar licensed Scripture API** | Serves texts from the UBS-managed Digital Bible Library with per-app access requests — a likely legitimate route to render VIE2010 verse text without holding our own UBS license. **Whether VIE2010 is actually offered through API.Bible could not be confirmed without registering an API key — verify by signing up and requesting access.** |
| **YouVersion Platform** (corrected 2026-11-05: no longer "partner-only") | Public developer platform: free App Key, Bible text through REST/SDKs, with licenses accepted in the Platform portal. **ERV and VIE2010 availability to our app is unverified**, and their publishers aren't on its public publisher list. The license text, prize/non-commercial question, rate limits and caching rules are unread or unpublished. See the [research note](youversion-platform.md) §2 for the post-App-Key checks. |

**Bible Gateway reader frame (#92, owner decision 2026-11-13).** The SOAP page can show the day's ERV/BPT passages by framing Bible Gateway's own passage page (unaltered, nothing hidden or cropped, loaded only when the participant opens the reader, Bible Gateway link always shown, publisher notice under the frame). Checked 2026-11-13: Bible Gateway sends no `X-Frame-Options`/`frame-ancestors`, and its [Terms](https://www.biblegateway.com/legal/terms/) (updated 2025-06-04) neither permit nor prohibit framing: they license display for "personal, non-commercial use" and bar other display "except as … explicitly allowed", and forbid obscuring their ads. So framing is **not expressly authorized**; ask Bible Gateway (and, for J-Life-rendered text, Bible League International) in writing before the public launch. No Bible text is stored or served by J-Life. Turn it off per language with `"embed": false` in `content/reading-plans/bible-links.json` and rebuild the plans; the links remain.

**Decision:** **references + deep links for the pilot and the Dec 20 marathon** (no translation text reproduced). In parallel, and **not as a launch prerequisite**:
- (a) the YouVersion App Key checks
- (b) checking API.Bible availability
- (c) asking UBS / Bible Society of Vietnam **two separate questions**: what modest quotations of VIE2010 are allowed, and what rights in-app display would need

None of these block the reference-only reading schedule.

## 3. Public-Domain Gospel Harmony Foundation (note for technical contributors)

**The chronological arrangement this platform is built on does not depend on anyone's permission.** The Gospel-harmony tradition behind Harmony Bible and the SonLife life-of-Christ framework traces to works that are fully in the public domain:

- **John A. Broadus**, *A Harmony of the Gospels* (1893) — Broadus died 1895.
- **A. T. Robertson**, *A Harmony of the Gospels for Students of the Life of Christ, Based on the Broadus Harmony in the Revised Version* (1922) — Robertson died 1934. Full text freely available on [Project Gutenberg (#36264)](https://www.gutenberg.org/ebooks/36264) and the [Internet Archive](https://archive.org/details/harmonyofgospels00robeuoft).

Both are public domain in the US (published before 1930) and in Vietnam (author's life + 50 years). The later **Thomas & Gundry** *NIV Harmony of the Gospels* (1978) **kept the Broadus/Robertson arrangement unchanged** — it paired that same arrangement with the NIV text and added new editorial matter. So the arrangement in modern use *is* the public-domain arrangement; what is copyrighted in Thomas & Gundry is the NIV text (Biblica/Zondervan) and their own notes/essays, not the chronology. Chronological facts as such are not copyrightable anyway.

**What this means for the platform:**

1. We may freely build, display, and distribute a system that presents any **public-domain Bible text arranged according to the Broadus/Robertson harmony** — including a Vietnamese presentation using the public-domain 1925 text — and write **original study content structured on that arrangement**, without any rights agreement.
2. The `gospel_event` outline in our content model should be **seeded directly from Robertson's 1922 section outline** (a free digital source exists), giving us canonical event IDs immediately. Prototyping is therefore **not gated on any content-rights conversation**.
3. The rights conversations in §1 remain about *specific expression*, not the arrangement: Harmony Bible's own section headings, essays, map presentation, and translations are Founders Passion's work; Knowing Him's text is SonLife's; VIE2010 verse text is UBS's. Contributors should source the outline from Robertson/Gutenberg, not by copying harmony-bible.com's rendering, and pair rendered Scripture with public-domain text (VIE1925) until the VIE2010 permission lands.
4. Standard caveat: this is careful project-level analysis, not formal legal advice; if the platform grows, a proper review is cheap insurance.

## 4. Handling Rules

- **No scraping** of harmony-bible.com, knowinghim.app, or any Bible site — architecture treats them as reference targets only until an agreement exists.
- Every content set in `/content/` carries `source_attribution` and `rights_note` fields (enforced by the schema); anything lacking them fails review.
- Translations of copyrighted works are **derivative works** — a Vietnamese translation of Knowing Him needs a license even if we typed every word.
- Fair-use/quotation is not a publication strategy for a ministry platform; when in doubt, reference and link.
- Keep signed agreements and correspondence in the private shared drive, **not** in this repo; record only status ("licensed for X, dated Y") here.

## 5. Contact Log

| Date | Party | Channel | Summary | Next step |
|---|---|---|---|---|
| — | — | — | (log first contacts here; no personal contact details in public repo) | — |
