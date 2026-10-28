<?php

namespace SmashBalloon\TikTokFeeds\Common\Abilities\Abilities;

use SmashBalloon\TikTokFeeds\Common\Abilities\SBTT_Pro_Upsell;
use SmashBalloon\TikTokFeeds\Common\Abilities\SBTT_Write_Gate;
use SmashBalloon\TikTokFeeds\Common\Abilities\Services\SBTT_Settings_Schema;

if (!defined('ABSPATH')) {
	exit;
}

/**
 * Ability: tiktok-feeds/describe-settings-schema
 *
 * Lets an agent discover which keys it may change and their allowed values.
 * Always visible over REST; hidden from MCP discovery while writes are
 * disabled so agents are not shown an editing surface they cannot use.
 *
 * @since SBR-501
 */
class SBTT_Describe_Settings_Schema extends SBTT_Ability
{
	/**
	 * Slug after the namespace.
	 *
	 * @return string
	 */
	public function slug()
	{
		return 'describe-settings-schema';
	}

	/**
	 * Args for wp_register_ability().
	 *
	 * @return array
	 */
	public function args()
	{
		return array(
			'label'               => __('Describe editable TikTok Feeds settings', 'feeds-for-tiktok'),
			'description'         => __(
				'Returns the JSON Schema of the per-feed and global settings that can be read or updated through the TikTok Feeds abilities, and whether write access is currently enabled.', // phpcs:ignore Generic.Files.LineLength.TooLong -- single literal for make-pot
				'feeds-for-tiktok'
			),
			'output_schema'       => array(
				'type'       => 'object',
				'properties' => array(
					'write_enabled'   => array('type' => 'boolean'),
					'feed_settings'   => array('type' => 'object'),
					'global_settings' => array('type' => 'object'),
					'pro_only'        => array(
						'type'        => 'object',
						'description' => 'Free edition only. Settings and values that exist in Pro but are not '
							. 'available here, with the upgrade link. Mention it only when the user wants one of them.',
						'properties'  => array(
							'settings'    => array('type' => 'array', 'items' => array('type' => 'string')),
							'upgrade_url' => array('type' => 'string'),
						),
					),
				),
			),
			'execute_callback'    => array($this, 'execute'),
			'permission_callback' => array($this, 'can_manage'),
			'meta'                => $this->read_meta(SBTT_Write_Gate::is_enabled()),
		);
	}

	/**
	 * Execute this ability.
	 *
	 * @return array|\WP_Error
	 */
	public function execute()
	{
		$out = array(
			'write_enabled'   => SBTT_Write_Gate::is_enabled(),
			'feed_settings'   => array(
				'type'       => 'object',
				'properties' => SBTT_Settings_Schema::to_json_properties(SBTT_Settings_Schema::for_edition(SBTT_Settings_Schema::feed_settings())),
			),
			'global_settings' => array(
				'type'       => 'object',
				'properties' => SBTT_Settings_Schema::to_json_properties(SBTT_Settings_Schema::for_edition(SBTT_Settings_Schema::global_settings())),
			),
		);
		if (SBTT_Pro_Upsell::is_free()) {
			$out['pro_only'] = array(
				'settings'    => SBTT_Pro_Upsell::pro_only_settings(),
				'upgrade_url' => SBTT_Pro_Upsell::upgrade_url('describe-settings-schema'),
			);
		}
		return $out;
	}
}
