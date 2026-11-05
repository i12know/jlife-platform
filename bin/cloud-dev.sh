#!/usr/bin/env bash
# Cloud-session dev runtime for the J-Life multisite.
#
# Claude Code cloud sessions (and similar sandboxes) can pull Docker images
# but cannot finish wp-env's Docker *builds*: packages fetched inside the
# build do not trust the sandbox's TLS-intercepting proxy. This script builds
# the same network without wp-env:
#
#   MariaDB in Docker (image pull only)  +  host PHP built-in server
#   +  pinned WP-CLI  +  the existing bin/setup.js, seed.js, verify.js
#
# The Node scripts run unchanged; JLIFE_DEV_RUNTIME=cloud makes bin/lib.js
# call WP-CLI directly instead of `wp-env run cli`.
#
# Local machines with Docker Desktop should keep using `npm run env:up`.
# CI keeps using wp-env on PHP 8.2 and remains the authoritative test run.
#
# Usage: bin/cloud-dev.sh up | test | wp <args...> | status | down | destroy
set -euo pipefail

REPO_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
STATE_DIR="${REPO_ROOT}/.cloud-dev"
WP_DIR="${STATE_DIR}/wordpress"
TESTS_DIR="${STATE_DIR}/wordpress-phpunit"
WP_CLI="${STATE_DIR}/wp-cli.phar"
PORT="${WP_ENV_PORT:-8888}"
DB_CONTAINER="jlife-cloud-db"
DB_PASS="password" # dev only, matches wp-env's defaults
WP_CLI_VERSION="2.11.0"
DOCKER_MIRROR="${JLIFE_DOCKER_MIRROR:-https://mirror.gcr.io}"

# WordPress version pinned in .wp-env.json ("WordPress/WordPress#7.0").
WP_VERSION="$(node -e 'const c=require(process.argv[1]);const m=String(c.core||"").match(/#([^/#]+)$/);process.stdout.write(m?m[1]:"")' "${REPO_ROOT}/.wp-env.json")"

log() { printf '\n==> %s\n' "$*"; }
die() { printf 'ERROR: %s\n' "$*" >&2; exit 1; }

wp_cli() { php -d memory_limit=512M "${WP_CLI}" --path="${WP_DIR}" --allow-root "$@"; }

ensure_docker() {
  if docker info >/dev/null 2>&1; then return; fi
  command -v dockerd >/dev/null || die "Docker is not running and dockerd is not installed."
  [ "$(id -u)" -eq 0 ] || die "Docker is not running; start it (root needed) and retry."
  # Docker Hub rate-limits shared sandbox IPs; pull through a mirror unless
  # the daemon config already names one (a flag would then conflict).
  local mirror_flag=()
  if ! grep -qs '"registry-mirrors"' /etc/docker/daemon.json; then
    mirror_flag=(--registry-mirror="${DOCKER_MIRROR}")
  fi
  log "Starting dockerd"
  nohup dockerd "${mirror_flag[@]}" >"${STATE_DIR}/dockerd.log" 2>&1 &
  for _ in $(seq 1 30); do docker info >/dev/null 2>&1 && return; sleep 1; done
  die "dockerd did not start; see ${STATE_DIR}/dockerd.log"
}

ensure_db() {
  if [ -z "$(docker ps -q -f name="^${DB_CONTAINER}$")" ]; then
    if [ -n "$(docker ps -aq -f name="^${DB_CONTAINER}$")" ]; then
      docker start "${DB_CONTAINER}" >/dev/null
    else
      log "Starting MariaDB container"
      docker run -d --name "${DB_CONTAINER}" -p 127.0.0.1:3306:3306 \
        -e MARIADB_ROOT_PASSWORD="${DB_PASS}" mariadb:lts >/dev/null
    fi
  fi
  for _ in $(seq 1 60); do
    if docker exec "${DB_CONTAINER}" mariadb -uroot -p"${DB_PASS}" -e 'SELECT 1' >/dev/null 2>&1; then
      docker exec "${DB_CONTAINER}" mariadb -uroot -p"${DB_PASS}" \
        -e 'CREATE DATABASE IF NOT EXISTS wordpress; CREATE DATABASE IF NOT EXISTS wordpress_test;'
      return
    fi
    sleep 1
  done
  die "MariaDB did not become ready."
}

