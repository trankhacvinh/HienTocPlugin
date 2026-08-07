<?php
/**
 * Plugin Name: Hiến Tóc Plugin
 * Description: Quản lý landing salon, đăng ký hiến tóc và đăng ký thành viên.
 * Version: 2.0.3
 * Requires at least: 6.4
 * Requires PHP: 8.1
 * Author: HienTocPlugin
 * Text Domain: hien-toc-plugin
 */

defined('ABSPATH') || exit;

define('HTP_VERSION', '2.0.3');
define('HTP_DB_VERSION', '2.0.2');
define('HTP_FILE', __FILE__);
define('HTP_PATH', plugin_dir_path(__FILE__));
define('HTP_URL', plugin_dir_url(__FILE__));

$htp_files = [
    'includes/class-htp-installer.php',
    'includes/class-htp-legacy-migrator.php',
    'includes/class-htp-activity-logger.php',
    'includes/class-htp-user-salon-service.php',
    'includes/class-htp-owner-service.php',
    'includes/class-htp-salon-repository.php',
    'includes/class-htp-form-repository.php',
    'includes/class-htp-submission-repository.php',
    'includes/class-htp-upload-service.php',
    'includes/class-htp-submission-service.php',
    'includes/class-htp-landing-service.php',
    'includes/class-htp-qr-service.php',
    'includes/class-htp-xlsx-exporter.php',
    'public/class-htp-shortcodes.php',
    'admin/class-htp-admin.php',
    'admin/class-htp-salons-page.php',
    'admin/class-htp-forms-page.php',
    'admin/class-htp-submissions-page.php',
    'admin/class-htp-users-page.php',
    'admin/class-htp-reports-page.php',
    'admin/class-htp-activity-page.php',
    'admin/class-htp-settings.php',
];

foreach ($htp_files as $htp_file) {
    require_once HTP_PATH . $htp_file;
}

register_activation_hook(__FILE__, ['HTP_Installer', 'activate']);
register_deactivation_hook(__FILE__, ['HTP_Installer', 'deactivate']);

add_action('plugins_loaded', static function (): void {
    load_plugin_textdomain('hien-toc-plugin', false, dirname(plugin_basename(__FILE__)) . '/languages');
    HTP_Installer::maybe_upgrade();
    HTP_Legacy_Migrator::maybe_migrate();
    HTP_User_Salon_Service::init();
    HTP_Owner_Service::init();
    HTP_Shortcodes::init();

    if (is_admin()) {
        HTP_Admin::init();
        HTP_Salons_Page::init();
        HTP_Forms_Page::init();
        HTP_Submissions_Page::init();
        HTP_Users_Page::init();
        HTP_Reports_Page::init();
        HTP_Activity_Page::init();
        HTP_Settings::init();
    }
});

add_action('wp_enqueue_scripts', static function (): void {
    if (!is_singular()) {
        return;
    }

    global $post;
    if (!$post instanceof WP_Post) {
        return;
    }

    $shortcodes = [
        'htp_salon_landing',
        'htp_donation_form',
        'htp_member_form',
        'htp_registration_form',
        'htp_registration_lookup',
        'htp_salon_list',
        'htp_statistics',
    ];

    foreach ($shortcodes as $shortcode) {
        if (has_shortcode($post->post_content, $shortcode)) {
            wp_enqueue_style('htp-public', HTP_URL . 'assets/css/public.css', [], HTP_VERSION);
            wp_enqueue_script('htp-public', HTP_URL . 'assets/js/public.js', [], HTP_VERSION, true);
            wp_localize_script('htp-public', 'HTPPublic', [
                'submitting' => 'Đang gửi...',
                'chooseImage' => 'Chọn ảnh',
                'removeImage' => 'Xóa ảnh',
            ]);
            break;
        }
    }
});
