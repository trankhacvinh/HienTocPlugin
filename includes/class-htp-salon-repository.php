<?php

defined('ABSPATH') || exit;

final class HTP_Salon_Repository
{
    public function find_active_by_code(string $code): ?object
    {
        global $wpdb;

        $table = $wpdb->prefix . 'htp_salons';
        $code = strtoupper(trim($code));

        $salon = $wpdb->get_row(
            $wpdb->prepare("SELECT * FROM {$table} WHERE code = %s AND status = 'active' LIMIT 1", $code)
        );

        return $salon ?: null;
    }

    public function all(): array
    {
        global $wpdb;
        $table = $wpdb->prefix . 'htp_salons';

        return $wpdb->get_results("SELECT * FROM {$table} ORDER BY created_at DESC") ?: [];
    }

    public function create(array $data): int
    {
        global $wpdb;
        $table = $wpdb->prefix . 'htp_salons';
        $now = current_time('mysql');

        $wpdb->insert($table, [
            'public_id' => wp_generate_uuid4(),
            'code' => strtoupper(trim((string) $data['code'])),
            'name' => sanitize_text_field((string) $data['name']),
            'address' => sanitize_textarea_field((string) ($data['address'] ?? '')),
            'phone' => sanitize_text_field((string) ($data['phone'] ?? '')),
            'email' => sanitize_email((string) ($data['email'] ?? '')),
            'manager_name' => sanitize_text_field((string) ($data['manager_name'] ?? '')),
            'instruction' => wp_kses_post((string) ($data['instruction'] ?? '')),
            'status' => in_array(($data['status'] ?? 'active'), ['active', 'inactive'], true) ? $data['status'] : 'active',
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return (int) $wpdb->insert_id;
    }
}