ensure_wp_cli() {
  [ -f "${WP_CLI}" ] && return
  log "Downloading WP-CLI ${WP_CLI_VERSION} (checksum-verified)"
  local base="https://github.com/wp-cli/wp-cli/releases/download/v${WP_CLI_VERSION}/wp-cli-${WP_CLI_VERSION}.phar"
  curl -fsSL -o "${WP_CLI}.tmp" "${base}"
  local want got
  want="$(curl -fsSL "${base}.sha512" | awk '{print $1}')"
  got="$(sha512sum "${WP_CLI}.tmp" | awk '{print $1}')"
  [ -n "${want}" ] && [ "${want}" = "${got}" ] || { rm -f "${WP_CLI}.tmp"; die "WP-CLI checksum mismatch."; }
  mv "${WP_CLI}.tmp" "${WP_CLI}"
}

ensure_wordpress() {
  if [ ! -f "${WP_DIR}/wp-includes/version.php" ]; then
    log "Downloading WordPress ${WP_VERSION}"
    mkdir -p "${WP_DIR}"
    wp_cli core download --version="${WP_VERSION}" --force
    mkdir -p "${WP_DIR}/wp-content/plugins" "${WP_DIR}/wp-content/themes" "${WP_DIR}/wp-content/uploads"
  fi
  if [ ! -f "${WP_DIR}/wp-config.php" ]; then
    log "Writing wp-config.php"
    wp_cli config create --dbname=wordpress --dbuser=root --dbpass="${DB_PASS}" \
      --dbhost=127.0.0.1:3306 --skip-check
    # Same constants as .wp-env.json "config", except WP_ALLOW_MULTISITE,
    # which `wp core multisite-convert` (bin/setup.js) writes itself.
    node -e 'const c=require(process.argv[1]).config||{};for(const[k,v]of Object.entries(c))if(k!=="WP_ALLOW_MULTISITE")console.log(k+"\t"+JSON.stringify(v))' \
      "${REPO_ROOT}/.wp-env.json" | while IFS=$'\t' read -r key value; do
        wp_cli config set "${key}" "${value}" --raw --type=constant
      done
  fi
  # Install here rather than in bin/setup.js: outside a container WordPress
  # guesses its URL from the install path (/wordpress), so pin it explicitly
  # before setup.js converts the install to a multisite network.
  if ! wp_cli core is-installed >/dev/null 2>&1; then
    log "Installing WordPress"
    wp_cli core install --url="http://localhost:${PORT}" --title="J-Life Dev Network" \
      --admin_user=admin --admin_password="${DB_PASS}" --admin_email=admin@example.test --skip-email
    wp_cli option update siteurl "http://localhost:${PORT}"
    wp_cli option update home "http://localhost:${PORT}"
  fi
  # Map the repo plugins in, like .wp-env.json "mappings".
  for plugin in "${REPO_ROOT}"/plugins/jlife-*; do
    ln -sfn "${plugin}" "${WP_DIR}/wp-content/plugins/$(basename "${plugin}")"
  done
}

ensure_server() {
  if curl -fsS -o /dev/null "http://localhost:${PORT}/wp-includes/js/wp-embed.min.js" 2>/dev/null; then return; fi
  log "Starting PHP built-in server on http://localhost:${PORT}"
  nohup php -S "0.0.0.0:${PORT}" -t "${WP_DIR}" "${REPO_ROOT}/bin/cloud-dev-router.php" \
    >"${STATE_DIR}/php-server.log" 2>&1 &
  echo $! >"${STATE_DIR}/php-server.pid"
  for _ in $(seq 1 20); do
    curl -fsS -o /dev/null "http://localhost:${PORT}/wp-includes/js/wp-embed.min.js" 2>/dev/null && return
    sleep 0.5
  done
  die "PHP server did not start; see ${STATE_DIR}/php-server.log"
}

run_node_scripts() {
  for script in setup seed verify; do
    JLIFE_DEV_RUNTIME=cloud JLIFE_CLOUD_WP_CLI="${WP_CLI}" JLIFE_CLOUD_WP_PATH="${WP_DIR}" \
      WP_ENV_PORT="${PORT}" node "${REPO_ROOT}/bin/${script}.js"
  done
}

