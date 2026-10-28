<?php

namespace SmashBalloon\TikTokFeeds\Common\Abilities;

use SmashBalloon\TikTokFeeds\Common\Utils;

if (!defined('ABSPATH')) {
	exit;
}

/**
 * Single source of truth for "which edition is this, and what does it allow"
 * inside the Abilities module (AgDR-9005).
 *
 * The edition is the SBTT_PRO constant (Utils::sbtt_is_pro()), NEVER the stored
 * licence tier: after a Pro to Free downgrade the `sbtt_statuses` option keeps
 * the old licence, so the stored tier can still say `pro` inside the free
 * build. On free the available tier-feature set is therefore always empty.
 *
 * @since SBR-579
 */
class SBTT_Edition
{
	/**
	 * Per-feed ceiling in the free edition. Mirrors the customizer's
	 * `SbUtils.feedsCountPerTier().free` (vendor/smashballoon/customizer
	 * .../sb-customizer/src/Utils/SbUtils.js). That map lives only in JS, so
	 * this is a temporary PHP restatement (AgDR-9005 section 1, "Temporary PHP
	 * restatement accepted"): it is enforcement, so PHP being stricter than JS
	 * is a net gain. Follow-up: localise the limits from PHP into
	 * sb-customizer so they are declared once.
	 */
	public const FREE_FEEDS_LIMIT = 2;

	/**
	 * Connected-account ceiling in the free edition. Mirrors
	 * `SbUtils.sourcesPerTier().free` (same file, same follow-up as above).
	 */
	public const FREE_SOURCES_LIMIT = 1;

	/**
	 * Whether this is the Pro build (the SBTT_PRO constant).
	 *
	 * @return bool
	 */
	public static function is_pro()
	{
		return Utils::sbtt_is_pro();
	}

	/**
	 * The embed shortcode for a feed in this edition. Free registers only
	 * `sbtt-tiktok` (Common\Services\ShortcodeService); `tiktok-feeds` comes
	 * from Pro\Services\ShortcodeService, which free never loads, so on free it
	 * would print as plain text.
	 *
	 * @param int $feed_id Feed id.
	 * @return string
	 */
	public static function shortcode($feed_id)
	{
		$tag = self::is_pro() ? 'tiktok-feeds' : 'sbtt-tiktok';
		return '[' . $tag . ' feed=' . (int) $feed_id . ']';
	}

	/**
	 * Tier features available to this site. Always empty on free, whatever the
	 * stored licence says.
	 *
	 * @return string[]
	 */
	public static function features()
	{
		return self::is_pro() ? (array) Utils::get_tiered_features_list() : array();
	}

	/**
	 * Whether a tier feature is available. An empty feature name means "no
	 * gate" and is available in every edition.
	 *
	 * This replaces the old `if (!sbtt_is_pro()) return true;` bypass: on
	 * free, NO tier feature is available.
	 *
	 * @param string|null $feature Tier feature name (Utils::get_features_list()).
	 * @return bool
	 */
	public static function has($feature)
	{
		if (empty($feature)) {
			return true;
		}
		return in_array($feature, self::features(), true);
	}

	/**
	 * Feed-count ceiling for this site. Free is keyed off the edition (2), not
	 * the stored tier (Utils::feeds_limit() reads the stored tier and reports
	 * 10 for `free`). Pro keeps its licence-tier ceiling.
	 *
	 * @return int
	 */
	public static function feeds_limit()
	{
		if (!self::is_pro()) {
			return self::FREE_FEEDS_LIMIT;
		}
		return (int) Utils::feeds_limit();
	}

	/**
	 * Connected-account ceiling for the FREE edition. Pro's per-tier source
	 * limits are not enforced by the abilities today (tracked on the Pro side),
	 * so this returns null there: "no ability-level limit".
	 *
	 * @return int|null
	 */
	public static function sources_limit()
	{
		return self::is_pro() ? null : self::FREE_SOURCES_LIMIT;
	}
}
