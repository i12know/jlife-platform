/* Share page (M7, #62, #98): the phone's share sheet (Web Share API) with a
 * copy fallback for the whole entry. Nothing here is sent to the server;
 * "Post to My Group" is a normal form submit. */
(function () {
	'use strict';
	var holder = document.getElementById('jl-share-data');
	var form = document.getElementById('jl-share-form');
	if (!holder || !form) { return; }
	var data = JSON.parse(holder.getAttribute('data-share'));
	var preview = document.getElementById('jl-preview');
	var chat = document.getElementById('jl-chat');
	var copy = document.getElementById('jl-copy');
	var note = document.getElementById('jl-copied');

	// The text is fixed (the whole entry): the page only previews, copies and shares it.
	function text() { return data.text; }

	function copyText() {
		var t = text();
		var done = function () { note.textContent = data.copied; };
		if (navigator.clipboard && navigator.clipboard.writeText) {
			navigator.clipboard.writeText(t).then(done, function () { selectPreview(); });
		} else { selectPreview(); }
	}
	function selectPreview() {
		var range = document.createRange();
		range.selectNodeContents(preview);
		var sel = window.getSelection();
		sel.removeAllRanges();
		sel.addRange(range);
	}

	if (navigator.share) {
		chat.hidden = false;
		chat.addEventListener('click', function () {
			navigator.share({ text: text() }).catch(function () { /* cancelled */ });
		});
	}
	copy.hidden = false;
	copy.addEventListener('click', copyText);

	// No double posts from a double tap.
	form.addEventListener('submit', function () {
		form.querySelectorAll('button[type=submit]').forEach(function (b) {
			setTimeout(function () { b.disabled = true; }, 0);
		});
	});
}());
