<?php

namespace SmashBalloon\TikTokFeeds\Common\Abilities\Abilities;

use SmashBalloon\TikTokFeeds\Common\Abilities\SBTT_Ability_Response;
use SmashBalloon\TikTokFeeds\Common\Abilities\SBTT_Edition;
use SmashBalloon\TikTokFeeds\Common\Abilities\Services\SBTT_Feed_Settings_Service;
use SmashBalloon\TikTokFeeds\Common\Abilities\Services\SBTT_Settings_Schema;

if (!defined('ABSPATH')) {
	exit;
}

/**
 * Ability: tiktok-feeds/create-feed
 *
 * @since SBR-501
 */
class SBTT_Create_Feed extends SBTT_Ability
{
	/**
	 * Slug after the namespace.
	 *
	 * @return string
	 */
	public function slug()
	{
		return 'create-feed';
	}

	/**
	 * Args for wp_register_ability().
	 *
	 * @return array
	 */
	public function args()
	{
		$writable = SBTT_Settings_Schema::writable(
			SBTT_Settings_Schema::for_edition(SBTT_Settings_Schema::feed_settings())
		);
		$is_pro   = SBTT_Edition::is_pro();
		// feed_name has a dedicated top-level property; a nested copy would be
		// silently written into the settings blob instead of the feeds row.
		unset($writable['feed_name']);

		$properties = array(
			'feed_name'    => array(
				'type'        => 'string',
				// Derived from the FeedsTable column width (AgDR-9004 B17) via
				// the schema's own constant, rather than a hand-picked literal
				// that could silently drift from update-feed-settings' cap.
				'maxLength'   => SBTT_Settings_Schema::FEED_NAME_MAX_LENGTH,
				'description' => 'Optional name; defaults to the first source\'s display name.',
			),
			'feedTemplate' => array(
				'type'        => 'string',
				'enum'        => SBTT_Settings_Schema::FEED_TEMPLATES,
				'description' => 'Optional feed template preset.',
			),
			'sources'      => array(
				'type'        => 'array',
				'items'       => array('type' => 'string', 'maxLength' => 100),
				// Free: one connected account (no combine_feed_sources).
				'maxItems'    => $is_pro ? 10 : (int) SBTT_Edition::sources_limit(),
				'description' => 'open_ids or display names of ALREADY-CONNECTED accounts (see list-sources).',
			),
			'settings'     => array(
				'type'                 => 'object',
				'properties'           => SBTT_Settings_Schema::to_json_properties($writable),
				'additionalProperties' => false,
				'description'          => 'Quick settings applied on top of the template.',
			),
		);
		// The free edition has only the `default` template: no property to pick.
		if (!$is_pro) {
			unset($properties['feedTemplate']);
		}

		return array(
			'label'               => __('Create a TikTok Feed', 'feeds-for-tiktok'),
			'description'         => $is_pro
				? __(
					'Creates a new feed from your own TikTok timeline, like the Feed Builder does: an optional feed template, optional quick settings, and optional already-connected accounts as sources. Returns the new feed id and its shortcode. Requires AI write access. A setting that is not in this schema may be a Pro-only feature: call describe-settings-schema (pro_only) or get-pro-features.', // phpcs:ignore Generic.Files.LineLength.TooLong -- single literal for make-pot
					'feeds-for-tiktok'
				)
				: __(
					'Creates a new feed from your own TikTok timeline, like the Feed Builder does: optional quick settings and one optional already-connected account as its source. The free edition has no feed templates and is limited to 2 feeds in total; create-feed fails once the limit is reached. Returns the new feed id and its shortcode. Requires AI write access. A setting that is not in this schema may be a Pro-only feature: call describe-settings-schema (pro_only) or get-pro-features.', // phpcs:ignore Generic.Files.LineLength.TooLong -- single literal for make-pot
					'feeds-for-tiktok'
				),
			'input_schema'        => array(
				'type'                 => 'object',
				'properties'           => $properties,
				'additionalProperties' => false,
			),
			'output_schema'       => array(
				'type'       => 'object',
				'properties' => array(
					'feed_id'   => array('type' => 'integer'),
					'feed_name' => array('type' => 'string'),
					'shortcode' => array('type' => 'string'),
					'updated'   => array('type' => 'array', 'items' => array('type' => 'string')),
					'ignored'   => array('type' => 'object'),
					// AgDR-9004 requirement 3 — same shape as write_output_schema()'s
					// `warnings`; create-feed builds its own output_schema (feed_id/
					// feed_name/shortcode have no home in the shared helper) so this
					// is declared here rather than inherited.
					'warnings'  => array(
						'type'  => 'array',
						'items' => array(
							'type'       => 'object',
							'properties' => array(
								'key'     => array('type' => 'string'),
								'reason'  => array('type' => 'string'),
								'message' => array('type' => 'string'),
							),
						),
					),
				),
			),
			'execute_callback'    => array($this, 'execute'),
			'permission_callback' => array($this, 'can_write'),
			'meta'                => $this->write_meta(false, false),
		);
	}

