<?php

namespace SmashBalloon\TikTokFeeds\Common\Admin;

use Smashballoon\Framework\Packages\Blocks\RecommendedBlocks;
use Smashballoon\Stubs\Services\ServiceProvider;

class Blocks extends ServiceProvider
{
	/**
	 * Register the service provider.
	 *
	 * @return void
	 */
	public function register()
	{
		add_action('init', [ $this, 'register_blocks' ]);
	}

	/**
	 * Registers the blocks for the TikTok Feeds plugin.
	 */
	public function register_blocks()
	{
		$recommended_blocks = new RecommendedBlocks();
		$recommended_blocks->setup();
	}
}
