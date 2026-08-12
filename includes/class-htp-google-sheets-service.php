<?php

defined('ABSPATH') || exit;

final class HTP_Google_Sheets_Service
{
    private const CRON_HOOK = 'htp_google_sheets_process_queue';
    private const CRON_SCHEDULE = 'htp_every_five_minutes';
    private const MAX_ATTEMPTS = 12;
    private const SCHEMA_VERSION = '1.0.0';
    private const SALON_DESTINATIONS_OPTION = 'htp_salon_google_sheets';

    public static function init(): void
    {
        self::maybe_create_queue_table();
        add_filter('cron_schedules', [self::class, 'cron_schedules']);
        add_action(self::CRON_HOOK, [self::class, 'process_queue']);
        add_action('htp_activity_logged', [self::class, 'handle_activity'], 10, 5);

        if (self::enabled()) {
            self::ensure_cron();
        } else {
            self::clear_cron();
        }
    }

    public static function deactivate(): void
    {
        self::clear_cron();
    }

    public static function handle_activity(string $action, ?string $entity_type, ?int $entity_id, array $details = [], ?int $user_id = null): void
    {
        if ($entity_type !== 'submission' || !$entity_id) {
            return;
        }
        if ($action === 'submission_created') {
            self::queue_submission($entity_id, 'created');
        } elseif ($action === 'submission_status_updated') {
            self::queue_submission($entity_id, 'updated');
        }
    }

    public static function cron_schedules(array $schedules): array
    {
        $schedules[self::CRON_SCHEDULE] = [
            'interval' => 5 * MINUTE_IN_SECONDS,
            'display' => 'Mỗi 5 phút (MyHair Google Sheets)',
        ];
        return $schedules;
    }

    public static function enabled(): bool
    {
        return (bool) get_option('htp_google_sheets_enabled', 0)
            && self::endpoint_url() !== '';
    }

    public static function endpoint_url(): string
    {
        return esc_url_raw(trim((string) get_option('htp_google_sheets_webhook_url', '')));
    }

    public static function ensure_cron(): void
    {
        if (!wp_next_scheduled(self::CRON_HOOK)) {
            wp_schedule_event(time() + 60, self::CRON_SCHEDULE, self::CRON_HOOK);
        }
    }

    public static function clear_cron(): void
    {
        $timestamp = wp_next_scheduled(self::CRON_HOOK);
        while ($timestamp) {
            wp_unschedule_event($timestamp, self::CRON_HOOK);
            $timestamp = wp_next_scheduled(self::CRON_HOOK);
        }
    }

    public static function salon_destination(int $salon_id): array
    {
        $all = get_option(self::SALON_DESTINATIONS_OPTION, []);
        if (!is_array($all)) {
            $all = [];
        }
        $row = isset($all[$salon_id]) && is_array($all[$salon_id]) ? $all[$salon_id] : [];
        $id = sanitize_text_field((string) ($row['spreadsheet_id'] ?? ''));
        $url = esc_url_raw((string) ($row['url'] ?? ''));

        return [
            'enabled' => !empty($row['enabled']) && $id !== '',
            'spreadsheet_id' => $id,
            'url' => $url !== '' ? $url : ($id !== '' ? self::spreadsheet_url($id) : ''),
        ];
    }

    public static function save_salon_destination(int $salon_id, array $input): void
    {
        if ($salon_id <= 0) {
            throw new InvalidArgumentException('Salon không hợp lệ.');
        }

        $enabled = !empty($input['google_sheet_enabled']);
        $raw = trim((string) ($input['google_sheet_url'] ?? ''));
        $spreadsheet_id = $raw !== '' ? self::extract_spreadsheet_id($raw) : '';

        if ($raw !== '' && $spreadsheet_id === '') {
            throw new InvalidArgumentException('Google Sheet URL/ID không hợp lệ. Hãy dán URL dạng docs.google.com/spreadsheets/d/.../edit hoặc Spreadsheet ID.');
        }
        if ($enabled && $spreadsheet_id === '') {
            throw new InvalidArgumentException('Đã bật Google Sheet riêng nhưng chưa nhập Google Sheet URL/ID.');
        }

        $all = get_option(self::SALON_DESTINATIONS_OPTION, []);
        if (!is_array($all)) {
            $all = [];
        }
        $all[$salon_id] = [
            'enabled' => $enabled ? 1 : 0,
            'spreadsheet_id' => $spreadsheet_id,
            'url' => $spreadsheet_id !== '' ? self::spreadsheet_url($spreadsheet_id) : '',
        ];
        update_option(self::SALON_DESTINATIONS_OPTION, $all, false);
    }

