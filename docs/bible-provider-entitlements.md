# J-Life Scripture provider entitlement registry

**Updated:** November 18, 2026. This document records what J-Life's YouVersion app **can see in its entitled catalog**, what has passed passage smoke tests, and what remains an unapproved candidate. **It does not authorize native Bible-text rendering or change live translation defaults.**

- Machine-readable record: [`bible-provider-registry.json`](../content/reading-plans/bible-provider-registry.json).
- Runtime allowlist (#139): [`bible-versions.json`](../plugins/jlife-scripture/data/bible-versions.json), which replaced `bible-links.json`. Each language's **fallback** link still targets English **ERV** and Vietnamese **BPT** at Bible Gateway; do not silently change it. Its YouVersion entries (NIrV 110, NIV 111, KTHD 1638) stay `enabled: false` / `display: link` until the owner gates on #139 clear, and `bin/validate-scripture.js` refuses native rendering of a YouVersion version this inventory has not marked `ready_for_production`.
- Owner's conditional native-reading selection from [Issue #78](https://github.com/i12know/jlife-platform/issues/78#issuecomment-6879689851): **NIrV** (en, 110) and **KTHD** (vi, 1638). A later discussion suggested NIV as a default, but **no new owner decision to replace this selection** has been recorded.

## Meaning of the statuses

1. **App catalog entitlement:** the application's YouVersion API returned this version as licensed in its catalog on 2026-11-14. This is evidence of availability **to the J-Life YouVersion app**, not unconditional distribution rights.
2. **Passage smoke test passed:** the test also successfully requested John 1:1-18 (without logging Bible text).
3. **Production text permitted:** **not yet established** for any version. Review the exact accepted publisher terms for display, attribution, caching/storage, any AI-use restrictions, and whether J-Life's free church marathon with an offline cash prize qualifies under noncommercial terms.
4. **Candidate:** no claim of a J-Life API.Bible license or deployable text rights. Never configure it as enabled based on a public catalog alone.

## YouVersion: actual J-Life app entitlements

Source: successful [GitHub Actions English/Vietnamese catalog run](https://github.com/i12know/jlife-platform/actions/runs/43537910398) and [Issue #78 evidence](https://github.com/i12know/jlife-platform/issues/78#issuecomment-6869166905). The run returned **20 English** and **1 Vietnamese** Bibles. No Bible League International entitlement was present.

| English version | YouVersion ID | License ID | Verification |
|---|---:|---:|---|
| AMP | 1588 | 3 | Catalog only |
| ASV | 12 | 1 | Catalog only |
| BSB | 3034 | 1 | Catalog only |
| CPDV | 42 | 1 | Catalog only |
| EASY | 2079 | 5 | John 1:1-18 passed |
| FBV | 1932 | 1 | Catalog only |
| GNV | 2163 | 1 | Catalog only |
| LSV | 2660 | 1 | Catalog only |
| NASB1995 | 100 | 3 | Catalog only |
| NASB2020 | 2692 | 3 | Catalog only |
| NIrV | 110 | 2 | John 1:1-18 passed |
| NIV | 111 | 2 | Catalog only |
| NIVUK | 113 | 2 | Catalog only |
| PEV | 2530 | 7 | Catalog only |
| TCENT | 3427 | 1 | Catalog only |
| TOJB2011 | 130 | 1 | Catalog only |
| TPT | 1849 | 4 | Catalog only |
| WEBUS | 206 | 1 | Catalog only |
| WMB | 1209 | 1 | Catalog only |
| WMBBE | 1207 | 1 | Catalog only |

| Vietnamese version | YouVersion ID | License ID | Verification |
|---|---:|---:|---|
| **KTHD / VCB — Kinh Thánh Hiện Đại** | **1638** | **2 (Biblica)** | **John 1:1-18 passed** |

Relevant licenses: 1 Public Domain/Creative Commons, 2 Biblica, 3 Lockman, 4 BroadStreet, 5 MissionAssist, 7 Wycliffe. Other license agreements accepted by the app are documented in Issue #78. Some publisher agreements are not yet independently reviewed for the intended production use. The YouVersion version number is *not* interchangeable with an API.Bible Bible ID.

**ERV** (Bible.com ID 406) returned a 404 from YouVersion Platform's direct metadata endpoint for this app. **BPT** was absent from the Vietnamese catalog. Their availability on Bible Gateway/Bible.com does not establish API developer entitlements.

## API.Bible: candidates, not yet licensed to this app

As of this registry update, **there is no evidence of an API.Bible account or entitlement verification for J-Life**. Proposed complementary versions are **NLT, CSB, NKJV**. These are **candidates**, not approved or selected app licenses. When an account is registered, record the exact chosen edition, returned API.Bible `bible_id`, account-specific license, approved usage type and smoke test before changing `status`.

Official provider documentation: [API.Bible FAQ](https://docs.api.bible/common-questions/) and [plan guide](https://docs.api.bible/quick-start/create-your-account/). The Starter plan permits up to three eligible licensed translations and has a 5,000-requests/month limit; eligibility is plan- and version-dependent. API.Bible describes FUMS display tracking, copyright notices, and caching fewer than 500 consecutive verses with refresh recommended within 14 days. A shared on-demand cache per provider + translation + passage is technically attractive for the 104-day plan, **only if the exact license terms allow it**.

## Bible League: explicit permission pending

The owner sent Bible League International a request on November 18, 2026 for hosted display of **ERV** and **BPT** (covering the 104-day primary passages). This is a **pending request, not permission**. Do not upload translation text, store it in the public repository, or treat Bible Gateway framing as licensed hosting.

## Implementation gates and change procedure

- Keep **secrets** only in the actual runtime's protected secret store; do not commit API keys or licensed Bible text.
- Keep the `languages` fallbacks and `default_for` values in `plugins/jlife-scripture/data/bible-versions.json` unchanged until the owner explicitly approves a new live reader/translation selection (the 2026-11-19 decision on #139 set the defaults to NIrV / Kinh Thánh 1925).
- Implement a provider adapter that exposes a verified version registry and native renderer; ensure that only reviewed, authorized versions can be enabled.
- For each provider/version: review written terms; verify a real passage request; display full attribution; handle unavailable/unlicensed/429 outcomes; enforce privacy and proper view tracking (FUMS for API.Bible); prevent unlicensed persistent caching; retain external links as a fallback.
- For **LATOC**: preserve daily reading order, partial verses, optional Harmony readings, and publicly accessible reading-page distinctions.
- Use a **central server-side per-provider/version/passage cache** with a request lock to avoid duplicate first-use calls; honor each license's cache TTL and max stored span. Do not let a global caching policy override publisher restrictions.
- Record the exact entitlement and license approval evidence in the JSON registry (but **not** private credentials or agreements). Add tests and a bounded proof of concept; don't automatically merge, deploy, or modify public reading screens from a research-only registry.

This registry is **not currently read by WordPress runtime**. The next engineering step is to wire a validated, allowlisted subset into the new native Scripture reader, with owner approval and license gates.

## Next owner actions

1. If desired, register J-Life for API.Bible's free plan and record which three copyrighted translations were actually granted.
2. Await Bible League's written ERV/BPT response.
3. Confirm whether the owner wants the previously recorded **NIrV + KTHD** native-reader selection or a newly proposed **NIV + KTHD** default.
4. Complete publisher-specific terms review and proof-of-concept before exposing licensed text publicly.
