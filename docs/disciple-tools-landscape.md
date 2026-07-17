# Disciple.Tools Landscape: Capability Map for J-Life

Status: Living reference — refresh per the protocol in §8 before each phase's spikes
Created: 2026-07-17
Related: [technical-analysis.md](technical-analysis.md) (the verified baseline this extends),
[vision-architecture.md](vision-architecture.md), [architecture.md](architecture.md),
[roadmap.md](roadmap.md), spike conclusions in [spikes/](spikes/)

This document maps the **full** Disciple.Tools ecosystem — which is substantially
larger than the slice `technical-analysis.md` §1.3 evaluated for the pilot — and
rates each capability's relevance to J-Life's phases. Its job is to keep us from
**building what D.T already ships** and from missing upstream capabilities as both
projects evolve.

**Verification level:** everything here is *documented upstream* (docs sites,
plugin directory, repo READMEs, fetched 2026-07-17) unless marked
**spike-verified**. Documented ≠ exercised: before any adopt/build decision, the
relevant plugin gets the same live-testing treatment the S1–S6 spikes gave the
core theme.

## 1. Where D.T Knowledge Lives (the sources)

| Source | What it covers | Refresh entry point |
|---|---|---|
| [developers.disciple.tools](https://developers.disciple.tools/) | Theme core: REST API (`dt-posts/v2`), hooks, custom post types/fields, permissions, auth (JWT, site-to-site), database tables, hosting, local dev | **[llms.txt](https://developers.disciple.tools/llms.txt)** — complete machine-readable index; every page also serves raw Markdown via `.md` suffix |
| [DiscipleTools/disciple-tools-documentation](https://github.com/DiscipleTools/disciple-tools-documentation) | User-facing docs (records, list/details views, user management, metrics, WP-admin customization), published at discipletools.github.io/disciple-tools-documentation/ | Repo README + `todo.md`; explicitly AI-collaboration-friendly (`documentation-rules.md`) |
| [disciple.tools/plugins](https://disciple.tools/plugins/) | The plugin directory (~41 plugins) with Featured/Community/Beta/PoC labels | The page itself; then each plugin's GitHub repo for releases/activity |
| [github.com/DiscipleTools](https://github.com/DiscipleTools) | All source (theme, plugins, starter template, mobile app) | Release feeds per repo |
| Weblate (per-plugin) | Plugin translation workflow (e.g. Prayer Campaigns documents "Plugin Translation on Weblate") | Relevant to the S2 Vietnamese gap list; S2 found the public translate.disciple.tools API unreliable — authenticated Weblate or upstream PO PRs remain the path |

## 2. Theme Core: What the Framework Gives Us Free

Beyond what `technical-analysis.md` established (theme-not-plugin, contacts/groups,
multiplier visibility model — all **spike-verified** in S1/S3), the developer docs
document these framework capabilities we have not yet used:

- **Custom post type framework.** A registered D.T post type gets, for free:
  menu tab, create flow, list + details pages, tiles/fields UI, the permissions
  system (`access_[post_type]`, `create_[post_type]`, `list_all_[post_type]`…),
  and a full `dt-posts/v2` REST endpoint set. Reference implementation: the
  [starter plugin template](https://github.com/DiscipleTools/disciple-tools-plugin-starter-template)
  (already our convention per architecture.md §6). *Implication:* HUB-side
  first-class objects (e.g. a future challenge-campaign record for admins) can be
  D.T post types rather than bespoke tables — but STUDY-side participant data
  stays behind our S5 gate regardless; the framework's permissions serve HUB
  actors, not rung-0/1 participants.
- **16 field types** (`connection`, `key_select`, `multi_select`, `tags`,
  `communication_channel`, `date`, `location`, `user_select`, `tasks`, …),
  registered via the `dt_custom_fields_settings` filter. The per-contact
  **channel preference** field that vision §5.2 needs (`sms | zalo | messenger |
  email | leader-relay`) is a one-filter `key_select` — no schema work.
- **API hooks** for event-driven integration: `dt_post_created`,
  `dt_post_updated`, `dt_post_deleted`, `dt_comment_created`, plus pre-filters
  (`dt_post_update_fields`, `dt_search_viewable_posts_query`, …). *Implication:*
  `jlife-bridge` can react to HUB roster/group changes (membership mirror
  invalidation, ChMeetings sync triggers) without polling.
- **Custom tables** (alongside `wp_posts`/`wp_p2p`): `wp_dt_share` (the sharing
  grants S3 verified), `wp_dt_post_user_meta` (**record data visible to one user
  only** — D.T has a native private-fields facility; our private notes still live
  STUDY-side per integration-boundaries §3, but this exists if HUB actors ever
  need private annotations), `wp_dt_activity_log`, `wp_dt_reports`/`reportmeta`
  (lightweight event records — e.g. meetings), `wp_dt_notifications`,
  `wp_dt_movement_log`, location grid tables. *Note:* `jlife-bridge` reading
  `wp_p2p` directly (group-membership.php) matches the documented core schema.
- **Site-to-Site Link** (documented under theme-core auth): secure server-to-server
  auth for **non-D.T external systems** — admin-created token → site key hashed
  with both domains → transfer token rotated hourly; per-connection-type
  permissions via filters; PHP/Node examples. *Implication:* this is the
  D.T-native pattern for **S7 ChMeetings sync** and any RP Pathway server-side
  calls — evaluate before inventing our own auth for `jlife-chm-sync`.
- **JWT authentication** exists for the mobile app; a reminder that D.T supports
  token-authenticated REST beyond cookies and application passwords.

## 3. Plugin Ecosystem: Relevance-Rated Inventory

Directory labels in parentheses. Ratings: **High** = evaluate before building the
overlapping J-Life capability; **Med** = likely useful, schedule with its phase;
**Low** = aware, no current fit; **Watch** = immature/beta but trajectory matters.

### 3.1 High relevance — overlaps things we planned to build

| Plugin | What it is (documented) | Overlaps / feeds |
|---|---|---|
| **Magic Link** (v1.33.0, 2026-04; 79 releases, 393 commits) | Not just tokenized links: **link objects assigned to users/teams, with scheduling and bulk dispatch via Twilio (SMS) and email**, activity summaries, send logs, "updates since last dispatch" reports; `magic-link/` framework + REST dirs for custom link apps | **`jlife-dispatch` §5.2** (compose/schedule/log = already there for API transports) and our hand-rolled S4 flow. See §4.1 |
| **Channels: Twilio** (v1.4.0, 2025-06) | Decoupled messaging channel: `Disciple_Tools_Twilio_API::send_sms()` / `::send_whatsapp()` (WhatsApp within 24h contact window); used by the Magic Links scheduler; optional notification routing | The **API-transport half of `jlife-dispatch`**; S8's provider spike should start here |
| **Prayer Campaigns** (v4.19.0, 2026-05; **153 releases, 1,278 commits** — one of the most active D.T plugins) | Time-boxed **campaigns** with embeddable public signup forms, subscriber self-management via **email magic links**, Weblate-translated, real deployments (pray4movement.org) | The closest shipped analog to **`jlife-challenges` §5.1** — campaign object, enrollment, public signup, notification loop. See §4.2 |
| **Webform** (v6.6.4, 2026-02; 65 releases) | Admin-built lead forms → D.T contacts; iframe embed on external sites; **remote-host mode** (form on a separate WP server, data privately transferred — for sensitive locations); source tagging, dispatcher assignment | Signup path for **RP Pathway CTAs** (vision §5.6 join 1) where ChMeetings forms aren't in play; rung 0→1 onboarding without ChMeetings |
| **Mobile App plugin** (Featured) | Backs the official leader-facing app (JWT auth) | Already planned for leaders (Phase D); **spike-verified** VN gap in S2 |
| **Groups Tile / Team Module** | Group search/filtering; collaborative team workflows | Already in architecture.md §2.2; Team = leader-cohort structure S3 reserved |
| **Multisite** (v1.17.0) | Network admin tooling | **Spike-verified** (S1); in production plan |

### 3.2 Medium relevance — schedule with their phase

| Plugin | What it is | J-Life fit |
|---|---|---|
| **Training** (Featured) | Events with participants, tasks, meeting times, sharing | Cohort mechanics (Phase 3 per analysis §1.3); compare with Meetings Tracker before building any cohort-session tracking |
| **Meetings Tracker** + **3/3rds Meetings** (PoC) | Meeting tracking; 3/3rds facilitation layer | Huddle-meeting rhythm, if leaders want per-meeting records in HUB |
| **Streams** (Beta) | Interconnects leaders/disciples/groups/trainings with reporting | Multiplication-pipeline view; overlaps vision §5.6 join 3 (Companion pipeline) |
| **GenMapper** | Visualizes generational parent-child relationships (contacts, baptisms, groups) | Pastor-facing multiplication visualization on HUB — vision §1's "leaders and pastors see … multiplication" with zero build |
| **AutoLink** | Simple mobile group/church registration | Already noted as the leader-UX pattern to imitate (analysis §1.3) |
| **Home Screen** (Featured) | Mobile-friendly app home screen | Candidate leader/member launcher; repo URL 404'd on fetch — verify current home during next refresh |
| **Porch** | Public landing pages fronting a D.T site | Alternative front door if RP Pathway weren't the plan; low priority given §5.6 |
| **Dashboard** (Featured) | Disciple-maker start page with priority actions | Leader daily-driver on HUB; complements our Progress tile |
| **Metrics Export / Data Reporting** | CSV/JSON/KML/GeoJSON export; cloud-provider export | Aggregate reporting paths; any use must pass integration-boundaries review (no participant content in exports) |
| **Auto Assignment** | Rule-based incoming-contact assignment | Useful if Webform/RP Pathway signups need routing to leaders |
| **Setup Wizard** (Community) | Multi-step site configuration | Phase F playbook ("second community in days") ingredient |
| **Migration** / **Personal Migration** (Beta) | Site-to-site config+record transfer; per-user data migration | Phase F multi-tenant moves; Personal Migration is prior art for **export-my-data** (vision §5.7) |
| **PII Obliterator** | Strips personally identifiable information | Deletion/retention obligations (integration-boundaries §6) — evaluate for the D.T-side half of account deletion |

### 3.3 Low / Watch

| Plugin | Note |
|---|---|
| **Facebook** (Featured), **Chatwoot**, **Echo**, **MailChimp** | Channel/CRM integrations we don't need; Facebook sync is contact-ingest, not the Messenger *relay* transport §5.2 designs |
| **Prayer List / Prayer Requests** (Community) | HUB-user prayer tools; our prayer requests are STUDY-side and huddle-scoped by design |
| **Survey Collection** | Regular activity statistics from leaders — possible later coaching-health input |
| **Availability** | Meeting-time coordination — nice-to-have for huddle scheduling someday |
| **Quick Comments** (Beta), **Static Section**, **Multisite Dropdown**, **Network Dashboard** (Featured) | Utilities; Network Dashboard matters only at Phase F scale |
| **Demo Content** (Featured) | Dev fixture tooling — could speed spike seeding (S3 seeded by hand) |
| **Disciple.Tools AI** (Beta/Laboratory) | Watch only. Any AI touching participant data would need its own integration-boundaries review before consideration |

## 4. What This Changes for J-Life (decision deltas)

These are proposals for review, not decisions — each names the doc/issue it touches.

### 4.1 `jlife-dispatch` becomes an adopt-vs-build question (vision §5.2, S8/#34)

The vision designed dispatch from scratch. Upstream already ships: **scheduled,
logged, bulk magic-link dispatch over Twilio SMS + email** (Magic Link plugin) on
top of a **decoupled channel API** (Twilio plugin, incl. WhatsApp). What upstream
does **not** have, and remains genuinely ours:

- the **relay transport** (dispatch sheet, copy/prefill/share-sheet for human
  senders) — §5.2's most original piece and Phase B's ship-first mode;
- **Zalo** as a channel (upstream has none; the Twilio plugin's channel pattern
  shows where an adapter would plug in);
- our **link-scope model** — S4 tokens are `(contact, group, lesson)`-scoped with
  the S5 gate behind them; upstream link objects are D.T-record-update apps.

**Amended S8 scope:** first live-test the Magic Link scheduler + Twilio channels
(can they carry *our* STUDY URLs? per-recipient templates? what do logs capture?),
then decide per layer: adopt (channels), extend (scheduler), or build (relay
sheet, Zalo). Vietnamese gap (S2) applies to the Magic Link plugin either way.

### 4.2 Study Prayer Campaigns before designing `jlife-challenges` (vision §5.1, Phase B)

Prayer Campaigns is a mature (1,278-commit), Weblate-translated, deployed
campaign engine: time-boxed campaign + public embeddable signup + subscriber
self-management through emailed magic links. Its object model and enrollment UX
are the closest running code to our challenge design. It is *prayer*-shaped, not
*reading-plan*-shaped (no series/lesson spine, no streaks, no team scoring), so
it's unlikely to be adopted wholesale — but its signup/self-management/notification
loop is exactly the part of §5.1 we'd otherwise invent blind. Add a half-day
"read the Prayer Campaigns source" item to the Phase B spike.

### 4.3 Site-to-Site Link is the S7 auth candidate (Phase C, #33)

`jlife-chm-sync` needs authenticated server-to-server calls into HUB. D.T's
site-to-site link (hashed site key, hourly-rotating transfer token,
per-connection-type permissions) is the native pattern, with non-WordPress
examples. S7 should evaluate it before designing custom auth — and it's equally
relevant to any future RP Pathway server-side integration.

### 4.4 Event-driven bridge (architecture.md §5)

`dt_post_created`/`dt_post_updated`/`dt_comment_created` allow the bridge to
invalidate its membership mirror and trigger syncs on change instead of on read.
Not urgent at pilot scale; becomes relevant when Phase C sync and Phase D
dashboards care about freshness.

### 4.5 Confirmations (no change, more confidence)

- Plugins-first extension model: the starter-template ecosystem is bigger and
  healthier than the analysis assumed — fork pressure stays low.
- The permissions model we verified in S3 (`assigned_to` + `wp_dt_share`,
  capability-per-post-type) is the documented, framework-wide pattern.
- Weblate is the documented plugin-translation route, refining S2's "upstream PO
  PRs or authenticated Weblate" conclusion.

## 5. What D.T Still Does Not Solve (unchanged gaps)

The participant world remains ours. Nothing in the ecosystem provides:
participant-facing **study content/reader** (series → lesson → sections),
**huddle discussion threads** with pastoral-grade privacy, **private reflection
notes**, **per-lesson progress with leader flags**, the **identity ladder** and
account-claim flow, **Zalo delivery**, or **Vietnamese-first participant UX**.
Every one of these sits behind the S5 gate on STUDY — the D.T framework's
permission system governs HUB users and cannot substitute for it
(architecture.md §3: participants have *no* HUB role by design).

## 6. Verified-Facts Ledger (this document's fetch, 2026-07-17)

| Claim | Basis |
|---|---|
| Magic Link plugin ships scheduling + Twilio/email bulk dispatch, logs, reports; v1.33.0 (2026-04-09), 79 releases | Repo README/releases |
| Twilio plugin exposes `send_sms()`/`send_whatsapp()` as a channel API used by the Magic Links scheduler; v1.4.0 (2025-06-24) | Repo README |
| Prayer Campaigns: campaign + embeddable signup + email-magic-link self-management; v4.19.0 (2026-05-04), 153 releases, 1,278 commits | Repo README/releases |
| Webform: external-site forms → contacts, iframe embed, remote-host mode; v6.6.4 (2026-02-11) | Repo README |
| Custom post types get list/details/tiles/fields/permissions/REST free; 16 field types via `dt_custom_fields_settings` | developers.disciple.tools (customization) |
| API hooks: `dt_post_created/updated/deleted`, `dt_comment_created`, pre-filters | developers.disciple.tools (api-hooks) |
| Custom tables incl. `wp_dt_share`, `wp_dt_post_user_meta` (single-user-visible data), activity/reports/notifications/movement logs | developers.disciple.tools (tables) |
| Site-to-site link: token → domain-hashed site key → hourly transfer token; for non-D.T systems; per-connection permissions | developers.disciple.tools (authentication) |
| Plugin directory ≈ 41 plugins with Featured/Community/Beta/PoC labels | disciple.tools/plugins |
| User docs published from disciple-tools-documentation repo (GitHub Pages), AI-collaboration rules included | Repo README |

**Not verified (known gaps in this pass):** Home Screen repo location (404 on the
guessed URL); Prayer Campaigns' internal cadence/slot model (README-level only);
whether Magic Link scheduling can target non-D.T URLs; the D.T AI plugin's actual
capabilities.

## 7. Relationship to Existing Docs

- `technical-analysis.md` stays the **decision record** for the pilot stack; this
  document is the **wider map**. Where they overlap (Magic Links, Multisite,
  Training, AutoLink, Porch), the analysis has the verdicts, this has the current
  versions and the ecosystem context.
- Vision §5.1/§5.2 amendments proposed here (§4.1–§4.2) should be argued in
  review and, if accepted, folded into vision-architecture.md and issues #33/#34
  — this document doesn't amend them itself.

## 8. Update Protocol (how future sessions refresh this)

1. **When:** before opening each phase's spike work (next: S8/#34 dispatch,
   S7/#33 ChMeetings), or when an upstream release note announces something in a
   High-relevance row; at minimum, glance per quarter.
2. **How:**
   - Fetch [developers.disciple.tools/llms.txt](https://developers.disciple.tools/llms.txt)
     and diff its section list against §1/§2 (new subsystems → new rows).
   - Fetch [disciple.tools/plugins](https://disciple.tools/plugins/) and diff
     against §3 (new/renamed/retired plugins).
   - For every **High** row: check the repo's latest release + notes; update
     versions in §3/§6.
   - Append newly verified facts to §6 with the fetch date; move anything
     live-tested into the spike docs and mark it **spike-verified** here.
3. **Discipline:** documented-vs-verified stays explicit; decision deltas go to
   §4 as *proposals* and graduate into vision/architecture docs through review,
   never silently. Date every refresh in the ledger.
