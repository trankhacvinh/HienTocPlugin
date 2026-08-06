<?php

defined('ABSPATH') || exit;

final class HTP_Activity_Page
{
    public static function init(): void
    {
        add_action('admin_menu', [self::class, 'register_page'], 14);
    }

    public static function register_page(): void
    {
        add_submenu_page(
            'htp-dashboard',
            'Nhật ký hoạt động',
            'Nhật ký hoạt động',
            'htp_view_activity',
            'htp-activity',
            [self::class, 'render']
        );
    }

    public static function render(): void
    {
        if (!current_user_can('htp_view_activity')) {
            wp_die(esc_html__('Bạn không có quyền truy cập.', 'hien-toc-plugin'));
        }
        $rows = HTP_Activity_Logger::recent(200);
        $labels = [
            'salon_created' => 'Tạo salon',
            'salon_updated' => 'Sửa salon',
            'salon_status_updated' => 'Đổi trạng thái salon',
            'registration_created' => 'Tạo đăng ký public',
            'registration_updated' => 'Sửa đăng ký',
            'registration_status_updated' => 'Đổi trạng thái đăng ký',
            'registrations_exported' => 'Xuất dữ liệu',
            'user_created' => 'Tạo tài khoản',
            'user_updated' => 'Sửa tài khoản',
            'user_status_updated' => 'Khóa/mở tài khoản',
            'settings_updated' => 'Cập nhật cài đặt',
        ];
        ?>
        <div class="wrap htp-admin-wrap">
            <h1>Nhật ký hoạt động</h1>
            <section class="htp-panel">
                <p>Hiển thị 200 thao tác gần nhất của plugin.</p>
                <div class="htp-table-wrap"><table class="widefat striped htp-responsive-table">
                    <thead><tr><th>Thời gian</th><th>Tài khoản</th><th>Hành động</th><th>Đối tượng</th><th>Chi tiết</th><th>IP</th></tr></thead>
                    <tbody>
                    <?php if (!$rows) : ?><tr><td colspan="6">Chưa có nhật ký.</td></tr><?php endif; ?>
                    <?php foreach ($rows as $row) : ?>
                        <tr>
                            <td data-label="Thời gian"><?php echo esc_html(mysql2date('d/m/Y H:i:s', $row->created_at)); ?></td>
                            <td data-label="Tài khoản"><?php echo esc_html($row->display_name ?: ($row->user_id ? 'User #' . $row->user_id : 'Khách public')); ?></td>
                            <td data-label="Hành động"><?php echo esc_html($labels[$row->action] ?? $row->action); ?></td>
                            <td data-label="Đối tượng"><?php echo esc_html(($row->entity_type ?: '—') . ($row->entity_id ? ' #' . $row->entity_id : '')); ?></td>
                            <td data-label="Chi tiết"><code><?php echo esc_html($row->details ?: '—'); ?></code></td>
                            <td data-label="IP"><?php echo esc_html($row->ip_address ?: '—'); ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table></div>
            </section>
        </div>
        <?php
    }
}
