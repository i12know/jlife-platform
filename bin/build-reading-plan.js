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
 * Sections are the atomic unit: a day gets one or more whole sections,
 * never part of one, so the narrative inside a section always reads
 * together. Days are assigned by walking the 185 sections in Robertson's
 * order and finding the contiguous split into exactly --days groups that
 * minimizes the heaviest day's word count (a standard "paginate into k
 * balanced parts" DP) — the most even plan a reader could ask for without
 * breaking a section in half.
 *
 * Usage:
 *   node bin/build-reading-plan.js --style=parallel --days=184
 *   node bin/build-reading-plan.js --style=primary --days=90 --out=plan.json
 *
 * Dependency-free. Reads content/harmony/robertson-1922-outline.json and
 * content/harmony/kjv-word-counts.json (regenerate the latter with
 * bin/generate-kjv-word-counts.js if it's missing a needed book/chapter).
 */
'use strict';

const fs = require('fs');
const path = require('path');

const ROOT = path.join(__dirname, '..');
const HARMONY_FILE = path.join(ROOT, 'content', 'harmony', 'robertson-1922-outline.json');
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
  console.error('Usage: node bin/build-reading-plan.js --style=parallel|primary --days=N [--out=path.json]');
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

// For each harmony event, resolve which scripture_refs count toward the
// chosen style, and the total word count for the day-planning weight.
function annotateEvents(events, verseCounts, style) {
  return events.map((e) => {
    const refWords = e.scripture_refs.map((r) => ({ ref: r, words: wordsInRange(verseCounts, r) }));
    let chosen;
    if (style === 'parallel') {
      chosen = refWords;
    } else {
      const maxWords = Math.max(...refWords.map((r) => r.words));
      chosen = refWords.filter((r) => r.words === maxWords).slice(0, 1);
    }
    const words = chosen.reduce((a, b) => a + b.words, 0);
    return {
      gospel_event_id: e.gospel_event_id,
      robertson_section: e.robertson_section,
      title: e.title,
      words,
      scripture_refs: chosen.map((c) => c.ref.display),
    };
  });
}

// Split `weights` (n positive numbers, in order) into exactly `days`
// contiguous groups minimizing the maximum group sum. Classic DP:
// dp[i][k] = min-possible max-group-sum splitting the first i items into k
// groups. O(n^2 * days); n=185 here so this is instant.
function balancedPartition(weights, days) {
  const n = weights.length;
  const prefix = [0];
  for (const w of weights) prefix.push(prefix[prefix.length - 1] + w);
  const rangeSum = (i, j) => prefix[j] - prefix[i]; // sum of weights[i..j-1]

  const INF = Infinity;
  const dp = Array.from({ length: n + 1 }, () => new Array(days + 1).fill(INF));
  const choice = Array.from({ length: n + 1 }, () => new Array(days + 1).fill(-1));
  dp[0][0] = 0;
  for (let i = 1; i <= n; i++) {
    for (let k = 1; k <= days; k++) {
      for (let j = k - 1; j < i; j++) {
        if (dp[j][k - 1] === INF) continue;
        const candidate = Math.max(dp[j][k - 1], rangeSum(j, i));
        if (candidate < dp[i][k]) {
          dp[i][k] = candidate;
          choice[i][k] = j;
        }
      }
    }
  }
  if (dp[n][days] === INF) {
    throw new Error(`cannot split ${n} sections into ${days} non-empty groups`);
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

  const harmony = JSON.parse(fs.readFileSync(HARMONY_FILE, 'utf8'));
  const wordCountsDoc = JSON.parse(fs.readFileSync(WORD_COUNTS_FILE, 'utf8'));
  const verseCounts = wordCountsDoc.verse_word_counts;

  const events = harmony.events;
  if (days > events.length) {
    usageError(`--days=${days} exceeds ${events.length} sections; a day cannot be empty and sections aren't split across days`);
  }

  const annotated = annotateEvents(events, verseCounts, args.style);
  const weights = annotated.map((e) => e.words);
  const bounds = balancedPartition(weights, days);

  const plan = bounds.map(([start, end], idx) => {
    const daySections = annotated.slice(start, end);
    const totalWords = daySections.reduce((a, b) => a + b.words, 0);
    return {
      day: idx + 1,
      total_words: totalWords,
      sections: daySections.map((s) => ({
        gospel_event_id: s.gospel_event_id,
        robertson_section: s.robertson_section,
        title: s.title,
        scripture_refs: s.scripture_refs,
      })),
    };
  });

  const dayTotals = plan.map((d) => d.total_words);
  const grandTotal = dayTotals.reduce((a, b) => a + b, 0);
  const maxDay = Math.max(...dayTotals);
  const minDay = Math.min(...dayTotals);
  const avgDay = grandTotal / plan.length;

  console.log(`Style: ${args.style === 'parallel' ? 'comparative harmony (all parallel accounts)' : 'continuous narrative (longest account per section)'}`);
  console.log(`Sections: ${events.length}  |  Days: ${plan.length}  |  Total words: ${grandTotal}`);
  console.log(`Per-day words — avg: ${avgDay.toFixed(0)}  min: ${minDay}  max: ${maxDay}  (spread: ${(((maxDay - minDay) / avgDay) * 100).toFixed(1)}% of average)`);
  console.log('');
  for (const d of plan) {
    const titles = d.sections.map((s) => `§${s.robertson_section} ${s.title}`).join('; ');
    console.log(`Day ${d.day} (${d.total_words}w): ${titles}`);
  }

  if (args.out) {
    const outPath = path.resolve(process.cwd(), args.out);
    const output = {
      style: args.style,
      days: plan.length,
      total_words: grandTotal,
      source: {
        harmony: 'content/harmony/robertson-1922-outline.json',
        word_counts: 'content/harmony/kjv-word-counts.json (KJV, public domain — see docs/content-rights.md §3)',
      },
      plan,
    };
    fs.writeFileSync(outPath, JSON.stringify(output, null, 2) + '\n');
    console.log(`\nWrote ${path.relative(process.cwd(), outPath)}`);
  }
}

main();
