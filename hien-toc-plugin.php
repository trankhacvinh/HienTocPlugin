<?php
/**
 * Plugin Name: Hiến Tóc Plugin
 * Description: Quản lý salon, QR và đăng ký hiến tóc theo salon.
 * Version: 0.1.0
 * Requires at least: 6.4
 * Requires PHP: 8.1
 * Author: HienTocPlugin
 * Text Domain: hien-toc-plugin
 */

defined('ABSPATH') || exit;

define('HTP_VERSION', '0.1.0');
define('HTP_FILE', __FILE__);
define('HTP_PATH', plugin_dir_path(__FILE__));
define('HTP_URL', plugin_dir_url(__FILE__));

require_once HTP_PATH . 'includes/class-htp-installer.php';
require_once HTP_PATH . 'includes/class-htp-salon-repository.php';
require_once HTP_PATH . 'includes/class-htp-registration-service.php';
require_once HTP_PATH . 'public/class-htp-shortcodes.php';
require_once HTP_PATH . 'admin/class-htp-admin.php';

register_activation_hook(__FILE__, ['HTP_Installer', 'activate']);

add_action('plugins_loaded', static function (): void {
    load_plugin_textdomain('hien-toc-plugin', false, dirname(plugin_basename(__FILE__)) . '/languages');

    HTP_Shortcodes::init();

    if (is_admin()) {
        HTP_Admin::init();
    }
});

add_action('wp_enqueue_scripts', static function (): void {
    if (!is_singular()) {
        return;
    }

    global $post;
    if (!$post instanceof WP_Post || !has_shortcode($post->post_content, 'htp_registration_form')) {
        return;
    }

    wp_enqueue_style('htp-public', HTP_URL . 'assets/css/public.css', [], HTP_VERSION);
});
