<?php

defined('ABSPATH') || exit;

final class HTP_Installer
{
    public static function activate(): void
    {
        self::create_tables();
        self::create_roles();
        self::set_default_options();
        self::create_default_pages();
        update_option('htp_db_version', HTP_DB_VERSION);
        flush_rewrite_rules();
    }

    public static function deactivate(): void
    {
        flush_rewrite_rules();
    }

    public static function maybe_upgrade(): void
    {
        if (get_option('htp_db_version') !== HTP_DB_VERSION) {
            self::create_tables();
            self::create_roles();
            self::set_default_options();
            update_option('htp_db_version', HTP_DB_VERSION);
        }
    }

    private static function create_tables(): void
    {
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $charset = $wpdb->get_charset_collate();
        $salons = $wpdb->prefix . 'htp_salons';
        $registrations = $wpdb->prefix . 'htp_registrations';
        $logs = $wpdb->prefix . 'htp_registration_logs';
        $user_salons = $wpdb->prefix . 'htp_user_salons';
        $visits = $wpdb->prefix . 'htp_qr_visits';
        $activity = $wpdb->prefix . 'htp_activity_logs';

        dbDelta("CREATE TABLE {$salons} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            public_id CHAR(36) NOT NULL,
            code VARCHAR(50) NOT NULL,
            name VARCHAR(190) NOT NULL,
            address TEXT NULL,
            phone VARCHAR(30) NULL,
            email VARCHAR(190) NULL,
            manager_name VARCHAR(190) NULL,
            logo_id BIGINT UNSIGNED NULL,
            cover_image_id BIGINT UNSIGNED NULL,
            instruction LONGTEXT NULL,
            status VARCHAR(20) NOT NULL DEFAULT 'active',
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY code (code),
            UNIQUE KEY public_id (public_id),
            KEY status (status),
            KEY name (name)
        ) {$charset};");

        dbDelta("CREATE TABLE {$registrations} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            public_id CHAR(36) NOT NULL,
            salon_id BIGINT UNSIGNED NOT NULL,
            registration_code VARCHAR(80) NULL,
            full_name VARCHAR(190) NOT NULL,
            phone VARCHAR(30) NOT NULL,
            phone_normalized VARCHAR(30) NOT NULL,
            date_of_birth DATE NULL,
            email VARCHAR(190) NULL,
            address TEXT NULL,
            customer_note TEXT NULL,
            internal_note TEXT NULL,
            source_url TEXT NULL,
            status VARCHAR(20) NOT NULL DEFAULT 'new',
            consent_at DATETIME NOT NULL,
            registered_at DATETIME NOT NULL,
            confirmed_at DATETIME NULL,
            received_at DATETIME NULL,
            completed_at DATETIME NULL,
            rejected_at DATETIME NULL,
            cancelled_at DATETIME NULL,
            created_by BIGINT UNSIGNED NULL,
            updated_by BIGINT UNSIGNED NULL,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY public_id (public_id),
            UNIQUE KEY registration_code (registration_code),
            KEY salon_status_date (salon_id, status, registered_at),
            KEY phone_normalized (phone_normalized),
            KEY registered_at (registered_at)
        ) {$charset};");

        dbDelta("CREATE TABLE {$logs} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            registration_id BIGINT UNSIGNED NOT NULL,
            old_status VARCHAR(20) NULL,
            new_status VARCHAR(20) NOT NULL,
            note TEXT NULL,
            changed_by BIGINT UNSIGNED NULL,
            changed_at DATETIME NOT NULL,
            PRIMARY KEY (id),
            KEY registration_id (registration_id),
            KEY changed_at (changed_at)
        ) {$charset};");

        dbDelta("CREATE TABLE {$user_salons} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            user_id BIGINT UNSIGNED NOT NULL,
            salon_id BIGINT UNSIGNED NOT NULL,
            created_at DATETIME NOT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY user_salon (user_id, salon_id),
            KEY salon_id (salon_id)
        ) {$charset};");

        dbDelta("CREATE TABLE {$visits} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            salon_id BIGINT UNSIGNED NOT NULL,
            visitor_hash CHAR(64) NOT NULL,
            device_type VARCHAR(20) NOT NULL DEFAULT 'unknown',
            converted TINYINT(1) NOT NULL DEFAULT 0,
            registration_id BIGINT UNSIGNED NULL,
            visited_at DATETIME NOT NULL,
            PRIMARY KEY (id),
            KEY salon_date (salon_id, visited_at),
            KEY visitor_hash (visitor_hash),
            KEY converted (converted)
        ) {$charset};");

        dbDelta("CREATE TABLE {$activity} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            user_id BIGINT UNSIGNED NULL,
            action VARCHAR(80) NOT NULL,
            entity_type VARCHAR(50) NULL,
            entity_id BIGINT UNSIGNED NULL,
            details LONGTEXT NULL,
            ip_address VARCHAR(64) NULL,
            created_at DATETIME NOT NULL,
            PRIMARY KEY (id),
            KEY user_date (user_id, created_at),
            KEY entity (entity_type, entity_id),
            KEY action (action)
        ) {$charset};");
    }

