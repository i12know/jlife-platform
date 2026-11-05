# `jlife-challenges` engine: design for review (M5, #60)

Status: **design draft for owner/consultant review. No code yet.** Building starts after #60 is approved and the #55 decisions D1–D5, D6, D14, D16–D18 are settled ([decision sheet](https://github.com/i12know/jlife-platform/issues/55#issuecomment-6651386035)). Defaults below follow the recommended answers. A changed decision changes the marked rule, not the structure.

Related: [#49](https://github.com/i12know/jlife-platform/issues/49) (spec); #60 (engine) with #61 (screens), #62 (sharing) and #64 (groups/admin); [S5 privacy model](spikes/S5-privacy-model.md); [reading plans](../content/reading-plans/README.md) (#59, done).

## 1. Principles the design enforces

1. **Scripture before score.** The engine serves the Today page's reading first; scoring is a side effect of submitting.
2. **Participation, not quality.** A point needs four non-empty fields submitted in time. No length, wording or AI test.
3. **Private by default.** A SOAP entry is readable by its author only. Sharing creates a *separate* snapshot.
4. **A SOAP entry is not a scored day.** Entries exist without a campaign (SOAP after Day 104, P4). Scoring is a separate ledger row.
5. **One point per person per day, enforced by the database**, not by application checks alone.
6. **Individual scoring only.** Groups, sharing and optional reading never change points.
7. **No Disciple.Tools dependency at launch** (D16). Groups feed the existing `jlife_huddles_group_membership` filter.

## 2. Tables (plugin `plugins/jlife-challenges`, prefix `{$wpdb->prefix}jlife_`)

| Table | Key columns | Notes |
|---|---|---|
| `campaigns` | `id`, `campaign_key` (unique slug), `plan_id` (e.g. `rpl-life-and-teachings-of-jesus-2026`), `title`, `start_date`, `end_date`, `timezone` (`America/Los_Angeles`), `grace_days` (D1: `1`), `status` (`draft`/`active`/`finished`) | Day content comes from the reading plan (#59) by `day_id`; nothing copied |
| `groups` | `id`, `campaign_id`, `name`, `invite_code_hash`, `invite_expires_at`, `status` (`active`/`archived`) | Invite codes stored **hashed**; a code is shown once to the admin |
| `enrollments` | `id`, `campaign_id`, `user_id`, `group_id`, `status` (`active`/`removed`), `consent_version`, `consented_at`, `joined_at`, `left_at`; **UNIQUE (`campaign_id`, `user_id`)** | One primary group per person (D14). A move updates `group_id` and writes the audit log |
| `soap_entries` | `id`, `user_id`, `campaign_id` (0 = unscored/personal), `day_id` (`''` if none), `entry_date` (campaign-local date), `scripture`, `observation`, `application`, `prayer`, `status` (`draft`/`submitted`), `submitted_at`, `revision`, timestamps; **UNIQUE (`user_id`, `campaign_id`, `day_id`)** for campaign entries | Drafts autosave server-side (M6). Never pre-filled with Bible text |
| `soap_revisions` | `id`, `entry_id`, `revision`, the four fields, `saved_at` | Edit history after submission (D3) |
| `score_ledger` | `id`, `campaign_id`, `user_id`, `day_id`, `entry_id`, `points` (0/1), `status` (`earned`/`late_no_point`/`removed_by_review`), `reason`, `source` (`platform`/`review`), `created_at`; **UNIQUE (`campaign_id`, `user_id`, `day_id`)** | Written once per person and day. Review changes (P1) go through an audited path |
| `group_shares` | `id`, `entry_id`, `user_id`, `group_id`, `campaign_id`, `day_id`, `fields` (which of S/O/A/P), snapshot text of **only** those fields, `status` (`shared`/`removed`/`hidden`), `hidden_reason`, timestamps; **UNIQUE (`entry_id`, `group_id`)** | A **snapshot**: editing the private SOAP never changes it; sharing again replaces it |
| `audit_log` | `id`, `actor_user_id`, `action`, `object_type`, `object_id`, `campaign_id`, `detail` (JSON, **never SOAP text**), `created_at` | Enrollment changes, moves, review actions, hides, exports |

## 3. Days and scoring

- **Campaign day:** the server converts *now* to the campaign timezone (never the device clock), takes the local date, and counts days from `start_date`. Day 1 = 2026-12-20; Day 104 = 2027-04-02. Outside the range there's no campaign day. The **Mar 14, 2027 DST change** is handled by date arithmetic in the campaign timezone, not by adding 24-hour periods.
- **Submit:** refused while any field is empty, without losing typed text. On the **first** complete submission for a day:
  - on time (local submit date ≤ day date + `grace_days`) → ledger row `points=1, status=earned`
  - otherwise → ledger row `points=0, status=late_no_point`, and the response says why (D2)
- **Idempotent:** the ledger insert relies on the UNIQUE key. A double tap, a retry or a second device can't create a second row.
- **Edits** (D3) write a revision and never touch the ledger.
- **Totals** ("N of 104") are computed from the ledger.

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
- the snapshot doesn't change when the SOAP is edited
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
