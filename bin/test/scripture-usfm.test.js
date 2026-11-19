'use strict';
// USFM reader and the repairs for the eBible.org vie1934 text (#139).

const test = require('node:test');
const assert = require('node:assert/strict');
const { parseUsfm, repairVniCapitals, repairYGrave, repairCp1258Words } = require('../content-lib/usfm');

test('USFM: verse text only, chapters and verses numbered', () => {
  const { book, verses } = parseUsfm('﻿\\id JHN\n\\h GIĂNG\n\\mt1 Giăng\n\\c 1\n\\p\n\\v 1 Ban đầu  \n\\v 2 Ngài\n\\c 2\n\\v 1 Cách\n');
  assert.equal(book, 'JHN');
  assert.deepEqual(verses, [
    { chapter: 1, verse: 1, text: 'Ban đầu' },
    { chapter: 1, verse: 2, text: 'Ngài' },
    { chapter: 2, verse: 1, text: 'Cách' },
  ]);
});

test('VNI capitals are repaired only at the start of a word', () => {
  assert.deepEqual(repairVniCapitals('Aáy là Oâng Aùp-ra-ham, Yên'), { text: 'Ấy là Ông Áp-ra-ham, Yên', count: 3 });
});

test('"ỳ" stored as a closing quote: kỳ and huỳnh, real quotes kept', () => {
  assert.deepEqual(repairYGrave('K” đã trọn, k” cuối; hu”nh; “A-men” rồi”'), { text: 'Kỳ đã trọn, kỳ cuối; huỳnh; “A-men” rồi”', count: 3 });
});

test('Windows-1258 words are re-decoded only in a damaged verse', () => {
  assert.equal(repairCp1258Words('Cha ðýõòc nên, õÒ ðâìt nhý trõÌi!').text, 'Cha được nên, ở đất như trời!');
  assert.equal(repairCp1258Words('kìa, nhãn tiền, ngõ, võ').count, 0, 'correct words untouched');
});
