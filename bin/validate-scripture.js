/**
 * Validate the on-page Scripture reader's data (#139).
 *
 * 1. The Bible version registry (plugins/jlife-scripture/data/bible-versions.json):
 *    fields, allowed values, and the allowlist rules (only enabled + native +
 *    license evidence may render text; link sources are links; one default
 *    per language; each language's fallback is an enabled link version;
 *    YouVersion ids match the provider inventory; local datasets exist).
 * 2. The committed Kinh Thánh 1925 dataset matches its manifest checksums.
 * 3. The PHP parser's test fixture (every reference in the reading plans)
 *    is in sync with the plan JSON. `--write` regenerates it.
 *
 * Dependency-free. Prints PASS/FAIL; exits non-zero on any failure.
 */
'use strict';

const fs = require('fs');
const path = require('path');
const datasetCheck = require('./build-scripture-vie1934').check;

const ROOT = path.join(__dirname, '..');
const PLUGIN = path.join(ROOT, 'plugins', 'jlife-scripture');
const REGISTRY = path.join(PLUGIN, 'data', 'bible-versions.json');
const INVENTORY = path.join(ROOT, 'content', 'reading-plans', 'bible-provider-registry.json');
const PLANS = path.join(ROOT, 'content', 'reading-plans', 'plans.json');
const FIXTURE = path.join(PLUGIN, 'tests', 'fixtures', 'plan-references.json');

const SOURCES = ['local', 'youversion', 'apibible', 'link'];
const DISPLAYS = ['native', 'link'];

let failures = 0;
function check(name, ok, detail = '') {
  if (!ok) failures++;
  console.log(`${ok ? 'PASS' : 'FAIL'}  ${name}${!ok && detail ? ` — ${detail}` : ''}`);
  return ok;
}

const readJson = (f) => JSON.parse(fs.readFileSync(f, 'utf8'));
const nonEmpty = (s) => typeof s === 'string' && s.trim() !== '';

