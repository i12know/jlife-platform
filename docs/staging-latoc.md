# latoc staging: deploy and rollback runbook

Status: development/pilot staging for the 104-Day SOAP Bible Marathon ([#49](https://github.com/i12know/jlife-platform/issues/49), draft item M1). Subset of [#17](https://github.com/i12know/jlife-platform/issues/17).

**What runs there (Option C):** WordPress multisite at `https://latoc.vayhub.org/`, STUDY site only, with `jlife-studies` and `jlife-huddles`. **No Disciple.Tools and no `jlife-bridge`** (magic links stay off until #37/#38). Search engines are discouraged. A pinned **Polylang** serves the public bilingual landing page ([#110](https://github.com/i12know/jlife-platform/issues/110); see [Public landing pages](#public-landing-pages-en--vi)).

> **Hosting checkpoint (D9), decided 2026-11-20:** latoc **stays on the shared hosting account** it uses with other sites, including after the Nov 29 shadow pilot stores real participants' SOAP entries. This is a cost decision. **The shared-hosting risk remains**: sites on one account are not isolated from each other, so every site's security affects latoc's data. Reducing that exposure ([#163](https://github.com/i12know/jlife-platform/issues/163)) and off-site backups ([#162](https://github.com/i12know/jlife-platform/issues/162)) are deferred to 2027 at low priority. Until then, the only backups are the per-deploy database dumps described below. latoc should stay portable, so another ministry can host its own copy. See [framework-direction.md](framework-direction.md).

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
- **Publish order:** edit the wording, replace the placeholder, delete the DRAFT notice, get the Vietnamese reviewed, then publish the English and Vietnamese page of each. Publish all three before the Nov 29 shadow pilot stores real participant data.

### D19: youth (13 to 17) group sharing needs no setting

The church's D19 policy (no separate guardian approval step in J-Life) is the **default**: the setting `jlife_challenges_require_guardian_consent` is off unless it is set to `1`. Nothing needs configuring. To check a site: `wp option get jlife_challenges_require_guardian_consent` should say the option does not exist, or print `0`. If it prints `1`, turn the guardian step off with `wp option update jlife_challenges_require_guardian_consent 0`. Sharing stays optional, chosen by the author, and limited to their own groups; private entries stay private.

## Pilot feedback post (#171)

During the Nov 29–Dec 13 shadow pilot, testers leave feedback as **comments on one ordinary WordPress post**. They don't need a GitHub account. A small **Pilot Feedback** / **Góp ý thử nghiệm** link in the footer of the participant screens leads there.

| | GitHub (the code) | wp-admin (the owner) |
|---|---|---|
| The post: wording, slug, language, publishing | **creates nothing**; a deploy never touches it | writes, publishes and edits it |
| Comments on the post, moderation, replies | **opens nothing**; no global comment setting is changed. On this post only, the "log in to comment" link goes to `/signin` | turns comments on for this post only, approves and replies |
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
     > **To comment, sign in with your LATOC account first** (the same email code you use for the Bible Marathon). **Approved comments are public.** Please do not post private SOAP reflections, passwords, email addresses (yours or anyone else's), invite codes, screenshots showing private information, or pastoral or prayer matters about anyone. The first name on your account appears with your comment; your email address is never shown.
     >
     > [DRAFT: Vietnamese below is for the Vietnamese reviewers to check before publishing.]
     >
     > Xin giúp chúng tôi cải thiện LATOC! Nếu có điều gì khó hiểu, không hoạt động hoặc cần đơn giản hơn, xin để lại nhận xét bên dưới. Hãy cho biết trang đang dùng, thiết bị/trình duyệt, các bước đã thử và kết quả. Nhận xét sẽ được duyệt trước khi đăng. Xin đừng chia sẻ bài SOAP riêng tư hoặc thông tin cá nhân.
     >
     > **Để nhận xét, xin đăng nhập bằng tài khoản LATOC của bạn trước** (cùng mã qua email bạn dùng cho Marathon Kinh Thánh). **Nhận xét đã được duyệt sẽ hiển thị công khai.** Xin đừng đăng bài SOAP riêng tư, mật khẩu, địa chỉ email (của bạn hay của người khác), mã mời, ảnh chụp màn hình có thông tin riêng tư, hoặc các vấn đề mục vụ hay cầu nguyện của bất kỳ ai. Tên trong tài khoản của bạn sẽ hiển thị cùng nhận xét; địa chỉ email của bạn không bao giờ được hiển thị.

2. **Give the post a language (Polylang).** In the editor sidebar, the **Languages** box should say **English**; set it if it doesn't. **Don't** add a Vietnamese translation: one bilingual post keeps one comment thread. Leave Languages > Settings alone, because the deploy re-asserts them.
3. **Turn comments on for this post only.** In the post sidebar, open Discussion and tick **Allow comments**. Leave pingbacks and trackbacks off. Don't change the site default for new posts (Settings > Discussion > Default post settings).
4. **Hold every comment for approval, accounts only, no avatars.** Settings > Discussion (owner decisions, 2026-11-20):
   - Tick **Users must be registered and logged in to comment**. **No signed-out comments.** A signed-out visitor sees the post and approved comments, plus "You must be logged in to post a comment". On this post only, that link goes to `/signin` (not `wp-login.php`, where participants can't sign in) and returns to the comment form.
   - Tick **Comment must be manually approved**.
   - Tick **Email me whenever … a comment is held for moderation**.
   - Keep the default link limit (2 links holds a comment).
   - **Untick Show Avatars** (Avatar Display). Otherwise each commenter's email hash goes to Gravatar.

   These settings are site-wide, but they only matter where comments are open, which is this post. A participant comments under their account's display name, which is the first name they gave on `/join`.
5. **Who can comment:** anyone with a LATOC account who has signed in, so the tester needs to have joined (any join mode). Membership in the LATOC Pilot Testers group is not required. Staff comment and reply from wp-admin, signed in with the standard WordPress login.
6. **Publish**, then click **View Post** and note the real address. With the `/blog/%postname%/` permalink structure it should be `https://latoc.vayhub.org/blog/pilot-feedback/`. Open it signed in as a participant to confirm the comment form shows, and signed out to confirm there is only the sign-in prompt.
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
- Signed out, open the post. There is no comment form, only "You must be logged in to post a comment". Its link opens `/signin` and, after signing in, returns to the post's comment form. No avatar images appear anywhere on the post.
- Signed in as a test participant (a `.test` account), leave a comment. It says it is awaiting moderation, and it isn't visible signed out. Approve it in Comments > Pending and reply; both now show on the post under the first name. Then delete the test comment.
- Unset the option. The link is gone from every screen.

## WordPress default content, and the top menu (#263)

**Defaults on a fresh site.** The first deploy runs `wp core multisite-install`, and WordPress's installer always creates a published **Sample Page** (`/sample-page/`) and a **Hello world!** post. The first run of `wp jlife help-pages seed` (the deploy's "Help, Rules and Privacy pages" step) now moves both to the **Trash** when they are still the untouched defaults: right type and slug (`sample-page` page, `hello-world` post), published, and never edited (modified time equals created time). A page the owner made or edited with that slug is left alone, nothing is ever permanently deleted, and the step runs only once (the same "seeded" flag as the help pages). Restore from Pages > Trash if you want one back.

**latoc itself** was installed before this step existed, so its seed flag is already set and the step does not run there. Both were trashed by hand on 2026-11-26 (#263); on another older site, do it once: wp-admin > Pages > Sample Page > Trash, and Posts > Hello world! > Trash. (Local `npm run env:up` and `bin/cloud-dev.sh` sites also keep the defaults; they are dev sites and nothing seeds the help pages there.)

**How the top menu is built.** The theme is not in this repository (latoc runs a theme chosen in wp-admin; the Divi notes below suggest Divi). On 2026-11-26 latoc had **no menus at all** (`wp menu list` was empty), so its top menu is the theme's fallback: every published top-level page. Check **Appearance > Menus** on any site:

- If a menu is assigned to the **Primary / Header menu** location, the top menu is that fixed list. A new page does not appear until someone adds it there.
- If no menu is assigned, Divi (like most themes) falls back to **all published top-level pages**, newest ordering per its setting. Then **any page you publish shows up in the top menu**, which is how Sample Page appeared next to Rules and Privacy.

Before the Nov 29 pilot, create a menu with only the pages you want (for example Rules, Privacy, Help) and assign it to the primary location, or keep every other page as a draft until it is ready. Drafts never show in the fallback list. With Polylang, make the menu for English and assign a Vietnamese one too (Appearance > Menus > Manage Locations).

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
  */16 * * * * <home directory>/.latoc/cron.sh >/dev/null 2>&1
  ```

- **The interval is 16 minutes, set by the host, not by us.** It was installed
  as `*/5`. On 2026-11-20 between 10:05 and 10:16 UTC it changed to `*/16`
  (found in `cron.log` during the 2026-11-21 admin QA). The owner didn't make
  the change and the deploy never touches the crontab. Bluehost's help article
  [Cron Job Basics](https://www.bluehost.com/help/article/cron-job-basics)
  (updated 2026-07-07) says shared servers may not run a cron job more often
  than every 15 minutes. It doesn't say the host rewrites jobs that break the
  rule, but the timing fits that. **Keep `*/16`:** never set it below 15
  minutes. `*/16` fires at :00, :16, :32 and :48, so the gaps are 16 or
  12 minutes.
- **Effect on reminders.** The WP-Cron event stays every 5 minutes, but it can
  only run when the server cron fires it. So a check can run up to about
  16 minutes late, and the first reminder emails can go out up to about
  16 minutes after 06:00 campaign time. The 06:00–12:00 send window leaves
  plenty of room.

- `wp-config.php` has `DISABLE_WP_CRON` true (set with `wp config set`; the
  deploy leaves an existing `wp-config.php` alone, so it survives redeploys).

Health check: `tail ~/.latoc/cron.log` should show a line from the last
16 minutes, and `wp cron event list` should show no event more than about
12 minutes overdue. With `*/16`, the event is overdue by at most 11 minutes
just before a run. The Reminders page warns when the check has not run for
more than 15 minutes, so with `*/16` that warning means at least one server
cron run was missed: check `cron.log` and cPanel → Cron Jobs. To go back to visit-triggered WP-Cron: `wp config delete DISABLE_WP_CRON`,
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
- **preview-remove** changes nothing. It prints what a removal would do, as
  counts only (campaigns, rows per table, accounts that would be deleted or
  kept), never names, emails or IDs, so it is safe in the public workflow log.
  Run it first and record the counts.
- **remove** deletes every test campaign (campaign keys starting `test-`)
  with its groups, memberships, enrollments, SOAP entries and revisions,
  points, shares, reminder choices, queued reminder emails and audit rows,
  and **keeps every account**. Alpha testers are real participants (owner
  decision on #174): they keep their sign-in and join the pilot like anyone
  else. The deploy never deletes accounts; the server command has an explicit
  `--delete-accounts` flag for a fully disposable staging copy only.

Before the removal, the deploy takes its usual database backup
(`~/.latoc/backups/`), which still holds the test data if it is ever needed.
Run the removal before inviting real participants (by the Nov 29 announcement,
which is also the pilot's Day 1, at the latest). The pilot campaign must **not** use a
`test-` key, or a later removal would delete it.

The same commands exist on the server: `wp jlife test-campaign create|status`
and `wp jlife test-campaign remove [--dry-run] [--delete-accounts]`. `status`
also prints the counts `remove` would affect.

## Pilot campaign (#178)

The Nov 29–Dec 13 pilot ("practice period", 15 days) is a normal campaign whose key starts
with `pilot-`, never `test-`, so the test-campaign removal cannot delete it.

- **Create it** with the deploy choice **pilot_campaign = create** (or
  `wp jlife pilot create` on the server). It makes `pilot-2026-12` (Nov 29–Dec 13,
  2026, Pacific time, active, same reading plan as the Marathon). Running it
  again changes nothing. It creates no groups and enrolls no one. The deploy
  creates the pilot with the `reflection-v1` scoring engine (an existing
  pilot keeps its engine). If `status` shows another engine, run
  `wp jlife campaign set-engine <id> reflection-v1` before Day 1.
- **Check it** with **pilot_campaign = status** (`wp jlife pilot status`):
  key, dates, phase, scoring engine, number of enrollments, and how many people chose
  "I'll start Dec 20". Counts only, safe in the public log.
- **Do this after the test campaign is removed** and before the Nov 29
  announcement. Sign-ups from creation (Nov 28 evening) join the pilot by default (the join rule,
  design §3b); from Dec 14 00:00 Pacific new sign-ups go to the Marathon.
- **While the pilot is current** its participants see "Practice period: Nov 29–Dec 13.
  Points don't count for prizes. The Marathon starts Dec 20." above Today, My
  Marathon, My Group and `/share`.
- **"I'll start Dec 20"** appears on `/join` while the pilot is what people
  join. It creates the account and a flag; the person is not enrolled and gets
  no email until the rollover (#180) and their reminder choice (#181).

## Official Marathon and rollover, part 1 (#180)

Server commands only for now (cPanel → Terminal or SSH; `wp` means the WP-CLI
call from the restore section, with `--url=https://latoc.vayhub.org`). A deploy
choice comes with #183. Every command prints counts, dates and campaign keys
only, so the output is safe to paste into an issue. Design: §3d of
[challenges-engine-design.md](challenges-engine-design.md).

**Timing risk.** An active, upcoming campaign takes new sign-ups whenever
nothing nearer is open (design §3b). With the pilot on the site that is the
plan: sign-ups join the pilot until Dec 13 and the Marathon from Dec 14. With
**no** pilot campaign, creating the Marathon sends every new sign-up straight
to Dec 20 and hides "I'll start Dec 20". `wp jlife official create` therefore
refuses when no pilot campaign exists, unless `--force` is given.

1. **By Dec 14: create the official campaign** (any time after the pilot
   exists; it is not current for anyone, and takes no sign-ups, until the pilot
   ends):

   ```bash
   wp jlife official create
   wp jlife official status
   ```

   `create` makes `marathon-2026` (2026-12-20 to 2027-04-02, Pacific, active,
   104 days, the pilot's reading plan) or reports that it exists; nothing
   changes the second time. `status` prints, for example:

   ```text
   marathon-2026 (id 14): 2026-12-20 to 2027-04-02, status active, phase upcoming, 104 days.
     enrollments          0
     entries              0
     ledger_rows          0
     shares               0
   New sign-ups join now: pilot-2026-12 (id 13).
   ```

2. **Dec 14: close the pilot** (design §3c): `wp jlife campaign close <pilot id> --dry-run`,
   then without `--dry-run`.
3. **Take a backup** (run the deploy, or `wp db export` into `~/.latoc/backups/`).
4. **Preview, then execute, then verify** (IDs from `wp jlife pilot status`
   and `wp jlife official status`):

   ```bash
   wp jlife campaign rollover --from=<pilot id> --to=<official id> --preview
   wp jlife campaign rollover --from=<pilot id> --to=<official id> --execute
   wp jlife campaign rollover --from=<pilot id> --to=<official id> --verify
   ```

   Preview and execute print the two campaigns, then one count per line:

   ```text
   From: pilot-2026-12 (id 13), closed.
   To:   marathon-2026 (id 14), upcoming, 2026-12-20 to 2027-04-02.
     source_enrollments                 41
     waiting_flags                       6
     already_enrolled                    2
     to_enroll                          44
     skipped_left_source                 1
     skipped_removed_in_destination      0
     skipped_wait_details_missing        0
   ```

   Execute adds `enrolled_now` and ends with "Enrolled N; M were already
   enrolled." Verify prints `expected`, `enrolled_once`, `missing`,
   `duplicate_enrollments`, `destination_enrollments`,
   `destination_ledger_rows`, `destination_entries`, `destination_shares`,
   `executed`, `source_changed_by_run` and `source_changed_since_run`, and ends
   with "Verified" or an error (non-zero exit) naming what is wrong.
   `source_changed_since_run` only reports pilot changes made after the run
   (for example someone removing a pilot post under Earlier reading); it does
   not fail the check.
5. **If execute stops part-way** (timeout, lost connection): run execute
   again. Each enrollment is written once; nothing is duplicated.
6. **If someone joins the pilot or chooses to wait after the run** (before the
   pilot is closed), run execute again; only the newcomers are added.
7. **Rehearse first** on a restored copy or with a throwaway pilot and official
   campaign, and record the preview, execute and verify counts on #180 by
   Dec 18.

What it does not do: groups (#182), reminder consent (#181: nobody gets an
official reminder until they choose it), guardian consent (recorded again by an
organizer where that option is on), and nothing in the pilot changes.

**Rollback:** before Dec 20 nobody can write in the official campaign, so a
wrong run is undone by restoring the backup from step 3. Do not delete
enrollment rows by hand.

## Rollover, part 2: official groups (#182)

Run after part 1 has executed and verified (people must be enrolled in the
Marathon before they can be put in its groups), and by Dec 18. Design: §3d,
"Part 2". The plan file holds display names and user IDs: keep it in a private
folder on the server (for example `~/.latoc/`), never in the web root, never
attached to an issue or pasted into chat, and delete it after Dec 20.

1. **Write the plan** (counts only on screen):

   ```bash
   wp jlife campaign rollover-groups --from=<pilot id> --to=<official id> --plan=$HOME/.latoc/groups-plan.csv --preview
   ```

   It refuses to overwrite an existing plan file; pick a new name or delete the
   old one first.
2. **Review and edit it** (File Manager → download, or a text editor on the
   server). One row per person per group; the first line and the header stay
   as they are:

   ```text
   # jlife rollover-groups plan; from=13; to=14
   group_key,group_name,role,user_id,age_band,name_for_review
   g21,Friday Friends,starter,105,adult,Anh Starter
   g21,Friday Friends,member,108,minor,Bao Youth
   g22,Youth Group,member,108,minor,Bao Youth
   ```

   - **Rename a group:** change `group_name` on every row of that group.
   - **Merge two groups:** give one group's rows the other group's
     `group_key` and `group_name` (delete a row if that person is already in
     the other group).
   - **Drop a group:** delete its rows. Its people stay in the Marathon, solo
     unless they are in another group.
   - **Move a person:** change the `group_key` and `group_name` on their row.
   - **A new group:** use a new key such as `new-1` on its rows.
   - `role` is `member` or `starter` (adults only; a starter can reset the
     group's code). `age_band` and `name_for_review` are for reading only.
   - Don't change a key after step 4 has run.
3. **Validate** (writes nothing; repeat until there are no errors):

   ```bash
   wp jlife campaign rollover-groups --from=<pilot id> --to=<official id> --plan=$HOME/.latoc/groups-plan.csv --validate
   ```

   It prints `groups`, `rows`, `memberships_to_add` and `not_enrolled`, and
   one line per error class: `bad_row`, `unknown_role`,
   `group_name_invalid`, `group_name_mismatch`, `duplicate_membership`,
   `minor_starter`, `group_limit`, `start_limit`. `not_enrolled` counts rows
   for people who are not in the Marathon (never added; not an error).
4. **Back up, then execute:**

   ```bash
   wp jlife campaign rollover-groups --from=<pilot id> --to=<official id> --plan=$HOME/.latoc/groups-plan.csv --execute
   ```

   It prints `groups_created`, `groups_existing`, `memberships_added`,
   `memberships_existing`, `not_enrolled` and `membership_errors`. Each new
   group gets a new invite code, valid until Apr 2, written **only** to
   `groups-plan.csv.codes.csv` next to the plan (mode 0600), never to the
   screen. Send each leader their group's code or link from that file, then
   delete the file. A lost code: J-Life → Groups → reset the group's code.
   Running execute again adds nothing; if it stopped part-way, running it
   again finishes it.
5. **Verify:**

   ```bash
   wp jlife campaign rollover-groups --from=<pilot id> --to=<official id> --plan=$HOME/.latoc/groups-plan.csv --verify
   ```

   It prints `groups_planned`, `groups_found`, `groups_missing`,
   `memberships_planned`, `memberships_found`, `memberships_missing`,
   `role_mismatch`, `extra_members`, `unplanned_groups`, `not_enrolled` and
   `plan_errors`, and ends with "Verified" or an error. `extra_members` (people
   who joined with a new code) and `unplanned_groups` (groups made by hand)
   are reported, not failures; an empty unplanned group left by a crash during
   execute can be disabled in J-Life → Groups.

Old pilot codes never open an official group: the pilot is closed, so its
codes open nothing at all.

## Daily devotionals (#211)

A bilingual devotional (lead-in, teaching and reflection questions in **one** rich body) shows above the writing box on Today, `/soap?day=` and earlier days, and on the public `/reading/` page. It is written outside WordPress, exported to Markdown, loaded in wp-admin, and fixed by hand in the block editor (Gutenberg). No deploy, SSH or WP-CLI step is needed. Fallback if this is not ready: daily lead-ins as normal blog posts, with links sent by SMS.

**How it is stored.** One non-public `jlife_devotional` post per reading-plan day and language. Draft or Ready is the post's own status. It is **not** on the Reading Day post, so the deploy's reading-plan import (which re-imports every day and forces Reading Days to publish) cannot publish, change or create a devotional, and importing devotionals never touches a Reading Day.

**When it shows.** Only a **Ready** (published) devotional, only for a day that has **opened in the reader's campaign**, and only in the reader's language (no fallback to the other language: a missing translation shows nothing). The pilot's Day 1 is Nov 29 and the Marathon's Day 1 is Dec 20; they share the same Reading Day, so release follows each campaign's calendar. The post's own date is never used, so marking something Ready on Dec 1 shows nothing until Nov 29 (pilot) or Dec 20 (Marathon).

### 1. Write and export to Markdown

File name: `D001-EN_Any-Title.md` and `D001-VI_Any-Title.md`. `D001` is the day number of the reading plan (`D104` is the last); `EN` or `VI` is the language; what follows the underscore is free. UTF-8, 200 KB at most.

Structure (one body; nothing else is needed):

```
# The devotional's title

## Luke 1:1-4

Paragraphs, **bold**, *italic*, [links](https://example.org) and lists.

### Reflection questions

1. A question the reader may answer.
2. Another question.
```

- `#` is the title (it becomes the post title). `##` is the Scripture line. A `###` heading containing *reflection*, *questions* or *discussion* (or the Vietnamese *suy ngẫm*, *câu hỏi*, *thảo luận*) starts the questions section. The import wraps that heading and the lines under it (up to the next `#`, `##` or `###` heading) in a marked section, `<section class="jl-reflection-questions" aria-labelledby="…">`, inside a Custom HTML block (#225). The marker, not the heading's words, is what the app reads, so a reworded or translated heading still works. Those lines are the questions shown above the participant's writing box and the ones that do not count toward their own words (#210).
- **Only the first** questions heading is marked. Keep the questions and any response instructions together under it. Put nothing else under that heading, because everything up to the next heading counts as a question line.
- **Fixing by hand:** in the block editor keep the questions inside the **Custom HTML** block that holds the section (edit its HTML), or add the same `section` wrapper by hand. If you delete the wrapper, the app falls back to the lines recorded at the last import (so a later edit to the text will no longer show or be excluded until you re-import or restore the wrapper). Edits inside the section show up at once in the writing box and in the word-count exclusion.
- **Older devotionals** imported before the marker have no section; they keep working through the recorded lines. Re-import them (tick **Overwrite my edits** if you hand-edited) to add the marker.
- **What participants see:** a Ready devotional released for their day and language switches their writing box to **My reflection** (blank start, optional **Use SOAP**); without one it stays **My SOAP**. A Draft or an unreleased day changes nothing.
- **Images are not supported yet** (#216): they are removed, and the preview says how many.
- Raw HTML is shown as text, not run. Links to anything but http, https, mailto and tel are disabled.

**From Google Docs:** File → Download → **Markdown (.md)**. Rename the file as above.

**From Word:** upload the `.docx` to Google Drive, right-click → Open with → Google Docs, then File → Download → Markdown (.md). Check headings came across as headings (Format → Paragraph styles) before exporting.

### 2. Upload and preview

wp-admin → **J-Life → Devotionals**. Choose one or more `.md` files (hold Ctrl or Shift) and press **Preview (no changes)**. Many hosts accept about 20 files per upload, so send more in several rounds. The uploaded files are not kept.

The preview writes nothing. Each file shows its day, language and one result:

| Result | Meaning |
|---|---|
| New | Will be created as a **Draft** |
| Changed | The file differs from the last import; the post will be updated |
| Unchanged | Same as the last import; nothing will happen |
| Skipped: edited in wp-admin since the last import | Someone changed it in the editor; kept unless you tick **Overwrite my edits** |
| Skipped: it is in the Trash | Restore it from the Trash first |
| Cannot be read | Bad file name, not `.md`, day not in the plan, not UTF-8, empty, over 200 KB, or the same day and language twice in one upload |

Warnings (for example "Images are not supported yet; 1 removed", or no questions section) are listed beside the file.

### 3. Import

Press **Import**. New devotionals are **Drafts**. Import never changes Draft or Ready, and uploading the same file again changes nothing and creates no duplicate. The preview's converted text is kept for one hour for this button (the page says so if it has expired; just preview again). The audit log records counts only: never a file name or any text.

### 4. Check, fix by hand, mark Ready

The **Days** grid lists every day against English and Vietnamese with Draft / Ready, an **Edit** link and **Mark Ready** / **Back to Draft**.

- **Edit** opens the standard **block editor** (Gutenberg). Fix wording, add a heading, tick **This is a SOAP practice day** in the side panel, then Update. Revisions are kept. The imported body opens as a Classic block; use "Convert to Blocks" if you like.
- Publishing in the editor, or **Mark Ready** in the grid, makes it Ready. Ready still waits for the day to open in the reader's campaign (see above).
- **SOAP practice day:** the reader sees a short suggestion that points to the "Use SOAP template" button below the writing box. Nothing they type is changed and scoring is unchanged.

### 5. Re-uploading after hand edits

A hand edit is detected by comparing the post with what the last import left. If a corrected file is uploaded, a hand-edited post is **skipped** (listed as such) so nothing is erased. Tick **Overwrite my edits** on the Import form only when the file should replace the hand edits. An unedited post that is Ready stays Ready when the file changes.

### Divi

The post type uses the standard editor only. The theme's page builder is switched off for it. **Verify on latoc after the first deploy:** open any devotional with Edit. It must open in the block editor with no "Use The Divi Builder" button. If Divi's builder appears, switch the devotional post type off in Divi → Theme Options → Builder → Post Type Integration, and tell us.

### Checks after a deploy

1. J-Life → Devotionals loads; the grid lists the plan's days.
2. A devotional that was Ready before the deploy is still Ready and its text is unchanged (the deploy re-imports reading plans, not devotionals).
3. Signed out, `https://latoc.vayhub.org/wp-json/wp/v2/jlife-devotionals` answers 401, and a Ready devotional's text is not readable anywhere before its day opens.

### Acceptance demo (owner)

Take one devotional drafted in Word or Google Docs, export it to Markdown, and upload the EN and VI files. Preview, import as Draft, mark Ready, and check it appears on the right campaign day (pilot Day 1 on Nov 29; or, before that, with a `test-` campaign). Run a routine deploy and check it survives. Upload the same files again and confirm nothing is created or erased; edit one in the block editor, upload again and confirm it is skipped.

## Day 1 reading is Luke 1:1-4; John 1:1-18 (#219)

Primary-plan Day 1 (`d001`) was John 1:1-18 alone; it is now **Luke 1:1-4; John 1:1-18**, in that order, to match the Day 1 devotional. Days 2-104, day IDs, dates, scoring and the supplemental Harmony plan are unchanged.

**No operator step is needed beyond the normal deploy.** The deploy workflow runs `wp jlife reading-plan import` on the shipped plan JSON every time. The importer upserts by `plan_id` + `day_id`: the existing `d001` Reading Day post is **updated in place** (same post ID, slug and URL), not skipped and not duplicated. Its Day 1 data (passages, links, Vietnamese references) is replaced; d002-d104 are re-written with identical data. SOAP entries and shares key on `day_key` (`d001`), which does not change, so nothing is orphaned. The on-page reader reads the day's passages on each view, and its passage cache is keyed per source, version and passage, so Luke 1:1-4 is fetched fresh and John 1:1-18 keeps its cached row; no cache needs clearing. If a host page cache or CDN is added later, purge `/reading/` and Today for Day 1.

**Checks after the deploy** (any enabled Bible: KT1925, NLT/NKJV/CSB, link-only):

1. `wp jlife reading-plan import` output ends with 104 days for the primary plan and no error; J-Life Reading Days still lists 104 days, one `d001`.
2. `/reading/` for Day 1 (Nov 29 for the pilot) shows **Luke 1:1-4** above **John 1:1-18** in English and Vietnamese ("Lu-ca 1:1-4", "Giăng 1:1-18"), with the Bible Gateway link `Luke+1:1-4;John+1:1-18`.
3. Today and `/soap?day=d001` show the same two passages, in the same order.
4. A day 2 spot check is unchanged (Luke 1:5-25).

## Photo reflections (#244)

A photo of a handwritten page as another way to write the day's reflection. **Official Marathon only** (owner decision 1): the pilot stays text-only. Design and access rules: [challenges-engine-design.md §4j](challenges-engine-design.md).

### Turn it on (before Day 1)

It is a per-campaign setting, **off by default**, and locked like the scoring engine: it can change only before Day 1 and while the campaign has no entries.

```
wp jlife official create --photo-reflections      # a NEW official campaign, created with photos on
wp jlife campaign set-photos <campaign-id> on     # an existing campaign, before Day 1 and before any entry
wp jlife official status                          # prints "photo reflections on|off"
```

Do it right after **official create** (Dec 14). A starter who writes ahead (#209) creates an entry and locks the setting, so do not wait.

### Where the files are

`wp-content/jlife-private/site-<id>/`, outside the Media Library and `uploads/`, in random-named files with no extension. The folder has `.htaccess` (deny all) and `index.php`. A photo opens only through `/photo/<id>/`, after an access check; the answer to every refusal is the same 404.

- **On latoc the deploy does this automatically:** it sets `JLIFE_PHOTO_DIR` to `~/.latoc/photos` (mode 700, above the web root) in `wp-config.php` once, if it isn't set yet (the plugin appends `/site-<id>`). Existing files are never moved, so this must happen before the first photo. For another host, add the same `define` by hand.
- **nginx or any host that ignores `.htaccess`:** the deny rule does not apply. The random names still cannot be guessed, but use `JLIFE_PHOTO_DIR` outside the web root.
- **Backups:** the per-deploy backups contain **only the database**. Photos depend on the hosting account's own backups (off-site backups, #162, are deferred). A photo is about 300 KB, one per person per day, so 100 people for 104 days is about 3 GB at most.

### Review a participant's photos (owner, by download)

There is no review screen. The owner checks a prize finalist like this:

```
wp jlife photos export --user=<user-id> [--campaign=<campaign-id>]
```

It writes **one zip** of that person's *photo-earned* days (days where the photo, not the typed text, earned the point) to `~/.latoc/photos-u<user>-c<campaign>-<time>.zip` (mode 0600): `dNNN.jpg` per day and `days.csv` (campaign, day, date, upload time UTC, submission type, file). The output shows counts and the zip path only, no names or emails; the audit log records counts only. Download it with cPanel File Manager (Show Hidden Files), then **delete it**. A day whose photo the person later removed is listed with an empty file (the point stays: points are never taken back).

Find the user ID with `wp user list --field=ID --search=<part of the email>` on the host. Do not paste it, or the email, into an issue or a public log.

### Deleting photos

- An account's deletion deletes its photos and the copies shared to groups, files included.
- `wp jlife test-campaign remove` deletes the test campaign's photos (its preview counts them).
- Removing a share ("Yours — change or remove") deletes the group's copy.
- The 60-day pilot purge (#186) is not written yet: it must call `jlife_challenges_delete_campaign_photos( $campaign_id )`. The pilot is text-only, so it has none.

### Owner paste-list (the live pages are yours; nothing here is applied for you)

`data/help-pages.json` only seeds the Rules and Privacy pages the first time. For the live pages, paste these (the English and Vietnamese drafts are in that file, under **Rules** "A photo of a handwritten reflection" and **Privacy** "Photos of handwritten reflections"; both are marked for your review):

1. **Rules**, after the scoring rule: the "A photo of a handwritten reflection" section (three paragraphs). Check the honor-system sentence and who may look at the photos.
2. **Privacy**: the "Photos of handwritten reflections" section, and add **photos** to the list in "Keeping and deleting information" ("Deleting the account removes its SOAP entries, revisions, shares, **photos**, points, …").
3. **Consent page** (`/consent`, wording in code): version `draft-2026-11-24` is deployed. It has the photo bullet (a photo is private to you, stored apart from public files, shown to a group only if you tick its box; the owner may look at prize finalists' photos) and a campaign-neutral points bullet ("a reflection that meets your campaign's rule"). Participants re-accept it once; earlier acceptances are kept in the audit log. A wording change must bump `JLIFE_AUTH_CONSENT_VERSION`, which asks everyone again (a test guards this). The Vietnamese wording is a draft for the translator's review.

### QA checklist on latoc

Photo reflections need the setting on for the campaign you test with. The setting is locked once Day 1 is today (or any entry exists), and `wp jlife test-campaign create` makes a campaign whose Day 1 is **today** and has no photo flag, so `set-photos` is refused on a fresh test campaign. `wp jlife campaign set-photos <id> on` only works before Day 1 and before anyone writes.

- **On latoc, do not create an extra campaign for photo QA.** A second official campaign starting Dec 20 would compete with the real Marathon for new sign-ups and for the Dec 14 rollover, and `test-campaign remove` cannot delete it. Test photos in the official Marathon itself (created on Dec 14 with `--photo-reflections`) during Dec 14–19 via starter read-ahead, or on a disposable staging copy (the #183 rehearsal).
- **On a local or disposable site only,** create the QA campaign with photos already on: `wp jlife official create --key=<qa-key> --photo-reflections --force` (Day 1 is Dec 20, so it is still in the future).

1. **iPhone Safari:** the button offers both "Take Photo" and "Photo Library". Take a photo and choose one. The preview appears, "Photo saved" shows, Submit gives the point, and the grid tile is green with a small camera and the label "Point earned – photo upload" (VoiceOver reads it).
2. **Android Chrome:** the same, camera and gallery.
3. **Desktop:** the file picker; Replace and Remove; a PDF or a renamed `.exe` is refused with a message and nothing is saved.
4. **Airplane mode** after choosing: the message says it was not saved; choose it again online. Submit twice quickly: one point, one photo.
5. **Text beats photo:** a complete text plus a photo gives a text point (no camera). An empty box plus a photo gives a photo point.
6. **No JavaScript** (turn it off): the plain file input and Submit still work; Remove works.
7. **Sharing:** tick a group, Submit. A member sees the thumbnail in My Group (opens full size); a non-member (another group, signed out, another browser) gets a 404 on `/photo/<id>/` for both the copy and the author's private photo. Replace your photo: the group's copy does not change until you tick again. "Yours — change or remove" removes the copy (404 afterwards). Leave the group: the member's access ends.
8. **Public file URLs:** with a file name from `wp-content/jlife-private/` (cPanel), `https://<site>/wp-content/jlife-private/site-<id>/<name>` returns 403 or 404.
9. **EXIF:** download the full-size image and open its properties: no location or camera data; upright even if the phone was sideways.
10. **Export:** `wp jlife photos export --user=<id>` makes the zip in `~/.latoc/`; open it: `dNNN.jpg` for each photo-earned day and `days.csv`; delete it.
11. **Deletion:** delete the test account (or `wp jlife test-campaign remove`): the files in `wp-content/jlife-private/site-<id>/` are gone.
12. **Layout:** 375 px and 320 px, light and dark: the preview and buttons fit; the grid camera is legible on the green tile; Tab reaches the picker button and shows a focus ring.

## Backups and restore

- Every deploy writes `~/.latoc/backups/<UTC timestamp>.sql.gz`; the 10 most recent are kept.
- Bluehost's own account backups also apply.
- **Restore a database backup** (cPanel → Terminal):

  ```bash
  cd ~/.latoc && ls -1t backups | head
  gunzip -c backups/<timestamp>.sql.gz | /opt/cpanel/ea-php82/root/usr/bin/php ~/.latoc/wp-cli-2.11.0.phar --path=<document root> db import -
  ```

- **Restore test** ([#56](https://github.com/i12know/jlife-platform/issues/56)): [`bin/deploy/latoc-restore-test.sh`](../bin/deploy/latoc-restore-test.sh) loads the newest backup into a scratch database (`<cpanel user>_rtest`, created and deleted with cPanel's `uapi`), compares table and row counts with the live site, and always deletes the scratch database. It only reads the live database and prints no passwords (the database password goes to a `mktemp` file, mode 600, removed on exit). Paste the script into cPanel → Terminal as `~/.latoc/restore-test.sh`, run it, then delete it. Re-run it before the Nov 29 shadow pilot and after any change to the backup step.
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
