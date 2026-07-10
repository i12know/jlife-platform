# Vision Architecture: From Pilot to Church-Wide Disciplemaking Platform

Status: **Draft for review** — this is the dreaming document. Nothing here is
committed work until it is reviewed, argued with, and broken into issues.
Created: 2026-07-10
Related: [architecture.md](architecture.md) (MVP design — still authoritative
for what exists), [integration-boundaries.md](integration-boundaries.md),
[pilot-context.md](pilot-context.md), [roadmap.md](roadmap.md), spike
conclusions in [spikes/](spikes/).

---

## 1. The Vision in One Paragraph

Grow the J-Life platform from a single Vietnamese pilot huddle into the
**disciplemaking backbone of a whole church**: any member can be invited into
a devotional challenge or a huddle by a text message; any curriculum — Life of
Jesus, other books of the Bible, licensed studies like Knowing Him — can be
published as a portable series; leaders and pastors see engagement and
multiplication through Disciple.Tools; the church roster stays authoritative
in ChMeetings; and the whole thing runs on the identity, privacy, and content
machinery the six spikes already proved. The unit of movement is not the
platform — it is a person reading Scripture today and a leader who knows to
follow up tomorrow.

## 2. What We Already Have (the proven substrate)

Every item below is merged, tested, and concluded in a spike document. The
vision builds *on* these, not beside them.

| Capability | Proven by | Why it matters at church scale |
|---|---|---|
| STUDY (participant) / HUB (D.T CRM) split on one multisite, capability-based isolation | S1 | One login world; participants never see CRM; caching split |
| Huddle = D.T group; leader sees own groups only; oversight via explicit shares | S3 | Scales to many huddles/cohorts with per-leader privacy by default |
| Scoped magic links: `(contact, group, lesson)` bearer tokens, revocable, expiring, cookie-independent | S4 | No-login participation over SMS/Zalo — the church's existing habit |
| Privacy gate: one tested access-control layer for threads/notes/progress; fail-closed; CI-enforced | S5 | Pastoral-grade privacy that survives volunteer developers |
| Portable content: series/lesson schema, harmony spine, lossless WP round-trip incl. Vietnamese | S6 + #5/#6 | Unlimited curricula; content outlives the platform |
| Vietnamese localization baseline + gap list | S2 | Bilingual church reality; known plugin gaps |
| CI: content validation, PHPCS/PHPStan, access-control tests required | #16 | Quality holds as contributors multiply |

**Design invariants that do not change in any phase:**

1. Portable files are the home of content; databases are renderings.
2. Every read/write of participant data passes the one gate; unknown scope
   fails closed.
3. Scripture as references + licensed deep links until a text license lands.
4. Private notes are author-only at the app layer; the honesty statement
   discloses operator reality.
5. Public repo safety (PRD §21): no partner-identifying data, `.test`
   fixtures only.

## 3. The SMS Devotional Challenge (vayhub) — Analysis

> ⚠️ **Review note:** vayhub.us sits behind Cloudflare bot protection, so this
> analysis could not be verified by automated fetch. It is based on the
> owner's description (daily SMS with a link to that day's lesson page, a
> rules page for the challenge) and the URL structure (`/rdpt22/rules`,
> `/rdpt22/gea09-2`). **Owner: please correct anything mischaracterized here
> during review.**

### 3.1 What the current system appears to be

- A **campaign** (`rdpt22`) with published rules and a defined duration.
- **Daily cadence**: each participant receives an SMS containing a link.
- The link opens a **mobile-friendly lesson page** (`gea09-2` — notably a
  *content-shaped* identifier, essentially a gospel-event/lesson ID much like
  our own `lesson_id`/`gospel_event_id` convention).
- Participation is **self-reported / socially accountable** via the rules;
  the page itself does not know who is reading it.

### 3.2 Is it similar to a D.T magic link? (the direct answer)

**The user experience is nearly identical; the identity model is fundamentally
different — and that difference is exactly what the platform adds.**

| Property | vayhub SMS link (as understood) | S4 magic link |
|---|---|---|
| Delivery | SMS → tap → mobile page | Same (SMS/Zalo/email → tap → STUDY page) |
| Login required | No | No |
| Cookie/app dependence | None | None (token in URL/form — survives in-app browsers) |
| URL identifies | **The content** (same URL for everyone) | **The person + huddle + lesson** (unique per participant) |
| Platform knows who read/responded | No | Yes (contact-scoped, auditable `use_count`/`last_used`) |
| Progress/streaks per person | Manual/honor system | Automatic (`progress` table, leader flags, aggregates) |
| Revocation/expiry | Page stays up for anyone | Per-person revoke + TTL; regeneration invalidates old links |
| Forwarding risk | None (nothing personal behind it) | Real and *measured* (S4): a forwarded link can read/overwrite that person's leader-visible response — mitigated by scope, revocation, and the S4/S5 sensitivity rule |

