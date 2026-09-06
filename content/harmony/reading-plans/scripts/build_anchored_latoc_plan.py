# -*- coding: utf-8 -*-
"""
Anchored LATOC (Life and Teachings of Christ, Plus Nothing) reading plan,
Dec 20, 2026 - onward, following PlusNothing's own 239-section sequence
(not Robertson's canonical order).

Two calendar anchors are held fixed:
  - Christmas Eve (Dec 24, 2026) ends with PlusNothing #1.8 "The Birth of Jesus"
  - Easter Sunday (Mar 28, 2027) ends with PlusNothing #6.1 "The Resurrection of Jesus"

The 239-unit sequence (built by build_latoc_plan.py) is cut into 3 fixed
segments at those two anchor points. Segments 1 and 2 (Dec 20 -> Dec 24,
and Dec 25 -> Mar 28) have a day count fixed by the calendar. Segment 3
(the post-Easter Ascension/Pentecost tail) is NOT crammed into a fixed
Mar 31 end date -- its day count is instead derived from the same
words/day pace as segment 2, so the plan naturally runs a few days past
Mar 31 instead of compressing the tail. Each segment is independently
least-squares balanced across its own days.

Run from the repo root (after build_latoc_plan.py has produced
latoc_units.json):
    python3 content/harmony/reading-plans/scripts/build_latoc_plan.py
    python3 content/harmony/reading-plans/scripts/build_anchored_latoc_plan.py

Writes 2027-Jan-Mar_LATOC-anchored-reading-plan.csv into
content/harmony/reading-plans/.
"""
import json, csv, statistics
from datetime import date, timedelta

ROOT = "content/harmony/reading-plans"
UNITS = f"{ROOT}/latoc_units.json"
OUT = f"{ROOT}/2027-Jan-Mar_LATOC-anchored-reading-plan.csv"

units = json.load(open(UNITS))
n = len(units)

start = date(2026, 12, 20)
xmas_eve_idx = (date(2026, 12, 24) - start).days
easter_idx = (date(2027, 3, 28) - start).days

sections = [u['section'] for u in units]
cut1 = sections.index('1.8') + 1
cut2 = sections.index('6.1') + 1

seg1_units = units[0:cut1]
seg2_units = units[cut1:cut2]
seg3_units = units[cut2:n]

seg1_days = xmas_eve_idx + 1                 # Dec 20 .. Dec 24 (fixed, anchor)
seg2_days = easter_idx - xmas_eve_idx         # Dec 25 .. Mar 28 (fixed, anchor)
seg2_words = sum(u['words'] for u in seg2_units)
pace = seg2_words / seg2_days                 # natural words/day for the bulk of the book

seg3_words = sum(u['words'] for u in seg3_units)
seg3_days = max(1, round(seg3_words / pace))  # let the tail run at the same pace, however long that takes

DAYS = seg1_days + seg2_days + seg3_days
end = start + timedelta(days=DAYS - 1)

def least_squares_partition(weights, days):
    m = len(weights)
    prefix = [0] * (m + 1)
    for i, w in enumerate(weights):
        prefix[i + 1] = prefix[i] + w
    total = prefix[m]
    target = total / days if days else 0
    def range_sum(j, i):
        return prefix[i] - prefix[j]
    INF = float('inf')
    dp = [[INF] * (m + 1) for _ in range(days + 1)]
    split = [[-1] * (m + 1) for _ in range(days + 1)]
    dp[0][0] = 0.0
    for k in range(1, days + 1):
        for i in range(k, m + 1):
            best = INF
            best_j = -1
            for j in range(k - 1, i):
                if dp[k - 1][j] == INF:
                    continue
                dev = range_sum(j, i) - target
                cost = dp[k - 1][j] + dev * dev
                if cost < best:
                    best = cost
                    best_j = j
            dp[k][i] = best
            split[k][i] = best_j
    boundaries = []
    i = m
    for k in range(days, 0, -1):
        j = split[k][i]
        boundaries.append((j, i))
        i = j
    boundaries.reverse()
    return boundaries

seg_defs = [
    (seg1_units, seg1_days),
    (seg2_units, seg2_days),
    (seg3_units, seg3_days),
]

all_groups = []
for seg_units, seg_days in seg_defs:
    weights = [u['words'] for u in seg_units]
    boundaries = least_squares_partition(weights, seg_days)
    for (j, i) in boundaries:
        all_groups.append(seg_units[j:i])

assert len(all_groups) == DAYS
assert sum(len(g) for g in all_groups) == n

xmas_group = all_groups[xmas_eve_idx]
easter_group = all_groups[easter_idx]
assert xmas_group[-1]['section'] == '1.8'
assert easter_group[-1]['section'] == '6.1'

def ascii_safe(s):
    if s is None:
        return ''
    s = str(s)
    repl = {'—': '-', '–': '-', '‘': "'", '’': "'", '“': '"', '”': '"', '§': '#'}
    for k, v in repl.items():
        s = s.replace(k, v)
    return s

def robertson_ref_label(group):
    labels = []
    seen = set()
    for u in group:
        for lbl in u['robertson_labels']:
            if lbl not in seen:
                seen.add(lbl)
                labels.append(lbl)
    if not labels:
        return '(no Robertson match)'
    return ', '.join(f"#{l}" for l in labels)

rows = []
for day_idx, g in enumerate(all_groups):
    d = start + timedelta(days=day_idx)
    pn_sections = ', '.join(u['section'] for u in g)
    titles = ' | '.join(ascii_safe(u['title']) for u in g)
    robertson_ref = ascii_safe(robertson_ref_label(g))
    scripture = '; '.join(ascii_safe(r) for u in g for r in u['refs'])
    words = sum(u['words'] for u in g)
    note = ''
    if day_idx == xmas_eve_idx:
        note = 'Christmas Eve anchor'
    elif day_idx == easter_idx:
        note = 'Easter Sunday anchor'
    rows.append([d.isoformat(), day_idx + 1, pn_sections, titles, robertson_ref, scripture, words, note])

if __name__ == '__main__':
    group_words = [sum(u['words'] for u in g) for g in all_groups]
    total_words = sum(group_words)
    print(f"Segment 2 (bulk) pace: {seg2_words} words / {seg2_days} days = {pace:.1f} words/day")
    print(f"Segment 3 (post-Easter tail): {seg3_words} words -> {seg3_days} days at that pace")
    print(f"Plan length: {DAYS} days, {start.isoformat()} .. {end.isoformat()}")
    print(f"Grand total: {total_words} words, avg {total_words/DAYS:.1f}/day")
    print(f"Min day {min(group_words)}  Max day {max(group_words)}  Stdev "
          f"{statistics.pstdev(group_words):.1f} ({statistics.pstdev(group_words)/(total_words/DAYS)*100:.1f}% of avg)")
    print("Anchors confirmed: Christmas Eve ends with 1.8, Easter Sunday ends with 6.1")

    with open(OUT, 'w', newline='', encoding='utf-8-sig') as f:
        w = csv.writer(f)
        w.writerow(['Date', 'Day', 'PlusNothing Sections', 'Titles', 'Robertson Sections (reference)', 'Scripture (PlusNothing)', 'Words', 'Notes'])
        w.writerows(rows)
    print(f"Wrote {OUT}")
