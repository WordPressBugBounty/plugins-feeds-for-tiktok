<?php

namespace SmashBalloon\TikTokFeeds\Common\Admin\Blocks;

if (! defined('ABSPATH')) {
	exit;
}

use TikTokFeeds\Vendor\Smashballoon\Framework\Packages\Blocks\SB_Feed_Block;
use TikTokFeeds\Vendor\Smashballoon\Framework\Packages\Blocks\SB_Feed_Blocks_Registry;
use SmashBalloon\TikTokFeeds\Common\Abilities\SBTT_Edition;
use SmashBalloon\TikTokFeeds\Common\Utils;

class SBTT_Modern_Feed_Block extends SB_Feed_Block
{
	/**
	 * ServiceContainer entry point. Delegates to the framework's hook registrar
	 * so this class can sit alongside the other plugin services in
	 * `ServiceContainer::$services`.
	 *
	 * @return void
	 */
	public function register()
	{
		$this->register_hooks();
	}

	/**
	 * Block type name registered with WordPress.
	 *
	 * @return string
	 */
	protected function get_block_name()
	{
		return 'smashballoon/tiktok-feed';
	}

	/**
	 * Shortcode tag the block renders to on the server.
	 *
	 * @return string
	 */
	protected function get_shortcode_tag()
	{
		return 'sbtt-tiktok';
	}

	/**
	 * Script handle for the block's editor/frontend assets.
	 *
	 * @return string
	 */
	protected function get_script_handle()
	{
		return 'sbtt-feed-blocks';
	}

	/**
	 * Plugin text domain used for block i18n.
	 *
	 * @return string
	 */
	protected function get_text_domain()
	{
		return 'feeds-for-tiktok';
	}

	/**
	 * Absolute path to the plugin directory (trailing-slashed).
	 *
	 * @return string
	 */
	protected function get_plugin_dir()
	{
		return trailingslashit(SBTT_PLUGIN_DIR);
	}

	/**
	 * Action hook that enqueues the feed's frontend assets.
	 *
	 * @return string
	 */
	protected function get_enqueue_scripts_action()
	{
		return 'sbtt_enqueue_scripts';
	}

	/**
	 * JS global the editor localization data is attached to.
	 *
	 * @return string
	 */
	protected function get_localize_var_name()
	{
		return 'sbttFeedBlock';
	}

	/**
	 * Feed block identifier shared with the framework registry.
	 *
	 * @return string
	 */
	protected function get_feed_block_id()
	{
		return 'tiktok';
	}

	/**
	 * Name of the JS function that initializes the feed on the frontend.
	 *
	 * @return string
	 */
	protected function get_init_function()
	{
		return 'sbttInitializeFeed';
	}

	/**
	 * Override the parent default which points at `build/feed-block/<id>`.
	 * The framework ships the TikTok block.json under `dist/feed-blocks/tiktok`.
	 */
	protected function get_block_dir()
	{
		return $this->get_plugin_dir() . 'vendor/smashballoon/framework/Packages/Blocks/dist/feed-blocks/tiktok';
	}

	/**
	 * Editor assets, plus the Add to Page insert when the Embed modal sent the
	 * user here with sbtt_wizard=<feed id>. Before WordPress 5.8 the block.json
	 * registration fails on the server, so the editor's block renders nothing; the
	 * script then shows a notice with the shortcode instead of inserting it.
	 *
	 * @return void
	 */
	public function enqueue_editor_assets()
	{
		parent::enqueue_editor_assets();

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$feed_id = isset($_GET['sbtt_wizard']) ? max(0, (int) $_GET['sbtt_wizard']) : 0;
		if (! $feed_id) {
			return;
		}

		$registered = \WP_Block_Type_Registry::get_instance()->is_registered($this->get_block_name());
		$deps       = array( 'wp-blocks', 'wp-data', 'wp-dom-ready', 'wp-editor', 'wp-notices' );
		// The block scripts' deps (wp-server-side-render is 5.3+) can be missing
		// where the block is unregistered, and would drop this script, notice and all.
		if ($registered) {
			$deps[] = SB_Feed_Blocks_Registry::instance()->get_script_handle();
		}
		$config = array( 'feedId' => $feed_id );
		if (! $registered) {
			$config['notice'] = sprintf(
				/* translators: %s: feed shortcode */
				__(
					'The TikTok Feed block needs WordPress 5.8 or later. To show this feed, add this shortcode to the page: %s',
					'feeds-for-tiktok'
				),
				SBTT_Edition::shortcode($feed_id)
			);
		}

		wp_enqueue_script(
			'sbtt-add-to-page-block',
			SBTT_PLUGIN_URL . 'assets/js/add-to-page-block.js',
			$deps,
			SBTTVER . '.' . filemtime(SBTT_PLUGIN_DIR . 'assets/js/add-to-page-block.js'),
			true
		);
		wp_localize_script(
			'sbtt-add-to-page-block',
			'sbttAddToPage',
			$config
		);
	}

	/**
	 * Build the data localized into the editor for the block UI.
	 *
	 * @return array
	 */
	protected function get_editor_localize_data()
	{
		return array(
			'feeds'         => Utils::get_feeds_compact(),
			'feed_url'      => admin_url('admin.php?page=sbtt'),
			'is_pro_active' => Utils::sbtt_is_pro(),
			'nonce'         => wp_create_nonce('sbtt-frontend'),
		);
	}
}
