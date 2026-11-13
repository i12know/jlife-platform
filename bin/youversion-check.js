/**
 * YouVersion Platform read-only checks (#79, #78 Gate 1).
 *
 * Run by .github/workflows/youversion-check.yml with the App Key in the
 * YVP_APP_KEY environment variable (a GitHub environment secret). Reports
 * only HTTP status, timing and non-secret metadata. It never prints the key,
 * request headers, or Bible text: for a passage it reports the status,
 * canonical reference and content length only.
 *
 *   node bin/youversion-check.js auth    # authentication smoke check (#79)
 *   node bin/youversion-check.js gate1   # catalog, licenses, passage text (#78)
 *
 * Targets: English Easy-to-Read Version (ERV, Bible.com 406) and Vietnamese
 * Bible: Easy-to-Read Version (BPT, chosen on #91). IDs are discovered from
 * the catalog by abbreviation; YVP_BIBLE_IDS ("ERV=406,BPT=1234") overrides.
 * The passage is YVP_PASSAGE (default JHN.1.1-18, John 1:1-18).
 */
'use strict';

const API = 'https://api.youversion.com';

const TARGETS = [
  { key: 'ERV', lang: 'en', name: 'Easy-to-Read Version', expectId: 406 },
  { key: 'BPT', lang: 'vi', name: 'Vietnamese Bible: Easy-to-Read Version', expectId: null },
];

/** Make a logger that can never print the key, even by accident. */
function makeOut(secret, write) {
  const clean = (s) => (secret ? String(s).split(secret).join('***') : String(s));
  return { line: (s = '') => write(clean(s) + '\n') };
}

/** One GET; never throws. Returns status, timing, parsed JSON (if any) and a short error message. */
async function get(fetchImpl, key, path) {
  const started = Date.now();
  let res;
  try {
    res = await fetchImpl(API + path, { headers: { 'X-YVP-App-Key': key, Accept: 'application/json' } });
  } catch (e) {
    return { status: 0, ms: Date.now() - started, json: null, error: `network error: ${e.message}` };
  }
  const ms = Date.now() - started;
  const text = await res.text();
  let json = null;
  try { json = text ? JSON.parse(text) : null; } catch (e) { /* 429 and some gateway errors are plain text */ }
  return { status: res.status, ms, json, error: res.ok ? '' : errorMessage(res.status, json, text), retryAfter: res.headers.get('retry-after') };
}

/** Non-secret error summary from the documented error shapes. */
function errorMessage(status, json, text) {
  if (json && json.fault) return `${json.fault.faultstring || ''} (${(json.fault.detail && json.fault.detail.errorcode) || 'no errorcode'})`;
  if (json && json.message) return String(json.message);
  if (json && Array.isArray(json.detail)) return json.detail.map((d) => `${(d.loc || []).join('.')}: ${d.msg}`).join('; ');
  if (status === 429) return 'rate limit exceeded';
  return text ? String(text).slice(0, 120) : '(empty body)';
}

const MEANING = {
  200: 'OK',
  401: 'key missing or not recognized',
  403: 'key valid, but this Bible or endpoint is not licensed/enabled for the app',
  404: 'not found',
  412: 'the app behind the key is archived or unknown',
  422: 'invalid or missing parameter',
  429: 'rate limited',
};
const meaning = (s) => MEANING[s] || (s >= 500 ? 'YouVersion server error' : s === 0 ? 'no response' : '');

/** All Bibles for one language, following page_token. */
async function listBibles(fetchImpl, key, lang, allAvailable) {
  const out = [];
  let token = '';
  for (let page = 0; page < 20; page++) {
    const qs = `language_ranges[]=${encodeURIComponent(lang)}&page_size=99${allAvailable ? '&all_available=true' : ''}${token ? `&page_token=${encodeURIComponent(token)}` : ''}`;
    const r = await get(fetchImpl, key, `/v1/bibles?${qs}`);
    if (r.status !== 200) return { status: r.status, error: r.error, bibles: out };
    out.push(...((r.json && r.json.data) || []));
    token = r.json && r.json.next_page_token;
    if (!token) break;
  }
  return { status: 200, error: '', bibles: out };
}

