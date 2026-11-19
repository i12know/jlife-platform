/**
 * Minimal USFM reader for public-domain Bible datasets (#139).
 *
 * Reads the subset eBible.org uses for plain-text Bibles: \id, \c, \v, \p and
 * heading/title markers. Returns verse text only (no headings, notes or
 * formatting). Inline character markers (\w ...\w*, \nd ...\nd*) are stripped
 * to their text; footnotes and cross references (\f ...\f*, \x ...\x*) are
 * dropped.
 */
'use strict';

/**
 * Repair the capital letters that a legacy VNI-to-Unicode conversion left in
 * the eBible.org vie1934 text: a plain capital vowel followed by the Latin-1
 * character VNI used for its marks ("Aáy" for "Ấy", "Oâng" for "Ông").
 * Only applied at the start of a word, where Vietnamese never has these pairs.
 */
const VNI_CAPITALS = {
  'Aù': 'Á', 'Aø': 'À', 'Aû': 'Ả', 'Aõ': 'Ã', 'Aï': 'Ạ',
  'Aâ': 'Â', 'Aá': 'Ấ', 'Aà': 'Ầ', 'Aå': 'Ẩ', 'Aã': 'Ẫ', 'Aä': 'Ậ',
  'Aê': 'Ă', 'Aé': 'Ắ', 'Aè': 'Ằ', 'Aú': 'Ẳ', 'Aü': 'Ẵ', 'Aë': 'Ặ',
  'Eù': 'É', 'Eø': 'È', 'Eû': 'Ẻ', 'Eõ': 'Ẽ', 'Eï': 'Ẹ',
  'Eâ': 'Ê', 'Eá': 'Ế', 'Eà': 'Ề', 'Eå': 'Ể', 'Eã': 'Ễ', 'Eä': 'Ệ',
  'Iù': 'Í', 'Iø': 'Ì', 'Iû': 'Ỉ', 'Iõ': 'Ĩ', 'Iï': 'Ị',
  'Où': 'Ó', 'Oø': 'Ò', 'Oû': 'Ỏ', 'Oõ': 'Õ', 'Oï': 'Ọ',
  'Oâ': 'Ô', 'Oá': 'Ố', 'Oà': 'Ồ', 'Oå': 'Ổ', 'Oã': 'Ỗ', 'Oä': 'Ộ',
  'Uù': 'Ú', 'Uø': 'Ù', 'Uû': 'Ủ', 'Uõ': 'Ũ', 'Uï': 'Ụ',
  'Yù': 'Ý', 'Yø': 'Ỳ', 'Yû': 'Ỷ', 'Yõ': 'Ỹ', 'Yï': 'Ỵ',
};
const VNI_RE = new RegExp(`(?<![\\p{L}\\p{M}])(${Object.keys(VNI_CAPITALS).join('|')})`, 'gu');

/**
 * @param {string} text
 * @returns {{text: string, count: number}} repaired text and how many pairs were replaced
 */
function repairVniCapitals(text) {
  let count = 0;
  const out = text.replace(VNI_RE, (m) => {
    count++;
    return VNI_CAPITALS[m];
  });
  return { text: out, count };
}

/**
 * Parse one USFM book.
 *
 * @param {string} source USFM text.
 * @returns {{book: string, verses: {chapter: number, verse: number, text: string}[]}}
 */
function parseUsfm(source) {
  const text = source.replace(/^﻿/, '').replace(/\r\n?/g, '\n');
  const idMatch = text.match(/^\\id\s+([1-4A-Z]{3})/m);
  if (!idMatch) throw new Error('no \\id marker');
  const book = idMatch[1];
  const body = text
    .replace(/\\f\s.*?\\f\*/gs, '')
    .replace(/\\x\s.*?\\x\*/gs, '')
    .replace(/\\\+?(?:w|nd|add|wj|qt|bk|k|tl|sc|it|bd|em)\s+(.*?)(?:\|[^\\]*)?\\\+?(?:w|nd|add|wj|qt|bk|k|tl|sc|it|bd|em)\*/gs, '$1');
  const verses = [];
  let chapter = 0;
  let current = null;
  const flush = () => {
    if (current) {
      current.text = current.text.replace(/\s+/g, ' ').trim();
      if (current.text) verses.push(current);
    }
    current = null;
  };
  for (const raw of body.split('\n')) {
    const line = raw.trim();
    if (!line) continue;
    let m;
    if ((m = line.match(/^\\c\s+(\d+)/))) {
      flush();
      chapter = Number(m[1]);
      continue;
    }
    if ((m = line.match(/^\\v\s+(\d+)\s*(.*)$/))) {
      flush();
      if (!chapter) throw new Error(`${book}: verse before a chapter`);
      current = { chapter, verse: Number(m[1]), text: m[2] };
      continue;
    }
    if ((m = line.match(/^\\(?:p|m|q\d?|pi\d?|nb|b|li\d?)\b\s*(.*)$/))) {
      if (current && m[1]) current.text += ` ${m[1]}`;
      continue;
    }
    if (/^\\/.test(line)) {
      // Headings, titles, \id, \h, \toc: not verse text.
      if (/^\\(?:s\d?|ms\d?|mt\d?|mr|r|d|sp|h|toc\d|id|ide|rem|cl|cp)\b/.test(line)) continue;
      throw new Error(`${book}: unsupported marker in "${line.slice(0, 20)}"`);
    }
    if (current) current.text += ` ${line}`;
  }
  flush();
  return { book, verses };
}

module.exports = { parseUsfm, repairVniCapitals, VNI_CAPITALS };
