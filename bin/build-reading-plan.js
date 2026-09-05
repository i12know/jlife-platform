/**
 * Build a daily reading plan from the Robertson 1922 harmony (#6), balanced
 * by Scripture word count rather than section count or verse count (a
 * pericope's verses vary a lot in length; words track actual reading time
 * more closely).
 *
 * Two reading styles, since the harmony lists parallel Gospel accounts per
 * section:
 *   --style=parallel   read every parallel account listed for a section
 *                       (comparative harmony — how the Gospels compare)
 *   --style=primary    read one account per section: the longest parallel
 *                       (continuous narrative — the story once, no repeats)
 *
 * Two granularities for the atomic reading unit:
 *   --granularity=section     (default) Robertson's 185 sections
 *   --granularity=subsection  185 sections, but the 35 sections
 *                             content/harmony/subsections.json subdivides
 *                             are split into their lettered pieces instead
 *                             — finer-grained, so a plan with few days
 *                             doesn't get stuck with one huge day wherever
 *                             a section (like the Olivet Discourse) is long.
 *
 * Either way, the atomic unit is never split across days — a day gets one
 * or more whole units. Days are assigned by walking the units in
 * Robertson's order and finding the contiguous split into exactly --days
 * groups that minimizes each day's squared deviation from the average
 * (least squares) — so every day is pulled toward the target length, not
 * just the worst one.
 *
 * --from=N and --to=N (Robertson section numbers, 1-184, inclusive) restrict
 * the plan to a subrange — e.g. a Christmas-to-Easter plan that starts at
 * the Nativity and stops short of the resurrection. Defaults to the full
 * 1-184 range.
 *
 * Usage:
 *   node bin/build-reading-plan.js --style=parallel --days=184
 *   node bin/build-reading-plan.js --style=primary --days=90 --out=plan.json
 *   node bin/build-reading-plan.js --style=parallel --days=90 --granularity=subsection
 *   node bin/build-reading-plan.js --style=primary --days=94 --granularity=subsection --from=10 --to=168
 *
 * Dependency-free. Reads content/harmony/robertson-1922-outline.json,
 * content/harmony/subsections.json, and content/harmony/kjv-word-counts.json
 * (regenerate the latter with bin/generate-kjv-word-counts.js if it's
 * missing a needed book/chapter).
 */
'use strict';

const fs = require('fs');
const path = require('path');

const ROOT = path.join(__dirname, '..');
const HARMONY_FILE = path.join(ROOT, 'content', 'harmony', 'robertson-1922-outline.json');
const SUBSECTIONS_FILE = path.join(ROOT, 'content', 'harmony', 'subsections.json');
const WORD_COUNTS_FILE = path.join(ROOT, 'content', 'harmony', 'kjv-word-counts.json');

function parseArgs(argv) {
  const args = {};
  for (const arg of argv) {
    const m = /^--([^=]+)=(.*)$/.exec(arg);
    if (!m) {
      throw new Error(`unrecognized argument "${arg}" (expected --key=value)`);
    }
    args[m[1]] = m[2];
  }
  return args;
}

function usageError(msg) {
  console.error(`Error: ${msg}`);
  console.error('');
  console.error('Usage: node bin/build-reading-plan.js --style=parallel|primary --days=N [--granularity=section|subsection] [--from=N] [--to=N] [--out=path.json]');
  process.exit(1);
}

function lastVerseOfChapter(verseCounts, book, chapter) {
  let max = 0;
  for (const key of Object.keys(verseCounts[book])) {
    const [c, v] = key.split(':').map(Number);
    if (c === chapter) max = Math.max(max, v);
  }
  if (max === 0) throw new Error(`no verses found for ${book} ${chapter} in kjv-word-counts.json`);
  return max;
}

function wordsInRange(verseCounts, ref) {
  const { book } = ref;
  if (!verseCounts[book]) {
    throw new Error(`kjv-word-counts.json has no entries for "${book}" — run bin/generate-kjv-word-counts.js`);
  }
  let chStart = ref.chapter_start;
  let vStart = ref.verse_start === null ? 1 : ref.verse_start;
  let chEnd = ref.chapter_end;
  let vEnd = ref.verse_end === null ? lastVerseOfChapter(verseCounts, book, chEnd) : ref.verse_end;
  let total = 0;
  for (let ch = chStart; ch <= chEnd; ch++) {
    const vs = ch === chStart ? vStart : 1;
    const ve = ch === chEnd ? vEnd : lastVerseOfChapter(verseCounts, book, ch);
    for (let v = vs; v <= ve; v++) {
      const w = verseCounts[book][`${ch}:${v}`];
      if (w === undefined) {
        throw new Error(`missing word count for ${book} ${ch}:${v} — run bin/generate-kjv-word-counts.js`);
      }
      total += w;
    }
  }
  return total;
}

