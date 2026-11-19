/* The daily reading email checkbox on Today and My Marathon (#135). A change
 * saves at once: "Saved." on success; on failure the checkbox and language go
 * back to what is saved and a short retry message shows. Without this script
 * the form's Save button posts it instead. */
(function () {
	'use strict';
	document.querySelectorAll('form.jl-reminder-toggle').forEach(function (form) {
		var box = form.querySelector('input[type=checkbox][name=jl_reminder]');
		var lang = form.querySelector('select[name=jl_reminder_locale]');
		var status = form.querySelector('.jl-reminder-status');
		if (!box || !status) { return; }
		var msg = { saved: 'Saved.', failed: 'Not saved. Please try again.' };
		try { msg = JSON.parse(form.dataset.i18n); } catch (e) { /* keep fallbacks */ }
		var saved = { on: box.checked, locale: lang ? lang.value : '' };
		var latest = 0;

		function show(text, failed) {
			status.textContent = text;
			status.classList.toggle('jl-reminder-failed', !!failed);
		}
		function restore() {
			box.checked = saved.on;
			if (lang) { lang.value = saved.locale; }
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
				saved = { on: data.on, locale: typeof data.locale === 'string' ? data.locale : saved.locale };
				if (mine !== latest) { return; }
				restore();
				show(msg.saved, false);
			}).catch(function () {
				if (mine !== latest) { return; }
				restore();
				show(msg.failed, true);
			});
		}

		box.addEventListener('change', function () {
			var body = { on: box.checked };
			if (lang) { body.locale = saved.locale; } // Toggling never changes the language.
			send(body);
		});
		if (lang) {
			lang.addEventListener('change', function () { send({ locale: lang.value }); });
		}
		form.addEventListener('submit', function (e) {
			e.preventDefault();
			var body = { on: box.checked };
			if (lang) { body.locale = lang.value; }
			send(body);
		});
	});
}());
