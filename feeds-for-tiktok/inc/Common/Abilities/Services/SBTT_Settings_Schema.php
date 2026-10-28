<?php

namespace SmashBalloon\TikTokFeeds\Common\Abilities\Services;

use SmashBalloon\TikTokFeeds\Common\Utils;
use SmashBalloon\TikTokFeeds\Common\Abilities\SBTT_Edition;

if (!defined('ABSPATH')) {
	exit;
}

/**
 * Typed allowlist of the settings exposed through the Abilities API.
 *
 * This is the single source of truth for what an AI agent may read or write.
 * Anything not listed here is never exposed — connected accounts, access
 * tokens, refresh tokens, license data and usage tracking are deliberately
 * absent. Ported from Instagram Feed's SBI_Settings_Schema (AgDR-0503); tables
 * swapped for TikTok Feeds' own key set.
 *
 * DIVERGENCE from Instagram Feed: TikTok Feeds' per-feed settings blob carries
 * a large surface of nested design tokens (headerNameFont, headerPadding,
 * headerMargin, postPadding, ... — arrays/objects, not scalars) that have no
 * natural JSON Schema without re-implementing font/spacing validation from
 * scratch. This schema exposes only the FLAT, independently validate-able
 * subset (layout/columns/counts, on/off toggles, enum pickers, flat hex
 * colours, short text fields) — the same "curated allowlist" posture the
 * canonical pattern already takes for keys it doesn't expose at all. The
 * nested design-token keys are simply not in this table, on both the read and
 * write side, exactly like Instagram Feed's own custom CSS/JS and usage
 * tracking are absent from its schema.
 *
 * `tier` gates the WHOLE key on one license feature. `tier_values` gates only
 * SPECIFIC enum values of an otherwise free-writable key (e.g. `layout` is
 * writable for every tier, but `layout: masonry` needs the `masonry_layout`
 * feature) — TikTok Feeds' tier model is finer-grained than Instagram Feed's,
 * so this is an additive schema shape, not present in the canonical port.
 *
 * @since SBR-501
 */
class SBTT_Settings_Schema
{
	/** Longest plain-string value accepted for a writable key. */
	public const MAX_STRING_LENGTH = 500;

	/**
	 * Longest `feed_name` value the database will actually store — the
	 * FeedsTable `feed_name` column is `varchar(255)` (FeedsTable.php:84).
	 * Declaring MAX_STRING_LENGTH (500) here let a write pass validation and
	 * then fail at the database layer with an opaque sbtt_feed_save_failed —
	 * AgDR-9004 finding B17.
	 */
	public const FEED_NAME_MAX_LENGTH = 255;

	/** Hex colour: #rgb, #rgba, #rrggbb, #rrggbbaa — or empty (plugin default). */
	public const COLOR_PATTERN = '^(#([0-9a-fA-F]{3,4}|[0-9a-fA-F]{6}|[0-9a-fA-F]{8}))?$';

	/**
	 * Feed templates the builder ships (Utils::get_feed_settings_by_feed_templates()).
	 *
	 * `grid` is deliberately absent: Utils::get_feed_settings_by_feed_templates()
	 * accepts it (falls through to the same default-template branch as no
	 * template at all), but the builder's own template picker never offers it
	 * as a choice (Rex PR #120 finding #3 follow-up) — so it isn't a real
	 * input an agent should be able to pick either.
	 *
	 * @var string[]
	 */
	public const FEED_TEMPLATES = array(
		'default',
		'carousel',
		'cards',
		'list',
		'gallery',
		'latest_video',
		'showcase_carousel',
		'widget',
	);