    private static function create_roles(): void
    {
        $manager_caps = [
            'read' => true,
            'htp_manage_salons' => true,
            'htp_manage_registrations' => true,
            'htp_update_registration_status' => true,
            'htp_view_reports' => true,
            'htp_export_data' => true,
            'htp_view_own_salon' => true,
            'htp_manage_users' => true,
            'htp_view_activity' => true,
        ];

        add_role('htp_program_manager', 'Quản lý chương trình hiến tóc', $manager_caps);
        $manager = get_role('htp_program_manager');
        if ($manager) {
            foreach ($manager_caps as $cap => $grant) {
                $manager->add_cap($cap, $grant);
            }
        }

        $salon_caps = [
            'read' => true,
            'htp_view_own_salon' => true,
            'htp_update_registration_status' => true,
        ];
        add_role('htp_salon_user', 'Tài khoản salon', $salon_caps);
        $salon_role = get_role('htp_salon_user');
        if ($salon_role) {
            foreach ($salon_caps as $cap => $grant) {
                $salon_role->add_cap($cap, $grant);
            }
        }

        $admin = get_role('administrator');
        if ($admin) {
            foreach ([
                'htp_manage_salons',
                'htp_manage_registrations',
                'htp_update_registration_status',
                'htp_view_reports',
                'htp_export_data',
                'htp_view_own_salon',
                'htp_manage_settings',
                'htp_manage_users',
                'htp_view_activity',
            ] as $capability) {
                $admin->add_cap($capability);
            }
        }
    }

    private static function set_default_options(): void
    {
        $defaults = [
            'htp_enable_date_of_birth' => 1,
            'htp_enable_email' => 1,
            'htp_enable_address' => 1,
            'htp_enable_customer_note' => 1,
            'htp_duplicate_days' => 30,
            'htp_privacy_text' => 'Tôi đồng ý cung cấp thông tin cho chương trình hiến tóc.',
            'htp_success_text' => 'Vui lòng lưu mã đăng ký và cung cấp cho salon khi đến hiến tóc.',
        ];

        foreach ($defaults as $key => $value) {
            if (get_option($key, null) === null) {
                add_option($key, $value);
            }
        }
    }

    private static function create_default_pages(): void
    {
        if (!get_option('htp_registration_page_id')) {
            $page_id = wp_insert_post([
                'post_title' => 'Đăng ký hiến tóc',
                'post_name' => 'dang-ky-hien-toc',
                'post_content' => '[htp_registration_form]',
                'post_status' => 'publish',
                'post_type' => 'page',
            ]);
            if (!is_wp_error($page_id)) {
                update_post_meta((int) $page_id, '_htp_created_page', 'registration');
                update_option('htp_registration_page_id', (int) $page_id);
            }
        }

        if (!get_option('htp_lookup_page_id')) {
            $page_id = wp_insert_post([
                'post_title' => 'Tra cứu đăng ký hiến tóc',
                'post_name' => 'tra-cuu-hien-toc',
                'post_content' => '[htp_registration_lookup]',
                'post_status' => 'publish',
                'post_type' => 'page',
            ]);
            if (!is_wp_error($page_id)) {
                update_post_meta((int) $page_id, '_htp_created_page', 'lookup');
                update_option('htp_lookup_page_id', (int) $page_id);
            }
        }
    }
}
