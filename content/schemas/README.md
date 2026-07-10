# /content/schemas — Portable Content Schema (series & lessons)

Defines the file format for study content. Content lives in these files; the WordPress database is a *rendering* of them ([architecture.md §4](../../docs/architecture.md)). This schema is the stable target for the pilot lessons (#7) and the S6 round-trip spike (#13).

## Files

| File | Purpose |
|---|---|
| `series.schema.json` | JSON Schema (2020-12) for a study series |
| `lesson.schema.json` | JSON Schema (2020-12) for a single lesson |
| `scripture-ref.schema.json` | Shared Scripture reference shape (same as the harmony dataset, #6) |
| `examples/example-series.json` | Worked example series — copy it to start a new series |
| `examples/example-lesson.json` | Worked example lesson — copy it to start a new lesson |

Validate with:

```
npm run content:validate                 # examples + everything in content/pilot-lessons/
node bin/validate-content.js <file>...   # specific files (validate a lesson together with its series)
```

The validator is dependency-free and enforces the invariants below; CI (#16) can additionally run ajv against the `.schema.json` files.

## Authoring a lesson (quick path)

1. Copy `examples/example-lesson.json`; pick a permanent `lesson_id` (`lsn-<slug>`, lowercase).
2. Fill the authored sections — `teaching`, `outside_the_box`, `live_it_out`, `prayer_prompt`, `leader_notes` — as **Markdown strings. No raw HTML** (the validator rejects it).
3. Add `reflection_questions` (ids `q01`, `q02`, …) and `huddle_discussion_prompts` (ids `p01`, …). Ids are stable within the lesson: once published, never renumber when wording changes.
4. Point `primary_gospel_event_id` at the Robertson event the lesson centers on (see `content/harmony/robertson-1922-outline.json`); list any additional events in `related_gospel_event_ids`.
5. Enter `scripture_reference` as structured references (shape below). **References only — never paste Scripture text** ([content-rights.md](../../docs/content-rights.md)).
6. Leave `phase: null`, `sub_phase: null`, `phase_mapping_status: "pending"` — phase mapping is ministry review (#21), not authoring.
7. List the `lesson_id` in the series file's `lessons` array; `order` must match its position there.
8. Run the validator.

## Editing tools

These are JSON files with long Markdown prose packed into string fields (`teaching`,
`leader_notes`, …) — readable by machines, uncomfortable for humans, and a bad fit for
Word (it will "helpfully" rewrite quotes/formatting and there is no lossless path back
to valid JSON — see the S6 round-trip guarantee in [content/README.md](../README.md)).

**For reading and light edits — VS Code, schema-validated (set up for this repo):**
Opening this repo in VS Code auto-binds `content/pilot-lessons/*.json` and
`content/schemas/examples/*.json` to the matching schema (`.vscode/settings.json`,
workspace-scoped — it does not change how VS Code handles JSON in any other project
on your machine). You get inline red squiggles on schema violations, field
descriptions on hover, and autocomplete for enum values (`translation_status`,
`phase_mapping_status`) and ID patterns — catching mistakes before you even run
`npm run content:validate`.

**For heavier prose authoring/translation — author in Markdown, convert to JSON.**
The `\n\n`-escaped strings are painful to write and risky to hand-edit (one stray `"`
breaks the file). [Obsidian](https://obsidian.md/) (free) is a strong fit *because*
the authored sections are already plain Markdown, not rich text — frontmatter for the
metadata fields (`lesson_id`, `translation_status`, `primary_gospel_event_id`, …),
Markdown body for `teaching`/`reflection_questions`/etc., edited with live preview. A
translator only ever touches prose under a heading, never JSON syntax. There is no
`.md` ⇄ `.json` converter yet for this schema — if you want to work this way, ask
before starting a translation pass so the tooling exists first.

## Key conventions

### Scripture references (shared with #6)

```json
{
  "book": "Mark",
  "chapter_start": 1,
  "verse_start": 16,
  "chapter_end": 1,
  "verse_end": 20,
  "display": "Mark 1:16-20",
  "deep_link": null
}
```

- `verse_start`/`verse_end` are `null` for whole-chapter ranges (e.g. John 14).
- `book` is the full English name; abbreviations/OSIS codes would be *additional* fields later, never a replacement.
- `deep_link` is reserved for licensed online renderings (e.g. VIE2010) once the link convention lands.

### Stable IDs (round-trip safety, #13)

| Thing | Pattern | Example |
|---|---|---|
| Series | `ser-<slug>` | `ser-following-jesus` |
| Lesson | `lsn-<slug>` | `lsn-come-follow-me` |
| Reflection question | `qNN` (per lesson) | `q01` |
| Huddle prompt | `pNN` (per lesson) | `p01` |
| Gospel event | `r1922-NNN` (from #6) | `r1922-041` |

IDs are permanent identity; wording can change, IDs cannot. The fixed named sections (`teaching`, `outside_the_box`, …) need no separate `section_id` — the field name **is** the stable identity.

### Phase fields

Same posture and vocabulary as the harmony dataset: `phase`/`sub_phase` are `null` with `phase_mapping_status: "pending"` until the SonLife phase mapping is done under ministry review (#21). The status enum is `pending | proposed | approved` — identical across lessons and the harmony dataset so tooling never translates between vocabularies.

### Rights metadata

`source_attribution` and `rights_note` are **required at the series level**; a lesson sets them to `null` to inherit, or to a string to override. No copied Knowing Him, Harmony Bible, or copyrighted Bible text anywhere ([content/README.md](../README.md)).

### Workflow states

`translation_status`: `draft → translated → theology-reviewed → language-reviewed → approved → published` (see [content/README.md](../README.md)). A substantive edit returns the file to `draft`.

## Out of scope

These files hold **study content only**. Participant responses, private notes, huddle discussion, prayer requests, and progress belong to `jlife-huddles` and the privacy/access-control work (#12) — never to `/content`.
