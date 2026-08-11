<?php

namespace SmashBalloon\TikTokFeeds\Common\UsageTracking;

use SmashBalloon\TikTokFeeds\Common\UsageTracking\Core\RegisterSite;
use SmashBalloon\TikTokFeeds\Common\UsageTracking\Core\Sender;
use SmashBalloon\TikTokFeeds\Common\UsageTracking\Core\PayloadBuilder;
use SmashBalloon\TikTokFeeds\Common\UsageTracking\Core\Scheduler;

if (! defined('ABSPATH')) {
	exit;
}

class SmashUsageTracking
{
	/**
	 * Plugin-specific reporter.
	 *
	 * @var ReporterInterface
	 */
	private $reporter;

	/**
	 * Site registration client.
	 *
	 * @var RegisterSite
	 */
	private $register_site;

	/**
	 * Payload sender.
	 *
	 * @var Sender
	 */
	private $sender;

	/**
	 * Payload builder.
	 *
	 * @var PayloadBuilder
	 */
	private $payload_builder;

	/**
	 * Cron scheduler.
	 *
	 * @var Scheduler
	 */
	private $scheduler;

	/**
	 * AJAX action (without the wp_ajax_ prefix) => event name recorded when it runs.
	 *
	 * These are passive listeners: they attach at priority 5 so they run before the
	 * action's own handler (which ends the request with a JSON response) and must
	 * never emit output of their own. Because priority 5 runs BEFORE the primary
	 * handler's checks, record_action_event() replicates the same sbtt-admin
	 * nonce + capability check (non-dying) before its option write — otherwise
	 * any logged-in user could inflate event counters via bare admin-ajax POSTs.
	 *
	 * @var array<string,string>
	 */
	private static $event_actions = array(
		'sbtt_builder_update'         => 'feed_saved',
		'sbtt_delete_feeds'           => 'feed_deleted',
		'sbtt_duplicate_feed'         => 'feed_duplicated',
		'sbtt_process_oauth_tokens'   => 'source_connected',
		'sbtt_delete_source'          => 'source_deleted',
		'sbtt_update_global_settings' => 'settings_saved',
		'sbtt_clear_all_caches'       => 'caches_cleared',
		'sbtt_activate_license'       => 'license_activated',
		'sbtt_deactivate_license'     => 'license_deactivated',
		'sbtt_install_plugin'         => 'upgrade_initiated',
	);

	/**
	 * Constructor.
	 *
	 * @param ReporterInterface $reporter Plugin-specific reporter.
	 */
	public function __construct(ReporterInterface $reporter)
	{
		$this->reporter        = $reporter;
		$this->register_site   = new RegisterSite();
		$this->sender          = new Sender();
		$this->payload_builder = new PayloadBuilder($reporter);
		$this->scheduler       = new Scheduler();
	}

	/**
	 * Register all hooks. Called from the service wrapper's register() method.
	 */
	public function init()
	{
		add_action('init', array( $this, 'maybe_schedule' ));
		add_filter('cron_schedules', array( $this->scheduler, 'add_schedules' ));
		add_action(Config::CRON_HOOK, array( $this, 'send_checkin' ));

		add_action('current_screen', array( $this, 'maybe_record_active_day' ));
		add_action('wp_ajax_sbtt_smash_usage_record_session', array( $this, 'ajax_record_session' ));
		add_action('admin_enqueue_scripts', array( $this, 'enqueue_session_script' ), 20);

		foreach (array_keys(self::$event_actions) as $action) {
			add_action('wp_ajax_' . $action, array( $this, 'record_action_event' ), 5);
		}
		add_action('sbtt_feed_created', array( $this, 'on_feed_created' ));

		if (defined('WP_CLI') && WP_CLI) {
			// WP_CLI is only present under wp-cli; guarded above, invisible to PHPStan's WP stubs.
			// @phpstan-ignore-next-line
			\WP_CLI::add_command(
				'sbtt usage-preview',
				function () {
					// @phpstan-ignore-next-line
					\WP_CLI::print_value($this->get_payload_preview(), array( 'format' => 'json' ));
				}
			);
		}
	}

	/**
	 * Non-dying replica of the primary AJAX handlers' checks. Listeners run at
	 * priority 5 — before the primary handler verifies the request — so the
	 * recorder must gate its option write on the same 'sbtt-admin' nonce and
	 * capability, without ending the request (the primary handler owns the
	 * response).
	 *
	 * @return bool
	 */
	private function verify_admin_ajax_request()
	{
		if (false === check_ajax_referer('sbtt-admin', 'nonce', false)) {
			return false;
		}
		if (function_exists('sbtt_current_user_can')) {
			return (bool) sbtt_current_user_can();
		}
		return current_user_can('manage_options');
	}

