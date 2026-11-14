/* SOAP form enhancements (M6, #61; one textbox, #98): debounced server
 * autosave of the single text, a local backup if the network drops (an older
 * four-field backup is joined under the headings, not lost), reference chips,
 * no double submit. The form works fully without this script. */
(function () {
	'use strict';
	var form = document.querySelector('form.jl-soap');
	if (!form) { return; }
	var box = form.elements.jl_text;
	if (!box) { return; }
	var key = 'jl-soap-' + form.dataset.campaign + '-' + form.dataset.day;
	var status = form.querySelector('.jl-saved');
	var template = form.dataset.template || '';
	var headings = ['S \u2014 Scripture', 'O \u2014 Observation', 'A \u2014 Application', 'P \u2014 Prayer'];
	var legacyKeys = ['scripture', 'observation', 'application', 'prayer'];
	var timer = null;
	var msg = { saving: '\u2026', saved: '\u2713', local: '\u2713', restored: '\u2713' };
	try { msg = JSON.parse(form.dataset.i18n); } catch (e) { /* keep fallbacks */ }
	try {
		var h = JSON.parse(form.dataset.headings);
		if (Array.isArray(h) && h.length === 4) { headings = h; }
	} catch (e) { /* keep English headings */ }

	function backup() {
		try { localStorage.setItem(key, JSON.stringify({ v: 2, text: box.value })); } catch (e) { /* storage unavailable */ }
	}
	function clearBackup() {
		try { localStorage.removeItem(key); } catch (e) { /* storage unavailable */ }
	}

	// A backup made before the single textbox held four parts: join them under the headings.
	function joinLegacy(old) {
		if (!legacyKeys.some(function (k) { return typeof old[k] === 'string'; })) { return null; }
		return headings.map(function (heading, i) {
			var text = String(old[legacyKeys[i]] || '').trim();
			return text ? heading + '\n' + text : heading;
		}).join('\n\n');
	}
	function backupText(raw) {
		var saved = JSON.parse(raw || 'null');
		if (!saved || typeof saved !== 'object') { return null; }
		return typeof saved.text === 'string' ? saved.text : joinLegacy(saved);
	}

	// Restore a local backup only when the server copy is untouched (empty or just the template).
	try {
		var restored = backupText(localStorage.getItem(key));
		var current = box.value.replace(/\s+$/, '');
		var untouched = current === '' || current === template.replace(/\s+$/, '');
		if (restored !== null && untouched && restored.replace(/\s+$/, '') !== current && restored.trim() !== '') {
			box.value = restored;
			status.textContent = msg.restored;
		}
	} catch (e) { /* ignore */ }

	function save() {
		backup();
		if (form.dataset.status === 'submitted') { return; } // Edits to a submitted entry are saved with the button.
		status.textContent = msg.saving;
		fetch(form.dataset.rest, {
			method: 'POST',
			credentials: 'same-origin',
			headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': form.dataset.nonce },
			body: JSON.stringify({ campaign_id: Number(form.dataset.campaign), day_key: form.dataset.day, text: box.value })
		}).then(function (r) {
			if (!r.ok) { throw new Error(String(r.status)); }
			status.textContent = msg.saved;
			clearBackup();
		}).catch(function () { status.textContent = msg.local; });
	}

	box.addEventListener('input', function () {
		backup();
		clearTimeout(timer);
		timer = setTimeout(save, 1500);
	});

	// A reference chip goes on its own line under the S heading (or at the end if the heading is gone).
	form.querySelectorAll('[data-insert]').forEach(function (chip) {
		chip.addEventListener('click', function () {
			var text = chip.getAttribute('data-insert');
			var value = box.value;
			var heading = /^[ \t]*S[ \t]*[:\u2014\u2013-]+.*$/im.exec(value);
			var pos;
			if (heading) {
				pos = heading.index + heading[0].length;
				box.value = value.slice(0, pos) + '\n' + text + value.slice(pos);
				pos += text.length + 1;
			} else {
				box.value = value ? value.replace(/\s*$/, '') + '\n' + text : text;
				pos = box.value.length;
			}
			box.focus();
			box.setSelectionRange(pos, pos);
			box.dispatchEvent(new Event('input', { bubbles: true }));
		});
	});

	form.addEventListener('submit', function (e) {
		clearTimeout(timer);
		var btn = e.submitter;
		if (btn && btn.value === 'submit') { clearBackup(); }
		form.querySelectorAll('button[type=submit]').forEach(function (b) {
			setTimeout(function () { b.disabled = true; }, 0); // after the submitter's value is captured
		});
	});
}());

/* Bible reader above the SOAP form (#92). The frame loads Bible Gateway only
 * when the participant opens the reader or picks a version, never on page
 * load. It sits outside the form, so opening, closing or switching versions
 * never touches what the participant has typed. If the frame hasn't loaded
 * after 20 seconds, a note points to the Bible Gateway link, which is always
 * shown. */
(function () {
	'use strict';
	var reader = document.querySelector('details.jl-reader');
	if (!reader) { return; }

	function load(pane) {
		if (!pane) { return; }
		// A pane for a language that isn't framed holds only a link: nothing to load.
		var frame = pane.querySelector('iframe[data-src]');
		if (!frame || frame.getAttribute('src')) { return; }
		var fail = pane.querySelector('.jl-reader-fail');
		var timer = setTimeout(function () { if (fail) { fail.hidden = false; } }, 20000);
		frame.addEventListener('load', function () {
			clearTimeout(timer);
			if (fail) { fail.hidden = true; }
		});
		frame.setAttribute('src', frame.getAttribute('data-src'));
	}
	function visiblePane() {
		return reader.querySelector('[data-reader-pane]:not([hidden])');
	}

	reader.addEventListener('toggle', function () {
		if (reader.open) { load(visiblePane()); }
	});
	reader.querySelectorAll('[data-reader-lang]').forEach(function (btn) {
		btn.addEventListener('click', function () {
			var lang = btn.getAttribute('data-reader-lang');
			reader.querySelectorAll('[data-reader-lang]').forEach(function (b) {
				b.setAttribute('aria-pressed', b === btn ? 'true' : 'false');
			});
			reader.querySelectorAll('[data-reader-pane]').forEach(function (p) {
				p.hidden = p.getAttribute('data-reader-pane') !== lang;
			});
			load(visiblePane());
		});
	});
}());