/** Find a target in a Bible list: explicit ID, else abbreviation match. */
function findBible(bibles, target, overrideId) {
  const abbr = (b) => [b.abbreviation, b.localized_abbreviation].map((a) => String(a || '').toUpperCase());
  if (overrideId) return bibles.find((b) => b.id === overrideId) || null;
  return bibles.find((b) => abbr(b).includes(target.key)) ||
    (target.expectId ? bibles.find((b) => b.id === target.expectId) : null) || null;
}

function parseOverrides(s) {
  const out = {};
  for (const part of String(s || '').split(',')) {
    const m = part.trim().match(/^([A-Za-z0-9]+)\s*=\s*(\d+)$/);
    if (m) out[m[1].toUpperCase()] = Number(m[2]);
  }
  return out;
}

const cell = (s) => String(s === undefined || s === null || s === '' ? '—' : s).replace(/\|/g, '\\|').replace(/\s+/g, ' ');

async function auth(fetchImpl, key, out) {
  out.line('## YouVersion authentication check (#79)');
  out.line();
  if (!key) {
    out.line('**Result: FAIL**: `YVP_APP_KEY` is empty. Check the secret name and that the job uses the `youversion-check` environment.');
    return false;
  }
  out.line(`Key present: yes (${key.length} characters; value never printed).`);
  const r = await get(fetchImpl, key, '/v1/bibles?language_ranges[]=en&page_size=1');
  out.line();
  out.line('| Request | HTTP | Meaning | Time |');
  out.line('|---|---|---|---|');
  out.line(`| GET /v1/bibles?language_ranges[]=en&page_size=1 | ${r.status} | ${cell(meaning(r.status) + (r.error ? `: ${r.error}` : ''))} | ${r.ms} ms |`);
  const ok = r.status === 200;
  out.line();
  out.line(ok ? '**Result: PASS**: the key authenticates.' : `**Result: FAIL**: HTTP ${r.status}. ${r.status === 401 ? 'The key is missing or not recognized: re-enter it.' : 'See the meaning above.'}`);
  return ok;
}

