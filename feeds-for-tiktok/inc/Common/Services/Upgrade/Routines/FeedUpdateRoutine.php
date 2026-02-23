<?php

namespace SmashBalloon\TikTokFeeds\Common\Services\Upgrade\Routines;

use Smashballoon\Stubs\Services\ServiceProvider;
use SmashBalloon\TikTokFeeds\Common\Database\FeedCacheTable;
use SmashBalloon\TikTokFeeds\Common\AuthorizationStatusCheck;
use SmashBalloon\TikTokFeeds\Common\FeedSettings;
use SmashBalloon\TikTokFeeds\Common\Feed;
use SmashBalloon\TikTokFeeds\Common\FeedCache;
use SmashBalloon\TikTokFeeds\Common\Utils;
use SmashBalloon\TikTokFeeds\Common\Database\PostsTable;
use SmashBalloon\TikTokFeeds\Common\Services\SettingsManagerService;

class FeedUpdateRoutine extends ServiceProvider
{
	/**
	 * The cron interval to use for the feed update routine.
	 *
	 * @var string
	 */
	private $cron_interval = 'hourly';

	/**
	 * The authorization status check service.
	 *
	 * @var AuthorizationStatusCheck
	 */
	private $auth_check;

	/**
	 * Class FeedUpdateRoutine
	 *
	 * Represents a routine for updating feeds.
	 */
	public function __construct()
	{
		$this->auth_check = new AuthorizationStatusCheck();
	}

	/**
	 * Registers the feed update routine.
	 */
	public function register()
	{
		if (! wp_next_scheduled('sbtt_feed_update_routine')) {
			wp_schedule_event(time(), $this->cron_interval, 'sbtt_feed_update_routine');
		}
		add_action('sbtt_feed_update_routine', array( $this, 'init_feed_updates' ));
		add_action('init', array( $this, 'sbtt_check_and_resize_images'));
	}

	/**
	 * Initializes the feed updates.
	 *
	 * This method checks if updates should be performed and retrieves the feeds to update.
	 * If there are feeds to update, it calls the update_feeds method to perform the updates.
	 * Finally, it updates the authentication statuses.
	 *
	 * @return void
	 */
	public function init_feed_updates()
	{
		if (! $this->should_do_updates()) {
			return;
		}

		$feeds_to_update = $this->get_feeds_to_update();

		if ($feeds_to_update === false) {
			return;
		}

		$this->update_feeds($feeds_to_update);

		$this->auth_check->update_statuses(
			array(
				'last_cron_update' => time(),
			)
		);
	}

	/**
	 * Determines whether updates should be performed.
	 *
	 * @return bool Returns true if updates should be performed, false otherwise.
	 */
	private function should_do_updates()
	{
		$statuses = $this->auth_check->get_statuses();
		$time_with_minute_buffer = time() + 60;

		return $statuses['last_cron_update'] < $time_with_minute_buffer - $statuses['update_frequency'];
	}

	/**
	 * Retrieves the feeds that need to be updated.
	 *
	 * @return array The feeds to update.
	 */
	private function get_feeds_to_update()
	{
		$feed_cache_table = new FeedCacheTable();
		return $feed_cache_table->get_feeds_to_update();
	}

	/**
	 * Updates the feeds based on the provided feed IDs.
	 *
	 * @param array $feeds_to_update An array of feed IDs to update.
	 * @return bool True if the feeds were successfully updated, false otherwise.
	 */
	public function update_feeds($feeds_to_update)
	{
		if (empty($feeds_to_update)) {
			return false;
		}

		foreach ($feeds_to_update as $single_feed) {
			$feed_id = ! empty($single_feed['feed_id']) ? absint($single_feed['feed_id']) : 0;

			$feed_data = new FeedSettings($feed_id);
			$feed_data = $feed_data->get_feed_data();

			if (empty($feed_data)) {
				continue;
			}

			$feed_settings = json_decode($feed_data['settings'], true);

			$feed = new Feed($feed_settings, $feed_id, new FeedCache($feed_id));
			$feed->init();
			$feed->get_set_cache();
		}

		return true;
	}

