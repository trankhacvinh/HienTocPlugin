<?php

defined('ABSPATH') || exit;

final class HTP_Installer
{
    public static function activate(): void
    {
        self::create_tables();
        self::create_roles();
        update_option('htp_db_version', HTP_VERSION);
    }

    private static function create_tables(): void
    {
        global $wpdb;

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $charset = $wpdb->get_charset_collate();
        $salons = $wpdb->prefix . 'htp_salons';
        $registrations = $wpdb->prefix . 'htp_registrations';
        $logs = $wpdb->prefix . 'htp_registration_logs';

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
            sequence_number BIGINT UNSIGNED NOT NULL DEFAULT 0,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY code (code),
            UNIQUE KEY public_id (public_id),
            KEY status (status)
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
            status VARCHAR(20) NOT NULL DEFAULT 'new',
            consent_at DATETIME NOT NULL,
            registered_at DATETIME NOT NULL,
            confirmed_at DATETIME NULL,
            received_at DATETIME NULL,
            completed_at DATETIME NULL,
            created_by BIGINT UNSIGNED NULL,
            updated_by BIGINT UNSIGNED NULL,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY public_id (public_id),
            UNIQUE KEY registration_code (registration_code),
            KEY salon_status_date (salon_id, status, registered_at),
            KEY phone_normalized (phone_normalized)
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
    }

    private static function create_roles(): void
    {
        add_role('htp_program_manager', 'Quản lý chương trình hiến tóc', [
            'read' => true,
            'htp_manage_salons' => true,
            'htp_manage_registrations' => true,
            'htp_view_reports' => true,
            'htp_export_data' => true,
        ]);

        add_role('htp_salon_user', 'Tài khoản salon', [
            'read' => true,
            'htp_view_own_salon' => true,
            'htp_update_registration_status' => true,
        ]);

        $admin = get_role('administrator');
        if ($admin) {
            foreach ([
                'htp_manage_salons',
                'htp_manage_registrations',
                'htp_view_reports',
                'htp_export_data',
                'htp_view_own_salon',
                'htp_update_registration_status',
                'htp_manage_settings',
            ] as $capability) {
                $admin->add_cap($capability);
            }
        }
    }
}
