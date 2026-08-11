<?php

namespace SmashBalloon\TikTokFeeds\Common\UsageTracking\TikTok;

use SmashBalloon\TikTokFeeds\Common\SourceErrors;
use SmashBalloon\TikTokFeeds\Common\UsageTracking\Config;
use SmashBalloon\TikTokFeeds\Common\UsageTracking\EventRecorder;
use SmashBalloon\TikTokFeeds\Common\UsageTracking\ReporterInterface;

if (! defined('ABSPATH')) {
	exit;
}

class TikTokFreeReporter implements ReporterInterface
{
	/**
	 * Payload schema version. 1.1 added feeds{} and features_enabled{},
	 * 1.2 added environment{} — this reporter sends all of them, so it declares
	 * 1.2 rather than the 1.0 it originally shipped with.
	 */
	const SCHEMA_VERSION = '1.2';

	/**
	 * True sbtt_feeds row count, set by get_all_feed_data() alongside the
	 * capped sample so get_feeds_summary() can report an honest total.
	 *
	 * @var int
	 */
	private $feeds_total_count = 0;

	/**
	 * Plugin slug for payload root.
	 *
	 * @return string
	 */
	public function get_plugin_slug()
	{
		return 'tiktok';
	}

	/**
	 * Schema version for the report payload.
	 *
	 * @return string
	 */
	public function get_schema_version()
	{
		return self::SCHEMA_VERSION;
	}

	/**
	 * Configuration snapshot (environment, settings, sources, feeds, features).
	 *
	 * @return array
	 */
	public function get_configuration_snapshot()
	{
		$global_settings = $this->get_global_settings();

		// Single DB scan — reused for latest sample, summary, and features map.
		$all_feed_data = $this->get_all_feed_data();

		return array(
			'environment'      => $this->get_environment(),
			'global_settings'  => $global_settings,
			'sources'          => $this->get_sources_summary(),
			'latest_10_feeds'  => $this->get_latest_feeds($all_feed_data),
			'feeds'            => $this->get_feeds_summary($all_feed_data),
			'features_enabled' => $this->get_features_enabled($all_feed_data, $global_settings),
			'version'          => defined('SBTTVER') ? SBTTVER : '',
			'license_tier'     => $this->get_license_tier(),
			'license_status'   => $this->get_license_status(),
			'license_expires'  => $this->get_license_expires(),
			'license_item_id'  => $this->get_license_item_id(),
		);
	}

	/**
	 * Dynamic metrics for the given period.
	 *
	 * @param string|int $period_start Start of period (ISO 8601 or timestamp).
	 * @param string|int $period_end   End of period (ISO 8601 or timestamp).
	 * @return array
	 */
	public function get_dynamic_metrics($period_start, $period_end)
	{
		$ts_start = is_numeric($period_start) ? (int) $period_start : (int) strtotime($period_start);
		$ts_end   = is_numeric($period_end) ? (int) $period_end : (int) strtotime($period_end);
		// period_end is a Y-m-d date; strtotime() yields MIDNIGHT AT ITS START,
		// which would exclude the entire final day of the period from every
		// timestamp filter. Extend to end-of-day.
		if (! is_numeric($period_end) && $ts_end > 0) {
			$ts_end += DAY_IN_SECONDS - 1;
		}

		// Errors are collected first so api_rate_limit_hits can be taken from the
		// same categorisation the errors block reports, rather than recounted.
		$errors = $this->get_error_metrics($ts_start, $ts_end);

		return array(
			'period_start'     => $period_start,
			'period_end'       => $period_end,
			'performance'      => $this->get_performance_metrics((int) ($errors['by_type']['rate_limit'] ?? 0)),
			'errors'           => $errors,
			'events'           => $this->get_events_for_period($ts_start, $ts_end),
			'days_active'      => $this->get_days_active($period_start, $period_end),
			'session_duration' => $this->get_session_duration(),
		);
	}

	/**
	 * Environment data (WP, PHP, theme, locale, multisite, install age).
	 *
	 * @return array
	 */
	private function get_environment()
	{
		$install_ts = null;
		$statuses   = get_option('sbtt_statuses', array());
		if (! empty($statuses['first_install']) && is_numeric($statuses['first_install'])) {
			$install_ts = (int) $statuses['first_install'];
		}
		$install_age_days = $install_ts ? max(0, (int) ((time() - $install_ts) / DAY_IN_SECONDS)) : 0;

		$theme      = wp_get_theme();
		$theme_name = $theme->exists() ? $theme->get('Name') : '';

		return array(
			'wp_version'           => get_bloginfo('version'),
			'php_version'          => PHP_VERSION,
			'active_theme'         => $theme_name,
			'locale'               => get_locale(),
			'multisite'            => is_multisite(),
			'site_count'           => is_multisite() ? (int) get_blog_count() : 1,
			'active_plugins_count' => count(
				array_unique(
					array_merge(
						(array) get_option('active_plugins', array()),
						array_keys((array) get_site_option('active_sitewide_plugins', array()))
					)
				)
			),
			'install_age_days'     => $install_age_days,
		);
	}