	/**
	 * Record the event mapped to the AJAX action currently running.
	 * Runs at priority 5 — must NOT send any response.
	 */
	public function record_action_event()
	{
		if (! $this->verify_admin_ajax_request()) {
			return;
		}
		$action = (string) current_action();
		if (0 === strpos($action, 'wp_ajax_')) {
			$action = substr($action, strlen('wp_ajax_'));
		}
		if (isset(self::$event_actions[ $action ])) {
			EventRecorder::record(self::$event_actions[ $action ]);
		}
	}

	/**
	 * Record feed_created when a feed row is inserted.
	 */
	public function on_feed_created()
	{
		EventRecorder::record('feed_created');
	}

	/**
	 * Schedule cron if enabled and not already scheduled.
	 */
	public function maybe_schedule()
	{
		$this->scheduler->schedule();
	}

	/**
	 * Cron callback: ensure site token, build payload, send, update last_send.
	 */
	public function send_checkin()
	{
		if (! Config::is_enabled()) {
			return;
		}

		$host = wp_parse_url(home_url(), PHP_URL_HOST);
		if ('smashballoon.com' === $host || '.smashballoon.com' === substr((string) $host, -17)) {
			return;
		}

		$opt       = get_option(Config::OPTION_TRACKING, array());
		$last_send = is_array($opt) && isset($opt['last_send']) ? (int) $opt['last_send'] : 0;
		// -6 days, not -1 week: last_send is stamped AFTER the send completes,
		// so with punctual cron the next weekly run fires slightly less than
		// 7 days later and an exact-week guard would skip every other run.
		if ($last_send > strtotime('-6 days')) {
			return;
		}

		// The last_send guard alone can't stop two overlapping runners (e.g.
		// multi-server wp-cron) — it is only updated after the up-to-30s send
		// completes. A second concurrent send would double-report and then
		// double-subtract events in reset_reported_metrics().
		if (false !== get_transient('sbtt_smash_usage_sending_lock')) {
			return;
		}
		set_transient('sbtt_smash_usage_sending_lock', 1, 2 * MINUTE_IN_SECONDS);

		$site_token = get_option(Config::OPTION_SITE_TOKEN, '');
		if ('' === $site_token || ! is_string($site_token)) {
			$site_token = $this->register_site->register($this->reporter);
			if (null === $site_token) {
				delete_transient('sbtt_smash_usage_sending_lock');
				return;
			}
		}

		$period_end   = gmdate('Y-m-d', time() - DAY_IN_SECONDS);
		$period_start = gmdate('Y-m-d', time() - 7 * DAY_IN_SECONDS);

		// Snapshot the durations present before the payload is built so the
		// post-send reset removes exactly those entries (by value), even when
		// the store's 10-entry cap displaces old entries during the send.
		$durations_before = get_option(Config::OPTION_SESSION_DURATIONS, array());
		$durations_before = is_array($durations_before) ? array_values($durations_before) : array();

		$payload = $this->payload_builder->build($site_token, $period_start, $period_end);
		$code    = $this->sender->send($payload);

		if ($code >= 200 && $code < 300) {
			update_option(Config::OPTION_TRACKING, array( 'last_send' => time() ), false);
			$sent_events = isset($payload['dynamic_metrics']['events']) && is_array($payload['dynamic_metrics']['events'])
				? $payload['dynamic_metrics']['events']
				: array();
			$this->reset_reported_metrics($sent_events, $durations_before);
		} elseif ($this->sender->last_error_rejected_token($code)) {
			// The API rejected the site token (revoked/unknown). Drop it so the
			// next weekly run re-registers instead of retrying a dead token forever.
			delete_option(Config::OPTION_SITE_TOKEN);
		}

		delete_transient('sbtt_smash_usage_sending_lock');
	}

