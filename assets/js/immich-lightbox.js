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
		// Skip if the link also contains a video (misinserted content).
		if ( link.querySelector('video') ) {
			return;
		}
		e.preventDefault();

		var overlay = document.createElement('div');
		overlay.className = 'immich-lightbox';

		var img = document.createElement('img');
		img.src = link.href;
		overlay.appendChild(img);

		// Don't show lightbox if the image fails to load.
		img.onerror = function () {
			overlay.remove();
		};

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
