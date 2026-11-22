# CLAUDE.md

Guidance for Claude Code sessions working in this repository.

## Orientation

Read [README.md](README.md) first — it indexes the docs corpus. The load-bearing
documents are `docs/PRD.md` (requirements), `docs/architecture.md` (MVP design,
authoritative), `docs/vision-architecture.md` (long-term draft), and
`docs/integration-boundaries.md` (data ownership/privacy rules — amend
deliberately, never incidentally).

## Architecture decisions (gate)

Before work that touches installation topology, accounts/identity or
Disciple.Tools contact creation, deployment tooling, cross-site or cross-tenant
data flow, or ChMeetings integration, read the **decision register** in
[docs/framework-direction.md](docs/framework-direction.md),
[ADR 0001](docs/adr/0001-multi-deployment-tenant-domain-architecture.md), and
the pending-decisions issue [#168](https://github.com/i12know/jlife-platform/issues/168).

Authority, highest first:

1. Accepted ADRs (`docs/adr/`, `Status: Accepted`), approved requirements
   (`docs/PRD.md`), and the privacy rules in `docs/integration-boundaries.md`.
2. Curated design docs and runbooks (`docs/*.md`).
3. Proposed or provisional direction: register entries not yet accepted,
   `docs/vision-architecture.md`.
4. Supporting evidence only: spike records, older drafts, `docs/research/`.

- **Pending is not settled.** Don't implement a Proposed or Provisional
  decision as if it were accepted, and don't write or accept an ADR unless the
  owner asks; status changes only through an owner-approved ADR PR that also
  updates the register.
- **Flag conflicts; never resolve them silently.** When sources disagree, say
  so in your reply or PR, follow the higher tier, and ask the owner. Known
  conflicts are listed in `docs/framework-direction.md` under the decision register.
- **Every architecture-related PR states its basis**: follows an accepted
  decision (name it), depends on a pending ADR (name it, #168), or none.

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
