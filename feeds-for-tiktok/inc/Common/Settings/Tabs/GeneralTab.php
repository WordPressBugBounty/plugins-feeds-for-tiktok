<?php

namespace SmashBalloon\TikTokFeeds\Common\Settings\Tabs;

use Smashballoon\Customizer\V3\SB_SettingsPage_Tab;

if (! defined('ABSPATH')) {
	exit;
}

class GeneralTab extends SB_SettingsPage_Tab
{
	/**
	 * Constructor. When consent is forced on by an active Pro plugin, wires the
	 * footer renderer that injects the read-only "Data sharing" info block into
	 * its placeholder section at the end of this tab (see tab_sections() and
	 * render_data_sharing_surface()). In Free, the user-manageable Data Sharing
	 * tab is shown instead, so we add neither the section nor the renderer.
	 *
	 * This class is auto-instantiated by Settings_Builder::settingspage_builder_data()
	 * during the settings page load, so the hook is implicitly scoped to that screen.
	 */
	public function __construct()
	{
		if ($this->show_data_sharing_info()) {
			add_action('admin_footer', array($this, 'render_data_sharing_surface'));
		}
	}

	/**
	 * Whether the Pro-only "Data sharing" info block should be shown — i.e. when
	 * the Consent package is present and consent is locked on by an active Pro plugin.
	 *
	 * @return bool
	 */
	protected function show_data_sharing_info()
	{
		return class_exists('\TikTokFeeds\Vendor\Smashballoon\Framework\Packages\Consent\ConsentManager')
			&& \TikTokFeeds\Vendor\Smashballoon\Framework\Packages\Consent\ConsentManager::is_locked_by_pro();
	}

	/**
	 * Get the Settings Tab info
	 *
	 * @since 1.0
	 *
	 * @return array
	 */
	protected function tab_info()
	{
		return [
			'id'   => 'sb-general-tab',
			'name' => __('General', 'feeds-for-tiktok'),
		];
	}

	/**
	 * Get the Settings Tab Section
	 *
	 * @since 1.0
	 *
	 * @return array
	 */
	protected function tab_sections()
	{
		$sections = [
			'licensekey_section'       => [
				'type'         => 'licensekey',
				'heading'      => __('License Key', 'feeds-for-tiktok'),
				'isProSetting' => true,
				'description'  => __('Your license key provides access to updates and support', 'feeds-for-tiktok'),
				'separator'    => true,
			],

			'licensekeyfree_section'   => [
				'type'          => 'licensekeyfree',
				'heading'       => __('License Key', 'feeds-for-tiktok'),
				'isFreeSetting' => true,
				'description'   => __('Your license key provides access to updates and support', 'feeds-for-tiktok'),
				'separator'     => true,
			],

			'sources_section'          => [
				'heading'          => __('Manage Sources', 'feeds-for-tiktok'),
				'inputDescription' => __('Add or remove connected TikTok accounts', 'feeds-for-tiktok'),
				'type'             => 'sources',
				'separator'        => true,
			],

			'preservesettings_section' => [
				'id'        => 'preserve_settings',
				'type'      => 'switcher',
				'heading'   => __('Preserve settings if plugin is removed', 'feeds-for-tiktok'),
				'info'      => __('This will make sure that all of your feeds and settings are still saved even if the plugin is uninstalled', 'feeds-for-tiktok'),
				'options'   => [
					'enabled'  => true,
					'disabled' => false,
				],
				'separator' => true,
			],

			'importfeed_section'       => [
				'heading' => __('Import Feed Settings', 'feeds-for-tiktok'),
				'type'    => 'importfeed',
				'info'    => __('You will need a JSON file previously exported from the TikTok Feeds Plugin', 'feeds-for-tiktok'),
			],
			'exportfeed_section'       => [
				'heading'   => __('Export Feed Settings', 'feeds-for-tiktok'),
				'type'      => 'exportfeed',
				'info'      => __('Export settings for one or more of your feeds', 'feeds-for-tiktok'),
				'separator' => true,
			],
		];

		// Pro-only read-only "Data sharing" block, pinned at the end of General.
		// An unknown section type renders a native heading row with an empty input
		// column; render_data_sharing_surface() injects the info fragment into it.
		if ($this->show_data_sharing_info()) {
			$sections['datasharing_section'] = [
				'type'    => 'consent-data-sharing',
				'heading' => __('Data sharing', 'feeds-for-tiktok'),
			];
		}

		return $sections;
	}

