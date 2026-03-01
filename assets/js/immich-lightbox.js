(function () {
	'use strict';

	document.addEventListener('click', function (e) {
		var link = e.target.closest('a[href*="immich_media_proxy=original"]');
		if ( ! link ) {
			return;
		}
		e.preventDefault();

		var overlay = document.createElement('div');
		overlay.className = 'immich-lightbox';

		var img = document.createElement('img');
		img.src = link.href;
		overlay.appendChild(img);
		document.body.appendChild(overlay);

		// Force reflow then add visible class for transition.
		overlay.offsetHeight;
		overlay.classList.add('immich-lightbox-visible');

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
