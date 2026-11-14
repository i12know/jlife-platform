/**
 * YouVersion Platform read-only checks (#79, #78 Gate 1).
 *
 * Run by .github/workflows/youversion-check.yml with the App Key in the
 * YVP_APP_KEY environment variable (a GitHub environment secret). Reports
 * only HTTP status, timing and non-secret metadata. It never prints the key,
 * request headers, or Bible text: for a passage it reports the status,
 * canonical reference and content length only.
 *
 *   node bin/youversion-check.js auth      # authentication smoke check (#79)
 *   node bin/youversion-check.js gate1     # ERV + BPT: catalog, licenses, passage (#78)
 *   node bin/youversion-check.js versions  # the versions listed in YVP_VERSIONS
 *   node bin/youversion-check.js list      # every Bible in YVP_LANGUAGE
 *
 * Environment:
 *   YVP_PASSAGE    one-chapter USFM passage, default JHN.1.1-18
 *   YVP_BIBLE_IDS  gate1 override, e.g. "ERV=406,BPT=1234"
 *   YVP_VERSIONS   versions to check: IDs ("406") and/or ABBR@lang ("NIV@en, BPT@vi")
 *   YVP_LANGUAGE   language tag(s) for "list", e.g. "vi" or "en,vi"
 *   YVP_LICENSED_ONLY  "true" to list only Bibles licensed to the app
 */
'use strict';

const API = 'https://api.youversion.com';
const MAX_PAGES = 30; // 30 x 99 Bibles per language

