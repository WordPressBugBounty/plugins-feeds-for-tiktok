<?php

/**
 * Main file that initializes the plugin.
 *
 * @package tiktok-feeds
 */

if (! defined('ABSPATH')) {
	exit;
}

require_once trailingslashit(SBTT_PLUGIN_DIR) . 'constants.php';
require_once trailingslashit(SBTT_PLUGIN_DIR) . 'vendor/autoload.php';

require_once __DIR__ . '/inc/Common/Utils/Utils.php';
require_once __DIR__ . '/inc/Common/Utils/SbttFunctions.php';

/**
 * SmashBalloon_TikTokFeeds class.
 */
class SmashBalloon_TikTokFeeds
{
	/**
	 * SmashBalloon_TikTokFeeds constructor.
	 */
	public function __construct()
	{
		$service = $this->get_service_container();
		$service->register();
	}

	/**
	 * Get service container. Load Pro service container if PRO version.
	 *
	 * @return \SmashBalloon\TikTokFeeds\Common\ServiceContainer|\SmashBalloon\TikTokFeeds\Pro\ServiceContainer|void
	 */
	public function get_service_container()
	{
		// Customizer container config.
		$customizer_container = \Smashballoon\Customizer\V3\Container::getInstance();
		$customizer_container->set(\Smashballoon\Customizer\V3\Config\Proxy::class, new \SmashBalloon\TikTokFeeds\Common\Config\Proxy());

		// Load Pro Service container if Pro version.
		if (
			defined('SBTT_PRO')
			&& class_exists('SmashBalloon\TikTokFeeds\Pro\ServiceContainer')
		) {
			return new SmashBalloon\TikTokFeeds\Pro\ServiceContainer();
		}

		// Load Common Service container if Free version.
		if (
			defined('SBTT_LITE')
			&& class_exists('SmashBalloon\TikTokFeeds\Common\ServiceContainer')
		) {
			return new SmashBalloon\TikTokFeeds\Common\ServiceContainer();
		}
	}
}

new SmashBalloon_TikTokFeeds();

// Initialize the deactivation feedback survey.
if (class_exists('\TikTokFeeds\Vendor\Smashballoon\Framework\Packages\Feedback\FeedbackManager')) {
	$sbtt_plugin_slug = defined('SBTT_PRO') ? 'sb-tiktok-feeds-pro' : 'sb-tiktok-feeds';
	\TikTokFeeds\Vendor\Smashballoon\Framework\Packages\Feedback\FeedbackManager::init([
		'plugin_slug'    => $sbtt_plugin_slug,
		'plugin_name'    => 'Smash Balloon TikTok Feeds',
		'plugin_version' => SBTTVER,
		'plugin_file'    => SBTT_PLUGIN_FILE,
		'support_url'    => 'https://smashballoon.com/support/',
	]);
}
