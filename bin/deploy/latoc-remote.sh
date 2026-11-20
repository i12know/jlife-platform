#!/usr/bin/env bash
# Runs ON the latoc staging server (cPanel shared hosting), uploaded and
# invoked by .github/workflows/deploy-latoc.yml. Idempotent: safe to re-run.
#
# Option C (#49): WordPress multisite with the STUDY site only, no
# Disciple.Tools, no jlife-bridge. Search engines are discouraged.
#
# Inputs (environment, from a 0600 env file the workflow uploads and this
# script deletes):
#   WP_PATH, SITE_URL, WP_VERSION, DB_NAME, DB_USER, DB_PASSWORD
# Optional: TEST_CAMPAIGN (none|create|preview-remove|remove-keep-accounts|remove), DB_HOST (localhost), PHP_BIN (cPanel ea-php82), STATE_DIR (~/.latoc),
#   BUNDLE (the uploaded plugin tarball, $STATE_DIR/jlife-plugins.tgz)
#   POLYLANG_VERSION (the pinned Polylang release, default below)
#
# Never prints secrets. The first-install admin password is written to
# $STATE_DIR/admin-password (0600) for the owner to read via File Manager.
set -euo pipefail
umask 077

STATE_DIR="${STATE_DIR:-${HOME}/.latoc}"
ENV_FILE="${STATE_DIR}/deploy.env"
if [ -f "${ENV_FILE}" ]; then
  # shellcheck disable=SC1090
  . "${ENV_FILE}"
  rm -f "${ENV_FILE}"
fi

: "${WP_PATH:?}" "${SITE_URL:?}" "${WP_VERSION:?}" "${DB_NAME:?}" "${DB_USER:?}" "${DB_PASSWORD:?}"
BUNDLE="${BUNDLE:-${STATE_DIR}/jlife-plugins.tgz}"
DB_HOST="${DB_HOST:-localhost}"
PHP_BIN="${PHP_BIN:-/opt/cpanel/ea-php82/root/usr/bin/php}"
WP_CLI_VERSION="2.11.0"
WP_CLI="${STATE_DIR}/wp-cli-${WP_CLI_VERSION}.phar"
PLUGINS=(jlife-studies jlife-huddles jlife-challenges jlife-auth jlife-scripture) # jlife-bridge stays off at launch (#49 M2)
# Public bilingual landing page (#110). Pinned: bumping it is a reviewed PR that
# re-runs the route checks in docs/spikes/2026-11-18-polylang-latoc-landing.md.
POLYLANG_VERSION="${POLYLANG_VERSION:-3.9.2}"
LANDING_DIR="${STATE_DIR}/landing"
LANDING_STATE="${STATE_DIR}/landing-state"

log() { printf '\n==> %s\n' "$*"; }
die() { printf 'ERROR: %s\n' "$*" >&2; exit 1; }
wp() { "${PHP_BIN}" -d memory_limit=512M "${WP_CLI}" --path="${WP_PATH}" --url="${SITE_URL}" "$@"; }

mkdir -p "${STATE_DIR}/backups"
[ -x "${PHP_BIN}" ] || die "PHP binary not found: ${PHP_BIN}"
[ -d "${WP_PATH}" ] || die "Document root not found: ${WP_PATH}"
log "PHP: $("${PHP_BIN}" -r 'echo PHP_VERSION;')"

if [ ! -f "${WP_CLI}" ]; then
  log "Downloading WP-CLI ${WP_CLI_VERSION} (checksum-verified)"
  base="https://github.com/wp-cli/wp-cli/releases/download/v${WP_CLI_VERSION}/wp-cli-${WP_CLI_VERSION}.phar"
  curl -fsSL -o "${WP_CLI}.tmp" "${base}"
  want="$(curl -fsSL "${base}.sha512" | awk '{print $1}')"
  got="$(sha512sum "${WP_CLI}.tmp" | awk '{print $1}')"
  [ -n "${want}" ] && [ "${want}" = "${got}" ] || { rm -f "${WP_CLI}.tmp"; die "WP-CLI checksum mismatch."; }
  mv "${WP_CLI}.tmp" "${WP_CLI}"
fi

if [ ! -f "${WP_PATH}/wp-includes/version.php" ]; then
  log "Downloading WordPress ${WP_VERSION}"
  wp core download --version="${WP_VERSION}"
fi

