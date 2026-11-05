/**
 * Minimal RFC-4180 CSV parser (quoted fields may contain commas, quotes and
 * newlines). Dependency-free, shared by the reading-plan tooling.
 */
'use strict';

/**
 * Parse CSV text into an array of rows (arrays of strings).
 *
 * @param {string} text CSV text (a leading UTF-8 BOM is ignored).
 * @returns {string[][]}
 */
function parseCsv(text) {
  const src = text.charCodeAt(0) === 0xfeff ? text.slice(1) : text;
  const rows = [];
  let row = [];
  let field = '';
  let inQuotes = false;
  for (let i = 0; i < src.length; i++) {
    const c = src[i];
    if (inQuotes) {
      if (c === '"' && src[i + 1] === '"') {
        field += '"';
        i++;
      } else if (c === '"') {
        inQuotes = false;
      } else {
        field += c;
      }
    } else if (c === '"') {
      inQuotes = true;
    } else if (c === ',') {
      row.push(field);
      field = '';
    } else if (c === '\n' || c === '\r') {
      if (c === '\r' && src[i + 1] === '\n') i++;
      row.push(field);
      rows.push(row);
      row = [];
      field = '';
    } else {
      field += c;
    }
  }
  if (field !== '' || row.length > 0) {
    row.push(field);
    rows.push(row);
  }
  return rows;
}

/**
 * Parse CSV text into objects keyed by the header row. Values are kept as
 * strings exactly as written (no numeric coercion: "4.40" stays "4.40").
 *
 * @param {string} text CSV text.
 * @returns {{ header: string[], records: Object<string,string>[] }}
 */
function parseCsvRecords(text) {
  const rows = parseCsv(text).filter((r) => r.some((v) => v !== ''));
  const header = rows.shift() || [];
  const records = rows.map((r) => {
    const rec = {};
    header.forEach((h, i) => {
      rec[h] = r[i] === undefined ? '' : r[i];
    });
    return rec;
  });
  return { header, records };
}

module.exports = { parseCsv, parseCsvRecords };
