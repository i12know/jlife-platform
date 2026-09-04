# content/harmony

Gospel harmony dataset sourced from Robertson 1922 (public domain).

## Files

| File | Purpose |
|---|---|
| `robertson-1922-outline.json` | Machine-readable dataset — all 184 Robertson sections |
| `robertson-1922-outline.review.csv` | Human-reviewable CSV for audit and comparison tooling |
| `kjv-word-counts.json` | Per-verse word counts (KJV, public domain) for every book the harmony references — see below |

## Source

**A Harmony of the Gospels for Students of the Life of Christ**
- Original harmony: John A. Broadus, 1893
- Revised by: A. T. Robertson, 1922
- Full text: Project Gutenberg #36264 — https://www.gutenberg.org/ebooks/36264
- Rights: Public domain (US copyright expired). See `docs/content-rights.md §3`.

Content was extracted only from Robertson/Gutenberg. No content from harmony-bible.com or other secondary sources was used.

## ID convention

Stable IDs are derived from Robertson's own section numbers:

```
r1922-001   →  Robertson §1
r1922-042   →  Robertson §42
r1922-128a  →  Robertson §128a
r1922-128b  →  Robertson §128b
r1922-184   →  Robertson §184
```

**IDs are permanent.** If titles are improved, IDs must not change. Section §128 is split into §128a and §128b exactly as Robertson numbered them.

## Scripture reference format

Each `scripture_refs` entry is a structured object:

```json
{
  "book":          "Luke",
  "chapter_start": 1,
  "verse_start":   1,
  "chapter_end":   1,
  "verse_end":     4,
  "display":       "Luke 1:1-4"
}
```

`verse_start` and `verse_end` are `null` for whole-chapter ranges (e.g. `John 14`, `Matthew 5–7`).

## Phase mapping — pending

All 185 events carry `"phase": null, "sub_phase": null, "phase_mapping_status": "pending"`.

Mapping the 184 Robertson sections onto the 5 SonLife phases is ministry judgment, not data entry, and is deferred to issue #21 (`ministry-discernment` + `theological-review`). The `phase` and `sub_phase` fields are present in the schema so downstream consumers can reference them now; they will be populated once the ministry review is complete.

## Daily reading plans

`bin/build-reading-plan.js` generates a daily reading plan from this dataset, balanced by
Scripture **word count** (not verse or section count — a pericope's verse length varies a
lot, words track actual reading time more closely). Since each harmony section lists the
parallel Gospel accounts of the same event, two reading styles are supported:

- `--style=parallel` — read every parallel account listed per section (comparative harmony:
  how the Gospels tell the same event differently).
- `--style=primary` — read one account per section, the longest parallel (continuous
  narrative: the story once, without repeats).

A day always gets one or more whole sections, never part of one — the days are the
contiguous split of the 185 sections into `--days` groups that minimizes the heaviest day's
word count.

```
node bin/build-reading-plan.js --style=parallel --days=184
node bin/build-reading-plan.js --style=primary --days=90 --out=my-plan.json
```

`kjv-word-counts.json` stores integer word counts per verse only — never verse text, per
[content-rights.md §4](../../docs/content-rights.md) ("References only — never paste
Scripture text"). It's derived from the public-domain KJV (Project Gutenberg #10; see
[content-rights.md §3](../../docs/content-rights.md) for why English KJV/public-domain text
needs no rights clearance). Regenerate it with `node bin/generate-kjv-word-counts.js` if the
harmony dataset ever references a book/chapter it doesn't yet cover — `bin/validate-harmony.js`
checks coverage.

## Validation

The JSON was cross-checked against the Robertson "Table for Finding Any Passage" (every section has at least one scripture reference; all 185 events including the §128a/§128b split are present). Run `node bin/validate-harmony.js` (also part of `npm run content:validate` and CI) for automated validation: dataset invariants, JSON↔CSV sync, and phase posture.

Phase posture rules (enforced by the validator, per #21's workflow):

- `phase: null` requires `phase_mapping_status: "pending"` — unless `notes` is non-empty, which is how #21 records a reviewed non-narrative section deliberately left unmapped.
- A non-null `phase` requires `phase_mapping_status: "proposed"` or `"approved"` — a phase value with `"pending"` status means someone bypassed the mapping workflow.
