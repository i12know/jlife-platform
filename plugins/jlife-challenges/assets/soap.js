/* SOAP form enhancements (M6, #61; one textbox, #98): debounced server
 * autosave of the single text, a local backup if the network drops (an older
 * four-field backup is joined under the headings, not lost), no double
 * submit. (The reference chips are gone, #188: the reference is typed under S.) The form works fully without this script. */
(function () {
	'use strict';
	var form = document.querySelector('form.jl-soap');
	if (!form) { return; }
	var box = form.elements.jl_text;
	if (!box) { return; }
	var key = 'jl-soap-' + form.dataset.campaign + '-' + form.dataset.day;
	var status = form.querySelector('.jl-saved');
	var template = form.dataset.template || '';
	var prefill = form.dataset.prefill || ''; // the question(s) a new guided entry starts with (#225)
	var headings = ['S \u2014 Scripture', 'O \u2014 Observation', 'A \u2014 Application', 'P \u2014 Prayer'];
	var legacyKeys = ['scripture', 'observation', 'application', 'prayer'];
	var timer = null;
	var msg = { saving: '\u2026', saved: '\u2713', local: '\u2713', restored: '\u2713', leave_failed: '', leave_unsaved: '' };
	var lastSaved = box.value; // what the server holds, as far as this page knows
	try { msg = JSON.parse(form.dataset.i18n); } catch (e) { /* keep fallbacks */ }
	try {
		var h = JSON.parse(form.dataset.headings);
		if (Array.isArray(h) && h.length === 4) { headings = h; }
	} catch (e) { /* keep English headings */ }

	// Anything beyond the headings? A blank box or the untouched template isn't a draft yet (#142).
	var headingOnly = /^[ \t]*[SOAP][ \t]*(?::|[\u2014\u2013]+|-+)[ \t]*(?:Scripture|Observation|Application|Prayer|Kinh Th\u00e1nh|Quan s\u00e1t|\u00c1p d\u1ee5ng|C\u1ea7u nguy\u1ec7n)?[\s:.\u2014\u2013-]*$/i;
	function hasWriting(text) {
		return text.split(/\r?\n/).some(function (line) {
			return line.trim() !== '' && !headingOnly.test(line);
		});
	}

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

	// Restore a local backup only when the server copy is untouched (empty, just the template, or just the prefilled questions).
	try {
		var restored = backupText(localStorage.getItem(key));
		var current = box.value.replace(/\s+$/, '');
		var untouched = current === '' || current === template.replace(/\s+$/, '') || (prefill !== '' && current === prefill.replace(/\s+$/, ''));
		if (restored !== null && untouched && restored.replace(/\s+$/, '') !== current && restored.trim() !== '') {
			box.value = restored;
			status.textContent = msg.restored;
		}
	} catch (e) { /* ignore */ }

	function save() {
		backup();
		if (form.dataset.status === 'submitted') { return Promise.resolve(false); } // Edits to a submitted entry are saved with the button.
		var sent = box.value;
		var written = hasWriting(sent);
		status.textContent = written ? msg.saving : '';
		return fetch(form.dataset.rest, {
			method: 'POST',
			credentials: 'same-origin',
			headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': form.dataset.nonce },
			body: JSON.stringify({ campaign_id: Number(form.dataset.campaign), day_key: form.dataset.day, text: sent })
		}).then(function (r) {
			if (!r.ok) { throw new Error(String(r.status)); }
			status.textContent = written ? msg.saved : '';
			lastSaved = sent;
			clearBackup();
			return true;
		}).catch(function () { status.textContent = written ? msg.local : ''; return false; });
	}

	box.addEventListener('input', function () {
		backup();
		clearTimeout(timer);
		timer = setTimeout(save, 1500);
	});

	// Previous / next day arrows (#225): plain links that never lose writing. If the box holds
	// text the server hasn't got, save it first and leave only when the save worked; otherwise
	// stay on the page with a message (the local backup is kept too). Nothing is submitted or shared.
	// Modified clicks (new tab) leave this page alone, so they need nothing. Without this script
	// the links are plain links.
	document.querySelectorAll('a.jl-daynav-link').forEach(function (link) {
		link.addEventListener('click', function (e) {
			if (e.defaultPrevented || e.button !== 0 || e.metaKey || e.ctrlKey || e.shiftKey || e.altKey) { return; }
			if (box.value === lastSaved) { return; } // nothing unsaved: follow the link
			e.preventDefault();
			clearTimeout(timer);
			if (form.dataset.status === 'submitted') {
				backup();
				status.textContent = msg.leave_unsaved;
				box.focus();
				return;
			}
			save().then(function (ok) {
				if (ok) {
					window.location.href = link.href;
				} else {
					status.textContent = msg.leave_failed;
					box.focus();
				}
			});
		});
	});

	// "Use SOAP template" (#210): insert the four headings at the cursor, or at the end if the
	// box was never focused. Never replaces or removes typed text, even a selection. Without
	// this script the button is a submit that appends the headings (same result, server side).
	var cursorSet = false;
	box.addEventListener('focus', function () { cursorSet = true; });
	form.querySelectorAll('button[value=template]').forEach(function (tplButton) {
		tplButton.addEventListener('click', function (e) {
			e.preventDefault();
			var text = box.value;
			var at = cursorSet ? Math.max(box.selectionStart || 0, box.selectionEnd || 0) : text.length;
			var before = text.slice(0, at);
			var after = text.slice(at);
			var lead = before.trim() === '' ? '' : (/\n\n$/.test(before) ? '' : (/\n$/.test(before) ? '\n' : '\n\n'));
			var tail = after.trim() === '' ? '' : (/^\n\n/.test(after) ? '' : (/^\n/.test(after) ? '\n' : '\n\n'));
			var caret = before.length + lead.length + headings[0].length + 1;
			box.value = before + lead + template + tail + after;
			box.focus();
			box.setSelectionRange(caret, caret);
			box.dispatchEvent(new Event('input', { bubbles: true }));
		});
	});

	// "More" beside the SOAP one-liner opens the "What goes under each heading?" box it jumps to.
	form.querySelectorAll('a[href="#jlife-soap-help"]').forEach(function (link) {
		link.addEventListener('click', function () {
			var help = document.getElementById('jlife-soap-help');
			if (help && help.tagName === 'DETAILS') { help.open = true; }
		});
	});

	// Photo of a handwritten reflection (#244). The photo is saved with the draft the moment it is
	// chosen, so a dropped connection does not lose it; it never earns a point (only Submit does).
	// Replace and Remove work on the saved photo. Without this script the file input is sent with
	// Save draft or Submit and the Remove button submits the form; the page works the same.
	var photo = form.querySelector('.jl-photo');
	var photoBusy = false;
	if (photo && form.dataset.photoRest && window.fetch && window.FormData) {
		var photoInput = photo.querySelector('input[type=file]');
		var photoAdd = photo.querySelector('.jl-photo-add');
		var photoPreview = photo.querySelector('.jl-photo-preview');
		var photoImg = photo.querySelector('.jl-photo-img');
		var photoLink = photo.querySelector('.jl-photo-link');
		var photoStatus = photo.querySelector('.jl-photo-status');
		var photoRemove = photo.querySelector('.jl-photo-remove');
		var groupNote = form.querySelector('.jl-share-photo-note');
		var say = function (text, bad) {
			photoStatus.textContent = text;
			photoStatus.className = 'jl-photo-status' + (bad ? ' jl-photo-error' : '');
		};
		var show = function (has, thumb, full) {
			photo.dataset.has = has ? '1' : '0';
			if (photoAdd) { photoAdd.hidden = has; }
			if (photoPreview) { photoPreview.hidden = !has; }
			if (has && photoImg) { photoImg.src = thumb; }
			if (has && photoLink) { photoLink.href = full; }
			if (groupNote) { groupNote.hidden = !has; } // the "your group will see this photo" reminder
		};
		var send = function (url, extra) {
			var data = new FormData();
			data.append('campaign_id', form.dataset.campaign);
			data.append('day_key', form.dataset.day);
			if (extra) { data.append('photo', extra); }
			return fetch(url, { method: 'POST', credentials: 'same-origin', headers: { 'X-WP-Nonce': form.dataset.nonce }, body: data })
				.then(function (r) {
					return r.json().catch(function () { return {}; }).then(function (j) {
						if (!r.ok) { var err = new Error(j && j.message ? j.message : ''); throw err; }
						return j;
					});
				});
		};
		photoInput.addEventListener('change', function () {
			var file = photoInput.files && photoInput.files[0];
			if (!file || photoBusy) { return; }
			photoBusy = true;
			say(msg.photo_saving, false);
			send(form.dataset.photoRest, file).then(function (res) {
				show(true, res.thumb, res.url);
				say(msg.photo_saved, false);
			}).catch(function (err) {
				say(err && err.message ? err.message : msg.photo_failed, true);
			}).then(function () {
				photoBusy = false;
				photoInput.value = ''; // sent already (or failed): choosing the same file again must work
			});
		});
		if (photoRemove && form.dataset.photoRemove) {
			photoRemove.addEventListener('click', function (e) {
				e.preventDefault();
				if (photoBusy) { return; }
				photoBusy = true;
				send(form.dataset.photoRemove).then(function () {
					show(false, '', '');
					say(msg.photo_removed, false);
				}).catch(function (err) {
					say(err && err.message ? err.message : msg.photo_failed, true);
				}).then(function () { photoBusy = false; });
			});
		}
	}

	form.addEventListener('submit', function (e) {
		if (photoBusy) { // a photo is still being saved: wait for it, so Submit sees it
			e.preventDefault();
			return;
		}
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
