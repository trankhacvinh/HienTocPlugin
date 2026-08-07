<?php

defined('ABSPATH') || exit;

final class HTP_Backup_Service
{
    public const FORMAT_VERSION = 1;
    public const MAGIC = 'HTP_BACKUP';

    private const TABLE_SUFFIXES = [
        'htp_salons',
        'htp_forms',
        'htp_form_fields',
        'htp_submissions',
        'htp_submission_values',
        'htp_submission_files',
        'htp_submission_logs',
        'htp_user_salons',
        'htp_qr_visits',
        'htp_activity_logs',
    ];

    private const STRUCTURAL_OPTIONS = [
        'htp_db_version',
        'htp_owner_schema_version',
        'htp_legacy_migrated_v2',
    ];

    public static function export_download(): void
    {
        self::assert_admin();
        check_admin_referer('htp_export_backup');

        $path = self::create_backup_file('manual');
        if (!$path || !is_file($path)) {
            wp_die('Không thể tạo file sao lưu. Vui lòng kiểm tra quyền ghi của máy chủ.');
        }

        nocache_headers();
        header('Content-Type: application/octet-stream');
        header('Content-Disposition: attachment; filename="' . basename($path) . '"');
        header('Content-Length: ' . (string) filesize($path));
        readfile($path); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_readfile
        @unlink($path);
        exit;
    }

