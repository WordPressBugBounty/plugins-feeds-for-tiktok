<?php

namespace SmashBalloon\TikTokFeeds\Common\Abilities\Services;

use SmashBalloon\TikTokFeeds\Common\Container;
use SmashBalloon\TikTokFeeds\Common\Services\SettingsManagerService;

if (!defined('ABSPATH')) {
	exit;
}

/**
 * Reads/writes plugin-wide settings for the Abilities API, restricted to the
 * SBTT_Settings_Schema::global_settings() allowlist.
 *
 * Goes through the plugin's own SettingsManagerService rather than a raw
 * get_option()/update_option() round trip: update_global_settings() MERGES
 * the incoming partial onto the current stored settings (wp_parse_args), so
 * calling it with only the ability-changed keys can never clobber unrelated
 * options (custom CSS/JS-equivalents, license fields, ...) — the same
 * "never call the settings-save path with a partial/synthetic model" rule
 * the port brief calls out, satisfied here by reusing the plugin's own safe
 * merge rather than reimplementing it.
 *
 * @since SBR-501
 */
class SBTT_Global_Settings_Service
{
	/**
	 * Read the current, schema-cast global settings.
	 *
	 * @return array<string, mixed>
	 */
	public function get()
	{
		$service  = Container::get_instance()->get(SettingsManagerService::class);
		$all      = $service->get_global_settings();
		$defaults = SBTT_Settings_Schema::global_settings_supplemental_defaults();
		$schema   = SBTT_Settings_Schema::for_edition(SBTT_Settings_Schema::global_settings(), true);
		$out      = array();
		foreach ($schema as $key => $def) {
			$out[$key] = SBTT_Settings_Schema::cast_out(
				$def,
				isset($all[$key]) ? $all[$key] : null,
				isset($defaults[$key]) ? $defaults[$key] : null
			);
		}
		return $out;
	}

	/**
	 * Apply a partial update to the plugin-wide settings.
	 *
	 * @param array $partial key => value, already schema-validated by core.
	 * @return array{updated: string[], ignored: object}|\WP_Error
	 */
	public function update(array $partial)
	{
		$schema   = SBTT_Settings_Schema::for_edition(SBTT_Settings_Schema::global_settings());
		$writable = SBTT_Settings_Schema::writable($schema);
		$updated  = array();
		$ignored  = array();
		$clean    = array();

		foreach ($partial as $key => $value) {
			if (!isset($schema[$key])) {
				$ignored[$key] = 'unknown_setting';
				continue;
			}
			if (!isset($writable[$key])) {
				$ignored[$key] = 'read_only';
				continue;
			}
			$v = SBTT_Settings_Schema::validate_in($schema[$key], $value, $key);
			if (is_wp_error($v)) {
				return $v;
			}
			$clean[$key] = $v;
			$updated[]   = $key;
		}

		if (empty($clean)) {
			return array('updated' => array(), 'ignored' => (object) $ignored, 'warnings' => array());
		}

		// update_global_settings() merges $clean onto the current stored option
		// (wp_parse_args) and handles its own side effects (e.g. the usage-tracking
		// scheduler toggle) — never a raw update_option() replace.
		$service = Container::get_instance()->get(SettingsManagerService::class);
		$service->update_global_settings($clean);

		/**
		 * Fires after an AI agent updated global settings through the Abilities API.
		 *
		 * @param string[] $updated Keys that were written.
		 */
		do_action('sbtt_abilities_global_settings_updated', $updated);

		$warnings = array();
		if (in_array('gdpr', $updated, true) && $clean['gdpr'] === 'no') {
			$warnings[] = 'GDPR mode is now "no": feeds load TikTok media and scripts before '
				. 'visitors consent. Tell the site owner, since this can affect legal compliance.';
		}

		return array('updated' => $updated, 'ignored' => (object) $ignored, 'warnings' => $warnings);
	}
}