	/**
	 * Per-feed settings (from the feed row's `settings` JSON blob, see
	 * sbtt_feed_settings_defaults()). `feed_name` is handled specially — it is
	 * a top-level FeedsTable column, not a settings-blob key.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public static function feed_settings()
	{
		// Number-of-posts ceiling, derived from the customizer's own control
		// (CustomizeTab.php: numPostDesktop declares `'max' => Utils::sbtt_is_pro()
		// ? 100 : 10`; numPostTablet/numPostMobile declare no max of their own,
		// so this module applies the same edition-derived ceiling to
		// all three rather than a hand-picked number, keeping the fetch-volume
		// cap identical across breakpoints). AgDR-9004 finding B15: the prior
		// literal (200) doubled both the UI's ceiling and the API fetch volume.
		$num_post_max = Utils::sbtt_is_pro() ? 100 : 10;

		// Grid/masonry/carousel/gallery column ceiling, derived from the
		// customizer's own controls (CustomizeTab.php: every *Columns control
		// in the grid_columns / masonry_columns / carousel_columns_rows /
		// gallery_columns groups declares `'max' => 6`). AgDR-9004 finding B15:
		// the prior literal (10) was a transcription error — Reviews' port of
		// the same table already declares 6 correctly.
		$columns_max = 6;

		return array(
			'feed_name' => array(
				'type'        => 'string',
				// FeedsTable.feed_name is varchar(255); MAX_STRING_LENGTH (500)
				// let a write pass schema validation and then fail at the DB
				// layer with an opaque sbtt_feed_save_failed. AgDR-9004 B17.
				'maxLength'   => self::FEED_NAME_MAX_LENGTH,
				'description' => 'Internal feed name shown in the Feed Builder list.',
			),
			'feedTemplate' => array(
				'type'        => 'string',
				'enum'        => self::FEED_TEMPLATES,
				// Mirrors Utils::get_features_list()'s basic_templates / plus_templates
				// buckets — templates whose expansion sets a plus-tier layout (carousel/
				// gallery/showcase_carousel/widget) are gated the same as that layout
				// value itself; `cards`/`list`/`latest_video` need basic_templates,
				// which only matters on an unlicensed Pro build (basic and up already
				// have it). Checked BEFORE expansion in
				// SBTT_Feed_Settings_Service::create_feed() — expansion writes `layout`
				// directly, which otherwise bypassed `layout`'s own tier_values gate
				// entirely (Rex PR #120 finding #3). `default` is free at every tier.
				'tier_values' => array(
					'cards'             => 'basic_templates',
					'list'              => 'basic_templates',
					'latest_video'      => 'basic_templates',
					'carousel'          => 'plus_templates',
					'gallery'           => 'plus_templates',
					'showcase_carousel' => 'plus_templates',
					'widget'            => 'plus_templates',
				),
				'description' => 'Feed template preset. Read-only on update-feed-settings: '
					. 'templates only expand into concrete settings on feed creation or the '
					. 'preview popup (AjaxHandlerService::newInsert()/:219), so writing this on '
					. 'an existing feed would store the slug and change nothing else — a write '
					. 'that reports success without effect (Rex PR #120 finding #2). Set it on '
					. 'create-feed instead, which keeps it as a top-level input.',
				'writable'    => false,
			),
			'layout' => array(
				'type'        => 'string',
				'enum'        => array('grid', 'list', 'carousel', 'masonry', 'gallery'),
				'tier_values' => array(
					'carousel' => 'carousel_layout',
					'masonry'  => 'masonry_layout',
					'gallery'  => 'gallery_layout',
					'list'     => 'list_layout',
				),
				'description' => 'Feed layout.',
			),
			'verticalSpacing' => array(
				'type'        => 'integer',
				'minimum'     => 0,
				'maximum'     => 100,
				'description' => 'Vertical spacing between posts, in px.',
			),
			'horizontalSpacing' => array(
				'type'        => 'integer',
				'minimum'     => 0,
				'maximum'     => 100,
				'description' => 'Horizontal spacing between posts, in px.',
			),
			'contentLength' => array(
				'type'        => 'integer',
				'minimum'     => 0,
				'maximum'     => 500,
				// NOT tier-gated, on purpose: the "Content Length" group
				// (CustomizeTab.php:431-442) carries no `upsellModal`, so tagging it
				// `tier` would invent a gate the customizer does not have.
				// But the free edition never RENDERS captions (the caption is
				// shown only `isPro &&`, PostDetails.js), so on free the key is
				// inert: writing it would report success with no effect. It is
				// therefore tagged `free_inert` (AgDR-9005's one allowed
				// non-tier Pro-only marker) and for_edition() drops it on free.
				'free_inert'  => true,
				'description' => 'Max caption characters (0 = plugin default).',
			),
			'numPostDesktop' => array(
				'type'        => 'integer',
				'minimum'     => 0,
				'maximum'     => $num_post_max,
				'description' => 'Number of videos on desktop (0 allowed, same as the customizer control).',
			),
			'numPostTablet' => array(
				'type'        => 'integer',
				'minimum'     => 0,
				'maximum'     => $num_post_max,
				'description' => 'Number of videos on tablet (0 allowed, same as the customizer control).',
			),
			'numPostMobile' => array(
				'type'        => 'integer',
				'minimum'     => 0,
				'maximum'     => $num_post_max,
				'description' => 'Number of videos on mobile (0 allowed, same as the customizer control).',
			),
			'gridDesktopColumns' => array(
				'type'        => 'integer',
				'minimum'     => 1,
				'maximum'     => $columns_max,
				'description' => 'Grid columns on desktop.',
			),
			'gridTabletColumns' => array(
				'type'        => 'integer',
				'minimum'     => 1,
				'maximum'     => $columns_max,
				'description' => 'Grid columns on tablet.',
			),
			'gridMobileColumns' => array(
				'type'        => 'integer',
				'minimum'     => 1,
				'maximum'     => $columns_max,
				'description' => 'Grid columns on mobile.',
			),
			'masonryDesktopColumns' => array(
				'type'        => 'integer',
				'minimum'     => 1,
				'maximum'     => $columns_max,
				'tier'        => 'masonry_layout',
				'condition'   => array('field' => 'layout', 'value' => 'masonry'),
				'description' => 'Masonry columns on desktop (masonry layout only).',
			),
			'masonryTabletColumns' => array(
				'type'        => 'integer',
				'minimum'     => 1,
				'maximum'     => $columns_max,
				'tier'        => 'masonry_layout',
				'condition'   => array('field' => 'layout', 'value' => 'masonry'),
				'description' => 'Masonry columns on tablet (masonry layout only).',
			),
			'masonryMobileColumns' => array(
				'type'        => 'integer',
				'minimum'     => 1,
				'maximum'     => $columns_max,
				'tier'        => 'masonry_layout',
				'condition'   => array('field' => 'layout', 'value' => 'masonry'),
				'description' => 'Masonry columns on mobile (masonry layout only).',
			),
			'carouselDesktopColumns' => array(
				'type'        => 'integer',
				'minimum'     => 1,
				'maximum'     => $columns_max,
				'tier'        => 'carousel_layout',
				'condition'   => array('field' => 'layout', 'value' => 'carousel'),
				'description' => 'Carousel columns on desktop (carousel layout only).',
			),
			'carouselTabletColumns' => array(
				'type'        => 'integer',
				'minimum'     => 1,
				'maximum'     => $columns_max,
				'tier'        => 'carousel_layout',
				'condition'   => array('field' => 'layout', 'value' => 'carousel'),
				'description' => 'Carousel columns on tablet (carousel layout only).',
			),
			'carouselMobileColumns' => array(
				'type'        => 'integer',
				'minimum'     => 1,
				'maximum'     => $columns_max,
				'tier'        => 'carousel_layout',
				'condition'   => array('field' => 'layout', 'value' => 'carousel'),
				'description' => 'Carousel columns on mobile (carousel layout only).',
			),
			'carouselDesktopRows' => array(
				'type'        => 'integer',
				'minimum'     => 1,
				'maximum'     => 3,
				'tier'        => 'carousel_layout',
				'condition'   => array('field' => 'layout', 'value' => 'carousel'),
				'description' => 'Carousel rows on desktop (carousel layout only).',
			),
			'carouselTabletRows' => array(
				'type'        => 'integer',
				'minimum'     => 1,
				'maximum'     => 3,
				'tier'        => 'carousel_layout',
				'condition'   => array('field' => 'layout', 'value' => 'carousel'),
				'description' => 'Carousel rows on tablet (carousel layout only).',
			),
			'carouselMobileRows' => array(
				'type'        => 'integer',
				'minimum'     => 1,
				'maximum'     => 3,
				'tier'        => 'carousel_layout',
				'condition'   => array('field' => 'layout', 'value' => 'carousel'),
				'description' => 'Carousel rows on mobile (carousel layout only).',
			),
			'carouselLoopType' => array(
				'type'        => 'string',
				'enum'        => array('infinity', 'rewind'),
				'tier'        => 'carousel_layout',
				'condition'   => array('field' => 'layout', 'value' => 'carousel'),
				'description' => 'Carousel loop behaviour (carousel layout only).',
			),
			'carouselIntervalTime' => array(
				'type'        => 'integer',
				'minimum'     => 500,
				'maximum'     => 60000,
				'tier'        => 'carousel_layout',
				'condition'   => array('field' => 'layout', 'value' => 'carousel'),
				'description' => 'Carousel autoplay interval, in ms (carousel layout only).',
			),
			'carouselShowArrows' => array(
				'type'        => 'boolean',
				'tier'        => 'carousel_layout',
				'condition'   => array('field' => 'layout', 'value' => 'carousel'),
				'description' => 'Show carousel navigation arrows (carousel layout only).',
			),
			'carouselShowPagination' => array(
				'type'        => 'boolean',
				'tier'        => 'carousel_layout',
				'condition'   => array('field' => 'layout', 'value' => 'carousel'),
				'description' => 'Show carousel pagination dots (carousel layout only).',
			),
			'carouselEnableAutoplay' => array(
				'type'        => 'boolean',
				'tier'        => 'carousel_layout',
				'condition'   => array('field' => 'layout', 'value' => 'carousel'),
				'description' => 'Autoplay the carousel (carousel layout only).',
			),
			'galleryDesktopColumns' => array(
				'type'        => 'integer',
				'minimum'     => 1,
				'maximum'     => $columns_max,
				'tier'        => 'gallery_layout',
				'condition'   => array('field' => 'layout', 'value' => 'gallery'),
				'description' => 'Gallery columns on desktop (gallery layout only).',
			),
			'galleryTabletColumns' => array(
				'type'        => 'integer',
				'minimum'     => 1,
				'maximum'     => $columns_max,
				'tier'        => 'gallery_layout',
				'condition'   => array('field' => 'layout', 'value' => 'gallery'),
				'description' => 'Gallery columns on tablet (gallery layout only).',
			),
			'galleryMobileColumns' => array(
				'type'        => 'integer',
				'minimum'     => 1,
				'maximum'     => $columns_max,
				'tier'        => 'gallery_layout',
				'condition'   => array('field' => 'layout', 'value' => 'gallery'),
				'description' => 'Gallery columns on mobile (gallery layout only).',
			),
			'showHeader' => array(
				'type'        => 'boolean',
				'description' => 'Show the feed header.',
			),
			'headerNameColor' => array(
				'type'        => 'string',
				'format'      => 'color',
				'description' => 'Header account-name colour (hex).',
			),
			'headerUsernameColor' => array(
				'type'        => 'string',
				'format'      => 'color',
				'description' => 'Header username colour (hex).',
			),
			'headerDescriptionColor' => array(
				'type'        => 'string',
				'format'      => 'color',
				// The "Description" section (CustomizeTab.php:633-637) carries
				// `upsellModal: proHeaderModal`, the same gate as the sibling
				// headerStatsColor/headerStatsDescriptionColor controls just below
				// (AgDR-9004 follow-up, confirmed by Rex on PR #120).
				'tier'        => 'header_stats_info',
				'description' => 'Header bio/description colour (hex).',
			),
			'headerStatsColor' => array(
				'type'        => 'string',
				'format'      => 'color',
				'tier'        => 'header_stats_info',
				'description' => 'Header stats number colour (hex).',
			),
			'headerStatsDescriptionColor' => array(
				'type'        => 'string',
				'format'      => 'color',
				'tier'        => 'header_stats_info',
				'description' => 'Header stats label colour (hex).',
			),
			'headerButtonContent' => array(
				'type'        => 'string',
				'description' => 'Header follow-button label.',
			),
			'headerButtonColor' => array(
				'type'        => 'string',
				'format'      => 'color',
				'description' => 'Header follow-button text colour (hex).',
			),
			'headerButtonBg' => array(
				'type'        => 'string',
				'format'      => 'color',
				'description' => 'Header follow-button background colour (hex).',
			),
			'headerButtonHoverColor' => array(
				'type'        => 'string',
				'format'      => 'color',
				'description' => 'Header follow-button hover text colour (hex).',
			),
			'headerButtonHoverBg' => array(
				'type'        => 'string',
				'format'      => 'color',
				'description' => 'Header follow-button hover background colour (hex).',
			),
			'postStyle' => array(
				'type'        => 'string',
				'enum'        => array('regular', 'boxed'),
				'tier_values' => array('boxed' => 'card_layout'),
				'description' => 'Post style.',
			),
			'boxedBackgroundColor' => array(
				'type'        => 'string',
				'format'      => 'color',
				'tier'        => 'card_layout',
				'condition'   => array('field' => 'postStyle', 'value' => 'boxed'),
				'description' => 'Boxed post background colour, boxed post style only (hex).',
			),
			'videoPlayer' => array(
				'type'        => 'string',
				'enum'        => array('lightbox', 'inline'),
				'tier_values' => array('inline' => 'inline_player'),
				'description' => 'How a clicked video plays.',
			),
			'captionColor' => array(
				'type'        => 'string',
				'format'      => 'color',
				// The "Caption" checkbox item (CustomizeTab.php:1075-1079) carries
				// `upsellModal: proPostModal` and gates its whole nested
				// font/color/spacing group, same as the sibling author_info/views/
				// likes toggles above it (AgDR-9004 follow-up, confirmed by Rex on
				// PR #120).
				'tier'        => 'post_stats_info',
				'description' => 'Caption text colour (hex).',
			),
			'showLoadButton' => array(
				'type'        => 'boolean',
				'tier'        => 'load_more_button',
				'description' => 'Show the Load More button.',
			),
			'loadButtonText' => array(
				'type'        => 'string',
				'tier'        => 'load_more_button',
				'description' => 'Load More button label.',
			),
			'loadButtonColor' => array(
				'type'        => 'string',
				'format'      => 'color',
				'tier'        => 'load_more_button',
				'description' => 'Load More button text colour (hex).',
			),
			'loadButtonHoverColor' => array(
				'type'        => 'string',
				'format'      => 'color',
				'tier'        => 'load_more_button',
				'description' => 'Load More button hover text colour (hex).',
			),
			'loadButtonBg' => array(
				'type'        => 'string',
				'format'      => 'color',
				'tier'        => 'load_more_button',
				'description' => 'Load More button background colour (hex).',
			),
			'loadButtonHoverBg' => array(
				'type'        => 'string',
				'format'      => 'color',
				'tier'        => 'load_more_button',
				'description' => 'Load More button hover background colour (hex).',
			),
			'sortFeedsBy' => array(
				'type'        => 'string',
				'enum'        => array('latest', 'oldest', 'likes', 'views'),
				'tier_values' => array('likes' => 'like_count_sorting', 'views' => 'view_count_sorting'),
				'description' => 'Video sort order.',
			),
			'sortRandomEnabled' => array(
				'type'        => 'boolean',
				'tier'        => 'random_sorting',
				'description' => 'Shuffle the fetched videos, applied after sortFeedsBy.',
			),
			'includeWords' => array(
				'type'        => 'string',
				'tier'        => 'filter_posts',
				'description' => 'Only show videos whose caption contains one of these comma-separated words.',
			),
			'excludeWords' => array(
				'type'        => 'string',
				'tier'        => 'filter_posts',
				'description' => 'Hide videos whose caption contains one of these comma-separated words.',
			),
		);
	}

	/**
	 * Global (plugin-wide) settings stored in the sbtt_global_settings option.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public static function global_settings()
	{
		return array(
			'optimize_images' => array(
				'type'        => 'boolean',
				'description' => 'Locally resize images to the smallest size needed for where they are displayed.',
			),
			'feed_issue_reports' => array(
				'type'        => 'boolean',
				// No consumer anywhere: not exposed in the free edition.
				'free_inert'  => true,
				'description' => 'Whether admin notices are shown when a feed has a source or fetch '
					. 'issue. Read-only for AI assistants: nothing in the plugin reads this option '
					. 'today (Rex PR #120 finding #2), so a write would report success without '
					. 'changing any behavior.',
				'writable'    => false,
			),
			'admin_error_notices' => array(
				'type'        => 'boolean',
				// No consumer anywhere: not exposed in the free edition.
				'free_inert'  => true,
				'description' => 'Show general plugin admin error notices. Read-only for AI assistants '
					. '(an agent must not silence error notices).',
				'writable'    => false,
			),
			'gdpr' => array(
				'type'        => 'string',
				'enum'        => array('auto', 'yes', 'no'),
				'description' => 'GDPR mode, as set in Settings → Feeds. "auto" turns it on only when '
					. 'a supported consent plugin is active, "yes" always on, "no" always off. '
					. 'While on, feeds skip third-party requests (TikTok media, scripts) until the '
					. 'visitor consents. It affects legal compliance, so confirm the change with the '
					. 'site owner first.',
			),
			'preserve_settings' => array(
				'type'        => 'boolean',
				'description' => 'Keep feeds and settings in the database if the plugin is uninstalled.',
			),
		);
	}

	/**
	 * Default values for keys NOT covered by
	 * SettingsManagerService::get_global_settings_defaults() (see
	 * SBTT_Global_Settings_Service::get() — the "layered defaults" lesson from
	 * the YouTube Feed port applies here too: cast_out()'s fallback must come
	 * from a REAL default, never a bare null).
	 *
	 * @return array<string, mixed>
	 */
	public static function global_settings_supplemental_defaults()
	{
		return array(
			'preserve_settings' => false,
		);
	}

