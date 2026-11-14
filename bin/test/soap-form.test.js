'use strict';

// The SOAP page script (#98): one textbox, autosave of the single text, a local
// backup that survives a dropped connection, and an older four-field backup
// joined under the headings instead of being lost. Runs the real script against
// a minimal fake page, so no browser is needed.

const test = require('node:test');
const assert = require('node:assert');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');

const SOURCE = fs.readFileSync(path.join(__dirname, '..', '..', 'plugins', 'jlife-challenges', 'assets', 'soap.js'), 'utf8');
const HEADINGS = ['S — Scripture', 'O — Observation', 'A — Application', 'P — Prayer'];
const TEMPLATE = HEADINGS.join('\n\n');
const KEY = 'jl-soap-7-d001';

function load({ serverText = TEMPLATE, backup, status = 'draft', storageThrows = false } = {}) {
	const store = new Map();
	if (backup !== undefined) { store.set(KEY, backup); }
	const timers = [];
	const fetches = [];
	const box = {
		value: serverText,
		handlers: {},
		addEventListener(type, fn) { (this.handlers[type] = this.handlers[type] || []).push(fn); },
		dispatchEvent() {},
		focus() {},
		setSelectionRange() {}
	};
	const saved = { textContent: '' };
	const form = {
		elements: { jl_text: box },
		dataset: {
			campaign: '7',
			day: 'd001',
			status,
			rest: '/wp-json/jlife/v1/soap/draft',
			nonce: 'nonce123',
			template: TEMPLATE,
			headings: JSON.stringify(HEADINGS),
			i18n: JSON.stringify({ saving: 'Saving…', saved: 'Draft saved', local: 'Saved on this device (offline)', restored: 'Restored your unsaved draft' })
		},
		querySelector(sel) { return sel === '.jl-saved' ? saved : null; },
		querySelectorAll() { return []; },
		addEventListener() {}
	};
	const storage = storageThrows
		? { getItem() { throw new Error('blocked'); }, setItem() { throw new Error('blocked'); }, removeItem() { throw new Error('blocked'); } }
		: {
			getItem(k) { return store.has(k) ? store.get(k) : null; },
			setItem(k, v) { store.set(k, String(v)); },
			removeItem(k) { store.delete(k); }
		};
	const context = {
		document: { querySelector(sel) { return sel === 'form.jl-soap' ? form : null; } },
		localStorage: storage,
		fetch(url, opts) { fetches.push({ url, opts }); return Promise.resolve({ ok: true }); },
		setTimeout(fn, ms) { timers.push({ fn, ms }); return timers.length; },
		clearTimeout() {},
		Event: class { constructor(type) { this.type = type; } }
	};
	vm.createContext(context);
	vm.runInContext(SOURCE, context);
	return { box, saved, store, timers, fetches };
}

function type(page, text) {
	page.box.value = text;
	page.box.handlers.input.forEach((fn) => fn());
}

test('an older four-field backup is joined under the headings, not lost', () => {
	const old = JSON.stringify({ scripture: 'John 1:14', observation: 'Line one\nLine two', application: '', prayer: 'Lord, help me' });
	const page = load({ backup: old });
	assert.strictEqual(
		page.box.value,
		'S — Scripture\nJohn 1:14\n\nO — Observation\nLine one\nLine two\n\nA — Application\n\nP — Prayer\nLord, help me'
	);
	assert.strictEqual(page.saved.textContent, 'Restored your unsaved draft');
});

test('a single-text backup is restored when the server copy is empty or just the template', () => {
	const backup = JSON.stringify({ v: 2, text: 'S — Scripture\nLuke 3\n\nO — Observation\nseen' });
	assert.strictEqual(load({ backup, serverText: TEMPLATE }).box.value, 'S — Scripture\nLuke 3\n\nO — Observation\nseen');
	assert.strictEqual(load({ backup, serverText: '' }).box.value, 'S — Scripture\nLuke 3\n\nO — Observation\nseen');
});

test('a backup never overwrites what the server already holds', () => {
	const backup = JSON.stringify({ v: 2, text: 'older local text' });
	const page = load({ backup, serverText: 'S — Scripture\nsaved on the server' });
	assert.strictEqual(page.box.value, 'S — Scripture\nsaved on the server');
	assert.strictEqual(page.saved.textContent, '');
});

test('an empty or template-only backup restores nothing', () => {
	const empty = JSON.stringify({ scripture: '', observation: '', application: '', prayer: '' });
	assert.strictEqual(load({ backup: empty }).box.value, TEMPLATE);
	assert.strictEqual(load({ backup: JSON.stringify({ v: 2, text: TEMPLATE }) }).box.value, TEMPLATE);
	assert.strictEqual(load({ backup: 'not json' }).box.value, TEMPLATE);
});

test('typing is backed up at once and autosaved as ONE text after a pause', async () => {
	const page = load();
	type(page, 'S — Scripture\nJohn 1:14\n\nO — Observation\nThe Word');
	assert.deepStrictEqual(JSON.parse(page.store.get(KEY)), { v: 2, text: 'S — Scripture\nJohn 1:14\n\nO — Observation\nThe Word' });
	assert.strictEqual(page.fetches.length, 0, 'not on every keystroke');
	assert.strictEqual(page.timers[page.timers.length - 1].ms, 1500);

	page.timers[page.timers.length - 1].fn();
	assert.strictEqual(page.fetches.length, 1);
	const sent = page.fetches[0];
	assert.strictEqual(sent.url, '/wp-json/jlife/v1/soap/draft');
	assert.strictEqual(sent.opts.headers['X-WP-Nonce'], 'nonce123');
	assert.deepStrictEqual(JSON.parse(sent.opts.body), {
		campaign_id: 7,
		day_key: 'd001',
		text: 'S — Scripture\nJohn 1:14\n\nO — Observation\nThe Word'
	});
	await Promise.resolve();
	await Promise.resolve();
	assert.strictEqual(page.saved.textContent, 'Draft saved');
	assert.strictEqual(page.store.has(KEY), false, 'the local backup is cleared once the server has it');
});

test('a submitted entry is only backed up, never autosaved over', () => {
	const page = load({ status: 'submitted', serverText: 'S — Scripture\nx' });
	type(page, 'S — Scripture\nedited');
	page.timers[page.timers.length - 1].fn();
	assert.strictEqual(page.fetches.length, 0);
	assert.ok(page.store.has(KEY));
});

test('blocked storage never breaks the form', () => {
	const page = load({ storageThrows: true });
	assert.doesNotThrow(() => type(page, 'S — Scripture\nstill typing'));
	assert.strictEqual(page.box.value, 'S — Scripture\nstill typing');
});
