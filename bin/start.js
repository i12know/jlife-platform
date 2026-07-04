/**
 * Start wp-env with the repo's pinned WordPress version preloaded in cache.
 *
 * wp-env parses its default config before merging .wp-env.json, so on machines
 * where Node's raw DNS resolver fails it can ask for the latest WordPress
 * version even when this repo pins one. Seeding this cache key keeps the
 * documented start command deterministic without changing wp-env itself.
 */
'use strict';

const fs = require('fs');
const path = require('path');

const { setCache } = require('../node_modules/@wordpress/env/lib/cache');
const getCacheDirectory = require('../node_modules/@wordpress/env/lib/config/get-cache-directory');
const md5 = require('../node_modules/@wordpress/env/lib/md5');
const { getConfigFilePath } = require('../node_modules/@wordpress/env/lib/config/parse-config');
const { wpEnv } = require('./lib');

const repoRoot = path.resolve(__dirname, '..');

function getPinnedWordPressVersion() {
  const config = JSON.parse(fs.readFileSync(path.join(repoRoot, '.wp-env.json'), 'utf8'));
  const match = typeof config.core === 'string' ? config.core.match(/#([^/#]+)$/) : null;
  return match ? match[1] : null;
}

async function seedPinnedVersionCache() {
  const version = getPinnedWordPressVersion();
  if (!version) return;

  const cacheRoot = await getCacheDirectory();
  const workDirectoryPath = path.resolve(cacheRoot, md5(getConfigFilePath(repoRoot)));
  await setCache('latestWordPressVersion', version, { workDirectoryPath });
}

seedPinnedVersionCache()
  .then(() => wpEnv(['start']))
  .catch((err) => {
    console.error(`Failed to prepare wp-env cache: ${err.message}`);
    process.exit(1);
  });
