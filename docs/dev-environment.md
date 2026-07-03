# Local Development Environment

Status: Authored 2026-07-03 (issue #15). Awaiting first verification run on a Docker-equipped machine — see §7.
Related: [architecture.md](architecture.md) §8

One reproducible command set gives every contributor the same disposable environment: a **subdirectory WordPress multisite** with the **STUDY** subsite at `/` (participant surface) and the **HUB** subsite at `/hub/` running the **Disciple.Tools** theme, plus seeded test users and sample data.

Tooling: [`@wordpress/env` (wp-env)](https://developer.wordpress.org/block-editor/reference-guides/packages/packages-env/) on Docker. This is the repo standard; the same containers back CI (#16).

## 1. Prerequisites

| Requirement | Notes |
|---|---|
| **Docker Desktop** | With the WSL2 backend on Windows. Admin rights + a reboot are typically needed once. |
| **Node.js ≥ 20** | Drives wp-env and the setup/seed/verify scripts. |
| **Git** | You already have it if you cloned this repo. |

No local PHP, Composer, MySQL, or WP-CLI needed — everything runs in containers (WP-CLI is available via `npm run env:cli`).

**Windows shells:** native PowerShell is a fully supported happy path — all orchestration is Node (no bash scripts), so nothing here requires a Unix shell. If you prefer WSL2 as your working shell, that works too: enable Docker Desktop's WSL2 integration for your distro, clone the repo inside the WSL2 filesystem (e.g. `~/src/jlife-platform`) for much faster file I/O, and run the same `npm` commands there.

## 2. Windows 10 + WSL2: disk-space planning (read before installing Docker)

Docker Desktop puts everything on `C:` by default. If `C:` is tight, plan locations **before** the first `docker` run — moving later is possible but slower.

Expected footprint:

| Item | Default location | Size |
|---|---|---|
| Docker Desktop app | `C:\Program Files\Docker` | ~2 GB (not movable) |
| WSL2 kernel + system | `C:` | ~1 GB |
| **`docker_data.vhdx`** (all images, containers, volumes — the big one) | `C:\Users\<you>\AppData\Local\Docker\wsl` | starts ~1 GB, grows to **4–8 GB** with this project (WordPress + MariaDB images, DB volumes); grows monotonically until compacted |
| wp-env cache (WordPress source downloads) | `C:\Users\<you>\.wp-env` | ~0.5–1 GB |
| This repo + `node_modules` | wherever you cloned it (keep on `S:`) | ~0.3 GB |

Plan for **≥ 12 GB free** wherever the Docker disk image lives.

### Moving the big items off C:

1. **Docker's disk image → `S:`** (do this first, ideally right after installing Docker Desktop):
   Docker Desktop → Settings → Resources → Advanced → **Disk image location** → e.g. `S:\Docker\wsl` → Apply & Restart. Docker moves the vhdx for you.
2. **wp-env cache → `S:`**: set the `WP_ENV_HOME` environment variable before first use:
   ```powershell
   [Environment]::SetEnvironmentVariable('WP_ENV_HOME', 'S:\wp-env-home', 'User')
   ```
   (restart the terminal afterward).
3. **Repo on `S:`** — already the case (`S:\MyPrj\jlife\platform`).

### Reclaiming space later

The vhdx does not shrink on its own. Periodically:

```powershell
docker system prune          # remove unused images/containers (asks first)
wsl --shutdown
Optimize-VHD -Path 'S:\Docker\wsl\disk\docker_data.vhdx' -Mode Full   # admin PowerShell; compacts the file
```

(If `Optimize-VHD` is unavailable on Windows 10 Pro without Hyper-V tools, use Docker Desktop → Troubleshoot → Clean/Purge data as the blunt alternative.)

## 3. Quickstart

```bash
npm install        # once per clone
npm run env:up     # start containers + convert to multisite + install D.T + seed + verify
```

`env:up` is the "one documented command"; it chains four steps you can also run individually:

| Command | What it does |
|---|---|
| `npm run env:start` | Start (or create) the containers |
| `npm run env:setup` | Idempotent: multisite conversion, D.T theme + plugins (pinned versions), HUB subsite, `vi` locale on HUB, permalinks |
| `npm run env:seed` | Idempotent: test users per role, sample huddle group + contacts |
| `npm run env:verify` | Read-only PASS/FAIL audit of the whole setup (also intended for CI) |

### URLs and accounts (dev only — all passwords are `password`)

| What | URL | Account |
|---|---|---|
| STUDY (participant surface) | <http://localhost:8888/> | `participant1`, `participant2` (subscriber), `editor1` (editor) |
| HUB (Disciple.Tools, Vietnamese) | <http://localhost:8888/hub/wp-admin/> | `leader1`, `coach1` (multiplier) |
| Network admin | <http://localhost:8888/wp-admin/network/> | `admin` (super admin) |

Role placeholders: participants are `subscriber` on STUDY until the `jlife-*` plugins define real roles (`jlife_participant`, `jlife_leader`) — see [architecture.md](architecture.md) §3. Participants intentionally have **no role on HUB**; `env:verify` enforces this.

### Daily commands

```bash
npm run env:stop            # stop containers, keep data
npm run env:start           # resume
npm run env:destroy         # delete containers + data (setup/seed rebuild everything)
npm run env:cli -- user list             # arbitrary WP-CLI, e.g. against STUDY
npm run env:cli -- user list --url=http://localhost:8888/hub   # ...against HUB
```

Port busy? Set `WP_ENV_PORT=8890` (env var) before `env:start`; the scripts pick it up.

## 4. What the setup pins

Artifact versions are pinned in [`bin/setup.js`](../bin/setup.js): Disciple.Tools theme **1.82.2**, disciple-tools-multisite **1.17.0**, Magic Links (bulk-magic-link-sender) **1.33.0**, demo-content **0.6.7**. Bump deliberately and note behavior changes in `docs/spikes/`.

Multisite conversion happens inside the container's volume (wp-env manages `wp-config.php`), so after `env:destroy` simply re-run `env:setup` — that's by design; the environment is disposable.

Richer sample data: the official **Demo Content** plugin is activated on HUB — generate bulk contacts/groups from HUB wp-admin when a spike needs volume.

## 5. Locale notes

- HUB runs `vi` (Vietnamese) to keep the Disciple.Tools translation constantly in view (spike S2 material).
- STUDY stays `en` until the content theme is chosen. To flip: `npm run env:cli -- option update WPLANG vi` (add `--url=...` for a specific subsite); to revert HUB to English for debugging: `npm run env:cli -- option update WPLANG "" --url=http://localhost:8888/hub`.

## 6. Known gaps / future issues

- **`jlife-*` plugin mappings**: when #14 creates `/plugins/jlife-{studies,huddles,bridge}`, add them to `.wp-env.json` `mappings` so they mount into both subsites.
- **Magic Links flows** are installed but unconfigured — that's spike S4 (#11).
- The `tests` environment wp-env creates on port 8889 is untouched for now; CI (#16) will use it.

## 7. Verifying on another (Docker-equipped) machine

This config was authored on a machine without Docker, so the first full run doubles as its acceptance test:

1. Clone the repo, `npm install`, `npm run env:up`.
2. `env:verify` must end with `Environment verified.` (exit code 0).
3. Manually: log into HUB as `leader1` — the D.T UI should be in Vietnamese; log into STUDY as `participant1` — no HUB access.
4. Report any FAIL lines on issue #15; fixes land in `bin/` scripts, not in manual steps.

## 8. Troubleshooting

| Symptom | Fix |
|---|---|
| `wp-env start` hangs on first run | It's downloading images (~1.5 GB). Check Docker Desktop's progress; be patient once. |
| `EACCES`/`ENOENT` launching scripts | Run `npm install` first; scripts call `node_modules/@wordpress/env` directly. |
| Port 8888 in use | `WP_ENV_PORT=8890 npm run env:up` (PowerShell: `$env:WP_ENV_PORT=8890; npm run env:up`). |
| `/hub/` returns 404s for inner pages | Permalinks/.htaccess: re-run `npm run env:setup`; confirm `.htaccess` mapping in `.wp-env.json`. |
| Docker vhdx ate the disk | §2 "Reclaiming space". |
| Everything is weird | `npm run env:destroy && npm run env:up` — the environment is disposable by design. |
