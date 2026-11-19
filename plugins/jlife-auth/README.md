# jlife-auth

Participant sign-in for J-Life (M3, #58): **a 6-digit code by email, no passwords and no sign-in links**, plus joining a group with an invite code, reading on your own or starting a group (adults), the age band (13+), language choice and the consent step.

## Pages

| Route | What it does |
|---|---|
| `/join/?code=INVITE` | Invite code, first name, email, age (18+ / 13–17; under-13s cannot join), language (English / Tiếng Việt) → emailed code → account created, group joined → consent |
| `/join/?mode=solo` | **Read on my own** (#112): the same form without a code → emailed code → account created and enrolled in the open campaign with **no group** → consent. The page is a choice: `/join/` (invite code) links to it, and it links back. A person who is already signed in can join a group later from `/group/` ("Have a code? Join a group", up to 3 groups). |
| `/join/?mode=start` | **Start a group** (#112 part 2, adults only): group name instead of a code → emailed code → account created, the group made with the person as its starter → a page shows the **invite code and link once** (copy buttons) → consent. A minor is refused before any email is sent, and again on the stored age band. The three paths link to one another |
| `/signin/` | Email → emailed code → signed in on this phone for 180 days |
| `/consent/` | The consent points (D15 draft wording, version `draft-2026-11-05`); asked again whenever the version changes |

The join form also has an **unticked** "Email me each morning's reading" box (#63 Phase B): ticked, the person is opted in to daily reminders in the language chosen on the form (stored by `jlife-challenges`; the consent page says reminders are optional). Unticked means no reminders; people already enrolled are asked once on Today.

## Rules

- Codes: 6 digits, single use, 10 minutes, stored only as a keyed hash; 5 wrong guesses void the code.
- No account enumeration: `/signin/` answers the same whether or not an address has an account.
- Rate limits: 3 codes per email per 15 minutes; 10 per IP per hour.
- Participants (no editing rights) never see wp-admin or the admin bar; staff keep WordPress's normal sessions.
- In-app browsers (Messenger, Zalo, …) get a tip to open the page in Safari or Chrome so the session sticks.
- After the emailed code is checked, a join that did not happen (already in that group, at the 3-group limit, a minor starting a group) shows a short notice page with **Continue**; the person is signed in either way.
- Signed-in participants see J-Life in their chosen language (D13). `languages/jlife-auth-vi.l10n.php` is a **machine-assisted DRAFT** Vietnamese translation of every string, awaiting a native-speaking translator's review. Signed-out visitors see the site language, or pick **English | Tiếng Việt** on `/join`, `/signin` and `/consent` (`?lang=`); the emailed code is in the language chosen on the form.

## Owner follow-ups

- Replace the consent wording with the final text from #65 and bump `JLIFE_AUTH_CONSENT_VERSION`.
- Email uses WordPress `wp_mail`, with an opt-in, site-scoped Brevo SMTP transport (#63). Private credentials come from `~/.latoc/mail.php`, included by `wp-config.php`; see `config/latoc-mail.example.php` and the staging runbook. With Brevo enabled, invalid configuration and send failures do not fall back to host mail. Only a status code and timestamp are stored, never recipients, codes, bodies or raw provider errors. `wp jlife mail status` reports configuration health; `wp jlife mail probe` checks verified TLS without sending. A successful send means acceptance, not inbox delivery. Daily reminders remain separate work.

## Depends on

`jlife-challenges` (invite codes, enrollment, consent records).
