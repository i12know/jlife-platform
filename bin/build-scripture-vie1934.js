/**
 * Convert the public-domain Kinh Thánh 1925 (eBible.org `vie1934`, USFM) into
 * the dataset the jlife-scripture plugin imports (#139).
 *
 * Output: plugins/jlife-scripture/data/vie1934/<USFM book>.tsv, one verse per
 * line ("chapter<TAB>verse<TAB>text"), plus manifest.json with the source URL,
 * edition, the source zip's SHA-256, the normalisations applied and a SHA-256
 * per file. The converted text is committed (public domain; see the PR for
 * #139), so deploys need no network and the import is reproducible.
 *
 * Usage (refresh from a new eBible.org download):
 *   curl -sSLo /tmp/vie1934_usfm.zip https://ebible.org/Scriptures/vie1934_usfm.zip
 *   mkdir /tmp/vie1934 && unzip -q /tmp/vie1934_usfm.zip -d /tmp/vie1934
 *   node bin/build-scripture-vie1934.js --zip /tmp/vie1934_usfm.zip --dir /tmp/vie1934
 *
 *   node bin/build-scripture-vie1934.js --check   # verify committed files against the manifest
 */
'use strict';

const fs = require('fs');
const path = require('path');
const crypto = require('crypto');
const { parseUsfm, repairVniCapitals } = require('./content-lib/usfm');

const ROOT = path.join(__dirname, '..');
const OUT_DIR = path.join(ROOT, 'plugins', 'jlife-scripture', 'data', 'vie1934');
const SOURCE_URL = 'https://ebible.org/Scriptures/vie1934_usfm.zip';
const DETAILS_URL = 'https://ebible.org/find/details.php?id=vie1934';

const sha256 = (buf) => crypto.createHash('sha256').update(buf).digest('hex');

/** The dataset checksum: one "CODE sha256" line per book, in manifest order. */
function dataChecksum(books) {
  return sha256(books.map((b) => `${b.code} ${b.sha256}\n`).join(''));
}

function arg(name) {
  const i = process.argv.indexOf(name);
  return i > 0 ? process.argv[i + 1] : null;
}

/** Edition line from eBible's copr.htm ("generated ... 30 Oct 2026 from source files dated 12 Dec 2025"). */
function edition(dir) {
  const file = path.join(dir, 'copr.htm');
  if (!fs.existsSync(file)) throw new Error('copr.htm not found in the extracted zip');
  const html = fs.readFileSync(file, 'utf8').replace(/<[^>]*>/g, ' ').replace(/\s+/g, ' ');
  if (!/Public Domain/.test(html)) throw new Error('copr.htm no longer says "Public Domain": stop and review');
  const m = html.match(/generated with Haiola by eBible\.org (\d+ \w+ \d{4}) from source files dated (\d+ \w+ \d{4})/);
  if (!m) throw new Error('edition dates not found in copr.htm');
  const iso = (d) => new Date(`${d} UTC`).toISOString().slice(0, 10);
  return `eBible.org vie1934 USFM, generated ${iso(m[1])} from source files dated ${iso(m[2])}`;
}

