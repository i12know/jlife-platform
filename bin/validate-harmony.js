/**
 * Validate the Robertson 1922 harmony dataset (#6).
 *
 * Checks the JSON invariants (185 events, unique r1922-NNN ids, refs present,
 * phase posture) and that the review CSV is in sync with the JSON (same ids,
 * same order, same titles and ref displays).
 *
 * Dependency-free. Exits non-zero on any failure.
 */
'use strict';

const fs = require('fs');
const path = require('path');

const ROOT = path.join(__dirname, '..');
const JSON_FILE = path.join(ROOT, 'content', 'harmony', 'robertson-1922-outline.json');
const CSV_FILE = path.join(ROOT, 'content', 'harmony', 'robertson-1922-outline.review.csv');

const EVENT_ID = /^r1922-[0-9]{3}[ab]?$/;
const PHASE_MAPPING_STATUSES = ['pending', 'proposed', 'approved'];

let failures = 0;
function check(name, ok, detail = '') {
  if (!ok) failures++;
  console.log(`${ok ? 'PASS' : 'FAIL'}  ${name}${detail ? ` — ${detail}` : ''}`);
}

// Minimal RFC-4180 CSV parser (fields may be quoted and contain commas/newlines).
function parseCsv(text) {
  const rows = [];
  let row = [];
  let field = '';
  let inQuotes = false;
  for (let i = 0; i < text.length; i++) {
    const c = text[i];
    if (inQuotes) {
      if (c === '"' && text[i + 1] === '"') { field += '"'; i++; }
      else if (c === '"') { inQuotes = false; }
      else { field += c; }
    } else if (c === '"') {
      inQuotes = true;
    } else if (c === ',') {
      row.push(field); field = '';
    } else if (c === '\n' || c === '\r') {
      if (c === '\r' && text[i + 1] === '\n') i++;
      row.push(field); field = '';
      rows.push(row); row = [];
    } else {
      field += c;
    }
  }
  if (field.length > 0 || row.length > 0) { row.push(field); rows.push(row); }
  return rows;
}

(function main() {
  const doc = JSON.parse(fs.readFileSync(JSON_FILE, 'utf8'));
  const events = doc.events;

  check('source attribution block present',
    doc.source && doc.source.title && doc.source.year === 1922 && doc.source.source_url && doc.source.rights_note);
  check('185 events (184 Robertson sections + §128a/§128b split)', events.length === 185, `got ${events.length}`);

  const ids = events.map((e) => e.gospel_event_id);
  check('all gospel_event_ids unique', new Set(ids).size === ids.length);
  check('all ids match r1922-NNN[ab]', ids.every((id) => EVENT_ID.test(id)));

  for (const ev of events) {
    if (!Array.isArray(ev.scripture_refs) || ev.scripture_refs.length === 0) {
      check(`${ev.gospel_event_id} has scripture refs`, false);
    }
    for (const ref of ev.scripture_refs || []) {
      const ok = typeof ref.book === 'string' && Number.isInteger(ref.chapter_start) &&
        (ref.verse_start === null || Number.isInteger(ref.verse_start)) &&
        Number.isInteger(ref.chapter_end) &&
        (ref.verse_end === null || Number.isInteger(ref.verse_end)) &&
        typeof ref.display === 'string';
      if (!ok) check(`${ev.gospel_event_id} ref shape`, false, JSON.stringify(ref));
    }
    if (!(ev.phase === null || typeof ev.phase === 'string') ||
      !PHASE_MAPPING_STATUSES.includes(ev.phase_mapping_status) ||
      (ev.phase === null && ev.phase_mapping_status !== 'pending' && ev.phase_mapping_status !== 'proposed')) {
      check(`${ev.gospel_event_id} phase posture valid`, false);
    }
  }
  check('every event has ≥1 scripture ref', events.every((e) => Array.isArray(e.scripture_refs) && e.scripture_refs.length > 0));
  check('parts run 1–14 in order', events.every((e, i) => e.part >= 1 && e.part <= 14 && (i === 0 || e.part >= events[i - 1].part)));

  // CSV ↔ JSON sync.
  const rows = parseCsv(fs.readFileSync(CSV_FILE, 'utf8')).filter((r) => r.length > 1);
  const header = rows.shift();
  const col = (name) => header.indexOf(name);
  check('CSV header has expected columns',
    ['gospel_event_id', 'robertson_section', 'title', 'scripture_refs', 'phase_mapping_status'].every((c) => col(c) !== -1));
  check('CSV row count matches JSON event count', rows.length === events.length, `csv=${rows.length}, json=${events.length}`);

  const n = Math.min(rows.length, events.length);
  let synced = true;
  for (let i = 0; i < n; i++) {
    const ev = events[i];
    const row = rows[i];
    const refsDisplay = ev.scripture_refs.map((r) => r.display).join('; ');
    if (row[col('gospel_event_id')] !== ev.gospel_event_id ||
      row[col('title')] !== ev.title ||
      row[col('scripture_refs')] !== refsDisplay) {
      synced = false;
      check(`CSV row ${i + 2} matches JSON ${ev.gospel_event_id}`, false);
    }
  }
  check('CSV content in sync with JSON (ids, order, titles, refs)', synced);

  console.log(failures === 0 ? '\nHarmony dataset checks passed.' : `\n${failures} check(s) FAILED.`);
  process.exit(failures === 0 ? 0 : 1);
})();
