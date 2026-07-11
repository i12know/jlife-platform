# Development Roadmap: J-Life Platform

Status: Draft for review
Created: 2026-07-03
Related: [PRD.md](PRD.md), [technical-analysis.md](technical-analysis.md), [architecture.md](architecture.md)

Timeboxes are working estimates for a small, partly volunteer team, not commitments. Phases follow PRD Section 8; this document adds the technical workstreams, spike definitions, exit criteria, and dependency ordering.

## Critical Path: Rights And Coordination

Existing ministry relationships may make some rights conversations faster and more relational than a cold external negotiation, but written confirmation is still required before content is translated, copied, rendered, or distributed (see [content-rights.md](content-rights.md)). Three items still gate downstream work, in descending order of remaining effort:

1. **Vietnamese Bible text** — the one genuinely external item; owned by Bible societies outside SonLife/Concentric. Pick a translation/API with a workable license.
2. **Founders Passion / Harmony Bible** — a relationship-based conversation, but a real one: their platform has no Vietnamese and no export/API today, so the *technical* path (they add Vietnamese, they provide data, or we deep-link) must still be agreed and built.
3. **SonLife / Knowing Him** — written confirmation to translate/adapt.

Engineering proceeds in parallel using original placeholder content and reference-only Scripture, so no phase blocks on these — but **publication** milestones and the Phase 4/5 content scope do.

**Prototyping is fully unblocked** ([content-rights.md](content-rights.md) §3): the Gospel-harmony arrangement traces to the public-domain Broadus (1893) / Robertson (1922) harmonies, so a working system displaying public-domain Bible text (e.g. VIE1925) in that chronological arrangement — with original study content built on it — requires no rights agreement at all. The `gospel_event` taxonomy seeds directly from Robertson's outline (free on Project Gutenberg). Technical contributors can build the full study reader, huddle layer, and harmony browser against this foundation while content conversations proceed.

## Phase 0 — Discernment & Rights Groundwork (now → ~4 weeks)

| Workstream | Deliverables |
|---|---|
| Governance | PRD reviewed with leadership and Vietnamese partners; repo ownership + public/private decision; project naming |
| Rights | Written SonLife confirmations recorded; Harmony Bible Vietnamese-path options discussed; Vietnamese Bible translation shortlist with license status ([content-rights.md](content-rights.md) table filled in) |
| Field | Pilot context and huddle leader identified; pilot workflow interview notes (generic per PRD §21) |
| Docs | This roadmap + architecture reviewed; GitHub issues created from PRD §14/§20.6 lists |

**Exit criteria:** go/no-go on Phase 1 pilot; rights conversations in motion; pilot leader committed.

## Phase 1 — Content Pilot Without a Platform (~4–8 weeks, overlaps Phase 2 spikes)

| Workstream | Deliverables |
|---|---|
| Content | 4–7 original Vietnamese life-of-Jesus lessons in the portable schema (Markdown/JSON in `/content/pilot-lessons/`), theology + language reviewed |
| Delivery | Distributed as PDF/Doc/simple static page — no accounts, no platform |
| Learning | Field observation notes: huddle rhythm, discussion patterns, leader pain points, vocabulary; decision memo: *is custom software justified?* |

**Exit criteria:** documented pilot feedback; explicit decision to proceed to platform MVP (or to stay with lightweight delivery — a legitimate outcome per PRD §16).

## Phase 2a — Architecture Spikes (~4–6 weeks, can start during Phase 1)

Each spike is a timeboxed GitHub issue with a written conclusion committed to `/docs/spikes/`:

| ID | Spike | Timebox | Pass condition |
|---|---|---|---|
| S1 | Multisite install: WP subdirectory network + D.T theme on HUB + `disciple-tools-multisite`; caching/PWA config sketch | 1 wk | Both subsites run cleanly; update flow via network admin works |
| S2 | Vietnamese localization audit: theme %, mobile app %, target plugins %, on-screen review by a Vietnamese reader | 1 wk | Gap list produced; upstream Weblate contribution path confirmed |
| S3 | Huddle ↔ D.T group mapping: create huddle, leader/member connections, custom tile with progress placeholder | 1–2 wk | No core forks needed; leader sees huddle view with own-groups-only permissions |
| S4 | Magic Link participant flow: lesson view + response via tokenized link on a phone, shared through Zalo | 1 wk | Usable on an older Android device; privacy limits documented |
| S5 | Privacy-scoped discussion: custom-table thread keyed (huddle, lesson) with capability checks; private notes; admin-visibility statement drafted | 2 wk | Access-control tests pass; privacy wording approved |
| S6 | Content schema round-trip: JSON/Markdown → WP CPTs → export, lossless | 1 wk | Pilot lessons import and render in a rough reader |

**Exit criteria:** all spikes concluded; architecture.md updated with findings; formal decision that WP/D.T meets requirements (or a documented exception case per PRD §13).

## Phase 2b — MVP Build (~8–12 weeks after spikes)

Scope = PRD §7.1. Workstreams:

1. **Platform setup** — production + staging networks, SEA hosting, backups, hardening, Vietnamese locales.
2. **`jlife-studies`** — CPTs, taxonomies (incl. `gospel_phase`/`gospel_event`), study reader UX, translation workflow states, import/export.
3. **`jlife-huddles`** — invites (link/code), membership, per-lesson huddle discussion, private notes, progress, leader lightweight view. *Largest and privacy-critical — build behind tests.*
4. **`jlife-bridge`** — user↔contact, huddle↔group mapping; onboarding flow; D.T progress tile.
5. **Content** — pilot lessons loaded through the real workflow; UI-string translation review.
6. **Trust & safety** — privacy policy (Vietnamese), account deletion path, report/escalation path, PRD §21 compliance check.

**Milestones:** M1 walking skeleton (one lesson readable in a huddle end-to-end on staging) → M2 feature-complete on staging → M3 pilot launch with one trusted huddle → M4 pilot retrospective.

**Exit criteria:** PRD §15 qualitative measures collected from the pilot huddle; no unresolved privacy/trust incidents; leaders ask to continue.

## Phase 3 — Cohort & Coaching Layer (~8+ weeks, demand-driven)

- Leader cohorts (evaluate D.T Training plugin vs. custom), coach-to-leader spaces, huddle-health snapshots on the D.T dashboard, resource library and leader guides on STUDY.
- Revisit forum question with a security review: huddle threads may remain sufficient; if not, select a forum plugin for STUDY at that time.
- Expand content sets as translation/theology review capacity allows.

**Exit criteria:** ≥2 cohorts operating; coaches report visibility without surveillance concerns.

## Phase 4 — Harmony Bible Source Layer (rights-dependent)

- Whichever partnership path landed in Phase 0–2: Vietnamese on harmony-bible.com with deep links from lessons (path 1), licensed content rendered in STUDY (path 2), or continued references-only (path 3).
- Gospel event browser by SonLife phase; map/location support only with licensed assets.
- Keep source-text layer distinct from guided-study layer per PRD §8.

## Phase 5 — Mature Platform (only if field use warrants)

- Full Knowing Him Vietnamese (50 days) if licensed; media (the 42 videos are a separate rights + subtitle production project); richer offline/PWA or app-store wrapper; multi-team administration; possible additional subsites on the same network.
- ChMeetings sync (`jlife-chm-sync`) if and when an operating ministry context requires it — per [integration-boundaries.md](integration-boundaries.md).

## Dependency Summary

```text
Rights: SonLife (internal) ──► gates Phase 5 KH content (not the engine); fast
        Founders Passion ────► gates Phase 4 technical path (references-only never blocked)
        VN Bible text ───────► gates rendering Scripture text (references never blocked);
                               only genuinely external item

Field:  Phase 1 pilot ───────► gates Phase 2b build decision
Tech:   Spikes S1–S6 ────────► gate Phase 2b architecture
        jlife-bridge ────────► depends on S3, S4
        jlife-huddles ───────► depends on S5
        Content workflow ────► depends on S6
```

## Versioning

