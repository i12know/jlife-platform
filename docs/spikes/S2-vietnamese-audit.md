# S2: Vietnamese localization audit
Issue: #9 - Timebox: 1 week - Actual: ~0.5 day repository/API audit; no live Vietnamese reader session

## Question

How complete is Vietnamese localization across the Disciple.Tools surfaces J-Life expects to use: the D.T theme, the mobile app, and target plugins (Magic Links, Groups Tile, Team Module, Mobile App plugin)? Can we identify the translation gaps and confirm a practical upstream contribution path?

## What we did

- Queried the public Weblate API at `https://translate.disciple.tools/api/components/` on 2026-07-05.
- Checked upstream repositories for the D.T theme, mobile app, Magic Links, Groups Tile, Team Module, and Mobile App plugin.
- Counted Vietnamese translation file coverage where a Vietnamese translation file exists.
- Checked whether each target plugin includes a Vietnamese file, only a template, or another locale.
- Reviewed the official D.T theme translation instructions at `dt-assets/translation/translation-instructions.md`.

We did not complete a live Vietnamese reader review of seeded S1 screens in this pass. That needs a real Vietnamese reader and a running staging/local instance with the final target plugins enabled. The technical audit below is still enough to answer the architecture question and produce the gap list.

## Findings

### 1. Public Weblate did not expose the expected D.T components

`https://translate.disciple.tools/api/components/` returned only three `doxa` components:

| Component | Project |
| --- | --- |
| DOXA | `doxa` |
| Doxa Prayer Mobile App | `doxa` |
| Doxa Marketing | `doxa` |

The expected Disciple.Tools theme, mobile app, and plugin components were not publicly discoverable through that API response during this audit. A request to `/api/projects/` also returned `429 Too Many Requests` once during the audit. Conclusion: do not rely on public Weblate percentages as the only source of truth until the D.T components are visible again or an authenticated contributor confirms their location.

### 2. Upstream repository translation files give a usable baseline

Coverage below is a file-level count of non-empty translated strings, not a Vietnamese quality review.

| Surface | Upstream source | Vietnamese file status | Audit result |
| --- | --- | --- | --- |
| D.T theme | `DiscipleTools/disciple-tools-theme`, branch `develop` | `dt-assets/translation/vi.po` and `vi.mo` present | 1302/1302 non-fuzzy strings translated; 100% by file count |
| D.T mobile app | `DiscipleTools/disciple-tools-mobile-app`, branch `development` | `languages/vi.json` present | 78/78 leaf strings non-empty; 100% by file count |
| Team Module | `cairocoder01/disciple-tools-team-module`, branch `master` | `languages/disciple-tools-team-module-vi.po` and `vi.mo` present | 13/13 non-fuzzy strings translated; 100% by file count |
| Magic Links | `DiscipleTools/disciple-tools-bulk-magic-link-sender`, branch `master` | no Vietnamese file; `languages/default.pot`, `terms_to_exclude.pot`, and Spanish files only | gap |
| Groups Tile | `thecodezone/disciple-tools-groups-tile`, branch `main` | no Vietnamese file; `languages/default.pot` and a French starter-template file only | gap |
| Mobile App plugin | `DiscipleTools/disciple-tools-mobile-app-plugin`, branch `master` | no Vietnamese file; `languages/default.pot` only | gap |

### 3. Completion percentages do not equal pilot readiness

The theme, mobile app, and Team Module look technically complete by file count. That does not prove the Vietnamese is natural, ministry-appropriate, or consistent with J-Life vocabulary. The first pilot still needs a Vietnamese reader to walk through:

| Screen area | Review target |
| --- | --- |
| Login and password/reset flows | clarity for leaders/coaches |
| Contacts list/detail | terms for contact, seeker, assigned/subassigned, faith milestones |
| Groups list/detail | whether group/huddle wording is confusing |
| Comments/activity stream | natural language for notes, mentions, updates |
| Magic-link participant screens | link-sharing privacy wording, button labels, response prompts |

### 4. Upstream contribution path is confirmed, but likely GitHub-first

The D.T theme includes official translation instructions in `dt-assets/translation/translation-instructions.md`. The documented flow is:

- read the glossary before translating
- preserve established term translations
- translate empty `msgstr ""` entries
- fix fuzzy strings and remove fuzzy flags
- preserve placeholders such as `%s`, `%1$s`, and template tokens
- validate PO files with `msgfmt` where available

Because the public Weblate API did not expose the expected D.T components in this audit, the reliable contribution path for now is: prepare PO/JSON changes against the upstream repository files, validate them, and submit them through the relevant upstream repository or through Weblate once authenticated component access is confirmed.

No upstream translation batch was submitted in this pass because the components with Vietnamese files were already complete by file count, and the missing plugin translations need human Vietnamese wording review before contribution.

## Gap List

| Gap | Severity | Owner / timing |
| --- | --- | --- |
| Magic Links has no Vietnamese translation file | High if Magic Links remains part of participant onboarding | Before pilot use of magic-link screens |
| Groups Tile has no Vietnamese translation file | Medium; leader/HUB-facing, not participant-facing | Before Vietnamese leader/cohort pilot |
| Mobile App plugin has no Vietnamese translation file | Medium; leader/coordinator-facing only | Before relying on the D.T mobile app |
| Public Weblate D.T components were not discoverable through the API | Medium; affects contribution workflow, not runtime | Confirm with authenticated contributor or D.T maintainers |
| No live Vietnamese reader review of key D.T screens yet | High for pilot polish/trust | Required before production/staging pilot |
| Custom J-Life strings are outside upstream D.T translations | High for MVP | Put every J-Life UI string through our own translation-review workflow |

## Conclusion - PASS for architecture, not final language QA

S2 passes the architecture spike condition: the gap list is produced, and an upstream contribution path is confirmed through the D.T translation-file workflow. The D.T theme, D.T mobile app, and Team Module are not localization blockers for an MVP architecture.

The main risk shifts to plugin-specific gaps and human review. Magic Links, Groups Tile, and the Mobile App plugin need Vietnamese translations if they appear in Vietnamese-facing workflows. Before a real pilot, a Vietnamese reader should review the running HUB/STUDY flows in `vi` and either approve terms or produce a contribution batch.

## Consequences for architecture.md / follow-up issues

- Keep the existing STUDY/HUB architecture. S2 found no localization reason to abandon D.T.
- Treat Disciple.Tools mobile app usage as leader/coach-facing only; participants should stay on STUDY.
- Do not promise full Vietnamese readiness just because core files are present. Add a pilot readiness gate for human language review.
- Prioritize custom J-Life strings in `jlife-studies`, `jlife-huddles`, and `jlife-bridge`; upstream D.T translations will not cover our study/huddle vocabulary.
- Follow-up work: create Vietnamese PO files for Magic Links, Groups Tile, and the Mobile App plugin only after a Vietnamese reviewer approves vocabulary.
