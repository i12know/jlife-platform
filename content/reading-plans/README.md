# 104-Day Life and Teachings of Jesus: source schedules (2026-12-20 to 2027-04-02)

**Status:** Data-only proposal for review under [Issue #49](https://github.com/i12know/jlife-platform/issues/49). These are exports of the existing planning workbooks provided by the project owner, **not** a newly generated or rebalanced reading plan. **Do not treat this pull request as authorization to implement application code or publish third-party copyrighted material.**

## Authoritative role of each file

1. `2026-12-20_life-and-teachings-of-jesus_primary.csv` — **canonical daily assignments for ALL Bible Marathon participants**. Based on the PlusNothing `Life and Teachings of Jesus` arrangement; use its `Date`, `Day`, `Scripture (PlusNothing)`, `Titles`, and `PlusNothing Sections` as the reference schedule, as confirmed by the project owner.
2. `2026-12-20_harmony-parallel_supplemental.csv` — **optional deeper study**. Robertson parallel accounts and references enrich discussion, including for the SonLife pastors' group. It is **not** a separate scoring curriculum, and it must not add extra reading requirements or points for anyone.

Both exported source tables contain **exactly 104 consecutive days** (Day 1 = 2026-12-20; Day 104 = 2027-04-02). The two sources intentionally differ in the Scripture passages for a given day. **Do not merge, overwrite, swap, or mechanically combine them.** Preserve the Day and Date mapping and use stable IDs. One participant has one scored day regardless of whether they consult the supplementary passages.

## Provenance / originals

- Primary Excel workbook: `2026-12-20to2027-04-02_Life-and-Teachings-of-Jesus_Bible-Reading-Plan.xlsx`.
- Supplemental Excel workbook: `2026-12-20to2027-04-02_harmony-parallel-reading-plan.xlsx`.
- Both originals were created 2026-09-06 and are held privately by the project owner (folder `2026-12-20to2027-04-02_Life-and-Teachings-of-Jesus`). They are deliberately not linked here because this repository is public; ask the owner for access.

The CSV data are text exports retaining the column content returned by the authenticated Drive reader. They include an unnamed leading row-index column from the export. **Preserve source values but normalize types explicitly in the importer**, not via spreadsheet floating-point inference. Do not import the unnamed column as a source ID.

## Review flags before implementing an importer

- **Section identifiers are strings, not decimals.** The owner explicitly confirmed that primary Day 53 must use `4.40` (not `4.4`) and Day 81 must use `5.20` (not `5.2`); both values are corrected in this PR. Preserve the trailing zeros when parsing, storing, displaying, and exporting section IDs. The original Drive workbook has not been modified as part of this GitHub correction.
- The supplemental plan includes additional parallel accounts, and daily word counts are therefore not directly comparable with the primary readings.
- **Rights scope (owner decision):** The plan contains Scripture references, day/date assignments, factual chronology, section identifiers, ordinary short headings, word counts and notes. The presence of a PlusNothing or Robertson source reference **does not by itself create a permission prerequisite** for using this reading schedule. Referencing biblical passages and factual events is distinct from reproducing a publisher's expressive text. Some creative selection/arrangement can be protected in appropriate circumstances; review any substantial verbatim third-party expression if actually included. **Do not reproduce third-party Bible translation text, substantive commentary, essays, maps, images, or other licensed material without the applicable rights.** Follow `docs/content-rights.md`; link to authorized Bible sources instead of copying translation text. The current reference-only schedules may proceed through technical validation and import without a blanket PlusNothing license gate.
- Audit Scripture reference syntaxes (including cross-chapter ranges and partial verses) before generating deep links; do not discard unusual citations.
- The CSV is a faithful data handoff. The owner has **approved the primary/supplemental roles** and confirmed the Day 53 `4.40` and Day 81 `5.20` corrections. Remaining pre-publication review is technical integrity (including Bible links and parser edge cases), provenance/attribution, and any **actual copied expressive content**; it is not a blanket license-approval milestone for reference-only data.

## Build implications

- Update draft plan D11 in #49: **do not generate a fresh split of Robertson's 185 sections**. Import the existing primary 104-day curriculum and expose the supplemental parallels as optional enrichment.
- For the Nov 20 demonstration, use the *actual* Day 1 primary passage (**John 1:1-18**) and optionally show Day 1 parallel references (**Luke 1:1-4; John 1:1-18**).
- Keep all participants on one scoring calendar with one daily SOAP. Optional advanced commentary/pastor discussion does not increase points.
- This change only supplies reference data for review, not app code. The reference-only reading schedule is approved for development use without seeking an arrangement license; verify rights separately if the app later includes third-party expressive content or Bible translation text, and confirm Bible deep-link targets.
