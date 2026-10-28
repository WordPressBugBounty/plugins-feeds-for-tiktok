<?php

namespace SmashBalloon\TikTokFeeds\Common\Abilities\Services;

use SmashBalloon\TikTokFeeds\Common\Utils;
use SmashBalloon\TikTokFeeds\Common\Database\FeedsTable;
use SmashBalloon\TikTokFeeds\Common\Abilities\SBTT_State_Token;
use SmashBalloon\TikTokFeeds\Common\Abilities\SBTT_Ability_Response;
use SmashBalloon\TikTokFeeds\Common\Abilities\SBTT_Edition;

if (!defined('ABSPATH')) {
	exit;
}

/**
 * Connected-account (source) operations for the Abilities API.
 *
 * Always redacted: never returns the access_token/refresh_token columns or
 * the raw `info` blob SourcesTable stores per account (structural exclusions,
 * same severity as Instagram Feed's per-row access_token — AgDR-0503).
 * Connecting an account remains a HUMAN step — this service only builds the
 * connect link; the OAuth handshake happens in the admin's own browser via
 * the TikTok connect relay.
 *
 * Utils::get_sources_list() / SourcesTable::get_sources() return every
 * connected account in ONE unpaginated query (no LIMIT — see SourcesTable::
 * get_sources()), so resolving handles here is never built on a truncated
 * index: an IN-list lookup would be used, but there simply is no page to
 * fall off in the first place.
 *
 * @since SBR-501
 */
class SBTT_Source_Service
{
	/**
	 * List the connected TikTok accounts, redacted.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function list_sources()
	{
		$sources = Utils::get_sources_list();
		if (!is_array($sources)) {
			return array();
		}

		$usage = $this->count_usage();

		$out = array();
		foreach ($sources as $source) {
			if (!is_array($source) || empty($source['open_id'])) {
				continue;
			}
			$open_id = (string) $source['open_id'];
			$out[]   = array(
				'open_id'       => $open_id,
				'display_name'  => isset($source['display_name']) ? (string) $source['display_name'] : '',
				'expires'       => isset($source['expires']) ? substr((string) $source['expires'], 0, 10) : '',
				'has_error'     => !empty($source['error']),
				'used_in_feeds' => isset($usage[$open_id]) ? $usage[$open_id] : 0,
			);
		}
		return $out;
	}

	/**
	 * Count how many feeds reference each open_id, from the feeds table's own
	 * stored settings (no dedicated "used in" column exists).
	 *
	 * @return array<string, int>
	 */
	private function count_usage()
	{
		$rows  = (new FeedsTable())->get_feeds();
		$usage = array();
		foreach ((array) $rows as $row) {
			$settings = isset($row['settings']) ? json_decode($row['settings'], true) : array();
			$sources  = is_array($settings) && !empty($settings['sources']) ? (array) $settings['sources'] : array();
			foreach ($sources as $open_id) {
				$open_id = (string) $open_id;
				$usage[$open_id] = isset($usage[$open_id]) ? $usage[$open_id] + 1 : 1;
			}
		}
		return $usage;
	}

	/**
	 * Build the account-connect URL for the human to open.
	 *
	 * Points at OUR wp-admin bootstrap page (SBTT_Connect_Bootstrap), which —
	 * in the admin's browser session — redirects to the TikTok connect relay
	 * with the SAME parameters the Settings page's own "Connect" button would
	 * send (sbtt_get_tiktok_connection_urls(), a fresh sbtt_con nonce, and a
	 * stateURL pointed at the Settings page), reusing the plugin's own helper
	 * so the fields never drift from the native flow. The single-use state
	 * token in this URL never leaves the site.
	 *
	 * @return array{connect_url: string, expires_in: int, instructions: string}|\WP_Error
	 */
	public function get_connect_url()
	{
		// Server-side OAuth never enforces the edition's account limit (it lives
		// only in the customizer JS), so without this check the ability would
		// bypass it. Free allows one connected account; refuse before a token is
		// even minted, unless the account(s) at the limit all need reconnecting
		// (then the link replaces them). Pro's per-tier limits are not enforced
		// here (sources_limit() is null on Pro).
		if (self::connect_blocked_by_limit()) {
			return SBTT_Ability_Response::source_limit_reached((int) SBTT_Edition::sources_limit());
		}

		$token = SBTT_State_Token::create('connect');
		if (is_wp_error($token)) {
			return $token;
		}

		return array(
			'connect_url'  => add_query_arg(
				array(
					'page'                   => 'sbtt-settings',
					'sbtt_abilities_connect' => $token,
				),
				admin_url('admin.php')
			),
			'expires_in'   => SBTT_State_Token::TTL,
			'instructions' => __(
				'Open this link in the browser where you are logged into wp-admin. It forwards you to TikTok to authorize the app; afterwards you return to TikTok Feeds → Settings, where the newly connected account appears under Manage Sources. The link is single-use and expires in 15 minutes. The AI assistant never sees your TikTok credentials.', // phpcs:ignore Generic.Files.LineLength.TooLong -- single literal for make-pot
				'feeds-for-tiktok'
			),
		);
	}

