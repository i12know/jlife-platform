# Bible Translation Quotation Permissions (J-Life reference)

**Last verified:** 2026-11-05 (first compiled 2026-11-04). **Next review:** before any J-Life surface displays Bible *text* rather than references and links, or at the latest 2027-05-01.

**Scope:** limited Scripture quotation, and the boundary between quotation and Bible-text distribution, for J-Life studies, SOAP resources, websites and apps (U.S.-oriented). **Maintainer:** when a publisher revises its terms, update the row with the translation, edition, medium, URL and date checked.

Companion: [YouVersion Platform research note](youversion-platform.md), covering licensed Bible text through an API/SDK.

## Read this first

- Many publishers allow limited quotation **without asking for separate written permission**. Those are **conditional publisher permissions**, not a statutory fair-use rule; there is no universal "500-verse fair use" safe harbor.
- **A quotation allowance is not a Bible-reader license.** No allowance below authorizes hosting a continuous or searchable Bible text.
- **References and links are not quotation.** J-Life's 104-day schedules ([PR #50](https://github.com/i12know/jlife-platform/pull/50)) are reference-only and need no Bible-text license. This is the launch posture for the Nov 20 demonstration and Dec 20 launch ([#49](https://github.com/i12know/jlife-platform/issues/49), D12).
- Permissions are **publisher-, translation-, edition- and medium-specific.** Never carry one publisher's rule over to another translation.
- Count allowances across the **whole work, site or product** where a publisher says so. Never split content across daily pages to stay under a limit.

## Five different uses; five different rights questions

| Use | What governs it | J-Life position |
|---|---|---|
| **1. Quoting Scripture in a devotional, sermon, article or SOAP reflection** | The publisher's quotation allowance for that *work* (verse cap, share of the work, no complete book, required notice) | Allowed within the confirmed allowances below, with attribution. **Participant-written SOAP entries** are the participant's own private writing, not J-Life publishing Scripture. J-Life never pre-fills Bible text into the Scripture field. If sharing or exports ever publish SOAP text, treat any quoted verses as user content. |
| **2. Publishing limited quotations on a website** | The same allowance, *if* it covers digital/web use, plus any website-specific condition | Translation-specific: ESV and NASB allowances expressly cover digital/web use. **ERV needs Bible League's written permission for website use.** NIV and VIE2010 are unverified. |
| **3. Hosting a searchable or continuous Bible reader** | A distribution license from the rights holder. Quotation allowances do **not** apply. | Not permitted without a license. NASB's allowance even caps storage at 1,000 verses in an electronic retrieval system; Bible League requires written permission to host ERV on any server. |
| **4. Displaying Bible text through an authorized third-party API/SDK** | That provider's license and terms (e.g. YouVersion Platform licenses, the ESV API, API.Bible), only for versions actually licensed to *our* application, with its attribution rules | A possible future path; see the [YouVersion note](youversion-platform.md). Catalog availability on a website ≠ licensed to our app. |
| **5. Linking to a passage without reproducing it** | Nothing in copyright; the linked site's own terms govern its readers | **Always available.** J-Life's launch default. |

## Publisher terms: status per translation

"**Confirmed**" = read on the publisher's own page on the date shown. "**Conditional**" = confirmed, but a stated condition limits J-Life's use. "**Unverified**" = could not be read from the rights holder's source; do not rely on it.

