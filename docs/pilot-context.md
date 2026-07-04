# Pilot Context and Huddle Workflow

## Purpose

This pilot exists to test whether Vietnamese-speaking discipleship huddles can use original life-of-Jesus lessons in a simple, repeatable workflow before the full J-Life platform is built.

The pilot should help us learn:

-   whether participants can easily access and use the lessons
-   whether the lesson format supports meaningful huddle discussion
-   what a huddle leader needs to see before and after meetings
-   what should remain private to each participant
-   how Disciple.Tools should support huddle oversight without becoming the participant lesson reader
-   which digital features are truly necessary before broader MVP development

This pilot is intentionally small, time-bound, and ministry-driven. It is not meant to prove every future feature.

## Public Repo Safety

This document must remain generic. It should not include real names, phone numbers, email addresses, ministry partner details, private pastoral situations, travel details, or sensitive participant information.

All sample identities used in future code, fixtures, or documentation should use fake `.test` data.

## Pilot Type

The first pilot should be treated as a **time-bound discipleship training huddle**.

This means:

-   **Discipleship**: the goal is formation in the life and way of Jesus.
-   **Training**: the pilot uses a limited sequence of lessons, likely 5-7 lessons.
-   **Huddle**: the experience is relational and discussion-based, not merely a class or content library.
-   **Time-bound**: the pilot has a defined beginning and ending so it can be evaluated.

For the first pilot, the huddle should map to a Disciple.Tools **Group**, not a Church. If the future use case is primarily for training leaders rather than ordinary participants, a Disciple.Tools **Team** may be considered later.

## Disciple.Tools Alignment

Disciple.Tools should be treated as the ministry CRM and huddle oversight layer.

STUDY should be treated as the participant lesson and content layer.

The intended split is:

### Disciple.Tools owns

-   huddle/group record
-   huddle leader relationship
-   participant/contact relationship
-   coach relationship, if used
-   high-level huddle status
-   high-level lesson/progress summary
-   ministry follow-up responsibility

### STUDY owns

- lesson reading
- Scripture reference flow
- participant-facing lesson pages
- huddle discussion prompts
- participant lesson responses, with explicit visibility:
  - private reflection notes, visible only to the participant if enabled
  - leader-visible submitted responses, visible to the huddle leader for follow-up
  - huddle-shared responses, only if intentionally enabled later
- no public lesson comments in the first pilot
- portable content import/export

### Bridge owns

The bridge should connect the two layers:

-   Disciple.Tools group ID to J-Life huddle ID
-   Disciple.Tools contact ID to J-Life participant mapping
-   Disciple.Tools leader/coach role to STUDY visibility permissions
-   STUDY progress summary to a Disciple.Tools tile or field

Private participant reflections should not be stored in Disciple.Tools comments or ordinary Disciple.Tools fields.

## Pilot Participant Profile

The first pilot should use a small, relationally connected group.

Recommended profile:

-   3-4 English and/or Vietnamese-speaking adults
-   mostly believers or spiritually open participants
-   already connected relationally to the huddle leader
-   mobile-first users
-   not assumed to be technically advanced
-   able to participate in a weekly or near-weekly rhythm
-   comfortable reading Vietnamese study material or English
-   willing to give feedback after the pilot

Participants may be bilingual, but the primary lesson experience should be mainly Vietnamese.

## Pilot Leader Profile

The pilot should have one primary huddle leader.

Recommended profile:

-   trusted Vietnamese-speaking huddle leader
-   able to facilitate discussion, not merely teach content
-   able to follow up with participants during the week
-   comfortable receiving simple progress summaries
-   not required to be technically advanced
-   able to report what worked, what confused participants, and what should change

A pastor or coach may review aggregate progress and support the huddle leader, but should not automatically receive private participant notes.

## Huddle Rhythm

Recommended rhythm:

-   weekly meetings
-   5-7 weeks total
-   75-90 minutes per meeting
-   one lesson per meeting
-   participants read and respond before the meeting
-   huddle meeting focuses on discussion, obedience, prayer, and follow-up

Suggested meeting flow:

1. Welcome and relational check-in
2. Brief review of the previous obedience step
3. Read or summarize the Gospel passage/event
4. Discuss observation and meaning
5. Discuss personal application
6. Identify one concrete obedience/practice step
7. Pray together
8. Leader notes any needed follow-up

The pilot should remain simple. It should not require a full online community experience before privacy and access rules are proven.

## Lesson Workflow

Each lesson should center on one life-of-Jesus event from the Robertson harmony dataset.

Each lesson should include:

-   lesson title
-   gospel event ID
-   Scripture references
-   VIE2010 deep links where appropriate
-   short introduction
-   observation questions
-   meaning/application questions
-   huddle discussion prompts
-   optional private reflection prompt
-   leader notes
-   prayer prompt
-   one concrete obedience/practice step

Scripture text should not be copied into the repository unless rights are secured. The pilot should use references and links only.

## Participant Workflow

Recommended participant flow:

1. Participant receives a lesson link or file from the huddle leader.
2. Participant reads the Scripture reference outside the platform or through an approved link.
3. Participant reads the lesson introduction.
4. Participant answers reflection or discussion prompts before the meeting.
5. Participant joins the huddle meeting.
6. Participant discusses selected prompts with the group.
7. Participant identifies one obedience/practice step for the week.
8. Participant may submit feedback after the lesson or pilot.

The first pilot may use static pages, PDFs, or simple links before full account-based platform behavior is finalized.

## Invite Method

Recommended first-pilot invite method:

-   huddle leader sends the lesson link through the normal communication channel used by the group
-   likely Zalo, Messenger, SMS, or email
-   each participant should eventually receive a unique participant link if magic links are used
-   shared public links should be avoided for private or trackable responses

For the earliest field test, a PDF or static page may be used if needed. However, if responses are collected digitally, participant identity and privacy must be handled carefully.

## Leader Visibility

The huddle leader may see:

-   participant completion status
-   submitted leader-visible discussion responses
-   submitted prayer requests, if explicitly shared by the participant
-   simple progress summary by lesson
-   who may need follow-up

The huddle leader should not see:

-   private reflection notes
-   unsubmitted drafts
-   responses marked private
-   sensitive prayer notes not intentionally shared
-   private participant data from other huddles

The leader experience should answer:

-   Who has engaged with the lesson?
-   Who may need encouragement or follow-up?
-   What discussion themes may help the huddle meeting?
-   What should remain private?

## Coach or Pastor Visibility

A coach or pastor may need high-level summary visibility, but should not automatically see everything the huddle leader sees.

Recommended default:

-   coach/pastor may see huddle-level progress summary
-   coach/pastor may see leader follow-up notes if intentionally shared
-   coach/pastor may not see private participant reflections
-   coach/pastor may not see participant-level sensitive prayer content unless explicitly shared

This should be revisited during the privacy spike.

## Discussion Expectations

For the first pilot, primary discussion should happen in the live huddle meeting.

Existing chat tools may be used for:

-   reminders
-   meeting coordination
-   encouragement
-   simple follow-up

The first pilot should avoid public lesson comments. If responses are collected, they should be structured lesson responses with explicit visibility, not open-ended public comments. The first pilot should not depend on a full online discussion thread unless privacy and permission rules are already proven.

Recommended default:

-   live meeting is the main discussion space
-   chat apps are used only for logistics and encouragement
-   STUDY may collect pre-meeting responses
-   Disciple.Tools should not store participant discussion content by default

## Privacy Expectations

The platform must distinguish between different kinds of participant content.

### Lower sensitivity

-   lesson completion
-   attendance or participation status
-   selected leader-visible responses
-   general huddle progress

### Medium sensitivity

-   discussion responses
-   shared prayer requests
-   follow-up needs

### High sensitivity

-   private reflections
-   unsubmitted drafts
-   sensitive prayer notes
-   pastoral/confessional content

Private reflections should stay private to the participant unless the participant explicitly shares them.

Private means not visible to participants, leaders, coaches, or pastors in normal app workflows; WordPress super admins, database operators, backup operators, and incident responders may technically access stored data unless later encryption is added.

## Data That May Flow to Disciple.Tools

Recommended data that may flow from STUDY to Disciple.Tools:

-   huddle/group ID
-   participant/contact relationship
-   huddle leader relationship
-   lesson progress summary
-   completion counts
-   high-level status such as active/inactive
-   follow-up flag if leader action is needed

Recommended data that should not flow to Disciple.Tools by default:

-   private reflection notes
-   unsubmitted drafts
-   sensitive prayer requests
-   full participant response history
-   raw lesson discussion unless explicitly designed and permissioned

## Success Criteria

The pilot succeeds if:

-   participants can access lessons with low friction
-   the huddle leader can facilitate meaningful discussion
-   lessons help participants reflect on and follow Jesus
-   the weekly rhythm feels realistic
-   privacy expectations are understandable
-   leader visibility is helpful but not invasive
-   the team learns which digital features are truly needed
-   the pilot produces clear direction for MVP development

The pilot does not need to prove every future feature.

## Questions for Follow-Up Spikes

### For S3 / Issue #10: Huddle to Disciple.Tools Group Mapping

-   Should a J-Life huddle map to a Disciple.Tools Group or Team?
    -   Answer: Use Group first for ordinary participant huddles. Reserve Team for leadership training cohorts, ministry task groups, or future leader-focused materials.
-   Which Disciple.Tools fields should represent huddle leader, members, and coach?
-   Should participant contacts be created in Disciple.Tools for the pilot?
-   What summary should appear in a J-Life Disciple.Tools tile?
-   How do parent/child group relationships apply, if at all?

### For S4 / Issue #11: Magic Links

-   Should pilot participants use accounts, magic links, or static links?
-   Can a magic link expose or mutate another participant’s data if forwarded?
-   Should links expire after a period of time?
-   Should links be scoped to one participant, one huddle, and one lesson?
-   What is the fallback for participants with older phones or in-app browsers?

### For S5 / Issue #12: Privacy

-   What exact content is visible to participants, leaders, coaches, pastors, and admins?
-   What does “private” mean in user-facing language?
-   Should private notes exist in the first pilot?
-   How are prayer requests shared or kept private?
-   What access-control tests must pass before real participant data is used?

### For Issue #7: Pilot Lessons

-   Which 5-7 life-of-Jesus events should be selected?
-   Should each lesson be one week?
-   What format is easiest for the first field test: static page, PDF, printable document, or platform page?
-   Who provides theological review?
-   Who provides Vietnamese language review?
-   How will field feedback be collected?

## Initial Recommendation

The first pilot should begin with a simple 5-7 week Vietnamese J-Life huddle.

Recommended starting posture:

-   model the huddle as a Disciple.Tools Group
-   use STUDY for participant lesson experience
-   use Disciple.Tools for huddle oversight only
-   avoid storing private reflections in Disciple.Tools
-   keep online discussion minimal until privacy rules are proven
-   collect enough field feedback to guide the MVP

This gives the project a clear ministry workflow without overbuilding the platform too early.
