/* Sign-out confirmation (#89): open the in-page dialog instead of following
 * the link to /signout/. Without JavaScript or <dialog> support the link
 * still leads to the same Cancel / Sign out choice on its own page. */
(function () {
	'use strict';
	var link = document.querySelector('.jl-signout');
	var dialog = document.getElementById('jl-signout-dialog');
	if (!link || !dialog || typeof dialog.showModal !== 'function') { return; }
	link.addEventListener('click', function (e) {
		e.preventDefault();
		dialog.showModal();
	});
	// Tapping the backdrop counts as Cancel.
	dialog.addEventListener('click', function (e) {
		if (e.target === dialog) { dialog.close('cancel'); }
	});
}());