    public static function test_salon_connection(int $salon_id): array
    {
        if (self::endpoint_url() === '') {
            throw new RuntimeException('Chưa cấu hình Apps Script Web App URL trong MyHair → Cài đặt.');
        }
        $salon = (new HTP_Salon_Repository())->find_by_id($salon_id);
        if (!$salon) {
            throw new RuntimeException('Không tìm thấy salon.');
        }
        $destination = self::salon_destination($salon_id);
        if (empty($destination['spreadsheet_id'])) {
            throw new RuntimeException('Salon chưa có Google Sheet riêng.');
        }

        $response = self::post_payload([
            'action' => 'ping_salon',
            'salon_spreadsheet_id' => $destination['spreadsheet_id'],
            'salon_code' => (string) $salon->code,
            'salon_name' => (string) $salon->name,
            'site_url' => home_url('/'),
            'sent_at' => gmdate('c'),
        ], 15);
        return self::decode_success_response($response, 'Kết nối Google Sheet riêng thất bại');
    }

    public static function queue_submission(int $submission_id, string $event = 'updated'): void
    {
        if (!self::enabled() || $submission_id <= 0) {
            return;
        }

        global $wpdb;
        $table = $wpdb->prefix . 'htp_sync_queue';
        $now = current_time('mysql');
        $event = sanitize_key($event) ?: 'updated';
        $existing_id = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$table} WHERE submission_id = %d LIMIT 1",
            $submission_id
        ));

        if ($existing_id) {
            $wpdb->update($table, [
                'event_key' => $event,
                'attempts' => 0,
                'available_at' => $now,
                'last_error' => '',
                'updated_at' => $now,
            ], ['id' => $existing_id]);
        } else {
            $wpdb->insert($table, [
                'submission_id' => $submission_id,
                'event_key' => $event,
                'attempts' => 0,
                'available_at' => $now,
                'last_error' => '',
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        self::ensure_cron();
    }

    public static function queue_all(): int
    {
        global $wpdb;
        $ids = $wpdb->get_col("SELECT id FROM {$wpdb->prefix}htp_submissions ORDER BY id ASC") ?: [];
        foreach ($ids as $id) {
            self::queue_submission((int) $id, 'resync');
        }
        return count($ids);
    }

    public static function queue_salon(int $salon_id): int
    {
        if (!self::enabled()) {
            throw new RuntimeException('Đồng bộ Google Sheets chung đang tắt hoặc chưa có Apps Script Web App URL.');
        }
        $destination = self::salon_destination($salon_id);
        if (empty($destination['enabled']) || empty($destination['spreadsheet_id'])) {
            throw new RuntimeException('Google Sheet riêng của salon chưa được bật hoặc chưa cấu hình.');
        }

        global $wpdb;
        $ids = $wpdb->get_col($wpdb->prepare(
            "SELECT id FROM {$wpdb->prefix}htp_submissions WHERE salon_id = %d ORDER BY id ASC",
            $salon_id
        )) ?: [];
        foreach ($ids as $id) {
            self::queue_submission((int) $id, 'salon_resync');
        }
        return count($ids);
    }

    public static function pending_count(): int
    {
        global $wpdb;
        $table = $wpdb->prefix . 'htp_sync_queue';
        return (int) $wpdb->get_var("SELECT COUNT(*) FROM {$table}");
    }

    public static function recent_errors(int $limit = 5): array
    {
        global $wpdb;
        $table = $wpdb->prefix . 'htp_sync_queue';
        $limit = max(1, min(20, $limit));
        return $wpdb->get_results($wpdb->prepare(
            "SELECT q.*, s.submission_code
             FROM {$table} q
             LEFT JOIN {$wpdb->prefix}htp_submissions s ON s.id = q.submission_id
             WHERE q.last_error <> ''
             ORDER BY q.updated_at DESC LIMIT %d",
            $limit
        )) ?: [];
    }

    public static function process_queue(int $limit = 20): array
    {
        if (!self::enabled()) {
            return ['success' => 0, 'failed' => 0, 'pending' => self::pending_count()];
        }

        global $wpdb;
        $table = $wpdb->prefix . 'htp_sync_queue';
        $limit = max(1, min(100, $limit));
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$table}
             WHERE available_at <= %s AND attempts < %d
             ORDER BY id ASC LIMIT %d",
            current_time('mysql'),
            self::MAX_ATTEMPTS,
            $limit
        )) ?: [];

        $success = 0;
        $failed = 0;
        foreach ($rows as $row) {
            try {
                self::send_submission((int) $row->submission_id, (string) $row->event_key);
                $wpdb->delete($table, ['id' => (int) $row->id], ['%d']);
                $success++;
            } catch (Throwable $exception) {
                $attempts = (int) $row->attempts + 1;
                $delay_minutes = min(360, (int) pow(2, min(8, $attempts)));
                $available_gmt = gmdate('Y-m-d H:i:s', current_time('timestamp', true) + ($delay_minutes * MINUTE_IN_SECONDS));
                $wpdb->update($table, [
                    'attempts' => $attempts,
                    'available_at' => get_date_from_gmt($available_gmt),
                    'last_error' => substr($exception->getMessage(), 0, 1000),
                    'updated_at' => current_time('mysql'),
                ], ['id' => (int) $row->id]);
                $failed++;
            }
        }

        $pending = self::pending_count();
        update_option('htp_google_sheets_last_sync_at', current_time('mysql'));
        update_option('htp_google_sheets_last_sync_summary', wp_json_encode([
            'success' => $success,
            'failed' => $failed,
            'pending' => $pending,
        ], JSON_UNESCAPED_UNICODE));

        return compact('success', 'failed', 'pending');
    }

    public static function send_submission(int $submission_id, string $event = 'updated'): void
    {
        $response = self::post_payload(self::build_payload($submission_id, $event), 12);
        self::decode_success_response($response, 'Google Sheets trả lỗi');
    }

    public static function test_connection(): array
    {
        if (self::endpoint_url() === '') {
            throw new RuntimeException('Chưa nhập URL Google Apps Script Web App.');
        }

        $response = self::post_payload([
            'action' => 'ping',
            'plugin' => 'MyHair',
            'site_url' => home_url('/'),
            'sent_at' => gmdate('c'),
        ], 15);
        return self::decode_success_response($response, 'Kết nối thất bại');
    }

    private static function decode_success_response(array $response, string $http_prefix): array
    {
        $code = wp_remote_retrieve_response_code($response);
        $body = trim((string) wp_remote_retrieve_body($response));
        if ($code < 200 || $code >= 300) {
            throw new RuntimeException(self::http_error_message($http_prefix, $response));
        }
        $decoded = json_decode($body, true);
        if (!is_array($decoded)) {
            throw new RuntimeException('Google Sheets phản hồi không phải JSON hợp lệ: ' . self::response_excerpt($body));
        }
        if (empty($decoded['ok'])) {
            throw new RuntimeException((string) ($decoded['error'] ?? 'Google Apps Script từ chối dữ liệu.'));
        }
        return $decoded;
    }

    private static function post_payload(array $payload, int $timeout): array
    {
        $payload['secret'] = trim((string) get_option('htp_google_sheets_secret', ''));
        $payload['sheet_tabs'] = [
            'donation' => (string) get_option('htp_google_sheets_donation_tab', 'Hien toc'),
            'member' => (string) get_option('htp_google_sheets_member_tab', 'Thanh vien'),
        ];

        $json = wp_json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (!is_string($json) || $json === '') {
            throw new RuntimeException('Không thể mã hóa dữ liệu gửi Google Sheets.');
        }

        $response = wp_remote_post(self::endpoint_url(), [
            'timeout' => $timeout,
            'redirection' => 0,
            'httpversion' => '1.1',
            'headers' => [
                'Accept' => 'application/json, text/plain, */*',
                'User-Agent' => 'MyHair-WordPress/' . (defined('HTP_VERSION') ? HTP_VERSION : 'unknown'),
            ],
            'body' => ['payload' => $json],
        ]);
        if (is_wp_error($response)) {
            throw new RuntimeException($response->get_error_message());
        }

        $code = wp_remote_retrieve_response_code($response);
        $location = wp_remote_retrieve_header($response, 'location');
        if (in_array($code, [301, 302, 303], true) && is_string($location) && $location !== '') {
            $redirect_response = wp_remote_get($location, [
                'timeout' => $timeout,
                'redirection' => 5,
                'httpversion' => '1.1',
                'headers' => [
                    'Accept' => 'application/json, text/plain, */*',
                    'User-Agent' => 'MyHair-WordPress/' . (defined('HTP_VERSION') ? HTP_VERSION : 'unknown'),
                ],
            ]);
            if (is_wp_error($redirect_response)) {
                throw new RuntimeException('Apps Script đã xử lý request nhưng không đọc được phản hồi chuyển hướng: ' . $redirect_response->get_error_message());
            }
            return $redirect_response;
        }
        return $response;
    }

    private static function build_payload(int $submission_id, string $event): array
    {
        $repository = new HTP_Submission_Repository();
        $submission = $repository->find($submission_id);
        if (!$submission) {
            throw new RuntimeException('Không tìm thấy đăng ký #' . $submission_id . '.');
        }

        $values = $repository->values($submission_id);
        $files = $repository->files($submission_id);
        $file_values = [];
        foreach ($files as $file) {
            $url = wp_get_attachment_url((int) $file->attachment_id);
            if ($url) {
                $file_values[(string) $file->field_key][] = $url;
            }
        }
        foreach ($file_values as $key => $urls) {
            $values[$key] = $urls;
        }

        $owner = class_exists('HTP_Owner_Service') ? HTP_Owner_Service::owner_for_submission($submission) : null;
        $flat = [];
        foreach ($values as $key => $value) {
            $flat[$key] = is_array($value) ? implode(', ', array_map('strval', $value)) : (string) $value;
        }

        $destination = self::salon_destination((int) $submission->salon_id);

        return [
            'action' => 'upsert_submission',
            'event' => sanitize_key($event),
            'site_url' => home_url('/'),
            'sent_at' => gmdate('c'),
            'salon_destination' => [
                'enabled' => !empty($destination['enabled']),
                'spreadsheet_id' => (string) $destination['spreadsheet_id'],
            ],
            'submission' => [
                'id' => (int) $submission->id,
                'public_id' => (string) $submission->public_id,
                'submission_code' => (string) $submission->submission_code,
                'form_key' => (string) $submission->form_key,
                'form_name' => (string) $submission->form_name,
                'status' => (string) $submission->status,
                'status_label' => HTP_Submission_Service::status_labels((string) $submission->form_key)[(string) $submission->status] ?? (string) $submission->status,
                'salon_id' => (int) $submission->salon_id,
                'salon_code' => (string) $submission->salon_code,
                'salon_name' => (string) $submission->salon_name,
                'salon_owner' => $owner ? $owner->display_name : '',
                'salon_owner_email' => $owner ? $owner->user_email : '',
                'full_name' => (string) $submission->full_name,
                'phone' => (string) $submission->phone,
                'email' => (string) $submission->email,
                'date_of_birth' => (string) $submission->date_of_birth,
                'source_url' => (string) $submission->source_url,
                'created_at' => (string) $submission->created_at,
                'updated_at' => (string) $submission->updated_at,
                'fields' => $flat,
            ],
        ];
    }

    private static function extract_spreadsheet_id(string $value): string
    {
        $value = trim($value);
        if ($value === '') {
            return '';
        }
        if (preg_match('~/spreadsheets/d/([a-zA-Z0-9_-]+)~', $value, $matches)) {
            return sanitize_text_field($matches[1]);
        }
        if (preg_match('/^[a-zA-Z0-9_-]{20,}$/', $value)) {
            return sanitize_text_field($value);
        }
        return '';
    }

    private static function spreadsheet_url(string $spreadsheet_id): string
    {
        return 'https://docs.google.com/spreadsheets/d/' . rawurlencode($spreadsheet_id) . '/edit';
    }

    private static function http_error_message(string $prefix, array $response): string
    {
        $code = (int) wp_remote_retrieve_response_code($response);
        $body = trim((string) wp_remote_retrieve_body($response));
        $content_type = trim((string) wp_remote_retrieve_header($response, 'content-type'));
        $location = trim((string) wp_remote_retrieve_header($response, 'location'));
        $parts = [$prefix . ', HTTP ' . $code];
        $excerpt = self::response_excerpt($body);
        if ($excerpt !== '') {
            $parts[] = $excerpt;
        }
        if ($content_type !== '') {
            $parts[] = 'Content-Type: ' . $content_type;
        }
        if ($location !== '') {
            $parts[] = 'Redirect: ' . $location;
        }
        return implode(' | ', $parts);
    }

    private static function response_excerpt(string $body): string
    {
        if ($body === '') {
            return '';
        }
        $text = html_entity_decode(wp_strip_all_tags($body), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = preg_replace('/\s+/u', ' ', $text) ?: '';
        return mb_substr(trim($text), 0, 400);
    }

    private static function maybe_create_queue_table(): void
    {
        if ((string) get_option('htp_google_sheets_schema_version', '') === self::SCHEMA_VERSION) {
            return;
        }

        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $table = $wpdb->prefix . 'htp_sync_queue';
        $charset = $wpdb->get_charset_collate();
        dbDelta("CREATE TABLE {$table} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            submission_id BIGINT UNSIGNED NOT NULL,
            event_key VARCHAR(40) NOT NULL DEFAULT 'updated',
            attempts INT UNSIGNED NOT NULL DEFAULT 0,
            available_at DATETIME NOT NULL,
            last_error TEXT NULL,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY submission_id (submission_id),
            KEY available_at (available_at),
            KEY attempts (attempts)
        ) {$charset};");
        update_option('htp_google_sheets_schema_version', self::SCHEMA_VERSION);
    }
}
