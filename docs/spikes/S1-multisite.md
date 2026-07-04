# S1: WordPress multisite + Disciple.Tools install and update flow
Issue: #8 · Timebox: 1 week · Actual: ~0.5 day (exercised against the #15 dev environment)

## Question

Can a **subdirectory** WordPress multisite run the Disciple.Tools theme on a private
HUB subsite and a normal content theme on a public STUDY subsite, managed by the
`disciple-tools-multisite` plugin, with (a) both subsites running cleanly on one
network, (b) shared `wp_users` and per-subsite roles, and (c) a working
network-admin update flow? And what — if anything — does subdirectory mode break
(redirects, cookies, REST routes)? (architecture.md §1, §8; roadmap S1.)

## What we did

Stood up the `#15` dev environment (`npm run env:up`) and exercised it directly.
All facts below were observed on a live network, not inferred.

- Network: WordPress **7.0**, **subdirectory** multisite (`SUBDOMAIN_INSTALL` unset).
- STUDY at `/` on the `twentytwentyfive` block theme; HUB at `/hub/` on the
  **Disciple.Tools theme 1.82.2**.
- `disciple-tools-multisite` **1.17.0** network-activated; Magic Links
  (`disciple-tools-bulk-magic-link-sender`) **1.33.0** active on HUB.
- Seeded users per architecture.md §3 and ran `bin/verify.js` (**19/19 PASS**).
- Exercised the update flow by **downgrading** the D.T theme to 1.81.0, letting the
  network-admin update checker detect it, and applying the update back to 1.82.2.

## Findings (verified facts, with versions)

### 1. Both subsites run cleanly on one network — PASS

