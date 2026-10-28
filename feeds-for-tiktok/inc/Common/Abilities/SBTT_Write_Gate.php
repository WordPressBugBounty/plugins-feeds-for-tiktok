<?php

namespace SmashBalloon\TikTokFeeds\Common\Abilities;

if (!defined('ABSPATH')) {
	exit;
}

/**
 * Site-level switch for write abilities (off by default).
 *
 * Mirrors InstagramFeed\Abilities\SBI_Write_Gate (AgDR-0503): a settings
 * toggle stored in the plugin's own global-settings option, overridable in
 * both directions by a filter. Read abilities never consult it.
 *
 * @since SBR-501
 */
class SBTT_Write_Gate
{
	/** Key inside the sbtt_global_settings option holding the toggle's value. */
	const OPTION_KEY = 'ai_write_enabled';
	/** Filter name that can force writes on or off in either direction. */
	const FILTER     = 'sbtt_abilities_allow_write';

	/** Option holding the plugin's global settings (and so the toggle). */
	const SETTINGS_OPTION = 'sbtt_global_settings';

	/**
	 * Gate epoch: when the STORED toggle last went on -> off (microtime(true), as
	 * a 6-decimal string). State tokens minted at or before it are dead for good, so
	 * disabling MCP Write Access revokes every outstanding link and re-enabling
	 * later cannot revive one. Not autoloaded. Tracks the stored toggle only; the
	 * FILTER is evaluated live wherever the gate is checked, so a filter override
	 * needs no epoch.
	 */
	const EPOCH_OPTION = 'sb_abilities_sbtt_gate_epoch';

	/**
	 * Start tracking disables of the stored toggle. Called once at boot.
	 *
	 * @return void
	 */
	public static function register_hooks()
	{
		add_action('update_option_' . self::SETTINGS_OPTION, array(__CLASS__, 'on_settings_updated'), 10, 2);
		// Deleting the settings resets the toggle to off; a later re-add must not revive links.
		add_action('delete_option_' . self::SETTINGS_OPTION, array(__CLASS__, 'record_disable'));
	}

	/**
	 * @param mixed $old Previous settings value.
	 * @param mixed $new New settings value.
	 * @return void
	 */
	public static function on_settings_updated($old, $new)
	{
		if (self::stored_toggle($old) && !self::stored_toggle($new)) {
			self::record_disable();
		}
	}

	/**
	 * @return void
	 */
	public static function record_disable()
	{
		// Fixed 6-decimal string: a bare (string) cast keeps only ~14 significant digits and would drop the microseconds.
		update_option(self::EPOCH_OPTION, sprintf('%.6F', microtime(true)), false);
	}

	/**
	 * @return float When the stored toggle was last disabled; 0.0 if never.
	 */
	public static function disabled_since()
	{
		return (float) get_option(self::EPOCH_OPTION, 0);
	}

	/**
	 * @param mixed $settings
	 * @return bool
	 */
	private static function stored_toggle($settings)
	{
		return is_array($settings) && !empty($settings[self::OPTION_KEY]);
	}

	/**
	 * Whether AI write access is currently enabled.
	 *
	 * @return bool
	 */
	public static function is_enabled()
	{
		$default = self::stored_toggle(get_option(self::SETTINGS_OPTION, array()));

		return (bool) apply_filters(self::FILTER, $default);
	}

	/**
	 * Gate a write ability on the toggle above.
	 *
	 * @return true|\WP_Error
	 */
	public static function check()
	{
		return self::is_enabled() ? true : SBTT_Ability_Response::writes_disabled();
	}
}
