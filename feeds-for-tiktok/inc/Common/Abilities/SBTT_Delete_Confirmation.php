<?php

namespace SmashBalloon\TikTokFeeds\Common\Abilities;

use SmashBalloon\TikTokFeeds\Common\Database\FeedsTable;
use SmashBalloon\TikTokFeeds\Common\Abilities\Services\SBTT_Feed_Settings_Service;

if (!defined('ABSPATH')) {
	exit;
}

/**
 * Browser-side confirmation for ability-requested feed deletion.
 *
 * The `request-feed-deletion` ability never deletes anything: it returns a
 * link to this screen. Deletion happens only after the logged-in admin sees
 * the feed's name here and clicks Confirm — capability check, admin nonce and
 * single-use state token all verified on the POST.
 *
 * CACHE NOTE: FeedsTable::delete_feeds() — the same method the native
 * `sbtt_delete_feeds` AJAX handler calls — does not itself clear any
 * FeedCacheTable rows for the deleted feed(s) (verified: AjaxHandlerService::
 * delete_feeds() calls nothing else after it). This handler replicates that
 * exact behaviour rather than inventing new cache cleanup the native delete
 * path doesn't have (hard rule #11 — no invented cache logic).
 *
 * OBJECT-CACHE NOTE (distinct from the above): FeedsTable::get_feed() also
 * memoizes rows into the `tiktok-feeds` wp_cache group for 24h, and
 * delete_feeds() does not invalidate that entry either — see the
 * OBJECT-CACHE NOTE on SBTT_Feed_Settings_Service for the full hazard this
 * closes (a ghost feed could otherwise keep resolving, letting
 * request-feed-deletion mint a link for a feed that no longer exists).
 * handle_delete() calls SBTT_Feed_Settings_Service::invalidate_cached_feed()
 * — the SAME single helper the write abilities use — right after the
 * delete, so the key construction stays in one place. maybe_render() ALSO
 * calls it immediately BEFORE its own get_feed() read (Alex's PR #120
 * review): without that, a native Feed Builder save or delete that ran
 * since this confirmation link was minted would leave a stale row in the
 * cache, and this screen would render the pre-change feed_name or fail to
 * notice the feed is already gone.
 *
 * @since SBR-501
 */
class SBTT_Delete_Confirmation
{
	/**
	 * Hook the confirmation screen and the delete handler.
	 *
	 * @return void
	 */
	public function __construct()
	{
		add_action('admin_init', array($this, 'maybe_render'), 2);
		add_action('admin_post_sbtt_abilities_delete_feed', array($this, 'handle_delete'));
	}

