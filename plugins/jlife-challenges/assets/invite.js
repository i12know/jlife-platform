/* Invite code and link (#112): copy buttons. Nothing is sent anywhere. Without
 * JavaScript the buttons stay hidden and the code and link are plain text to
 * select. */
(function () {
	'use strict';
	var note = document.getElementById('jl-copied');
	document.querySelectorAll('[data-copy]').forEach(function (btn) {
		btn.hidden = false;
		btn.addEventListener('click', function () {
			var text = btn.getAttribute('data-copy');
			var done = function () { if (note) { note.textContent = btn.getAttribute('data-done') || ''; } };
			if (navigator.clipboard && navigator.clipboard.writeText) {
				navigator.clipboard.writeText(text).then(done, function () { /* select it by hand */ });
			}
		});
	});
}());