// Resolve which of a unit's scripture_refs count toward the chosen style,
// and the resulting word count for the day-planning weight. Shared by both
// whole sections and subsections since they use the same ref shape.
function wordsForStyle(refs, verseCounts, style) {
  const refWords = refs.map((r) => ({ ref: r, words: wordsInRange(verseCounts, r) }));
  let chosen;
  if (style === 'parallel') {
    chosen = refWords;
  } else {
    const maxWords = Math.max(...refWords.map((r) => r.words));
    chosen = refWords.filter((r) => r.words === maxWords).slice(0, 1);
  }
  const words = chosen.reduce((a, b) => a + b.words, 0);
  return { words, scripture_refs: chosen.map((c) => c.ref.display) };
}

// Build the ordered list of atomic reading units. In "section" granularity
// this is just the 185 Robertson events. In "subsection" granularity, the
// 35 sections content/harmony/subsections.json subdivides are replaced by
// their lettered pieces (and r1922-041's one-off override is applied),
// while every other section passes through unchanged.
function buildUnits(events, subDoc, verseCounts, style, granularity) {
  if (granularity === 'section') {
    return events.map((e) => {
      const { words, scripture_refs } = wordsForStyle(e.scripture_refs, verseCounts, style);
      return { unit_id: e.gospel_event_id, section_label: e.robertson_section, title: e.title, words, scripture_refs };
    });
  }

  const subsByParent = new Map();
  for (const s of subDoc.subsections) {
    if (!subsByParent.has(s.gospel_event_id)) subsByParent.set(s.gospel_event_id, []);
    subsByParent.get(s.gospel_event_id).push(s);
  }
  for (const list of subsByParent.values()) list.sort((a, b) => a.letter.localeCompare(b.letter));
  const overrideByParent = new Map(subDoc.overrides.map((o) => [o.gospel_event_id, o]));

  const units = [];
  for (const e of events) {
    const subs = subsByParent.get(e.gospel_event_id);
    if (subs) {
      for (const s of subs) {
        const { words, scripture_refs } = wordsForStyle(s.scripture_refs, verseCounts, style);
        units.push({ unit_id: s.subsection_id, section_label: `${e.robertson_section}${s.letter}`, title: s.title, words, scripture_refs });
      }
      continue;
    }
    const override = overrideByParent.get(e.gospel_event_id);
    const refs = override ? override.scripture_refs : e.scripture_refs;
    const { words, scripture_refs } = wordsForStyle(refs, verseCounts, style);
    units.push({ unit_id: e.gospel_event_id, section_label: e.robertson_section, title: e.title, words, scripture_refs });
  }
  return units;
}

// Split `weights` (n positive numbers, in order) into exactly `days`
// contiguous groups, minimizing the total squared deviation of each group's
// sum from the target (total / days).
//
// Least squares, not min-max: minimizing only the heaviest day leaves the
// objective blind once that day hits its floor (the heaviest indivisible
// unit), so every partition under that ceiling scores the same and a
// 20-word day can sit next to a 900-word day at "optimal" cost. Penalizing
// squared deviation pulls *every* day toward the average instead.
//
// dp[i][k] = min total cost of splitting the first i units into k groups.
// O(n^2 * days); n is a few hundred here, so this is instant.
function balancedPartition(weights, days) {
  const n = weights.length;
  const prefix = [0];
  for (const w of weights) prefix.push(prefix[prefix.length - 1] + w);
  const rangeSum = (i, j) => prefix[j] - prefix[i]; // sum of weights[i..j-1]
  const target = prefix[n] / days;

  const INF = Infinity;
  const dp = Array.from({ length: n + 1 }, () => new Array(days + 1).fill(INF));
  const choice = Array.from({ length: n + 1 }, () => new Array(days + 1).fill(-1));
  dp[0][0] = 0;
  for (let i = 1; i <= n; i++) {
    for (let k = 1; k <= days; k++) {
      for (let j = k - 1; j < i; j++) {
        if (dp[j][k - 1] === INF) continue;
        const deviation = rangeSum(j, i) - target;
        const candidate = dp[j][k - 1] + deviation * deviation;
        if (candidate < dp[i][k]) {
          dp[i][k] = candidate;
          choice[i][k] = j;
        }
      }
    }
  }
  if (dp[n][days] === INF) {
    throw new Error(`cannot split ${n} units into ${days} non-empty groups`);
  }
  // Reconstruct boundaries.
  const bounds = [];
  let i = n;
  let k = days;
  while (k > 0) {
    const j = choice[i][k];
    bounds.push([j, i]); // weights[j..i-1] is one group
    i = j;
    k--;
  }
  bounds.reverse();
  return bounds;
}

