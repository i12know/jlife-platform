# Technical Analysis: WordPress Multisite + Disciple.Tools Platform

Status: Draft for review
Created: 2026-07-03
Related: [PRD.md](PRD.md), [architecture.md](architecture.md), [roadmap.md](roadmap.md)

This document analyzes the proposed stack — WordPress multisite running the Disciple.Tools ecosystem via the `disciple-tools-multisite` plugin, plus a Vietnamese participant-facing study experience inspired by Knowing Him and referencing Harmony Bible — and identifies the architectural tensions, verified facts, and open risks that should shape the build.

## 1. Component Analysis

### 1.1 Disciple.Tools Theme

- **What it is:** an open-source disciple-making-movement CRM implemented as a **WordPress theme** (GPL). Core objects: Contacts and Groups, with generational tracking, health metrics, role-based permissions, dashboards, maps, an activity/comment stream per record, REST API, and extensive hooks.
- **Extension model:** the maintainers explicitly recommend building new features **as plugins first** (a starter plugin template exists), not by forking the theme. This matches the PRD's guardrail.
- **Critical architectural fact:** because Disciple.Tools is a theme, it **owns the entire front end** of any site it is active on. It is a private, login-first CRM UI. It cannot simultaneously serve a public Vietnamese study/content experience. This single fact drives the multisite decision (Section 2).
- **Audience fact:** Disciple.Tools is designed for **ministry workers** (dispatchers, multipliers, coaches, admins) as its logged-in users. The people being discipled are normally **contact records**, not user accounts. A huddle participant logging in to read lessons and discuss is *not* the native Disciple.Tools usage pattern. This is the second major driver of the architecture (Section 3).
- **Maintenance:** actively developed (5,800+ commits), modern tooling (Composer, npm, Vite), regular releases.

### 1.2 Disciple.Tools Multisite Plugin

- **Purpose:** Super Admin tooling for running Disciple.Tools on a WordPress **multisite network**: a Network Admin "Disciple.Tools" menu, bulk theme/plugin updates across subsites, subsite import, central Mapbox key management, and Network Dashboard / Movement Maps authorization.
- **Status:** actively maintained — v1.17.0 released June 2026, 32 releases, 158 commits.
- **Scope note:** it is an *administration* plugin. It does not itself create cross-subsite user experiences, SSO flows, or data sync — WordPress multisite core provides shared users; anything richer is ours to build.
- **Fit:** exactly matches our need — one network, one codebase, shared user accounts, with the Disciple.Tools theme active only on the private workflow subsite.

### 1.3 Relevant Disciple.Tools Ecosystem Plugins

| Plugin | Relevance to J-Life |
|---|---|
| **Magic Links** | Highest relevance. Generates per-person tokenized links to view/update specific records **without a login**. This is the lowest-friction pattern for Vietnamese mobile participants (shareable over Zalo/Messenger) and may cover leader check-ins and lightweight participant flows before we invest in full participant accounts. |
| **Mobile App plugin** | Backs the official Disciple.Tools mobile app. Useful for **leaders/coaches**, not for participants (CRM-oriented UX). |
| **Groups Tile / Team Module** | Group search, health metrics, collaborative workflows — the raw material for huddle and cohort views. |
| **AutoLink** | Simplified mobile-friendly interface for leaders to register groups/churches — a proven pattern for "simple leader UX on top of D.T data" that our huddle-leader flow can imitate. |
| **Training** | Tracks training events with participants, tasks, meeting times — a candidate model for cohort mechanics in Phase 3. |
| **Porch** | Customizable public landing pages in front of a D.T site — an option for the invite/onboarding boundary. |
| **Multisite Dropdown / Network Dashboard** | Multi-subsite navigation and cross-site reporting if the network grows beyond two subsites. |

### 1.4 Vietnamese Localization (verified findings)

- The Disciple.Tools theme ships a **Vietnamese translation** (`vi.po` / `vi.mo` in `dt-assets/translation/`). The `vi.po` file is ~203 KB — the same order as French (~196 KB), Spanish (~194 KB), and Korean (~195 KB), which suggests substantial coverage, not a stub. **Exact completion percentage must still be verified** on `translate.disciple.tools` (Weblate), and plugin/mobile-app translations are tracked separately and are typically less complete.
- Translations are volunteer-driven via Weblate; we can contribute missing Vietnamese strings upstream — a good early, low-risk contribution that also builds relationship with the D.T community.
- WordPress core, by contrast, has a mature `vi` locale. The content subsite (normal WordPress) has no localization risk; the D.T subsite has *moderate, fixable* localization risk.
- Custom fields and tiles we add via plugins carry their own translation burden; every custom field label we create must go through our translation-review workflow.

### 1.5 Harmony Bible (harmony-bible.com)