| Translation | Status | Quotation allowance (verbatim conditions summarized) | J-Life-relevant limits | Source (checked) |
|---|---|---|---|---|
| **ESV** (Crossway) | **Confirmed** | Up to **500 verses** in print, digital and audio "without a formal license or express written permission," provided the verses are **not more than one-half of any one book**, are **less than 25%** of the total text of the work, and are **not quoted in a commentary or other biblical reference work**. | Required notice (2025 edition): *"Scripture quotations are from the ESV® Bible … © 2001 by Crossway … ESV Text Edition: 2025. The ESV text may not be quoted in any publication made available to the public by a Creative Commons license. The ESV may not be translated in whole or in part into any other language. Used by permission. All rights reserved."* Non-saleable church media may use "(ESV)" initials. Crossway separately offers the **ESV API** for non-commercial website/app use, under its own terms (not reviewed). | [crossway.org/permissions](https://www.crossway.org/permissions/) (2026-11-04) |
| **NASB** (Lockman) | **Confirmed** | Up to **1,000 verses** without express written permission, if the verses are **not a complete book** and **not more than 50%** of the work, and **no more than 1,000 verses are stored in an electronic retrieval system**. | Web pages/apps must show the **full copyright notice with a clickable link to www.Lockman.org**. A separate AI section permits AI responses to quote, but forbids bulk downloading, scraping and model training. NBLA/LBLA (Spanish) differ: 500 verses / 25%. | [lockman.org permission page](https://www.lockman.org/permission-to-quote-copyright-trademark-information/) (2026-11-04) |
| **ERV** — Easy-to-Read Version (Bible League International) | **Conditional** | Up to **1,000 verses** without written permission; **not a complete book**; **not more than 50%** of the work. Non-saleable church media may use the "ERV" initials instead of the full notice. | **Website restriction (confirmed):** electronic copies "may not be hosted on any kind of server (including a web or ftp server) without written permission," and Bible League asks for permission to use its text **on your web site**. So **ERV text on a J-Life web page needs Bible League's written permission** (or an authorized API that carries ERV). Copyright years differ between sources; use Bible League's current notice. The license page says "Last Updated: July, 2015". | [bibleleague.org/license-agreement](https://www.bibleleague.org/license-agreement/); [ebible.org/engerv/copyright.htm](https://ebible.org/engerv/copyright.htm) (2026-11-04) |
| **NIV** and other Biblica texts | **Unverified** | Not read from source in this review: biblica.com, thenivbible.com/faqs and harpercollinschristian.com all refused automated reading (2026-11-04/05). The long-standing NIV terms are commonly summarized as 500 verses / no complete book / under 25% of the work, but **that summary is not verified here**, nor are any platform-wide or app-licensing rules. | Owner (or a person in a browser) should confirm at biblica.com/permissions or HarperCollins Christian Publishing permissions, and record the date. Biblica is a listed YouVersion Platform publisher, so API display may be possible (use 4). | Not verified |
| **VIE2010 / RVV11** (© United Bible Societies; Bible Society of Vietnam) | **Unverified** | **No public quotation allowance located.** UBS's 500-verse guidelines are **explicitly for Spanish-language UBS texts only** (confirmed) and must not be applied to VIE2010. | Links work (use 5). For in-app text, ask Bible Society of Vietnam / UBS two separate questions: the quotation rule, and app/website display rights. UBS and Bible Society of Vietnam are **not** on YouVersion Platform's public publisher list, so API availability is unknown; see the [YouVersion note](youversion-platform.md). Tracked in [#3](https://github.com/i12know/jlife-platform/issues/3). | [ubscopyrights.org rules](https://ubscopyrights.org/index.php/pages/view/rules) (Spanish scope confirmed 2026-11-04); arc.dbs.org VIE record refused automated reading |

**Name collision:** "ERV" here is Bible League's *Easy-to-Read Version*, not the 1885 English Revised Version.

## Implementation rules for J-Life

1. **Default reading experience (launch):** daily references, short original introductions, and links to a publisher-authorized reading page. No Bible text stored or rendered.
2. **Editorial quotations in authored lessons:** record `version`, `edition`, `use type`, `quoted ranges`, `notice`, `source URL`, `date checked`, and any permission. Check the whole-work totals and exclusions. ERV on the website needs written permission first.
3. **Reading passages inside J-Life** is use 3 or 4, never use 1 or 2. It needs a license or an authorized API, recorded in [content-rights.md](content-rights.md).
4. **Never copy Scripture text** from websites into the repository, database seeds, email reminders, logs or cached assets, unless the specific use is within confirmed terms.
5. **Attribution:** use each publisher's exact current notice. Short initials only where the publisher allows them.
6. **Review triggers:** a new translation or edition, displaying text in-app, growth past cumulative limits, paid distribution, classification as a commentary/reference work, or AI-based reproduction.

## Corrections log

- **2026-11-05:**
  - ERV moved from "verified 1,000 verses" to **Conditional**, because of Bible League's website/server restriction.
  - NIV/Biblica moved from "Verified" to **Unverified**: the source couldn't be read in this review.
  - ESV wording corrected to "not more than one-half" of a book.
  - The verification date is no longer mislabeled as a next-review date.
  - The five-use distinction was added.
- **2026-11-04:** first compiled (PR #52).

## Sources

- Crossway, ESV permissions: https://www.crossway.org/permissions/
- Lockman Foundation, NASB permission to quote: https://www.lockman.org/permission-to-quote-copyright-trademark-information/
- Bible League International, license agreement: https://www.bibleleague.org/license-agreement/
- ERV copyright notice (eBible): https://ebible.org/engerv/copyright.htm
- United Bible Societies, guidelines for **Spanish-language** texts: https://ubscopyrights.org/index.php/pages/view/rules
- Biblica permissions (not readable in this review): https://www.biblica.com/permissions/
- Bible Society of Vietnam: https://biblevietnam.org/
- YouVersion Platform research: [youversion-platform.md](youversion-platform.md)
