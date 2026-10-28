<?php

namespace SmashBalloon\TikTokFeeds\Common\Abilities\Abilities;

use SmashBalloon\TikTokFeeds\Common\Abilities\Services\SBTT_Source_Service;

if (!defined('ABSPATH')) {
	exit;
}

/**
 * Ability: tiktok-feeds/list-sources
 *
 * @since SBR-501
 */
class SBTT_List_Sources extends SBTT_Ability
{
	/**
	 * Slug after the namespace.
	 *
	 * @return string
	 */
	public function slug()
	{
		return 'list-sources';
	}

	/**
	 * Args for wp_register_ability().
	 *
	 * @return array
	 */
	public function args()
	{
		return array(
			'label'               => __('List connected TikTok accounts', 'feeds-for-tiktok'),
			'description'         => __(
				'Lists the TikTok accounts connected to this site (open_id, display name, token expiry date, error flag, how many feeds use each). Never returns access tokens or account credentials.', // phpcs:ignore Generic.Files.LineLength.TooLong -- single literal for make-pot
				'feeds-for-tiktok'
			),
			// No input_schema: a bare GET passes null input, which an object schema rejects.
			'output_schema'       => array(
				'type'       => 'object',
				'properties' => array(
					'sources' => array(
						'type'  => 'array',
						'items' => array(
							'type'       => 'object',
							'properties' => array(
								'open_id'       => array('type' => 'string'),
								'display_name'  => array('type' => 'string'),
								'expires'       => array('type' => 'string'),
								'has_error'     => array('type' => 'boolean'),
								'used_in_feeds' => array('type' => 'integer'),
							),
						),
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
	 * @return array|\WP_Error
	 */
	public function execute()
	{
		return array('sources' => (new SBTT_Source_Service())->list_sources());
	}
}
