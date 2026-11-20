# latoc staging: deploy and rollback runbook

Status: development/pilot staging for the 104-Day SOAP Bible Marathon ([#49](https://github.com/i12know/jlife-platform/issues/49), draft item M1). Subset of [#17](https://github.com/i12know/jlife-platform/issues/17).

**What runs there (Option C):** WordPress multisite at `https://latoc.vayhub.org/`, STUDY site only, with `jlife-studies` and `jlife-huddles`. **No Disciple.Tools and no `jlife-bridge`** (magic links stay off until #37/#38). Search engines are discouraged. A pinned **Polylang** serves the public bilingual landing page ([#110](https://github.com/i12know/jlife-platform/issues/110); see [Public landing pages](#public-landing-pages-en--vi)).

> **Hosting checkpoint (D9), decided 2026-11-20:** latoc **stays on the shared hosting account** it uses with other sites, including after the Dec 5 shadow pilot stores real participants' SOAP entries. This is a cost decision. **The shared-hosting risk remains**: sites on one account are not isolated from each other, so every site's security affects latoc's data. Reducing that exposure ([#163](https://github.com/i12know/jlife-platform/issues/163)) and off-site backups ([#162](https://github.com/i12know/jlife-platform/issues/162)) are deferred to 2027 at low priority. Until then, the only backups are the per-deploy database dumps described below. latoc should stay portable, so another ministry can host its own copy. See [framework-direction.md](framework-direction.md).

## Pieces

| Piece | Where |
|---|---|
| Workflow | [`.github/workflows/deploy-latoc.yml`](../.github/workflows/deploy-latoc.yml), manual trigger only |
| Server script | [`bin/deploy/latoc-remote.sh`](../bin/deploy/latoc-remote.sh), idempotent, uploaded on each run |
| Landing-page seed (#110) | [`bin/deploy/latoc-landing.php`](../bin/deploy/latoc-landing.php) (Polylang settings + one-time draft seed) and [`bin/deploy/latoc-landing-content.php`](../bin/deploy/latoc-landing-content.php) (starting block markup, EN and VI). Both travel in the plugin bundle; the script keeps them in `~/.latoc/landing/` |
| Help, Rules and Privacy pages (#65) | `wp jlife help-pages seed` (run by the deploy), copy in [`plugins/jlife-challenges/data/help-pages.json`](../plugins/jlife-challenges/data/help-pages.json); see [Help, Rules and Privacy pages](#help-rules-and-privacy-pages-65) |
| Pilot feedback post (#171) | Nothing deployed: the owner writes the post in wp-admin and sets `jlife_pilot_feedback_post_id`; see [Pilot feedback post](#pilot-feedback-post-171) |
| Secrets | GitHub → Settings → Environments → `latoc-staging` (9 secrets; deployments restricted to `main`) |
| Server state | `~/.latoc/` on the host (mode 700): WP-CLI, backups, debug log, first-install admin password, `landing-state` (page statuses and slugs for the smoke test; no secrets) |
| PHP | Site and WP-CLI both use cPanel's PHP 8.2 (`/opt/cpanel/ea-php82/root/usr/bin/php`); the host's default CLI PHP is 8.3 |

## Deploy

1. Merge the change to `main`.
2. GitHub → **Actions** → **Deploy latoc staging** → **Run workflow** (branch `main`).
3. The run:
   - bundles `jlife-studies` and `jlife-huddles` (no tests or vendor files)
   - uploads them with the server script
   - sends the settings over SSH into a 0600 file that the script deletes
   - on the server: installs WordPress if absent, **backs up the database** (stops if the backup fails), swaps in the plugins and activates them
   - **WordPress core follows the pin in `.wp-env.json`** (the version CI tests). After the backup, a server older than the pin is updated to it (`wp core update`, network database upgrade, core language packs); one already on the pin or newer is left alone, never downgraded. Core checksums are then verified, and a mismatch stops the deploy. To upgrade WordPress, bump the pin in a PR (CI tests it), merge, and deploy. Don't use "Update now" in the dashboard: staging would run a version CI hasn't tested.
   - installs the **pinned Polylang** (checksum-verified, per site), the Vietnamese language packs, re-asserts the language settings, and **seeds the two landing pages once, as drafts** (see below)
   - smoke test: the front page answers HTTP 200, the magic-link route is off, and the landing pages behave as described under [Smoke test](#smoke-test)

**Logs are public** because the repository is public. The workflow never prints secrets, and GitHub masks secret values. Don't add debugging that echoes settings.

## Public landing pages (EN / VI)

Issue [#110](https://github.com/i12know/jlife-platform/issues/110). Spike and conditions: [`docs/spikes/2026-11-18-polylang-latoc-landing.md`](spikes/2026-11-18-polylang-latoc-landing.md). English is at `/`, Vietnamese at `/vi/`. The app screens (`/join/`, `/signin/`, `/today/`, ...) are unprefixed and untouched.

### Who owns what

| | GitHub (the deploy) | wp-admin (the owner) |
|---|---|---|
| Polylang: version, per-site activation, languages, URL settings (`/` English, `/vi/` Vietnamese, no browser detection, `redirect_lang` on, jlife post types untranslated), language packs | sets and **re-asserts these on every run** | **do not change** Languages > Settings; the next deploy would reset it |
| The two pages: content, titles, slugs, logo, switcher position | creates them **once** | **edits them**; the deploy never touches them again |

The pages are ordinary WordPress pages. The Markdown in `content/latoc/` is only the starting draft and the review record; it is not kept in sync afterwards.

### What the deploy creates, and when

On the first run after this change (and only then) it creates two **drafts**, both titled "Life and Teaching of Christ (LATOC)", with slugs `welcome` (English) and `chao-mung` (Vietnamese), linked to each other as Polylang translations. Each page starts with the language switcher (a Polylang block in the page content, not in the header), uses core blocks with the brand palette as inline styles, and has no image.

- **Copy:** only the "Page copy" and "CTA block" sections of `landing-page.md` and `landing-page.vi.md`. Reviewer notes, PENDING/decision blockquotes, translator notes, the logo section and the unapproved acknowledgement line are **not** included (add the acknowledgement in wp-admin once its wording is approved).
- **The Vietnamese is an unreviewed, machine-assisted draft.** The translator must review it before it is published.
- **CTA routes:** the buttons use the real [#112](https://github.com/i12know/jlife-platform/issues/112) routes, in both the hero and the final call: "Read on my own" is `/join/?mode=solo`, "Start a group" is `/join/?mode=start`, "I have an invite code" is `/join/`, and "Sign in" is `/signin/`. The Vietnamese page adds `lang=vi` to each (the app screens show Vietnamese only when the URL says so; without it a Vietnamese visitor lands on an English form). The seed holds no hidden notes. Seeding is unchanged: these links only apply to a first seed; see "Owner step" below for pages that already exist.
- **No logo:** none is checked in. Add the approved logo in the editor (an Image block at the top, with the alt text from `design/latoc/brand-guidelines.md`).
- The page title is the theme's single h1; the content starts at h2.

"Seeded" is recorded in the option `jlife_landing_seeded`. Each page also carries the meta `_jlife_landing_seed`, so an interrupted first run can finish. After that:

- **Edits are never overwritten.** An edit saved in the block editor survived later deploys byte for byte.
- **A page the owner deletes or trashes is not recreated.** To recreate on purpose, delete the option (`wp option delete jlife_landing_seeded`) and re-deploy: only missing pages are created, as drafts, and linked to the surviving one.

### Owner step: point the live buttons at the real routes

The seed is applied once and never overwrites an edit, so the seeded pages that already exist on the site still have the old links (all three sign-up buttons on `/join/`). Updating the seed file does not change them. In wp-admin, edit each landing page and set the button links (each appears twice: the hero and the final call) to the values below. Use the page's **Link** field on each button; type the address exactly, `&` included.

| Button | English page (`/`) | Vietnamese page (`/vi/`) |
|---|---|---|
| Read on my own / Đọc một mình | `/join/?mode=solo` | `/join/?mode=solo&lang=vi` |
| Start a group / Lập một nhóm | `/join/?mode=start` | `/join/?mode=start&lang=vi` |
| I have an invite code / Tôi có mã mời | `/join/` | `/join/?lang=vi` |
| Sign in / Đăng nhập (text link) | `/signin/` | `/signin/?lang=vi` |

Check afterwards: from `/vi/` every sign-up button opens a Vietnamese form, and from `/` an English one. The old hidden `TODO(#112)` notes, if the page has them, can be deleted (they are invisible Custom HTML blocks).

### Nothing new is public until the owner publishes

- Both pages are **drafts**: `/welcome/` and `/vi/chao-mung/` answer 404 to visitors.
- **The front page is not changed while the English page is a draft.** `/` keeps serving what it served before.
- Switching the front page is the owner's step, in one of two ways:
  1. **One click, at once:** publish the English page in Pages, then **Settings > Reading** > "Your homepage displays: A static page" > Homepage: the English landing page, Save. (Both landing pages share a title; pick the one marked EN in the Pages list.) `/vi/` serves the Vietnamese page as soon as that page is published too; no deploy is needed.
  2. **Automatically on the next deploy:** if the English page is published and the site has no static front page, the deploy sets it, **once** (option `jlife_landing_front_done`). If the owner already chose a front page, the deploy leaves it and records that. It never changes the front page again.
- Publishing the English page alone is allowed: `/vi/` then answers 404 until the Vietnamese page is published.
- Search engines stay discouraged (`blog_public 0`). Removing noindex at launch stays a separate, explicit owner decision.

Side effects of Polylang that exist even while the pages are drafts (none carries page content): hreflang `<link>` tags on `/`, a functional `pll_language` cookie on public pages (no cookie notice, owner decision), and Vietnamese-language feed URLs under `/vi/`. In the replica, `/vi/` answered 200 with the existing front page even before Polylang was installed (WordPress's multisite fallback); with Polylang it answers 200 (an empty Vietnamese-chrome blog index) while the front page is still the blog, and 404 once the English page is the front page and the Vietnamese page is a draft.

### Smoke test

The workflow reads `~/.latoc/landing-state` (statuses and slugs only) and checks, on top of the front-page and magic-link checks:

| State | Expected |
|---|---|
| any | `/vi/` answers 200 or 404, never another status or a redirect loop (curl stops after 5 redirects) |
| English page is a draft | `/<slug>/` answers 404 |
| Vietnamese page is a draft | `/vi/<slug>/` answers 404 |
| both published and the English page is the front page | `/` has `<html lang="en...">` and a `hreflang="vi"` alternate; `/vi/` answers 200 with `<html lang="vi...">` |

So the drafts-only state passes, a draft that became public would fail the run, and the strict pair check switches on by itself once the owner publishes.

### Things that can silently break it

- Never activate Polylang network-wide, and never create a subsite with the slug `vi` or `en`. The deploy stops with an error if it finds either.
- Keep the Polylang version pinned (`POLYLANG_VERSION` in the script, 3.9.2). The deploy reinstalls the pin if the installed version differs and verifies the checksums. Keep plugin auto-updates off; bumping the pin is a reviewed PR that re-runs the spike's route checks.
- Polylang caches its language list; the deploy clears it and flushes rewrites after each run. If `/vi/` ever redirects to the Vietnamese page's slug, re-run the deploy.
- The Vietnamese page's browser `<title>` is the site title until the owner sets a Vietnamese site title in Languages > Translations.
- The deploy resets web file permissions after the Polylang install and language packs too (the script's strict umask would otherwise make them unreadable to Apache).

## Help, Rules and Privacy pages (#65)

Three ordinary WordPress pages, in English and Vietnamese, linked as Polylang translations. The wording is **the owner's** (human-only lane, `docs/team.md`): the deploy seeds clearly marked **DRAFT** starting copy, once, and never touches it again.

| | GitHub (the deploy) | wp-admin (the owner) |
|---|---|---|
| The six pages (3 × EN/VI): wording, titles, slugs, publishing | creates them **once**, as **drafts** | **edits and publishes them**; the deploy never updates them |
| The three page-ID options | fills in an option **only if it is empty** | may point an option at another page |

- **What is seeded** (`wp jlife help-pages seed`, run by the deploy after Polylang is set up; copy in [`plugins/jlife-challenges/data/help-pages.json`](../plugins/jlife-challenges/data/help-pages.json)): How it works (`how-it-works` / `cach-hoat-dong`), Rules (`rules` / `quy-dinh`), Privacy (`privacy` / `quyen-rieng-tu`). Each starts with a DRAFT notice to delete once approved. The Vietnamese is a draft marked for translator review.
- **Contact placeholder:** the pages say `[privacy contact email]` where the privacy and deletion contact goes. No real address is in the repository. Replace it in wp-admin (it appears on How it works and Privacy, in both languages). Rules also has an `[Owner to confirm: ...]` line about prize eligibility for ages 13 to 17.
- **Seeded once:** recorded in the option `jlife_challenges_help_pages_seeded`; each page also carries the meta `_jlife_help_seed`. An edited page is never overwritten, and a page the owner deletes or trashes is not recreated. To recreate on purpose: `wp option delete jlife_challenges_help_pages_seeded` and re-deploy; only missing pages are created.
- **Page IDs live in options** (the English page; Polylang resolves the reader's language): `jlife_challenges_help_page_id`, `jlife_challenges_rules_page_id`, `jlife_challenges_privacy_page_id`. `wp jlife help-pages status` prints each option, its ID and the page's status. To use a page you wrote yourself: `wp option update jlife_challenges_privacy_page_id <page ID>`.
- **Where the links appear.** A small Help · Rules · Privacy footer on Today, `/soap`, My Marathon, My Group, `/share`, the sign-out confirmation, `/join`, `/signin` and `/consent`, in the participant's language (the Polylang translation when it is published, else the page the option names). A **Privacy** link ("Read the full privacy summary") on the consent step. A "How SOAP works" link beside the SOAP one-liner's "More". **A link appears only when its page is published**: drafts and missing pages give no link, never a broken one. So until the owner publishes the pages, the screens look exactly as before, and the consent step has no Privacy link.
- **Keep the SOAP anchor.** The heading "What SOAP means" on the Help page has the HTML anchor `what-is-soap`, which the SOAP link jumps to. If you retitle that heading, keep the anchor (Advanced > HTML anchor in the editor).
- **Publish order:** edit the wording, replace the placeholder, delete the DRAFT notice, get the Vietnamese reviewed, then publish the English and Vietnamese page of each. Publish all three before the Dec 5 shadow pilot stores real participant data.

### D19: youth (13 to 17) group sharing needs no setting

The church's D19 policy (no separate guardian approval step in J-Life) is the **default**: the setting `jlife_challenges_require_guardian_consent` is off unless it is set to `1`. Nothing needs configuring. To check a site: `wp option get jlife_challenges_require_guardian_consent` should say the option does not exist, or print `0`. If it prints `1`, turn the guardian step off with `wp option update jlife_challenges_require_guardian_consent 0`. Sharing stays optional, chosen by the author, and limited to their own groups; private entries stay private.

## Pilot feedback post (#171)

During the Dec 5–13 shadow pilot, testers leave feedback as **comments on one ordinary WordPress post**. They don't need a GitHub account. A small **Pilot Feedback** / **Góp ý thử nghiệm** link in the footer of the participant screens leads there.

| | GitHub (the code) | wp-admin (the owner) |
|---|---|---|
| The post: wording, slug, language, publishing | **creates nothing**; a deploy never touches it | writes, publishes and edits it |
| Comments on the post, moderation, replies | **opens nothing**; no global comment setting is changed | turns comments on for this post only, approves and replies |
| The footer link | shows it only while the option names a **published** post | sets or deletes the option |

**Where the link appears.** It sits at the end of the Help · Rules · Privacy footer on Today, `/soap`, My Marathon, My Group, `/share`, the sign-out confirmation, `/join`, `/signin` and `/consent`. On the public `/reading/` day pages, which have no other footer, it appears on its own. It shows for signed-in and signed-out visitors, and its label follows the screen language. The address comes from WordPress (`get_permalink()`), never a typed URL. A published Polylang translation of the post is used for Vietnamese if one exists; otherwise both languages go to the same bilingual post, so there is one comment thread. **There is no link while the option is unset or the post is a draft, private, scheduled, password-protected, trashed or deleted.**

**Comments and the theme.** No jlife plugin blocks comments, and none opens them. Twenty Twenty-Five's single-post template includes the comments block (comment list and form), which renders the form only when comments are open on that post. If latoc runs another theme (Appearance > Themes), check that its single-post template shows comments.

### Owner steps (wp-admin, before testers are invited)

1. **Write the post.** Posts > Add New.
   - **Title:** `LATOC Pilot Testing - Feedback & Questions`
   - **Slug:** `pilot-feedback`. In the post sidebar, open Summary > URL.
   - **Body:** paste the text below. It is English first, then Vietnamese. The Vietnamese is a **draft for the Vietnamese reviewers**; publish it only after they've reviewed it. Remove the `[DRAFT …]` line once they approve.

     > Help us improve LATOC! If something is confusing, broken, or could be easier, leave a comment below. Include the page, device/browser, what you tried, and what happened. Comments are reviewed before publication. Please do not share private SOAP entries or personal information.
     >
     > **Approved comments are public.** Please do not post private SOAP reflections, passwords, email addresses (yours or anyone else's), invite codes, screenshots showing private information, or pastoral or prayer matters about anyone. The name you give appears with your comment; your email address is never shown.
     >
     > [DRAFT: Vietnamese below is for the Vietnamese reviewers to check before publishing.]
     >
     > Xin giúp chúng tôi cải thiện LATOC! Nếu có điều gì khó hiểu, không hoạt động hoặc cần đơn giản hơn, xin để lại nhận xét bên dưới. Hãy cho biết trang đang dùng, thiết bị/trình duyệt, các bước đã thử và kết quả. Nhận xét sẽ được duyệt trước khi đăng. Xin đừng chia sẻ bài SOAP riêng tư hoặc thông tin cá nhân.
     >
     > **Nhận xét đã được duyệt sẽ hiển thị công khai.** Xin đừng đăng bài SOAP riêng tư, mật khẩu, địa chỉ email (của bạn hay của người khác), mã mời, ảnh chụp màn hình có thông tin riêng tư, hoặc các vấn đề mục vụ hay cầu nguyện của bất kỳ ai. Tên bạn nhập sẽ hiển thị cùng nhận xét; địa chỉ email của bạn không bao giờ được hiển thị.

2. **Give the post a language (Polylang).** In the editor sidebar, the **Languages** box should say **English**; set it if it doesn't. **Don't** add a Vietnamese translation: one bilingual post keeps one comment thread. Leave Languages > Settings alone, because the deploy re-asserts them.
3. **Turn comments on for this post only.** In the post sidebar, open Discussion and tick **Allow comments**. Leave pingbacks and trackbacks off. Don't change the site default for new posts (Settings > Discussion > Default post settings).
4. **Hold every comment for approval.** Settings > Discussion:
   - Tick **Comment must be manually approved**.
   - Tick **Email me whenever … a comment is held for moderation**.
   - Keep the default link limit (2 links holds a comment).
   - **Turn Show Avatars off.** Otherwise each commenter's email hash goes to Gravatar.

   These settings are site-wide, but they only matter where comments are open, which is this post.
5. **Decide who may comment** (owner decision). Also in Settings > Discussion:
   - **Signed-out testers allowed** (recommended for the pilot; matches #171): untick **Users must be registered and logged in to comment**, and tick **Comment author must fill out name and email**. The email is stored for moderation and never shown.
   - **Accounts only:** tick **Users must be registered and logged in to comment**. Participants who are signed in through `/signin` comment under their account's display name, which is then shown publicly with the comment.
6. **Publish**, then click **View Post** and note the real address. With the `/blog/%postname%/` permalink structure it should be `https://latoc.vayhub.org/blog/pilot-feedback/`. Open it signed out to confirm the comment form shows.
7. **Point the footer link at it.** The post ID is the number after `post=` in the editor's address. In cPanel > Terminal (`wp` = the WP-CLI call from the restore section, with `--url=https://latoc.vayhub.org`):

   ```bash
   wp option update jlife_pilot_feedback_post_id <post ID>
   ```

   Check it with `wp option get jlife_pilot_feedback_post_id` and `wp post get <post ID> --field=post_status`, which should print `publish`.
8. **Moderate and reply.** Comments > Pending: **Approve**, **Spam** or **Trash**. Reply from the row's **Reply** link; a reply from an administrator is published at once. Before approving, edit out anything private (Quick Edit). Copy confirmed bugs into GitHub Issues **without** names, emails or other private details.

### After the beta (Dec 13) and for the Dec 20 launch

- **Remove the link:** run `wp option delete jlife_pilot_feedback_post_id`. The link disappears from every screen at once; no deploy is needed. Then untick **Allow comments** on the post, or set it to Draft or Private.
- **"Help & Feedback" at launch:** the simplest option is to rely on the existing **Help** link and add a feedback section to the Help page. To keep a separate link instead, open a small PR that renames the label (`Pilot Feedback` in `plugins/jlife-challenges/includes/pilot-feedback.php`, with its Vietnamese entry in `languages/jlife-challenges-vi.l10n.php`). Then point the same option at the launch post. The Help, Rules and Privacy links are unaffected either way.

### Smoke test

- Visit `/today/`, `/marathon/`, `/group/`, `/join/`, `/signin/` and a `/reading/` day page at 375 px wide, signed out where possible and signed in. Each shows the **Pilot Feedback** link at the bottom, after the page content, and it opens the post's real address.
- Add `?lang=vi` on `/join/` and `/signin/`, and use a Vietnamese account on the other screens. The label reads **Góp ý thử nghiệm** and goes to the **same** post.
- Open `/vi/` and the post's address. The post opens (HTTP 200), not a 404 or a redirect to another page.
- Signed out, leave a test comment with a `.test` email address. The page says it is awaiting moderation, and it is not public. Approve it in Comments > Pending and reply; both now show on the post. Then delete the test comment.
- Unset the option. The link is gone from every screen.

## First install: admin login

The first run creates the network admin `latoc-admin` with a random password, saved only on the server:

1. cPanel → File Manager → turn on "Show Hidden Files" → open `~/.latoc/admin-password`.
2. Log in at `https://latoc.vayhub.org/wp-admin/` as `latoc-admin`.
3. **Immediately** change that user's email to a real mailbox (it starts as a placeholder `admin@latoc.vayhub.org`) and set your own password.
4. Delete `~/.latoc/admin-password`.

## Transactional email (Brevo, #63 Phase A)

The J-Life Sign-in plugin includes an explicitly enabled, site-scoped Brevo
transport for WordPress mail on the configured network/site IDs. It also handles
the test-campaign invitation sent from WP-CLI. Other sites retain their own mail
behavior. Daily reading reminders (#63 Phase B) use this transport; see
[Daily reading reminders](#daily-reading-reminders-63-phase-b).

1. Deploy the reviewed code with `test_campaign = none`. No real email is sent by
   deployment. The deploy installs a private settings include in `wp-config.php`;
   it does not create or overwrite the credentials file.
2. Check reachability on the host using its PHP 8.2 and WP-CLI:

   ```bash
   /opt/cpanel/ea-php82/root/usr/bin/php ~/.latoc/wp-cli-2.11.0.phar --path="$HOME/latoc.vayhub.org" --url=https://latoc.vayhub.org jlife mail probe
   ```

   This verifies the certificate and SMTP greeting on port 465 without logging
   in or sending. If blocked, stop and evaluate Brevo HTTPS delivery separately;
   do not disable certificate checking or fall back silently.
3. The owner creates a dedicated staging SMTP key in Brevo (not an API key or
   account password). Use the exact SMTP login and the approved sender/reply-to
   recorded in #63. Keep staging and production credentials separate.
4. Copy `config/latoc-mail.example.php` into **`~/.latoc/mail.php` on the host**,
   set its real site URL, SMTP login/key, approved From name/address and reply-to,
   and set permissions to **0600** (the `.latoc` directory is 0700). Enter the key
   privately; never put this real file in GitHub, a deployment archive, screenshots
   or chat. Both web PHP and WP-CLI read it before plugins load. Keep the product
   name `LATOC` for branded sign-in subjects. Publishing or editing a page does
   not change these mail settings.
5. Run `jlife mail status` with the same PHP/WP-CLI prefix. It must report
   `enabled_here`, `configured`, and `private_config` as true; it never prints
   credentials or message data. The approved key must be nonempty; the template
   placeholder is rejected. Check the matching web runtime by requesting a
   sign-in code and inspecting the subsequent status and actual received email.
6. Send only to owner-designated test inboxes. Check From/Reply-to, LATOC subject,
   arrival before the 10-minute expiry, single use, inbox/spam, DKIM/DMARC headers,
   and Brevo delivery events. An `accepted` status proves only that `wp_mail`
   returned success, not delivery. Recheck after a redeploy: the private file
   must survive and both web/CLI must still work.

The transport uses implicit TLS to `smtp-relay.brevo.com:465`, certificate checks,
authentication, a 10-second timeout, and no SMTP debug output. While explicitly
enabled, missing configuration or send failures return failure rather than using
host mail. Participant responses remain generic. Staff see an admin notice on
failure; `jlife_mail_health` holds only the allowlisted outcome and UTC timestamp,
and server logging uses only that outcome. No recipient, code, body, credentials,
or raw provider errors are stored by this integration. Brevo itself can retain
transactional message content: review its account retention/access settings.
Its open and click tracking cannot be turned off on the Free plan (see **Brevo
settings** under Daily reading reminders).

**Rotation:** create a replacement staging key privately, atomically replace the
protected file with its new value (same 0600 permissions), test delivery, then
revoke the old key. No redeploy is needed. Never disable an existing key until
its replacement passes, except during a confirmed credential compromise.

**Disable/rollback:** set `JLIFE_MAIL_ENABLED` to `false` in the protected file;
this explicitly restores WordPress's original host-mail behavior for this site.
That is an operator decision, never an automatic failure fallback. Reverting the
code leaves the protected file intact but unused. Preserve it for recovery and
revoke the dedicated key in Brevo if it is no longer needed. No credentials are
stored in WordPress options, so a normal database export does not contain them.
The host's full-account backups may include the protected file.

## Daily reading reminders (#63 Phase B)

One email a day at **06:00 Pacific** (the campaign's timezone, DST-correct) to
people who opted in, in the language they chose. Retries stop at **12:00**. It
sends through the Brevo transport above; "accepted" means Brevo took it, not
that an inbox received it.

**Real cron is required.** The reminder is a WP-Cron event every 5 minutes. With
`DISABLE_WP_CRON` true and no server cron it never runs (see #56). Installed by
hand on 2026-11-19 (not by the deploy):

- `~/.latoc/cron.sh` (mode 0700) lists the network's sites and, for each, runs
  `cron event run --due-now` with the PHP 8.2 and WP-CLI prefix used above. A
  lock directory (`~/.latoc/cron.lock`, no `flock` on this host) stops runs
  overlapping; a lock older than 20 minutes is treated as stale.
- It writes one line per site per run to `~/.latoc/cron.log`
  (`<UTC time> <site> rc=<exit> Success: Executed a total of N cron events.`),
  rotated to `cron.log.1` at 1 MB.
- The account crontab (cPanel → Cron Jobs shows the same entry):

  ```text
  MAILTO=""
  */5 * * * * <home directory>/.latoc/cron.sh >/dev/null 2>&1
  ```

- `wp-config.php` has `DISABLE_WP_CRON` true (set with `wp config set`; the
  deploy leaves an existing `wp-config.php` alone, so it survives redeploys).

Health check: `tail ~/.latoc/cron.log` should show a line from the last 5
minutes, and `wp cron event list` should show no event overdue by more than
that. To go back to visit-triggered WP-Cron: `wp config delete DISABLE_WP_CRON`,
then remove the line with `crontab -e` (or cPanel → Cron Jobs).

**Use the dashboard page to switch modes.** A site admin opens **J-Life →
Reminders** (or **Settings** under J-Life Challenges on the Plugins screen) to see
the mode, the opted-in count, today's queued / accepted / failed / skipped counts,
the send window and the next scheduled check, plus warnings (cron not running,
Brevo not on or not configured, test mode with no addresses, more than 300
recipients). The buttons are Off, Dry run, Test and Live; **Live** needs the
confirmation box ticked and is refused while the mail settings are not ready or Brevo is not turned on for the site; Off
is always allowed. Every change is recorded in the audit log under the admin's
user ID. **WP-CLI is the fallback** (it works the same, and does not ask for the
Live confirmation), for example if the dashboard is unreachable:
`wp jlife reminders mode <mode>`.

**Modes** (it starts `off` and a deploy never changes it):

| Mode | What happens |
|---|---|
| `off` | Nothing is queued or sent |
| `dry_run` | Builds the queue and every message, marks each row `skipped`, sends nothing. A day with no reading shows as `failed` (`no_reading`) |
| `test` | Sends only to the account emails listed in `JLIFE_REMINDER_TEST_RECIPIENTS`; everyone else is `skipped` |
| `live` | Sends to every current recipient |

Switching from `dry_run` to `live` on the same day sends nothing more that day:
the dry run already logged each person's row for that day.

**Test recipients.** Define the constant in the private config on the host
(`~/.latoc/mail.php`, mode 0600), never in the repository or in chat. Use the
addresses of the owner's own test accounts, comma-separated:

```php
define( 'JLIFE_REMINDER_TEST_RECIPIENTS', 'owner-test@example.invalid,second-test@example.invalid' );
```

Test mode also needs those people to have staging accounts that are enrolled,
have given consent and opted in to reminders.

**Commands** (same prefix as above, followed by):

- `jlife reminders status [--campaign=<id>]`: mode, today's counts by status, the
  next scheduled tick (flagged overdue if the server cron is not running). No
  addresses.
- `jlife reminders preview --day=<n> [--locale=vi] [--html]`: prints the subject
  and text for a made-up recipient. Sends and logs nothing. Use it to read the
  Vietnamese wording before a pilot.
- `jlife reminders run`: one tick now, the same work the cron event does.
- `jlife reminders mode <off|dry_run|test|live>`

**Brevo settings** (checked on the Free plan, 2026-11-19, [#63](https://github.com/i12know/jlife-platform/issues/63#issuecomment-7013213884)):

- **Tracking cannot be turned off.** Transactional → Settings offers only
  *Anonymous email tracking?*, which stays **No** (owner decision). With Yes,
  opens and clicks are still recorded, just not tied to a Brevo contact. Turning
  tracking off is an Enterprise request, and SMTP has no per-message control. So
  Brevo may rewrite the links in the HTML email (Today and our unsubscribe link)
  through its tracking domain and record opens.
- **There is no Brevo unsubscribe-link setting** for transactional mail, and
  nothing indicates Brevo adds one to SMTP messages. Our unsubscribe is the
  signed one-click link (`/unsubscribe/`) plus the `List-Unsubscribe` header; it
  changes only reminder choices, never sign-in.
- **Watch *Blocked or unsubscribed contacts*** during the pilot. An address on
  that list (spam report, repeated bounces, unsubscribe) silently stops getting
  **all** our mail, sign-in codes included. WordPress cannot see this: Brevo
  accepts the message and drops it. The only fix is removing the address there
  by hand.
- **Verify in PR 3** on one received reminder: whether the Today link is
  rewritten through Brevo's tracking domain, and that our `/unsubscribe/` link and
  `List-Unsubscribe` header are the only unsubscribe. If rewriting matters, the
  options are showing the Today address as plain text too, or sending reminders
  as plain text only. Text-only mail is likely to carry neither the open-tracking
  image nor rewritten links; confirm that on a received message.

**Staging acceptance order** (PR 3): `dry_run` for a day and read `status`;
`test` for three days with the owner's inboxes; then `live` only when the owner
says. Check each morning: one email each, correct language, unsubscribe works.
To stop at any time: click **Off** on the Reminders page, or run
`jlife reminders mode off`.

The send log (`reminder_sends`) holds user IDs, status, attempt count, times and
a fixed error class, never an address, subject, body or provider error text.
Provider errors, bounces and delivery events are in the Brevo dashboard.

## Test drive campaign

The deploy has a **test_campaign** choice (default `none`):

- **create** makes a "TEST Bible Marathon" whose Day 1 is today (Pacific),
  using the real 104-day plan, with one "Test Group". The join link is
  emailed to the site admin address and saved to `~/.latoc/test-invite.txt`
  (0600, readable via File Manager); it is never printed in the workflow log.
  Running **create** again keeps the same campaign and issues a new link (the
  old one stops working).
- **remove** deletes every test campaign (campaign keys starting `test-`)
  with its groups, enrollments, SOAP entries and revisions, points, shares and
  audit rows, and deletes participant accounts that were only ever in a test
  campaign, so testers start fresh. Staff accounts and anything in a real
  campaign are untouched. Run it before inviting real participants (by the
  Dec 5 shadow pilot at the latest). The pre-deploy backup still holds the
  test data if it is ever needed.

The same commands exist on the server: `wp jlife test-campaign create|remove|status`.

## Backups and restore

- Every deploy writes `~/.latoc/backups/<UTC timestamp>.sql.gz`; the 10 most recent are kept.
- Bluehost's own account backups also apply.
- **Restore a database backup** (cPanel → Terminal):

  ```bash
  cd ~/.latoc && ls -1t backups | head
  gunzip -c backups/<timestamp>.sql.gz | /opt/cpanel/ea-php82/root/usr/bin/php ~/.latoc/wp-cli-2.11.0.phar --path=<document root> db import -
  ```

- **Restore test** ([#56](https://github.com/i12know/jlife-platform/issues/56)): [`bin/deploy/latoc-restore-test.sh`](../bin/deploy/latoc-restore-test.sh) loads the newest backup into a scratch database (`<cpanel user>_rtest`, created and deleted with cPanel's `uapi`), compares table and row counts with the live site, and always deletes the scratch database. It only reads the live database and prints no passwords (the database password goes to a `mktemp` file, mode 600, removed on exit). Paste the script into cPanel → Terminal as `~/.latoc/restore-test.sh`, run it, then delete it. Re-run it before the Dec 5 shadow pilot and after any change to the backup step.
- **Last restore test: 2026-11-19, passed.** Backup `20261119T030929Z.sql.gz` (256 KB) passed `gzip -t`; scratch database created and granted (`status: 1`); import succeeded in under a second; **31 of 31 tables** restored; row counts matched live exactly for `options` (177), `users` (11), `posts` (120), `blogs` (1) and every J-Life table checked (campaigns, enrollments, group members, SOAP entries, score ledger, audit log); restored `siteurl` was `https://latoc.vayhub.org`; scratch database deleted.

## Rollback

- **Code:** revert the commit on `main`, then run the workflow again. Plugins are replaced wholesale on each deploy. (Reverting the landing-page change stops the deploy from managing Polylang; it does not remove Polylang or the pages. See the next item.)
- **Data:** restore the backup taken just before the bad deploy (see above). The run log prints its name ("Latest backup: …").
- **WordPress core:** the deploy never downgrades. To go back after a core update, restore that deploy's backup, then in cPanel > Terminal run `wp core update --version=<old version> --force`, and revert the pin on `main` so the next deploy doesn't upgrade again.
- **Polylang and the landing pages** (cPanel > Terminal; `wp` means the WP-CLI call from the restore section, with `--url=https://latoc.vayhub.org`). The pages and their content always survive:
  1. `wp plugin deactivate polylang`. `/` keeps serving the English page, `/vi/` becomes 404, the switcher block renders nothing, and the app routes are unchanged.
  2. Take the Vietnamese page out of public view (it would otherwise answer at its plain slug): in Pages, or `wp post update <VI page id> --post_status=draft`.
  3. Optional: remove the switcher block from the English page (the editor shows it as unsupported without the plugin).
  4. To delete Polylang's data too, set the opt-in constant, uninstall, then remove the constant. **`wp plugin uninstall polylang` alone leaves all Polylang data behind** (language terms, translation links, the `polylang` and `pll_*` options): Polylang's `uninstall.php` deletes it only when `PLL_REMOVE_ALL_DATA` is true.

     ```bash
     wp config set PLL_REMOVE_ALL_DATA true --raw --type=constant
     wp plugin uninstall polylang
     wp config delete PLL_REMOVE_ALL_DATA
     wp rewrite flush
     ```

     Verified in the replica: zero Polylang taxonomy rows and options afterwards, the pages and the front-page setting intact, the app routes identical.
  5. `jlife_landing_seeded` and `jlife_landing_front_done` are harmless leftovers; delete them (`wp option delete ...`) if the landing pages are ever to be seeded again. The Vietnamese language packs in `wp-content/languages/` can stay.

## Safety notes

- The deploy key in `LATOC_SSH_KEY` can log in to the **whole hosting account**, not only latoc. It lives only in the `main`-restricted environment. To revoke it, remove the `github-actions-latoc` line from `~/.ssh/authorized_keys`.
- The owner's workstation also has an account-wide SSH key (added 2026-11-19 in Bluehost → Hosting → SSH Keys; passphrase-protected, held in the owner's ssh-agent) so maintenance such as the cron above can be done over SSH instead of the cPanel Terminal. To revoke it, delete that key on the same Bluehost page.
- The script refuses to convert an existing single-site WordPress, and never touches other sites or their `.htaccess`. In latoc's `.htaccess` it adds the multisite rules once, above cPanel's PHP-handler block.
- Bluehost's bot protection may challenge automated requests to some URLs (e.g. `wp-login.php`). The smoke test only checks the front page.

## Verified so far

- **2026-11-18, landing-page deploy step (#110)**, run locally against a latoc replica: WordPress 7.0 subdirectory multisite, the four jlife plugins, both reading plans, Twenty Twenty-Five, MariaDB in Docker. **Not** the host's setup: PHP 8.3 (not 8.2) and PHP's built-in server with `bin/cloud-dev-router.php` (not Apache and its `.htaccess`). The real `latoc-remote.sh` was run, not a copy:
  - run 1 created both pages as drafts, linked them, set the Polylang options and left the front page alone; runs 2 and 3 changed nothing (same IDs, modified dates and content hashes);
  - an edit saved in the block editor survived a later deploy; a trashed page stayed trashed and a permanently deleted page was not recreated while the seed option was set; deleting the option recreated only the missing page and re-linked it;
  - the seeded markup opens in the block editor with no invalid or recovered blocks;
  - app routes (`/today /soap /marathon /share /group /signout /join /signin /consent`, `/reading/` day pages, `/wp-login.php`, `/wp-admin/`, `/wp-json/`, feeds) gave identical status, redirect chain, `lang`, canonical and robots with Polylang active (drafts and published) and with it deactivated; only the landing URLs differ;
  - with both pages published: `/` is English (`lang="en-US"`, canonical `/`, hreflang en and vi), `/vi/` is Vietnamese (`lang="vi"`, canonical `/vi/`); the switcher works both ways by mouse and keyboard at 360 px width with a visible 2 px focus outline; no horizontal scroll; one h1; the CTAs' targets (`/join/`, `/join/?mode=solo`, `/join/?mode=start`, `/signin/`, with and without `lang=vi`) return 200;
  - Settings > Reading (the owner's one-click path) made `/` and `/vi/` work with no deploy; a drifted Polylang version was reinstalled to the pin; rollback with `PLL_REMOVE_ALL_DATA` left no Polylang data;
  - the smoke-test logic passed in the drafts-only, English-only and both-published states.
  
  **Still to check on the real host:** PHP 8.2, Apache's `.htaccess` rules with `/vi/` (no real `vi/` directory), the host's page cache with the `pll_language` cookie, and the language-pack downloads.

- 2026-11-04, in a cloud dev session against a scratch database (not the real host):
  - first install and an idempotent re-run passed
  - the backup contained all tables
  - a run with backups unavailable stopped before changing anything
  - no secret appeared in any log
- **2026-11-04, first real run** ([run 41937755437](https://github.com/i12know/jlife-platform/actions/runs/41937755437)), green in 27 s:
  - WordPress 7.0 multisite installed; the front page serves "J-Life (staging)" with `noindex, nofollow`.
  - **Bug found:** the script's strict `umask 077` also applied to the WordPress files, so Apache returned 403 ("Server unable to read htaccess file") for `/wp-admin/` and every CSS/JS file.
  - Fixed by resetting web permissions on every run: directories 755, files 644, `wp-config.php` 600. Re-run the workflow to apply it.
- **2026-11-05, second run** (after #54): verified from the cloud session in a phone-size browser. Home 200; `/wp-admin/` redirects to the login page; login page fully styled; CSS/JS 200; `/hub/` 404 (no Disciple.Tools, as intended); no `jlife-bridge`.
- **2026-11-05, third run** ([run 41978189194](https://github.com/i12know/jlife-platform/actions/runs/41978189194), after #67): both 104-day reading plans imported. Day pages verified on latoc: Days 1, 38, 53, 71 and 104 return 200 with the expected passages and links. The magic-link probe (`/?jlife_token=…`) does not answer.
- From #68 on, the deploy itself fails if `jlife-bridge` is active or the magic-link route answers.
