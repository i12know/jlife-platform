# J-Life_VN Digital Disciplemaking System PRD - Draft 1

Owner: Project leadership / project sponsor
Status: Draft for review
Created: 2026-07-03
Related project: `G:\Shared drives\RP Google Drive\Sonlife\J-Life_VN`
Related source materials: Knowing Him, Harmony Bible, SonLife / J-Life disciplemaking framework
Intended review audience: project leadership, SonLife/J-Life partners, Vietnamese ministry partners, potential technical contributors

## 1. Product Summary

J-Life_VN needs a long-term digital system that can support Vietnamese-led disciplemaking through the life and strategy of Jesus. Existing tools such as Knowing Him and Harmony Bible are valuable but incomplete for the Vietnam context: they do not currently support Vietnamese, and they do not provide private small-group huddles, community discussion, leader/member roles, coaching cohorts, or field-ready structures for participants and local ministry teams.

The proposed system should begin as a lightweight Vietnamese disciplemaking pilot and grow, only if field use warrants it, into a mobile-first platform for Bible-centered study, private huddles, leader coaching, and community-based multiplication.

This product should not merely digitize content. It should serve Vietnamese partners as they practice, contextualize, and multiply Jesus-shaped disciplemaking in local churches, trusted small-group contexts, coaching relationships, and future J-Life/SonLife cohorts.

## 2. Background And Strategic Context

J-Life_VN is part of RP's exploratory 2027-2037 Vietnam SonLife / J-Life disciplemaking strategy. The strategy has two connected pathways:

- Top-down pathway: build theological credibility and trust through recognized Vietnamese church leaders and potential exposure through the SonLife Strategy Tour.
- Bottom-up pathway: test disciplemaking practice in real ministry contexts through trusted local and diaspora ministry relationships.

Current near-term field need:

- A trusted field partner requested Bible study materials for use in a small-group ministry context.
- Knowing Him was identified as a likely first candidate because it follows the life of Jesus.
- Harmony Bible appears to provide the Gospel chronology/source-text layer behind the SonLife/J-Life life-of-Christ framework.

Current gap:

- Knowing Him provides guided study and reflection, but not Vietnamese support or private group/community structure.
- Harmony Bible provides Gospel chronology/source text, but not Vietnamese support or private group/community structure.
- Neither tool appears designed for a Vietnamese-led huddle/cohort system with leader follow-up, private discussion, or small-group/team ministry workflows.

## 3. Goals

### 3.1 Ministry Goals

- Help Vietnamese partners study the life, methods, priorities, and disciplemaking strategy of Jesus.
- Support locally owned Vietnamese disciplemaking rather than importing an American program.
- Give students, young adults, and emerging leaders a practical pathway to read, reflect, discuss, obey, and multiply.
- Equip leaders to guide small huddles and coaching cohorts with simple rhythms.
- Create a foundation that could support a 10-year J-Life_VN roadmap if RP and partners discern long-term commitment.

### 3.2 Product Goals

- Provide a Vietnamese-first mobile experience.
- Support private small-group huddles with leader/member roles.
- Support guided studies through the life of Jesus using licensed/approved content.
- Allow leaders to see lightweight progress and respond pastorally without becoming intrusive.
- Provide enough community structure for discussion, encouragement, questions, and follow-up.
- Keep the system usable in low-bandwidth and mobile-first environments.
- Leave room for future integration with Harmony Bible, Knowing Him, or original Vietnamese contextual material.

### 3.3 Organizational Goals

- Create a GitHub-based home for planning, issues, documentation, prototypes, and eventual code.
- Clarify scope before development begins.
- Make it easy for ministry leaders, translators, reviewers, designers, and developers to collaborate.
- Preserve distinctions between confirmed decisions, proposed strategy, open questions, and sensitive information.

## 4. Non-Goals

For the first version, the system should not attempt to:

- Replace local church discipleship, pastoral care, or face-to-face coaching.
- Clone Knowing Him or Harmony Bible without permission.
- Publish copyrighted SonLife, Knowing Him, Harmony Bible, Bible translation, audio, video, or map assets without explicit rights.
- Launch as a public app-store native app before validating field use.
- Build a complex social network.
- Require every participant to create an account before they can experience the material.
- Store sensitive pastoral-care details or donor/payment details.