	/**
	 * The one choke point that turns the shared (Pro) table into what THIS
	 * edition exposes (AgDR-9005).
	 *
	 * On Pro the table is returned unchanged. On free:
	 *  - every key with a `tier` (or flagged `free_inert`) is removed: Pro-only
	 *    keys are ABSENT, never "accepted but ignored", because render-time
	 *    gating is weak and a stored Pro value would otherwise render in free;
	 *  - for a key with `tier_values`, the tier-locked enum values are removed
	 *    from the WRITE schema (`$for_read = false`). A key whose write enum
	 *    collapses to a single value has nothing to write and is dropped
	 *    (layout, postStyle, videoPlayer, feedTemplate);
	 *  - the READ schema (`$for_read = true`) keeps the full enum of a kept key,
	 *    so a value stored while the site was Pro reads back as stored
	 *    (downgrade rule) instead of being coerced to a default;
	 *  - tier / tier_values / free_inert metadata is stripped, so no
	 *    `x-license-feature*` annotation is ever emitted on free.
	 *
	 * Every consumer (input schemas, describe-settings-schema, get-feed,
	 * update/create merge) goes through here; edition detection is the
	 * SBTT_PRO constant, never the stored licence tier.
	 *
	 * @param array     $table    A feed_settings() / global_settings() table.
	 * @param bool      $for_read True for output (read) schemas.
	 * @param bool|null $is_pro   Override the edition (tests); null = detect.
	 * @return array
	 */
	public static function for_edition(array $table, $for_read = false, $is_pro = null)
	{
		$is_pro = $is_pro === null ? SBTT_Edition::is_pro() : (bool) $is_pro;
		if ($is_pro) {
			return $table;
		}

		$out = array();
		foreach ($table as $key => $def) {
			if (isset($def['tier']) || !empty($def['free_inert'])) {
				continue;
			}
			if (isset($def['tier_values'])) {
				$free_enum = isset($def['enum'])
					? array_values(array_diff($def['enum'], array_keys($def['tier_values'])))
					: array();
				if (count($free_enum) <= 1) {
					continue; // Collapses to a single value: nothing to write.
				}
				if (!$for_read) {
					$def['enum'] = $free_enum;
				}
			}
			unset($def['tier'], $def['tier_values'], $def['free_inert']);
			$out[$key] = $def;
		}
		return $out;
	}

