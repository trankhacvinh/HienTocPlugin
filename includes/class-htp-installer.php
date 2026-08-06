<?php

defined('ABSPATH') || exit;

final class HTP_Installer
{
    public static function activate(): void
    {
        self::create_tables();
        self::create_roles();
        self::set_default_options();
        self::seed_default_forms();
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
        if ((string) get_option('htp_db_version') !== HTP_DB_VERSION) {
            self::create_tables();
            self::create_roles();
            self::set_default_options();
            self::seed_default_forms();
            update_option('htp_db_version', HTP_DB_VERSION);
            flush_rewrite_rules(false);
        }
    }

    private static function create_tables(): void
    {
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $charset = $wpdb->get_charset_collate();
        $salons = $wpdb->prefix . 'htp_salons';
        $forms = $wpdb->prefix . 'htp_forms';
        $fields = $wpdb->prefix . 'htp_form_fields';
        $submissions = $wpdb->prefix . 'htp_submissions';
        $values = $wpdb->prefix . 'htp_submission_values';
        $files = $wpdb->prefix . 'htp_submission_files';
        $logs = $wpdb->prefix . 'htp_submission_logs';
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
            intro LONGTEXT NULL,
            instruction LONGTEXT NULL,
            opening_hours TEXT NULL,
            map_url TEXT NULL,
            oa_url TEXT NULL,
            landing_page_id BIGINT UNSIGNED NULL,
            status VARCHAR(20) NOT NULL DEFAULT 'active',
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY code (code),
            UNIQUE KEY public_id (public_id),
            KEY status (status),
            KEY name (name),
            KEY landing_page_id (landing_page_id)
        ) {$charset};");

        dbDelta("CREATE TABLE {$forms} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            form_key VARCHAR(40) NOT NULL,
            name VARCHAR(190) NOT NULL,
            description TEXT NULL,
            submit_label VARCHAR(190) NOT NULL,
            success_message LONGTEXT NULL,
            status VARCHAR(20) NOT NULL DEFAULT 'active',
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY form_key (form_key),
            KEY status (status)
        ) {$charset};");

        dbDelta("CREATE TABLE {$fields} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            form_id BIGINT UNSIGNED NOT NULL,
            field_key VARCHAR(80) NOT NULL,
            label VARCHAR(190) NOT NULL,
            field_type VARCHAR(30) NOT NULL,
            enabled TINYINT(1) NOT NULL DEFAULT 1,
            required TINYINT(1) NOT NULL DEFAULT 0,
            sort_order INT NOT NULL DEFAULT 0,
            width VARCHAR(10) NOT NULL DEFAULT 'full',
            placeholder VARCHAR(255) NULL,
            help_text TEXT NULL,
            options_json LONGTEXT NULL,
            system_field TINYINT(1) NOT NULL DEFAULT 0,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY form_field (form_id, field_key),
            KEY form_order (form_id, sort_order),
            KEY enabled (enabled)
        ) {$charset};");

        dbDelta("CREATE TABLE {$submissions} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            public_id CHAR(36) NOT NULL,
            salon_id BIGINT UNSIGNED NOT NULL,
            form_id BIGINT UNSIGNED NOT NULL,
            form_key VARCHAR(40) NOT NULL,
            submission_code VARCHAR(80) NULL,
            full_name VARCHAR(190) NOT NULL,
            phone VARCHAR(30) NOT NULL,
            phone_normalized VARCHAR(30) NOT NULL,
            email VARCHAR(190) NULL,
            date_of_birth DATE NULL,
            status VARCHAR(30) NOT NULL DEFAULT 'new',
            consent_at DATETIME NOT NULL,
            source_url TEXT NULL,
            created_by BIGINT UNSIGNED NULL,
            updated_by BIGINT UNSIGNED NULL,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY public_id (public_id),
            UNIQUE KEY submission_code (submission_code),
            KEY salon_form_date (salon_id, form_key, created_at),
            KEY form_status_date (form_key, status, created_at),
            KEY phone_normalized (phone_normalized),
            KEY created_at (created_at)
        ) {$charset};");

        dbDelta("CREATE TABLE {$values} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            submission_id BIGINT UNSIGNED NOT NULL,
            field_key VARCHAR(80) NOT NULL,
            field_value LONGTEXT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY submission_field (submission_id, field_key),
            KEY field_key (field_key)
        ) {$charset};");

        dbDelta("CREATE TABLE {$files} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            submission_id BIGINT UNSIGNED NOT NULL,
            field_key VARCHAR(80) NOT NULL,
            attachment_id BIGINT UNSIGNED NOT NULL,
            sort_order INT NOT NULL DEFAULT 0,
            created_at DATETIME NOT NULL,
            PRIMARY KEY (id),
            KEY submission_field (submission_id, field_key),
            KEY attachment_id (attachment_id)
        ) {$charset};");

