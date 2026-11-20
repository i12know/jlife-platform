# jlife-challenges

The Bible Marathon engine (M5, #60): campaigns, groups and enrollment, private SOAP entries, **one point per person per day**, and deliberate group shares. Design and privacy matrix: [docs/challenges-engine-design.md](../../docs/challenges-engine-design.md).

## Owns

- Campaign calendar in the campaign's timezone (`includes/calendar.php`)
- Groups, hashed invite codes, enrollment (one per person per campaign), **group membership** (solo, or up to 3 groups per campaign; `group_members`, #112), moves and removals, consent and guardian-consent records (`includes/groups.php`)
- SOAP entries (one free text, #98) with drafts and revision history; database-enforced scoring (`includes/soap.php`)
- Scoring engines (#98): a pluggable engine decides whether an entry earns the day's point; the default `soap-regex` checks the S, O, A, P parts (`includes/scoring-engine.php`). Chosen per campaign (`campaigns.scoring_engine`); each awarded point records the engine and version
- Progress, organizer counts and the score export without SOAP text (`includes/scores.php`)
- The Help, Rules and Privacy pages (#65): where they are (three page-ID options), the footer links on every participant screen, the one-time DRAFT seed (`wp jlife help-pages seed`, copy in `data/help-pages.json`) (`includes/help-pages.php`)
- The pilot feedback link (#171): a "Pilot Feedback" link at the end of the footer (and alone on the public `/reading/` pages) to the post named by `jlife_pilot_feedback_post_id`; no post content or comment setting comes from code (`includes/pilot-feedback.php`)
- Daily reading reminder choices (opt-in, language, `reminder_prefs`), the Today prompt, the My Marathon switch and the public one-click `/unsubscribe/` (#63 Phase B part 1; **no sending yet**) (`includes/reminders.php`)
- The **My Bible** picker on My Marathon (#140): "My Bible" and an optional "Also show…" select under the email-language row, `POST /jlife/v1/bible`, and the no-JavaScript form (`includes/bible-choice.php`, `assets/bible.js`). The choice itself (two registry keys in user meta) is stored and resolved by `jlife-scripture`; this plugin only draws the picker and answers "My language" for it. Without `jlife-scripture` the picker is absent.
- Today and `/soap` show the day's Scripture on the page, in the participant's own Bible(s), when `jlife-scripture`'s `jlife_scripture_native_reader` option is on (#139); the framed Bible Gateway reader (#92) is the flag-off path (`jlife_challenges_render_reader()`, `includes/screens.php`).
- Start a group (adults only; the starter holds the invite code and nothing more), leave a group, "Groups you left" on `/share`, and organizer oversight (`wp jlife group list|rename|disable|enable`) (`includes/groups.php`, `includes/group-admin.php`, `includes/share-screens.php`)
- **J-Life → Groups** (#64 part 1): the organizer's plain admin screen to create and rename groups, make, replace or revoke an invite code with an expiry, and add, move or remove members, all audited and nonce-checked, display names only (`includes/groups-admin-page.php`, matrix §4g)
- **J-Life → Daily view** and the **CSV download** (#64 part 2): per-participant status and point and per-group counts for one day, and a nonce-checked, formula-guarded CSV with no SOAP text, email or tokens (`includes/daily-view.php`, `includes/daily-admin-page.php`, matrix §4h)
- Group shares as snapshots of the whole entry, one per group: one unticked box per group on a save, or a section per group on `/share`; a solo reader has none; hide for reported posts (`includes/shares.php`)
- The privacy gate (`includes/gate.php`), audit log (`includes/audit.php`), account-deletion purge (`includes/lifecycle.php`)

## Does not own

- Reading content and Bible links (`jlife-studies`, #59)
- Screens: Today, SOAP form, My Group (M6, #61); share-to-chat (M7, #62); the public `/reading/` day pages, a signed-out Today (#106, `includes/reading-page.php`)
- Sign-in (M3, #58) and email (M8, #63)
- Disciple.Tools: launch groups feed `jlife_huddles_group_membership`; D.T groups replace this source after launch (P9)

## Non-negotiables

- Every read/write goes through the gate. Unknown and forbidden look the same (`jlife_denied`).
- SOAP text is readable by its author only. Organizers see points and counts; they can hide a reported share but never browse feeds.
- Points come only from complete, on-time submissions. Sharing, groups and optional reading never change them.
- Never store Bible text; never pre-fill the Scripture field.

## Settings

- `jlife_challenges_help_page_id`, `jlife_challenges_rules_page_id`, `jlife_challenges_privacy_page_id` (page IDs, English page; Polylang resolves the reader's language): the pages the footer, the consent step and the SOAP help link point to. A link is left out, not broken, while its page is unset or not published. The deploy fills an empty option when it seeds the drafts; the owner edits and publishes the pages in wp-admin (`docs/staging-latoc.md`).
- `jlife_pilot_feedback_post_id` (post ID): the pilot feedback post (#171). The link shows only while that post is published (a published Polylang translation is used for its language, else the post itself); delete the option to remove the link. Set by the owner with WP-CLI (`docs/staging-latoc.md`).
- `jlife_challenges_require_guardian_consent` (option `'0'` default / `'1'`): off by default per D19 (owner decision 2026-11-05, #55): participants aged 13–17 share with their own group with no guardian step in J-Life, because the church's existing consent process covers it. A deployment whose church needs a recorded guardian consent turns it on with `wp option update jlife_challenges_require_guardian_consent 1`; minors then share only after an organizer records it.

## Tests

`npm run cloud:test` (cloud) or CI's `wp-env` PHPUnit job: calendar, scoring, scoring engines, privacy, shares, share-on-save, memberships and multi-group sharing, start a group and its access control, the group screens, translations, lifecycle.
