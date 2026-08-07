<?php

defined('ABSPATH') || exit;

final class HTP_Settings
{
    public static function init(): void
    {
        add_action('admin_menu', [self::class, 'register_page']);
        add_action('admin_init', [self::class, 'register_settings']);
        add_action('admin_init', [self::class, 'repair_lookup_page'], 30);
        add_action('admin_post_htp_enable_pretty_permalinks', [self::class, 'enable_pretty_permalinks']);
        add_action('admin_post_htp_export_backup', [HTP_Backup_Service::class, 'export_download']);
        add_action('admin_post_htp_import_backup', [HTP_Backup_Service::class, 'import_uploaded']);
        add_action('admin_post_htp_restore_server_backup', [HTP_Backup_Service::class, 'restore_server_backup']);
        add_action('admin_post_htp_delete_server_backup', [HTP_Backup_Service::class, 'delete_server_backup']);
        add_action('admin_post_htp_test_google_sheets', [self::class, 'test_google_sheets']);
        add_action('admin_post_htp_sync_all_google_sheets', [self::class, 'sync_all_google_sheets']);
        add_action('admin_post_htp_process_google_sheets_queue', [self::class, 'process_google_sheets_queue']);
    }

    public static function register_page(): void
    {
        add_submenu_page('htp-dashboard', 'Cài đặt', 'Cài đặt', 'htp_manage_settings', 'htp-settings', [self::class, 'render']);
    }

    public static function register_settings(): void
    {
        $settings = [
            'htp_lookup_page_id' => ['type' => 'integer', 'sanitize_callback' => 'absint', 'default' => 0],
            'htp_oa_url' => ['type' => 'string', 'sanitize_callback' => 'esc_url_raw', 'default' => ''],
            'htp_oa_button_label' => ['type' => 'string', 'sanitize_callback' => 'sanitize_text_field', 'default' => 'Quan tâm OA MyHair'],
            'htp_upload_max_mb' => ['type' => 'integer', 'sanitize_callback' => [self::class, 'sanitize_upload_mb'], 'default' => 5],
            'htp_hair_photo_limit' => ['type' => 'integer', 'sanitize_callback' => [self::class, 'sanitize_photo_limit'], 'default' => 3],
            'htp_duplicate_days' => ['type' => 'integer', 'sanitize_callback' => [self::class, 'sanitize_duplicate_days'], 'default' => 30],
            'htp_privacy_text' => ['type' => 'string', 'sanitize_callback' => 'sanitize_textarea_field', 'default' => 'Tôi đồng ý cung cấp thông tin cho chương trình MyHair.'],
        ];
        foreach ($settings as $name => $args) {
            register_setting('htp_settings', $name, $args);
        }

        $google_settings = [
            'htp_google_sheets_enabled' => ['type' => 'boolean', 'sanitize_callback' => [self::class, 'sanitize_checkbox'], 'default' => 0],
            'htp_google_sheets_webhook_url' => ['type' => 'string', 'sanitize_callback' => 'esc_url_raw', 'default' => ''],
            'htp_google_sheets_secret' => ['type' => 'string', 'sanitize_callback' => 'sanitize_text_field', 'default' => ''],
            'htp_google_sheets_donation_tab' => ['type' => 'string', 'sanitize_callback' => 'sanitize_text_field', 'default' => 'Hien toc'],
            'htp_google_sheets_member_tab' => ['type' => 'string', 'sanitize_callback' => 'sanitize_text_field', 'default' => 'Thanh vien'],
        ];
        foreach ($google_settings as $name => $args) {
            register_setting('htp_google_sheets_settings', $name, $args);
        }
    }