So: your instinct is right — the *habit* your church already has (receive a
text, tap, read today's devotion on your phone) is precisely the habit the
magic-link flow was built to serve. What the platform adds is that the same
tap becomes **known**: streaks, completion, a leader who can see who may need
encouragement, and an aggregate tile for the pastor — without asking anyone
to create an account on day one. The anonymous broadcast link (vayhub-style)
remains available as the bottom rung of the ladder below.

### 3.3 The identity ladder (a core vision concept)

Participation should be possible at increasing levels of identity, with the
S4/S5 sensitivity rules deciding what each rung can touch:

```
Rung 0  Anonymous public link      → read-only lesson page (vayhub-equivalent;
                                     fine for open evangelistic challenges)
Rung 1  Magic-link contact         → + per-person progress/streaks,
                                     leader-visible responses  (S4, exists)
Rung 2  Claimed account            → + huddle discussion, private notes,
                                     durable history            (S5 rules, exists;
                                     claim flow to build)
Rung 3  Leader/coach account       → + D.T HUB, flags, aggregates (exists)
```

A challenge can start a person at rung 0 or 1 and invite them up the ladder;
the gate already refuses higher-sensitivity surfaces to lower rungs
(`jlife_huddles_link_actor_can()` is the enforcement point, tested in CI).

## 4. Target Architecture (the dream, drawn)

```
                        ┌────────────────────────────────────────────┐
                        │              CHMEETINGS (church CRM)       │
                        │  roster, households, ministries, events    │
                        └──────────────┬─────────────────────────────┘
                                       │ one-way roster sync (S7 spike)
                                       ▼
┌───────────────────────────────────────────────────────────────────────────┐
│                       WORDPRESS MULTISITE NETWORK                         │
│                                                                           │
│  ┌─────────────────────────────┐      ┌───────────────────────────────┐  │
│  │   STUDY (/)  participant    │      │   HUB (/hub/)  Disciple.Tools │  │
│  │                             │      │                               │  │
│  │  series catalog (portable)  │      │  contacts / groups / cohorts  │  │
│  │  lesson reader (S6)         │◄────►│  coaching generations         │  │
│  │  challenges (jlife-         │bridge│  J-Life Progress tile (S3)    │  │
│  │   challenges — NEW)         │      │  leader & coach workflows     │  │
│  │  huddle threads/notes/      │      │  D.T mobile app (leaders)     │  │
│  │   progress behind the gate  │      └───────────────────────────────┘  │
│  │   (S5)                      │                                         │
│  └──────────▲──────────────────┘   jlife-bridge = the ONLY crossing:     │
│             │ magic links (S4)     identity map (wp/dt/chm ids),         │
│             │ account claim (NEW)  membership reads, aggregates,         │
│             │                      magic links, roster sync              │
└─────────────┼─────────────────────────────────────────────────────────────┘
              │
   ┌──────────┴───────────────┐
   │   jlife-dispatch (NEW)   │  outbound messages: SMS (Twilio/etc),
   │   provider adapters      │  Zalo OA, email — each carrying a
   └──────────▲───────────────┘  per-person magic link or public URL
              │
      participant's phone: SMS / Zalo / Messenger → tap → STUDY
```

Roles of the three custom plugins stay exactly as architected — they gain
modules, not new crossings:

- **jlife-studies**: + challenge-aware reader, series catalog UI, PDF/print
  rendering.
- **jlife-huddles**: + REST endpoints over the existing data API (the gate is
  the review checklist), account-claim linkage, notes export.
- **jlife-bridge**: + `chm-sync` module (behind the reserved
  `chm_person_id`/`chm_group_id` keys), dispatch scheduling hooks, link-batch
  minting for campaigns.
- **jlife-challenges (new plugin)**: campaigns as first-class objects.
- **jlife-dispatch (new plugin)**: message-out with pluggable providers.

## 5. New Capability Designs

### 5.1 `jlife-challenges` — the campaign engine (the vayhub upgrade)

A **challenge** is a time-boxed, cadence-driven run of a series for a defined
audience — the generalization of `rdpt22`:

```
challenge {
  challenge_id            "chal-rdpt-2027"
  series_id               → any series in the catalog
  cadence                 daily | weekdays | weekly | custom map
  start_date / end_date
  audience                huddle(s) | ministry tag | whole church | open link
  enrollment              invited (links minted per person) | self-serve | both
  rules_page              rendered from the series/challenge doc (≈ /rules)
  scoring (optional)      individual streaks; team totals (rdpt22-style teams
                          map to D.T groups, so team standings reuse the
                          aggregate surface — counts only, never content)
}
```

New tables (same conventions as S5: gate-checked data API, utf8mb4,
`dt_group_id` where huddle-scoped): `jlife_challenges`,
`jlife_challenge_enrollment` (person ↔ challenge, rung of the identity
ladder, streak counters). Daily unlock is computed (start_date + cadence),
not scheduled per-row, so a late joiner sees the right "today."

Progress rows reuse the existing `jlife_progress` table — a challenge is
*not* a new privacy domain; the S5 matrix applies unchanged.

### 5.2 `jlife-dispatch` — messages out

One module owns "send participant X a message containing link Y at time Z":

- **Provider adapters**: SMS (Twilio or similar), email (SMTP), Zalo OA
  (Vietnam contexts), with a dry-run/log provider for dev. WhatsApp later via
  the same interface.
- **Batch minting** via the bridge: a challenge morning-run mints/refreshes
  scoped magic links and hands `(phone, url, template)` tuples to the adapter.
- **Templates** are translatable strings in the plugin text domain
  (Vietnamese-first, per S2's custom-strings finding).
- **Send-time rules**: per-audience local send hour, quiet hours, opt-out
  keyword handling (STOP/HELP) recorded on the D.T contact.

> **Compliance flag (decision item, not optional):** bulk SMS to US numbers
> requires A2P 10DLC campaign registration through the provider, documented
> opt-in consent, and honored opt-outs (TCPA). Since the church already runs
> SMS challenges, existing consent practice should be reviewed and recorded
> when this module is specified. Zalo OA has its own verification process.

### 5.3 ChMeetings integration (the reserved seam, activated)

`integration-boundaries.md` already pre-commits the shape; the vision only
schedules it:

- **Direction**: one-way, ChMeetings → platform. ChMeetings remains the
  source of truth for who is in the church; the platform never writes
  membership data back (aggregate participation summaries *may* be pushed
  later as a deliberate, reviewed exception).
- **Mechanism (to verify in spike S7)**: ChMeetings API/export → bridge
  `chm-sync` module → D.T contacts (with `chm_person_id`) and, where
  ministries map to huddles/audiences, D.T groups (`chm_group_id`).
  ChMeetings' mobile app remains the church-ops surface; leaders' J-Life
  surface is HUB/D.T mobile; participants' surface is STUDY links. No user
  ever needs to know three systems exist.
- **Never crosses** (restating the boundary doc, because church scale raises
  the stakes): discussion threads, private notes, prayer content, per-person
  progress detail. ChMeetings sees at most "this ministry ran challenge X,
  N participants, M completions" — and only if explicitly built and reviewed.

### 5.4 Account claim — the ladder's staircase

The S4 token already identifies a D.T contact. The claim flow turns rung 1
into rung 2 without re-registration friction:

1. Participant taps "Save my journey" on a magic-link page.
2. Platform creates/links a WP user (`corresponds_to_user` on the contact —
   the exact key the S5 live membership read already uses).
3. Link-actor progress rows (negative `user_id` = contact ID, by design)
   are merged into the account's rows — the schema anticipated this.
4. The participant gains threads/notes per the S5 matrix; the magic link
   keeps working for low-sensitivity surfaces.

### 5.5 Content-schema generalization (beyond the Life of Jesus)

To serve "other parts of the Bible" (and the church's existing whole-Bible
reading challenges):

- Make `primary_gospel_event_id` **conditionally required**: required for
  series tagged `life-of-jesus`, optional otherwise; add an optional
  `canonical_passage` key (normalized book/chapter range) as the non-gospel
  spine. Validator + JSON schema change only — S6 proved the round-trip
  machinery never inspects these fields.
- `gospel_phase` taxonomy gains sibling taxonomies rather than being bent
  (e.g., `curriculum_track`). The harmony dataset remains the spine for
  Jesus-centered curricula and the SonLife phases (#21).
- Rights stay per-series (already true) — licensed (Knowing Him), original,
  and public-domain series coexist in one catalog.

### 5.6 Participant data dignity (carrying an S5 review note forward)

Before church-wide launch: an **export-my-data** flow (own notes/responses,
Markdown/PDF) and a defined grace window or export prompt when someone leaves
a huddle — resolving the "removed member loses access to their own
reflections" tension deliberately rather than by default. Thread retention on
account deletion (retain/anonymize/delete) must also be decided (open item in
S5).

## 6. Phased Roadmap

Numbering continues the existing spike convention (S1–S6 done). Each phase
ends with a reviewable exit criterion; phases can overlap where noted.

### Phase A — Finish the pilot (current work, prerequisite for everything)
- #21 phase mapping (ministry), #7 pilot lessons (Vietnamese authoring +
  review), #17 staging/ops workflow, #1–#3 rights records.
- Vietnamese reader pass (S2 follow-up) + S5 privacy-wording approval.
- **Exit:** one real huddle completes a 5–7 week series on staging/production;
  feedback captured.

### Phase B — Challenge engine MVP ("rdpt on J-Life")
- **S8 spike: dispatch deliverability** — provider choice, A2P 10DLC/consent
  audit, cost model at church scale (~N texts/day × challenge length), Zalo
  OA feasibility for VN-side audiences.
- Build `jlife-challenges` (enrollment, cadence unlock, streaks; teams via
  D.T groups) and `jlife-dispatch` (one SMS provider + email + dry-run).
- Reuse the pilot series or a whole-Bible reading plan as the first content.
- **Exit:** a church devotional challenge runs end-to-end on the platform —
  daily SMS with per-person magic links, streaks visible to the participant,
  aggregates to the challenge admin — matching or beating the vayhub UX.
  Run it in parallel with the old system once as a shadow test.

### Phase C — ChMeetings roster integration
- **S7 spike: ChMeetings API/export reality** — what the API (or scheduled
  export) actually provides, auth model, rate limits, field mapping to D.T
  contacts, dedupe strategy. *This is the highest-uncertainty item in the
  vision; nothing else depends on its internals, only on its outcome.*
- Build bridge `chm-sync` (one-way, idempotent, logged, dry-run mode);
  activation is per-context per the boundary doc.
- **Exit:** inviting an existing ministry group to a challenge requires zero
  manual roster entry; boundary doc audit passes (nothing flows to
  ChMeetings).

### Phase D — Church-wide launch surface
- Account-claim flow (5.4) + huddle discussion/notes UI over the S5 data API
  (REST endpoints; gate functions are the PR review checklist).
- Participant data dignity items (5.6). Coach dashboards via D.T sharing
  patterns (S3). D.T mobile app rollout to leaders (S2 noted it's
  leader-facing and Vietnamese-complete).
- Localization: contribute Vietnamese PO files upstream for Magic Links /
  Groups Tile / Mobile App plugin after native review (S2 gap list).
- **Exit:** a member with no prior platform contact can go text → reader →
  responder → account → huddle member without staff intervention.

### Phase E — Catalog expansion
- Content-schema generalization (5.5); Knowing Him series onboarded under its
  recorded license (#1); whole-Bible/topical tracks; VIE2010 rendering if #3
  lands a license, else references+links continue.
- Authoring guide for lay content teams; translation workflow exercised at
  catalog scale.
- **Exit:** ≥3 series of different rights profiles live; a non-gospel series
  runs a challenge with zero platform changes.

### Phase F — Multiplication beyond one congregation (optional horizon)
- The *other* multisite axis: sibling networks or subsites per congregation/
  language mission, each with its own HUB, sharing the content catalog repo.
  Governance, theming, and data isolation review before any second tenant.
- **Exit:** documented playbook proving a second community can be stood up
  from the repo in days, not months.

### Cross-phase engineering hygiene
- Every new participant-data surface: matrix first, gate function, CI tests
  (the S5 discipline is the platform's immune system).
- `docs/integration-boundaries.md` is amended *before* any new sync ships.
- Ops (#17) grows with each phase: dispatch monitoring, provider spend
  alerts, challenge-day error budgets.

## 7. Risks and Open Decisions

| # | Risk / decision | Phase | Posture |
|---|---|---|---|
| 1 | ChMeetings API capability unknown | C (S7) | Spike before design; fallback = scheduled CSV export sync |
| 2 | SMS compliance (A2P 10DLC, TCPA consent, opt-out) & per-message cost | B (S8) | Legal/ops review with provider onboarding; budget model before launch |
| 3 | Magic-link forwarding at church scale | B+ | Already measured (S4); mitigations: scope, revoke, sensitivity rule, participant-facing warning; monitor `use_count` anomalies |
| 4 | Vietnamese quality on target plugins | B/D | Native review then upstream contribution (S2 gap list) |
| 5 | Notes access after huddle removal / account deletion retention | D | Product decision + export flow (5.6) before wide launch |
| 6 | Public challenge pages under load / bot abuse | B | Cache rung-0 pages at CDN (S1 caching split); rate-limit token endpoints; consider the same class of protection vayhub uses (Cloudflare) — noting it blocks bots, as this analysis experienced firsthand |
| 7 | Rights: Knowing Him confirmation recording (#1), VIE2010 (#3), Harmony Bible path (#2) | A/E | Register-first discipline; no content ships ahead of its row |
| 8 | Volunteer maintainer bus-factor | all | CI gates, this docs corpus, boring-technology bias (WordPress, PO files, JSON) |

## 8. What This Document Is Not

It is not a commitment, a schedule, or a replacement for
[architecture.md](architecture.md). It is the shared picture to argue with.
The intended lifecycle: review → correct the vayhub assumptions (§3) → agree
or amend the phase order → cut Phase B/C spike issues (S7, S8) in the tracker
→ retire sections into architecture.md as they become real.