/** Problems with one version entry (mirrors jlife_scripture_version_problems()). */
function versionProblems(v) {
  const p = [];
  if (!/^[a-z0-9-]{1,40}$/.test(v.key || '')) p.push('key');
  for (const f of ['language', 'name', 'short_code', 'source_id']) if (!nonEmpty(v[f])) p.push(f);
  if (!SOURCES.includes(v.source)) p.push('source');
  if (!DISPLAYS.includes(v.display)) p.push('display');
  if (v.source === 'link' && v.display !== 'link') p.push('a link source can only display as a link');
  if (!v.copyright || !nonEmpty(v.copyright.text)) p.push('copyright.text');
  if (!v.copyright || !/^https:\/\//.test(v.copyright.url || '')) p.push('copyright.url');
  const age = v.cache && v.cache.max_age_seconds;
  if (!Number.isInteger(age) || age < 0) p.push('cache.max_age_seconds');
  const max = v.cache ? v.cache.max_consecutive_verses : undefined;
  if (max !== null && (!Number.isInteger(max) || max < 1)) p.push('cache.max_consecutive_verses');
  if (typeof v.enabled !== 'boolean') p.push('enabled');
  if (!Array.isArray(v.default_for)) p.push('default_for');
  if (v.enabled && v.display === 'native' && !nonEmpty(v.license_evidence)) p.push('an enabled native version needs license_evidence');
  if (v.license_evidence !== null && !nonEmpty(v.license_evidence)) p.push('license_evidence must be null or text');
  return p;
}

function validateRegistry() {
  const reg = readJson(REGISTRY);
  const versions = reg.versions || [];
  const byKey = new Map();
  for (const v of versions) {
    const p = versionProblems(v);
    check(`registry: version ${v.key || '?'} is well formed`, p.length === 0, p.join(', '));
    check(`registry: key ${v.key} is unique`, !byKey.has(v.key));
    byKey.set(v.key, v);
  }
  const langs = Object.keys(reg.languages || {});
  check('registry: languages en and vi are configured', langs.includes('en') && langs.includes('vi'), langs.join(','));
  for (const lang of langs) {
    const l = reg.languages[lang];
    const defaults = versions.filter((v) => (v.default_for || []).includes(lang));
    check(`registry: ${lang} has exactly one default version`, defaults.length === 1, defaults.map((v) => v.key).join(','));
    check(`registry: ${lang} default is a version in that language`, defaults.every((v) => v.language === lang));
    const fb = byKey.get(l.fallback);
    check(`registry: ${lang} fallback is an enabled Bible Gateway link version in ${lang}`, !!fb && fb.source === 'link' && fb.enabled && fb.language === lang, l.fallback);
    check(`registry: ${lang} has a label and an embed flag`, nonEmpty(l.label) && typeof l.embed === 'boolean');
  }

  // Allowlist rules from #139.
  for (const code of ['ERV', 'BPT']) {
    const v = versions.find((x) => x.short_code === code);
    check(`registry: ${code} is a link (Bible League has not licensed hosted text)`, !!v && v.source === 'link' && v.display === 'link');
  }
  const kt = byKey.get('kt1925');
  check('registry: Kinh Thánh 1925 is local, native, enabled and the Vietnamese default', !!kt && kt.source === 'local' && kt.display === 'native' && kt.enabled && kt.default_for.includes('vi'));

  const inventory = readJson(INVENTORY);
  const yv = new Map(((inventory.youversion || {}).versions || []).map((v) => [String(v.id), v]));
  for (const v of versions.filter((x) => x.source === 'youversion')) {
    const inv = yv.get(v.source_id);
    check(`registry: YouVersion ${v.short_code} (${v.source_id}) is in the provider inventory`, !!inv && inv.code === v.short_code);
    const ready = !!inv && inv.ready_for_production === true;
    check(`registry: YouVersion ${v.short_code} renders text only once the inventory marks it ready`, ready || !(v.enabled && v.display === 'native'));
  }
  const ab = new Map((((inventory.api_bible || {}).versions) || []).map((v) => [v.key, v]));
  for (const v of versions.filter((x) => x.source === 'apibible')) {
    const inv = ab.get(v.key);
    check(`registry: API.Bible ${v.short_code} matches the provider inventory`, !!inv && inv.code === v.short_code && inv.source_id === v.source_id && inv.language === v.language);
    check(`registry: API.Bible ${v.short_code} has a recorded bibleId, not a placeholder`, /^[0-9a-f]{16}-[0-9]{2}$/.test(v.source_id), v.source_id);
    check(`registry: API.Bible ${v.short_code} links to its rights holder, not API.Bible, once it shows text`, !(v.enabled && v.display === 'native') || !/api\.bible/.test((v.copyright && v.copyright.url) || ''), v.copyright && v.copyright.url);
  }
  for (const v of versions.filter((x) => x.source === 'local')) {
    const manifest = path.join(PLUGIN, 'data', v.source_id, 'manifest.json');
    const ok = fs.existsSync(manifest);
    check(`registry: local dataset ${v.source_id} exists`, ok);
    if (ok) {
      const m = readJson(manifest);
      check(`registry: local dataset ${v.source_id} is public domain in ${v.language}`, m.license === 'Public Domain' && m.language === v.language);
    }
  }
}

function planReferences() {
  const manifest = readJson(PLANS);
  const seen = new Map();
  const files = [];
  for (const plan of manifest.plans) {
    const rel = `content/reading-plans/${plan.source_csv.replace(/\.csv$/, '.json')}`;
    files.push(rel);
    for (const day of readJson(path.join(ROOT, rel)).days) {
      for (const p of day.passages) if (!seen.has(p.display)) seen.set(p.display, p.refs);
    }
  }
  return {
    description: 'Every Scripture reference in the reading plans with the Node parser\'s segments (bin/content-lib/scripture.js). The PHP parser must agree. Regenerate: node bin/validate-scripture.js --write',
    generated_from: files,
    references: [...seen.entries()].map(([display, refs]) => ({ display, refs })),
  };
}

function validateFixture() {
  const text = `${JSON.stringify(planReferences(), null, 2)}\n`;
  if (process.argv.includes('--write')) {
    fs.mkdirSync(path.dirname(FIXTURE), { recursive: true });
    fs.writeFileSync(FIXTURE, text);
    console.log(`WROTE ${path.relative(ROOT, FIXTURE)}`);
  }
  const current = fs.existsSync(FIXTURE) ? fs.readFileSync(FIXTURE, 'utf8') : null;
  check('parser fixture lists every reading-plan reference (run with --write after a plan change)', current === text);
}

validateRegistry();
const dataset = datasetCheck();
check('Kinh Thánh 1925 dataset matches its manifest checksums', dataset.length === 0, dataset.join('; '));
validateFixture();

if (failures) {
  console.log(`\n${failures} check(s) failed.`);
  process.exit(1);
}
console.log('\nScripture data valid.');
