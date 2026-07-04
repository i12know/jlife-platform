# jlife-studies

Study content engine for the **STUDY** subsite. Scaffold only — see issue #14; feature logic arrives with #5/#13 and the MVP build.

## Will own (per [architecture.md §6](../../docs/architecture.md))

- `jlife_series` / `jlife_lesson` custom post types and the section structure (scripture reference, teaching, outside-the-box, reflection questions, live-it-out, prayer, huddle discussion prompts, leader notes)
- Taxonomies/reference fields: `gospel_phase`, `gospel_event`, machine-readable scripture refs
- Translation workflow states (draft → translated → theology-reviewed → language-reviewed → approved → published)
- Study reader templates/blocks
- Lossless JSON/Markdown import/export against `/content/schemas/` (WP-CLI)

## Will NOT own

- Huddle membership, discussion, notes, progress (→ `jlife-huddles`)
- Any Disciple.Tools integration or ID mapping (→ `jlife-bridge`)
- Rendered copyrighted Scripture text — references and licensed/API paths only ([content-rights.md](../../docs/content-rights.md))

Text domain: `jlife-studies`. All strings translation-ready from the first commit.
