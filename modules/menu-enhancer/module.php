<?php

/**
 * Menu Enhancer module.
 * 
 * Inspired by Menu Management Enhancer by SevenSpark (discontinued).
 *
 * Bizen module — written and maintained by Bizen (https://bizen.it).
 *
 */

defined('ABSPATH') || exit;

return new class extends Bizen_Module {

	public function get_id(): string
	{
		return 'menu-enhancer';
	}

	public function get_name(): string
	{
		return __('Menu Management Enhancer', 'bizen-toolkit');
	}

	public function get_description(): string
	{
		return __('Expand/collapse menu trees, jump between top-level items, and highlight item groups in Appearance → Menus.', 'bizen-toolkit');
	}

	public function get_source_version(): ?string
	{
		return '2.0';
	}

	public function get_conflicts(): array
	{
		return [
			['file' => 'menu_management_enhancer/wp-mm-enhancer.php', 'name' => 'Menu Management Enhancer'],
		];
	}

	public function boot(): void
	{
		add_action('admin_print_styles-nav-menus.php', [$this, 'enqueue_assets']);
	}

	public function enqueue_assets(): void
	{
		$base = plugin_dir_url(__FILE__);
		$ver  = $this->get_source_version();

		wp_enqueue_style('bizen-menu-enhancer', $base . 'css/bznme.css', [], $ver);
		wp_enqueue_script('bizen-menu-enhancer', $base . 'js/bznme.js', ['jquery'], $ver, true);
	}
};