	/**
	 * Render the package's data-sharing info fragment into a hidden holder and
	 * relocate it into the React-rendered placeholder section's input column.
	 *
	 * Mirrors DebugTab's relocate technique (re-entrant via MutationObserver so
	 * the block survives React tab remounts) but is toggle-free — purely
	 * informational, so there is no state to bind or persist.
	 *
	 * @return void
	 */
	public function render_data_sharing_surface()
	{
		$permissions_url = \TikTokFeeds\Vendor\Smashballoon\Framework\Packages\Consent\ConsentManager::link_url('settings', 'permissions', 'tiktok');
		$terms_url       = \TikTokFeeds\Vendor\Smashballoon\Framework\Packages\Consent\ConsentManager::link_url('settings', 'terms', 'tiktok');
		$privacy_url     = \TikTokFeeds\Vendor\Smashballoon\Framework\Packages\Consent\ConsentManager::link_url('settings', 'privacy', 'tiktok');
		?>
		<div id="sbc-data-sharing-holder" style="display:none;">
			<div data-sbc-data-sharing-fragment>
				<p style="margin:0 0 10px;"><?php echo esc_html__('To keep your Smash Balloon plugins secure and up to date, we collect limited, anonymised usage data. We never collect your feed content or your visitors’ personal information. We’ll also send you occasional in-plugin notifications about updates, fixes, and new features.', 'feeds-for-tiktok'); ?></p>
				<div>
					<a href="<?php echo esc_url($permissions_url); ?>" target="_blank" rel="noopener" style="margin-right:16px;"><?php echo esc_html__('What data is collected?', 'feeds-for-tiktok'); ?></a>
					<a href="<?php echo esc_url($terms_url); ?>" target="_blank" rel="noopener" style="margin-right:16px;"><?php echo esc_html__('Terms & Conditions', 'feeds-for-tiktok'); ?></a>
					<a href="<?php echo esc_url($privacy_url); ?>" target="_blank" rel="noopener"><?php echo esc_html__('Privacy', 'feeds-for-tiktok'); ?></a>
				</div>
			</div>
		</div>
		<script>
		(function () {
			'use strict';
			var SECTION_TYPE = 'consent-data-sharing';

			var template = null;
			var holder   = document.getElementById('sbc-data-sharing-holder');
			if (holder) {
				var frag = holder.querySelector('[data-sbc-data-sharing-fragment]');
				if (frag) { template = frag.outerHTML; }
				if (holder.parentNode) { holder.parentNode.removeChild(holder); }
			}

			// Inject the fragment into the (empty) section input column whenever
			// it (re)appears — React remounts the active tab's sections on switch.
			function ensure() {
				if (!template) { return; }
				var sec = document.querySelector('.sb-settings-section-ctn[data-type="' + SECTION_TYPE + '"]');
				if (!sec) { return; }
				var target = sec.querySelector('.sb-settings-section-input') || sec;
				if (target.querySelector('[data-sbc-data-sharing-fragment]')) { return; }
				target.insertAdjacentHTML('beforeend', template);
			}

			var app = document.getElementById('sb-app') || document.body;
			new MutationObserver(ensure).observe(app, { childList: true, subtree: true });

			if (document.readyState === 'loading') {
				document.addEventListener('DOMContentLoaded', ensure);
			} else {
				ensure();
			}
		})();
		</script>
		<?php
	}
}
