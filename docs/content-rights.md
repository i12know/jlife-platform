# Content Rights Register

Status: Living document — update whenever a rights conversation advances
Created: 2026-07-03
Related: [PRD.md](PRD.md) §12, [roadmap.md](roadmap.md)

Rule of the repo: **nothing ships — and nothing is committed to this repository — that we do not have documented rights to use.** References, IDs, and deep links are always permitted; copied text, translations of copyrighted works, images, maps, video, and audio are not, until the corresponding row below says so.

**Standing context (2026-07-03):** existing ministry relationships may make several rights conversations relationship-based rather than cold external negotiations. The register is kept anyway, for three reasons: (1) written confirmations protect future volunteers and partners; (2) related organizations still need explicit coordination before content or platform data is reused; (3) Vietnamese Bible text is owned outside the project and requires its own permission path.

## 1. Rights Inventory

| Asset | Holder (believed) | What we want | Path | Status |
|---|---|---|---|---|
| Knowing Him 50-day study text | SonLife (author Mark Edwards) | Approval to translate/adapt into Vietnamese | Existing ministry relationship; obtain written OK for the record | **Written confirmation pending** |
| Knowing Him 42 Israel videos | SonLife (hosted on Vimeo) | Vietnamese subtitles/embed approval (Phase 5 at earliest) | Separate scope from text; obtain written OK for the record | **Written confirmation pending** |
| Harmony Bible chronology, outline, essays, map | Founders Passion / Harmony Bible | Preferred: they add Vietnamese to their platform; fallback: data export/API for our content subsite; default until then: deep links only | Relationship-based coordination path; their platform has no Vietnamese and no export/API today | **Not yet coordinated** |
| SonLife/J-Life framework language & diagrams (incl. 5-phase model names) | SonLife | Use of phase names/framework vocabulary in Vietnamese materials | Existing ministry relationship | **Likely allowed — document in writing** |
| Vietnamese Bible text | Varies by translation (see §2) | Render verses in lessons, or link out | Per-translation; **VIE2010 (UBS / Bible Society of Vietnam) identified as preferred candidate** | **Candidate chosen — license path open** |
| Original J-Life_VN Vietnamese lessons | Project team / sponsoring organization | Full rights; choose our own license | Internal decision (recommend: keep content © while code is GPL-compatible) | **Decision open** |
| UI translations we contribute to D.T Weblate | Community (D.T license terms) | Contribute upstream freely | translate.disciple.tools | Allowed, no blocker |
| Maps/imagery for lessons | Varies | Only openly licensed or licensed assets (e.g., Mapbox under our key, CC-licensed imagery) | Per-asset log below | Standing rule |

## 2. Bible Text Quotation and Vietnamese Bible Options

**Verified limited-quotation permissions exist for several translations.** Before repeating a general licensing concern, consult [Bible Translation Quotation Permissions](bible-quotation-permissions.md), which records publisher links, verified caps, conditions, app/website caveats, and the next-review date (2026-11-04). The word *fair use* is often used informally in these publisher rules; a publisher's 500- or 1,000-verse allowance is a **conditional permission**, not a universal statutory fair-use safe harbor. **References and direct Bible links remain usable without text reproduction.**

| Translation | Publisher quotation status (2026-11-04) | J-Life decision |
|---|---|---|
| **ESV** | **500 verses verified**, with limits on proportion, book coverage, commentary/reference works, digital use, and attribution (Crossway) | Limited quotes if the exact use meets Crossway's conditions; reference links otherwise |
| **ERV** (Easy-to-Read Version) | **1,000 verses verified**, with 50%/whole-book and attribution conditions; Bible League has a separate server-hosting caveat | Brief attributed quotations per policy; no assumption of a full digital Bible hosting right |
| **NASB** | **1,000 verses verified**, including an electronic-storage restriction and full web/app copyright notice (Lockman) | Limited quotes consistent with exact edition and medium |
| **NIV** (Biblica) | **500 verses verified** under Biblica's general use guidelines, with total-work restrictions and attribution | Limited quotes only if within the publisher's conditions |
| **VIE2010 / RVV11** | **No translation-specific numerical allowance independently verified**; UBS's online 500-verse rule concerns **Spanish** Bibles and does not automatically apply to Vietnamese | Continue references and deep links. Ask UBS/Bible Society Vietnam for its exact VIE2010 quotation policy; do not represent 500 as confirmed |

Legal status and permission scope must still be evaluated for the *specific translation, edition, and proposed use*. Notes below describe larger-scale rendering/hosting options and are separate from occasional quotation.

| Option | Notes |
|---|---|
| **References only + link out** (to YouVersion/Bible.com or another licensed app) | Zero rights risk; MVP default. Weakest reading UX. VIE2010 deep links work today: `bible.com/bible/151/{BOOK}.{CH}.{VS}.VIE2010`. |
| Bản Truyền Thống 1925 (Cadman) — `VIE1925` on YouVersion (id 193) | Published pre-1930, commonly treated as public domain in the US; verify status and text-source quality for Vietnam use. Archaic language may not serve young readers. |
| **Bản Hiệu Đính 2010 (`VIE2010` / RVV11) — preferred candidate** | Full name: *Kinh Thánh Tiếng Việt Bản Hiệu Đính 2010*, also marketed in English as the Revised Vietnamese Version (RVV11). These are **one and the same translation** — YouVersion version id **151** serves both codes. It is the 2010 revision of the 1925 Bible into current Vietnamese, © 2010 **United Bible Societies**, published locally by the **Bible Society of Vietnam**; the copyright notice is "All rights reserved. Used by permission," with no public quotation-limit terms — licensing goes through UBS/Bible Society of Vietnam. Widely trusted, modern language, free to read on YouVersion. **Reviewed again 2026-11-04:** no version-specific public 500-verse quotation permission could be confirmed. For extensive in-app display/hosting, obtain permission or use a licensed API (see API.Bible row). For limited quotations, obtain the specific VIE2010 publisher policy rather than assuming UBS's Spanish edition rule applies. |
| Bản Dịch 2011 (`BD2011`, YouVersion id 19) / Bản Dịch Mới (NVB) / Bản Phổ Thông | Independent modern translations with individual license holders; fallback candidates if UBS terms stall. |
| **API.Bible (American Bible Society) or similar licensed Scripture API** | Serves texts from the UBS-managed Digital Bible Library with per-app access requests — the likeliest legitimate route to render VIE2010 verse text without holding our own UBS license. **Whether VIE2010 is actually offered through API.Bible could not be confirmed without registering an API key — verify by signing up and requesting access.** YouVersion's own API is partner-only. |

Decision needed by Phase 2b M1 (walking skeleton): which option the study reader implements first. Recommendation: **references + VIE2010 deep links to YouVersion for the pilot** (does not reproduce translation text), while in parallel (a) checking API.Bible availability, and (b) asking UBS / Bible Society Vietnam **two separate questions**: what modest quotations of VIE2010 are allowed without written permission, and what rights would be required to host longer passages in an app. These questions do not block an existing reference-only reading schedule.

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
