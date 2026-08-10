<?php

/**
 * Service responsible with plugin global settings functionality.
 *
 * @package tiktok-feeds
 */

namespace SmashBalloon\TikTokFeeds\Common\Services;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use Smashballoon\Stubs\Services\ServiceProvider;
use SmashBalloon\TikTokFeeds\Common\Utils;

/**
 * Class SettingsManagerService
 */
class SettingsManagerService extends ServiceProvider
{
	/**
	 * Options name for the global settings.
	 *
	 * @var string
	 */
	private $settings_options = 'sbtt_global_settings';

	/**
	 * Register the service.
	 */
	public function register()
	{
		add_action('wp_ajax_sbtt_update_global_settings', array( $this, 'ajax_update_global_settings' ));
	}

	/**
	 * Update the global settings.
	 */
	public function ajax_update_global_settings()
	{
		check_ajax_referer('sbtt-admin', 'nonce');

		if (! sbtt_current_user_can()) {
			wp_send_json_error();
		}

		unset($_POST['action'], $_POST['nonce']);

		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.MissingUnslash, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$settings = isset($_POST['settings']) ? sbtt_sanitize_data($_POST['settings']) : [];

		$this->update_global_settings($settings);

		wp_send_json_success();
	}

	/**
	 * Update the global settings.
	 *
	 * @param array $settings The settings to update.
	 */
	public function update_global_settings($settings)
	{
		if (! is_array($settings) || empty($settings)) {
			return;
		}

		$settings = self::harden_html_sink_values($settings);

		$current_settings = $this->get_global_settings();
		$updated_settings = wp_parse_args($settings, $current_settings);

		update_option($this->settings_options, $updated_settings);
	}

	/**
	 * Character-class-restrict the settings values that reach an HTML sink.
	 *
	 * Applied here rather than at any single caller, because the three writers that
	 * can carry a hostile value all funnel through this method -- the settings AJAX
	 * endpoint (which accepts arbitrary keys: sbtt_sanitize_data() runs no key
	 * allowlist and wp_parse_args() merges whatever it is given), the license
	 * recheck/activate paths, and the upgrader.
	 *
	 * This is NOT a complete choke point on the option, and must not be relied on as
	 * one. Five call sites write sbtt_global_settings with a direct update_option()
	 * and bypass this hardening entirely: Relay.php:289 and :297,
	 * RegisterWebsiteRoutine.php:81 and :89, and AjaxHandlerService.php:388. None is
	 * a live bypass today -- every one of them writes only api_site_access_token and
	 * api_site_error, and neither key is printed into the Support page blob. But if a
	 * key below is ever written from one of those paths, or a new sink-bound key is
	 * added to the list without checking them, the hardening is silently skipped
	 * there. Either route new writes through update_global_settings(), or harden at
	 * the new call site too.
	 *
	 * Both keys below are printed into the Support page's system-info blob, which
	 * is shipped to the browser via wp_localize_script() -- entity-decoding every
	 * top-level scalar -- and rendered with dangerouslySetInnerHTML. Escaping at
	 * the output point is therefore reversed in transit, and sanitize_text_field()
	 * is not enough either: core only strips tags inside
	 * `if ( str_contains( $filtered, '<' ) )`, so an entity-encoded payload such as
	 * '&#60;img src=x onerror=1&#62;' carries no literal '<', survives byte-identical,
	 * and is reassembled into a live tag downstream.
	 *
	 * sanitize_key() removes '&', '<' and '>' outright, which is the invariant this
	 * sink actually needs. It is lossless for both keys' real value sets:
	 * license_status takes EDD status tokens (valid, expired, site_inactive,
	 * item_name_mismatch, ...) and gdpr takes 'auto' | 'yes' | 'no'.
	 *
	 * license_key is deliberately NOT included: sanitize_key() lowercases, which
	 * would corrupt a real mixed-case licence key and break activation. It needs a
	 * case-preserving treatment, tracked separately.
	 *
	 * @param array $settings Incoming (possibly partial) settings.
	 *
	 * @return array
	 */
	private static function harden_html_sink_values($settings)
	{
		foreach (array( 'license_status', 'gdpr' ) as $key) {
			if (isset($settings[$key]) && is_scalar($settings[$key])) {
				$settings[$key] = sanitize_key($settings[$key]);
			}
		}

		return $settings;
	}

	/**
	 * Get the global settings.
	 *
	 * @return array
	 */
	public function get_global_settings()
	{
		$defaults = $this->get_global_settings_defaults();
		$settings = get_option($this->settings_options, []);
		$settings = wp_parse_args($settings, $defaults);

		return $settings;
	}

	/**
	 * Get the global settings defaults.
	 *
	 * @return array
	 */
	private function get_global_settings_defaults()
	{
		return [
			'optimize_images'     => true,
			'usagetracking'       => Utils::sbtt_is_pro() ? true : false,
			'admin_error_notices' => true,
			'feed_issue_reports'  => true,
			'gdpr'                => 'auto',
		];
	}
}