    public static function import_uploaded(): void
    {
        self::assert_admin();
        check_admin_referer('htp_import_backup');

        if (!isset($_FILES['htp_backup_file']) || (int) ($_FILES['htp_backup_file']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            wp_die('Vui lòng chọn file sao lưu hợp lệ.');
        }

        $tmp = (string) ($_FILES['htp_backup_file']['tmp_name'] ?? '');
        if ($tmp === '' || !is_uploaded_file($tmp)) {
            wp_die('File tải lên không hợp lệ.');
        }

        try {
            $result = self::restore_backup_file($tmp);
        } catch (Throwable $exception) {
            wp_die(esc_html('Khôi phục thất bại: ' . $exception->getMessage()));
        }

        HTP_Activity_Logger::log('backup_imported', 'backup', 0, $result);
        wp_safe_redirect(add_query_arg([
            'page' => 'htp-settings',
            'htp_message' => 'backup_imported',
        ], admin_url('admin.php')));
        exit;
    }

    public static function restore_server_backup(): void
    {
        self::assert_admin();
        $file = sanitize_file_name((string) ($_GET['file'] ?? ''));
        check_admin_referer('htp_restore_server_backup_' . $file);

        $path = trailingslashit(self::backup_directory()) . $file;
        if ($file === '' || !is_file($path) || !str_ends_with(strtolower($file), '.htpbackup')) {
            wp_die('Không tìm thấy file sao lưu trên máy chủ.');
        }

        try {
            $result = self::restore_backup_file($path);
        } catch (Throwable $exception) {
            wp_die(esc_html('Khôi phục thất bại: ' . $exception->getMessage()));
        }

        HTP_Activity_Logger::log('server_backup_restored', 'backup', 0, array_merge($result, ['file' => $file]));
        wp_safe_redirect(add_query_arg([
            'page' => 'htp-settings',
            'htp_message' => 'backup_imported',
        ], admin_url('admin.php')));
        exit;
    }

    public static function delete_server_backup(): void
    {
        self::assert_admin();
        $file = sanitize_file_name((string) ($_GET['file'] ?? ''));
        check_admin_referer('htp_delete_server_backup_' . $file);

        $path = trailingslashit(self::backup_directory()) . $file;
        if ($file !== '' && is_file($path) && str_ends_with(strtolower($file), '.htpbackup')) {
            @unlink($path);
        }

        wp_safe_redirect(add_query_arg([
            'page' => 'htp-settings',
            'htp_message' => 'backup_deleted',
        ], admin_url('admin.php')));
        exit;
    }

    public static function create_server_backup(string $reason = 'auto'): ?string
    {
        try {
            $directory = self::backup_directory();
            self::protect_backup_directory($directory);
            return self::create_backup_file($reason, $directory);
        } catch (Throwable $exception) {
            error_log('[HienTocPlugin] Backup before uninstall failed: ' . $exception->getMessage()); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
            return null;
        }
    }

    public static function server_backups(int $limit = 10): array
    {
        $directory = self::backup_directory();
        if (!is_dir($directory)) {
            return [];
        }

        $files = glob(trailingslashit($directory) . '*.htpbackup') ?: [];
        usort($files, static fn(string $a, string $b): int => filemtime($b) <=> filemtime($a));
        $files = array_slice($files, 0, max(1, $limit));

        return array_map(static function (string $path): array {
            return [
                'name' => basename($path),
                'path' => $path,
                'size' => (int) filesize($path),
                'modified' => (int) filemtime($path),
            ];
        }, $files);
    }

    public static function create_backup_file(string $reason = 'manual', ?string $directory = null): string
    {
        $directory = $directory ?: get_temp_dir();
        if (!is_dir($directory) && !wp_mkdir_p($directory)) {
            throw new RuntimeException('Không thể tạo thư mục sao lưu.');
        }

        $payload = self::build_payload($reason);
        $filename = sprintf(
            'myhair-backup-%s-%s.htpbackup',
            sanitize_file_name($reason),
            gmdate('Ymd-His')
        );
        $path = trailingslashit($directory) . $filename;

        if (class_exists('ZipArchive')) {
            self::write_zip_backup($path, $payload);
        } else {
            self::write_gzip_backup($path, $payload);
        }

        return $path;
    }

    public static function restore_backup_file(string $path): array
    {
        if (!is_file($path) || !is_readable($path)) {
            throw new RuntimeException('Không đọc được file sao lưu.');
        }

        $package = self::read_package($path);
        $manifest = $package['manifest'];
        if (($manifest['magic'] ?? '') !== self::MAGIC) {
            throw new RuntimeException('Đây không phải file sao lưu của MyHair.');
        }
        if ((int) ($manifest['format_version'] ?? 0) > self::FORMAT_VERSION) {
            throw new RuntimeException('File sao lưu được tạo bởi phiên bản mới hơn. Hãy cập nhật plugin trước khi khôi phục.');
        }
        if (empty($manifest['tables']) || !is_array($manifest['tables'])) {
            throw new RuntimeException('File sao lưu không có dữ liệu bảng.');
        }

        $user_map = self::resolve_user_map((array) ($manifest['users'] ?? []));
        $attachment_map = self::restore_attachments((array) ($manifest['attachments'] ?? []), $package);
        $page_map = self::restore_pages((array) ($manifest['pages'] ?? []));

        self::restore_tables((array) $manifest['tables'], $user_map, $attachment_map, $page_map);
        self::restore_options((array) ($manifest['options'] ?? []));
        self::restore_user_meta((array) ($manifest['user_meta'] ?? []), $user_map);

        update_option('htp_db_version', defined('HTP_DB_VERSION') ? HTP_DB_VERSION : (string) get_option('htp_db_version', ''));
        if (class_exists('HTP_Owner_Service')) {
            HTP_Owner_Service::maybe_upgrade();
        }
        flush_rewrite_rules(false);

        return [
            'tables' => count($manifest['tables']),
            'attachments' => count($attachment_map),
            'pages' => count($page_map),
            'exported_at' => (string) ($manifest['exported_at'] ?? ''),
        ];
    }

    private static function build_payload(string $reason): array
    {
        global $wpdb;

        $tables = [];
        foreach (self::TABLE_SUFFIXES as $suffix) {
            $table = $wpdb->prefix . $suffix;
            if (!self::table_exists($table)) {
                $tables[$suffix] = [];
                continue;
            }
            $tables[$suffix] = $wpdb->get_results("SELECT * FROM `{$table}`", ARRAY_A) ?: []; // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        }

        $salons = $tables['htp_salons'] ?? [];
        $submission_files = $tables['htp_submission_files'] ?? [];
        $attachments = self::collect_attachments($salons, $submission_files);
        $pages = self::collect_pages($salons);
        $users = self::collect_users($tables);

        return [
            'magic' => self::MAGIC,
            'format_version' => self::FORMAT_VERSION,
            'plugin_version' => defined('HTP_VERSION') ? HTP_VERSION : 'unknown',
            'db_version' => defined('HTP_DB_VERSION') ? HTP_DB_VERSION : (string) get_option('htp_db_version', ''),
            'site_url' => home_url('/'),
            'exported_at' => gmdate('c'),
            'reason' => sanitize_key($reason),
            'tables' => $tables,
            'options' => self::collect_options(),
            'pages' => $pages,
            'attachments' => $attachments,
            'users' => $users,
            'user_meta' => self::collect_user_meta(array_keys($users)),
        ];
    }

    private static function collect_options(): array
    {
        global $wpdb;
        $rows = $wpdb->get_results(
            "SELECT option_name, option_value FROM {$wpdb->options} WHERE option_name LIKE 'htp\\_%'",
            ARRAY_A
        ) ?: [];

        $result = [];
        foreach ($rows as $row) {
            $name = (string) $row['option_name'];
            if (in_array($name, self::STRUCTURAL_OPTIONS, true)) {
                continue;
            }
            $result[$name] = (string) $row['option_value'];
        }
        return $result;
    }

    private static function collect_pages(array $salons): array
    {
        $pages = [];
        foreach ($salons as $salon) {
            $page_id = absint($salon['landing_page_id'] ?? 0);
            if (!$page_id || isset($pages[$page_id])) {
                continue;
            }
            $post = get_post($page_id);
            if (!$post instanceof WP_Post || $post->post_type !== 'page') {
                continue;
            }
            $pages[$page_id] = [
                'old_id' => $page_id,
                'post_title' => $post->post_title,
                'post_name' => $post->post_name,
                'post_content' => $post->post_content,
                'post_excerpt' => $post->post_excerpt,
                'post_status' => $post->post_status,
                'menu_order' => (int) $post->menu_order,
                'page_template' => (string) get_post_meta($page_id, '_wp_page_template', true),
                'plugin_created' => (string) get_post_meta($page_id, '_htp_created_page', true),
                'salon_id' => absint(get_post_meta($page_id, '_htp_salon_id', true)),
            ];
        }
        return $pages;
    }

    private static function collect_attachments(array $salons, array $submission_files): array
    {
        $ids = [];
        foreach ($submission_files as $row) {
            $ids[] = absint($row['attachment_id'] ?? 0);
        }
        foreach ($salons as $salon) {
            $ids[] = absint($salon['logo_id'] ?? 0);
            $ids[] = absint($salon['cover_image_id'] ?? 0);
        }
        $ids = array_values(array_unique(array_filter($ids)));

        $result = [];
        foreach ($ids as $attachment_id) {
            $post = get_post($attachment_id);
            $file = get_attached_file($attachment_id);
            if (!$post instanceof WP_Post || $post->post_type !== 'attachment' || !$file || !is_file($file) || !is_readable($file)) {
                continue;
            }
            $result[$attachment_id] = [
                'old_id' => $attachment_id,
                'filename' => basename($file),
                'mime_type' => (string) get_post_mime_type($attachment_id),
                'title' => $post->post_title,
                'caption' => $post->post_excerpt,
                'description' => $post->post_content,
                'alt' => (string) get_post_meta($attachment_id, '_wp_attachment_image_alt', true),
                'is_submission_file' => (int) get_post_meta($attachment_id, '_htp_submission_file', true) === 1,
                'source_path' => $file,
            ];
        }
        return $result;
    }

    private static function collect_users(array $tables): array
    {
        $ids = [];
        $collect = static function (array $rows, array $columns) use (&$ids): void {
            foreach ($rows as $row) {
                foreach ($columns as $column) {
                    $id = absint($row[$column] ?? 0);
                    if ($id) {
                        $ids[$id] = true;
                    }
                }
            }
        };

        $collect($tables['htp_salons'] ?? [], ['owner_user_id']);
        $collect($tables['htp_submissions'] ?? [], ['salon_owner_user_id', 'created_by', 'updated_by']);
        $collect($tables['htp_submission_logs'] ?? [], ['changed_by']);
        $collect($tables['htp_user_salons'] ?? [], ['user_id']);
        $collect($tables['htp_activity_logs'] ?? [], ['user_id']);

        $result = [];
        foreach (array_keys($ids) as $id) {
            $user = get_user_by('id', $id);
            if (!$user instanceof WP_User) {
                continue;
            }
            $result[$id] = [
                'old_id' => $id,
                'user_login' => $user->user_login,
                'user_email' => $user->user_email,
                'display_name' => $user->display_name,
            ];
        }
        return $result;
    }

    private static function collect_user_meta(array $user_ids): array
    {
        $result = [];
        foreach ($user_ids as $user_id) {
            $result[$user_id] = [
                'htp_disabled' => get_user_meta((int) $user_id, 'htp_disabled', true),
                'htp_last_login' => get_user_meta((int) $user_id, 'htp_last_login', true),
            ];
        }
        return $result;
    }

    private static function write_zip_backup(string $path, array $payload): void
    {
        $zip = new ZipArchive();
        if ($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException('Không thể tạo gói sao lưu ZIP.');
        }

        $attachments = $payload['attachments'];
        foreach ($attachments as $old_id => &$attachment) {
            $source = (string) ($attachment['source_path'] ?? '');
            unset($attachment['source_path']);
            if ($source === '' || !is_file($source)) {
                continue;
            }
            $archive_path = 'media/' . absint($old_id) . '/' . sanitize_file_name((string) $attachment['filename']);
            if (!$zip->addFile($source, $archive_path)) {
                $zip->close();
                @unlink($path);
                throw new RuntimeException('Không thể thêm ảnh vào file sao lưu.');
            }
            $attachment['archive_path'] = $archive_path;
        }
        unset($attachment);
        $payload['attachments'] = $attachments;

        $json = wp_json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($json === false || !$zip->addFromString('manifest.json', $json)) {
            $zip->close();
            @unlink($path);
            throw new RuntimeException('Không thể ghi manifest sao lưu.');
        }
        $zip->close();
    }

    private static function write_gzip_backup(string $path, array $payload): void
    {
        foreach ($payload['attachments'] as &$attachment) {
            $source = (string) ($attachment['source_path'] ?? '');
            unset($attachment['source_path']);
            if ($source !== '' && is_file($source)) {
                $raw = file_get_contents($source); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
                if ($raw !== false) {
                    $attachment['data_base64'] = base64_encode($raw);
                }
            }
        }
        unset($attachment);

        $json = wp_json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            throw new RuntimeException('Không thể mã hóa dữ liệu sao lưu.');
        }
        $bytes = function_exists('gzencode') ? gzencode($json, 6) : $json;
        if (file_put_contents($path, $bytes, LOCK_EX) === false) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
            throw new RuntimeException('Không thể ghi file sao lưu.');
        }
    }

