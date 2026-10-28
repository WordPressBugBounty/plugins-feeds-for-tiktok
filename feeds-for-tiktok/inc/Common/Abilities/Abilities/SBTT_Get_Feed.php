<?php

namespace SmashBalloon\TikTokFeeds\Common\Abilities\Abilities;

use SmashBalloon\TikTokFeeds\Common\Abilities\SBTT_Ability_Response;
use SmashBalloon\TikTokFeeds\Common\Abilities\Services\SBTT_Feed_Settings_Service;
use SmashBalloon\TikTokFeeds\Common\Abilities\Services\SBTT_Settings_Schema;

if (!defined('ABSPATH')) {
	exit;
}

/**
 * Ability: tiktok-feeds/get-feed
 *
 * @since SBR-501
 */
class SBTT_Get_Feed extends SBTT_Ability
{
	/**
	 * Slug after the namespace.
	 *
	 * @return string
	 */
	public function slug()
	{
		return 'get-feed';
	}

	/**
	 * Args for wp_register_ability().
	 *
	 * @return array
	 */
	public function args()
	{
		return array(
			'label'               => __('Get a TikTok Feed', 'feeds-for-tiktok'),
			'description'         => __(
				'Returns one feed\'s customer-configurable settings (layout, columns, post count, header, captions, buttons, colors). Connected accounts are reduced to open_id and display name; tokens are never returned.', // phpcs:ignore Generic.Files.LineLength.TooLong -- single literal for make-pot
				'feeds-for-tiktok'
			),
			'input_schema'        => array(
				'type'                 => 'object',
				'properties'           => array(
					'feed_id' => array(
						'type'        => 'integer',
						'minimum'     => 1,
						'description' => 'Feed id from list-feeds.',
					),
				),
				'required'             => array('feed_id'),
				'additionalProperties' => false,
			),
			'output_schema'       => array(
				'type'       => 'object',
				'properties' => array(
					'id'            => array('type' => 'integer'),
					'feed_title'    => array('type' => 'string'),
					'status'        => array('type' => 'string'),
					'last_modified' => array('type' => 'string'),
					'shortcode'     => array('type' => 'string'),
					'sources'       => array(
						'type'  => 'array',
						'items' => array(
							'type'       => 'object',
							'properties' => array(
								'open_id'      => array('type' => 'string'),
								'display_name' => array('type' => 'string'),
							),
						),
					),
					// Read-side warnings (AgDR-9005 downgrade rule): present only
					// when a stored value is one the free edition cannot set.
					'warnings'      => array(
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
					'settings'      => array(
						'type'       => 'object',
						'properties' => SBTT_Settings_Schema::to_json_properties(SBTT_Settings_Schema::for_edition(SBTT_Settings_Schema::feed_settings(), true)),
					),
				),
			),
			'execute_callback'    => array($this, 'execute'),
			'permission_callback' => array($this, 'can_manage'),
			'meta'                => $this->read_meta(true),
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
		$feed = (new SBTT_Feed_Settings_Service())->get_feed($feed_id);
		return $feed === null ? SBTT_Ability_Response::feed_not_found($feed_id) : $feed;
	}
}