## 5. Primary Users

### 5.1 Athlete / Participant

A Vietnamese student, young adult, or participant who joins a huddle or ministry group. They primarily need mobile access to study content, Scripture references, reflection prompts, group discussion, and simple next steps.

Needs:

- Vietnamese language interface and content.
- Easy entry from a shared link or invitation.
- Mobile-first reading and response flow.
- Private huddle space with people they know.
- Trust that their personal reflections are not exposed broadly.

### 5.2 Huddle Leader

A local Vietnamese leader, coach, ministry staff member, or mature believer guiding a small group.

Needs:

- Create or manage a private huddle.
- Invite members simply.
- Assign or recommend studies.
- See who is participating at a lightweight level.
- Post discussion prompts, prayer requests, encouragement, and follow-up questions.
- Keep sensitive conversations private.

### 5.3 Coach / Mentor

A more experienced leader supporting several huddle leaders or a ministry cohort.

Needs:

- See huddle health without micromanaging.
- Encourage leaders.
- Share resources and coaching notes.
- Track cohort progress and identify leaders who need help.

### 5.4 Content Editor / Translator

A trusted person preparing Vietnamese content, reviewing biblical accuracy, and adapting examples for local small-group contexts.

Needs:

- Structured content workflow.
- Translation status tracking.
- Review and approval states.
- Ability to keep source references separate from published material.

### 5.5 Admin / Steward

A small group of trusted system administrators responsible for user access, permissions, configuration, and privacy.

Needs:

- Manage roles and organizations.
- Respond to support issues.
- Maintain audit-friendly content and permission records.
- Protect participant privacy.

## 6. Product Principles

- Vietnamese-led: the product must serve Vietnamese partners, not make them passive recipients.
- Mobile-first: design for phones, low friction, and sharing through common communication channels.
- Huddle-centered: the basic unit is a small, trusted disciplemaking group, not an anonymous audience.
- Content plus practice: study content should lead toward discussion, obedience, witness, prayer, and multiplication.
- Privacy-aware: protect personal reflections and group conversations.
- Permission-aware: never assume rights to source material, Bible text, media, or maps.
- Lightweight before complex: validate with pilots before building full platform features.
- Local church friendly: strengthen church relationships rather than creating a detached para-church silo.

## 7. MVP Scope

The MVP should validate whether Vietnamese partners actually need a custom system and what group workflows matter most.

### 7.1 MVP Included

- Vietnamese interface foundation.
- Mobile web/PWA experience.
- A small pilot study set, likely 4-7 life-of-Jesus lessons.
- Study reader with sections: Scripture reference, short reading/teaching, reflection questions, application, prayer, and huddle discussion prompts.
- Private huddle creation.
- Invite link or invite code.
- Basic member roles: participant, huddle leader, admin.
- Group discussion thread per lesson.
- Optional private reflection notes visible only to the participant in normal application workflows unless explicitly shared.
- Simple progress indicators.
- Leader view showing participant progress at a non-invasive level.
- Basic content management through structured files or simple admin workflow.
- Privacy policy and content rights notes appropriate to pilot scope.

### 7.2 MVP Excluded

- Public social feed.
- Complex forum moderation.
- Native iOS/Android apps.
- Payment/donation processing.
- Full Harmony Bible implementation.
- Full Knowing Him 50-day translation.
- Audio/video production.
- Advanced analytics.
- AI coaching or automated pastoral advice.
- Multi-organization enterprise administration.

## 8. Future Phases

### Phase 0: Review And Discernment

- Review this PRD with project leadership and Vietnamese ministry partners.
- Decide whether to create a GitHub repository now for planning and prototypes.
- Confirm project naming and ownership.
- Contact relevant rights holders before reproducing source content.
- Identify Vietnamese reviewers and field testers.

### Phase 1: Content Pilot Without Full Platform

- Prepare 4-7 Vietnamese lessons for a pilot small-group context.
- Test by PDF, Google Doc, printed guide, or simple static webpage.
- Observe huddle patterns, discussion needs, leader pain points, and vocabulary.
- Decide whether custom software is justified.

