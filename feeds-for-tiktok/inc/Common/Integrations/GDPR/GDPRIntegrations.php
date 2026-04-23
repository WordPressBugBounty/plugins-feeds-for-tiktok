<?php

namespace SmashBalloon\TikTokFeeds\Common\Integrations\GDPR;

// If this file is called directly, abort.
if (! defined('ABSPATH')) {
	die;
}

class GDPRIntegrations
{
	/**
	 * Get the registry of supported GDPR consent plugins.
	 *
	 * @return array
	 */
	public static function get_plugin_registry()
	{
		$registry = [
			'wpconsent' => [
				'name'         => 'WPConsent',
				'detect'       => function () {
					return function_exists('WPConsent');
				},
				'consent_type' => 'marketing',
			],
			'real_cookie_banner' => [
				'name'         => 'Real Cookie Banner',
				'detect'       => function () {
					return defined('RCB_ROOT_SLUG');
				},
				'consent_type' => 'marketing',
			],
			'cookie_notice' => [
				'name'         => 'Cookie Notice & Compliance for GDPR / CCPA',
				'detect'       => function () {
					return class_exists('Cookie_Notice');
				},
				'consent_type' => 'marketing',
			],
			'cookie_law_info' => [
				'name'         => 'CookieYes – Cookie Banner for Cookie Consent',
				'detect'       => function () {
					return defined('CKY_APP_ASSETS_URL');
				},
				'consent_type' => 'functional',
			],
			'cookiebot' => [
				'name'         => 'Cookiebot',
				'detect'       => function () {
					return class_exists('Cookiebot_WP');
				},
				'consent_type' => 'marketing',
			],
			'complianz' => [
				'name'         => 'Complianz',
				'detect'       => function () {
					return class_exists('COMPLIANZ');
				},
				'consent_type' => 'marketing',
			],
			'borlabs' => [
				'name'         => 'Borlabs Cookie',
				'detect'       => function () {
					return defined('BORLABS_COOKIE_VERSION');
				},
				'consent_type' => 'marketing',
			],
			'moove' => [
				'name'         => 'GDPR Cookie Compliance by Moove',
				'detect'       => function () {
					return function_exists('gdpr_cookie_is_accepted');
				},
				'consent_type' => 'thirdparty',
			],
		];

		return apply_filters('sbtt_gdpr_plugin_registry', $registry);
	}

	/**
	 * Check if GDPR compliance should be enforced.
	 *
	 * @param array $settings Global settings.
	 * @return bool
	 */
	public static function doing_gdpr($settings)
	{
		$gdpr = isset($settings['gdpr']) ? $settings['gdpr'] : 'auto';

		if ($gdpr === 'no') {
			return false;
		}

		if ($gdpr === 'yes') {
			return true;
		}

		// Auto mode: skip in admin feed builder context (but not AJAX requests like Load More).
		if (is_admin() && ! wp_doing_ajax()) {
			return false;
		}

		return self::gdpr_plugins_active() !== false;
	}

	/**
	 * Get the active GDPR plugin's human-readable name.
	 *
	 * @return string|false Plugin name or false if none active.
	 */
	public static function gdpr_plugins_active()
	{
		$registry = self::get_plugin_registry();

		foreach ($registry as $plugin) {
			if (is_callable($plugin['detect']) && call_user_func($plugin['detect'])) {
				return $plugin['name'];
			}
		}

		return false;
	}

	/**
	 * Get the active GDPR plugin's registry slug.
	 *
	 * @return string|false Plugin slug or false if none active.
	 */
	public static function get_active_plugin_slug()
	{
		$registry = self::get_plugin_registry();

		foreach ($registry as $slug => $plugin) {
			if (is_callable($plugin['detect']) && call_user_func($plugin['detect'])) {
				return $slug;
			}
		}

		return false;
	}

}