    private static function read_package(string $path): array
    {
        $signature = file_get_contents($path, false, null, 0, 4); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
        if ($signature !== false && str_starts_with($signature, "PK") && class_exists('ZipArchive')) {
            $zip = new ZipArchive();
            if ($zip->open($path) !== true) {
                throw new RuntimeException('Không mở được file sao lưu ZIP.');
            }
            $json = $zip->getFromName('manifest.json');
            if ($json === false) {
                $zip->close();
                throw new RuntimeException('File sao lưu thiếu manifest.json.');
            }
            $manifest = json_decode($json, true);
            if (!is_array($manifest)) {
                $zip->close();
                throw new RuntimeException('Manifest sao lưu không hợp lệ.');
            }
            return ['manifest' => $manifest, 'zip' => $zip, 'path' => $path];
        }

        $raw = file_get_contents($path); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
        if ($raw === false) {
            throw new RuntimeException('Không đọc được file sao lưu.');
        }
        if (str_starts_with($raw, "\x1f\x8b") && function_exists('gzdecode')) {
            $decoded = gzdecode($raw);
            if ($decoded === false) {
                throw new RuntimeException('Không giải nén được file sao lưu.');
            }
            $raw = $decoded;
        }
        $manifest = json_decode($raw, true);
        if (!is_array($manifest)) {
            throw new RuntimeException('Nội dung file sao lưu không hợp lệ.');
        }
        return ['manifest' => $manifest, 'zip' => null, 'path' => $path];
    }

