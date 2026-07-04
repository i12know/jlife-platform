# content/harmony

Gospel harmony dataset sourced from Robertson 1922 (public domain).

## Files

| File | Purpose |
|---|---|
| `robertson-1922-outline.json` | Machine-readable dataset — all 184 Robertson sections |
| `robertson-1922-outline.review.csv` | Human-reviewable CSV for audit and comparison tooling |

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

## Validation

The JSON was cross-checked against the Robertson "Table for Finding Any Passage" (every section has at least one scripture reference; all 185 events including the §128a/§128b split are present). Run `node bin/validate-harmony.js` (also part of `npm run content:validate` and CI) for automated validation: dataset invariants, JSON↔CSV sync, and phase posture.

Phase posture rules (enforced by the validator, per #21's workflow):

- `phase: null` requires `phase_mapping_status: "pending"` — unless `notes` is non-empty, which is how #21 records a reviewed non-narrative section deliberately left unmapped.
- A non-null `phase` requires `phase_mapping_status: "proposed"` or `"approved"` — a phase value with `"pending"` status means someone bypassed the mapping workflow.
