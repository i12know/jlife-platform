# Integration Boundaries and Data Ownership

Status: Draft for review
Created: 2026-07-03
Related: [PRD.md](PRD.md) §13/§20, [architecture.md](architecture.md)

This document pre-commits the data-ownership and sync boundaries between the platform's systems so that later integration work (especially ChMeetings) cannot quietly erode the privacy model. Change this document deliberately, with review — not incidentally, in code.

## 1. Systems and Their Roles

| System | Role | Source of truth for |
|---|---|---|
| **ChMeetings** (future, optional) | Local church CRM | Approved people/contact records, households, churches/ministries, events, operational fields — for contexts that operate in ChMeetings |
| **HUB subsite** (Disciple.Tools) | Disciplemaking workflow | Huddles (groups), cohorts, coaching relationships, huddle health, generational/multiplication links, leader assignments |
| **STUDY subsite** (WordPress content) | Participant experience | Lessons and study content, translation workflow state, huddle discussion threads, private notes, progress, prayer requests |
| **Harmony Bible** (external) | Gospel chronology reference | Phase/event outline and source-text layer — referenced by ID/deep link only until licensed otherwise |
| **Knowing Him** (external) | Study content source (pending license) | Its own content; no runtime integration |

## 2. Identity Keys

- `wp_users.ID` — one network-wide identity per leader/participant-with-account.
- `dt_contact_id` — D.T contact for every participant and leader; stored in user meta on the network and as `jlife_user_id` on the contact.
- `dt_group_id` — D.T group for every huddle; stored on all STUDY-side huddle structures.
- `chm_person_id` / `chm_group_id` — ChMeetings external IDs, stored on D.T records **only** when a ChMeetings context is activated; isolated behind a `CHM_FIELDS`-style mapping module per the portfolio pattern.
- `gospel_event_id` / `gospel_phase` — content-side reference keys for future Harmony Bible joins.

## 3. Data Ownership Map

| Data | Lives in | Syncs to | Never goes to |
|---|---|---|---|
| Participant name, contact info (minimal) | HUB contact (+ WP user) | ← from ChMeetings if activated (one-way) | — |
| Huddle membership, leader assignment | HUB group | mirrored read-only to STUDY | ChMeetings (by default) |
| Huddle health, generations, coaching notes | HUB | — | STUDY participants, ChMeetings |
| Lesson content + translation status | STUDY (+ portable files in repo) | — | — |
| Lesson progress (completion flags) | STUDY | aggregate summary → HUB tile | ChMeetings |
| Huddle discussion threads | STUDY | — | HUB records, ChMeetings, any export |
| **Private reflection notes** | STUDY | **nowhere** in normal application workflows (author-only at the app layer) | leaders, coaches, HUB, ChMeetings |
| Prayer requests | STUDY (huddle-scoped) | — | ChMeetings; HUB only if leaders adopt an explicit workflow |
| Pastoral-care details | **not stored in the platform** (PRD §11) | — | everywhere |
| Donor/payment data | **not stored** | — | everywhere |

## 4. Sync Rules

1. **ChMeetings → D.T is the only default direction**, narrow and explicit: approved contacts/groups and external IDs, per an approved field map. Idempotent writes, mock-mode tests by default, structured logs — the established portfolio pattern.
2. **Any D.T → ChMeetings back-sync requires**: its own data map, consent/security review, and field-level approval. None is planned.
3. **STUDY → HUB** carries only: contact/group linkage, membership mirror, and progress aggregates. Discussion text, note text, and prayer text never cross.
4. **HUB → STUDY** carries only: huddle roster and leader assignment (so STUDY can enforce thread access).
5. All cross-system reads/writes go through the `jlife-bridge` (or future `jlife-chm-sync`) plugin interfaces with capability checks — no theme-level or ad-hoc queries across boundaries.
6. Webhooks, if ever added, must be idempotent, fast-acknowledging, secret-verified, and PII-minimal.

## 5. Authentication Boundaries

- Participants authenticate on STUDY only (account or magic link). No HUB access, ever.
- Leaders hold one account with per-subsite roles; HUB role limited to own groups (D.T multiplier pattern).
- ChMeetings credentials/authentication are never reused for platform login; no shared passwords, no SSO with ChMeetings unless a future review approves one.
- Magic-link tokens are revocable, expiring, and scoped to a single person + surface.

## 6. Deletion and Data Requests

- Account deletion removes: WP user, STUDY notes/progress/thread authorship (content anonymized or deleted per policy), and flags the D.T contact for archive per ministry retention policy.
- A participant's private notes are deleted outright — they exist nowhere else by design (Section 3).
- Backups age out on a defined schedule so deletion is eventually complete there too; document the window in the privacy policy.

## 7. Admin Visibility Honesty

"Private" means not visible to other participants, huddle leaders, coaches, HUB users, ChMeetings, or normal exports. WordPress super admins, database operators, backup operators, and incident responders may still technically access stored data unless a later phase adds application-layer encryption. The product copy and privacy policy must say this plainly.