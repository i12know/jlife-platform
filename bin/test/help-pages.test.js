/**
 * Guards the seed copy for the Help, Rules and Privacy pages (#65):
 * plugins/jlife-challenges/data/help-pages.json. The owner edits the live pages
 * in wp-admin, so this only protects the FIRST seed: the repository may become
 * public, so no real contact details or hosting paths, a DRAFT notice on every
 * page, the same blocks in English and Vietnamese, and wording that matches how
 * sharing works now (the whole entry, Prayer included).
 * Run: npm run test:js
 */
'use strict';

const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');

const FILE = path.join(__dirname, '..', '..', 'plugins', 'jlife-challenges', 'data', 'help-pages.json');
const data = JSON.parse(fs.readFileSync(FILE, 'utf8'));
const KEYS = ['help', 'rules', 'privacy'];
const textOf = (page) => page.blocks.map((b) => (Array.isArray(b[1]) ? b[1].join(' ') : b[1])).join('\n');

test('the three pages exist in English and Vietnamese with the same blocks', () => {
  for (const key of KEYS) {
    assert.deepEqual(Object.keys(data[key]).sort(), ['en', 'vi'], key);
    const shape = (p) => p.blocks.map((b) => b[0]).join(',');
    assert.equal(shape(data[key].en), shape(data[key].vi), `${key}: same block types in the same order`);
    for (const code of ['en', 'vi']) {
      const page = data[key][code];
      assert.ok(page.title && page.slug && /^[a-z0-9-]+$/.test(page.slug), `${key}-${code}: title and slug`);
      for (const b of page.blocks) assert.ok(['h2', 'p', 'ul'].includes(b[0]), `${key}-${code}: block type ${b[0]}`);
    }
    assert.notEqual(data[key].en.slug, data[key].vi.slug);
  }
});

test('slugs do not collide with the app routes', () => {
  const routes = ['today', 'soap', 'share', 'group', 'marathon', 'join', 'signin', 'signout', 'consent', 'reading', 'unsubscribe', 'welcome', 'chao-mung'];
  for (const key of KEYS) for (const code of ['en', 'vi']) assert.ok(!routes.includes(data[key][code].slug), data[key][code].slug);
});

test('no real contact details, hosting paths or partner identifiers are seeded', () => {
  for (const key of KEYS) for (const code of ['en', 'vi']) {
    const t = textOf(data[key][code]);
    assert.ok(!/[A-Za-z0-9._+-]+@[A-Za-z0-9-]+\.[A-Za-z]{2,}/.test(t), `${key}-${code}: an email address`);
    assert.ok(!/redemptionpoint/i.test(t), `${key}-${code}: a real domain`);
    assert.ok(!/\/home\/|public_html|\.latoc/.test(t), `${key}-${code}: a hosting path`);
    assert.ok(!/https?:\/\//.test(t), `${key}-${code}: a hard-coded URL`);
  }
});

test('every page is marked DRAFT; the contact is a placeholder; Vietnamese is marked for the translator', () => {
  for (const key of KEYS) {
    assert.match(textOf(data[key].en), /DRAFT for the owner to review/, `${key}-en`);
    assert.match(textOf(data[key].vi), /BẢN NHÁP \(DRAFT\)/, `${key}-vi`);
    assert.match(textOf(data[key].vi), /người dịch xem lại/, `${key}-vi: translator review`);
  }
  for (const key of ['help', 'privacy']) for (const code of ['en', 'vi']) assert.ok(textOf(data[key][code]).includes('[privacy contact email]'), `${key}-${code}`);
});

test('sharing is described as the whole entry, Prayer included, never "the parts you select"', () => {
  for (const key of ['help', 'privacy']) {
    assert.match(textOf(data[key].en), /whole entry/);
    assert.match(textOf(data[key].en), /Prayer/);
    assert.match(textOf(data[key].vi), /toàn bộ bài viết/);
    assert.match(textOf(data[key].vi), /Cầu nguyện/);
  }
  for (const key of KEYS) for (const code of ['en', 'vi']) {
    assert.ok(!/parts you (select|picked|choose)|phần bạn đã chọn/i.test(textOf(data[key][code])), `${key}-${code}`);
  }
});

test('the SOAP section anchor the app links to is on the Help page, in both languages', () => {
  for (const code of ['en', 'vi']) {
    assert.ok(data.help[code].blocks.some((b) => b[0] === 'h2' && b[2] === 'what-is-soap'), code);
  }
});

test('Privacy carries the two backup policies and the youth paragraph', () => {
  const en = textOf(data.privacy.en);
  assert.ok(en.includes('Bluehost retains emergency backups for one week. J-Life separately keeps its ten most recent deployment backups, which may remain longer. Account deletion does not immediately remove information from existing backup copies.'));
  assert.match(en, /there is no separate parent or guardian approval step in the app/);
  assert.match(en, /Participants aged 13 to 17 normally join a youth group/);
});
