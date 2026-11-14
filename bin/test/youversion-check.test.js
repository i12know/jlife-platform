/**
 * Tests for bin/youversion-check.js (#79, #78) against a fake API.
 * The key and Bible text must never reach the output.
 */
'use strict';

const test = require('node:test');
const assert = require('node:assert/strict');
const { main, findBible, parseOverrides, parseVersionSpecs } = require('../youversion-check');

const KEY = 'test-key-SHOULD-NEVER-APPEAR-0123456789';
const VERSE_TEXT = 'FAKE-VERSE-TEXT In the beginning was the Word';

const CATALOG = {
  en: [
    { id: 406, abbreviation: 'ERV', title: 'Easy-to-Read Version', publisher_name: 'Bible League International', language_tag: 'en', copyright: '© 2006 Bible League International' },
    { id: 111, abbreviation: 'NIV', title: 'New International Version', publisher_name: 'Biblica', language_tag: 'en', copyright: '© Biblica' },
  ],
  vi: [
    { id: 2001, abbreviation: 'BPT', localized_abbreviation: 'BPT', title: 'Vietnamese Bible: Easy-to-Read Version', publisher_name: 'Bible League International', language_tag: 'vi', copyright: '© 2010 Bible League International' },
    { id: 193, abbreviation: 'VIE1925', title: 'Kinh Thánh 1925', publisher_name: null, language_tag: 'vi', copyright: 'Public domain' },
  ],
};

/**
 * Fake YouVersion API. inCatalog: IDs present in the language listings;
 * direct: IDs answered by GET /v1/bibles/{id}; licensedIds: IDs licensed to the app.
 */