    public static function render(): void
    {
        if (!current_user_can('htp_manage_settings')) {
            wp_die('Bạn không có quyền truy cập.');
        }
        HTP_Admin::notice_from_query();
        $structure = (string) get_option('permalink_structure', '');
        $pretty = $structure !== '' && !str_contains($structure, 'index.php');
        $server_backups = current_user_can('manage_options') ? HTP_Backup_Service::server_backups(10) : [];
        $pending_sync = current_user_can('manage_options') ? HTP_Google_Sheets_Service::pending_count() : 0;
        $sync_errors = current_user_can('manage_options') ? HTP_Google_Sheets_Service::recent_errors(5) : [];
        $last_sync_at = (string) get_option('htp_google_sheets_last_sync_at', '');
        $last_summary = json_decode((string) get_option('htp_google_sheets_last_sync_summary', ''), true);
        ?>
        <div class="wrap htp-admin-wrap">
            <h1>Cài đặt MyHair</h1>

            <?php if (current_user_can('manage_options')) : ?>
                <section class="htp-panel">
                    <h2>Đồng bộ Google Sheets</h2>
                    <p>Kết nối bằng <strong>Google Apps Script Web App</strong>, phù hợp cả hosting PHP thông thường và không cần cài thư viện Google API trên máy chủ.</p>
                    <p>Mỗi đăng ký mới hoặc thay đổi trạng thái sẽ được đưa vào hàng đợi. Nếu Google tạm lỗi, plugin giữ dữ liệu trong database và tự thử lại theo chu kỳ, nên việc đăng ký của khách không phụ thuộc vào Google Sheets.</p>

                    <form method="post" action="options.php">
                        <?php settings_fields('htp_google_sheets_settings'); ?>
                        <table class="form-table" role="presentation">
                            <tr><th>Trạng thái</th><td><label><input type="checkbox" name="htp_google_sheets_enabled" value="1" <?php checked((int) get_option('htp_google_sheets_enabled', 0), 1); ?>> Bật đồng bộ Google Sheets</label></td></tr>
                            <tr><th><label for="htp-google-webhook">Apps Script Web App URL</label></th><td><input id="htp-google-webhook" name="htp_google_sheets_webhook_url" type="url" class="large-text" placeholder="https://script.google.com/macros/s/.../exec" value="<?php echo esc_attr((string) get_option('htp_google_sheets_webhook_url', '')); ?>"><p class="description">Dùng URL kết thúc bằng <code>/exec</code> sau khi Deploy Apps Script thành Web App.</p></td></tr>
                            <tr><th><label for="htp-google-secret">Secret key</label></th><td><input id="htp-google-secret" name="htp_google_sheets_secret" type="password" class="regular-text" autocomplete="new-password" value="<?php echo esc_attr((string) get_option('htp_google_sheets_secret', '')); ?>"><p class="description">Nhập cùng một chuỗi bí mật trong WordPress và biến <code>MYHAIR_SECRET</code> của Apps Script.</p></td></tr>
                            <tr><th><label for="htp-google-donation-tab">Sheet hiến tóc</label></th><td><input id="htp-google-donation-tab" name="htp_google_sheets_donation_tab" class="regular-text" value="<?php echo esc_attr((string) get_option('htp_google_sheets_donation_tab', 'Hien toc')); ?>"></td></tr>
                            <tr><th><label for="htp-google-member-tab">Sheet thành viên</label></th><td><input id="htp-google-member-tab" name="htp_google_sheets_member_tab" class="regular-text" value="<?php echo esc_attr((string) get_option('htp_google_sheets_member_tab', 'Thanh vien')); ?>"></td></tr>
                        </table>
                        <?php submit_button('Lưu cấu hình Google Sheets'); ?>
                    </form>

                    <div class="htp-two-column">
                        <div>
                            <h3>Thiết lập Google Sheet</h3>
                            <ol>
                                <li>Tạo hoặc mở Google Sheet muốn nhận dữ liệu.</li>
                                <li>Vào <strong>Extensions → Apps Script</strong>.</li>
                                <li>Mở mã mẫu của plugin, dán vào Apps Script và đổi <code>MYHAIR_SECRET</code> cho trùng với Secret key ở trên.</li>
                                <li>Chọn <strong>Deploy → New deployment → Web app</strong>, chạy dưới tài khoản của bạn và cấp quyền truy cập Web App phù hợp.</li>
                                <li>Dán URL <code>/exec</code> vào cấu hình rồi bấm <strong>Kiểm tra kết nối</strong>.</li>
                            </ol>
                            <p><a class="button" href="<?php echo esc_url(HTP_URL . 'docs/google-sheets-apps-script.gs'); ?>" target="_blank" rel="noopener">Mở mã Apps Script mẫu</a></p>
                        </div>
                        <div>
                            <h3>Tình trạng đồng bộ</h3>
                            <p><strong>Hàng đợi:</strong> <?php echo esc_html(number_format_i18n($pending_sync)); ?> bản ghi.</p>
                            <p><strong>Lần xử lý gần nhất:</strong> <?php echo esc_html($last_sync_at ? mysql2date('d/m/Y H:i:s', $last_sync_at) : 'Chưa có'); ?></p>
                            <?php if (is_array($last_summary)) : ?><p><strong>Kết quả:</strong> thành công <?php echo esc_html((string) ($last_summary['success'] ?? 0)); ?> · lỗi <?php echo esc_html((string) ($last_summary['failed'] ?? 0)); ?> · còn chờ <?php echo esc_html((string) ($last_summary['pending'] ?? 0)); ?></p><?php endif; ?>
                            <div class="htp-inline-actions">
                                <a class="button" href="<?php echo esc_url(wp_nonce_url(add_query_arg('action', 'htp_test_google_sheets', admin_url('admin-post.php')), 'htp_test_google_sheets')); ?>">Kiểm tra kết nối</a>
                                <a class="button" href="<?php echo esc_url(wp_nonce_url(add_query_arg('action', 'htp_process_google_sheets_queue', admin_url('admin-post.php')), 'htp_process_google_sheets_queue')); ?>">Đồng bộ hàng đợi ngay</a>
                                <a class="button button-primary" href="<?php echo esc_url(wp_nonce_url(add_query_arg('action', 'htp_sync_all_google_sheets', admin_url('admin-post.php')), 'htp_sync_all_google_sheets')); ?>" onclick="return confirm('Đưa toàn bộ dữ liệu hiện có vào hàng đợi Google Sheets? Các dòng có cùng mã đăng ký sẽ được cập nhật, không tạo trùng.');">Đồng bộ lại toàn bộ dữ liệu</a>
                            </div>
                        </div>
                    </div>

                    <?php if ($sync_errors) : ?>
                        <h3>Lỗi đồng bộ gần đây</h3>
                        <div class="htp-table-wrap"><table class="widefat striped"><thead><tr><th>Mã</th><th>Số lần thử</th><th>Lỗi</th><th>Thử lại sau</th></tr></thead><tbody><?php foreach ($sync_errors as $error) : ?><tr><td><?php echo esc_html($error->submission_code ?: ('#' . $error->submission_id)); ?></td><td><?php echo esc_html((string) $error->attempts); ?></td><td><?php echo esc_html($error->last_error); ?></td><td><?php echo esc_html(mysql2date('d/m/Y H:i:s', $error->available_at)); ?></td></tr><?php endforeach; ?></tbody></table></div>
                    <?php endif; ?>
                </section>

                <section class="htp-panel">
                    <h2>Sao lưu & khôi phục dữ liệu</h2>
                    <p><strong>Nên tạo một bản sao lưu trước khi cập nhật, xóa hoặc cài lại plugin.</strong> File sao lưu MyHair chứa dữ liệu salon, hai form, khách hiến tóc, thành viên, trạng thái, phân quyền salon, cấu hình, landing page và các ảnh được hệ thống quản lý.</p>
                    <p>Khi <strong>xóa plugin</strong>, hệ thống cũng cố gắng tự tạo một bản backup an toàn trên máy chủ trước khi xóa bảng dữ liệu. Sau khi cài lại plugin, các bản backup đó sẽ xuất hiện tại đây.</p>

                    <div class="htp-two-column">
                        <div>
                            <h3>Xuất backup</h3>
                            <p>Tải một file <code>.htpbackup</code> về máy tính. Đây là lựa chọn an toàn nhất trước khi xóa plugin.</p>
                            <a class="button button-primary" href="<?php echo esc_url(wp_nonce_url(add_query_arg('action', 'htp_export_backup', admin_url('admin-post.php')), 'htp_export_backup')); ?>">Tải bản sao lưu đầy đủ</a>
                        </div>
                        <div>
                            <h3>Nhập backup</h3>
                            <p><strong>Lưu ý:</strong> khôi phục sẽ thay thế toàn bộ dữ liệu MyHair hiện có bằng dữ liệu trong file backup. Tài khoản WordPress không bị xóa hoặc tạo mới.</p>
                            <p class="description">Giới hạn upload hiện tại của máy chủ: <?php echo esc_html(size_format(wp_max_upload_size(), 2)); ?>.</p>
                            <form method="post" enctype="multipart/form-data" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" onsubmit="return confirm('Khôi phục sẽ thay thế toàn bộ dữ liệu MyHair hiện tại. Bạn chắc chắn muốn tiếp tục?');">
                                <input type="hidden" name="action" value="htp_import_backup">
                                <?php wp_nonce_field('htp_import_backup'); ?>
                                <input type="file" name="htp_backup_file" accept=".htpbackup,application/octet-stream" required>
                                <?php submit_button('Khôi phục từ file', 'secondary', 'submit', false); ?>
                            </form>
                        </div>
                    </div>

                    <h3>Backup tự động còn trên máy chủ</h3>
                    <?php if (!$server_backups) : ?>
                        <p>Chưa có backup tự động nào.</p>
                    <?php else : ?>
                        <div class="htp-table-wrap">
                            <table class="widefat striped htp-responsive-table">
                                <thead><tr><th>File</th><th>Thời gian</th><th>Dung lượng</th><th>Thao tác</th></tr></thead>
                                <tbody>
                                <?php foreach ($server_backups as $backup) :
                                    $restore_url = wp_nonce_url(add_query_arg([
                                        'action' => 'htp_restore_server_backup',
                                        'file' => $backup['name'],
                                    ], admin_url('admin-post.php')), 'htp_restore_server_backup_' . $backup['name']);
                                    $delete_url = wp_nonce_url(add_query_arg([
                                        'action' => 'htp_delete_server_backup',
                                        'file' => $backup['name'],
                                    ], admin_url('admin-post.php')), 'htp_delete_server_backup_' . $backup['name']);
                                    ?>
                                    <tr>
                                        <td data-label="File"><code><?php echo esc_html($backup['name']); ?></code></td>
                                        <td data-label="Thời gian"><?php echo esc_html(wp_date('d/m/Y H:i:s', (int) $backup['modified'])); ?></td>
                                        <td data-label="Dung lượng"><?php echo esc_html(size_format((int) $backup['size'], 2)); ?></td>
                                        <td data-label="Thao tác"><a class="button" href="<?php echo esc_url($restore_url); ?>" onclick="return confirm('Khôi phục sẽ thay thế toàn bộ dữ liệu MyHair hiện tại. Tiếp tục?');">Khôi phục</a> <a class="button htp-confirm" href="<?php echo esc_url($delete_url); ?>">Xóa backup</a></td>
                                    </tr>
                                <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </section>
            <?php endif; ?>

            <section class="htp-panel">
                <h2>Đường dẫn đẹp</h2>
                <p>Trạng thái: <strong><?php echo $pretty ? 'Đạt' : 'Chưa đạt'; ?></strong></p>
                <p>Cấu trúc hiện tại: <code><?php echo esc_html($structure ?: '(mặc định)'); ?></code></p>
                <?php if (!$pretty) : ?>
                    <p>URL có thể xuất hiện dạng <code>/index.php/salon001/</code>. Bấm nút bên dưới để chuyển sang <code>/%postname%/</code>.</p>
                    <p><strong>Lưu ý:</strong> Máy chủ cần hỗ trợ rewrite. Nếu sau khi bật bị lỗi 404, hãy bật Apache mod_rewrite hoặc cấu hình rewrite trên Nginx/IIS.</p>
                    <a class="button button-primary" href="<?php echo esc_url(wp_nonce_url(add_query_arg('action', 'htp_enable_pretty_permalinks', admin_url('admin-post.php')), 'htp_enable_pretty_permalinks')); ?>">Bật đường dẫn đẹp</a>
                <?php else : ?><p>Trang salon sẽ có dạng <code><?php echo esc_html(home_url('/salon001/')); ?></code>.</p><?php endif; ?>
            </section>

            <form method="post" action="options.php" class="htp-panel">
                <?php settings_fields('htp_settings'); ?>
                <table class="form-table" role="presentation">
                    <tr><th><label for="htp-lookup-page">Trang tra cứu</label></th><td><?php wp_dropdown_pages(['name' => 'htp_lookup_page_id', 'id' => 'htp-lookup-page', 'selected' => absint(get_option('htp_lookup_page_id')), 'show_option_none' => '— Chọn trang —', 'option_none_value' => 0]); ?><p class="description">Trang nên chứa shortcode <code>[htp_registration_lookup]</code>.</p></td></tr>
                    <tr><th><label for="htp-oa-url">URL OA MyHair chung</label></th><td><input id="htp-oa-url" name="htp_oa_url" type="url" class="regular-text" value="<?php echo esc_attr((string) get_option('htp_oa_url', '')); ?>"><p class="description">Salon có thể nhập URL riêng; nếu để trống sẽ dùng URL này.</p></td></tr>
                    <tr><th><label for="htp-oa-label">Nhãn nút OA</label></th><td><input id="htp-oa-label" name="htp_oa_button_label" class="regular-text" value="<?php echo esc_attr((string) get_option('htp_oa_button_label', 'Quan tâm OA MyHair')); ?>"></td></tr>
                    <tr><th><label for="htp-upload-max">Dung lượng tối đa mỗi ảnh</label></th><td><input id="htp-upload-max" name="htp_upload_max_mb" type="number" min="1" max="20" value="<?php echo esc_attr((string) get_option('htp_upload_max_mb', 5)); ?>"> MB</td></tr>
                    <tr><th><label for="htp-photo-limit">Số ảnh tóc tối đa</label></th><td><input id="htp-photo-limit" name="htp_hair_photo_limit" type="number" min="1" max="10" value="<?php echo esc_attr((string) get_option('htp_hair_photo_limit', 3)); ?>"></td></tr>
                    <tr><th><label for="htp-duplicate-days">Khoảng cảnh báo trùng</label></th><td><input id="htp-duplicate-days" name="htp_duplicate_days" type="number" min="1" max="365" value="<?php echo esc_attr((string) get_option('htp_duplicate_days', 30)); ?>"> ngày</td></tr>
                    <tr><th><label for="htp-privacy">Nội dung đồng ý chung</label></th><td><textarea id="htp-privacy" name="htp_privacy_text" class="large-text" rows="3"><?php echo esc_textarea((string) get_option('htp_privacy_text', '')); ?></textarea><p class="description">Có thể thay đổi riêng nhãn trường đồng ý trong Cấu hình form.</p></td></tr>
                </table>
                <?php submit_button('Lưu cài đặt'); ?>
            </form>
        </div>
        <?php
    }

