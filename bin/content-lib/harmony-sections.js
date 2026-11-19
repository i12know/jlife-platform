/**
 * Harmony Bible section links for a reading day (#90, #109).
 *
 * A day's Robertson labels ("#8a", "#8b", "#9") become one entry per
 * Harmony Bible page, in reading order and once per page: lettered
 * sub-sections share their parent's Robertson number, so 8a/8b/8c are section 8.
 * The title is Robertson's own (public domain) title for that section, never
 * harmony-bible.com's headings; the link only points at the matching page.
 *
 * Harmony Bible mostly numbers its sections like Robertson, but not always
 * (#109 compared all 184 by Scripture reference). A Robertson section can
 * map to a differently numbered page, to several lettered pages ("32a",
 * "32b"), or to pages that belong to a neighbour. Those exceptions live in
 * `harmony_sections_overrides` next to `harmony_sections_url` in
 * content/reading-plans/plans.json: Robertson section number -> Harmony page
 * slugs. Sections without an override link to the page of the same number.
 */
'use strict';

/** A Harmony Bible page slug: a section number, optionally with one letter ("32", "32a"). */
const HARMONY_SLUG = /^([1-9][0-9]*)[a-z]?$/;

/**
 * @param {string} number Robertson section number without letters ("32").
 * @param {Object<string, string[]>} overrides Override map.
 * @returns {string[]} Harmony page slugs to link.
 */
function harmonyTargets(number, overrides) {
  const mapped = overrides && Object.prototype.hasOwnProperty.call(overrides, number) ? overrides[number] : null;
  return Array.isArray(mapped) && mapped.length > 0 ? mapped : [number];
}

/**
 * @param {string[]} labels Robertson labels without "#" (e.g. ["8a","8b","9"]).
 * @param {(label: string) => ?{gospel_event_id: string, title: string}} toEvent Label resolver.
 * @param {string} urlTemplate URL containing "{number}".
 * @param {Object<string, string[]>} [overrides] Robertson section -> Harmony page slugs.
 * @returns {{number: string, title: string, gospel_event_id: string, url: string}[]}
 */
function buildHarmonySections(labels, toEvent, urlTemplate, overrides = {}) {
  const seenSections = new Set();
  const seenPages = new Set();
  const sections = [];
  for (const label of labels) {
    const section = label.replace(/[a-z]+$/, '');
    if (seenSections.has(section)) continue;
    const event = toEvent(label);
    if (!event) continue;
    seenSections.add(section);
    for (const page of harmonyTargets(section, overrides)) {
      if (seenPages.has(page)) continue;
      seenPages.add(page);
      sections.push({
        number: page,
        title: event.title,
        gospel_event_id: event.gospel_event_id,
        url: urlTemplate.replace('{number}', encodeURIComponent(page)),
      });
    }
  }
  return sections;
}

module.exports = { buildHarmonySections, harmonyTargets, HARMONY_SLUG };
