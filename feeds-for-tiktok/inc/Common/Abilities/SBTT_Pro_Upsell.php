<?php

namespace SmashBalloon\TikTokFeeds\Common\Abilities;

use SmashBalloon\TikTokFeeds\Common\Utils;

if (!defined('ABSPATH')) {
	exit;
}

/**
 * Pro upsell surfaces for the FREE edition's Abilities API (AgDR-9006).
 *
 * One place that knows three things, so every surface stays consistent and the
 * copy never drifts from the rest of the plugin:
 *   1. the upgrade URL + UTM convention  -> upgrade_url()
 *   2. which Pro features exist, and which feed settings they unlock
 *                                         -> features() / pro_only_settings()
 *   3. the one-line "this is Pro" hint attached to Pro-caused refusals
 *                                         -> hint() / hint_for_feature() / hint_for_tier_feature()
 *
 * Nothing is restated here. The URL base and campaign are read from the
 * plugin's own lite-upgrade link (get_upgrade_pro_plugin_link()); feature
 * names and descriptions come from Utils::get_upsell_modal_content(), the data
 * behind the customizer's upsell modals, so a copy change there flows through
 * automatically. Only two maps live here, and tests pin both against the
 * edition filter (SBTT_Settings_Schema::for_edition()):
 *   - PRO_SETTINGS_BY_FEATURE: upsell-modal key -> Pro-only setting keys;
 *   - TIER_FEATURE_MODAL: licence tier feature -> upsell-modal key.
 *
 * UTM convention (one rule, so every port matches):
 *   - utm_source = `abilities` for API responses, `settings` for the admin card;
 *   - utm_medium = the upsell-modal key in kebab-case (`carouselModal` ->
 *     `carousel-modal`, the same rule Utils::get_upsell_modal_content() uses;
 *     see feature_medium()) for any link tied to one feature;
 *   - utm_medium = `ai-agents-card` for the banner, `get-pro-features` for that
 *     ability's top-level upgrade_url, and `describe-settings-schema` for the
 *     `pro_only` block's upgrade_url.
 *
 * Free edition only. Every public method is inert in a Pro build.
 *
 * @since SBR-579
 */
class SBTT_Pro_Upsell
{
	/** `utm_content` on every upgrade link (the customizer's own upgrade buttons use `upgrade`). */
	public const UTM_CONTENT = 'upgrade';

	/**
	 * Pro-only feed settings, grouped by the upsell modal that promotes them.
	 *
	 * Keys are the Utils::get_upsell_modal_content() keys. Values are setting
	 * keys that exist in Pro and are deliberately ABSENT from the free schema
	 * (AgDR-9005): tier-tagged keys, `free_inert` keys, and keys whose write
	 * enum collapses to one value on free (layout, postStyle, videoPlayer,
	 * feedTemplate). `key=value` marks a single Pro-only value of a key free
	 * does expose (sortFeedsBy=likes). Modals with no per-feed setting (feed and
	 * source caps, update frequency) map to an empty list: never invent a key.
	 *
	 * @var array<string, string[]>
	 */
	public const PRO_SETTINGS_BY_FEATURE = array(
		'feedsLimitModal'       => array(),
		'sourcesLimitModal'     => array(),
		'feedsUpdateLimitModal' => array(),
		'loadMoreModal'         => array(
			'showLoadButton', 'loadButtonText', 'loadButtonColor', 'loadButtonHoverColor',
			'loadButtonBg', 'loadButtonHoverBg',
		),
		'listModal'             => array('layout'),
		'masonryModal'          => array('masonryDesktopColumns', 'masonryTabletColumns', 'masonryMobileColumns'),
		'carouselModal'         => array(
			'carouselDesktopColumns', 'carouselTabletColumns', 'carouselMobileColumns',
			'carouselDesktopRows', 'carouselTabletRows', 'carouselMobileRows',
			'carouselLoopType', 'carouselIntervalTime', 'carouselShowArrows',
			'carouselShowPagination', 'carouselEnableAutoplay',
		),
		'galleryModal'          => array('galleryDesktopColumns', 'galleryTabletColumns', 'galleryMobileColumns'),
		'basicTemplateModal'    => array('feedTemplate'),
		'plusTemplateModal'     => array(),
		'proHeaderModal'        => array('headerDescriptionColor', 'headerStatsColor', 'headerStatsDescriptionColor'),
		'proPostModal'          => array('captionColor', 'contentLength'),
		'randomSortModal'       => array('sortRandomEnabled'),
		'filtersModal'          => array('includeWords', 'excludeWords'),
		'sortModal'             => array('sortFeedsBy=likes', 'sortFeedsBy=views'),
		'cardLayoutModal'       => array('postStyle', 'boxedBackgroundColor'),
		'playerExperienceModal' => array('videoPlayer'),
	);

