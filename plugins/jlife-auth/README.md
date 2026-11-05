# jlife-auth

Participant sign-in for J-Life (M3, #58): **a 6-digit code by email, no passwords and no sign-in links**, plus joining a group with an invite code, the age band (13+), language choice and the consent step.

## Pages

| Route | What it does |
|---|---|
| `/join/?code=INVITE` | Invite code, first name, email, age (18+ / 13–17; under-13s cannot join), language (English / Tiếng Việt) → emailed code → account created, group joined → consent |
| `/signin/` | Email → emailed code → signed in on this phone for 180 days |
| `/consent/` | The consent points (D15 draft wording, version `draft-2026-11-05`); asked again whenever the version changes |

## Rules

- Codes: 6 digits, single use, 10 minutes, stored only as a keyed hash; 5 wrong guesses void the code.
- No account enumeration: `/signin/` answers the same whether or not an address has an account.
- Rate limits: 3 codes per email per 15 minutes; 10 per IP per hour.
- Participants (no editing rights) never see wp-admin or the admin bar; staff keep WordPress's normal sessions.
- In-app browsers (Messenger, Zalo, …) get a tip to open the page in Safari or Chrome so the session sticks.
- Signed-in participants see J-Life in their chosen language (strings are translation-ready; Vietnamese translations still to be written).

## Owner follow-ups

- Replace the consent wording with the final text from #65 and bump `JLIFE_AUTH_CONSENT_VERSION`.
- Email delivery uses WordPress `wp_mail`; reliable delivery comes with the transactional email provider in #63 (M8). Delivery failures are logged on the server.

## Depends on

`jlife-challenges` (invite codes, enrollment, consent records).
