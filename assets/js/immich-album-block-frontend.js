/**
 * Frontend lightbox for the Immich Album Gallery block.
 *
 * Click any image inside a `.immich-album-gallery[data-immich-lightbox]` and
 * the fullsize variant of the same asset opens in a centred overlay. The
 * proxy URL just swaps `immich_media_proxy=preview` (or whatever the gallery
 * was rendered with) for `immich_media_proxy=fullsize` — the signed token
 * (`album_token`) authorises any size for the same asset+post pair.
 */
( function () {
	'use strict';

	function getFullsizeUrl( srcUrl ) {
		try {
			var u = new URL( srcUrl, window.location.origin );
			if ( ! u.searchParams.get( 'immich_media_proxy' ) ) {
				return srcUrl;
			}
			u.searchParams.set( 'immich_media_proxy', 'fullsize' );
			return u.toString();
		} catch ( e ) {
			return srcUrl;
		}
	}

	function openOverlay( url, alt ) {
		var overlay = document.createElement( 'div' );
		overlay.className = 'immich-album-overlay';
		overlay.setAttribute( 'role', 'dialog' );
		overlay.setAttribute( 'aria-modal', 'true' );
		overlay.tabIndex = -1;

		var stage = document.createElement( 'div' );
		stage.className = 'immich-album-overlay-stage';

		var img = document.createElement( 'img' );
		img.className = 'immich-album-overlay-img';
		img.src = url;
		img.alt = alt || '';

		var close = document.createElement( 'button' );
		close.type = 'button';
		close.className = 'immich-album-overlay-close';
		close.setAttribute( 'aria-label', 'Close' );
		close.textContent = '×';

		stage.appendChild( img );
		stage.appendChild( close );
		overlay.appendChild( stage );
		document.body.appendChild( overlay );
		overlay.focus();

		function dismiss() {
			document.removeEventListener( 'keydown', onKey );
			if ( overlay.parentNode ) {
				overlay.parentNode.removeChild( overlay );
			}
		}
		function onKey( e ) {
			if ( e.key === 'Escape' ) { dismiss(); }
		}
		document.addEventListener( 'keydown', onKey );
		// Any click on the overlay (backdrop, close button, or the image
		// itself) dismisses — matches the common lightbox UX where clicking
		// the zoomed image takes you back to the gallery.
		overlay.addEventListener( 'click', dismiss );
	}

	document.addEventListener( 'click', function ( e ) {
		var img = e.target && e.target.closest ? e.target.closest( '.immich-album-gallery img' ) : null;
		if ( ! img ) { return; }
		var gallery = img.closest( '.immich-album-gallery' );
		if ( ! gallery || ! gallery.hasAttribute( 'data-immich-lightbox' ) ) { return; }
		e.preventDefault();
		openOverlay( getFullsizeUrl( img.currentSrc || img.src ), img.alt );
	} );
}() );