        dbDelta("CREATE TABLE {$logs} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            submission_id BIGINT UNSIGNED NOT NULL,
            old_status VARCHAR(30) NULL,
            new_status VARCHAR(30) NOT NULL,
            note TEXT NULL,
            changed_by BIGINT UNSIGNED NULL,
            changed_at DATETIME NOT NULL,
            PRIMARY KEY (id),
            KEY submission_id (submission_id),
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
            selected_form VARCHAR(40) NULL,
            converted TINYINT(1) NOT NULL DEFAULT 0,
            submission_id BIGINT UNSIGNED NULL,
            visited_at DATETIME NOT NULL,
            PRIMARY KEY (id),
            KEY salon_date (salon_id, visited_at),
            KEY visitor_hash (visitor_hash),
            KEY converted (converted),
            KEY selected_form (selected_form)
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
            'htp_manage_forms' => true,
            'htp_manage_registrations' => true,
            'htp_update_registration_status' => true,
            'htp_view_reports' => true,
            'htp_export_data' => true,
            'htp_view_own_salon' => true,
            'htp_manage_users' => true,
            'htp_view_activity' => true,
        ];

        add_role('htp_program_manager', 'Quản lý chương trình MyHair', $manager_caps);
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
                'htp_manage_forms',
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
            'htp_duplicate_days' => 30,
            'htp_oa_url' => '',
            'htp_oa_button_label' => 'Quan tâm OA MyHair',
            'htp_upload_max_mb' => 5,
            'htp_hair_photo_limit' => 3,
            'htp_member_success_text' => 'Cảm ơn bạn đã đăng ký thành viên MyHair.',
            'htp_donation_success_text' => 'Vui lòng lưu mã đăng ký và cung cấp cho salon khi đến hiến tóc.',
            'htp_privacy_text' => 'Tôi đồng ý cung cấp thông tin cho chương trình MyHair.',
        ];

        foreach ($defaults as $key => $value) {
            if (get_option($key, null) === null) {
                add_option($key, $value);
            }
        }
    }

    private static function seed_default_forms(): void
    {
        global $wpdb;
        $forms_table = $wpdb->prefix . 'htp_forms';
        $fields_table = $wpdb->prefix . 'htp_form_fields';
        $now = current_time('mysql');

        $forms = [
            'donation' => [
                'name' => 'Đăng ký hiến tóc',
                'description' => 'Form đăng ký hiến tóc tại salon.',
                'submit_label' => 'Gửi đăng ký hiến tóc',
                'success_message' => (string) get_option('htp_donation_success_text', ''),
            ],
            'member' => [
                'name' => 'Đăng ký thành viên',
                'description' => 'Form đăng ký thành viên MyHair.',
                'submit_label' => 'Đăng ký thành viên',
                'success_message' => (string) get_option('htp_member_success_text', ''),
            ],
        ];

        foreach ($forms as $key => $form) {
            $form_id = (int) $wpdb->get_var($wpdb->prepare("SELECT id FROM {$forms_table} WHERE form_key = %s", $key));
            if (!$form_id) {
                $wpdb->insert($forms_table, [
                    'form_key' => $key,
                    'name' => $form['name'],
                    'description' => $form['description'],
                    'submit_label' => $form['submit_label'],
                    'success_message' => $form['success_message'],
                    'status' => 'active',
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
                $form_id = (int) $wpdb->insert_id;
            }

            $default_fields = self::default_fields($key);
            foreach ($default_fields as $index => $field) {
                $exists = (int) $wpdb->get_var($wpdb->prepare(
                    "SELECT id FROM {$fields_table} WHERE form_id = %d AND field_key = %s",
                    $form_id,
                    $field['field_key']
                ));
                if ($exists) {
                    continue;
                }
                $wpdb->insert($fields_table, [
                    'form_id' => $form_id,
                    'field_key' => $field['field_key'],
                    'label' => $field['label'],
                    'field_type' => $field['field_type'],
                    'enabled' => $field['enabled'],
                    'required' => $field['required'],
                    'sort_order' => ($index + 1) * 10,
                    'width' => $field['width'],
                    'placeholder' => $field['placeholder'] ?? '',
                    'help_text' => $field['help_text'] ?? '',
                    'options_json' => isset($field['options']) ? wp_json_encode($field['options'], JSON_UNESCAPED_UNICODE) : null,
                    'system_field' => $field['system_field'] ?? 0,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }
        }
    }

    private static function default_fields(string $form_key): array
    {
        $common = [
            ['field_key' => 'full_name', 'label' => 'Họ và tên', 'field_type' => 'text', 'enabled' => 1, 'required' => 1, 'width' => 'full', 'system_field' => 1, 'placeholder' => 'Nhập họ và tên'],
            ['field_key' => 'phone', 'label' => 'Số điện thoại', 'field_type' => 'tel', 'enabled' => 1, 'required' => 1, 'width' => 'half', 'system_field' => 1, 'placeholder' => '0901 234 567'],
            ['field_key' => 'date_of_birth', 'label' => 'Ngày sinh', 'field_type' => 'date', 'enabled' => 1, 'required' => 0, 'width' => 'half'],
            ['field_key' => 'gender', 'label' => 'Giới tính', 'field_type' => 'select', 'enabled' => 1, 'required' => 0, 'width' => 'half', 'options' => ['Nữ', 'Nam', 'Khác']],
            ['field_key' => 'email', 'label' => 'Email', 'field_type' => 'email', 'enabled' => 1, 'required' => 0, 'width' => 'half', 'placeholder' => 'email@example.com'],
            ['field_key' => 'address', 'label' => 'Địa chỉ', 'field_type' => 'textarea', 'enabled' => 1, 'required' => 0, 'width' => 'full'],
        ];

        if ($form_key === 'member') {
            return array_merge($common, [
                ['field_key' => 'province', 'label' => 'Tỉnh/Thành phố', 'field_type' => 'text', 'enabled' => 1, 'required' => 0, 'width' => 'half'],
                ['field_key' => 'district', 'label' => 'Quận/Huyện', 'field_type' => 'text', 'enabled' => 1, 'required' => 0, 'width' => 'half'],
                ['field_key' => 'interested_services', 'label' => 'Dịch vụ quan tâm', 'field_type' => 'checkbox_group', 'enabled' => 1, 'required' => 0, 'width' => 'full', 'options' => ['Cắt', 'Uốn', 'Nhuộm', 'Chăm sóc tóc', 'Khác']],
                ['field_key' => 'referral_source', 'label' => 'Bạn biết MyHair qua đâu?', 'field_type' => 'select', 'enabled' => 1, 'required' => 0, 'width' => 'full', 'options' => ['Bạn bè giới thiệu', 'Facebook', 'TikTok', 'Google', 'Tại salon', 'Khác']],
                ['field_key' => 'avatar', 'label' => 'Ảnh đại diện', 'field_type' => 'image', 'enabled' => 1, 'required' => 0, 'width' => 'full', 'help_text' => 'Chụp ảnh hoặc chọn ảnh có sẵn trên điện thoại.'],
                ['field_key' => 'marketing_consent', 'label' => 'Tôi đồng ý nhận thông tin ưu đãi từ MyHair', 'field_type' => 'checkbox', 'enabled' => 1, 'required' => 0, 'width' => 'full'],
                ['field_key' => 'consent', 'label' => (string) get_option('htp_privacy_text', 'Tôi đồng ý cung cấp thông tin.'), 'field_type' => 'consent', 'enabled' => 1, 'required' => 1, 'width' => 'full', 'system_field' => 1],
            ]);
        }

        return array_merge($common, [
            ['field_key' => 'hair_length', 'label' => 'Độ dài tóc dự kiến (cm)', 'field_type' => 'number', 'enabled' => 1, 'required' => 0, 'width' => 'half', 'placeholder' => '30'],
            ['field_key' => 'hair_condition', 'label' => 'Tình trạng tóc', 'field_type' => 'select', 'enabled' => 1, 'required' => 0, 'width' => 'half', 'options' => ['Tóc tự nhiên', 'Đã nhuộm', 'Đã uốn', 'Đã duỗi', 'Khác']],
            ['field_key' => 'hair_photos', 'label' => 'Ảnh tóc', 'field_type' => 'images', 'enabled' => 1, 'required' => 0, 'width' => 'full', 'help_text' => 'Có thể chụp trực tiếp hoặc chọn nhiều ảnh.'],
            ['field_key' => 'customer_note', 'label' => 'Ghi chú', 'field_type' => 'textarea', 'enabled' => 1, 'required' => 0, 'width' => 'full'],
            ['field_key' => 'consent', 'label' => (string) get_option('htp_privacy_text', 'Tôi đồng ý cung cấp thông tin.'), 'field_type' => 'consent', 'enabled' => 1, 'required' => 1, 'width' => 'full', 'system_field' => 1],
        ]);
    }

    private static function create_default_pages(): void
    {
        if (!get_option('htp_lookup_page_id')) {
            $page_id = wp_insert_post([
                'post_title' => 'Tra cứu đăng ký MyHair',
                'post_name' => 'tra-cuu-myhair',
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