	/**
	 * Build a JSON Schema `properties` map from a table (drops private keys).
	 *
	 * @param array $table Schema table (feed_settings() or global_settings()).
	 * @return array
	 */
	public static function to_json_properties(array $table)
	{
		$out = array();
		foreach ($table as $key => $def) {
			$prop = array('type' => $def['type']);
			foreach (array('enum', 'minimum', 'maximum', 'description') as $k) {
				if (isset($def[$k])) {
					$prop[$k] = $def[$k];
				}
			}
			if ($def['type'] === 'string' && !isset($def['enum'])) {
				// A per-key maxLength (e.g. feed_name, capped to the FeedsTable
				// column width — AgDR-9004 B17) wins over the shared default.
				$prop['maxLength'] = isset($def['maxLength']) ? (int) $def['maxLength'] : self::MAX_STRING_LENGTH;
			}
			if (isset($def['format']) && $def['format'] === 'color') {
				$prop['pattern']     = self::COLOR_PATTERN;
				$prop['description'] = (isset($prop['description']) ? $prop['description'] . ' ' : '')
					. 'Hex colour (#rgb, #rrggbb or #rrggbbaa) or empty for the default.';
			}
			if (isset($def['writable']) && $def['writable'] === false) {
				$prop['readOnly'] = true;
			}
			if (isset($def['tier'])) {
				$prop['x-license-feature'] = $def['tier'];
			}
			if (isset($def['tier_values'])) {
				$prop['x-license-feature-values'] = $def['tier_values'];
			}
			if (isset($def['condition'])) {
				// Surfaced at discovery time too (describe-settings-schema), not
				// only as a post-write warning: an agent that reads the schema
				// before writing should be able to see up front that this key
				// only takes visible effect when another key has a given value.
				$prop['x-condition'] = $def['condition'];
			}
			$out[$key] = $prop;
		}
		return $out;
	}

