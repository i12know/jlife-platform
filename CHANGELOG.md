# Changelog

All notable changes to this project will be documented in this file.

This project is still pre-release. Until the first tagged release, dated entries describe meaningful repository milestones and MVP-facing changes merged to `main`.

The format follows the spirit of [Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and this project intends to use semantic versioning once releases begin. The version ladder of record — pilot milestones as 0.x, v1.0.0 at pilot completion, post-1.0 majors as capability epochs — is defined in [docs/roadmap.md §Versioning](docs/roadmap.md#versioning).

## Unreleased

### Added

- Added the S2 Vietnamese localization audit covering Disciple.Tools theme, mobile app, and target plugin translation gaps.
- Added the draft vision-architecture document covering church-wide scale-out: the verified vayhub `rdpt22` challenge analysis, the identity ladder, `jlife-challenges`/`jlife-dispatch` designs (relay-first dispatch), ChMeetings integration scheduling, RP Pathway App convergence, and a phased roadmap with Phase A substrate-hardening items.
- Added the draft 4-lesson Gospel of John pilot series in the portable content schema (English source text; Vietnamese translation, theology review, and field rendering still pending under issue #7).
- Added workspace-scoped VS Code JSON-schema bindings for pilot lesson and series files, and documented content editing tool options in the schemas README.
- Added the versioning roadmap (`docs/roadmap.md` §Versioning): 0.x pilot milestones, v1.0.0 at Phase A exit, and post-1.0 capability epochs in catalog-first order (2.0 catalog generalization → 3.0 challenge engine → 4.0 ChMeetings → 5.0 church-wide launch → 6.0 multiplication), with licensed content shipping as rights-gated minor releases.
- Added the team structure document (`docs/team.md`): hub-and-rings working model around the owner, per-ring review protocols, human-only lanes (theology, native-reader review, rights, privacy postures) vs AI-run build lanes, cadence, and the bus-factor plan.
- Added a research archive (`docs/research/`) for raw research inputs kept verbatim with provenance headers — quarantined from the curated corpus (not authoritative, not loaded by default; rules in its README) — seeded with the 2026-07-17 deep-research review of the Disciple.Tools mobile-app fork ecosystem.
- Added the mobile app posture decision (`docs/disciple-tools-landscape.md` §4.5): participants stay PWA + magic links (no native app); leaders consume the official D.T store app with a Phase D staging validation gate (JWT/WP 7.0, Vietnamese); no fork adoption from the stale mobile-app lineage; Phase F-era horizon note prefers a fresh Expo client over fork archaeology if a native app ever becomes necessary.
- Added a root `CLAUDE.md` orienting Claude Code sessions: docs corpus entry points, the shared [vay-chmeetings-skill](https://github.com/i12know/vay-chmeetings-skill) as required reading before any ChMeetings work (Option C reference now; submodule when Phase C begins), the D.T landscape refresh protocol, repo-safety rules, and engineering discipline.
- Added the Disciple.Tools landscape document (`docs/disciple-tools-landscape.md`): a living, relevance-rated map of the full D.T ecosystem beyond the pilot slice — documentation sources, theme-framework capabilities (custom post types, field registration, API hooks, custom tables, site-to-site auth), the ~41-plugin directory, proposed decision deltas (Magic Link/Twilio dispatch adopt-vs-build for S8, Prayer Campaigns study before the challenge engine, site-to-site link as the S7 auth candidate), and a refresh protocol for keeping the map current as D.T and J-Life evolve.

### Changed

- Downgraded vision risk #1 (ChMeetings API capability unknown) and narrowed the S7 spike scope: the shared `vay-chmeetings-skill` documents and largely live-verifies the ChMeetings API (REST + OpenAPI, per-tenant API keys, People/Groups/Events, outbound webhooks, merge-orphan 404 semantics); residual S7 work is J-Life-specific (field map to D.T contacts, webhook signature verification, RP-tenant key, merge-orphan handling). Cross-referenced the skill from the integration-boundaries identity-keys section.
- Aligned the pilot lesson scope from 5-7 to 4-7 lessons across the PRD, architecture, pilot-context, and roadmap documents.
- Amended the vision-architecture phase order per owner review: catalog generalization (Phase E1) promoted ahead of the challenge engine so general Bible curricula are supported before further Life-of-Christ-harmony-specific work; the rights-gated catalog remainder (Phase E2) ships as minor releases.
- Expanded the team document's Ring 2 platform-engineering lane into a four-role matrix (architect/design reviewer, implementer, adversarial tester, chronicler) with the LLM characteristics each role needs, example model classes, per-role usage guidance, and lane rules (author ≠ reviewer, docs corpus as shared memory, S5 checklist on every merge).

## 0.0.0 - 2026-07-05

Initial pre-release baseline covering repository work from 2026-07-03 through 2026-07-05.

### Added

- Created the initial repository README, product requirements draft, technical analysis, architecture notes, roadmap, content-rights register, integration-boundary guidance, and development-environment documentation.
- Added repository structure for planning docs, portable content, design/prototype artifacts, local tooling, and custom WordPress plugin work.
- Added scaffold plugins for `jlife-studies`, `jlife-huddles`, and `jlife-bridge`.
- Added a reproducible local WordPress multisite development environment using `wp-env`, Docker, Disciple.Tools, and verification scripts.
- Added GitHub Actions CI for coding standards, PHPStan static analysis, PHPUnit, and portable content validation.
- Added portable content schemas for series, lessons, scripture references, and example content files.
- Added the Robertson 1922 harmony outline dataset with phase, sub-phase, and phase-mapping-status fields for Gospel events.
- Added content validation and round-trip tooling for portable study content.
- Added spike documentation for S1 multisite/Disciple.Tools feasibility, S3 huddle-to-group mapping, S4 magic-link flow, S5 privacy-scoped huddle data, and S6 content round-trip validation.
- Added the pilot huddle workflow context for MVP implementation planning.
- Added the J-Life system primer documenting the STUDY/HUB split, Disciple.Tools role, plugin boundaries, deployment model, and likely future ChMeetings and Disciple.Tools Mobile App integration points.
- Added `jlife-bridge` spike code for Disciple.Tools group membership reads, huddle hub tile data, and magic-link handling.
- Added `jlife-huddles` spike code for huddle schema setup, lesson progress, private notes, and access-control gates.
- Added PHPUnit coverage for privacy-sensitive huddle access behavior.

### Changed

- Aligned the PRD, architecture notes, and roadmap around a WordPress multisite direction with separate STUDY and HUB responsibilities.
- Recorded the S1 security-model consequence that multisite authentication is shared and access separation must be enforced by explicit capability and data-access checks.
- Hardened S3/S4 bridge spike behavior so sensitive group mapping and magic-link assumptions are exercised by code and documented for follow-up implementation.
- Hardened S5 private-note and progress access so participant reads require current huddle membership.
- Scoped private notes by huddle group and lesson to avoid collisions when the same user studies the same lesson in multiple huddles.
- Tightened phase-posture validation and first-run CI behavior after review feedback.
- Pinned plugin test dependency resolution to the CI PHP 8.2 platform expectation.

### Fixed

- Closed local dev-environment verification gaps from review feedback.
- Stabilized `wp-env` startup and PHPUnit cache recovery for local acceptance.
- Fixed first-run CI findings, including test library behavior and PHPStan cleanup.
- Prevented plugin-level `composer.lock` files from being committed, avoiding accidental PHP 8.4 lockfile drift in plugin test dependencies.
- Removed the stale-membership privacy gap where a removed participant could still read their own huddle progress or private notes.
