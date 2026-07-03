/**
 * Verify the dev environment matches docs/architecture.md.
 *
 * Read-only. Prints a PASS/FAIL table and exits non-zero on any failure —
 * used both by humans on a fresh machine and (later) by CI (#16).
 */
'use strict';

const { wpGet, step, BASE_URL, HUB_URL } = require('./lib');

const results = [];
function check(name, ok, detail = '') {
  results.push({ name, ok, detail });
  console.log(`${ok ? 'PASS' : 'FAIL'}  ${name}${detail ? ` — ${detail}` : ''}`);
}

async function httpStatus(url) {
  try {
    const res = await fetch(url, { redirect: 'follow' });
    return res.status;
  } catch (e) {
    return `unreachable (${e.cause ? e.cause.code || e.cause.message : e.message})`;
  }
}

(async () => {
  step('Verifying network structure');
  check('WordPress multisite network installed', wpGet(['core', 'is-installed', '--network']) !== null);

  const sites = wpGet(['site', 'list', '--field=url']) || '';
  check('HUB subsite exists at /hub/', sites.includes('/hub/'), sites.replace(/\n/g, ' '));

  step('Verifying themes and plugins');
  const hubTheme = wpGet(['theme', 'list', '--status=active', '--field=name', `--url=${HUB_URL}`]) || '';
  check('Disciple.Tools theme active on HUB', hubTheme.includes('disciple-tools-theme'), hubTheme);

  const studyTheme = wpGet(['theme', 'list', '--status=active', '--field=name', `--url=${BASE_URL}`]) || '';
  check('STUDY does NOT run the D.T theme', !studyTheme.includes('disciple-tools-theme'), studyTheme);

  const networkPlugins = wpGet(['plugin', 'list', '--status=active-network', '--field=name']) || '';
  check('disciple-tools-multisite network-active', networkPlugins.includes('disciple-tools-multisite'));

  const hubPlugins = wpGet(['plugin', 'list', '--status=active', '--field=name', `--url=${HUB_URL}`]) || '';
  check('Magic Links active on HUB', hubPlugins.includes('disciple-tools-bulk-magic-link-sender'));

  step('Verifying locale and users');
  const hubLang = wpGet(['option', 'get', 'WPLANG', `--url=${HUB_URL}`]) || '(empty)';
  check('HUB locale is Vietnamese (vi)', hubLang === 'vi', hubLang);

  for (const login of ['leader1', 'participant1']) {
    check(`user ${login} exists`, wpGet(['user', 'get', login, '--field=user_login']) === login);
  }
  const hubRoles = wpGet(['user', 'list', '--field=user_login', `--url=${HUB_URL}`]) || '';
  check('participant1 has NO role on HUB', !hubRoles.split('\n').includes('participant1'));

  step('Verifying HTTP responses');
  const studyStatus = await httpStatus(`${BASE_URL}/`);
  check('STUDY front page responds 200', studyStatus === 200, `status ${studyStatus}`);
  const hubStatus = await httpStatus(`${HUB_URL}/wp-login.php`);
  check('HUB login page responds 200', hubStatus === 200, `status ${hubStatus}`);

  const failed = results.filter((r) => !r.ok);
  console.log(`\n${results.length - failed.length}/${results.length} checks passed.`);
  if (failed.length) {
    console.error('Environment does NOT match the expected setup. See FAIL lines above.');
    process.exit(1);
  }
  console.log('Environment verified.');
})();
