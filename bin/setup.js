/**
 * One-shot (idempotent) setup for the J-Life dev network.
 *
 * Run after `npm run env:start`. Converts the wp-env install to a
 * subdirectory multisite, installs the Disciple.Tools theme + plugins,
 * creates the HUB subsite, and sets locales — per docs/architecture.md.
 *
 * Safe to re-run: every step checks current state first.
 */
'use strict';

const { wp, wpGet, step, BASE_URL, HUB_URL } = require('./lib');

// Pinned artifacts. Bump deliberately; record notable jumps in docs/spikes.
const ARTIFACTS = {
  theme:
    'https://github.com/DiscipleTools/disciple-tools-theme/releases/download/1.82.2/disciple-tools-theme.zip',
  multisite:
    'https://github.com/DiscipleTools/disciple-tools-multisite/releases/download/1.17.0/disciple-tools-multisite.zip',
  magicLinks:
    'https://github.com/DiscipleTools/disciple-tools-bulk-magic-link-sender/releases/download/1.33.0/disciple-tools-bulk-magic-link-sender.zip',
  demoContent:
    'https://github.com/DiscipleTools/disciple-tools-demo-content/releases/download/0.6.7/disciple-tools-demo-content.zip',
};

step('Checking WordPress is installed (did env:start finish?)');
if (wpGet(['core', 'is-installed']) === null && wpGet(['core', 'version']) === null) {
  console.error('WordPress does not respond. Run "npm run env:start" first.');
  process.exit(1);
}

step('Converting to subdirectory multisite (skipped if already a network)');
if (wpGet(['core', 'is-installed', '--network']) === null) {
  wp(['core', 'multisite-convert', '--title=J-Life Dev Network']);
} else {
  console.log('Already a multisite network.');
}

step('Installing Disciple.Tools theme and plugins (pinned versions)');
wp(['theme', 'install', ARTIFACTS.theme, '--force']);
wp(['plugin', 'install', ARTIFACTS.multisite, '--force']);
wp(['plugin', 'install', ARTIFACTS.magicLinks, '--force']);
wp(['plugin', 'install', ARTIFACTS.demoContent, '--force']);

step('Network-activating the Disciple.Tools multisite plugin');
wp(['plugin', 'activate', 'disciple-tools-multisite', '--network']);

step('Creating HUB subsite at /hub/ (skipped if it exists)');
const sites = wpGet(['site', 'list', '--field=url']) || '';
if (!sites.includes('/hub/')) {
  wp(['site', 'create', '--slug=hub', '--title=J-Life Hub']);
} else {
  console.log('HUB subsite already exists.');
}

step('Activating the Disciple.Tools theme on HUB');
wp(['theme', 'enable', 'disciple-tools-theme', '--network']);
wp(['theme', 'activate', 'disciple-tools-theme', `--url=${HUB_URL}`]);

step('Activating Magic Links and Demo Content on HUB only');
wp(['plugin', 'activate', 'disciple-tools-bulk-magic-link-sender', `--url=${HUB_URL}`]);
wp(['plugin', 'activate', 'disciple-tools-demo-content', `--url=${HUB_URL}`]);

step('Setting Vietnamese locale on HUB (STUDY stays en for now — see docs)');
wp(['language', 'core', 'install', 'vi'], { allowFail: true });
wp(['option', 'update', 'WPLANG', 'vi', `--url=${HUB_URL}`]);

step('Setting pretty permalinks on both subsites');
wp(['rewrite', 'structure', '/%postname%/', `--url=${BASE_URL}`]);
wp(['rewrite', 'structure', '/%postname%/', `--url=${HUB_URL}`]);

console.log(`
Setup complete.
  STUDY (participant surface): ${BASE_URL}/
  HUB   (Disciple.Tools):      ${HUB_URL}/wp-admin/
  Login: admin / password  (wp-env default — dev only)

Next: npm run env:seed
`);