	/**
	 * Licence tier feature (Utils::get_features_list()) -> the upsell modal that
	 * promotes it. Used to name the Pro feature in a refusal hint.
	 *
	 * @var array<string, string>
	 */
	public const TIER_FEATURE_MODAL = array(
		'combine_feed_sources' => 'sourcesLimitModal',
		'list_layout'          => 'listModal',
		'masonry_layout'       => 'masonryModal',
		'carousel_layout'      => 'carouselModal',
		'gallery_layout'       => 'galleryModal',
		'card_layout'          => 'cardLayoutModal',
		'inline_player'        => 'playerExperienceModal',
		'header_stats_info'    => 'proHeaderModal',
		'post_stats_info'      => 'proPostModal',
		'load_more_button'     => 'loadMoreModal',
		'like_count_sorting'   => 'sortModal',
		'view_count_sorting'   => 'sortModal',
		'random_sorting'       => 'randomSortModal',
		'filter_posts'         => 'filtersModal',
		'basic_templates'      => 'basicTemplateModal',
		'plus_templates'       => 'plusTemplateModal',
	);

	/**
	 * Upsell-modal keys of the 3 features the AI Agents card banner names. Picked
	 * by key (not array position) so reordering the modals cannot change the banner.
	 *
	 * @var string[]
	 */
	public const BANNER_FEATURES = array('carouselModal', 'filtersModal', 'sortModal');

	/**
	 * Only the free edition promotes Pro. Guarded here so a shared build can
	 * never surface any of this inside Pro.
	 *
	 * @return bool
	 */
	public static function is_free()
	{
		return !SBTT_Edition::is_pro();
	}

	/**
	 * Upgrade link with the plugin's standard UTM set.
	 *
	 * The base URL and `utm_campaign` are read from get_upgrade_pro_plugin_link(),
	 * the plugin's existing lite-upgrade link, so they are declared once.
	 *
	 * @param string $medium What was shown (e.g. `get-pro-features`, `carousel-modal`, `ai-agents-card`).
	 * @param string $source Surface: `abilities` for API responses, `settings` for the admin card.
	 * @return string
	 */
	public static function upgrade_url($medium, $source = 'abilities')
	{
		$parts = explode('?', (string) get_upgrade_pro_plugin_link(), 2);
		$query = array();
		parse_str(isset($parts[1]) ? $parts[1] : '', $query);
		$campaign = isset($query['utm_campaign']) ? (string) $query['utm_campaign'] : 'tiktok-free';

		return $parts[0]
			. '?utm_campaign=' . rawurlencode($campaign)
			. '&utm_source=' . rawurlencode((string) $source)
			. '&utm_medium=' . rawurlencode((string) $medium)
			. '&utm_content=' . self::UTM_CONTENT;
	}

	/**
	 * The utm_medium for a feature: its modal key in kebab-case (`carouselModal` ->
	 * `carousel-modal`). Same rule as Utils::get_upsell_modal_content().
	 *
	 * @param string $feature_id Upsell-modal key.
	 * @return string
	 */
	public static function feature_medium($feature_id)
	{
		return strtolower((string) preg_replace('/([A-Z])/', '-$1', lcfirst((string) $feature_id)));
	}

	/**
	 * Short feature name from an upsell-modal heading.
	 *
	 * The modal headings are calls to action ("Upgrade to TikTok Pro plans to get
	 * advanced layouts"). The English call-to-action prefix is stripped so the
	 * name reads as a feature ("Advanced layouts"); in any other locale, or if
	 * the copy changes, the full heading is used unchanged.
	 *
	 * @param string $heading Modal heading.
	 * @return string
	 */
	public static function feature_name($heading)
	{
		$heading = trim((string) $heading);
		$pattern = '/^Upgrade to TikTok Pro(?: plans)?\s+(?:to|for)\s+(?:get\s+)?/iu';
		$name    = trim((string) preg_replace('/[\s.!]+$/', '', (string) preg_replace($pattern, '', $heading)));
		return $name === '' ? $heading : ucfirst($name);
	}

	/**
	 * Pro features as `[name, description, settings[]]`.
	 *
	 * Derived from the upsell modals; modals that share one heading and
	 * description (the four layout modals, the two template modals) are one
	 * feature, with their settings merged. Empty outside the free edition.
	 *
	 * @return array<int, array{name: string, description: string, settings: string[]}>
	 */
	public static function features()
	{
		if (!self::is_free()) {
			return array();
		}
		$groups = array();
		foreach (Utils::get_upsell_modal_content() as $id => $modal) {
			$gid = (string) $modal['heading'] . "\n" . (string) $modal['description'];
			if (!isset($groups[$gid])) {
				$groups[$gid] = array(
					'name'        => self::feature_name($modal['heading']),
					'description' => (string) $modal['description'],
					'settings'    => array(),
				);
			}
			$settings = isset(self::PRO_SETTINGS_BY_FEATURE[$id]) ? self::PRO_SETTINGS_BY_FEATURE[$id] : array();

			$groups[$gid]['settings'] = array_values(array_unique(array_merge($groups[$gid]['settings'], $settings)));
		}
		return array_values($groups);
	}

