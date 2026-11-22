# /content — Portable Study Content

Study content lives here as portable, rights-aware files. The WordPress database is a *rendering* of this content, never its only home ([architecture.md §4](../docs/architecture.md)).

## Layout

| Directory | Holds | Populated by |
|---|---|---|
| `schemas/` | JSON/Markdown schema definitions + a documented example lesson | #5 |
| `pilot-lessons/` | The 5–7 Vietnamese pilot lessons | #7 |
| `harmony/` | `gospel_event` outline dataset from the public-domain Robertson (1922) harmony | #6 |
| `latoc/` | Public LATOC landing-page copy (EN source, DRAFT VI), and campaign facts for the 2026-27 journey; draft until owner/translator review | #110 |
| `reading-plans/` | The owner's 104-day Bible Marathon schedules (CSV source), generated plan JSON with Bible links, and link settings ([README](reading-plans/README.md)) | PR #50, #59 |

## Rules (enforced at review)

- Every content set carries `source_attribution` and `rights_note` fields; anything lacking them fails review.
- Scripture as **references only** (plus VIE2010 deep links) until a text license/API path lands — [content-rights.md](../docs/content-rights.md).
- No copied Knowing Him, Harmony Bible, or other copyrighted text. The harmony outline is sourced from Robertson/Project Gutenberg, never from harmony-bible.com's rendering.
- No partner-identifying information (PRD §21). Sample identities use `.test` domains.
- Translation workflow states: `draft → translated → theology-reviewed → language-reviewed → approved → published`. A substantive edit returns the file to `draft`; `published` means the STUDY rendering may be generated from it.

## Daily devotionals (exception)

Daily devotionals for the Reading Days are **not** kept in this directory. The owner writes them in Word or Google Docs, which are the master copy, exports them to Markdown and uploads them in wp-admin (J-Life → Devotionals, #211). The database is a rendering of those files; a hand edit in Gutenberg is protected from re-import. This applies to devotionals only: lessons and the other sets above keep the repo-content rule. Basis: [#205 comment 7069225640](https://github.com/i12know/jlife-platform/issues/205#issuecomment-7069225640), 2026-11-21.

## Authoring & validation

Series/lesson file formats, ID conventions, and a copyable example live in [`schemas/README.md`](schemas/README.md). Validate content files with:

```
npm run content:validate
```

The validator checks rights metadata, Scripture reference shape (shared with the harmony dataset), Robertson event ID linkage, phase-pending posture (#21), stable question/prompt IDs, and series↔lesson ordering.
