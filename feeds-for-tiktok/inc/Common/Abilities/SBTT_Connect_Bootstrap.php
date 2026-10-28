<?php

namespace SmashBalloon\TikTokFeeds\Common\Abilities;

if (!defined('ABSPATH')) {
	exit;
}

/**
 * Browser-side start of an ability-initiated account connect.
 *
 * DIVERGENCE from Instagram/YouTube Feed: TikTok Feeds' native "Connect"
 * button POSTs a hidden form to SBTT_CONNECT_URL carrying the params built by
 * sbtt_get_tiktok_connection_urls() (page, stateURL, a fresh sbtt_con nonce,
 * sbtt_connect_ver=3, wordpress_user, v). This bootstrap sends the same params
 * as an encoded GET redirect instead (see the ENCODING note in
 * maybe_redirect()); the relay accepts either. On return, the relay appends the OAuth tokens to the
 * URL FRAGMENT (never sent to the server); assets/js/oauth-fragment-handler.js
 * — already enqueued on every `sbtt`-prefixed admin screen — reads that
 * fragment client-side and POSTs it to the `sbtt_process_oauth_tokens` AJAX
 * action, which stores the connected account. So this bootstrap only needs to
 * mint a FRESH nonce (via the plugin's own sbtt_get_tiktok_connection_urls()
 * helper — the "reuse the plugin's own parameter builder" pattern from the
 * YouTube Feed port) and issue a plain 302 to the connect relay; the
 * fragment handler on the return page does the rest, completely unchanged.
 *
 * `get-connect-url` hands the admin a wp-admin URL carrying only our
 * single-use state token; landing here (capability-checked, token consumed)
 * builds the SAME connect params the Settings page's own "Connect" button
 * would send, with `stateURL` pointed at the Settings page so the admin
 * returns where the new account is visible under Manage Sources.
 *
 * @since SBR-501
 */
class SBTT_Connect_Bootstrap
{
	/**
	 * Hook the redirect handler.
	 *
	 * @return void
	 */
	public function __construct()
	{
		add_action('admin_init', array($this, 'maybe_redirect'), 1);
	}

	/**
	 * Redirect to the connect relay when the state token checks out.
	 *
	 * @return void
	 */
	public function maybe_redirect()
	{
		if (
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only page-view guard; the single-use, user-bound state token consumed below is the actual authorization, not a nonce.
			empty($_GET['page']) || $_GET['page'] !== 'sbtt-settings'
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- same guard as above.
			|| empty($_GET['sbtt_abilities_connect']) || !is_string($_GET['sbtt_abilities_connect'])
		) {
			return;
		}
		if (!sbtt_current_user_can()) {
			return;
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- capability-checked above; the state token itself (consume() below) is the actual authorization for this GET.
		$token = sanitize_key($_GET['sbtt_abilities_connect']);
		// Revocation parity with SBTT_Delete_Confirmation::handle_delete(): if AI
		// write access was turned off after this link was minted, honour the
		// revocation rather than completing a connect an agent is no longer
		// allowed to have requested. The token is burned (not just refused) so
		// re-enabling the gate later cannot revive it.
		if (!SBTT_Write_Gate::is_enabled()) {
			SBTT_State_Token::consume($token, 'connect');
			wp_die(
				esc_html__('AI write access has been disabled since this link was created.', 'feeds-for-tiktok'),
				esc_html__('TikTok Feeds', 'feeds-for-tiktok'),
				array('response' => 403, 'back_link' => true)
			);
		}
		if (!SBTT_State_Token::consume($token, 'connect')) {
			wp_die(
				esc_html__(
					'This connect link is invalid or has expired. Ask your AI assistant for a new one.',
					'feeds-for-tiktok'
				),
				esc_html__('TikTok Feeds', 'feeds-for-tiktok'),
				array('response' => 403, 'back_link' => true)
			);
		}

		// Re-check the free edition's account limit at click time: a link minted while
		// the site was empty must not let a second account in after the first connect
		// completed. Same rule as get-connect-url (reconnecting an expired or errored
		// account is still allowed). The completion step itself (the shared AJAX token
		// handler) is the native flow and is not changed here.
		if (Services\SBTT_Source_Service::connect_blocked_by_limit()) {
			$limit = SBTT_Ability_Response::source_limit_reached((int) SBTT_Edition::sources_limit());
			wp_die(
				esc_html($limit->get_error_message()),
				esc_html__('TikTok Feeds', 'feeds-for-tiktok'),
				array('response' => 409, 'back_link' => true)
			);
		}

		// Same helper the Settings page itself uses to build its "Connect" link —
		// minted HERE, in the browser session, with a fresh sbtt_con nonce. Passing
		// true routes stateURL at the Settings page (its own Manage Sources
		// section), so the admin watches the account get added where accounts are
		// managed, matching the Settings page's own Connect button.
		//
		// ENCODING (Alex's PR #120 review): the Settings page's own "Connect"
		// button does NOT build a GET query string at all — the vendored
		// customizer bundle (main.js, `Kf` handler) creates a hidden <form
		// method="POST">, sets one <input> per key with the RAW value, and calls
		// submit() — the browser's own application/x-www-form-urlencoded
		// serialization encodes every value on submit, so stateURL and
		// wordpress_user always arrive correctly encoded even though the JS never
		// calls encodeURIComponent() itself. This bootstrap instead issues a
		// server-side 302 with the same params on the query string (no form to
		// auto-submit from a redirect), so it needs its OWN encoding step to get
		// the same result: rawurlencode_deep() before add_query_arg(), matching
		// what the browser's form submission does for the native path. Without
		// it, stateURL's own query string (`?page=sbtt-settings`) and an admin
		// email like `name+wp@site.com` in wordpress_user reach the relay mangled
		// — only surviving today because the relay happens to split each pair on
		// the first `=` and tolerate a raw `+`.
		$urls = sbtt_get_tiktok_connection_urls(true);
		// 'page' is the destination URL itself (add_query_arg()'s $url argument),
		// not a query value — pull it out BEFORE rawurlencode_deep() so the
		// endpoint URL isn't itself percent-encoded into something unusable.
		$page = isset($urls['page']) ? $urls['page'] : SBTT_CONNECT_URL;
		unset($urls['page']);
		// rawurlencode(false) is '' — cast booleans (e.g. sw_feed) to 0/1 first so
		// the relay keeps receiving the same values the native form posts.
		$urls = array_map(
			static function ($value) {
				return is_bool($value) ? (int) $value : $value;
			},
			$urls
		);

		wp_redirect(add_query_arg(rawurlencode_deep($urls), $page));
		exit;
	}
}