const GATE1_TARGETS = [
  { label: 'ERV (Easy-to-Read Version)', abbr: 'ERV', lang: 'en', id: 406 },
  { label: 'BPT (Vietnamese Bible: Easy-to-Read Version)', abbr: 'BPT', lang: 'vi', id: null },
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

const cell = (s) => String(s === undefined || s === null || s === '' ? '—' : s).replace(/\|/g, '\\|').replace(/\s+/g, ' ');

/** All Bibles for one language, following page_token. Reports whether the listing was complete. */
async function listBibles(fetchImpl, key, lang, allAvailable) {
  const out = [];
  let token = '';
  let total = null;
  for (let page = 0; page < MAX_PAGES; page++) {
    const qs = `language_ranges[]=${encodeURIComponent(lang)}&page_size=99${allAvailable ? '&all_available=true' : ''}${token ? `&page_token=${encodeURIComponent(token)}` : ''}`;
    const r = await get(fetchImpl, key, `/v1/bibles?${qs}`);
    if (r.status !== 200) return { status: r.status, error: r.error, bibles: out, total, complete: false };
    out.push(...((r.json && r.json.data) || []));
    if (r.json && Number.isInteger(r.json.total_size)) total = r.json.total_size;
    token = r.json && r.json.next_page_token;
    if (!token) return { status: 200, error: '', bibles: out, total, complete: true };
  }
  return { status: 200, error: '', bibles: out, total, complete: false };
}

/** Cache catalog and licensed listings per language for one run. */
function makeCatalog(fetchImpl, key) {
  const cache = {};
  return async (lang) => {
    if (!cache[lang]) {
      cache[lang] = {
        all: await listBibles(fetchImpl, key, lang, true),
        licensed: await listBibles(fetchImpl, key, lang, false),
      };
    }
    return cache[lang];
  };
}

/** Find a Bible by abbreviation (abbreviation or localized_abbreviation), case-insensitive. */
function findBible(bibles, abbr) {
  const want = String(abbr || '').toUpperCase();
  return bibles.find((b) => [b.abbreviation, b.localized_abbreviation].some((a) => String(a || '').toUpperCase() === want)) || null;
}

function parseOverrides(s) {
  const out = {};
  for (const part of String(s || '').split(',')) {
    const m = part.trim().match(/^([A-Za-z0-9]+)\s*=\s*(\d+)$/);
    if (m) out[m[1].toUpperCase()] = Number(m[2]);
  }
  return out;
}

/** "406, NIV@en, bpt@vi" -> [{ id: 406 }, { abbr: 'NIV', lang: 'en' }, { abbr: 'BPT', lang: 'vi' }]; junk is reported, not fetched. */
function parseVersionSpecs(s) {
  const specs = [];
  for (const raw of String(s || '').split(',').map((x) => x.trim()).filter(Boolean)) {
    let m;
    if ((m = raw.match(/^\d{1,6}$/))) specs.push({ label: raw, id: Number(raw) });
    else if ((m = raw.match(/^([A-Za-z0-9-]{1,20})@([A-Za-z]{2,3}(?:-[A-Za-z0-9]{2,8})*)$/))) specs.push({ label: raw, abbr: m[1].toUpperCase(), lang: m[2].toLowerCase() });
    else specs.push({ label: raw, invalid: true });
  }
  return specs.slice(0, 20);
}

function safePassage(p) {
  return /^[1-4A-Z]{3}\.\d+(\.\d+(-\d+)?)?$/.test(p || '') ? p : 'JHN.1.1-18';
}

/**
 * Check one version: resolve it (direct ID lookup and/or catalog search),
 * then licensing and a passage request. Returns a result row.
 */
async function checkVersion(fetchImpl, key, spec, ctx) {
  const row = { spec, bible: null, how: '', direct: null, catalog: null, licensedList: null, licenses: [], licStatus: '', passage: null };
  if (spec.invalid) {
    row.how = 'not understood: use an ID (406) or ABBR@lang (NIV@en)';
    return row;
  }
  if (spec.lang) {
    const cat = await ctx.catalog(spec.lang);
    row.catalog = { status: cat.all.status, scanned: cat.all.bibles.length, total: cat.all.total, complete: cat.all.complete };
    const b = spec.abbr ? findBible(cat.all.bibles, spec.abbr) : null;
    if (b) { row.bible = b; row.how = `catalog ${spec.lang}`; }
    if (cat.licensed.status === 200 && (b || spec.id)) {
      const id = b ? b.id : spec.id;
      row.licensedList = cat.licensed.bibles.some((x) => x.id === id);
    }
  }
  if (spec.id) {
    // Metadata returns 200 even for Bibles not licensed to the app, so this settles existence.
    const d = await get(fetchImpl, key, `/v1/bibles/${spec.id}`);
    row.direct = { status: d.status, meaning: meaning(d.status) + (d.error ? `: ${d.error}` : '') };
    if (d.status === 200 && d.json && d.json.id) {
      if (!row.bible) row.how = `GET /v1/bibles/${spec.id}`;
      row.bible = row.bible || d.json;
    }
  }
  if (!row.bible) {
    if (!row.how) row.how = 'not found';
    return row;
  }
  const id = row.bible.id;
  if (row.licensedList === null && row.bible.language_tag) {
    const cat = await ctx.catalog(String(row.bible.language_tag).split('-')[0]);
    if (cat.licensed.status === 200) row.licensedList = cat.licensed.bibles.some((x) => x.id === id);
  }
  row.licenses = ctx.licenses.filter((l) => (l.bible_ids || []).includes(id)).map((l) => `${l.id} ${l.name}`);
  const lb = await get(fetchImpl, key, `/v1/licenses?bible_id=${id}`);
  row.licStatus = `${lb.status}${lb.json && lb.json.data ? ` (${lb.json.data.length} license${lb.json.data.length === 1 ? '' : 's'}: ${lb.json.data.map((l) => l.id).join(', ') || 'none'})` : lb.error ? ` (${lb.error})` : ''}`;
  const p = await get(fetchImpl, key, `/v1/bibles/${id}/passages/${ctx.passage}?format=text`);
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
  return row;
}

function catalogNote(c) {
  if (!c) return '—';
  if (c.status !== 200) return `HTTP ${c.status}`;
  return `${c.scanned}${c.total !== null && c.total !== undefined ? ` of ${c.total}` : ''} scanned${c.complete ? '' : ' (INCOMPLETE)'}`;
}

function printRows(out, rows, passage) {
  out.line();
  out.line('### Versions');
  out.line();
  out.line('| Checked | Direct ID lookup | Catalog listing | Found? | YouVersion ID | Abbreviation | Title | Publisher | Language | In app-licensed list | Licenses covering it | GET /v1/licenses?bible_id= |');
  out.line('|---|---|---|---|---|---|---|---|---|---|---|---|');
  for (const r of rows) {
    const b = r.bible || {};
    const abbr = b.abbreviation ? `${cell(b.abbreviation)}${b.localized_abbreviation && b.localized_abbreviation !== b.abbreviation ? ` / ${cell(b.localized_abbreviation)}` : ''}` : '—';
    out.line(`| ${cell(r.spec.label)} | ${r.direct ? `${r.direct.status} ${cell(r.direct.meaning)}` : '—'} | ${catalogNote(r.catalog)} | ${r.bible ? `yes (${cell(r.how)})` : cell(r.how)} | ${cell(b.id)} | ${abbr} | ${cell(b.localized_title || b.title)} | ${cell(b.publisher_name)} | ${cell(b.language_tag)} | ${r.licensedList === null ? '—' : r.licensedList ? 'yes' : 'no'} | ${cell(r.licenses.join('; '))} | ${cell(r.licStatus)} |`);
  }
  out.line();
  out.line(`### Passage text request (\`${passage}\`; status and length only, never the text)`);
  out.line();
  out.line('| Checked | HTTP | Meaning | Returned text? | Reference | Content length (chars) |');
  out.line('|---|---|---|---|---|---|');
  for (const r of rows) {
    const p = r.passage;
    out.line(p
      ? `| ${cell(r.spec.label)} | ${p.status} | ${cell(p.meaning)}${p.retryAfter ? ` (Retry-After ${p.retryAfter}s)` : ''} | ${p.returnedText ? 'yes' : 'no'} | ${cell(p.reference || p.id)} | ${p.returnedText ? p.length : '—'} |`
      : `| ${cell(r.spec.label)} | — | not requested: version not found | no | — | — |`);
  }
  const withCopyright = rows.filter((r) => r.bible && r.bible.copyright);
  if (withCopyright.length) {
    out.line();
    out.line('### Copyright metadata (for attribution)');
    out.line();
    for (const r of withCopyright) out.line(`- **${cell(r.spec.label)}** (${r.bible.id}): ${cell(r.bible.copyright)}`);
  }
}

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

async function printLicenses(fetchImpl, key, out) {
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
  return licenses;
}

async function runVersions(fetchImpl, key, out, specs, opts, title) {
  out.line(title);
  out.line();
  const ctx = { catalog: makeCatalog(fetchImpl, key), passage: safePassage(opts.passage), licenses: [] };
  ctx.licenses = await printLicenses(fetchImpl, key, out);
  const rows = [];
  for (const spec of specs) rows.push(await checkVersion(fetchImpl, key, spec, ctx));
  printRows(out, rows, ctx.passage);
  return rows;
}

async function gate1(fetchImpl, key, out, opts) {
  const overrides = parseOverrides(opts.bibleIds);
  const specs = GATE1_TARGETS.map((t) => ({
    label: t.label,
    abbr: t.abbr,
    lang: t.lang,
    id: overrides[t.abbr] || t.id || undefined,
  }));
  return runVersions(fetchImpl, key, out, specs, opts, '## #78 Gate 1: ERV and BPT (read-only)');
}

async function versions(fetchImpl, key, out, opts) {
  const specs = parseVersionSpecs(opts.versions);
  if (!specs.length) {
    out.line('## Versions check');
    out.line();
    out.line('Nothing to check: set the `versions` input, e.g. `406, NIV@en, BPT@vi`.');
    return [];
  }
  return runVersions(fetchImpl, key, out, specs, opts, '## Versions check (read-only)');
}

async function list(fetchImpl, key, out, opts) {
  const langs = String(opts.language || '').split(',').map((s) => s.trim().toLowerCase()).filter((s) => /^[a-z]{2,3}(-[a-z0-9]{2,8})*$/.test(s)).slice(0, 5);
  out.line('## Bible listing (read-only)');
  if (!langs.length) {
    out.line();
    out.line('Nothing to list: set the `language` input, e.g. `vi` or `en,vi`.');
    return;
  }
  const licensedOnly = opts.licensedOnly === true || opts.licensedOnly === 'true';
  const licenses = await printLicenses(fetchImpl, key, out);
  const licName = (id) => licenses.filter((l) => (l.bible_ids || []).includes(id)).map((l) => l.id).join(', ');
  for (const lang of langs) {
    const all = await listBibles(fetchImpl, key, lang, true);
    const licensed = await listBibles(fetchImpl, key, lang, false);
    const licensedIds = new Set(licensed.bibles.map((b) => b.id));
    const shown = licensedOnly ? all.bibles.filter((b) => licensedIds.has(b.id)) : all.bibles;
    out.line();
    out.line(`### \`${lang}\`: catalog ${catalogNote({ status: all.status, scanned: all.bibles.length, total: all.total, complete: all.complete })}; licensed to the app: ${licensed.status === 200 ? licensed.bibles.length : `HTTP ${licensed.status}`}${licensedOnly ? ' (showing licensed only)' : ''}`);
    out.line();
    out.line('| ID | Abbreviation | Title | Publisher | Language | Licensed to app | License IDs |');
    out.line('|---|---|---|---|---|---|---|');
    for (const b of shown) {
      out.line(`| ${b.id} | ${cell(b.abbreviation)}${b.localized_abbreviation && b.localized_abbreviation !== b.abbreviation ? ` / ${cell(b.localized_abbreviation)}` : ''} | ${cell(b.localized_title || b.title)} | ${cell(b.publisher_name)} | ${cell(b.language_tag)} | ${licensedIds.has(b.id) ? 'yes' : 'no'} | ${cell(licName(b.id))} |`);
    }
  }
}

async function main(argv, env, fetchImpl, write) {
  const key = env.YVP_APP_KEY || '';
  const out = makeOut(key, write);
  const mode = argv[0] || 'auth';
  const ok = await auth(fetchImpl, key, out);
  if (!ok) return 1;
  const opts = {
    passage: env.YVP_PASSAGE,
    bibleIds: env.YVP_BIBLE_IDS,
    versions: env.YVP_VERSIONS,
    language: env.YVP_LANGUAGE,
    licensedOnly: env.YVP_LICENSED_ONLY,
  };
  if (mode !== 'auth') out.line();
  if (mode === 'gate1') await gate1(fetchImpl, key, out, opts);
  else if (mode === 'versions') await versions(fetchImpl, key, out, opts);
  else if (mode === 'list') await list(fetchImpl, key, out, opts);
  return 0;
}

module.exports = { main, findBible, parseOverrides, parseVersionSpecs, errorMessage };

if (require.main === module) {
  const fs = require('fs');
  const summary = process.env.GITHUB_STEP_SUMMARY;
  const write = (s) => {
    process.stdout.write(s);
    if (summary) fs.appendFileSync(summary, s);
  };
  main(process.argv.slice(2), process.env, fetch, write).then((code) => { process.exitCode = code; });
}
