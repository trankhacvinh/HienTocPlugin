<?php

defined('ABSPATH') || exit;

final class HTP_Admin
{
    public static function init(): void
    {
        add_action('admin_menu', [self::class, 'register_menu'], 5);
        add_action('admin_enqueue_scripts', [self::class, 'enqueue_assets']);
        add_action('admin_notices', [self::class, 'permalink_notice']);
    }

    public static function register_menu(): void
    {
        add_menu_page(
            'MyHair',
            'MyHair',
            'htp_view_own_salon',
            'htp-dashboard',
            [self::class, 'render_dashboard'],
            'dashicons-heart',
            26
        );
    }

    public static function enqueue_assets(string $hook): void
    {
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
            wp_die('Bạn không có quyền truy cập.');
        }

        $allowed = current_user_can('htp_manage_registrations') ? null : HTP_User_Salon_Service::salon_ids_for_user();
        $counts = (new HTP_Submission_Repository())->dashboard_counts($allowed);
        $salons = (new HTP_Salon_Repository())->counts();
        ?>
        <div class="wrap htp-admin-wrap">
            <h1>Quản lý chương trình MyHair</h1>
            <div class="htp-stat-grid">
                <?php if (current_user_can('htp_manage_salons')) : ?><div class="htp-stat"><span>Tổng salon</span><strong><?php echo esc_html((string) $salons['total']); ?></strong><small><?php echo esc_html((string) $salons['active']); ?> đang hoạt động</small></div><?php endif; ?>
                <div class="htp-stat"><span>Lượt hiến tóc</span><strong><?php echo esc_html((string) $counts['donation']); ?></strong><small><?php echo esc_html((string) $counts['completed']); ?> đã hoàn thành</small></div>
                <div class="htp-stat"><span>Thành viên</span><strong><?php echo esc_html((string) $counts['member']); ?></strong><small><?php echo esc_html((string) $counts['active_members']); ?> đang hoạt động</small></div>
                <div class="htp-stat"><span>Hôm nay</span><strong><?php echo esc_html((string) $counts['today']); ?></strong><small><?php echo esc_html((string) $counts['month']); ?> trong tháng</small></div>
            </div>

            <div class="htp-two-column">
                <section class="htp-panel">
                    <h2>Hướng dẫn vận hành</h2>
                    <ol>
                        <li>Tạo salon và bấm <strong>Tạo trang mặc định</strong>.</li>
                        <li>Chỉnh nội dung trang salon bằng WordPress; giữ shortcode <code>[htp_salon_landing]</code>.</li>
                        <li>Tải QR của salon. QR mở thẳng landing riêng của salon.</li>
                        <li>Khách chuyển tab giữa form hiến tóc và form thành viên.</li>
                        <li>Vào menu tương ứng để xử lý, lọc, phân trang và xuất Excel.</li>
                    </ol>
                </section>
                <section class="htp-panel">
                    <h2>Shortcode</h2>
                    <p><code>[htp_salon_landing]</code> — landing salon kèm hai tab form.</p>
                    <p><code>[htp_donation_form]</code> — chỉ form hiến tóc.</p>
                    <p><code>[htp_member_form]</code> — chỉ form thành viên.</p>
                    <p><code>[htp_registration_lookup]</code> — tra cứu mã.</p>
                </section>
            </div>
        </div>
        <?php
    }

    public static function permalink_notice(): void
    {
        if (!current_user_can('manage_options')) {
            return;
        }
        $structure = (string) get_option('permalink_structure', '');
        if ($structure !== '' && !str_contains($structure, 'index.php')) {
            return;
        }
        $settings_url = admin_url('admin.php?page=htp-settings');
        printf('<div class="notice notice-warning"><p><strong>MyHair:</strong> URL hiện có thể chứa <code>index.php</code>. Vào <a href="%s">Cài đặt MyHair</a> để bật đường dẫn đẹp.</p></div>', esc_url($settings_url));
    }

    public static function notice_from_query(): void
    {
        $message = isset($_GET['htp_message']) ? sanitize_key(wp_unslash($_GET['htp_message'])) : '';
        $messages = [
            'saved' => ['success', 'Đã lưu thay đổi.'],
            'created' => ['success', 'Đã tạo dữ liệu.'],
            'updated' => ['success', 'Đã cập nhật dữ liệu.'],
            'status' => ['success', 'Đã cập nhật trạng thái.'],
            'page_created' => ['success', 'Đã tạo trang landing mặc định cho salon.'],
            'permalink' => ['success', 'Đã cập nhật cấu trúc đường dẫn. Hãy mở thử một trang salon để kiểm tra.'],
            'backup_imported' => ['success', 'Đã khôi phục dữ liệu MyHair từ bản sao lưu.'],
            'backup_deleted' => ['success', 'Đã xóa file backup trên máy chủ.'],
            'google_test_ok' => ['success', 'Kết nối Google Sheets thành công.'],
            'google_queued' => ['success', 'Đã đưa toàn bộ dữ liệu hiện có vào hàng đợi Google Sheets và bắt đầu đồng bộ.'],
            'google_processed' => ['success', 'Đã chạy xử lý hàng đợi Google Sheets ngay bây giờ.'],
            'error' => ['error', 'Không thể thực hiện thao tác. Vui lòng thử lại.'],
        ];
        if (isset($messages[$message])) {
            [$type, $text] = $messages[$message];
            printf('<div class="notice notice-%s is-dismissible"><p>%s</p></div>', esc_attr($type), esc_html($text));
        }
    }
}
