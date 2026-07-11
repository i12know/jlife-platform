# Team Structure: Roles and Working Model

Status: Adopted 2026-07-11
Created: 2026-07-11
Related: [vision-architecture.md](vision-architecture.md) (§7 row 8 bus-factor
risk), [roadmap.md](roadmap.md) (§Versioning gate reviews),
[pilot-context.md](pilot-context.md), PRD §21 (public-repo safety — this
document names roles, never people).

The project is structured as a **hub with three rings** around the owner. The
roles below are derivable from the repo itself — issue labels, schema workflow
states, and spike conclusions already encode them — and this document makes
that structure explicit so it survives contributor turnover. Some roles are
filled by AI development sessions today; the human-only lanes are marked and
are non-negotiable.

## The hub — the owner

Product owner, theological authority of first resort, rights-relationship
holder (SonLife, Founders Passion, Bible societies), and **single merge
authority**. Every ring's job is to make what reaches the owner *small*: a PR
with green CI and a completed checklist, a lesson with reviews already
recorded in its workflow states, a decision framed with its options.

## Ring 1 — decision partners (human-only, ministry-rooted)

| Role | Mandate | Routed by | Anchors |
|---|---|---|---|
| **Theological reviewer** | Second reviewer the schema itself requires: `phase_mapping_status: proposed → approved` and lesson `translation_status` review states. SonLife-framework fluency. The owner's bus-factor twin on doctrine | `theological-review`, `ministry-discernment` | #21, #7 |
| **Vietnamese language lead** | Native-reader pass (S2's explicit open item); approves lesson Vietnamese and plugin UI strings before upstream PO contribution | `translation` | #7, S2 gap list |
| **Pilot huddle leader** | The field partner — their huddle completing the pilot series *is* v1.0.0; their feedback gates everything after. Multiplies into challenge team captains post-1.0 (relay dispatch, vision §5.2, makes leaders senders by design) | `huddle-workflow` | pilot-context.md |

## Ring 2 — build lanes (AI-run, human-review-gated)

This formalizes the current working model: AI development sessions
(`claude/*`, `codex/*` branches) produce PRs; humans gate merges. The repo is
deliberately built for it — CI gates, a docs corpus that carries context
between sessions, boring technology, and fail-closed access control.

| Lane | Mandate | Routed by | Anchors |
|---|---|---|---|
| **Platform engineering** | Plugins, privacy gate, CI. The S5 discipline — matrix first, gate function, CI tests — is the merge checklist that makes AI output safe | `engineering`, `privacy` | #36–#39 |
| **Content toolsmith** | Schemas, validators, Markdown⇄JSON converter, authoring guides. Customer: the Vietnamese language lead — every tool exists so Ring 1 works in prose, never JSON | `engineering`, `content` | #40, #42 |

## Ring 3 — stewards (part-time human, phased in)

| Role | Mandate | Routed by | When |
|---|---|---|---|
| **Ops steward** | Staging/backup/monitoring runbooks (#17); provider spend and dispatch monitoring from v3.0.0 | `operation` | Before the 0.9.0 pilot launch |
| **Rights & compliance coordinator** | Register-first discipline (#1, #2, #3); S8 TCPA/A2P consent audit. Owner-held initially — these are relationship conversations — delegable later | `permissions` | Owner now; delegate when trusted |

## Working agreements

1. **GitHub is the office.** Issues + labels are the role router; assignment
   is a label plus an assignee, nothing more. No parallel tracker.
2. **Three review protocols, one per artifact type:**
   - *Engineering PR*: green CI + S5 checklist + owner merge.
   - *Content PR*: validator + language lead + theological reviewer, recorded
     in the workflow states (`translation_status`, `phase_mapping_status`) —
     never in anyone's memory.
   - *Anything content-shaped*: ships only behind its rights row
     (vision §7 row 7).
3. **Human-only lanes are marked.** Theology, native-reader review, rights
   conversations, and pastoral privacy postures never route to an AI lane.
   Engineering and tooling route there freely.
4. **Cadence:** weekly 30-minute async triage; a gate review at each version
   ladder exit ([roadmap.md §Versioning](roadmap.md) — the exit tests are the
   agenda).
5. **Bus-factor plan:** by Phase D (church-wide launch), one Ring 2 human
   grows into a co-maintainer with merge rights. Until then the single-owner
   merge point is a named, accepted pilot-era risk (vision §7 row 8).
6. **Phase A headcount:** the owner plus three humans at a few hours a week —
   theological reviewer, Vietnamese reader, huddle leader — plus the AI
   lanes. The ops steward joins before 0.9.0.

## What this document is not

Not an org chart and not a hiring plan. Roles may share one person (they do
today); a person may hold roles in two rings. It exists so that when a new
contributor arrives, the question is only "which ring, which label?" — and so
the human-only lanes stay human as the AI lanes get faster.
