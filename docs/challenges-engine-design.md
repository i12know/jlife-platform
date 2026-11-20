# `jlife-challenges` engine: design for review (M5, #60)

Status: **design draft for owner/consultant review. No code yet.** Building starts after #60 is approved and the #55 decisions D1–D5, D6, D14, D16–D18 are settled ([decision sheet](https://github.com/i12know/jlife-platform/issues/55#issuecomment-6651386035)). Defaults below follow the recommended answers. A changed decision changes the marked rule, not the structure. **D14 changed on 2026-11-18** ([#55](https://github.com/i12know/jlife-platform/issues/55#issuecomment-6983874900), [#112](https://github.com/i12know/jlife-platform/issues/112)): a participant is solo or in up to 3 groups per campaign; see §2 "Group membership" and §4–4b.

Related: [#49](https://github.com/i12know/jlife-platform/issues/49) (spec); #60 (engine) with #61 (screens), #62 (sharing) and #64 (groups/admin); [S5 privacy model](spikes/S5-privacy-model.md); [reading plans](../content/reading-plans/README.md) (#59, done).

## 1. Principles the design enforces

1. **Scripture before score.** The engine serves the Today page's reading first; scoring is a side effect of submitting.
2. **Participation, not quality.** A point needs an entry that passes the campaign's **scoring engine** (§3a), submitted in time. The default engine only checks structure (the S, O, A and P parts are present, in order, and not empty); it never judges wording. No AI or outside service reads entries without its own privacy decision (§3a).
3. **Private by default.** A SOAP entry is readable by its author only. Sharing creates a *separate* snapshot.
4. **A SOAP entry is not a scored day.** Entries exist without a campaign (SOAP after Day 104, P4). Scoring is a separate ledger row.
5. **One point per person per day, enforced by the database**, not by application checks alone.
6. **Individual scoring only.** Groups, sharing and optional reading never change points.
7. **No Disciple.Tools dependency at launch** (D16). Groups feed the existing `jlife_huddles_group_membership` filter.

## 2. Tables (plugin `plugins/jlife-challenges`, prefix `{$wpdb->prefix}jlife_`)

| Table | Key columns | Notes |
|---|---|---|
| `campaigns` | `id`, `campaign_key` (unique slug), `plan_id` (e.g. `rpl-life-and-teachings-of-jesus-2026`), `title`, `start_date`, `end_date`, `timezone` (`America/Los_Angeles`), `grace_days` (D1: `1`), `status` (`draft`/`active`/`finished`), `scoring_engine` | Day content comes from the reading plan (#59) by `day_id`; nothing copied. `scoring_engine` (default `soap-regex`) names the campaign's scoring engine (#98) |
| `groups` | `id`, `campaign_id`, `name` (1–60 chars, plain text), `invite_code_hash`, `invite_expires_at`, `status` (`active`/`disabled`), `started_by` (user ID of the adult who started it; `0` for organizer-made groups; DB version 4) | Invite codes stored **hashed**; a code is shown once to whoever makes it (an organizer, or the group's starter). A `disabled` group's code stops working and its members lose its feed and slot (§4c). The name is shown only to the group's members and to organizers |
| `enrollments` | `id`, `campaign_id`, `user_id`, `group_id` (**retired**, see below), `status` (`active`/`removed`), `age_band`, `guardian_consent`, `consent_version`, `consented`, `joined`, `left_at`; **UNIQUE (`campaign_id`, `user_id`)** | **One per person per campaign**: consent, age band, progress, entries and points are personal. It says *that* the person takes part, not *which group* (D14 as changed in #112). "Read on my own" is an enrollment with no membership. `group_id` is no longer written (new rows get `0`) or read; see the note under the table |
| `group_members` | `id`, `campaign_id`, `enrollment_id`, `user_id`, `group_id`, `role` (`member`/`starter`), `status` (`active`/`left`/`removed`), `joined_at`, `left_at`; **UNIQUE (`group_id`, `user_id`)** | **A person is solo (no active row) or in up to 3 groups per campaign** (D14 changed, #112), adults and minors alike. The only source of "who is in this group": the gate, the feed and the `jlife_huddles_group_membership` filter read it. Joining, leaving, moving and removal are audited (D18). `starter` is written by "start a group" (#112 part 2, §4c): the one extra right is resetting that group's invite code; it gives no extra view of other members |
| `soap_entries` | `id`, `user_id`, `campaign_id` (0 = unscored/personal), `day_id` (`''` if none), `entry_date` (campaign-local date), `body` (**one** text; #98), `status` (`draft`/`submitted`), `submitted_at`, `revision`, timestamps; **UNIQUE (`user_id`, `campaign_id`, `day_id`)** for campaign entries | Drafts autosave server-side (M6). New entries are prefilled with the S/O/A/P heading template (never Bible text). `submitted` means the entry has passed the engine; an entry that has not passed stays `draft` with its text saved |
| `soap_revisions` | `id`, `entry_id`, `revision`, `body`, `saved_at` | Edit history after submission (D3) |
| `score_ledger` | `id`, `campaign_id`, `user_id`, `day_id`, `entry_id`, `points` (0/1), `status` (`earned`/`late_no_point`/`removed_by_review`), `reason`, `source` (`platform`/`review`), `engine`, `engine_version` (which scoring engine awarded the row; #98), `created_at`; **UNIQUE (`campaign_id`, `user_id`, `day_id`)** | Written once per person and day, append-only: points are never taken back. Review changes (P1) go through an audited path |
| `group_shares` | `id`, `entry_id`, `user_id`, `group_id`, `campaign_id`, `day_id`, `body` (snapshot of the **whole** entry; #98), `status` (`shared`/`removed`/`hidden`), `hidden_reason`, timestamps; **UNIQUE (`entry_id`, `group_id`)** | A **snapshot**: editing the private SOAP never changes it; sharing again replaces it. One row per entry **per group**: a share to group A is a different row from a share to group B and is read only through group A's feed |
| `reminder_prefs` | `id`, `campaign_id`, `user_id`, `opted_in`, `opted_out` (both nullable), `consent_version`, `locale` (`en_US`/`vi`), `updated`; **UNIQUE (`campaign_id`, `user_id`)** | Daily reading reminder choices (#63 Phase B, §4d). No email address in the table; absence of a row means no reminders |
| `reminder_sends` | `id`, `campaign_id`, `day_key`, `user_id`, `status` (`queued`/`accepted`/`failed`/`skipped`), `attempts`, `next_attempt`, `last_error_class` (nullable), `created`, `updated`; **UNIQUE (`campaign_id`, `day_key`, `user_id`)** | Daily reminder queue and log (#63 Phase B, §4e). Only IDs, a status, counters, times and a fixed class: **no email address, subject, body or raw error**. The UNIQUE key is the duplicate guard. Purged with the account |
| `audit_log` | `id`, `actor_user_id`, `action`, `object_type`, `object_id`, `campaign_id`, `detail` (JSON, **never SOAP text**), `created_at` | Enrollment changes, moves, review actions, hides, exports |

**Group membership (D14 changed, #112).** On 2026-11-18 the owner replaced "one primary group per person" with: a participant is **solo or in up to 3 groups per campaign**, minors too. The `enrollments` row stays one per person per campaign because consent, progress, entries and points are personal; groups only decide *who can read what a person chose to share*. Consequences:

- **Scoring never depends on groups.** Solo, one group and three groups earn the same one point per day from the same ledger row. Organizer counts are per group for reporting only (a person in two groups appears in both rows; a solo reader under group `0`); use the ledger for totals. The score export lists a person's active groups in one cell (`A; B`), one row per participant and day.
- **Limit:** at most `JLIFE_CHALLENGES_MAX_GROUPS` (3) *active* memberships per person per campaign, enforced when a membership is added (code join, organizer enrollment, a re-join) and re-checked after the write so two simultaneous joins cannot exceed it. A fourth is refused with `jlife_group_limit`. Leaving frees a slot.
- **Migration.** The upgrade `jlife_challenges_upgrade_memberships()` (run by the installer on every deploy, idempotent) copies each `enrollments.group_id > 0` into a `group_members` row (active enrollment → active membership; removed enrollment → removed membership with its `left_at`; `joined_at` = the enrollment's `joined`; role `member`). A row is copied only if no membership exists for that person and group *in any status*, so a re-run never resurrects someone who left. The old column is **retired, not dropped**: it stays `NOT NULL DEFAULT 0` for one release as the migration's source and a rollback aid, nothing writes it and nothing but the upgrade reads it (a test pins that the gate ignores it). A later release drops it once every deployment has migrated.
- **Joining later.** A solo reader or a member can enter another code up to the limit (`/group` has the "Have a code? Join a group" box; the emailed-code join does the same for a new sign-in). Entries saved before joining stay private; they reach the new group only if the author ticks that group on a later save or posts from `/share`. Every join and leave is audited (`group_joined`, `group_left`, `member_moved`, `member_removed`); wrong codes are rate limited per person.
- **Sharing.** One unticked checkbox per active group on the SOAP form, no "share with all", none for a solo reader; `/share` has one section per group; `/group` has a picker when there is more than one group. See §4a.
- **Starting a group** (adults only, §4c): an adult names a group and becomes its `starter` member; it uses one of their 3 slots; at most 2 started per person per campaign; the code and link are shown once and the starter can reset the code. The age check reads the *stored* enrollment, never the form.
- **Leaving.** `/group` has a confirmed **Leave this group**; the person keeps their past shares in that group (D18) until they remove them from **Groups you left** on `/share`; the slot frees at once.
- **Organizers** can still enroll, move (naming which group is left when there are several) and remove, and (#112 part 2) list, rename, disable and re-enable any group with `wp jlife group ...` (audited; the list shows names, status, the starter's user ID and active member counts, never codes or member names). They never browse feeds (D17).

## 3. Days and scoring

- **Campaign day:** the server converts *now* to the campaign timezone (never the device clock), takes the local date, and counts days from `start_date`. Day 1 = 2026-12-20; Day 104 = 2027-04-02. Outside the range there's no campaign day. The **Mar 14, 2027 DST change** is handled by date arithmetic in the campaign timezone, not by adding 24-hour periods.
- **Submit (#98):** the text is saved first, then passed to the campaign's scoring engine (§3a) synchronously. If the engine **does not pass** it, the text stays saved, no ledger row is written and the page says kindly what to add; submitting again after editing can still earn the point. On the **first** passing submission for a day:
  - on time (local submit date ≤ day date + `grace_days`) → ledger row `points=1, status=earned`
  - otherwise → ledger row `points=0, status=late_no_point`, and the response says why (D2)
  - the row records the engine id and version that passed it
- **Idempotent:** the ledger insert relies on the UNIQUE key. A double tap, a retry or a second device can't create a second row.
- **Edits** (D3) write a revision and never touch the ledger. Points are only added, never revoked: a later edit, or a later engine, can't take a point back.
- **Totals** ("N of 104") are computed from the ledger.

## 3a. Scoring engines (#98)

Whether an entry earns the day's point is decided by a separate, pluggable **scoring engine**, so writing and saving never depend on the entry's structure.

- **Contract:** `jlife_challenges_score_entry( $text, $context )` returns `{ passes: bool, reasons: string[], engine: id, version: string }`. It is deterministic for a given input and engine version. `$context` carries the campaign row, day key and day index. An engine is registered with the `jlife_challenges_scoring_engines` filter (id → label, version, callback).
- **Selection:** `campaigns.scoring_engine`; unknown or empty falls back to the default, `soap-regex`.
- **`soap-regex` (first engine):** passes when `S`, `O`, `A` and `P` headings appear in that order and each has non-whitespace content beyond its heading. A heading is a line starting with the letter (any case) and then `:` or a dash (`—`, `–`, `-`), optionally followed by the English or Vietnamese template word. A plain hyphen glued to the next word (`A-rôn`, `A-đam`: Vietnamese writes names with hyphens) is text, not a heading, unless that word is the heading word (`S-Scripture`). The untouched template does not pass. Failure reasons are short, kind and translatable (for example "Add something under P — Prayer").
- **Never revokes:** engines decide at submit time only. A future engine that runs later (for example a classifier) can add a quality signal for organizers; it can't remove a point.
- **Out of scope until decided separately:** any engine that sends entries to an AI or other outside service. Entries include prayers and some participants are 13–17, so that needs an updated consent wording, an update to [integration-boundaries.md](integration-boundaries.md), and a data-processing agreement or self-hosting first.

## 4. Privacy matrix: new rows for the engine

Extends the [S5 matrix](spikes/S5-privacy-model.md) with the M5 objects. S = SOAP entry and its revisions; L-self = own ledger detail; L-counts = per-day/group counts; GS = group share; X = score export (CSV, no SOAP text, no email, no tokens). Site-admin reads apply only to a **confirmed** group (#48, fixed in #68).

Since D14 changed (#112) a participant is **solo** (no group) or in **up to 3 groups** per campaign. "Group" in the GS columns means *that one group*: every row is read per group, so a person's rights in group A say nothing about group B.

| Actor | S read | S write | L-self | L-counts | GS read | GS create / remove | GS hide | X |
|---|---|---|---|---|---|---|---|---|
| Author (participant) | ✅ own | ✅ own | ✅ | own group's counts only if D5 allows (default ❌) | ✅ each group they are in | ✅ own entries, to each group they are in (≤3, one choice per group) | ❌ | ❌ |
| Member of group A (reading group A's feed) | ❌ | ❌ | ❌ | ❌ | ✅ A's shares only | ❌ (not others') | ❌ | ❌ |
| Member of group A, asking for group B (not a member) | ❌ | ❌ | ❌ | ❌ | ❌ B | ❌ | ❌ | ❌ |
| Solo reader (enrolled, no active membership) | ✅ own | ✅ own | ✅ | ❌ | ❌ no feed | ❌ no group to share to | ❌ | ❌ |
| Group leader (P2) | ❌ | ❌ | ❌ | ✅ own group (D5) | ✅ as a member of that group | ❌ | ❌ | ❌ |
| Group starter (#112 part 2): the adult who made one group | same as any member of that group; **no extra view** of others' entries or progress. Their one extra right is to reset *that group's* invite code (§4c) | | | | | | | |
| Former member after leaving or a move (D18) | own S only | own | own | ❌ | ❌ that group | remove own old shares only | ❌ | ❌ |
| Site admin / organizer | ❌ (P1 review path only, audited) | ❌ | ✅ (D5) | ✅ | ❌ browse (D17) | ❌ | ✅ reported posts, audited, author told (D17) | ✅ |
| Anonymous | ❌ | ❌ | ❌ | ❌ | ❌ | ❌ | ❌ | ❌ |
| Magic-link actor | ❌ (bridge off at launch) | ❌ | ❌ | ❌ | ❌ | ❌ | ❌ | ❌ |

Unknown campaign, group or entry → **denied, indistinguishable from "not found."**

### 4a. Share on save (#98, per group since #112): one checkbox per group

The SOAP form has **one unchecked checkbox for each group the participant is in**, labelled with the group's name. There is no "share with all" box and no default tick. A **solo reader** (no active membership) sees no share box at all. It is a second way to create the same group share (GS create), not a new kind of access. The whole saved entry, including Prayer, is the snapshot; there are no field choices. The share engine's rules apply unchanged, per group; this table is the row that the access-control tests in `test-share-on-save.php` and `test-multi-group-sharing.php` pin down.

| Case | Result |
|---|---|
| No box ticked, or a draft save/autosave | No post is created or changed |
| Box(es) ticked on Submit / Save changes, save succeeded | One post **per ticked group** (create, or update the same one; retries never duplicate). Groups not ticked get nothing. Saved without a point still counts as saved |
| Box ticked, save failed (denied, closed day) | No post |
| Member of a ticked group | Reads the post (first name only) in that group's feed only |
| Member of a group that was **not** ticked, other-group member, outsider, organizer, anonymous | Cannot read the post or that feed |
| Group ID in the request that is not one of the author's active groups | Refused for that group (denied, same as "not found"); nothing is posted there |
| Solo reader (no active membership) | No share box; any share request is denied; no group row is ever created |
| Someone else's entry | Cannot be shared: denied, same as "not found" |
| Fourth group | Refused at join (never a fourth box); the share rules never need a limit check |
| Minor, 13–17 (D19, decided 2026-11-05) | Same as any member, per group: posts to the groups they tick, with no guardian step in J-Life (the church's consent process covers it). Minors may belong to up to 3 groups like adults |
| Minor awaiting guardian consent, only where a deployment turns `jlife_challenges_require_guardian_consent` on | Save and point stand, no post to **any** group, the page says so |
| Post hidden by an organizer (D17) | In that group only: not re-posted, not updated, not un-hidden; the page says so; save and point stand. Posts to the author's other groups are unaffected |
| Entry saved **before** joining a group | Stays private; it reaches the new group only if the author ticks that group on a later save (or posts it from `/share`) |
| Member who left or was moved from a group (D18) | Past shares stay with the old group; the person loses that feed; the new group never sees their old shares; they can still remove their own old shares |
| Share failed after a good save | The page says so plainly, naming the groups; the entry and any point are kept |

Unchecking later never removes a post; removal stays with the per-group "Remove from this group" flow on `/share`.

### 4b. Membership rows (#112)

| Case | Result |
|---|---|
| Join with a valid code (signed in) | Adds one active membership for that group; the enrollment (consent, progress, points) is unchanged; audited |
| Join a group the person is already in | Refused (`jlife_already_enrolled`), no duplicate row |
| Fourth active membership in one campaign | Refused (`jlife_group_limit`), for adults, minors and organizer enrollment alike; the first three are untouched |
| Leave a group, then join another | The slot frees up; the left group's shares stay there (D18); audited |
| "Read on my own" (`/join/?mode=solo`) | Creates or keeps the enrollment with **no** membership; consent and age band recorded as for any join |
| Enrollment removed by an organizer | All the person's memberships end with it |
| Feed read or membership list of group G | Only active members of G (through an active enrollment); the gate never answers from `enrollments.group_id` |
| Account deletion, test-campaign removal | Memberships are deleted with the enrollment (no orphan rows) |

### 4c. Start a group (#112 part 2)

An adult can make a group and hold its invite code. Holding the code is the **only** extra right; the starter reads exactly what any member reads. Audit actions: `group_started`, `group_joined` (role `starter`), `invite_reset` (by `starter` or `organizer`), `group_start_refused` (minor or a limit), `group_left`, `group_renamed`, `group_disabled`, `group_enabled`, `share_removed`.

**Pages.** `/join/?mode=start` (new sign-up, adults only: the age is asked and a minor is refused before any email is sent, and again on the stored enrollment); **Start a group** on `/group` for an adult with a free slot (hidden for minors and at 3 groups); on the page after the emailed code (or after the form on `/group`) the code and link are shown once with copy buttons. A reload of that form never makes a second group (a one-use token). **/join notices:** after the code is checked, "already in this group", "3-group limit" and "minors cannot start a group" are shown on a page with **Continue**; the person is signed in either way.

| Case | Result |
|---|---|
| Adult with an active enrollment and a free slot starts a group | One `groups` row (campaign = the open campaign, `started_by` = them) and a `starter` membership that counts toward their 3 groups; the code is shown once; audited (`group_started`, `group_joined`, `invite_reset`) |
| Minor (13–17) starts a group | **Always refused**, on the stored enrollment's age band (a form field cannot override it): `jlife_minor_cannot_start`; no group, no membership; the form is never shown; `/join/?mode=start` refuses a minor before any email is sent; audited as `group_start_refused` |
| Person with no enrollment | Denied, nothing created |
| Third started group in a campaign | Refused (`jlife_start_limit`): at most 2 per person per campaign, counted over every status, so leaving or an organizer disabling the group does not free the count |
| Start while already in 3 groups | Refused (`jlife_group_limit`); no group row is created (no orphan) |
| More than 5 creation attempts in an hour (refused ones count) | Refused (`jlife_start_rate`) |
| Group name | Sanitized (`sanitize_text_field`), 1–60 characters, never a blank; anything else is refused (`jlife_group_name_invalid`) |
| Reset the invite code: the group's own active starter, or an organizer | New code; the old one stops working at once; audited with who did it |
| Reset: another member, a non-member, a starter of a different group, a former starter, anonymous, or a starter of a disabled group | Denied (`jlife_denied`); the code is unchanged |
| Starter reads a member's entry, progress, score counts, or a group they are not in | ❌ denied; the group feed returns only what members shared, the same as for a member |
| Organizer lists, renames, disables or re-enables any group (starter-made included) | ✅ through `wp jlife group list\|rename\|disable\|enable` (no participant-facing path); audited as `group_renamed`, `group_disabled`, `group_enabled`. Listing shows names, status, starter's user ID and active member counts; never codes, hashes, entries or member names |
| Disabled group | Its code stops working; members lose its feed and the slot is freed; past shares stay stored (re-enabling restores them) but are readable by nobody meanwhile. Re-enabling is refused if it would put a member over 3 groups |
| Group name seen by | Its active members and organizers only. Another participant asking for the group by ID gets "Not found or not allowed" with no name; `/join` never prints a group name; the audit log holds IDs |
| Leave a group | Feed access ends and the slot frees; the person's past shares stay with the group (D18) until they remove them from "Groups you left" on `/share`; audited (`group_left`). A starter who leaves stops holding the code (organizers can still reset or disable) |
| Author removes an old share in a group they left (or one an organizer closed) | ✅ only their own; others get denied; audited (`share_removed`) |

### 4d. Daily reading reminder preferences (#63 Phase B, part 1)

A reminder is a message the person asked for. The table holds the choice and the language only: no email address (sending looks it up from the account), no reading content. Sending is §4e; its queue starts from `jlife_challenges_reminder_recipients()`. Audit actions: `reminder_opt_in`, `reminder_opt_out` (detail: language, or `via: link`); never any message text.

| Actor / case | Read the choice | Change the choice | Notes |
|---|---|---|---|
| The participant (active enrollment) | ✅ own | ✅ own, from the join form's unticked box or the "Email me each morning's reading." checkbox on Today (under Save draft) and My Marathon (#135) | Always for the signed-in user: a user ID in a form post or REST body is ignored. The checkbox saves through `POST /jlife/v1/reminder` (signed in, `wp_rest` nonce via cookie auth; `on` and/or `locale`, leaving out either keeps it) or, without JavaScript, the `jlife_reminder` nonce form; both call `jlife_challenges_set_reminder()` |
| Another participant, group member, starter or leader | ❌ | ❌ `jlife_denied` | Nothing about who gets reminders is shown to groups |
| Organizer | ✅ (recipient list, for PR 2's queue and status) | ✅ (audited, e.g. `wp` tooling later) | Never reads what anyone wrote |
| Person with no active enrollment (never joined, left, removed) | ❌ | ❌ `jlife_denied` | An organizer cannot set one for them either; a left or removed person is not a recipient even if a row remains |
| Anonymous with a valid unsubscribe link | ❌ | ✅ **opt out only**, for the person and campaign in the signed link | `/unsubscribe/?c=&u=&t=`, `t` = keyed hash of `unsub\|campaign\|user`, compared with `hash_equals`. GET shows a confirm button and changes nothing (link scanners); the confirm POST and an RFC 8058 `List-Unsubscribe=One-Click` POST opt out |
| Anonymous with a forged, other-user, other-campaign or missing token | ❌ | ❌ | The same neutral page for every failure; nothing changes, nothing is logged (no token in any log) |
| Unsubscribe's reach | | | Only `reminder_prefs`. Sign-in codes, the account, enrollment, consent and scoring are untouched (Brevo has no unsubscribe-link setting for transactional mail; an address on its blocked-contacts list stops getting sign-in codes too, see `docs/staging-latoc.md` → Brevo settings) |
| Account deletion, test-campaign removal | | | The person's rows are deleted with the account / campaign |

Recipients require **recorded consent** (`enrollments.consented`): the join form's box is saved before the `/consent` step, so a ticked box alone never makes someone a recipient.

**My Bible choice (#140).** Which Bible a participant reads in is a low-sensitivity preference, kept as two user-meta values on the participant's own account, not in `reminder_prefs`: `jlife_bible_version` (a registry key, or absent = follow My language) and `jlife_bible_version_2` (an optional second Bible, or absent = none). It holds only registry keys such as `kt1925` or `nirv`: no email address, no reading content, no Bible text. Stored as ordinary user meta like `locale` (network-wide on multisite: the same person reads in the same Bible on every site they belong to). The owner is `plugins/jlife-scripture` (`jlife_scripture_set_user_choice()` writes, `jlife_scripture_versions_for_user()` resolves); `jlife-challenges` only draws the My Marathon picker and the `POST /jlife/v1/bible` route.

| Actor / case | Read the choice | Change the choice | Notes |
|---|---|---|---|
| The participant (active enrollment) | ✅ own, on My Marathon (the picker) and by the reader when it shows their own readings | ✅ own, from the **My Bible** and **Also show…** selects on My Marathon | Always for the signed-in user: a user ID in a form post or REST body is ignored. Saves through `POST /jlife/v1/bible` (signed in, `wp_rest` nonce via cookie auth; `bible_version` and/or `bible_version_2`, leaving one out keeps it, an empty value or `null` clears it) or, without JavaScript, the `jlife_bible` nonce form. No `GET` route exists |
| A key the registry does not list, or lists as not `enabled` | n/a | ❌ refused (`jlife_bible_unknown`, HTTP 400); nothing changes | Validated against the registry (`bible-versions.json`), never against the posted label. A version disabled *after* it was chosen is not an error for the reader: it falls back to the language default, quietly |
| Another participant, group member, starter or leader | ❌ | ❌ `jlife_denied` / not reachable | No screen, share, group feed or REST route shows anyone else's Bible. `jlife_scripture_versions_for_user()` is a server-side helper: callers pass only the viewer's own ID |
| Organizer | ❌ no screen | ❌ no screen or REST path acts for another person | The choice is a preference, not a record: organizers do not need it |
| Site admin | ✅ at host level only (user meta: `wp user meta get`) | ✅ at host level only | No admin screen shows it; the audit log is not used (a preference change is not an event to keep) |
| Person with no active enrollment (never joined, left, removed) | ❌ | ❌ `jlife_denied` (HTTP 403) | The picker is only on My Marathon, which needs an enrollment |
| Signed out, or a cookie request without the `wp_rest` nonce | ❌ | ❌ HTTP 401, nothing changes | Public `/reading/` pages never read a stored choice: they follow the page language (`?lang=`) |
| Logs | | | A save writes no log line and no audit row; an error never names the posted value or a user |
| Account deletion | | | WordPress deletes the user's meta with the account |

*Default until a person picks:* the Bible follows **My language**: the reminder-email language on My Marathon, else the account's `locale`; English → the registry's `default_for: en`, Vietnamese → `default_for: vi`. Once a person picks, the choice stays even if they change the email language.

Consent wording: the consent page says reminders are **optional** ("If you choose daily reminders..."); only a ticked box (at joining, or the checkbox on Today or My Marathon) records an opt-in, with the consent wording version in force.

### 4f. On-page Scripture reader (#139, plugin `jlife-scripture`)

No participant data is stored by the reader. It reads the participant's language (My language: the reminder-email language, else the `locale` user meta, the same rule as `jlife_challenges_language_order()`) and, since #140, their own Bible choice (§4d, "My Bible choice": two registry keys in user meta); a visitor who is not signed in gets the page's locale and the language's default Bible.

| Object | Who reads | Who writes | Notes |
|---|---|---|---|
| Bible text (`jlife_scripture_verses`, network-wide) | Anyone the screen is shown to | Only `wp jlife scripture import` (public-domain datasets, checksum-verified) | Not personal data |
| Passage cache (`jlife_scripture_cache`, network-wide) | The reader, server-side | The reader, one shared row per source + version + passage | Holds licensed text only within each version's cache rule; no user ID, no request data |
| Logs | Host | The cache layer on a failed fetch | Source, version, passage key and a known error code only; never Bible text, API keys or a source's own error message |
| API.Bible source (#141 part 2) | Not a read path: it feeds the same screens above | Server-side only (the reader, `wp jlife scripture try`) | Calls from the server through the client in `jlife-challenges`: the key only in the `api-key` header, never in a URL, log, error or output. **No participant data leaves the site**: a request carries a bibleId and a passage ID; a FUMS report carries a random install ID, a daily session ID and the passage's token, no `uId`, and API.Bible never sees a participant's IP address or account. The shared cache (`jlife_scripture_cache`, network-wide, no user ID) holds licensed text for at most 14 days, never `link_only`/`fallback` results. Logs carry the version key, an outcome code, the passage key and an HTTP status only: never text, the key or a person. NLT, NKJV and CSB are enabled (#141, final part) and run for a participant who chose one, and the NLT is also the English default (owner decision), so English readers and the English public pages show it. The copyright link points to the site's own page in the reader's language (option `jlife_scripture_copyright_page_id`, Polylang `pll_get_post`); no participant data is involved. `wp jlife scripture try` prints one passage to the owner's terminal (CLI only) |
| Public `/reading/<plan>-dNNN/` pages (#139 part 4) | Anyone, signed in or not (a public page) | Nobody (display only) | With `jlife_scripture_native_reader` on, `jlife_studies_render_native_reading()` shows the day's passages from `jlife_scripture_maybe_render_day( $day, 0, array( 'language' => <page language> ) )`: **user 0, and a forced language**, so the reader reads no stored My Bible choice and no account data, and the markup is identical for every visitor (the page may be page-cached; the output varies only by URL). The page language is `?lang=` (`en`/`vi`), else the site language (`get_locale()`, which Polylang-style plugins set); never the visitor's account language and not `determine_locale()`. Default Bible per language from the registry: Kinh Thánh 1925 text for Tiếng Việt, the NLT through API.Bible for English (the Bible Gateway ERV link when the API cannot answer). The optional parallel readings keep their outside links. Off by default: the page is unchanged. The Scripture tables are network-wide (`base_prefix`) and hold no personal data, so the call is an ordinary same-request function call (guarded by `function_exists`): no `switch_to_blog`, no cross-site query, nothing crosses the STUDY/HUB boundary |
| Today and `/soap` (#139 part 3) | The signed-in participant, for their own day | Nobody (display only) | With `jlife_scripture_native_reader` on, `jlife_challenges_render_reader()` shows the day's passages from `jlife_scripture_maybe_render_day( $day, get_current_user_id() )`: the viewer's own My Bible choice (§4d), never a user ID from a request, and no way to see anyone else's. The framed Bible Gateway reader is the flag-off path. The page reads no new participant data; Bible text is not stored with the participant, the entry or the audit log |

### 4e. Daily reminder sends (#63 Phase B, part 2)

The scheduler turns the recipients from §4d into one `reminder_sends` row per person per campaign day and sends from that queue. It runs **only from a WP-Cron event** (every 5 minutes; the host's real cron fires it), never on a page request, and a failure there never touches reading, SOAP entry or scoring.

| Case | Rule |
|---|---|
| Who gets a row | Exactly `jlife_challenges_reminder_recipients()`: opted in, not opted out, active enrollment, consent recorded. Rechecked before every send and retry; anyone who stopped is marked `skipped` (`not_recipient`) |
| When | From 06:00 until before 12:00 **campaign time** (`campaigns.timezone`, DST-correct), on a campaign day 1..N. After noon, or on a later day, a still-queued row becomes `skipped` (`expired`) |
| Language | The recipient's `reminder_prefs.locale` (`vi` or `en_US`); the day's title and references are localized the same way as on Today |
| Content | Day number, the day's title, the passage references, a link to Today, the signed unsubscribe link (also `List-Unsubscribe` + `List-Unsubscribe-Post` headers). **No Bible text, no SOAP content, no sign-in token, no per-user login link, no name** |
| Duplicates | UNIQUE (`campaign_id`, `day_key`, `user_id`) with INSERT IGNORE; each send first claims its row with a compare-and-swap UPDATE (attempt counted, 15-minute lease); a short lock stops overlapping ticks. A tick that dies mid-send is retried later, so a duplicate is possible only in that crash window |
| Retries | `wp_mail` false: retry at +10, +30, +60 minutes, at most 4 attempts, never at or after 12:00; then `failed` (`send_failed`) |
| Error classes | `send_failed`, `configuration_missing` (Brevo enabled but not configured: no fallback to host mail), `no_email`, `no_reading`; skips: `not_recipient`, `expired`, `campaign_inactive`, `dry_run`, `not_test_recipient`. The WP_Error message, which can hold an address or body, is never read or stored |
| Modes (`jlife_reminders_mode`) | `off` (default, does nothing), `dry_run` (queues, builds each message, marks rows `skipped`/`dry_run`, sends nothing), `test` (sends only to account emails listed in the private `JLIFE_REMINDER_TEST_RECIPIENTS` constant; others `skipped`/`not_test_recipient`), `live`. Changed through `wp jlife reminders mode` or the **J-Life → Reminders** admin page (#131); audited as `reminders_mode` (the admin's user ID, or 0 for WP-CLI; no personal data) |
| Reminders admin page (#131) | `manage_options` only (site admin; the mode is a per-site option). The mode changes only on a POST to `admin-post.php` with a valid nonce, then redirects. **Live** needs a ticked confirmation and is refused while `jlife_challenges_reminder_mail_state()` is not `ok` or Brevo is not enabled for the site; **Off** is always allowed. The page shows counts only: no name, no email address (test mode shows the *number* of test addresses), no log rows |
| Who can read the log | Nobody through the product: no screen reads `reminder_sends`. Organizers use `wp jlife reminders status` (counts by status for today, no addresses); the database is host-level access |
| Delivery status | `accepted` means `wp_mail` returned true, not delivery. Delivered/bounced need Brevo webhooks (a later phase) |
| Account deletion | The person's rows are deleted with the account |

## 5. Required tests (written first; CI)

**Calendar:**
- Dec 20 = Day 1, Apr 2 = Day 104, no Day 0 or 105
- the day boundary at 23:59/00:00 Pacific
- the DST change
- a participant in another timezone gets the campaign day

**Scoring:**
- complete on time → 1
- double submit, retry, second device → still 1
- an empty field → no submission and no point
- late → `late_no_point` with a reason
- backfill → no point (D2)
- edits keep the point (D3)

**Privacy (one test per matrix cell above)**, including:
- ID guessing for entries and shares
- cross-group reads
- an unshared SOAP stays author-only
- group move/removal both ways (D18)
- the admin can't read SOAP text or browse feeds

**Shares:**
- the snapshot doesn't change when the SOAP is edited (until the author saves with the box checked, or shares again)
- re-sharing replaces
- removing doesn't change points
- duplicate share requests → one share
- nobody can share someone else's entry

**Lifecycle:**
- account deletion purges entries, revisions and shares, and removes ledger rows; the audit entry has no text
- history readable after Apr 2
- export contains no SOAP text, emails or tokens

**Membership:** an integration test through `jlife_huddles_group_membership` with real membership rows, not fixtures only.

**Multi-group (#112; written first, `test-memberships.php`, `test-multi-group-sharing.php`, jlife-auth `test-join-modes.php`):**
- a member of group A can't read group B's shares or feed
- a non-member (not enrolled, solo, organizer, anonymous) can't read a feed
- a 4th membership is refused, for adults, minors and organizer enrollment
- a solo reader has no share route or share effect, and sees no share box
- sharing to two groups reaches exactly those two
- one unticked box per group, none for a solo reader, no "share with all"
- migration of existing single-group enrollments (idempotent, never resurrects a left membership)
- the test-campaign create/remove command and account deletion clean up memberships
- points are identical for solo, one-group and three-group participants

## 6. Interfaces for the screens (M6/M7)

- **PHP functions:**
  - `jlife_challenges_today( $user_id )` → day, reading (via `jlife_studies_get_reading_day()`), own entry status
  - `…_save_draft()`, `…_submit()`, `…_get_entry()`
  - `…_share( $user_id, $entry_id, $group_id )` and `…_unshare()`; `…_save_and_share( …, $group_ids )`
  - `…_group_feed( $viewer_user_id, $group_id )`
  - `…_active_memberships( $campaign_id, $user_id )`, `…_join_with_code()`, `…_enroll_solo()`, `…_leave_group()`
  - `…_progress( $user_id )`
- **REST (logged-in, nonce-checked)** for autosave and submit, so a dropped connection can retry safely: `POST /jlife/v1/soap/draft`, `POST /jlife/v1/soap/submit`.

## 7. Open points for review

1. **Display names in group feeds:** first name only, or full name? This affects the D15 wording.
2. **Group leaders at launch:** do the 12 groups have designated leaders in J-Life, or are all members equal until P2?
3. **Unscored entries after Day 104:** one per calendar day, or unlimited?