	/**
	 * Cast a raw stored value to the declared type for output.
	 *
	 * Stored data is not guaranteed to satisfy the schema (legacy installs,
	 * import/export, a bad save). Core validates our OUTPUT against the schema
	 * and fails the whole ability on one bad value, so anything that does not
	 * satisfy the declared enum / range / pattern / length falls back to
	 * $default (the plugin's own default for that key) rather than being
	 * passed through — the same lesson the YouTube Feed port learned the hard
	 * way (a template's own subscribehovercolor value, 'rgb(255, 255, 255,
	 * .25)', failed the hex-only output pattern and 500'd every fresh feed).
	 *
	 * @param array $def     Schema entry for the key.
	 * @param mixed $value   Raw stored value (null when missing).
	 * @param mixed $default Plugin default for the key.
	 * @return mixed
	 */
	public static function cast_out(array $def, $value, $default = null)
	{
		switch ($def['type']) {
			case 'boolean':
				// '' is a legitimate "off" in these plugins (legacy read paths write it
				// for on/off switcher fields), so only a MISSING value takes the default.
				if ($value === null) {
					$value = $default;
				}
				return $value === true || $value === 'true' || $value === 1 || $value === '1' || $value === 'on';

			case 'integer':
				if ($value === null || $value === '' || !is_numeric($value)) {
					$value = is_numeric($default) ? $default : 0;
				}
				$int = (int) $value;
				// Clamp so the output always satisfies the declared range even if the
				// plugin default itself is ever out of spec.
				if (isset($def['minimum']) && $int < $def['minimum']) {
					$int = (int) $def['minimum'];
				}
				if (isset($def['maximum']) && $int > $def['maximum']) {
					$int = (int) $def['maximum'];
				}
				return $int;

			default:
				$str = is_scalar($value) ? (string) $value : '';

				// Clamp to the declared hex-colour pattern BEFORE the enum/length
				// checks below: a stored value can predate this schema and not be hex
				// at all. '' always satisfies COLOR_PATTERN (it is optional-empty), so
				// it is always a safe last resort even when $default itself does not
				// match.
				$is_color = isset($def['format']) && $def['format'] === 'color';
				if ($is_color && !preg_match('/' . self::COLOR_PATTERN . '/', $str)) {
					$fallback_str = is_scalar($default) ? (string) $default : '';
					$str          = preg_match('/' . self::COLOR_PATTERN . '/', $fallback_str) ? $fallback_str : '';
				}

				if (isset($def['enum']) && !in_array($str, $def['enum'], true)) {
					$fallback = is_scalar($default) ? (string) $default : '';
					$str      = in_array($fallback, $def['enum'], true) ? $fallback : (string) $def['enum'][0];
				}

				// Same reasoning again for length: to_json_properties() only declares
				// maxLength on plain strings (never on an enum, which is always
				// short), so truncate rather than fail the whole ability on an
				// over-length stored value. A per-key maxLength (if this def
				// declares one narrower than the shared cap — e.g. feed_name)
				// wins over MAX_STRING_LENGTH.
				if (!isset($def['enum'])) {
					$max = isset($def['maxLength']) ? (int) $def['maxLength'] : self::MAX_STRING_LENGTH;
					if (mb_strlen($str) > $max) {
						$str = mb_substr($str, 0, $max);
					}
				}

				return $str;
		}
	}

