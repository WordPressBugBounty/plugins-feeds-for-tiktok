<?php

namespace SmashBalloon\TikTokFeeds\Common\UsageTracking;

use SmashBalloon\TikTokFeeds\Common\Utils;

if (! defined('ABSPATH')) {
	exit;
}

class Config
{
	/**
	 * Option key: last_send timestamp only. Consent lives in
	 * the sbtt_global_settings option under the 'usagetracking' key.
	 */
	const OPTION_TRACKING = 'sbtt_smash_usage_tracking';

	/**
	 * Option key: site token returned by the API.
	 */
	const OPTION_SITE_TOKEN = 'sbtt_smash_usage_tracking_site_token';

	/**
	 * Option key: schedule metadata.
	 */
	const OPTION_SCHEDULE = 'sbtt_smash_usage_tracking_schedule';

	/**
	 * Option key: dates when plugin was active (Y-m-d), for days_active metric.
	 */
	const OPTION_ACTIVE_DATES = 'sbtt_smash_usage_active_dates';

	/**
	 * Option key: last N session durations in seconds, for session_duration metric.
	 */
	const OPTION_SESSION_DURATIONS = 'sbtt_smash_usage_session_durations';

	/**
	 * Cron hook name.
	 */
	const CRON_HOOK = 'sbtt_smash_usage_tracking_cron';

	/**
	 * Max request timeout in seconds for usage report.
	 */
	const REQUEST_TIMEOUT = 30;

	/**
	 * Max payload size in bytes before send is skipped (default 2MB).
	 */
	const MAX_PAYLOAD_BYTES = 2097152;

	/**
	 * Register-site endpoint path (relative to API base).
	 */
	const REGISTER_SITE_PATH = '/v1/register-site';

	/**
	 * Usage report endpoint path (relative to API base).
	 */
	const USAGE_REPORT_PATH = '/v1/usage-report';

	/**
	 * Get the API base URL (filterable).
	 *
	 * @return string
	 */
	public static function get_api_url()
	{
		$url = defined('SBTT_SMASH_USAGE_TRACKING_API_URL') ? SBTT_SMASH_USAGE_TRACKING_API_URL : '';
		return (string) apply_filters('sbtt_smash_usage_tracking_api_url', $url);
	}

	/**
	 * Get full URL for register-site endpoint.
	 *
	 * @return string
	 */
	public static function get_register_site_url()
	{
		return rtrim(self::get_api_url(), '/') . self::REGISTER_SITE_PATH;
	}

	/**
	 * Get full URL for usage-report endpoint.
	 *
	 * @return string
	 */
	public static function get_usage_report_url()
	{
		return rtrim(self::get_api_url(), '/') . self::USAGE_REPORT_PATH;
	}

	/**
	 * Check if tracking is enabled.
	 *
	 * Consent is stored in the global settings array under 'usagetracking'.
	 * When the key is absent, defaults to enabled on Pro and disabled on Free
	 * (mirrors SettingsManagerService::get_global_settings_defaults()).
	 *
	 * @return bool
	 */
	public static function is_enabled()
	{
		$settings = get_option('sbtt_global_settings', array());
		if (is_array($settings) && array_key_exists('usagetracking', $settings)) {
			return (bool) $settings['usagetracking'];
		}

		return Utils::sbtt_is_pro();
	}
}
