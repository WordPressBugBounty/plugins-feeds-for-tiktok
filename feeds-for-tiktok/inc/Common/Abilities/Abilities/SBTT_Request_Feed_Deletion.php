<?php

namespace SmashBalloon\TikTokFeeds\Common\Abilities\Abilities;

use SmashBalloon\TikTokFeeds\Common\Database\FeedsTable;
use SmashBalloon\TikTokFeeds\Common\Abilities\SBTT_Ability_Response;
use SmashBalloon\TikTokFeeds\Common\Abilities\SBTT_State_Token;
use SmashBalloon\TikTokFeeds\Common\Abilities\Services\SBTT_Feed_Settings_Service;

if (!defined('ABSPATH')) {
	exit;
}

/**
 * Ability: tiktok-feeds/request-feed-deletion
 *
 * Never deletes anything itself: it returns a browser confirmation link. The
 * deletion happens only when the logged-in admin clicks Confirm on the
 * wp-admin screen behind that link (see SBTT_Delete_Confirmation).
 *
 * @since SBR-501
 */
class SBTT_Request_Feed_Deletion extends SBTT_Ability
{
	/**
	 * Slug after the namespace.
	 *
	 * @return string
	 */
	public function slug()
	{
		return 'request-feed-deletion';
	}

	/**
	 * Args for wp_register_ability().
	 *
	 * @return array
	 */
	public function args()
	{
		return array(
			'label'               => __('Request deletion of a TikTok Feed', 'feeds-for-tiktok'),
			'description'         => __(
				'Starts deletion of ONE feed. Returns a wp-admin confirmation link; the feed is deleted only after the site admin opens the link in their browser and clicks Confirm. The link is single-use, expires in 15 minutes, and requesting another deletion replaces any pending one — only one feed can ever be awaiting confirmation.', // phpcs:ignore Generic.Files.LineLength.TooLong -- single literal for make-pot
				'feeds-for-tiktok'
			),
			'input_schema'        => array(
				'type'                 => 'object',
				'properties'           => array(
					'feed_id' => array('type' => 'integer', 'minimum' => 1),
				),
				'required'             => array('feed_id'),
				'additionalProperties' => false,
			),
			'output_schema'       => array(
				'type'       => 'object',
				'properties' => array(
					'feed_id'     => array('type' => 'integer'),
					'feed_name'   => array('type' => 'string'),
					'review_url'  => array(
						'type'        => 'string',
						'description' => 'Opens this exact feed in the Feed Builder, to inspect before confirming.',
					),
					'confirm_url' => array('type' => 'string'),
					'expires_in'  => array('type' => 'integer'),
					'note'        => array('type' => 'string'),
				),
			),
			'execute_callback'    => array($this, 'execute'),
			'permission_callback' => array($this, 'can_write'),
			// Deliberately NOT annotated destructive: this ability only mints a
			// confirmation link — the destructive act happens on the wp-admin page
			// behind it, after the human clicks Delete. Annotating it destructive
			// would make MCP clients add a second, earlier approval that duplicates
			// the browser confirmation and doubles the friction (maintainer decision,
			// same as the Instagram Feed port).
			'meta'                => $this->write_meta(false, false),
		);
	}

	/**
	 * Execute this ability.
	 *
	 * @param mixed $input Raw ability input.
	 * @return array|\WP_Error
	 */
	public function execute($input = null)
	{
		$input   = $this->normalize_input($input);
		$feed_id = isset($input['feed_id']) ? (int) $input['feed_id'] : 0;
		if ($feed_id <= 0) {
			return SBTT_Ability_Response::invalid_feed_id();
		}
		// See the OBJECT-CACHE NOTE on SBTT_Feed_Settings_Service: invalidate
		// BEFORE this read so a feed the native Feed Builder just deleted (or a
		// stale pre-save row) can't be minted into a deletion-confirmation link.
		SBTT_Feed_Settings_Service::invalidate_cached_feed($feed_id);
		$row = (new FeedsTable())->get_feed($feed_id);
		if (!$row) {
			return SBTT_Ability_Response::feed_not_found($feed_id);
		}

		// One pending deletion per user: this invalidates any earlier, unconfirmed link.
		$token = SBTT_State_Token::create_exclusive('delete_feed_' . $feed_id, 'delete');
		if (is_wp_error($token)) {
			return $token;
		}

		return array(
			'feed_id'     => $feed_id,
			'feed_name'   => isset($row['feed_name']) ? (string) $row['feed_name'] : '',
			'review_url'  => admin_url('admin.php?page=sbtt&feed_id=' . $feed_id),
			'confirm_url' => add_query_arg(
				array(
					'page'                          => 'sbtt',
					'sbtt_abilities_confirm_delete' => $feed_id,
					'sbtt_abilities_state'          => $token,
				),
				admin_url('admin.php')
			),
			'expires_in'  => SBTT_State_Token::TTL,
			'note'        => __(
				'Nothing has been deleted yet. Ask the site admin to open the confirmation link in their browser and click "Delete feed".', // phpcs:ignore Generic.Files.LineLength.TooLong -- single literal for make-pot
				'feeds-for-tiktok'
			),
		);
	}
}