    private static function resolve_user_map(array $users): array
    {
        $map = [];
        foreach ($users as $old_id => $snapshot) {
            $old_id = absint($old_id);
            if (!$old_id) {
                continue;
            }
            $existing = get_user_by('id', $old_id);
            if ($existing instanceof WP_User) {
                $map[$old_id] = (int) $existing->ID;
                continue;
            }
            $email = sanitize_email((string) ($snapshot['user_email'] ?? ''));
            if ($email !== '') {
                $existing = get_user_by('email', $email);
            }
            if (!$existing instanceof WP_User) {
                $login = sanitize_user((string) ($snapshot['user_login'] ?? ''), true);
                $existing = $login !== '' ? get_user_by('login', $login) : false;
            }
            $map[$old_id] = $existing instanceof WP_User ? (int) $existing->ID : 0;
        }
        return $map;
    }

    private static function restore_attachments(array $attachments, array $package): array
    {
        if (!$attachments) {
            if ($package['zip'] instanceof ZipArchive) {
                $package['zip']->close();
            }
            return [];
        }

        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/image.php';

        $map = [];
        foreach ($attachments as $old_id => $attachment) {
            $old_id = absint($old_id);
            if (!$old_id) {
                continue;
            }

            $existing = get_post($old_id);
            $existing_file = $existing instanceof WP_Post && $existing->post_type === 'attachment' ? get_attached_file($old_id) : '';
            if ($existing instanceof WP_Post && $existing->post_type === 'attachment' && $existing_file && is_file($existing_file)) {
                $map[$old_id] = $old_id;
                continue;
            }

            $bytes = null;
            if ($package['zip'] instanceof ZipArchive && !empty($attachment['archive_path'])) {
                $bytes = $package['zip']->getFromName((string) $attachment['archive_path']);
            } elseif (!empty($attachment['data_base64'])) {
                $bytes = base64_decode((string) $attachment['data_base64'], true);
            }
            if (!is_string($bytes)) {
                continue;
            }

            $filename = sanitize_file_name((string) ($attachment['filename'] ?? ('htp-' . $old_id . '.jpg')));
            $upload = wp_upload_bits($filename, null, $bytes);
            if (!empty($upload['error'])) {
                throw new RuntimeException('Không thể phục hồi ảnh ' . $filename . ': ' . $upload['error']);
            }

            $attachment_id = wp_insert_attachment([
                'post_mime_type' => sanitize_mime_type((string) ($attachment['mime_type'] ?? 'image/jpeg')),
                'post_title' => sanitize_text_field((string) ($attachment['title'] ?? pathinfo($filename, PATHINFO_FILENAME))),
                'post_excerpt' => sanitize_textarea_field((string) ($attachment['caption'] ?? '')),
                'post_content' => wp_kses_post((string) ($attachment['description'] ?? '')),
                'post_status' => 'inherit',
            ], $upload['file']);
            if (is_wp_error($attachment_id) || !$attachment_id) {
                throw new RuntimeException('Không thể tạo attachment khi khôi phục.');
            }

            $metadata = wp_generate_attachment_metadata((int) $attachment_id, $upload['file']);
            wp_update_attachment_metadata((int) $attachment_id, $metadata);
            update_post_meta((int) $attachment_id, '_wp_attachment_image_alt', sanitize_text_field((string) ($attachment['alt'] ?? '')));
            if (!empty($attachment['is_submission_file'])) {
                update_post_meta((int) $attachment_id, '_htp_submission_file', 1);
            }
            $map[$old_id] = (int) $attachment_id;
        }

        if ($package['zip'] instanceof ZipArchive) {
            $package['zip']->close();
        }
        return $map;
    }