function main() {
  const args = parseArgs(process.argv.slice(2));
  if (!['parallel', 'primary'].includes(args.style)) {
    usageError('--style must be "parallel" (all Gospel parallels per section) or "primary" (longest account only)');
  }
  const days = Number(args.days);
  if (!Number.isInteger(days) || days < 1) {
    usageError('--days must be a positive integer');
  }
  const granularity = args.granularity || 'section';
  if (!['section', 'subsection'].includes(granularity)) {
    usageError('--granularity must be "section" or "subsection"');
  }

  const harmony = JSON.parse(fs.readFileSync(HARMONY_FILE, 'utf8'));
  const subDoc = JSON.parse(fs.readFileSync(SUBSECTIONS_FILE, 'utf8'));
  const wordCountsDoc = JSON.parse(fs.readFileSync(WORD_COUNTS_FILE, 'utf8'));
  const verseCounts = wordCountsDoc.verse_word_counts;

  const events = harmony.events;
  let units = buildUnits(events, subDoc, verseCounts, args.style, granularity);

  const fromSection = args.from !== undefined ? Number(args.from) : 1;
  const toSection = args.to !== undefined ? Number(args.to) : 184;
  if (!Number.isInteger(fromSection) || !Number.isInteger(toSection) || fromSection < 1 || toSection > 184 || fromSection > toSection) {
    usageError('--from and --to must be integers with 1 <= from <= to <= 184 (Robertson section numbers)');
  }
  if (args.from !== undefined || args.to !== undefined) {
    units = units.filter((u) => {
      const n = Number(/^r1922-(\d+)/.exec(u.unit_id)[1]);
      return n >= fromSection && n <= toSection;
    });
    if (units.length === 0) {
      usageError(`no ${granularity}s found in range §${fromSection}-§${toSection}`);
    }
  }

  if (days > units.length) {
    usageError(`--days=${days} exceeds ${units.length} ${granularity}s in range; a day cannot be empty and units aren't split across days`);
  }

  const weights = units.map((u) => u.words);
  const bounds = balancedPartition(weights, days);

  const plan = bounds.map(([start, end], idx) => {
    const dayUnits = units.slice(start, end);
    const totalWords = dayUnits.reduce((a, b) => a + b.words, 0);
    return {
      day: idx + 1,
      total_words: totalWords,
      sections: dayUnits.map((u) => ({
        gospel_event_id: u.unit_id,
        robertson_section: u.section_label,
        title: u.title,
        scripture_refs: u.scripture_refs,
      })),
    };
  });

  const dayTotals = plan.map((d) => d.total_words);
  const grandTotal = dayTotals.reduce((a, b) => a + b, 0);
  const maxDay = Math.max(...dayTotals);
  const minDay = Math.min(...dayTotals);
  const avgDay = grandTotal / plan.length;
  const stdev = Math.sqrt(dayTotals.reduce((a, b) => a + (b - avgDay) ** 2, 0) / plan.length);
  const heaviestUnit = Math.max(...units.map((u) => u.words));

  console.log(`Style: ${args.style === 'parallel' ? 'comparative harmony (all parallel accounts)' : 'continuous narrative (longest account per section)'}`);
  console.log(`Granularity: ${granularity}${granularity === 'subsection' ? ' (35 sections split into lettered pieces)' : ''}`);
  if (args.from !== undefined || args.to !== undefined) {
    console.log(`Range: §${fromSection}-§${toSection}`);
  }
  console.log(`Units: ${units.length}  |  Days: ${plan.length}  |  Total words: ${grandTotal}`);
  console.log(`Per-day words — avg: ${avgDay.toFixed(0)}  min: ${minDay}  max: ${maxDay}  stdev: ${stdev.toFixed(0)} (${((stdev / avgDay) * 100).toFixed(0)}% of avg)`);
  if (maxDay >= heaviestUnit) {
    console.log(`Note: the heaviest day is bounded below by one indivisible ${granularity} of ${heaviestUnit} words.`);
  }
  console.log('');
  for (const d of plan) {
    const titles = d.sections.map((s) => `§${s.robertson_section} ${s.title}`).join('; ');
    console.log(`Day ${d.day} (${d.total_words}w): ${titles}`);
  }

  if (args.out) {
    const outPath = path.resolve(process.cwd(), args.out);
    const output = {
      style: args.style,
      granularity,
      range: { from: fromSection, to: toSection },
      days: plan.length,
      total_words: grandTotal,
      source: {
        harmony: 'content/harmony/robertson-1922-outline.json',
        subsections: 'content/harmony/subsections.json',
        word_counts: 'content/harmony/kjv-word-counts.json (KJV, public domain — see docs/content-rights.md §3)',
      },
      plan,
    };
    fs.writeFileSync(outPath, JSON.stringify(output, null, 2) + '\n');
    console.log(`\nWrote ${path.relative(process.cwd(), outPath)}`);
  }
}

main();
