/**
 * Seed the dev network with test users and sample disciplemaking data.
 *
 * Idempotent: existing users/records are left alone. Run after env:setup.
 *
 * Role notes (see docs/architecture.md §3):
 * - Participants get a role on STUDY only (subscriber as a placeholder until
 *   the jlife plugins define jlife_participant) and never a HUB role.
 * - Leaders/coaches get the Disciple.Tools "multiplier" role on HUB, which
 *   scopes them to their own contacts/groups.
 */
'use strict';

const { wp, wpGet, step, BASE_URL, HUB_URL } = require('./lib');

const PASSWORD = 'password'; // dev only, matches wp-env's admin default

const USERS = [
  { login: 'leader1', email: 'leader1@example.test', study: 'subscriber', hub: 'multiplier' },
  { login: 'coach1', email: 'coach1@example.test', study: null, hub: 'multiplier' },
  { login: 'participant1', email: 'participant1@example.test', study: 'subscriber', hub: null },
  { login: 'participant2', email: 'participant2@example.test', study: 'subscriber', hub: null },
  { login: 'editor1', email: 'editor1@example.test', study: 'editor', hub: null },
];

step('Creating test users (network-wide, then per-subsite roles)');
for (const u of USERS) {
  if (wpGet(['user', 'get', u.login, '--field=user_login'])) {
    console.log(`${u.login}: already exists`);
  } else {
    wp(['user', 'create', u.login, u.email, `--user_pass=${PASSWORD}`, '--role=subscriber']);
  }
  if (u.study) wp(['user', 'set-role', u.login, u.study, `--url=${BASE_URL}`]);
  if (u.hub) {
    const res = wp(['user', 'set-role', u.login, u.hub, `--url=${HUB_URL}`], {
      allowFail: true,
      capture: true,
    });
    if (res.status !== 0) {
      // D.T roles are registered by the theme; fall back so seeding never dies.
      console.warn(`${u.login}: role "${u.hub}" not accepted on HUB, using subscriber. `);
      wp(['user', 'set-role', u.login, 'subscriber', `--url=${HUB_URL}`]);
    }
  }
}

step('Creating a sample huddle (D.T group) and contacts on HUB');
// Minimal direct inserts — good enough for UI smoke tests. For richer data,
// use the Demo Content plugin from HUB wp-admin (see docs/dev-environment.md).
const groupExists = (wpGet([
  'post', 'list', '--post_type=groups', '--field=post_title', `--url=${HUB_URL}`,
]) || '').includes('Pilot Huddle');
if (groupExists) {
  console.log('Pilot Huddle already exists.');
} else {
  const groupId = wpGet([
    'post', 'create', '--post_type=groups', '--post_title=Pilot Huddle',
    '--post_status=publish', '--porcelain', `--url=${HUB_URL}`,
  ]);
  if (groupId) {
    wp(['post', 'meta', 'update', groupId, 'group_status', 'active', `--url=${HUB_URL}`]);
    wp(['post', 'meta', 'update', groupId, 'group_type', 'group', `--url=${HUB_URL}`]);
    console.log(`Pilot Huddle created (post ${groupId}).`);
  }
  for (const name of ['Test Participant One', 'Test Participant Two']) {
    const contactId = wpGet([
      'post', 'create', '--post_type=contacts', `--post_title=${name}`,
      '--post_status=publish', '--porcelain', `--url=${HUB_URL}`,
    ]);
    if (contactId) {
      wp(['post', 'meta', 'update', contactId, 'overall_status', 'active', `--url=${HUB_URL}`]);
      console.log(`Contact "${name}" created (post ${contactId}).`);
    }
  }
}

console.log(`
Seed complete. Test accounts (password: "${PASSWORD}"):
  admin         super admin        ${BASE_URL}/wp-admin/network/
  leader1       multiplier on HUB  ${HUB_URL}/
  coach1        multiplier on HUB  ${HUB_URL}/
  participant1  subscriber, STUDY only
  participant2  subscriber, STUDY only
  editor1       editor on STUDY

For bulk realistic D.T sample data: HUB wp-admin -> Extensions (D.T) -> Demo Content.

Next: npm run env:verify
`);