    private static function restore_pages(array $pages): array
    {
        $map = [];
        foreach ($pages as $old_id => $page) {
            $old_id = absint($old_id);
            if (!$old_id) {
                continue;
            }

            $existing = get_post($old_id);
            if ($existing instanceof WP_Post && $existing->post_type === 'page') {
                $map[$old_id] = $old_id;
                continue;
            }

            $new_id = wp_insert_post([
                'post_type' => 'page',
                'post_title' => sanitize_text_field((string) ($page['post_title'] ?? 'Salon MyHair')),
                'post_name' => sanitize_title((string) ($page['post_name'] ?? '')),
                'post_content' => wp_kses_post((string) ($page['post_content'] ?? '[htp_salon_landing]')),
                'post_excerpt' => sanitize_textarea_field((string) ($page['post_excerpt'] ?? '')),
                'post_status' => in_array(($page['post_status'] ?? 'publish'), ['publish', 'draft', 'private', 'pending'], true) ? $page['post_status'] : 'publish',
                'menu_order' => (int) ($page['menu_order'] ?? 0),
                'comment_status' => 'closed',
            ], true);
            if (is_wp_error($new_id)) {
                throw new RuntimeException('Không thể phục hồi landing page: ' . $new_id->get_error_message());
            }

            if (!empty($page['page_template'])) {
                update_post_meta((int) $new_id, '_wp_page_template', sanitize_text_field((string) $page['page_template']));
            }
            if (!empty($page['plugin_created'])) {
                update_post_meta((int) $new_id, '_htp_created_page', sanitize_text_field((string) $page['plugin_created']));
            }
            if (!empty($page['salon_id'])) {
                update_post_meta((int) $new_id, '_htp_salon_id', absint($page['salon_id']));
            }
            $map[$old_id] = (int) $new_id;
        }
        return $map;
    }

