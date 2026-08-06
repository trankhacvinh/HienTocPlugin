<?php

defined('ABSPATH') || exit;

final class HTP_Admin
{
    public static function init(): void
    {
        add_action('admin_menu', [self::class, 'register_menu'], 5);
        add_action('admin_enqueue_scripts', [self::class, 'enqueue_assets']);
    }

    public static function register_menu(): void
    {
        add_menu_page(
            'Hiến tóc',
            'Hiến tóc',
            'htp_view_own_salon',
            'htp-dashboard',
            [self::class, 'render_dashboard'],
            'dashicons-heart',
            26
        );
    }

    public static function enqueue_assets(string $hook): void
    {
        if (!str_contains($hook, 'htp-') && !isset($_GET['page'])) {
            return;
        }
        $page = isset($_GET['page']) ? sanitize_key(wp_unslash($_GET['page'])) : '';
        if (!str_starts_with($page, 'htp-')) {
            return;
        }

        wp_enqueue_style('htp-admin', HTP_URL . 'assets/css/admin.css', [], HTP_VERSION);
        wp_enqueue_script('htp-admin', HTP_URL . 'assets/js/admin.js', [], HTP_VERSION, true);
        wp_localize_script('htp-admin', 'HTPAdmin', [
            'copied' => 'Đã sao chép đường dẫn.',
            'confirm' => 'Bạn có chắc muốn thực hiện thao tác này?',
        ]);
    }

    public static function render_dashboard(): void
    {
        if (!current_user_can('htp_view_own_salon')) {
            wp_die(esc_html__('Bạn không có quyền truy cập.', 'hien-toc-plugin'));
        }

        $allowed = current_user_can('htp_manage_registrations') ? null : HTP_User_Salon_Service::salon_ids_for_user();
        $counts = (new HTP_Registration_Repository())->dashboard_counts($allowed);
        $salons = (new HTP_Salon_Repository())->counts();
        $labels = HTP_Registration_Service::status_labels();
        ?>
        <div class="wrap htp-admin-wrap">
            <h1>Quản lý chương trình hiến tóc</h1>
            <div class="htp-stat-grid">
                <?php if (current_user_can('htp_manage_salons')) : ?>
                    <div class="htp-stat"><span>Tổng salon</span><strong><?php echo esc_html((string) $salons['total']); ?></strong><small><?php echo esc_html((string) $salons['active']); ?> đang hoạt động</small></div>
                <?php endif; ?>
                <div class="htp-stat"><span>Tổng đăng ký</span><strong><?php echo esc_html((string) $counts['total']); ?></strong><small><?php echo esc_html((string) $counts['month']); ?> trong tháng</small></div>
                <div class="htp-stat"><span>Hôm nay</span><strong><?php echo esc_html((string) $counts['today']); ?></strong><small>Đăng ký mới trong ngày</small></div>
                <div class="htp-stat"><span>Đã tiếp nhận</span><strong><?php echo esc_html((string) $counts['received']); ?></strong><small><?php echo esc_html((string) $counts['completed']); ?> đã hoàn thành</small></div>
            </div>

            <div class="htp-panel">
                <h2>Phân bố theo trạng thái</h2>
                <div class="htp-status-grid">
                    <?php foreach (HTP_Registration_Service::statuses() as $status) : ?>
                        <a href="<?php echo esc_url(add_query_arg(['page' => 'htp-registrations', 'status' => $status], admin_url('admin.php'))); ?>">
                            <span class="htp-status-badge htp-status-<?php echo esc_attr($status); ?>"><?php echo esc_html($labels[$status]); ?></span>
                            <strong><?php echo esc_html((string) ($counts[$status] ?? 0)); ?></strong>
                        </a>
                    <?php endforeach; ?>
                </div>
            </div>

            <div class="htp-panel">
                <h2>Hướng dẫn nhanh</h2>
                <ol>
                    <li>Tạo hoặc chọn trang WordPress có shortcode <code>[htp_registration_form]</code>.</li>
                    <li>Vào <strong>Hiến tóc → Cài đặt</strong> để chọn trang đăng ký.</li>
                    <li>Tạo salon, sao chép đường dẫn hoặc tải QR.</li>
                    <li>Khách quét QR, kiểm tra thông tin salon và gửi form trên điện thoại.</li>
                </ol>
            </div>
        </div>
        <?php
    }

    public static function notice_from_query(): void
    {
        $message = isset($_GET['htp_message']) ? sanitize_key(wp_unslash($_GET['htp_message'])) : '';
        $messages = [
            'saved' => ['success', 'Đã lưu thay đổi.'],
            'created' => ['success', 'Đã tạo dữ liệu.'],
            'updated' => ['success', 'Đã cập nhật dữ liệu.'],
            'status' => ['success', 'Đã cập nhật trạng thái.'],
            'error' => ['error', 'Không thể thực hiện thao tác. Vui lòng thử lại.'],
        ];
        if (isset($messages[$message])) {
            [$type, $text] = $messages[$message];
            printf('<div class="notice notice-%s is-dismissible"><p>%s</p></div>', esc_attr($type), esc_html($text));
        }
    }
}
