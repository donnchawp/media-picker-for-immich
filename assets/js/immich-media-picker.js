(function ($, wp) {
	'use strict';

	if ( ! wp || ! wp.media || ! wp.media.view ) {
		return;
	}

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
			'click .immich-thumb': 'onThumbClick',
			'click .immich-use-btn': 'onUseClick',
			'click .immich-copy-btn': 'onCopyClick',
			'click .immich-used-thumb': 'onUsedThumbClick',
		},

		initialize: function () {
			this.selected = {};
			this.searchTimer = null;
			this.currentPage = 1;
			this.nextPage = null;
			this.loading = false;
			this.lastQuery = '';
			this.lastPersonId = '';
			this.render();
			this.loadPeople();
			this.loadUsedAssets();
		},

		render: function () {
			this.$el.html(
				'<div class="immich-toolbar">' +
					'<input type="search" class="immich-search-input" placeholder="Search photos..." />' +
					'<select class="immich-people-select"><option value="">All people</option></select>' +
					'<button type="button" class="button button-primary immich-use-btn" disabled>Use Selected</button>' +
					'<button type="button" class="button immich-copy-btn" disabled>Copy Selected</button>' +
				'</div>' +
				'<div class="immich-grid"></div>' +
				'<div class="immich-used-divider" style="display:none;"><span>Previously added</span></div>' +
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
			if ( this.loading || ! this.nextPage ) {
				return;
			}

			var $grid = this.$('.immich-grid');
			var scrollTop = $grid.scrollTop();
			var scrollHeight = $grid[0].scrollHeight;
			var clientHeight = $grid[0].clientHeight;

			if ( scrollTop + clientHeight >= scrollHeight - 200 ) {
				this.loadMore();
			}
		},

		doSearch: function () {
			var query = this.$('.immich-search-input').val();
			var personId = this.$('.immich-people-select').val();

			if ( ! query && ! personId ) {
				this.$('.immich-grid').empty();
				this.nextPage = null;
				this.lastQuery = '';
				this.lastPersonId = '';
				return;
			}

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
				this.$('.immich-status-text').text('Searching...');
			} else {
				this.$('.immich-status-text').text('Loading more...');
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
						self.$('.immich-status-text').text('Search failed. Please try again.');
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
					self.$('.immich-status-text').text('Request failed.');
					self.loading = false;
				},
			});
		},

		renderGrid: function (items) {
			var $grid = this.$('.immich-grid').empty();
			this.selected = {};
			this.updateButtons();

			if ( ! items.length ) {
				$grid.html('<p class="immich-no-results">No results found.</p>');
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
			this.$('.immich-use-btn').prop('disabled', count === 0)
				.text(count > 1 ? 'Use ' + count + ' Selected' : 'Use Selected');
			this.$('.immich-copy-btn').prop('disabled', count === 0)
				.text(count > 1 ? 'Copy ' + count + ' Selected' : 'Copy Selected');
		},

		onUseClick: function () {
			this._runAction('immich_use', this.$('.immich-use-btn'), 'Adding');
		},

		onCopyClick: function () {
			this._runAction('immich_import', this.$('.immich-copy-btn'), 'Copying');
		},

		_runAction: function (action, $progressBtn, verb) {
			var self = this;
			var ids = Object.keys(this.selected);
			if ( ! ids.length ) return;

			this.$('.immich-use-btn, .immich-copy-btn').prop('disabled', true);
			$progressBtn.text(verb + '...');

			var succeeded = 0;
			var failed = 0;
			var total = ids.length;

			function next(index) {
				if ( index >= ids.length ) {
					self._onActionComplete(succeeded, failed, verb);
					return;
				}

				$progressBtn.text(verb + ' ' + (index + 1) + ' of ' + total + '...');

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
							var attachment = wp.media.attachment(resp.data.attachmentId);
							attachment.fetch().then(function () {
								if ( self.controller.state().get('selection') ) {
									self.controller.state().get('selection').add(attachment);
								}
							});
						} else {
							failed++;
						}
						next(index + 1);
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
				this.$('.immich-status-text').text(succeeded + ' ' + verb.toLowerCase() + ', ' + failed + ' failed.');
			} else {
				this.$('.immich-status-text').text(succeeded + ' photo(s) ' + verb.toLowerCase() + '.');
			}

			this.selected = {};
			this.$('.immich-thumb').removeClass('selected');
			this.updateButtons();

			if ( succeeded > 0 ) {
				var library = this.controller.state().get('library');
				if ( library ) {
					library._requery( true );
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

					items.forEach(function (item) {
						var $thumb = $(
							'<div class="immich-used-thumb" data-attachment-id="' + _.escape(item.attachmentId) + '">' +
								'<img src="' + _.escape(item.thumbUrl) + '" alt="' + _.escape(item.title) + '" />' +
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
				this.usedNextPage = null; // prevent duplicate requests
				this.fetchUsedPage(nextPage);
			}
		},

		onUsedThumbClick: function (e) {
			var self = this;
			var $thumb = $(e.currentTarget);
			var attachmentId = $thumb.data('attachment-id');

			var attachment = wp.media.attachment(attachmentId);
			attachment.fetch().then(function () {
				if ( self.controller.state().get('selection') ) {
					self.controller.state().get('selection').add(attachment);
				}
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

})(jQuery, wp);
