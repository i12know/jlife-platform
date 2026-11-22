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
  const { linksConfigFromRegistry } = require('../build-reading-plans');
  const shipped = linksConfigFromRegistry(require('../../plugins/jlife-scripture/data/bible-versions.json'));
  const l = buildDayLinks(parseReferenceList('Luke 3:1-6; Matthew 3:4-10; Luke 3:10-18').passages, shipped);
  assert.equal(l.vi.provider, 'bible_gateway');
  assert.equal(l.vi.version, 'BPT');
  assert.equal(
    l.vi.combined.url,
    'https://www.biblegateway.com/passage/?search=Luke+3:1-6;Matthew+3:4-10;Luke+3:10-18&version=BPT',
  );
  assert.equal(l.en.combined.url, l.vi.combined.url.replace('version=BPT', 'version=ERV'));
});

test('Harmony Bible sections: once per section number, in reading order (#90)', () => {
  const { buildHarmonySections } = require('../content-lib/harmony-sections');
  const toEvent = (label) => {
    const n = label.replace(/[a-z]+$/, '');
    return { gospel_event_id: `r1922-${n.padStart(3, '0')}`, title: `Title ${n}` };
  };
  const url = 'https://harmony-bible.com/sections/{number}/';
  const day9 = buildHarmonySections(['20', '21', '22', '23'], toEvent, url);
  assert.deepEqual(day9.map((s) => s.number), ['20', '21', '22', '23']);
  assert.equal(day9[0].url, 'https://harmony-bible.com/sections/20/');
  assert.equal(day9[0].title, 'Title 20');
  const lettered = buildHarmonySections(['8b', '8c', '9', '8a', '10'], toEvent, url);
  assert.deepEqual(lettered.map((s) => s.number), ['8', '9', '10'], 'lettered parts share one section; first position wins');
  assert.deepEqual(buildHarmonySections(['999'], () => null, url), []);
});

test('shipped plan: Day 9 lists Harmony sections 20-23, each linked (#90)', () => {
  const { buildAll } = require('../build-reading-plans');
  const supp = buildAll().find((b) => b.doc.role === 'supplemental').doc;
  const day9 = supp.days.find((d) => d.day === 9);
  assert.deepEqual(day9.harmony_sections.map((s) => [s.number, s.title, s.url]), [
    ['20', 'The Time of the Beginning', 'https://harmony-bible.com/sections/20/'],
    ['21', 'The Message and the Messenger', 'https://harmony-bible.com/sections/21/'],
    ['22', "A Specimen of John's Preaching", 'https://harmony-bible.com/sections/22/'],
    ['23', "The Forerunner's Picture of the Messiah Before Seeing Him", 'https://harmony-bible.com/sections/23/'],
  ]);
  for (const d of supp.days) assert.ok(d.harmony_sections.length > 0, `${d.day_id} has Harmony sections`);
});

test('Harmony Bible overrides: Robertson section -> Harmony pages (#109)', () => {
  const { buildHarmonySections, harmonyTargets } = require('../content-lib/harmony-sections');
  const toEvent = (label) => {
    const n = label.replace(/[a-z]+$/, '');
    return { gospel_event_id: `r1922-${n.padStart(3, '0')}`, title: `Title ${n}` };
  };
  const url = 'https://harmony-bible.com/sections/{number}/';
  const overrides = { 32: ['32a', '32b'], 44: ['43b'], 93: ['95'], 95: ['94'] };
  assert.deepEqual(harmonyTargets('31', overrides), ['31'], 'no override: same number');
  assert.deepEqual(harmonyTargets('32', overrides), ['32a', '32b']);
  assert.deepEqual(harmonyTargets('toString', overrides), ['toString'], 'inherited keys are not overrides');
  const split = buildHarmonySections(['31', '32a', '32b'], toEvent, url, overrides);
  assert.deepEqual(split.map((s) => [s.number, s.title, s.url]), [
    ['31', 'Title 31', 'https://harmony-bible.com/sections/31/'],
    ['32a', 'Title 32', 'https://harmony-bible.com/sections/32a/'],
    ['32b', 'Title 32', 'https://harmony-bible.com/sections/32b/'],
  ], 'a split section links every page and keeps Robertson\'s title');
  const offset = buildHarmonySections(['93', '94', '95'], toEvent, url, overrides);
  assert.deepEqual(offset.map((s) => s.number), ['95', '94'], 'pages are listed once each; 94 has no override and its own page is unrelated to 95');
});

