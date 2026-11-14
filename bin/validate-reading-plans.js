/**
 * Validate the 104-day reading plans (#59).
 *
 * Checks every plan JSON in content/reading-plans/ against its CSV source and
 * the reading-plan contract: day count and calendar, stable IDs, string section
 * IDs in order, parseable references, Harmony linkage, links for every
 * configured language, supplemental/primary alignment, and rights metadata.
 *
 * Dependency-free. Prints PASS/FAIL per check; exits non-zero on any failure.
 */
'use strict';

const fs = require('fs');
const path = require('path');
const { buildAll } = require('./build-reading-plans');

const ROOT = path.join(__dirname, '..');
const HARMONY = path.join(ROOT, 'content', 'harmony', 'robertson-1922-outline.json');

const PLAN_ID = /^rpl-[a-z0-9]+(-[a-z0-9]+)*$/;
const DAY_ID = /^d[0-9]{3}$/;
const SECTION_ID = /^[0-9]+\.[0-9]+$/;
const DATE = /^\d{4}-\d{2}-\d{2}$/;
const URL_HOSTS = { bible_gateway: 'https://www.biblegateway.com/', bible_com: 'https://www.bible.com/' };

let failures = 0;
function check(scope, name, ok, detail = '') {
  if (!ok) failures++;
  if (!ok || process.env.VERBOSE) console.log(`${ok ? 'PASS' : 'FAIL'}  [${scope}] ${name}${detail ? ` — ${detail}` : ''}`);
  return ok;
}
let summaries = 0;
function pass(scope, name) {
  summaries++;
  console.log(`PASS  [${scope}] ${name}`);
}

function addDays(iso, n) {
  const d = new Date(`${iso}T00:00:00Z`);
  d.setUTCDate(d.getUTCDate() + n);
  return d.toISOString().slice(0, 10);
}

function sectionKey(id) {
  const [a, b] = id.split('.').map(Number);
  return a * 10000 + b;
}

let built;
try {
  built = buildAll();
} catch (e) {
  console.error(`FAIL  building plans from CSV: ${e.message}`);
  process.exit(1);
}

const eventIds = new Set(JSON.parse(fs.readFileSync(HARMONY, 'utf8')).events.map((e) => e.gospel_event_id));
const byId = new Map();