	/**
	 * Checks and resizes images based on global settings.
	 *
	 * This function retrieves global settings and checks if image optimization is enabled.
	 * If enabled, it retrieves resize data from the options table and processes each post's images.
	 * After resizing the images, it clears the resize data option.
	 *
	 * @return void
	 */
	public function sbtt_check_and_resize_images()
	{
		$global_settings = new SettingsManagerService();
		$global_settings = $global_settings->get_global_settings();

		if (! isset($global_settings['optimize_images']) || $global_settings['optimize_images'] !== true) {
			return;
		}

		$resize_data = get_option('sbtt_resize_images_data', array());

		if (empty($resize_data)) {
			return;
		}

		// Only check HEIC capability for pro (free version has normal images, no HEIC).
		$can_process_heic = Utils::sbtt_is_pro() ? Utils::can_process_heic() : true;

		foreach ($resize_data as $data) {
			if (empty($data['posts']) || empty($data['feed_id'])) {
				continue;
			}

			$posts = $data['posts'];
			$feed_id = $data['feed_id'];

			if (! $can_process_heic) {
				$heic_posts = array();
				$non_heic_posts = array();

				foreach ($posts as $key => $post) {
					$cover_url = isset($post['cover_image_url']) ? $post['cover_image_url'] : '';
					if ($this->is_heic_url($cover_url)) {
						$heic_posts[$key] = $post;
					} else {
						$non_heic_posts[$key] = $post;
					}
				}

				// Batch-mark HEIC posts for client-side conversion (no network).
				if (! empty($heic_posts)) {
					$this->batch_mark_heic_posts($heic_posts, $feed_id);
				}

				// Only process non-HEIC posts through image editor.
				if (! empty($non_heic_posts)) {
					$this->resize_post_images($non_heic_posts, $feed_id);
				}
			} else {
				$this->resize_post_images($posts, $feed_id);
			}
		}

		// Clear the option after resizing.
		delete_option('sbtt_resize_images_data');

		/**
		 * Apply a filter to allow other plugins to clear and update their cache.
		 */
		do_action('sbtt_cache_update_after_resize');
	}

	/**
	 * Resizes the images of the posts based on the global settings and feed settings.
	 *
	 * @param array $posts The array of posts to resize the images for.
	 * @param int   $feed_id The ID of the feed.
	 * @return void
	 */
	public function resize_post_images($posts, $feed_id)
	{
		if (!is_array($posts) || empty($posts) || empty($feed_id)) {
			return;
		}

		$id = strpos($feed_id, '_CUSTOMIZER') !== false ? str_replace('_CUSTOMIZER', '', $feed_id) : $feed_id;
		$feed_data = new FeedSettings($id);
		$feed_settings = $feed_data->get_feed_settings();

		if (empty($feed_settings['sources'])) {
			return;
		}

		$feed = new Feed($feed_settings, $id, new FeedCache($feed_id, 2 * DAY_IN_SECONDS));
		$feed->init();

		$resized_posts = array();
		foreach ($posts as $key => $post) {
			$post = $feed->resize_images($post);
			$resized_posts[$key] = $post;
		}

		// Update the cache with resized images.
		$feed->update_posts_cache_from_resize($resized_posts);
	}

	/**
	 * Check if a URL points to a HEIC/HEIF image.
	 *
	 * @param string $url The URL to check.
	 * @return bool
	 */
	private function is_heic_url($url)
	{
		if (empty($url)) {
			return false;
		}

		$path = wp_parse_url($url, PHP_URL_PATH);
		if (empty($path)) {
			return false;
		}

		$ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
		return in_array($ext, array('heic', 'heif'), true);
	}

	/**
	 * Batch-mark HEIC posts for client-side conversion without network calls.
	 *
	 * Sets image_processing_attempted = true and images_done = -1 so the
	 * client-side heic-converter.js handles them.
	 *
	 * @param array $heic_posts Array of HEIC posts.
	 * @param int   $feed_id The feed ID.
	 * @return void
	 */
	private function batch_mark_heic_posts($heic_posts, $feed_id)
	{
		$posts_table = new PostsTable();

		foreach ($heic_posts as $key => $post) {
			$video_id = isset($post['id']) ? sanitize_text_field($post['id']) : '';
			if (empty($video_id)) {
				continue;
			}

			$post['image_processing_attempted'] = true;
			$json_data = sbtt_sanitize_data($post);

			$posts_table->update(
				array(
					'json_data'   => $json_data,
					'images_done' => -1,
				),
				array('video_id' => $video_id)
			);

			$heic_posts[$key] = $post;
		}

		// Update feed cache so the front-end gets the updated posts.
		$id = strpos($feed_id, '_CUSTOMIZER') !== false ? str_replace('_CUSTOMIZER', '', $feed_id) : $feed_id;
		$feed_data = new FeedSettings($id);
		$feed_settings = $feed_data->get_feed_settings();

		if (empty($feed_settings['sources'])) {
			return;
		}

		$feed = new Feed($feed_settings, $id, new FeedCache($feed_id, 2 * DAY_IN_SECONDS));
		$feed->init();
		$feed->update_posts_cache_from_resize($heic_posts);
	}
}
