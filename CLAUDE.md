# CLAUDE.md

Guidance for Claude Code sessions working in this repository.

## Orientation

Read [README.md](README.md) first — it indexes the docs corpus. The load-bearing
documents are `docs/PRD.md` (requirements), `docs/architecture.md` (MVP design,
authoritative), `docs/vision-architecture.md` (long-term draft), and
`docs/integration-boundaries.md` (data ownership/privacy rules — amend
deliberately, never incidentally).

## Shared skills

- **ChMeetings** (any work touching ChMeetings, `jlife-chm-sync`, or spike
  S7/#33): before writing anything, read the shared portfolio skill at
  [i12know/vay-chmeetings-skill](https://github.com/i12know/vay-chmeetings-skill)
  (`skill/SKILL.md` plus its `docs/`). It is the cross-project source of truth
  for the ChMeetings API, auth, webhooks, multi-tenancy/merge behavior, the
  `CHM_FIELDS` mapping pattern, and Vietnamese-name conventions. This repo
  consumes it by reference (Option C) until Phase C begins; the first
  `jlife-chm-sync` PR should vendor it as a git submodule (Option A) per that
  repo's README.
- **Disciple.Tools**: the ecosystem map lives in
  [docs/disciple-tools-landscape.md](docs/disciple-tools-landscape.md) — refresh
  it per its §8 protocol before phase spikes.

## Docs tiers

The corpus has three tiers; don't mix them when ingesting:

1. **Curated docs** (`docs/*.md`) — authoritative; what to trust and load.
2. **Spike records** (`docs/spikes/`) — dated, live-verified evidence.
3. **Research archive** (`docs/research/`) — raw inputs kept verbatim for
   provenance. **Not authoritative; do not load by default.** Read a file
   there only when re-examining the decision it fed; rules in its README.

## Repository safety (PRD §21)

This repo may become public. Never commit partner-identifying information, real
contact details, credentials, exports/screenshots from private systems
(ChMeetings, Disciple.Tools, Gmail, Calendar), or unlicensed content. Sample
data uses fake `.test` identities only.

## Engineering discipline

- Every participant-data read/write goes through the `jlife-huddles` gate
  (S5); new surfaces get a sensitivity-matrix row and CI tests first.
- Cross-subsite access only via `jlife-bridge` functions — no theme-level or
  ad-hoc queries across the STUDY/HUB boundary.
- All user-facing strings translation-ready (correct text domain) from the
  first commit; Vietnamese-first posture.
- CI must stay green: PHPCS, PHPStan, PHPUnit (access-control tests required),
  portable-content validation.
