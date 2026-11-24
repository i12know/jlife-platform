/* Home-screen tip (#251): show the hint once, on phone-width screens, never
 * inside the installed app, and remember a dismissal in this browser only
 * (localStorage). Nothing is sent or stored anywhere else. */
(function () {
	'use strict';
	var hint = document.querySelector('.jl-launcher-hint');
	if (!hint) { return; }
	var KEY = 'jlife_launcher_hint_dismissed';
	function read() {
		try { return window.localStorage.getItem(KEY); } catch (e) { return null; }
	}
	function write() {
		try { window.localStorage.setItem(KEY, '1'); } catch (e) { /* Private mode: it may show again. */ }
	}
	function matches(query) {
		return !!(window.matchMedia && window.matchMedia(query).matches);
	}
	if (read() || window.navigator.standalone === true || matches('(display-mode: standalone)') || !matches('(max-width: 600px)')) {
		return;
	}
	var button = hint.querySelector('.jl-launcher-dismiss');
	if (button) {
		button.addEventListener('click', function () {
			write();
			hint.hidden = true;
		});
	}
	hint.hidden = false;
}());
