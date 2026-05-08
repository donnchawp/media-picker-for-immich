(function ($, wp) {
	'use strict';

	if ( ! wp || ! wp.media || ! wp.media.view ) {
		return;
	}

	var __ = wp.i18n.__;
	var _x = wp.i18n._x;
	var sprintf = wp.i18n.sprintf;
	var config = window.ImmichMediaPicker || {};

	/**
	 * Immich browser content view.
	 */
	var ImmichBrowser = wp.media.View.extend({
		className: 'immich-browser',
		template: false,

		events: {
			'input .immich-search-input': 'onSearchInput',
			'change .immich-people-select': 'onPeopleChange',
			'click .immich-browse-btn': 'onBrowseClick',
			'click .immich-thumb': 'onThumbClick',
			'click .immich-use-btn': 'onUseClick',
			'click .immich-copy-btn': 'onCopyClick',
			'click .immich-used-thumb': 'onUsedThumbClick',
			'pointerdown .immich-split-handle': 'onSplitPointerDown',
			'keydown .immich-split-handle': 'onSplitKeyDown',
			'click .immich-preview-btn': 'onPreviewBtnClick',
		},

		initialize: function (options) {
			this.selected = {};
			this.searchTimer = null;
			this.currentPage = 1;
			this.nextPage = null;
			this.loading = false;
			this.lastQuery = '';
			this.lastPersonId = '';
			this.browsing = false;
			this.browseNextPage = null;
			this.isManageFrame = !!(options && options.isManageFrame);
			this.allowedTypes = this._readAllowedTypes(this.controller, this.isManageFrame);
			this.usedCount = 0;
			this.splitPct = this._clampSplit(parseInt(config.splitPct, 10) || 70);
			this.render();
			this._applySplit(this.splitPct);
			this.loadPeople();
			this.loadUsedAssets();
			this.doBrowse();
		},

		/**
		 * Read the parent media frame's allowed media types so we can filter
		 * out assets the caller can't use (e.g. videos when the Image block
		 * opened the picker). Returns null when there's no constraint.
		 */
		_readAllowedTypes: function (controller, isManageFrame) {
			if (isManageFrame) {
				return null;
			}
			try {
				var state = controller && controller.state && controller.state();
				var library = state && state.get && state.get('library');
				var type = library && library.props && library.props.get && library.props.get('type');
				if (!type) {
					return null;
				}
				return _.isArray(type) ? type : [type];
			} catch (e) {
				return null;
			}
		},

		_assetMatchesAllowed: function (immichType) {
			if (!this.allowedTypes) {
				return true;
			}
			var wpType = 'VIDEO' === immichType ? 'video' : 'image';
			return this.allowedTypes.indexOf(wpType) !== -1;
		},

		/**
		 * If the parent frame restricts to a single media type that maps to
		 * an Immich asset type, return that ('IMAGE' or 'VIDEO') so the
		 * server can ask Immich for only matching assets. Returning null
		 * means "no server-side filter" (frame is unconstrained or allows
		 * multiple types we can't express in one Immich request).
		 */
		_immichRequestType: function () {
			if (!this.allowedTypes || this.allowedTypes.length !== 1) {
				return null;
			}
			if (this.allowedTypes[0] === 'image') return 'IMAGE';
			if (this.allowedTypes[0] === 'video') return 'VIDEO';
			return null;
		},

		_isAlbumMode: function () {
			try {
				var lib = this.controller && this.controller.state() && this.controller.state().get( 'library' );
				if ( ! lib ) { return false; }
				return lib.props.get( 'type' ) === 'immich-album';
			} catch ( e ) { return false; }
		},

		_renderAlbumGrid: function () {
			var self = this;
			this.$el.append(
				'<div class="immich-status">' + _.escape( __( 'Loading albums…', 'media-picker-for-immich' ) ) + '</div>' +
				'<div class="immich-album-grid"></div>'
			);

			wp.ajax.post( 'immich_albums', { nonce: config.nonce } )
				.done( function ( data ) {
					self.$el.find( '.immich-status' ).hide();
					var $grid = self.$el.find( '.immich-album-grid' );
					if ( ! $grid.length ) { return; }
					if ( ! data || ! data.items || ! data.items.length ) {
						$grid.html( '<p class="immich-empty">' + _.escape( __( 'No albums in this Immich server.', 'media-picker-for-immich' ) ) + '</p>' );
						return;
					}
					var html = data.items.map( function ( a ) {
						return '<button type="button" class="immich-album-tile"' +
							' data-album-id="' + _.escape( a.id ) + '"' +
							' data-album-name="' + _.escape( a.name ) + '">' +
							( a.thumbnail
								? '<img class="immich-album-tile-cover" src="' + _.escape( a.thumbnail ) + '" alt="" />'
								: '<span class="immich-album-tile-cover is-empty"></span>' ) +
							'<span class="immich-album-tile-name">' + _.escape( a.name ) + '</span>' +
							'<span class="immich-album-tile-count">' + _.escape( String( a.count ) ) + '</span>' +
							'</button>';
					} ).join( '' );
					$grid.html( html );
				} )
				.fail( function ( resp ) {
					self.$el.find( '.immich-status' )
						.text( ( resp && resp.message ) ? resp.message : __( 'Could not load albums.', 'media-picker-for-immich' ) );
				} );

			this.$el.off( 'click.immichAlbums' ).on( 'click.immichAlbums', '.immich-album-tile', function () {
				var $btn = $( this );
				self.controller.trigger( 'immich:album-selected', {
					id:   $btn.attr( 'data-album-id' ),
					name: $btn.attr( 'data-album-name' )
				} );
			} );
		},

		render: function () {
			if ( this._isAlbumMode() ) {
				this.$el.empty().addClass( 'immich-browser is-album-mode' );
				this._renderAlbumGrid();
				return this;
			}

			var headerLabel = __( 'Previously added', 'media-picker-for-immich' );
			var headerHelp  = __( 'Assets you have previously selected or copied from Immich. Reuse them without round-tripping to the server.', 'media-picker-for-immich' );
			var handleLabel = __( 'Resize the cached assets pane', 'media-picker-for-immich' );

			this.$el.html(
				'<div class="immich-toolbar">' +
					'<input type="search" class="immich-search-input" placeholder="' + _.escape( __( 'Search photos\u2026', 'media-picker-for-immich' ) ) + '" />' +
					'<select class="immich-people-select"><option value="">' + _.escape( __( 'All people', 'media-picker-for-immich' ) ) + '</option></select>' +
					'<button type="button" class="button immich-browse-btn">' + _.escape( __( 'Browse', 'media-picker-for-immich' ) ) + '</button>' +
					'<button type="button" class="button button-primary immich-use-btn" disabled>' + _.escape( __( 'Use Selected', 'media-picker-for-immich' ) ) + '</button>' +
					'<button type="button" class="button immich-copy-btn" disabled>' + _.escape( __( 'Copy Selected', 'media-picker-for-immich' ) ) + '</button>' +
				'</div>' +
				'<div class="immich-grid"></div>' +
				'<div class="immich-split-handle" role="separator" aria-orientation="horizontal" tabindex="0" aria-label="' + _.escape( handleLabel ) + '"></div>' +
				'<div class="immich-used-pane">' +
					'<div class="immich-used-header">' +
						'<span class="immich-used-title">' + _.escape( headerLabel ) + '</span>' +
						'<span class="immich-used-count" aria-hidden="true">0</span>' +
						'<span class="dashicons dashicons-info-outline immich-used-info" role="img" tabindex="0" aria-label="' + _.escape( headerHelp ) + '" title="' + _.escape( headerHelp ) + '"></span>' +
					'</div>' +
					'<div class="immich-used-grid"></div>' +
				'</div>' +
				'<div class="immich-status"><span class="spinner"></span><span class="immich-status-text"></span></div>'
			);

			var self = this;
			this.$('.immich-grid').on('scroll', function () {
				self.onGridScroll();
			});

			this.$('.immich-used-grid').on('scroll', function () {
				self.onUsedGridScroll();
			});

			return this;
		},

		_clampSplit: function (pct) {
			pct = Math.round(pct);
			if (pct < 10) return 10;
			if (pct > 90) return 90;
			return pct;
		},

		_applySplit: function (pct) {
			this.splitPct = this._clampSplit(pct);
			this.$el[0].style.setProperty('--immich-live-flex', this.splitPct);
			this.$el[0].style.setProperty('--immich-used-flex', 100 - this.splitPct);
			this.$('.immich-split-handle').attr('aria-valuenow', this.splitPct);
		},

		_savePickerSplit: function () {
			$.post(config.ajaxUrl, {
				action: 'immich_save_picker_split',
				nonce: config.nonce,
				pct: this.splitPct,
			});
		},

		onSplitPointerDown: function (e) {
			var self = this;
			var handle = e.currentTarget;
			handle.setPointerCapture(e.pointerId);
			e.preventDefault();

			var browser = this.$el[0];
			var rect = browser.getBoundingClientRect();
			var toolbarH = this.$('.immich-toolbar').outerHeight() || 0;
			var statusH = this.$('.immich-status').outerHeight() || 0;
			var handleH = handle.offsetHeight || 0;
			var available = rect.height - toolbarH - statusH - handleH;

			if (available <= 0) {
				return;
			}

			function onMove(ev) {
				var y = ev.clientY - rect.top - toolbarH;
				var pct = Math.round((y / available) * 100);
				self._applySplit(pct);
			}
			function onEnd() {
				try {
					handle.releasePointerCapture(e.pointerId);
				} catch (err) { /* pointer already released */ }
				handle.removeEventListener('pointermove', onMove);
				handle.removeEventListener('pointerup', onEnd);
				handle.removeEventListener('pointercancel', onEnd);
				self._savePickerSplit();
			}

			handle.addEventListener('pointermove', onMove);
			handle.addEventListener('pointerup', onEnd);
			handle.addEventListener('pointercancel', onEnd);
		},

		onSplitKeyDown: function (e) {
			var step = e.shiftKey ? 10 : 5;
			var pct = this.splitPct;
			switch (e.key) {
				case 'ArrowUp':
				case 'ArrowLeft':
					pct -= step;
					break;
				case 'ArrowDown':
				case 'ArrowRight':
					pct += step;
					break;
				case 'Home':
					pct = 10;
					break;
				case 'End':
					pct = 90;
					break;
				default:
					return;
			}
			e.preventDefault();
			this._applySplit(pct);
			this._savePickerSplit();
		},

		/**
		 * Parse Immich's duration string ("0:01:23.456" or seconds) into "m:ss".
		 */
		_durationLabel: function (raw) {
			if (!raw) return '';
			var s = String(raw);
			var totalSeconds = 0;
			if (s.indexOf(':') !== -1) {
				var parts = s.split(':');
				for (var i = 0; i < parts.length; i++) {
					totalSeconds = totalSeconds * 60 + parseFloat(parts[i] || '0');
				}
			} else {
				totalSeconds = parseFloat(s);
			}
			if (!isFinite(totalSeconds) || totalSeconds < 0) return '';
			totalSeconds = Math.round(totalSeconds);
			var minutes = Math.floor(totalSeconds / 60);
			var seconds = totalSeconds % 60;
			return minutes + ':' + (seconds < 10 ? '0' : '') + seconds;
		},

		_videoOverlayHtml: function () {
			return '<span class="immich-video-icon dashicons dashicons-controls-play" aria-hidden="true"></span>';
		},

		_durationBadgeHtml: function (duration) {
			var label = this._durationLabel(duration);
			if (!label) return '';
			return '<span class="immich-duration-badge" aria-hidden="true">' + _.escape(label) + '</span>';
		},

		_previewBtnHtml: function () {
			var label = __( 'Preview', 'media-picker-for-immich' );
			return '<button type="button" class="immich-preview-btn" aria-label="' + _.escape(label) + '" title="' + _.escape(label) + '"><span class="dashicons dashicons-visibility" aria-hidden="true"></span></button>';
		},

		_previewProxyUrl: function (id, mediaType) {
			var proxyType = 'VIDEO' === mediaType ? 'video' : 'preview';
			var sep = config.proxyUrl && config.proxyUrl.indexOf('?') !== -1 ? '&' : '?';
			return (config.proxyUrl || '/') + sep
				+ 'immich_media_proxy=' + encodeURIComponent(proxyType)
				+ '&id=' + encodeURIComponent(id)
				+ '&preview_nonce=' + encodeURIComponent(config.previewNonce || '');
		},

		onPreviewBtnClick: function (e) {
			e.preventDefault();
			e.stopPropagation();
			e.stopImmediatePropagation();
			var $tile = $(e.currentTarget).closest('.immich-thumb, .immich-used-thumb');
			if (!$tile.length) return;
			var assetId   = $tile.attr('data-id') || '';
			var mediaType = $tile.attr('data-type') || 'IMAGE';
			var filename  = $tile.attr('data-filename') || '';
			if (!assetId) return;
			this._openPreview(assetId, mediaType, filename);
		},

		_openPreview: function (assetId, mediaType, filename) {
			this._closePreview();

			var self = this;
			var url = this._previewProxyUrl(assetId, mediaType);
			var media;
			if ('VIDEO' === mediaType) {
				media = $('<video class="immich-preview-media" controls autoplay playsinline></video>')
					.attr('src', url);
			} else {
				media = $('<img class="immich-preview-media" alt="" />').attr('src', url);
				if (filename) {
					media.attr('alt', filename);
				}
			}

			var closeLabel = __( 'Close preview', 'media-picker-for-immich' );
			var $overlay = $(
				'<div class="immich-preview-overlay" role="dialog" aria-modal="true" tabindex="-1">' +
					'<button type="button" class="immich-preview-close" aria-label="' + _.escape(closeLabel) + '" title="' + _.escape(closeLabel) + '"><span class="dashicons dashicons-no-alt" aria-hidden="true"></span></button>' +
					'<div class="immich-preview-stage"></div>' +
				'</div>'
			);
			$overlay.find('.immich-preview-stage').append(media);
			this.$el.append($overlay);

			$overlay.on('click', function (ev) {
				if (ev.target === $overlay[0] || $(ev.target).closest('.immich-preview-close').length) {
					self._closePreview();
				}
			});

			this._previewKeyHandler = function (ev) {
				if (ev.key === 'Escape') {
					self._closePreview();
				}
			};
			document.addEventListener('keydown', this._previewKeyHandler);

			$overlay.focus();
		},

		_closePreview: function () {
			var $overlay = this.$('.immich-preview-overlay');
			if ($overlay.length) {
				$overlay.find('video').each(function () { try { this.pause(); } catch (e) {} });
				$overlay.remove();
			}
			if (this._previewKeyHandler) {
				document.removeEventListener('keydown', this._previewKeyHandler);
				this._previewKeyHandler = null;
			}
		},

		_modeBadgeHtml: function (addMode) {
			var isCopy = 'copy' === addMode;
			var label  = isCopy
				? __( 'Copied (in Media Library)', 'media-picker-for-immich' )
				: __( 'Selected (proxied)', 'media-picker-for-immich' );
			var letter = isCopy ? 'C' : 'S';
			return '<span class="immich-mode-badge" aria-label="' + _.escape(label) + '" title="' + _.escape(label) + '">' + letter + '</span>';
		},

		_renderUsedEmptyState: function () {
			var copy = __( 'No assets yet. Assets you Select or Copy from Immich will appear here for quick reuse.', 'media-picker-for-immich' );
			this.$('.immich-used-grid').html('<p class="immich-empty-state">' + _.escape(copy) + '</p>');
		},

		_updateUsedCount: function (delta) {
			this.usedCount = Math.max(0, this.usedCount + delta);
			this.$('.immich-used-count').text(this.usedCount);
		},

		loadPeople: function () {
			var self = this;
			$.ajax({
				url: config.ajaxUrl,
				method: 'GET',
				data: { action: 'immich_people', nonce: config.nonce },
				dataType: 'json',
				success: function (resp) {
					if ( ! resp.success || ! resp.data ) return;
					var $select = self.$('.immich-people-select');
					resp.data.forEach(function (person) {
						$select.append(
							$('<option>').val(person.id).text(person.name)
						);
					});
				},
				error: function () {
					console.warn('[Immich] Failed to load people filter.');
				},
			});
		},

		onBrowseClick: function () {
			this.$('.immich-search-input').val('');
			this.$('.immich-people-select').val('');
			this.lastQuery = '';
			this.lastPersonId = '';
			this.doBrowse();
		},

		doBrowse: function () {
			this.browsing = true;
			this.browseNextPage = null;
			this.selected = {};
			this.updateButtons();
			this.$('.immich-grid').empty();
			this.fetchBrowsePage(1);
		},

		fetchBrowsePage: function (page) {
			var self = this;
			this.loading = true;
			this.$('.spinner').addClass('is-active');
			if ( page === 1 ) {
				this.$('.immich-status-text').text( __( 'Loading latest photos\u2026', 'media-picker-for-immich' ) );
			} else {
				this.$('.immich-status-text').text( __( 'Loading more\u2026', 'media-picker-for-immich' ) );
			}

			var browseData = {
				action: 'immich_browse',
				nonce: config.nonce,
				page: page,
			};
			var browseType = this._immichRequestType();
			if (browseType) {
				browseData.assetType = browseType;
			}

			$.ajax({
				url: config.ajaxUrl,
				method: 'GET',
				data: browseData,
				dataType: 'json',
				success: function (resp) {
					self.$('.spinner').removeClass('is-active');
					self.$('.immich-status-text').text('');
					self.loading = false;

					if ( ! resp.success ) {
						self.$('.immich-status-text').text( __( 'Failed to load photos.', 'media-picker-for-immich' ) );
						return;
					}

					var items = resp.data.items || [];
					self.browseNextPage = resp.data.nextPage || null;

					if ( page === 1 ) {
						self.renderGrid(items);
					} else {
						self.appendToGrid(items);
					}
				},
				error: function () {
					self.$('.spinner').removeClass('is-active');
					self.$('.immich-status-text').text( __( 'Request failed.', 'media-picker-for-immich' ) );
					self.loading = false;
				},
			});
		},

		onSearchInput: function () {
			var self = this;
			clearTimeout(this.searchTimer);
			this.searchTimer = setTimeout(function () {
				self.doSearch();
			}, 400);
		},

		onPeopleChange: function () {
			this.doSearch();
		},

		onGridScroll: function () {
			if ( this.loading ) {
				return;
			}

			var $grid = this.$('.immich-grid');
			var scrollTop = $grid.scrollTop();
			var scrollHeight = $grid[0].scrollHeight;
			var clientHeight = $grid[0].clientHeight;

			if ( scrollTop + clientHeight < scrollHeight - 200 ) {
				return;
			}

			if ( this.browsing && this.browseNextPage ) {
				var nextPage = this.browseNextPage;
				this.browseNextPage = null;
				this.fetchBrowsePage(nextPage);
			} else if ( ! this.browsing && this.nextPage ) {
				this.loadMore();
			}
		},

		doSearch: function () {
			var query = this.$('.immich-search-input').val();
			var personId = this.$('.immich-people-select').val();

			if ( ! query && ! personId ) {
				this.doBrowse();
				return;
			}

			this.browsing = false;
			this.lastQuery = query;
			this.lastPersonId = personId;
			this.currentPage = 1;
			this.nextPage = null;
			this.selected = {};
			this.updateButtons();
			this.$('.immich-grid').empty();

			this.fetchPage(1, false);
		},

		loadMore: function () {
			if ( ! this.nextPage ) return;
			this.fetchPage(this.nextPage, true);
		},

		fetchPage: function (page, append) {
			var self = this;
			this.loading = true;

			var data = {
				action: 'immich_search',
				nonce: config.nonce,
				page: page,
			};

			if (this.lastPersonId) {
				data.personIds = [this.lastPersonId];
			}
			if (this.lastQuery) {
				data.query = this.lastQuery;
			}
			var searchType = this._immichRequestType();
			if (searchType) {
				data.assetType = searchType;
			}

			this.$('.spinner').addClass('is-active');
			if ( ! append ) {
				this.$('.immich-status-text').text( __( 'Searching\u2026', 'media-picker-for-immich' ) );
			} else {
				this.$('.immich-status-text').text( __( 'Loading more\u2026', 'media-picker-for-immich' ) );
			}

			$.ajax({
				url: config.ajaxUrl,
				method: 'POST',
				data: data,
				dataType: 'json',
				success: function (resp) {
					self.$('.spinner').removeClass('is-active');
					self.$('.immich-status-text').text('');
					self.loading = false;

					if ( ! resp.success ) {
						self.$('.immich-status-text').text( __( 'Search failed. Please try again.', 'media-picker-for-immich' ) );
						return;
					}

					var items = resp.data.items || [];
					self.nextPage = resp.data.nextPage || null;

					if ( append ) {
						self.appendToGrid(items);
					} else {
						self.renderGrid(items);
					}
				},
				error: function () {
					self.$('.spinner').removeClass('is-active');
					self.$('.immich-status-text').text( __( 'Request failed.', 'media-picker-for-immich' ) );
					self.loading = false;
				},
			});
		},

		renderGrid: function (items) {
			var $grid = this.$('.immich-grid').empty();
			this.selected = {};
			this.updateButtons();

			if ( ! items.length ) {
				$grid.html('<p class="immich-no-results">' + _.escape( __( 'No results found.', 'media-picker-for-immich' ) ) + '</p>');
				return;
			}

			this.appendToGrid(items);
		},

		appendToGrid: function (items) {
			var self = this;
			var $grid = this.$('.immich-grid');

			items.forEach(function (item) {
				if ( ! self._assetMatchesAllowed(item.type) ) {
					return;
				}
				var isVideo = 'VIDEO' === item.type;
				var $thumb = $(
					'<div class="immich-thumb' + (isVideo ? ' is-video' : '') + '" data-id="' + _.escape(item.id) + '" data-type="' + _.escape(item.type || 'IMAGE') + '" data-filename="' + _.escape(item.filename) + '">' +
						'<img src="' + _.escape(item.thumbUrl) + '" alt="' + _.escape(item.filename) + '" />' +
						(isVideo ? self._videoOverlayHtml() : '') +
						(isVideo ? self._durationBadgeHtml(item.duration) : '') +
						'<span class="immich-check dashicons dashicons-yes-alt"></span>' +
						self._previewBtnHtml() +
					'</div>'
				);
				$grid.append($thumb);
			});
		},

		onThumbClick: function (e) {
			var $thumb = $(e.currentTarget);
			var id = $thumb.data('id');

			if ( this.selected[id] ) {
				delete this.selected[id];
				$thumb.removeClass('selected');
			} else {
				this.selected[id] = true;
				$thumb.addClass('selected');
			}

			this.updateButtons();
		},

		updateButtons: function () {
			var count = Object.keys(this.selected).length;
			var useLabel = count > 1
				/* translators: %d: number of selected items */
				? sprintf( __( 'Use %d Selected', 'media-picker-for-immich' ), count )
				: __( 'Use Selected', 'media-picker-for-immich' );
			var copyLabel = count > 1
				/* translators: %d: number of selected items */
				? sprintf( __( 'Copy %d Selected', 'media-picker-for-immich' ), count )
				: __( 'Copy Selected', 'media-picker-for-immich' );
			this.$('.immich-use-btn').prop('disabled', count === 0).text(useLabel);
			this.$('.immich-copy-btn').prop('disabled', count === 0).text(copyLabel);
		},

		onUseClick: function () {
			this._runAction('immich_use', this.$('.immich-use-btn'), __( 'Adding', 'media-picker-for-immich' ));
		},

		onCopyClick: function () {
			this._runAction('immich_import', this.$('.immich-copy-btn'), __( 'Copying', 'media-picker-for-immich' ));
		},

		_runAction: function (action, $progressBtn, verb) {
			var self = this;
			var ids = Object.keys(this.selected);
			if ( ! ids.length ) return;

			var mismatched = ids.filter(function (id) {
				var type = self.$('.immich-thumb[data-id="' + id + '"]').data('type');
				return type && ! self._assetMatchesAllowed(type);
			});
			if ( mismatched.length ) {
				this.$('.immich-status-text').text( __( 'One or more selected assets are not allowed in this context.', 'media-picker-for-immich' ) );
				return;
			}

			this.$('.immich-use-btn, .immich-copy-btn').prop('disabled', true);
			/* translators: %s: action verb (e.g. "Adding", "Copying") */
			$progressBtn.text( sprintf( __( '%s\u2026', 'media-picker-for-immich' ), verb ) );

			var succeeded = 0;
			var failed = 0;
			var total = ids.length;

			function next(index) {
				if ( index >= ids.length ) {
					self._onActionComplete(succeeded, failed, verb);
					return;
				}

				/* translators: 1: action verb, 2: current item number, 3: total items */
				$progressBtn.text( sprintf( __( '%1$s %2$d of %3$d\u2026', 'media-picker-for-immich' ), verb, index + 1, total ) );

				$.ajax({
					url: config.ajaxUrl,
					method: 'POST',
					data: {
						action: action,
						nonce: config.nonce,
						id: ids[index],
					},
					dataType: 'json',
					success: function (resp) {
						if ( resp.success && resp.data && resp.data.attachmentId ) {
							succeeded++;
							if ( self.isManageFrame ) {
								next(index + 1);
							} else {
								var attachment = wp.media.attachment(resp.data.attachmentId);
								attachment.fetch().then(function () {
									if ( self.controller.state().get('selection') ) {
										self.controller.state().get('selection').add(attachment);
									}
									next(index + 1);
								}, function () {
									next(index + 1);
								});
							}
						} else {
							failed++;
							next(index + 1);
						}
					},
					error: function () {
						failed++;
						next(index + 1);
					},
				});
			}

			next(0);
		},

		_onActionComplete: function (succeeded, failed, verb) {
			if ( failed > 0 ) {
				/* translators: 1: number succeeded, 2: action verb (lowercase), 3: number failed */
				this.$('.immich-status-text').text( sprintf( __( '%1$d %2$s, %3$d failed.', 'media-picker-for-immich' ), succeeded, verb.toLowerCase(), failed ) );
			} else {
				/* translators: 1: number of photos, 2: action verb (lowercase) */
				this.$('.immich-status-text').text( sprintf( __( '%1$d photo(s) %2$s.', 'media-picker-for-immich' ), succeeded, verb.toLowerCase() ) );
			}

			this.selected = {};
			this.$('.immich-thumb').removeClass('selected');
			this.updateButtons();

			if ( succeeded > 0 ) {
				if ( this.controller.state() && this.controller.state().get('library') ) {
					this.controller.state().get('library')._requery( true );
				}
				this.loadUsedAssets();
			}
		},

		loadUsedAssets: function () {
			if ( this.usedLoading ) return;
			this.usedNextPage = null;
			this.$('.immich-used-grid').empty();
			this.usedCount = 0;
			this._updateUsedCount(0);
			this.fetchUsedPage(1);
		},

		fetchUsedPage: function (page) {
			var self = this;
			this.usedLoading = true;

			$.ajax({
				url: config.ajaxUrl,
				method: 'GET',
				data: {
					action: 'immich_used_assets',
					nonce: config.nonce,
					page: page,
				},
				dataType: 'json',
				success: function (resp) {
					self.usedLoading = false;
					if ( ! resp.success ) return;

					var items = (resp.data.items || []).filter(function (item) {
						return self._assetMatchesAllowed(item.type);
					});
					self.usedNextPage = resp.data.nextPage || null;

					var $grid = self.$('.immich-used-grid');
					$grid.find('.immich-empty-state').remove();

					if ( items.length === 0 && page === 1 && self.usedCount === 0 ) {
						self._renderUsedEmptyState();
						return;
					}

					var selection = !self.isManageFrame &&
						self.controller.state() &&
						self.controller.state().get('selection');
					items.forEach(function (item) {
						var isSelected = selection && !!selection.get(item.attachmentId);
						var addMode   = 'copy' === item.addMode ? 'copy' : 'select';
						var isVideo   = 'VIDEO' === item.type;
						var $thumb = $(
							'<div class="immich-used-thumb' + (isSelected ? ' selected' : '') + (isVideo ? ' is-video' : '') + '" data-attachment-id="' + _.escape(item.attachmentId) + '" data-id="' + _.escape(item.immichId || '') + '" data-type="' + _.escape(item.type || 'IMAGE') + '" data-add-mode="' + _.escape(addMode) + '" data-filename="' + _.escape(item.title || '') + '">' +
								'<img src="' + _.escape(item.thumbUrl) + '" alt="' + _.escape(item.title) + '" />' +
								(isVideo ? self._videoOverlayHtml() : '') +
								'<span class="immich-check dashicons dashicons-yes-alt"></span>' +
								self._modeBadgeHtml(addMode) +
								self._previewBtnHtml() +
							'</div>'
						);
						$grid.append($thumb);
					});
					self._updateUsedCount(items.length);
				},
				error: function () {
					self.usedLoading = false;
				},
			});
		},

		onUsedGridScroll: function () {
			if ( ! this.usedNextPage ) return;

			var $grid = this.$('.immich-used-grid');
			var scrollTop = $grid.scrollTop();
			var scrollHeight = $grid[0].scrollHeight;
			var clientHeight = $grid[0].clientHeight;

			if ( scrollTop + clientHeight >= scrollHeight - 200 ) {
				var nextPage = this.usedNextPage;
				this.usedNextPage = null;
				this.fetchUsedPage(nextPage);
			}
		},

		onUsedThumbClick: function (e) {
			var self = this;
			var $thumb = $(e.currentTarget);
			var attachmentId = $thumb.data('attachment-id');

			if ( this.isManageFrame ) {
				return;
			}

			var selection = this.controller.state().get('selection');
			if ( ! selection ) return;

			if ( selection.get(attachmentId) ) {
				selection.remove(selection.get(attachmentId));
				$thumb.removeClass('selected');
				return;
			}

			var attachment = wp.media.attachment(attachmentId);
			attachment.fetch().then(function () {
				selection.add(attachment);
				$thumb.addClass('selected');
			}, function () {
				console.warn('[Immich] Attachment ' + attachmentId + ' could not be fetched.');
			});
		},
	});

	/**
	 * Hook into the Post media frame to add the Immich tab.
	 */
	var originalPostRouter = wp.media.view.MediaFrame.Post.prototype.browseRouter;

	wp.media.view.MediaFrame.Post.prototype.browseRouter = function (routerView) {
		originalPostRouter.call(this, routerView);
		routerView.set('immich', {
			text: 'Immich',
			priority: 60,
		});
	};

	var originalPostBind = wp.media.view.MediaFrame.Post.prototype.bindHandlers;

	wp.media.view.MediaFrame.Post.prototype.bindHandlers = function () {
		originalPostBind.call(this);
		this.on('content:create:immich', function () {
			var view = new ImmichBrowser({
				controller: this,
			});
			this.content.set(view);
		}, this);
	};

	/**
	 * Hook into the Select media frame (featured image, etc).
	 */
	if ( wp.media.view.MediaFrame.Select.prototype.browseRouter ) {
		var originalSelectRouter = wp.media.view.MediaFrame.Select.prototype.browseRouter;

		wp.media.view.MediaFrame.Select.prototype.browseRouter = function (routerView) {
			originalSelectRouter.call(this, routerView);
			routerView.set('immich', {
				text: 'Immich',
				priority: 60,
			});
		};

		var originalSelectBind = wp.media.view.MediaFrame.Select.prototype.bindHandlers;

		wp.media.view.MediaFrame.Select.prototype.bindHandlers = function () {
			originalSelectBind.call(this);
			this.on('content:create:immich', function () {
				var view = new ImmichBrowser({
					controller: this,
				});
				this.content.set(view);
			}, this);
		};
	}

	/**
	 * Hook into the Manage media frame (Media > Library grid mode at upload.php).
	 *
	 * Unlike Post/Select frames, Manage never binds router events so the
	 * router region stays empty. We hook into bindRegionModeHandlers to
	 * wire up the router and add an Immich content handler.
	 *
	 * The frame is instantiated on DOM ready via media.js, so prototype
	 * overrides here take effect before instantiation.
	 */
	if ( wp.media.view.MediaFrame.Manage ) {
		var originalManageBindRegion = wp.media.view.MediaFrame.Manage.prototype.bindRegionModeHandlers;

		wp.media.view.MediaFrame.Manage.prototype.bindRegionModeHandlers = function () {
			originalManageBindRegion.call(this);

			// Bind router creation and rendering (Manage never does this itself).
			this.on( 'router:create:browse', this.createRouter, this );
			this.on( 'router:render:browse', function ( routerView ) {
				routerView.set({
					browse: {
						text: wp.media.view.l10n.mediaLibraryTitle || __( 'Media Library', 'media-picker-for-immich' ),
						priority: 40,
					},
					immich: {
						text: 'Immich',
						priority: 60,
					},
				});
			}, this );

			// Handle Immich content creation.
			this.on( 'content:create:immich', function () {
				var view = new ImmichBrowser({
					controller: this,
					isManageFrame: true,
				});
				this.content.set( view );
			}, this );
		};
	}

})(jQuery, wp);
