/* The My Bible pickers on My Marathon (#140). A change saves at once: "Saved."
 * on success; on failure both selects go back to what is saved and a short
 * retry message shows. The server may change the other select too (the same
 * Bible twice is dropped from the second), so both are set from its answer.
 * Without this script the form's Save button posts it instead. */
(function () {
	'use strict';
	document.querySelectorAll('form.jl-bible-picker').forEach(function (form) {
		var first = form.querySelector('select[name=jl_bible_version]');
		var second = form.querySelector('select[name=jl_bible_version_2]');
		var status = form.querySelector('.jl-bible-status');
		if (!first || !second || !status) { return; }
		var msg = { saved: 'Saved.', failed: 'Not saved. Please try again.' };
		try { msg = JSON.parse(form.dataset.i18n); } catch (e) { /* keep fallbacks */ }
		var saved = { v1: first.value, v2: second.value };
		var latest = 0;

		function show(text, failed) {
			status.textContent = text;
			status.classList.toggle('jl-reminder-failed', !!failed);
		}
		function restore() {
			first.value = saved.v1;
			second.value = saved.v2;
		}
		function send(body) {
			var mine = ++latest;
			show('', false);
			fetch(form.dataset.rest, {
				method: 'POST',
				credentials: 'same-origin',
				headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': form.dataset.nonce },
				body: JSON.stringify(body)
			}).then(function (r) {
				if (!r.ok) { throw new Error(String(r.status)); }
				return r.json();
			}).then(function (data) {
				if (!data || typeof data.bible_version !== 'string' || typeof data.bible_version_2 !== 'string') {
					throw new Error('bad response');
				}
				if (mine !== latest) { return; }
				saved = { v1: data.bible_version, v2: data.bible_version_2 };
				restore();
				show(msg.saved, false);
			}).catch(function () {
				if (mine !== latest) { return; }
				restore();
				show(msg.failed, true);
			});
		}
		function orNull(value) { return value === '' ? null : value; }

		first.addEventListener('change', function () { send({ bible_version: orNull(first.value) }); });
		second.addEventListener('change', function () { send({ bible_version_2: orNull(second.value) }); });
		form.addEventListener('submit', function (e) {
			e.preventDefault();
			send({ bible_version: orNull(first.value), bible_version_2: orNull(second.value) });
		});
	});
}());
