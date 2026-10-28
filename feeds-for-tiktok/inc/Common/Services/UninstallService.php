<?php

/**
 * Service responsible with plugin uninstall functionality.
 *
 * @package tiktok-feeds
 */

namespace SmashBalloon\TikTokFeeds\Common\Services;

use SmashBalloon\TikTokFeeds\Common\Container;

/**
 * Plugin Uninstall Service class.
 */
class UninstallService
{
	/**
	 * Register.
	 *
	 * @return void
	 */
	public function register()
	{
		register_uninstall_hook(SBTT_PLUGIN_FILE, [ self::class, 'uninstall' ]);
	}

	/**
	 * Remove plugin database data. Drop tables when the plugin is deleted from WordPress Admin Plugins page.
	 *
	 * @return void
	 */
	public static function uninstall()
	{
		if (!function_exists('get_plugins')) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		$is_pro = defined('SBTT_PRO') && SBTT_PRO === true;

		$installed_plugins = get_plugins();

		$free_plugin_is_installed = isset($installed_plugins['feeds-for-tiktok/feeds-for-tiktok.php']);
		$pro_plugin_is_installed = isset($installed_plugins['tiktok-feeds-pro/tiktok-feeds-pro.php']);

		if (($is_pro && $free_plugin_is_installed) || (!$is_pro && $pro_plugin_is_installed)) {
			// Do nothing.
			return;
		}

		// Abilities API state tokens + slots (SBTT_State_Token::PREFIX). They no
		// longer start with `sbtt_`, so the cache-wipe patterns do not reach them;
		// remove them explicitly, even when settings are preserved (they are
		// short-lived link credentials, not user data).
		self::delete_abilities_tokens();

		$global_settings = get_option('sbtt_global_settings');

		if (isset($global_settings['preserve_settings']) && $global_settings['preserve_settings']) {
			return;
		}

		self::remove_capabilities();

		self::delete_db_tables();

		self::delete_options();

		self::delete_cron_jobs();

		self::delete_upload_folder();
	}

	/**
	 * Remove Abilities API state-token and slot transients (and timeout rows).
	 *
	 * @return void
	 */
	public static function delete_abilities_tokens()
	{
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->query("DELETE FROM $wpdb->options WHERE option_name LIKE '\_transient\_sb\_abilities\_sbtt\_state\_%'");
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->query("DELETE FROM $wpdb->options WHERE option_name LIKE '\_transient\_timeout\_sb\_abilities\_sbtt\_state\_%'");
	}

	/**
	 * Remove custom capability from all roles.
	 *
	 * @return void
	 */
	public static function remove_capabilities()
	{
		global $wp_roles;
		foreach ($wp_roles->roles as $role_name => $role_info) {
			$wp_roles->remove_cap($role_name, 'manage_tiktok_feed_options');
		}
	}

	/**
	 * Remove plugin database data.
	 *
	 * @return void
	 */
	public static function delete_db_tables()
	{
		Container::get_instance()->get('DBManager')->drop_db_tables();
	}

	/**
	 * Remove plugin options.
	 *
	 * @return void
	 */
	public static function delete_options()
	{
		$options_to_delete = [
			'sbtt_global_settings',
			'sbtt_statuses',
			'sbtt_db_version',
			'sbtt_usage_tracking_config',
			'sbtt_smash_usage_tracking',
			'sbtt_smash_usage_tracking_site_token',
			'sbtt_smash_usage_tracking_schedule',
			'sbtt_smash_usage_events',
			'sbtt_smash_usage_active_dates',
			'sbtt_smash_usage_session_durations',
			'sbtt_source_errors',
			'sbtt_newuser_notifications',
			'sbtt_notifications',
			'sbtt_resize_images_data'
		];

		foreach ($options_to_delete as $option) {
			delete_option($option);
		}

		// Gate epoch (SBTT_Write_Gate::EPOCH_OPTION). Must come AFTER the settings
		// option above: the plugin's hooks are live in the uninstall request (the
		// plugin file is included and services register at load), so deleting
		// sbtt_global_settings fires delete_option_ and re-creates the epoch.
		delete_option('sb_abilities_sbtt_gate_epoch');
	}

	/**
	 * Remove plugin cron jobs.
	 *
	 * @return void
	 */
	public static function delete_cron_jobs()
	{
		$cron_jobs = [
			'sbtt_refresh_token_routine',
			'sbtt_feed_update_routine',
			'sbtt_usage_tracking_cron',
			'sbtt_smash_usage_tracking_cron',
		];

		foreach ($cron_jobs as $cron_job) {
			wp_clear_scheduled_hook($cron_job);
		}
	}

	/**
	 * Remove plugin upload folder.
	 *
	 * @return void
	 */
	public static function delete_upload_folder()
	{
		$upload     = wp_upload_dir();
		$upload_dir = trailingslashit($upload['basedir']) . SBTT_UPLOAD_FOLDER_NAME;

		if (file_exists($upload_dir)) {
			$files = glob($upload_dir . '/*');
			foreach ($files as $file) {
				if (is_file($file)) {
					unlink($file); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
				}
			}

			global $wp_filesystem;
			$wp_filesystem->delete($upload_dir, true);
		}
	}
}
