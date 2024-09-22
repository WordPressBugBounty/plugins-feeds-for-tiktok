<?php

/**
 * Service responsible with plugin activation functionality.
 *
 * @package tiktok-feeds
 */

namespace SmashBalloon\TikTokFeeds\Common\Services;

use SmashBalloon\TikTokFeeds\Common\Container;

/**
 * Plugin Activation Service class.
 */
class ActivationService
{
	/**
	 * Register.
	 *
	 * @return void
	 */
	public function register()
	{
		register_activation_hook(SBTT_PLUGIN_FILE, [ $this, 'activate' ]);
		add_action('activated_plugin', [ $this, 'on_plugin_activation' ]);
	}

	/**
	 * Setup db tables.
	 *
	 * @return void
	 */
	public function activate()
	{
		Container::get_instance()->get('DBManager')->create_or_update_db_tables();
		self::create_upload_folder();
	}

	/**
	 * Create upload folder.
	 *
	 * @return void
	 */
	public static function create_upload_folder()
	{
		$upload_dir = wp_upload_dir();
		$upload_dir = $upload_dir['basedir'];
		$upload_dir = trailingslashit($upload_dir) . SBTT_UPLOAD_FOLDER_NAME;

		if (! file_exists($upload_dir)) {
			wp_mkdir_p($upload_dir);
		}
	}

	/**
	 * On plugin activation.
	 *
	 * @param string $plugin Plugin path.
	 * @return void
	 */
	public function on_plugin_activation($plugin)
	{
		if (! in_array(basename($plugin), array( 'feeds-for-tiktok.php', 'tiktok-feeds-pro.php' ))) {
			return;
		}

		$plugin_to_deactivate = 'feeds-for-tiktok/feeds-for-tiktok.php';
		if (strpos($plugin, $plugin_to_deactivate) !== false) {
			$plugin_to_deactivate = 'tiktok-feeds-pro/tiktok-feeds-pro.php';
		}

		$active_plugins = $this->get_active_plugins();
		foreach ($active_plugins as $plugin) {
			if ($plugin === $plugin_to_deactivate) {
				deactivate_plugins($plugin);
				return;
			}
		}
	}

	/**
	 * Get active plugins.
	 *
	 * @return array
	 */
	private function get_active_plugins()
	{
		if (is_multisite()) {
			$active_plugins = array_keys((array)get_site_option('active_sitewide_plugins', array()));
		} else {
			$active_plugins = (array)get_option('active_plugins', array());
		}

		return $active_plugins;
	}
}
