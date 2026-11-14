/**
 * Harmony Bible section links for a reading day (#90).
 *
 * A day's Robertson labels ("#8a", "#8b", "#9") become one entry per
 * Harmony section number, in reading order and once per section: lettered
 * sub-sections share their parent's number, so 8a/8b/8c are section 8.
 * The title is Robertson's own (public domain) title for that section, never
 * harmony-bible.com's headings; the link only points at the matching page.
 * Harmony Bible's numbering is assumed to follow Robertson's (owner decision
 * on #90).
 */
'use strict';

/**
 * @param {string[]} labels Robertson labels without "#" (e.g. ["8a","8b","9"]).
 * @param {(label: string) => ?{gospel_event_id: string, title: string}} toEvent Label resolver.
 * @param {string} urlTemplate URL containing "{number}".
 * @returns {{number: string, title: string, gospel_event_id: string, url: string}[]}
 */
function buildHarmonySections(labels, toEvent, urlTemplate) {
  const seen = new Set();
  const sections = [];
  for (const label of labels) {
    const number = label.replace(/[a-z]+$/, '');
    if (seen.has(number)) continue;
    const event = toEvent(label);
    if (!event) continue;
    seen.add(number);
    sections.push({
      number,
      title: event.title,
      gospel_event_id: event.gospel_event_id,
      url: urlTemplate.replace('{number}', encodeURIComponent(number)),
    });
  }
  return sections;
}

module.exports = { buildHarmonySections };
