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
			this.render();
			this.loadPeople();
			this.loadUsedAssets();
			this.doBrowse();
		},

		render: function () {
			this.$el.html(
				'<div class="immich-toolbar">' +
					'<input type="search" class="immich-search-input" placeholder="' + _.escape( __( 'Search photos\u2026', 'immich-media-picker' ) ) + '" />' +
					'<select class="immich-people-select"><option value="">' + _.escape( __( 'All people', 'immich-media-picker' ) ) + '</option></select>' +
					'<button type="button" class="button immich-browse-btn">' + _.escape( __( 'Browse', 'immich-media-picker' ) ) + '</button>' +
					'<button type="button" class="button button-primary immich-use-btn" disabled>' + _.escape( __( 'Use Selected', 'immich-media-picker' ) ) + '</button>' +
					'<button type="button" class="button immich-copy-btn" disabled>' + _.escape( __( 'Copy Selected', 'immich-media-picker' ) ) + '</button>' +
				'</div>' +
				'<div class="immich-grid"></div>' +
				'<div class="immich-used-divider" style="display:none;"><span>' + _.escape( __( 'Previously added', 'immich-media-picker' ) ) + '</span></div>' +
				'<div class="immich-used-grid"></div>' +
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
				this.$('.immich-status-text').text( __( 'Loading latest photos\u2026', 'immich-media-picker' ) );
			} else {
				this.$('.immich-status-text').text( __( 'Loading more\u2026', 'immich-media-picker' ) );
			}

			$.ajax({
				url: config.ajaxUrl,
				method: 'GET',
				data: {
					action: 'immich_browse',
					nonce: config.nonce,
					page: page,
				},
				dataType: 'json',
				success: function (resp) {
					self.$('.spinner').removeClass('is-active');
					self.$('.immich-status-text').text('');
					self.loading = false;

					if ( ! resp.success ) {
						self.$('.immich-status-text').text( __( 'Failed to load photos.', 'immich-media-picker' ) );
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
					self.$('.immich-status-text').text( __( 'Request failed.', 'immich-media-picker' ) );
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

			this.$('.spinner').addClass('is-active');
			if ( ! append ) {
				this.$('.immich-status-text').text( __( 'Searching\u2026', 'immich-media-picker' ) );
			} else {
				this.$('.immich-status-text').text( __( 'Loading more\u2026', 'immich-media-picker' ) );
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
						self.$('.immich-status-text').text( __( 'Search failed. Please try again.', 'immich-media-picker' ) );
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
					self.$('.immich-status-text').text( __( 'Request failed.', 'immich-media-picker' ) );
					self.loading = false;
				},
			});
		},

		renderGrid: function (items) {
			var $grid = this.$('.immich-grid').empty();
			this.selected = {};
			this.updateButtons();

			if ( ! items.length ) {
				$grid.html('<p class="immich-no-results">' + _.escape( __( 'No results found.', 'immich-media-picker' ) ) + '</p>');
				return;
			}

			this.appendToGrid(items);
		},

		appendToGrid: function (items) {
			var $grid = this.$('.immich-grid');

			items.forEach(function (item) {
				var $thumb = $(
					'<div class="immich-thumb" data-id="' + _.escape(item.id) + '" data-filename="' + _.escape(item.filename) + '">' +
						'<img src="' + _.escape(item.thumbUrl) + '" alt="' + _.escape(item.filename) + '" />' +
						'<span class="immich-check dashicons dashicons-yes-alt"></span>' +
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
				? sprintf( __( 'Use %d Selected', 'immich-media-picker' ), count )
				: __( 'Use Selected', 'immich-media-picker' );
			var copyLabel = count > 1
				/* translators: %d: number of selected items */
				? sprintf( __( 'Copy %d Selected', 'immich-media-picker' ), count )
				: __( 'Copy Selected', 'immich-media-picker' );
			this.$('.immich-use-btn').prop('disabled', count === 0).text(useLabel);
			this.$('.immich-copy-btn').prop('disabled', count === 0).text(copyLabel);
		},

		onUseClick: function () {
			this._runAction('immich_use', this.$('.immich-use-btn'), __( 'Adding', 'immich-media-picker' ));
		},

		onCopyClick: function () {
			this._runAction('immich_import', this.$('.immich-copy-btn'), __( 'Copying', 'immich-media-picker' ));
		},

		_runAction: function (action, $progressBtn, verb) {
			var self = this;
			var ids = Object.keys(this.selected);
			if ( ! ids.length ) return;

			this.$('.immich-use-btn, .immich-copy-btn').prop('disabled', true);
			/* translators: %s: action verb (e.g. "Adding", "Copying") */
			$progressBtn.text( sprintf( __( '%s\u2026', 'immich-media-picker' ), verb ) );

			var succeeded = 0;
			var failed = 0;
			var total = ids.length;

			function next(index) {
				if ( index >= ids.length ) {
					self._onActionComplete(succeeded, failed, verb);
					return;
				}

				/* translators: 1: action verb, 2: current item number, 3: total items */
				$progressBtn.text( sprintf( __( '%1$s %2$d of %3$d\u2026', 'immich-media-picker' ), verb, index + 1, total ) );

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
				this.$('.immich-status-text').text( sprintf( __( '%1$d %2$s, %3$d failed.', 'immich-media-picker' ), succeeded, verb.toLowerCase(), failed ) );
			} else {
				/* translators: 1: number of photos, 2: action verb (lowercase) */
				this.$('.immich-status-text').text( sprintf( __( '%1$d photo(s) %2$s.', 'immich-media-picker' ), succeeded, verb.toLowerCase() ) );
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
			this.$('.immich-used-divider').hide();
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

					var items = resp.data.items || [];
					self.usedNextPage = resp.data.nextPage || null;

					if ( items.length > 0 ) {
						self.$('.immich-used-divider').show();
					}

					var selection = !self.isManageFrame &&
						self.controller.state() &&
						self.controller.state().get('selection');
					items.forEach(function (item) {
						var isSelected = selection && !!selection.get(item.attachmentId);
						var $thumb = $(
							'<div class="immich-used-thumb' + (isSelected ? ' selected' : '') + '" data-attachment-id="' + _.escape(item.attachmentId) + '">' +
								'<img src="' + _.escape(item.thumbUrl) + '" alt="' + _.escape(item.title) + '" />' +
								'<span class="immich-check dashicons dashicons-yes-alt"></span>' +
							'</div>'
						);
						self.$('.immich-used-grid').append($thumb);
					});
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
						text: wp.media.view.l10n.mediaLibraryTitle || __( 'Media Library', 'immich-media-picker' ),
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
