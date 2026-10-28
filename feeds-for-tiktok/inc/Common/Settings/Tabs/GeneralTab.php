<?php

namespace SmashBalloon\TikTokFeeds\Common\Settings\Tabs;

use Smashballoon\Customizer\V3\SB_SettingsPage_Tab;
use SmashBalloon\TikTokFeeds\Common\Abilities\SBTT_Abilities;
use SmashBalloon\TikTokFeeds\Common\Abilities\SBTT_Pro_Upsell;
use SmashBalloon\TikTokFeeds\Common\Integrations\WPVibe;

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

		// The Abilities API integration (SBR-501, free port SBR-579) is
		// registered in both editions (inc/Common/Abilities/SBTT_Abilities.php
		// via Common\ServiceContainer), so the "AI Agents" card and the
		// write-access toggle that gates it are shown in both, but only where
		// the core Abilities API exists (WP 6.9+).
		//
		// Positioned right after the license section, above Manage Sources
		// (SBR-501 follow-up, parity with instagram-feed-pro@d640c047) — the
		// write-access toggle originally moved here from the Advanced tab so
		// it's visible without digging into advanced settings, and the
		// "AI Agents" card (SBR-501) was added alongside it, cascaded from
		// the Instagram Feed Pro reference implementation
		// (instagram-feed-pro#1583, instagram-feed-pro@d640c047). The
		// toggle's section 'id' (ai_write_enabled) is UNCHANGED, so it still
		// persists to the same sbtt_global_settings['ai_write_enabled'] key
		// that inc/Common/Abilities/SBTT_Write_Gate.php reads — only its
		// heading/info copy changed, to "MCP Write Access" framing.
		if (SBTT_Abilities::is_available()) {
			$wpvibe = WPVibe::status();

			$ai_sections = [
				'aiagents_section' => [
					'id'      => 'ai_agents_card',
					// Deliberately not one of the customizer's known section
					// types (see the render_ai_agents_card() docblock below)
					// so SettingSectionOutput's type switch is a no-op here
					// and doesn't try to mount a control against this id.
					'type'      => 'aiagentscard',
					'heading'   => __('AI Agents', 'feeds-for-tiktok'),
					'info'      => $this->render_ai_agents_card($wpvibe),
					// Tie the MCP Write Access section (always rendered just below)
					// to this card: drop the separator so the two fuse into one
					// card, joined by the flat 1px border-bottom divider every
					// section has by default.
					'separator' => false,
				],
			];

			// MCP Write Access — rendered unconditionally (SBR-195 QA §1, Joel's
			// 2026-09-23 decision). The write gate is a plugin option the Abilities
			// API reads directly and never depends on WPVibe; gating the toggle's
			// visibility on WPVibe would leave non-WPVibe MCP clients no way to
			// enable writes and could strand an unrevocable permission if WPVibe
			// were later removed.
			$ai_sections['aiwriteenabled_section'] = [
				'id'        => 'ai_write_enabled',
				'type'      => 'switcher',
				'heading'   => __('MCP Write Access', 'feeds-for-tiktok'),
				'info'      => __('This will allow your AI agent to make changes to TikTok Feed.', 'feeds-for-tiktok'),
				'options'   => [
					'enabled'  => true,
					'disabled' => false,
				],
				'separator' => true,
			];

			// "Do more with AI on Pro" strip directly under MCP Write Access (AgDR-9006).
			// Free edition only; in Pro there is no section at all. The switcher drops its
			// divider so the strip fuses into the same card, like the card above it.
			if (SBTT_Pro_Upsell::is_free()) {
				$ai_sections['aiwriteenabled_section']['separator'] = false;
				$ai_sections['aiproupsell_section']                 = [
					'id'        => 'ai_pro_upsell',
					// Not one of the customizer's section types (same reason as
					// 'aiagentscard' above): only its `info` markup is rendered.
					'type'      => 'aiproupsell',
					'info'      => $this->render_ai_pro_upsell(),
					'separator' => true,
				];
			}

			$sections = $this->insert_after($sections, 'licensekeyfree_section', $ai_sections);
		}

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
	 * Render the "AI Agents" card (SBR-501).
	 *
	 * WHY THIS IS A RAW HTML STRING, NOT A CUSTOMIZER SECTION TYPE:
	 *
	 * TikTok Feed's settings screen is a data-driven customizer — this
	 * class returns plain config arrays (`tab_sections()` above), and the
	 * vendored `smashballoon/customizer` React app (composer package
	 * `smashballoon/customizer`, upstream repo `sb-customizer.git`, `v3`
	 * branch) renders each section from a fixed `type` switch in
	 * `SettingSectionOutput.js` — 'switcher', 'sources', 'licensekey',
	 * 'wpcodeintegration', 'gdpr', etc. There is no generic
	 * custom/HTML/callback section type in that switch, and `vendor/` is
	 * gitignored in this repo (pulled from a separate upstream git repo via
	 * composer) — so adding a proper `aiagentscard` case + a React
	 * component (the natural fix, mirroring how `wpcodeintegration` /
	 * `ManageWPCodeIntegration.js` already does exactly this for the WPCode
	 * install-CTA card) is NOT something this PR/repo can ship. That
	 * belongs in a follow-up PR against `awesomemotive/sb-customizer`
	 * (branch `v3`), not a local hack of the vendored bundle.
	 *
	 * What IS universally supported, regardless of `type`, is the `info`
	 * field: SettingsPage.js renders it as raw HTML for every section type
	 * (`dangerouslySetInnerHTML`), independent of the type switch. That's
	 * the closest faithful expression of the card available today, so this
	 * method server-renders the full card (logos, per-state heading/
	 * description, and a CTA) as static markup into that slot, with a
	 * companion vanilla-JS file (assets/js/ai-agents-card.js, enqueued by
	 * Pro\Services\ActionHooksService, NOT part of the vendor bundle) doing
	 * delegated-click AJAX install/activate against TikTok's own existing
	 * sbtt_install_plugin / sbtt_activate_plugin handlers
	 * (Common\Services\PluginInstallerService — the same handlers the
	 * vendor's own WPCode card already calls).
	 *
	 * GAP TO FLAG: because the card's `type` isn't a real customizer
	 * section, its interactive states (loading / installed / failed) are
	 * driven by plain DOM attribute toggling in that companion script
	 * rather than the customizer's own React state — functionally
	 * equivalent, but outside the customizer's normal component model.
	 * Full parity (a native section type, React-managed state, no separate
	 * script) needs the `sb-customizer` addition described above.
	 *
	 * @since 1.2.0
	 *
	 * @param array{status: string, basename: string|null} $wpvibe WPVibe::status(),
	 *        passed in rather than re-fetched so tab_sections() only scans
	 *        installed plugins once per request.
	 *
	 * @return string Escaped HTML for the section's `info` field.
	 */
	private function render_ai_agents_card($wpvibe)
	{
		$status   = $wpvibe['status'];
		$basename = $wpvibe['basename'];

		/* translators: %s: URL to the WPVibe plugin on WordPress.org */
		$description = sprintf(
			__('Manage TikTok Feed from your favorite AI agent.<br>Works through <a href="%s" target="_blank" rel="noopener">WPVibe</a>, a free plugin you install once.', 'feeds-for-tiktok'), // phpcs:ignore Generic.Files.LineLength.TooLong -- single literal for make-pot
			esc_url(WPVibe::ORG_URL)
		);

		$active_description = __('Manage TikTok Feed from your favorite AI agent.<br>Connect Claude, ChatGPT, Cursor or any MCP client in WPVibe.', 'feeds-for-tiktok'); // phpcs:ignore Generic.Files.LineLength.TooLong -- single literal for make-pot

		$heading_default = __('Use TikTok Feed with Claude, ChatGPT and more.', 'feeds-for-tiktok');
		$heading_active   = __('WPVibe is connected to TikTok Feed.', 'feeds-for-tiktok');

		$states = array(
			'not_installed' => array(
				'heading'     => $heading_default,
				'description' => $description,
				'button'      => __('Install WPVibe for free', 'feeds-for-tiktok'),
				'action'      => 'install',
			),
			'inactive'      => array(
				'heading'     => $heading_default,
				'description' => $description,
				'button'      => __('Activate WPVibe', 'feeds-for-tiktok'),
				'action'      => 'activate',
			),
			'active'        => array(
				'heading'     => $heading_active,
				'description' => $active_description,
				'button'      => __('Open WPVibe', 'feeds-for-tiktok'),
				'action'      => 'open',
			),
		);

		$current = isset($states[$status]) ? $states[$status] : $states['not_installed'];

		/* translators: %s: URL to the Plugins screen */
		$failed_error = sprintf(
			__('Couldn\'t install WPVibe. <a href="%s">Install it from the Plugins screen</a> instead.', 'feeds-for-tiktok'), // phpcs:ignore Generic.Files.LineLength.TooLong -- single literal for make-pot
			esc_url(admin_url('plugins.php'))
		);

		$apps_label = __('Works with ChatGPT, Cursor and Claude', 'feeds-for-tiktok');

		ob_start();
		?>
		<div class="sb-ai-agents-card"
			data-sbtt-ai-agents="1"
			data-state="<?php echo esc_attr($status); ?>"
			data-basename="<?php echo esc_attr((string) $basename); ?>"
			data-install-url="<?php echo esc_url(WPVibe::INSTALL_DOWNLOAD_URL); ?>"
			data-admin-url="<?php echo esc_url(WPVibe::admin_url()); ?>"
			data-nonce="<?php echo esc_attr(wp_create_nonce('sbtt-admin')); ?>"
			data-failed-error="<?php echo esc_attr($failed_error); ?>"
			data-retry-label="<?php echo esc_attr__('Try again', 'feeds-for-tiktok'); ?>">
			<div class="sb-ai-agents-apps" role="img" aria-label="<?php echo esc_attr($apps_label); ?>">
				<span class="sb-ai-agents-app sb-ai-agents-app-chatgpt" title="ChatGPT">
					<?php // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static literal brand-mark SVG, not user input. ?>
					<svg width="28" height="28" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true" focusable="false"><path d="M22.2819 9.8211a5.9847 5.9847 0 0 0-.5157-4.9108 6.0462 6.0462 0 0 0-6.5098-2.9A6.0651 6.0651 0 0 0 4.9807 4.1818a5.9847 5.9847 0 0 0-3.9977 2.9 6.0462 6.0462 0 0 0 .7427 7.0966 5.98 5.98 0 0 0 .511 4.9107 6.051 6.051 0 0 0 6.5146 2.9001A5.9847 5.9847 0 0 0 13.2599 24a6.0557 6.0557 0 0 0 5.7718-4.2058 5.9894 5.9894 0 0 0 3.9977-2.9001 6.0557 6.0557 0 0 0-.7475-7.0729zm-9.022 12.6081a4.4755 4.4755 0 0 1-2.8764-1.0408l.1419-.0804 4.7783-2.7582a.7948.7948 0 0 0 .3927-.6813v-6.7369l2.02 1.1686a.071.071 0 0 1 .038.052v5.5826a4.504 4.504 0 0 1-4.4945 4.4944zm-9.6607-4.1254a4.4708 4.4708 0 0 1-.5346-3.0137l.142.0852 4.783 2.7582a.7712.7712 0 0 0 .7806 0l5.8428-3.3685v2.3324a.0804.0804 0 0 1-.0332.0615L9.74 19.9502a4.4992 4.4992 0 0 1-6.1408-1.6464zM2.3408 7.8956a4.485 4.485 0 0 1 2.3655-1.9728V11.6a.7664.7664 0 0 0 .3879.6765l5.8144 3.3543-2.0201 1.1685a.0757.0757 0 0 1-.071 0l-4.8303-2.7865A4.504 4.504 0 0 1 2.3408 7.872zm16.5963 3.8558L13.1038 8.364 15.1192 7.2a.0757.0757 0 0 1 .071 0l4.8303 2.7913a4.4944 4.4944 0 0 1-.6765 8.1042v-5.6772a.79.79 0 0 0-.407-.667zm2.0107-3.0231l-.142-.0852-4.7735-2.7818a.7759.7759 0 0 0-.7854 0L9.409 9.2297V6.8974a.0662.0662 0 0 1 .0284-.0615l4.8303-2.7866a4.4992 4.4992 0 0 1 6.6802 4.66zM8.3065 12.863l-2.02-1.1638a.0804.0804 0 0 1-.038-.0567V6.0742a4.4992 4.4992 0 0 1 7.3757-3.4537l-.142.0805L8.704 5.459a.7948.7948 0 0 0-.3927.6813zm1.0976-2.3654l2.602-1.4998 2.6069 1.4998v2.9994l-2.5974 1.4997-2.6067-1.4997Z"/></svg>
				</span>
				<span class="sb-ai-agents-app sb-ai-agents-app-cursor" title="Cursor">
					<?php // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static literal brand-mark SVG, not user input. ?>
					<svg width="28" height="28" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true" focusable="false"><path d="M11.503.131 1.891 5.678a.84.84 0 0 0-.42.726v11.188c0 .3.162.575.42.724l9.609 5.55a1 1 0 0 0 .998 0l9.61-5.55a.84.84 0 0 0 .42-.724V6.404a.84.84 0 0 0-.42-.726L12.497.131a1.01 1.01 0 0 0-.996 0M2.657 6.338h18.55c.263 0 .43.287.297.515L12.23 22.918c-.062.107-.229.064-.229-.06V12.335a.59.59 0 0 0-.295-.51l-9.11-5.257c-.109-.063-.064-.23.061-.23"/></svg>
				</span>
				<span class="sb-ai-agents-app sb-ai-agents-app-claude" title="Claude">
					<?php // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static literal brand-mark SVG, not user input. ?>
					<svg width="28" height="28" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true" focusable="false"><path d="m4.7144 15.9555 4.7174-2.6471.079-.2307-.079-.1275h-.2307l-.7893-.0486-2.6956-.0729-2.3375-.0971-2.2646-.1214-.5707-.1215-.5343-.7042.0546-.3522.4797-.3218.686.0608 1.5179.1032 2.2767.1578 1.6514.0972 2.4468.255h.3886l.0546-.1579-.1336-.0971-.1032-.0972L6.973 9.8356l-2.55-1.6879-1.3356-.9714-.7225-.4918-.3643-.4614-.1578-1.0078.6557-.7225.8803.0607.2246.0607.8925.686 1.9064 1.4754 2.4893 1.8336.3643.3035.1457-.1032.0182-.0728-.164-.2733-1.3539-2.4467-1.445-2.4893-.6435-1.032-.17-.6194c-.0607-.255-.1032-.4674-.1032-.7285L6.287.1335 6.6997 0l.9957.1336.419.3642.6192 1.4147 1.0018 2.2282 1.5543 3.0296.4553.8985.2429.8318.091.255h.1579v-.1457l.1275-1.706.2368-2.0947.2307-2.6957.0789-.7589.3764-.9107.7468-.4918.5828.2793.4797.686-.0668.4433-.2853 1.8517-.5586 2.9021-.3643 1.9429h.2125l.2429-.2429.9835-1.3053 1.6514-2.0643.7286-.8196.85-.9046.5464-.4311h1.0321l.759 1.1293-.34 1.1657-1.0625 1.3478-.8804 1.1414-1.2628 1.7-.7893 1.36.0729.1093.1882-.0183 2.8535-.607 1.5421-.2794 1.8396-.3157.8318.3886.091.3946-.3278.8075-1.967.4857-2.3072.4614-3.4364.8136-.0425.0304.0486.0607 1.5482.1457.6618.0364h1.621l3.0175.2247.7892.522.4736.6376-.079.4857-1.2142.6193-1.6393-.3886-3.825-.9107-1.3113-.3279h-.1822v.1093l1.0929 1.0686 2.0035 1.8092 2.5075 2.3314.1275.5768-.3218.4554-.34-.0486-2.2039-1.6575-.85-.7468-1.9246-1.621h-.1275v.17l.4432.6496 2.3436 3.5214.1214 1.0807-.17.3521-.6071.2125-.6679-.1214-1.3721-1.9246L14.38 17.959l-1.1414-1.9428-.1397.079-.674 7.2552-.3156.3703-.7286.2793-.6071-.4614-.3218-.7468.3218-1.4753.3886-1.9246.3157-1.53.2853-1.9004.17-.6314-.0121-.0425-.1397.0182-1.4328 1.9672-2.1796 2.9446-1.7243 1.8456-.4128.164-.7164-.3704.0667-.6618.4008-.5889 2.386-3.0357 1.4389-1.882.929-1.0868-.0062-.1579h-.0546l-6.3385 4.1164-1.1293.1457-.4857-.4554.0608-.7467.2307-.2429 1.9064-1.3114Z" style="fill:#D97757"/></svg>
				</span>
			</div>

			<span class="sb-ai-agents-heading" data-role="heading">
				<?php echo wp_kses_post($current['heading']); ?>
			</span>
			<span class="help-text" data-role="description">
				<?php echo wp_kses_post($current['description']); ?>
			</span>

			<div class="sb-ai-agents-action">
				<?php if ('active' === $status) : ?>
					<a href="<?php echo esc_url(WPVibe::admin_url()); ?>"
						class="sb-btn sb-btn-secondary sb-btn-medium sbtt-ai-agents-btn"
						data-action="open">
						<?php echo esc_html($current['button']); ?>
					</a>
				<?php else : ?>
					<button type="button"
						class="sb-btn sb-btn-primary sb-btn-medium sbtt-ai-agents-btn"
						data-action="<?php echo esc_attr($current['action']); ?>">
						<?php echo esc_html($current['button']); ?>
					</button>
				<?php endif; ?>
			</div>

			<p class="sb-ai-agents-error" data-role="error" hidden>
				<?php echo wp_kses_post($failed_error); ?>
			</p>
		</div>
		<?php
		return trim((string) ob_get_clean());
	}

	/**
	 * Render the free-edition "Do more with AI on Pro" strip (AgDR-9006).
	 *
	 * Sits under the MCP Write Access switcher in the AI Agents block. Feature
	 * names come from the plugin's own upsell data (SBTT_Pro_Upsell::banner_feature_names()),
	 * so the strip carries no copy of its own beyond the title and button label.
	 * Server-rendered into the same raw-HTML `info` slot as the card above it, so
	 * every dynamic value is escaped here. The link is a real anchor that opens
	 * in a new tab, with a visually hidden notice for screen readers.
	 *
	 * @since SBR-579
	 *
	 * @return string Escaped HTML for the section's `info` field; empty outside the free edition.
	 */
	private function render_ai_pro_upsell()
	{
		if (!SBTT_Pro_Upsell::is_free()) {
			return '';
		}

		$names = SBTT_Pro_Upsell::banner_feature_names();
		$url   = SBTT_Pro_Upsell::upgrade_url('ai-agents-card', 'settings');

		ob_start();
		?>
		<div class="sb-ai-pro-upsell">
			<div class="sb-ai-pro-upsell-text">
				<strong><?php echo esc_html__('Do more with AI on Pro', 'feeds-for-tiktok'); ?></strong>
				<?php if (!empty($names)) : ?>
					<span><?php echo esc_html(implode(' · ', $names)); ?></span>
				<?php endif; ?>
			</div>
			<a href="<?php echo esc_url($url); ?>" target="_blank" rel="noopener"
				class="sb-btn sb-btn-primary sb-btn-small sb-ai-pro-upsell-btn">
				<?php echo esc_html__('Upgrade to Pro', 'feeds-for-tiktok'); ?>
				<span class="sb-ai-pro-upsell-sr">
					<?php echo esc_html__('(opens in a new tab)', 'feeds-for-tiktok'); ?>
				</span>
			</a>
		</div>
		<?php
		return trim((string) ob_get_clean());
	}

	/**
	 * Insert a set of sections immediately after a given section key,
	 * preserving array order (associative arrays have no native splice).
	 *
	 * @since 1.0
	 *
	 * @param array  $sections Existing ordered sections keyed by section id.
	 * @param string $afterKey Key to insert the new sections after.
	 * @param array  $insert   New sections to insert, keyed by section id.
	 *
	 * @return array
	 */
	private function insert_after($sections, $afterKey, $insert)
	{
		$position = array_search($afterKey, array_keys($sections), true);

		if (false === $position) {
			return array_merge($sections, $insert);
		}

		return array_slice($sections, 0, $position + 1, true)
			+ $insert
			+ array_slice($sections, $position + 1, null, true);
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