    public static function enable_pretty_permalinks(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die('Không có quyền.');
        }
        check_admin_referer('htp_enable_pretty_permalinks');
        update_option('permalink_structure', '/%postname%/');
        flush_rewrite_rules(true);
        wp_safe_redirect(add_query_arg(['page' => 'htp-settings', 'htp_message' => 'permalink'], admin_url('admin.php')));
        exit;
    }

    public static function repair_lookup_page(): void
    {
        if (!current_user_can('manage_options')) {
            return;
        }

        $page_id = absint(get_option('htp_lookup_page_id', 0));
        $page = $page_id ? get_post($page_id) : null;
        if ($page instanceof WP_Post && $page->post_type === 'page') {
            return;
        }

        $existing = get_page_by_path('tra-cuu-myhair', OBJECT, 'page');
        if ($existing instanceof WP_Post) {
            update_option('htp_lookup_page_id', (int) $existing->ID);
            return;
        }

        $new_id = wp_insert_post([
            'post_title' => 'Tra cứu đăng ký MyHair',
            'post_name' => 'tra-cuu-myhair',
            'post_content' => '[htp_registration_lookup]',
            'post_status' => 'publish',
            'post_type' => 'page',
            'comment_status' => 'closed',
        ]);
        if (!is_wp_error($new_id) && $new_id) {
            update_post_meta((int) $new_id, '_htp_created_page', 'lookup');
            update_option('htp_lookup_page_id', (int) $new_id);
        }
    }

    public static function test_google_sheets(): void
    {
        self::assert_admin();
        check_admin_referer('htp_test_google_sheets');
        try {
            HTP_Google_Sheets_Service::test_connection();
        } catch (Throwable $exception) {
            wp_die(esc_html('Kiểm tra Google Sheets thất bại: ' . $exception->getMessage()));
        }
        wp_safe_redirect(add_query_arg(['page' => 'htp-settings', 'htp_message' => 'google_test_ok'], admin_url('admin.php')));
        exit;
    }

    public static function sync_all_google_sheets(): void
    {
        self::assert_admin();
        check_admin_referer('htp_sync_all_google_sheets');
        if (!HTP_Google_Sheets_Service::enabled()) {
            wp_die('Hãy bật và lưu cấu hình Google Sheets trước.');
        }
        $count = HTP_Google_Sheets_Service::queue_all();
        HTP_Google_Sheets_Service::process_queue(20);
        HTP_Activity_Logger::log('google_sheets_full_sync_queued', 'integration', 0, ['count' => $count]);
        wp_safe_redirect(add_query_arg(['page' => 'htp-settings', 'htp_message' => 'google_queued'], admin_url('admin.php')));
        exit;
    }

    public static function process_google_sheets_queue(): void
    {
        self::assert_admin();
        check_admin_referer('htp_process_google_sheets_queue');
        if (!HTP_Google_Sheets_Service::enabled()) {
            wp_die('Hãy bật và lưu cấu hình Google Sheets trước.');
        }
        HTP_Google_Sheets_Service::process_queue(50);
        wp_safe_redirect(add_query_arg(['page' => 'htp-settings', 'htp_message' => 'google_processed'], admin_url('admin.php')));
        exit;
    }

    public static function sanitize_upload_mb(mixed $value): int
    {
        return max(1, min(20, absint($value)));
    }

    public static function sanitize_photo_limit(mixed $value): int
    {
        return max(1, min(10, absint($value)));
    }

    public static function sanitize_duplicate_days(mixed $value): int
    {
        return max(1, min(365, absint($value)));
    }

    public static function sanitize_checkbox(mixed $value): int
    {
        return empty($value) ? 0 : 1;
    }

    private static function assert_admin(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die('Bạn không có quyền thực hiện thao tác này.');
        }
    }
}
