<?php

namespace SmashBalloon\TikTokFeeds\Common\Settings\Tabs;

use Smashballoon\Customizer\V3\SB_SettingsPage_Tab;

if (! defined('ABSPATH')) {
	exit;
}

class FeedsTab extends SB_SettingsPage_Tab
{
	/**
	 * Get the Settings Tab info
	 *
	 * @since 1.0
	 *
	 * @return array
	 */
	protected function tab_info()
	{
		return [
			'id'   => 'sb-feeds-tab',
			'name' => __('Feeds', 'feeds-for-tiktok'),
		];
	}

	/**
	 * Get the Settings Tab Section
	 *
	 * @since 1.0
	 *
	 * @return array
	 */
	protected function tab_sections()
	{
		return [
			'caching_section' => [
				'type'    => 'caching',
				'heading' => __('Caching', 'feeds-for-tiktok'),
			],
			'gdpr_section'    => [
				'id'        => 'gdpr',
				'type'      => 'gdpr',
				'heading'   => __('GDPR', 'feeds-for-tiktok'),
				'separator' => true,
			],
		];
	}
}
