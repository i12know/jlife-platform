# S3: Huddle ↔ Disciple.Tools group mapping

Issue: #10 · Timebox: 1–2 weeks · Actual: ~0.5 day (exercised against a live network)

## Question

Does `huddle = D.T group` hold without awkward workarounds — leader/member
connections, own-groups-only leader visibility, a custom progress tile, and
cohort (parent/child) shapes — with no core forks? (architecture.md §5,
pilot-context.md, roadmap S3.) Per the pre-implementation review on #10, the
pass condition includes **negative** permission tests, not just the happy path.

## What we did

Live subdirectory multisite (WordPress 7.0, PHP 8.2): Disciple.Tools theme
**1.82.2** on `/hub/`, `disciple-tools-multisite` network-active. Seeded per
architecture.md §3 with fake `.test` identities (PRD §21):

- Users: `leader1`, `leader2` (**multiplier** on HUB), `participant1`
  (subscriber network-wide, **no HUB role**), `admin` (super admin).
- Contacts: two members assigned to leader1, one to leader2.
- Groups: **Huddle Alpha** (assigned leader1, 2 member contacts),
  **Huddle Beta** (assigned leader2, 1 member), **Cohort Parent** (assigned
  admin) with both huddles as `child_groups`.

All REST calls used core application passwords against `dt-posts/v2`;
page-level checks used real cookie logins.

## Findings

### 1. Permission matrix — PASS, including all negative tests

REST (`/hub/wp-json/dt-posts/v2/...`), observed:

| Actor | Alpha (own\*) | Beta (other's) | Cohort (admin's) | Contact own | Contact other's |
|---|---|---|---|---|---|
| `leader1` (multiplier) | **200** | 403 | 403 | **200** | 403 |
| `leader2` (multiplier) | 403 | **200** | 403 | 403 | **200** |
| `participant1` (no HUB role) | 403 | 403 | 403 | 403 | 403 |
| anonymous | 401 | 401 | 401 | 401 | 401 |
| `admin` (administrator) | 200 | 200 | 200 | 200 | 200 |

\* "own" = `assigned_to` that leader. List endpoints match: each leader
enumerates exactly one group and only their own contacts (`total: 1`).

Page level (`/hub/groups/{id}/`): leader1 gets the full record; leader2 gets
D.T's **"Permission denied"** page with none of Alpha's data; participant1 and
anonymous are redirected to the HUB login screen. The S1 finding stands: a
network-wide session does not help `participant1` — isolation is
**capability-based** (D.T shows content only for records `assigned_to` the
user or explicitly shared), and it held on every path we probed.

**Roles/capabilities that produced this:** D.T's `multiplier` role; record
visibility = `assigned_to` + `shared_with`. WordPress `administrator` sees
everything — consistent with the admin-visibility honesty statement
(integration-boundaries §7).

### 2. Intentional cross-visibility = D.T sharing — PASS

`DT_Posts::add_shared( 'groups', cohort, leader1 )` flipped leader1's cohort
access 403→200 while Beta stayed 403 — **shares do not cascade** to child
groups. This is the mechanism for the pilot-context coach/pastor pattern:
oversight is an explicit, per-record grant, never an ambient side effect.

### 3. Two metadata-exposure caveats (documented, not blockers)

- **Connection fields expose titles across permission boundaries.** Huddle
  Alpha's `parent_groups` field shows leader1 the cohort's ID and title even
  though direct access to the cohort is 403. Content doesn't leak; existence
  and name do. Rule of thumb: **name parent cohorts as if every child-huddle
  leader can read the name** (no sensitive locations/people in group titles).
- **Tile labels render on denied pages.** D.T embeds registered tile
  labels/descriptions in the page's JS settings even on the Permission-denied
  screen. Our tile's label is generic ("J-Life Progress"); keep it that way —
  tile labels and descriptions must never contain per-huddle information.

### 4. Custom tile PoC in `jlife-bridge` — PASS

`plugins/jlife-bridge/includes/hub-tile.php` registers a **J-Life Progress**
tile on group records (`dt_details_additional_tiles` +
`dt_details_additional_section`, the starter-plugin pattern — no core hooks
missing, no fork pressure). Verified rendered for leader1 on Huddle Alpha with
aggregate placeholders and the correct member count ("0 of 2 members"); not
rendered for any actor who cannot open the record, because the tile sits
behind D.T's own record gate and **adds no new read path**.

Boundary honored by construction: the tile shows counts only, and future live
numbers arrive via the `jlife_bridge_group_progress` filter fed from STUDY by
the bridge — private participant content (notes, discussion, prayer) has no
route into HUB (pilot-context.md "Data That May Flow to Disciple.Tools").

### 5. Mapping contract (for S4/S5 and the MVP)

- **D.T group is the source of truth** for huddle identity, leader, members,
  and cohort links. Relevant fields, verified present on the record:
  `assigned_to` (user — drives visibility), `members` / `leaders` (contact
  connections), `coaches` (connection), `parent_groups` / `child_groups` /
  `peer_groups`, `group_type`, `group_status`.
- **STUDY-side structures reference the group by `dt_group_id`**; the bridge
  owns the mapping (`dt_group_id`, `dt_contact_id` ↔ STUDY user/participant).
- **All cross-layer reads go through `jlife-bridge`** — neither theme code nor
  `jlife-huddles` queries D.T tables directly, so S5's privacy checks have one
  gate.
- Pilot answers (pilot-context.md §S3 questions): huddle = **Group** (Team
  reserved for leader cohorts); participant contacts **are** created in D.T
  for the pilot (they are the member connections); the tile shows aggregate
  progress + engagement counts only; parent/child works with the
  title-visibility caveat above.

## Conclusion — PASS

No core forks, no fields that don't fit, no awkward workarounds. Leader
own-groups-only visibility holds across REST and page rendering with all
negative cases verified; the tile renders inside D.T's existing permission
envelope; cohort shape works with one nameable caveat (title exposure via
connection fields) and sharing provides the intentional-grant path for
coaches.

## Consequences / follow-ups

- **S4 (#11):** use `dt_contact_id` / `dt_group_id` as the identity language
  for magic-link scoping; the seeded fixtures here are reusable.
- **S5 (#12):** membership checks should treat the D.T group (via
  `jlife-bridge`) as authoritative; the stale-membership test in #12's review
  is the flip side of this contract. Group **titles** must be treated as
  huddle-visible metadata, not secrets.
- **Ops/docs:** record the two metadata caveats (connection-field titles, tile
  labels on denied pages) in the security model notes alongside the S1
  cookie-scope finding.
