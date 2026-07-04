/**
 * Ensure the WordPress PHPUnit test library is usable in the wp-env instance.
 *
 * wp-env only downloads the PHPUnit suite and generates wp-tests-config.php
 * during its "configure" phase — a fresh `wp-env start`. On an existing
 * instance where that phase was skipped or interrupted, /wordpress-phpunit is
 * empty and `vendor/bin/phpunit` dies with
 * "Could not find /wordpress-phpunit/includes/functions.php".
 *
 * This script makes the state deterministic (idempotent, safe to always run
 * before phpunit): for both environments it downloads the suite matching the
 * installed WordPress version if missing (same sparse-checkout approach as
 * wp-env's download-wp-phpunit.js) and generates wp-tests-config.php from the
 * environment's wp-config.php if missing (same transform as wp-env's
 * wordpress.js).
 *
 * Usage: node bin/ensure-wp-tests.js   (after `wp-env start`)
 * Override the instance dir with JLIFE_WP_ENV_INSTALL_PATH (testing only).
 */
'use strict';

const { execFileSync } = require('child_process');
const fs = require('fs');
const path = require('path');

const getCacheDirectory = require('../node_modules/@wordpress/env/lib/config/get-cache-directory');
const md5 = require('../node_modules/@wordpress/env/lib/md5');
const { getConfigFilePath } = require('../node_modules/@wordpress/env/lib/config/parse-config');

const repoRoot = path.resolve(__dirname, '..');
const DEVELOP_REPO = 'https://github.com/WordPress/wordpress-develop.git';

function git(args, cwd) {
  return execFileSync('git', args, { cwd, stdio: ['ignore', 'pipe', 'pipe'] }).toString();
}

const REQUIRED_TEST_CONSTANTS = {
  WP_TESTS_DOMAIN: 'localhost',
  WP_TESTS_EMAIL: 'admin@example.org',
  WP_TESTS_TITLE: 'Test Blog',
  WP_PHP_BINARY: 'php',
};

function withRequiredTestConstants(config) {
  const missing = Object.entries(REQUIRED_TEST_CONSTANTS).filter(
    ([key]) => !new RegExp(`define\\(\\s*'${key}'`).test(config)
  );
  if (missing.length === 0) return config;

  const block = [
    '',
    '// WordPress PHPUnit defaults expected by the upstream test suite.',
    ...missing.map(([key, value]) => `if ( ! defined( '${key}' ) ) { define( '${key}', '${value}' ); }`),
    '',
  ].join('\n');
  const abspath = "define( 'ABSPATH', '/var/www/html/' );";
  return config.includes(abspath)
    ? config.replace(abspath, `${block}${abspath}`)
    : `${config.trimEnd()}${block}`;
}

/** Reads the installed WordPress version from wp-includes/version.php. */
function readWpVersion(wpDir) {
  const versionFile = path.join(wpDir, 'wp-includes', 'version.php');
  if (!fs.existsSync(versionFile)) return null;
  const match = fs.readFileSync(versionFile, 'utf8').match(/\$wp_version\s*=\s*'([^']+)'/);
  return match ? match[1] : null;
}

