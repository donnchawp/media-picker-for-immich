(function () {
	'use strict';

	document.addEventListener('click', function (e) {
		// Only trigger on <img> clicks inside Immich image links.
		if ( ! e.target.closest('img') ) {
			return;
		}
		var link = e.target.closest('a[href*="immich_media_proxy=original"]');
		if ( ! link ) {
			return;
		}
		e.preventDefault();

		var overlay = document.createElement('div');
		overlay.className = 'immich-lightbox';

		var img = new Image();
		img.onload = function () {
			overlay.appendChild(img);
			document.body.appendChild(overlay);
			overlay.offsetHeight;
			overlay.classList.add('immich-lightbox-visible');
		};
		img.onerror = function () {
			// Not an image — navigate normally.
			window.location = link.href;
		};
		img.src = link.href;

		function close() {
			overlay.classList.remove('immich-lightbox-visible');
			setTimeout(function () { overlay.remove(); }, 200);
			document.removeEventListener('keydown', onKey);
		}

		function onKey(e) {
			if ( e.key === 'Escape' ) {
				close();
			}
		}

		overlay.addEventListener('click', close);
		document.addEventListener('keydown', onKey);
	});
})();
