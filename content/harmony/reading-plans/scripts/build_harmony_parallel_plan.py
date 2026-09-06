# -*- coding: utf-8 -*-
"""
Derivative "Harmony (Robertson/SonLife) Parallel Reading Plan" that tracks
the same calendar and, as closely as possible, the same day boundaries as
the anchored LATOC/PlusNothing plan -- but reads the Master harmony's own
full parallel-Gospel scripture for each Robertson/SonLife section, not
PlusNothing's single-citation excerpts.

Resolutions (per project decision, see CHANGELOG.md):
  - Style: PARALLEL (all Gospel accounts for each section/subsection).
  - "Orphan" sections PlusNothing's dominant-match never touched are
    folded into whichever adjacent covered day is nearer in canonical
    Robertson order (ties go to the earlier/previous day).
  - Sections whose PlusNothing citations spanned multiple adjacent days
    are read once, on the FIRST day they appear -- unless doing so would
    leave another day with zero content, in which case the repeat is
    re-homed onto the day that actually needs it.

Run from the repo root (after the anchored LATOC plan and the full Master
unit list exist):
    python3 content/harmony/reading-plans/scripts/build_latoc_plan.py
    python3 content/harmony/reading-plans/scripts/build_anchored_latoc_plan.py
    node bin/build-reading-plan.js --style=parallel --days=255 \\
        --granularity=subsection \\
        --out=content/harmony/reading-plans/master_full_units.json
    python3 content/harmony/reading-plans/scripts/build_harmony_parallel_plan.py

Writes 2026-12-20to2027-04-02_harmony-parallel-reading-plan.csv into
content/harmony/reading-plans/.
"""
import json, csv, statistics
from datetime import date, timedelta

ROOT = "content/harmony/reading-plans"
ANCHORED_CSV = f"{ROOT}/2027-Jan-Mar_LATOC-anchored-reading-plan.csv"
MASTER_UNITS = f"{ROOT}/master_full_units.json"
OUT = f"{ROOT}/2026-12-20to2027-04-02_harmony-parallel-reading-plan.csv"

master = json.load(open(MASTER_UNITS))
master_plan = master['plan']  # 255 units, in canonical Robertson order
labels_in_order = [u['sections'][0]['robertson_section'] for u in master_plan]
label_index = {lbl: i for i, lbl in enumerate(labels_in_order)}
assert len(labels_in_order) == 255

# ---------- read the anchored plan's day-by-day Robertson reference column ----------
with open(ANCHORED_CSV, encoding='utf-8-sig') as f:
    anchor_rows = list(csv.DictReader(f))
NUM_DAYS = len(anchor_rows)
start = date.fromisoformat(anchor_rows[0]['Date'])
end = date.fromisoformat(anchor_rows[-1]['Date'])

day_first_labels = {}  # label -> day_idx (0-based), first occurrence only
for i, r in enumerate(anchor_rows):
    col = r['Robertson Sections (reference)']
    if col.strip() == '(no Robertson match)':
        continue
    for tok in col.split(','):
        lbl = tok.strip().lstrip('#')
        if lbl not in day_first_labels:
            day_first_labels[lbl] = i

covered = sorted(day_first_labels.keys(), key=lambda l: label_index[l])
orphans = [l for l in labels_in_order if l not in day_first_labels]

covered_by_index = sorted(((label_index[l], day_first_labels[l]) for l in covered))

def nearest_day_for_orphan(lbl):
    idx = label_index[lbl]
    prev_pair = None
    next_pair = None
    for ci, di in covered_by_index:
        if ci < idx:
            prev_pair = (ci, di)
        elif ci > idx and next_pair is None:
            next_pair = (ci, di)
            break
    if prev_pair is None:
        return next_pair[1]
    if next_pair is None:
        return prev_pair[1]
    prev_dist = idx - prev_pair[0]
    next_dist = next_pair[0] - idx
    return prev_pair[1] if prev_dist <= next_dist else next_pair[1]

label_to_day = dict(day_first_labels)
orphan_assignment = {}
for lbl in orphans:
    d = nearest_day_for_orphan(lbl)
    label_to_day[lbl] = d
    orphan_assignment[lbl] = d

assert len(label_to_day) == 255

