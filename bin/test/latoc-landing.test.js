/**
 * Guards the latoc landing-page seed (#110): bin/deploy/latoc-landing-content.php.
 * The owner edits the live pages in wp-admin, so this only protects the FIRST
 * seed: reviewer-only text must never leak in, the routes must be the real ones,
 * and the markup must be well formed. Needs `php` on the PATH (CI runners have
 * it); skipped otherwise.
 * Run: npm run test:js
 */
'use strict';

const test = require('node:test');
const assert = require('node:assert/strict');
const { spawnSync } = require('node:child_process');
const fs = require('node:fs');
const path = require('node:path');

const ROOT = path.join(__dirname, '..', '..');
const CONTENT = path.join(ROOT, 'bin', 'deploy', 'latoc-landing-content.php');

// WordPress's serialize_block_attributes(), reproduced so the file can run
// without WordPress (same escaping as wp-includes/blocks.php).
const PHP = `
function serialize_block_attributes( $attrs ) {
  $e = json_encode( $attrs, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
  return str_replace( array( '--', '<', '>', '&', '\\\\"' ), array( '\\\\u002d\\\\u002d', '\\\\u003c', '\\\\u003e', '\\\\u0026', '\\\\u0022' ), $e );
}
echo json_encode( require ${JSON.stringify(CONTENT)} );
`;

const run = spawnSync('php', ['-r', PHP], { encoding: 'utf8' });
const hasPhp = !run.error;
const pages = hasPhp && run.status === 0 ? JSON.parse(run.stdout) : null;

test('the seed content file loads and defines the EN and VI pages', { skip: !hasPhp }, () => {
  assert.equal(run.status, 0, run.stderr);
  assert.deepEqual(Object.keys(pages).sort(), ['en', 'vi']);
  assert.equal(pages.en.slug, 'welcome');
  assert.equal(pages.vi.slug, 'chao-mung');
  assert.notEqual(pages.en.key, pages.vi.key);
});

const each = (fn) => () => { for (const code of ['en', 'vi']) fn(code, pages[code].content); };

test('no reviewer-only or draft-status text is seeded', { skip: !hasPhp }, each((code, html) => {
  for (const banned of ['PENDING', 'Notes for reviewers', 'translator', 'người dịch', 'Decided 2026', 'DRAFT', 'NOT reviewed', 'Review state', 'Acknowledgement', 'PlusNothing', 'Biểu tượng']) {
    assert.ok(!html.includes(banned), `${code} page contains "${banned}"`);
  }
}));

test('no image is seeded (the logo is not checked in)', { skip: !hasPhp }, each((code, html) => {
  assert.ok(!/<img|wp:image|wp:cover/.test(html), `${code} page has an image block`);
}));

// The real #112 routes. In a block's href an "&" is saved as "&amp;".
const ROUTES = {
  en: { solo: '/join/?mode=solo', start: '/join/?mode=start', code: '/join/', signin: '/signin/' },
  vi: { solo: '/join/?mode=solo&amp;lang=vi', start: '/join/?mode=start&amp;lang=vi', code: '/join/?lang=vi', signin: '/signin/?lang=vi' },
};
const hrefsOf = (html) => [...html.matchAll(/href="([^"]*)"/g)].map((m) => m[1]);

test('sign-up buttons use the exact #112 routes, in the hero and the final call', { skip: !hasPhp }, each((code, html) => {
  const r = ROUTES[code];
  // Document order: hero (solo, start, code, sign in), then the final call (solo, start, code, sign in).
  assert.deepEqual(hrefsOf(html), [r.solo, r.start, r.code, r.signin, r.solo, r.start, r.code, r.signin]);
  // Each button's label is attached to its own route.
  const link = (label, href) => assert.equal(html.split(`href="${href}"`).length - 1, 2, `${code}: "${label}" -> ${href} twice`);
  const t = code === 'en'
    ? { solo: 'Read on my own', start: 'Start a group', code: 'I have an invite code', signin: 'Sign in' }
    : { solo: 'Đọc một mình', start: 'Lập một nhóm', code: 'Tôi có mã mời', signin: 'Đăng nhập' };
  for (const key of Object.keys(r)) {
    link(t[key], r[key]);
    assert.ok(new RegExp(`href="${r[key].replace(/[?]/g, '\\?')}"[^>]*>${t[key]}</a>`).test(html), `${code}: label "${t[key]}" is on ${r[key]}`);
  }
}));

