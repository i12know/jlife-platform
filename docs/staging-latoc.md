# latoc staging: deploy and rollback runbook

Status: development/pilot staging for the 104-Day SOAP Bible Marathon ([#49](https://github.com/i12know/jlife-platform/issues/49), draft item M1). Subset of [#17](https://github.com/i12know/jlife-platform/issues/17).

**What runs there (Option C):** WordPress multisite at `https://latoc.vayhub.org/`, STUDY site only, with `jlife-studies` and `jlife-huddles`. **No Disciple.Tools and no `jlife-bridge`** (magic links stay off until #37/#38). Search engines are discouraged.

> **Hosting checkpoint (D9):** latoc currently shares a hosting account with other sites. That is accepted for development and test data. **Before the Dec 5 shadow pilot stores real participants' SOAP entries, the owner re-decides** whether latoc stays on the shared account or moves to its own.

## Pieces

| Piece | Where |
|---|---|
| Workflow | [`.github/workflows/deploy-latoc.yml`](../.github/workflows/deploy-latoc.yml), manual trigger only |
| Server script | [`bin/deploy/latoc-remote.sh`](../bin/deploy/latoc-remote.sh), idempotent, uploaded on each run |
| Secrets | GitHub → Settings → Environments → `latoc-staging` (9 secrets; deployments restricted to `main`) |
| Server state | `~/.latoc/` on the host (mode 700): WP-CLI, backups, debug log, first-install admin password |
| PHP | Site and WP-CLI both use cPanel's PHP 8.2 (`/opt/cpanel/ea-php82/root/usr/bin/php`); the host's default CLI PHP is 8.3 |

## Deploy

1. Merge the change to `main`.
2. GitHub → **Actions** → **Deploy latoc staging** → **Run workflow** (branch `main`).
3. The run:
   - bundles `jlife-studies` and `jlife-huddles` (no tests or vendor files)
   - uploads them with the server script
   - sends the settings over SSH into a 0600 file that the script deletes
   - on the server: installs WordPress if absent, **backs up the database** (stops if the backup fails), swaps in the plugins and activates them
   - checks that the front page answers HTTP 200

**Logs are public** because the repository is public. The workflow never prints secrets, and GitHub masks secret values. Don't add debugging that echoes settings.

## First install: admin login

The first run creates the network admin `latoc-admin` with a random password, saved only on the server:

1. cPanel → File Manager → turn on "Show Hidden Files" → open `~/.latoc/admin-password`.
2. Log in at `https://latoc.vayhub.org/wp-admin/` as `latoc-admin`.
3. **Immediately** change that user's email to a real mailbox (it starts as a placeholder `admin@latoc.vayhub.org`) and set your own password.
4. Delete `~/.latoc/admin-password`.

## Backups and restore

- Every deploy writes `~/.latoc/backups/<UTC timestamp>.sql.gz`; the 10 most recent are kept.
- Bluehost's own account backups also apply.
- **Restore a database backup** (cPanel → Terminal):

  ```bash
  cd ~/.latoc && ls -1t backups | head
  gunzip -c backups/<timestamp>.sql.gz | /opt/cpanel/ea-php82/root/usr/bin/php ~/.latoc/wp-cli-2.11.0.phar --path=<document root> db import -
  ```

## Rollback

- **Code:** revert the commit on `main`, then run the workflow again. Plugins are replaced wholesale on each deploy.
- **Data:** restore the backup taken just before the bad deploy (see above). The run log prints its name ("Latest backup: …").

## Safety notes

- The deploy key in `LATOC_SSH_KEY` can log in to the **whole hosting account**, not only latoc. It lives only in the `main`-restricted environment. To revoke it, remove the `github-actions-latoc` line from `~/.ssh/authorized_keys`.
- The script refuses to convert an existing single-site WordPress, and never touches other sites or their `.htaccess`. In latoc's `.htaccess` it adds the multisite rules once, above cPanel's PHP-handler block.
- Bluehost's bot protection may challenge automated requests to some URLs (e.g. `wp-login.php`). The smoke test only checks the front page.

## Verified so far

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
