# S5: Privacy-scoped huddle discussion and private notes

Issue: #12 · Timebox: 2 weeks · Actual: ~1 day (matrix → schema → gate → tests, all green)

## Question

Can huddle discussion, author-only private notes, and progress live in custom
tables with capability checks on **every** read/write path — member,
non-member, other-huddle member, leader, other leader, admin, anonymous, and
S4 link actors — enforceable by CI? (architecture.md §5,
integration-boundaries.md, pilot-context.md, roadmap S5.)

## The permission matrix (written first; the tests implement it)

Objects: huddle thread (T), private note (N), progress detail (P-self),
progress flags (P-flags, per-member status values), progress aggregate
(P-agg, counts only).

| Actor | T read | T write | N read | N write | P-self | P-flags | P-agg |
|---|---|---|---|---|---|---|---|
| Huddle member | ✅ | ✅ | own only | own only | ✅ | ❌ | ❌ |
| Second member, same huddle | ✅ | ✅ | ❌ (others') | ❌ | own only | ❌ | ❌ |
| Member of another huddle | ❌ | ❌ | ❌ | ❌ | own only | ❌ | ❌ |
| Huddle leader | ✅ | ✅ | **❌** | ❌ | own only | ✅ | ✅ |
| Another huddle's leader | ❌ | ❌ | ❌ | ❌ | — | ❌ | ❌ |
| Site admin (app layer) | ✅ (moderation, disclosed) | ❌ | **❌** | ❌ | — | ❌ | ✅ |
| Anonymous | ❌ | ❌ | ❌ | ❌ | ❌ | ❌ | ❌ |
| S4 magic-link actor | ❌ | ❌ | ❌ | ❌ | write progress + leader-visible response only | ❌ | ❌ |
| Coach/pastor (pilot default) | ❌ | ❌ | ❌ | ❌ | — | ❌ | via leader/HUB tile only |

Unknown huddle (no membership data): **everything denied** — the gate fails
closed.

## Implementation

Post-review hardening for the MVP seed code:

- `jlife_private_notes` is huddle-scoped with `dt_group_id` and keys notes by
  `(user_id, dt_group_id, lesson_id)`, so the same participant can keep
  separate private notes for the same lesson in separate huddles.
- Private-note reads and writes remain author-only, but now also require
  current membership in the note's huddle scope.
- Progress self-detail now requires current membership in the huddle, so a
  removed participant loses progress read access along with thread and note
  access on the next membership check.

- **Tables** (`plugins/jlife-huddles/includes/schema.php`, dbDelta, utf8mb4):
  `jlife_huddle_threads`, `jlife_private_notes`, `jlife_progress` — separate
  tables per sensitivity, never one "responses" junk drawer. Every
  huddle-scoped row carries `dt_group_id` (S3 contract). Indexes on
  `(dt_group_id, lesson_id)` and `(user_id, lesson_id)`.
  `progress.user_id` is signed: negative values key S4 link actors
  (contact IDs) until the account-claim flow merges them.
- **One gate** (`includes/gate.php`): every decision is a named function —
  `jlife_huddles_can_read_thread()`, `can_write_thread()`,
  `can_read_private_note()`, `can_write_private_note()`,
  `can_read_progress()`, `can_read_progress_flags()`,
  `can_read_progress_aggregate()`, `link_actor_can()`. No raw
  `current_user_can()` or SQL scattered in rendering code.
- **Data API** (`includes/data.php`): the only code that touches the tables;
  refuses without the gate; all SQL through `$wpdb->prepare()`. Fetch-by-ID
  re-checks the gate against the **row's own** huddle, and "not found" is
  byte-identical to "denied" (no existence oracle).
- **Membership is authoritative from D.T** via the
  `jlife_huddles_group_membership` filter: production =
  `jlife_bridge_get_group_membership()` (live HUB read: `assigned_to` →
  leader; member contacts → WP users via `corresponds_to_user`, unlinked
  contacts simply absent); tests = fixtures. No STUDY-side mirror table
  exists yet, so there is **no sync window**: a membership change in D.T is
  effective on the next request (verified live and by the stale-membership
  test). If a cache/mirror is added later, its TTL becomes part of this
  document.
- **Account deletion**: `deleted_user` purges the user's private notes and
  progress rows immediately (tested). Thread posts are group-context content
  and are currently **retained** — flagged below as an open product decision.

## Verification — two independent layers

**1. PHPUnit access-control suite**
(`plugins/jlife-huddles/tests/test-access-control.php`) — 11 tests /
63 assertions, green in the wp-env tests container (the same job CI runs, so
**#16's access-control gate is now substantive, not scaffold**):

- full thread matrix (7 actors × read/write)
- cross-huddle **ID-guessing** on threads and notes (list protected AND
  `get/{id}` protected; missing row ≡ denied)
- private notes author-only — including **leader denied** and **site admin
  denied** at the app layer
- progress: self detail; leader flags whose row shape is `(user_id, status)`
  only — the query cannot produce bodies; aggregate counts for leader/admin
  only
- **stale membership**: member removed from the huddle loses read and write
  on the next check
- unknown group fails closed
- S4 rule: link actors allowed `progress`/`leader_response`, denied
  `thread`/`private_note`
- Vietnamese text integrity through utf8mb4 storage
- account deletion purges notes + progress

**2. Live smoke against the real S3 environment** (WP 7.0, D.T 1.82.2):
bridge read Huddle Alpha's record → leader1 identified from `assigned_to`,
participant1 from a `corresponds_to_user`-linked contact, unlinked contact
absent; gate then allowed leader1/participant1 and denied leader2/anonymous
using **live** data; Vietnamese thread post round-tripped intact through the
real tables.

## Admin-visibility honesty statement (user-facing draft)

**English:**

> "Private" means your huddle leader, coaches, and pastors cannot see this
> content in the app. The people who maintain the website's technical
> systems (site administrators and database or backup operators) could
> technically access stored data while doing their jobs. If you delete your
> account, your private notes are deleted right away, but copies may remain
> in system backups for up to the backup retention period.

**Vietnamese (draft for language review):**

> «Riêng tư» có nghĩa là trưởng nhóm, người huấn luyện và mục sư của bạn
> không thể xem nội dung này trong ứng dụng. Những người bảo trì hệ thống kỹ
> thuật của trang web (quản trị viên trang, người vận hành cơ sở dữ liệu và
> sao lưu) về mặt kỹ thuật có thể truy cập dữ liệu được lưu trữ khi thực
> hiện công việc của họ. Nếu bạn xóa tài khoản, các ghi chú riêng tư của bạn
> sẽ được xóa ngay, nhưng bản sao có thể còn tồn tại trong bản sao lưu hệ
> thống cho đến hết thời gian lưu giữ sao lưu.

## Conclusion — PASS

Access-control tests pass on every read/write path in the matrix, including
the negative and adversarial cases (ID guessing, stale membership, link
actors, fail-closed unknowns); leaders see completion flags but structurally
cannot read note bodies; private content has no path into HUB (S3 tile is
aggregate-only by construction); and the tests run in CI, closing #16's
done-condition. Privacy wording is drafted in both languages — **approval of
that wording is the remaining human step in this issue's pass condition.**

## Open items / follow-ups

- **Product decision:** thread posts on account deletion — retain (group
  record), anonymize, or delete. Currently retained; decide before pilot.
- **Language review** of the Vietnamese privacy wording (#9 reviewer).
- **`prayer_request`** was deliberately not built: pilot-context keeps shared
  prayer in the live meeting for the first pilot. If it becomes a table, it
  follows the thread rules at minimum, likely stricter.
- **REST endpoints** (when the UI lands) must call the data API only — the
  gate functions are the review checklist for that PR.
- **Coach role**: pilot default is aggregate-via-tile only (S3); any richer
  coach view is a deliberate future grant using D.T sharing.
