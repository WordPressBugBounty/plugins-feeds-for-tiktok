<?php

namespace SmashBalloon\TikTokFeeds\Common\UsageTracking;

use Smashballoon\Stubs\Services\ServiceProvider;
use SmashBalloon\TikTokFeeds\Common\UsageTracking\TikTok\TikTokFreeReporter;

if (! defined('ABSPATH')) {
	exit;
}

class SmashUsageTrackingService extends ServiceProvider
{
	/**
	 * Tracking orchestrator.
	 *
	 * @var SmashUsageTracking
	 */
	private $tracking;

	/**
	 * Wire the orchestrator with the Free reporter.
	 */
	public function __construct()
	{
		$this->tracking = new SmashUsageTracking(new TikTokFreeReporter());
	}

	/**
	 * Register all tracking hooks.
	 */
	public function register()
	{
		$this->tracking->init();
	}
}
