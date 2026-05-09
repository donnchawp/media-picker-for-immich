/**
 * Frontend lightbox for the Immich Album Gallery block.
 *
 * Click any image inside a `.immich-album-gallery[data-immich-lightbox]` and
 * the fullsize variant of the same asset opens in a centred overlay. The
 * proxy URL just swaps `immich_media_proxy=preview` (or whatever the gallery
 * was rendered with) for `immich_media_proxy=fullsize` — the signed token
 * (`album_token`) authorises any size for the same asset+post pair.
 *
 * While open the visitor can navigate to neighbouring images via on-screen
 * chevron buttons or the keyboard (arrows + Home/End). Dismissal is via the
 * close button, Esc, or clicking the dimmed backdrop — clicking the image
 * itself or the chevrons does not close.
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

	function openOverlay( images, startIndex, labels ) {
		var index = startIndex;

		var overlay = document.createElement( 'div' );
		overlay.className = 'immich-album-overlay';
		overlay.setAttribute( 'role', 'dialog' );
		overlay.setAttribute( 'aria-modal', 'true' );
		overlay.tabIndex = -1;

		var stage = document.createElement( 'div' );
		stage.className = 'immich-album-overlay-stage';

		var img = document.createElement( 'img' );
		img.className = 'immich-album-overlay-img';

		var prev = document.createElement( 'button' );
		prev.type = 'button';
		prev.className = 'immich-album-overlay-prev';
		prev.setAttribute( 'aria-label', labels.prev || 'Previous image' );
		prev.textContent = '‹';

		var next = document.createElement( 'button' );
		next.type = 'button';
		next.className = 'immich-album-overlay-next';
		next.setAttribute( 'aria-label', labels.next || 'Next image' );
		next.textContent = '›';

		var close = document.createElement( 'button' );
		close.type = 'button';
		close.className = 'immich-album-overlay-close';
		close.setAttribute( 'aria-label', labels.close || 'Close' );
		close.textContent = '×';

		function show() {
			var current = images[ index ];
			img.src = getFullsizeUrl( current.currentSrc || current.src );
			img.alt = current.alt || '';
		}

		function navigate( delta ) {
			// Wrap around at both ends.
			index = ( index + delta + images.length ) % images.length;
			show();
		}

		// Chevrons attach to the overlay (full viewport) so they stay pinned
		// to the page edges across portrait/landscape image swaps. Image and
		// close button stay on the stage, which sizes to the image.
		stage.appendChild( img );
		stage.appendChild( close );
		overlay.appendChild( prev );
		overlay.appendChild( stage );
		overlay.appendChild( next );
		document.body.appendChild( overlay );
		overlay.focus();

		show();

		function dismiss() {
			document.removeEventListener( 'keydown', onKey );
			if ( overlay.parentNode ) {
				overlay.parentNode.removeChild( overlay );
			}
		}
		function onKey( e ) {
			switch ( e.key ) {
				case 'Escape':     dismiss(); break;
				case 'ArrowRight': navigate( 1 ); break;
				case 'ArrowLeft':  navigate( -1 ); break;
				case 'Home':       index = 0; show(); break;
				case 'End':        index = images.length - 1; show(); break;
			}
		}
		document.addEventListener( 'keydown', onKey );

		// Backdrop-only dismissal: a click that bubbles to the overlay only
		// reaches it when the user clicked outside the stage (the dimmed
		// area). Clicks on the stage children — image, chevrons, close —
		// each have their own handler and don't dismiss.
		overlay.addEventListener( 'click', function ( e ) {
			if ( e.target === overlay ) { dismiss(); }
		} );
		close.addEventListener( 'click', dismiss );
		prev.addEventListener( 'click', function () { navigate( -1 ); } );
		next.addEventListener( 'click', function () { navigate( 1 ); } );
	}

	document.addEventListener( 'click', function ( e ) {
		var img = e.target && e.target.closest ? e.target.closest( '.immich-album-gallery img' ) : null;
		if ( ! img ) { return; }
		var gallery = img.closest( '.immich-album-gallery' );
		if ( ! gallery || ! gallery.hasAttribute( 'data-immich-lightbox' ) ) { return; }
		e.preventDefault();
		// Sibling list resolved at open time; gallery DOM may change later.
		// Labels come from the wrapper's data attributes so the translations
		// live in PHP (no wp-i18n dependency in this script).
		var images = Array.prototype.slice.call( gallery.querySelectorAll( 'img' ) );
		var index  = images.indexOf( img );
		if ( index < 0 ) { index = 0; }
		openOverlay( images, index, {
			close: gallery.getAttribute( 'data-immich-lightbox-close' ),
			prev:  gallery.getAttribute( 'data-immich-lightbox-prev' ),
			next:  gallery.getAttribute( 'data-immich-lightbox-next' )
		} );
	} );
}() );
