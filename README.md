# jlife-platform

J-Life Platform is an early-stage product and architecture exploration for a SonLife/J-Life disciplemaking platform that can support structured study content, private huddles, coaching cohorts, and long-term localization needs.

This repository is starting as a planning and prototype space. The first source of truth is the product requirements draft:

- [Product Requirements](docs/PRD.md)
- [Technical Analysis](docs/technical-analysis.md) — verified findings on Disciple.Tools, the multisite plugin, Harmony Bible, Knowing Him, and Vietnamese localization
- [Architecture](docs/architecture.md) — proposed two-subsite multisite design, identity model, content model, and custom plugins
- [Roadmap](docs/roadmap.md) — phased development plan with spikes, milestones, and exit criteria
- [Content Rights Register](docs/content-rights.md) — rights inventory and handling rules
- [Integration Boundaries](docs/integration-boundaries.md) — data ownership and sync rules across ChMeetings, Disciple.Tools, and the study surface

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

## Repository Safety

This repository may eventually become public. Do not commit private partner names, contact details, private relationship maps, donor/support information, travel details, sensitive ministry context, credentials, or exports from private systems.
