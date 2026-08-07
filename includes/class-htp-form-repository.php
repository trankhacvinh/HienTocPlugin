<?php

defined('ABSPATH') || exit;

final class HTP_Form_Repository
{
    private string $forms_table;
    private string $fields_table;

    public function __construct()
    {
        global $wpdb;
        $this->forms_table = $wpdb->prefix . 'htp_forms';
        $this->fields_table = $wpdb->prefix . 'htp_form_fields';
    }

    public function find_by_key(string $form_key, bool $active_only = false): ?object
    {
        global $wpdb;
        $form_key = sanitize_key($form_key);
        $sql = "SELECT * FROM {$this->forms_table} WHERE form_key = %s";
        if ($active_only) {
            $sql .= " AND status = 'active'";
        }
        $sql .= ' LIMIT 1';
        $form = $wpdb->get_row($wpdb->prepare($sql, $form_key));
        return $form ?: null;
    }

    public function all(): array
    {
        global $wpdb;
        return $wpdb->get_results("SELECT * FROM {$this->forms_table} ORDER BY id ASC") ?: [];
    }

    public function fields(int $form_id, bool $enabled_only = false): array
    {
        global $wpdb;
        $sql = "SELECT * FROM {$this->fields_table} WHERE form_id = %d";
        if ($enabled_only) {
            $sql .= ' AND enabled = 1';
        }
        $sql .= ' ORDER BY sort_order ASC, id ASC';
        return $wpdb->get_results($wpdb->prepare($sql, $form_id)) ?: [];
    }

    public function update_form(int $form_id, array $data): void
    {
        global $wpdb;
        $payload = [
            'name' => sanitize_text_field((string) ($data['name'] ?? '')),
            'description' => sanitize_textarea_field((string) ($data['description'] ?? '')),
            'submit_label' => sanitize_text_field((string) ($data['submit_label'] ?? '')),
            'success_message' => wp_kses_post((string) ($data['success_message'] ?? '')),
            'status' => (($data['status'] ?? 'active') === 'inactive') ? 'inactive' : 'active',
            'updated_at' => current_time('mysql'),
        ];
        if ($payload['name'] === '' || $payload['submit_label'] === '') {
            throw new InvalidArgumentException('Tên form và nhãn nút gửi không được để trống.');
        }
        if ($wpdb->update($this->forms_table, $payload, ['id' => $form_id]) === false) {
            throw new RuntimeException('Không thể lưu cấu hình form.');
        }
    }

    public function update_fields(int $form_id, array $rows): void
    {
        global $wpdb;
        $allowed_types = self::field_types();
        $allowed_widths = ['full', 'two_thirds', 'half', 'third'];
        $order = 10;

        foreach ($rows as $field_id => $row) {
            $field_id = absint($field_id);
            if (!$field_id) {
                continue;
            }
            $existing = $wpdb->get_row($wpdb->prepare(
                "SELECT * FROM {$this->fields_table} WHERE id = %d AND form_id = %d",
                $field_id,
                $form_id
            ));
            if (!$existing) {
                continue;
            }

            $enabled = empty($row['enabled']) ? 0 : 1;
            $required = empty($row['required']) ? 0 : 1;
            if ((int) $existing->system_field === 1 && in_array($existing->field_key, ['full_name', 'phone', 'consent'], true)) {
                $enabled = 1;
                $required = 1;
            }

            $field_type = sanitize_key((string) ($row['field_type'] ?? $existing->field_type));
            if (!isset($allowed_types[$field_type])) {
                $field_type = $existing->field_type;
            }
            $width = sanitize_key((string) ($row['width'] ?? 'full'));
            if (!in_array($width, $allowed_widths, true)) {
                $width = 'full';
            }

            $options = self::normalize_options((string) ($row['options'] ?? ''));
            $wpdb->update($this->fields_table, [
                'label' => sanitize_text_field((string) ($row['label'] ?? $existing->label)),
                'field_type' => $field_type,
                'enabled' => $enabled,
                'required' => $required,
                'sort_order' => $order,
                'width' => $width,
                'placeholder' => sanitize_text_field((string) ($row['placeholder'] ?? '')),
                'help_text' => sanitize_textarea_field((string) ($row['help_text'] ?? '')),
                'options_json' => $options ? wp_json_encode($options, JSON_UNESCAPED_UNICODE) : null,
                'updated_at' => current_time('mysql'),
            ], ['id' => $field_id]);
            $order += 10;
        }
    }

