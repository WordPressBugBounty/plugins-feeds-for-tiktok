<?php

namespace SmashBalloon\TikTokFeeds\Common\UsageTracking\Core;

use SmashBalloon\TikTokFeeds\Common\UsageTracking\Config;
use SmashBalloon\TikTokFeeds\Common\UsageTracking\ReporterInterface;

if (! defined('ABSPATH')) {
	exit;
}

class RegisterSite
{
	/**
	 * Log a registration failure under WP_DEBUG so a silently-never-starting
	 * tracker is diagnosable in production.
	 *
	 * @param string $reason What failed.
	 */
	private function log_failure($reason)
	{
		if (defined('WP_DEBUG') && WP_DEBUG && defined('WP_DEBUG_LOG') && WP_DEBUG_LOG && function_exists('error_log')) {
			error_log('[SBTT Usage Tracking] Site registration failed: ' . $reason);
		}
	}

	/**
	 * Register the site with the API and store the returned site_token.
	 *
	 * @param ReporterInterface $reporter Plugin reporter (for slug/version).
	 * @return string|null Site token on success, null on failure.
	 */
	public function register(ReporterInterface $reporter)
	{
		$existing = get_option(Config::OPTION_SITE_TOKEN, '');
		if ('' !== $existing && is_string($existing)) {
			return $existing;
		}

		if ('' === Config::get_api_url()) {
			$this->log_failure('API base URL is empty (constant undefined or blanked by filter).');
			return null;
		}

		$url  = Config::get_register_site_url();
		$body = array(
			'site_url'       => home_url(),
			'plugin_slug'    => $reporter->get_plugin_slug(),
			'plugin_version' => defined('SBTTVER') ? SBTTVER : '',
		);

		$response = wp_remote_post(
			$url,
			array(
				'method'      => 'POST',
				'timeout'     => 15,
				'redirection' => 5,
				'headers'     => array(
					'Content-Type' => 'application/json',
				),
				'body'        => wp_json_encode($body),
			)
		);

		if (is_wp_error($response)) {
			$this->log_failure('transport error — ' . $response->get_error_message());
			return null;
		}

		$code = wp_remote_retrieve_response_code($response);
		if ($code < 200 || $code >= 300) {
			$this->log_failure('HTTP ' . $code . ' from ' . $url);
			return null;
		}

		$body_raw = wp_remote_retrieve_body($response);
		$data     = json_decode($body_raw, true);
		if (! is_array($data)) {
			$this->log_failure('non-JSON response body.');
			return null;
		}

		$token = $data['site_token'] ?? $data['token'] ?? null;
		if (null === $token || '' === $token || ! is_string($token)) {
			$this->log_failure('response JSON carries no site_token.');
			return null;
		}

		$token = sanitize_text_field($token);
		update_option(Config::OPTION_SITE_TOKEN, $token, false);
		return $token;
	}
}