test('shipped plan: Day 12 Nicodemus section links both Harmony pages (#109)', () => {
  const { buildAll } = require('../build-reading-plans');
  const supp = buildAll().find((b) => b.doc.role === 'supplemental').doc;
  const day12 = supp.days.find((d) => d.day === 12);
  const nicodemus = day12.harmony_sections.filter((s) => s.gospel_event_id === 'r1922-032');
  assert.deepEqual(nicodemus.map((s) => s.url), [
    'https://harmony-bible.com/sections/32a/',
    'https://harmony-bible.com/sections/32b/',
  ]);
  assert.ok(nicodemus.every((s) => s.title === 'The Interview of Nicodemus with Jesus'));
});

test('reading-plan validator covers the override map (#109)', () => {
  const fs = require('fs');
  const path = require('path');
  const { harmonyTargets, HARMONY_SLUG } = require('../content-lib/harmony-sections');
  const manifest = JSON.parse(fs.readFileSync(path.join(__dirname, '..', '..', 'content', 'reading-plans', 'plans.json'), 'utf8'));
  const supplemental = manifest.plans.find((p) => p.role === 'supplemental');
  const overrides = supplemental.harmony_sections_overrides;
  assert.ok(overrides && Object.keys(overrides).length > 0);
  for (const [section, pages] of Object.entries(overrides)) {
    assert.deepEqual(harmonyTargets(section, overrides), pages);
    for (const p of pages) {
      const n = Number(p.match(HARMONY_SLUG)[1]);
      assert.ok(n >= 1 && n <= 184, `${section} -> ${p}`);
    }
  }
  for (const bad of ['0', '185', '32ab', 'a', '', '32-b']) assert.equal(HARMONY_SLUG.test(bad) && Number(bad.match(HARMONY_SLUG)[1]) <= 184, false, bad);
  assert.ok(HARMONY_SLUG.test('32b') && HARMONY_SLUG.test('184'));
});

test('Vietnamese titles and Protestant book names are built for every day (#119)', () => {
  const { localizeDisplay } = require('../content-lib/book-names');
  assert.equal(localizeDisplay('John 4:4-26', 'John', 'vi'), 'Giăng 4:4-26');
  assert.equal(localizeDisplay('John 11:55-12:1, 9-11', 'John', 'vi'), 'Giăng 11:55-12:1, 9-11');
  assert.equal(localizeDisplay('Acts 1:4b-8', 'Acts', 'vi'), 'Công-vụ 1:4b-8');
  assert.throws(() => localizeDisplay('Romans 1:1', 'Romans', 'vi'), /no vi name/);
  const { buildAll } = require('../build-reading-plans');
  const primary = buildAll().find((b) => b.doc.role === 'primary').doc;
  for (const d of primary.days) {
    assert.ok(d.i18n && d.i18n.vi && d.i18n.vi.title, d.day_id);
    assert.equal(d.i18n.vi.passages.length, d.passages.length, d.day_id);
  }
  const d14 = primary.days.find((d) => d.day === 14);
  assert.deepEqual(d14.i18n.vi, { title: 'Chúa Giê-xu nói chuyện với người đàn bà Sa-ma-ri', passages: ['Giăng 4:4-26'] });
  assert.equal(d14.title, 'Jesus Talks to a Samaritan Woman', 'English stays the default');
});

test('shipped primary plan: Day 1 is Luke 1:1-4 then John 1:1-18, with links in that order (#219)', () => {
  const { buildAll } = require('../build-reading-plans');
  const primary = buildAll().find((b) => b.doc.role === 'primary').doc;
  const day1 = primary.days[0];
  assert.equal(day1.day_id, 'd001');
  assert.equal(day1.date, '2026-12-20');
  assert.deepEqual(day1.passages.map((p) => p.display), ['Luke 1:1-4', 'John 1:1-18']);
  assert.deepEqual(day1.plusnothing_sections, ['1.1'], 'section identifier is preserved');
  assert.equal(day1.links.en.combined.label, 'Luke 1:1-4; John 1:1-18');
  assert.equal(
    day1.links.en.combined.url,
    'https://www.biblegateway.com/passage/?search=Luke+1:1-4;John+1:1-18&version=ERV',
  );
  assert.deepEqual(day1.links.en.passages.map((p) => p.label), ['Luke 1:1-4', 'John 1:1-18']);
  assert.deepEqual(day1.links.vi.passages.map((p) => p.label), ['Luke 1:1-4', 'John 1:1-18']);
  assert.deepEqual(day1.i18n.vi.passages, ['Lu-ca 1:1-4', 'Giăng 1:1-18']);
  assert.equal(primary.days.length, 104);
  assert.equal(day1.day, 1);
  assert.equal(primary.days[103].date, '2027-04-02');
});
