<?php

namespace SmashBalloon\TikTokFeeds\Common;

if (! defined('ABSPATH')) {
	exit;
}

/**
 * Single place for source reconnect-error state: which errors require a
 * reconnect, the notice shown for them, and the per-source flags surfaced on
 * the Manage Sources screen. Add or change error codes in one spot here.
 */
class SourceErrors
{
	const OPTION = 'sbtt_source_errors';

	/**
	 * Error codes/messages that mean a source's token can no longer be used.
	 *
	 * @return string[]
	 */
	public static function reconnect_errors()
	{
		return [
			'access_token_invalid',
			'token_decrypt_failed',
			'The payload is invalid.',
			'The MAC is invalid.',
			'Could not decrypt the data.',
		];
	}

	/**
	 * @param string $error Error code or message from the relay.
	 * @return bool
	 */
	public static function is_reconnect_error($error)
	{
		return in_array($error, self::reconnect_errors(), true);
	}

	/**
	 * The user-facing "reconnect" notice + directions.
	 *
	 * @return array
	 */
	public static function notice()
	{
		return [
			'message'    => __('Invalid Access Token. Please reconnect the source.', 'feeds-for-tiktok'),
			/* translators: 1: Opening anchor tag, 2: Closing anchor tag */
			'directions' => wp_sprintf(__('Please go to %1$sTikTok Feeds%2$s settings page to reconnect the source.', 'feeds-for-tiktok'), '<a href="' . esc_url(admin_url('admin.php?page=sbtt-settings')) . '" target="_blank" rel="noopener noreferrer">', '</a>'),
		];
	}

	/**
	 * All flagged sources, keyed by open_id.
	 *
	 * @return array
	 */
	public static function all()
	{
		$errors = get_option(self::OPTION, []);
		return is_array($errors) ? $errors : [];
	}

	/**
	 * Flag a source as needing reconnection.
	 *
	 * @param string $open_id Source open id.
	 * @param string $message Originating error.
	 * @return void
	 */
	public static function flag($open_id, $message = '')
	{
		if (empty($open_id)) {
			return;
		}

		$errors = self::all();
		$errors[ $open_id ] = [
			'message' => is_string($message) ? $message : '',
			'time'    => time(),
		];

		update_option(self::OPTION, $errors);
	}

	/**
	 * Clear a source's reconnect flag.
	 *
	 * @param string $open_id Source open id.
	 * @return void
	 */
	public static function clear($open_id)
	{
		if (empty($open_id)) {
			return;
		}

		$errors = self::all();
		if (isset($errors[ $open_id ])) {
			unset($errors[ $open_id ]);
			update_option(self::OPTION, $errors);
		}
	}
}
