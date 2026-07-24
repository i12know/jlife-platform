# J-Life Domain Architecture

Status: Accepted direction; implementation remains phased

Created: 2026-07-24

Related: [architecture.md](architecture.md), [integration-boundaries.md](integration-boundaries.md), [vision-architecture.md](vision-architecture.md), [ADR 0001](adr/0001-multi-deployment-tenant-domain-architecture.md)

## 1. Purpose

This document is the canonical cross-project view of the J-Life platform. It defines the boundaries between the reusable J-Life framework, organization-owned deployments, local church tenants, external systems, and public church websites.

Individual application documents may describe one implementation surface in more detail, but they must not redefine these boundaries silently.

## 2. Core decision

J-Life is an organization-neutral disciplemaking framework built initially on WordPress multisite and Disciple.Tools.

A sponsoring organization operates its own independently governed deployment of that framework. Within that deployment, each participating church or ministry normally receives an isolated tenant.

```text
Reusable J-Life framework
    |
    +-- VAY Hub deployment
    |     domain: vayhub.org
    |     tenants: rp.vayhub.org, gla.vayhub.org, ...
    |     church CRM integration: VAY ChMeetings Diocese
    |
    +-- SonLife Hub deployment
          domain: sonlifehub.org
          tenants: church-a.sonlifehub.org, church-b.sonlifehub.org, ...
          church CRM integration: selected by SonLife USA
```

VAY Hub is the first deployment and proving ground. It is not the platform core, and SonLife USA would not normally be a tenant inside VAY Hub.

## 3. Architectural layers

### 3.1 Framework core

The framework core provides reusable, organization-neutral capabilities:

- Disciple.Tools extensions and configuration
- STUDY participant experience
- Huddle and coaching workflows
- Curriculum and portable content model
- Field Companion APIs and focused mobile workflows
- Tenant provisioning conventions
- Identity mapping contracts
- Integration adapter interfaces
- Security, privacy, audit, and data-export conventions
- Shared movement metrics definitions

The framework core must not contain hard-coded VAY, Redemption Point, GLA, or SonLife identifiers.

### 3.2 Organization deployment

An organization deployment owns:

- Primary domain and branding
- Hosting, backups, release policy, and operations
- Authentication and administrator policy
- Integration credentials and adapters
- Tenant provisioning
- Organization-wide reporting rules
- Data governance and incident response
- Shared curriculum configuration

Examples:

- `vayhub.org`: governed by VAY
- `sonlifehub.org`: governed by SonLife USA

### 3.3 Local tenant

A tenant is the isolated ministry workspace for a participating church or ministry unit.

Examples:

- `rp.vayhub.org`: Redemption Point J-Life tenant
- `gla.vayhub.org`: GLA J-Life tenant
- `church-a.sonlifehub.org`: a SonLife partner church tenant

A tenant owns its local leaders, huddles, disciplemaking records, permissions, workflows, and local reporting. Platform operators may maintain infrastructure without automatically receiving pastoral-record access.

## 4. Public websites are separate from private J-Life tenants

A church's public website remains its public ministry front door.

```text
redemptionpoint.org
    Public church website: worship, ministries, sermons, events, giving,
    newcomer information, and public forms

rp.vayhub.org
    Private J-Life tenant: discipleship relationships, huddles, coaching,
    follow-up, progress, and multiplication workflows
```

A church may offer a branded convenience address such as `jlife.redemptionpoint.org`, but that address should route to or front the tenant rather than merge the public church website and the private disciplemaking application into one security boundary.

## 5. VAY tenant mapping

VAY Hub aligns with the existing VAY ChMeetings Diocese structure.

```text
VAY ChMeetings Diocese                 VAY Hub deployment

VAY Diocese account                   vayhub.org platform governance
    RP organization   <------------>      rp.vayhub.org tenant
    GLA organization  <------------>      gla.vayhub.org tenant
    Other church      <------------>      other.vayhub.org tenant
```

The normal provisioning rule is:

> One participating ChMeetings church organization normally maps to one isolated J-Life/Disciple.Tools tenant.

The mapping is explicit configuration, not a naming guess.

## 6. Domain responsibilities

### 6.1 ChMeetings

ChMeetings is the authoritative operational church-management system where an organization uses it. It owns approved people and household records, church membership, contact information, communication preferences, consent, events, attendance, forms, and other church-operational data.

### 6.2 Disciple.Tools HUB

Disciple.Tools owns disciplemaking workflow data: huddles, mentoring and coaching relationships, leader assignments, follow-up, spiritual milestones, huddle health, and generational or multiplication links.

### 6.3 STUDY

STUDY owns curriculum delivery and participant learning data: series, lessons, translation state, participant progress, leader-visible submitted responses, huddle discussion, and private reflections according to the privacy model.

### 6.4 Field Companion

Field Companion is a focused capture and interaction surface. It should own as little authoritative data as possible. It may cache and synchronize, but durable records belong to ChMeetings, HUB, or STUDY according to the data-ownership map.

### 6.5 Public church websites

