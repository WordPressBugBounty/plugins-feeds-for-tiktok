<?php

namespace SmashBalloon\TikTokFeeds\Common\Abilities\Abilities;

use SmashBalloon\TikTokFeeds\Common\Abilities\SBTT_Edition;
use SmashBalloon\TikTokFeeds\Common\Abilities\Services\SBTT_Global_Settings_Service;
use SmashBalloon\TikTokFeeds\Common\Abilities\Services\SBTT_Settings_Schema;

if (!defined('ABSPATH')) {
	exit;
}

/**
 * Ability: tiktok-feeds/update-global-settings
 *
 * Gated by SBTT_Write_Gate (off by default), then the same capability as the
 * Settings page. Unknown keys are rejected by core (additionalProperties:false);
 * read-only keys are reported in `ignored`.
 *
 * @since SBR-501
 */
class SBTT_Update_Global_Settings extends SBTT_Ability
{
	/**
	 * Slug after the namespace.
	 *
	 * @return string
	 */
	public function slug()
	{
		return 'update-global-settings';
	}

	/**
	 * Args for wp_register_ability().
	 *
	 * @return array
	 */
	public function args()
	{
		$writable = SBTT_Settings_Schema::writable(SBTT_Settings_Schema::for_edition(SBTT_Settings_Schema::global_settings()));

		return array(
			'label'               => __('Update TikTok Feeds global settings', 'feeds-for-tiktok'),
			'description'         => SBTT_Edition::is_pro()
				? __(
					'Updates one or more plugin-wide TikTok Feeds settings (image optimization, notices, preserve-on-uninstall). Requires AI write access to be enabled in TikTok Feeds → Settings → General. Returns the keys that were updated and the keys that were ignored.', // phpcs:ignore Generic.Files.LineLength.TooLong -- single literal for make-pot
					'feeds-for-tiktok'
				)
				: __(
					'Updates one or more plugin-wide TikTok Feeds settings: image optimization, GDPR mode and preserve-on-uninstall. Requires AI write access to be enabled in TikTok Feeds → Settings → General. Returns the keys that were updated and the keys that were ignored.', // phpcs:ignore Generic.Files.LineLength.TooLong -- single literal for make-pot
					'feeds-for-tiktok'
				),
			'input_schema'        => array(
				'type'                 => 'object',
				'properties'           => SBTT_Settings_Schema::to_json_properties($writable),
				'minProperties'        => 1,
				'additionalProperties' => false,
			),
			'output_schema'       => $this->write_output_schema(),
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
		return (new SBTT_Global_Settings_Service())->update($this->normalize_input($input));
	}
}
