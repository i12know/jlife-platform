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
# Optional: TEST_CAMPAIGN (none|create|remove), DB_HOST (localhost), PHP_BIN (cPanel ea-php82), STATE_DIR (~/.latoc),
#   BUNDLE (the uploaded plugin tarball, $STATE_DIR/jlife-plugins.tgz)
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
PLUGINS=(jlife-studies jlife-huddles jlife-challenges jlife-auth) # jlife-bridge stays off at launch (#49 M2)

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
rm -rf "${stage}" "${BUNDLE}"

# The strict umask above protects ~/.latoc (backups, admin password). Web
# files must be world-readable or Apache cannot serve wp-admin/, CSS or JS
# ("Server unable to read htaccess file" 403s). Reset them on every run;
# wp-config.php stays owner-only (PHP runs as the account owner on cPanel).
log "Setting web file permissions"
find "${WP_PATH}" -type d ! -perm 755 -exec chmod 755 {} +
find "${WP_PATH}" -type f ! -name wp-config.php ! -perm 644 -exec chmod 644 {} +
chmod 600 "${WP_PATH}/wp-config.php"

wp plugin activate "${PLUGINS[@]}" >/dev/null
# Never active at launch, even if a copy is present from a manual upload.
wp plugin deactivate jlife-bridge >/dev/null 2>&1 || true
if wp plugin is-active jlife-bridge >/dev/null 2>&1; then
  die "jlife-bridge (magic links) is active; it must stay off until #37/#38 land."
fi

log "Hardening staging settings"
wp option update blog_public 0 >/dev/null # discourage search engines
wp rewrite structure '/%postname%/' >/dev/null

if ls "${STATE_DIR}"/reading-plans/*.json >/dev/null 2>&1; then
  log "Importing reading plans"
  wp jlife reading-plan import "${STATE_DIR}"/reading-plans/*.json
fi
wp rewrite flush >/dev/null

# Staging test drive (never printed: the invite goes by email and to an
# owner-only file in ${STATE_DIR}).
case "${TEST_CAMPAIGN:-none}" in
  create)
    log "Test campaign: create"
    wp jlife test-campaign create --save-to="${STATE_DIR}/test-invite.txt"
    ;;
  remove)
    log "Test campaign: remove"
    wp jlife test-campaign remove
    rm -f "${STATE_DIR}/test-invite.txt"
    ;;
  none) ;;
  *) die "Unknown TEST_CAMPAIGN: ${TEST_CAMPAIGN}" ;;
esac

log "Status"
printf 'WordPress %s; network installed: %s\n' "$(wp core version)" \
  "$(wp core is-installed --network >/dev/null 2>&1 && echo yes || echo no)"
wp plugin list --status=active --fields=name,version --format=csv
printf 'Latest backup: %s\n' "${stamp}.sql.gz"
