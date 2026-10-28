<?php

namespace SmashBalloon\TikTokFeeds\Common\Abilities;

if (!defined('ABSPATH')) {
	exit;
}

/**
 * Short-lived, single-use, user-bound state tokens for ability flows that
 * finish in the browser (account connect, feed-deletion confirmation).
 *
 * WordPress nonces are bound to the session token, so a nonce minted during an
 * Application-Password REST request never verifies in the admin's browser
 * session. These tokens bridge that gap: minted by an ability, consumed once
 * by the SAME user in a browser context, expired after 15 minutes. Mirrors
 * InstagramFeed\Abilities\SBI_State_Token (AgDR-0503).
 *
 * @since SBR-501
 */
class SBTT_State_Token
{
	/** Token lifetime. */
	const TTL    = 15 * MINUTE_IN_SECONDS;
	/**
	 * Transient name prefix. DELIBERATELY does not start with `sbtt_`: the
	 * plugin's "Clear all caches" (AjaxHandlerService::clear_all_caches()) deletes
	 * every option matching `_transient_sbtt_%` (and the timeout twin), so an
	 * `sbtt_`-style name let a cache clear silently kill every pending deletion /
	 * connect link. Nothing a cache wipe matches may ever be added here;
	 * tests/test-abilities-paging-and-token-prefix.php pins that. Uninstall removes
	 * these rows explicitly (see UninstallService::delete_abilities_tokens()).
	 *
	 * Tokens minted under the previous `sbtt_abilities_state_` prefix are simply
	 * never found again and expire with their 15-minute TTL; no migration needed.
	 */
	const PREFIX = 'sb_abilities_sbtt_state_';

	/**
	 * Mint a single-use, 15-minute, user-bound token.
	 *
	 * @param string $action Namespaced action, e.g. `connect` or `delete_feed_2`.
	 * @return string|\WP_Error The token, or an error if no CSPRNG is available.
	 */
	public static function create($action)
	{
		try {
			// Lowercase hex: invariant under sanitize_key(), so the storage key and
			// the lookup key can never diverge (case-sensitive object caches!).
			$token = bin2hex(random_bytes(16));
		} catch (\Exception $e) {
			// random_bytes() throws \Exception when the platform has no CSPRNG
			// source — rare, but a fatal here would take down the whole ability
			// instead of surfacing a normal WP_Error to the caller.
			return new \WP_Error(
				'sbtt_token_generation_failed',
				__('Could not generate a secure token on this server.', 'feeds-for-tiktok'),
				array('status' => 500)
			);
		}
		set_transient(
			self::key($token),
			array(
				'action' => (string) $action,
				'user'   => get_current_user_id(),
				// Same clock as SBTT_Write_Gate::disabled_since(); see peek().
				'created' => round(microtime(true), 6),
			),
			self::TTL
		);
		return $token;
	}

	/**
	 * Transient key for a token. Stored under the token's SHA-256 so a
	 * database read of wp_options cannot replay a live token.
	 *
	 * @param string $token The raw token.
	 * @return string
	 */
	private static function key($token)
	{
		return self::PREFIX . hash('sha256', sanitize_key($token));
	}

	/**
	 * Create a token that is EXCLUSIVE within a group: minting a new one
	 * invalidates the group's previous pending token. Used for feed deletion
	 * so a user can only ever have ONE confirmable deletion at a time.
	 *
	 * @param string $action Namespaced action, e.g. `delete_feed_2`.
	 * @param string $group  Slot name, e.g. `delete`.
	 * @return string|\WP_Error The token, or an error if no CSPRNG is available.
	 */
	public static function create_exclusive($action, $group)
	{
		// Mint FIRST, before touching the slot: if create() fails (no CSPRNG),
		// propagate the WP_Error untouched — the previous pending token (if any)
		// must stay valid rather than being invalidated for a mint that never
		// happened.
		$token = self::create($action);
		if (is_wp_error($token)) {
			return $token;
		}

		$slot = self::slot_key($group);
		$prev = get_transient($slot);
		if (is_string($prev) && $prev !== '') {
			// $prev is the previous token's STORAGE key (never the raw token).
			delete_transient($prev);
		}
		set_transient($slot, self::key($token), self::TTL);
		return $token;
	}

	/**
	 * Slot transient name for a group; the current user id is part of it.
	 *
	 * @param string $group Slot name, e.g. `delete`.
	 * @return string
	 */
	private static function slot_key($group)
	{
		return self::PREFIX . 'slot_' . sanitize_key($group) . '_' . get_current_user_id();
	}

	/**
	 * Invalidate the CURRENT user's pending token for a group, and the slot
	 * itself. Used when the write gate refuses a link, so re-enabling the gate
	 * later cannot revive it.
	 *
	 * @param string $group Slot name, e.g. `delete`.
	 * @return void
	 */
	public static function revoke_group($group)
	{
		$slot = self::slot_key($group);
		$prev = get_transient($slot);
		if (is_string($prev) && $prev !== '') {
			delete_transient($prev);
		}
		delete_transient($slot);
	}

	/**
	 * Validate without consuming (e.g. to render a confirmation screen).
	 *
	 * @param string $token  The token to check.
	 * @param string $action The expected namespaced action.
	 * @return bool
	 */
	public static function peek($token, $action)
	{
		$data = get_transient(self::key($token));
		return is_array($data)
			&& isset($data['action'], $data['user'], $data['created'])
			// Minted at or before the last MCP Write Access disable: revoked for good.
			// Strict `>` so a same-instant token is refused (fails closed). Tokens
			// without `created` never validate.
			&& (float) $data['created'] > SBTT_Write_Gate::disabled_since()
			&& hash_equals((string) $data['action'], (string) $action)
			&& (int) $data['user'] === get_current_user_id();
	}

	/**
	 * Validate AND invalidate (single use).
	 *
	 * @param string $token  The token to consume.
	 * @param string $action The expected namespaced action.
	 * @return bool
	 */
	public static function consume($token, $action)
	{
		if (!self::peek($token, $action)) {
			return false;
		}
		delete_transient(self::key($token));
		return true;
	}
}
