/**
 * Unit tests for reading-plan parsing and Bible links (#59).
 * Run: npm run test:js   (Node's built-in test runner; no dependencies)
 */
'use strict';

const test = require('node:test');
const assert = require('node:assert/strict');
const { parseReference, parseReferenceList, VERSES_PER_CHAPTER } = require('../content-lib/scripture');
const { linkRanges, buildDayLinks } = require('../content-lib/bible-links');
const { parseCsvRecords } = require('../content-lib/csv');

const CONFIG = {
  en: { provider: 'bible_gateway', version: 'ERV' },
  vi: { provider: 'bible_com', version_id: 151, version_code: 'VIE2010' },
};
const links = (cell) => buildDayLinks(parseReferenceList(cell).passages, CONFIG);

test('simple range, single verse and whole chapter', () => {
  assert.deepEqual(parseReference('John 1:1-18').segments[0], {
    book: 'John', chapter_start: 1, verse_start: 1, verse_start_part: null,
    chapter_end: 1, verse_end: 18, verse_end_part: null, display: 'John 1:1-18',
  });
  assert.equal(parseReference('Mark 6:45').segments[0].display, 'Mark 6:45');
  const whole = parseReference('John 14').segments[0];
  assert.equal(whole.verse_start, null);
  assert.equal(whole.display, 'John 14');
});

test('half-verse parts are kept for display', () => {
  const s = parseReference('Matthew 4:13b-17a').segments[0];
  assert.equal(s.verse_start_part, 'b');
  assert.equal(s.verse_end_part, 'a');
  assert.equal(s.display, 'Matthew 4:13b-17a');
  assert.equal(parseReference('Luke 3:23a').segments[0].display, 'Luke 3:23a');
});

test('comma forms: continuation in the last chapter, and another part of a verse', () => {
  const a = parseReference('John 11:55-12:1, 9-11').segments.map((s) => s.display);
  assert.deepEqual(a, ['John 11:55-12:1', 'John 12:9-11']);
  const b = parseReference('Luke 24:10b, d').segments.map((s) => s.display);
  assert.deepEqual(b, ['Luke 24:10b', 'Luke 24:10d']);
});

test('numbered books and unknown/invalid references', () => {
  assert.equal(parseReference('1 Corinthians 15:5').segments[0].book, '1 Corinthians');
  assert.equal(parseReference('Hezekiah 1:1').ok, false);
  assert.equal(parseReference('John 22:1').ok, false, 'John has 21 chapters');
  assert.equal(parseReference('Mark 6:99').ok, false, 'Mark 6 has 56 verses');
  assert.equal(parseReference('John 1:18-1').ok, false, 'backwards range');
});

test('versification table totals match the standard Gospel verse counts', () => {
  const sum = (b) => VERSES_PER_CHAPTER[b].reduce((x, y) => x + y, 0);
  assert.equal(sum('Matthew'), 1071);
  assert.equal(sum('Mark'), 678);
  assert.equal(sum('Luke'), 1151);
  assert.equal(sum('John'), 879);
});

test('Day 38 English link equals the link the owner tested on #59', () => {
  const l = links('Mark 6:30-37; John 6:8-15a; Mark 6:45; Matthew 14:23a; Matthew 14:23b-33; Mark 6:53-56');
  assert.equal(
    l.en.combined.url,
    'https://www.biblegateway.com/passage/?search=Mark+6:30-37;John+6:8-15;Mark+6:45;Matthew+14:23-33;Mark+6:53-56&version=ERV',
  );
  assert.equal(l.vi.combined, null);
  assert.equal(l.vi.passages.length, 5, 'half-verse parts of Matthew 14:23 merge into one passage');
});

test('Day 1 Bible.com links equal the tested links', () => {
  const l = links('John 1:1-18');
  assert.equal(l.vi.passages[0].url, 'https://www.bible.com/bible/151/JHN.1.1-18.VIE2010');
  assert.equal(l.en.combined.url, 'https://www.biblegateway.com/passage/?search=John+1:1-18&version=ERV');
});

test('chapter-crossing ranges split at the chapter boundary (Bible.com 404s otherwise)', () => {
  const l = links('John 3:22-4:3');
  assert.deepEqual(l.vi.passages.map((p) => p.url), [
    'https://www.bible.com/bible/151/JHN.3.22-36.VIE2010',
    'https://www.bible.com/bible/151/JHN.4.1-3.VIE2010',
  ]);
  const comma = linkRanges(parseReferenceList('John 11:55-12:1, 9-11').passages);
  assert.deepEqual(comma.map((r) => [r.chapter, r.verse_start, r.verse_end]), [[11, 55, 57], [12, 1, 1], [12, 9, 11]]);
});

test('whole-chapter and single-verse Bible.com links', () => {
  const l = links('John 14; Mark 6:45');
  assert.deepEqual(l.vi.passages.map((p) => p.url), [
    'https://www.bible.com/bible/151/JHN.14.VIE2010',
    'https://www.bible.com/bible/151/MRK.6.45.VIE2010',
  ]);
});

test('CSV parser keeps section IDs as strings and handles quoted commas', () => {
  const { records } = parseCsvRecords('﻿,Day,Sections\n0,53,4.40\n1,3,"1.3, 1.4"\n');
  assert.equal(records[0].Sections, '4.40');
  assert.equal(records[1].Sections, '1.3, 1.4');
});

test('shipped config: Vietnamese uses one Bible Gateway BPT link, like English ERV (#91)', () => {
  const shipped = require('../../content/reading-plans/bible-links.json');
  const l = buildDayLinks(parseReferenceList('Luke 3:1-6; Matthew 3:4-10; Luke 3:10-18').passages, shipped);
  assert.equal(l.vi.provider, 'bible_gateway');
  assert.equal(l.vi.version, 'BPT');
  assert.equal(
    l.vi.combined.url,
    'https://www.biblegateway.com/passage/?search=Luke+3:1-6;Matthew+3:4-10;Luke+3:10-18&version=BPT',
  );
  assert.equal(l.en.combined.url, l.vi.combined.url.replace('version=BPT', 'version=ERV'));
});