function fakeApi({ status = 200, licensedIds = [406], inCatalog = [406, 111, 2001, 193], direct = [406, 111, 2001, 193] } = {}) {
  const calls = [];
  const all = [...CATALOG.en, ...CATALOG.vi];
  const json = (code, body) => ({ status: code, ok: code < 300, headers: { get: () => null }, text: async () => JSON.stringify(body) });
  const fetchImpl = async (url, opts) => {
    calls.push({ url, key: opts.headers['X-YVP-App-Key'] });
    if (status !== 200) return json(status, { fault: { faultstring: 'Invalid ApiKey', detail: { errorcode: 'oauth.v2.InvalidApiKey' } } });
    const u = new URL(url);
    if (u.pathname === '/v1/licenses') {
      const lic = [
        { id: 7, name: 'Express Bible License', version: 2, agreed_dt: '2026-11-01T00:00:00Z', bible_ids: licensedIds },
        { id: 1, name: 'Public Domain and Creative Commons', version: 1, bible_ids: [193] },
      ];
      const bid = Number(u.searchParams.get('bible_id'));
      return json(200, { data: bid ? lic.filter((l) => l.bible_ids.includes(bid)) : lic });
    }
    if (u.pathname === '/v1/bibles') {
      const lang = u.searchParams.get('language_ranges[]');
      const list = (CATALOG[lang] || []).filter((b) => inCatalog.includes(b.id));
      const data = u.searchParams.get('all_available') ? list : list.filter((b) => licensedIds.includes(b.id));
      return json(200, { data, next_page_token: null, total_size: data.length });
    }
    let m = u.pathname.match(/^\/v1\/bibles\/(\d+)$/);
    if (m) {
      const b = all.find((x) => x.id === Number(m[1]));
      return b && direct.includes(b.id) ? json(200, b) : json(404, { message: 'Bible not found' });
    }
    m = u.pathname.match(/^\/v1\/bibles\/(\d+)\/passages\/(.+)$/);
    if (m) {
      return [...licensedIds, 193].includes(Number(m[1]))
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

function assertSafe(out) {
  assert.ok(!out.includes(KEY), 'the key must never be printed');
  assert.ok(!out.includes('FAKE-VERSE-TEXT'), 'Bible text must never be printed');
}

test('auth passes with a working key and never prints it', async () => {
  const api = fakeApi();
  const { code, out } = await run(['auth'], { YVP_APP_KEY: KEY }, api);
  assert.equal(code, 0);
  assert.match(out, /Result: PASS/);
  assertSafe(out);
  assert.equal(api.calls[0].key, KEY, 'the key goes in the X-YVP-App-Key header');
});

test('a rejected key fails with HTTP 401 and its meaning, without the key', async () => {
  const { code, out } = await run(['gate1'], { YVP_APP_KEY: KEY }, fakeApi({ status: 401 }));
  assert.equal(code, 1);
  assert.match(out, /\| 401 \| key missing or not recognized/);
  assert.match(out, /Result: FAIL/);
  assertSafe(out);
  assert.ok(!out.includes('Gate 1'), 'gate1 does not run after a failed auth');
});

test('a missing secret fails without calling the API', async () => {
  const api = fakeApi();
  const { code, out } = await run(['auth'], {}, api);
  assert.equal(code, 1);
  assert.match(out, /YVP_APP_KEY` is empty/);
  assert.equal(api.calls.length, 0);
});

test('gate1 looks ERV up directly by 406 as well, reports scan counts, and never prints text', async () => {
  const api = fakeApi({ licensedIds: [406] });
  const { code, out } = await run(['gate1'], { YVP_APP_KEY: KEY }, api);
  assert.equal(code, 0);
  assertSafe(out);
  assert.ok(api.calls.some((c) => new URL(c.url).pathname === '/v1/bibles/406'), 'direct GET /v1/bibles/406');
  assert.match(out, /\| ERV \(Easy-to-Read Version\) \| 200 OK \| 2 of 2 scanned \| yes \(catalog en\) \| 406 \| ERV/);
  assert.match(out, /\| BPT \(Vietnamese Bible: Easy-to-Read Version\) \| — \| 2 of 2 scanned \| yes \(catalog vi\) \| 2001 \| BPT/);
  assert.match(out, /\| ERV \(Easy-to-Read Version\) \| 200 \| OK \| yes \| John 1:1-18 \| 45 \|/);
  assert.match(out, /\| BPT \(Vietnamese Bible: Easy-to-Read Version\) \| 403 \| key valid, but .*Access denied for 2001 \| no \|/);
  assert.match(out, /7 Express Bible License/);
});

test('gate1: an ID missing from the listing but answered directly is still found', async () => {
  const { out } = await run(['gate1'], { YVP_APP_KEY: KEY }, fakeApi({ inCatalog: [111, 193], licensedIds: [] }));
  assertSafe(out);
  assert.match(out, /\| ERV \(Easy-to-Read Version\) \| 200 OK \| 1 of 1 scanned \| yes \(GET \/v1\/bibles\/406\) \| 406 \|/);
  assert.match(out, /\| BPT \(Vietnamese Bible: Easy-to-Read Version\) \| — \| 1 of 1 scanned \| not found \|/);
});

test('versions: IDs and ABBR@lang, 404 for an unknown ID, junk reported, public domain returns text', async () => {
  const env = { YVP_APP_KEY: KEY, YVP_VERSIONS: '406, 999999, NIV@en, VIE1925@vi, nonsense!' };
  const { code, out } = await run(['versions'], env, fakeApi({ licensedIds: [], direct: [406, 111, 193] }));
  assert.equal(code, 0);
  assertSafe(out);
  assert.match(out, /\| 406 \| 200 OK \| — \| yes \(GET \/v1\/bibles\/406\) \| 406 \|/);
  assert.match(out, /\| 999999 \| 404 not found: Bible not found \| — \| not found \|/);
  assert.match(out, /\| NIV@en \| — \| 2 of 2 scanned \| yes \(catalog en\) \| 111 \|.*\| no \|/);
  assert.match(out, /\| VIE1925@vi \| 200 \| OK \| yes \| John 1:1-18 \| 45 \|/);
  assert.match(out, /\| nonsense! \| — \| — \| not understood/);
  assert.match(out, /\| 406 \| 403 \| key valid/);
});

test('list: shows each Bible with licensing, optionally licensed only', async () => {
  const api = fakeApi({ licensedIds: [2001] });
  let r = await run(['list'], { YVP_APP_KEY: KEY, YVP_LANGUAGE: 'vi' }, api);
  assertSafe(r.out);
  assert.match(r.out, /### `vi`: catalog 2 of 2 scanned; licensed to the app: 1/);
  assert.match(r.out, /\| 2001 \| BPT \| Vietnamese Bible: Easy-to-Read Version \| Bible League International \| vi \| yes \| 7 \|/);
  assert.match(r.out, /\| 193 \| VIE1925 \|.*\| no \| 1 \|/);
  r = await run(['list'], { YVP_APP_KEY: KEY, YVP_LANGUAGE: 'vi', YVP_LICENSED_ONLY: 'true' }, api);
  assert.ok(!r.out.includes('| 193 |'), 'licensed only hides unlicensed Bibles');
  r = await run(['list'], { YVP_APP_KEY: KEY, YVP_LANGUAGE: '' }, api);
  assert.match(r.out, /Nothing to list/);
});

test('helpers: abbreviation lookup, override and version-spec parsing', () => {
  const bibles = [{ id: 1, abbreviation: 'NIV' }, { id: 2, abbreviation: 'erv' }, { id: 3, localized_abbreviation: 'BPT' }];
  assert.equal(findBible(bibles, 'ERV').id, 2);
  assert.equal(findBible(bibles, 'bpt').id, 3);
  assert.equal(findBible(bibles, 'XYZ'), null);
  assert.deepEqual(parseOverrides('ERV=406, bpt=12 ,junk'), { ERV: 406, BPT: 12 });
  assert.deepEqual(parseVersionSpecs('406, niv@EN, BPT@vi, x y'), [
    { label: '406', id: 406 },
    { label: 'niv@EN', abbr: 'NIV', lang: 'en' },
    { label: 'BPT@vi', abbr: 'BPT', lang: 'vi' },
    { label: 'x y', invalid: true },
  ]);
});