- **What it is:** a hosted, proprietary web platform by **Founders Passion**, part of the **Concentric** alliance. It presents the life of Christ chronologically across five phases — Preparation Period, Ministry Foundations, Ministry Training, Expanded Evangelism, Leadership Multiplication — with an event outline, an interactive map of locations, twelve scholarly essays, and user accounts.
- **Languages:** English, French, German, Portuguese, Spanish, Tagalog, Ukrainian. **No Vietnamese today.**
- **No public API, export, embed mechanism, or license terms are visible.** There is a contact form.
- **Implication — this is a coordination problem, not an engineering problem.** Existing ministry relationships may make this a relationship-based conversation rather than a cold approach, but the *technical* gap is unchanged: no Vietnamese and no export/API exist today, so a path must still be agreed and built. Three realistic paths, in order of preference:
  1. **Founders Passion adds Vietnamese to their own platform** (we contribute translation labor; they host). Cheapest, most durable, keeps one canonical Harmony Bible.
  2. **Content/data export or API** they provide, which we render inside our content subsite under agreed attribution.
  3. **References-only integration** (our default until a path is agreed): we model the five phases and event outline as taxonomy *identifiers* and deep-link to harmony-bible.com. No copying, no scraping.
- **Design consequence:** our content model should carry `phase` and `gospel_event` reference fields from day one, so that any of the three paths later becomes a mapping exercise rather than a remodel. The five-phase framework itself is the shared SonLife/J-Life strategy vocabulary and structures our taxonomy regardless.
- **Public-domain foundation (important for contributors):** the Gospel-harmony chronology underlying all of this traces to Broadus (1893) and A. T. Robertson (1922, *A Harmony of the Gospels for Students of the Life of Christ*, full text on Project Gutenberg) — both fully public domain in the US and Vietnam. Chronological arrangement of Scripture per Broadus/Robertson, displayed with public-domain Bible text, plus original study content structured on that arrangement, requires **no license from anyone**. Prototyping is therefore never blocked on the Founders Passion conversation; that conversation concerns their platform's specific expression (headings, essays, maps, translations), not the harmony itself. Details in [content-rights.md](content-rights.md) §3.

### 1.6 Knowing Him (knowinghim.app)

- **What it is:** a free 50-day chronological study through the life of Jesus by Mark Edwards / **Sonlife**, delivered as a JavaScript single-page web app (it renders client-side; there is a `StudyList` route). Each day has four sections: introductory commentary, "Outside the box," "Live it Out," and "Digging deeper," supplemented by 42 videos filmed in Israel.
- **No Vietnamese support and no visible group/huddle/community features** — it is an individual study experience. This confirms the PRD gap analysis.
- **Implication — "Vietnamese clone" decomposes into two separable efforts:**
  1. **A study-reader engine** (our own build): series → lesson → sections → prompts, with progress tracking. This is generic software we own regardless of whose content flows through it.
  2. **The Knowing Him content itself**, which is SonLife's copyrighted material. Translation/adaptation approval should be recorded in writing per [content-rights.md](content-rights.md). The 42 videos remain a separate (larger) production question — Vietnamese subtitling/dubbing effort — treat as Phase 5 at the earliest.
- The four-section daily structure is a strong template for our lesson schema, extended with the PRD's huddle-oriented sections (discussion prompts, prayer, application, leader notes).

## 2. Why Multisite Is the Right Split (and its costs)

A single WordPress site cannot run the Disciple.Tools theme *and* a public Vietnamese content theme. The realistic options:

| Option | Verdict |
|---|---|
| Two separate WordPress installs | Two user databases, manual identity bridging, double ops. Rejected. |
| Headless D.T + custom front end | Violates the PRD guardrail (custom backend/front-end stack before the WP spike fails). Exception path only. |
| **WordPress multisite, two subsites** | One codebase, one shared `wp_users` table, per-site themes and roles, officially supported by `disciple-tools-multisite`. **Recommended.** |

Multisite gives us: shared user identity (a leader is one account on both subsites), single hosting/backup/update surface, and the D.T multisite admin tooling.

**Costs to accept knowingly:**

- **Ops complexity:** network-level updates can break one subsite while fixing another; a staging network is non-negotiable before production pilots.
- **Plugin compatibility:** most mainstream plugins support multisite, but each addition must be verified network-wide.
- **Shared user table = shared attack surface:** a participant account exists in the same user table as admins; role hygiene per subsite matters (participants get roles **only** on the content subsite, never on the D.T subsite).
- **Subdomain vs subdirectory:** subdirectory (`example.org/` public, `example.org/hub/` private) keeps one TLS cert and trivially shared auth cookies; subdomains give cleaner separation of the private tool's identity. Recommend **subdirectory first** and validate in the architecture spike (S1) that Disciple.Tools has no subdomain assumptions that bite us.

## 3. The Participant-Identity Problem (most important design decision)

Disciple.Tools expects ministry workers as users and disciples as contact records. Our participants need to read lessons, mark progress, and discuss privately. Three patterns, which can be **layered over time**:

