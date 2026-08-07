<?php

defined('ABSPATH') || exit;

final class HTP_Owner_Service
{
    private const SCHEMA_VERSION = '1.0.0';

    public static function init(): void
    {
        self::maybe_upgrade();

        $is_public_submission = $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['htp_form_submit']);
        $admin_action = isset($_REQUEST['action']) ? sanitize_key(wp_unslash($_REQUEST['action'])) : '';
        $is_owner_related_admin_action = in_array($admin_action, ['htp_save_salon', 'htp_save_user'], true);

        if ($is_public_submission || $is_owner_related_admin_action) {
            add_action('shutdown', [self::class, 'backfill_missing_submission_owners']);
        }
    }

    public static function maybe_upgrade(): void
    {
        global $wpdb;

        if ((string) get_option('htp_owner_schema_version') === self::SCHEMA_VERSION) {
            return;
        }

        $salons_table = $wpdb->prefix . 'htp_salons';
        $submissions_table = $wpdb->prefix . 'htp_submissions';

        if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $salons_table)) !== $salons_table) {
            return;
        }

        if (!self::column_exists($salons_table, 'owner_user_id')) {
            $wpdb->query("ALTER TABLE `{$salons_table}` ADD `owner_user_id` BIGINT UNSIGNED NULL AFTER `manager_name`"); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        }
        if (!self::index_exists($salons_table, 'owner_user_id')) {
            $wpdb->query("ALTER TABLE `{$salons_table}` ADD KEY `owner_user_id` (`owner_user_id`)"); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        }

        if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $submissions_table)) === $submissions_table) {
            if (!self::column_exists($submissions_table, 'salon_owner_user_id')) {
                $wpdb->query("ALTER TABLE `{$submissions_table}` ADD `salon_owner_user_id` BIGINT UNSIGNED NULL AFTER `salon_id`"); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            }
            if (!self::index_exists($submissions_table, 'salon_owner_user_id')) {
                $wpdb->query("ALTER TABLE `{$submissions_table}` ADD KEY `salon_owner_user_id` (`salon_owner_user_id`)"); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            }
        }

        update_option('htp_owner_schema_version', self::SCHEMA_VERSION);
        self::backfill_missing_submission_owners();
    }

    public static function owner_users(): array
    {
        $users = HTP_User_Salon_Service::plugin_users();
        usort($users, static function (WP_User $left, WP_User $right): int {
            return strcasecmp($left->display_name, $right->display_name);
        });
        return $users;
    }

    public static function validate_owner_user_id(int $user_id): int
    {
        if (!$user_id) {
            return 0;
        }

        $user = get_user_by('id', $user_id);
        if (!$user instanceof WP_User) {
            throw new InvalidArgumentException('Tài khoản chủ salon không tồn tại.');
        }

        $allowed_roles = ['administrator', 'htp_program_manager', 'htp_salon_user'];
        if (!array_intersect($allowed_roles, $user->roles)) {
            throw new InvalidArgumentException('Tài khoản được chọn không thuộc hệ thống MyHair.');
        }

        return $user_id;
    }

    public static function synchronize_assignment(int $salon_id, int $owner_user_id): void
    {
        if (!$salon_id || !$owner_user_id) {
            return;
        }

        global $wpdb;
        $table = $wpdb->prefix . 'htp_user_salons';
        $exists = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$table} WHERE user_id = %d AND salon_id = %d",
            $owner_user_id,
            $salon_id
        ));
        if (!$exists) {
            $wpdb->insert($table, [
                'user_id' => $owner_user_id,
                'salon_id' => $salon_id,
                'created_at' => current_time('mysql'),
            ], ['%d', '%d', '%s']);
        }
    }

    public static function owner_for_submission(object|array $submission, ?object $salon = null): ?WP_User
    {
        $snapshot_id = is_array($submission)
            ? absint($submission['salon_owner_user_id'] ?? 0)
            : absint($submission->salon_owner_user_id ?? 0);

        if ($snapshot_id) {
            $snapshot_owner = get_user_by('id', $snapshot_id);
            if ($snapshot_owner instanceof WP_User) {
                return $snapshot_owner;
            }
        }

        if (!$salon) {
            $salon_id = is_array($submission)
                ? absint($submission['salon_id'] ?? 0)
                : absint($submission->salon_id ?? 0);
            $salon = $salon_id ? (new HTP_Salon_Repository())->find_by_id($salon_id) : null;
        }

        $current_owner_id = $salon ? absint($salon->owner_user_id ?? 0) : 0;
        $current_owner = $current_owner_id ? get_user_by('id', $current_owner_id) : false;
        return $current_owner instanceof WP_User ? $current_owner : null;
    }

    public static function backfill_missing_submission_owners(): void
    {
        global $wpdb;

        if ((string) get_option('htp_owner_schema_version') !== self::SCHEMA_VERSION) {
            return;
        }

        $salons_table = $wpdb->prefix . 'htp_salons';
        $submissions_table = $wpdb->prefix . 'htp_submissions';
        $wpdb->query(
            "UPDATE `{$submissions_table}` x
             INNER JOIN `{$salons_table}` s ON s.id = x.salon_id
             SET x.salon_owner_user_id = s.owner_user_id
             WHERE x.salon_owner_user_id IS NULL
               AND s.owner_user_id IS NOT NULL"
        ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
    }

    private static function column_exists(string $table, string $column): bool
    {
        global $wpdb;
        return (bool) $wpdb->get_var($wpdb->prepare("SHOW COLUMNS FROM `{$table}` LIKE %s", $column)); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
    }

    private static function index_exists(string $table, string $index): bool
    {
        global $wpdb;
        return (bool) $wpdb->get_var($wpdb->prepare("SHOW INDEX FROM `{$table}` WHERE Key_name = %s", $index)); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
    }
}
