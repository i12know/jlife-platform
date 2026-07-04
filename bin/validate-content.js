/**
 * Validate portable content files against the /content/schemas contract (#5).
 *
 * Dependency-free on purpose: enforces the schema invariants directly rather
 * than running a generic JSON Schema engine (CI can add ajv against the same
 * .schema.json files in #16 — this script and those files must agree).
 *
 * Usage:
 *   node bin/validate-content.js              # validates schema examples + pilot lessons
 *   node bin/validate-content.js <file>...    # validates specific series/lesson JSON files
 *
 * Prints a PASS/FAIL line per check and exits non-zero on any failure.
 */
'use strict';

const fs = require('fs');
const path = require('path');

const ROOT = path.join(__dirname, '..');
const HARMONY = path.join(ROOT, 'content', 'harmony', 'robertson-1922-outline.json');

const TRANSLATION_STATUSES = ['draft', 'translated', 'theology-reviewed', 'language-reviewed', 'approved', 'published'];
const PHASE_MAPPING_STATUSES = ['pending', 'proposed', 'approved'];
const SERIES_ID = /^ser-[a-z0-9]+(-[a-z0-9]+)*$/;
const LESSON_ID = /^lsn-[a-z0-9]+(-[a-z0-9]+)*$/;
const EVENT_ID = /^r1922-[0-9]{3}[ab]?$/;
const ITEM_ID = /^(q|p)[0-9]{2}$/;
const LANG = /^[a-z]{2,3}(-[A-Za-z0-9]+)*$/;

const LESSON_MD_SECTIONS = ['teaching', 'outside_the_box', 'live_it_out', 'prayer_prompt', 'leader_notes'];

let failures = 0;
function check(file, name, ok, detail = '') {
  if (!ok) failures++;
  console.log(`${ok ? 'PASS' : 'FAIL'}  [${path.relative(ROOT, file)}] ${name}${detail ? ` — ${detail}` : ''}`);
}

function loadJson(file) {
  try {
    return JSON.parse(fs.readFileSync(file, 'utf8'));
  } catch (e) {
    check(file, 'parses as JSON', false, e.message);
    return null;
  }
}

function harmonyEventIds() {
  if (!fs.existsSync(HARMONY)) return null;
  const doc = JSON.parse(fs.readFileSync(HARMONY, 'utf8'));
  return new Set(doc.events.map((e) => e.gospel_event_id));
}

function isMarkdownString(v) {
  // Authored sections are Markdown strings; raw HTML tags are not allowed.
  return typeof v === 'string' && v.length > 0 && !/<\/?[a-zA-Z][^>]*>/.test(v);
}

function checkScriptureRefs(file, refs, fieldName) {
  check(file, `${fieldName} is a non-empty array`, Array.isArray(refs) && refs.length > 0);
  if (!Array.isArray(refs)) return;
  refs.forEach((ref, i) => {
    const label = `${fieldName}[${i}]`;
    const okShape =
      ref && typeof ref === 'object' &&
      typeof ref.book === 'string' && ref.book.length > 0 &&
      Number.isInteger(ref.chapter_start) && ref.chapter_start >= 1 &&
      (ref.verse_start === null || (Number.isInteger(ref.verse_start) && ref.verse_start >= 1)) &&
      Number.isInteger(ref.chapter_end) && ref.chapter_end >= 1 &&
      (ref.verse_end === null || (Number.isInteger(ref.verse_end) && ref.verse_end >= 1)) &&
      typeof ref.display === 'string' && ref.display.length > 0;
    check(file, `${label} matches the canonical ref shape (#6)`, okShape);
    if (okShape) {
      check(file, `${label} range is ordered`, ref.chapter_end >= ref.chapter_start);
      const knownKeys = ['book', 'chapter_start', 'verse_start', 'chapter_end', 'verse_end', 'display', 'deep_link'];
      const extra = Object.keys(ref).filter((k) => !knownKeys.includes(k));
      check(file, `${label} has no unknown fields`, extra.length === 0, extra.join(', '));
    }
  });
}

function validateSeries(file, doc) {
  check(file, 'schema_version is "1.0"', doc.schema_version === '1.0');
  check(file, 'series_id matches ser-<slug>', typeof doc.series_id === 'string' && SERIES_ID.test(doc.series_id));
  check(file, 'title present', typeof doc.title === 'string' && doc.title.length > 0);
  check(file, 'source_language is a BCP-47 tag', typeof doc.source_language === 'string' && LANG.test(doc.source_language));
  check(file, 'translation_status is a known workflow state', TRANSLATION_STATUSES.includes(doc.translation_status));
  check(file, 'source_attribution present (required at series level)', typeof doc.source_attribution === 'string' && doc.source_attribution.length > 0);
  check(file, 'rights_note present (required at series level)', typeof doc.rights_note === 'string' && doc.rights_note.length > 0);
  check(file, 'lessons is a non-empty ordered array of lesson_ids',
    Array.isArray(doc.lessons) && doc.lessons.length > 0 && doc.lessons.every((id) => LESSON_ID.test(id)));
  if (Array.isArray(doc.lessons)) {
    check(file, 'lesson_ids are unique', new Set(doc.lessons).size === doc.lessons.length);
  }
}