	/**
	 * Whether a new account-connect is refused by the free edition's account limit.
	 *
	 * Blocked only when the limit is reached AND at least one connected account is
	 * healthy. When every account at the limit needs reconnecting, connecting is
	 * allowed: re-authorising the same TikTok account refreshes its row in place
	 * (SourcesTable::update_or_insert() keys on open_id), so it replaces rather than
	 * adds. Fails open when the sources cannot be read (an empty table also reads as
	 * false). Always false on Pro (no ability-level limit).
	 *
	 * @return bool
	 */
	public static function connect_blocked_by_limit()
	{
		$limit = SBTT_Edition::sources_limit();
		if ($limit === null) {
			return false;
		}
		$existing = Utils::get_sources_list();
		if (!is_array($existing) || count($existing) < $limit) {
			return false;
		}
		foreach ($existing as $source) {
			if (!is_array($source) || !self::needs_reconnect($source)) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Whether a stored account is expired or errored and needs reconnecting.
	 *
	 * Two signals from the plugin itself: the `error` string Utils::get_sources_list()
	 * exposes (set when the relay flagged the token unusable, SourceErrors::flag()),
	 * and `refresh_expires` in the past (the refresh token is dead, so the access
	 * token can no longer be renewed). `expires` alone is NOT used: the access token
	 * is short-lived and renewed automatically.
	 *
	 * @param array $source A row from Utils::get_sources_list().
	 * @return bool
	 */
	public static function needs_reconnect(array $source)
	{
		if (!empty($source['error'])) {
			return true;
		}
		if (empty($source['refresh_expires']) || !is_string($source['refresh_expires'])) {
			return false;
		}
		$ts = strtotime($source['refresh_expires'] . ' UTC');
		return $ts !== false && $ts > 0 && $ts < time();
	}

	/**
	 * Resolve open_ids or display names to connected-account rows.
	 *
	 * @param string[] $handles open_ids or display names.
	 * @return array{ids: string[], details: array, missing: string[]}
	 */
	public function resolve_sources(array $handles)
	{
		$rows = Utils::get_sources_list();
		$rows = is_array($rows) ? $rows : array();

		$ids     = array();
		$details = array();
		$missing = array();
		foreach ($handles as $handle) {
			$handle = trim((string) $handle);
			$found  = null;
			foreach ($rows as $row) {
				if (!is_array($row) || empty($row['open_id'])) {
					continue;
				}
				if (
					(string) $row['open_id'] === $handle
					|| (isset($row['display_name']) && strcasecmp((string) $row['display_name'], $handle) === 0)
				) {
					$found = $row;
					break;
				}
			}
			if ($found === null) {
				$missing[] = $handle;
				continue;
			}
			$open_id = (string) $found['open_id'];
			if (in_array($open_id, $ids, true)) {
				continue; // Two handles resolved to the same account.
			}
			$ids[]     = $open_id;
			$details[] = array(
				'open_id'      => $open_id,
				'display_name' => isset($found['display_name']) ? (string) $found['display_name'] : $open_id,
			);
		}
		return array('ids' => $ids, 'details' => $details, 'missing' => $missing);
	}
}
