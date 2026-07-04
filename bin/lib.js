/**
 * Shared helpers for dev-environment scripts.
 *
 * All WordPress commands run inside the wp-env "cli" container via the
 * @wordpress/env JS entry point, invoked with the current Node binary so the
 * scripts behave identically on Windows, macOS, and Linux (no npx/.cmd or
 * shell-quoting differences).
 */
'use strict';

const { spawnSync } = require('child_process');
const path = require('path');

const WP_ENV_BIN = path.join(
  __dirname,
  '..',
  'node_modules',
  '@wordpress',
  'env',
  'bin',
  'wp-env'
);

const BASE_URL = `http://localhost:${process.env.WP_ENV_PORT || 8888}`;
const HUB_URL = `${BASE_URL}/hub`;

function wpEnv(args, opts = {}) {
  const res = spawnSync(process.execPath, [WP_ENV_BIN, ...args], {
    stdio: opts.capture ? ['ignore', 'pipe', 'pipe'] : 'inherit',
    encoding: 'utf8',
  });
  if (res.error) {
    console.error(`Failed to launch wp-env: ${res.error.message}`);
    console.error('Did you run "npm install" first?');
    process.exit(1);
  }
  if (res.status !== 0 && !opts.allowFail) {
    console.error(`\nCommand failed: wp-env ${args.join(' ')}`);
    if (opts.capture && res.stderr) console.error(res.stderr);
    process.exit(res.status === null ? 1 : res.status);
  }
  return res;
}

/** Run a wp-cli command inside the development container. Exits on failure. */
function wp(args, opts = {}) {
  return wpEnv(['run', 'cli', '--', 'wp', ...args], opts);
}

/** Run a wp-cli command, returning stdout on success or null on failure. */
function wpGet(args) {
  const res = wp(args, { capture: true, allowFail: true });
  return res.status === 0 ? res.stdout.trim() : null;
}

function step(msg) {
  console.log(`\n==> ${msg}`);
}

module.exports = { wp, wpGet, wpEnv, step, BASE_URL, HUB_URL };
