# Spike Write-Ups

Every architecture spike (issues #8–#13) ends with a written conclusion here, committed even when the answer is "this doesn't work."

Naming: `S<number>-<slug>.md` — e.g. `S1-multisite.md`, `S3-huddle-group-mapping.md`.

Suggested structure:

```markdown
# S<x>: <title>
Issue: #<n> · Timebox: <planned> · Actual: <spent>

## Question
## What we did
## Findings (verified facts, with versions)
## Conclusion — PASS/FAIL against the issue's pass condition
## Consequences for architecture.md / follow-up issues
```

## Records

| Record | Issue | Outcome |
|---|---|---|
| [S1: multisite + Disciple.Tools](S1-multisite.md) | #8 | PASS |
| [S2: Vietnamese audit](S2-vietnamese-audit.md) | #9 | PASS for architecture, not final language QA |
| [S3: huddle-to-group mapping](S3-huddle-group-mapping.md) | #10 | PASS |
| [S4: magic-link flow](S4-magic-link-flow.md) | #11 | PASS (device pass outstanding) |
| [S5: privacy model](S5-privacy-model.md) | #12 | PASS |
| [S6: content round-trip](S6-content-roundtrip.md) | #13 | PASS |
| [Polylang on the latoc landing page](2026-11-18-polylang-latoc-landing.md) (dated; not part of S1-S6) | #110 | GO-WITH-CONDITIONS |