	/**
	 * Writable subset of a table (keys not flagged `writable => false`).
	 *
	 * @param array $table Schema table (feed_settings() or global_settings()).
	 * @return array
	 */
	public static function writable(array $table)
	{
		return array_filter(
			$table,
			function ($def) {
				return !isset($def['writable']) || $def['writable'] !== false;
			}
		);
	}

	/**
	 * Feed settings whose visible effect comes ONLY from the pre-rendered
	 * `feed_style` CSS stored on the feed row.
	 *
	 * Derived in two steps. (1) The Feed Builder (sb-customizer
	 * Utils/SbUtils.js getSingleControlStyle()) builds `feed_style` from every
	 * CustomizeTab control that declares a `style` map, substituting the control's
	 * current value for `{{value}}`, and only the browser-side builder regenerates
	 * it (saveFeedData() posts it as `feed_style`). (2) Of those controls, these
	 * are the ones in this module's schema that the front-end React feed
	 * (tikTokFeed.js, GridLayout/Caption/Header components) does NOT also read
	 * from the settings blob, so changing them through the Abilities API stores
	 * the value but leaves the live page unchanged until the builder re-saves.
	 *
	 * `verticalSpacing` is a conditional member, see FEED_STYLE_VERTICAL_SPACING_LIVE_LAYOUTS.
	 *
	 * @var string[]
	 */
	public const FEED_STYLE_ONLY_KEYS = array(
		'headerNameColor',
		'headerUsernameColor',
		'headerDescriptionColor',
		'headerStatsColor',
		'headerStatsDescriptionColor',
		'headerButtonColor',
		'headerButtonBg',
		'headerButtonHoverColor',
		'headerButtonHoverBg',
		'boxedBackgroundColor',
		'captionColor',
		'loadButtonColor',
		'loadButtonHoverColor',
		'loadButtonBg',
		'loadButtonHoverBg',
	);