### Phase 2: WordPress / Disciple.Tools MVP

- Build a lightweight Vietnamese WordPress/Disciple.Tools-based MVP with study reader, private huddles, and mobile-first UX.
- Support invitation links, discussion, progress, and leader follow-up.
- Keep content set limited and reviewable.
- Test with a trusted pilot context.

### Phase 3: Cohort And Coaching Layer

- Add leader cohorts.
- Add coach-to-leader feedback spaces.
- Add resource library and leader guides.
- Add huddle health snapshots.
- Expand content after theological and language review.

### Phase 4: Harmony Bible Source Layer

- If permissions allow, incorporate Vietnamese Harmony Bible chronology or deep links.
- Add Gospel event browser by SonLife/J-Life phase.
- Add source-text references and map/location support.
- Keep source text and guided study content as distinct layers.

### Phase 5: Mature Platform

- Add full content library.
- Add media resources if rights and production capacity exist.
- Add offline support, exports, and possibly app-store wrappers.
- Add multi-region/team administration if Vietnamese partners request it.

## 9. Functional Requirements

### 9.1 Localization

- System must support Vietnamese UI from the beginning.
- Future-ready for English/Vietnamese bilingual mode.
- All participant-facing content must be translatable.
- Content model should separate source language, translation, review status, and publication status.

### 9.2 Study Content

- Study content should be structured by series, lesson, section, prompt, and Scripture reference.
- Lessons should support reflection questions, application prompts, discussion prompts, prayer prompts, and leader notes.
- Content should be exportable for offline review or print if needed.
- Source attribution and permission notes should be stored with each content set.

### 9.3 Huddles

- A huddle is a private small group with a leader and participants.
- Leaders can create huddles and invite participants.
- Participants can join via invite link/code.
- Huddles can be connected to a study series or cohort.
- Huddles have private lesson discussion threads.
- Huddles may include prayer requests and next-step commitments.

### 9.4 Community Forum

- MVP may include only huddle discussion.
- Future version may include broader community forums by cohort, region, ministry type, or leader group.
- Forum spaces must support moderation and privacy boundaries.
- Public visibility should be avoided unless intentionally approved.

### 9.5 User Roles

Initial roles:

- Participant
- Huddle leader
- Coach/mentor
- Content editor
- Admin

Role requirements:

- Participants can read assigned content, take private notes, share selected responses, and join huddle discussion.
- Huddle leaders can manage a huddle, view lightweight progress, and guide discussion.
- Coaches can support multiple leaders or huddles if assigned.
- Editors can prepare/review content but should not automatically see private participant reflections.
- Admins manage system access and safety.

### 9.6 Notes And Responses

- Private notes are visible only to the author by default in normal application workflows.
- Shared responses must require explicit participant action.
- Leaders should not receive sensitive journal content unless shared.
- Product copy and privacy policy must be honest that trusted system administrators, database operators, backup operators, or incident responders may technically access stored notes unless a later phase adds application-layer encryption.
- The system should encourage in-person or trusted huddle conversation rather than replacing it.

### 9.7 Progress

- Participants can see their own progress.
- Huddle leaders can see lightweight completion status.
- Coaches can see aggregate huddle progress if authorized.
- Progress metrics should not shame or rank participants.

### 9.8 Admin And Safety

- Admins can suspend users or remove inappropriate content.
- Huddle leaders can manage membership.
- System should provide a basic report/escalation path for misuse.
- Sensitive pastoral-care situations should move offline to trusted leaders.

## 10. Non-Functional Requirements

- Mobile-first responsive design.
- PWA-ready for home-screen installation.
- Low-bandwidth friendly.
- Works acceptably on older Android phones.
- Unicode/Vietnamese typography support.
- Secure authentication if accounts are required.
- Strong privacy defaults.
- Data backup plan.
- Minimal analytics, focused on product improvement rather than surveillance.
- Maintainable by a small volunteer/mission-aligned technical team.

## 11. Data And Privacy Considerations

Likely data types:

- User profile: name, email/phone if needed, language, role.
- Huddle membership.
- Lesson progress.
- Private notes.
- Shared discussion posts.
- Prayer requests or commitments, if included.
- Content status and source metadata.

Privacy rules:

- Avoid collecting more data than needed.
- Do not store donor/payment details.
- Do not store highly sensitive pastoral-care records in the app.
- Make private notes private by default.
- Make group discussion visible only inside the huddle or authorized cohort.
- Provide a process for deleting accounts and removing data.

## 12. Content And Rights Requirements

Before publication beyond internal pilot, confirm rights for:

- Knowing Him content.
- Harmony Bible structure/content/assets.
- SonLife/J-Life training language or diagrams.
- Vietnamese Bible translation text.
- Videos, podcasts, maps, images, logos, and partner marks.

Potential content paths:

- Original Vietnamese contextual study inspired by the life of Jesus and SonLife/J-Life framework.
- Licensed Vietnamese translation/adaptation of Knowing Him.
- Partnership with Harmony Bible for Vietnamese source-text chronology.
- Hybrid model: original guided study plus approved links to source tools.

## 13. Preferred Technical Direction

Default stack direction:

- **Primary platform:** WordPress with Disciple.Tools evaluated first as the disciplemaking CRM / huddle / cohort foundation.
- **Primary extension model:** Disciple.Tools plugin first; WordPress companion plugin second if Disciple.Tools cannot cleanly own the study/huddle UX.
- **Primary integration pattern:** ChMeetings remains the likely CRM/source-of-truth for existing approved people, groups, churches/ministries, and selected operational data in contexts that already use it; private discipleship reflections should remain in the J-Life_VN / Disciple.Tools layer unless explicitly shared and approved.
- **Primary mobile strategy:** keep WordPress/Disciple.Tools as the default platform/backend while supporting the right participant mobile client: responsive web, PWA-style front end, Disciple.Tools mobile app/plugin integration, or a future companion mobile app if field testing proves it necessary. The PRD should avoid creating a separate standalone backend by default; it should not prohibit a separate mobile client.
- **Primary content strategy:** portable structured content using WordPress custom post types, taxonomies, metadata, or exportable Markdown/JSON schemas; content must remain rights-aware and translation-reviewable. Do not assume the Disciple.Tools theme must also be the public study-content theme.
- **Primary auth/permissions strategy:** reuse WordPress and Disciple.Tools users, roles, capabilities, groups, and permission models wherever feasible.
- **Primary sync strategy:** follow the existing ChMeetings portfolio pattern: REST API for production sync, `.env` secrets, `CHM_FIELDS` mapping, field inspector, mock-mode tests, optional live tests, idempotent writes, and structured logs.
- **Primary data-ownership assumption:** if ChMeetings is used, it should remain the local church CRM source of truth for approved people/contact records, household or group relationships, churches/ministries, events, and operational fields. Disciple.Tools should hold the disciplemaking/huddle subset needed for coaching, cohorts, progress, and relationship workflows. Private study reflections, sensitive huddle notes, and pastoral-care details should not sync back to ChMeetings by default.
- **Primary ChMeetings sync direction:** begin with a narrow, explicit ChMeetings-to-Disciple.Tools sync for approved contacts/groups and external IDs. Any Disciple.Tools-to-ChMeetings back-sync should require a separate data map, consent/security review, and clear field-level approval.

Preferred architecture order:

1. **Disciple.Tools plugin** for huddles, cohorts, leader/member roles, group progress, and disciplemaking relationships.
2. **Participant content surface** for study content, guided lesson flow, Harmony Bible references, translation workflow, and reader UX. This may be a separate WordPress site/subsite, a companion plugin, or a front-end client; it should not be forced into the Disciple.Tools theme if that weakens the content experience.
3. **Participant-facing mobile client** using responsive web, PWA-style front end, Disciple.Tools mobile app integration, or a future companion mobile app if mobile UX, offline behavior, or participant simplicity requires it, while retaining WordPress/Disciple.Tools as the default platform/backend.
4. **Separate standalone platform/backend** only as an explicit exception after the architecture spike proves WordPress/Disciple.Tools cannot meet core requirements.

