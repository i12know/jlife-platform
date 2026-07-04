# Proposed Architecture: J-Life Platform Prototype

Status: Draft for review — to be validated by spikes S1–S6 (see [roadmap.md](roadmap.md))
Created: 2026-07-03
Related: [PRD.md](PRD.md), [technical-analysis.md](technical-analysis.md), [integration-boundaries.md](integration-boundaries.md)

## 1. Network Topology

One WordPress multisite network, subdirectory mode (validate in S1), two subsites:

```text
                        ┌─────────────────────────────────────────────┐
                        │        WordPress Multisite Network          │
                        │  shared: wp_users, codebase, media policy   │
                        │  network plugin: disciple-tools-multisite   │
                        └─────────────────────────────────────────────┘
                              │                          │
              ┌───────────────┴──────────┐   ┌───────────┴──────────────┐
              │  STUDY subsite (public-  │   │  HUB subsite (private)   │
              │  facing, /)              │   │  (/hub/)                 │
              │  theme: content theme    │   │  theme: Disciple.Tools   │
              │                          │   │                          │
              │  • Vietnamese lessons    │   │  • Contacts (people)     │
              │  • study reader + PWA    │   │  • Groups (= huddles)    │
              │  • huddle discussion     │   │  • cohorts, coaching     │
              │  • private notes         │   │  • health metrics,       │
              │  • progress tracking     │   │    generational tracking │
              │  • invites / magic links │   │  • leader dashboards     │
              └──────────┬───────────────┘   └───────────┬──────────────┘
                         │      internal REST + shared    │
                         └──────────── IDs (bridge) ──────┘

  Participants ──▶ STUDY only (login or magic link; never see HUB)
  Huddle leaders ─▶ STUDY (lead discussion) + HUB (limited role)
  Coaches/admins ─▶ HUB primarily
  ChMeetings ─────▶ (future, one-way) ──▶ HUB contacts/groups only
  Harmony Bible ──▶ referenced by ID/deep link from STUDY (no copied content)
```

## 2. Subsite Responsibilities

### 2.1 STUDY subsite (participant surface)

- Normal WordPress content theme (block theme or lightweight classic theme), Vietnamese-first UI, mobile-first, PWA-enabled (installable, offline cache of recent lessons).
- Public marketing/landing pages are optional and minimal; lesson content itself defaults to **access-controlled** (invite/huddle membership), per PRD privacy posture.
- Hosts all participant interaction: reading, reflection notes, huddle discussion, progress, prayer requests.

### 2.2 HUB subsite (ministry workflow surface)

- Disciple.Tools theme + standard plugins: Magic Links, Groups Tile, Team Module, Mobile App plugin (leaders), AutoLink (evaluate), Training (Phase 3).
- Never linked publicly; hardened login (2FA for coach/admin roles); Vietnamese locale active.
- Holds the relational graph: who leads whom, huddle health, cohort structure, multiplication generations.

## 3. Identity and Roles

| Person | STUDY subsite | HUB subsite | D.T record |
|---|---|---|---|
| Participant | `jlife_participant` role (or magic link, no account) | **no role** | Contact |
| Huddle leader | `jlife_leader` role | Multiplier (sees only own contacts/groups) | Contact + user |
| Coach/mentor | optional | Multiplier/custom coach role | Contact + user |
| Content editor/translator | Editor-tier role with review workflow | no role | — |
| Admin/steward | Site admin | D.T admin | — |
| Super admin (1–2 people) | — | — | Network admin |

Principles:

- One `wp_users` account per person across the network; **roles are granted per subsite** and participants never receive a HUB role.
- **HUB isolation is capability-based, not session-based** (verified in [spike S1](spikes/S1-multisite.md)): the `wordpress_logged_in_*` cookie is network-wide (`path=/`), so any logged-in user is *authenticated* on both subsites. What keeps participants out of HUB is having no role/capabilities there plus Disciple.Tools' authenticated-REST gate — which is why capability checks on every huddle read/write path (spike S5, #12) are load-bearing, not defense-in-depth.
- Every participant user (or magic-link recipient) maps to a D.T **contact** via a stored external ID (`jlife_user_id` on the contact; `dt_contact_id` in user meta). The bridge plugin owns this mapping and its creation flow.
- Onboarding flow: leader creates huddle in HUB (or via a simplified AutoLink-style form) → system generates invite link/code → participant opens link on STUDY → chooses account or continues via magic link → bridge creates/links D.T contact and adds them to the group.

## 4. Content Model (STUDY)

Custom post types and taxonomies (in the `jlife-studies` plugin, so content survives theme changes):

```text
CPT: jlife_series          (a study, e.g. a 5–7 lesson pilot)
CPT: jlife_lesson          (ordered within a series)
  └ sections stored as structured blocks/meta:
      scripture_reference   (references only — text fetched/linked per rights)
      teaching              (commentary body)
      outside_the_box       (cultural/contextual insight)
      reflection_questions[]
      live_it_out           (application)
      prayer_prompt
      huddle_discussion_prompts[]
      leader_notes          (visible to leader role only)

Taxonomies / reference fields:
  gospel_phase      — the 5 SonLife/Harmony phases (Preparation, Ministry
                      Foundations, Ministry Training, Expanded Evangelism,
                      Leadership Multiplication)
  gospel_event      — event outline ID seeded from the public-domain Robertson
                      (1922) harmony outline (Project Gutenberg #36264);
                      optional harmony-bible.com deep link per event
  scripture_ref     — book/chapter/verse metadata (machine-readable)

Workflow meta (per lesson):
  source_language, translation_status (draft → translated → theology-reviewed
  → language-reviewed → approved → published), rights_note, source_attribution
```