/** Downloads the PHPUnit suite for wpVersion into suiteDir if not present. */
function ensureSuite(suiteDir, wpVersion, label) {
  const marker = path.join(suiteDir, 'tests', 'phpunit', 'includes', 'functions.php');
  if (fs.existsSync(marker)) {
    console.log(`OK    ${label}: PHPUnit suite present`);
    return;
  }

  // Mirror wp-env's version handling: wordpress-develop tags are X.X.X,
  // WordPress reports X.X for non-patch releases; pre-releases use trunk.
  let ref = 'trunk';
  let fetchArgs = ['fetch', 'origin', 'trunk', '--depth', '1'];
  if (wpVersion && !/-(alpha|beta|rc)/i.test(wpVersion)) {
    const version = /^[0-9]+\.[0-9]+$/.test(wpVersion) ? `${wpVersion}.0` : wpVersion;
    ref = `tags/${version}`;
    fetchArgs = ['fetch', 'origin', 'tag', version, '--depth', '1'];
  }

  console.log(`FIX   ${label}: downloading PHPUnit suite (${ref})...`);
  const isRepo = fs.existsSync(path.join(suiteDir, '.git'));
  if (!isRepo) {
    if (fs.existsSync(suiteDir) && fs.readdirSync(suiteDir).length > 0) {
      const cacheName = path.basename(suiteDir);
      if (!['WordPress-PHPUnit', 'tests-WordPress-PHPUnit'].includes(cacheName)) {
        throw new Error(`${label}: refusing to replace unexpected directory ${suiteDir}`);
      }
      console.log(`FIX   ${label}: replacing incomplete PHPUnit suite directory`);
      fs.rmSync(suiteDir, { recursive: true, force: true });
    }
    fs.mkdirSync(suiteDir, { recursive: true });
    git(['clone', '--depth', '1', '--no-checkout', DEVELOP_REPO, suiteDir]);
    git(['sparse-checkout', 'set', '--cone', 'tests/phpunit'], suiteDir);
  }
  git(fetchArgs, suiteDir);
  git(['checkout', ref], suiteDir);

  if (!fs.existsSync(marker)) {
    throw new Error(`${label}: suite download finished but ${marker} is still missing`);
  }
  console.log(`OK    ${label}: PHPUnit suite installed`);
}

/** Generates wp-tests-config.php from the environment's wp-config.php. */
function ensureTestsConfig(wpDir, suiteDir, label) {
  const target = path.join(suiteDir, 'tests', 'phpunit', 'wp-tests-config.php');
  if (fs.existsSync(target)) {
    const existing = fs.readFileSync(target, 'utf8');
    const repaired = withRequiredTestConstants(existing);
    if (existing === repaired) {
      console.log(`OK    ${label}: wp-tests-config.php present`);
      return;
    }
    fs.writeFileSync(target, repaired);
    console.log(`FIX   ${label}: wp-tests-config.php repaired`);
    return;
  }

  const wpConfig = path.join(wpDir, 'wp-config.php');
  if (!fs.existsSync(wpConfig)) {
    throw new Error(`${label}: ${wpConfig} not found — run \`wp-env start\` first`);
  }

  // Same transform wp-env applies (lib/runtime/docker/wordpress.js): drop the
  // wp-settings.php require and point ABSPATH at the container's WP root.
  const abspath = "define( 'ABSPATH', '/var/www/html/' );\n\tdefine( 'WP_DEFAULT_THEME', 'default' );";
  const transformed = fs
    .readFileSync(wpConfig, 'utf8')
    .split('\n')
    .filter((line) => !/^require.*wp-settings\.php/.test(line))
    .join('\n')
    .replace(/define\(\s*'ABSPATH',\s*(?:__DIR__|dirname\(\s*__FILE__\s*\))\s*\.\s*'\/'\s*\);/, abspath);

  if (!transformed.includes("'/var/www/html/'")) {
    throw new Error(`${label}: could not locate the ABSPATH define in ${wpConfig}`);
  }
  fs.writeFileSync(target, withRequiredTestConstants(transformed));
  console.log(`FIX   ${label}: wp-tests-config.php generated`);
}

async function main() {
  let installPath = process.env.JLIFE_WP_ENV_INSTALL_PATH;
  if (!installPath) {
    const cacheRoot = await getCacheDirectory();
    installPath = path.resolve(cacheRoot, md5(getConfigFilePath(repoRoot)));
  }
  if (!fs.existsSync(installPath)) {
    throw new Error(`wp-env instance not found at ${installPath} — run \`wp-env start\` first`);
  }

  for (const env of ['development', 'tests']) {
    const prefix = env === 'development' ? '' : 'tests-';
    const wpDir = path.join(installPath, `${prefix}WordPress`);
    const suiteDir = path.join(installPath, `${prefix}WordPress-PHPUnit`);
    ensureSuite(suiteDir, readWpVersion(wpDir), env);
    ensureTestsConfig(wpDir, suiteDir, env);
  }
}

main().catch((err) => {
  console.error(`FAIL  ${err.message}`);
  process.exit(1);
});
