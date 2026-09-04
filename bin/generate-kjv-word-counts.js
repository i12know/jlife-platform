/**
 * Regenerate content/harmony/kjv-word-counts.json — per-verse word counts
 * for every book/chapter the Robertson harmony references.
 *
 * This is a maintenance script, not part of `npm run content:validate`: it
 * fetches the public-domain KJV text from Project Gutenberg (#10) over the
 * network and re-derives word counts from it. Run it only when the harmony
 * dataset's scripture_refs change in a way that needs a book/chapter this
 * file doesn't yet cover (see content/harmony/README.md).
 *
 * It stores integer word counts only — never verse text — per
 * docs/content-rights.md ("References only — never paste Scripture text").
 * A word count is not the text; nothing here reproduces KJV wording.
 *
 * Usage: node bin/generate-kjv-word-counts.js
 */
'use strict';

const fs = require('fs');
const path = require('path');
const https = require('https');

const ROOT = path.join(__dirname, '..');
const OUT_FILE = path.join(ROOT, 'content', 'harmony', 'kjv-word-counts.json');
const SOURCE_URL = 'https://www.gutenberg.org/cache/epub/10/pg10.txt';

// Books the harmony's scripture_refs touch, and the chapters we need from
// each (keeps the derived file scoped to the harmony, not the whole Bible).
const BOOKS_NEEDED = ['Matthew', 'Mark', 'Luke', 'John', 'Acts', '1 Corinthians'];

function fetchText(url) {
  return new Promise((resolve, reject) => {
    https.get(url, (res) => {
      if (res.statusCode >= 300 && res.statusCode < 400 && res.headers.location) {
        fetchText(res.headers.location).then(resolve, reject);
        return;
      }
      if (res.statusCode !== 200) {
        reject(new Error(`GET ${url} -> ${res.statusCode}`));
        return;
      }
      const chunks = [];
      res.on('data', (c) => chunks.push(c));
      res.on('end', () => resolve(Buffer.concat(chunks).toString('utf8')));
      res.on('error', reject);
    }).on('error', reject);
  });
}

// Book header lines appear twice in the Gutenberg text (once in the table
// of contents, once at the actual section start). We want the second
// occurrence of each, in canonical NT order, as start/end boundaries.
const HEADER_BY_BOOK = {
  Matthew: 'The Gospel According to Saint Matthew',
  Mark: 'The Gospel According to Saint Mark',
  Luke: 'The Gospel According to Saint Luke',
  John: 'The Gospel According to Saint John',
  Acts: 'The Acts of the Apostles',
  Romans: 'The Epistle of Paul the Apostle to the Romans',
  '1 Corinthians': 'The First Epistle of Paul the Apostle to the Corinthians',
  '2 Corinthians': 'The Second Epistle of Paul the Apostle to the Corinthians',
};
// Order matters: gives each book's boundary as [thisHeader, nextHeader].
const NT_ORDER = ['Matthew', 'Mark', 'Luke', 'John', 'Acts', 'Romans', '1 Corinthians', '2 Corinthians'];

function findBoundaries(lines) {
  const boundaries = {};
  for (const book of NT_ORDER) {
    const header = HEADER_BY_BOOK[book];
    const occurrences = [];
    lines.forEach((line, i) => {
      if (line.trim() === header) occurrences.push(i);
    });
    if (occurrences.length < 2) {
      throw new Error(`expected 2 occurrences of "${header}", found ${occurrences.length}`);
    }
    boundaries[book] = occurrences[1]; // second occurrence = actual section start
  }
  return boundaries;
}

function extractBookVerses(lines, startLine, endLine) {
  const text = lines.slice(startLine, endLine).join('\n');
  const re = /(\d+):(\d+)\s/g;
  const markers = [];
  let m;
  while ((m = re.exec(text)) !== null) {
    markers.push({ chapter: +m[1], verse: +m[2], start: m.index, contentStart: re.lastIndex });
  }
  const verses = {};
  for (let i = 0; i < markers.length; i++) {
    const cur = markers[i];
    const next = markers[i + 1];
    const contentEnd = next ? next.start : text.length;
    const content = text.slice(cur.contentStart, contentEnd).replace(/\s+/g, ' ').trim();
    verses[`${cur.chapter}:${cur.verse}`] = content.length ? content.split(' ').length : 0;
  }
  return verses;
}

async function main() {
  console.log(`Fetching ${SOURCE_URL} ...`);
  const raw = await fetchText(SOURCE_URL);
  const lines = raw.split('\n');
  const boundaries = findBoundaries(lines);

  const bookOrder = NT_ORDER;
  const verseCounts = {};
  for (const book of BOOKS_NEEDED) {
    const idx = bookOrder.indexOf(book);
    const start = boundaries[book];
    const end = idx + 1 < bookOrder.length ? boundaries[bookOrder[idx + 1]] : lines.length;
    verseCounts[book] = extractBookVerses(lines, start, end);
  }

  const out = {
    source: {
      title: 'The King James Version of the Bible',
      publisher: 'Project Gutenberg',
      source_url: 'https://www.gutenberg.org/ebooks/10',
      rights_note:
        'Public domain (KJV text, and the Gutenberg edition carries no additional restrictions). ' +
        'Only integer word counts per verse are stored here, never verse text, per docs/content-rights.md §4 ' +
        '("References only — never paste Scripture text").',
      generated_by: 'bin/generate-kjv-word-counts.js',
      generated_at: new Date().toISOString().slice(0, 10),
    },
    verse_word_counts: verseCounts,
  };

  fs.writeFileSync(OUT_FILE, JSON.stringify(out, null, 2) + '\n');
  const total = Object.values(verseCounts).reduce((a, b) => a + Object.keys(b).length, 0);
  console.log(`Wrote ${path.relative(ROOT, OUT_FILE)} — ${total} verses across ${BOOKS_NEEDED.length} books.`);
}

main().catch((e) => {
  console.error(e.stack || e.message);
  process.exit(1);
});
