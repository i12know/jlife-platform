/* SOAP form enhancements (M6, #61): debounced server autosave, a local
 * backup if the network drops, reference chips, no double submit.
 * The form works fully without this script. */
(function () {
	'use strict';
	var form = document.querySelector('form.jl-soap');
	if (!form) { return; }
	var fields = ['scripture', 'observation', 'application', 'prayer'];
	var key = 'jl-soap-' + form.dataset.campaign + '-' + form.dataset.day;
	var status = form.querySelector('.jl-saved');
	var timer = null;
	var msg = { saving: '…', saved: '✓', local: '✓', restored: '✓' };
	try { msg = JSON.parse(form.dataset.i18n); } catch (e) { /* keep fallbacks */ }

	function values() {
		var out = {};
		fields.forEach(function (f) { out[f] = form.elements[f].value; });
		return out;
	}
	function backup() {
		try { localStorage.setItem(key, JSON.stringify(values())); } catch (e) { /* storage unavailable */ }
	}
	function clearBackup() {
		try { localStorage.removeItem(key); } catch (e) { /* storage unavailable */ }
	}

	// Restore a local backup only when the server copy is empty.
	try {
		var saved = JSON.parse(localStorage.getItem(key) || 'null');
		var empty = fields.every(function (f) { return form.elements[f].value === ''; });
		if (saved && empty) {
			fields.forEach(function (f) { form.elements[f].value = saved[f] || ''; });
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
			body: JSON.stringify({ campaign_id: Number(form.dataset.campaign), day_key: form.dataset.day, fields: values() })
		}).then(function (r) {
			if (!r.ok) { throw new Error(String(r.status)); }
			status.textContent = msg.saved;
			clearBackup();
		}).catch(function () { status.textContent = msg.local; });
	}

	form.addEventListener('input', function () {
		backup();
		clearTimeout(timer);
		timer = setTimeout(save, 1500);
	});

	form.querySelectorAll('[data-insert]').forEach(function (chip) {
		chip.addEventListener('click', function () {
			var ta = form.elements.scripture;
			var text = chip.getAttribute('data-insert');
			ta.value = ta.value ? ta.value.replace(/\s*$/, '') + '\n' + text : text;
			ta.focus();
			ta.dispatchEvent(new Event('input', { bubbles: true }));
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