    private static function restore_tables(array $tables, array $user_map, array $attachment_map, array $page_map): void
    {
        global $wpdb;

        $wpdb->query('START TRANSACTION');
        try {
            foreach (array_reverse(self::TABLE_SUFFIXES) as $suffix) {
                $table = $wpdb->prefix . $suffix;
                if (self::table_exists($table)) {
                    $wpdb->query("DELETE FROM `{$table}`"); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
                }
            }

            foreach (self::TABLE_SUFFIXES as $suffix) {
                $table = $wpdb->prefix . $suffix;
                if (!self::table_exists($table)) {
                    continue;
                }
                $columns = self::table_columns($table);
                foreach ((array) ($tables[$suffix] ?? []) as $row) {
                    $row = self::transform_row($suffix, (array) $row, $user_map, $attachment_map, $page_map);
                    $row = array_intersect_key($row, array_flip($columns));
                    if (!$row) {
                        continue;
                    }
                    if ($wpdb->insert($table, $row) === false) {
                        throw new RuntimeException('Không thể phục hồi bảng ' . $suffix . '.');
                    }
                }
            }
            $wpdb->query('COMMIT');
        } catch (Throwable $exception) {
            $wpdb->query('ROLLBACK');
            throw $exception;
        }
    }

    private static function transform_row(string $suffix, array $row, array $user_map, array $attachment_map, array $page_map): array
    {
        $map_user = static fn(mixed $value): ?int => ($id = absint($value)) ? (($user_map[$id] ?? 0) ?: null) : null;
        $map_attachment = static fn(mixed $value): ?int => ($id = absint($value)) ? (($attachment_map[$id] ?? 0) ?: null) : null;
        $map_page = static fn(mixed $value): ?int => ($id = absint($value)) ? (($page_map[$id] ?? 0) ?: null) : null;

        if ($suffix === 'htp_salons') {
            if (array_key_exists('owner_user_id', $row)) $row['owner_user_id'] = $map_user($row['owner_user_id']);
            if (array_key_exists('logo_id', $row)) $row['logo_id'] = $map_attachment($row['logo_id']);
            if (array_key_exists('cover_image_id', $row)) $row['cover_image_id'] = $map_attachment($row['cover_image_id']);
            if (array_key_exists('landing_page_id', $row)) $row['landing_page_id'] = $map_page($row['landing_page_id']);
        } elseif ($suffix === 'htp_submissions') {
            foreach (['salon_owner_user_id', 'created_by', 'updated_by'] as $key) {
                if (array_key_exists($key, $row)) $row[$key] = $map_user($row[$key]);
            }
        } elseif ($suffix === 'htp_submission_files') {
            if (array_key_exists('attachment_id', $row)) $row['attachment_id'] = $map_attachment($row['attachment_id']);
            if (empty($row['attachment_id'])) return [];
        } elseif ($suffix === 'htp_submission_logs') {
            if (array_key_exists('changed_by', $row)) $row['changed_by'] = $map_user($row['changed_by']);
        } elseif ($suffix === 'htp_user_salons') {
            if (array_key_exists('user_id', $row)) $row['user_id'] = $map_user($row['user_id']);
            if (empty($row['user_id'])) return [];
        } elseif ($suffix === 'htp_activity_logs') {
            if (array_key_exists('user_id', $row)) $row['user_id'] = $map_user($row['user_id']);
        }

        return $row;
    }

