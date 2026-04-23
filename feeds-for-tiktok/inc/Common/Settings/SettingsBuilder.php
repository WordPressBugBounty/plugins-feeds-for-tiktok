<?php

namespace SmashBalloon\TikTokFeeds\Common\Settings;

use Smashballoon\Customizer\V3\Settings_Builder;
use SmashBalloon\TikTokFeeds\Common\Container;
use SmashBalloon\TikTokFeeds\Common\Utils;
use SmashBalloon\TikTokFeeds\Common\Services\SettingsManagerService;
use SmashBalloon\TikTokFeeds\Common\AuthorizationStatusCheck;
use SmashBalloon\TikTokFeeds\Common\Services\PluginUpgraderService;
use SmashBalloon\TikTokFeeds\Common\Integrations\WPCode;
use SmashBalloon\TikTokFeeds\Common\Integrations\GDPR\GDPRIntegrations;

/**
 * Settings Builder class.
 */
class SettingsBuilder extends Settings_Builder
{
	/**
	 * Settings Menu Info
	 *
	 * @var array
	 */
	protected $menu;

	/**
	 *  Settings Tabs Path
	 *
	 * @var string
	 */
	protected $settingspage_tabs_path;

	/**
	 *  Settings Tabs Name Space
	 *
	 * @var string
	 */
	protected $settingspage_tabs_namespace;

	/**
	 *  Settings Tabs Order
	 *
	 * @var array
	 */
	protected $tabs_order;

	/**
	 *  Add to Menu
	 *
	 * @var bool
	 */
	protected $add_to_menu;

	/**
	 *  Plugin Status
	 *
	 * @var AuthorizationStatusCheck
	 */
	protected $plugin_status;

	/**
	 *  Global Settings
	 *
	 * @var SettingsManagerService
	 */
	protected $global_settings;

	/**
	 * Constructor
	 */
	public function __construct()
	{
		$this->menu                        = [
			'parent_menu_slug' => "sbtt",
			'page_title'       => "Settings",
			'menu_title'       => "Settings",
			'menu_slug'        => "sbtt-settings",
		];
		$this->settingspage_tabs_path      = SBTT_SETTINGSPAGE_TABS_PATH;
		$this->settingspage_tabs_namespace = SBTT_SETTINGSPAGE_TABS_NAMESPACE;
		$this->tabs_order                  = [ 'sb-general-tab', 'sb-feeds-tab', 'sb-advanced-tab', 'sb-code-snippets-tab' ];

		$this->add_to_menu     = !Utils::sbtt_is_pro() ? true : Utils::is_license_valid();
		$this->plugin_status   = new AuthorizationStatusCheck();
		$this->global_settings = Container::get_instance()->get(SettingsManagerService::class);
	}

	/**
	 * Retrieves the custom settings data for the plugin.
	 *
	 * @return array The custom settings data.
	 */
	public function customSettingsData()
	{
		$plugin_settings = $this->global_settings->get_global_settings();
		$active_gdpr_plugin = GDPRIntegrations::gdpr_plugins_active();

		$settings_data = [
			'nonce'          => wp_create_nonce('sbtt-admin'),
			'pluginSettings' => $plugin_settings,
			'currentTab'     => 'sb-general-tab',
			'assetsURL'      => SBTT_COMMON_ASSETS,
			'sourcesList'    => Utils::get_sources_list(),
			'feedsList'      => Utils::get_feeds_list(),
			'connectionURLs' => sbtt_get_tiktok_connection_urls(true),
			'pluginStatus'   => $this->plugin_status->get_statuses(),
			'isPro'          => Utils::sbtt_is_pro(),
			'aboutPageUrl'   => admin_url('admin.php?page=sbtt-about'),
			'isSocialWallActive' => Utils::is_sb_plugin_active('social-wall'),
			'socialWallLinks'    => Utils::get_social_wall_links(),
			'isDevUrl'       => PluginUpgraderService::is_dev_url(home_url()),
			'tieredFeatures' => Utils::get_tiered_features_list(),
			'upsellContent' => Utils::get_upsell_modal_content(),
			'wpCode' => array(
				'snippets' => WPCode::load_snippets(),
				'pluginInstalled' => WPCode::is_plugin_installed(),
				'pluginActive' => WPCode::is_plugin_active(),
				'isProInstalled' => WPCode::is_pro_installed(),
			),
			'gdprInfo' => array(
				'activePlugin' => $active_gdpr_plugin,
				'isActive'     => GDPRIntegrations::doing_gdpr($plugin_settings),
				'testsPass'    => $active_gdpr_plugin !== false,
				'wpConsent'    => array(
					'pluginInstalled' => file_exists(WP_PLUGIN_DIR . '/wpconsent-cookies-banner-privacy-suite/wpconsent.php'),
					'pluginActive'    => Utils::is_sb_plugin_active('wpconsent'),
				),
				'texts'        => array(
					'autoActive'   => __('Some TikTok Feed features will be limited for visitors to ensure GDPR compliance, until they give consent.', 'feeds-for-tiktok'),
					'autoInactive' => sprintf(
						__('No GDPR consent plugin detected. Install a compatible GDPR consent %1$splugin%2$s, or manually enable the setting to display a GDPR compliant version of the feed to all visitors.', 'feeds-for-tiktok'),
						'<a href="https://smashballoon.com/gdpr-compliant/?tiktok&utm_campaign=tiktok-free&utm_source=settings&utm_medium=gdpr-link" target="_blank" rel="noopener">',
						'</a>'
					),
					'yes'              => __('No requests will be made to third-party websites. To accommodate this, some features of the plugin will be limited.', 'feeds-for-tiktok'),
					'no'               => __('The plugin will function as normal and load images and videos directly from TikTok.', 'feeds-for-tiktok'),
					'whatLimited'      => __('What will be limited?', 'feeds-for-tiktok'),
					'limitedHeadline'  => __('Features that would be disabled or limited include:', 'feeds-for-tiktok'),
					'limitedFeatures'  => array(
						__('Only locally stored images will be displayed in the feed.', 'feeds-for-tiktok'),
						__('Placeholder images will be displayed until local images are available.', 'feeds-for-tiktok'),
						__('To view videos, visitors will click a link to view the video on TikTok.', 'feeds-for-tiktok'),
					),
				),
			),
			'adminNoticeContent' => apply_filters('sbtt_admin_notices_filter', 1),
			'upgradeProLink'	=> get_upgrade_pro_plugin_link($plugin_settings['license_key'] ?? null),
			'isLicenseUpgraded'   => get_option('sbtt_islicence_upgraded'),
			'licenseUpgradedInfo' => get_option('sbtt_upgraded_info')
		];

		$newly_retrieved_source_connection_data = Utils::maybe_source_connection_data();
		if ($newly_retrieved_source_connection_data) {
			$settings_data['newSourceData'] = $newly_retrieved_source_connection_data;
		}

		return $settings_data;
	}
}
