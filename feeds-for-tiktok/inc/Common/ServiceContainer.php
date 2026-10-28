<?php

namespace SmashBalloon\TikTokFeeds\Common;

use SmashBalloon\TikTokFeeds\Common\Container;
use SmashBalloon\TikTokFeeds\Common\Abilities\SBTT_Abilities;
use Smashballoon\Stubs\Services\ServiceProvider;
use SmashBalloon\TikTokFeeds\Common\Admin\MenuService;
use SmashBalloon\TikTokFeeds\Common\Admin\Blocks;
use SmashBalloon\TikTokFeeds\Common\Admin\Blocks\SBTT_Modern_Feed_Block;
use SmashBalloon\TikTokFeeds\Common\Admin\SupportBuilder;
use SmashBalloon\TikTokFeeds\Common\Integrations\Elementor\SBTT_Elementor_Base;
use SmashBalloon\TikTokFeeds\Common\Customizer\FeedBuilder;
use SmashBalloon\TikTokFeeds\Common\Integrations\FeedAnalytics;
use SmashBalloon\TikTokFeeds\Common\Services\ActivationService;
use SmashBalloon\TikTokFeeds\Common\Services\AjaxHandlerService;
use SmashBalloon\TikTokFeeds\Common\Services\DBManagerService;
use SmashBalloon\TikTokFeeds\Common\Services\DeactivationService;
use SmashBalloon\TikTokFeeds\Common\Services\SettingsManagerService;
use SmashBalloon\TikTokFeeds\Common\Services\Upgrade\RoutineManagerService;
use SmashBalloon\TikTokFeeds\Common\Services\PluginUpgraderService;
use SmashBalloon\TikTokFeeds\Common\Services\PluginInstallerService;
use SmashBalloon\TikTokFeeds\Common\Services\ActionHooksService;
use SmashBalloon\TikTokFeeds\Common\Services\NotificationService;
use SmashBalloon\TikTokFeeds\Common\Services\ShortcodeService;
use SmashBalloon\TikTokFeeds\Common\Services\UninstallService;
use SmashBalloon\TikTokFeeds\Common\UsageTracking\SmashUsageTrackingService;
use SmashBalloon\TikTokFeeds\Common\Services\NewUserService;
use SmashBalloon\TikTokFeeds\Common\Settings\SettingsBuilder;

class ServiceContainer extends ServiceProvider
{
	/**
	 * Services
	 *
	 * @var mixed[]
	 */
	protected $services = [
		ActivationService::class,
		DeactivationService::class,
		UninstallService::class,
		ActionHooksService::class,
		SettingsManagerService::class,
		RoutineManagerService::class,
		PluginInstallerService::class,
		PluginUpgraderService::class,
		// Customizer Services.
		\Smashballoon\Customizer\V3\ServiceContainer::class,
		MenuService::class,
		FeedBuilder::class,
		SettingsBuilder::class,
		SupportBuilder::class,
		DBManagerService::class,
		ShortcodeService::class,
		AjaxHandlerService::class,
		SmashUsageTrackingService::class,
		NotificationService::class,
		NewUserService::class,
		FeedAnalytics::class,
		Blocks::class,
		SBTT_Modern_Feed_Block::class,
		SBTT_Elementor_Base::class,
		// SBR-501 / SBR-579 -- WordPress Abilities API integration (AgDR-0503,
		// AgDR-9005). One module for both editions; the schema is
		// edition-filtered (SBTT_Edition).
		SBTT_Abilities::class,
	];

	/**
	 * Constructor.
	 *
	 * @return void
	 */
	public function __construct()
	{
		$container = Container::get_instance();
		foreach ($this->services as $service) {
			// Some services need a Proxy instance to be passed to them.
			if ($service === SupportBuilder::class) {
				$container->set($service, new $service($container->get('Proxy')));
				continue;
			}
			$container->set($service, new $service());
		}
	}

	/**
	 * Register the services.
	 *
	 * @return void
	 */
	public function register(): void
	{
		$container = Container::get_instance();

		foreach ($this->services as $service) {
			$serviceInstance = $container->get($service);

			if ($serviceInstance !== null) {
				$serviceInstance->register();
			}
		}
	}
}
