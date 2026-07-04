# /content — Portable Study Content

Study content lives here as portable, rights-aware files. The WordPress database is a *rendering* of this content, never its only home ([architecture.md §4](../docs/architecture.md)).

## Layout

| Directory | Holds | Populated by |
|---|---|---|
| `schemas/` | JSON/Markdown schema definitions + a documented example lesson | #5 |
| `pilot-lessons/` | The 5–7 Vietnamese pilot lessons | #7 |
| `harmony/` | `gospel_event` outline dataset from the public-domain Robertson (1922) harmony | #6 |

## Rules (enforced at review)

- Every content set carries `source_attribution` and `rights_note` fields; anything lacking them fails review.
- Scripture as **references only** (plus VIE2010 deep links) until a text license/API path lands — [content-rights.md](../docs/content-rights.md).
- No copied Knowing Him, Harmony Bible, or other copyrighted text. The harmony outline is sourced from Robertson/Project Gutenberg, never from harmony-bible.com's rendering.
- No partner-identifying information (PRD §21). Sample identities use `.test` domains.
- Translation workflow states: `draft → translated → theology-reviewed → language-reviewed → approved → published`.
