<?php

defined('ABSPATH') || exit;

final class HTP_Submission_Service
{
    public static function status_labels(string $form_key): array
    {
        if ($form_key === 'member') {
            return [
                'active' => 'Đang hoạt động',
                'inactive' => 'Ngừng hoạt động',
                'duplicate' => 'Trùng đăng ký',
            ];
        }

        return [
            'new' => 'Mới đăng ký',
            'confirmed' => 'Đã xác nhận',
            'received' => 'Đã tiếp nhận',
            'completed' => 'Đã hoàn thành',
            'rejected' => 'Không đạt yêu cầu',
            'cancelled' => 'Đã hủy',
            'duplicate' => 'Trùng đăng ký',
        ];
    }

    public static function statuses(string $form_key): array
    {
        return array_keys(self::status_labels($form_key));
    }

    public function submit(object $salon, string $form_key, array $input): array
    {
        global $wpdb;

        $form_repository = new HTP_Form_Repository();
        $form = $form_repository->find_by_key($form_key, true);
        if (!$form) {
            throw new RuntimeException('Biểu mẫu hiện không khả dụng.');
        }
        $fields = $form_repository->fields((int) $form->id, true);
        $normalized = $this->validate_and_normalize($fields, $input);
        $phone_normalized = self::normalize_phone((string) ($normalized['phone'] ?? ''));

        if ($form_key === 'member') {
            $existing = (new HTP_Submission_Repository())->find_member_by_phone_and_salon($phone_normalized, (int) $salon->id);
            if ($existing) {
                return [
                    'id' => (int) $existing->id,
                    'submission_code' => (string) $existing->submission_code,
                    'full_name' => (string) $existing->full_name,
                    'status' => (string) $existing->status,
                    'existing' => true,
                    'form_key' => 'member',
                    'oa_url' => $this->oa_url($salon),
                ];
            }
        }

        $rate_key = 'htp_rate_' . md5(HTP_Activity_Logger::ip_address() . '|' . $phone_normalized . '|' . $form_key);
        $attempts = (int) get_transient($rate_key);
        if ($attempts >= 5) {
            throw new RuntimeException('Bạn đã gửi quá nhiều lần. Vui lòng thử lại sau ít phút.');
        }
        set_transient($rate_key, $attempts + 1, 10 * MINUTE_IN_SECONDS);

        $submissions = $wpdb->prefix . 'htp_submissions';
        $values_table = $wpdb->prefix . 'htp_submission_values';
        $files_table = $wpdb->prefix . 'htp_submission_files';
        $logs_table = $wpdb->prefix . 'htp_submission_logs';
        $now = current_time('mysql');
        $public_id = wp_generate_uuid4();
        $status = $form_key === 'member' ? 'active' : 'new';
        $source_url = esc_url_raw((string) ($input['source_url'] ?? ''));

        $wpdb->query('START TRANSACTION');
        try {
            $inserted = $wpdb->insert($submissions, [
                'public_id' => $public_id,
                'salon_id' => (int) $salon->id,
                'form_id' => (int) $form->id,
                'form_key' => $form_key,
                'submission_code' => null,
                'full_name' => (string) $normalized['full_name'],
                'phone' => (string) $normalized['phone'],
                'phone_normalized' => $phone_normalized,
                'email' => sanitize_email((string) ($normalized['email'] ?? '')),
                'date_of_birth' => $this->sanitize_date($normalized['date_of_birth'] ?? null),
                'status' => $status,
                'consent_at' => $now,
                'source_url' => $source_url,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            if ($inserted === false) {
                throw new RuntimeException('Không thể lưu đăng ký. Vui lòng thử lại.');
            }

            $submission_id = (int) $wpdb->insert_id;
            $prefix = $form_key === 'member' ? 'M' : 'D';
            $submission_code = sprintf('%s-%s-%06d', $salon->code, $prefix, $submission_id);
            if ($wpdb->update($submissions, ['submission_code' => $submission_code], ['id' => $submission_id]) === false) {
                throw new RuntimeException('Không thể tạo mã đăng ký.');
            }

            foreach ($fields as $field) {
                $key = (string) $field->field_key;
                if (in_array($field->field_type, ['image', 'images'], true)) {
                    continue;
                }
                $value = $normalized[$key] ?? null;
                if ($value === null || $value === '') {
                    continue;
                }
                $wpdb->replace($values_table, [
                    'submission_id' => $submission_id,
                    'field_key' => $key,
                    'field_value' => is_array($value) ? wp_json_encode($value, JSON_UNESCAPED_UNICODE) : (string) $value,
                ]);
            }

            $upload_service = new HTP_Upload_Service();
            foreach ($fields as $field) {
                if (!in_array($field->field_type, ['image', 'images'], true)) {
                    continue;
                }
                $attachment_ids = $upload_service->upload_field((string) $field->field_key, $field->field_type === 'images');
                foreach ($attachment_ids as $index => $attachment_id) {
                    $wpdb->insert($files_table, [
                        'submission_id' => $submission_id,
                        'field_key' => (string) $field->field_key,
                        'attachment_id' => (int) $attachment_id,
                        'sort_order' => $index,
                        'created_at' => $now,
                    ]);
                }
            }

            $wpdb->insert($logs_table, [
                'submission_id' => $submission_id,
                'old_status' => null,
                'new_status' => $status,
                'note' => 'Tạo từ form public.',
                'changed_by' => null,
                'changed_at' => $now,
            ]);

            $this->mark_visit_converted((int) $salon->id, $form_key, $submission_id);
            $wpdb->query('COMMIT');
            delete_transient($rate_key);

            HTP_Activity_Logger::log('submission_created', 'submission', $submission_id, [
                'salon_id' => (int) $salon->id,
                'form_key' => $form_key,
                'submission_code' => $submission_code,
            ], 0);

            return [
                'id' => $submission_id,
                'public_id' => $public_id,
                'submission_code' => $submission_code,
                'full_name' => (string) $normalized['full_name'],
                'status' => $status,
                'existing' => false,
                'form_key' => $form_key,
                'success_message' => (string) $form->success_message,
                'oa_url' => $form_key === 'member' ? $this->oa_url($salon) : '',
            ];
        } catch (Throwable $exception) {
            $wpdb->query('ROLLBACK');
            throw $exception;
        }
    }

    public function update_status(int $submission_id, string $new_status, string $note = ''): void
    {
        global $wpdb;
        $repository = new HTP_Submission_Repository();
        $submission = $repository->find($submission_id);
        if (!$submission) {
            throw new RuntimeException('Không tìm thấy dữ liệu.');
        }
        if (!HTP_User_Salon_Service::can_access_salon((int) $submission->salon_id)) {
            throw new RuntimeException('Bạn không có quyền xử lý dữ liệu này.');
        }

        $new_status = sanitize_key($new_status);
        if (!in_array($new_status, self::statuses((string) $submission->form_key), true)) {
            throw new InvalidArgumentException('Trạng thái không hợp lệ.');
        }
        if ($new_status === $submission->status) {
            return;
        }

        $can_override = current_user_can('htp_manage_registrations');
        if (!$can_override && !in_array($new_status, $this->allowed_transitions((string) $submission->form_key, (string) $submission->status), true)) {
            throw new RuntimeException('Không thể chuyển sang trạng thái đã chọn.');
        }

        $now = current_time('mysql');
        $wpdb->query('START TRANSACTION');
        try {
            if ($wpdb->update($wpdb->prefix . 'htp_submissions', [
                'status' => $new_status,
                'updated_by' => get_current_user_id(),
                'updated_at' => $now,
            ], ['id' => $submission_id]) === false) {
                throw new RuntimeException('Không thể cập nhật trạng thái.');
            }
            $wpdb->insert($wpdb->prefix . 'htp_submission_logs', [
                'submission_id' => $submission_id,
                'old_status' => $submission->status,
                'new_status' => $new_status,
                'note' => sanitize_textarea_field($note),
                'changed_by' => get_current_user_id(),
                'changed_at' => $now,
            ]);
            $wpdb->query('COMMIT');
        } catch (Throwable $exception) {
            $wpdb->query('ROLLBACK');
            throw $exception;
        }

        HTP_Activity_Logger::log('submission_status_updated', 'submission', $submission_id, [
            'old_status' => $submission->status,
            'new_status' => $new_status,
            'note' => sanitize_textarea_field($note),
        ]);
    }

    public function record_visit(object $salon, string $selected_form = ''): void
    {
        global $wpdb;
        $hash = self::visitor_hash((int) $salon->id);
        $transient = 'htp_visit_' . substr($hash, 0, 32);
        if (get_transient($transient)) {
            return;
        }
        $ua = isset($_SERVER['HTTP_USER_AGENT']) ? strtolower(sanitize_text_field(wp_unslash($_SERVER['HTTP_USER_AGENT']))) : '';
        $device = preg_match('/mobile|android|iphone|ipad/', $ua) ? 'mobile' : 'desktop';
        $wpdb->insert($wpdb->prefix . 'htp_qr_visits', [
            'salon_id' => (int) $salon->id,
            'visitor_hash' => $hash,
            'device_type' => $device,
            'selected_form' => in_array($selected_form, ['donation', 'member'], true) ? $selected_form : null,
            'converted' => 0,
            'submission_id' => null,
            'visited_at' => current_time('mysql'),
        ]);
        set_transient($transient, 1, 30 * MINUTE_IN_SECONDS);
    }

    public static function normalize_phone(string $phone): string
    {
        $normalized = preg_replace('/\D+/', '', $phone) ?: '';
        if (str_starts_with($normalized, '84') && strlen($normalized) >= 11) {
            $normalized = '0' . substr($normalized, 2);
        }
        return $normalized;
    }

    public function allowed_transitions(string $form_key, string $status): array
    {
        if ($form_key === 'member') {
            $map = [
                'active' => ['inactive', 'duplicate'],
                'inactive' => ['active', 'duplicate'],
                'duplicate' => [],
            ];
            return $map[$status] ?? [];
        }

        $map = [
            'new' => ['confirmed', 'received', 'cancelled', 'duplicate'],
            'confirmed' => ['received', 'rejected', 'cancelled', 'duplicate'],
            'received' => ['completed', 'rejected', 'cancelled'],
            'completed' => [],
            'rejected' => [],
            'cancelled' => [],
            'duplicate' => [],
        ];
        return $map[$status] ?? [];
    }

    private function validate_and_normalize(array $fields, array $input): array
    {
        $result = [];
        foreach ($fields as $field) {
            $key = (string) $field->field_key;
            $type = (string) $field->field_type;
            if (in_array($type, ['image', 'images'], true)) {
                if ((int) $field->required === 1 && !$this->has_uploaded_file($key)) {
                    throw new InvalidArgumentException('Vui lòng cung cấp: ' . (string) $field->label . '.');
                }
                continue;
            }

            $raw = $input[$key] ?? null;
            $value = $this->sanitize_field_value($type, $raw, HTP_Form_Repository::decode_options($field->options_json));
            $is_empty = $value === '' || $value === null || $value === [] || $value === false;
            if ((int) $field->required === 1 && $is_empty) {
                throw new InvalidArgumentException('Vui lòng nhập: ' . (string) $field->label . '.');
            }
            $result[$key] = $value;
        }

        $full_name = sanitize_text_field((string) ($result['full_name'] ?? ''));
        $phone = sanitize_text_field((string) ($result['phone'] ?? ''));
        $phone_normalized = self::normalize_phone($phone);
        if ($full_name === '') {
            throw new InvalidArgumentException('Vui lòng nhập họ và tên.');
        }
        if (strlen($phone_normalized) < 9 || strlen($phone_normalized) > 15) {
            throw new InvalidArgumentException('Số điện thoại không hợp lệ.');
        }
        if (!empty($result['email']) && !is_email((string) $result['email'])) {
            throw new InvalidArgumentException('Email không hợp lệ.');
        }
        if (empty($result['consent'])) {
            throw new InvalidArgumentException('Vui lòng đồng ý với chính sách thu thập thông tin.');
        }
        $result['full_name'] = $full_name;
        $result['phone'] = $phone;
        return $result;
    }

    private function has_uploaded_file(string $field_key): bool
    {
        if (!isset($_FILES[$field_key])) {
            return false;
        }
        $file = $_FILES[$field_key];
        $errors = $file['error'] ?? UPLOAD_ERR_NO_FILE;
        if (is_array($errors)) {
            foreach ($errors as $error) {
                if ((int) $error === UPLOAD_ERR_OK) {
                    return true;
                }
            }
            return false;
        }
        return (int) $errors === UPLOAD_ERR_OK;
    }

    private function sanitize_field_value(string $type, mixed $raw, array $allowed_options): mixed
    {
        if (in_array($type, ['checkbox', 'consent'], true)) {
            return !empty($raw) ? '1' : '';
        }
        if ($type === 'checkbox_group') {
            $values = is_array($raw) ? $raw : [];
            $values = array_values(array_filter(array_map('sanitize_text_field', array_map('strval', $values))));
            return $allowed_options ? array_values(array_intersect($values, $allowed_options)) : $values;
        }
        if (is_array($raw)) {
            return [];
        }
        $value = trim((string) $raw);
        if ($type === 'email') {
            return sanitize_email($value);
        }
        if ($type === 'textarea') {
            return sanitize_textarea_field($value);
        }
        if ($type === 'number') {
            return is_numeric($value) ? (string) $value : '';
        }
        if ($type === 'date') {
            return $this->sanitize_date($value);
        }
        if (in_array($type, ['select', 'radio'], true) && $allowed_options) {
            $clean = sanitize_text_field($value);
            return in_array($clean, $allowed_options, true) ? $clean : '';
        }
        return sanitize_text_field($value);
    }

    private function sanitize_date(mixed $value): ?string
    {
        $value = trim((string) $value);
        if ($value === '') {
            return null;
        }
        $date = DateTimeImmutable::createFromFormat('Y-m-d', $value);
        if (!$date || $date->format('Y-m-d') !== $value) {
            throw new InvalidArgumentException('Ngày sinh không hợp lệ.');
        }
        return $value;
    }

    private static function visitor_hash(int $salon_id): string
    {
        $ua = isset($_SERVER['HTTP_USER_AGENT']) ? sanitize_text_field(wp_unslash($_SERVER['HTTP_USER_AGENT'])) : '';
        return hash('sha256', HTP_Activity_Logger::ip_address() . '|' . $ua . '|' . $salon_id . '|' . gmdate('Y-m-d-H'));
    }

    private function mark_visit_converted(int $salon_id, string $form_key, int $submission_id): void
    {
        global $wpdb;
        $hash = self::visitor_hash($salon_id);
        $visit_id = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$wpdb->prefix}htp_qr_visits
             WHERE salon_id = %d AND visitor_hash = %s AND converted = 0
             ORDER BY visited_at DESC LIMIT 1",
            $salon_id,
            $hash
        ));
        if ($visit_id) {
            $wpdb->update($wpdb->prefix . 'htp_qr_visits', [
                'converted' => 1,
                'selected_form' => $form_key,
                'submission_id' => $submission_id,
            ], ['id' => (int) $visit_id]);
        }
    }

    private function oa_url(object $salon): string
    {
        $salon_url = isset($salon->oa_url) ? trim((string) $salon->oa_url) : '';
        return esc_url_raw($salon_url !== '' ? $salon_url : (string) get_option('htp_oa_url', ''));
    }
}