if [ ! -f "${WP_PATH}/wp-config.php" ]; then
  log "Writing wp-config.php"
  wp config create --dbname="${DB_NAME}" --dbuser="${DB_USER}" --dbhost="${DB_HOST}" \
    --prompt=dbpass <<<"${DB_PASSWORD}" >/dev/null
  wp config set WP_DEBUG false --raw --type=constant
  wp config set WP_DEBUG_DISPLAY false --raw --type=constant
  # Log outside the public document root.
  wp config set WP_DEBUG_LOG "${STATE_DIR}/debug.log" --type=constant
  wp config set DISALLOW_FILE_EDIT true --raw --type=constant
  wp config set WP_ENVIRONMENT_TYPE staging --type=constant
fi

# Mail credentials stay in the protected state directory, outside the web root.
# Only the include path/expression are versioned/provisioned here, never the key.
# These definitions run before wp-settings.php in both web PHP and WP-CLI.
log "Configuring private mail settings include"
wp config set JLIFE_MAIL_CONFIG_FILE "${STATE_DIR}/mail.php" --type=constant >/dev/null
wp config set JLIFE_MAIL_CONFIG_LOADED '( is_readable( JLIFE_MAIL_CONFIG_FILE ) ? (bool) ( require JLIFE_MAIL_CONFIG_FILE ) : false )' --raw --type=constant >/dev/null
if [ -f "${STATE_DIR}/mail.php" ]; then
  chmod 600 "${STATE_DIR}/mail.php"
fi

# API.Bible key (#141): same pattern, a separate private file the owner creates.
log "Configuring private Bible settings include"
wp config set JLIFE_BIBLE_CONFIG_FILE "${STATE_DIR}/bible.php" --type=constant >/dev/null
wp config set JLIFE_BIBLE_CONFIG_LOADED '( is_readable( JLIFE_BIBLE_CONFIG_FILE ) ? (bool) ( require JLIFE_BIBLE_CONFIG_FILE ) : false )' --raw --type=constant >/dev/null
if [ -f "${STATE_DIR}/bible.php" ]; then
  chmod 600 "${STATE_DIR}/bible.php"
fi

if ! wp core is-installed --network >/dev/null 2>&1; then
  if wp core is-installed >/dev/null 2>&1; then
    die "A single-site WordPress already exists here; refusing to convert it automatically."
  fi
  log "Installing WordPress multisite (subdirectory, STUDY site only)"
  admin_pass="$("${PHP_BIN}" -r 'echo bin2hex(random_bytes(16));')"
  wp core multisite-install --title="J-Life (staging)" --admin_user=latoc-admin \
    --admin_password="${admin_pass}" --admin_email="admin@$(printf '%s' "${SITE_URL}" | sed -E 's#^https?://##; s#/.*$##')" \
    --skip-email >/dev/null
  printf '%s\n' "${admin_pass}" >"${STATE_DIR}/admin-password"
  unset admin_pass
  log "Admin user 'latoc-admin' created; password saved to ${STATE_DIR}/admin-password"
fi

# .htaccess: add the multisite rewrites, keeping the cPanel PHP handler block.
HTACCESS="${WP_PATH}/.htaccess"
touch "${HTACCESS}"
if ! grep -q '# BEGIN J-Life multisite' "${HTACCESS}"; then
  log "Adding multisite rewrite rules to .htaccess"
  {
    cat <<'RULES'
# BEGIN J-Life multisite
RewriteEngine On
RewriteRule .* - [E=HTTP_AUTHORIZATION:%{HTTP:Authorization}]
RewriteBase /
RewriteRule ^index\.php$ - [L]
RewriteRule ^([_0-9a-zA-Z-]+/)?wp-admin$ $1wp-admin/ [R=301,L]
RewriteCond %{REQUEST_FILENAME} -f [OR]
RewriteCond %{REQUEST_FILENAME} -d
RewriteRule ^ - [L]
RewriteRule ^([_0-9a-zA-Z-]+/)?(wp-(content|admin|includes).*) $2 [L]
RewriteRule ^([_0-9a-zA-Z-]+/)?(.*\.php)$ $2 [L]
RewriteRule . index.php [L]
# END J-Life multisite

RULES
    cat "${HTACCESS}"
  } >"${HTACCESS}.new"
  mv "${HTACCESS}.new" "${HTACCESS}"
fi
chmod 644 "${HTACCESS}"

