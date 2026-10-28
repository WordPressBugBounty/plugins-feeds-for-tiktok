/**
 * "AI Agents" card interactivity (SBR-501).
 *
 * The card's markup is server-rendered raw HTML injected into the
 * customizer's generic `info` slot (see GeneralTab::render_ai_agents_card())
 * because the vendored `smashballoon/customizer` React app has no
 * custom/HTML/callback section type to mount a real component against --
 * see that method's docblock for the full reasoning. Because there is no
 * React component here, this file drives the card's install/activate/retry
 * states with plain DOM attribute toggling instead of customizer state.
 *
 * Reuses TikTok Feed's own existing sbtt_install_plugin / sbtt_activate_plugin
 * AJAX handlers (Common\Services\PluginInstallerService) -- the same ones the
 * vendored WPCode integration card already calls (see
 * vendor/smashballoon/customizer/.../Sections/CodeSnippets/InstallSnippetLibrary.js).
 * No new install/activate surface is introduced by this file.
 *
 * @since 1.2.0
 */
( function () {
	'use strict';

	/**
	 * @param {Element} el
	 * @return {Element|null}
	 */
	function getCard( el ) {
		return el.closest( '[data-sbtt-ai-agents]' );
	}

	/**
	 * @param {Element} card
	 * @param {boolean} loading
	 */
	function setLoading( card, loading ) {
		card.classList.toggle( 'sb-ai-agents-loading', loading );

		var btn = card.querySelector( '.sbtt-ai-agents-btn' );
		if ( btn ) {
			btn.disabled = loading;
			btn.setAttribute( 'aria-busy', loading ? 'true' : 'false' );
		}
	}

	/**
	 * Switch the card into its "installFailed" display state -- the retry
	 * copy plus the manual-install fallback link. Mirrors the
	 * `installFailed` state in the Instagram Feed Pro reference card
	 * (instagram-feed-pro#1583), adapted to plain DOM updates since there's
	 * no reactive template driving this card.
	 *
	 * @param {Element} card
	 */
	function showFailedState( card ) {
		card.setAttribute( 'data-state', 'installFailed' );

		// The error copy is already server-rendered into this element
		// (GeneralTab::render_ai_agents_card(), wp_kses_post()'d) -- just
		// reveal it. Writing `data-failed-error` into innerHTML here would
		// have been a second, redundant render of the same string, but
		// through innerHTML on an attribute value instead of the trusted
		// server-escaped markup already in the DOM (Hakim PR #120 finding,
		// LOW).
		var errorEl = card.querySelector( '[data-role="error"]' );
		if ( errorEl ) {
			errorEl.hidden = false;
		}

		var btn = card.querySelector( '.sbtt-ai-agents-btn' );
		if ( btn ) {
			var retryLabel = card.getAttribute( 'data-retry-label' );
			if ( retryLabel ) {
				btn.textContent = retryLabel;
			}
			btn.dataset.action = 'install';
		}
	}

	/**
	 * @param {Element} card
	 * @param {string}  action 'install' | 'activate'
	 */
	function installOrActivate( card, action ) {
		var basename = card.getAttribute( 'data-basename' ) || '';
		var installUrl = card.getAttribute( 'data-install-url' ) || '';
		var nonce = card.getAttribute( 'data-nonce' ) || '';
		var ajaxUrl =
			( window.sb_customizer && window.sb_customizer.ajaxHandler ) ||
			window.ajaxurl ||
			'/wp-admin/admin-ajax.php';

		var formData = new FormData();
		formData.append(
			'action',
			'install' === action ? 'sbtt_install_plugin' : 'sbtt_activate_plugin'
		);
		formData.append( 'nonce', nonce );
		formData.append( 'plugin', 'install' === action ? installUrl : basename );

		var errorEl = card.querySelector( '[data-role="error"]' );
		if ( errorEl ) {
			errorEl.hidden = true;
		}

		setLoading( card, true );

		fetch( ajaxUrl, {
			method: 'POST',
			credentials: 'same-origin',
			body: formData,
		} )
			.then( function ( response ) {
				return response.json();
			} )
			.then( function ( response ) {
				setLoading( card, false );

				if ( ! response || true !== response.success ) {
					showFailedState( card );
					return;
				}

				// The card is server-rendered from WPVibe::status() -- reload
				// so PHP re-evaluates it and shows the resulting state
				// (active, or inactive-but-installed if activation itself
				// failed after a fresh install). Matches the same
				// reload-after-success pattern the vendor's own WPCode
				// install card uses (InstallSnippetLibrary.js).
				window.location.reload();
			} )
			.catch( function () {
				setLoading( card, false );
				showFailedState( card );
			} );
	}

	document.addEventListener( 'click', function ( event ) {
		var btn = event.target.closest( '.sbtt-ai-agents-btn' );
		if ( ! btn ) {
			return;
		}

		var card = getCard( btn );
		if ( ! card ) {
			return;
		}

		var action = btn.dataset.action;

		// The "active" state's button is a real <a> to WPVibe's admin page --
		// let native navigation handle it.
		if ( 'open' === action ) {
			return;
		}

		event.preventDefault();
		installOrActivate( card, action );
	} );
} )();
