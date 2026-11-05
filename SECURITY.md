# Security Policy

J-Life stores private discipleship data: SOAP reflections, private notes, group membership. Please report security problems privately so they can be fixed before anyone else learns of them.

## How to report

Use GitHub's **private vulnerability reporting**: go to this repository's **Security** tab and choose **Report a vulnerability**. Your report is visible only to the maintainers.

Please **do not** open a public issue, pull request, or discussion for a suspected vulnerability. Never include real participants' data in a report; describe the problem with test accounts or fake `.test` identities.

Helpful details:

- what an attacker could read, change, or do, and as which kind of user (anonymous, participant, group leader, admin)
- steps to reproduce, the affected URL or code path, and the commit you tested
- whether you believe it is being exploited

## What happens next

- We aim to acknowledge a report within **7 days** and to keep you updated until it is resolved.
- Fixes for issues that expose participant data take priority over all feature work.
- With your permission, we credit reporters once a fix is released.

## Scope

In scope: the J-Life plugins in `plugins/`, the deploy tooling in `bin/` and `.github/`, and the documented privacy rules (`docs/spikes/S5-privacy-model.md`, `docs/integration-boundaries.md`).

Out of scope here: vulnerabilities in WordPress core, Disciple.Tools, or other third-party plugins. Report those to their own projects. Also out of scope: findings that need physical access to a participant's unlocked device.

This project is pre-release and run by a small volunteer team; there is no bug bounty.