# ---------- group master units into days ----------
def regroup():
    day_units = [[] for _ in range(NUM_DAYS)]
    for u in master_plan:
        lbl = u['sections'][0]['robertson_section']
        d = label_to_day[lbl]
        day_units[d].append(u)
    return day_units

day_units = regroup()
empty_days = [i for i, g in enumerate(day_units) if not g]

# A "first occurrence wins" duplicate resolution can strand a day whose
# ONLY Robertson label that day was a repeat of an earlier day -- that
# earlier day already has other unique content, so re-home the repeat
# onto the day that actually needs it instead of leaving it empty.
rehomed = []
for day_idx in empty_days:
    col = anchor_rows[day_idx]['Robertson Sections (reference)']
    if col.strip() == '(no Robertson match)':
        continue
    day_orig_labels = [t.strip().lstrip('#') for t in col.split(',')]
    for lbl in day_orig_labels:
        owner_day = label_to_day[lbl]
        owner_group_labels = [u['sections'][0]['robertson_section'] for u in day_units[owner_day]]
        if len(owner_group_labels) > 1:
            label_to_day[lbl] = day_idx
            rehomed.append((lbl, owner_day, day_idx))
            break

if rehomed:
    day_units = regroup()
    empty_days = [i for i, g in enumerate(day_units) if not g]
assert not empty_days, f"still empty: {empty_days}"

def ascii_safe(s):
    if s is None:
        return ''
    s = str(s)
    repl = {'—': '-', '–': '-', '‘': "'", '’': "'", '“': '"', '”': '"', '§': '#'}
    for k, v in repl.items():
        s = s.replace(k, v)
    return s

rows = []
group_words = []
for day_idx, g in enumerate(day_units):
    d = start + timedelta(days=day_idx)
    sec_ids = ', '.join(f"#{s['sections'][0]['robertson_section']}" for s in g)
    titles = ' | '.join(ascii_safe(s['sections'][0]['title']) for s in g)
    scripture = '; '.join(ascii_safe(r) for s in g for r in s['sections'][0]['scripture_refs'])
    words = sum(s['total_words'] for s in g)
    group_words.append(words)
    note = ''
    if day_idx == 4:
        note = 'Christmas Eve anchor'
    elif day_idx == 98:
        note = 'Easter Sunday anchor'
    folded = [lbl for lbl, d2 in orphan_assignment.items() if d2 == day_idx]
    if folded:
        note = (note + '; ' if note else '') + f"folded in: {', '.join('#'+l for l in folded)}"
    rehomed_here = [lbl for lbl, src, dst in rehomed if dst == day_idx]
    if rehomed_here:
        note = (note + '; ' if note else '') + f"re-homed here: {', '.join('#'+l for l in rehomed_here)}"
    pn_sections = anchor_rows[day_idx]['PlusNothing Sections']
    rows.append([d.isoformat(), day_idx + 1, sec_ids, pn_sections, titles, scripture, words, note])

if __name__ == '__main__':
    total_words = sum(group_words)
    print(f"Anchored plan: {NUM_DAYS} days, {start.isoformat()} .. {end.isoformat()}")
    print(f"Distinct labels covered (first occurrence): {len(covered)} of 255; orphans folded in: {len(orphans)}")
    if rehomed:
        print("Re-homed repeat labels to keep every day non-empty:")
        for lbl, src, dst in rehomed:
            print(f"  #{lbl}: Day {src+1} -> Day {dst+1}")
    print(f"Total Harmony (parallel) words: {total_words} over {NUM_DAYS} days, avg {total_words/NUM_DAYS:.1f}")
    print(f"Min day {min(group_words)}  Max day {max(group_words)}  Stdev {statistics.pstdev(group_words):.1f} "
          f"({statistics.pstdev(group_words)/(total_words/NUM_DAYS)*100:.1f}% of avg)")

    with open(OUT, 'w', newline='', encoding='utf-8-sig') as f:
        w = csv.writer(f)
        w.writerow(['Date', 'Day', 'Robertson Sections', 'PlusNothing Sections (reference)', 'Titles', 'Scripture (Harmony - Parallel Accounts)', 'Words', 'Notes'])
        w.writerows(rows)
    print(f"Wrote {OUT}")
