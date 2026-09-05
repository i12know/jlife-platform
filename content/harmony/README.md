# content/harmony

Gospel harmony dataset sourced from Robertson 1922 (public domain).

## Files

| File | Purpose |
|---|---|
| `robertson-1922-outline.json` | Machine-readable dataset — all 184 Robertson sections |
| `robertson-1922-outline.review.csv` | Human-reviewable CSV for audit and comparison tooling |
| `kjv-word-counts.json` | Per-verse word counts (KJV, public domain) for every book the harmony references — see below |
| `subsections.json` | Lettered subdivisions of 35 sections (34 Thomas & Gundry, 1 SonLife) — see below |

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

## Section subdivisions (`subsections.json`)

35 of Robertson's 184 sections are long enough that several downstream readings subdivide
them with lettered suffixes on Robertson's own section number (e.g. §8 → §8a/§8b/§8c). This
file is an **additive overlay** on `robertson-1922-outline.json`, which stays untouched as
the canonical Robertson dataset — nothing here changes it, this just adds finer-grained
reading units on top for consumers that want them (like `bin/build-reading-plan.js
--granularity=subsection`).

Two provenances, tagged per entry via `subsection_source`:

- **`"thomas-gundry"`** (34 sections, 104 subsections) — Robert L. Thomas & Stanley N.
  Gundry's NIV/NASB *Harmony of the Gospels* editorial split of Robertson's longer sections.
  Cross-verified against two independently sourced transcriptions of their numbering before
  being committed here. Only the split points (verse boundaries) are stored — never Thomas &
  Gundry's text — which needs no rights clearance on the same public-domain-arrangement
  reasoning `docs/content-rights.md` §3 point 4 already applies to Robertson's own division of
  the Gospels into sections.
- **`"sonlife"`** (§44a/§44b, plus an `overrides` entry trimming §41) — SonLife's own teaching
  tradition, *not* part of Thomas & Gundry's numbering. Luke 5:1-11 ("Peter's great catch") is
  pulled out of §41 (which keeps only Matthew 4:18-22 + Mark 1:16-20) and reattached after §44
  as its own two-part unit. Titles throughout are original SonLife phrasing.

Every entry carries a precomputed `words` field (KJV word count, via `kjv-word-counts.json`,
with a shared verse split 50/50 when a subsection boundary falls mid-verse) — informational;
`bin/build-reading-plan.js` recomputes from `scripture_refs` itself rather than trusting it
blindly.

## Daily reading plans

`bin/build-reading-plan.js` generates a daily reading plan from this dataset, balanced by
Scripture **word count** (not verse or section count — a pericope's verse length varies a
lot, words track actual reading time more closely). Since each harmony section lists the
parallel Gospel accounts of the same event, two reading styles are supported:

- `--style=parallel` — read every parallel account listed per section (comparative harmony:
  how the Gospels tell the same event differently).
- `--style=primary` — read one account per section, the longest parallel (continuous
  narrative: the story once, without repeats).

A day always gets one or more whole units, never part of one — the days are the contiguous
split of the units into `--days` groups that minimizes the total squared deviation from the
average day length (least squares), so every day is pulled toward the target rather than only
capping the heaviest one. Because units are never split, the longest day is still bounded
below by the longest single unit in range; the tool reports that floor when a day hits it.

`--granularity` picks the atomic unit: `section` (default, Robertson's 185 sections) or
`subsection` (255 units — the 35 sections `subsections.json` subdivides are split into their
lettered pieces). Subsection granularity gives a much more even plan at low day counts, since
a whole-section outlier like the Olivet Discourse (§139, ~3,566 words on its own under
`parallel` style) no longer has to be one day by itself — it splits into its own 7 lettered
pieces like everything else.

```
node bin/build-reading-plan.js --style=parallel --days=184
node bin/build-reading-plan.js --style=primary --days=90 --out=my-plan.json
node bin/build-reading-plan.js --style=parallel --days=90 --granularity=subsection
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
