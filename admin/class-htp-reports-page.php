<?php

defined('ABSPATH') || exit;

final class HTP_Reports_Page
{
    public static function init(): void
    {
        add_action('admin_menu', [self::class, 'register_page'], 13);
    }

    public static function register_page(): void
    {
        add_submenu_page(
            'htp-dashboard',
            'Báo cáo',
            'Báo cáo',
            'htp_view_reports',
            'htp-reports',
            [self::class, 'render']
        );
    }

    public static function render(): void
    {
        if (!current_user_can('htp_view_reports')) {
            wp_die(esc_html__('Bạn không có quyền truy cập.', 'hien-toc-plugin'));
        }

        $filters = [
            'from' => isset($_GET['from']) ? sanitize_text_field(wp_unslash($_GET['from'])) : '',
            'to' => isset($_GET['to']) ? sanitize_text_field(wp_unslash($_GET['to'])) : '',
            'status' => '',
            's' => '',
            'salon_id' => 0,
        ];
        $allowed = current_user_can('htp_manage_registrations') ? null : HTP_User_Salon_Service::salon_ids_for_user();
        $rows = (new HTP_Registration_Repository())->report_by_salon($filters, $allowed);

        global $wpdb;
        $visits_table = $wpdb->prefix . 'htp_qr_visits';
        $visit_where = [];
        $visit_params = [];
        if ($filters['from'] && preg_match('/^\d{4}-\d{2}-\d{2}$/', $filters['from'])) {
            $visit_where[] = 'DATE(visited_at) >= %s';
            $visit_params[] = $filters['from'];
        }
        if ($filters['to'] && preg_match('/^\d{4}-\d{2}-\d{2}$/', $filters['to'])) {
            $visit_where[] = 'DATE(visited_at) <= %s';
            $visit_params[] = $filters['to'];
        }
        if ($allowed !== null) {
            $ids = array_values(array_filter(array_map('absint', $allowed)));
            if ($ids) {
                $visit_where[] = 'salon_id IN (' . implode(',', array_fill(0, count($ids), '%d')) . ')';
                $visit_params = array_merge($visit_params, $ids);
            } else {
                $visit_where[] = '1=0';
            }
        }
        $visit_sql = "SELECT COUNT(*) total, SUM(converted=1) converted, SUM(device_type='mobile') mobile FROM {$visits_table}";
        if ($visit_where) {
            $visit_sql .= ' WHERE ' . implode(' AND ', $visit_where);
        }
        $visit_stats = $visit_params
            ? $wpdb->get_row($wpdb->prepare($visit_sql, ...$visit_params), ARRAY_A)
            : $wpdb->get_row($visit_sql, ARRAY_A);
        $total_visits = (int) ($visit_stats['total'] ?? 0);
        $converted = (int) ($visit_stats['converted'] ?? 0);
        $conversion_rate = $total_visits > 0 ? round(($converted / $total_visits) * 100, 1) : 0;
        ?>
        <div class="wrap htp-admin-wrap">
            <h1>Báo cáo</h1>
            <section class="htp-panel">
                <form method="get" class="htp-filter-form htp-filter-form--compact">
                    <input type="hidden" name="page" value="htp-reports">
                    <label><span>Từ ngày</span><input type="date" name="from" value="<?php echo esc_attr($filters['from']); ?>"></label>
                    <label><span>Đến ngày</span><input type="date" name="to" value="<?php echo esc_attr($filters['to']); ?>"></label>
                    <div class="htp-filter-actions"><button class="button button-primary">Xem báo cáo</button><a class="button" href="<?php echo esc_url(admin_url('admin.php?page=htp-reports')); ?>">Xóa lọc</a></div>
                </form>
            </section>

            <div class="htp-stat-grid">
                <div class="htp-stat"><span>Lượt mở QR/form</span><strong><?php echo esc_html((string) $total_visits); ?></strong><small><?php echo esc_html((string) ((int) ($visit_stats['mobile'] ?? 0))); ?> từ thiết bị di động</small></div>
                <div class="htp-stat"><span>Chuyển thành đăng ký</span><strong><?php echo esc_html((string) $converted); ?></strong><small>Tỷ lệ <?php echo esc_html((string) $conversion_rate); ?>%</small></div>
                <div class="htp-stat"><span>Tổng đăng ký</span><strong><?php echo esc_html((string) array_sum(array_map(static fn($r) => (int) $r->total, $rows))); ?></strong><small>Theo khoảng thời gian đã chọn</small></div>
                <div class="htp-stat"><span>Đã hoàn thành</span><strong><?php echo esc_html((string) array_sum(array_map(static fn($r) => (int) $r->completed_count, $rows))); ?></strong><small>Hoàn tất quy trình</small></div>
            </div>

            <section class="htp-panel">
                <h2>Kết quả theo salon</h2>
                <div class="htp-table-wrap"><table class="widefat striped htp-responsive-table">
                    <thead><tr><th>Salon</th><th>Tổng</th><th>Mới</th><th>Xác nhận</th><th>Tiếp nhận</th><th>Hoàn thành</th><th>Không đạt</th><th>Hủy</th><th>Trùng</th><th>Tỷ lệ hoàn thành</th></tr></thead>
                    <tbody>
                    <?php if (!$rows) : ?><tr><td colspan="10">Chưa có dữ liệu.</td></tr><?php endif; ?>
                    <?php foreach ($rows as $row) :
                        $rate = (int) $row->total > 0 ? round(((int) $row->completed_count / (int) $row->total) * 100, 1) : 0;
                        ?>
                        <tr>
                            <td data-label="Salon"><strong><?php echo esc_html($row->code); ?></strong><br><small><?php echo esc_html($row->name); ?></small></td>
                            <td data-label="Tổng"><?php echo esc_html((string) $row->total); ?></td>
                            <td data-label="Mới"><?php echo esc_html((string) $row->new_count); ?></td>
                            <td data-label="Xác nhận"><?php echo esc_html((string) $row->confirmed_count); ?></td>
                            <td data-label="Tiếp nhận"><?php echo esc_html((string) $row->received_count); ?></td>
                            <td data-label="Hoàn thành"><?php echo esc_html((string) $row->completed_count); ?></td>
                            <td data-label="Không đạt"><?php echo esc_html((string) $row->rejected_count); ?></td>
                            <td data-label="Hủy"><?php echo esc_html((string) $row->cancelled_count); ?></td>
                            <td data-label="Trùng"><?php echo esc_html((string) $row->duplicate_count); ?></td>
                            <td data-label="Tỷ lệ"><?php echo esc_html((string) $rate); ?>%</td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table></div>
            </section>
        </div>
        <?php
    }
}
