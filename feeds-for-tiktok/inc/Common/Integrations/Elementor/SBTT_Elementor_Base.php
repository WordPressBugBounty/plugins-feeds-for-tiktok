<?php

namespace SmashBalloon\TikTokFeeds\Common\Integrations\Elementor;

if (! defined('ABSPATH')) {
	exit;
}

use Smashballoon\Stubs\Services\ServiceProvider;
use TikTokFeeds\Vendor\Smashballoon\Framework\Packages\Blocks\SB_Block_Utils;
use TikTokFeeds\Vendor\Smashballoon\Framework\Packages\Blocks\SB_Elementor_Editor_Assets;
use TikTokFeeds\Vendor\Smashballoon\Framework\Packages\Blocks\SB_Feed_Blocks_Registry;
use TikTokFeeds\Vendor\Smashballoon\Framework\Packages\Blocks\RecommendedElementorWidgets;
use SmashBalloon\TikTokFeeds\Common\Utils;

class SBTT_Elementor_Base extends ServiceProvider
{
	/**
	 * Elementor widget name. Lives on the always-loaded base so the registry
	 * call in register() doesn't have to autoload SBTT_Modern_Elementor_Widget
	 * (which guards against Elementor not being installed).
	 */
	public const WIDGET_NAME = 'sb-tiktok-feed';

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public function register()
	{
		// Always register the widget config in the unified registry so the
		// shared editor JS knows about it. No-op without Elementor.
		SB_Feed_Blocks_Registry::instance()->register_elementor_widget(
			[
				'blockId'    => 'tiktok',
				'widgetName' => self::WIDGET_NAME,
				'globalVar'  => 'sbttElementorData',
				'feedInitFn' => 'sbttInitializeFeed',
			]
		);

		// Defer Elementor-specific wiring until `init` so Elementor's autoloader
		// has fully bootstrapped. The framework's RecommendedElementorWidgets
		// file has a top-level `class_exists('\Elementor\Widget_Base')` guard
		// that aborts the entire file (skipping its inner SB_Faux_* class
		// declarations) if Widget_Base isn't autoloadable yet — which is the
		// case at plugins_loaded but not at init.
		add_action('init', [ $this, 'maybe_init_elementor' ], 4);
	}

	/**
	 * Wire Elementor-specific hooks once we know Elementor is loaded.
	 *
	 * @return void
	 */
	public function maybe_init_elementor()
	{
		if (! did_action('elementor/loaded')) {
			return;
		}

		// Faux widgets for uninstalled SB plugins (Smash Balloon discovery panel).
		( new RecommendedElementorWidgets('tiktok') )->setup();

		// Canonical Elementor hook for registering custom widgets — the
		// widgets manager is passed in and is fully ready at this point.
		add_action('elementor/widgets/register', [ $this, 'register_widget' ]);

		// Ensure the "Smash Balloon" category exists so our widget appears
		// in its category panel rather than under "Other".
		add_action('elementor/elements/categories_registered', [ $this, 'register_category' ]);

		// Shared Elementor styles (icons used by faux + real widgets).
		add_action(
			'elementor/editor/after_enqueue_scripts',
			static function () {
				SB_Elementor_Editor_Assets::enqueue_shared_elementor_styles(SBTTVER);
			}
		);

		// Frontend / preview iframe: enqueue plugin scripts, localize plugin
		// data, and register the shared editor JS so the widget's
		// script_depends can pull it in. The bundled sb-elementor-editor.js
		// reads `window.sbttElementorData` from inside the iframe to render
		// the customizer link and CTA — localizing on the editor-only hook
		// doesn't reach the iframe.
		add_action('elementor/frontend/after_register_scripts', [ $this, 'register_frontend_scripts' ]);
	}

	/**
	 * Register the modern Elementor widget with Elementor's widgets manager.
	 *
	 * @param \Elementor\Widgets_Manager $widgets_manager Elementor widgets manager.
	 * @return void
	 */
	public function register_widget($widgets_manager)
	{
		$widgets_manager->register(new SBTT_Modern_Elementor_Widget());
	}

	/**
	 * Register the "Smash Balloon" category with Elementor.
	 *
	 * @param \Elementor\Elements_Manager $elements_manager Elementor elements manager.
	 * @return void
	 */
	public function register_category($elements_manager)
	{
		$elements_manager->add_category(
			SB_Block_Utils::CATEGORY_SLUG,
			[
				'title' => esc_html__('Smash Balloon', 'feeds-for-tiktok'),
				'icon'  => 'fa fa-plug',
			]
		);
	}

	/**
	 * Enqueue plugin frontend scripts in the Elementor preview iframe,
	 * localize TikTok-specific editor data against the plugin handle, and
	 * register the shared editor JS via the registry.
	 *
	 * @return void
	 */
	public function register_frontend_scripts()
	{
		// Ensure plugin frontend scripts are present in the preview iframe.
		do_action('sbtt_enqueue_scripts', true);

		wp_localize_script(
			'sbtt-tiktok-feed',
			'sbttElementorData',
			[
				'feeds'         => Utils::get_feeds_compact(),
				'feed_url'      => admin_url('admin.php?page=sbtt'),
				'is_pro_active' => Utils::sbtt_is_pro(),
				'nonce'         => wp_create_nonce('sbtt-frontend'),
			]
		);

		// Register sb-elementor-editor + localize sbElementorRegistry so the
		// widget's script_depends['sb-elementor-editor'] can pull it into the
		// iframe.
		SB_Feed_Blocks_Registry::instance()->enqueue_elementor_assets();
	}
}
