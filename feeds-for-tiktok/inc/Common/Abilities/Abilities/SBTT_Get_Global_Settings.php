<?php

namespace SmashBalloon\TikTokFeeds\Common\Abilities\Abilities;

use SmashBalloon\TikTokFeeds\Common\Abilities\Services\SBTT_Global_Settings_Service;
use SmashBalloon\TikTokFeeds\Common\Abilities\Services\SBTT_Settings_Schema;

if (!defined('ABSPATH')) {
	exit;
}

/**
 * Ability: tiktok-feeds/get-global-settings
 *
 * @since SBR-501
 */
class SBTT_Get_Global_Settings extends SBTT_Ability
{
	/**
	 * Slug after the namespace.
	 *
	 * @return string
	 */
	public function slug()
	{
		return 'get-global-settings';
	}

	/**
	 * Args for wp_register_ability().
	 *
	 * @return array
	 */
	public function args()
	{
		return array(
			'label'               => __('Get TikTok Feeds global settings', 'feeds-for-tiktok'),
			'description'         => __(
				'Returns the plugin-wide TikTok Feeds settings (image optimization, GDPR, notices, preserve-on-uninstall). Never returns connected accounts, tokens or license data.', // phpcs:ignore Generic.Files.LineLength.TooLong -- single literal for make-pot
				'feeds-for-tiktok'
			),
			// No input_schema: a bare GET passes null input, which an object schema rejects.
			'output_schema'       => array(
				'type'       => 'object',
				'properties' => SBTT_Settings_Schema::to_json_properties(SBTT_Settings_Schema::for_edition(SBTT_Settings_Schema::global_settings(), true)),
			),
			'execute_callback'    => array($this, 'execute'),
			'permission_callback' => array($this, 'can_manage'),
			'meta'                => $this->read_meta(true),
		);
	}

	/**
	 * Execute this ability.
	 *
	 * @return array|\WP_Error
	 */
	public function execute()
	{
		return (new SBTT_Global_Settings_Service())->get();
	}
}
