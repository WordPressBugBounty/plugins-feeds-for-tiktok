<?php

namespace SmashBalloon\TikTokFeeds\Common\Integrations;

// If this file is called directly, abort.
if (! defined('ABSPATH')) {
	die;
}

/**
 * WPVibe install/activation status for the "AI Agents" card (SBR-501).
 *
 * Mirrors the WPCode integration class in this same directory -- a thin,
 * static, folder-scan-based status helper with no persisted state of its
 * own. WPVibe is the free companion plugin that exposes TikTok Feed's
 * WordPress Abilities API ability set to MCP-speaking AI agents.
 *
 * @since 1.2.0
 */
class WPVibe
{
	/**
	 * WPVibe's WordPress.org plugin slug. Used for the folder-scan below and
	 * for the wp.org/downloads URLs -- never renamed.
	 */
	const SLUG = 'vibe-ai';

	/**
	 * One-click install source. Matches the download URL the "Install Now"
	 * flow on wp.org would use for this slug.
	 */
	const INSTALL_DOWNLOAD_URL = 'https://downloads.wordpress.org/plugin/vibe-ai.zip';

	/**
	 * WPVibe's WordPress.org plugin page, linked from the card's description.
	 */
	const ORG_URL = 'https://wordpress.org/plugins/vibe-ai/';

	/**
	 * Find WPVibe's plugin basename by scanning installed plugins for a
	 * folder named `vibe-ai/`, rather than assuming a specific main-file
	 * name -- a plugin's wp.org slug and its main file are not guaranteed
	 * to match.
	 *
	 * @since 1.2.0
	 *
	 * @return string|null The plugin basename (e.g. "vibe-ai/vibe-ai.php"),
	 *                      or null if WPVibe isn't installed.
	 */
	public static function get_basename()
	{
		if (! function_exists('get_plugins')) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		foreach (array_keys(get_plugins()) as $plugin_file) {
			if (strtok($plugin_file, '/') === self::SLUG) {
				return $plugin_file;
			}
		}

		return null;
	}

	/**
	 * Is WPVibe installed (active or not)?
	 *
	 * @since 1.2.0
	 *
	 * @return bool
	 */
	public static function is_plugin_installed()
	{
		return null !== self::get_basename();
	}

	/**
	 * Is WPVibe installed AND active?
	 *
	 * @since 1.2.0
	 *
	 * @return bool
	 */
	public static function is_plugin_active()
	{
		$basename = self::get_basename();

		if (null === $basename) {
			return false;
		}

		if (! function_exists('is_plugin_active')) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		return is_plugin_active($basename);
	}

	/**
	 * Determine WPVibe's install/activation status for the AI Agents card.
	 *
	 * @since 1.2.0
	 *
	 * @return array{status: string, basename: string|null} status is one
	 *               of 'not_installed' | 'inactive' | 'active'.
	 */
	public static function status()
	{
		$basename = self::get_basename();

		if (null === $basename) {
			return array(
				'status'   => 'not_installed',
				'basename' => null,
			);
		}

		if (! function_exists('is_plugin_active')) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		return array(
			'status'   => is_plugin_active($basename) ? 'active' : 'inactive',
			'basename' => $basename,
		);
	}

	/**
	 * WPVibe's own admin page, opened from the card once it's active.
	 *
	 * @since 1.2.0
	 *
	 * @return string
	 */
	public static function admin_url()
	{
		return admin_url('admin.php?page=vibe-ai');
	}
}
