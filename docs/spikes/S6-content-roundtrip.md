# S6: Content schema round-trip (import/export)

Issue: #13 · Timebox: 1 week · Actual: ~0.5 day (exercised against a live multisite)

## Question

Can study content round-trip losslessly between the portable files in `/content/`
(the source of truth, architecture.md §4) and WordPress CPTs (the rendering) —
including Vietnamese text — and can an imported lesson render on STUDY?
(roadmap S6; depends on the #5 schema.)

## What we did

Prototyped the full chain in `plugins/jlife-studies` and exercised it against a
live subdirectory multisite (WordPress 7.0, PHP 8.2, the #15 wp-env recipe):

- **CPTs + taxonomies** (`includes/content-types.php`): `jlife_series` and
  `jlife_lesson`, plus derived flat taxonomies `gospel_phase`, `gospel_event`,
  `scripture_ref` — rebuilt from file fields on import, never authored in wp-admin.
- **Import/export core** (`includes/content-io.php`): every schema field stored
  as its own JSON-encoded post meta (`_jlife_<field>`); export reconstructs the
  document from those decomposed metas in canonical schema key order.
- **WP-CLI** (`includes/class-jlife-content-command.php`):
  `wp jlife content import <file>...` (upsert by stable ID) and
  `wp jlife content export --dir=<dir> [--id=<stable-id>]`.
- **Rough reader** (`includes/reader.php`): renders an imported lesson's
  sections on the lesson permalink; huddle prompts and leader notes render only
  for users with `edit_others_posts`.

Test set: the committed example series + English lesson, plus a Vietnamese
lesson (`lsn-example-hay-theo-ta`, full diacritics in every section) validated
against the schema first. All imports/exports run through the real WP-CLI in
the wp-env `cli` container.

## Findings

### 1. Round-trip is lossless — PASS (the pass condition)

- **Import**: series + 2 lessons → posts 4–6; `gospel_event` terms
  (`r1922-041`, `r1922-047`) and `scripture_ref` terms created from the files.
- **Export → diff**: all three documents **semantically identical** to the
  sources (deep JSON equality). The Vietnamese file and the series file were
  **byte-identical**; the English lesson differed only in array whitespace
  (the hand-authored source uses a compact one-line array, the exporter always
  expands — same data).
- **Stability**: import-of-export then re-export is **byte-identical** for all
  three documents, so after one normalization pass the format is a fixed point —
  exactly what file-based diff review needs.
- **Exports re-validate**: `bin/validate-content.js` passes on the exported
  files, closing the loop against the #5 schema.

### 2. Vietnamese text integrity — PASS

Diacritics survived DB storage, meta round-trip, and rendering
(`Đức Chúa Giê-xu gặp bốn người đánh cá…`, `Mác 1:16-20` all intact in the
exported JSON and in the rendered page). Two things made this work and should
be kept: `JSON_UNESCAPED_UNICODE` on every encode, and `wp_slash()` around
meta writes (WordPress unslashes meta input; unslashed JSON with quotes/escapes
would corrupt).

### 3. Rendering — PASS

The lesson permalink renders scripture references (with deep-link support),
teaching, outside-the-box, reflection questions, live-it-out, and prayer for
anonymous readers. Huddle prompts and leader notes are **capability-gated**
(`edit_others_posts`): verified absent for an anonymous fetch and present for a
logged-in leader-capable user. Markdown is currently escaped plain text — the
Markdown pipeline is an MVP decision, not a spike blocker.

### 4. Design notes that should carry into the MVP

- **Decomposed meta, not a blob.** Export rebuilds from per-field metas; the
  original file is never stored. A passing diff therefore proves the DB
  projection carries all the data — resist the temptation to "cache" the source
  JSON, which would make round-trip tests meaningless.
- **JSON-encoding each meta value** preserves the null / absent-optional-field
  distinction (`"null"` vs no meta row), which plain string metas cannot.
- **Stable IDs as post slugs** (`post_name = lesson_id`) make upsert trivial
  and give readable permalinks (`/?jlife_lesson=lsn-…`).
- **Taxonomies are projections.** They're rebuilt on every import; wp-admin term
  edits would be overwritten. Fine for the architecture (files are the home),
  but worth stating in contributor docs.

## Conclusion — PASS

Example lessons import, render, and export losslessly, including Vietnamese
text. The #5 schema needed **no changes** to survive the round-trip — the S6
target is stable. Unblocks #7 (pilot lessons can be authored knowing they'll
load) and gives #16's CI a future job (round-trip test in the phpunit suite).

## Consequences / follow-ups

- **#7 (pilot lessons):** author directly in the schema; this prototype imports
  them as-is.
- **#16 (CI):** add a PHPUnit round-trip test (import example → export →
  deep-compare) to the jlife-studies suite so losslessness is regression-tested.
- **MVP build:** pick a Markdown renderer (server-side, sanitized); decide
  whether `translation_status` should map to post_status (currently everything
  imports as `publish`; drafts-by-status is probably right for the real STUDY).
- **Normalization note:** one cosmetic normalization pass (array whitespace) is
  expected when a hand-authored file first round-trips; committing the exported
  form keeps subsequent diffs clean.
