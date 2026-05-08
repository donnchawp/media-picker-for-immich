(function ($, wp) {
	'use strict';

	if (!wp || !wp.i18n) {
		return;
	}

	var __ = wp.i18n.__;
	var sprintf = wp.i18n.sprintf;
	var config = window.ImmichAdmin || {};

	$(function () {
		$(document).on('click', '.immich-test-btn', function () {
			var $btn = $(this);
			var context = $btn.data('immich-context');
			var $result = $btn.siblings('.immich-test-result');

			var url, key;
			if ('site' === context) {
				url = $('#immich_settings_api_url').val() || config.savedUrl || '';
				key = $('#immich_settings_api_key').val() || '';
			} else {
				url = config.savedUrl || '';
				key = $('#immich_api_key').val() || '';
			}

			$btn.prop('disabled', true);
			$result.removeClass('immich-test-ok immich-test-fail').empty();
			$result.append(
				$('<span class="spinner is-active" style="float:none;"></span>'),
				$('<span></span>').text(' ' + __('Testing…', 'media-picker-for-immich'))
			);

			$.post(config.ajaxUrl, {
				action: 'immich_test_connection',
				nonce: config.nonce,
				url: url,
				key: key,
			}).done(function (resp) {
				$btn.prop('disabled', false);
				$result.empty();

				var data = (resp && resp.data) || {};

				if (!resp || !resp.success || !data.ok) {
					$result.addClass('immich-test-fail');
					$result.append($('<span class="dashicons dashicons-warning"></span>'));
					$result.append($('<strong></strong>').text(' ' + (data.message || __('Connection failed.', 'media-picker-for-immich'))));
					return;
				}

				$result.addClass('immich-test-ok');
				$result.append($('<span class="dashicons dashicons-yes"></span>'));
				$result.append($('<strong></strong>').text(' ' + (data.message || __('Connected.', 'media-picker-for-immich'))));

				var scopes = data.scopes || {};
				var $list = $('<ul class="immich-test-scopes"></ul>');
				Object.keys(scopes).forEach(function (slug) {
					var status = scopes[slug];
					var icon, label;
					if ('ok' === status) {
						icon = 'dashicons-yes';
						label = __('granted', 'media-picker-for-immich');
					} else if ('missing' === status) {
						icon = 'dashicons-no';
						label = __('missing — update the key in Immich', 'media-picker-for-immich');
					} else if ('unverified' === status) {
						icon = 'dashicons-minus';
						label = __('not verified (no assets in library)', 'media-picker-for-immich');
					} else {
						icon = 'dashicons-warning';
						label = __('error', 'media-picker-for-immich');
					}
					var $li = $('<li></li>')
						.append($('<span class="dashicons"></span>').addClass(icon))
						.append(' ')
						.append($('<code></code>').text(slug))
						.append(' — ')
						.append(document.createTextNode(label));
					$list.append($li);
				});
				$result.append($list);
			}).fail(function (xhr) {
				$btn.prop('disabled', false);
				$result.empty().addClass('immich-test-fail');
				var data = (xhr.responseJSON && xhr.responseJSON.data) || {};
				$result.append($('<span class="dashicons dashicons-warning"></span>'));
				$result.append(' ');
				$result.append(document.createTextNode(data.message || __('Request failed.', 'media-picker-for-immich')));
			});
		});
	});
})(jQuery, window.wp);
