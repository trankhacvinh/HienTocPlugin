<?php

defined('ABSPATH') || exit;

final class HTP_Salon_Repository
{
    private string $table;

    public function __construct()
    {
        global $wpdb;
        $this->table = $wpdb->prefix . 'htp_salons';
    }

    public function find_by_id(int $id): ?object
    {
        global $wpdb;
        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT s.*, u.display_name owner_name, u.user_email owner_email
             FROM {$this->table} s
             LEFT JOIN {$wpdb->users} u ON u.ID = s.owner_user_id
             WHERE s.id = %d LIMIT 1",
            $id
        ));
        return $row ?: null;
    }

    public function find_by_code(string $code, bool $active_only = false): ?object
    {
        global $wpdb;
        $code = strtoupper(trim($code));
        $sql = "SELECT s.*, u.display_name owner_name, u.user_email owner_email
                FROM {$this->table} s
                LEFT JOIN {$wpdb->users} u ON u.ID = s.owner_user_id
                WHERE s.code = %s";
        if ($active_only) {
            $sql .= " AND s.status = 'active'";
        }
        $sql .= ' LIMIT 1';
        $row = $wpdb->get_row($wpdb->prepare($sql, $code));
        return $row ?: null;
    }

    public function find_active_by_code(string $code): ?object
    {
        return $this->find_by_code($code, true);
    }

    public function all(array $ids = [], bool $active_only = false): array
    {
        global $wpdb;
        $where = [];
        $params = [];

        if ($ids) {
            $ids = array_values(array_filter(array_map('absint', $ids)));
            if (!$ids) {
                return [];
            }
            $where[] = 's.id IN (' . implode(',', array_fill(0, count($ids), '%d')) . ')';
            $params = array_merge($params, $ids);
        }
        if ($active_only) {
            $where[] = "s.status = 'active'";
        }

        $sql = "SELECT s.*, u.display_name owner_name, u.user_email owner_email
                FROM {$this->table} s
                LEFT JOIN {$wpdb->users} u ON u.ID = s.owner_user_id";
        if ($where) {
            $sql .= ' WHERE ' . implode(' AND ', $where);
        }
        $sql .= ' ORDER BY s.name ASC';
        if ($params) {
            $sql = $wpdb->prepare($sql, ...$params);
        }
        return $wpdb->get_results($sql) ?: [];
    }

    public function create(array $data): int
    {
        global $wpdb;
        $now = current_time('mysql');
        $payload = $this->sanitize($data);
        $payload['public_id'] = wp_generate_uuid4();
        $payload['created_at'] = $now;
        $payload['updated_at'] = $now;

        $result = $wpdb->insert($this->table, $payload);
        if ($result === false) {
            throw new RuntimeException('Không thể tạo salon. Mã salon có thể đã tồn tại.');
        }

        $salon_id = (int) $wpdb->insert_id;
        HTP_Owner_Service::synchronize_assignment($salon_id, absint($payload['owner_user_id'] ?? 0));
        return $salon_id;
    }

    public function update(int $id, array $data): void
    {
        global $wpdb;
        $payload = $this->sanitize($data);
        $payload['updated_at'] = current_time('mysql');
        $result = $wpdb->update($this->table, $payload, ['id' => $id]);
        if ($result === false) {
            throw new RuntimeException('Không thể cập nhật salon.');
        }

        HTP_Owner_Service::synchronize_assignment($id, absint($payload['owner_user_id'] ?? 0));
    }

    public function set_landing_page(int $id, int $page_id): void
    {
        global $wpdb;
        $wpdb->update($this->table, [
            'landing_page_id' => $page_id ?: null,
            'updated_at' => current_time('mysql'),
        ], ['id' => $id]);
    }

    public function set_status(int $id, string $status): void
    {
        global $wpdb;
        $status = $status === 'active' ? 'active' : 'inactive';
        $wpdb->update($this->table, [
            'status' => $status,
            'updated_at' => current_time('mysql'),
        ], ['id' => $id], ['%s', '%s'], ['%d']);
    }

    public function counts(): array
    {
        global $wpdb;
        $row = $wpdb->get_row("SELECT COUNT(*) total, SUM(status='active') active FROM {$this->table}", ARRAY_A);
        return [
            'total' => (int) ($row['total'] ?? 0),
            'active' => (int) ($row['active'] ?? 0),
        ];
    }

    private function sanitize(array $data): array
    {
        $code = strtoupper(trim(sanitize_text_field((string) ($data['code'] ?? ''))));
        $name = sanitize_text_field((string) ($data['name'] ?? ''));
        if ($code === '' || !preg_match('/^[A-Z0-9-]{2,50}$/', $code)) {
            throw new InvalidArgumentException('Mã salon chỉ gồm chữ in hoa, số, dấu gạch ngang và dài 2–50 ký tự.');
        }
        if ($name === '') {
            throw new InvalidArgumentException('Vui lòng nhập tên salon.');
        }

        $owner_user_id = HTP_Owner_Service::validate_owner_user_id(absint($data['owner_user_id'] ?? 0));

        return [
            'code' => $code,
            'name' => $name,
            'address' => sanitize_textarea_field((string) ($data['address'] ?? '')),
            'phone' => sanitize_text_field((string) ($data['phone'] ?? '')),
            'email' => sanitize_email((string) ($data['email'] ?? '')),
            'manager_name' => sanitize_text_field((string) ($data['manager_name'] ?? '')),
            'owner_user_id' => $owner_user_id ?: null,
            'intro' => wp_kses_post((string) ($data['intro'] ?? '')),
            'instruction' => wp_kses_post((string) ($data['instruction'] ?? '')),
            'opening_hours' => sanitize_textarea_field((string) ($data['opening_hours'] ?? '')),
            'map_url' => esc_url_raw((string) ($data['map_url'] ?? '')),
            'oa_url' => esc_url_raw((string) ($data['oa_url'] ?? '')),
            'landing_page_id' => absint($data['landing_page_id'] ?? 0) ?: null,
            'status' => ($data['status'] ?? 'active') === 'inactive' ? 'inactive' : 'active',
        ];
    }
}
