<?php

namespace SmashBalloon\TikTokFeeds\Common\Abilities;

use WP_Error;

if (!defined('ABSPATH')) {
	exit;
}

/**
 * WP_Error factories shared by every TikTok Feeds ability.
 *
 * Every error carries an HTTP status in `data.status` so the core Abilities
 * REST controller and the MCP adapter surface the right code. Mirrors
 * InstagramFeed\Abilities\SBI_Ability_Response (AgDR-0503).
 *
 * @since SBR-501
 */
class SBTT_Ability_Response
{
	/**
	 * A generic forbidden error.
	 *
	 * @return WP_Error
	 */
	public static function forbidden()
	{
		return new WP_Error(
			'sbtt_forbidden',
			__('You do not have permission to manage TikTok Feeds settings.', 'feeds-for-tiktok'),
			array('status' => 403)
		);
	}

	/**
	 * AI write access is off.
	 *
	 * @return WP_Error
	 */
	public static function writes_disabled()
	{
		return new WP_Error(
			'sbtt_writes_disabled',
			__(
				'AI write access is disabled. Enable "MCP Write Access" under TikTok Feeds → Settings → General.', // phpcs:ignore Generic.Files.LineLength.TooLong -- single literal for make-pot
				'feeds-for-tiktok'
			),
			array('status' => 403)
		);
	}

	/**
	 * The feed_id input was missing or not positive.
	 *
	 * @return WP_Error
	 */
	public static function invalid_feed_id()
	{
		return new WP_Error(
			'sbtt_invalid_feed_id',
			__('A valid feed_id is required.', 'feeds-for-tiktok'),
			array('status' => 400)
		);
	}

	/**
	 * No feed exists with the given id.
	 *
	 * @param int $feed_id The feed id that was not found.
	 * @return WP_Error
	 */
	public static function feed_not_found($feed_id)
	{
		return new WP_Error(
			'sbtt_feed_not_found',
			sprintf(
				/* translators: %d: feed id */
				__('Feed %d was not found.', 'feeds-for-tiktok'),
				(int) $feed_id
			),
			array('status' => 404)
		);
	}

	/**
	 * One or more requested source handles could not be resolved.
	 *
	 * @param string[] $ids Requested source handles that could not be resolved.
	 * @return WP_Error
	 */
	public static function source_not_found(array $ids)
	{
		$clean = array();
		foreach ($ids as $id) {
			$id = trim(sanitize_text_field((string) $id));
			if ($id !== '') {
				$clean[] = $id;
			}
		}

		$total = count($clean);
		$shown = array_slice($clean, 0, 5);
		$list  = implode(', ', $shown);
		if ($total > 5) {
			$list .= sprintf(
				/* translators: %d: number of additional, un-listed handles */
				__(' and %d more', 'feeds-for-tiktok'),
				$total - 5
			);
		}

		return new WP_Error(
			'sbtt_source_not_found',
			sprintf(
				/* translators: %s: comma-separated account handles (capped at 5, with a count of any remainder) */
				__(
					'These accounts are not connected to this site: %s. Use get-connect-url to connect one first.', // phpcs:ignore Generic.Files.LineLength.TooLong -- single literal for make-pot
					'feeds-for-tiktok'
				),
				$list
			),
			array('status' => 404)
		);
	}

