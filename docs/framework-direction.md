# Framework Direction: From the LATOC Pilot to Reusable J-Life

Status: **Direction, not a decision.** It records the outcome of an architecture consultation (2026-11-20) so later sessions build on it instead of contradicting it. Where it proposes something, the ADR named in §7 decides it.

Related: [ADR 0001](adr/0001-multi-deployment-tenant-domain-architecture.md), [domain-architecture.md](domain-architecture.md), [architecture.md](architecture.md), [integration-boundaries.md](integration-boundaries.md), [staging-latoc.md](staging-latoc.md)

This document authorizes no refactoring, deployment change, new repository or production change. The December 20, 2026 LATOC launch comes first.

## Decision register

| Decision | Status | Authority |
|---|---|---|
| Independent J-Life deployments | Accepted | [ADR 0001](adr/0001-multi-deployment-tenant-domain-architecture.md) |
| Contact created on ministry relationship | Proposed | ADR 0002 pending ([#168](https://github.com/i12know/jlife-platform/issues/168)) |
| Multisite-first for new installs | Provisional | ADR 0003 pending, after the §8 experiment ([#168](https://github.com/i12know/jlife-platform/issues/168)) |
| Generic deployment with site profiles | Proposed | ADR 0004 pending ([#168](https://github.com/i12know/jlife-platform/issues/168)) |
| Scoring rule `reflection-v1` (free-form or SOAP, one rule per campaign) | Approved requirement (not an ADR) | Owner decision on [#205](https://github.com/i12know/jlife-platform/issues/205#issuecomment-7069225640), 2026-11-21; [challenges-engine-design.md §3a](challenges-engine-design.md) |
| Daily devotionals: Word/Google Docs master, wp-admin importer | Approved requirement (not an ADR) | Same decision, 2026-11-21; [architecture.md §4](architecture.md), [content/README.md](../content/README.md) |
| Public `/reading/` comments and participant devotional offers | Approved future capability; implementation deferred | Same decision, 2026-11-21; [PRD §7.2](PRD.md); [#213](https://github.com/i12know/jlife-platform/issues/213), [#214](https://github.com/i12know/jlife-platform/issues/214) |

**What each status means:**
- **Accepted:** follow it.
- **Proposed:** a direction, not settled. Don't build on it as if it were decided.
- **Provisional:** proposed, and also waiting for evidence.

Only an owner-approved ADR changes a status, and it updates this table in the same PR. The authority order is in `CLAUDE.md`, "Architecture decisions".

**Remaining conflicts after the 2026-11-21 amendments (not resolved here):**
- [#209](https://github.com/i12know/jlife-platform/issues/209) (advance scoring for scheduled group shares) is still a proposed rule awaiting owner approval; nothing here records it as decided.
- The owner updated the live Help pages in wp-admin on 2026-11-21. The Rules page must carry the `reflection-v1` rule before the pilot uses it (Nov 29) and before the official campaign starts.
- Existing campaigns keep `soap-regex`; no active campaign changes without an owner-reviewed plan.

## 1. Two frameworks, one rule

J-Life grows as two separate frameworks:

| | A. WordPress deployment infrastructure | B. J-Life discipleship capabilities |
|---|---|---|
| What | Repeatable install, deploy, backup and recovery for independent WordPress installations | Modular plugins: reading plans and Scripture, campaigns and SOAP, groups, reminders, auth, later huddles and the Disciple.Tools bridge |
| Consumers | Any ministry website, with or without J-Life (e.g. Sports Fest, a future Bylaws site) | Only sites that run J-Life features |
| Lives in | `bin/deploy/` in this repository for now | `plugins/` in this repository |

**The rule: sharing code does not mean sharing data.** Each ministry runs its own installation, database, credentials and administrators. This extends ADR 0001's framework / deployment / tenant layers; it does not replace them.

Framework A stays in this repository until it has served more than one real consumer. Only then do we decide whether it moves to its own repository. The first step is to split the LATOC deploy script into a generic part and a LATOC site profile. The generic part must not assume a particular host, so a ministry can host its own LATOC.

## 2. Terms that are not interchangeable

| Term | Boundary it sets | Example |
|---|---|---|
| Ministry application | Features and code ownership | Sports Fest, PEAK, a J-Life reading campaign |
| WordPress installation | Data: one codebase folder, one database, one user table | latoc's installation |
| Multisite network | Users, login cookie and super admin, shared by that network's sites only | latoc: a network with one site |
| J-Life deployment | Governance: domain, administrators, CRM adapter, policy (ADR 0001) | VAY Hub |
| Tenant | A church's private pastoral records (ADR 0001) | `rp.vayhub.org` (planned) |

Working rules (proposed, ADR 0003):

- Independent ministries never share an installation, so they never share a user table.
- One tenant equals one installation. A tenant's STUDY and HUB may be two sites of one network, because they are meant to share users.
- LATOC is a **campaign site** of the VAY Hub deployment, not a tenant. It holds campaign data and no pastoral records.

## 3. Installation topology (provisional)

**Preferred proposal:** a new installation that our pipeline deploys starts as a **one-site, subdirectory Multisite network**, one network per independent ministry. **Single-site remains supported** where compatibility or operations justify it: existing sites (no forced conversions), sites that rely on a host tool that refuses Multisite, and sites whose editors need raw HTML without the capability workaround below.

Why this default:

- One topology for our tooling. The current deploy script already supports only Multisite.
- Adding a site later needs no conversion. Converting an established single site is not trivial: in a subdirectory network the main site's post permalinks gain a `/blog/` prefix, and some editor capabilities change.

Known recurring costs, to be measured:

- On Multisite only super admins have `unfiltered_html`, which affects page builders such as Divi. The workaround is a small must-use plugin that restores the capability to site administrators.
- Some host tools and backup plugins may not support Multisite (unverified).
- Per-site discipline: network versus per-site plugin activation (Polylang must stay per-site), the site URL on every WP-CLI call, cron run per site.

**This stays provisional** until the comparison experiment in §8 has run and ADR 0003 has been reviewed.

## 4. Identity: three records, three jobs

| Record | Scope | Created when |
|---|---|---|
| WordPress account | One installation | A person verifies an email to join something. Authentication only |
| Campaign enrollment | One campaign | A person joins a campaign (`jlife-challenges`). Participation, consent and age band |
| Disciple.Tools contact | One tenant | **Proposed:** only when an actual ministry relationship begins (a leader adds the person to a huddle, or the person asks for follow-up). Never at sign-up for a reading campaign |

ChMeetings person IDs attach to a tenant's Disciple.Tools contact, never directly to a WordPress account, and always with organization and tenant context ([vay-chmeetings-skill](https://github.com/i12know/vay-chmeetings-skill)). Matching two records never grants access.

Between installations, people move by **invitation**, not by copying records. For example, Sports Fest can hand out a J-Life campaign invite link, and the participant signs up themselves. Registration in one ministry is not consent to another.

**Conflicts to resolve in ADR 0002.** These documents still say every participant gets a Disciple.Tools contact:

- [architecture.md §3](architecture.md#3-identity-and-roles): the identity table (Participant → Contact) and "Every participant user … maps to a D.T contact".
- [integration-boundaries.md §2](integration-boundaries.md#2-identity-keys): "`dt_contact_id` — D.T contact for every participant and leader", and the §3 data map row that places participant contact info in the HUB contact.

Until ADR 0002 amends them, treat those passages as superseded *proposals*, and change neither them nor the bridge without that review.

## 5. Hosting decision (D9)

Decided 2026-11-20: latoc **stays on the existing shared hosting account**; no separate hosting is bought. This is a cost decision. **It does not remove the risk**: on a shared account, the security of every site on that account affects latoc's data. The response is to reduce exposure within the account and keep latoc portable. Details live in [staging-latoc.md](staging-latoc.md) and in private notes, not in this public repository.

## 6. Deferred operations work

The 2026-11-20 review proposed doing two items before the shadow pilot (then planned for December 5, now November 29). On the same day the owner **deferred both to 2027 or later, at low priority**:

- [#162](https://github.com/i12know/jlife-platform/issues/162): off-site encrypted backups and a restore drill.
- [#163](https://github.com/i12know/jlife-platform/issues/163): shared hosting account cleanup.

So the pilot and launch run with the backups described in the runbook (database dumps on the same server before each deploy) and with the shared-account risk accepted. Revisit this if the pilot's data volume or sensitivity changes.

## 7. Future decisions (need review; none written yet)

| ADR | Decides | Amends |
|---|---|---|
| 0002: Identity tiers | When accounts, enrollments and Disciple.Tools contacts are created and linked | architecture.md §3, integration-boundaries.md §2–3 |
| 0003: Installation topology | Default topology and its exceptions, one tenant per installation, campaign sites versus tenants, hosting-account policy. Written after §8 | domain-architecture.md §11 |
| 0004: Deployment tooling | Generic script plus site profiles, environments (staging before production), backup and rollback, no host-specific assumptions, when to extract a repository | staging-latoc.md |

## 8. Proposed comparison experiment (for ADR 0003; not started)

1. **In GitHub Actions**, with disposable installations and free GPL software only, using the same core version, plugins, theme and deploy script:
   - **A.** A clean single site.
   - **B.** A clean one-site Multisite network.
   - **C.** Single site A with sample content, converted to Multisite, with a second site added.

   For each, measure: setup-specific deploy steps, install and upgrade, plugin test results, backup and restore, effort to add a second site, and, for C, which URLs and settings changed.
2. **On a disposable subdomain of the real host** (needs owner approval): check page-builder editing by a site administrator with and without the capability workaround, and how the host's own tools and backup plugins behave on Multisite.

Multisite-first is confirmed if B costs little more than A and the host checks pass or don't matter to us. Single-site-first wins if a host tool we rely on refuses Multisite, or if C shows conversion is painless.
