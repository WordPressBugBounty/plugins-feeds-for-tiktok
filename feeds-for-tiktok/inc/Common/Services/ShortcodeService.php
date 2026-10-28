<?php

namespace SmashBalloon\TikTokFeeds\Common\Services;

use Smashballoon\Stubs\Services\ServiceProvider;
use SmashBalloon\TikTokFeeds\Common\Database\FeedCacheTable;
use SmashBalloon\TikTokFeeds\Common\FeedSettings;
use SmashBalloon\TikTokFeeds\Common\Feed;
use SmashBalloon\TikTokFeeds\Common\FeedCache;

class ShortcodeService extends ServiceProvider
{
	/**
	 * Register the shortcode.
	 *
	 * @return void
	 */
	public function register()
	{
		add_shortcode('sbtt-tiktok', array( $this, 'render' ));
	}

	/**
	 * Render the TikTok Feed Shortcode.
	 *
	 * @param array $atts Shortcode attributes.
	 *
	 * @return string
	 */
	public function render($atts = array())
	{
		$feed_id = ! empty($atts['feed']) ? absint($atts['feed']) : 0;

		$feed_data = new FeedSettings($feed_id);
		$feed_settings = $feed_data->get_feed_settings();

		if (empty($feed_settings)) {
			return $this->render_error(
				[
					[
						/* translators: %d: Feed ID number */
						'message'    => wp_sprintf(__('No feed with the ID %d found.', 'feeds-for-tiktok'), $feed_id),
						/* translators: 1: Opening anchor tag, 2: Closing anchor tag */
						'directions' => wp_sprintf(__('Please go to %1$sTikTok Feeds%2$s settings page to create a feed.', 'feeds-for-tiktok'), '<a href="' . esc_url(admin_url('admin.php?page=sbtt')) . '" target="_blank" rel="noopener noreferrer">', '</a>'),
					],
				]
			);
		}

		do_action('sbtt_before_shortcode_render', $feed_id);

		$feed_style = $feed_data->get_feed_style();

		// Pro's unauthenticated load-more handler treats the unsuffixed cache row
		// as proof the feed has been published, so a render that isn't public
		// must not create it. Until a public render has, a non-public one uses
		// its own "_PREVIEW_CUSTOMIZER" row. The builder never writes that row,
		// so it can't serve an unsaved builder preview, and it keeps the normal
		// cache time. The suffix must end in "_CUSTOMIZER": cron skips those
		// rows, but would absint() any other suffix back into the bare id and
		// write it. Once the bare row exists, writing it again changes nothing
		// the gate can see.
		$feed_cache = $this->is_public_render() || ( new FeedCacheTable() )->feed_cache_row_exists($feed_id)
			? new FeedCache($feed_id, 2 * DAY_IN_SECONDS)
			: new FeedCache($feed_id . '_PREVIEW_CUSTOMIZER', 2 * DAY_IN_SECONDS);

		$feed = new Feed($feed_settings, $feed_id, $feed_cache);
		$feed->init();
		$feed->get_set_cache();

		$header_data = $feed->get_header_data();
		$header_data = is_array($header_data) ? $header_data : [];

		$posts     = $feed->get_post_set_page();
		$next_page = $feed->has_next_page();
		$next_page = $next_page !== false ? $next_page : '';
		if (isset($feed_settings['sortRandomEnabled']) && $feed_settings['sortRandomEnabled'] === true) {
			shuffle($posts);
		}

		wp_enqueue_script('sbtt-tiktok-feed');
		wp_enqueue_style('sbtt-tiktok-feed');

		ob_start();

		if (! empty($feed->get_errors())) {
			$error_html = $this->render_error($feed->get_errors());
			echo wp_kses_post($error_html);
		}

		?>
			<style><?php echo esc_attr($feed_style); ?></style>
			<div class="sbtt-tiktok-feed" id="sbtt-tiktok-feed-<?php echo esc_attr($feed_id); ?>" data-feed-settings="<?php echo esc_attr(wp_json_encode($feed_settings)); ?>" data-feed-id="<?php echo esc_attr($feed_id); ?>" data-feed-posts="<?php echo esc_attr(wp_json_encode($posts)); ?>" data-feed-header="<?php echo esc_attr(wp_json_encode($header_data)); ?>" data-next-page="<?php echo esc_attr($next_page); ?>"></div>
		<?php
		return ob_get_clean();
	}

