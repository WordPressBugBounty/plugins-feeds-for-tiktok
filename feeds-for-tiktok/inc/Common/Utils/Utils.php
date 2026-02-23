<?php

namespace SmashBalloon\TikTokFeeds\Common;

use SmashBalloon\TikTokFeeds\Common\Container;
use SmashBalloon\TikTokFeeds\Common\Relay\Relay;
use SmashBalloon\TikTokFeeds\Common\Database\FeedsTable;
use SmashBalloon\TikTokFeeds\Common\Database\SourcesTable;
use SmashBalloon\TikTokFeeds\Common\AuthorizationStatusCheck;
use SmashBalloon\TikTokFeeds\Common\Services\NotificationService;

if (! defined('ABSPATH')) {
	exit;
}

class Utils
{
	/**
	 * Check if the application is running in production mode.
	 *
	 * @return bool Returns true if the application is in production mode, false otherwise.
	 */
	public static function isProduction()
	{
		return SBTT_PRODUCTION;
	}

	/**
	 * Checks if the plugin is the pro version.
	 *
	 * @return bool Returns true if the plugin is the pro version, false otherwise.
	 */
	public static function sbtt_is_pro()
	{
		return defined('SBTT_PRO') && SBTT_PRO === true;
	}

	/**
	 * Checks if the plugin is installed and activated.
	 *
	 * @param string $plugin Plugin name.
	 * @return bool
	 */
	public static function is_sb_plugin_active($plugin)
	{
		if (! function_exists('is_plugin_active')) {
			include_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		$sb_plugins = [
			'social-wall' => 'social-wall/social-wall.php',
			'instagram-feed' => 'instagram-feed/instagram-feed.php',
		];

		if (isset($sb_plugins[$plugin])) {
			$plugin = $sb_plugins[$plugin];
		}

		return is_plugin_active($plugin);
	}

	/**
	 * Used as a listener for the account connection process. If
	 * data is returned from the account connection processed it's used
	 * to generate the list of possible sources to chose from.
	 *
	 * @return array|false Source data on success, false on failure.
	 *
	 * @since 1.0
	 */
	public static function maybe_source_connection_data()
	{
		// Check for fragment-based OAuth success (version 3).
		// After AJAX processes the tokens, it stores source data in a transient
		// and redirects with sbtt_oauth_success=1.
		if ( isset( $_GET['sbtt_oauth_success'] ) && $_GET['sbtt_oauth_success'] === '1' ) {
			$user_id = get_current_user_id();
			$source  = get_transient( 'sbtt_new_source_data_' . $user_id );
			if ( $source ) {
				delete_transient( 'sbtt_new_source_data_' . $user_id );
				return $source;
			}
			// Transient missing (expired/cache issue) - don't fall through to legacy logic
			// as it expects tokens in $_REQUEST which aren't present in fragment-based flow.
			return false;
		}

		// Original logic for query-string based approach (version 1/2).
		$nonce = ! empty( $_REQUEST['sbtt_con'] ) ? sanitize_key( $_REQUEST['sbtt_con'] ) : '';
		if ( ! wp_verify_nonce( $nonce, 'sbtt_con' ) ) {
			return false;
		}

		if ( empty( $_REQUEST['sbtt_access_token'] ) || empty( $_REQUEST['sbtt_refresh_token'] ) ) {
			return false;
		}

		$result = self::retrieve_user_info();

		// Return false on error for backward compatibility with callers checking truthiness.
		return ( is_array( $result ) && isset( $result['error'] ) ) ? false : $result;
	}

	/**
	 * Retrieve the User Info for the new source connection from the User Info API.
	 *
	 * @param array $oauth_data Optional. OAuth data array with keys: access_token, refresh_token,
	 *                          openid, expires_in, refresh_expires_in, scope. If not provided,
	 *                          falls back to $_REQUEST for backward compatibility.
	 * @return array|false Source data array on success, array with 'error' key on API failure,
	 *                     or false if access_token is missing.
	 */
	public static function retrieve_user_info( $oauth_data = array() )
	{
		$oauth_data = self::extract_oauth_data( $oauth_data );

		if ( empty( $oauth_data['access_token'] ) ) {
			return false;
		}

		// Fetch user info from API before building source data.
		$relay    = new Relay();
		$response = $relay->call(
			'user/info',
			array(
				'access_token' => $oauth_data['access_token'],
				'open_id'      => $oauth_data['openid'],
			)
		);

		if ( isset( $response['success'] ) && false === $response['success'] ) {
			$error_message = isset( $response['data']['error'] ) ? $response['data']['error'] : __( 'Failed to retrieve user info from TikTok.', 'feeds-for-tiktok' );
			return array( 'error' => $error_message );
		}

		// Build source data only after successful API call.
		$sources      = array(
			'access_token'    => $oauth_data['access_token'],
			'refresh_token'   => $oauth_data['refresh_token'],
			'open_id'         => $oauth_data['openid'],
			'expires'         => date( 'Y-m-d H:i:s', time() + $oauth_data['expires_in'] ),
			'refresh_expires' => date( 'Y-m-d H:i:s', time() + $oauth_data['refresh_expires_in'] ),
			'scope'           => $oauth_data['scope'],
			'last_updated'    => date( 'Y-m-d H:i:s' ),
		);

		if ( isset( $response['data']['user_data'] ) ) {
			$user_data               = $response['data']['user_data'];
			$sources['display_name'] = ! empty( $user_data['display_name'] ) ? sanitize_text_field( wp_unslash( $user_data['display_name'] ) ) : '';
			$sources['info']         = sbtt_sanitize_data( $user_data );
		}

		// Update or insert the source.
		$source_table = new SourcesTable();
		$source_table->update_or_insert( $sources );

		if ( ! empty( $sources['info'] ) ) {
			$sources['info'] = sbtt_json_encode( $sources['info'] );
		}

		return $sources;
	}

	/**
	 * Extract and sanitize OAuth data from array or $_REQUEST.
	 *
	 * @param array $oauth_data Optional. Pre-sanitized OAuth data array.
	 * @return array Sanitized OAuth data with consistent keys.
	 */
	private static function extract_oauth_data( $oauth_data = array() )
	{
		if ( ! empty( $oauth_data ) ) {
			// Sanitize even when passed directly - defense in depth.
			return array(
				'access_token'       => ! empty( $oauth_data['access_token'] ) ? sanitize_text_field( $oauth_data['access_token'] ) : '',
				'refresh_token'      => ! empty( $oauth_data['refresh_token'] ) ? sanitize_text_field( $oauth_data['refresh_token'] ) : '',
				'openid'             => ! empty( $oauth_data['openid'] ) ? sanitize_text_field( $oauth_data['openid'] ) : '',
				'expires_in'         => ! empty( $oauth_data['expires_in'] ) ? absint( $oauth_data['expires_in'] ) : 0,
				'refresh_expires_in' => ! empty( $oauth_data['refresh_expires_in'] ) ? absint( $oauth_data['refresh_expires_in'] ) : 0,
				'scope'              => ! empty( $oauth_data['scope'] ) ? sanitize_text_field( $oauth_data['scope'] ) : '',
			);
		}

		// Fallback to $_REQUEST for backward compatibility (traditional OAuth redirect flow).
		return array(
			'access_token'       => ! empty( $_REQUEST['sbtt_access_token'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['sbtt_access_token'] ) ) : '',
			'refresh_token'      => ! empty( $_REQUEST['sbtt_refresh_token'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['sbtt_refresh_token'] ) ) : '',
			'openid'             => ! empty( $_REQUEST['sbtt_openid'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['sbtt_openid'] ) ) : '',
			'expires_in'         => ! empty( $_REQUEST['sbtt_expires_in'] ) ? absint( $_REQUEST['sbtt_expires_in'] ) : 0,
			'refresh_expires_in' => ! empty( $_REQUEST['sbtt_refresh_expires_in'] ) ? absint( $_REQUEST['sbtt_refresh_expires_in'] ) : 0,
			'scope'              => ! empty( $_REQUEST['sbtt_scope'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['sbtt_scope'] ) ) : '',
		);
	}

	/**
	 * Get Sources List from the database.
	 *
	 * @param array $args Source arguments.
	 * @return array
	 */
	public static function get_sources_list($args = [])
	{
		$source_table = new SourcesTable();
		$sources      = $source_table->get_sources($args);
		return $sources;
	}

	/**
	 * Get Feeds List from the database.
	 *
	 * @return array
	 */
	public static function get_feeds_list()
	{
		$feeds_table = new FeedsTable();
		$feeds       = $feeds_table->get_feeds();

		if (! $feeds) {
			return array();
		}

		// Add localization and sources list to each feed.
		foreach ($feeds as $key => $feed) {
			$settings                          = json_decode($feed['settings'], true);
			$settings                          = wp_parse_args($settings, sbtt_feed_settings_defaults());
			$feeds[ $key ]['instance_count']   = 0;
			$feeds[ $key ]['location_summary'] = array();
			$feeds[ $key ]['settings']         = $settings;
			$feeds[ $key ]['sourcesList']      = self::get_sources_list(
				array(
					'open_id' => isset($settings['sources']) && ! empty($settings['sources']) ? $settings['sources'] : array(),
				)
			);
		}

		return $feeds;
	}

	/**
	 * Get Feeds Count from the database.
	 *
	 * @return int
	 */
	public static function get_feeds_count()
	{
		$feeds_table = new FeedsTable();
		$feeds_count = $feeds_table->get_feeds_count();

		$feeds_count = $feeds_count ? absint($feeds_count) : 0;
		return $feeds_count;
	}

	/**
	 * Get feed settings depending on the feed templates.
	 *
	 * @param array $settings Feed settings.
	 * @return array
	 */
	public static function get_feed_settings_by_feed_templates($settings)
	{
		if (empty($settings['feedTemplate'])) {
			return self::get_default_template_settings($settings);
		}

		switch ($settings['feedTemplate']) {
			case 'carousel':
				return self::get_carousel_template_settings($settings);
			case 'cards':
				return self::get_card_template_settings($settings);
			case 'latest_video':
				return self::get_latest_video_template_settings($settings);
			case 'showcase_carousel':
				return self::get_showcase_carousel_template_settings($settings);
			case 'widget':
				return self::get_widget_template_settings($settings);
			case 'list':
				return self::get_list_template_settings($settings);
			case 'grid':
				return self::get_default_template_settings($settings);
			case 'gallery':
				return self::get_gallery_template_settings($settings);
			default:
				return self::get_default_template_settings($settings);
		}
	}

	/**
	 * Get the feed settings for the default/grid template.
	 *
	 * @param array $settings Feed settings.
	 * @return array
	 */
	public static function get_default_template_settings($settings)
	{
		// Layout.
		$settings['layout']      = 'grid';
		$settings['numPostDesktop'] = 9;
		$settings['numPostTablet']  = 8;
		$settings['numPostMobile'] = 6;

		// Header.
		$settings['showHeader'] = true;

		// Post Style.
		$settings['postStyle'] = 'regular';
		$settings['captionPadding'] = [
			'top' => 12,
			'bottom' => 12
		];
		$settings['postElements'] = [ 'thumbnail', 'playIcon', 'views', 'likes', 'caption' ];

		// Video Player Experience.
		$settings['videoPlayer'] = 'lightbox';

		// Load More Button.
		$settings['showLoadButton'] = true;

		return $settings;
	}

	/**
	 * Get the feed settings for the carousel template.
	 *
	 * @param array $settings 		Feed settings.
	 * @return array
	 */
	public static function get_carousel_template_settings($settings)
	{
		// Layout.
		$settings['layout']      = 'carousel';
		$settings['numPostDesktop'] = 10;
		$settings['numPostTablet']  = 8;
		$settings['numPostMobile'] = 6;
		$settings['carouselDesktopColumns'] = 3;
		$settings['carouselTabletColumns']  = 2;
		$settings['carouselMobileColumns']  = 1;

		// Header.
		$settings['showHeader'] = true;

		// Post Style.
		$settings['postStyle'] = 'regular';
		$settings['captionPadding'] = [
			'left' => 0,
			'top' => 12,
			'right' => 0,
			'bottom' => 12
		];
		$settings['postElements'] = [ 'thumbnail', 'playIcon', 'views', 'likes', 'caption' ];

		// Video Player Experience.
		$settings['videoPlayer'] = 'lightbox';

		// Load More Button.
		$settings['showLoadButton'] = false;

		return $settings;
	}

	/**
	 * Get the feed settings for the latest video template.
	 *
	 * @param array $settings 		Feed settings.
	 * @return array
	 */
	public static function get_latest_video_template_settings($settings)
	{
		// Layout.
		$settings['layout']      = 'list';
		$settings['numPostDesktop'] = 1;
		$settings['numPostTablet']  = 1;
		$settings['numPostMobile'] = 1;

		// Header.
		$settings['showHeader'] = true;

		// Post Style.
		$settings['postStyle'] = 'regular';
		$settings['captionPadding'] = [
			'left' => 0,
			'top' => 8,
			'right' => 0,
			'bottom' => 8
		];
		$settings['postElements'] = [ 'author_info', 'thumbnail', 'playIcon', 'views', 'likes', 'caption' ];

		// Video Player Experience.
		$settings['videoPlayer'] = 'inline';

		// Load More Button.
		$settings['showLoadButton'] = false;

		return $settings;
	}

	/**
	 * Get the feed settings for the list template.
	 *
	 * @param array $settings 		Feed settings.
	 * @return array
	 */
	public static function get_list_template_settings($settings)
	{
		// Layout.
		$settings['layout']      = 'list';
		$settings['numPostDesktop'] = 10;
		$settings['numPostTablet']  = 8;
		$settings['numPostMobile'] = 6;

		// Header.
		$settings['showHeader'] = true;

		// Post Style.
		$settings['postStyle'] = 'regular';
		$settings['captionPadding'] = [
			'left' => 0,
			'top' => 8,
			'right' => 0,
			'bottom' => 8
		];
		$settings['postElements'] = [ 'author_info', 'thumbnail', 'playIcon', 'views', 'likes', 'caption' ];

		// Video Player Experience.
		$settings['videoPlayer'] = 'inline';

		// Load More Button.
		$settings['showLoadButton'] = true;

		return $settings;
	}

	/**
	 * Get the feed settings for the card template.
	 *
	 * @param array $settings 		Feed settings.
	 * @return array
	 */
	public static function get_card_template_settings($settings)
	{
		// Layout.
		$settings['layout']      = 'grid';
		$settings['numPostDesktop'] = 10;
		$settings['numPostTablet']  = 8;
		$settings['numPostMobile'] = 6;

		// Header.
		$settings['showHeader'] = true;

		// Post Style.
		$settings['postStyle'] = 'boxed';
		$settings['captionPadding'] = [
			'left' => 8,
			'top' => 8,
			'right' => 8,
			'bottom' => 8
		];
		$settings['boxedBackgroundColor'] = '#ffffff';
		$settings['boxedBoxShadow'] = [
			'enabled' => true,
			'x' => '0',
			'y' => '1',
			'blur' => '10',
			'spread' => '1',
			'color' => 'rgba(0, 0, 0,0.11)'
		];
		$settings['boxedBorderRadius'] = [
			'enabled' => true,
			'radius' => '4'
		];

		$settings['postElements'] = [ 'thumbnail', 'playIcon', 'views', 'likes', 'caption' ];

		// Video Player Experience.
		$settings['videoPlayer'] = 'lightbox';

		// Load More Button.
		$settings['showLoadButton'] = true;

		return $settings;
	}

	/**
	 * Get the feed settings for the widget template.
	 *
	 * @param array $settings 		Feed settings.
	 * @return array
	 */
	public static function get_widget_template_settings($settings)
	{
		// Layout.
		$settings['layout']      = 'grid';
		$settings['numPostDesktop'] = 10;
		$settings['numPostTablet']  = 8;
		$settings['numPostMobile'] = 6;
		$settings['gridDesktopColumns'] = 1;
		$settings['gridTabletColumns']  = 1;
		$settings['gridMobileColumns']  = 1;

		// Header.
		$settings['showHeader'] = false;

		// Post Style.
		$settings['postStyle'] = 'regular';
		$settings['captionPadding'] = [
			'left' => 0,
			'top' => 12,
			'right' => 0,
			'bottom' => 12
		];
		$settings['postElements'] = [ 'thumbnail', 'playIcon', 'views', 'likes', 'caption' ];

		// Video Player Experience.
		$settings['videoPlayer'] = 'inline';

		// Load More Button.
		$settings['showLoadButton'] = true;

		return $settings;
	}

	/**
	 * Get the feed settings for the showcase carousel template.
	 *
	 * @param array $settings 		Feed settings.
	 * @return array
	 */
	public static function get_showcase_carousel_template_settings($settings)
	{
		// Layout.
		$settings['layout']      = 'carousel';
		$settings['numPostDesktop'] = 10;
		$settings['numPostTablet']  = 8;
		$settings['numPostMobile'] = 6;
		$settings['carouselDesktopColumns'] = 1;
		$settings['carouselTabletColumns']  = 1;
		$settings['carouselMobileColumns']  = 1;
		$settings['carouselLoopType']            = 'infinity';
		$settings['carouselIntervalTime']        = 5000;
		$settings['carouselShowArrows']          = false;
		$settings['carouselShowPagination']      = true;
		$settings['carouselEnableAutoplay']      = true;

		// Header.
		$settings['showHeader'] = true;

		// Post Style.
		$settings['postStyle'] = 'regular';
		$settings['captionPadding'] = [
			'top' => 12,
			'bottom' => 12
		];
		$settings['postElements'] = [ 'author_info', 'thumbnail', 'playIcon', 'views', 'likes', 'caption' ];

		// Video Player Experience.
		$settings['videoPlayer'] = 'lightbox';

		// Load More Button.
		$settings['showLoadButton'] = false;

		return $settings;
	}

	/**
	 * Get the feed settings for the gallery template.
	 *
	 * @param array $settings 		Feed settings.
	 * @return array
	 */
	public static function get_gallery_template_settings($settings)
	{
		// Layout.
		$settings['layout']      = 'gallery';
		$settings['numPostDesktop'] = 9;
		$settings['numPostTablet']  = 8;
		$settings['numPostMobile'] = 6;
		$settings['galleryDesktopColumns'] = 3;
		$settings['galleryTabletColumns']  = 2;
		$settings['galleryMobileColumns']  = 1;

		// Header.
		$settings['showHeader'] = true;

		// Post Style.
		$settings['postStyle'] = 'regular';
		$settings['captionPadding'] = [
			'top' => 12,
			'bottom' => 12
		];
		$settings['postElements'] = [ 'thumbnail', 'playIcon', 'views', 'likes', 'caption' ];

		// Video Player Experience.
		$settings['videoPlayer'] = 'inline';

		// Load More Button.
		$settings['showLoadButton'] = true;

		return $settings;
	}

	/**
	 * Check if the license is active and valid.
	 *
	 * @return bool
	 */
	public static function is_license_valid()
	{
		$settings = get_option('sbtt_global_settings', array());

		$license_key = isset($settings['license_key']) ? trim($settings['license_key']) : '';
		$license_status = isset($settings['license_status']) ? trim($settings['license_status']) : '';

		if (empty($license_key) || empty($license_status)) {
			return false;
		}

		if ($license_status !== 'invalid') {
			return true;
		}

		return false;
	}

	/**
	 * Features list
	 *
	 * @return array
	 */
	public static function get_features_list()
	{
		$features_list = [
			'basic' => [
				'card_layout',
				'load_more_button',
				'list_layout',
				'basic_templates',
				'header_stats_info',
				'post_stats_info',
				'inline_player'
			],
			'plus' => [
				'plus_templates',
				'masonry_layout',
				'carousel_layout',
				'gallery_layout',
				'random_sorting',
				'filter_posts'
			],
			'pro' => [
				'view_count_sorting',
				'like_count_sorting',
				'combine_feed_sources',
			],
		];

		return $features_list;
	}

	/**
	 * Features list in different tiers
	 *
	 * @return array
	 */
	public static function get_tiered_features_list()
	{
		$plugin_status = new AuthorizationStatusCheck();
		$statuses = $plugin_status->get_statuses();

		$license_tier = isset($statuses['license_tier']) ? $statuses['license_tier'] : 'free';
		if ($license_tier === 'free') {
			return [];
		}

		$features_list = self::get_features_list();
		$tiered_features = [];

		// if basic, return only basic, if plus, return basic and plus, if pro, return all.
		foreach ($features_list as $tier => $features) {
			if ($tier === $license_tier) {
				$tiered_features = array_merge($tiered_features, $features);
				break;
			}
			$tiered_features = array_merge($tiered_features, $features);
		}

		return $tiered_features;
	}

	/**
	 * Get list of Upsell Modal Content
	 *
	 * @return array
	 */
	public static function get_upsell_modal_content()
	{
		$base_url = 'https://smashballoon.com/';
		$utm_params = [
			'lite' => '?utm_campaign=tiktok-free&utm_source=all-feeds&utm_medium=%s&utm_content=LiteUsers50OFF',
			'upgrade' => '?utm_campaign=tiktok-free&utm_source=customizer&utm_medium=%s&utm_content=Upgrade',
			'learnMore' => '?utm_campaign=tiktok-free&utm_source=customizer&utm_medium=%s&utm_content=LearnMore'
		];

		$modals = [
			'feedsLimitModal' => [
				'heading' => __('Upgrade to TikTok Pro plans to add multiple feeds', 'feeds-for-tiktok'),
				'description' => __('Boost leads and conversions by displaying custom feeds all over your website to show fresh, relevant content.', 'feeds-for-tiktok'),
				'image' => 'upsell-multiple-feeds.png'
			],
			'sourcesLimitModal' => [
				'heading' => __('Upgrade to TikTok Pro plans to add multiple sources', 'feeds-for-tiktok'),
				'description' => __('Do you have multiple TikTok accounts? Use them all in different feeds or combine them into one.', 'feeds-for-tiktok'),
				'image' => 'upsell-multiple-sources.png'
			],
			'feedsUpdateLimitModal' => [
				'heading' => __('Upgrade to TikTok Pro plans to update your feed more often', 'feeds-for-tiktok'),
				'description' => __('Keep your feed content ultra-fresh with more frequent feed updates in the Pro version.', 'feeds-for-tiktok'),
				'image' => 'upsell-more-frequent-updates.png'
			],
			'loadMoreModal' => [
				'heading' => __('Upgrade to TikTok Pro to add load more functionality', 'feeds-for-tiktok'),
				'description' => __('Add a Load More button to your feed to allow users to load more posts.', 'feeds-for-tiktok'),
				'image' => 'upsell-loadmore.png'
			],
			'listModal' => [
				'heading' => __('Upgrade to TikTok Pro plans to get advanced layouts', 'feeds-for-tiktok'),
				'description' => __('Display your videos in a list or carousel to provide your content wherever it fits best on your site.', 'feeds-for-tiktok'),
				'image' => 'upsell-advanced-layouts.png'
			],
			'masonryModal' => [
				'heading' => __('Upgrade to TikTok Pro plans to get advanced layouts', 'feeds-for-tiktok'),
				'description' => __('Display your videos in a list or carousel to provide your content wherever it fits best on your site.', 'feeds-for-tiktok'),
				'image' => 'upsell-advanced-layouts.png'
			],
			'carouselModal' => [
				'heading' => __('Upgrade to TikTok Pro plans to get advanced layouts', 'feeds-for-tiktok'),
				'description' => __('Display your videos in a list or carousel to provide your content wherever it fits best on your site.', 'feeds-for-tiktok'),
				'image' => 'upsell-advanced-layouts.png'
			],
			'galleryModal' => [
				'heading' => __('Upgrade to TikTok Pro plans to get advanced layouts', 'feeds-for-tiktok'),
				'description' => __('Display your videos in a list or carousel to provide your content wherever it fits best on your site.', 'feeds-for-tiktok'),
				'image' => 'upsell-advanced-layouts.png'
			],
			'basicTemplateModal' => [
				'heading' => __('Upgrade to TikTok Pro plans to get one-click templates!', 'feeds-for-tiktok'),
				'description' => __('Choose from our expertly designed templates to make feed creation simple no matter the situation.', 'feeds-for-tiktok'),
				'image' => 'upsell-one-click-templates.png'
			],
			'plusTemplateModal' => [
				'heading' => __('Upgrade to TikTok Pro plans to get one-click templates!', 'feeds-for-tiktok'),
				'description' => __('Choose from our expertly designed templates to make feed creation simple no matter the situation.', 'feeds-for-tiktok'),
				'image' => 'upsell-one-click-templates.png'
			],
			'proHeaderModal' => [
				'heading' => __('Upgrade to TikTok Pro plans to display stats & description.', 'feeds-for-tiktok'),
				'description' => __('Provide rich content to boost visitor engagement and encourage them to follow your account.', 'feeds-for-tiktok'),
				'image' => 'upsell-header-elements.png'
			],
			'proPostModal' => [
				'heading' => __('Upgrade to TikTok Pro plans for more video elements', 'feeds-for-tiktok'),
				'description' => __('Show descriptions, view and like counts and author info to provide more rich content to engage your visitors.', 'feeds-for-tiktok'),
				'image' => 'upsell-post-elements.png'
			],
			'randomSortModal' => [
				'heading' => __('Upgrade to TikTok Pro plans for custom sorting', 'feeds-for-tiktok'),
				'description' => __('Sort videos randomly for a unique visitor experience or show off your most viewed or most liked videos first.', 'feeds-for-tiktok'),
				'image' => 'upsell-sort-random.png'
			],
			'filtersModal' => [
				'heading' => __('Upgrade to TikTok Pro plans to get word & hashtag filters', 'feeds-for-tiktok'),
				'description' => __('Filter by words and hashtags found in the caption. Curate content to target specific kinds of visitors.', 'feeds-for-tiktok'),
				'image' => 'upsell-word-filters.png'
			],
			'sortModal' => [
				'heading' => __('Upgrade to TikTok Pro plans to sort by likes & views', 'feeds-for-tiktok'),
				'description' => __('Show off your best content first to boost conversions of site visitors to TikTok followers.', 'feeds-for-tiktok'),
				'image' => 'upsell-sort-likes-views.png'
			],
			'cardLayoutModal' => [
				'heading' => __('Upgrade to TikTok Pro plans to get card layouts!', 'feeds-for-tiktok'),
				'description' => __('Display your videos in an attractive, modern card layout. Help your content standout to engage visitors.', 'feeds-for-tiktok'),
				'image' => 'upsell-boxed-layouts.png'
			],
			'playerExperienceModal' => [
				'heading' => __('Upgrade to TikTok Pro plans to get inline playback', 'feeds-for-tiktok'),
				'description' => __('Play videos directly on the page without the lightbox. Provide a seamless experience for visitors.', 'feeds-for-tiktok'),
				'image' => 'upsell-inline-player.png'
			],
		];

		$upsell_modal_content = [];

		foreach ($modals as $key => $modal) {
			$upsell_modal_content[$key] = array_merge($modal, [
				'buttons' => [
					'lite' => $base_url . 'pricing/tiktok-feed/' . sprintf($utm_params['lite'], $key),
					'upgrade' => $base_url . 'pricing/tiktok-feed/' . sprintf($utm_params['upgrade'], $key),
					'learnMore' => $base_url . 'tiktok-feeds/' . sprintf($utm_params['learnMore'], $key)
				],
				'includeContent' => true
			]);
		}

		return $upsell_modal_content;
	}

	/**
	 * Get list of Upsell Sidebar Cards
	 *
	 * @return array
	 */
	public static function get_sidebar_upsell_cards()
	{
		$tiered_features = self::get_tiered_features_list();

		$upsell_cards = [
			'like_count_sorting' => [
				'heading' => __('Sort by likes or views', 'feeds-for-tiktok'),
				'description' => __('Show most liked or most viewed videos first with a TikTok Pro plan', 'feeds-for-tiktok'),
				'image' => 'upsell-card-sort.png',
				'modal' => 'sortModal',
				'section' => 'sort'
			]
		];

		$tired_upsell_cards = [];

		// if feature is not in tiered features, add it to tired_upsell_cards.
		foreach ($upsell_cards as $key => $upsell_card) {
			if (! in_array($key, $tiered_features)) {
				$tired_upsell_cards[] = $upsell_card;
			}
		}

		return $tired_upsell_cards;
	}

	/**
	 * Get list of links for Social Wall plugin
	 *
	 * @return array
	 */
	public static function get_social_wall_links()
	{
		return [
			'<a href="' . esc_url(admin_url('admin.php?page=sbtt')) . '">' . __('All Feeds', 'feeds-for-tiktok') . '</a>',
			'<a href="' . esc_url(admin_url('admin.php?page=sbtt-settings')) . '">' . __('Settings', 'feeds-for-tiktok') . '</a>',
			'<a href="' . esc_url(admin_url('admin.php?page=sbtt-about')) . '">' . __('About Us', 'feeds-for-tiktok') . '</a>',
			'<a href="' . esc_url(admin_url('admin.php?page=sbtt-support')) . '">' . __('Support', 'feeds-for-tiktok') . '</a>',
		];
	}

	/**
	 * Check if the server can process HEIC/HEIF images.
	 *
	 * Performs a local-only check (no network calls) by verifying that
	 * ImageMagick has HEIC/HEIF delegates and is in the active
	 * wp_image_editors chain. Result is cached for 12 hours.
	 *
	 * @return bool
	 */
	public static function can_process_heic()
	{
		$override = apply_filters('sbtt_heic_supported', null);
		if ($override !== null) {
			return (bool) $override;
		}

		$cached = get_transient('sbtt_heic_capability');
		if ($cached !== false) {
			return $cached === 'yes';
		}

		$supported = false;

		// Check if ImageMagick has HEIC/HEIF delegates.
		if (class_exists('Imagick')) {
			try {
				$formats = \Imagick::queryFormats('HEIC');
				if (!empty($formats)) {
					$supported = true;
				}
				if (!$supported) {
					$formats = \Imagick::queryFormats('HEIF');
					$supported = !empty($formats);
				}
			} catch (\Exception $e) {
				$supported = false;
			}
		}

		// Verify ImageMagick is in the active wp_image_editors chain
		// (a host/plugin may force GD-only via the wp_image_editors filter).
		if ($supported) {
			$editors = apply_filters('wp_image_editors', array('WP_Image_Editor_Imagick', 'WP_Image_Editor_GD'));
			$has_imagick = false;
			foreach ($editors as $editor) {
				if (strpos($editor, 'Imagick') !== false) {
					$has_imagick = true;
					break;
				}
			}
			if (!$has_imagick) {
				$supported = false;
			}
		}

		set_transient('sbtt_heic_capability', $supported ? 'yes' : 'no', 12 * HOUR_IN_SECONDS);
		return $supported;
	}

	/**
	 * Get the count of notifications
	 *
	 * @return int
	 */
	public static function get_notifications_count()
	{
		$notifications = new NotificationService();
		$notifications = $notifications->getNotifications();
		$notifications_count = !empty($notifications) ? count($notifications) : 0;

		return $notifications_count;
	}
}
