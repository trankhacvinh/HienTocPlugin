<?php

defined('ABSPATH') || exit;

final class HTP_Submission_Repository
{
    private string $table;
    private string $salons_table;
    private string $forms_table;
    private string $values_table;
    private string $files_table;
    private string $logs_table;

    public function __construct()
    {
        global $wpdb;
        $this->table = $wpdb->prefix . 'htp_submissions';
        $this->salons_table = $wpdb->prefix . 'htp_salons';
        $this->forms_table = $wpdb->prefix . 'htp_forms';
        $this->values_table = $wpdb->prefix . 'htp_submission_values';
        $this->files_table = $wpdb->prefix . 'htp_submission_files';
        $this->logs_table = $wpdb->prefix . 'htp_submission_logs';
    }

    public function find(int $id): ?object
    {
        global $wpdb;
        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT x.*, s.code salon_code, s.name salon_name, s.address salon_address, s.phone salon_phone,
                    f.name form_name
             FROM {$this->table} x
             INNER JOIN {$this->salons_table} s ON s.id = x.salon_id
             INNER JOIN {$this->forms_table} f ON f.id = x.form_id
             WHERE x.id = %d LIMIT 1",
            $id
        ));
        return $row ?: null;
    }

    public function find_by_code_and_phone_last4(string $code, string $last4): ?object
    {
        global $wpdb;
        $code = strtoupper(trim($code));
        $last4 = preg_replace('/\D+/', '', $last4) ?: '';
        if ($code === '' || strlen($last4) !== 4) {
            return null;
        }

        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT x.*, s.code salon_code, s.name salon_name, s.address salon_address, s.phone salon_phone,
                    f.name form_name
             FROM {$this->table} x
             INNER JOIN {$this->salons_table} s ON s.id = x.salon_id
             INNER JOIN {$this->forms_table} f ON f.id = x.form_id
             WHERE x.submission_code = %s AND RIGHT(x.phone_normalized, 4) = %s
             LIMIT 1",
            $code,
            $last4
        ));
        return $row ?: null;
    }

    public function find_member_by_phone_and_salon(string $phone_normalized, int $salon_id): ?object
    {
        global $wpdb;
        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$this->table}
             WHERE form_key = 'member' AND salon_id = %d AND phone_normalized = %s
               AND status <> 'duplicate'
             ORDER BY created_at DESC LIMIT 1",
            $salon_id,
            $phone_normalized
        ));
        return $row ?: null;
    }

    public function search(array $filters, int $page = 1, int $per_page = 30, ?array $allowed_salon_ids = null): array
    {
        global $wpdb;
        [$where_sql, $params] = $this->build_where($filters, $allowed_salon_ids);
        $offset = max(0, ($page - 1) * $per_page);
        $sql = "SELECT x.*, s.code salon_code, s.name salon_name, f.name form_name, u.display_name updated_by_name
                FROM {$this->table} x
                INNER JOIN {$this->salons_table} s ON s.id = x.salon_id
                INNER JOIN {$this->forms_table} f ON f.id = x.form_id
                LEFT JOIN {$wpdb->users} u ON u.ID = x.updated_by
                {$where_sql}
                ORDER BY x.created_at DESC
                LIMIT %d OFFSET %d";
        $params[] = $per_page;
        $params[] = $offset;
        return $wpdb->get_results($wpdb->prepare($sql, ...$params)) ?: [];
    }

    public function count(array $filters, ?array $allowed_salon_ids = null): int
    {
        global $wpdb;
        [$where_sql, $params] = $this->build_where($filters, $allowed_salon_ids);
        $sql = "SELECT COUNT(*) FROM {$this->table} x {$where_sql}";
        return (int) ($params ? $wpdb->get_var($wpdb->prepare($sql, ...$params)) : $wpdb->get_var($sql));
    }

    public function values(int $submission_id): array
    {
        global $wpdb;
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT field_key, field_value FROM {$this->values_table} WHERE submission_id = %d",
            $submission_id
        ), ARRAY_A) ?: [];
        $result = [];
        foreach ($rows as $row) {
            $decoded = json_decode((string) $row['field_value'], true);
            $result[$row['field_key']] = json_last_error() === JSON_ERROR_NONE ? $decoded : $row['field_value'];
        }
        return $result;
    }

    public function files(int $submission_id): array
    {
        global $wpdb;
        return $wpdb->get_results($wpdb->prepare(
            "SELECT sf.*, p.post_title, p.guid
             FROM {$this->files_table} sf
             LEFT JOIN {$wpdb->posts} p ON p.ID = sf.attachment_id
             WHERE sf.submission_id = %d
             ORDER BY sf.field_key, sf.sort_order, sf.id",
            $submission_id
        )) ?: [];
    }

    public function status_logs(int $submission_id): array
    {
        global $wpdb;
        return $wpdb->get_results($wpdb->prepare(
            "SELECT l.*, u.display_name
             FROM {$this->logs_table} l
             LEFT JOIN {$wpdb->users} u ON u.ID = l.changed_by
             WHERE l.submission_id = %d
             ORDER BY l.changed_at DESC, l.id DESC",
            $submission_id
        )) ?: [];
    }

    public function dashboard_counts(?array $allowed_salon_ids = null): array
    {
        global $wpdb;
        $where = '';
        $params = [];
        if ($allowed_salon_ids !== null) {
            $ids = array_values(array_filter(array_map('absint', $allowed_salon_ids)));
            if (!$ids) {
                return ['total' => 0, 'donation' => 0, 'member' => 0, 'today' => 0, 'month' => 0, 'completed' => 0, 'active_members' => 0];
            }
            $where = ' WHERE salon_id IN (' . implode(',', array_fill(0, count($ids), '%d')) . ')';
            $params = $ids;
        }

        $sql = "SELECT COUNT(*) total,
                       SUM(form_key='donation') donation,
                       SUM(form_key='member') member,
                       SUM(DATE(created_at)=CURDATE()) today,
                       SUM(YEAR(created_at)=YEAR(CURDATE()) AND MONTH(created_at)=MONTH(CURDATE())) month,
                       SUM(form_key='donation' AND status='completed') completed,
                       SUM(form_key='member' AND status='active') active_members
                FROM {$this->table}{$where}";
        $row = $params ? $wpdb->get_row($wpdb->prepare($sql, ...$params), ARRAY_A) : $wpdb->get_row($sql, ARRAY_A);
        $keys = ['total', 'donation', 'member', 'today', 'month', 'completed', 'active_members'];
        $result = [];
        foreach ($keys as $key) {
            $result[$key] = (int) ($row[$key] ?? 0);
        }
        return $result;
    }

    public function report_by_salon(array $filters = [], ?array $allowed_salon_ids = null): array
    {
        global $wpdb;
        [$where_sql, $params] = $this->build_where($filters, $allowed_salon_ids);
        $sql = "SELECT s.id, s.code, s.name,
                       COUNT(x.id) total,
                       SUM(x.form_key='donation') donation_count,
                       SUM(x.form_key='member') member_count,
                       SUM(x.form_key='donation' AND x.status='completed') completed_count,
                       SUM(x.form_key='member' AND x.status='active') active_member_count
                FROM {$this->salons_table} s
                LEFT JOIN {$this->table} x ON x.salon_id = s.id
                {$where_sql}
                GROUP BY s.id, s.code, s.name
                ORDER BY total DESC, s.name ASC";
        return $params ? ($wpdb->get_results($wpdb->prepare($sql, ...$params)) ?: []) : ($wpdb->get_results($sql) ?: []);
    }

    public function export_rows(array $filters, ?array $allowed_salon_ids = null): array
    {
        global $wpdb;
        [$where_sql, $params] = $this->build_where($filters, $allowed_salon_ids);
        $sql = "SELECT x.*, s.code salon_code, s.name salon_name, f.name form_name
                FROM {$this->table} x
                INNER JOIN {$this->salons_table} s ON s.id = x.salon_id
                INNER JOIN {$this->forms_table} f ON f.id = x.form_id
                {$where_sql}
                ORDER BY x.created_at DESC";
        return $params ? ($wpdb->get_results($wpdb->prepare($sql, ...$params), ARRAY_A) ?: []) : ($wpdb->get_results($sql, ARRAY_A) ?: []);
    }

    public function bulk_values(array $submission_ids): array
    {
        global $wpdb;
        $ids = array_values(array_filter(array_map('absint', $submission_ids)));
        if (!$ids) {
            return [];
        }
        $placeholders = implode(',', array_fill(0, count($ids), '%d'));
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT submission_id, field_key, field_value FROM {$this->values_table} WHERE submission_id IN ({$placeholders})",
            ...$ids
        ), ARRAY_A) ?: [];
        $result = [];
        foreach ($rows as $row) {
            $decoded = json_decode((string) $row['field_value'], true);
            $result[(int) $row['submission_id']][$row['field_key']] = json_last_error() === JSON_ERROR_NONE ? $decoded : $row['field_value'];
        }
        return $result;
    }

    public function bulk_files(array $submission_ids): array
    {
        global $wpdb;
        $ids = array_values(array_filter(array_map('absint', $submission_ids)));
        if (!$ids) {
            return [];
        }
        $placeholders = implode(',', array_fill(0, count($ids), '%d'));
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT submission_id, field_key, attachment_id FROM {$this->files_table} WHERE submission_id IN ({$placeholders}) ORDER BY sort_order, id",
            ...$ids
        ), ARRAY_A) ?: [];
        $result = [];
        foreach ($rows as $row) {
            $url = wp_get_attachment_url((int) $row['attachment_id']);
            if ($url) {
                $result[(int) $row['submission_id']][$row['field_key']][] = $url;
            }
        }
        return $result;
    }

    private function build_where(array $filters, ?array $allowed_salon_ids): array
    {
        global $wpdb;
        $where = [];
        $params = [];

        if ($allowed_salon_ids !== null) {
            $ids = array_values(array_filter(array_map('absint', $allowed_salon_ids)));
            if (!$ids) {
                return [' WHERE 1=0', []];
            }
            $where[] = 'x.salon_id IN (' . implode(',', array_fill(0, count($ids), '%d')) . ')';
            $params = array_merge($params, $ids);
        }

        $form_key = sanitize_key((string) ($filters['form_key'] ?? ''));
        if (in_array($form_key, ['donation', 'member'], true)) {
            $where[] = 'x.form_key = %s';
            $params[] = $form_key;
        }

        $salon_id = absint($filters['salon_id'] ?? 0);
        if ($salon_id) {
            $where[] = 'x.salon_id = %d';
            $params[] = $salon_id;
        }

        $status = sanitize_key((string) ($filters['status'] ?? ''));
        if ($status !== '') {
            $where[] = 'x.status = %s';
            $params[] = $status;
        }

        $search = sanitize_text_field((string) ($filters['s'] ?? ''));
        if ($search !== '') {
            $like = '%' . $wpdb->esc_like($search) . '%';
            $where[] = '(x.submission_code LIKE %s OR x.full_name LIKE %s OR x.phone LIKE %s OR x.phone_normalized LIKE %s OR x.email LIKE %s)';
            array_push($params, $like, $like, $like, $like, $like);
        }

        foreach (['from' => '>=', 'to' => '<='] as $key => $operator) {
            $date = sanitize_text_field((string) ($filters[$key] ?? ''));
            if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
                $where[] = "DATE(x.created_at) {$operator} %s";
                $params[] = $date;
            }
        }

        return [$where ? ' WHERE ' . implode(' AND ', $where) : '', $params];
    }
}
