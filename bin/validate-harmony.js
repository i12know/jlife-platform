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
const WORD_COUNTS_FILE = path.join(ROOT, 'content', 'harmony', 'kjv-word-counts.json');
const SUBSECTIONS_FILE = path.join(ROOT, 'content', 'harmony', 'subsections.json');

const EVENT_ID = /^r1922-[0-9]{3}[ab]?$/;
const SUBSECTION_ID = /^r1922-[0-9]{3}[a-z]$/;
const SUBSECTION_SOURCES = ['thomas-gundry', 'sonlife'];
const PHASE_MAPPING_STATUSES = ['pending', 'proposed', 'approved'];

let failures = 0;
function check(name, ok, detail = '') {
  if (!ok) failures++;
  console.log(`${ok ? 'PASS' : 'FAIL'}  ${name}${detail ? ` — ${detail}` : ''}`);
}

function refShapeOk(ref) {
  return ref && typeof ref === 'object' &&
    typeof ref.book === 'string' && Number.isInteger(ref.chapter_start) &&
    (ref.verse_start === null || Number.isInteger(ref.verse_start)) &&
    Number.isInteger(ref.chapter_end) &&
    (ref.verse_end === null || Number.isInteger(ref.verse_end)) &&
    typeof ref.display === 'string';
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
      if (!refShapeOk(ref)) check(`${ev.gospel_event_id} ref shape`, false, JSON.stringify(ref));
    }
    // Phase posture (issue #21 workflow): null phase means unreviewed
    // (status "pending") — unless the mapper deliberately left a
    // non-narrative section unmapped, which #21 requires a note for.
    // A non-null phase means the mapping workflow has run, so the status
    // must be "proposed" or "approved", never "pending".
    const status = ev.phase_mapping_status;
    let posture = PHASE_MAPPING_STATUSES.includes(status);
    if (posture) {
      const hasNote = typeof ev.notes === 'string' && ev.notes.trim().length > 0;
      if (ev.phase === null) {
        posture = status === 'pending' || hasNote;
      } else if (typeof ev.phase === 'string') {
        posture = status === 'proposed' || status === 'approved';
      } else {
        posture = false;
      }
    }
    if (!posture) {
      check(`${ev.gospel_event_id} phase posture valid`, false,
        `phase=${JSON.stringify(ev.phase)}, status=${JSON.stringify(status)}, null phase needs "pending" (or a note per #21); non-null needs "proposed"/"approved"`);
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

  // bin/build-reading-plan.js needs a word count for every verse any event
  // references — catch a gap here rather than at reading-plan build time.
  const wordCountsDoc = JSON.parse(fs.readFileSync(WORD_COUNTS_FILE, 'utf8'));
  const verseCounts = wordCountsDoc.verse_word_counts;
  function lastVerseOfChapter(book, chapter) {
    let max = 0;
    for (const key of Object.keys(verseCounts[book] || {})) {
      const [c, v] = key.split(':').map(Number);
      if (c === chapter) max = Math.max(max, v);
    }
    return max;
  }
  function missingVerseCoverage(label, refs, missing) {
    for (const ref of refs || []) {
      if (!verseCounts[ref.book]) { missing.push(`${label}: no book "${ref.book}"`); continue; }
      const vStart = ref.verse_start === null ? 1 : ref.verse_start;
      const vEnd = ref.verse_end === null ? lastVerseOfChapter(ref.book, ref.chapter_end) : ref.verse_end;
      for (let ch = ref.chapter_start; ch <= ref.chapter_end; ch++) {
        const vs = ch === ref.chapter_start ? vStart : 1;
        const ve = ch === ref.chapter_end ? vEnd : lastVerseOfChapter(ref.book, ch);
        for (let v = vs; v <= ve; v++) {
          if (verseCounts[ref.book][`${ch}:${v}`] === undefined) {
            missing.push(`${label}: ${ref.book} ${ch}:${v}`);
          }
        }
      }
    }
  }
  const missing = [];
  for (const ev of events) missingVerseCoverage(ev.gospel_event_id, ev.scripture_refs, missing);
  check('kjv-word-counts.json covers every scripture_ref verse (bin/build-reading-plan.js input)',
    missing.length === 0, missing.length === 0 ? '' : `${missing.length} missing, e.g. ${missing.slice(0, 5).join('; ')} — run bin/generate-kjv-word-counts.js`);

  // content/harmony/subsections.json (#T&G subdivisions overlay) — additive on
  // top of the untouched Robertson dataset above, so validate it separately.
  const eventIds = new Set(ids);
  const subDoc = JSON.parse(fs.readFileSync(SUBSECTIONS_FILE, 'utf8'));
  check('subsections.json source attribution block present',
    subDoc.source && subDoc.source.title && subDoc.source.rights_note);

  const subIds = subDoc.subsections.map((s) => s.subsection_id);
  check('all subsection_ids unique', new Set(subIds).size === subIds.length);

  const missingSub = [];
  const byParent = {};
  let subShapeOk = true;
  for (const s of subDoc.subsections) {
    const idOk = SUBSECTION_ID.test(s.subsection_id) && s.subsection_id === `${s.gospel_event_id}${s.letter}`;
    const parentOk = eventIds.has(s.gospel_event_id);
    const refsOk = Array.isArray(s.scripture_refs) && s.scripture_refs.length > 0 && s.scripture_refs.every(refShapeOk);
    const wordsOk = typeof s.words === 'number' && s.words >= 0;
    const titleOk = typeof s.title === 'string' && s.title.length > 0;
    const sourceOk = SUBSECTION_SOURCES.includes(s.subsection_source);
    if (!(idOk && parentOk && refsOk && wordsOk && titleOk && sourceOk)) {
      subShapeOk = false;
      check(`${s.subsection_id || '(missing id)'} shape valid`, false,
        `id=${idOk} parent=${parentOk} refs=${refsOk} words=${wordsOk} title=${titleOk} source=${sourceOk}`);
    }
    (byParent[s.gospel_event_id] = byParent[s.gospel_event_id] || []).push(s.letter);
    missingVerseCoverage(s.subsection_id, s.scripture_refs, missingSub);
  }
  check('every subsection has valid shape (id/parent/refs/words/title/source)', subShapeOk);

  let letterSequenceOk = true;
  for (const [parent, letters] of Object.entries(byParent)) {
    const sorted = [...letters].sort();
    const expected = sorted.map((_, i) => String.fromCharCode(97 + i));
    if (JSON.stringify(sorted) !== JSON.stringify(expected)) {
      letterSequenceOk = false;
      check(`${parent} subsection letters contiguous from 'a'`, false, `got [${sorted.join(',')}]`);
    }
  }
  check("every split parent's subsection letters run a, b, c, ... with no gaps", letterSequenceOk);

  let overridesOk = true;
  for (const o of subDoc.overrides || []) {
    const parentOk = eventIds.has(o.gospel_event_id);
    const refsOk = Array.isArray(o.scripture_refs) && o.scripture_refs.length > 0 && o.scripture_refs.every(refShapeOk);
    const noteOk = typeof o.note === 'string' && o.note.length > 0;
    const sourceOk = SUBSECTION_SOURCES.includes(o.subsection_source);
    if (!(parentOk && refsOk && noteOk && sourceOk)) {
      overridesOk = false;
      check(`override ${o.gospel_event_id || '(missing id)'} shape valid`, false,
        `parent=${parentOk} refs=${refsOk} note=${noteOk} source=${sourceOk}`);
    } else {
      missingVerseCoverage(`override ${o.gospel_event_id}`, o.scripture_refs, missingSub);
    }
  }
  check('every override has valid shape (parent/refs/note/source)', overridesOk);
  check('kjv-word-counts.json covers every subsections.json verse',
    missingSub.length === 0, missingSub.length === 0 ? '' : `${missingSub.length} missing, e.g. ${missingSub.slice(0, 5).join('; ')}`);

  console.log(failures === 0 ? '\nHarmony dataset checks passed.' : `\n${failures} check(s) FAILED.`);
  process.exit(failures === 0 ? 0 : 1);
})();
