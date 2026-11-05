/**
 * Build external Bible links for a reading-plan day (#59).
 *
 * Links only; no Bible text is fetched or stored. Rules, from the owner's
 * manual link tests on #59 (2026-11-05):
 *   - Half-verse letters are dropped in links (the display keeps them).
 *   - Ranges that cross a chapter are split at the chapter boundary
 *     (Bible.com cannot open them; splitting is harmless on Bible Gateway).
 *   - Consecutive ranges that overlap in the same chapter are merged
 *     ("Matthew 14:23a; Matthew 14:23b-33" -> "Matthew 14:23-33").
 *   - Bible Gateway: one link with every passage, in order, plus one per passage.
 *   - Bible.com: one link per passage, in order.
 * Provider and translation per language come from bible-links.json.
 */
'use strict';

const { USFM, chapterLength } = require('./scripture');

/**
 * Turn parsed passages into link ranges: one chapter each, no part letters,
 * overlaps merged. Throws if a cross-chapter split needs an unknown chapter length.
 *
 * @param {{refs: object[]}[]} passages Parsed passages in schedule order.
 * @returns {{book: string, chapter: number, verse_start: number|null, verse_end: number|null}[]}
 */
function linkRanges(passages) {
  const ranges = [];
  const push = (r) => {
    const prev = ranges[ranges.length - 1];
    if (
      prev && prev.book === r.book && prev.chapter === r.chapter &&
      prev.verse_start !== null && r.verse_start !== null &&
      r.verse_start <= prev.verse_end
    ) {
      prev.verse_end = Math.max(prev.verse_end, r.verse_end);
      return;
    }
    if (prev && prev.book === r.book && prev.chapter === r.chapter && prev.verse_start === null && r.verse_start === null) {
      return;
    }
    ranges.push({ ...r });
  };

  for (const p of passages) {
    for (const s of p.refs) {
      if (s.verse_start === null) {
        for (let c = s.chapter_start; c <= s.chapter_end; c++) {
          push({ book: s.book, chapter: c, verse_start: null, verse_end: null });
        }
        continue;
      }
      if (s.chapter_start === s.chapter_end) {
        push({ book: s.book, chapter: s.chapter_start, verse_start: s.verse_start, verse_end: s.verse_end });
        continue;
      }
      for (let c = s.chapter_start; c <= s.chapter_end; c++) {
        const len = chapterLength(s.book, c);
        if (c < s.chapter_end && len === null) {
          throw new Error(`cannot split "${s.display}": verse count for ${s.book} ${c} unknown`);
        }
        const vs = c === s.chapter_start ? s.verse_start : 1;
        const ve = c === s.chapter_end ? s.verse_end : len;
        push({ book: s.book, chapter: c, verse_start: vs, verse_end: ve });
      }
    }
  }
  return ranges;
}

/** "John 3:22-36", "Mark 6:45", "John 14". */
function rangeLabel(r) {
  if (r.verse_start === null) return `${r.book} ${r.chapter}`;
  if (r.verse_start === r.verse_end) return `${r.book} ${r.chapter}:${r.verse_start}`;
  return `${r.book} ${r.chapter}:${r.verse_start}-${r.verse_end}`;
}

function bibleGatewayUrl(ranges, version) {
  const search = ranges.map((r) => rangeLabel(r).replace(/ /g, '+')).join(';');
  return `https://www.biblegateway.com/passage/?search=${search}&version=${encodeURIComponent(version)}`;
}

function bibleComUrl(r, versionId, versionCode) {
  let usfm = `${USFM[r.book]}.${r.chapter}`;
  if (r.verse_start !== null) {
    usfm += r.verse_start === r.verse_end ? `.${r.verse_start}` : `.${r.verse_start}-${r.verse_end}`;
  }
  return `https://www.bible.com/bible/${versionId}/${usfm}.${versionCode}`;
}

/**
 * Links for one day in every configured language.
 *
 * @param {{refs: object[]}[]} passages Parsed passages.
 * @param {Object<string, object>} config Parsed bible-links.json.
 * @returns {Object<string, {provider: string, version: string, combined: ?{label: string, url: string}, passages: {label: string, url: string}[]}>}
 */
function buildDayLinks(passages, config) {
  const ranges = linkRanges(passages);
  const out = {};
  for (const [lang, c] of Object.entries(config)) {
    if (c.provider === 'bible_gateway') {
      out[lang] = {
        provider: c.provider,
        version: c.version,
        combined: { label: ranges.map(rangeLabel).join('; '), url: bibleGatewayUrl(ranges, c.version) },
        passages: ranges.map((r) => ({ label: rangeLabel(r), url: bibleGatewayUrl([r], c.version) })),
      };
    } else if (c.provider === 'bible_com') {
      out[lang] = {
        provider: c.provider,
        version: c.version_code,
        combined: null,
        passages: ranges.map((r) => ({ label: rangeLabel(r), url: bibleComUrl(r, c.version_id, c.version_code) })),
      };
    } else {
      throw new Error(`unknown link provider "${c.provider}" for ${lang}`);
    }
  }
  return out;
}

module.exports = { linkRanges, rangeLabel, bibleGatewayUrl, bibleComUrl, buildDayLinks };
