<?php

namespace SmashBalloon\TikTokFeeds\Common\Abilities\Abilities;

use SmashBalloon\TikTokFeeds\Common\Abilities\SBTT_Ability_Response;
use SmashBalloon\TikTokFeeds\Common\Abilities\SBTT_Edition;
use SmashBalloon\TikTokFeeds\Common\Abilities\Services\SBTT_Feed_Settings_Service;

if (!defined('ABSPATH')) {
	exit;
}

/**
 * Ability: tiktok-feeds/set-feed-sources
 *
 * Destructive by annotation: changing sources changes what a feed shows, so
 * MCP clients that honour annotations (WPVibe) show their approval panel.
 *
 * @since SBR-501
 */
class SBTT_Set_Feed_Sources extends SBTT_Ability
{
	/**
	 * Slug after the namespace.
	 *
	 * @return string
	 */
	public function slug()
	{
		return 'set-feed-sources';
	}

	/**
	 * Args for wp_register_ability().
	 *
	 * @return array
	 */
	public function args()
	{
		return array(
			'label'               => __('Set the accounts a TikTok Feed shows', 'feeds-for-tiktok'),
			'description'         => __(
				'Replaces a feed\'s source list with already-connected accounts (see list-sources; use get-connect-url to connect a new one first). Changes what the feed displays. Requires AI write access.', // phpcs:ignore Generic.Files.LineLength.TooLong -- single literal for make-pot
				'feeds-for-tiktok'
			),
			'input_schema'        => array(
				'type'                 => 'object',
				'properties'           => array(
					'feed_id' => array('type' => 'integer', 'minimum' => 1),
					'sources' => array(
						'type'        => 'array',
						'items'       => array('type' => 'string', 'maxLength' => 100),
						'minItems'    => 1,
						// Free: one connected account (no combine_feed_sources).
						'maxItems'    => SBTT_Edition::is_pro() ? 10 : (int) SBTT_Edition::sources_limit(),
						'description' => 'open_ids or display names of connected accounts.',
					),
				),
				'required'             => array('feed_id', 'sources'),
				'additionalProperties' => false,
			),
			'output_schema'       => array(
				'type'       => 'object',
				'properties' => array(
					'feed_id' => array('type' => 'integer'),
					'sources' => array('type' => 'array', 'items' => array('type' => 'string')),
				),
			),
			'execute_callback'    => array($this, 'execute'),
			'permission_callback' => array($this, 'can_write'),
			'meta'                => $this->write_meta(true, true),
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
		$handles = isset($input['sources']) && is_array($input['sources'])
			? array_map('strval', $input['sources'])
			: array();
		if (empty($handles)) {
			return new \WP_Error(
				'sbtt_invalid_value',
				__('At least one connected account is required.', 'feeds-for-tiktok'),
				array('status' => 400)
			);
		}
		// Combining more than one source into a feed is a pro-tier feature
		// (Utils::get_features_list()['pro']['combine_feed_sources'],
		// SbUtils.canCombineSources on the JS side) — the input schema's
		// `maxItems: 10` alone let any tier attach up to 10 sources with no
		// license check at all (Rex PR #120 finding #3, cross-cutting fix 1).
		if (count($handles) > 1 && !SBTT_Update_Feed_Settings::tier_check('combine_feed_sources')) {
			return SBTT_Ability_Response::tier_locked('combine_feed_sources');
		}
		$result = (new SBTT_Feed_Settings_Service())->set_feed_sources($feed_id, $handles);

		return $result === null ? SBTT_Ability_Response::feed_not_found($feed_id) : $result;
	}
}
