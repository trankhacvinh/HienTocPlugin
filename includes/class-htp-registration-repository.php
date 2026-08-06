<?php

defined('ABSPATH') || exit;

final class HTP_Registration_Repository
{
    private string $table;
    private string $salons_table;
    private string $logs_table;

    public function __construct()
    {
        global $wpdb;
        $this->table = $wpdb->prefix . 'htp_registrations';
        $this->salons_table = $wpdb->prefix . 'htp_salons';
        $this->logs_table = $wpdb->prefix . 'htp_registration_logs';
    }

    public function find(int $id): ?object
    {
        global $wpdb;
        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT r.*, s.code salon_code, s.name salon_name, s.address salon_address, s.phone salon_phone
             FROM {$this->table} r
             INNER JOIN {$this->salons_table} s ON s.id = r.salon_id
             WHERE r.id = %d LIMIT 1",
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
            "SELECT r.*, s.code salon_code, s.name salon_name, s.address salon_address, s.phone salon_phone
             FROM {$this->table} r
             INNER JOIN {$this->salons_table} s ON s.id = r.salon_id
             WHERE r.registration_code = %s AND RIGHT(r.phone_normalized, 4) = %s
             LIMIT 1",
            $code,
            $last4
        ));
        return $row ?: null;
    }

    public function search(array $filters, int $page = 1, int $per_page = 30, ?array $allowed_salon_ids = null): array
    {
        global $wpdb;
        [$where_sql, $params] = $this->build_where($filters, $allowed_salon_ids);
        $offset = max(0, ($page - 1) * $per_page);
        $sql = "SELECT r.*, s.code salon_code, s.name salon_name, u.display_name updated_by_name
                FROM {$this->table} r
                INNER JOIN {$this->salons_table} s ON s.id = r.salon_id
                LEFT JOIN {$wpdb->users} u ON u.ID = r.updated_by
                {$where_sql}
                ORDER BY r.registered_at DESC
                LIMIT %d OFFSET %d";
        $params[] = $per_page;
        $params[] = $offset;

        return $wpdb->get_results($wpdb->prepare($sql, ...$params)) ?: [];
    }

    public function count(array $filters, ?array $allowed_salon_ids = null): int
    {
        global $wpdb;
        [$where_sql, $params] = $this->build_where($filters, $allowed_salon_ids);
        $sql = "SELECT COUNT(*) FROM {$this->table} r {$where_sql}";
        return (int) ($params ? $wpdb->get_var($wpdb->prepare($sql, ...$params)) : $wpdb->get_var($sql));
    }

    public function dashboard_counts(?array $allowed_salon_ids = null): array
    {
        global $wpdb;
        $where = '';
        $params = [];
        if ($allowed_salon_ids !== null) {
            $ids = array_values(array_filter(array_map('absint', $allowed_salon_ids)));
            if (!$ids) {
                return array_fill_keys(array_merge(['total', 'today', 'month'], HTP_Registration_Service::statuses()), 0);
            }
            $where = ' WHERE salon_id IN (' . implode(',', array_fill(0, count($ids), '%d')) . ')';
            $params = $ids;
        }

        $sql = "SELECT
                    COUNT(*) total,
                    SUM(status='new') new,
                    SUM(status='confirmed') confirmed,
                    SUM(status='received') received,
                    SUM(status='completed') completed,
                    SUM(status='rejected') rejected,
                    SUM(status='cancelled') cancelled,
                    SUM(status='duplicate') duplicate,
                    SUM(DATE(registered_at)=CURDATE()) today,
                    SUM(YEAR(registered_at)=YEAR(CURDATE()) AND MONTH(registered_at)=MONTH(CURDATE())) month
                FROM {$this->table}{$where}";
        $row = $params ? $wpdb->get_row($wpdb->prepare($sql, ...$params), ARRAY_A) : $wpdb->get_row($sql, ARRAY_A);
        $keys = ['total', 'new', 'confirmed', 'received', 'completed', 'rejected', 'cancelled', 'duplicate', 'today', 'month'];
        $result = [];
        foreach ($keys as $key) {
            $result[$key] = (int) ($row[$key] ?? 0);
        }
        return $result;
    }

    public function status_logs(int $registration_id): array
    {
        global $wpdb;
        return $wpdb->get_results($wpdb->prepare(
            "SELECT l.*, u.display_name
             FROM {$this->logs_table} l
             LEFT JOIN {$wpdb->users} u ON u.ID = l.changed_by
             WHERE l.registration_id = %d
             ORDER BY l.changed_at DESC, l.id DESC",
            $registration_id
        )) ?: [];
    }

    public function report_by_salon(array $filters = [], ?array $allowed_salon_ids = null): array
    {
        global $wpdb;
        [$where_sql, $params] = $this->build_where($filters, $allowed_salon_ids);
        $sql = "SELECT s.id, s.code, s.name,
                       COUNT(r.id) total,
                       SUM(r.status='new') new_count,
                       SUM(r.status='confirmed') confirmed_count,
                       SUM(r.status='received') received_count,
                       SUM(r.status='completed') completed_count,
                       SUM(r.status='rejected') rejected_count,
                       SUM(r.status='cancelled') cancelled_count,
                       SUM(r.status='duplicate') duplicate_count
                FROM {$this->salons_table} s
                LEFT JOIN {$this->table} r ON r.salon_id = s.id
                {$where_sql}
                GROUP BY s.id, s.code, s.name
                ORDER BY total DESC, s.name ASC";
        return $params ? ($wpdb->get_results($wpdb->prepare($sql, ...$params)) ?: []) : ($wpdb->get_results($sql) ?: []);
    }

    public function recent_duplicates(string $phone_normalized, int $salon_id, int $days): array
    {
        global $wpdb;
        $days = max(1, min(365, $days));
        return $wpdb->get_results($wpdb->prepare(
            "SELECT id, registration_code, registered_at, status
             FROM {$this->table}
             WHERE phone_normalized = %s AND salon_id = %d
               AND registered_at >= DATE_SUB(NOW(), INTERVAL %d DAY)
             ORDER BY registered_at DESC LIMIT 5",
            $phone_normalized,
            $salon_id,
            $days
        )) ?: [];
    }

    public function export_rows(array $filters, ?array $allowed_salon_ids = null): array
    {
        global $wpdb;
        [$where_sql, $params] = $this->build_where($filters, $allowed_salon_ids);
        $sql = "SELECT r.*, s.code salon_code, s.name salon_name
                FROM {$this->table} r
                INNER JOIN {$this->salons_table} s ON s.id = r.salon_id
                {$where_sql}
                ORDER BY r.registered_at DESC";
        return $params ? ($wpdb->get_results($wpdb->prepare($sql, ...$params), ARRAY_A) ?: []) : ($wpdb->get_results($sql, ARRAY_A) ?: []);
    }

    private function build_where(array $filters, ?array $allowed_salon_ids): array
    {
        $where = [];
        $params = [];

        if ($allowed_salon_ids !== null) {
            $ids = array_values(array_filter(array_map('absint', $allowed_salon_ids)));
            if (!$ids) {
                return [' WHERE 1=0', []];
            }
            $where[] = 'r.salon_id IN (' . implode(',', array_fill(0, count($ids), '%d')) . ')';
            $params = array_merge($params, $ids);
        }

        $salon_id = absint($filters['salon_id'] ?? 0);
        if ($salon_id) {
            $where[] = 'r.salon_id = %d';
            $params[] = $salon_id;
        }

        $status = sanitize_key((string) ($filters['status'] ?? ''));
        if (in_array($status, HTP_Registration_Service::statuses(), true)) {
            $where[] = 'r.status = %s';
            $params[] = $status;
        }

        $search = sanitize_text_field((string) ($filters['s'] ?? ''));
        if ($search !== '') {
            global $wpdb;
            $like = '%' . $wpdb->esc_like($search) . '%';
            $where[] = '(r.registration_code LIKE %s OR r.full_name LIKE %s OR r.phone LIKE %s OR r.phone_normalized LIKE %s)';
            array_push($params, $like, $like, $like, $like);
        }

        foreach (['from' => '>=', 'to' => '<='] as $key => $operator) {
            $date = sanitize_text_field((string) ($filters[$key] ?? ''));
            if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
                $where[] = "DATE(r.registered_at) {$operator} %s";
                $params[] = $date;
            }
        }

        return [$where ? ' WHERE ' . implode(' AND ', $where) : '', $params];
    }
}
