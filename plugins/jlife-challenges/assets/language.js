/* The Language setting on My Marathon (#159). A change saves at once and the
 * page reloads in the new language (screens, emails and the default Bible all
 * follow it). On failure the select goes back to what is saved and a short
 * retry message shows. Without this script the form's Save button posts it. */
(function () {
	'use strict';
	document.querySelectorAll('form.jl-language-form').forEach(function (form) {
		var select = form.querySelector('select[name=jl_language]');
		var status = form.querySelector('.jl-language-status');
		if (!select || !status) { return; }
		var msg = { saving: 'Saving…', failed: 'Not saved. Please try again.' };
		try { msg = JSON.parse(form.dataset.i18n); } catch (e) { /* keep fallbacks */ }
		var saved = select.value;
		var busy = false;

		function show(text, failed) {
			status.textContent = text;
			status.classList.toggle('jl-reminder-failed', !!failed);
		}
		function send() {
			var wanted = select.value;
			if (busy || wanted === saved) { return; }
			busy = true;
			select.disabled = true;
			show(msg.saving, false);
			fetch(form.dataset.rest, {
				method: 'POST',
				credentials: 'same-origin',
				headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': form.dataset.nonce },
				body: JSON.stringify({ locale: wanted })
			}).then(function (r) {
				if (!r.ok) { throw new Error(String(r.status)); }
				return r.json();
			}).then(function (data) {
				if (!data || data.locale !== wanted) { throw new Error('bad response'); }
				window.location.replace(form.dataset.done);
			}).catch(function () {
				busy = false;
				select.disabled = false;
				select.value = saved;
				show(msg.failed, true);
			});
		}

		select.addEventListener('change', send);
		form.addEventListener('submit', function (e) {
			e.preventDefault();
			send();
		});
	});
}());
