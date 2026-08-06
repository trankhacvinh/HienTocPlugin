<?php

defined('ABSPATH') || exit;

final class HTP_Reports_Page
{
    public static function init(): void
    {
        add_action('admin_menu', [self::class, 'register_page'], 15);
    }

    public static function register_page(): void
    {
        add_submenu_page('htp-dashboard', 'Báo cáo', 'Báo cáo', 'htp_view_reports', 'htp-reports', [self::class, 'render']);
    }

    public static function render(): void
    {
        if (!current_user_can('htp_view_reports')) {
            wp_die('Bạn không có quyền truy cập.');
        }
        global $wpdb;
        $allowed = current_user_can('htp_manage_registrations') ? null : HTP_User_Salon_Service::salon_ids_for_user();
        $filters = [
            'from' => sanitize_text_field(wp_unslash($_GET['from'] ?? '')),
            'to' => sanitize_text_field(wp_unslash($_GET['to'] ?? '')),
        ];
        $rows = (new HTP_Submission_Repository())->report_by_salon($filters, $allowed);
        $visits_table = $wpdb->prefix . 'htp_qr_visits';
        $salons_table = $wpdb->prefix . 'htp_salons';
        $where = [];
        $params = [];
        if ($allowed !== null) {
            $ids = array_values(array_filter(array_map('absint', $allowed)));
            if (!$ids) {
                $where[] = '1=0';
            } else {
                $where[] = 'v.salon_id IN (' . implode(',', array_fill(0, count($ids), '%d')) . ')';
                $params = array_merge($params, $ids);
            }
        }
        foreach (['from' => '>=', 'to' => '<='] as $key => $operator) {
            if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $filters[$key])) {
                $where[] = "DATE(v.visited_at) {$operator} %s";
                $params[] = $filters[$key];
            }
        }
        $sql = "SELECT s.id, COUNT(v.id) visits, SUM(v.device_type='mobile') mobile_visits, SUM(v.converted=1) converted
                FROM {$salons_table} s LEFT JOIN {$visits_table} v ON v.salon_id=s.id";
        if ($where) {
            $sql .= ' WHERE ' . implode(' AND ', $where);
        }
        $sql .= ' GROUP BY s.id';
        $visit_rows = $params ? $wpdb->get_results($wpdb->prepare($sql, ...$params), OBJECT_K) : $wpdb->get_results($sql, OBJECT_K);
        ?>
        <div class="wrap htp-admin-wrap">
            <h1>Báo cáo</h1>
            <form method="get" class="htp-panel htp-filter-form">
                <input type="hidden" name="page" value="htp-reports">
                <label>Từ ngày <input type="date" name="from" value="<?php echo esc_attr($filters['from']); ?>"></label>
                <label>Đến ngày <input type="date" name="to" value="<?php echo esc_attr($filters['to']); ?>"></label>
                <button class="button button-primary">Xem báo cáo</button>
            </form>
            <section class="htp-panel">
                <div class="htp-table-wrap">
                    <table class="widefat striped htp-responsive-table">
                        <thead><tr><th>Salon</th><th>Lượt mở landing</th><th>Mobile</th><th>Hiến tóc</th><th>Hoàn thành</th><th>Thành viên</th><th>Thành viên hoạt động</th><th>Tỷ lệ chuyển đổi</th></tr></thead>
                        <tbody>
                        <?php if (!$rows) : ?><tr><td colspan="8">Không có dữ liệu.</td></tr><?php else : foreach ($rows as $row) :
                            $visit = $visit_rows[$row->id] ?? null;
                            $visits = (int) ($visit->visits ?? 0);
                            $converted = (int) ($visit->converted ?? 0);
                            $rate = $visits > 0 ? round($converted * 100 / $visits, 1) : 0;
                            ?>
                            <tr>
                                <td data-label="Salon"><strong><?php echo esc_html($row->code); ?></strong><br><?php echo esc_html($row->name); ?></td>
                                <td data-label="Lượt mở"><?php echo esc_html(number_format_i18n($visits)); ?></td>
                                <td data-label="Mobile"><?php echo esc_html(number_format_i18n((int) ($visit->mobile_visits ?? 0))); ?></td>
                                <td data-label="Hiến tóc"><?php echo esc_html(number_format_i18n((int) $row->donation_count)); ?></td>
                                <td data-label="Hoàn thành"><?php echo esc_html(number_format_i18n((int) $row->completed_count)); ?></td>
                                <td data-label="Thành viên"><?php echo esc_html(number_format_i18n((int) $row->member_count)); ?></td>
                                <td data-label="Hoạt động"><?php echo esc_html(number_format_i18n((int) $row->active_member_count)); ?></td>
                                <td data-label="Chuyển đổi"><?php echo esc_html($rate . '%'); ?></td>
                            </tr>
                        <?php endforeach; endif; ?>
                        </tbody>
                    </table>
                </div>
            </section>
        </div>
        <?php
    }
}