`bin/verify.js` reports 19/19, including after the update exercise below. STUDY
front page returns `200`; HUB root and `/hub/wp-admin/` `302`-redirect
unauthenticated users to `/hub/wp-login.php` (D.T's login-first posture); network
admin is reachable. No subdirectory rewrite breakage on inner pages.

### 2. Shared `wp_users`, per-subsite roles — PASS

One network-wide `wp_users` table (no per-site `wp_2_users`). Same account carries
**different roles per subsite**:

| User | Global ID | Role on STUDY (`/`) | Role on HUB (`/hub/`) |
|---|---|---|---|
| `leader1` | 2 | `subscriber` | `multiplier` |
| `participant1` | 4 | `subscriber` | *(none — no membership)* |

This is exactly the architecture.md §3 identity model: participants are excluded
from HUB by having **no role there**, while a leader is a single account with a
HUB role.

### 3. Network-admin update flow works — PASS (the pass-condition item)

`disciple-tools-multisite` registers a **Network Admin → "Disciple.Tools"** menu
(`network_admin_menu` → `add_menu_page`, cap `manage_options`) with tabs for
overview, network dashboard, Mapbox keys, SSO, storage, multisite migration, etc.

The update mechanism is the key finding. The plugin bundles Yahnis Elsts'
**plugin-update-checker (PUC v5)** and, **only** in a gated context
(`is_network_admin() || wp_doing_cron() || an update POST`, on the main site),
points the D.T theme and any D.T plugin (one that ships `version-control.json` and
has a GitHub `PluginURI`) at a GitHub-hosted version-control JSON. Those feed
WordPress's native `update_themes` / `update_plugins` transients, so the standard
**Network Admin → Updates** screen bulk-updates the whole network.

Exercised end-to-end:

1. Downgraded the HUB theme to **1.81.0** (simulated a stale network).
2. Loaded **Network Admin → Updates** as super admin → admin-bar showed a
   **"2 updates"** badge and `disciple-tools-theme` (1.81.0) listed under "The
   following themes."
3. In the gated context the checker populated the transient with the offered
   version (`disciple-tools-theme → 1.82.2`); the version-control sources returned
   `HTTP 200` and advertised the current versions (theme 1.82.2, multisite plugin
   1.17.0 — matching the pins, which is why nothing was stale until we forced it).
4. Applied the update (the same `Theme_Upgrader` path the network button drives):
   **1.81.0 → 1.82.2, "Theme updated successfully."** Re-verify stayed 19/19.

Note: the gating means a plain WP-CLI `wp theme update` will *not* see D.T updates
(no network-admin/cron context), and neither does a non-main subsite. This is
deliberate (keeps the update-checker off every front-end request), not a bug.
Bulk application from the network screen itself is JS/iframe-driven — fine in a
browser; scripted acceptance should drive it via cron context or the browser.

### 4. Subdirectory-mode conflicts with D.T — none blocking

- **REST routes:** resolve per-subsite. STUDY `/wp-json/` exposes only core
  namespaces (**no D.T leakage**). HUB `/hub/wp-json/` routes the `dt-*`
  namespaces correctly and returns **`401 rest_cannot_access`** to anonymous
  callers — D.T's "authenticated users only" REST posture — while its
  `dt-public/v1` namespace is reachable unauthenticated (the magic-link surface).
  Routing and isolation are correct under subdirectory mode.
- **Cookies / auth scope:** `COOKIEPATH`, `SITECOOKIEPATH`, and
  `ADMIN_COOKIE_PATH` are all `/` network-wide; the `wordpress_logged_in_*` cookie
  is set at `path=/`. **Consequence:** login is shared across the network — a
  logged-in leader is authenticated at the WP layer on *both* subsites. HUB
  isolation is therefore **capability-based, not session-based**: a participant
  with a valid session but no HUB role/capabilities (plus D.T's REST auth gate)
  sees nothing on HUB. Worth stating plainly in the security model.
- **Redirects:** D.T login-first works cleanly on `/hub/*`. The multisite plugin's
  `login-site-redirect.php` adds a useful UX: a user who logs in at the main site
  with no main-site membership but exactly one subsite is auto-forwarded there;
  multiple subsites show a site-picker. Good fit for "leader logs in → lands on
  HUB."

### 5. Caching split (sketch) — confirmed feasible

Observed headers support the intended split:

- **STUDY** anonymous GET: `200`, **no `Set-Cookie`**, no `no-cache` → **full-page
  cacheable** (+ CDN).
- **HUB** anonymous GET: `302` to login and **sets `wordpress_*` cookies** →
  **never cache**; object cache only.

Because `wordpress_logged_in_*` is scoped to `path=/`, a standard rule —
*bypass full-page cache when a `wordpress_logged_in_*` cookie is present* — serves
cached pages to anonymous participants and fresh pages to any logged-in user with
no D.T-specific config. Blanket-exclude `/hub/*` from full-page cache. (Exact VCL/
plugin rules are an ops task, not an architecture blocker.)

## Conclusion — PASS

The pass condition ("both subsites run cleanly on one network and the update flow
via network admin works") is **met**, demonstrated against a live network including
an actual downgrade→detect→apply theme update. Subdirectory mode introduced no
blocking conflicts; the one substantive design consequence is that network-wide
auth cookies make HUB isolation capability-based, which the architecture already
assumes.

## Consequences for architecture.md / follow-up issues

- **Confirmed** — architecture.md §1 (subdirectory network, D.T on HUB only) and §3
  (one account, per-subsite roles, participants have no HUB role) hold as written.
  No changes required.
- **Add to the security model (§3/§8):** state explicitly that a network-wide
  `wordpress_logged_in` cookie means HUB protection is capabilities + D.T's REST
  auth gate, not a separate login boundary. This reinforces the S5 (#12) privacy
  work — capability checks on every huddle read/write path are what actually keep
  participants out, at both the UI and REST layers.
- **Ops (issue #17):** the D.T update-checker only runs in network-admin/cron
  context; schedule real WP-cron on staging so updates surface, and always apply
  D.T's frequent releases on the staging network first. Full-page-cache rule:
  bypass on `wordpress_logged_in_*`, exclude `/hub/*`.
- **Unblocks** S3 (#10) and S4 (#11): the HUB D.T surface, groups, and Magic Links
  plugin are installed and reachable to build against.