    public function add_custom_field(int $form_id, array $data): int
    {
        global $wpdb;
        $label = sanitize_text_field((string) ($data['label'] ?? ''));
        $type = sanitize_key((string) ($data['field_type'] ?? 'text'));
        if ($label === '' || !isset(self::field_types()[$type])) {
            throw new InvalidArgumentException('Tên hoặc loại trường không hợp lệ.');
        }

        $base_key = sanitize_key(remove_accents($label));
        $base_key = preg_replace('/[^a-z0-9_]+/', '_', $base_key) ?: 'custom_field';
        $field_key = 'custom_' . trim($base_key, '_');
        $suffix = 1;
        while ((int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$this->fields_table} WHERE form_id = %d AND field_key = %s",
            $form_id,
            $field_key
        )) > 0) {
            $suffix++;
            $field_key = 'custom_' . trim($base_key, '_') . '_' . $suffix;
        }

        $max_order = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COALESCE(MAX(sort_order), 0) FROM {$this->fields_table} WHERE form_id = %d",
            $form_id
        ));

        $options = self::normalize_options((string) ($data['options'] ?? ''));
        $wpdb->insert($this->fields_table, [
            'form_id' => $form_id,
            'field_key' => $field_key,
            'label' => $label,
            'field_type' => $type,
            'enabled' => 1,
            'required' => empty($data['required']) ? 0 : 1,
            'sort_order' => $max_order + 10,
            'width' => in_array(($data['width'] ?? 'full'), ['full', 'two_thirds', 'half', 'third'], true) ? $data['width'] : 'full',
            'placeholder' => sanitize_text_field((string) ($data['placeholder'] ?? '')),
            'help_text' => sanitize_textarea_field((string) ($data['help_text'] ?? '')),
            'options_json' => $options ? wp_json_encode($options, JSON_UNESCAPED_UNICODE) : null,
            'system_field' => 0,
            'created_at' => current_time('mysql'),
            'updated_at' => current_time('mysql'),
        ]);

        if (!$wpdb->insert_id) {
            throw new RuntimeException('Không thể thêm trường mới.');
        }
        return (int) $wpdb->insert_id;
    }

    public function delete_custom_field(int $field_id): void
    {
        global $wpdb;
        $field = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$this->fields_table} WHERE id = %d", $field_id));
        if (!$field || (int) $field->system_field === 1) {
            throw new RuntimeException('Không thể xóa trường hệ thống.');
        }
        $wpdb->delete($this->fields_table, ['id' => $field_id], ['%d']);
    }

    public static function field_types(): array
    {
        return [
            'text' => 'Văn bản ngắn',
            'tel' => 'Số điện thoại',
            'email' => 'Email',
            'number' => 'Số',
            'date' => 'Ngày',
            'textarea' => 'Văn bản dài',
            'select' => 'Danh sách chọn',
            'radio' => 'Lựa chọn một',
            'checkbox' => 'Ô chọn',
            'checkbox_group' => 'Chọn nhiều',
            'image' => 'Một ảnh',
            'images' => 'Nhiều ảnh',
            'consent' => 'Đồng ý điều khoản',
        ];
    }

    public static function decode_options(?string $json): array
    {
        if (!$json) {
            return [];
        }
        $decoded = json_decode($json, true);
        return is_array($decoded) ? array_values(array_filter(array_map('strval', $decoded))) : [];
    }

    private static function normalize_options(string $raw): array
    {
        $parts = preg_split('/\r\n|\r|\n|,/', $raw) ?: [];
        $result = [];
        foreach ($parts as $part) {
            $value = trim(sanitize_text_field($part));
            if ($value !== '') {
                $result[] = $value;
            }
        }
        return array_values(array_unique($result));
    }
}
