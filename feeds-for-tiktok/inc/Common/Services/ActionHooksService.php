<?php

namespace SmashBalloon\TikTokFeeds\Common\Services;

use Smashballoon\Stubs\Services\ServiceProvider;
use SmashBalloon\TikTokFeeds\Common\Utils;

class ActionHooksService extends ServiceProvider
{
	/**
	 * Registers the action hooks for the plugin.
	 */
	public function register()
	{
		add_action('init', array($this, 'load_textdomain' ));
		add_action('admin_enqueue_scripts', array($this, 'dequeue_styles'), 11);
		add_action('admin_enqueue_scripts', array($this, 'enqueue_oauth_fragment_handler'));

		add_action('sbtt_enqueue_scripts', array( $this, 'register_scripts' ));
		add_action('wp_enqueue_scripts', array( $this, 'register_scripts' ));
		add_action('wp_enqueue_scripts', array( $this, 'set_script_translations' ), 11);

		add_action('wpcode_loaded', array($this, 'register_username'));
	}

	/**
	 * Load the plugin text domain for translation.
	 *
	 * @return void
	 */
	public function load_textdomain()
	{
		load_plugin_textdomain('feeds-for-tiktok', false, dirname(SBTT_PLUGIN_BASENAME) . '/languages');
	}

	/**
	 * Dequeue styles.
	 *
	 * @return void
	 */
	public function dequeue_styles()
	{
		$current_screen = get_current_screen();

		if (! $current_screen || ! isset($current_screen->id)) {
			return;
		}

		if (strpos($current_screen->id, 'sbtt') !== false) {
			wp_dequeue_style('cff_custom_wp_admin_css');
			wp_deregister_style('cff_custom_wp_admin_css');

			wp_dequeue_style('feed-global-style');
			wp_deregister_style('feed-global-style');

			wp_dequeue_style('sb_instagram_admin_css');
			wp_deregister_style('sb_instagram_admin_css');

			wp_dequeue_style('ctf_admin_styles');
			wp_deregister_style('ctf_admin_styles');
		}
	}

	/**
	 * Register the plugin's scripts and styles.
	 *
	 * @param bool $enqueue Whether to enqueue the scripts and styles.
	 *
	 * @return void
	 */
	public function register_scripts($enqueue = false)
	{
		$feed_js_file = SBTT_CUSTOMIZER_ASSETS . '/build/static/js/tikTokFeed.js';

		if (! Utils::isProduction()) {
			$feed_js_file = "http://localhost:3000/static/js/tikTokFeed.js";
		} else {
			wp_register_style(
				'sbtt-tiktok-feed',
				SBTT_CUSTOMIZER_ASSETS . '/build/static/css/tikTokFeed.css',
				false,
				false
			);
		}

		wp_register_script('sbtt-tiktok-feed', $feed_js_file, array( 'wp-i18n', 'jquery' ), SBTTVER, true);

		$data = array(
			'ajaxHandler' => admin_url('admin-ajax.php'),
			'nonce'       => wp_create_nonce('sbtt-frontend'),
			'isPro'		  => Utils::sbtt_is_pro()
		);

		wp_localize_script('sbtt-tiktok-feed', 'sbtt_feed_options', $data);

		if ($enqueue) {
			wp_enqueue_script('sbtt-tiktok-feed');
			wp_enqueue_style('sbtt-tiktok-feed');
		}
	}

	/**
	 * Set script translations.
	 *
	 * @return void
	 */
	public function set_script_translations()
	{
		wp_set_script_translations('sbtt-tiktok-feed', 'feeds-for-tiktok', SBTT_PLUGIN_DIR . 'languages/');
	}

	/**
	 * Register the username for the WPCode snippets.
	 *
	 * @return void
	 */
	public function register_username()
	{
		if (!function_exists('wpcode_register_library_username')) {
			return;
		}

		wpcode_register_library_username('smashballoon', 'Smash Balloon');
	}

	/**
	 * Enqueue the OAuth fragment handler script and related assets.
	 *
	 * This script captures OAuth tokens from URL fragments and sends them via AJAX.
	 * Also includes toast notification system for success/error feedback.
	 * Only loads on plugin admin pages where OAuth redirect can occur.
	 *
	 * @return void
	 */
	public function enqueue_oauth_fragment_handler()
	{
		$current_screen = get_current_screen();

		if (! $current_screen || ! isset($current_screen->id)) {
			return;
		}

		// Only load on plugin pages where OAuth redirect can occur.
		if (strpos($current_screen->id, 'sbtt') === false) {
			return;
		}

		// Enqueue OAuth notification and loading styles.
		wp_enqueue_style(
			'sbtt-oauth',
			SBTT_PLUGIN_URL . 'assets/css/sbtt-oauth.css',
			array(),
			SBTTVER
		);

		// Enqueue OAuth fragment handler (includes toast notification module).
		wp_enqueue_script(
			'sbtt-oauth-fragment',
			SBTT_PLUGIN_URL . 'assets/js/oauth-fragment-handler.js',
			array(),
			SBTTVER,
			false  // Load in head so it runs early.
		);

		wp_localize_script('sbtt-oauth-fragment', 'sbtt_oauth', array(
			'ajaxurl' => admin_url('admin-ajax.php'),
			'nonce'   => wp_create_nonce('sbtt-admin'),
			'strings' => array(
				'success'       => __('TikTok account connected successfully!', 'feeds-for-tiktok'),
				'error_prefix'  => __('Failed to connect TikTok account: ', 'feeds-for-tiktok'),
				'error_generic' => __('Failed to connect TikTok account. Please try again.', 'feeds-for-tiktok'),
			),
		));
	}
}
