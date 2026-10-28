<?php

namespace SmashBalloon\TikTokFeeds\Common\Abilities\Abilities;

use SmashBalloon\TikTokFeeds\Common\Abilities\Services\SBTT_Source_Service;

if (!defined('ABSPATH')) {
	exit;
}

/**
 * Ability: tiktok-feeds/get-connect-url
 *
 * The OAuth handoff: the agent hands the returned wp-admin link to the human;
 * that page (SBTT_Connect_Bootstrap) consumes the single-use state token and
 * redirects to the TikTok connect relay with the plugin's own native
 * connect parameters (see SBTT_Connect_Bootstrap for why this is a plain
 * redirect, not an auto-submitted form). The agent never sees TikTok
 * credentials.
 *
 * @since SBR-501
 */
class SBTT_Get_Connect_Url extends SBTT_Ability
{
	/**
	 * Slug after the namespace.
	 *
	 * @return string
	 */
	public function slug()
	{
		return 'get-connect-url';
	}

	/**
	 * Args for wp_register_ability().
	 *
	 * @return array
	 */
	public function args()
	{
		return array(
			'label'               => __('Get a TikTok account-connect link', 'feeds-for-tiktok'),
			'description'         => __(
				'Returns a single-use link (valid 15 minutes) the site admin opens in their browser to connect a TikTok account via TikTok\'s own login/authorize flow. The assistant never touches TikTok credentials. After connecting, use list-sources to see the new account.', // phpcs:ignore Generic.Files.LineLength.TooLong -- single literal for make-pot
				'feeds-for-tiktok'
			),
			// Null-tolerant: POST abilities must define an input schema when any
			// input arrives; a bodyless POST passes null.
			'input_schema'        => array(
				'type'                 => array('object', 'null'),
				'additionalProperties' => false,
			),
			'output_schema'       => array(
				'type'       => 'object',
				'properties' => array(
					'connect_url'  => array('type' => 'string'),
					'expires_in'   => array('type' => 'integer'),
					'instructions' => array('type' => 'string'),
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
	 * @return array|\WP_Error
	 */
	public function execute()
	{
		return (new SBTT_Source_Service())->get_connect_url();
	}
}
