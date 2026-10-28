<?php

namespace SmashBalloon\TikTokFeeds\Common\Services;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use Smashballoon\Stubs\Services\ServiceProvider;
use SmashBalloon\TikTokFeeds\Common\Utils;
use SmashBalloon\TikTokFeeds\Common\Container;
use SmashBalloon\TikTokFeeds\Common\Services\SettingsManagerService;
use SmashBalloon\TikTokFeeds\Common\Integrations\GDPR\GDPRIntegrations;
use SmashBalloon\TikTokFeeds\Common\Abilities\SBTT_Abilities;

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

		// AI Agents card (SBR-501 / SBR-579) -- Settings screen only, both editions
		// (rendered by GeneralTab::tab_sections() under the same is_available() gate).
		add_action('admin_enqueue_scripts', array($this, 'enqueue_ai_agents_card_assets'));
	}

	/**
	 * Enqueue the "AI Agents" card's CSS + companion vanilla-JS AJAX handler.
	 *
	 * The card's markup is server-rendered into the customizer's generic
	 * `info` slot (see GeneralTab::render_ai_agents_card() for the full
	 * reasoning) rather than a real customizer section component, so it
	 * needs its own small script instead of anything shipped inside the
	 * vendored `sb-customizer` bundle. The script depends on
	 * 'sb-customizer-app' purely for load ordering (it reads
	 * window.sb_customizer.ajaxHandler as a fallback to window.ajaxurl) --
	 * not because the vendor bundle exposes any hook for it.
	 *
	 * @return void
	 */
	public function enqueue_ai_agents_card_assets()
	{
		// Both editions: only where the Abilities API can actually register.
		if (! SBTT_Abilities::is_available()) {
			return;
		}

		$screen = get_current_screen();
		if (! $screen || ! isset($screen->id)) {
			return;
		}

		// The Settings screen's hook suffix is `{parent}_page_{menu_slug}` --
		// i.e. `sbtt_page_sbtt-settings` -- because GeneralTab's card is
		// rendered on SettingsBuilder's page, whose menu_slug
		// ('sbtt-settings') differs from its parent_menu_slug ('sbtt'). The
		// list this used to check against ('toplevel_page_sbtt', 'sbtt',
		// 'sbtt-settings', copy-pasted from is_feed_builder_page() below) only
		// matches the Feed Builder/Customizer page, where parent_menu_slug
		// === menu_slug -- it never matches the real Settings screen, so
		// this callback returned early on every load there and the card's
		// CSS/JS silently never enqueued (SBR-501). Match on substring
		// instead of an exact id/base list so this doesn't re-drift if the
		// hook suffix format changes.
		if (false === strpos($screen->id, 'sbtt-settings') && false === strpos((string) $screen->base, 'sbtt-settings')) {
			return;
		}

		wp_enqueue_style(
			'sbtt-ai-agents-card',
			SBTT_PLUGIN_URL . 'assets/css/ai-agents-card.css',
			array(),
			SBTTVER
		);

		wp_enqueue_script(
			'sbtt-ai-agents-card',
			SBTT_PLUGIN_URL . 'assets/js/ai-agents-card.js',
			array('sb-customizer-app'),
			SBTTVER,
			true
		);
	}

	/**
	 * Load the plugin text domain for translation.
	 *
	 * @return void
	 */
	public function load_textdomain()
	{
		// phpcs:ignore PluginCheck.CodeAnalysis.DiscouragedFunctions.load_plugin_textdomainFound
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
			// SBTTVER, not false: passing false makes WordPress substitute its OWN
			// version, so the cache key only changes when WordPress is upgraded — any
			// CSS shipped in a plugin update is served stale from CDNs until then. The
			// sibling wp_register_script() below already passes SBTTVER.
			wp_register_style(
				'sbtt-tiktok-feed',
				SBTT_CUSTOMIZER_ASSETS . '/build/static/css/tikTokFeed.css',
				array(),
				SBTTVER
			);
		}

		wp_register_script('sbtt-tiktok-feed', $feed_js_file, array( 'wp-i18n', 'jquery' ), SBTTVER, true);

		$global_settings = Container::get_instance()->get(SettingsManagerService::class)->get_global_settings();

		$data = array(
			'ajaxHandler' => admin_url('admin-ajax.php'),
			'nonce'       => wp_create_nonce('sbtt-frontend'),
			'isPro'		  => Utils::sbtt_is_pro(),
		);

		wp_localize_script('sbtt-tiktok-feed', 'sbtt_feed_options', $data);

		// Pass GDPR options for the React frontend.
		$gdpr_data = array(
			'gdprActive'         => GDPRIntegrations::doing_gdpr($global_settings),
			'activePlugin'       => GDPRIntegrations::get_active_plugin_slug(),
			'placeholder'        => SBTT_PLUGIN_URL . 'assets/images/sbtt-gdpr-placeholder.svg',
			'avatarPlaceholder'  => SBTT_PLUGIN_URL . 'assets/images/sbtt-gdpr-avatar-placeholder.svg',
		);

		wp_localize_script('sbtt-tiktok-feed', 'sbtt_gdpr_options', $gdpr_data);

		// Hydrates feed divs that arrive via the Gutenberg block's ServerSideRender.
		// See assets/js/block-editor-mount.js for rationale. Admin-only since the
		// frontend has feed divs in the initial HTML and tikTokFeed.js mounts them
		// directly on script load — the MutationObserver shim is only useful for
		// the iframed editor where SSR injects DOM after script load.
		if (is_admin()) {
			wp_register_script(
				'sbtt-block-editor-mount',
				SBTT_PLUGIN_URL . 'assets/js/block-editor-mount.js',
				array( 'sbtt-tiktok-feed' ),
				SBTTVER,
				true
			);
		}

		if ($enqueue) {
			wp_enqueue_script('sbtt-tiktok-feed');
			wp_enqueue_style('sbtt-tiktok-feed');
			if (is_admin()) {
				wp_enqueue_script('sbtt-block-editor-mount');
			}
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
				'connecting'    => __('Connecting your TikTok account…', 'feeds-for-tiktok'),
			),
		));
	}
}
