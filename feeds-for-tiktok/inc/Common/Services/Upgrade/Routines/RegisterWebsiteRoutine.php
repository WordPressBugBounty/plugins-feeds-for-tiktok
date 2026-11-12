<?php

namespace SmashBalloon\TikTokFeeds\Common\Services\Upgrade\Routines;

use Smashballoon\Stubs\Services\ServiceProvider;
use SmashBalloon\TikTokFeeds\Common\Relay\Relay;

class RegisterWebsiteRoutine extends ServiceProvider
{
	/**
	 * The target version for this routine.
	 *
	 * @var int
	 */
	protected $target_version = 0;

	/**
	 * Registers the routine.
	 *
	 * @return void
	 */
	public function register()
	{
		if ($this->will_run()) {
			$this->run();
		}
	}

	/**
	 * Checks if the routine will run.
	 *
	 * @return bool
	 */
	protected function will_run()
	{
		// A malformed home option gives no URL to register.
		if (self::site_url() === '') {
			return false;
		}

		$global_settings = get_option('sbtt_global_settings', []);

		// A token or error stored for another URL (cloned, migrated or staged site)
		// is not valid for this one, so register again for this URL.
		if (! isset($global_settings['api_site_url']) || $global_settings['api_site_url'] !== self::site_url()) {
			$needs_register = true;
		} elseif (isset($global_settings['api_site_error']) && $global_settings['api_site_error'] !== '') {
			// Retry a failed registration once a day instead of never again.
			$registered_at  = isset($global_settings['api_site_registered_at'])
				? (int) $global_settings['api_site_registered_at']
				: 0;
			$needs_register = time() - $registered_at >= DAY_IN_SECONDS;
		} else {
			$needs_register = empty($global_settings['api_site_access_token']);
		}

		// Read the throttle last: a transient with an expiry is not autoloaded, so reading it
		// costs a query. A registration was tried a moment ago, so do not call the relay again.
		return $needs_register && ! get_transient('sbtt_register_website_retry');
	}

	/**
	 * Runs the routine.
	 *
	 * @return void
	 */
	public function run()
	{
		$global_settings       = get_option('sbtt_global_settings', []);

		$args = [
			'url'         => self::site_url(),
			'license_key' => isset($global_settings['license_key']) && ! empty($global_settings['license_key']) ? trim($global_settings['license_key']) : '',
		];

		// Set before the call, so concurrent requests and a relay outage do not pile up.
		set_transient('sbtt_register_website_retry', 1, 5 * MINUTE_IN_SECONDS);

		$relay = new Relay();
		self::save_response($relay->call('auth/register', $args));
	}

	/**
	 * The site URL as the relay keys it: lowercase scheme://host[:port] of the home option.
	 *
	 * Read from the option, not get_home_url(), which follows the request scheme and
	 * home_url filters and would flip between values on mixed HTTP/HTTPS sites.
	 *
	 * @return string
	 */
	public static function site_url()
	{
		$parts = wp_parse_url((string) get_option('home'));
		if (empty($parts['scheme']) || empty($parts['host'])) {
			return '';
		}

		$port = isset($parts['port']) ? ':' . $parts['port'] : '';
		return strtolower($parts['scheme'] . '://' . $parts['host'] . $port);
	}

	/**
	 * Stores an auth/register response with the URL and time it was made for.
	 *
	 * @param mixed $response The decoded relay response.
	 *
	 * @return string The site token, or '' when none was issued.
	 */
	public static function save_response($response)
	{
		$global_settings = get_option('sbtt_global_settings', []);

		// A first registration answers {"token": ...}, a repeat one {"data": {"token": ...}}.
		$token = ! empty($response['data']['token']) ? $response['data']['token'] : '';
		if ($token === '' && ! empty($response['token'])) {
			$token = $response['token'];
		}

		if (! empty($response['data']['error'])) {
			$global_settings['api_site_access_token'] = '';
			$global_settings['api_site_error']        = $response['data']['error'];
		} elseif ($token !== '') {
			$global_settings['api_site_access_token'] = $token;
			$global_settings['api_site_error']        = '';
		} else {
			return '';
		}

		$global_settings['api_site_url']           = self::site_url();
		$global_settings['api_site_registered_at'] = time();
		update_option('sbtt_global_settings', $global_settings);

		return $global_settings['api_site_access_token'];
	}
}
