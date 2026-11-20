/* The daily reading email checkbox on Today and My Marathon (#135). A change
 * saves at once: "Saved." on success; on failure the checkbox goes back to
 * what is saved and a short retry message shows. Without this script the
 * form's Save button posts it instead. The email language is not here: the
 * Language setting owns it (#159, language.js). */
(function () {
	'use strict';
	document.querySelectorAll('form.jl-reminder-toggle').forEach(function (form) {
		var box = form.querySelector('input[type=checkbox][name=jl_reminder]');
		var status = form.querySelector('.jl-reminder-status');
		if (!box || !status) { return; }
		var msg = { saved: 'Saved.', failed: 'Not saved. Please try again.' };
		try { msg = JSON.parse(form.dataset.i18n); } catch (e) { /* keep fallbacks */ }
		var saved = box.checked;
		var latest = 0;

		function show(text, failed) {
			status.textContent = text;
			status.classList.toggle('jl-reminder-failed', !!failed);
		}
		function restore() {
			box.checked = saved;
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
				if (!data || typeof data.on !== 'boolean') { throw new Error('bad response'); }
				saved = data.on;
				if (mine !== latest) { return; }
				restore();
				show(msg.saved, false);
			}).catch(function () {
				if (mine !== latest) { return; }
				restore();
				show(msg.failed, true);
			});
		}

		box.addEventListener('change', function () { send({ on: box.checked }); });
		form.addEventListener('submit', function (e) {
			e.preventDefault();
			send({ on: box.checked });
		});
	});
}());
