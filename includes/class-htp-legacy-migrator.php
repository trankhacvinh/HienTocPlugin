<?php

defined('ABSPATH') || exit;

final class HTP_Legacy_Migrator
{
    public static function maybe_migrate(): void
    {
        global $wpdb;

        if (get_option('htp_legacy_migrated_v2')) {
            return;
        }

        $legacy_table = $wpdb->prefix . 'htp_registrations';
        $legacy_logs = $wpdb->prefix . 'htp_registration_logs';
        if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $legacy_table)) !== $legacy_table) {
            update_option('htp_legacy_migrated_v2', 1);
            return;
        }

        $forms_table = $wpdb->prefix . 'htp_forms';
        $submissions_table = $wpdb->prefix . 'htp_submissions';
        $values_table = $wpdb->prefix . 'htp_submission_values';
        $logs_table = $wpdb->prefix . 'htp_submission_logs';
        $form_id = (int) $wpdb->get_var("SELECT id FROM {$forms_table} WHERE form_key = 'donation' LIMIT 1");
        if (!$form_id) {
            return;
        }

        $rows = $wpdb->get_results("SELECT * FROM {$legacy_table} ORDER BY id ASC", ARRAY_A) ?: [];
        $valid_statuses = ['new', 'confirmed', 'received', 'completed', 'rejected', 'cancelled', 'duplicate'];
        $has_legacy_logs = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $legacy_logs)) === $legacy_logs;

        foreach ($rows as $row) {
            $public_id = (string) ($row['public_id'] ?? '');
            if ($public_id !== '' && (int) $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM {$submissions_table} WHERE public_id = %s",
                $public_id
            )) > 0) {
                continue;
            }

            $status = in_array(($row['status'] ?? 'new'), $valid_statuses, true) ? $row['status'] : 'new';
            $created_at = (string) ($row['created_at'] ?? $row['registered_at'] ?? current_time('mysql'));
            $updated_at = (string) ($row['updated_at'] ?? $created_at);
            $inserted = $wpdb->insert($submissions_table, [
                'public_id' => $public_id !== '' ? $public_id : wp_generate_uuid4(),
                'salon_id' => (int) ($row['salon_id'] ?? 0),
                'form_id' => $form_id,
                'form_key' => 'donation',
                'submission_code' => !empty($row['registration_code']) ? (string) $row['registration_code'] : null,
                'full_name' => (string) ($row['full_name'] ?? ''),
                'phone' => (string) ($row['phone'] ?? ''),
                'phone_normalized' => (string) ($row['phone_normalized'] ?? ''),
                'email' => (string) ($row['email'] ?? ''),
                'date_of_birth' => !empty($row['date_of_birth']) ? (string) $row['date_of_birth'] : null,
                'status' => $status,
                'consent_at' => (string) ($row['consent_at'] ?? $created_at),
                'source_url' => (string) ($row['source_url'] ?? ''),
                'created_by' => !empty($row['created_by']) ? (int) $row['created_by'] : null,
                'updated_by' => !empty($row['updated_by']) ? (int) $row['updated_by'] : null,
                'created_at' => $created_at,
                'updated_at' => $updated_at,
            ]);
            if ($inserted === false) {
                continue;
            }

            $submission_id = (int) $wpdb->insert_id;
            if (empty($row['registration_code'])) {
                $salon_code = (string) $wpdb->get_var($wpdb->prepare(
                    "SELECT code FROM {$wpdb->prefix}htp_salons WHERE id = %d",
                    (int) ($row['salon_id'] ?? 0)
                ));
                $wpdb->update($submissions_table, [
                    'submission_code' => sprintf('%s-D-%06d', $salon_code ?: 'DONATION', $submission_id),
                ], ['id' => $submission_id]);
            }

            foreach (['address', 'customer_note', 'internal_note'] as $field_key) {
                if (!empty($row[$field_key])) {
                    $wpdb->replace($values_table, [
                        'submission_id' => $submission_id,
                        'field_key' => $field_key,
                        'field_value' => (string) $row[$field_key],
                    ]);
                }
            }

            if ($has_legacy_logs) {
                $legacy_log_rows = $wpdb->get_results($wpdb->prepare(
                    "SELECT * FROM {$legacy_logs} WHERE registration_id = %d ORDER BY id ASC",
                    (int) $row['id']
                ), ARRAY_A) ?: [];
                foreach ($legacy_log_rows as $legacy_log) {
                    $wpdb->insert($logs_table, [
                        'submission_id' => $submission_id,
                        'old_status' => $legacy_log['old_status'] ?: null,
                        'new_status' => (string) ($legacy_log['new_status'] ?? $status),
                        'note' => (string) ($legacy_log['note'] ?? ''),
                        'changed_by' => !empty($legacy_log['changed_by']) ? (int) $legacy_log['changed_by'] : null,
                        'changed_at' => (string) ($legacy_log['changed_at'] ?? $created_at),
                    ]);
                }
            }

            $has_new_log = (int) $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM {$logs_table} WHERE submission_id = %d",
                $submission_id
            ));
            if (!$has_new_log) {
                $wpdb->insert($logs_table, [
                    'submission_id' => $submission_id,
                    'old_status' => null,
                    'new_status' => $status,
                    'note' => 'Chuyển từ dữ liệu plugin phiên bản 1.',
                    'changed_by' => null,
                    'changed_at' => $created_at,
                ]);
            }
        }

        update_option('htp_legacy_migrated_v2', 1);
    }
}