    private static function restore_options(array $options): void
    {
        foreach ($options as $name => $raw_value) {
            $name = sanitize_key((string) $name);
            if (!str_starts_with($name, 'htp_') || in_array($name, self::STRUCTURAL_OPTIONS, true)) {
                continue;
            }
            update_option($name, maybe_unserialize((string) $raw_value));
        }
    }

    private static function restore_user_meta(array $user_meta, array $user_map): void
    {
        foreach ($user_meta as $old_user_id => $meta) {
            $old_user_id = absint($old_user_id);
            $new_user_id = absint($user_map[$old_user_id] ?? 0);
            if (!$new_user_id) {
                continue;
            }
            foreach (['htp_disabled', 'htp_last_login'] as $key) {
                if (array_key_exists($key, (array) $meta)) {
                    update_user_meta($new_user_id, $key, sanitize_text_field((string) $meta[$key]));
                }
            }
        }
    }

    private static function backup_directory(): string
    {
        return trailingslashit(WP_CONTENT_DIR) . 'htp-backups';
    }

    private static function protect_backup_directory(string $directory): void
    {
        if (!is_dir($directory) && !wp_mkdir_p($directory)) {
            throw new RuntimeException('Không thể tạo thư mục backup trên máy chủ.');
        }

        $files = [
            'index.php' => "<?php\n// Silence is golden.\n",
            '.htaccess' => "Options -Indexes\n<FilesMatch \".*\">\nRequire all denied\nDeny from all\n</FilesMatch>\n",
            'web.config' => "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<configuration><system.webServer><directoryBrowse enabled=\"false\"/><authorization><deny users=\"*\"/></authorization></system.webServer></configuration>\n",
        ];
        foreach ($files as $name => $content) {
            $path = trailingslashit($directory) . $name;
            if (!is_file($path)) {
                @file_put_contents($path, $content, LOCK_EX); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
            }
        }
    }

    private static function table_exists(string $table): bool
    {
        global $wpdb;
        return $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table)) === $table;
    }

    private static function table_columns(string $table): array
    {
        global $wpdb;
        $rows = $wpdb->get_results("SHOW COLUMNS FROM `{$table}`", ARRAY_A) ?: []; // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        return array_values(array_filter(array_map(static fn(array $row): string => (string) ($row['Field'] ?? ''), $rows)));
    }

    private static function assert_admin(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die('Chỉ quản trị viên WordPress mới được sao lưu hoặc khôi phục dữ liệu.');
        }
    }
}
