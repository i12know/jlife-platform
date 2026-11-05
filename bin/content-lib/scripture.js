/**
 * Scripture reference parsing for reading plans (#59).
 *
 * Parses the reference strings used in the 104-day schedules (PR #50) into
 * the repo's canonical reference shape (content/schemas/scripture-ref.schema.json)
 * plus optional half-verse parts. References only; never Bible text.
 *
 * Supported forms (all observed in the schedules):
 *   John 1:1-18          Mark 6:45            John 14 (whole chapter)
 *   Luke 3:23a           Matthew 4:13b-17a    John 3:22-4:3 (crosses a chapter)
 *   John 15:18-16:4b     John 11:55-12:1, 9-11 (comma continues the last chapter)
 *   Luke 24:10b, d       (comma adds another part of the same verse)
 */
'use strict';

/** USFM book codes (used by Bible.com links), keyed by English book name. */
const USFM = {
  Genesis: 'GEN', Exodus: 'EXO', Leviticus: 'LEV', Numbers: 'NUM', Deuteronomy: 'DEU',
  Joshua: 'JOS', Judges: 'JDG', Ruth: 'RUT', '1 Samuel': '1SA', '2 Samuel': '2SA',
  '1 Kings': '1KI', '2 Kings': '2KI', '1 Chronicles': '1CH', '2 Chronicles': '2CH',
  Ezra: 'EZR', Nehemiah: 'NEH', Esther: 'EST', Job: 'JOB', Psalms: 'PSA', Proverbs: 'PRO',
  Ecclesiastes: 'ECC', 'Song of Songs': 'SNG', Isaiah: 'ISA', Jeremiah: 'JER',
  Lamentations: 'LAM', Ezekiel: 'EZK', Daniel: 'DAN', Hosea: 'HOS', Joel: 'JOL',
  Amos: 'AMO', Obadiah: 'OBA', Jonah: 'JON', Micah: 'MIC', Nahum: 'NAM', Habakkuk: 'HAB',
  Zephaniah: 'ZEP', Haggai: 'HAG', Zechariah: 'ZEC', Malachi: 'MAL',
  Matthew: 'MAT', Mark: 'MRK', Luke: 'LUK', John: 'JHN', Acts: 'ACT', Romans: 'ROM',
  '1 Corinthians': '1CO', '2 Corinthians': '2CO', Galatians: 'GAL', Ephesians: 'EPH',
  Philippians: 'PHP', Colossians: 'COL', '1 Thessalonians': '1TH', '2 Thessalonians': '2TH',
  '1 Timothy': '1TI', '2 Timothy': '2TI', Titus: 'TIT', Philemon: 'PHM', Hebrews: 'HEB',
  James: 'JAS', '1 Peter': '1PE', '2 Peter': '2PE', '1 John': '1JN', '2 John': '2JN',
  '3 John': '3JN', Jude: 'JUD', Revelation: 'REV',
};

/**
 * Verses per chapter (standard English versification) for the books whose
 * chapter boundaries a split may need. A link that must split a range in a
 * book missing here fails validation rather than guessing.
 */
const VERSES_PER_CHAPTER = {
  Matthew: [25, 23, 17, 25, 48, 34, 29, 34, 38, 42, 30, 50, 58, 36, 39, 28, 27, 35, 30, 34, 46, 46, 39, 51, 46, 75, 66, 20],
  Mark: [45, 28, 35, 41, 43, 56, 37, 38, 50, 52, 33, 44, 37, 72, 47, 20],
  Luke: [80, 52, 38, 44, 39, 49, 50, 56, 62, 42, 54, 59, 35, 35, 32, 31, 37, 43, 48, 47, 38, 71, 56, 53],
  John: [51, 25, 36, 54, 47, 71, 53, 59, 41, 42, 57, 50, 38, 31, 27, 33, 26, 40, 42, 31, 25],
};

const BOOK_RE = /^((?:[1-3] )?[A-Z][a-z]+(?: of [A-Z][a-z]+)?)\s+(.+)$/;
const VERSE_RE = /^(\d+)([a-z])?$/;

/** @returns {number|null} verses in a chapter, or null if unknown. */
function chapterLength(book, chapter) {
  const table = VERSES_PER_CHAPTER[book];
  return table && chapter >= 1 && chapter <= table.length ? table[chapter - 1] : null;
}

/** Canonical display for one parsed segment. */
function formatSegment(s) {
  const v = (n, p) => `${n}${p || ''}`;
  if (s.verse_start === null) {
    return s.chapter_start === s.chapter_end
      ? `${s.book} ${s.chapter_start}`
      : `${s.book} ${s.chapter_start}-${s.chapter_end}`;
  }
  const start = `${s.chapter_start}:${v(s.verse_start, s.verse_start_part)}`;
  if (s.chapter_start === s.chapter_end && s.verse_start === s.verse_end && s.verse_start_part === s.verse_end_part) {
    return `${s.book} ${start}`;
  }
  const end = s.chapter_start === s.chapter_end
    ? v(s.verse_end, s.verse_end_part)
    : `${s.chapter_end}:${v(s.verse_end, s.verse_end_part)}`;
  return `${s.book} ${start}-${end}`;
}

