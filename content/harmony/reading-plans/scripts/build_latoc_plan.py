# -*- coding: utf-8 -*-
"""
Build the PlusNothing-native reading units: group citation_seq.json's 359
citations into PlusNothing's own 239 numbered sections (1.1, 1.2, ... 6.14),
in the order the book itself presents them, with a word count computed from
the public-domain KJV word-count table.

Run from the repo root:
    python3 content/harmony/reading-plans/scripts/build_latoc_plan.py

Writes latoc_units.json next to this script's parent directory (a build
artifact, not committed -- regenerate as needed).
"""
import json, re

ROOT = "content/harmony/reading-plans"
CITATION_SEQ = f"{ROOT}/citation_seq.json"
KJV_WORDS = "content/harmony/kjv-word-counts.json"
OUT = f"{ROOT}/latoc_units.json"

citation_seq = json.load(open(CITATION_SEQ))
wc = json.load(open(KJV_WORDS))['verse_word_counts']

def last_verse(book, ch):
    m = 0
    for k in wc[book]:
        c, v = k.split(':')
        if int(c) == ch:
            m = max(m, int(v))
    return m

VERSE_TOKEN = re.compile(r'^(\d+)([a-c])?$')

def expand_ref(book, ref_str):
    out = []
    current_chapter = None
    current_verse = None
    for piece in [p.strip() for p in ref_str.split(',')]:
        if ':' in piece.split('-')[0]:
            chap_str, verse_part = piece.split(':', 1)
            current_chapter = int(chap_str)
        else:
            verse_part = piece
        if re.match(r'^[a-z]$', verse_part.strip()):
            out.append((book, current_chapter, current_verse, current_chapter, current_verse))
            continue
        if '-' in verse_part:
            a, b = verse_part.split('-', 1)
            if ':' in b:
                bchap, bverse = b.split(':', 1)
                m1 = VERSE_TOKEN.match(a.strip()); m2 = VERSE_TOKEN.match(bverse.strip())
                out.append((book, current_chapter, int(m1.group(1)), int(bchap), int(m2.group(1))))
                current_chapter = int(bchap)
                current_verse = int(m2.group(1))
            else:
                m1 = VERSE_TOKEN.match(a.strip()); m2 = VERSE_TOKEN.match(b.strip())
                out.append((book, current_chapter, int(m1.group(1)), current_chapter, int(m2.group(1))))
                current_verse = int(m2.group(1))
        else:
            m1 = VERSE_TOKEN.match(verse_part.strip())
            out.append((book, current_chapter, int(m1.group(1)), current_chapter, int(m1.group(1))))
            current_verse = int(m1.group(1))
    return out

def words_for_ref(book, ref_str):
    if book not in wc:
        return 0
    total = 0
    seen = set()
    for (b, ch_s, v_s, ch_e, v_e) in expand_ref(book, ref_str):
        for ch in range(ch_s, ch_e + 1):
            a = v_s if ch == ch_s else 1
            bnd = v_e if ch == ch_e else last_verse(b, ch)
            for v in range(a, bnd + 1):
                key = (b, ch, v)
                if key in seen:
                    continue
                seen.add(key)
                total += wc[b].get(f"{ch}:{v}", 0)
    return total

# ---------- group citations into PlusNothing's own 239 section units, in book order ----------
units = []
cur = None
for c in citation_seq:
    words = words_for_ref(c['book'], c['ref'])
    if cur is None or cur['section'] != c['section']:
        cur = {
            'section': c['section'],
            'title': c['title'],
            'citations': [],
            'refs': [],
            'robertson_labels': [],
            'words': 0,
        }
        units.append(cur)
    cur['citations'].append(c['citation'])
    cur['refs'].append(f"{c['book']} {c['ref']}")
    if c['order'] is not None:
        cur['robertson_labels'].append(c['label'])
    cur['words'] += words

if __name__ == '__main__':
    print(f"Built {len(units)} PlusNothing section units from {len(citation_seq)} citations")
    total_words = sum(u['words'] for u in units)
    print(f"Total words: {total_words}")
    json.dump(units, open(OUT, 'w'), indent=2)
    print(f"Wrote {OUT}")