test('the Vietnamese page has no auth link without lang=vi, and English has none with it', { skip: !hasPhp }, () => {
  const auth = (h) => /^\/(join|signin)\//.test(h);
  const vi = hrefsOf(pages.vi.content).filter(auth);
  assert.equal(vi.length, 8);
  for (const h of vi) assert.ok(/(\?|&amp;)lang=vi$/.test(h), `vi link lacks lang=vi: ${h}`);
  for (const h of hrefsOf(pages.en.content).filter(auth)) assert.ok(!h.includes('lang='), `en link has lang: ${h}`);
  // The query is escaped for the attribute: no raw "&" in any href.
  for (const code of ['en', 'vi']) for (const h of hrefsOf(pages[code].content)) assert.ok(!/&(?!amp;)/.test(h), `${code}: unescaped & in ${h}`);
});

test('no TODO(#112) reminder or hidden HTML-comment block is left in the seed', { skip: !hasPhp }, each((code, html) => {
  assert.ok(!html.includes('TODO(#112)'), `${code} page still has a #112 TODO`);
  assert.ok(!/wp:html/.test(html), `${code} page has a Custom HTML block`);
  assert.ok(!fs.readFileSync(CONTENT, 'utf8').includes('TODO(#112)'), 'the seed source still has a #112 TODO');
}));

test('headings start at h2 (the theme prints the one h1)', { skip: !hasPhp }, each((code, html) => {
  assert.ok(!/<h1/.test(html));
  assert.ok(/<h2/.test(html));
  const levels = [...html.matchAll(/<h([1-6])/g)].map((m) => Number(m[1]));
  assert.equal(levels[0], 2);
}));

test('only core blocks plus the Polylang switcher, once, and blocks are balanced', { skip: !hasPhp }, each((code, html) => {
  const opens = [...html.matchAll(/<!-- wp:([a-z0-9/-]+)(?: \{.*?\})? (\/)?-->/g)];
  const names = opens.map((m) => (m[1].includes('/') ? m[1] : `core/${m[1]}`));
  for (const n of new Set(names)) assert.ok(n.startsWith('core/') || n === 'polylang/language-switcher', `${code}: unexpected block ${n}`);
  assert.equal(names.filter((n) => n === 'polylang/language-switcher').length, 1);
  const closers = [...html.matchAll(/<!-- \/wp:([a-z0-9/-]+) -->/g)].length;
  const nonSelfClosing = opens.filter((m) => m[2] !== '/').length;
  assert.equal(closers, nonSelfClosing, `${code}: unbalanced block comments`);
}));

test('EN and VI have the same structure', { skip: !hasPhp }, () => {
  const shape = (html) => [...html.matchAll(/<!-- wp:([a-z0-9/-]+)/g)].map((m) => m[1]).join(',');
  assert.equal(shape(pages.en.content), shape(pages.vi.content));
});

// The seed is authored from the Markdown "Page copy": its hero heading must match.
for (const [code, file] of [['en', 'landing-page.md'], ['vi', 'landing-page.vi.md']]) {
  test(`${code} hero heading matches content/latoc/${file}`, { skip: !hasPhp }, () => {
    const md = fs.readFileSync(path.join(ROOT, 'content', 'latoc', file), 'utf8');
    const h1 = md.split('\n').filter((l) => /^# 1\d\d /.test(l))[0].replace(/^# /, '').trim();
    assert.ok(pages[code].content.includes(h1), `${code} seed lacks the hero heading "${h1}"`);
  });
}
