<?php

defined('ABSPATH') || exit;

final class HTP_Registration_Service
{
    public function register(object $salon, array $input): array
    {
        global $wpdb;

        $full_name = sanitize_text_field((string) ($input['full_name'] ?? ''));
        $phone = sanitize_text_field((string) ($input['phone'] ?? ''));
        $phone_normalized = $this->normalize_phone($phone);

        if ($full_name === '') {
            throw new InvalidArgumentException('Vui lòng nhập họ và tên.');
        }

        if (strlen($phone_normalized) < 9 || strlen($phone_normalized) > 15) {
            throw new InvalidArgumentException('Số điện thoại không hợp lệ.');
        }

        if (empty($input['consent'])) {
            throw new InvalidArgumentException('Vui lòng đồng ý với chính sách thu thập thông tin.');
        }

        $table = $wpdb->prefix . 'htp_registrations';
        $logs = $wpdb->prefix . 'htp_registration_logs';
        $now = current_time('mysql');
        $public_id = wp_generate_uuid4();

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
                'email' => sanitize_email((string) ($input['email'] ?? '')),
                'address' => sanitize_textarea_field((string) ($input['address'] ?? '')),
                'customer_note' => sanitize_textarea_field((string) ($input['customer_note'] ?? '')),
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

            $wpdb->update(
                $table,
                ['registration_code' => $registration_code],
                ['id' => $registration_id],
                ['%s'],
                ['%d']
            );

            $wpdb->insert($logs, [
                'registration_id' => $registration_id,
                'old_status' => null,
                'new_status' => 'new',
                'note' => 'Tạo từ form public.',
                'changed_by' => null,
                'changed_at' => $now,
            ]);

            $wpdb->query('COMMIT');

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

    private function normalize_phone(string $phone): string
    {
        $normalized = preg_replace('/\D+/', '', $phone) ?: '';

        if (str_starts_with($normalized, '84') && strlen($normalized) >= 11) {
            $normalized = '0' . substr($normalized, 2);
        }

        return $normalized;
    }

    private function sanitize_date(mixed $value): ?string
    {
        $value = trim((string) $value);
        if ($value === '') {
            return null;
        }

        $date = DateTimeImmutable::createFromFormat('Y-m-d', $value);
        return $date && $date->format('Y-m-d') === $value ? $value : null;
    }
}
