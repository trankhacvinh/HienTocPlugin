<?php

defined('ABSPATH') || exit;

final class HTP_User_Salon_Service
{
    public static function init(): void
    {
        add_filter('wp_authenticate_user', [self::class, 'block_disabled_user'], 20);
        add_action('wp_login', [self::class, 'record_last_login'], 10, 2);
    }

    public static function salon_ids_for_user(?int $user_id = null): array
    {
        global $wpdb;
        $user_id = $user_id ?? get_current_user_id();
        if (!$user_id) {
            return [];
        }

        $table = $wpdb->prefix . 'htp_user_salons';
        return array_map('intval', $wpdb->get_col(
            $wpdb->prepare("SELECT salon_id FROM {$table} WHERE user_id = %d ORDER BY salon_id", $user_id)
        ) ?: []);
    }

    public static function assign(int $user_id, array $salon_ids): void
    {
        global $wpdb;
        $table = $wpdb->prefix . 'htp_user_salons';
        $wpdb->delete($table, ['user_id' => $user_id], ['%d']);

        $salon_ids = array_values(array_unique(array_filter(array_map('absint', $salon_ids))));
        foreach ($salon_ids as $salon_id) {
            $wpdb->insert($table, [
                'user_id' => $user_id,
                'salon_id' => $salon_id,
                'created_at' => current_time('mysql'),
            ], ['%d', '%d', '%s']);
        }
    }

    public static function can_access_salon(int $salon_id, ?int $user_id = null): bool
    {
        $user_id = $user_id ?? get_current_user_id();
        if (!$user_id) {
            return false;
        }

        if (user_can($user_id, 'htp_manage_registrations') || user_can($user_id, 'htp_manage_salons')) {
            return true;
        }

        return in_array($salon_id, self::salon_ids_for_user($user_id), true);
    }

    public static function plugin_users(): array
    {
        $users = get_users([
            'role__in' => ['administrator', 'htp_program_manager', 'htp_salon_user'],
            'orderby' => 'display_name',
            'order' => 'ASC',
        ]);

        return is_array($users) ? $users : [];
    }

    public static function block_disabled_user(WP_User|WP_Error $user): WP_User|WP_Error
    {
        if ($user instanceof WP_User && get_user_meta($user->ID, 'htp_disabled', true)) {
            return new WP_Error('htp_account_disabled', 'Tài khoản này đang bị khóa. Vui lòng liên hệ quản trị viên.');
        }
        return $user;
    }

    public static function record_last_login(string $user_login, WP_User $user): void
    {
        update_user_meta($user->ID, 'htp_last_login', current_time('mysql'));
    }
}
