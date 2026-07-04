# jlife-huddles

Huddle layer for the **STUDY** subsite. Scaffold only — see issue #14; the data model and capability checks are designed and tested in spike S5 (#12) **before** feature code lands here.

## Will own (per [architecture.md §5–§6](../../docs/architecture.md))

- Huddle membership mirror (roster/leader read from HUB via the bridge; keyed by `dt_group_id`)
- Per-lesson discussion threads scoped to a huddle — custom tables, capability checks on **every** read/write path
- Private reflection notes (author-only at the app layer; see [integration-boundaries.md §7](../../docs/integration-boundaries.md) for the admin-visibility honesty rule)
- Lesson progress records (participant sees own; leader sees completion flags only, never note content)
- Invite links/codes and the participant onboarding flow (with `jlife-bridge`)

## Will NOT own

- Study content (→ `jlife-studies`)
- Direct Disciple.Tools reads/writes or ID mapping (→ `jlife-bridge`)
- Coaching/cohort workflow — that lives in Disciple.Tools on HUB

## Non-negotiables

Discussion text, note text, and prayer text never leave this plugin's storage toward HUB, ChMeetings, or exports ([integration-boundaries.md §4](../../docs/integration-boundaries.md)). Access-control tests are required CI checks (#16).

Text domain: `jlife-huddles`.