	/**
	 * Render the confirmation screen when the state token checks out.
	 *
	 * @return void
	 */
	public function maybe_render()
	{
		if (
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only page-view guard; the single-use, user-bound, capability-gated state token checked below (peek()) is the actual authorization, not a nonce.
			empty($_GET['page']) || $_GET['page'] !== 'sbtt'
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- same guard as above.
			|| empty($_GET['sbtt_abilities_confirm_delete']) || empty($_GET['sbtt_abilities_state'])
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- same guard as above.
			|| !is_string($_GET['sbtt_abilities_confirm_delete']) || !is_string($_GET['sbtt_abilities_state'])
		) {
			return;
		}
		if (!sbtt_current_user_can()) {
			return;
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- capability-checked above; the state token (peek() below) is the actual authorization for this GET.
		$feed_id = absint($_GET['sbtt_abilities_confirm_delete']);
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- same as above.
		$token   = sanitize_key($_GET['sbtt_abilities_state']);
		// n2 (Rex round 2): re-check the write gate here too, matching
		// SBTT_Connect_Bootstrap's own revocation check — if AI write access was
		// turned off after this link was minted, the confirmation screen itself
		// should say so rather than let the admin reach the POST form only to
		// have handle_delete() reject it there. The token is burned (not just
		// refused) so re-enabling the gate later cannot revive it (Alex's QA
		// review). Capability was checked above and tokens are user-bound, so
		// this can only burn the caller's own link.
		if (!SBTT_Write_Gate::is_enabled()) {
			self::revoke_pending($token, $feed_id);
			wp_die(
				esc_html__('AI write access has been disabled since this link was created.', 'feeds-for-tiktok'),
				esc_html__('TikTok Feeds', 'feeds-for-tiktok'),
				array('response' => 403, 'back_link' => true)
			);
		}
		if (!$feed_id || !SBTT_State_Token::peek($token, 'delete_feed_' . $feed_id)) {
			wp_die(
				esc_html__(
					'This deletion link is invalid or has expired. Ask your AI assistant for a new one.',
					'feeds-for-tiktok'
				),
				esc_html__('TikTok Feeds', 'feeds-for-tiktok'),
				array('response' => 403, 'back_link' => true)
			);
		}
		// See the OBJECT-CACHE NOTE on SBTT_Feed_Settings_Service: invalidate
		// BEFORE this read so this confirmation screen can't render a feed that a
		// native Feed Builder save just changed (stale row) or a native delete
		// just removed (ghost feed from a stale cache entry).
		SBTT_Feed_Settings_Service::invalidate_cached_feed($feed_id);
		$row = (new FeedsTable())->get_feed($feed_id);
		if (!$row) {
			wp_die(
				esc_html__('This feed no longer exists.', 'feeds-for-tiktok'),
				esc_html__('TikTok Feeds', 'feeds-for-tiktok'),
				array('response' => 404, 'back_link' => true)
			);
		}
		$feed_name  = $row['feed_name'];
		$cancel     = admin_url('admin.php?page=sbtt');
		$review_url = admin_url('admin.php?page=sbtt&feed_id=' . $feed_id);

		// Minimal interstitial, rendered before the builder app boots.
		// phpcs:disable Generic.Files.LineLength.TooLong -- inline template/CSS.
		header('Content-Type: text/html; charset=' . get_option('blog_charset'));
		header('X-Frame-Options: SAMEORIGIN');
		header('Referrer-Policy: no-referrer');
		?>
		<!DOCTYPE html>
		<html <?php language_attributes(); ?>>
		<head>
			<meta charset="utf-8">
			<meta name="viewport" content="width=device-width, initial-scale=1">
			<title><?php esc_html_e('Confirm feed deletion', 'feeds-for-tiktok'); ?></title>
			<style>
				body{font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,sans-serif;background:#f0f0f1;display:flex;align-items:center;justify-content:center;min-height:100vh;margin:0}
				.sbtt-confirm{background:#fff;border:1px solid #c3c4c7;border-radius:8px;box-shadow:0 1px 3px rgba(0,0,0,.1);max-width:480px;padding:32px;text-align:center}
				.sbtt-confirm h1{font-size:18px;margin:0 0 12px}
				.sbtt-confirm p{color:#50575e;margin:0 0 24px}
				.sbtt-confirm .actions{display:flex;gap:12px;justify-content:center}
				.sbtt-confirm button{background:#d63638;border:none;border-radius:4px;color:#fff;cursor:pointer;font-size:14px;padding:10px 18px}
				.sbtt-confirm .actions a{align-items:center;border:1px solid #c3c4c7;border-radius:4px;color:#2c3338;display:inline-flex;font-size:14px;padding:10px 18px;text-decoration:none}
				.sbtt-confirm .meta{background:#f6f7f7;border-radius:4px;color:#50575e;font-size:13px;margin:0 0 24px;padding:12px}
				.sbtt-confirm .meta code{background:transparent}
				.sbtt-confirm .meta a{color:#2271b1}
			</style>
		</head>
		<body>
			<div class="sbtt-confirm">
				<h1><?php esc_html_e('Delete this TikTok feed?', 'feeds-for-tiktok'); ?></h1>
				<p>
					<?php
					printf(
						/* translators: 1: feed name, 2: feed id */
						esc_html__('An AI assistant asked to delete the feed %1$s (ID %2$d). This removes the feed; the connected account is not affected. This cannot be undone.', 'feeds-for-tiktok'),
						'<strong>' . esc_html($feed_name) . '</strong>',
						(int) $feed_id
					);
					?>
				</p>
				<p class="meta">
					<?php
					printf(
						/* translators: 1: shortcode, 2: opening anchor tag, 3: closing anchor tag */
						esc_html__('Shortcode: %1$s — %2$sreview this feed in the Feed Builder%3$s before deciding.', 'feeds-for-tiktok'),
						'<code>' . esc_html(SBTT_Edition::shortcode($feed_id)) . '</code>',
						'<a href="' . esc_url($review_url) . '" target="_blank" rel="noopener">',
						'</a>'
					);
					?>
				</p>
				<div class="actions">
					<form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
						<input type="hidden" name="action" value="sbtt_abilities_delete_feed">
						<input type="hidden" name="feed_id" value="<?php echo (int) $feed_id; ?>">
						<input type="hidden" name="state" value="<?php echo esc_attr($token); ?>">
						<?php wp_nonce_field('sbtt_abilities_delete_feed'); ?>
						<button type="submit"><?php esc_html_e('Delete feed', 'feeds-for-tiktok'); ?></button>
					</form>
					<a href="<?php echo esc_url($cancel); ?>"><?php esc_html_e('Cancel', 'feeds-for-tiktok'); ?></a>
				</div>
			</div>
		</body>
		</html>
		<?php
		// phpcs:enable Generic.Files.LineLength.TooLong
		exit;
	}

	/**
	 * Handle the confirmed deletion POST.
	 *
	 * @return void
	 */
	public function handle_delete()
	{
		if (!sbtt_current_user_can()) {
			wp_die(
				esc_html__('You do not have permission to do this.', 'feeds-for-tiktok'),
				'',
				array('response' => 403)
			);
		}
		check_admin_referer('sbtt_abilities_delete_feed');

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- check_admin_referer() above already verified the admin nonce for this POST.
		$feed_id = isset($_POST['feed_id']) && is_scalar($_POST['feed_id']) ? absint($_POST['feed_id']) : 0;
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- same admin nonce, already verified above.
		$token   = isset($_POST['state']) && is_string($_POST['state']) ? sanitize_key($_POST['state']) : '';

		// If AI write access was revoked after the link was minted, honour the
		// revocation: the whole flow exists because an agent asked. The token is
		// burned (not just refused) so re-enabling the gate later cannot revive it.
		if (!SBTT_Write_Gate::is_enabled()) {
			self::revoke_pending($token, $feed_id);
			wp_die(
				esc_html__('AI write access has been disabled since this link was created.', 'feeds-for-tiktok'),
				'',
				array('response' => 403)
			);
		}

		if (!$feed_id || !SBTT_State_Token::consume($token, 'delete_feed_' . $feed_id)) {
			wp_die(
				esc_html__('This deletion link is invalid or has expired.', 'feeds-for-tiktok'),
				'',
				array('response' => 403)
			);
		}

		// Same call the native `sbtt_delete_feeds` AJAX handler makes.
		(new FeedsTable())->delete_feeds(array($feed_id));
		// See the OBJECT-CACHE NOTE on this class: without this, FeedsTable::
		// get_feed()'s stale cached row would let this now-deleted feed keep
		// resolving for up to 24h.
		SBTT_Feed_Settings_Service::invalidate_cached_feed($feed_id);

		/**
		 * Fires after a feed was deleted through the ability-initiated,
		 * browser-confirmed flow.
		 *
		 * @param int $feed_id
		 */
		do_action('sbtt_abilities_feed_deleted', $feed_id);

		wp_safe_redirect(admin_url('admin.php?page=sbtt&sbtt_abilities_feed_deleted=' . $feed_id));
		exit;
	}

	/**
	 * Burn a pending deletion link when the write gate refuses it.
	 *
	 * @param string $token   Raw state token ('' when absent).
	 * @param int    $feed_id Feed the link targets (0 when absent).
	 * @return void
	 */
	private static function revoke_pending($token, $feed_id)
	{
		if ($feed_id && $token !== '') {
			SBTT_State_Token::consume($token, 'delete_feed_' . $feed_id);
		}
		// Also clear the single-pending-deletion slot (and the token it points at).
		SBTT_State_Token::revoke_group('delete');
	}
}