log "Backing up the database"
stamp="$(date -u +%Y%m%dT%H%M%SZ)"
backup="${STATE_DIR}/backups/${stamp}.sql.gz"
# Write to a temp name and keep it only if the dump succeeded (pipefail).
if ! wp db export - 2>/dev/null | gzip >"${backup}.part"; then
  rm -f "${backup}.part"
  die "Database backup failed; nothing was changed. Fix the backup before deploying."
fi
mv "${backup}.part" "${backup}"
# Keep the 10 most recent backups.
find "${STATE_DIR}/backups" -name '*.sql.gz' -printf '%T@ %p\n' | sort -rn | tail -n +11 | cut -d' ' -f2- | xargs -r rm -f

# WordPress core follows the version pinned in .wp-env.json (the one CI tests),
# after the backup above. Upgrade only: a server already on the pin or newer is
# left alone, never downgraded.
current_wp="$(wp core version)"
if [ "${current_wp}" != "${WP_VERSION}" ] \
  && [ "$(printf '%s\n%s\n' "${current_wp}" "${WP_VERSION}" | sort -V | head -n1)" = "${current_wp}" ]; then
  log "Updating WordPress ${current_wp} -> ${WP_VERSION} (pinned)"
  wp core update --version="${WP_VERSION}"
  wp core update-db --network
  wp language core update || log "Language pack update failed; WordPress still works in English."
else
  log "WordPress ${current_wp} (pin ${WP_VERSION}; no core update)"
fi
wp core verify-checksums || die "WordPress core checksum verification failed."

log "Installing J-Life plugins: ${PLUGINS[*]}"
stage="$(mktemp -d "${STATE_DIR}/stage.XXXXXX")"
tar -xzf "${BUNDLE}" -C "${stage}"
for plugin in "${PLUGINS[@]}"; do
  [ -d "${stage}/${plugin}" ] || die "Bundle is missing ${plugin}"
  dest="${WP_PATH}/wp-content/plugins/${plugin}"
  rm -rf "${dest}.old"
  [ -d "${dest}" ] && mv "${dest}" "${dest}.old"
  mv "${stage}/${plugin}" "${dest}"
  rm -rf "${dest}.old"
done
# Reading-plan JSON (#59) travels in the same bundle; keep the latest copy.
if [ -d "${stage}/reading-plans" ]; then
  rm -rf "${STATE_DIR}/reading-plans"
  mv "${stage}/reading-plans" "${STATE_DIR}/reading-plans"
fi
# Landing-page seed (#110): the configuration script and its copy, same bundle.
for f in latoc-landing.php latoc-landing-content.php; do
  [ -f "${stage}/${f}" ] || die "Bundle is missing ${f}"
done
rm -rf "${LANDING_DIR}"
mkdir -p "${LANDING_DIR}"
mv "${stage}/latoc-landing.php" "${stage}/latoc-landing-content.php" "${LANDING_DIR}/"
rm -rf "${stage}" "${BUNDLE}"

# The strict umask above protects ~/.latoc (backups, admin password). Web
# files must be world-readable or Apache cannot serve wp-admin/, CSS or JS
# ("Server unable to read htaccess file" 403s). Reset them on every run, and
# again after anything that writes into the document root later in the run
# (Polylang, language packs); wp-config.php stays owner-only (PHP runs as the
# account owner on cPanel).
fix_web_permissions() {
  find "${WP_PATH}" -type d ! -perm 755 -exec chmod 755 {} +
  find "${WP_PATH}" -type f ! -name wp-config.php ! -perm 644 -exec chmod 644 {} +
  chmod 600 "${WP_PATH}/wp-config.php"
}
log "Setting web file permissions"
fix_web_permissions

wp plugin activate "${PLUGINS[@]}" >/dev/null
# Never active at launch, even if a copy is present from a manual upload.
wp plugin deactivate jlife-bridge >/dev/null 2>&1 || true
if wp plugin is-active jlife-bridge >/dev/null 2>&1; then
  die "jlife-bridge (magic links) is active; it must stay off until #37/#38 land."
fi

# ---- Public bilingual landing page (#110): Polylang + one-time draft seed ----
# Conditions are in docs/spikes/2026-11-18-polylang-latoc-landing.md. In short:
# per-site activation (never --network), pinned version, vi language packs,
# no subsite named like a language, and the pages are created once, as drafts.
log "Polylang ${POLYLANG_VERSION} (pinned)"
if wp plugin is-active polylang --network >/dev/null 2>&1; then
  die "Polylang is network-activated; it must be activated per site only (spike 2026-11-18 s1)."
