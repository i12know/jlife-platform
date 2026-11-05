/**
 * Build portable reading-plan JSON from the owner's schedule CSVs (#59).
 *
 * The CSVs in content/reading-plans/ (PR #50) stay the source; this writes
 * one <csv-name>.json per plan listed in content/reading-plans/plans.json,
 * with parsed Scripture references and pre-built Bible links (links only,
 * never Bible text). Deterministic: the same inputs always give the same bytes.
 *
 * Usage:
 *   node bin/build-reading-plans.js          # write the JSON files
 *   node bin/build-reading-plans.js --check  # exit non-zero if any JSON is stale
 */
'use strict';

const fs = require('fs');
const path = require('path');
const { parseCsvRecords } = require('./content-lib/csv');
const { parseReferenceList } = require('./content-lib/scripture');
const { buildDayLinks } = require('./content-lib/bible-links');

const ROOT = path.join(__dirname, '..');
const PLAN_DIR = path.join(ROOT, 'content', 'reading-plans');
const HARMONY = path.join(ROOT, 'content', 'harmony', 'robertson-1922-outline.json');

function readJson(file) {
  return JSON.parse(fs.readFileSync(file, 'utf8'));
}

/** Robertson section label ("54a", "128a", "2") -> r1922 event id. */
function robertsonIndex() {
  const index = new Map();
  for (const e of readJson(HARMONY).events) index.set(String(e.robertson_section), e.gospel_event_id);
  return (label) => index.get(label) || index.get(label.replace(/[a-z]+$/, '')) || null;
}

/** "a, b , c" -> ["a","b","c"] (strings kept exactly; "4.40" stays "4.40"). */
function list(cell) {
  return String(cell).split(',').map((s) => s.trim()).filter(Boolean);
}

/**
 * Build one plan document.
 *
 * @param {object} plan Entry from plans.json.
 * @param {object} linkConfig Parsed bible-links.json.
 * @param {(label: string) => ?string} toEventId Robertson label resolver.
 * @returns {object}
 */
function buildPlan(plan, linkConfig, toEventId) {
  const csvFile = path.join(PLAN_DIR, plan.source_csv);
  const { records } = parseCsvRecords(fs.readFileSync(csvFile, 'utf8'));
  const col = plan.columns;
  const errors = [];

  const days = records.map((r) => {
    const day = Number(r[col.day]);
    const where = `${plan.source_csv} day ${r[col.day]}`;
    const parsed = parseReferenceList(r[col.scripture]);
    if (!parsed.ok) {
      errors.push(`${where}: ${parsed.error}`);
      return null;
    }
    const robertson = list(r[col.robertson_sections]).map((s) => s.replace(/^#/, ''));
    const eventIds = [];
    for (const label of robertson) {
      const id = toEventId(label);
      if (!id) errors.push(`${where}: Robertson section #${label} not in the harmony dataset`);
      else if (!eventIds.includes(id)) eventIds.push(id);
    }
    let links = null;
    try {
      links = buildDayLinks(parsed.passages, linkConfig);
    } catch (e) {
      errors.push(`${where}: ${e.message}`);
    }
    const notes = String(r[col.notes] || '').trim();
    return {
      day_id: `d${String(day).padStart(3, '0')}`,
      day,
      date: String(r[col.date]).trim().slice(0, 10),
      title: String(r[col.titles]).trim(),
      titles: String(r[col.titles]).split('|').map((t) => t.trim()).filter(Boolean),
      plusnothing_sections: list(r[col.plusnothing_sections]),
      robertson_sections: robertson,
      gospel_event_ids: eventIds,
      passages: parsed.passages,
      word_count: Number(String(r[col.words]).trim()),
      notes: notes === '' ? null : notes,
      links,
    };
  });

  if (errors.length) throw new Error(`${plan.plan_id}:\n  ${errors.join('\n  ')}`);

  const doc = {
    schema_version: '1.0',
    plan_id: plan.plan_id,
    role: plan.role,
  };
  if (plan.primary_plan_id) doc.primary_plan_id = plan.primary_plan_id;
  Object.assign(doc, {
    title: plan.title,
    start_date: plan.start_date,
    end_date: plan.end_date,
    day_count: plan.day_count,
    source_file: `content/reading-plans/${plan.source_csv}`,
    source_attribution: plan.source_attribution,
    rights_note: plan.rights_note,
    links_config: linkConfig,
    days,
  });
  return doc;
}

/** @returns {{file: string, text: string, doc: object}[]} */
function buildAll() {
  const manifest = readJson(path.join(PLAN_DIR, 'plans.json'));
  const linkConfig = readJson(path.join(PLAN_DIR, 'bible-links.json'));
  const toEventId = robertsonIndex();
  return manifest.plans.map((plan) => {
    const doc = buildPlan(plan, linkConfig, toEventId);
    const file = path.join(PLAN_DIR, plan.source_csv.replace(/\.csv$/, '.json'));
    return { file, doc, text: `${JSON.stringify(doc, null, 2)}\n` };
  });
}

if (require.main === module) {
  const check = process.argv.includes('--check');
  let built;
  try {
    built = buildAll();
  } catch (e) {
    console.error(`FAIL  ${e.message}`);
    process.exit(1);
  }
  let stale = 0;
  for (const { file, text } of built) {
    const rel = path.relative(ROOT, file);
    if (check) {
      const current = fs.existsSync(file) ? fs.readFileSync(file, 'utf8') : null;
      const ok = current === text;
      if (!ok) stale++;
      console.log(`${ok ? 'PASS' : 'FAIL'}  ${rel} is in sync with its CSV${ok ? '' : ' — run: npm run plans:build'}`);
    } else {
      fs.writeFileSync(file, text);
      console.log(`WROTE ${rel}`);
    }
  }
  process.exit(stale ? 1 : 0);
}

module.exports = { buildAll, buildPlan };