- Content must round-trip to the portable JSON/Markdown schemas in `/content/schemas/` (export/import WP-CLI commands in the plugin). The WordPress database is a *rendering* of the content, not its only home.
- Bible text: MVP stores **references only** and links out (or renders via a licensed API) — see [content-rights.md](content-rights.md).

## 5. Huddle, Discussion, and Progress Model

- **Huddle = D.T group** (HUB) — leader/member connections, health fields, cohort/parent-group links.
- **STUDY-side structures** (in the `jlife-huddles` plugin), each carrying `dt_group_id`:
  - `huddle_thread` — discussion entries keyed by (huddle, lesson), stored in a custom table with capability checks on every read/write; visible only to that huddle's members and leader.
  - `progress` — (user, lesson) completion records; participant sees own; leader sees per-member completion flags only (not note content); coach sees huddle-level aggregates via HUB.
  - `private_note` — (user, lesson) reflections; author-only at the app layer. Sharing to the huddle thread is an explicit copy action by the participant.
  - `prayer_request` — huddle-scoped, optional MVP.
- **Cross-subsite reads:** multisite shares one database, so the bridge can use `switch_to_blog()`/direct queries for same-server efficiency, but all access goes through bridge-plugin functions with capability checks — never raw queries from theme code. If the network ever splits across servers, the bridge falls back to the D.T REST API; write the interface accordingly.
- **Leader dashboard:** lightweight progress summary surfaces in both places — a simple huddle view on STUDY (where leaders already are) and a custom tile on the D.T group record in HUB (where coaches look).

## 6. Custom Plugins to Build

| Plugin | Subsite | Responsibility | Size estimate |
|---|---|---|---|
| `jlife-studies` | STUDY | CPTs, taxonomies, study reader templates/blocks, translation workflow states, JSON/Markdown import-export | Medium |
| `jlife-huddles` | STUDY | huddle membership mirror, discussion threads, private notes, progress, invites/magic-link participant flow | **Largest** — privacy-critical |
| `jlife-bridge` | network | user↔contact and huddle↔group ID mapping, onboarding flow, cross-subsite read API, leader progress tile for D.T (D.T starter-plugin based) | Small–medium |
| `jlife-chm-sync` | HUB (future) | ChMeetings → D.T one-way sync per [integration-boundaries.md](integration-boundaries.md) | Deferred |

All follow the D.T starter-plugin conventions where they touch D.T; all strings translation-ready from the first commit.

## 7. External Integrations

- **Harmony Bible:** reference IDs + deep links only, until an approved coordination path with Founders Passion / Harmony Bible settles a technical path — their platform currently has no Vietnamese and no export/API (see [content-rights.md](content-rights.md)). The `gospel_event` taxonomy is the future join key.
- **Knowing Him:** no runtime integration; it is a SonLife content source pending written confirmation to translate. Our reader engine is independent.
- **ChMeetings:** deferred; boundaries pre-committed in [integration-boundaries.md](integration-boundaries.md) so the pilot doesn't accidentally create coupling.
- **Bible text provider:** decision pending rights research (link-out vs. licensed API vs. public-domain text).

## 8. Hosting, Environments, Operations

- **Production:** LEMP on a SEA-region VPS or managed WP host supporting multisite; full-page cache + CDN for STUDY, object cache network-wide; TLS everywhere.
- **Staging:** a full clone of the network; **all** theme/plugin updates (including D.T's frequent releases, bulk-applied via the multisite plugin) go through staging first.
- **Local dev:** reproducible local multisite (e.g. ddev or wp-env) with a seed script that installs D.T, creates both subsites, and loads sample content.
- **Backups:** nightly database + uploads, tested restore procedure; backups contain private reflections → encrypt at rest and restrict access.
- **Security:** limit plugin count, 2FA for leader+ roles on HUB, login rate limiting, no public user directories, audit logging on HUB, and the PRD Section 21 information-safety rules applied to all data and screenshots.

## 9. Decisions Deferred to Spikes

| ID | Question | Doc section |
|---|---|---|
| S1 | Subdirectory multisite + D.T: any conflicts? Cache/PWA config? | §1, §8 |
| S2 | Vietnamese completeness across D.T theme, mobile app, target plugins | analysis §1.4 |
| S3 | Do huddles map to D.T groups without awkward workarounds? Custom tile viability? | §5 |
| S4 | Can Magic Links carry the lesson+response participant flow? | §3 |
| S5 | Discussion/notes privacy model — custom table design, capability checks, admin-visibility honesty | §5 |
| S6 | Content schema round-trip: JSON/Markdown ↔ WP import-export | §4 |