The ladder of record for platform releases (CHANGELOG entries roll under these
version headings; [vision-architecture.md §6](vision-architecture.md) carries
the same tags on its phases). **Principle: pre-1.0 minors track pilot
milestones; post-1.0 majors are capability epochs** — each adds a
plugin/surface or a schema capability, usually with a privacy-matrix or
validator decision. Licensed *content* (Knowing Him #1, VIE2010 #3) and
ministry work (#21 SonLife phase mapping) are **not** epochs: they land as
minor releases whenever their rights/review gates clear, in whatever epoch is
current (register-first discipline unchanged).

### 0.x — getting the pilot off the ground (vision Phase A ≙ Phases 1–2b above)

| Version | Milestone | Exit test |
|---|---|---|
| **0.1.0** | Pilot content release (#7 done) | 4 approved Vietnamese lessons + field-usable PDF/print rendering; deliverable with no platform |
| **0.2.0** | Walking skeleton (M1) + substrate hardening | One lesson readable in a huddle end-to-end on staging; the five hardening items (vision §6 Phase A) land here, before real participant data — including schema-version tolerance, which is what lets 2.0's schema v1.1 be additive |
| **0.3.0** | Feature-complete staging (M2) | Reader, huddles, magic links, privacy gate, Vietnamese reader pass, privacy wording approved, ops workflow (#17) |
| **0.9.0** | Pilot launch (M3, release-candidate posture) | One trusted huddle live on production |
| **1.0.0** | **Phase A exit** (M4) | Pilot huddle completes the 4-lesson Gospel of John series on production; retrospective captured. The John series fits schema v1.0 as-is, so 1.0 does not wait for catalog generalization |

1.x minors: pilot-feedback fixes, more huddles on the same feature set.

### Post-1.0 majors — capability epochs, catalog-first

| Version | Epoch | Vision phase | Exit test |
|---|---|---|---|
| **2.0.0** | **Catalog generalization** — general Bible curricula before further Life-of-Christ-harmony-specific work | E1 (moved up) | Content schema v1.1 (`canonical_passage` spine; `primary_gospel_event_id` required only for `life-of-jesus`-tagged series; sibling taxonomies); a general-Bible series authors, validates, imports, and renders with zero platform special-casing |
| **3.0.0** | **Challenge engine** (`jlife-challenges` + relay-first `jlife-dispatch`; S8 #34 runs during this cycle) | B | An rdpt-style challenge runs end-to-end on the church's real whole-Bible reading curriculum, team reflection loop included; nobody hand-copies messages across apps; shadow test vs. the existing system |
| **4.0.0** | **ChMeetings roster** (S7 #33 first) | C | Zero manual roster entry to invite an existing ministry group; boundary-doc audit passes |
| **5.0.0** | **Church-wide launch surface** (account claim, discussion/notes UI, RP Pathway front door, Companions, data dignity) | D | Map → signup → text → reader → account → huddle with no staff intervention |
| **6.0.0** | **Multiplication** (optional horizon) | F | Second-community playbook proven in days |

The Life-of-Christ harmony remains the spine for Jesus-centered curricula —
nothing is removed — but it is no longer the gate for the catalog: general
curricula come first; harmony-specific enrichment (E2: licensed series,
harmony browser, #21 phases) follows demand and rights as minor releases.

### Independent version tracks (never conflated with the platform version)

- **Content `schema_version`**: `1.0` today → `1.1` in the 2.0.0 epoch; additive because of the 0.2.0 tolerance item.
- **Portable content**: versioned by git + per-series rights rows.
- **Upstream WP/D.T versions**: external; tracked via the #17 ops workflow.

### Release mechanics

- Tag `main` when a milestone's exit criterion is met; roll the CHANGELOG `Unreleased` section under the dated version heading.
- Every tag requires green CI and the PRD §21 public-repo safety scan.
- 0.x may break anything; post-1.0: majors = epochs, minors = additive features/content within an epoch, patches = fixes.

## Standing Rules (all phases)

- No copyrighted Knowing Him, Harmony Bible, Sonlife, or Bible-text content in the repo or platform without documented permission.
- No partner-identifying information in the public repo (PRD §21).
- Plugins over core forks; portable content over database lock-in; staging before production; Vietnamese review before publication.
