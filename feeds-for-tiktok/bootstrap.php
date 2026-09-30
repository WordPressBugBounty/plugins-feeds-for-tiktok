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

// Initialize the WordPress-compliance consent manager.
if (class_exists('\TikTokFeeds\Vendor\Smashballoon\Framework\Packages\Consent\ConsentManager')) {
	$sbtt_is_pro = (class_exists('\SmashBalloon\TikTokFeeds\Common\Utils') && \SmashBalloon\TikTokFeeds\Common\Utils::sbtt_is_pro());
	\TikTokFeeds\Vendor\Smashballoon\Framework\Packages\Consent\ConsentManager::init([
		'plugin_slug'         => 'tiktok-feeds',
		'plugin_name'         => $sbtt_is_pro ? 'Feeds for TikTok Pro' : 'Feeds for TikTok',
		'utm_slug'            => 'tiktok',
		'legacy_class'        => 'SBTT_Consent',
		'is_pro'              => $sbtt_is_pro,
		'show_reprompt_modal' => ! $sbtt_is_pro,
		'pro_init_option'     => $sbtt_is_pro ? 'sbtt_pro_consent_initialized' : null,
	]);
}

// Initialize the deactivation feedback survey.
if (class_exists('\TikTokFeeds\Vendor\Smashballoon\Framework\Packages\Feedback\FeedbackManager')) {
	$sbtt_plugin_slug    = defined('SBTT_PRO') ? 'sb-tiktok-feeds-pro' : 'sb-tiktok-feeds';
	$sbtt_utm_campaign   = defined('SBTT_PRO') ? 'tiktok-pro' : 'tiktok-free';
	\TikTokFeeds\Vendor\Smashballoon\Framework\Packages\Feedback\FeedbackManager::init([
		'plugin_slug'        => $sbtt_plugin_slug,
		'plugin_name'        => 'Smash Balloon TikTok Feeds',
		'plugin_version'     => SBTTVER,
		'plugin_file'        => SBTT_PLUGIN_FILE,
		'support_url'        => 'https://smashballoon.com/support/?utm_campaign=' . $sbtt_utm_campaign . '&utm_source=deactivation&utm_medium=support',
		'enable_help_widget' => true,
		'help_url'           => 'https://smashballoon.com/docs/tiktok/',
	]);
}

// Initialize the shared About Us page in place of the legacy AboutBuilder.
if (class_exists('\TikTokFeeds\Vendor\Smashballoon\Framework\Packages\AboutUs\AboutUsManager')) {
	\TikTokFeeds\Vendor\Smashballoon\Framework\Packages\AboutUs\AboutUsManager::init([
		'plugin_slug'    => defined('SBTT_PRO') ? 'sb-tiktok-feeds-pro' : 'sb-tiktok-feeds',
		'plugin_name'    => 'Smash Balloon TikTok Feeds',
		'plugin_version' => SBTTVER,
		'plugin_file'    => SBTT_PLUGIN_FILE,
		'menu_parent'    => SBTT_MENU_SLUG,
		'page_slug'      => 'sbtt-about',
		'menu_position'  => 4,
		'is_pro'         => defined('SBTT_PRO'),
	]);
}
