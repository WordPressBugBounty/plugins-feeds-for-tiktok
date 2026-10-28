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
 * Ability: tiktok-feeds/update-feed-settings
 *
 * Gated by SBTT_Write_Gate, then capability, then a per-key/per-value
 * license-tier check: tier-locked keys (or tier-locked values of an
 * otherwise-free key) are not written and come back in `ignored` as
 * `feature_unavailable`. In the free edition Pro-only keys are not in the
 * schema at all (SBTT_Settings_Schema::for_edition()), so core rejects them
 * with a 400 before this code runs.
 *
 * @since SBR-501
 */
class SBTT_Update_Feed_Settings extends SBTT_Ability
{
	/**
	 * Slug after the namespace.
	 *
	 * @return string
	 */
	public function slug()
	{
		return 'update-feed-settings';
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
		$props    = array(
			'feed_id'  => array(
				'type'        => 'integer',
				'minimum'     => 1,
				'description' => 'Feed id from list-feeds.',
			),
			'settings' => array(
				'type'                 => 'object',
				'properties'           => SBTT_Settings_Schema::to_json_properties($writable),
				'minProperties'        => 1,
				'additionalProperties' => false,
				'description'          => 'Settings to change. Only the keys you pass are changed; '
					. 'other stored values are re-saved as-is.',
			),
		);

		return array(
			'label'               => __('Update a TikTok Feed', 'feeds-for-tiktok'),
			'description'         => SBTT_Edition::is_pro()
				? __(
					'Changes one or more settings of a feed (layout, columns, post count, header, captions, buttons, colours, sorting, filters). Only the keys you pass are changed. Requires AI write access to be enabled in TikTok Feeds → Settings → General. Returns updated and ignored keys. Colour settings are saved but only restyle the live page after the feed is opened and saved in the Feed Builder: the response lists them in warnings. A setting that is not in this schema may be a Pro-only feature: call describe-settings-schema (pro_only) or get-pro-features.', // phpcs:ignore Generic.Files.LineLength.TooLong -- single literal for make-pot
					'feeds-for-tiktok'
				)
				: __(
					'Changes one or more settings of a feed: its name, post counts, grid columns, spacing, header (show or hide, name and username colours, follow-button label and colours) and sort order (latest or oldest). Only the keys you pass are changed. Requires AI write access to be enabled in TikTok Feeds → Settings → General. Returns updated and ignored keys. Colour settings are saved but only restyle the live page after the feed is opened and saved in the Feed Builder: the response lists them in warnings. A setting that is not in this schema may be a Pro-only feature: call describe-settings-schema (pro_only) or get-pro-features.', // phpcs:ignore Generic.Files.LineLength.TooLong -- single literal for make-pot
					'feeds-for-tiktok'
				),
			'input_schema'        => array(
				'type'                 => 'object',
				'properties'           => $props,
				'required'             => array('feed_id', 'settings'),
				'additionalProperties' => false,
			),
			'output_schema'       => $this->write_output_schema(array('feed_id' => array('type' => 'integer'))),
			'execute_callback'    => array($this, 'execute'),
			'permission_callback' => array($this, 'can_write'),
			'meta'                => $this->write_meta(false, true),
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
		$input   = $this->normalize_input($input);
		$feed_id = isset($input['feed_id']) ? (int) $input['feed_id'] : 0;
		if ($feed_id <= 0) {
			return SBTT_Ability_Response::invalid_feed_id();
		}
		$settings = isset($input['settings']) ? $this->normalize_input($input['settings']) : array();

		$service = new SBTT_Feed_Settings_Service();
		$result  = $service->update_feed($feed_id, $settings, array(__CLASS__, 'is_feature_available'));

		return $result === null ? SBTT_Ability_Response::feed_not_found($feed_id) : $result;
	}

	/**
	 * Same gate the Feed Builder applies: feature present in the current
	 * license tier's feature list.
	 *
	 * @param string $feature License feature name.
	 * @return bool
	 */
	public static function is_feature_available($feature)
	{
		return self::tier_check($feature);
	}

	/**
	 * Same gate the Feed Builder applies, reusable by other abilities.
	 *
	 * @param string $feature License feature name.
	 * @return bool
	 */
	public static function tier_check($feature)
	{
		// Edition-keyed (AgDR-9005): on the free build NO tier feature is
		// available, so every tier-gated key or value is refused. This used to
		// be `if (!sbtt_is_pro()) return true;` ("free build: no tier
		// concept"), which would have made every Pro key writable in free.
		return SBTT_Edition::has($feature);
	}
}
