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
**disciplemaking backbone of a whole church**: a newcomer finds their place on
a visual **pathway map** (the RP Pathway App — §5.6) and taps one next step;
any member can be invited into a devotional challenge or a huddle by a text
message; any curriculum — Life of Jesus, other books of the Bible, licensed
studies like Knowing Him — can be published as a portable series; leaders and
pastors see engagement and multiplication through Disciple.Tools; the church
roster stays authoritative in ChMeetings; and the whole thing runs on the
identity, privacy, and content machinery the six spikes already proved. The
unit of movement is not the platform — it is a person reading Scripture today
and a leader who knows to follow up tomorrow.

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

> ✅ **Verified 2026-07-10** against the live site (`/rdpt22/rules`,
> `/rdpt22/devotionals`, `/rdpt22/gea09-5`) through a real-browser session on
> the owner's own site — Cloudflare's bot gate blocks server-side fetches but
> not a real browser, so the account below is now **observed, not inferred**.
> The earlier draft guessed a per-person "SMS with a link"; the reality is
> **team group texts, with reflections posted back into the group chat** —
> corrected throughout §3.1–§3.2. Owner-clarified same day (not visible from
> the site): the admin sends every team's daily text himself, one member per
> team posts the team's web comment to fulfill the day, and the admin tracks
> participation by being in every group chat.

### 3.1 What the current system actually is (observed)

- A **10-week campaign** (`rdpt22`, the "Daily Reading Challenge") running
  **5 days a week**, started **09/08/25**, with a published rules page and a
  first-week grace period.