	/**
	 * Whether this render is on content that anyone can view.
	 *
	 * Previews, Customizer previews, wp-admin page loads, admin-ajax renders (page-builder editors
	 * such as Elementor's), editor REST renders and saves, and single views of a
	 * post that isn't published, are not. Load-more never calls render(), so
	 * treating admin-ajax as non-public costs nothing legitimate.
	 * get_post_status() resolves an attachment to its parent's status.
	 * is_post_publicly_viewable() would also do, but needs WordPress 5.7.
	 *
	 * @return bool
	 */
	private function is_public_render()
	{
		// is_admin(): opening a post in the block editor preloads
		// /wp/v2/<type>/<id>?context=edit through rest_do_request() during the
		// post.php page load, so the content is rendered with no REST_REQUEST and
		// no $_GET context. No public visitor ever renders a feed in wp-admin.
		if (is_preview() || is_customize_preview() || wp_doing_ajax() || is_admin() || $this->is_editor_rest_request()) {
			return false;
		}

		return ! is_singular() || 'publish' === get_post_status(get_queried_object_id());
	}

	/**
	 * Whether this is a REST request from an editor.
	 *
	 * The block editor renders the feed block with ServerSideRender, which
	 * posts to /wp/v2/block-renderer/ with context=edit while the post is
	 * usually still a draft. An editor's REST read of post content also uses
	 * context=edit. A public REST read, such as a headless site's GET of a
	 * published post, is neither, so it still counts as public.
	 *
	 * Saving a post is an editor request too, but carries neither signal: the
	 * block editor saves with POST /wp/v2/<type>/<id>?_locale=user, and
	 * WP_REST_Posts_Controller sets context=edit on the request object, not in
	 * $_GET, before rendering content.rendered. So any REST write that renders
	 * content is non-public, and so is a REST render of a post that isn't
	 * published (prepare_item_for_response() sets it up as the global post).
	 *
	 * @return bool
	 */
	private function is_editor_rest_request()
	{
		if (! defined('REST_REQUEST') || ! REST_REQUEST) {
			return false;
		}

		$method = isset($_SERVER['REQUEST_METHOD']) ? strtoupper(sanitize_key(wp_unslash($_SERVER['REQUEST_METHOD']))) : 'GET';
		if ('GET' !== $method && 'HEAD' !== $method) {
			return true;
		}

		$post_id = get_the_ID();
		if ($post_id && 'publish' !== get_post_status($post_id)) {
			return true;
		}

		global $wp;
		$route = isset($wp->query_vars['rest_route']) ? (string) $wp->query_vars['rest_route'] : '';
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only: only picks which cache row a render uses.
		$context = isset($_GET['context']) ? sanitize_key(wp_unslash($_GET['context'])) : '';

		return 0 === strpos($route, '/wp/v2/block-renderer/') || 'edit' === $context;
	}

	/**
	 * Render the error message.
	 *
	 * @param array $errors The errors to render.
	 * @return string
	 */
	public function render_error($errors)
	{
		if (! sbtt_current_user_can()) {
			return '';
		}

		if (empty($errors)) {
			return '';
		}

		if (! is_array($errors)) {
			$errors = array( $errors );
		}

		ob_start();
		?>
			<div class="sbtt-tiktok-feed-error" style="border: 1px solid #ddd; background: #eee; color: #333; margin: 0 auto 10px; padding: 10px 15px; font-size: 13px; text-align: center;">
				<span style="font-size: 12px; font-style: italic">
					<?php esc_html_e('This error message is only visible to WordPress admins.', 'feeds-for-tiktok'); ?>
				</span>
				<?php foreach ($errors as $error) : ?>
					<?php if (is_string($error)) : ?>
						<p><?php echo wp_kses_post($error); ?></p>
					<?php endif; ?>

					<?php if (is_array($error) && isset($error['message']) && isset($error['directions'])) : ?>
						<p><strong><?php echo wp_kses_post($error['message']); ?></strong></p>
						<p><?php echo wp_kses_post($error['directions']); ?></p>
					<?php endif; ?>

				<?php endforeach; ?>
			</div>
		<?php
		return ob_get_clean();
	}
}