ensure_phpunit_suite() {
  local marker="${TESTS_DIR}/tests/phpunit/includes/functions.php"
  if [ ! -f "${marker}" ]; then
    local tag="${WP_VERSION}"
    [[ "${tag}" =~ ^[0-9]+\.[0-9]+$ ]] && tag="${tag}.0"
    log "Downloading the WordPress PHPUnit suite (${tag})"
    rm -rf "${TESTS_DIR}"
    git clone -q --depth 1 --no-checkout https://github.com/WordPress/wordpress-develop.git "${TESTS_DIR}"
    git -C "${TESTS_DIR}" sparse-checkout set --cone tests/phpunit
    git -C "${TESTS_DIR}" fetch -q origin tag "${tag}" --depth 1
    git -C "${TESTS_DIR}" checkout -q "tags/${tag}"
  fi
  cat >"${TESTS_DIR}/tests/phpunit/wp-tests-config.php" <<PHP
<?php
// Generated by bin/cloud-dev.sh — cloud dev only.
define( 'ABSPATH', '${WP_DIR}/' );
define( 'WP_DEFAULT_THEME', 'default' );
define( 'WP_DEBUG', true );
define( 'DB_NAME', 'wordpress_test' );
define( 'DB_USER', 'root' );
define( 'DB_PASSWORD', '${DB_PASS}' );
define( 'DB_HOST', '127.0.0.1:3306' );
define( 'DB_CHARSET', 'utf8' );
define( 'DB_COLLATE', '' );
\$table_prefix = 'wptests_';
define( 'WP_TESTS_DOMAIN', 'localhost' );
define( 'WP_TESTS_EMAIL', 'admin@example.org' );
define( 'WP_TESTS_TITLE', 'Test Blog' );
define( 'WP_PHP_BINARY', 'php' );
PHP
}

run_tests() {
  ensure_phpunit_suite
  for plugin in "${REPO_ROOT}"/plugins/jlife-*; do
    [ -f "${plugin}/phpunit.xml.dist" ] || [ -f "${plugin}/phpunit.xml" ] || continue
    log "PHPUnit: $(basename "${plugin}")"
    if [ ! -x "${plugin}/vendor/bin/phpunit" ]; then
      (cd "${plugin}" && composer install --no-interaction --no-progress --quiet)
    fi
    (cd "${plugin}" && WP_TESTS_DIR="${TESTS_DIR}/tests/phpunit" vendor/bin/phpunit)
  done
}

stop_server() {
  if [ -f "${STATE_DIR}/php-server.pid" ]; then
    kill "$(cat "${STATE_DIR}/php-server.pid")" 2>/dev/null || true
    rm -f "${STATE_DIR}/php-server.pid"
  fi
}

mkdir -p "${STATE_DIR}"
cmd="${1:-}"
shift || true
case "${cmd}" in
  up)
    command -v php >/dev/null || die "php is required."
    [ -n "${WP_VERSION}" ] || die "Could not read the pinned WordPress version from .wp-env.json."
    ensure_docker; ensure_db; ensure_wp_cli; ensure_wordpress; ensure_server; run_node_scripts
    printf '\nCloud dev network ready: http://localhost:%s/  (admin / password, dev only)\n' "${PORT}"
    ;;
  test)
    ensure_docker; ensure_db; ensure_wp_cli; ensure_wordpress; run_tests
    ;;
  wp)
    wp_cli "$@"
    ;;
  status)
    docker ps -f name="^${DB_CONTAINER}$" --format '{{.Names}}: {{.Status}}' 2>/dev/null || true
    curl -fsS -o /dev/null -w "PHP server: HTTP %{http_code}\n" "http://localhost:${PORT}/" 2>/dev/null || echo "PHP server: not running"
    ;;
  down)
    stop_server
    docker stop "${DB_CONTAINER}" >/dev/null 2>&1 || true
    echo "Stopped (data kept in the ${DB_CONTAINER} container and ${STATE_DIR})."
    ;;
  destroy)
    stop_server
    docker rm -f "${DB_CONTAINER}" >/dev/null 2>&1 || true
    rm -rf "${WP_DIR}" "${TESTS_DIR}"
    echo "Destroyed the cloud dev network (WP-CLI download kept)."
    ;;
  *)
    echo "Usage: bin/cloud-dev.sh up | test | wp <args...> | status | down | destroy" >&2
    exit 2
    ;;
esac
