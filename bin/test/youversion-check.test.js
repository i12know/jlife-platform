/**
 * Tests for bin/youversion-check.js (#79, #78) against a fake API.
 * The key and Bible text must never reach the output.
 */
'use strict';

const test = require('node:test');
const assert = require('node:assert/strict');
const { main, findBible, parseOverrides } = require('../youversion-check');

const KEY = 'test-key-SHOULD-NEVER-APPEAR-0123456789';
const VERSE_TEXT = 'FAKE-VERSE-TEXT In the beginning was the Word';

function fakeApi({ status = 200, licensedIds = [406] } = {}) {
  const calls = [];
  const catalog = {
    en: [{ id: 406, abbreviation: 'ERV', title: 'Easy-to-Read Version', publisher_name: 'Bible League International', language_tag: 'en', copyright: '© 2006 Bible League International' }],
    vi: [{ id: 2001, abbreviation: 'BPT', localized_abbreviation: 'BPT', title: 'Vietnamese Bible: Easy-to-Read Version', publisher_name: 'Bible League International', language_tag: 'vi', copyright: '© 2010 Bible League International' }],
  };
  const json = (code, body) => ({ status: code, ok: code < 300, headers: { get: () => null }, text: async () => JSON.stringify(body) });
  const fetchImpl = async (url, opts) => {
    calls.push({ url, key: opts.headers['X-YVP-App-Key'] });
    if (status !== 200) return json(status, { fault: { faultstring: 'Invalid ApiKey', detail: { errorcode: 'oauth.v2.InvalidApiKey' } } });
    const u = new URL(url);
    if (u.pathname === '/v1/licenses') {
      const all = [{ id: 7, name: 'Express Bible License', version: 2, agreed_dt: '2026-11-01T00:00:00Z', bible_ids: licensedIds }];
      const bid = Number(u.searchParams.get('bible_id'));
      return json(200, { data: bid ? all.filter((l) => l.bible_ids.includes(bid)) : all });
    }
    if (u.pathname === '/v1/bibles') {
      const lang = u.searchParams.get('language_ranges[]');
      const list = catalog[lang] || [];
      const data = u.searchParams.get('all_available') ? list : list.filter((b) => licensedIds.includes(b.id));
      return json(200, { data, next_page_token: null });
    }
    const m = u.pathname.match(/^\/v1\/bibles\/(\d+)\/passages\/(.+)$/);
    if (m) {
      return licensedIds.includes(Number(m[1]))
        ? json(200, { id: m[2], reference: 'John 1:1-18', content: VERSE_TEXT })
        : json(403, { message: `Access denied for ${m[1]}` });
    }
    return json(404, { message: 'nope' });
  };
  return { fetchImpl, calls };
}

async function run(argv, env, api) {
  let out = '';
  const code = await main(argv, env, api.fetchImpl, (s) => { out += s; });
  return { code, out };
}

test('auth passes with a working key and never prints it', async () => {
  const api = fakeApi();
  const { code, out } = await run(['auth'], { YVP_APP_KEY: KEY }, api);
  assert.equal(code, 0);
  assert.match(out, /Result: PASS/);
  assert.ok(!out.includes(KEY));
  assert.equal(api.calls[0].key, KEY, 'the key goes in the X-YVP-App-Key header');
});

test('a rejected key fails with HTTP 401 and its meaning, without the key', async () => {
  const { code, out } = await run(['gate1'], { YVP_APP_KEY: KEY }, fakeApi({ status: 401 }));
  assert.equal(code, 1);
  assert.match(out, /\| 401 \| key missing or not recognized/);
  assert.match(out, /Result: FAIL/);
  assert.ok(!out.includes(KEY));
  assert.ok(!out.includes('Gate 1'), 'gate1 does not run after a failed auth');
});

test('a missing secret fails without calling the API', async () => {
  const api = fakeApi();
  const { code, out } = await run(['auth'], {}, api);
  assert.equal(code, 1);
  assert.match(out, /YVP_APP_KEY` is empty/);
  assert.equal(api.calls.length, 0);
});

test('gate1 reports IDs, licenses and passage status but never the verse text', async () => {
  const { code, out } = await run(['gate1'], { YVP_APP_KEY: KEY }, fakeApi({ licensedIds: [406] }));
  assert.equal(code, 0);
  assert.ok(!out.includes(KEY));
  assert.ok(!out.includes('FAKE-VERSE-TEXT'), 'Bible text must never be printed');
  assert.match(out, /\| ERV \(Easy-to-Read Version\) \| found \| 406 \| ERV/);
  assert.match(out, /\| BPT \(Vietnamese Bible: Easy-to-Read Version\) \| found \| 2001 \| BPT/);
  assert.match(out, /\| ERV \| 200 \| OK \| yes \| John 1:1-18 \| 45 \|/);
  assert.match(out, /\| BPT \| 403 \| key valid, but .*Access denied for 2001 \| no \|/);
  assert.match(out, /7 Express Bible License/);
});

test('helpers: catalog lookup by abbreviation or override, override parsing', () => {
  const bibles = [{ id: 1, abbreviation: 'NIV' }, { id: 2, abbreviation: 'erv' }, { id: 3, localized_abbreviation: 'BPT' }];
  assert.equal(findBible(bibles, { key: 'ERV' }).id, 2);
  assert.equal(findBible(bibles, { key: 'BPT' }).id, 3);
  assert.equal(findBible(bibles, { key: 'BPT' }, 1).id, 1);
  assert.equal(findBible(bibles, { key: 'XYZ' }), null);
  assert.deepEqual(parseOverrides('ERV=406, bpt=12 ,junk'), { ERV: 406, BPT: 12 });
});