fi
# A subsite at /vi/ or /en/ would shadow the language (spike s2).
site_paths="$(wp site list --field=path 2>/dev/null || true)"
if grep -Eq '^/(vi|en)/$' <<<"${site_paths}"; then
  die "A subsite uses a language slug (vi or en); it would shadow the Polylang language URL."
fi
current="$(wp plugin get polylang --field=version 2>/dev/null || true)"
if [ "${current}" != "${POLYLANG_VERSION}" ]; then
  wp plugin install polylang --version="${POLYLANG_VERSION}" --force
fi
wp plugin verify-checksums polylang || die "Polylang checksum verification failed."
wp plugin activate polylang # per site: never --network
# Language packs so the Vietnamese page's chrome (switcher label, 404s, feeds)
# is Vietnamese. Failing to fetch them (translate.wordpress.org down) must not
# block a deploy: warn and carry on.
theme="$(wp option get stylesheet)"
wp language core is-installed vi >/dev/null 2>&1 || wp language core install vi \
  || printf 'WARNING: Vietnamese core language pack not installed.\n' >&2
wp language plugin is-installed polylang vi >/dev/null 2>&1 || wp language plugin install polylang vi \
  || printf 'WARNING: Vietnamese Polylang language pack not installed.\n' >&2
wp language theme is-installed "${theme}" vi >/dev/null 2>&1 || wp language theme install "${theme}" vi \
  || printf 'WARNING: Vietnamese language pack for theme %s not installed.\n' "${theme}" >&2
fix_web_permissions
rm -f "${LANDING_STATE}"
LATOC_LANDING_STATE="${LANDING_STATE}" wp eval-file "${LANDING_DIR}/latoc-landing.php"
# Polylang caches its language list (with each language's front page); rebuild
# it in a fresh process, or /vi/ can keep redirecting to the VI page's slug.
wp transient delete pll_languages_list >/dev/null 2>&1 || true
wp rewrite flush >/dev/null
# Help, Rules and Privacy pages (#65): created once, as drafts, in EN and VI, linked
# through Polylang; never updated, so the owner's edits in wp-admin are safe.
log "Help, Rules and Privacy pages (seeded once, as drafts)"
wp jlife help-pages seed
wp jlife help-pages status

log "Hardening staging settings"
wp option update blog_public 0 >/dev/null # discourage search engines
wp rewrite structure '/%postname%/' >/dev/null

if ls "${STATE_DIR}"/reading-plans/*.json >/dev/null 2>&1; then
  log "Importing reading plans"
  wp jlife reading-plan import "${STATE_DIR}"/reading-plans/*.json
fi
# Public-domain Kinh Thánh 1925 text for the on-page reader (#139), shipped in
# the plugin and checksum-verified; a no-op when unchanged. The reader itself
# stays off until the jlife_scripture_native_reader option is turned on.
log "Kinh Thánh 1925 dataset"
wp jlife scripture import vie1934
wp rewrite flush >/dev/null

# Staging test drive (never printed: the invite goes by email and to an
# owner-only file in ${STATE_DIR}).
case "${TEST_CAMPAIGN:-none}" in
  create)
    log "Test campaign: create"
    wp jlife test-campaign create --save-to="${STATE_DIR}/test-invite.txt"
    ;;
  preview-remove)
    log "Test campaign: preview removal (nothing is changed)"
    wp jlife test-campaign remove --dry-run
    ;;
  remove-keep-accounts)
    log "Test campaign: remove, keeping every account"
    wp jlife test-campaign remove --keep-accounts
    rm -f "${STATE_DIR}/test-invite.txt"
    ;;
  remove)
    log "Test campaign: remove, deleting test-only accounts"
    wp jlife test-campaign remove
    rm -f "${STATE_DIR}/test-invite.txt"
    ;;
  none) ;;
  *) die "Unknown TEST_CAMPAIGN: ${TEST_CAMPAIGN}" ;;
esac

log "Status"
wp jlife mail status
printf 'WordPress %s; network installed: %s\n' "$(wp core version)" \
  "$(wp core is-installed --network >/dev/null 2>&1 && echo yes || echo no)"
wp plugin list --status=active --fields=name,version --format=csv
printf 'Latest backup: %s\n' "${stamp}.sql.gz"