async function gate1(fetchImpl, key, out, opts) {
  const overrides = parseOverrides(opts.bibleIds);
  const passage = /^[1-3A-Z]{3}\.\d+(\.\d+(-\d+)?)?$/.test(opts.passage || '') ? opts.passage : 'JHN.1.1-18';
  out.line('## #78 Gate 1: ERV and BPT (read-only)');
  out.line();
  out.line(`Passage test: \`${passage}\`. Text is never printed; only status, reference and length.`);

  // Licenses agreed by this app (unfiltered call; the docs disagree on required params).
  const lic = await get(fetchImpl, key, '/v1/licenses');
  const licenses = (lic.json && lic.json.data) || [];
  out.line();
  out.line(`### Licenses agreed to by the app: HTTP ${lic.status}${lic.error ? ` (${cell(lic.error)})` : ''}`);
  if (licenses.length) {
    out.line();
    out.line('| License ID | Name | Version | Agreed | Bibles covered |');
    out.line('|---|---|---|---|---|');
    for (const l of licenses) out.line(`| ${l.id} | ${cell(l.name)} | ${cell(l.version)} | ${cell(l.agreed_dt)} | ${(l.bible_ids || []).length} |`);
  }

  const rows = [];
  for (const t of TARGETS) {
    const catalog = await listBibles(fetchImpl, key, t.lang, true);
    const licensed = await listBibles(fetchImpl, key, t.lang, false);
    const b = findBible(catalog.bibles, t, overrides[t.key]);
    const row = { t, catalogStatus: catalog.status, inCatalog: !!b, bible: b, licensedList: false, licenses: [], licStatus: '', passage: null };
    if (b) {
      row.licensedList = licensed.bibles.some((x) => x.id === b.id);
      row.licenses = licenses.filter((l) => (l.bible_ids || []).includes(b.id)).map((l) => `${l.id} ${l.name}`);
      const lb = await get(fetchImpl, key, `/v1/licenses?bible_id=${b.id}`);
      row.licStatus = `${lb.status}${lb.json && lb.json.data ? ` (${lb.json.data.length} license${lb.json.data.length === 1 ? '' : 's'}: ${lb.json.data.map((l) => l.id).join(', ') || 'none'})` : lb.error ? ` (${lb.error})` : ''}`;
      const p = await get(fetchImpl, key, `/v1/bibles/${b.id}/passages/${passage}?format=text`);
      const content = p.json && typeof p.json.content === 'string' ? p.json.content : '';
      row.passage = {
        status: p.status,
        meaning: meaning(p.status) + (p.error ? `: ${p.error}` : ''),
        reference: p.json && p.json.reference,
        id: p.json && p.json.id,
        length: content.length,
        returnedText: p.status === 200 && content.length > 0,
        retryAfter: p.retryAfter,
      };
    }
    rows.push(row);
  }

  out.line();
  out.line('### Versions');
  out.line();
  out.line('| Target | Catalog (all_available) | YouVersion ID | Abbreviation | Title | Publisher | Language | In app-licensed list | Licenses covering it | GET /v1/licenses?bible_id= |');
  out.line('|---|---|---|---|---|---|---|---|---|---|');
  for (const r of rows) {
    const b = r.bible || {};
    out.line(`| ${r.t.key} (${r.t.name}) | ${r.catalogStatus === 200 ? (r.inCatalog ? 'found' : 'not found') : `HTTP ${r.catalogStatus}`} | ${cell(b.id)} | ${cell(b.abbreviation)}${b.localized_abbreviation && b.localized_abbreviation !== b.abbreviation ? ` / ${cell(b.localized_abbreviation)}` : ''} | ${cell(b.localized_title || b.title)} | ${cell(b.publisher_name)} | ${cell(b.language_tag)} | ${r.inCatalog ? (r.licensedList ? 'yes' : 'no') : '—'} | ${cell(r.licenses.join('; '))} | ${cell(r.licStatus)} |`);
  }
  out.line();
  out.line('### Passage text request');
  out.line();
  out.line('| Target | HTTP | Meaning | Returned text? | Reference | Content length (chars) |');
  out.line('|---|---|---|---|---|---|');
  for (const r of rows) {
    const p = r.passage;
    out.line(p
      ? `| ${r.t.key} | ${p.status} | ${cell(p.meaning)}${p.retryAfter ? ` (Retry-After ${p.retryAfter}s)` : ''} | ${p.returnedText ? 'yes' : 'no'} | ${cell(p.reference || p.id)} | ${p.returnedText ? p.length : '—'} |`
      : `| ${r.t.key} | — | not requested: version not found in the catalog | no | — | — |`);
  }
  out.line();
  out.line('### Copyright metadata (from the catalog, for attribution)');
  out.line();
  for (const r of rows) {
    if (r.bible) out.line(`- **${r.t.key}** (${r.bible.id}): ${cell(r.bible.copyright)}`);
  }
  return rows;
}

async function main(argv, env, fetchImpl, write) {
  const key = env.YVP_APP_KEY || '';
  const out = makeOut(key, write);
  const mode = argv[0] || 'auth';
  const ok = await auth(fetchImpl, key, out);
  if (!ok) return 1;
  if (mode === 'gate1') {
    out.line();
    await gate1(fetchImpl, key, out, { bibleIds: env.YVP_BIBLE_IDS, passage: env.YVP_PASSAGE });
  }
  return 0;
}

module.exports = { main, findBible, parseOverrides, errorMessage };

if (require.main === module) {
  const fs = require('fs');
  const summary = process.env.GITHUB_STEP_SUMMARY;
  const write = (s) => {
    process.stdout.write(s);
    if (summary) fs.appendFileSync(summary, s);
  };
  main(process.argv.slice(2), process.env, fetch, write).then((code) => { process.exitCode = code; });
}
