# Changelog

All notable changes to this project will be documented in this file.

This project is still pre-release. Until the first tagged release, dated entries describe meaningful repository milestones and MVP-facing changes merged to `main`.

The format follows the spirit of [Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and this project intends to use semantic versioning once releases begin. The version ladder of record — pilot milestones as 0.x, v1.0.0 at pilot completion, post-1.0 majors as capability epochs — is defined in [docs/roadmap.md §Versioning](docs/roadmap.md#versioning).

## Unreleased

### Changed

- Vietnamese Bible Marathon links now open Bible Gateway's *Vietnamese Bible: Easy-to-Read Version* (BPT) instead of Bible.com VIE2010 (#91): one "open all of today's passages" button, like English ERV, on Today, the SOAP page and the public `/reading/` day pages. The optional parallel readings on Today and the SOAP page now show their own per-language buttons too.
- Sign out on the participant screens now asks first (#89): an in-page dialog with Cancel and Sign out, or a `/signout/` confirmation page without JavaScript. Cancel keeps the participant on the same screen and day.

### Added

- Added an expandable **Read today's passages** reader above the SOAP form (#92): Bible Gateway's ERV/BPT page for the day's assigned passages in a frame, participant's language first with a version switch, loaded only when opened, sandboxed so it can't navigate away from the SOAP, publisher notice and an "Open in Bible Gateway" link always shown. Opening, closing and switching never touch the typed SOAP. Framing is not expressly authorized by Bible Gateway's terms; see `docs/content-rights.md`. Per-language switch: `"embed"` in `bible-links.json`.
- Added sharing (#62, M7) and the My Group feed (#61): from a submitted SOAP, `/share?day=dNNN` lets the participant tick which parts to share (Prayer unticked by default), see the exact preview, then **Post to My Group** (a snapshot only their current group can read; posting again replaces it; removable any time) and/or **Send to a chat** (the phone's share sheet or Copy text, with only a public link to that day's reading — never a sign-in, invite or personal link). `/group` shows the group's posts with first names only and no points. Sharing is never automatic, never part of Submit and never changes points; minors awaiting guardian consent cannot post to the group (D19); posts hidden by an organizer stay hidden. Covered by a PHPUnit share-screens suite.
- Added a reversible staging test drive (`wp jlife test-campaign create|remove|status`, and a **test_campaign** choice on the latoc deploy): creates a TEST Bible Marathon starting today with a Test Group and emails the invite link to the site admin (never logged); **remove** deletes all test-campaign data and test-only participant accounts without touching real campaigns. Runbook: `docs/staging-latoc.md`.
- Added the participant screens (#61) in `jlife-challenges`: `/today` (Day N, the reading with Bible links in the participant's language first, optional parallels collapsed, status and "N of 104"), `/soap?day=dNNN` (four guided fields, reference chips with no Bible text, server draft autosave plus an on-device backup, submit with a "1 point" confirmation, incomplete submissions keep the typed text) and `/marathon` (a 104-day grid with neutral wording for days not written). Signed-in participants landing on the home page go to Today. Works without JavaScript; covered by a PHPUnit screens suite (view states, privacy, autosave endpoint).
- Added `jlife-auth` participant sign-in (#58): emailed 6-digit codes (single use, 10 minutes, hashed, 5-guess limit, no account enumeration, rate limits, no sign-in links), `/join` with invite code, first name, age band (13+) and language, `/signin`, a versioned `/consent` step, 180-day phone sessions for participants, and no wp-admin or admin bar for participants.
- Added the `jlife-challenges` Bible Marathon engine (#60, design in `docs/challenges-engine-design.md`): campaign calendar in Pacific time (DST-safe), groups with hashed invite codes and audited moves, private SOAP entries with drafts and revision history, a database-enforced one-point-per-person-per-day ledger with the grace window and backfill rules, organizer counts and a score export without SOAP text, deliberate group shares as snapshots with moderation (hide), group-move rules and minors' guardian-consent gate, and account-deletion purge — covered by PHPUnit calendar, scoring, privacy, share and lifecycle suites.
- Added security prerequisites (#57): `SECURITY.md` (private vulnerability reporting), Dependabot for Actions/npm/Composer, an advisory dependency-audit CI job, and deploy checks that keep `jlife-bridge` magic links off on latoc.
- Added the 104-day reading-plan import (#59): a dependency-free Scripture-reference parser and Bible-link builder (English ERV via one Bible Gateway link per day, Vietnamese VIE2010 via Bible.com per passage, chapter-crossing ranges split), generated plan JSON from the owner's CSV schedules, a CI validator and unit tests, and a `wp jlife reading-plan import` command that publishes one reference-only page per day with the optional parallel readings attached; the latoc deploy imports the plans.
- Added a manual GitHub Actions deploy for the `latoc.vayhub.org` development/pilot staging site (Option C: WordPress multisite, STUDY site only, `jlife-studies` + `jlife-huddles`, no Disciple.Tools or `jlife-bridge`), with a pre-deploy database backup that blocks the deploy if it fails; runbook in `docs/staging-latoc.md`.
- Added a cloud-session dev runtime (`bin/cloud-dev.sh`, `npm run cloud:*`) that builds the same multisite + Disciple.Tools network without `wp-env`'s Docker builds, so sandboxed sessions can run setup, seed, verify, and every plugin's PHPUnit suite; documented in `docs/dev-environment.md` §7a.
- Added the canonical J-Life domain architecture defining the organization-neutral framework, independently governed deployments, isolated church tenants, public-site boundary, VAY ChMeetings Diocese mapping, identity scopes, integration adapters, and repository responsibilities.
- Added ADR 0001 accepting the multi-deployment tenant model: VAY Hub under `vayhub.org`, church tenants such as `rp.vayhub.org` and `gla.vayhub.org`, and a possible independently governed SonLife Hub deployment rather than placing SonLife under VAY.
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

- Site-admin moderation reads of huddle threads and progress aggregates now require a group the membership source confirms exists; unknown or unavailable groups fail closed for everyone (#48 finding 2, #57). Membership sources report `exists`; the bridge sets it from the D.T group record.
- CI and deploy workflows move to `actions/checkout@v5` / `actions/setup-node@v5` (Node 24 runtime).
- Clarified that the current STUDY/HUB WordPress multisite design is a tenant-level implementation inside the broader framework/deployment/tenant domain architecture.
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