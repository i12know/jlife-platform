# ADR 0001: Multi-deployment, tenant-aligned domain architecture

Status: Accepted

Date: 2026-07-24

## Context

The J-Life work began as a WordPress multisite and Disciple.Tools prototype for a Vietnamese ministry context. As the technical work expanded, several related but distinct concerns began to converge:

- Redemption Point and GLA are separate church organizations within the VAY ChMeetings Diocese.
- Each church needs its own private disciplemaking workspace.
- VAY can provide shared hosting, standards, integration, and support.
- Public church websites must remain separate from confidential disciplemaking applications.
- SonLife USA may eventually want to use the same J-Life/Disciple.Tools framework under its own governance and branding.
- ChMeetings is important to VAY, but another organization may use a different church-management system.

Without a clear boundary, VAY-specific IDs, branding, governance, and ChMeetings assumptions could leak into the reusable platform core. Alternatively, putting unrelated organizations into one deployment could create confusing ownership and unsafe data-access expectations.

## Decision

We will distinguish three architectural layers:

1. **J-Life framework core**: reusable, organization-neutral domain model and software capabilities.
2. **Organization deployment**: independently governed installation, domain, operations, branding, integrations, and tenant catalog.
3. **Local tenant**: isolated church or ministry workspace within an organization deployment.

VAY will operate a deployment under `vayhub.org`. Each participating VAY church will normally receive one tenant aligned with its ChMeetings Diocese organization, for example:

- RP ChMeetings organization -> `rp.vayhub.org`
- GLA ChMeetings organization -> `gla.vayhub.org`

Redemption Point's public site remains `redemptionpoint.org`; its VAY Hub tenant is a private disciplemaking application, not a replacement public church website.

A future SonLife USA adoption will normally be a separate deployment such as `sonlifehub.org`, not a tenant under `vayhub.org`. It may reuse the same framework while selecting its own CRM adapter, governance, branding, and tenant structure.

The framework core will depend on generic organization, tenant, person, huddle, study, and integration contracts. Deployment-specific packages may depend on the core, but the core must not depend on VAY- or SonLife-specific packages.

## Consequences

### Positive

- VAY and SonLife can share software without sharing confidential databases.
- VAY's J-Life tenant structure can align naturally with the ChMeetings Diocese tenant structure.
- Each church retains local ministry-record isolation and operational autonomy.
- Public websites can use their own branding, themes, caching, and plugin policies.
- CRM integrations become replaceable adapters instead of core assumptions.
- Shared releases, curriculum, and aggregate metrics remain possible.
- Repository and package ownership become easier to explain.

### Costs and tradeoffs

- Deployment-specific packaging and configuration must be maintained separately.
- Tenant provisioning, domain mapping, TLS, backups, and export procedures require automation.
- Cross-church people and coaches require explicit identity and permission handling.
- A shared identity registry may eventually be necessary, but it cannot override tenant privacy.
- Aggregate reporting needs approved definitions and privacy thresholds.

## Guardrails

- No hard-coded VAY, RP, GLA, SonLife, ChMeetings organization, or domain identifiers in the framework core.
- Technical administration does not automatically grant pastoral-record access.
- Person-level data does not cross tenants or deployments by default.
- External CRM IDs always retain deployment and organization context.
- Public church sites do not become authoritative stores for confidential J-Life records.
- Any exception requires an explicit architecture or governance decision.

## Alternatives considered

### One global J-Life deployment for every organization

Rejected because governance, branding, integrations, incident response, and confidential-data boundaries would become unnecessarily coupled.

### Put SonLife under `sonlife.vayhub.org`

Rejected because the domain and operational model would imply VAY ownership or administration over SonLife's deployment.

### Give every church a completely independent codebase

Rejected because it would duplicate maintenance, fragment the domain model, and make shared improvements difficult.

### Merge each church's public website and private J-Life tenant

Rejected as the default because the two surfaces have different audiences, caching needs, plugin risks, security posture, and data sensitivity.

## Follow-up

Implementation work should validate:

- Tenant-aware ChMeetings synchronization
- Multi-church identity and transfer behavior
- Domain provisioning and mapped-domain support
- Deployment package boundaries
- Tenant export and departure procedures
- Aggregate reporting privacy
- Whether STUDY is tenant-local, deployment-shared, or hybrid

The living architecture is maintained in [domain-architecture.md](../domain-architecture.md).