	/**
	 * A requested feature needs a higher license tier than the site has.
	 *
	 * Distinct from the per-key/per-value `ignored: feature_unavailable`
	 * shape used by the partial-update abilities (update-feed-settings,
	 * create-feed's `settings` object): those quietly skip the one tier-
	 * locked key and still apply everything else. This factory is for
	 * abilities where a tier-locked feature is the WHOLE request — e.g.
	 * set-feed-sources combining more than one source on a non-Pro license
	 * (cross-cutting fix 1) — so there is nothing partial to fall back to
	 * and the call fails outright with a 403.
	 *
	 * @param string $feature The gated feature name (Utils::get_features_list()).
	 * @return WP_Error
	 */
	public static function tier_locked($feature)
	{
		// No upsell hint here: both callers are unreachable in free (sources.maxItems = 1
		// rejects a second source in core input validation first), and in Pro every hint is
		// empty. See SBTT_Pro_Upsell::hint_for_tier_feature() (@internal).
		$message = sprintf(
			/* translators: %s: the gated feature's internal name, e.g. combine_feed_sources */
			__(
				'This action needs a higher TikTok Feeds Pro license tier (%s). Upgrade your license, or ask a site admin to.', // phpcs:ignore Generic.Files.LineLength.TooLong -- single literal for make-pot
				'feeds-for-tiktok'
			),
			(string) $feature
		);

		return new WP_Error('sbtt_tier_locked', $message, array('status' => 403));
	}

	/**
	 * The free edition's connected-account limit is reached, so a new
	 * account-connect link is refused.
	 *
	 * @param int $limit The edition's account limit.
	 * @return WP_Error
	 */
	public static function source_limit_reached($limit)
	{
		// The limit only exists in the free edition, so this is always a Pro-caused refusal (AgDR-9006).
		$message = SBTT_Pro_Upsell::append(
			sprintf(
				/* translators: %d: number of accounts the free edition allows */
				_n(
					'This edition of TikTok Feeds allows %d connected account, and it is already connected. To use a different account, remove the existing one in TikTok Feeds → Settings → Manage Sources first.', // phpcs:ignore Generic.Files.LineLength.TooLong -- single literal for make-pot
					'This edition of TikTok Feeds allows %d connected accounts, and the limit is already reached. Remove one in TikTok Feeds → Settings → Manage Sources first.', // phpcs:ignore Generic.Files.LineLength.TooLong -- single literal for make-pot
					(int) $limit,
					'feeds-for-tiktok'
				),
				(int) $limit
			),
			SBTT_Pro_Upsell::hint_for_feature('sourcesLimitModal')
		);

		return new WP_Error('sbtt_source_limit_reached', $message, array('status' => 409));
	}

	/**
	 * The site is at its ability-created feed ceiling.
	 *
	 * @param bool $edition_cap True when the edition's own feed cap was hit (not a
	 *                          lowered `sbtt_abilities_max_feeds` filter or a failed
	 *                          count query): only then is the refusal Pro-caused, so
	 *                          only then does the free edition add its upgrade hint.
	 * @return WP_Error
	 */
	public static function too_many_feeds($edition_cap = false)
	{
		if (!SBTT_Edition::is_pro()) {
			// Free edition: the filter can only lower the cap, so never point at it. The
			// "allows N feeds" sentence is only true when the edition's own cap was hit;
			// a lowered filter or a failed count query gets a neutral sentence instead.
			if ($edition_cap) {
				$message = sprintf(
					/* translators: %d: number of feeds the free edition allows */
					__(
						'This edition of TikTok Feeds allows %d feeds, and this site already has that many. Delete an existing feed first.', // phpcs:ignore Generic.Files.LineLength.TooLong -- single literal for make-pot
						'feeds-for-tiktok'
					),
					SBTT_Edition::feeds_limit()
				);
				$message = SBTT_Pro_Upsell::append($message, SBTT_Pro_Upsell::hint_for_feature('feedsLimitModal'));
			} else {
				$message = __(
					'Feed limit reached, or the feed count could not be verified. Delete an existing feed first, or try again.', // phpcs:ignore Generic.Files.LineLength.TooLong -- single literal for make-pot
					'feeds-for-tiktok'
				);
			}

			return new WP_Error('sbtt_too_many_feeds', $message, array('status' => 400));
		}

		return new WP_Error(
			'sbtt_too_many_feeds',
			__(
				'This site already has the maximum number of feeds an AI assistant may create. Delete an existing feed first, or ask a site admin to raise the sbtt_abilities_max_feeds filter.', // phpcs:ignore Generic.Files.LineLength.TooLong -- single literal for make-pot
				'feeds-for-tiktok'
			),
			array('status' => 400)
		);
	}
}
