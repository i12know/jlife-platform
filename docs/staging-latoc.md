# latoc staging: deploy and rollback runbook

Status: development/pilot staging for the 104-Day SOAP Bible Marathon ([#49](https://github.com/i12know/jlife-platform/issues/49), draft item M1). Subset of [#17](https://github.com/i12know/jlife-platform/issues/17).

**What runs there (Option C):** WordPress multisite at `https://latoc.vayhub.org/`, STUDY site only, with `jlife-studies` and `jlife-huddles`. **No Disciple.Tools and no `jlife-bridge`** (magic links stay off until #37/#38). Search engines are discouraged. A pinned **Polylang** serves the public bilingual landing page ([#110](https://github.com/i12know/jlife-platform/issues/110); see [Public landing pages](#public-landing-pages-en--vi)).

> **Hosting checkpoint (D9):** latoc currently shares a hosting account with other sites. That is accepted for development and test data. **Before the Dec 5 shadow pilot stores real participants' SOAP entries, the owner re-decides** whether latoc stays on the shared account or moves to its own.

## Pieces

| Piece | Where |
|---|---|
| Workflow | [`.github/workflows/deploy-latoc.yml`](../.github/workflows/deploy-latoc.yml), manual trigger only |
| Server script | [`bin/deploy/latoc-remote.sh`](../bin/deploy/latoc-remote.sh), idempotent, uploaded on each run |
| Landing-page seed (#110) | [`bin/deploy/latoc-landing.php`](../bin/deploy/latoc-landing.php) (Polylang settings + one-time draft seed) and [`bin/deploy/latoc-landing-content.php`](../bin/deploy/latoc-landing-content.php) (starting block markup, EN and VI). Both travel in the plugin bundle; the script keeps them in `~/.latoc/landing/` |
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
- **CTA routes:** "Read on my own" and "Start a group" do not exist yet ([#112](https://github.com/i12know/jlife-platform/issues/112)), so both point at `/join/` for now, like "I have an invite code"; "Sign in" goes to `/signin/`. Each page holds two invisible HTML-comment blocks (`TODO(#112)`, one in the hero and one above the final call) marking the two buttons to update when #112 ships. `/join/` and `/signin/` are English-only until #112 PR2 adds Vietnamese strings.
- **No logo:** none is checked in. Add the approved logo in the editor (an Image block at the top, with the alt text from `design/latoc/brand-guidelines.md`).
- The page title is the theme's single h1; the content starts at h2.

"Seeded" is recorded in the option `jlife_landing_seeded`. Each page also carries the meta `_jlife_landing_seed`, so an interrupted first run can finish. After that:

- **Edits are never overwritten.** An edit saved in the block editor survived later deploys byte for byte.
- **A page the owner deletes or trashes is not recreated.** To recreate on purpose, delete the option (`wp option delete jlife_landing_seeded`) and re-deploy: only missing pages are created, as drafts, and linked to the surviving one.

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
behavior. Daily reminders are not implemented by this change.

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
transactional message content: review its account retention/access settings and
keep unnecessary tracking off for authentication emails.

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

## Rollback

- **Code:** revert the commit on `main`, then run the workflow again. Plugins are replaced wholesale on each deploy. (Reverting the landing-page change stops the deploy from managing Polylang; it does not remove Polylang or the pages. See the next item.)
- **Data:** restore the backup taken just before the bad deploy (see above). The run log prints its name ("Latest backup: …").
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
- The script refuses to convert an existing single-site WordPress, and never touches other sites or their `.htaccess`. In latoc's `.htaccess` it adds the multisite rules once, above cPanel's PHP-handler block.
- Bluehost's bot protection may challenge automated requests to some URLs (e.g. `wp-login.php`). The smoke test only checks the front page.

## Verified so far

- **2026-11-18, landing-page deploy step (#110)**, run locally against a latoc replica: WordPress 7.0 subdirectory multisite, the four jlife plugins, both reading plans, Twenty Twenty-Five, MariaDB in Docker. **Not** the host's setup: PHP 8.3 (not 8.2) and PHP's built-in server with `bin/cloud-dev-router.php` (not Apache and its `.htaccess`). The real `latoc-remote.sh` was run, not a copy:
  - run 1 created both pages as drafts, linked them, set the Polylang options and left the front page alone; runs 2 and 3 changed nothing (same IDs, modified dates and content hashes);
  - an edit saved in the block editor survived a later deploy; a trashed page stayed trashed and a permanently deleted page was not recreated while the seed option was set; deleting the option recreated only the missing page and re-linked it;
  - the seeded markup opens in the block editor with no invalid or recovered blocks;
  - app routes (`/today /soap /marathon /share /group /signout /join /signin /consent`, `/reading/` day pages, `/wp-login.php`, `/wp-admin/`, `/wp-json/`, feeds) gave identical status, redirect chain, `lang`, canonical and robots with Polylang active (drafts and published) and with it deactivated; only the landing URLs differ;
  - with both pages published: `/` is English (`lang="en-US"`, canonical `/`, hreflang en and vi), `/vi/` is Vietnamese (`lang="vi"`, canonical `/vi/`); the switcher works both ways by mouse and keyboard at 360 px width with a visible 2 px focus outline; no horizontal scroll; one h1; the CTAs' targets `/join/` and `/signin/` return 200;
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