1. **Magic links (recommended for earliest pilots).** Participant receives a tokenized link (shareable via Zalo) to a lesson + response flow. No password, no account creation friction, works on old Android phones. Limits: link forwarding is a real privacy consideration; fine for pilot-scale trust levels, revisit before scale.
2. **Participant = WordPress user on the content subsite only (recommended MVP target).** Full login, private notes, huddle discussion, progress — all on the content subsite. A lightweight bridge plugin maps each participant user to a D.T contact record (by external ID) so leaders' coaching workflow lives in D.T without participants ever seeing the D.T UI.
3. **Participant = Disciple.Tools user (multiplier role).** Not recommended: the D.T UI is a CRM, wrong surface for participants, and per-user D.T accounts at participant scale create permissions sprawl.

**Huddle mapping:** a huddle is a **Disciple.Tools group** (leader connection, member connections, health fields, generational links all come free), plus content-subsite structures for what D.T does not provide: per-lesson discussion threads scoped to the huddle, private participant notes, and lesson progress. The bridge is a stored `dt_group_id` on the content side.

## 4. Discussion, Notes, and the Privacy Model

- **Huddle discussion threads:** WordPress core comments are not access-controlled per group; a companion plugin must store discussion either in a custom table or a CPT keyed by (huddle, lesson) with capability checks on every read path. This is the largest single piece of custom engineering in the MVP — budget it accordingly.
- **Private reflection notes:** per-user post meta or custom table rows, never rendered to any other role. **Honesty requirement:** WordPress admins and anyone with database access can technically read stored notes. The PRD's privacy promise must be worded as "not visible to leaders or other participants" — not "nobody can ever read this" — unless we add application-layer encryption (possible later; not MVP).
- **Public forum:** must **not** live on the D.T subsite (theme owns the front end; forum users would need D.T accounts). When Phase 3+ justifies it, options on the content subsite are bbPress (simple, but minimally maintained), wpForo, or BuddyPress. The PRD already defers forums past MVP; huddle threads cover the MVP need. Defer the plugin choice — the forum landscape may shift before we need it.
- **Sensitive-context posture:** given Section 21 of the PRD, default every space to private, avoid public member directories entirely, and keep the public forum question tied to a security review, not just a feature decision.

## 5. Hosting and Performance for Vietnam

- **Region:** host in Singapore (or another SEA region) for latency to Vietnam; standard LEMP stack per Disciple.Tools hosting guidance.
- **Caching split:** the content subsite's lesson pages are cacheable (full-page cache + CDN); the D.T subsite is logged-in and dynamic (object cache only). Multisite-aware cache configuration is an S1 spike item.
- **Low bandwidth / old Android:** server-rendered lesson pages with minimal JavaScript; PWA layer (installable, offline-cache recent lessons) via a service worker on the content subsite only. Vietnamese typography needs a font stack verified for full diacritics (system fonts + a subsetted webfont fallback).
- **Distribution reality:** links will spread through Zalo and Facebook Messenger; invite links and magic links must unfurl cleanly (Open Graph tags) and survive in-app browsers.

## 6. Verified Facts vs. Assumptions

| Claim | Status |
|---|---|
| D.T is a WP theme; plugins-first extension model | **Verified** (repo docs) |
| `disciple-tools-multisite` actively maintained (v1.17.0, 2026-06) | **Verified** |
| D.T theme ships Vietnamese translation comparable in size to major languages | **Verified** (`vi.po` ~203 KB in repo); exact % **unverified** |
| D.T mobile app / plugins Vietnamese coverage | **Unverified** — audit in spike S2 |
| Harmony Bible: 7 languages, no Vietnamese, no public API | **Verified** (site inspection) |
| Knowing Him: free 50-day Sonlife study, 4 sections/day, 42 videos, no VN, no groups | **Verified** (site + Sonlife store) |
| Huddles map cleanly to D.T groups without workarounds | **Assumption** — spike S3 |
| Magic links can carry a lesson+response participant flow | **Assumption** — spike S4 |
| Subdirectory multisite has no D.T conflicts | **Assumption** — spike S1 |
| Vietnamese Bible text licensing path exists for pilot | **Unverified** — see [content-rights.md](content-rights.md) |

## 7. Summary Judgment

The proposed direction is sound and the PRD's guardrails are correct. The stack decomposes into:

1. **Install and configure** (low risk): WP multisite + D.T theme + D.T multisite plugin + ecosystem plugins.
2. **Build** (the real engineering): a study-reader/content plugin, a huddle-discussion/progress plugin with a strict privacy model, and a thin bridge plugin linking participant users to D.T contacts/groups.
3. **Coordinate:** written SonLife confirmation for Knowing Him translation, a Founders Passion / Harmony Bible conversation to pick the Vietnamese technical path, and — the one genuinely external item — Vietnamese Bible text rights. The roadmap sequences these first because publication milestones still depend on them.

The biggest technical risks are participant UX friction on the D.T side (mitigated by keeping participants entirely on the content subsite / magic links) and the custom privacy-scoped discussion layer (mitigated by spiking it early, S5).