	/**
	 * Global TikTok Feed settings.
	 *
	 * @return array
	 */
	private function get_global_settings()
	{
		$settings = get_option('sbtt_global_settings', array());
		if (! is_array($settings)) {
			$settings = array();
		}

		return array(
			'usage_tracking'    => ! empty($settings['usagetracking']),
			'preserve_settings' => ! empty($settings['preserve_settings']),
			'disable_css'       => ! empty($settings['disable_css']),
			'disable_js'        => ! empty($settings['disable_js']),
		);
	}

	/**
	 * Sources summary (connected accounts count).
	 *
	 * @return array
	 */
	private function get_sources_summary()
	{
		global $wpdb;
		$sources_table = $wpdb->prefix . (defined('SBTT_SOURCES_TABLE') ? SBTT_SOURCES_TABLE : 'sbtt_sources');
		$table_exists  = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $sources_table)) === $sources_table;

		$connected_count = 0;
		if ($table_exists) {
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name derived from $wpdb->prefix, not user input.
			$connected_count = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$sources_table}");
		}

		return array(
			'connected_accounts_count' => $connected_count,
		);
	}

	/**
	 * Whitelist of feed setting keys to track.
	 *
	 * @var string[]
	 */
	private static $feed_settings_whitelist = array(
		'feedType',
		'feedTemplate',
		'layout',
		'numPostDesktop',
		'numPostTablet',
		'numPostMobile',
		'gridDesktopColumns',
		'gridTabletColumns',
		'gridMobileColumns',
		'masonryDesktopColumns',
		'masonryTabletColumns',
		'masonryMobileColumns',
		'carouselDesktopColumns',
		'carouselTabletColumns',
		'carouselMobileColumns',
		'carouselDesktopRows',
		'carouselTabletRows',
		'carouselMobileRows',
		'carouselLoopType',
		'carouselEnableAutoplay',
		'carouselShowArrows',
		'carouselShowPagination',
		'showHeader',
		'headerAvatar',
		'videoPlayer',
		'showLoadButton',
		'sortFeedsBy',
		'sortRandomEnabled',
		'postStyle',
	);

	/**
	 * Feed settings reported as a COUNT of entries rather than their values.
	 * Word-filter lists are operator-authored free text that can plausibly
	 * contain personal data; the dashboard only needs adoption depth, and
	 * features_enabled.word_filter already captures whether it's used at all.
	 *
	 * @var string[]
	 */
	private static $feed_settings_counted = array(
		'includeWords',
		'excludeWords',
	);

	/**
	 * Load every feed's decoded settings plus feed_name, sorted newest-first.
	 *
	 * @return array[]
	 */
	private function get_all_feed_data(): array
	{
		global $wpdb;
		$table        = $wpdb->prefix . (defined('SBTT_FEEDS_TABLE') ? SBTT_FEEDS_TABLE : 'sbtt_feeds');
		$table_exists = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table)) === $table;

		if (! $table_exists) {
			return array();
		}

		// Honest total, independent of the 500-row sample cap below.
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name derived from $wpdb->prefix, not user input.
		$this->feeds_total_count = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$table}");

		$rows = $wpdb->get_results(
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name derived from $wpdb->prefix, not user input.
			"SELECT feed_name, settings FROM {$table} ORDER BY last_modified DESC LIMIT 500",
			ARRAY_A
		);

		if (! is_array($rows)) {
			return array();
		}

		$out = array();
		foreach ($rows as $row) {
			$decoded = ! empty($row['settings']) ? json_decode($row['settings'], true) : array();
			$out[]   = array(
				'feed_name' => isset($row['feed_name']) ? sanitize_text_field((string) $row['feed_name']) : '',
				'settings'  => is_array($decoded) ? $decoded : array(),
			);
		}

		return $out;
	}

	/**
	 * Latest 10 feeds with whitelisted settings. The count matches the
	 * latest_10_feeds payload key and the backend snapshot column of the
	 * same name, so the key stays accurate and the wire contract is intact.
	 *
	 * @param array[] $all_feed_data From get_all_feed_data().
	 * @return array
	 */
	private function get_latest_feeds(array $all_feed_data): array
	{
		$feeds = array();
		foreach (array_slice($all_feed_data, 0, 10) as $row) {
			$feed_name = $row['feed_name'];
			if (strlen($feed_name) > 255) {
				$feed_name = substr($feed_name, 0, 255);
			}
			$feeds[] = array(
				'feed_name' => $feed_name,
				'settings'  => $this->pick_whitelisted_settings($row['settings']),
			);
		}
		return $feeds;
	}

	/**
	 * Aggregate feed type and layout distribution across ALL feeds.
	 *
	 * @param array[] $all_feed_data From get_all_feed_data().
	 * @return array
	 */
	private function get_feeds_summary(array $all_feed_data): array
	{
		$by_type   = array();
		$by_layout = array();

		foreach ($all_feed_data as $row) {
			$s      = $row['settings'];
			$type   = isset($s['feedType']) && '' !== $s['feedType'] ? (string) $s['feedType'] : 'user';
			$layout = isset($s['layout']) && '' !== $s['layout'] ? (string) $s['layout'] : 'grid';

			$by_type[ $type ]     = ($by_type[ $type ] ?? 0) + 1;
			$by_layout[ $layout ] = ($by_layout[ $layout ] ?? 0) + 1;
		}

		return array(
			// The true row count — the by_* distributions below are computed
			// over the newest-500 sample from get_all_feed_data().
			'total_count' => max($this->feeds_total_count, count($all_feed_data)),
			'by_type'     => $by_type,
			'by_layout'   => $by_layout,
		);
	}

	/**
	 * Flat boolean feature map for the dashboard's feature adoption page.
	 *
	 * @param array[] $all_feed_data   From get_all_feed_data().
	 * @param array   $global_settings From get_global_settings().
	 * @return array<string,bool>
	 */
	private function get_features_enabled(array $all_feed_data, array $global_settings): array
	{
		$feed_flags = array(
			'carousel_layout'   => false,
			'masonry_layout'    => false,
			'show_header'       => false,
			'load_more'         => false,
			'sort_random'       => false,
			'word_filter'       => false,
			'carousel_autoplay' => false,
		);

		foreach ($all_feed_data as $row) {
			$s = $row['settings'];

			if (! $feed_flags['carousel_layout'] && isset($s['layout']) && 'carousel' === $s['layout']) {
				$feed_flags['carousel_layout'] = true;
			}
			if (! $feed_flags['masonry_layout'] && isset($s['layout']) && 'masonry' === $s['layout']) {
				$feed_flags['masonry_layout'] = true;
			}
			if (! $feed_flags['show_header'] && ! empty($s['showHeader'])) {
				$feed_flags['show_header'] = true;
			}
			if (! $feed_flags['load_more'] && ! empty($s['showLoadButton'])) {
				$feed_flags['load_more'] = true;
			}
			if (! $feed_flags['sort_random'] && ! empty($s['sortRandomEnabled'])) {
				$feed_flags['sort_random'] = true;
			}
			if (! $feed_flags['word_filter'] && ( ! empty($s['includeWords']) || ! empty($s['excludeWords']))) {
				$feed_flags['word_filter'] = true;
			}
			if (! $feed_flags['carousel_autoplay'] && ! empty($s['carouselEnableAutoplay'])) {
				$feed_flags['carousel_autoplay'] = true;
			}

			if (! in_array(false, $feed_flags, true)) {
				break;
			}
		}

		return array_merge(
			$feed_flags,
			array(
				'preserve_settings' => (bool) ($global_settings['preserve_settings'] ?? false),
			)
		);
	}

	/**
	 * Return only whitelisted feed settings.
	 *
	 * @param array $settings Raw feed settings.
	 * @return array
	 */
	private function pick_whitelisted_settings(array $settings)
	{
		$out = array();
		foreach (self::$feed_settings_whitelist as $key) {
			if (! array_key_exists($key, $settings)) {
				continue;
			}
			$value = $settings[ $key ];
			if (is_array($value) || is_scalar($value)) {
				$out[ $key ] = $value;
			}
		}
		foreach (self::$feed_settings_counted as $key) {
			if (! array_key_exists($key, $settings)) {
				continue;
			}
			$value = $settings[ $key ];
			if (is_array($value)) {
				$out[ $key . 'Count' ] = count($value);
			} elseif (is_string($value) && '' !== trim($value)) {
				$out[ $key . 'Count' ] = count(array_filter(array_map('trim', explode(',', $value))));
			}
		}
		return $out;
	}

	// ── License methods ───────────────────────────────────────────────────────
	// The Free plugin has no EDD license infrastructure. These return static
	// values so the payload is always consistent and the dashboard can correctly
	// segment free vs paid sites.

	/**
	 * License tier — always free on this variant.
	 *
	 * @return string
	 */
	protected function get_license_tier()
	{
		return 'free';
	}

	/**
	 * License status — none on the Free variant.
	 *
	 * @return null
	 */
	protected function get_license_status()
	{
		return null;
	}

	/**
	 * License expiry — none on the Free variant.
	 *
	 * @return null
	 */
	protected function get_license_expires()
	{
		return null;
	}

	/**
	 * License item/price ID — none on the Free variant.
	 *
	 * @return null
	 */
	protected function get_license_item_id()
	{
		return null;
	}

	// ── Metrics methods ───────────────────────────────────────────────────────

	/**
	 * Performance metrics (feed caches count, API rate-limit incidents).
	 *
	 * @param int $rate_limit_hits Rate-limit error count from get_error_metrics().
	 * @return array
	 */
	private function get_performance_metrics($rate_limit_hits = 0)
	{
		global $wpdb;
		$cache_table  = $wpdb->prefix . (defined('SBTT_FEED_CACHES_TABLE') ? SBTT_FEED_CACHES_TABLE : 'sbtt_feed_caches');
		$table_exists = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $cache_table)) === $cache_table;

		$feed_caches_count = 0;
		if ($table_exists) {
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name derived from $wpdb->prefix, not user input.
			$feed_caches_count = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$cache_table}");
		}

		return array(
			'feed_caches_count'   => $feed_caches_count,
			'api_rate_limit_hits' => (int) $rate_limit_hits,
		);
	}

	/**
	 * Error metrics from the sbtt_source_errors option — the only error store
	 * this plugin persists (written by Relay::handle_response() on API
	 * failures). These are reconnect-class source errors, narrower than "all
	 * API failures"; the metric reflects sources currently in an error state
	 * during the reporting period.
	 *
	 * The store is keyed by open_id — a TikTok account identifier — so only
	 * the entry VALUES are read here; the keys must never enter the payload.
	 *
	 * @param int $ts_start Period start timestamp (0 = no lower bound).
	 * @param int $ts_end   Period end timestamp (0 = no upper bound).
	 * @return array
	 */
	private function get_error_metrics($ts_start = 0, $ts_end = 0)
	{
		$stored = SourceErrors::all();

		$by_type        = array(
			'auth'       => 0,
			'rate_limit' => 0,
			'permission' => 0,
			'not_found'  => 0,
			'server'     => 0,
			'network'    => 0,
			'other'      => 0,
		);
		$latest         = array();
		$critical_count = 0;
		$api_fails      = 0;

		foreach (array_values($stored) as $err) {
			// Entries are ['message' => string, 'time' => int]. Scope to the
			// reporting period when a timestamp exists; legacy entries without
			// one are included rather than silently dropped.
			$time = is_array($err) && isset($err['time']) && is_numeric($err['time']) ? (int) $err['time'] : 0;
			if ($time > 0 && ( ( $ts_start > 0 && $time < $ts_start ) || ( $ts_end > 0 && $time > $ts_end ) )) {
				continue;
			}

			$raw      = is_array($err) && isset($err['message']) ? $err['message'] : (is_string($err) ? $err : '');
			$message  = $this->sanitize_error_message((string) $raw, 300);
			$cat      = $this->categorize_error_message($message);
			$critical = in_array($cat, array( 'auth', 'permission' ), true);

			++$api_fails;
			++$by_type[ $cat ];
			if ($critical) {
				++$critical_count;
			}

			$latest[] = array(
				'category' => $cat,
				'message'  => $message,
				'critical' => $critical,
			);
		}

		return array(
			'api_failures'   => $api_fails,
			'by_type'        => $by_type,
			'critical_count' => $critical_count,
			'latest'         => array_slice($latest, -10),
		);
	}

	/**
	 * Bucket an error message into one of the reported error categories.
	 *
	 * @param string $message Sanitized error message.
	 * @return string One of auth|rate_limit|permission|not_found|server|network|other.
	 */
	private function categorize_error_message($message)
	{
		// All five reconnect-class messages SourceErrors ever stores mean the
		// source's token is unusable — including the decrypt/MAC/payload
		// variants that carry neither "auth" nor "token" in their text.
		if (SourceErrors::is_reconnect_error($message)) {
			return 'auth';
		}
		if (stripos($message, 'auth') !== false || stripos($message, 'token') !== false) {
			return 'auth';
		}
		if (stripos($message, 'rate') !== false) {
			return 'rate_limit';
		}
		if (stripos($message, 'permission') !== false) {
			return 'permission';
		}
		if (
			stripos($message, 'not_found') !== false || stripos($message, 'not found') !== false
			|| stripos($message, '404') !== false
		) {
			return 'not_found';
		}
		if (
			stripos($message, 'server') !== false || stripos($message, '500') !== false
			|| stripos($message, '502') !== false || stripos($message, '503') !== false
		) {
			return 'server';
		}
		if (
			stripos($message, 'timeout') !== false || stripos($message, 'connection') !== false
			|| stripos($message, 'wp_error') !== false || stripos($message, 'http request') !== false
		) {
			return 'network';
		}

		return 'other';
	}

	/**
	 * Strip tokens and truncate error message.
	 *
	 * Relay error bodies are frequently JSON, so the key pattern accepts an
	 * optional closing quote between the key and the delimiter
	 * ("access_token":"..."). A shape-based fallback additionally redacts the
	 * act.* / rft.* token formats TikTok issues even when they appear in
	 * prose with no key=value structure. preg_replace() returns null on
	 * backtrack-limit failure — fail toward '' rather than the raw message.
	 *
	 * @param string $message Raw error message.
	 * @param int    $max_len Truncation length.
	 * @return string
	 */
	private function sanitize_error_message($message, $max_len = 300)
	{
		$message = (string) preg_replace(
			'/\b(access_token|accesstoken|api_key|api_secret|client_id|client_secret|consumer_key|consumer_secret|secret_key|auth_token|refresh_token|private_key|token)["\']?\s*[=:]\s*["\']?[^\s&"\'\\\\,\]}\)]{4,}["\']?/i',
			'$1=[REDACTED]',
			(string) $message
		);
		$message = (string) preg_replace('/\bBearer\s+[A-Za-z0-9\-._~+\/]+=*/i', 'Bearer [REDACTED]', $message);
		$message = (string) preg_replace('/\b(act|rft)\.[A-Za-z0-9._-]{8,}/i', '$1.[REDACTED]', $message);
		if (strlen($message) > $max_len) {
			$message = substr($message, 0, $max_len) . '...';
		}
		return $message;
	}

	/**
	 * Days active in the given period.
	 *
	 * @param string $period_start Y-m-d period start.
	 * @param string $period_end   Y-m-d period end.
	 * @return int
	 */
	private function get_days_active($period_start, $period_end)
	{
		$dates = get_option(Config::OPTION_ACTIVE_DATES, array());
		if (! is_array($dates) || empty($dates)) {
			return 0;
		}
		$count = 0;
		$start = strtotime($period_start);
		$end   = strtotime($period_end);
		foreach ($dates as $d) {
			$ts = strtotime($d);
			if (false !== $ts && $ts >= $start && $ts <= $end) {
				++$count;
			}
		}
		return $count;
	}

	/**
	 * Average of last recorded session durations in seconds.
	 */
	private function get_session_duration()
	{
		$durations = get_option(Config::OPTION_SESSION_DURATIONS, array());
		if (! is_array($durations) || empty($durations)) {
			return 0;
		}
		return (int) round(array_sum($durations) / count($durations));
	}

	/**
	 * Event counts and last_date for each event in the period.
	 *
	 * @param int $ts_start Period start timestamp.
	 * @param int $ts_end   Period end timestamp.
	 * @return array
	 */
	private function get_events_for_period($ts_start, $ts_end)
	{
		unset($ts_start, $ts_end);

		$events = get_option(EventRecorder::OPTION_NAME, array());
		if (! is_array($events)) {
			return array();
		}

		// The store has held the name-keyed {count,last_date} map since the
		// feature first shipped — no version ever wrote timestamped list
		// entries, so there is no legacy-list branch. A format sniff keyed on
		// the FIRST entry was also unsound: EventRecorder appends name-keyed
		// entries regardless, and a mixed store would silently hide them.
		//
		// Accumulate-then-clear format: report all stored events regardless
		// of last_date. The period parameters are payload metadata only — filtering
		// by last_date would silently exclude events recorded today and, combined
		// with the post-send reset, cause permanent data loss for those events.
		$out = array();
		foreach ($events as $name => $value) {
			if (! is_string($name) || '' === $name) {
				continue;
			}
			if (is_array($value) && isset($value['count'])) {
				$last_date    = isset($value['last_date']) && is_string($value['last_date']) ? $value['last_date'] : null;
				$out[ $name ] = array(
					'count'     => (int) $value['count'],
					'last_date' => $last_date,
				);
				continue;
			}
			if (is_numeric($value)) {
				$out[ $name ] = array(
					'count'     => (int) $value,
					'last_date' => null,
				);
			}
		}

		return $out;
	}
}