	/**
	 * Clear reported dynamic metrics after a successful send: subtracts the
	 * sent event counts and drops the reported session durations, leaving
	 * anything recorded during the send window to roll into the next period.
	 *
	 * @param array $sent_events        Events map from the sent payload.
	 * @param array $reported_durations Session-duration entries that existed
	 *                                  when the payload was built.
	 */
	private function reset_reported_metrics(array $sent_events, array $reported_durations = array())
	{
		if (! empty($sent_events)) {
			$stored = get_option(EventRecorder::OPTION_NAME, array());
			if (is_array($stored)) {
				foreach ($sent_events as $key => $sent) {
					if (! isset($stored[ $key ])) {
						continue;
					}

					// Subtract the reported count rather than unsetting the key: a
					// concurrent request can increment an event AFTER the payload was
					// built but BEFORE this runs, and unsetting would discard those
					// extra occurrences. Only drop the key once nothing is left.
					$sent_count   = is_array($sent) && isset($sent['count']) ? (int) $sent['count'] : 0;
					$stored_count = is_array($stored[ $key ]) && isset($stored[ $key ]['count'])
						? (int) $stored[ $key ]['count']
						: 0;
					$remaining    = $stored_count - $sent_count;

					if ($remaining > 0) {
						$stored[ $key ]['count'] = $remaining;
					} else {
						unset($stored[ $key ]);
					}
				}
				update_option(EventRecorder::OPTION_NAME, $stored, false);
			}
		}
		// Do not wipe the event store when $sent_events is empty: the store may
		// contain valid events that were not included in this payload (e.g. recorded
		// after the payload was built). They should roll into the next period.

		// Same concurrency care for durations: remove one occurrence of each
		// REPORTED value (multiset diff) rather than slicing by count — the
		// store keeps only the last 10 entries, so a count-based slice would
		// delete the new unreported sessions whenever the cap displaced old
		// reported ones during the send window.
		$durations = get_option(Config::OPTION_SESSION_DURATIONS, array());
		$durations = is_array($durations) ? array_values($durations) : array();
		foreach ($reported_durations as $reported) {
			$idx = array_search($reported, $durations, true);
			if (false !== $idx) {
				unset($durations[ $idx ]);
			}
		}
		update_option(Config::OPTION_SESSION_DURATIONS, array_values($durations), false);
	}

	/**
	 * Unschedule cron (call when disabling tracking).
	 */
	public function unschedule()
	{
		$this->scheduler->unschedule();
	}

	/**
	 * Record active day and optionally settings_page_viewed when on an SBTT admin page.
	 *
	 * @param \WP_Screen|null $screen Current screen from the current_screen hook.
	 */
	public function maybe_record_active_day($screen)
	{
		if (! $screen || strpos($screen->id, 'sbtt') === false) {
			return;
		}
		if (! Config::is_enabled()) {
			return;
		}
		EventRecorder::record_active_day();
		if (strpos($screen->id, 'sbtt-settings') !== false || strpos($screen->base, 'sbtt-settings') !== false) {
			EventRecorder::record('settings_page_viewed');
		}
	}

	/**
	 * AJAX: record session duration from JS (seconds).
	 */
	public function ajax_record_session()
	{
		check_ajax_referer('sbtt_smash_usage_record_session', 'nonce');
		// Same capability the plugin's own pages gate on: a user holding only
		// the custom manage_tiktok_feed_options cap sees the admin pages and
		// gets the script enqueued, so manage_options alone would silently
		// drop every session they generate.
		$can = function_exists('sbtt_current_user_can') ? sbtt_current_user_can() : current_user_can('manage_options');
		if (! $can || ! Config::is_enabled()) {
			wp_send_json_error();
		}
		$seconds = isset($_POST['duration_seconds']) ? (int) $_POST['duration_seconds'] : 0;
		EventRecorder::record_session_duration($seconds);
		wp_send_json_success();
	}

	/**
	 * Enqueue the session-duration script on SBTT admin pages.
	 */
	public function enqueue_session_script()
	{
		if (! function_exists('get_current_screen')) {
			return;
		}
		$screen = get_current_screen();
		if (! $screen || strpos($screen->id, 'sbtt') === false) {
			return;
		}
		$script_path = 'admin/js/smash-usage-session.js';
		$path        = defined('SBTT_PLUGIN_DIR') ? trailingslashit(SBTT_PLUGIN_DIR) . $script_path : '';
		if ('' === $path || ! file_exists($path)) {
			return;
		}
		wp_enqueue_script(
			'sbtt-smash-usage-session',
			defined('SBTT_PLUGIN_URL') ? trailingslashit(SBTT_PLUGIN_URL) . $script_path : '',
			array( 'jquery' ),
			defined('SBTTVER') ? SBTTVER : '1.0',
			true
		);
		wp_localize_script(
			'sbtt-smash-usage-session',
			'sbttSmashUsageSession',
			array(
				'ajax_url' => admin_url('admin-ajax.php'),
				'nonce'    => wp_create_nonce('sbtt_smash_usage_record_session'),
			)
		);
	}

	/**
	 * Build the usage report payload without sending (for preview/debugging).
	 *
	 * @return array
	 */
	public function get_payload_preview()
	{
		$period_end   = gmdate('Y-m-d', time() - DAY_IN_SECONDS);
		$period_start = gmdate('Y-m-d', time() - 7 * DAY_IN_SECONDS);

		return $this->payload_builder->build('preview-no-api', $period_start, $period_end);
	}
}
