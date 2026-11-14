# `jlife-challenges` engine: design for review (M5, #60)

Status: **design draft for owner/consultant review. No code yet.** Building starts after #60 is approved and the #55 decisions D1–D5, D6, D14, D16–D18 are settled ([decision sheet](https://github.com/i12know/jlife-platform/issues/55#issuecomment-6651386035)). Defaults below follow the recommended answers. A changed decision changes the marked rule, not the structure.

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
| `groups` | `id`, `campaign_id`, `name`, `invite_code_hash`, `invite_expires_at`, `status` (`active`/`archived`) | Invite codes stored **hashed**; a code is shown once to the admin |
| `enrollments` | `id`, `campaign_id`, `user_id`, `group_id`, `status` (`active`/`removed`), `consent_version`, `consented_at`, `joined_at`, `left_at`; **UNIQUE (`campaign_id`, `user_id`)** | One primary group per person (D14). A move updates `group_id` and writes the audit log |
| `soap_entries` | `id`, `user_id`, `campaign_id` (0 = unscored/personal), `day_id` (`''` if none), `entry_date` (campaign-local date), `body` (**one** text; #98), `status` (`draft`/`submitted`), `submitted_at`, `revision`, timestamps; **UNIQUE (`user_id`, `campaign_id`, `day_id`)** for campaign entries | Drafts autosave server-side (M6). New entries are prefilled with the S/O/A/P heading template (never Bible text). `submitted` means the entry has passed the engine; an entry that has not passed stays `draft` with its text saved |
| `soap_revisions` | `id`, `entry_id`, `revision`, `body`, `saved_at` | Edit history after submission (D3) |
| `score_ledger` | `id`, `campaign_id`, `user_id`, `day_id`, `entry_id`, `points` (0/1), `status` (`earned`/`late_no_point`/`removed_by_review`), `reason`, `source` (`platform`/`review`), `engine`, `engine_version` (which scoring engine awarded the row; #98), `created_at`; **UNIQUE (`campaign_id`, `user_id`, `day_id`)** | Written once per person and day, append-only: points are never taken back. Review changes (P1) go through an audited path |
| `group_shares` | `id`, `entry_id`, `user_id`, `group_id`, `campaign_id`, `day_id`, `body` (snapshot of the **whole** entry; #98), `status` (`shared`/`removed`/`hidden`), `hidden_reason`, timestamps; **UNIQUE (`entry_id`, `group_id`)** | A **snapshot**: editing the private SOAP never changes it; sharing again replaces it |
| `audit_log` | `id`, `actor_user_id`, `action`, `object_type`, `object_id`, `campaign_id`, `detail` (JSON, **never SOAP text**), `created_at` | Enrollment changes, moves, review actions, hides, exports |

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

| Actor | S read | S write | L-self | L-counts | GS read | GS create / remove | GS hide | X |
|---|---|---|---|---|---|---|---|---|
| Author (participant) | ✅ own | ✅ own | ✅ | own group's counts only if D5 allows (default ❌) | ✅ own group | ✅ own entries, own current group | ❌ | ❌ |
| Same-group member | ❌ | ❌ | ❌ | ❌ | ✅ | ❌ (not others') | ❌ | ❌ |
| Other-group member | ❌ | ❌ | ❌ | ❌ | ❌ | ❌ | ❌ | ❌ |
| Group leader (P2) | ❌ | ❌ | ❌ | ✅ own group (D5) | ✅ as a member of that group | ❌ | ❌ | ❌ |
| Former member after a move (D18) | own S only | own | own | ❌ | ❌ old group | remove own old shares only | ❌ | ❌ |
| Site admin / organizer | ❌ (P1 review path only, audited) | ❌ | ✅ (D5) | ✅ | ❌ browse (D17) | ❌ | ✅ reported posts, audited, author told (D17) | ✅ |
| Anonymous | ❌ | ❌ | ❌ | ❌ | ❌ | ❌ | ❌ | ❌ |
| Magic-link actor | ❌ (bridge off at launch) | ❌ | ❌ | ❌ | ❌ | ❌ | ❌ | ❌ |

Unknown campaign, group or entry → **denied, indistinguishable from "not found."**

### 4a. Share on save (#98): the "Share this with my group" checkbox

The SOAP form has one **unchecked** checkbox. It is a second way to create the same group share (GS create), not a new kind of access. The whole saved entry, including Prayer, is the snapshot; there are no field choices any more. The share engine's rules apply unchanged; this table is the row that the access-control tests in `test-share-on-save.php` pin down.

| Case | Result |
|---|---|
| Box unchecked, or a draft save/autosave | No post is created or changed |
| Box checked on Submit / Save changes, save succeeded | One post for the author's **current** group (create, or update the same one; retries never duplicate). Saved without a point still counts as saved |
| Box checked, save failed (denied, closed day) | No post |
| Same-group member | Reads the post (first name only) |
| Other-group member, outsider, organizer, anonymous | Cannot read the post or the feed |
| Someone else's entry | Cannot be shared: denied, same as "not found" |
| Minor awaiting guardian consent (D19) | Save and point stand, no post, the page says so |
| Post hidden by an organizer (D17) | Not re-posted, not updated, not un-hidden; the page says so; save and point stand |
| Member who moved group (D18) | Posts go to the new group only; older posts stay with the old group |
| Share failed after a good save | The page says so plainly; the entry and any point are kept |

Unchecking later never removes a post; removal stays with the existing "Remove from my group" flow on `/share`.

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

**Membership:** an integration test through `jlife_huddles_group_membership` with real enrollment rows, not fixtures only.

## 6. Interfaces for the screens (M6/M7)

- **PHP functions:**
  - `jlife_challenges_today( $user_id )` → day, reading (via `jlife_studies_get_reading_day()`), own entry status
  - `…_save_draft()`, `…_submit()`, `…_get_entry()`
  - `…_share()` and `…_unshare()`
  - `…_group_feed( $user_id )`
  - `…_progress( $user_id )`
- **REST (logged-in, nonce-checked)** for autosave and submit, so a dropped connection can retry safely: `POST /jlife/v1/soap/draft`, `POST /jlife/v1/soap/submit`.

## 7. Open points for review

1. **Display names in group feeds:** first name only, or full name? This affects the D15 wording.
2. **Group leaders at launch:** do the 12 groups have designated leaders in J-Life, or are all members equal until P2?
3. **Unscored entries after Day 104:** one per calendar day, or unlimited?