- **Team-based, not individual.** Participants form **teams of 4–8**. Each day
  the **admin (the pastor) personally sends each team's group chat** a link to
  that day's devotional page — and "group chat" means whatever app each team
  lives in, so the same message is **hand-copied across SMS, FB Messenger,
  Zalo, …** every morning. Members read it, then **text their reflections
  back into the group chat**. The reflection loop lives in chat, not on the
  website. Separately, **one designated member posts the team's daily entry as
  a website comment** — the rules frame this as Tiebreaker 1 ("one entry per
  team per day"), but in practice it is how a team fulfills the day's
  requirement, which is why the observed comments read as one-per-team,
  sometimes relayed ("(from Sean)", "Tina").
- **Competitive vs. non-competitive members.** Everyone may start competitive,
  but missing participation drops a member to non-competitive; a team needs
  **≥4 competitive members** to qualify for the prize (a shared KBBQ meal).
  Winning is judged on *consistent, meaningful* daily reflection from **all**
  members before midnight — meaningfulness is **human-judged** (screenshots and
  activity photos count), with tiebreakers by (1) posting the team's reflection
  as a **website comment**, (2) Sunday attendance, (3) random draw.
- The **lesson page** (e.g. `GeA09-5: The Warrior's Bow`, dated) is a mobile
  WordPress/Divi page: nav (Devotionals · Rules · **Tiếng Việt**), the passage
  (full **NIV84 text rendered inline**), a short teaching, one combined
  *"Reflect, Share & Prayer"* prompt, prev/next links, an article star-rating,
  and a **wpDiscuz comment thread**. The slug is a **content-shaped ID**
  (`GeA09-5`), exactly our `gospel_event_id`/`lesson_id` convention.
- **The page does not know who is reading it — but the pastor does.** The
  site's only identity signal is the hand-signed comment thread; the *actual*
  per-person tracking layer is the **admin being a member of every team's
  group chat**, noticing each day who reflected and who went silent, and
  flipping competitive status accordingly. The system works — but its
  dispatcher, its progress tracker, and its "who needs encouragement" radar
  are all **one person reading every thread every day**, which caps how many
  teams can run.

Two observations that matter for the platform:

- **Bible text is embedded, not referenced.** vayhub renders the full NIV84
  passage on each page. The platform's invariant is the opposite — references
  plus licensed deep links until a text license lands (§2, invariant 3). NIV84
  is licensed text (Biblica/Zondervan), so under the register-first discipline
  (§7 row 7) a migration needs an NIV rights row — a sibling question to
  VIE2010 (#3), not covered by it — and must *resolve* text rights, not copy
  the current pages verbatim.
- **Bilingual already exists** (the `Tiếng Việt` nav), matching the platform's
  Vietnamese-first posture (S2): the challenge is already living the bilingual
  reality the platform assumes.

### 3.2 Is it similar to a D.T magic link? (the direct answer)

**The reading habit is identical (text → tap → today's devotion); the identity
model — and where the reflection lives — is fundamentally different, and that
difference is exactly what the platform adds.**

| Property | vayhub challenge (verified) | S4 magic link |
|---|---|---|
| Delivery | **Admin hand-texts each team's group chat** a link to the day's public page — re-copied per app (SMS, Messenger, Zalo) — reflections replied in-thread; one member posts the team's web comment | Per-person link via `jlife-dispatch` — API-sent or leader-relayed through any app (§5.2) → tap → personal STUDY page |
| Login required | No | No |
| Cookie/app dependence | None | None (token in URL/form — survives in-app browsers) |
| URL identifies | **The content** (same URL for everyone) | **The person + huddle + lesson** (unique per participant) |
| Platform knows who read/responded | The *site* doesn't; **the admin does** — by reading every team's chat daily | Yes (contact-scoped, auditable `use_count`/`last_used`) — no human needs to sit in every thread |
| Progress/streaks per person | Admin's daily observation of each group chat; competitive→non-competitive flipped **by hand** | Automatic (`progress` table, leader flags, aggregates) |
| Scoring | Human-judged "meaningful" reflection from all members before midnight | Completion/streaks automatic; *meaningfulness* still human (a leader flag, not a metric) |
| Revocation/expiry | Page stays up for anyone | Per-person revoke + TTL; regeneration invalidates old links |
| Forwarding risk | None (nothing personal behind it) | Real and *measured* (S4): a forwarded link can read/overwrite that person's leader-visible response — mitigated by scope, revocation, and the S4/S5 sensitivity rule |

So: your instinct is right — the *habit* your church already has (a daily text,
a tap to today's devotion, a reflection shared with your people) is precisely
the habit the magic-link flow was built to serve. Today that habit runs on
**two jobs the pastor does by hand**: sending every team's daily group text,
and reading every thread to know who reflected and who went quiet. Those are,
almost exactly, `jlife-dispatch` (§5.2) and the `progress`/aggregate surface —
the platform's addition is to absorb both, so the same tap becomes **known**:
streaks, completion, and competitive/non-competitive status computed
automatically, any team's leader (not only the one person in every chat) able
to see who may need encouragement, and an aggregate tile for the pastor —
without asking anyone to create an account on day one, and without the
challenge's size being capped by one person's reading capacity. One thing the
platform should *not* pretend to automate is the rules' notion of a
*meaningful* reflection: that stays a human (leader) judgment, surfaced as a
flag, not a score. The anonymous broadcast page (vayhub-style) remains
available as the bottom rung of the ladder below.

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

vayhub today lives entirely at **rung 0** — the group chat plus the self-signed
web comments. Its "competitive vs. non-competitive" member status is exactly the
kind of per-person state that appears *for free* once the same challenge runs at
rung 1 (`jlife_challenge_enrollment`, §5.1), instead of depending on one person
reading every team's group chat every day.

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
                          aggregate surface — counts only, never content).
                          A per-day entry may carry a leader "counts as
                          meaningful" flag — rdpt22 scores meaning, not mere
                          presence, and that judgment stays human (§3.2).
}
```

The verified `rdpt22` shape (§3.1) validates this design directly: its cadence
is `weekdays` over 10 weeks, its teams are 4–8 people that map cleanly to D.T
groups, and its "grace period" is just a `start_date` offset. The one field it
adds is a **member status** — rdpt22's *competitive vs. non-competitive*, where
missing a day downgrades you. Model it on the enrollment row (default
`competitive`, auto-downgrade on a missed-cadence gap, leader-restorable), and a
team's prize-eligibility (`≥4 competitive`) becomes a computed aggregate rather
than a hand-count.

New tables (same conventions as S5: gate-checked data API, utf8mb4,
`dt_group_id` where huddle-scoped): `jlife_challenges`,
`jlife_challenge_enrollment` (person ↔ challenge, rung of the identity
ladder, streak counters, **member status**). Daily unlock is computed
(start_date + cadence), not scheduled per-row, so a late joiner sees the right
"today."

Progress rows reuse the existing `jlife_progress` table — a challenge is
*not* a new privacy domain; the S5 matrix applies unchanged.

### 5.2 `jlife-dispatch` — messages out

One module owns "send participant X a message containing link Y at time Z" —
the job the pastor currently performs by hand for every team, every morning,
**re-copied across SMS, FB Messenger, Zalo, and whatever app each team lives
in** (§3.1). That fan-out reality drives the core design rule: **compose once,
transport many** — and the human relay is a *first-class transport*, not a
failure mode.

- **Compose/transport split.** Dispatch composes a message exactly once —
  `(recipient, template, minted link)` — with no knowledge of how it will
  travel. Transports are registered through a WordPress filter
  (`jlife_dispatch_transports`), so a new chat app in vogue means writing one
  adapter, never touching compose, templates, scheduling, or the log.
- **Two classes of transport, same interface:**
  - **API transports** — machine-sent where an API exists: SMS (Twilio or
    similar), email (SMTP), Zalo OA (Vietnam contexts), WhatsApp later; plus
    a dry-run/log transport for dev.
  - **Relay transports** — human-sent where no API exists or none is worth
    its compliance cost (personal FB Messenger, ad-hoc group chats). The
    platform renders a **dispatch sheet**: the day's ready-to-send message
    per team/participant with per-item copy buttons, prefill deep links
    (`sms:?body=…`, `zalo.me`, `m.me`), and — on mobile — the **OS share
    sheet**, which forwards to every app installed today *and every app not
    invented yet*. The relayer taps once per group instead of retyping N
    times; the sheet marks each item sent.
  - **Link shape follows the destination** (S4 rule, systematized): a
    **group chat** receives the day's *shared* link (rung-0 public page or a
    team page) — never a batch of personal tokens, which would hand every
    member every other member's bearer credential. **Personal** magic links
    travel one-to-one only: API transport or a per-person DM share from the
    sheet. The sheet enforces this by construction — group items compose
    with the shared link, person items with the personal one.
- **Leaders are senders, not just the admin.** A huddle leader (or challenge
  team captain) sees the dispatch sheet for *their own* people only — the
  same S3 scoping as everything else — so daily delivery decentralizes with
  the huddles instead of funneling through one person's phone.
- **Per-contact channel preference** recorded on the D.T contact at
  enrollment (`sms | zalo | messenger | email | leader-relay`), so compose
  routes each person to the transport that actually reaches them.
- **Batch minting** via the bridge: a challenge morning-run mints/refreshes
  scoped magic links and hands `(recipient, url, template)` tuples to each
  transport.
- **Dispatch log**: every send records `(recipient, channel, sent_by
  api|human, timestamp)` regardless of transport, so "did everyone get
  today's link?" is answerable — and the link's own `use_count` answers "did
  they tap it?"
- **Templates** are translatable strings in the plugin text domain
  (Vietnamese-first, per S2's custom-strings finding).
- **Send-time rules** (API transports): per-audience local send hour, quiet
  hours, opt-out keyword handling (STOP/HELP) recorded on the D.T contact.

> **Compliance flag (decision item, not optional):** bulk SMS to US numbers
> requires A2P 10DLC campaign registration through the provider, documented
> opt-in consent, and honored opt-outs (TCPA). Since the church already runs
> SMS challenges, existing consent practice should be reviewed and recorded
> when this module is specified. Zalo OA has its own verification process.
> **Relay transports sidestep this initially**: a leader personally sharing a
> link into their own group chat is exactly what happens today, not bulk A2P
> traffic — so Phase B can ship relay-first and add API transports as
> registration and budget land.

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
  machinery never inspects these fields. **Prerequisite:** the Phase A
  schema-version-tolerance item — without it the v1.1 bump is a flag-day for
  every existing file instead of an additive change. **Scheduled as Phase E1
  (v2.0.0), the first post-1.0 epoch** — ahead of the challenge engine, per
  the amended phase order in §6.
- Note this change is **not** needed for Gospel read-through series: the #7
  pilot (4 lessons spanning John 1–5 / 6–10 / 11–15 / 16–21, one apologetic
  theme each) fits schema v1.0 as-is — each lesson anchors on one Robertson
  event (e.g. `r1922-002` Logos, `r1922-149` "I am the way") and carries the
  chapter span as a whole-chapter `scripture_reference`. `canonical_passage`
  is for content the harmony genuinely doesn't cover (Genesis, Psalms,
  whole-Bible plans).
- `gospel_phase` taxonomy gains sibling taxonomies rather than being bent
  (e.g., `curriculum_track`). The harmony dataset remains the spine for
  Jesus-centered curricula and the SonLife phases (#21).
- Rights stay per-series (already true) — licensed (Knowing Him), original,
  and public-domain series coexist in one catalog.

### 5.6 RP Pathway App — the journey map becomes the front door

> Source: [i12know/rp-pathway-app](https://github.com/i12know/rp-pathway-app)
> — an interactive discipleship pathway map for the church (8 waypoints from
> *meeting-jesus* to *multiply-community* across upward/inward/outward stages,
> each with tracks, prerequisites, and ChMeetings signup forms; generated as a
> WordPress image-map plugin from a canonical `rp-pathway-waypoints.json`).

**The convergence is not a coincidence to engineer — it already exists.** The
pathway app's own PRD stubs "Disciple.Tools Integration (Phase 4)" with
exactly the design J-Life has already built and tested: ChMeetings as system
of record for people, D.T mirroring contacts with `chmeetings_person_id` as
the canonical key, cohort/mentoring workflows in D.T, magic-link micro-apps,
and summary progress writing back to ChMeetings. In other words: **J-Life is
the RP Pathway's Phase 4, already implemented; the RP Pathway is the front
door J-Life doesn't have yet.** The two apps are halves of one system:

| Layer | Owned by | What it answers |
|---|---|---|
| **Journey map** (pathway app) | RP Pathway | "Where am I? What's my one next step?" |
| **Walking surface** (series/challenges/huddles) | J-Life STUDY | "What do I read/do today, with whom?" |
| **Relationships & oversight** | J-Life HUB (D.T) | "Who walks with whom? Where are the bottlenecks?" |
| **Roster & signups of record** | ChMeetings | "Who is in our church? Who signed up?" |

**Concrete integration joins** (each small, because both codebases practice
the same stable-ID discipline):

1. **Track → series deep link.** The pathway's `track_id` CTA today points at
   a ChMeetings form (`cta_url`). When a track has a J-Life series behind it,
   the CTA gains a second action: the ChMeetings form remains the signup of
   record, and the confirmation (or the dispatch engine) sends the
   participant a **magic link into the series/challenge** — the map flows
   straight onto the walking surface. A one-file mapping registry
   (`track_id ↔ series_id/challenge_id`) lives in the content repo, versioned
   like everything else.
2. **Verified completion feed.** The pathway PRD distinguishes *self-attested*
   vs *leader-verified* completion, and hard-gates Companion eligibility on
   verified. J-Life's `jlife_progress` + leader flags are precisely a
   leader-verified completion source: the bridge can push "track completed
   (verified)" summaries to the ChMeetings *My Pathway* profile fields — the
   pathway app's Phase 4 stub asked for exactly this write-back, and
   integration-boundaries.md already classifies it as the deliberate,
   reviewed aggregate exception (counts/flags only, never content).
3. **Companions = the leader pipeline.** The pathway's Companion concept (a
   member qualified to walk others through a track they completed) maps onto
   the D.T structures S3 reserved: Companions-in-training as a D.T **Team**
   or leader cohort, companion↔learner matches as D.T group/contact
   assignments in HUB, giving matching a home with the privacy model already
   attached — instead of a parallel matching system.
4. **The map is the rung-0/rung-1 surface.** The pathway app is deliberately
   guest-friendly (no login) — it *is* the identity ladder's ground floor
   (§3.3). A guest explores the map anonymously, signs up via ChMeetings
   form (rung 0→1 via roster sync), and receives a magic link; account claim
   (§5.4) comes only when they need discussion or private notes.
5. **Taxonomies stay siblings.** The pathway's upward/inward/outward stages
   and the SonLife 5 phases are different lenses on the journey; per §5.5
   both live as per-series/track tags rather than being force-merged. A
   J-Life series can carry both a `gospel_phase` and a `pathway_waypoint`
   tag; the map and the catalog stay consistent without either owning the
   other's theology.
6. **Deployment reality:** the pathway app is a generated WordPress plugin —
   it can be installed on the STUDY subsite as-is (eventually as STUDY's
   front page), sharing the multisite, caching split, and bilingual EN/VI
   posture both projects independently chose.

**What deliberately stays separate:** the pathway app keeps shipping on its
own repo/cadence (its JSON → generated-plugin pipeline is self-contained);
ChMeetings forms remain the signup mechanism (J-Life never becomes a signup
system of record); and nothing in the pathway's guest surface ever reads
huddle threads, notes, or progress detail — it consumes at most the same
aggregate/flag shapes as the HUB tile.

### 5.7 Participant data dignity (carrying an S5 review note forward)

Before church-wide launch: an **export-my-data** flow (own notes/responses,
Markdown/PDF) and a defined grace window or export prompt when someone leaves
a huddle — resolving the "removed member loses access to their own
reflections" tension deliberately rather than by default. Thread retention on
account deletion (retain/anonymize/delete) must also be decided (open item in
S5).

## 6. Phased Roadmap

Numbering continues the existing spike convention (S1–S6 done). Each phase
ends with a reviewable exit criterion; phases can overlap where noted.

**Version ladder** (of record in [roadmap.md §Versioning](roadmap.md)): each
phase exit below carries its target release. Order as amended in review
(2026-07-11): **A → E1 → B → C → D → F**. Catalog generalization (E1) is
promoted to the first post-1.0 epoch so general Bible curricula are supported
before further Life-of-Christ-harmony-specific work — the church's real
challenges are whole-Bible reading plans (§3.1), and the challenge engine must
not ship before the content model can carry the church's actual curriculum.
The rights-gated remainder of old Phase E (now E2) leaves the epoch ladder and
ships as minor releases whenever its rights rows land.

### Phase A — Finish the pilot (current work, prerequisite for everything) → v1.0.0
- #21 phase mapping (ministry), #7 pilot lessons (Vietnamese authoring +
  review), #17 staging/ops workflow, #1–#3 rights records.
- Vietnamese reader pass (S2 follow-up) + S5 privacy-wording approval.
- **Substrate hardening** — a code review of the pilot plugins against this
  vision (2026-07-10) found the bones sound (signed `user_id` anticipates
  account claim; the single gate and string `lesson_id` keys carry challenges
  unchanged) and five adjustments that are cheap now but become live-data
  migrations after launch:
  1. **Hash magic-link tokens** (bearer token is currently stored cleartext in
     HUB contact meta) and move link state to a dedicated
     `jlife_magic_links` table supporting **multiple concurrent scoped links
     per contact** — one contact meta key cannot hold a huddle link and a
     challenge link at once, and §5.2 batch minting collides with it.
     Must land before real links circulate; a later switch voids them all.
  2. **Move link responses out of the `jlife_s4_responses` option** (spike
     posture, read-modify-write race, ungated) into a gated `jlife_responses`
     table before real reflections accumulate.
  3. **Widen the token scope shape**: optional `challenge_id`, `lesson_id`
     optional and resolvable to "today" via cadence — so one link serves a
     whole challenge and late joiners land on the right day.
  4. **Decision:** non-huddle challenge audiences materialize as D.T groups
     (what rdpt22 teams already are) rather than a sentinel `dt_group_id` —
     `dt_group_id NOT NULL` sits inside every unique key, so this is the
     moment to choose.
  5. **Schema-version tolerance:** validator/importer accept a known-versions
     list instead of hardcoding `"1.0"`, so the §5.5 v1.1 bump is additive,
     not a flag-day.
- **Exit:** one real huddle completes the pilot series (per #7: minimum 4
  lessons — the 4-week Gospel-of-John read-through) on staging/production;
  feedback captured. This exit is the **v1.0.0** release; earlier 0.x
  milestones (pilot content, walking skeleton + hardening, feature-complete
  staging, pilot launch) are tabled in roadmap.md §Versioning.

### Phase E1 — Catalog generalization (promoted ahead of Phase B) → v2.0.0

- Content-schema generalization (§5.5) as its own epoch: schema v1.1 with the
  `canonical_passage` spine, `primary_gospel_event_id` required only for
  `life-of-jesus`-tagged series, and sibling taxonomies (`curriculum_track`)
  rather than bending `gospel_phase`. Validator, importer, and JSON schema
  updates; authoring guide for lay content teams.
- The pilot's John series needs none of this (it fits v1.0 as-is), which is
  why E1 sits *after* v1.0 rather than blocking the pilot — but it sits
  *before* the challenge engine because the church's real devotional
  challenges are whole-Bible reading plans the v1.0 schema cannot express.
- Depends on the Phase A schema-version-tolerance hardening item (#5 above):
  with it, v1.1 is additive; without it, a flag-day.
- **Exit:** a general-Bible (non-gospel) series authors, validates, imports,
  and renders with zero platform special-casing.

### Phase B — Challenge engine MVP ("rdpt on J-Life") → v3.0.0
- **S8 spike: dispatch deliverability** — provider choice, A2P 10DLC/consent
  audit, cost model at church scale (~N texts/day × challenge length), Zalo
  OA feasibility for VN-side audiences.
- Build `jlife-challenges` (enrollment, cadence unlock, streaks; teams via
  D.T groups) and `jlife-dispatch` **relay-first**: the compose layer, the
  leader dispatch sheet (copy / prefill / share-sheet), the dispatch log, and
  the dry-run transport — then one API transport (SMS or email) behind the
  same interface once S8 settles provider and consent. Relay mode alone
  already beats today's hand-copying across apps.
- First content: the church's whole-Bible reading plan — expressible now that
  E1 landed; the pilot John series remains available as a fallback.
- **Design constraint from the verified rdpt22 (§3.1):** the challenge's
  social engine is the **team-visible reflection** in the group chat — not a
  private response to a leader. The S4 response surface is leader-visible, and
  team-visible threads sit at rung 2 (claimed account) in the S5 matrix. So
  Phase B must either (a) keep the existing group chat alongside per-person
  links (platform tracks reading/streaks; chat keeps carrying the reflections),
  or (b) decide — matrix first, per the S5 discipline — whether a
  challenge-team thread can be opened to rung-1 link actors. Do not ship a
  version that quietly downgrades a shared reflection into a private form.
- **Exit:** a church devotional challenge runs end-to-end on the platform —
  a daily message with per-person magic links reaching every team through its
  own app (leader-relayed or API-sent), streaks visible to the participant,
  aggregates to the challenge admin — matching or beating the rdpt22 UX
  *including its team reflection loop*, and **nobody hand-copies the same
  message across SMS, Messenger, and Zalo**. Run it in parallel with the old
  system once as a shadow test.

### Phase C — ChMeetings roster integration → v4.0.0
- **S7 spike: ChMeetings API/export reality** — what the API (or scheduled
  export) actually provides, auth model, rate limits, field mapping to D.T
  contacts, dedupe strategy. *This is the highest-uncertainty item in the
  vision; nothing else depends on its internals, only on its outcome.*
  > ✅ **Largely pre-answered 2026-07-17** by the shared portfolio skill
  > [i12know/vay-chmeetings-skill](https://github.com/i12know/vay-chmeetings-skill)
  > (v0.1.3, verified against ChMeetings 2026.5; tenant scoping live-probed
  > 2026-07-16): a documented REST API exists (Scalar/OpenAPI; People CRUD,
  > Groups read-only — sufficient for our one-way direction), auth is a
  > per-tenant API key, **outbound webhooks exist** (People
  > created/updated/deleted) so event-driven sync is possible and the
  > scheduled-CSV fallback is likely unnecessary, and person **merges orphan
  > external IDs as plain 404s** — `chm_person_id` handling must follow the
  > skill's retire-never-delete repair workflow. S7 narrows to J-Life
  > specifics: RP-tenant key issuance, the `CHM_FIELDS` map → D.T contact
  > fields, webhook signature verification (undocumented upstream), and the
  > shared-tenant scope line with rp-pathway-app.
- Build bridge `chm-sync` (one-way, idempotent, logged, dry-run mode);
  activation is per-context per the boundary doc.
- **Shared with RP Pathway:** S7 also covers reading pathway signups and *My
  Pathway* profile fields, and scopes the verified-completion write-back
  (§5.6 join 2) — one spike serves both apps' ChMeetings needs.
- **Exit:** inviting an existing ministry group to a challenge requires zero
  manual roster entry; boundary doc audit passes (nothing flows to
  ChMeetings beyond the reviewed aggregate exception).

### Phase D — Church-wide launch surface → v5.0.0
- Account-claim flow (5.4) + huddle discussion/notes UI over the S5 data API
  (REST endpoints; gate functions are the PR review checklist).
- Participant data dignity items (5.7). Coach dashboards via D.T sharing
  patterns (S3). D.T mobile app rollout to leaders (S2 noted it's
  leader-facing and Vietnamese-complete).
- Localization: contribute Vietnamese PO files upstream for Magic Links /
  Groups Tile / Mobile App plugin after native review (S2 gap list).
- **RP Pathway joins land here:** pathway map installed on STUDY as the
  front door; `track_id ↔ series_id` registry live so track CTAs hand off to
  magic-link challenges (§5.6 join 1); verified-completion feed to *My
  Pathway* (join 2); Companion pipeline modeled in D.T (join 3).
- **Exit:** a member with no prior platform contact can go map → signup →
  text → reader → responder → account → huddle member without staff
  intervention.

### Phase E2 — Licensed catalog & harmony enrichment (rights-gated minor releases, not an epoch)
- The rights-dependent half of old Phase E: Knowing Him series onboarded under
  its recorded license (#1); VIE2010 rendering if #3 lands a license, else
  references+links continue; harmony enrichment (#21 SonLife phase mapping,
  gospel-event browsing) as ministry review completes.
- These ship as **minor releases** on whatever major is current, the moment
  each rights row / theological review lands — content gates should never
  hold a capability epoch hostage. Register-first discipline (§7 row 7)
  unchanged. Translation workflow exercised at catalog scale as series land.
- **Exit (rolling):** ≥3 series of different rights profiles live.

### Phase F — Multiplication beyond one congregation (optional horizon) → v6.0.0
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
| 1 | ChMeetings API capability unknown | C (S7) | ~~Spike before design; fallback = scheduled CSV export sync~~ **Downgraded 2026-07-17:** API capability documented and largely live-verified in [vay-chmeetings-skill](https://github.com/i12know/vay-chmeetings-skill); residual S7 scope is J-Life-specific (field map, webhook signatures, RP-tenant key, merge-orphan handling) — see Phase C note |
| 2 | SMS compliance (A2P 10DLC, TCPA consent, opt-out) & per-message cost | B (S8) | Legal/ops review with provider onboarding; budget model before launch. Relay-first dispatch (§5.2) ships without it — API transports wait for registration, not vice versa |
| 3 | Magic-link forwarding at church scale | B+ | Already measured (S4); mitigations: scope, revoke, sensitivity rule, participant-facing warning; monitor `use_count` anomalies |
| 4 | Vietnamese quality on target plugins | B/D | Native review then upstream contribution (S2 gap list) |
| 5 | Notes access after huddle removal / account deletion retention | D | Product decision + export flow (5.7) before wide launch |
| 6 | Public challenge pages under load / bot abuse | B | Cache rung-0 pages at CDN (S1 caching split); rate-limit token endpoints; consider the same class of protection vayhub uses (Cloudflare) — noting it blocks bots, as this analysis experienced firsthand |
| 7 | Rights: Knowing Him confirmation recording (#1), VIE2010 (#3), Harmony Bible path (#2) | A/E2 | Register-first discipline; no content ships ahead of its row |
| 8 | Volunteer maintainer bus-factor | all | CI gates, this docs corpus, boring-technology bias (WordPress, PO files, JSON); roles and co-maintainer plan in [team.md](team.md) |
| 9 | RP Pathway ↔ J-Life ID drift (`track_id` ↔ `series_id`, waypoint tags) | C/D | One versioned mapping-registry file, validated in CI like all content; both repos keep their stable-ID disciplines |
| 10 | Two definitions of "completion" (pathway self-attested vs J-Life leader-verified) | D | Adopt the pathway's own `completion confidence` vocabulary end-to-end; J-Life feeds only the *verified* tier |

## 8. What This Document Is Not

It is not a commitment, a schedule, or a replacement for
[architecture.md](architecture.md). It is the shared picture to argue with.
The intended lifecycle: review (the vayhub account in §3 is now verified
against the live site, 2026-07-10) → agree or amend the phase order (amended
2026-07-11: catalog-first, E1 before B, version ladder recorded in roadmap.md
§Versioning) → cut spike issues in the tracker (done: S7 → #33, S8 → #34;
both deferred until after v1.0) → retire sections into architecture.md as
they become real.