function validateLesson(file, doc, eventIds) {
  check(file, 'schema_version is "1.0"', doc.schema_version === '1.0');
  check(file, 'lesson_id matches lsn-<slug>', typeof doc.lesson_id === 'string' && LESSON_ID.test(doc.lesson_id));
  check(file, 'series_id matches ser-<slug>', typeof doc.series_id === 'string' && SERIES_ID.test(doc.series_id));
  check(file, 'order is a positive integer', Number.isInteger(doc.order) && doc.order >= 1);
  check(file, 'title present', typeof doc.title === 'string' && doc.title.length > 0);
  check(file, 'source_language is a BCP-47 tag', typeof doc.source_language === 'string' && LANG.test(doc.source_language));
  check(file, 'translation_status is a known workflow state', TRANSLATION_STATUSES.includes(doc.translation_status));

  // Rights: lesson-level values are overrides; null inherits from the series.
  check(file, 'source_attribution is a string or null (null = inherit)',
    doc.source_attribution === null || (typeof doc.source_attribution === 'string' && doc.source_attribution.length > 0));
  check(file, 'rights_note is a string or null (null = inherit)',
    doc.rights_note === null || (typeof doc.rights_note === 'string' && doc.rights_note.length > 0));

  // Gospel event linkage.
  check(file, 'primary_gospel_event_id matches r1922-NNN',
    typeof doc.primary_gospel_event_id === 'string' && EVENT_ID.test(doc.primary_gospel_event_id));
  check(file, 'related_gospel_event_ids is an array of r1922-NNN ids',
    Array.isArray(doc.related_gospel_event_ids) && doc.related_gospel_event_ids.every((id) => EVENT_ID.test(id)));
  if (eventIds) {
    const all = [doc.primary_gospel_event_id, ...(doc.related_gospel_event_ids || [])];
    for (const id of all) {
      check(file, `event ${id} exists in the harmony dataset`, eventIds.has(id));
    }
  } else {
    check(file, 'harmony dataset available for event cross-check', false, 'content/harmony/robertson-1922-outline.json missing');
  }

  // Phase posture (matches #6 / #21).
  check(file, 'phase is a string or null', doc.phase === null || typeof doc.phase === 'string');
  check(file, 'sub_phase is a string or null', doc.sub_phase === null || typeof doc.sub_phase === 'string');
  check(file, 'phase_mapping_status is pending|proposed|approved', PHASE_MAPPING_STATUSES.includes(doc.phase_mapping_status));
  if (doc.phase === null) {
    check(file, 'null phase requires phase_mapping_status "pending"', doc.phase_mapping_status === 'pending');
  } else {
    check(file, 'non-null phase requires phase_mapping_status "proposed" or "approved"',
      ['proposed', 'approved'].includes(doc.phase_mapping_status));
  }

  checkScriptureRefs(file, doc.scripture_reference, 'scripture_reference');

  for (const section of LESSON_MD_SECTIONS) {
    check(file, `${section} is Markdown (present, no raw HTML)`, isMarkdownString(doc[section]));
  }

  for (const [field, prefix] of [['reflection_questions', 'q'], ['huddle_discussion_prompts', 'p']]) {
    const items = doc[field];
    const ok = Array.isArray(items) && items.length > 0 &&
      items.every((it) => it && ITEM_ID.test(it.id) && it.id.startsWith(prefix) && isMarkdownString(it.text));
    check(file, `${field} items have stable ${prefix}NN ids and Markdown text`, ok);
    if (Array.isArray(items)) {
      check(file, `${field} ids are unique`, new Set(items.map((i) => i && i.id)).size === items.length);
    }
  }
}

function crossCheck(seriesDocs, lessonDocs) {
  for (const [file, lesson] of lessonDocs) {
    const parent = seriesDocs.find(([, s]) => s.series_id === lesson.series_id);
    if (!parent) {
      check(file, `series ${lesson.series_id} found among validated files`, false,
        'validate the series file in the same run to enable the order cross-check');
      continue;
    }
    const [seriesFile, series] = parent;
    const idx = series.lessons.indexOf(lesson.lesson_id);
    check(file, `listed in ${path.basename(seriesFile)} lessons`, idx !== -1);
    if (idx !== -1) {
      check(file, 'order agrees with position in series.lessons', lesson.order === idx + 1,
        `order=${lesson.order}, series position=${idx + 1}`);
    }
    if (lesson.source_attribution === null || lesson.rights_note === null) {
      check(file, 'inherited rights metadata resolves via series',
        typeof series.source_attribution === 'string' && typeof series.rights_note === 'string');
    }
  }
}

(function main() {
  let files = process.argv.slice(2);
  if (files.length === 0) {
    const defaults = [
      path.join(ROOT, 'content', 'schemas', 'examples'),
      path.join(ROOT, 'content', 'pilot-lessons'),
    ];
    files = defaults.flatMap((dir) =>
      fs.existsSync(dir)
        ? fs.readdirSync(dir).filter((f) => f.endsWith('.json')).map((f) => path.join(dir, f))
        : []
    );
  }
  if (files.length === 0) {
    console.log('No content files found to validate.');
    process.exit(0);
  }

  const eventIds = harmonyEventIds();
  const seriesDocs = [];
  const lessonDocs = [];

  for (const file of files.map((f) => path.resolve(f))) {
    const doc = loadJson(file);
    if (!doc) continue;
    if (typeof doc.series_id === 'string' && !('lesson_id' in doc)) {
      validateSeries(file, doc);
      seriesDocs.push([file, doc]);
    } else if ('lesson_id' in doc) {
      validateLesson(file, doc, eventIds);
      lessonDocs.push([file, doc]);
    } else {
      check(file, 'recognized as series or lesson', false, 'has neither series-only shape nor lesson_id');
    }
  }

  crossCheck(seriesDocs, lessonDocs);

  console.log(failures === 0 ? '\nAll content checks passed.' : `\n${failures} check(s) FAILED.`);
  process.exit(failures === 0 ? 0 : 1);
})();
