<?php

defined('WP_UNINSTALL_PLUGIN') || exit;

global $wpdb;

// Chỉ xóa các trang do plugin tự tạo. Trang người dùng tự chọn sẽ được giữ lại.
$created_page_ids = get_posts([
    'post_type' => 'page',
    'post_status' => 'any',
    'numberposts' => -1,
    'fields' => 'ids',
    'meta_key' => '_htp_created_page',
]);
foreach ($created_page_ids as $page_id) {
    wp_delete_post((int) $page_id, true);
}

$tables = [
    $wpdb->prefix . 'htp_registration_logs',
    $wpdb->prefix . 'htp_user_salons',
    $wpdb->prefix . 'htp_qr_visits',
    $wpdb->prefix . 'htp_activity_logs',
    $wpdb->prefix . 'htp_registrations',
    $wpdb->prefix . 'htp_salons',
];

foreach ($tables as $table) {
    $wpdb->query("DROP TABLE IF EXISTS `{$table}`"); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
}

$options = [
    'htp_db_version',
    'htp_registration_page_id',
    'htp_lookup_page_id',
    'htp_enable_date_of_birth',
    'htp_enable_email',
    'htp_enable_address',
    'htp_enable_customer_note',
    'htp_duplicate_days',
    'htp_privacy_text',
    'htp_success_text',
];

foreach ($options as $option) {
    delete_option($option);
}

$roles = ['administrator', 'htp_program_manager', 'htp_salon_user'];
$capabilities = [
    'htp_manage_salons',
    'htp_manage_registrations',
    'htp_update_registration_status',
    'htp_view_reports',
    'htp_export_data',
    'htp_view_own_salon',
    'htp_manage_settings',
    'htp_manage_users',
    'htp_view_activity',
];

foreach ($roles as $role_name) {
    $role = get_role($role_name);
    if ($role) {
        foreach ($capabilities as $capability) {
            $role->remove_cap($capability);
        }
    }
}

remove_role('htp_program_manager');
remove_role('htp_salon_user');

$wpdb->delete($wpdb->usermeta, ['meta_key' => 'htp_disabled']);
$wpdb->delete($wpdb->usermeta, ['meta_key' => 'htp_last_login']);

// Xóa transient chống spam/lượt truy cập còn sót lại.
$wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_htp_rate_%' OR option_name LIKE '_transient_timeout_htp_rate_%' OR option_name LIKE '_transient_htp_visit_%' OR option_name LIKE '_transient_timeout_htp_visit_%'"); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
