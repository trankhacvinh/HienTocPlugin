<?php

defined('ABSPATH') || exit;

final class HTP_Registration_Service
{
    public static function statuses(): array
    {
        return ['new', 'confirmed', 'received', 'completed', 'rejected', 'cancelled', 'duplicate'];
    }

    public static function status_labels(): array
    {
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

    public function register(object $salon, array $input): array
    {
        global $wpdb;

        $full_name = sanitize_text_field((string) ($input['full_name'] ?? ''));
        $phone = sanitize_text_field((string) ($input['phone'] ?? ''));
        $phone_normalized = self::normalize_phone($phone);
        $email = sanitize_email((string) ($input['email'] ?? ''));

        if ($full_name === '') {
            throw new InvalidArgumentException('Vui lòng nhập họ và tên.');
        }
        if (strlen($phone_normalized) < 9 || strlen($phone_normalized) > 15) {
            throw new InvalidArgumentException('Số điện thoại không hợp lệ.');
        }
        if ($email !== '' && !is_email($email)) {
            throw new InvalidArgumentException('Email không hợp lệ.');
        }
        if (empty($input['consent'])) {
            throw new InvalidArgumentException('Vui lòng đồng ý với chính sách thu thập thông tin.');
        }

        $rate_key = 'htp_rate_' . md5(HTP_Activity_Logger::ip_address() . '|' . $phone_normalized);
        $attempts = (int) get_transient($rate_key);
        if ($attempts >= 5) {
            throw new RuntimeException('Bạn đã gửi quá nhiều lần. Vui lòng thử lại sau ít phút.');
        }
        set_transient($rate_key, $attempts + 1, 10 * MINUTE_IN_SECONDS);

        $table = $wpdb->prefix . 'htp_registrations';
        $logs = $wpdb->prefix . 'htp_registration_logs';
        $now = current_time('mysql');
        $public_id = wp_generate_uuid4();
        $source_url = isset($input['source_url']) ? esc_url_raw((string) $input['source_url']) : '';

        $wpdb->query('START TRANSACTION');
        try {
            $inserted = $wpdb->insert($table, [
                'public_id' => $public_id,
                'salon_id' => (int) $salon->id,
                'registration_code' => null,
                'full_name' => $full_name,
                'phone' => $phone,
                'phone_normalized' => $phone_normalized,
                'date_of_birth' => $this->sanitize_date($input['date_of_birth'] ?? null),
                'email' => $email,
                'address' => sanitize_textarea_field((string) ($input['address'] ?? '')),
                'customer_note' => sanitize_textarea_field((string) ($input['customer_note'] ?? '')),
                'source_url' => $source_url,
                'status' => 'new',
                'consent_at' => $now,
                'registered_at' => $now,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            if ($inserted === false) {
                throw new RuntimeException('Không thể lưu đăng ký. Vui lòng thử lại.');
            }

            $registration_id = (int) $wpdb->insert_id;
            $registration_code = sprintf('%s-%06d', $salon->code, $registration_id);
            if ($wpdb->update($table, ['registration_code' => $registration_code], ['id' => $registration_id], ['%s'], ['%d']) === false) {
                throw new RuntimeException('Không thể tạo mã đăng ký.');
            }

            $wpdb->insert($logs, [
                'registration_id' => $registration_id,
                'old_status' => null,
                'new_status' => 'new',
                'note' => 'Tạo từ form public.',
                'changed_by' => null,
                'changed_at' => $now,
            ]);

            $this->mark_visit_converted((int) $salon->id, $registration_id);
            $wpdb->query('COMMIT');
            delete_transient($rate_key);

            HTP_Activity_Logger::log('registration_created', 'registration', $registration_id, [
                'salon_id' => (int) $salon->id,
                'registration_code' => $registration_code,
            ], 0);

            return [
                'id' => $registration_id,
                'public_id' => $public_id,
                'registration_code' => $registration_code,
                'full_name' => $full_name,
                'status' => 'new',
                'registered_at' => $now,
            ];
        } catch (Throwable $exception) {
            $wpdb->query('ROLLBACK');
            throw $exception;
        }
    }

    public function recent_duplicates(object $salon, string $phone): array
    {
        $normalized = self::normalize_phone($phone);
        if (strlen($normalized) < 9) {
            return [];
        }
        $days = max(1, (int) get_option('htp_duplicate_days', 30));
        return (new HTP_Registration_Repository())->recent_duplicates($normalized, (int) $salon->id, $days);
    }

    public function update_status(int $registration_id, string $new_status, string $note = ''): void
    {
        global $wpdb;
        $repository = new HTP_Registration_Repository();
        $registration = $repository->find($registration_id);
        if (!$registration) {
            throw new RuntimeException('Không tìm thấy đăng ký.');
        }
        if (!HTP_User_Salon_Service::can_access_salon((int) $registration->salon_id)) {
            throw new RuntimeException('Bạn không có quyền xử lý đăng ký này.');
        }

        $new_status = sanitize_key($new_status);
        if (!in_array($new_status, self::statuses(), true)) {
            throw new InvalidArgumentException('Trạng thái không hợp lệ.');
        }
        if ($new_status === $registration->status) {
            return;
        }

        $can_override = current_user_can('htp_manage_registrations');
        if (!$can_override && !in_array($new_status, $this->allowed_transitions((string) $registration->status), true)) {
            throw new RuntimeException('Không thể chuyển sang trạng thái đã chọn.');
        }

        $now = current_time('mysql');
        $payload = [
            'status' => $new_status,
            'updated_by' => get_current_user_id(),
            'updated_at' => $now,
        ];
        $timestamp_map = [
            'confirmed' => 'confirmed_at',
            'received' => 'received_at',
            'completed' => 'completed_at',
            'rejected' => 'rejected_at',
            'cancelled' => 'cancelled_at',
        ];
        if (isset($timestamp_map[$new_status])) {
            $payload[$timestamp_map[$new_status]] = $now;
        }

        $wpdb->query('START TRANSACTION');
        try {
            if ($wpdb->update($wpdb->prefix . 'htp_registrations', $payload, ['id' => $registration_id]) === false) {
                throw new RuntimeException('Không thể cập nhật trạng thái.');
            }
            $wpdb->insert($wpdb->prefix . 'htp_registration_logs', [
                'registration_id' => $registration_id,
                'old_status' => $registration->status,
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

        HTP_Activity_Logger::log('registration_status_updated', 'registration', $registration_id, [
            'old_status' => $registration->status,
            'new_status' => $new_status,
            'note' => sanitize_textarea_field($note),
        ]);
    }

    public function update_details(int $registration_id, array $input): void
    {
        global $wpdb;
        $repository = new HTP_Registration_Repository();
        $registration = $repository->find($registration_id);
        if (!$registration || !HTP_User_Salon_Service::can_access_salon((int) $registration->salon_id)) {
            throw new RuntimeException('Bạn không có quyền sửa đăng ký này.');
        }

        $full_name = sanitize_text_field((string) ($input['full_name'] ?? ''));
        $phone = sanitize_text_field((string) ($input['phone'] ?? ''));
        $phone_normalized = self::normalize_phone($phone);
        if ($full_name === '' || strlen($phone_normalized) < 9) {
            throw new InvalidArgumentException('Họ tên hoặc số điện thoại không hợp lệ.');
        }

        $wpdb->update($wpdb->prefix . 'htp_registrations', [
            'full_name' => $full_name,
            'phone' => $phone,
            'phone_normalized' => $phone_normalized,
            'date_of_birth' => $this->sanitize_date($input['date_of_birth'] ?? null),
            'email' => sanitize_email((string) ($input['email'] ?? '')),
            'address' => sanitize_textarea_field((string) ($input['address'] ?? '')),
            'customer_note' => sanitize_textarea_field((string) ($input['customer_note'] ?? '')),
            'internal_note' => sanitize_textarea_field((string) ($input['internal_note'] ?? '')),
            'updated_by' => get_current_user_id(),
            'updated_at' => current_time('mysql'),
        ], ['id' => $registration_id]);

        HTP_Activity_Logger::log('registration_updated', 'registration', $registration_id);
    }

    public function record_visit(object $salon): void
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
            'converted' => 0,
            'registration_id' => null,
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

    public function allowed_transitions(string $status): array
    {
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

    private function mark_visit_converted(int $salon_id, int $registration_id): void
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
                'registration_id' => $registration_id,
            ], ['id' => (int) $visit_id]);
        }
    }
}
