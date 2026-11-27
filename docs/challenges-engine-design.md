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
| `campaigns` | `id`, `campaign_key` (unique slug), `plan_id` (e.g. `rpl-life-and-teachings-of-jesus-2026`), `title`, `start_date`, `end_date`, `timezone` (`America/Los_Angeles`), `grace_days` (D1: `1`), `status` (`draft`/`active`/`closed`; §3c. An older `finished` reads as `closed`), `scoring_engine` | Day content comes from the reading plan (#59) by `day_id`; nothing copied. `scoring_engine` (default `soap-regex`) names the campaign's scoring engine (#98) |
| `groups` | `id`, `campaign_id`, `name` (1–60 chars, plain text), `invite_code_hash`, `invite_expires_at`, `status` (`active`/`disabled`), `started_by` (user ID of the adult who started it; `0` for organizer-made groups; DB version 4) | Invite codes stored **hashed**; a code is shown once to whoever makes it (an organizer, or the group's starter). A `disabled` group's code stops working and its members lose its feed and slot (§4c). The name is shown only to the group's members and to organizers |
| `enrollments` | `id`, `campaign_id`, `user_id`, `group_id` (**retired**, see below), `status` (`active`/`removed`), `age_band`, `guardian_consent`, `consent_version`, `consented`, `joined`, `left_at`; **UNIQUE (`campaign_id`, `user_id`)** | **One per person per campaign**: consent, age band, progress, entries and points are personal. It says *that* the person takes part, not *which group* (D14 as changed in #112). "Read on my own" is an enrollment with no membership. `group_id` is no longer written (new rows get `0`) or read; see the note under the table |
| `group_members` | `id`, `campaign_id`, `enrollment_id`, `user_id`, `group_id`, `role` (`member`/`starter`), `status` (`active`/`left`/`removed`), `joined_at`, `left_at`; **UNIQUE (`group_id`, `user_id`)** | **A person is solo (no active row) or in up to 3 groups per campaign** (D14 changed, #112), adults and minors alike. The only source of "who is in this group": the gate, the feed and the `jlife_huddles_group_membership` filter read it. Joining, leaving, moving and removal are audited (D18). `starter` is written by "start a group" (#112 part 2, §4c): the one extra right is resetting that group's invite code; it gives no extra view of other members |
| `soap_entries` | `id`, `user_id`, `campaign_id` (0 = unscored/personal), `day_id` (`''` if none), `entry_date` (campaign-local date), `body` (**one** text; #98), `status` (`draft`/`submitted`), `submitted_at`, `revision`, timestamps; **UNIQUE (`user_id`, `campaign_id`, `day_id`)** for campaign entries | Drafts autosave server-side (M6). New entries on a `soap-regex` campaign are prefilled with the S/O/A/P heading template (never Bible text); on a `reflection-v1` campaign the box starts blank with an optional SOAP template button. `submitted` means the entry has passed the engine; an entry that has not passed stays `draft` with its text saved |
| `soap_revisions` | `id`, `entry_id`, `revision`, `body`, `saved_at` | Edit history after submission (D3) |
| `score_ledger` | `id`, `campaign_id`, `user_id`, `day_id`, `entry_id`, `points` (0/1), `status` (`earned`/`late_no_point`/`removed_by_review`), `reason`, `source` (`platform`/`review`), `engine`, `engine_version` (which scoring engine awarded the row; #98), `created_at`; **UNIQUE (`campaign_id`, `user_id`, `day_id`)** | Written once per person and day, append-only: points are never taken back. Review changes (P1) go through an audited path |
| `group_shares` | `id`, `entry_id`, `user_id`, `group_id`, `campaign_id`, `day_id`, `body` (snapshot of the **whole** entry; #98), `status` (`shared`/`removed`/`hidden`), `hidden_reason`, timestamps; **UNIQUE (`entry_id`, `group_id`)** | A **snapshot**: editing the private SOAP never changes it; sharing again replaces it. One row per entry **per group**: a share to group A is a different row from a share to group B and is read only through group A's feed |
| `reminder_prefs` | `id`, `campaign_id`, `user_id`, `opted_in`, `opted_out` (both nullable), `consent_version`, `locale` (`en_US`/`vi`), `updated`; **UNIQUE (`campaign_id`, `user_id`)** | Daily reading reminder choices (#63 Phase B, §4d). No email address in the table; absence of a row means no reminders |
| `reminder_sends` | `id`, `campaign_id`, `day_key`, `user_id`, `status` (`queued`/`accepted`/`failed`/`skipped`/`cancelled`), `attempts`, `next_attempt`, `last_error_class` (nullable), `created`, `updated`; **UNIQUE (`campaign_id`, `day_key`, `user_id`)** | Daily reminder queue and log (#63 Phase B, §4e). Only IDs, a status, counters, times and a fixed class: **no email address, subject, body or raw error**. The UNIQUE key is the duplicate guard. Purged with the account |
| `audit_log` | `id`, `actor_user_id`, `action`, `object_type`, `object_id`, `campaign_id`, `detail` (JSON, **never SOAP text**), `created_at` | Enrollment changes, moves, review actions, hides, exports |

**Group membership (D14 changed, #112).** On 2026-11-18 the owner replaced "one primary group per person" with: a participant is **solo or in up to 3 groups per campaign**, minors too. The `enrollments` row stays one per person per campaign because consent, progress, entries and points are personal; groups only decide *who can read what a person chose to share*. Consequences:

- **Scoring never depends on groups.** Solo, one group and three groups earn the same one point per day from the same ledger row. Organizer counts are per group for reporting only (a person in two groups appears in both rows; a solo reader under group `0`); use the ledger for totals. The score export lists a person's active groups in one cell (`A; B`), one row per participant and day.
- **Limit:** at most `JLIFE_CHALLENGES_MAX_GROUPS` (3) *active* memberships per person per campaign, enforced when a membership is added (code join, organizer enrollment, a re-join) and re-checked after the write so two simultaneous joins cannot exceed it. A fourth is refused with `jlife_group_limit`. Leaving frees a slot.
- **Migration.** The upgrade `jlife_challenges_upgrade_memberships()` (run by the installer on every deploy, idempotent) copies each `enrollments.group_id > 0` into a `group_members` row (active enrollment → active membership; removed enrollment → removed membership with its `left_at`; `joined_at` = the enrollment's `joined`; role `member`). A row is copied only if no membership exists for that person and group *in any status*, so a re-run never resurrects someone who left. The old column is **retired, not dropped**: it stays `NOT NULL DEFAULT 0` for one release as the migration's source and a rollback aid, nothing writes it and nothing but the upgrade reads it (a test pins that the gate ignores it). A later release drops it once every deployment has migrated.
- **Joining later.** A solo reader or a member can enter another code up to the limit (`/group` has the "Have a code? Join a group" box; the emailed-code join does the same for a new sign-in). Entries saved before joining stay private; they reach the new group only if the author ticks that group on a later save or posts from `/share`. Every join and leave is audited (`group_joined`, `group_left`, `member_moved`, `member_removed`); wrong codes are rate limited per person.
- **Sharing.** One unticked checkbox per active group on the SOAP form, no "share with all", none for a solo reader; `/share` has one section per group; `/group` has a picker when there is more than one group. See §4a.
- **Starting a group** (adults only, §4c): an adult names a group and becomes its `starter` member; it uses one of their 3 slots; at most 2 started per person per campaign; the code and link are shown once and the starter can reset the code. The age check reads the *stored* enrollment, never the form.
- **Leaving.** `/group` has a confirmed **Leave this group**; the person keeps their past shares in that group (D18) until they remove them from **Groups you left** on `/share`; the slot frees at once.
- **Organizers** can still enroll, move (naming which group is left when there are several) and remove, and (#112 part 2) list, rename, disable and re-enable any group with `wp jlife group ...` (audited; the CLI list shows names, status, the starter's user ID and active member counts, never codes or member names). Since #64 part 1 the same work, plus creating groups, invite expiry and revoking, is on the **J-Life → Groups** admin screen (§4g), which does show each member's *display name* because an organizer cannot add, move or remove someone they cannot name. They never browse feeds (D17).

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
- **Engines available:** `soap-regex` (the first engine; existing campaigns) and `reflection-v1` (#210; campaigns created from the Nov 29, 2026 pilot on, including the official Marathon). A campaign names exactly one.
- **`soap-regex` (first engine; kept for existing campaigns):** passes when `S`, `O`, `A` and `P` headings appear in that order and each has non-whitespace content beyond its heading. A heading is a line starting with the letter (any case) and then `:` or a dash (`—`, `–`, `-`), optionally followed by the English or Vietnamese template word. A plain hyphen glued to the next word (`A-rôn`, `A-đam`: Vietnamese writes names with hyphens) is text, not a heading, unless that word is the heading word (`S-Scripture`). The untouched template does not pass. Failure reasons are short, kind and translatable (for example "Add something under P — Prayer").
- **`reflection-v1` (#210, owner decision 2026-11-21, [#205 comment 7069225640](https://github.com/i12know/jlife-platform/issues/205#issuecomment-7069225640)):** one rule for free-form reflections and SOAP entries. An entry passes with **at least 20 words of the person's own, at least 8 of them different**. Headings (both languages) and the day's printed questions do not count as the person's words. SOAP is an **optional scaffold**: a "Use SOAP template" button never erases text, and a suggested SOAP-practice day never disqualifies a free-form entry. The engine only counts words: **no AI or semantic assessment**, no outside calls. It is deterministic and versioned; the ledger records `engine` and `engine_version` on every scored row.
- **Policy for any engine:** the rule is **fixed per campaign before Day 1** (`wp jlife campaign set-engine` is refused once Day 1 has started or any entry exists), is published on the Rules page first, and **never re-scores history**. One point per person per campaign per day.
- **Never revokes:** engines decide at submit time only. A future engine that runs later (for example a classifier) can add a quality signal for organizers; it can't remove a point.
- **Out of scope until decided separately:** any engine that sends entries to an AI or other outside service. Entries include prayers and some participants are 13–17, so that needs an updated consent wording, an update to [integration-boundaries.md](integration-boundaries.md), and a data-processing agreement or self-hosting first.

## 3b. The current campaign (#177)

From Dec 14, 2026 a person can be enrolled in two campaigns at once: the Nov 29–Dec 13 pilot, which is ending, and the official Dec 20 Marathon, which is upcoming. Every route must agree on which campaign is "current". **One rule** decides it, in `includes/current-campaign.php`: `jlife_challenges_pick_campaign( $campaigns, $purpose )`. Nothing else may sort campaigns by `start_date` or by the `test-` prefix.

**Phase of a campaign** at a moment, in the campaign's own timezone (`campaigns.timezone`, `America/Los_Angeles` for every J-Life campaign), comparing calendar dates as §3 does:

| Phase | When |
|---|---|
| `draft` | `status = 'draft'`. Never current, never joinable |
| `closed` | `status = 'closed'` (added by #179), or any other status that is not `draft` or `active`. View-only: never joinable |
| `upcoming` | `active`, and today is before `start_date` |
| `running` | `active`, and `start_date` ≤ today ≤ `end_date` |
| `ended` | `active`, and today is after `end_date` |

A day starts at 00:00 campaign time: the pilot is `upcoming` until Nov 28 23:59:59, `running` from Nov 29 00:00 to Dec 13 23:59:59, and `ended` from Dec 14 00:00. The Marathon is `upcoming` until Dec 19 23:59:59 and `running` from Dec 20 00:00.

**Order (first wins):**

1. **Real, running**: the latest `start_date`, then the higher ID.
2. **Real, upcoming**: the **nearest** `start_date`, then the lower ID.
3. **`test-*`, running**: as 1.
4. **`test-*`, upcoming**: as 2.
5. **Ended or closed**: the most recent `end_date`, then real before `test-*`, then the higher ID.

`test-*` campaigns are disposable staging campaigns (§4b, #86). A running or upcoming real campaign always outranks a test one, even a test campaign that is running while the real one has not started yet. A running test campaign still outranks an ended real one. This keeps the earlier rule's guarantee that a staging test campaign never captures real sign-ups (#176, #178), and it means a tester enrolled in both sees the real campaign, as before. A test campaign is chosen only when no real campaign is running or upcoming for that purpose.

**Purposes.** The same order serves both questions; only the candidates differ:

| Purpose | Candidates | Used by |
|---|---|---|
| `join`: which campaign a new enrollment goes into | Every campaign on this site in phase `running` or `upcoming` (so `status = 'active'` and not past `end_date`). The `jlife_challenges_open_campaign` filter can still override the answer | `jlife_challenges_open_campaign()`: "Read on my own", "Start a group" (join form and signed-in), the `jlife-auth` join paths, the organizer screens' default campaign |
| `view`: which campaign a signed-in participant sees | The campaigns of the person's **active** enrollments, in any phase but `draft` | `jlife_challenges_participant_campaign()`: Today, My Marathon, My Group, `/share`, joining another group from My Group, and the reminder target |

**Invite codes** are tied to one group, so a code join lands in that group's campaign. The code works only while that campaign is joinable (`running` or `upcoming`): a code for a group in an ended, closed or draft campaign is "not valid", like any other unknown code. Joining from My Group also needs the code's campaign to be the person's current campaign (unchanged).

**Reminder target.** The person's `view` campaign. The same campaign also anchors the account's Bible choice, Scripture language (§4f) and screen language (#159), so it is not cleared when a campaign closes; refusing reminder changes and sends for a closed campaign is #179.

**The 2026–27 timeline under this rule** (one person enrolled in both, America/Los_Angeles):

| Moment | Current (`view`) | New joins (`join`) |
|---|---|---|
| Nov 28 evening (pilot created) – 23:59 | Pilot (upcoming: "Almost time") | Pilot (nearest start), even if the Marathon is already `active` |
| Nov 29 – Dec 13 | Pilot (running) | Pilot |
| Dec 14 – Dec 19 | Marathon (upcoming: "Almost time") once enrolled in it; otherwise the pilot (ended) | Marathon |
| From Dec 20 | Marathon (running) | Marathon |

Two real campaigns running on the same dates should not happen; if they do, the one that started later wins, which is what the old rule did.

**The pilot campaign and "I'll start Dec 20" (#178).** The pilot is an ordinary campaign: key `pilot-2026-12` (the prefix is `pilot-`, never `test-`, so the staging cleanup cannot touch it), Nov 29–Dec 13, 2026 (15 days), `America/Los_Angeles`, status `active`, the same reading plan as the Marathon. It is created by `wp jlife pilot create` (deploy choice `pilot_campaign`), which is safe to rerun and creates no groups and enrolls no one. The pilot is recognised only by its key prefix; **which campaign is current is still decided only by the rule above.** While the pilot is a person's current campaign and has not ended, Today, My Marathon, My Group and `/share` show the practice banner (the nav items `today`, `marathon` and `group`; not the public reading pages or the sign-out page).

"I'll start Dec 20" is a join mode (`/join/?mode=wait`), offered only while the `join` pick is the pilot. It creates the account as usual (name, age band, language, consent as today) and sets the user meta `jlife_start_official` (UTC time of the choice). It enrolls no one, records no consent and no reminder choice, and the rollover (#180) reads `jlife_challenges_waiting_for_official_ids()` to enroll those people in the official campaign. The flag is not a consent and is not cleared by enrolling in the pilot.

**Not part of this rule:** moving people between campaigns (#180). Closing is §3c.

## 3c. Closing a campaign (#179)

The Nov 29–Dec 13 pilot is frozen on Dec 14 (owner decision D4-A on #174): its private data is kept for 60 days, then purged without deleting accounts (#186). Closing is the freeze.

- **How:** `wp jlife campaign close <id> [--dry-run]`, on the site that holds the campaign, run by the owner. The dry run prints counts only (active enrollments, active groups, entries, ledger rows, live shares, queued reminders) and changes nothing. A real run sets `campaigns.status = 'closed'` and cancels the campaign's queued `reminder_sends` rows (`cancelled`, class `campaign_closed`). It writes one `campaign_closed` audit row with the same counts. Only an `active` campaign can be closed. Running it again changes nothing and writes no second audit row.
- **Writes refused** (`jlife_campaign_closed`, "This reading period has closed. You can still read what you wrote, but it can no longer be changed or shared."), each checked by `jlife_challenges_campaign_accepts_writes()` in `gate.php` **after** the caller's own gate, so an outsider still gets the plain denial:
  - SOAP save draft, submit and save changes (also through save-and-share and the REST routes);
  - creating or re-posting (editing) a share. **Removing one's own share stays allowed** (owner decision 2026-11-20): it only reduces what others see;
  - group joins (code, organizer add, a new enrollment), moves, starts, new groups and invite resets. A code for a closed campaign is "not valid" (§3b);
  - new `score_ledger` rows, refused again inside the ledger writer itself;
  - opting in to reminder emails. Opting out still works, and so does the unsubscribe link.
- **Reminders:** the cron only queues for `active` campaigns, so it skips a closed one. A row queued just before the close is cancelled by the close. If a tick still picks one up, the sender cancels it instead of mailing.
- **Reads unchanged, nothing more visible:**
  - each person still reads their own entries and points;
  - a group's members still read its feed, which is now read-only;
  - non-members, organizers and anonymous visitors are refused exactly as before.
  - The day screen of a closed campaign shows the reading and the person's own text read-only, with the notice above: no form, no share boxes.
  - Organizer actions that only reduce visibility (hiding a share, revoking an invite code, removing a member, a person leaving a group) stay available.
- **Still the anchor:** a closed campaign stays the person's reminder target (§3b) for the account's Bible choice and language settings.
- **Earlier reading (#174, D4-A):** once a closed or ended campaign is no longer the person's current one (for LATOC, from their Marathon enrollment), My Marathon lists it under **Earlier reading**, linking `/marathon/?past=<id>`. That page shows only the person's own entries, read-only, and their own shares that still show text (live, or hidden with text not yet cleared) with **Remove my post**, listed from `group_shares` itself so a share stays removable even if its SOAP was cleared after sharing. Removing is idempotent (no second audit row) and a failed write is an error, never reported as removed. It is offered only for campaigns of the person's active enrollments in phase `ended` or `closed` that are not current; any other ID shows "Not found or not allowed." Nothing there writes entries, shares, points or memberships (`includes/past-campaigns.php`).
- **Not here:** purging after the retention period (#186); moving people to the next campaign (§3d, #180).

## 3d. Rollover, part 1: the official campaign and its enrollments (#180)

The owner wants the official Marathon and its enrollments **prepared and verified by Dec 18**, so Dec 20 is an activation, not a migration (#174). Part 1 creates the campaign and carries enrollments; groups are part 2 (#182); reminder consent is asked again (D3-B modified, #181). Everything is site-scoped, with explicit source and destination IDs (ADR 0001); nothing reads or writes another site or another installation.

**Creating the official campaign.** `wp jlife official create [--key=<key>] [--force]` makes `marathon-2026`: Day 1 = 2026-12-20, Day 104 = 2027-04-02, `America/Los_Angeles`, `active`, the same reading plan as the pilot. Running it again changes nothing; `wp jlife official status` prints its key, dates, phase, counts, and which campaign new sign-ups join right now. A key starting with `test-` or `pilot-` is refused.
- **Timing risk.** Under §3b an `active` campaign that is upcoming takes new sign-ups whenever nothing nearer is open. With the pilot on the site (upcoming, running, ended or closed) that is harmless: new sign-ups join the pilot until Dec 13 and the Marathon from Dec 14, which is the plan. With **no** pilot campaign, creating the Marathon early sends every new sign-up straight to Dec 20 and hides "I'll start Dec 20". So `create` refuses (`jlife_official_no_pilot`) unless a non-draft `pilot-` campaign exists, or `--force` is given for a site that deliberately has no pilot.

**The rollover.** `wp jlife campaign rollover --from=<source id> --to=<destination id> --preview|--execute|--verify`, run by the owner (as an organizer) on the site that holds both campaigns, normally after the pilot is closed (§3c) and before Dec 18. Output, the audit row and logs are **counts only**, plus the two campaigns' keys and phases so the operator can see they picked the right ones.

| Who carries over | How |
|---|---|
| Every person with an **active** enrollment in the source | Same WordPress user ID and `age_band` as the source enrollment. `consent_version` and `consented` as recorded on the source enrollment; if that is empty, as recorded on the account (`jlife_consent_version`, `jlife_consent_at`, written by the consent step) |
| Every person on this site who chose **"I'll start Dec 20"** (#178) | The age band they gave when choosing (stored per site with the choice, `jlife_start_official_band` as a user option). Consent as recorded on the account. A choice recorded before this change, or on another site, has no band here: skipped and counted (`skipped_wait_details_missing`); they can still join from `/join` |
| Someone whose source enrollment was removed or left | Not carried (`skipped_left_source`), even if they also chose to wait |
| Someone already enrolled in the destination (e.g. joined directly after Dec 14) | Left exactly as they are (`already_enrolled`) |
| Someone removed from the destination | Not put back (`skipped_removed_in_destination`) |

- **What never carries over:** SOAP entries and revisions, `score_ledger` rows, shares, group memberships and groups (#182), reminder choices and queued sends (#181: consent is asked again), and guardian consent (`guardian_consent` is per campaign and recorded again by an organizer where the D19 option is on). The new enrollment is solo, with `joined` = the run time. Official points start at 0.
- **Fails closed** (nothing written) when: the actor is not an organizer on this site; either ID is not a campaign on this site (`jlife_rollover_not_found`); the IDs are equal (`jlife_rollover_same`); the source is a `test-` campaign or a draft (`jlife_rollover_source_invalid`); the destination's key starts with `test-` or `pilot-`, it is not `active`, or it is not still upcoming (`jlife_rollover_destination_invalid`); the destination already has SOAP entries, ledger rows or shares (`jlife_rollover_destination_not_empty`). Preview and execute both check all of these.
- **Safe to rerun:** each enrollment is one `INSERT IGNORE` on the UNIQUE (`campaign_id`, `user_id`) key, so a second run adds nothing, two runs at once cannot duplicate, and a run that stops part-way is finished by running it again. Each execute writes one `campaign_rollover` audit row (counts and a source snapshot, no user IDs). The source campaign is only read.
- **Verify** recomputes the expected people and reports: `expected`, `enrolled_once` (expected people with exactly one active destination enrollment), `missing`, `duplicate_enrollments`, `destination_enrollments`, destination ledger rows, entries and shares (must be 0), `executed`, `source_changed_by_run` (the source's row counts before and after the last execute differ, which would mean the rollover touched it) and `source_changed_since_run` (the source changed after the last execute, e.g. someone removed a pilot post under Earlier reading: reported, not a failure). It passes only when an execute has run, `missing`, duplicates, the three destination counts and `source_changed_by_run` are all 0, and exits non-zero otherwise.
- **After the rollover:** the official campaign is each carried person's current campaign (§3b: upcoming beats ended or closed), so Today shows "Almost time" from Dec 14 and Day 1 on Dec 20, and the closed pilot moves to My Marathon → Earlier reading (§3c).

**Part 2: official groups, with organizer review (#182, D2-A).** Groups, memberships and invite codes belong to one campaign, so pilot groups are never repointed: new official groups are made from a plan the organizer reviews. `wp jlife campaign rollover-groups --from=<source id> --to=<destination id> --plan=<file>` with one of:

- `--preview` writes the **plan file** (refused if the file exists, so an edited plan is never overwritten). It is a CSV, mode 0600, never printed: a first line `# jlife rollover-groups plan; from=<id>; to=<id>`, a header `group_key,group_name,role,user_id,age_band,name_for_review`, then one row per active membership of each **active** source group (a disabled group, someone who left, and solo readers are not listed). `group_key` is a stable plan key (`g<source group id>`; an organizer adds new ones such as `new-1`). `age_band` and `name_for_review` are for reading only; the destination enrollment's age band is what counts. The display name (an email-like name shows as `Participant #ID`) is there because reviewing user IDs alone is not practical; the file never leaves the server. Cells that start with `= + - @` get a leading `'`, as in the score CSV (§4h).
- **Editing**, in a spreadsheet or text editor: rename (change `group_name` on all of a group's rows), merge (give one group's rows the other's `group_key` and name), drop (delete a group's rows), move a person (change their row's `group_key` and name), remove a person (delete their row). Keys must not change after an execute.
- `--validate` reads the plan and writes nothing. It reports counts and error classes only: `bad_row`, `unknown_role` (only `member` or `starter`), `group_name_invalid` (§4c rules), `group_name_mismatch` (one key, two names; names are never assumed unique), `duplicate_membership`, `minor_starter` (by the destination enrollment's age band), `group_limit` (more than 3 groups for one person, counting their other destination groups) and `start_limit` (starter of more than 2). People without an active destination enrollment (not carried by part 1, or removed since) are counted as `not_enrolled`, never added and not an error.
- `--execute` refuses a plan with any error class, then, for each plan group: finds the group it made before (by plan key) or makes a new one with `jlife_challenges_create_group()` (audited; a new hashed invite code valid until the campaign's last day), records `started_by` for the first starter, and adds each enrolled person with `jlife_challenges_add_membership()` (audited, source `rollover`, limits re-checked). Each new group's code goes only into `<plan>.codes.csv` next to the plan (mode 0600, never printed); a lost code is replaced in J-Life → Groups. One `campaign_rollover_groups` audit row per run, counts only.
- `--verify` compares the plan with the database: groups and memberships found, missing, with a different role, members not in the plan, active destination groups made outside the plan, plus `plan_errors`. It passes when nothing planned is missing or has another role and the plan has no errors.
- **Rerun and recovery:** the plan key → group ID map is kept in the option `jlife_rollover_groups_<destination id>` and written right after each group is made; memberships are skipped when already active. A second execute adds nothing; an interrupted one is finished by running it again. A crash in the instant between making a group and recording it would leave one empty group; `--verify` counts it under `unplanned_groups`, and an organizer disables it.
- **Never:** shares, entries, points or the source's groups, memberships or codes change; a source code never opens a destination group (separate groups; and a closed source's codes open nothing, §3c).

## 4. Privacy matrix: new rows for the engine

Extends the [S5 matrix](spikes/S5-privacy-model.md) with the M5 objects. S = SOAP entry and its revisions; L-self = own ledger detail; L-counts = per-day/group counts; GS = group share; X = score export (CSV, no SOAP text, no email, no tokens). Site-admin reads apply only to a **confirmed** group (#48, fixed in #68).

**Future rows, not built (owner-approved capability, 2026-11-21; #213, #214).** Participant devotional contributions (an object "DC": offer, select, publish, revoke, anonymous read) and public comments on `/reading/` need their own rows and per-cell tests **before any code**, including how an organizer may read offers to pick one (the site-admin cells above say no today). A DC is a separate consented copy, never a view of the private entry (S) or of a group share (GS).

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

**Help, Rules and Privacy pages (#65).** Ordinary public WordPress pages that the owner writes in wp-admin, seeded once as drafts by `wp jlife help-pages seed`. They read and write **no participant data**: the only stored values are three page IDs in site options (`jlife_challenges_help_page_id`, `jlife_challenges_rules_page_id`, `jlife_challenges_privacy_page_id`). The footer on the participant screens, the consent step's Privacy link and the SOAP help link resolve a page in the reader's language and are left out unless it is published, so they add no new access path and need no matrix row of their own.

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
| Join with a code whose group's campaign has ended, is closed or is a draft (#177, §3b) | Refused as an unknown code (`jlife_invite_invalid`, counts toward the hourly limit); no enrollment or membership is written. The code itself is unchanged |
| Any membership change in a **closed** campaign (#179, §3c): code join, organizer add or move, start, new group, invite reset | Refused (`jlife_campaign_closed`; a code is "not valid"); nothing is written. Leaving, organizer removal and invite revoke still work. Feeds stay readable by their active members only |
| "Read on my own" or "Start a group" while two campaigns are active (#177) | Joins the §3b `join` campaign only: running before upcoming (nearest start), real before `test-*`; never an ended, closed or draft campaign |
| Fourth active membership in one campaign | Refused (`jlife_group_limit`), for adults, minors and organizer enrollment alike; the first three are untouched |
| Leave a group, then join another | The slot frees up; the left group's shares stay there (D18); audited |
| "Read on my own" (`/join/?mode=solo`) | Creates or keeps the enrollment with **no** membership; consent and age band recorded as for any join |
| Enrollment removed by an organizer | All the person's memberships end with it |
| Feed read or membership list of group G | Only active members of G (through an active enrollment); the gate never answers from `enrollments.group_id` |
| Account deletion, test-campaign removal | Memberships are deleted with the enrollment (no orphan rows) |
| Rollover, part 2 (#182, §3d): an organizer turns a reviewed plan into destination groups and memberships | Only enrolled destination participants are added, through the normal membership checks (3 groups, adults-only starters, active group and enrollment); new codes go only to a 0600 file; the plan file (names, user IDs) is 0600 and never printed; output and audit are counts and classes only. Source groups, codes, shares and entries are untouched, and source codes never open a destination group |
| Rollover, part 1 (#180, §3d): an organizer carries source enrollments and "I'll start Dec 20" choices into an upcoming destination on the same site | Writes only new destination enrollments (same user, age band and consent record; no group, no guardian consent, no reminder row); never reads or writes another site; never touches source rows; refused for non-organizers and for a test, draft, started or non-empty campaign. Output and audit are counts only |

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
| Organizer lists, renames, disables or re-enables any group (starter-made included) | ✅ through `wp jlife group list\|rename\|disable\|enable`, and (since #64) rename and (since #158) disable/enable on **J-Life → Groups** (§4g); no participant-facing path; audited as `group_renamed`, `group_disabled`, `group_enabled`. Listing shows names, status, starter's user ID and active member counts; never codes, hashes, entries or member names |
| Disabled group | Its code stops working; members lose its feed and the slot is freed; past shares stay stored (re-enabling restores them) but are readable by nobody meanwhile. Re-enabling is refused if it would put a member over 3 groups |
| Group name seen by | Its active members and organizers only. Another participant asking for the group by ID gets "Not found or not allowed" with no name; `/join` never prints a group name; the audit log holds IDs |
| Leave a group | Feed access ends and the slot frees; the person's past shares stay with the group (D18) until they remove them from "Groups you left" on `/share`; audited (`group_left`). A starter who leaves stops holding the code (organizers can still reset or disable) |
| Author removes an old share in a group they left (or one an organizer closed) | ✅ only their own; others get denied; audited (`share_removed`) |
| Earlier reading page (`/marathon/?past=<id>`, §3c): a person's own entries and shares in an ended or closed campaign that is not their current one | ✅ the person only: their own entries (read-only) and their own live or hidden shares, which they may remove (audited `share_removed`, also in a closed campaign). Anyone else, another campaign ID, or the current campaign: "Not found or not allowed."; nobody else's entry, share or name is shown |

### 4d. Daily reading reminder preferences (#63 Phase B, part 1)

A reminder is a message the person asked for. The table holds the choice and the language only: no email address (sending looks it up from the account), no reading content. Sending is §4e; its queue starts from `jlife_challenges_reminder_recipients()`. Audit actions: `reminder_opt_in`, `reminder_opt_out` (detail: language, or `via: link`); never any message text.

| Actor / case | Read the choice | Change the choice | Notes |
|---|---|---|---|
| The participant (active enrollment) | ✅ own | ✅ own, from the join form's unticked box or the "Email me each morning's reading." checkbox on Today (under Save draft) and My Marathon (#135) | Always for the signed-in user: a user ID in a form post or REST body is ignored. The checkbox saves through `POST /jlife/v1/reminder` (signed in, `wp_rest` nonce via cookie auth; `on`, leaving it out keeps it; since #159 a `locale` in the body is ignored) or, without JavaScript, the `jlife_reminder` nonce form; both call `jlife_challenges_set_reminder()`. The row's `locale` is a copy of the account's (see "Language setting" below) |
| The participant, in a **closed** campaign (#179, §3c) | ✅ own | Opt out only; opting in is refused (`jlife_campaign_closed`) | Nothing is sent for a closed campaign: its queued rows are cancelled |
| Another participant, group member, starter or leader | ❌ | ❌ `jlife_denied` | Nothing about who gets reminders is shown to groups |
| Organizer | ✅ (recipient list, for PR 2's queue and status) | ✅ (audited, e.g. `wp` tooling later) | Never reads what anyone wrote |
| Person with no active enrollment (never joined, left, removed) | ❌ | ❌ `jlife_denied` | An organizer cannot set one for them either; a left or removed person is not a recipient even if a row remains |
| Anonymous with a valid unsubscribe link | ❌ | ✅ **opt out only**, for the person and campaign in the signed link | `/unsubscribe/?c=&u=&t=`, `t` = keyed hash of `unsub\|campaign\|user`, compared with `hash_equals`. GET shows a confirm button and changes nothing (link scanners); the confirm POST and an RFC 8058 `List-Unsubscribe=One-Click` POST opt out |
| Anonymous with a forged, other-user, other-campaign or missing token | ❌ | ❌ | The same neutral page for every failure; nothing changes, nothing is logged (no token in any log) |
| Unsubscribe's reach | | | Only `reminder_prefs`. Sign-in codes, the account, enrollment, consent and scoring are untouched (Brevo has no unsubscribe-link setting for transactional mail; an address on its blocked-contacts list stops getting sign-in codes too, see `docs/staging-latoc.md` → Brevo settings) |
| Account deletion, test-campaign removal | | | The person's rows are deleted with the account / campaign |

Recipients require **recorded consent** (`enrollments.consented`): the join form's box is saved before the `/consent` step, so a ticked box alone never makes someone a recipient.

**The opt-in is offered only in saved Live mode (#189, part 1).** The source of truth is the site's saved sending mode (`jlife_reminders_mode`, saved on J-Life → Reminders or by `wp jlife reminders mode`; `jlife_challenges_reminder_optin_offered()`). Ticking Live's confirmation without saving changes nothing.

| Saved mode | Today and My Marathon checkbox | Join form box (`/join`, every way in) | New opt-in accepted | Opt-out (checkbox "off", unsubscribe link) |
|---|---|---|---|---|
| Off (default), Dry run, Test | Hidden (My Marathon keeps Language and My Bible; that section is headed "Bible") | Hidden | ❌ REST `on: true` → 403 `jlife_reminders_not_offered`; a form "on" or a crafted join field saves nothing | ✅ |
| Live | Shown | Shown (unticked) | ✅ | ✅ |

- Hiding changes no data: existing rows (on or off) stay exactly as they are, nobody is turned on, and no consent or consent version is recorded. Switching modes (Off → Live → Off → Live) keeps every campaign-specific choice.
- An absent field is no change: a Language or My Bible save never touches the reminder choice, and `POST /jlife/v1/reminder` without `on` only answers the saved state (it no longer rewrites the row, which used to refresh its consent version).
- A join code requested while Live carries the ticked box; completing it after a switch away from Live still records that choice (it was made while offered).
- The consent page has no opt-in control; its "If you choose daily reminders…" line is versioned wording and is unchanged.
- **#181 coordination:** the Dec 14–19 official-Marathon consent offer must follow this rule: it shows only while the saved mode is Live (the plan is that the owner has reminders Live by then). It must not be shown in Off, Dry run or Test to satisfy #181's older wording, and Live must not be turned on early just to collect consent. Implemented as below.

**Official-Marathon reminder choice during Dec 14–19 onboarding (#181, owner decision D3-B modified).** The choice is made for the official campaign ahead of Day 1; nothing is carried from the pilot.

- *Which campaign:* the reminder target is the person's current campaign (§3b, unchanged). After the pilot ends (Dec 14) and the rollover (§3d) has enrolled them, that is the upcoming official campaign.
- *When it shows:* while that campaign is **upcoming or running** and the saved mode is **Live** (`jlife_challenges_reminder_choice_open()`). An ended or closed campaign (the pilot after Dec 13) offers no choice and accepts no "on".
- *Where:* on Today under "Almost time" (`jlife_challenges_today_no_day_body()`) and on My Marathon. Before Day 1 of a non-pilot campaign it reads "Email me each morning's Marathon reading starting <start date>?" (the campaign's own date); from Day 1 it is the everyday "Email me each morning's reading." (late joiners and anyone who has not chosen).
- *What is stored:* only a tick or untick writes the official campaign's own `reminder_prefs` row, with the consent wording version in force and the account language (#159). The pilot's rows are never read, copied or changed; a pilot opt-in or opt-out pre-ticks nothing (no official row means unticked).
- *No early or duplicate sends:* unchanged sender guarantees. A campaign is queued only on a day 1–N inside its 06:00–12:00 window, so nothing goes out before Dec 20. The UNIQUE (`campaign_id`, `day_key`, `user_id`) key makes Day 1 one row per person. The pilot (closed, or past its end) queues nothing, and a row left queued there is cancelled (`campaign_closed`), so nobody gets a pilot and an official email.

| Actor / case | See the choice | Make the choice | Notes |
|---|---|---|---|
| Enrolled in the upcoming official campaign, saved mode Live | ✅ own, unticked unless they chose for this campaign | ✅ own, recorded against the official campaign | `POST /jlife/v1/reminder` or the `jlife_reminder` nonce form; a user ID or campaign ID in the request is ignored |
| Same, mode Off, Dry run or Test | ❌ | ❌ "on" refused (`jlife_reminders_not_offered`); "off" works | §4d, #189 |
| Only in the closed or ended pilot (not carried) | ❌ | ❌ "on" refused for the closed campaign | Their unsubscribe link and an "off" still work |
| Another participant, group member, starter or leader | ❌ | ❌ | Unchanged |
| Logs | | | `reminder_opt_in` / `reminder_opt_out` audit rows as for every choice; no email address or text |

**Consent record on a later join (#181, from PR #197 decision 4).** `jlife_challenges_open_enrollment()`, the one place every join goes through (invite code, Read on my own, Start a group, organizer add), records the account's existing participation-consent record (`jlife_consent_version` / `jlife_consent_at`) on a new or reactivated enrollment, through `jlife_challenges_rollover_consent()`, exactly as the rollover does. A re-joined enrollment keeps its own record. An account without a record (no `jlife_consent_at`) gets none: the person is asked at sign-in as before, and nothing is invented. Without this, someone who agreed once and then joined the Marathon directly after Dec 14 could never be a reminder recipient there.

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

*Default until a person picks:* the Bible follows **My language**: since #159 the account's `locale` (the Language setting below); English → the registry's `default_for: en`, Vietnamese → `default_for: vi`. Once a person picks, the choice stays even if they change the language.

**Language setting (#159).** One **Language** select on My Marathon (English / Tiếng Việt) replaces "Language of the email". The one source of truth is the account's `locale` user meta, which the screens already read (`jlife_auth_front_locale()`). Saving it (`jlife_challenges_set_language()`, `includes/language.php`) writes `locale` and copies the same code onto every `reminder_prefs` row of the person (only `locale` and `updated` change; on/off and consent stay), so the sender is unchanged; the default Bible follows `locale` through jlife-scripture's own answer (the `jlife_scripture_user_language` filter is no longer hooked). Audit action: `language_change` (object `account`, detail: `from` and `locale` codes only). Existing accounts whose saved email language differs from their screen language keep that email language until they next save the setting; the setting shows the screen language (owner confirmed on #159, 2026-11-20).

| Actor / case | Read | Change | Notes |
|---|---|---|---|
| The participant (active enrollment) | ✅ own, on My Marathon | ✅ own | Always for the signed-in user: a user ID in a form post or REST body is ignored. Saves through `POST /jlife/v1/language` (signed in, `wp_rest` nonce via cookie auth; `locale` = `en_US` or `vi`, anything else HTTP 400) or, without JavaScript, the `jlife_language` nonce form. With JavaScript the page then reloads in the new language |
| Another participant, organizer, group member | ❌ | ❌ `jlife_denied` | No screen or REST path acts for another person; organizers cannot change it either |
| Person with no active enrollment | ❌ | ❌ `jlife_denied` (HTTP 403) | The setting is only on My Marathon |
| Signed out, or a cookie request without the `wp_rest` nonce | ❌ | ❌ HTTP 401, nothing changes | Signed-out pages keep the site language or `?lang=` on /join, /signin, /consent and /reading |
| Logs | | | One audit row per change; no log line, no email address or name |

Consent wording: the consent page says reminders are **optional** ("If you choose daily reminders..."); only a ticked box (at joining, or the checkbox on Today or My Marathon) records an opt-in, with the consent wording version in force.

### 4f. On-page Scripture reader (#139, plugin `jlife-scripture`)

No participant data is stored by the reader. It reads the participant's language (My language: the `locale` user meta, the same rule as `jlife_challenges_language_order()`; since #159 the Language setting on My Marathon changes it) and, since #140, their own Bible choice (§4d, "My Bible choice": two registry keys in user meta); a visitor who is not signed in gets the page's locale and the language's default Bible.

| Object | Who reads | Who writes | Notes |
|---|---|---|---|
| Bible text (`jlife_scripture_verses`, network-wide) | Anyone the screen is shown to | Only `wp jlife scripture import` (public-domain datasets, checksum-verified) | Not personal data |
| Passage cache (`jlife_scripture_cache`, network-wide) | The reader, server-side | The reader, one shared row per source + version + passage | Holds licensed text only within each version's cache rule; no user ID, no request data |
| Logs | Host | The cache layer on a failed fetch | Source, version, passage key and a known error code only; never Bible text, API keys or a source's own error message |
| API.Bible source (#141 part 2) | Not a read path: it feeds the same screens above | Server-side only (the reader, `wp jlife scripture try`) | Calls from the server through the client in `jlife-challenges`: the key only in the `api-key` header, never in a URL, log, error or output. **No participant data leaves the site**: a request carries a bibleId and a passage ID; a FUMS report carries a random install ID, a daily session ID and the passage's token, no `uId`, and API.Bible never sees a participant's IP address or account. The shared cache (`jlife_scripture_cache`, network-wide, no user ID) holds licensed text for at most 14 days, never `link_only`/`fallback` results. Logs carry the version key, an outcome code, the passage key and an HTTP status only: never text, the key or a person. NLT, NKJV and CSB are enabled (#141, final part) and run for a participant who chose one, and the NLT is also the English default (owner decision), so English readers and the English public pages show it. The copyright link points to the site's own page in the reader's language (option `jlife_scripture_copyright_page_id`, Polylang `pll_get_post`); no participant data is involved. `wp jlife scripture try` prints one passage to the owner's terminal (CLI only) |
| Public `/reading/<plan>-dNNN/` pages (#139 part 4, #106) | Anyone, signed in or not (a public page: a signed-out Today) | Nobody (display only) | `jlife_challenges_public_reading_page_html()` (`includes/reading-page.php`) builds the page from the reading-day post alone, in Today's standalone shell (`screens.css`, noindex, no navigation, sign-out or theme chrome), with the same functions as Today and `/soap` (`jlife_challenges_render_reading_head()`, `_render_reader()`, `_render_optional_readings()`, `_render_harmony_sections()`). **Who is looking changes one thing only:** a signed-out visitor gets links to `/join/` and `/signin/` (no code or personal data in the URL); a signed-in person gets one link to `/soap/?day=dNNN`. Nothing else is read about them, and apart from that invitation the page is byte-for-byte the same for everyone: **no SOAP text, points, status, group names, names or email, no nonce, no form** (a test compares signed-in and signed-out output and searches for each). **No frame:** no iframe, no `data-src`, no sandbox; Bible Gateway is only ever a link that opens in a new tab (`docs/content-rights.md`), and framing waits for Bible Gateway's written permission or the YouVersion display (#78). It needs no JavaScript. With `jlife_scripture_native_reader` on, the day's passages come from `jlife_scripture_maybe_render_day( $day, 0, array( 'language' => <page language> ) )`: **user 0 and a forced language**, so the reader reads no stored My Bible choice, under the English \| Tiếng Việt `?lang=` switch (plain links); its copyright lines and the "Full copyright information" link are the reader's own. Off, or for a version the reader cannot show, the page lists one plain Bible Gateway link per language that has one. The page language is `?lang=` (`en`/`vi`), else a signed-in person's account `locale`, else the site language (`get_locale()`); a signed-in view is sent `no-store`. Default Bible per language from the registry: Kinh Thánh 1925 text for Tiếng Việt, the NLT through API.Bible for English (the Bible Gateway ERV link when the API cannot answer). The optional parallel readings keep their outside links. The Scripture tables are network-wide (`base_prefix`) and hold no personal data, so the call is an ordinary same-request function call (guarded by `function_exists`): no `switch_to_blog`, no cross-site query, nothing crosses the STUDY/HUB boundary |
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
| Error classes | `send_failed`, `configuration_missing` (Brevo enabled but not configured: no fallback to host mail), `no_email`, `no_reading`; skips: `not_recipient`, `expired`, `campaign_inactive`, `dry_run`, `not_test_recipient`; cancelled: `campaign_closed` (§3c). The WP_Error message, which can hold an address or body, is never read or stored |
| Modes (`jlife_reminders_mode`) | `off` (default, does nothing), `dry_run` (queues, builds each message, marks rows `skipped`/`dry_run`, sends nothing), `test` (sends only to account emails listed in the private `JLIFE_REMINDER_TEST_RECIPIENTS` constant; others `skipped`/`not_test_recipient`), `live`. Changed through `wp jlife reminders mode` or the **J-Life → Reminders** admin page (#131); audited as `reminders_mode` (the admin's user ID, or 0 for WP-CLI; no personal data) |
| Reminders admin page (#131) | `manage_options` only (site admin; the mode is a per-site option). The mode changes only on a POST to `admin-post.php` with a valid nonce, then redirects. **Live** needs a ticked confirmation and is refused while `jlife_challenges_reminder_mail_state()` is not `ok` or Brevo is not enabled for the site; **Off** is always allowed. The status panel shows counts only (test mode shows the *number* of test addresses). Below it, the **Who gets reminders** section (#268) is the recipients listing (next row) |
| Who can read the log | Only the Who gets reminders section (#268, `manage_options` only) reads today's `reminder_sends` status per person; nothing else shows the log. Organizers use `wp jlife reminders status` (counts by status for today, no addresses); the database is host-level access |
| Recipients listing (`wp jlife reminders recipients`, and **J-Life → Reminders → Who gets reminders**, #268) | Shell access (a server admin running WP-CLI), or the wp-admin section for `manage_options` only: an editor, author, group starter, participant or signed-out visitor gets nothing, including through a forged POST or GET. The screen shows 50 rows per section per page with previous/next links, one block per active campaign, times in the campaign time zone, and an email search that is a **POST with a nonce** (the address never reaches a URL or log, is never echoed back, and a bad or missing nonce gives no result) plus a partial-name search (GET). It adds nothing to the data: it calls the same report as the CLI. Read-only (writes and sends nothing). Prints IDs, display names, reminder language, opt-in/opt-out dates (UTC) and today's send status or reason, in three sections: will be emailed, opted in but not emailed (`enrollment not active` / `consent not accepted`), unsubscribed. **No email address** (a display name that looks like one is replaced), no reflection text, no group name, no score. `--email=<address>` finds one person by address and shows only their rows without printing the address |
| Delivery status | `accepted` means `wp_mail` returned true, not delivery. Delivered/bounced need Brevo webhooks (a later phase) |
| Account deletion | The person's rows are deleted with the account |

### 4g. Groups admin screen (M9 part 1, #64)

**J-Life → Groups** (`wp-admin/admin.php?page=jlife-groups`) is the organizer's plain WordPress screen for the roughly 12 pilot groups. There is no separate "organizer" role: an organizer is anyone with `manage_options` on this site (`jlife_challenges_is_organizer()`), so "their scope" is this site's campaigns and a site admin gets the same. The screen never reads or prints SOAP text, shares, email addresses, invite hashes or session tokens; it shows display names only (a name that looks like an email address is replaced by `Participant #ID`). Audit actions: `group_created`, `group_renamed`, `invite_reset`, `invite_revoked`, `group_joined` (source `organizer`), `member_moved`, `group_member_removed`, and (#158) `group_disabled` / `group_enabled`.

| Actor / case | Open the screen (menu and page) | Create / rename a group | Show, regenerate or revoke an invite | Member list (display names) | Add / move / remove a member |
|---|---|---|---|---|---|
| Site admin or organizer (`manage_options`) | ✅ | ✅ audited | ✅ the code is shown **once**, on the page that regenerates it (never stored in clear); afterwards only the state (none, active until a date, expired) | ✅ names and role only | ✅ audited, each through a nonce-checked POST |
| Participant, group member, group starter, leader, editor, subscriber | ❌ no menu entry; the page answers 403 | ❌ | ❌ (a starter's own reset stays on `/group`, §4c) | ❌ | ❌ |
| Anonymous | ❌ (sent to sign in) | ❌ | ❌ | ❌ | ❌ |
| A POST with no nonce, a nonce for another action or another group, or a GET | n/a | ❌ nothing changes | ❌ | n/a | ❌ nothing changes |

Rules the tests pin down:

- **IDs.** The group and campaign come from the organizer's own list; a group ID the site does not know is "Not found or not allowed". The participant in an add, move or remove must hold an **active enrollment** in that group's campaign (the screen never enrolls anyone: consent comes from sign-up), and a move's two groups must be in the same campaign. Each write's nonce is bound to its action and the group ID.
- **Invites.** Expiry is chosen from 7, 14, 30, 60 or 90 days. Regenerating replaces the old code at once; revoking leaves the group with no working code. Neither changes any member. A disabled group's code never works. The hash is never printed. The link is `/join/?code=CODE`: the code is the only parameter, with no sign-in token, session, nonce or user ID.
- **Remove from this group** ends that one membership (status `removed`); the enrollment, entries, points and other groups are untouched. **Remove from the Marathon** stays a separate organizer action (`jlife_challenges_remove_member()`) and is not on this screen. A person removed from a group can rejoin it with a valid code, so regenerate or revoke the code first when that is not wanted.
- **D18, both directions (move and remove).** Past shares stay with the old group; the person loses the old feed at once; the new group never sees anything shared to the old one; the old group never sees what the person shares afterwards; the person can still see and remove their own old shares ("Groups you left" on `/share`); no one else's private entries are reachable from either feed. Organizers still cannot read any feed (D17).
- **Disable / Enable (#158).** A group's detail view shows only the button that applies. Both call the CLI's functions (`jlife_challenges_disable_group()` / `_enable_group()`), so the behavior and the audit row are the same as `wp jlife group disable|enable`. **Disable** has a one-line explanation and a required "I understand" box (works without JavaScript; the server checks the box again and changes nothing without it): the code stops working, nobody new can be added, members lose the feed, nothing is deleted. **Enable** needs no confirmation and restores the group; it is refused, with its own message, when a member would go over the group limit. In a **closed campaign** (#179) both work as the functions allow: disabling only reduces what members see, and enabling gives members back read access to what was already shared, while joining by code and sharing stay closed by the campaign gate.
- **Scores.** Add, move, remove, rename, regenerate, revoke, disable and enable never write or change a `score_ledger` row, an enrollment's status or consent, or the export; points, eligibility and prize standing are individual (principle 6).

### 4h. Organizer daily view and CSV export (M9 part 2, #64)

Two organizer surfaces: **J-Life → Daily view** (`admin.php?page=jlife-daily`) and a **CSV download** (a nonce-checked POST to `admin-post.php?action=jlife_challenges_csv`, offered on that page). Both need `manage_options`; as in §4g an organizer and a site admin are the same thing, and "their scope" is this site's campaigns. Neither surface reads or prints SOAP text, share text, email addresses, invite codes or hashes, session tokens or nonces inside the data.

| Actor / case | Open the Daily view | Per-participant status and point | Per-group counts | Download the CSV | SOAP or share text on either surface |
|---|---|---|---|---|---|
| Site admin or organizer (`manage_options`) | ✅ | ✅ display name, group names, status, point | ✅ counts only | ✅ POST with a valid nonce for that campaign and day; audited | ❌ never |
| Participant, group member, group starter, leader, editor, subscriber | ❌ no menu entry; the page answers 403 | ❌ | ❌ | ❌ 403 | ❌ |
| Anonymous | ❌ | ❌ | ❌ | ❌ | ❌ |
| A download with no nonce, a nonce for another campaign or day, or a GET | n/a | n/a | n/a | ❌ nothing is sent or audited | ❌ |

Rules the tests pin down:

- **Status of a day** (one per active participant): *done on time* (the ledger row is `earned`, point 1), *done late* (`late_no_point`, point 0), *draft* (an entry row exists and no ledger row, point 0), *none* (point 0), and *point removed on review* (`removed_by_review`, point 0; only the future P1 review path writes it, so it is rare). The point is the ledger's, never a guess.
- **Group counts** for the chosen day: *members* (active members of an active enrollment), *SOAPs submitted* (those members with a submitted entry that day, on time or late) and *shares* (posts currently shared to the group for that day). A person in two groups is counted in both, so never add the rows up (use the ledger for totals); a *solo readers* row lists people in no group. The queries select ids, statuses and counts only: `soap_entries.body` and `group_shares.body` are never selected.
- **CSV.** Columns `participant_id, display_name, groups, day, point, status` (English, fixed; `status` is `done_on_time`, `done_late`, `draft`, `none` or `point_removed`). Either the whole campaign (one row per participant and day that has a result in the ledger; a person in several groups has them in one cell, `A; B`) or one day (every active participant, so drafts and *none* appear). UTF-8 with a byte-order mark so Excel reads Vietnamese names. **Formula guard:** a cell that begins with `=`, `+`, `-`, `@`, a tab or a carriage return gets a leading `'` (display names and group names are typed by people). Display names follow §4g (a name that looks like an email address becomes `Participant #ID`). Every export writes one `scores_exported` audit row with the row count (and the day), never a name.
- **IDs from the request.** The campaign must exist and the day must be one of its days; anything else is "Not found or not allowed" and sends nothing. The nonce is bound to the campaign and day.
- **D17.** Admins cannot browse group feeds, and neither surface lists or opens a share. An organizer's one route to a shared post stays `jlife_challenges_hide_share()`: it takes a share ID from a report, is audited (`share_hidden`) and tells the author. Hiding changes no count of points and no text appears anywhere afterwards.
- **Scores.** Both surfaces are read-only: viewing or exporting never writes a ledger row, enrollment or membership.

### 4i. Group starters read ahead and schedule a group share (#209)

Owner-approved rule (#209, 2026-11-22). Two separate, explicit actions. **Starter** means the existing active `starter` membership: no WordPress role, no admin access.

**Who may read ahead.** `jlife_challenges_can_read_ahead()`: the campaign is `running` or `upcoming`, the person has an active enrollment in it, and an active `starter` membership in an **active** group of that same campaign. Everyone else keeps the rule that a day must have opened in campaign time. A `closed`, `draft` or `ended` campaign never opens extra days. The same check sits in `jlife_challenges_resolve_day()`, so the page, the REST autosave and the submit are one path.

| Actor / case | Open a future day | Draft / submit a future day | Schedule a group share | See a scheduled share (any read) |
|---|---|---|---|---|
| Starter of group A (active, campaign running or upcoming) | ✅ Scripture and Ready devotional only | ✅ the normal entry flow; scored at once | ✅ to **A** only | ✅ own, as "Scheduled for…", with update and withdraw |
| Same person, asking for group B where they are only a member | ✅ (the right is "starter of any group") | ✅ | ❌ denied; nothing is copied to B | own only |
| Ordinary member, solo reader, member of A who is not its starter | ❌ "not available" (page, `?day=`, REST, POST) | ❌ | ❌ | ❌ never: not in My Group, counts, previews, Today, `/share`, REST |
| Former starter (left, removed, role lost), starter of a disabled group, starter of another campaign | ❌ | ❌ | ❌ | ❌ |
| Starter of a **closed** campaign | ❌ read-only as today | ❌ | ❌ (withdrawing stays allowed) | own only |
| Organizer, site admin, anonymous, magic-link actor | ❌ | ❌ | ❌ | ❌ (organizers hide by ID only, D17) |

**Scoring.** A submit on a future day runs the campaign's engine at once (§3a). One point per person, campaign and day (UNIQUE key), across SOAP and reflection formats and every group. It is **never** re-scored at release; edits, retries, schedule, update and withdraw never add or remove a point. Only metadata is stored: `score_ledger.early` (yes/no) and `early_hours` (whole hours before release). No text enters the ledger, the audit log or any counts-only report.

**Scheduled share.** A `group_shares` row with `status = 'scheduled'` and `advance = 1`, one per entry and group (existing UNIQUE). No release time is stored: the day opens by `jlife_challenges_day_is_open()` in the **campaign's** timezone (DST-safe date arithmetic). Every existing read selects `status = 'shared'`, so a scheduled share is invisible to feeds, counts, previews, `/share` for others and any REST route by construction. `jlife_challenges_release_due_shares()` turns a due share into `shared` only if the author is still an active starter of the active group in a campaign that still takes writes; otherwise it withdraws it. Nothing runs at deploy time that could release or reset one.

| Event | Result |
|---|---|
| Day opens (campaign time) | Next read (any member's feed or Today, or the author's own page) releases it; no browser session needed; no scoring |
| Author leaves, is removed or moved out, loses the role, group is disabled, campaign closes | Unreleased shares are **withdrawn** (status `removed`, text cleared, audited with IDs only). A share already due is released first. Regaining the role, rejoining or re-enabling never brings one back; the author must schedule again |
| Author withdraws | `removed`, text cleared; the earned point stays; withdrawing twice is a no-op |
| Schedule again, update, retry | One row per entry and group (upsert); the snapshot changes only on that explicit action |
| Organizer hid it (D17) | Stays hidden; not re-scheduled |
| Rollover or deploy | Shares never carry between campaigns and keep their campaign, day and group, so pilot shares never appear in the official campaign |

**Presentation after release.** My Group: `advance = 1` posts first within that day's section (#203), labelled. Today: a collapsed "[Group name] leader's thoughts" disclosure per eligible group after the Scripture and devotional, before the writing area, from the same row; omitted when there is none; the author's own post is not repeated.

**Read-ahead content.** Scripture and **Ready** devotionals of that day only. No drafts, nobody else's writing, no change to a post's status, public date or comment window.

### 4j. Photo of a handwritten reflection (#244)

Owner decisions on #244 (2026-11-23): official Marathon only (the pilot stays text-only), one photo per day, shared with groups at Submit as a frozen copy, owner review by WP-CLI zip export. **P** = a photo (the author's own draft photo, `kind = entry`) and **PS** = a share copy of it (`kind = share`, one per share). The photo is another way to write the same reflection, not a new object with its own reader or point.

**The setting.** `campaigns.accepts_photos` (0 default, so every existing campaign and the pilot stay off). Set at creation (`--photo-reflections` on `wp jlife official create` and `wp jlife pilot create`) or changed with `wp jlife campaign set-photos <id> on|off`, which has the same lock as `set-engine`: refused once Day 1 has started or the campaign has any entry or ledger row, or is closed. With the setting off there is no photo button, no upload route that works, and a submit never looks at a photo.

**Storage and serving.** Files are re-encoded JPEGs (longest side 1600 px, EXIF orientation applied, EXIF/GPS/comments removed, no original kept) with random names and no extension, in `wp-content/jlife-private/site-<id>/` (or `JLIFE_PHOTO_DIR`), never the Media Library or `uploads/`; the folder denies direct requests. A photo is read only through `/photo/<id>/` (and `?size=thumb`), which answers every refusal with the same 404 and sends `Cache-Control: private, no-store`.

| Actor / case | See P (own draft photo) | Upload / replace / remove P | See PS (share copy) |
|---|---|---|---|
| Author, any time (draft, submitted, closed campaign, after leaving the group) | ✅ always | ✅ while the campaign takes writes and accepts photos | ✅ own copies, any status |
| Another participant, group member or starter, other group's member | ❌ 404 | ❌ | ❌ unless a member of that share's group (below) |
| Member of the group, **before** the author ticked it, or the share is `scheduled` (#209), `hidden` (D17) or `removed` | ❌ | ❌ | ❌ |
| Member of the group, the share is `shared` | ❌ P | ❌ | ✅ thumbnail and full size, that group's copy only |
| Same member **after leaving** or being removed, or the group is disabled | ❌ | ❌ | ❌ at once |
| Author removes the share, or the scheduled share is withdrawn | n/a | n/a | ❌ the copy is deleted from disk |
| Member asking for a copy shared to another group, a photo id that is not theirs, a wrong day or campaign id | ❌ 404 | ❌ denied | ❌ 404 |
| Organizer, site admin, anonymous, magic-link actor | ❌ no screen; the owner reviews by `wp jlife photos export` on the host only (audited, counts only) | ❌ | ❌ organizers hide by share ID only (D17) |
| Participant of a campaign with photos **off** | n/a | ❌ refused (`jlife_photos_off`); nothing stored | n/a |
| Not enrolled, or the day not available to them (#209) | ❌ | ❌ same refusal as a text save | ❌ |

| Case | Result |
|---|---|
| Choosing a file | Saved with the draft at once (JS), or with Submit / Save draft (no JS). It never earns a point |
| Not a real JPEG or PNG (renamed executable, PDF, HEIC, truncated file), over 12 MB, or no image library | Refused with a message; nothing stored; any earlier photo is kept; the typed text is kept |
| Same upload repeated, double submit, retry | One row (UNIQUE `kind`, `entry`, `share`); the old file is deleted, never duplicated |
| Submit: typed text passes the campaign engine | **Text** point (`score_ledger.submission_type = 'text'`), photo or not |
| Submit: text does not pass, a stored photo exists, campaign accepts photos | **Photo** point (`submission_type = 'photo'`, engine `photo-upload` v1), on time or late by the usual deadline rule |
| Submit: neither | No point; text and photo are kept |
| Replace or remove after submission | The ledger row is untouched (same source, no new point, no revoked point); audited `photo_replaced` / `photo_removed` with IDs only |
| Ticking a group on Submit with a photo | A **frozen copy** of the photo is made for that group's share; the entry's later replace or remove does not change it; ticking again replaces it. A photo-only entry can be shared. D19 youth rules and #209 release rules apply unchanged |
| Account deletion, test-campaign removal, campaign purge (#186 hook) | Files and rows are deleted (`jlife_challenges_delete_campaign_photos()`); nothing is written to logs or the audit log but counts |
| `wp jlife photos export` | Host only, organizer. Zip of one participant's photo-earned days (`dNNN.jpg` + `days.csv`) in `~/.latoc/` (0600); output and audit are counts and the path |
| My Marathon | A photo-earned day stays green and shows a camera marker; its label reads "Point earned – photo upload" |

### 4k. Ask the Pastors (#249)

Owner decisions (2026-11-24): questions are **private to administrators, not anonymous to them**; one gate, `jlife_questions_can_review()`, true only for `manage_options` (network super admins count), with a filter so a pastor capability can be added later (separate staff accounts; `jlife-auth` eligibility unchanged, no role or capability ships now); admins may permanently delete **archived** questions only; **no retention job**; the switch is an on/off button on the Bible Questions screen, default off; a private pastor note exists and is never exported; minors stay in the queue with an "under 18" badge and a paraphrase warning; no urgent-help contact and no email. **BQ** = a question (`jlife_bible_questions`: user, campaign, day, passage, text, minor flag, status, note). BQ shares no column or join with S, GS, L or scores.

| Actor | BQ create | BQ list / read | Status, note | De-identified export | Delete |
|---|---|---|---|---|---|
| Participant (solo, in a group, minor) | ✅ own, within limits, enrolled and the day open | ❌ (receipt only; no list of their own questions) | ❌ | ❌ | ❌ |
| Member, leader or starter of the asker's group; any other group | ❌ for others | ❌ | ❌ | ❌ | ❌ |
| Editor, author, any other wp-admin user | ❌ | ❌ | ❌ | ❌ | ❌ |
| Site administrator (`manage_options`) | ❌ (not as a reader here) | ✅ identity + text | ✅ | ✅ text and passage only | ✅ archived only |
| Anonymous, magic-link actor | ❌ | ❌ | ❌ | ❌ | ❌ |

| Case | Result |
|---|---|
| Feature off (default) | No link, no screen, `jlife_questions_submit()` refuses; nothing stored |
| Not enrolled, a day that has not opened (including a starter reading ahead), a closed campaign | Refused; nothing stored |
| 10 to 1,000 characters, passage at most 100, tags stripped | Otherwise refused with a message; typed text is kept |
| 3 per rolling 24 hours, 15 per campaign, same text on the same day within 24 hours | The 4th and 16th are refused; the repeat returns the first row |
| A question | Earns no point, makes no share, changes no draft, sends no email; audit row `question_submitted` holds ids only |
| Export | Numbered list of `(passage) question` ordered by passage; no name, id, date, status, minor flag or note; audited as a count |
| Delete | Archived rows only, by an administrator; audited by id; account deletion removes a person's questions; removing a test campaign removes its questions; no automatic purge |

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

**Groups admin (#64 part 1; written first, `test-groups-admin.php`):**
- a subscriber, participant, starter and editor get no menu entry and a 403 on the page and on every write; an organizer and a site admin get the screen
- every write needs a valid nonce bound to its action and group; a GET or a forged nonce changes nothing
- create, rename, regenerate, revoke, add, move and remove each write one audit row without personal data
- the invite link holds only the code; regenerating replaces the old code; revoking leaves none; the page never prints a hash
- move and remove, both directions (D18), and nobody else's private entry leaks
- a member is added only if already enrolled; a fourth group is refused; a move across campaigns is refused
- membership changes leave the ledger, enrollment, consent and the score export untouched
- acceptance: two groups, several participants joined by invite and by admin, each sees the correct My Group, one moved and one removed with no leak

**Daily view and CSV (#64 part 2; written first, `test-daily-view.php`):**
- a subscriber, participant, starter and editor get no menu entry and a 403 on the page and on the download; an organizer and a site admin get both
- a download needs the capability, a POST and a nonce for that campaign and day; an unknown campaign or day sends nothing
- each status (done on time, done late, draft, none) shows with the ledger's point; per-group members, submitted and shares are right
- no SOAP text, share text, email address, login or token appears in the page or the CSV, including for hidden and removed shares and drafts
- the CSV has individual points and the formula guard on every text cell
- viewing and exporting write no ledger, enrollment or membership change; an export is audited with a count only
- acceptance: the CSV for the two-group scenario has each person's point and no reflection

**Ask the Pastors (#249; written first, `test-bible-questions.php`):**
- every actor above on list, detail, status, note, export, delete and the switch, directly and by forged request; nonce bound to the operation and question; GET changes nothing
- the gate filter widens and narrows access; no new role or capability exists and a subscriber stays eligible for emailed codes
- length, rate and repeat limits; not enrolled, unopened day, closed campaign and feature off store nothing
- no points, shares, entries or membership change and a draft is untouched
- export holds no name, date, note or flag; no question text in `error_log`, the audit log or any email
- account deletion and test-campaign removal purge; no retention cron

**Devotionals bulk status (#262; written first, `test-devotionals.php`):**
- bulk Ready and Back to Draft by an administrator only; an editor and a participant refused by the page and the handler; a missing, forged or other-action nonce refused with no change
- posted IDs trusted no further than the grid: another plan, another post type, a duplicate cell, the Trash and made-up IDs are skipped; each change goes through the single action's function and audit; one summary audit row holds counts only, never a title or text
- Ready still shows to a reader only once the day has opened in their campaign

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
