/**
 * Bible book names in other languages, for displaying references (#119).
 *
 * Vietnamese uses the traditional Protestant names shared by the 1926 Bible
 * and the Bản Phổ Thông (BPT) Easy-to-Read Version that the reader links to
 * (owner decision 2026-11-18: "Giăng 4:4-26", not the Catholic "Gioan").
 * Only books the reading plans use are listed; the build fails on a missing
 * one rather than showing an English name in a Vietnamese reference.
 */
'use strict';

const BOOK_NAMES = {
  vi: {
    Matthew: 'Ma-thi-ơ',
    Mark: 'Mác',
    Luke: 'Lu-ca',
    John: 'Giăng',
    Acts: 'Công-vụ',
  },
};

/**
 * Translate a passage display ("John 11:55-12:1, 9-11") into a language.
 * Every display names one book, followed by chapters and verses.
 *
 * @param {string} display English display.
 * @param {string} book English book name of the passage.
 * @param {string} lang Language code.
 * @returns {string}
 */
function localizeDisplay(display, book, lang) {
  const names = BOOK_NAMES[lang] || {};
  if (!Object.prototype.hasOwnProperty.call(names, book)) {
    throw new Error(`no ${lang} name for the book "${book}" (add it to bin/content-lib/book-names.js)`);
  }
  if (!display.startsWith(`${book} `)) {
    throw new Error(`"${display}" does not start with its book "${book}"`);
  }
  return `${names[book]}${display.slice(book.length)}`;
}

module.exports = { BOOK_NAMES, localizeDisplay };