Public websites own public-facing content and ministry presentation. They do not own confidential discipleship records merely because they share a church brand.

## 7. Integration architecture

External systems connect through replaceable adapters rather than becoming assumptions in the J-Life core.

```text
J-Life domain services
    |
    +-- ChMeetings adapter
    +-- Planning Center adapter (possible future)
    +-- Breeze adapter (possible future)
    +-- Disciple.Tools-only identity mode
```

The VAY deployment uses a ChMeetings Diocese adapter. A future SonLife deployment may use a different adapter without forking the J-Life domain model.

A deployment-level integration service should centralize:

- Tenant-to-organization mapping
- Person identity reconciliation
- Duplicate and merge handling
- Idempotent synchronization
- Credential isolation
- Audit logs
- Conflict handling
- Church-transfer handling
- Cross-church participation rules
- Aggregate reporting

Each tenant should not invent a different synchronization method.

## 8. Identity model

Identity has several scopes and must not be collapsed into one database ID.

Recommended conceptual keys:

- `deployment_id`: identifies an organization deployment such as VAY Hub
- `tenant_id`: identifies the local church workspace
- `source_organization_id`: identifies the corresponding external CRM organization
- `source_person_id`: identifies the person in the external CRM
- `dt_contact_id`: identifies the tenant-local Disciple.Tools contact
- `wp_user_id`: identifies a WordPress network user where an account exists
- `study_participant_id`: identifies participant state where needed

For VAY, the integration must retain both the ChMeetings person ID and organization context. The same person may participate in more than one church, so a local D.T contact is a tenant representation, not proof of a globally unique human identity.

A future shared identity registry may know that two tenant-local records represent the same ChMeetings person, while tenant permissions continue to isolate local pastoral data.

## 9. Cross-tenant and cross-deployment rules

By default:

- A church cannot read another church's pastoral or disciplemaking records.
- VAY technical administrators do not automatically receive ministry-record access.
- Cross-church coaches receive explicit, least-privilege access.
- Aggregated movement reporting excludes identities and sensitive text unless separately approved.
- Church departure includes an export and data-retention process.
- VAY Hub and SonLife Hub do not share person-level records automatically.

Deployments may exchange software releases, curriculum packages, training resources, and approved aggregate metrics without federating confidential records.

## 10. Repository and package boundaries

The expected separation is:

```text
jlife-platform
    Shared framework, domain contracts, WordPress/Disciple.Tools plugins,
    portable content model, architecture, and reference deployment tooling

VAY deployment package or repository
    VAY branding, tenant catalog, ChMeetings Diocese configuration,
    VAY reporting rules, and deployment secrets outside public source control

SonLife deployment package or repository
    SonLife branding, tenant catalog, selected CRM adapter configuration,
    and SonLife governance rules

rp-field-companion
    Native/mobile client and offline field workflows using platform APIs

rp-command-center
    RP-specific leadership and operational coordination; consumes platform
    information but does not redefine the J-Life domain model
```

Repository names may evolve, but responsibility must remain explicit. A deployment package may depend on the framework core; the framework core must not depend on a specific deployment package.

## 11. Deployment topology options

The logical model does not require every deployment to use identical DNS or server topology. A deployment may use subdomains or mapped domains, provided tenant isolation and canonical IDs remain intact.

The current preferred VAY presentation is:

```text
vayhub.org              Public platform, training, support, or landing surface
rp.vayhub.org           RP private tenant
gla.vayhub.org          GLA private tenant
network.vayhub.org      Optional VAY coaching and aggregate workspace
study.vayhub.org        Optional shared curriculum surface if separated
```

The current prototype's STUDY-at-root and HUB-at-`/hub/` topology remains valid inside a single tenant implementation. DNS structure and internal WordPress subsite structure are related but distinct decisions.

## 12. Architectural invariants

1. The J-Life core remains organization-neutral.
2. Organization deployments are independently governed.
3. Local church tenants are isolated by default.
4. Public church websites are not the system of record for confidential disciplemaking data.
5. ChMeetings and other CRMs remain authoritative only for their approved operational domains.
6. Disciple.Tools remains authoritative for disciplemaking relationships and workflow.
7. STUDY remains authoritative for curriculum and participant learning data.
8. Field Companion is primarily a capture and interaction surface, not another master database.
9. Cross-system synchronization is explicit, idempotent, auditable, and tenant-aware.
10. Aggregate collaboration must not quietly become person-level data sharing.

## 13. Decisions still requiring implementation validation

- Exact WordPress multisite topology for many church tenants
- Whether STUDY is tenant-local, deployment-shared, or supports both modes
- Tenant domain mapping and TLS automation
- Authentication and optional SSO between deployment surfaces
- Diocese API credential and permission model
- Global identity registry requirements
- Church transfer and multi-church participation workflows
- Export, archival, and tenant departure procedures
- Aggregate metric definitions and privacy thresholds
- Packaging and release strategy for deployment-specific adapters

These questions refine the implementation. They do not change the accepted separation between framework, deployment, tenant, public website, and external source systems.