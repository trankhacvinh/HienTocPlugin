<?php

defined('ABSPATH') || exit;

final class HTP_Activity_Logger
{
    public static function log(string $action, ?string $entity_type = null, ?int $entity_id = null, array $details = [], ?int $user_id = null): void
    {
        global $wpdb;

        $wpdb->insert($wpdb->prefix . 'htp_activity_logs', [
            'user_id' => $user_id ?? (get_current_user_id() ?: null),
            'action' => sanitize_key($action),
            'entity_type' => $entity_type ? sanitize_key($entity_type) : null,
            'entity_id' => $entity_id,
            'details' => $details ? wp_json_encode($details, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null,
            'ip_address' => self::ip_address(),
            'created_at' => current_time('mysql'),
        ]);
    }

    public static function recent(int $limit = 100): array
    {
        global $wpdb;
        $table = $wpdb->prefix . 'htp_activity_logs';
        $users = $wpdb->users;
        $limit = max(1, min(500, $limit));

        return $wpdb->get_results(
            $wpdb->prepare(
                "SELECT a.*, u.display_name, u.user_login
                 FROM {$table} a
                 LEFT JOIN {$users} u ON u.ID = a.user_id
                 ORDER BY a.created_at DESC
                 LIMIT %d",
                $limit
            )
        ) ?: [];
    }

    public static function ip_address(): string
    {
        $ip = isset($_SERVER['REMOTE_ADDR']) ? sanitize_text_field(wp_unslash($_SERVER['REMOTE_ADDR'])) : '';
        return substr($ip, 0, 64);
    }
}