function segment(book, cs, vs, vsp, ce, ve, vep) {
  const s = {
    book,
    chapter_start: cs,
    verse_start: vs,
    verse_start_part: vsp || null,
    chapter_end: ce,
    verse_end: ve,
    verse_end_part: vep || null,
  };
  s.display = formatSegment(s);
  return s;
}

/**
 * Parse one reference string into segments.
 *
 * @param {string} input e.g. "John 11:55-12:1, 9-11"
 * @returns {{ ok: true, segments: object[] } | { ok: false, error: string }}
 */
function parseReference(input) {
  const text = String(input).trim();
  const m = text.match(BOOK_RE);
  if (!m) return { ok: false, error: `no book name in "${text}"` };
  const book = m[1];
  if (!USFM[book]) return { ok: false, error: `unknown book "${book}"` };

  const parts = m[2].split(/\s*,\s*/);
  const segments = [];
  let chapter = null;
  let lastVerse = null;

  for (let i = 0; i < parts.length; i++) {
    const p = parts[i];
    let mm;
    if (i === 0 && (mm = p.match(/^(\d+)$/))) {
      // Whole chapter: "John 14".
      const c = Number(mm[1]);
      segments.push(segment(book, c, null, null, c, null, null));
      chapter = c;
      continue;
    }
    if ((mm = p.match(/^(\d+):(\d+[a-z]?)(?:-(?:(\d+):)?(\d+[a-z]?))?$/))) {
      // "C:V", "C:V-V", "C:V-C:V" (each verse may carry a part letter).
      const cs = Number(mm[1]);
      const [, vs, vsp] = mm[2].match(VERSE_RE);
      const ce = mm[3] ? Number(mm[3]) : cs;
      const end = mm[4] ? mm[4].match(VERSE_RE) : null;
      const ve = end ? Number(end[1]) : Number(vs);
      const vep = end ? end[2] : vsp;
      segments.push(segment(book, cs, Number(vs), vsp, ce, ve, vep));
      chapter = ce;
      lastVerse = ve;
      continue;
    }
    if (i > 0 && chapter !== null && (mm = p.match(/^(\d+[a-z]?)(?:-(\d+[a-z]?))?$/))) {
      // Continuation in the last chapter: ", 9-11".
      const [, vs, vsp] = mm[1].match(VERSE_RE);
      const end = mm[2] ? mm[2].match(VERSE_RE) : null;
      segments.push(segment(book, chapter, Number(vs), vsp, chapter, end ? Number(end[1]) : Number(vs), end ? end[2] : vsp));
      lastVerse = end ? Number(end[1]) : Number(vs);
      continue;
    }
    if (i > 0 && lastVerse !== null && (mm = p.match(/^([a-z])$/))) {
      // Another part of the same verse: "Luke 24:10b, d".
      segments.push(segment(book, chapter, lastVerse, mm[1], chapter, lastVerse, mm[1]));
      continue;
    }
    return { ok: false, error: `cannot parse "${p}" in "${text}"` };
  }

  for (const s of segments) {
    const err = checkSegment(s);
    if (err) return { ok: false, error: `${err} in "${text}"` };
  }
  return { ok: true, segments };
}

/** @returns {string|null} a problem with the segment's numbers, if any. */
function checkSegment(s) {
  if (s.chapter_end < s.chapter_start) return 'chapter range runs backwards';
  if (s.verse_start !== null && s.chapter_start === s.chapter_end && s.verse_end < s.verse_start) {
    return 'verse range runs backwards';
  }
  for (const [c, v] of [[s.chapter_start, s.verse_start], [s.chapter_end, s.verse_end]]) {
    if (VERSES_PER_CHAPTER[s.book]) {
      const len = chapterLength(s.book, c);
      if (len === null) return `${s.book} has no chapter ${c}`;
      if (v !== null && v > len) return `${s.book} ${c} has only ${len} verses`;
    }
  }
  return null;
}

/**
 * Parse a semicolon-separated list of references (one schedule cell).
 *
 * @param {string} cell e.g. "Mark 6:30-37; John 6:8-15a"
 * @returns {{ ok: true, passages: {display: string, refs: object[]}[] } | { ok: false, error: string }}
 */
function parseReferenceList(cell) {
  const passages = [];
  for (const raw of String(cell).split(';')) {
    const display = raw.trim();
    if (!display) continue;
    const r = parseReference(display);
    if (!r.ok) return r;
    passages.push({ display, refs: r.segments });
  }
  if (passages.length === 0) return { ok: false, error: 'no references' };
  return { ok: true, passages };
}

module.exports = { USFM, VERSES_PER_CHAPTER, chapterLength, parseReference, parseReferenceList, formatSegment };