for (const { file, text, doc: fresh } of built) {
  const rel = path.relative(ROOT, file);
  const onDisk = fs.existsSync(file) ? fs.readFileSync(file, 'utf8') : null;
  check(rel, 'committed JSON is in sync with its CSV source', onDisk === text, 'run: npm run plans:build');
  const doc = onDisk ? JSON.parse(onDisk) : fresh;
  byId.set(doc.plan_id, doc);
  let ok = true;

  ok = check(rel, 'plan_id matches rpl-<slug>', PLAN_ID.test(doc.plan_id), doc.plan_id) && ok;
  ok = check(rel, 'role is primary|supplemental', ['primary', 'supplemental'].includes(doc.role), doc.role) && ok;
  ok = check(rel, 'source_attribution present', typeof doc.source_attribution === 'string' && doc.source_attribution.length > 20) && ok;
  ok = check(rel, 'rights_note present', typeof doc.rights_note === 'string' && /reference-only/i.test(doc.rights_note)) && ok;
  ok = check(rel, `exactly ${doc.day_count} days`, Array.isArray(doc.days) && doc.days.length === doc.day_count, String(doc.days.length)) && ok;
  ok = check(rel, 'end_date = start_date + day_count - 1', addDays(doc.start_date, doc.day_count - 1) === doc.end_date) && ok;

  const seenIds = new Set();
  let lastSection = 0;
  doc.days.forEach((d, i) => {
    const where = `${rel} ${d.day_id || `#${i + 1}`}`;
    ok = check(where, 'day number is sequential', d.day === i + 1, String(d.day)) && ok;
    ok = check(where, 'day_id is d### and matches the day', DAY_ID.test(d.day_id) && d.day_id === `d${String(i + 1).padStart(3, '0')}`) && ok;
    ok = check(where, 'day_id unique', !seenIds.has(d.day_id)) && ok;
    seenIds.add(d.day_id);
    ok = check(where, 'date is the campaign date for this day', DATE.test(d.date) && d.date === addDays(doc.start_date, i), d.date) && ok;
    ok = check(where, 'title present', typeof d.title === 'string' && d.title.length > 0) && ok;
    ok = check(where, 'section IDs are strings like 4.40', Array.isArray(d.plusnothing_sections) && d.plusnothing_sections.every((s) => typeof s === 'string' && SECTION_ID.test(s))) && ok;
    if (doc.role === 'primary') {
      for (const s of d.plusnothing_sections) {
        ok = check(where, `section ${s} follows the previous section`, sectionKey(s) > lastSection) && ok;
        lastSection = sectionKey(s);
      }
    }
    ok = check(where, 'Harmony event IDs exist', d.gospel_event_ids.every((id) => eventIds.has(id)), d.gospel_event_ids.join(',')) && ok;
    if (doc.role === 'supplemental') {
      const hs = d.harmony_sections;
      const expected = [...new Set(d.robertson_sections.map((l) => l.replace(/[a-z]+$/, '')))];
      ok = check(where, 'Harmony Bible sections: once each, in reading order, linked to harmony-bible.com', Array.isArray(hs) &&
        hs.map((x) => x.number).join(',') === expected.join(',') &&
        hs.every((x) => x.title && eventIds.has(x.gospel_event_id) && x.url === `https://harmony-bible.com/sections/${x.number}/`),
      hs ? hs.map((x) => x.number).join(',') : 'missing') && ok;
    }
    ok = check(where, 'has at least one passage with parsed refs', d.passages.length > 0 && d.passages.every((p) => p.display && p.refs.length > 0)) && ok;
    ok = check(where, 'word count is a positive integer', Number.isInteger(d.word_count) && d.word_count > 0) && ok;
    for (const [lang, cfg] of Object.entries(doc.links_config)) {
      const l = d.links && d.links[lang];
      const host = URL_HOSTS[cfg.provider];
      ok = check(where, `${lang} links exist`, !!l && l.passages.length > 0) && ok;
      if (!l) continue;
      const urls = [...l.passages.map((p) => p.url), ...(l.combined ? [l.combined.url] : [])];
      ok = check(where, `${lang} links use ${cfg.provider}`, urls.every((u) => u.startsWith(host))) && ok;
      if (cfg.provider === 'bible_gateway') ok = check(where, `${lang} has one combined link`, !!l.combined) && ok;
      if (cfg.provider === 'bible_com') {
        ok = check(where, `${lang} Bible.com links never cross a chapter`, l.passages.every((p) => !/\.\d+\.\d+-\d+\.\d+\./.test(p.url))) && ok;
      }
    }
  });

  if (ok) pass(rel, `${doc.days.length} days: calendar, IDs, sections, references, Harmony links and Bible links all valid`);
}

for (const doc of byId.values()) {
  if (doc.role !== 'supplemental') continue;
  const primary = byId.get(doc.primary_plan_id);
  const scope = doc.plan_id;
  if (!check(scope, `primary plan ${doc.primary_plan_id} exists`, !!primary)) continue;
  const aligned = primary.days.length === doc.days.length &&
    primary.days.every((p, i) => p.day_id === doc.days[i].day_id && p.date === doc.days[i].date);
  if (check(scope, 'aligned day-for-day with the primary plan (same day_id and date)', aligned)) {
    pass(scope, `aligned day-for-day with ${primary.plan_id}`);
  }
}

console.log(`\n${failures === 0 ? 'Reading plans valid.' : `${failures} check(s) failed.`}`);
process.exit(failures === 0 ? 0 : 1);
