<?php

namespace SmashBalloon\TikTokFeeds\Common\Abilities\Abilities;

use SmashBalloon\TikTokFeeds\Common\Abilities\Services\SBTT_Feed_Settings_Service;

if (!defined('ABSPATH')) {
	exit;
}

/**
 * Ability: tiktok-feeds/list-feeds
 *
 * @since SBR-501
 */
class SBTT_List_Feeds extends SBTT_Ability
{
	/**
	 * Slug after the namespace.
	 *
	 * @return string
	 */
	public function slug()
	{
		return 'list-feeds';
	}

	/**
	 * Args for wp_register_ability().
	 *
	 * @return array
	 */
	public function args()
	{
		$summary = array(
			'type'       => 'object',
			'properties' => array(
				'id'            => array('type' => 'integer'),
				'feed_name'     => array('type' => 'string'),
				'feed_title'    => array('type' => 'string'),
				'layout'        => array('type' => 'string'),
				'status'        => array('type' => 'string'),
				'last_modified' => array('type' => 'string'),
				'shortcode'     => array('type' => 'string', 'description' => 'Shortcode that renders this feed.'),
			),
		);

		return array(
			'label'               => __('List TikTok Feeds', 'feeds-for-tiktok'),
			'description'         => __(
				'Lists the feeds created in the Feed Builder with their id, name, layout and shortcode. Paginated.', // phpcs:ignore Generic.Files.LineLength.TooLong -- single literal for make-pot
				'feeds-for-tiktok'
			),
			'input_schema'        => array(
				// null is accepted because a bare GET passes null input (all params are optional).
				'type'                 => array('object', 'null'),
				'properties'           => array(
					'page'     => array('type' => 'integer', 'minimum' => 1, 'default' => 1),
					'per_page' => array(
						'type'    => 'integer',
						'minimum' => 1,
						'maximum' => SBTT_Feed_Settings_Service::MAX_PER_PAGE,
						'default' => 20,
					),
				),
				'additionalProperties' => false,
			),
			'output_schema'       => array(
				'type'       => 'object',
				'properties' => array(
					'feeds'       => array('type' => 'array', 'items' => $summary),
					'total'       => array('type' => 'integer'),
					'page'        => array('type' => 'integer'),
					'per_page'    => array('type' => 'integer'),
					'total_pages' => array('type' => 'integer'),
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
		$input = $this->normalize_input($input);
		return (new SBTT_Feed_Settings_Service())->list_feeds(
			isset($input['page']) ? (int) $input['page'] : 1,
			isset($input['per_page']) ? (int) $input['per_page'] : 20
		);
	}
}
