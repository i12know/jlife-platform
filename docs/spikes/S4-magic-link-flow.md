# S4: Magic Link participant lesson flow

Issue: #11 · Timebox: 1 week · Actual: ~0.5 day (all flows exercised live; physical-device pass outstanding)

## Question

Can tokenized, no-login links carry a lesson-view + response flow for
mobile-first Vietnamese participants — and what are the privacy limits?
(technical-analysis.md §3, pilot-context.md, roadmap S4.) Per the
pre-implementation review on #11, the pass condition is the **privacy
tradeoff proven empirically**, not just mobile usability.

## What we did

Same live network and fixtures as S3 (WP 7.0, D.T 1.82.2 on `/hub/`).
`disciple-tools-bulk-magic-link-sender` **1.33.0** activated on HUB and the
core `DT_Magic_URL` framework confirmed present. Per the review's §4, the
participant flow itself is a **bridge-owned route on STUDY**
(`plugins/jlife-bridge/includes/magic-link.php`), not a D.T/HUB screen:

- `jlife_bridge_create_magic_link( $dt_contact_id, $dt_group_id, $lesson_id, $ttl )`
  mints a token stored as meta on the HUB contact record
  (`jlife_magic_key` + a JSON scope blob), returning a STUDY URL
  (`/?jlife_token=…`). Uses S3's identity language: the token *is* a
  (contact, huddle, lesson) triple — never broad contact access.
- The STUDY route resolves the token cross-blog through the bridge, renders
  the S6 lesson (title, scripture refs, teaching, reflection questions) plus
  one **leader-visible** response field, and re-embeds the token in the form —
  the flow is **cookie-independent** end to end.
- `jlife_bridge_revoke_magic_link()` revokes; re-minting regenerates (old
  token invalidated). The scope blob records `last_used` / `use_count`
  (audit trail).
- Responses land in a STUDY option for the spike; real storage with access
  control is S5's (#12) job.

## Findings (all observed, not inferred)

### 1. A magic link is a bearer token — demonstrated, not assumed

| Step | Observed |
|---|---|
| A (old-Android/Zalo UA, empty cookie jar) opens link | 200, lesson renders, **no Set-Cookie required** |
| A submits response | stored `{dt_contact_id: 5, dt_group_id: 8, visibility: leader}` |
| **B** (different device UA, no cookies) opens the *same* URL | 200 — sees the lesson **and A's submitted response** |
| B submits | **overwrites A's response as A** |

The system has no way to distinguish devices. Token identity: the D.T
**contact** (not a WP user, no session), readable scope: one lesson + that
contact's own response; writable scope: that one response. Every use is
logged (`use_count`, `last_used`).

### 2. Revocation, expiry, regeneration — PASS, including post-submit exposure

- **Revoked** link: GET → 403 with a generic "no longer available" page —
  zero bytes of lesson content or the previously submitted response; POST →
  403 and the write is blocked.
- **Expired** link (TTL elapsed): 403 with the *identical* generic page —
  revoked and expired are indistinguishable to the holder (no oracle).
- **Regeneration**: minting a new link invalidates the old token (403) while
  the new one works (200). Old and new do not coexist — one live link per
  contact by design.

### 3. Response-type sensitivity classification (the S4/S5 shared rule)

| Response type | Via magic link? | Rationale |
|---|---|---|
| Lesson view | **Yes** | content the leader chose to send |
| Progress / check-off | **Yes** | low sensitivity, aggregate-bound |
| Short leader-visible response | **Yes, with caveats** | forwarding exposes/overwrites it (finding 1); acceptable for pilot-level content, participants must be told not to forward |
| Huddle discussion post | **No** | huddle-private; a forwarded link would impersonate a member in group space |
| Private reflection note | **No — accounts required** | author-only content must not ride a forwardable bearer token |

The rendered page states the forwarding warning in participant-facing
language, and private notes are simply not reachable through the link flow.

### 4. Mobile/in-app-browser posture — automatable parts verified

- **Query-param token survives everything** — the flow never depends on
  cookies, localStorage, or redirects, which is precisely what Zalo/Messenger
  in-app browsers are known to drop. Verified: full view+submit round trip
  with empty cookie jars under an Android 9 Zalo UA and a Facebook in-app UA.
- **Link-preview leak checked**: chat apps fetch the URL anonymously for
  previews; the page `<title>` is a generic "Lesson" and no Open Graph tags
  are emitted — a preview reveals nothing about the participant or content.
- Page is a single small HTML document (no theme CSS/JS payload), viewport
  meta set — friendly to old devices and throttled connections.

**Outstanding (needs physical hardware):** the actual tap-test on an older
Android device over a throttled connection, opening from inside Zalo and
Messenger. Recipe: mint a link
(`wp eval 'echo jlife_bridge_create_magic_link( <contact>, <group>, "<lesson_id>", WEEK_IN_SECONDS );'`),
send it to yourself in Zalo, open, submit, then revoke and confirm the 403
page. Everything else in this spike is device-independent.

### 5. Participants never touch HUB — PASS

The link lands on STUDY root; the D.T/HUB UI is never rendered to a
participant. HUB's only involvement is meta storage on the contact record,
read through bridge functions.

## Conclusion — PASS (with the device pass outstanding)

Magic links work for the pilot's participant flow and their privacy limits
are now *measured*: safe for lesson view, progress, and pilot-level
leader-visible responses; **not** safe for huddle discussion or private
notes, which require accounts. Revocation/expiry/regeneration behave
correctly including after submission, and the flow is structurally immune to
in-app-browser cookie loss.

## Consequences / follow-ups

- **S5 (#12):** encode the table above as policy — link-authenticated writes
  may touch only progress/leader-visible response surfaces; `private_note`
  and `huddle_thread` require account identity. (This is the "special rule"
  #12's review asks for; S4 and S5 now agree by construction.)
- **Pilot ops:** one live link per participant; leaders regenerate on any
  suspected forwarding; participant-facing copy already warns against
  forwarding. Keep lesson titles generic enough for chat previews.
- **Upgrade path:** when huddle discussion or private notes ship, participants
  move to accounts (Magic Link → account claim flow is a natural bridge:
  the token already identifies the D.T contact).
- **Owner action:** run the physical Android/Zalo/Messenger recipe above and
  append device/network observations to this doc.