	/**
	 * Execute this ability.
	 *
	 * @param mixed $input Raw ability input.
	 * @return array|\WP_Error
	 */
	public function execute($input = null)
	{
		$input = $this->normalize_input($input);

		// Fail CLOSED before any write (mirrors set-feed-sources' posture): an
		// unbounded create-feed loop is asymmetrically expensive to undo, since
		// deletion is deliberately one browser-confirmed feed at a time.
		//
		// `sbtt_abilities_max_feeds` semantics: any positive integer up to the
		// edition's own feed-count ceiling (SBTT_Edition::feeds_limit(): 2 on
		// free, the licence tier's 10/20/50 on Pro, the same limits the Feed
		// Builder's UI already enforces) is honoured.
		// A non-positive value (0, negative, or a filter returning something
		// non-numeric) is NOT "unlimited" — it FAILS CLOSED to that ceiling,
		// not a flat 50 for every tier (Rex PR #120 round-1 finding #3).
		// The filter can LOWER the ceiling but never RAISE it above the
		// tier's own limit (Hakim PR #120 round-2 LOW) — a site owner using
		// the filter to be more conservative is fine; a filter silently
		// letting an agent create more feeds than the license tier allows
		// is the same class of bypass finding #3 already fixed elsewhere.
		$tier_max_feeds = SBTT_Edition::feeds_limit();
		$max_feeds      = (int) apply_filters('sbtt_abilities_max_feeds', $tier_max_feeds);
		if ($max_feeds <= 0) {
			$max_feeds = $tier_max_feeds;
		}
		$max_feeds = min($max_feeds, $tier_max_feeds);
		// FeedsTable::get_feeds_count() returns `false` both on a genuine DB
		// error AND on a healthy "0 feeds" result (`if (!$result) return false;`
		// treats the numeric string '0' as falsy too) — that ambiguity would let
		// a DB hiccup silently fail OPEN and skip the cap entirely. Disambiguate
		// with the same direct, prepared-name COUNT(*) shape
		// SBTT_Feed_Settings_Service::list_feeds() already uses. $wpdb->get_var()
		// itself returns null (not false, not '0') on a query failure, so the
		// null-check below must happen BEFORE casting to int — (int) null is 0,
		// which would silently read a failed query as "zero feeds" and defeat
		// the whole point of this disambiguation.
		global $wpdb;
		$table = $wpdb->prefix . SBTT_FEEDS_TABLE;
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name is $wpdb->prefix + constant, no user input.
		$raw = $wpdb->get_var("SELECT COUNT(*) FROM {$table}");
		if ($raw === null) {
			// Query failure: fail CLOSED, the same posture as every other
			// ambiguous case in this ability, rather than reading it as zero.
			return SBTT_Ability_Response::too_many_feeds();
		}
		$existing = (int) $raw;
		if ($existing >= $max_feeds) {
			// The hint is only true when the edition's own cap was hit, not a lowered filter.
			return SBTT_Ability_Response::too_many_feeds($existing >= $tier_max_feeds);
		}

		return (new SBTT_Feed_Settings_Service())->create_feed(
			$input,
			array(SBTT_Update_Feed_Settings::class, 'tier_check')
		);
	}
}