Technology choices that should be avoided as default starting points:

- Do not begin with Next.js, Firebase, Supabase, or a custom JavaScript backend/platform stack unless the WordPress/Disciple.Tools spike fails a documented requirement. A mobile client may still be considered if it uses WordPress/Disciple.Tools as its source platform.
- Do not create a separate user/group database before testing whether WordPress, Disciple.Tools, and ChMeetings can provide the needed identity and relationship model.
- Do not fork Disciple.Tools core for first implementation; use plugin or companion-plugin patterns first.

MVP engineering bias:

- Prototype the huddle/study model against Disciple.Tools before building custom infrastructure.
- Keep content portable and rights-aware.
- Avoid duplicating ChMeetings people/group data unless there is a clear reason.
- Keep private discipleship notes separate from CRM records.
- Prioritize huddle privacy, Vietnamese text quality, leader workflow, and integration discipline over polished media features.

### WordPress Theme / Content Architecture Clarification

Disciple.Tools is a WordPress theme, not only a plugin. Because a normal single WordPress site can only run one active theme at a time, the PRD should not assume that the public study-content experience and the private Disciple.Tools CRM/huddle experience must live in the same WordPress front end.

A WordPress multisite network may be the cleanest first architecture to test. Multisite can share one WordPress codebase, installed themes/plugins, updates, and user accounts across multiple subsites, while allowing each subsite to activate the theme appropriate for its purpose. The Disciple.Tools multisite plugin is specifically intended to help Super Admins manage a multisite Disciple.Tools server, including updates, subsite import, network dashboard authorization, and related administration.

Preferred architecture for the first serious prototype:

1. A private Disciple.Tools-powered multisite subsite for contacts, groups, huddles, cohorts, coaching, permissions, metrics, and disciplemaking workflow.
2. A participant-facing content subsite or app surface for lessons, Harmony Bible references, Knowing Him-style study flow, translation review, and reader UX. This surface may use a normal WordPress content theme, a companion WordPress plugin, or a PWA/mobile client backed by structured content.
3. Integration between the two surfaces through approved APIs, shared external IDs, network-level user accounts where appropriate, magic links/SSO if needed, and explicit field-level permissions.

Harmony Bible should be treated as a rights-aware source/reference, not as content to scrape or clone. A future implementation may display Harmony Bible material only through an approved license, API/export, embed agreement, or deep links. Until permissions and technical access are confirmed, the J-Life platform should store portable lesson metadata and references rather than copying Harmony Bible content into Disciple.Tools.

### Mobile Client Clarification

Knowing Him and the Disciple.Tools mobile app show that a separate participant-facing mobile client can be valuable. The architectural guardrail is against creating a separate source-of-truth/backend before testing WordPress/Disciple.Tools, not against mobile clients.
## 14. GitHub Repository Recommendation

Repo name decision:

- Use `jlife-platform` as the public GitHub repository name.

Rationale:

- It is short, clear, and durable while allowing the platform to support J-Life_VN and other SonLife/J-Life-related discipleship use cases over time.
- It can hold PRD/docs now, architecture spikes next, and plugin/code work later.
- It avoids locking the repository to one geography, one ministry context, or one implementation layer such as huddles, WordPress, Disciple.Tools, or app.
- It leaves room for Vietnamese partners and SonLife/J-Life leaders to help name public-facing products separately from the technical repo.

Retired alternatives:

- `jlife-vn`
- `j-life-vn-system`
- `jlife-vn-discipleship`

Recommended initial repository structure:

```text
/docs
  PRD.md
  roadmap.md
  architecture.md
  content-rights.md
  integration-boundaries.md
  partner-notes-template.md
/content
  README.md
  pilot-lessons/
  schemas/
/disciple-tools-plugin
  README.md
/wordpress-plugin
  README.md
/middleware
  README.md
  clients/
  tests/
/design
  user-flows.md
  wireframes/
/prototypes
  README.md
/.github
  ISSUE_TEMPLATE/
  PULL_REQUEST_TEMPLATE.md
```

Recommended GitHub issue labels:

- `ministry-discernment`
- `content`
- `translation`
- `theological-review`
- `permissions`
- `product`
- `design`
- `engineering`
- `privacy`
- `pilot-feedback`
- `huddle-workflow`

Recommended first GitHub issues:

- Confirm repository owner/organization and create `jlife-platform` under the chosen GitHub account.
- Confirm permission path for Knowing Him.
- Confirm permission/contact path for Harmony Bible.
- Research Vietnamese Bible translation rights.
- Define pilot users and huddle workflow.
- Draft first 4-7 pilot lessons.
- Define content schema.
- Sketch mobile study reader flow.
- Sketch private huddle discussion flow.
- Define privacy boundaries for notes, prayer requests, and leader visibility.

## 15. Success Metrics

Pilot success should be measured qualitatively first.

Possible measures:

- Vietnamese partners say the language feels natural and usable.
- Athletes can access and complete a lesson without technical help.
- Huddle leaders can guide discussion using the prompts.
- Participants share that the study helps them see and follow Jesus more clearly.
- Leaders request continued use after the pilot.
- The tool strengthens, rather than distracts from, real-life discipleship relationships.
- Translation/review workflow is manageable.
- No major privacy or trust concerns emerge.

## 16. Risks

- Building software before validating field need.
- Rights/permissions delays.
- Vietnamese Bible text licensing complexity.
- Overbuilding a platform when a guide/PDF/huddle rhythm would be enough.
- Creating a tool that feels foreign to Vietnamese partners.
- Privacy concerns if notes or group discussions are mishandled.
- Weak local ownership.
- Too much dependence on RP technical capacity.
- Confusing SonLife/J-Life training framework with local church discipleship ownership.

## 17. Open Questions

- Who owns the GitHub repository: RP, a future J-Life_VN organization, or another ministry entity?
- Should the repository be public or private during discernment?
- What name should be used for the system in English and Vietnamese?
- Who should contact Founders Passion / Harmony Bible?
- Who should contact SonLife / Knowing Him rights holders?
- Which Vietnamese Bible translation is appropriate and permissible?
- Should the first pilot use full Bible text, references only, or links to a Bible app/site?
- What exact huddle workflow does the first pilot context need?
- Does the system need chat/forum features, or only lesson discussion threads?
- What should leaders be allowed to see about participant progress and responses?
- Who will do Vietnamese translation, theological review, and field testing?
- What local church leaders should review the concept before a broader pilot?
- What minimum evidence would justify moving from pilot content to custom software?

## 18. Recommended Next Steps

1. Review and revise this PRD with project leadership and the project sponsor.
2. Decide whether to create a private GitHub repo for planning.
3. Create the `jlife-platform` GitHub repository under the chosen owner/organization.
4. Identify the first pilot audience and huddle leader.
5. Contact rights holders for Knowing Him and Harmony Bible before reproducing or translating content.
6. Research Vietnamese Bible text rights.
7. Draft a 4-7 lesson Vietnamese pilot before building a full platform.
8. After pilot feedback and the architecture spike, decide whether to build the WordPress/Disciple.Tools MVP or document why an exception stack is required.

## 19. Draft Decision Log

- 2026-07-03: J-Life_VN identified the need to consider digital system work beyond existing Knowing Him and Harmony Bible tools.
- 2026-07-03: Key gaps identified: Vietnamese language support, community forum, private small-group huddle structure, leader/member roles, group discussion flow, and cohort-based discipleship support.
- 2026-07-03: Draft PRD created for review before GitHub repository creation or software build.
- 2026-07-03: Supporting docs added for technical analysis, architecture, roadmap, content rights, and integration boundaries.
## 20. Architecture Addendum: WordPress, Disciple.Tools, And ChMeetings

Added: 2026-07-03
Status: Draft PRD update for review

### 20.1 Why This Changes The PRD

After reviewing the existing project portfolio, future J-Life_VN system work should not begin from a generic standalone backend/platform assumption. Existing ministry operations already include a ChMeetings-centered CRM environment and WordPress integration experience. One existing ministry event system uses ChMeetings as core registration/profile data, WordPress as the operations/admin surface, and Python middleware for durable sync. The project sponsor is also more familiar with WordPress than with other application stacks.