function build(zip, dir) {
  const files = fs.readdirSync(dir).filter((f) => /\.usfm$/i.test(f)).sort();
  if (files.length === 0) throw new Error(`no .usfm files in ${dir}`);
  fs.mkdirSync(OUT_DIR, { recursive: true });
  for (const f of fs.readdirSync(OUT_DIR)) if (f.endsWith('.tsv')) fs.unlinkSync(path.join(OUT_DIR, f));

  const books = [];
  let repairs = 0;
  let total = 0;
  for (const f of files) {
    const { book, verses } = parseUsfm(fs.readFileSync(path.join(dir, f), 'utf8'));
    const seen = new Set();
    const lines = verses.map((v) => {
      const key = `${v.chapter}:${v.verse}`;
      if (seen.has(key)) throw new Error(`${book} ${key} appears twice`);
      seen.add(key);
      const r = repairVniCapitals(v.text.normalize('NFC'));
      repairs += r.count;
      if (/[\t\n\\]/.test(r.text)) throw new Error(`${book} ${key}: unexpected tab, newline or marker`);
      return `${v.chapter}\t${v.verse}\t${r.text}\n`;
    });
    const body = lines.join('');
    fs.writeFileSync(path.join(OUT_DIR, `${book}.tsv`), body);
    books.push({ code: book, file: `${book}.tsv`, verses: lines.length, sha256: sha256(body) });
    total += lines.length;
  }
  const manifest = {
    dataset: 'vie1934',
    name: 'Kinh Thánh 1925 (Bản Truyền Thống)',
    language: 'vi',
    license: 'Public Domain',
    license_evidence: 'eBible.org copr.htm: NT first published 1923, OT 1925; NT public domain since 2019-01-01, OT since 2021-01-01 (translator William Cadman, 1883-1948)',
    source_url: SOURCE_URL,
    details_url: DETAILS_URL,
    edition: edition(dir),
    source_sha256: sha256(fs.readFileSync(zip)),
    normalization: [
      'Unicode NFC; whitespace collapsed; verse text only (no headings or book titles)',
      `Repaired ${repairs} capital letters left by a legacy VNI conversion at the start of a word (e.g. "Aáy" -> "Ấy", "Oâng" -> "Ông"); see bin/content-lib/usfm.js`,
    ],
    verse_count: total,
    books,
  };
  manifest.data_sha256 = dataChecksum(books);
  fs.writeFileSync(path.join(OUT_DIR, 'manifest.json'), `${JSON.stringify(manifest, null, 2)}\n`);
  console.log(`WROTE ${books.length} books, ${total} verses, ${repairs} VNI repairs -> ${path.relative(ROOT, OUT_DIR)}`);
}

/** @returns {string[]} problems with the committed dataset (empty when valid). */
function check() {
  const problems = [];
  const manifestFile = path.join(OUT_DIR, 'manifest.json');
  if (!fs.existsSync(manifestFile)) return ['manifest.json missing'];
  const m = JSON.parse(fs.readFileSync(manifestFile, 'utf8'));
  for (const k of ['dataset', 'source_url', 'edition', 'source_sha256', 'license', 'data_sha256']) {
    if (!m[k]) problems.push(`manifest has no ${k}`);
  }
  let total = 0;
  for (const b of m.books || []) {
    const file = path.join(OUT_DIR, b.file);
    if (!fs.existsSync(file)) {
      problems.push(`${b.file} missing`);
      continue;
    }
    const body = fs.readFileSync(file, 'utf8');
    if (sha256(body) !== b.sha256) problems.push(`${b.file} does not match its checksum`);
    const lines = body.split('\n').filter(Boolean);
    if (lines.length !== b.verses) problems.push(`${b.file} has ${lines.length} verses, manifest says ${b.verses}`);
    if (!lines.every((l) => /^\d+\t\d+\t[^\t]+$/.test(l))) problems.push(`${b.file} has a malformed line`);
    total += lines.length;
  }
  if (total !== m.verse_count) problems.push(`verse_count ${m.verse_count} != ${total}`);
  if (dataChecksum(m.books || []) !== m.data_sha256) problems.push('data_sha256 does not match the per-book checksums');
  const extra = fs.readdirSync(OUT_DIR).filter((f) => f.endsWith('.tsv') && !(m.books || []).some((b) => b.file === f));
  if (extra.length) problems.push(`files not in the manifest: ${extra.join(', ')}`);
  return problems;
}

if (require.main === module) {
  if (process.argv.includes('--check')) {
    const p = check();
    for (const x of p) console.log(`FAIL  ${x}`);
    if (p.length) process.exit(1);
    console.log('PASS  vie1934 dataset matches its manifest');
  } else {
    const zip = arg('--zip');
    const dir = arg('--dir');
    if (!zip || !dir) {
      console.error('Usage: node bin/build-scripture-vie1934.js --zip <vie1934_usfm.zip> --dir <extracted dir> | --check');
      process.exit(2);
    }
    build(zip, dir);
  }
}

module.exports = { check, dataChecksum, OUT_DIR };
