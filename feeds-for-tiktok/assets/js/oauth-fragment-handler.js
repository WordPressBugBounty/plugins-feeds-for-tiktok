/**
 * OAuth Fragment Handler
 *
 * Captures OAuth tokens from URL fragment and sends them to the backend via AJAX.
 * This approach avoids server URL length limits since fragments are not sent to the server.
 *
 * Also includes toast notification system for success/error feedback.
 *
 * @package tiktok-feeds
 */

(function (window) {
	'use strict';

	/**
	 * SVG Icons matching the customizer icon set
	 */
	var icons = {
		success: '<svg width="13" height="10" viewBox="0 0 13 10" fill="none" xmlns="http://www.w3.org/2000/svg"><path fill-rule="evenodd" clip-rule="evenodd" d="M4.46447 6.88917L10.8284 0.525204L12.2426 1.93942L4.46447 9.71759L0.221826 5.47495L1.63604 4.06074L4.46447 6.88917Z"/></svg>',
		error: '<svg width="2" height="10" viewBox="0 0 2 10" fill="none" xmlns="http://www.w3.org/2000/svg"><path d="M0.999878 0V6" stroke="white" stroke-width="2"/><circle cx="0.999878" cy="9" r="1" fill="white"/></svg>',
		message: '<svg width="2" height="10" viewBox="0 0 2 10" fill="none" xmlns="http://www.w3.org/2000/svg"><circle cx="1" cy="1" r="1" fill="white"/><path d="M1 4V10" stroke="white" stroke-width="2"/></svg>'
	};

	/**
	 * Toast notification object
	 * Matches the existing sb-notification-ctn pattern from the React customizer.
	 */
	var sbttToast = {
		container: null,
		timeout: null,

		/**
		 * Initialize or get notification container
		 *
		 * @return {HTMLElement} The notification container element
		 */
		getContainer: function () {
			if (this.container) {
				return this.container;
			}

			// Check if container already exists (e.g., from React)
			this.container = document.querySelector('.sb-notification-ctn');

			if (!this.container) {
				this.container = document.createElement('div');
				this.container.className = 'sb-notification-ctn';
				this.container.setAttribute('data-active', 'hidden');
				document.body.appendChild(this.container);
			}

			return this.container;
		},

		/**
		 * Show a toast notification
		 *
		 * @param {string} type    - Notification type: 'success', 'error', or 'message'
		 * @param {string} message - The message to display
		 * @param {number} time    - Duration in ms before auto-hide (default: 3000)
		 */
		show: function (type, message, time) {
			var container = this.getContainer();
			var duration = time || 3000;

			// Clear any existing timeout
			if (this.timeout) {
				clearTimeout(this.timeout);
				this.timeout = null;
			}

			// Build notification HTML matching the React component structure
			var iconSvg = icons[type] || icons.message;
			container.innerHTML =
				'<span class="sb-notification-icon">' + iconSvg + '</span>' +
				'<span class="sb-notification-text">' + this.escapeHtml(message) + '</span>';

			// Set type and show
			container.setAttribute('data-type', type);
			container.setAttribute('data-active', 'shown');

			// Auto-hide after duration
			var self = this;
			this.timeout = setTimeout(function () {
				self.hide();
			}, duration);
		},

		/**
		 * Hide the toast notification
		 */
		hide: function () {
			if (this.container) {
				this.container.setAttribute('data-active', 'hidden');
			}
			if (this.timeout) {
				clearTimeout(this.timeout);
				this.timeout = null;
			}
		},

		/**
		 * Show a success notification
		 *
		 * @param {string} message - The success message
		 * @param {number} time    - Duration in ms (default: 3000)
		 */
		success: function (message, time) {
			this.show('success', message, time);
		},

		/**
		 * Show an error notification
		 *
		 * @param {string} message - The error message
		 * @param {number} time    - Duration in ms (default: 5000 for errors)
		 */
		error: function (message, time) {
			this.show('error', message, time || 5000);
		},

		/**
		 * Escape HTML to prevent XSS
		 *
		 * @param {string} text - The text to escape
		 * @return {string} Escaped text
		 */
		escapeHtml: function (text) {
			var div = document.createElement('div');
			div.textContent = text;
			return div.innerHTML;
		}
	};

	// Expose toast to global scope
	window.sbttToast = sbttToast;

	/**
	 * Check for success state parameter and show toast notification.
	 * This runs on every page load to catch the post-OAuth-redirect state.
	 */
	function checkForSuccessState() {
		var url = new URL(window.location.href);

		if (url.searchParams.has('sbtt_oauth_success')) {
			// Prepare clean URL (remove success param)
			url.searchParams.delete('sbtt_oauth_success');
			var cleanUrl = url.pathname + url.search;

			// Show toast and clean URL when DOM is ready
			var handleSuccess = function () {
				// Show toast first
				var message = (typeof sbtt_oauth !== 'undefined' && sbtt_oauth.strings && sbtt_oauth.strings.success)
					? sbtt_oauth.strings.success
					: 'TikTok account connected successfully!';
				sbttToast.success(message);

				// Clean URL after toast is displayed
				setTimeout(function () {
					history.replaceState(null, '', cleanUrl);
				}, 100);
			};

			if (document.readyState === 'loading') {
				document.addEventListener('DOMContentLoaded', handleSuccess);
			} else {
				handleSuccess();
			}
		}
	}

	/**
	 * Show loading overlay during OAuth processing.
	 */
	function showLoading() {
		var overlay = document.createElement('div');
		overlay.id = 'sbtt-oauth-loading';
		overlay.className = 'sbtt-oauth-loading';
		overlay.innerHTML = '<div class="sbtt-oauth-loading-spinner"></div>';

		// Use documentElement if body isn't ready yet (script runs in head)
		var parent = document.body || document.documentElement;
		parent.appendChild(overlay);
	}

	/**
	 * Hide loading overlay.
	 */
	function hideLoading() {
		var overlay = document.getElementById('sbtt-oauth-loading');
		if (overlay) {
			overlay.remove();
		}
	}

	/**
	 * Show error notification using toast system.
	 *
	 * @param {string} message - Error message to display
	 */
	function showError(message) {
		hideLoading();
		sbttToast.error(message);
	}

	/**
	 * OAuth-related query parameters that should be cleaned from URLs.
	 * These may be present if connect site sends params via query string.
	 */
	var oauthParamsToClean = [
		'sbtt_con',
		'sbtt_connect_ver'
	];

	/**
	 * Remove OAuth-related parameters from a URL object.
	 *
	 * @param {URL} url - URL object to clean
	 * @return {URL} The same URL object with OAuth params removed
	 */
	function cleanOAuthParams(url) {
		oauthParamsToClean.forEach(function (param) {
			url.searchParams.delete(param);
		});
		return url;
	}

	/**
	 * Build the redirect URL with success parameter.
	 *
	 * @return {string} URL with success parameter added
	 */
	function buildSuccessRedirectUrl() {
		var url = new URL(window.location.href);
		// Remove any existing fragment
		url.hash = '';
		// Remove any OAuth params that may have leaked into query string
		cleanOAuthParams(url);
		// Add success parameter
		url.searchParams.set('sbtt_oauth_success', '1');
		return url.toString();
	}

	// Check for success state on every page load (runs even without OAuth tokens)
	if (typeof sbtt_oauth !== 'undefined') {
		checkForSuccessState();
	}

	// Only continue with OAuth processing if we have a fragment with OAuth tokens.
	if (!window.location.hash || !window.location.hash.includes('sbtt_access_token')) {
		return;
	}

	// Parse fragment parameters.
	var fragment = new URLSearchParams(window.location.hash.substring(1));

	// Check required params exist.
	var accessToken = fragment.get('sbtt_access_token');
	var refreshToken = fragment.get('sbtt_refresh_token');
	var oauthNonce = fragment.get('sbtt_con');

	if (!accessToken || !refreshToken || !oauthNonce) {
		console.error('SBTT: Missing required OAuth parameters in fragment');
		return;
	}

	// Clear fragment and OAuth params from URL immediately for security.
	var cleanUrl = new URL(window.location.href);
	cleanUrl.hash = '';
	cleanOAuthParams(cleanUrl);
	history.replaceState(null, '', cleanUrl.pathname + cleanUrl.search);

	// Show loading overlay
	showLoading();

	// Guard check: Ensure sbtt_oauth is localized before proceeding.
	if (typeof sbtt_oauth === 'undefined') {
		showError('OAuth configuration not loaded. Please refresh the page and try again.');
		return;
	}

	// Build form data.
	var formData = new FormData();
	formData.append('action', 'sbtt_process_oauth_tokens');
	formData.append('nonce', sbtt_oauth.nonce);
	formData.append('sbtt_con', oauthNonce);
	formData.append('sbtt_access_token', accessToken);
	formData.append('sbtt_refresh_token', refreshToken);
	formData.append('sbtt_openid', fragment.get('sbtt_openid') || '');
	formData.append('sbtt_expires_in', fragment.get('sbtt_expires_in') || '');
	formData.append('sbtt_refresh_expires_in', fragment.get('sbtt_refresh_expires_in') || '');
	formData.append('sbtt_scope', fragment.get('sbtt_scope') || '');

	// Send to backend.
	fetch(sbtt_oauth.ajaxurl, {
		method: 'POST',
		credentials: 'same-origin',
		body: formData
	})
		.then(function (response) {
			if (!response.ok) {
				throw new Error('HTTP error: ' + response.status);
			}
			return response.json();
		})
		.then(function (data) {
			if (data.success) {
				// Redirect with success parameter - toast will show after reload
				window.location.href = buildSuccessRedirectUrl();
			} else {
				var errorMessage = (data.data && data.data.message) ? data.data.message : 'Unknown error';
				var prefix = (typeof sbtt_oauth !== 'undefined' && sbtt_oauth.strings && sbtt_oauth.strings.error_prefix)
					? sbtt_oauth.strings.error_prefix
					: 'Failed to connect TikTok account: ';
				showError(prefix + errorMessage);
			}
		})
		.catch(function (error) {
			console.error('SBTT OAuth error:', error);
			var message = (typeof sbtt_oauth !== 'undefined' && sbtt_oauth.strings && sbtt_oauth.strings.error_generic)
				? sbtt_oauth.strings.error_generic
				: 'Failed to connect TikTok account. Please try again.';
			showError(message);
		});

})(window);