	/**
	 * Layouts whose React component also applies `verticalSpacing` live (as
	 * `rowGap`, GridLayout.js / GalleryLayout.js). On every other layout the
	 * spacing comes only from the `feed_style` margin-bottom rule.
	 *
	 * @var string[]
	 */
	public const FEED_STYLE_VERTICAL_SPACING_LIVE_LAYOUTS = array('grid', 'gallery');

	/**
	 * Build `warnings[]` entries for just-written keys that only take visible
	 * effect once the Feed Builder regenerates and saves `feed_style`.
	 *
	 * The value IS stored. Abilities do not port the builder's CSS generator, so
	 * the live page keeps its old styling until a human opens the feed in the
	 * Feed Builder and saves it.
	 *
	 * @param string[] $written  Keys actually written this call.
	 * @param array    $final    Final, merged settings (post-write).
	 * @param int      $feed_id  Feed id, used to build the builder URL.
	 * @return array<int, array{key: string, reason: string, message: string}>
	 */
	public static function feed_style_warnings(array $written, array $final, $feed_id)
	{
		$warnings = array();
		$layout   = isset($final['layout']) && is_string($final['layout']) ? $final['layout'] : 'grid';
		$url      = admin_url('admin.php?page=sbtt&feed_id=' . (int) $feed_id);

		foreach ($written as $key) {
			$only = in_array($key, self::FEED_STYLE_ONLY_KEYS, true)
				|| ('verticalSpacing' === $key && !in_array($layout, self::FEED_STYLE_VERTICAL_SPACING_LIVE_LAYOUTS, true));
			if (!$only) {
				continue;
			}
			$warnings[] = array(
				'key'     => $key,
				'reason'  => 'needs_builder_save',
				'message' => sprintf(
					/* translators: 1: setting key, 2: Feed Builder URL */
					__(
						'%1$s was saved, but it only styles the live page after the feed is opened in the Feed Builder and saved there (%2$s). Until then the live feed keeps its previous styling.',
						'feeds-for-tiktok'
					),
					$key,
					$url
				),
			);
		}
		return $warnings;
	}

