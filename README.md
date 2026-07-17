# jlife-platform

[![CI](https://github.com/i12know/jlife-platform/actions/workflows/ci.yml/badge.svg)](https://github.com/i12know/jlife-platform/actions/workflows/ci.yml)

J-Life Platform is an early-stage product and architecture exploration for a SonLife/J-Life disciplemaking platform that can support structured study content, private huddles, coaching cohorts, and long-term localization needs.

This repository is starting as a planning and prototype space. The first source of truth is the product requirements draft:

- [Product Requirements](docs/PRD.md)
- [Technical Analysis](docs/technical-analysis.md) — verified findings on Disciple.Tools, the multisite plugin, Harmony Bible, Knowing Him, and Vietnamese localization
- [Disciple.Tools Landscape](docs/disciple-tools-landscape.md) — living map of the full D.T ecosystem (theme framework, ~41 plugins) rated for J-Life relevance, with decision deltas and a refresh protocol
- [Architecture](docs/architecture.md) — proposed two-subsite multisite design, identity model, content model, and custom plugins
- [Roadmap](docs/roadmap.md) — phased development plan with spikes, milestones, and exit criteria
- [Content Rights Register](docs/content-rights.md) — rights inventory and handling rules
- [Integration Boundaries](docs/integration-boundaries.md) — data ownership and sync rules across ChMeetings, Disciple.Tools, and the study surface
- [Dev Environment](docs/dev-environment.md) — reproducible local multisite (wp-env + Docker): quickstart, Windows disk-space guidance, verify script
- [Vision Architecture](docs/vision-architecture.md) — draft long-term design: church-wide disciplemaking, SMS/challenge engine, ChMeetings integration, identity ladder, phased roadmap
- [Team Structure](docs/team.md) — roles and working model: hub-and-rings around the owner, review protocols, human-only lanes vs AI-run build lanes
- [Changelog](CHANGELOG.md) - dated summary of meaningful repository milestones and MVP-facing changes

## Current Direction

- Use WordPress and Disciple.Tools as the first platform/backend direction to test.
- Prefer a WordPress multisite prototype: one Disciple.Tools-powered private workflow subsite and one participant-facing content/study subsite or app surface.
- Keep study content portable, rights-aware, and translation-reviewable.
- Treat Harmony Bible, Knowing Him, and related SonLife/J-Life materials as referenced source material until permissions, licensing, translation, and display rights are confirmed.
- Leave room for ChMeetings integration as the local church CRM/source-of-truth where appropriate, with narrow and explicit sync boundaries.

## Near-Term Work

- Review and refine the PRD.
- Define the first prototype architecture.
- Confirm content rights and translation workflow assumptions.
- Map ChMeetings, Disciple.Tools, and participant-facing content data ownership.
- Create initial technical spikes for WordPress multisite, Disciple.Tools plugin compatibility, and content modeling.

## Repository Layout

| Path | Purpose |
|---|---|
| `docs/` | PRD, technical analysis, architecture, roadmap, rights, spike write-ups (`docs/spikes/`) |
| `content/` | Portable study content: schemas, pilot lessons, harmony outline ([rules](content/README.md)) |
| `plugins/jlife-studies` | Study content engine for STUDY (scaffold) |
| `plugins/jlife-huddles` | Huddle discussion/notes/progress for STUDY — privacy-critical (scaffold) |
| `plugins/jlife-bridge` | STUDY ↔ Disciple.Tools HUB identity mapping and read API (scaffold) |
| `design/`, `prototypes/` | UX artifacts; disposable spike experiments |
| `bin/`, `config/`, `.wp-env.json` | Local dev environment ([docs](docs/dev-environment.md)) |

## Repository Safety

This repository may eventually become public. Do not commit private partner names, contact details, private relationship maps, donor/support information, travel details, sensitive ministry context, credentials, or exports from private systems.