	/**
	 * Every Pro-only setting key / `key=value`, flattened. Drives the `pro_only`
	 * block of describe-settings-schema.
	 *
	 * @return string[]
	 */
	public static function pro_only_settings()
	{
		if (!self::is_free()) {
			return array();
		}
		$all = array();
		foreach (self::PRO_SETTINGS_BY_FEATURE as $settings) {
			$all = array_merge($all, $settings);
		}
		return array_values(array_unique($all));
	}

	/**
	 * Names of the BANNER_FEATURES, in banner order, from the upsell-modal copy.
	 * Keys missing from the modals are skipped.
	 *
	 * @return string[]
	 */
	public static function banner_feature_names()
	{
		if (!self::is_free()) {
			return array();
		}
		$modals = Utils::get_upsell_modal_content();
		$names  = array();
		foreach (self::BANNER_FEATURES as $id) {
			if (isset($modals[$id]['heading'])) {
				$names[] = self::feature_name($modals[$id]['heading']);
			}
		}
		return $names;
	}

	/**
	 * One-line hint for a refusal caused by a Pro-only feature.
	 *
	 * @internal Only for refusals the plugin's own code makes because of a Pro
	 *           feature. Two are live in free: too_many_feeds at the edition cap and
	 *           the get-connect-url 409 (hint_for_feature()). Every other refusal is
	 *           unreachable in free: core JSON-schema validation rejects Pro-only
	 *           keys, a second source (sources.maxItems = 1) and feedTemplate (absent
	 *           from the free create-feed schema) with a 400 before plugin code runs,
	 *           and the edition-filtered table carries no tier metadata so no
	 *           `feature_unavailable` refusal exists. Do not wire a hint onto those.
	 *
	 * @param string $feature_label Human name of the Pro feature.
	 * @param string $medium        UTM medium: the modal key via feature_medium().
	 * @return string Empty outside the free edition.
	 */
	public static function hint($feature_label, $medium)
	{
		if (!self::is_free()) {
			return '';
		}
		return sprintf(
			/* translators: 1: Pro feature name, 2: upgrade URL */
			__('Pro feature: %1$s. Upgrade: %2$s', 'feeds-for-tiktok'),
			$feature_label,
			self::upgrade_url($medium)
		);
	}

	/**
	 * Hint for an upsell-modal key (`feedsLimitModal`, `sourcesLimitModal`, ...).
	 *
	 * @internal See hint().
	 *
	 * @param string $modal_key Upsell-modal key.
	 * @return string Empty outside the free edition or for an unknown key.
	 */
	public static function hint_for_feature($modal_key)
	{
		if (!self::is_free()) {
			return '';
		}
		$modals = Utils::get_upsell_modal_content();
		if (!isset($modals[$modal_key]['heading'])) {
			return '';
		}
		return self::hint(self::feature_name($modals[$modal_key]['heading']), self::feature_medium($modal_key));
	}

	/**
	 * Hint for a licence tier feature that a refusal named (`combine_feed_sources`,
	 * `masonry_layout`, ...).
	 *
	 * @internal See hint(). Currently has NO runtime caller: the refusals that name a
	 *           tier feature (tier_locked('combine_feed_sources'), `feature_unavailable`
	 *           warnings) cannot be reached in free, and in Pro every hint is empty.
	 *           Kept, with TIER_FEATURE_MODAL, for a future edition or surface where a
	 *           tier-named refusal is reachable.
	 *
	 * @param string $tier_feature Utils::get_features_list() feature name.
	 * @return string Empty outside the free edition or for an unmapped feature.
	 */
	public static function hint_for_tier_feature($tier_feature)
	{
		return isset(self::TIER_FEATURE_MODAL[$tier_feature])
			? self::hint_for_feature(self::TIER_FEATURE_MODAL[$tier_feature])
			: '';
	}

	/**
	 * Append a hint to a message, separated by a space. A no-op for an empty hint.
	 *
	 * @internal See hint().
	 *
	 * @param string $message Message to extend.
	 * @param string $hint    Hint from one of the hint*() methods.
	 * @return string
	 */
	public static function append($message, $hint)
	{
		return $hint === '' ? (string) $message : rtrim((string) $message) . ' ' . $hint;
	}
}
