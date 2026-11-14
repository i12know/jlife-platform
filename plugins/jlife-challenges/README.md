# jlife-challenges

The Bible Marathon engine (M5, #60): campaigns, groups and enrollment, private SOAP entries, **one point per person per day**, and deliberate group shares. Design and privacy matrix: [docs/challenges-engine-design.md](../../docs/challenges-engine-design.md).

## Owns

- Campaign calendar in the campaign's timezone (`includes/calendar.php`)
- Groups, hashed invite codes, enrollment, moves and removals, consent and guardian-consent records (`includes/groups.php`)
- SOAP entries (one free text, #98) with drafts and revision history; database-enforced scoring (`includes/soap.php`)
- Scoring engines (#98): a pluggable engine decides whether an entry earns the day's point; the default `soap-regex` checks the S, O, A, P parts (`includes/scoring-engine.php`). Chosen per campaign (`campaigns.scoring_engine`); each awarded point records the engine and version
- Progress, organizer counts and the score export without SOAP text (`includes/scores.php`)
- Group shares as snapshots of the whole entry, from the "Share this with my group" box on a save or from `/share`; hide for reported posts (`includes/shares.php`)
- The privacy gate (`includes/gate.php`), audit log (`includes/audit.php`), account-deletion purge (`includes/lifecycle.php`)

## Does not own

- Reading content and Bible links (`jlife-studies`, #59)
- Screens: Today, SOAP form, My Group (M6, #61); share-to-chat (M7, #62); admin screens (M9, #64)
- Sign-in (M3, #58) and email (M8, #63)
- Disciple.Tools: launch groups feed `jlife_huddles_group_membership`; D.T groups replace this source after launch (P9)

## Non-negotiables

- Every read/write goes through the gate. Unknown and forbidden look the same (`jlife_denied`).
- SOAP text is readable by its author only. Organizers see points and counts; they can hide a reported share but never browse feeds.
- Points come only from complete, on-time submissions. Sharing, groups and optional reading never change them.
- Never store Bible text; never pre-fill the Scripture field.

## Settings

- `jlife_challenges_require_guardian_consent` (option `'1'` default / `'0'`): with `'1'`, minors share only after a guardian's consent is recorded (D19 option a); `'0'` allows self-attested sharing (option b). Set with `wp option update jlife_challenges_require_guardian_consent 0`.

## Tests

`npm run cloud:test` (cloud) or CI's `wp-env` PHPUnit job: calendar, scoring, scoring engines, privacy, shares, share-on-save, lifecycle.
