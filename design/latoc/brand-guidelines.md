---
title: LATOC brand guidelines (evergreen)
status: draft
review_state: awaiting owner review
issue: "#110"
---

# LATOC brand guidelines (evergreen)

**Life and Teaching of Christ**, abbreviated **LATOC**. This document covers the permanent brand only. Campaign dates, day counts and badges are separate (see the last sections).

## Name

- Full name: **Life and Teaching of Christ**. Short form: **LATOC**.
- The public logo and headings use "Christ", not "Jesus".
- Do not bake a year or a day count into the logo or the brand name.
- The Vietnamese rendering of the name is a translator decision; see the translator notes in [landing-page.vi.md](../../content/latoc/landing-page.vi.md).

## Logo

- **Format:** square.
- **Concept:** an open Bible, a cross, and a sunrise.
- **Asset status:** `design/latoc/logo-square.png` is **NOT yet checked in.** It must come from the owner's approved export of the design conversation. A mockup or an image link is not an approved asset. Do not create, redraw or fabricate the image in this repo. An SVG master may follow later only if an authentic vector is made.
- **Alt text (EN):** "Life and Teaching of Christ logo: an open Bible with a cross rising over it and a sunrise behind."
- **Alt text (VI):** supplied with the Vietnamese page, pending human review.
- If the logo sits next to visible text reading "Life and Teaching of Christ", the image may be marked decorative (empty alt) to avoid reading the name twice. Otherwise use the alt text above.

## Palette (owner-approved direction: red, leather brown, warm cream/tan)

The hex values below are **proposed, confirm against approved export.** Sample them from the approved logo before use.

| Role | Name | Proposed hex |
|---|---|---|
| Accent / primary action | Red | `#A12B24` |
| Text and dark surfaces | Leather brown | `#4A2E1F` |
| Page background | Warm cream | `#F7EFE0` |
| Secondary surface | Tan | `#E3D2B4` |

Expected contrast (WCAG AA: 4.5:1 for normal text, 3:1 for large text and UI boundaries). These are estimates; re-measure with the final hex values and record the ratios here.

| Pairing | Use | Expectation |
|---|---|---|
| Leather brown on cream | Body text | Passes AA comfortably |
| Leather brown on tan | Body text on panels | Passes AA |
| Cream on red | Button label | Should pass AA |
| Red on cream | Links, headings | Should pass AA |
| Red on tan | Small text | Marginal; check before use |
| Tan on cream | Decoration only | Fails; never for text |

Rules: never convey meaning by color alone; keep visible focus outlines.

## Sizing and clear space

- Minimum display size: 48 px square on screen. Check that the cross and sunrise stay legible; raise the minimum if they blur.
- Header/nav: about 48 to 64 px. Hero: about 120 to 160 px, never wider than the viewport.
- Clear space: at least one quarter of the logo's width on every side.
- Do not stretch, recolor, rotate, add effects, or place on busy photos. On dark backgrounds use a cream tile behind the logo.
- Ship a compressed file; the page must stay fast on low-end phones.

## Evergreen brand vs campaign

| Evergreen (this document) | Campaign-specific (separate) |
|---|---|
| Name, logo, palette, tone | "104 Days. One Life. A Journey Worth Taking." |
| Square logo, no year or day count | Dates (Dec 20, 2026 to Apr 2, 2027), day count, milestone badges |
| Lives in `design/latoc/` | Lives in `content/latoc/reading-journey-*.md` and campaign assets |

Future cohorts may differ in length and dates. Campaign badges are separate files placed next to the logo, never part of it.

## Attribution

The reading journey is inspired by a printed chronology from PlusNothing. The acknowledgement wording is in [landing-page.md](../../content/latoc/landing-page.md) and follows [docs/content-rights.md](../../docs/content-rights.md): credit the source's role, reproduce no booklet text or imagery, imply no partnership. The leather-brown palette direction reflects the printed booklet's look as inspiration only; do not copy its artwork.

## Repository safety

No real names, contacts, partner details, or screenshots from private systems (PRD §21). Use only owner-supplied, approved brand assets.
