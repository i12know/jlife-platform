---
title: LATOC public landing page (English source copy)
status: draft
review_state: awaiting owner review
language: en
issue: "#110"
translation_counterpart: landing-page.vi.md
campaign_facts: reading-journey-2026-2027.md
source_attribution: Original copy written for this project. Reading schedule references come from the owner's primary 104-day plan (content/reading-plans/).
rights_note: No Scripture text, no booklet text. Scripture is cited by reference only. See docs/content-rights.md.
---

# LATOC landing page: English source copy

**Review state:** DRAFT, awaiting owner review. Not approved for publishing. Not authorization to deploy (issue #110).

**How to read this file.** Everything under "Page copy" is proposed public text. The `PENDING` block and "Notes for reviewers" are for reviewers only and must not be published.

**Brand rule.** The public brand is **Life and Teaching of Christ (LATOC)**. Do not use "Jesus" in the logo or the brand heading. Campaign dates and the "104 days" count belong to the hero and campaign facts, never to the permanent logo. See [brand-guidelines](../../design/latoc/brand-guidelines.md).

Campaign facts (dates, milestones) live in [reading-journey-2026-2027.md](reading-journey-2026-2027.md). If a date changes there, update it here.

---

## Logo

- **Image:** `design/latoc/logo-square.png` (not yet checked in; owner's approved export required).
- **Alt text (EN):** "Life and Teaching of Christ logo: an open Bible with a cross rising over it and a sunrise behind."
- **Alt text (VI):** see the Vietnamese counterpart.

## Page copy

### Hero

**Life and Teaching of Christ** (LATOC)

# 104 Days. One Life. A Journey Worth Taking.

Walk through the life of Christ from his birth to his resurrection, one short reading at a time. Starting December 20, 2026.

[Primary CTA: see "CTA block" below]

---

### What is this journey?

For 104 days, we read the four Gospels together as one story, in the order things happened.

Matthew, Mark, Luke and John each tell the life of Christ from a different angle. Here they are woven into a single chronological path, so you can follow Christ step by step: his birth, his teaching, his cross, his resurrection.

Each day is a short reading. You can do it in a few minutes.

The goal is not to finish a book. The goal is to know Christ and follow him.

---

### Two milestones along the way

**Christmas.** The journey begins on December 20. By December 24 and 25 you will be reading the story of his birth, in the very days we celebrate it.

**Easter.** Near the end, you will walk through the cross on Friday, March 26, and the resurrection on Sunday, March 28, in the same days we remember them.

The journey ends on April 2, 2027, with the ascension, the coming of the Holy Spirit, and a conclusion.

---

### How each day works: Read, Reflect, Respond

**Read.** Open the day's passage. It is short.

**Reflect.** Ask what this passage shows you about Christ.

**Respond.** Decide one thing you will believe, change, or do today.

### SOAP, a simple way to reflect

SOAP gives your reflection a shape:

- **Scripture:** what did you read?
- **Observation:** what do you notice?
- **Application:** what does this mean for your life?
- **Prayer:** talk with God about it.

A few honest sentences are enough. There is no right answer to perform.

---

### Come on your own, or with others

**On your own.** Read at your own pace each day and keep your reflections.

**With a group.** Many people will walk this journey with a small group from their church or community, sharing what they are learning and praying for one another. A group is where a journey like this tends to stay alive.

---

### Final call

[Final CTA: see "CTA block" below]

*This journey is not about points or prizes. It is about knowing and following Christ.*

---

## CTA block

> **PENDING OWNER DECISION.** The issue #110 developer comment lists three options. The copy below is written for **option (a)**, the recommended one. Do not build the CTA until the owner chooses.
>
> - **(a) Recommended for Dec 20:** CTA goes to the invite-code join page. No contact form, no new participant data.
> - **(b)** A public "open group" invite code for walk-ins. Changes the campaign model; needs a privacy review.
> - **(c)** An interest/sign-up form. Collects personal information; needs `integration-boundaries.md` and a `jlife-huddles` sensitivity-matrix row first.
>
> Why: there is **no open sign-up**. `/join/` requires a group invite code, and `/signin/` is only for existing accounts. Verify routes at build time.

### Option (a) copy

**Helper text above the button:** Have an invite code?

**Primary button:** Join the Journey -> `/join/`

**Line for people without a code:** Don't have a code yet? Ask your church or group leader for one.

**Secondary link:** Already joined? Sign in -> `/signin/`

Use the same block in the hero and in the final call.

If the owner chooses (b) or (c), this block and the Vietnamese counterpart must be rewritten.

---

## Acknowledgement (footer)

Suggested wording:

> This reading journey was inspired by the printed *Life and Teachings of Jesus* chronology from PlusNothing. It is an independent project and is not affiliated with or endorsed by PlusNothing. Scripture is referenced by passage only.

Notes:
- Follows [docs/content-rights.md](../../docs/content-rights.md): references and factual chronology need no license; credit the source's role; no booklet text, maps, images or study prose reproduced; no Bible translation text. That doc does not constrain the credit wording itself, so this is a suggestion.
- The booklet title appears only as a bibliographic identifier. Owner to confirm the title wording and whether naming PlusNothing is acceptable. The footer explicitly disclaims partnership.
- If a Bible link is shown on the page later, follow the publisher notice rules in content-rights.md.

## Notes for reviewers

1. **Tone.** Warm, short paragraphs, plain words for phones and for translation. Flag anything that sounds like marketing.
2. **No points or prizes** appear except the closing line saying they are not the point. Confirm the balance given the app has scoring.
3. **"Four Gospels as one story"** describes the owner's chronological plan; no booklet text is reproduced.
4. **SOAP** here is Scripture, Observation, Application, Prayer. Confirm this matches the app screens.
5. **Milestone wording** matches the primary CSV; see the facts file for caveats (Dec 24 holds two readings; Good Friday is the crucifixion reading, his death is on Mar 27).
6. **Not on this page, by design:** contact details, church or partner names, participant data, screenshots, Bible text.
7. **Open items:** CTA option (a/b/c); logo export; final footer wording; Vietnamese reviewer.
