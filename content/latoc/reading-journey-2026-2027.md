---
title: LATOC reading journey 2026-2027 (campaign facts)
status: draft
review_state: facts verified against primary CSV; awaiting owner review
issue: "#110"
verified_against: content/reading-plans/2026-12-20_life-and-teachings-of-jesus_primary.csv
verified_on: 2026-11-18
source_attribution: Owner's primary 104-day schedule (based on the PlusNothing arrangement); references only.
rights_note: Scripture is cited by reference only. No Bible text and no booklet text. See docs/content-rights.md.
---

# LATOC reading journey 2026-2027: campaign facts

These facts are specific to one campaign. The evergreen brand lives in [design/latoc/brand-guidelines.md](../../design/latoc/brand-guidelines.md). Future cohorts may use different dates and lengths, so do not copy these values into brand assets.

Source of truth: the **primary** plan, `content/reading-plans/2026-12-20_life-and-teachings-of-jesus_primary.csv`. The supplemental harmony plan is optional enrichment and is **not** used for any fact below.

## Dates

| Fact | Value |
|---|---|
| Start | Sunday, December 20, 2026 (Day 1) |
| End | Friday, April 2, 2027 (Day 104) |
| Length | 104 consecutive days, both dates inclusive |

Checked: the CSV has 104 data rows; Day 1 is 2026-12-20 and Day 104 is 2027-04-02; dates run consecutively.

## Milestones (verified against the primary CSV)

| Day | Date | Occasion | Scripture references in the CSV | Section(s) |
|---|---|---|---|---|
| 1 | Dec 20, 2026 | First day | Luke 1:1-4; John 1:1-18 | 1.1 "The Beginning" |
| 5 | Dec 24, 2026 | Christmas Eve, birth of Christ | Luke 1:67-80; Luke 2:1-7 | 1.7, 1.8 (1.8 "The Birth of Jesus" is Luke 2:1-7) |
| 6 | Dec 25, 2026 | Christmas Day | Luke 2:8-21; Luke 2:22-38 | 1.9, 1.10 (shepherds; presentation in the temple) |
| 97 | Mar 26, 2027 | Good Friday, the crucifixion | Luke 23:26-32; Matthew 27:33-34; Luke 23:33b-34a; John 19:23-24; John 19:19-22; Matthew 27:39-43; Luke 23:39-43; John 19:25-27 | 5.54 "Jesus Is Nailed to a Cross" |
| 99 | Mar 28, 2027 | Easter Sunday, the resurrection | Matthew 27:62-66; Mark 16:1a; Luke 24:10b, d; Mark 16:1b-2; Matthew 28:2-6; Luke 24:2-7; Mark 16:7; Luke 24:8; Matthew 28:8; Luke 24:10b-11; John 20:3-10 | 5.57, 6.1 |
| 104 | Apr 2, 2027 | Final day: ascension, Pentecost, conclusion | Luke 24:50-51; Acts 1:9b-11; Luke 24:52-53; Acts 2:1-11; John 21:25; John 20:31 | 6.12, 6.13, 6.14 |

Calendar check: Mar 26, 2027 is a Friday and Mar 28, 2027 is a Sunday. Day 97 = Dec 20 + 96 days = Mar 26. Day 99 = Mar 28.

## Wording notes for copy writers

- **Christmas:** Dec 24 combines two readings (Luke 1:67-80, then Luke 2:1-7 for the birth). Safe wording: "by December 24 and 25 you will be reading the story of his birth." Avoid implying Day 5 is the birth story alone.
- **Good Friday:** Day 97 (Mar 26) is the crucifixion reading. His death and burial are in Day 98 (Mar 27, sections 5.55 and 5.56). Safe wording: "the cross on Good Friday."
- **Easter:** Day 99 (Mar 28) holds the resurrection narrative (section 6.1, "The Resurrection of Jesus"). Safe wording: "the resurrection on Easter Sunday."
- **Day 104:** CSV titles are "Jesus Is Carried up Into Heaven", "The Coming of the Holy Spirit" and "The Conclusion". "Pentecost" is the public name for the coming of the Holy Spirit (Acts 2); the CSV does not use that word.
- Section titles and references are the owner's schedule data. Do not add Bible text or booklet prose.

## Discrepancies

None against issue #110 or the developer comment. Clarifications only: Day 5 and Day 6 each contain two readings, and the death of Christ falls on Day 98, not Day 97.

## Re-verification

Run `npm run content:validate` (checks 104 days, Dec 20 to Apr 2) and re-read the rows above after any CSV change.
