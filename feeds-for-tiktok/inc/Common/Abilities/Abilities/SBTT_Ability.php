<?php

namespace SmashBalloon\TikTokFeeds\Common\Abilities\Abilities;

use SmashBalloon\TikTokFeeds\Common\Abilities\SBTT_Ability_Response;
use SmashBalloon\TikTokFeeds\Common\Abilities\SBTT_Write_Gate;

if (!defined('ABSPATH')) {
	exit;
}

/**
 * Base class for one TikTok Feeds ability.
 *
 * Subclasses return the wp_register_ability() args; the shared pieces
 * (namespace, capability check, input normalisation, read-only meta) live here
 * so every ability fences authorization the same way. Mirrors
 * InstagramFeed\Abilities\Abilities\SBI_Ability (AgDR-0503).
 *
 * @since SBR-501
 */
abstract class SBTT_Ability
{
	/** Ability namespace shared by every TikTok Feeds ability. */
	const NAMESPACE_PREFIX = 'tiktok-feeds';
	/** Ability category shared by every TikTok Feeds ability. */
	const CATEGORY         = 'tiktok-feeds';

	/**
	 * The slug after the namespace.
	 *
	 * @return string Slug after the namespace, e.g. `get-feed`.
	 */
	abstract public function slug();

	/**
	 * The wp_register_ability() args for this ability.
	 *
	 * @return array Args for wp_register_ability() (without `category`).
	 */
	abstract public function args();

	/**
	 * The fully-qualified ability name.
	 *
	 * @return string Fully-qualified ability name.
	 */
	public function name()
	{
		return self::NAMESPACE_PREFIX . '/' . $this->slug();
	}

	/**
	 * Register this ability with the core Abilities API.
	 *
	 * @return void
	 */
	public function register()
	{
		$args             = $this->args();
		$args['category'] = self::CATEGORY;
		wp_register_ability($this->name(), $args);
	}

	/**
	 * Same gate as the Settings/Feed Builder pages, including the
	 * `sbtt_settings_pages_capability` filter contract.
	 *
	 * @return true|\WP_Error
	 */
	public function can_manage()
	{
		return sbtt_current_user_can() ? true : SBTT_Ability_Response::forbidden();
	}

	/**
	 * Normalise ability input to a plain array.
	 *
	 * Core hands abilities null|array|object depending on transport.
	 *
	 * @param mixed $input Raw ability input.
	 * @return array
	 */
	protected function normalize_input($input)
	{
		if (is_object($input)) {
			$input = get_object_vars($input);
		}
		return is_array($input) ? $input : array();
	}

	/**
	 * Shared `meta` block for a read-only ability.
	 *
	 * @param bool $mcp_public Whether the ability is discoverable over MCP.
	 * @return array
	 */
	protected function read_meta($mcp_public = true)
	{
		return array(
			'annotations'  => array(
				'readonly'    => true,
				'destructive' => false,
				'idempotent'  => true,
			),
			'show_in_rest' => true,
			'mcp'          => array('public' => (bool) $mcp_public),
		);
	}

	/**
	 * Write gate first, then capability — same order as the Instagram Feed port.
	 *
	 * @return true|\WP_Error
	 */
	public function can_write()
	{
		$gate = SBTT_Write_Gate::check();
		if (is_wp_error($gate)) {
			return $gate;
		}
		return $this->can_manage();
	}

	/**
	 * Shared `meta` block for a write ability.
	 *
	 * @param bool $destructive Whether the ability is destructive.
	 * @param bool $idempotent  Whether the ability is idempotent.
	 * @return array
	 */
	protected function write_meta($destructive, $idempotent)
	{
		return array(
			'annotations'  => array(
				'readonly'    => false,
				'destructive' => (bool) $destructive,
				'idempotent'  => (bool) $idempotent,
			),
			'show_in_rest' => true,
			// Hidden from MCP discovery while writes are disabled (agents should not
			// be shown a tool they cannot use); REST stays capability-gated.
			'mcp'          => array('public' => SBTT_Write_Gate::is_enabled()),
		);
	}

	/**
	 * Shared `output_schema` shape for a write ability.
	 *
	 * @param array $extra Extra top-level output properties.
	 * @return array
	 */
	protected function write_output_schema(array $extra = array())
	{
		return array(
			'type'       => 'object',
			'properties' => array_merge(
				$extra,
				array(
					'updated' => array(
						'type'        => 'array',
						'items'       => array('type' => 'string'),
						'description' => 'Keys that were written.',
					),
					'ignored' => array(
						'type'        => 'object',
						'description' => 'Keys not written, with the reason: '
							. 'read_only, feature_unavailable or unknown_setting.',
					),
					// AgDR-9004 requirement 3 — non-fatal signal for a WRITTEN
					// key that is currently inert (e.g. a masonry-only setting
					// while layout is not masonry). Always present, even when
					// empty, so an agent never has to infer "nothing to report"
					// from a missing field.
					'warnings' => array(
						'type'        => 'array',
						'items'       => array(
							'type'       => 'object',
							'properties' => array(
								'key'     => array('type' => 'string'),
								'reason'  => array('type' => 'string'),
								'message' => array('type' => 'string'),
							),
						),
						'description' => 'Non-fatal signals about the keys just written — for example, a '
							. 'value that was stored but has no visible effect until another setting '
							. '(layout, postStyle) changes. Empty when there is nothing to flag.',
					),
				)
			),
		);
	}
}
