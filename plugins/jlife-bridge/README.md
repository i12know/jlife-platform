# jlife-bridge

Network-activated bridge between the **STUDY** subsite and the Disciple.Tools **HUB** subsite. Scaffold only — see issue #14; feature code is designed in spikes S3 (#10) and S4 (#11) first.

Scaffold note: this shell follows Disciple.Tools **starter-plugin conventions** (guarded bootstrap, `Disciple_Tools` presence check) rather than vendoring the full starter template — the template's feature boilerplate (sample post types, tiles, endpoints, admin pages) would violate the thin-scaffold boundary of #14. When S3 builds the group tile, graft the relevant starter-template components then.

## Will own (per [architecture.md §6](../../docs/architecture.md))

- Identity mapping: participant/leader `wp_users.ID` ↔ D.T `dt_contact_id`; huddle ↔ `dt_group_id` ([integration-boundaries.md §2](../../docs/integration-boundaries.md))
- Participant onboarding flow (invite link → user/magic-link → contact created/linked → group membership)
- Cross-subsite read API with capability checks (roster/leader to STUDY; progress aggregates to HUB) — the **only** sanctioned path across the boundary; no theme-level or ad-hoc cross-site queries
- The D.T group-record progress tile on HUB (spike S3)

## Will NOT own

- Study content (→ `jlife-studies`); discussion/notes/progress storage (→ `jlife-huddles`)
- ChMeetings sync (→ future `jlife-chm-sync`, deferred; see [integration-boundaries.md](../../docs/integration-boundaries.md))
- Carrying discussion/note/prayer text across the boundary — forbidden by [integration-boundaries.md §4](../../docs/integration-boundaries.md)

Text domain: `jlife-bridge`.