Disciple.Tools is especially relevant because it is a WordPress-based disciple-making movement CRM. Its public project describes a WordPress theme with contacts, groups, generational tracking, roles/permissions, metrics, REST API, hooks, plugins, secure collaboration, mobile-friendly behavior, and multilingual support. Its maintainers recommend building new features as plugins first rather than modifying the core theme.

Therefore, the architecture bias is WordPress/Disciple.Tools-first as the platform/backend, with mobile-first/PWA-style usability and possible mobile-client integration preserved as product requirements rather than a separate default platform.

### 20.2 Revised Architecture Bias

Preferred direction:

1. Disciple.Tools plugin, if huddles/cohorts can map cleanly to Disciple.Tools contacts, groups, roles, metrics, and workflows.
2. WordPress companion plugin, if the content/huddle layer needs more custom UX while staying in a familiar WordPress environment.
3. Disciple.Tools mobile app integration, PWA front-end, or future companion mobile client, if mobile UX or offline needs exceed what a normal plugin/theme workflow can support while still using WordPress/Disciple.Tools as the source platform.
4. Standalone platform/backend only if WordPress/Disciple.Tools cannot meet privacy, UX, offline, or field constraints.

This addendum reinforces Section 13: separate standalone backend/platform stacks are exception paths only, while mobile clients may be evaluated as front ends to the WordPress/Disciple.Tools platform.

### 20.3 ChMeetings Integration Principles

ChMeetings should be treated as a possible CRM/source-of-truth for approved local church people/contact records, churches/ministries, groups, events, and selected operational data. ChMeetings has its own web platform, content features, and authentication scheme; this PRD does not assume those become the primary study-content system or the primary participant login for the J-Life platform. Study content should remain portable and translation-reviewable in WordPress/custom post types, Markdown, JSON, or another exportable content model.

If ChMeetings is the local church CRM source of truth, then the J-Life platform will likely need to sync the relevant contact/group subset into Disciple.Tools so disciplemaking relationships, cohorts, huddles, and progress can be managed there. ChMeetings should not become the storage location for private study reflections, sensitive huddle discussion, or pastoral-care notes by default.

Future system work should follow the established ChMeetings portfolio pattern:

- Document tenant and scope explicitly: local church, regional/network, or ministry-level context.
- Store external IDs for ChMeetings people, groups, churches, ministries, and events where relevant.
- Isolate ChMeetings field names behind a `CHM_FIELDS`-style mapping module.
- Use deterministic REST API calls for production sync rather than scraping or AI-only tooling.
- Use mock-mode tests by default, with optional live tests for intentional verification.
- Handle pagination, 401/403/422/429/5xx errors, retries, and structured logging deliberately.
- Treat webhooks as future optional enhancements; receivers must be idempotent, fast to acknowledge, and careful with secrets and PII.
- Keep private discipleship reflections separate from CRM/profile records unless leaders explicitly approve a limited sync field and pastoral use case.

### 20.4 Disciple.Tools Compatibility Requirements

Before building custom infrastructure, the team should test whether Disciple.Tools can support or be extended to support:

- private huddles as groups or a custom group-like post type;
- participant/contact mapping;
- leader, coach, content editor, and admin roles;
- cohort-level visibility;
- huddle discussion or notes with privacy boundaries;
- generational/multiplication tracking where appropriate;
- mobile-first participant experience;
- Vietnamese localization;
- custom study/content objects;
- integration with ChMeetings external IDs;
- reporting that helps leaders without turning discipleship into surveillance.

New features should favor plugin/extension patterns over modifying Disciple.Tools core.

### 20.5 Revised MVP Implications

The MVP should still begin with field validation and 4-7 pilot lessons, but the first technical spike should answer these architecture questions before any full build:

- Should the first prototype use two WordPress surfaces: a Disciple.Tools site/subsite for private disciplemaking workflow and a separate content site/subsite for study UX?
- Can Harmony Bible provide an approved API, export, embed path, or licensing agreement for Vietnamese display?
- Can J-Life_VN huddles map to Disciple.Tools groups without awkward workarounds?
- Should participants be WordPress users, Disciple.Tools contacts, or both?
- Can private reflections be protected from leaders and normal users unless intentionally shared, while honestly disclosing trusted admin/database access limits?
- Can leader/cohort workflows be modeled through existing Disciple.Tools permissions and workflows?
- Should ChMeetings sync be deferred until after the first field pilot?
- Which ChMeetings tenant/scope would be used first, if any: an existing US-based church/ministry tenant, a district-level tenant, or no ChMeetings tenant during pilot?
- What information, if any, should sync back to ChMeetings?
- Which fields are owned by ChMeetings, which are owned by Disciple.Tools, and which are derived or read-only in the J-Life platform?
- Should J-Life participants authenticate through WordPress/Disciple.Tools, ChMeetings, a single sign-on bridge, or different methods for different roles?

### 20.6 Updated Repo Structure Recommendation

The GitHub repository should leave room for WordPress, Disciple.Tools, and middleware work from the start:

```text
/docs
  PRD.md
  roadmap.md
  architecture.md
  content-rights.md
  integration-boundaries.md
  partner-notes-template.md
/content
  README.md
  pilot-lessons/
  schemas/
/disciple-tools-plugin
  README.md
/wordpress-plugin
  README.md
/middleware
  README.md
  clients/
  tests/
/design
  user-flows.md
  wireframes/
/prototypes
  README.md
/.github
  ISSUE_TEMPLATE/
  PULL_REQUEST_TEMPLATE.md
```

Recommended additional labels:

- `wordpress`
- `disciple-tools`
- `chmeetings`
- `integration`
- `architecture-spike`

Recommended first integration issues:

- Prototype the WordPress multisite/theme boundary: Disciple.Tools theme on a private workflow subsite versus a separate content theme/subsite for public study UX.
- Contact Harmony Bible/SonLife rights holders about approved display, API/export, embed, translation, and attribution options before implementing content ingestion.
- Evaluate Disciple.Tools fit for contacts, groups, huddles, metrics, roles, and mobile use.
- Prototype a Disciple.Tools plugin using the official starter template or document why a companion WordPress plugin is better.
- Define ChMeetings integration boundaries and tenant/scope assumptions.
- Create a data ownership and sync map: ChMeetings people/groups to Disciple.Tools contacts/groups to J-Life_VN huddles/cohorts, including one-way sync fields, optional back-sync fields, and never-sync fields.
- Define which data must never sync back to ChMeetings.

### 20.7 Updated Decision Log

- 2026-07-03: Local portfolio review added WordPress/Disciple.Tools-first architecture consideration and ChMeetings integration readiness requirements.
- 2026-07-03: PRD direction changed from generic standalone app/PWA default to WordPress/Disciple.Tools-first platform/backend, with mobile-first UX and possible mobile-client integration preserved as requirements.
- 2026-07-03: Disciple.Tools theme architecture clarified: prefer testing WordPress multisite with a Disciple.Tools private workflow subsite and a separate content subsite/app surface for public study content and Harmony Bible references.
- 2026-07-03: Repo name initially considered as `jlife-vn`, then changed to `jlife-platform` to allow broader J-Life/SonLife discipleship platform use across J-Life_VN and related ministry contexts.
## 21. Public Repository Safety Notes

Added: 2026-07-03
Status: Required guidance for public GitHub publication

Because this PRD may live in a public GitHub repository, it must avoid identifying in-country partners, sensitive relationship networks, exact field contexts, or ministry labels that could create risk for believers or ministry workers in Vietnam.

Public documentation should use generic descriptions such as:

- trusted field partner;
- trusted pilot context;
- local ministry partner;
- small-group ministry context;
- student/young adult participants;
- local and diaspora ministry relationships.

Public documentation should not include:

- names of in-country ministry partners;
- private relationship maps;
- specific ministry affiliations that could expose local partners;
- donor/support details;
- exact locations or travel details for sensitive work;
- pastoral-care, security, or personal contact details;
- screenshots or exports from ChMeetings, Disciple.Tools, Gmail, Google Calendar, or private ministry systems.

Detailed partner notes, support history, contact records, and security-sensitive context should remain in private shared-drive materials or another restricted location, not in the public repo.