	/**
	 * Build `warnings[]` entries (AgDR-9004 requirement 3) for keys that were
	 * just written but are currently INERT — the schema entry declares a
	 * `condition` (e.g. masonryDesktopColumns only takes visible effect when
	 * `layout` is `masonry` — derived directly from the customizer's own group
	 * conditions in CustomizeTab.php: masonry_columns / carousel_columns_rows /
	 * gallery_columns groups, and the boxed post-style fields) and the FINAL,
	 * post-write settings do not satisfy it.
	 *
	 * The value IS still stored — it will take effect the moment the
	 * condition is met — so this is deliberately a warning, not a rejection:
	 * an agent switching `layout` and its columns in the same call must not
	 * be told the columns write failed.
	 *
	 * @param array    $schema  A feed_settings()-shaped table.
	 * @param string[] $written Keys actually written this call.
	 * @param array    $final   Final, merged settings (post-write).
	 * @return array<int, array{key: string, reason: string, message: string}>
	 */
	public static function condition_warnings(array $schema, array $written, array $final)
	{
		$warnings = array();
		foreach ($written as $key) {
			if (!isset($schema[$key]['condition'])) {
				continue;
			}
			$condition = $schema[$key]['condition'];
			$field     = $condition['field'];
			$required  = $condition['value'];
			$actual    = isset($final[$field]) ? $final[$field] : null;
			if ($actual === $required) {
				continue;
			}
			$warnings[] = array(
				'key'     => $key,
				'reason'  => 'accepted_but_inert',
				'message' => sprintf(
					/* translators: 1: setting key, 2: field name, 3: required value, 4: actual value */
					__(
						'%1$s was saved but has no visible effect unless %2$s is "%3$s" (currently "%4$s").',
						'feeds-for-tiktok'
					),
					$key,
					$field,
					$required,
					is_scalar($actual) ? (string) $actual : ''
				),
			);
		}
		return $warnings;
	}

	/**
	 * The license feature (if any) required to write THIS value of this key —
	 * either the whole-key `tier`, or the value-specific `tier_values` entry.
	 * Returns null when the value is free to write at every tier.
	 *
	 * @param array $def   Schema entry for the key.
	 * @param mixed $value Already-validated (validate_in()'d) value.
	 * @return string|null
	 */
	public static function required_tier(array $def, $value)
	{
		if (isset($def['tier'])) {
			return $def['tier'];
		}
		if (isset($def['tier_values']) && is_scalar($value) && isset($def['tier_values'][(string) $value])) {
			return $def['tier_values'][(string) $value];
		}
		return null;
	}

	/**
	 * Validate + normalise ONE incoming value for storage.
	 *
	 * Core has already validated the request against the JSON Schema, so this is
	 * the second, PHP-side line: it never trusts the transport. Returns the
	 * value to store, or a WP_Error (code sbtt_invalid_value, status 400).
	 *
	 * @param array  $def   Schema entry for the key.
	 * @param mixed  $value Raw incoming value.
	 * @param string $key   Setting key, for the error message.
	 * @return mixed|\WP_Error
	 */
	public static function validate_in(array $def, $value, $key)
	{
		$bad = function ($reason) use ($key) {
			return new \WP_Error(
				'sbtt_invalid_value',
				sprintf(
					/* translators: 1: setting key, 2: reason */
					__('Invalid value for %1$s: %2$s', 'feeds-for-tiktok'),
					$key,
					$reason
				),
				array('status' => 400)
			);
		};

		switch ($def['type']) {
			case 'boolean':
				if (is_bool($value)) {
					return $value;
				}
				if (in_array($value, array('true', '1', 1, 'on'), true)) {
					return true;
				}
				if (in_array($value, array('false', '0', 0, 'off', ''), true)) {
					return false;
				}
				return $bad(__('expected a boolean', 'feeds-for-tiktok'));

			case 'integer':
				if (!is_numeric($value) || (float) $value !== (float) (int) $value) {
					return $bad(__('expected an integer', 'feeds-for-tiktok'));
				}
				$int = (int) $value;
				if (isset($def['minimum']) && $int < $def['minimum']) {
					return $bad(sprintf(__('minimum is %d', 'feeds-for-tiktok'), $def['minimum']));
				}
				if (isset($def['maximum']) && $int > $def['maximum']) {
					return $bad(sprintf(__('maximum is %d', 'feeds-for-tiktok'), $def['maximum']));
				}
				return $int;

			default:
				if (!is_scalar($value)) {
					return $bad(__('expected a string', 'feeds-for-tiktok'));
				}
				$str = sanitize_text_field((string) $value);
				if (isset($def['enum']) && !in_array($str, $def['enum'], true)) {
					return $bad(sprintf(__('allowed values: %s', 'feeds-for-tiktok'), implode(', ', $def['enum'])));
				}
				$is_color = isset($def['format']) && $def['format'] === 'color';
				if ($is_color && !preg_match('/' . self::COLOR_PATTERN . '/', $str)) {
					return $bad(__('expected a hex colour such as #408bd1', 'feeds-for-tiktok'));
				}
				// A per-key maxLength (e.g. feed_name, capped to the FeedsTable
				// column width — AgDR-9004 B17) wins over the shared cap, and
				// rejects here rather than at the database layer, where the
				// prior mismatch surfaced only as an opaque sbtt_feed_save_failed.
				$max_length = isset($def['maxLength']) ? (int) $def['maxLength'] : self::MAX_STRING_LENGTH;
				if (mb_strlen($str) > $max_length) {
					return $bad(
						sprintf(__('maximum length is %d characters', 'feeds-for-tiktok'), $max_length)
					);
				}
				return $str;
		}
	}
}
