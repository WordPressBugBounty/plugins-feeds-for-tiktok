<?php

namespace SmashBalloon\TikTokFeeds\Common\Integrations\Elementor;

if (! defined('ABSPATH')) {
	exit;
}

use TikTokFeeds\Vendor\Smashballoon\Framework\Packages\Blocks\SB_Elementor_Feed_Widget;
use SmashBalloon\TikTokFeeds\Common\Utils;

if (! class_exists('\Elementor\Widget_Base')) {
	return;
}

class SBTT_Modern_Elementor_Widget extends SB_Elementor_Feed_Widget
{
	/** {@inheritDoc} */
	protected function get_widget_name()
	{
		return SBTT_Elementor_Base::WIDGET_NAME;
	}

	/** {@inheritDoc} */
	protected function get_widget_title()
	{
		return __('TikTok Feed', 'feeds-for-tiktok');
	}

	/** {@inheritDoc} */
	protected function get_widget_icon()
	{
		return 'sb-elem-icon sb-elem-tiktok';
	}

	/** {@inheritDoc} */
	protected function get_shortcode_tag()
	{
		return 'sbtt-tiktok';
	}

	/**
	 * {@inheritDoc}
	 *
	 * @return array<int|string, string> Map of feed ID => feed name.
	 */
	protected function get_feeds_options()
	{
		$options = array();
		foreach (Utils::get_feeds_compact() as $feed) {
			if (empty($feed['id'])) {
				continue;
			}

			$id    = (string) $feed['id'];
			$label = ! empty($feed['feed_name'])
				? $feed['feed_name']
				/* translators: %s: numeric feed ID */
				: sprintf(__('Feed #%s', 'feeds-for-tiktok'), $id);

			$options[ $id ] = $label;
		}

		return $options;
	}

	/** {@inheritDoc} */
	protected function get_text_domain()
	{
		return 'feeds-for-tiktok';
	}

	/** {@inheritDoc} */
	protected function get_script_deps()
	{
		return array( 'sbtt-tiktok-feed', 'sb-elementor-editor' );
	}

	/** {@inheritDoc} */
	protected function get_style_deps()
	{
		return array( 'sbtt-tiktok-feed', 'sb-elementor-editor' );
	}

	/** {@inheritDoc} */
	protected function get_output_filter()
	{
		return 'sbtt_output';
	}
}
