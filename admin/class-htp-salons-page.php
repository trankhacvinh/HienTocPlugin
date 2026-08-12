<?php

defined('ABSPATH') || exit;

final class HTP_Salons_Page
{
    public static function init(): void
    {
        add_action('admin_menu', [self::class, 'register_page'], 10);
        add_action('admin_post_htp_save_salon', [self::class, 'save']);
        add_action('admin_post_htp_toggle_salon', [self::class, 'toggle']);
        add_action('admin_post_htp_create_salon_page', [self::class, 'create_landing_page']);
        add_action('admin_post_htp_download_qr', [self::class, 'download_qr']);
        add_action('admin_post_htp_test_salon_google_sheet', [self::class, 'test_google_sheet']);
        add_action('admin_post_htp_sync_salon_google_sheet', [self::class, 'sync_google_sheet']);
    }

    public static function register_page(): void
    {
        add_submenu_page('htp-dashboard', 'Salon', 'Salon', 'htp_manage_salons', 'htp-salons', [self::class, 'render']);
    }

    public static function render(): void
    {
        if (!current_user_can('htp_manage_salons')) {
            wp_die('Bạn không có quyền truy cập.');
        }

        HTP_Admin::notice_from_query();
        $repository = new HTP_Salon_Repository();
        $edit_id = absint($_GET['salon_id'] ?? 0);
        $editing = $edit_id ? $repository->find_by_id($edit_id) : null;
        $editing_sheet = $editing
            ? HTP_Google_Sheets_Service::salon_destination($edit_id)
            : ['enabled' => false, 'spreadsheet_id' => '', 'url' => ''];
        $salons = $repository->all();
        $owner_users = HTP_Owner_Service::owner_users();
        ?>
        <div class="wrap htp-admin-wrap">
            <h1><?php echo $editing ? 'Sửa salon' : 'Quản lý salon'; ?></h1>

            <div class="htp-two-column">
                <section class="htp-panel">
                    <h2><?php echo $editing ? 'Thông tin salon' : 'Thêm salon'; ?></h2>
                    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                        <input type="hidden" name="action" value="htp_save_salon">
                        <input type="hidden" name="salon_id" value="<?php echo esc_attr((string) ($editing->id ?? 0)); ?>">
                        <?php wp_nonce_field('htp_save_salon'); ?>
                        <div class="htp-form-grid">
                            <label><span>Mã salon *</span><input name="code" required pattern="[A-Za-z0-9-]{2,50}" maxlength="50" value="<?php echo esc_attr($editing->code ?? ''); ?>" <?php disabled((bool) $editing); ?>><small>Ví dụ: PHU0001. Mã này là định danh duy nhất của salon.</small></label>
                            <?php if ($editing) : ?><input type="hidden" name="code" value="<?php echo esc_attr($editing->code); ?>"><?php endif; ?>
                            <label><span>Tên salon *</span><input name="name" required maxlength="190" value="<?php echo esc_attr($editing->name ?? ''); ?>"></label>
                            <label><span>Chủ salon chính</span><select name="owner_user_id"><option value="0">— Chưa gán chủ salon —</option><?php foreach ($owner_users as $owner_user) : ?><option value="<?php echo esc_attr((string) $owner_user->ID); ?>" <?php selected(absint($editing->owner_user_id ?? 0), $owner_user->ID); ?>><?php echo esc_html($owner_user->display_name . ' (' . $owner_user->user_login . ')'); ?><?php echo get_user_meta($owner_user->ID, 'htp_disabled', true) ? ' — Đã khóa' : ''; ?></option><?php endforeach; ?></select><small>Khách gửi form từ landing của salon sẽ được ghi nhận thuộc salon này và chủ salon này tại thời điểm đăng ký.</small></label>
                            <label><span>Người phụ trách/liên hệ</span><input name="manager_name" maxlength="190" value="<?php echo esc_attr($editing->manager_name ?? ''); ?>"></label>
                            <label><span>Điện thoại</span><input name="phone" inputmode="tel" maxlength="30" value="<?php echo esc_attr($editing->phone ?? ''); ?>"></label>
                            <label><span>Email</span><input name="email" type="email" maxlength="190" value="<?php echo esc_attr($editing->email ?? ''); ?>"></label>
                            <label><span>Trạng thái</span><select name="status"><option value="active" <?php selected($editing->status ?? 'active', 'active'); ?>>Đang hoạt động</option><option value="inactive" <?php selected($editing->status ?? '', 'inactive'); ?>>Tạm ngừng</option></select></label>
                            <label class="htp-span-2"><span>Địa chỉ</span><textarea name="address" rows="3"><?php echo esc_textarea($editing->address ?? ''); ?></textarea></label>
                            <label class="htp-span-2"><span>Giới thiệu salon</span><textarea name="intro" rows="4"><?php echo esc_textarea($editing->intro ?? ''); ?></textarea></label>
                            <label><span>Giờ hoạt động</span><textarea name="opening_hours" rows="3" placeholder="Thứ 2–Chủ nhật: 8:00–20:00"><?php echo esc_textarea($editing->opening_hours ?? ''); ?></textarea></label>
                            <label><span>Google Maps URL</span><input name="map_url" type="url" value="<?php echo esc_attr($editing->map_url ?? ''); ?>"></label>
                            <label class="htp-span-2"><span>Hướng dẫn/ưu đãi riêng</span><textarea name="instruction" rows="4"><?php echo esc_textarea($editing->instruction ?? ''); ?></textarea></label>
                            <label class="htp-span-2"><span>URL OA MyHair riêng</span><input name="oa_url" type="url" value="<?php echo esc_attr($editing->oa_url ?? ''); ?>"><small>Để trống sẽ dùng URL OA chung trong Cài đặt.</small></label>
                            <label class="htp-span-2"><span>Trang landing</span><?php wp_dropdown_pages(['name' => 'landing_page_id', 'selected' => absint($editing->landing_page_id ?? 0), 'show_option_none' => '— Chưa gắn trang —', 'option_none_value' => 0]); ?><small>Trang cần chứa shortcode <code>[htp_salon_landing]</code>. Có thể chỉnh trang tự do bằng WordPress.</small></label>

                            <div class="htp-span-2">
                                <h3>Google Sheet riêng của salon</h3>
                                <p class="description">Khi bật, mỗi đăng ký của salon vẫn được ghi vào Sheet tổng MyHair và đồng thời được ghi thêm vào Google Sheet riêng này.</p>
                            </div>
                            <label class="htp-span-2">
                                <span>Trạng thái đồng bộ riêng</span>
                                <input type="hidden" name="google_sheet_enabled" value="0">
                                <span><input type="checkbox" name="google_sheet_enabled" value="1" <?php checked(!empty($editing_sheet['enabled'])); ?>> Bật Google Sheet riêng cho salon này</span>
                            </label>
                            <label class="htp-span-2"><span>Google Sheet URL hoặc Spreadsheet ID</span><input name="google_sheet_url" type="text" value="<?php echo esc_attr((string) ($editing_sheet['url'] ?? '')); ?>" placeholder="https://docs.google.com/spreadsheets/d/.../edit"><small>Google Account đang chạy Apps Script phải được chia sẻ quyền <strong>Editor</strong> trên file Sheet này. Plugin sẽ tự lấy Spreadsheet ID từ URL.</small></label>
                        </div>
                        <?php submit_button($editing ? 'Lưu thay đổi' : 'Tạo salon'); ?>
                        <?php if ($editing) : ?><a class="button" href="<?php echo esc_url(admin_url('admin.php?page=htp-salons')); ?>">Hủy sửa</a><?php endif; ?>
                    </form>

                    <?php if ($editing && !empty($editing_sheet['spreadsheet_id'])) :
                        $test_sheet_url = wp_nonce_url(add_query_arg(['action' => 'htp_test_salon_google_sheet', 'salon_id' => $editing->id], admin_url('admin-post.php')), 'htp_test_salon_google_sheet_' . $editing->id);
                        $sync_sheet_url = wp_nonce_url(add_query_arg(['action' => 'htp_sync_salon_google_sheet', 'salon_id' => $editing->id], admin_url('admin-post.php')), 'htp_sync_salon_google_sheet_' . $editing->id);
                        ?>
                        <hr>
                        <h3>Thao tác Google Sheet riêng</h3>
                        <div class="htp-inline-actions">
                            <a class="button" href="<?php echo esc_url($test_sheet_url); ?>">Kiểm tra kết nối Sheet</a>
                            <a class="button" href="<?php echo esc_url((string) $editing_sheet['url']); ?>" target="_blank" rel="noopener">Mở Google Sheet</a>
                            <a class="button button-primary htp-confirm" href="<?php echo esc_url($sync_sheet_url); ?>">Đồng bộ lại dữ liệu salon</a>
                        </div>
                        <p class="description">“Đồng bộ lại dữ liệu salon” đưa toàn bộ đăng ký hiện có của salon vào hàng đợi. Apps Script dùng <code>submission_code</code> để cập nhật đúng dòng nên không tạo bản ghi trùng.</p>
                    <?php endif; ?>
                </section>

                <section class="htp-panel htp-help-panel">
                    <h2>Salon, chủ salon và khách hàng</h2>
                    <p>Mỗi salon có một mã duy nhất, ví dụ <code>PHU0001</code>, và một landing riêng.</p>
                    <p>Khi khách gửi form trên landing đó, hệ thống lưu trực tiếp <strong>salon ID</strong>, mã salon và <strong>chủ salon tại thời điểm đăng ký</strong>.</p>
                    <p>Chủ salon chính được tự động cấp quyền truy cập salon. Nhân viên khác vẫn có thể được phân công thêm tại menu <strong>Tài khoản</strong>.</p>
                    <p>Bấm <strong>Tạo trang mặc định</strong> để plugin tạo sẵn trang có shortcode; sau đó có thể sửa bằng Gutenberg hoặc page builder.</p>
                    <hr>
                    <h3>Google Sheet riêng</h3>
                    <p>Chỉ cần <strong>một Apps Script chung</strong>. Mỗi salon có thể dùng một file Google Sheet riêng; không cần tạo Apps Script hoặc Secret riêng cho từng salon.</p>
                    <p>Hãy Share file Google Sheet riêng cho tài khoản Google đang Deploy Apps Script với quyền <strong>Editor</strong>, sau đó dán URL vào cấu hình salon.</p>
                </section>
            </div>

            <section class="htp-panel">
                <h2>Danh sách salon</h2>
                <div class="htp-table-wrap">
                    <table class="widefat striped htp-responsive-table">
                        <thead><tr><th>Mã</th><th>Salon</th><th>Chủ salon chính</th><th>Landing & QR</th><th>Google Sheet riêng</th><th>Trạng thái</th><th>Thao tác</th></tr></thead>
                        <tbody>
                        <?php if (!$salons) : ?><tr><td colspan="7">Chưa có salon.</td></tr><?php else : foreach ($salons as $salon) :
                            $url = HTP_QR_Service::registration_url($salon);
                            $has_page = absint($salon->landing_page_id ?? 0) && get_post((int) $salon->landing_page_id);
                            $qr_url = HTP_QR_Service::image_url($url, 280);
                            $toggle_url = wp_nonce_url(add_query_arg(['action' => 'htp_toggle_salon', 'salon_id' => $salon->id, 'status' => $salon->status === 'active' ? 'inactive' : 'active'], admin_url('admin-post.php')), 'htp_toggle_salon_' . $salon->id);
                            $create_page_url = wp_nonce_url(add_query_arg(['action' => 'htp_create_salon_page', 'salon_id' => $salon->id], admin_url('admin-post.php')), 'htp_create_salon_page_' . $salon->id);
                            $download_url = wp_nonce_url(add_query_arg(['action' => 'htp_download_qr', 'salon_id' => $salon->id], admin_url('admin-post.php')), 'htp_download_qr_' . $salon->id);
                            $sheet = HTP_Google_Sheets_Service::salon_destination((int) $salon->id);
                            ?>
                            <tr>
                                <td data-label="Mã"><strong><?php echo esc_html($salon->code); ?></strong></td>
                                <td data-label="Salon"><strong><?php echo esc_html($salon->name); ?></strong><br><small><?php echo esc_html($salon->address); ?></small><br><small><?php echo esc_html($salon->phone); ?></small></td>
                                <td data-label="Chủ salon"><?php if ($salon->owner_name) : ?><strong><?php echo esc_html($salon->owner_name); ?></strong><br><small><?php echo esc_html($salon->owner_email); ?></small><?php else : ?><em>Chưa gán</em><?php endif; ?></td>
                                <td data-label="Landing & QR">
                                    <?php if ($has_page) : ?>
                                        <input class="htp-copy-source" type="text" readonly value="<?php echo esc_attr($url); ?>">
                                        <div class="htp-inline-actions">
                                            <button type="button" class="button htp-copy-button">Sao chép</button>
                                            <a class="button" href="<?php echo esc_url($url); ?>" target="_blank" rel="noopener">Mở landing</a>
                                            <a class="button" href="<?php echo esc_url(get_edit_post_link((int) $salon->landing_page_id)); ?>">Sửa trang</a>
                                            <a class="button" href="<?php echo esc_url($qr_url); ?>" target="_blank" rel="noopener">Xem QR</a>
                                            <a class="button" href="<?php echo esc_url($download_url); ?>">Tải QR</a>
                                        </div>
                                    <?php else : ?>
                                        <p><em>Chưa có trang landing.</em></p>
                                        <a class="button button-primary" href="<?php echo esc_url($create_page_url); ?>">Tạo trang mặc định</a>
                                    <?php endif; ?>
                                </td>
                                <td data-label="Google Sheet riêng">
                                    <?php if (!empty($sheet['enabled'])) : ?>
                                        <span class="htp-status-badge htp-status-active">Đang bật</span><br>
                                        <a href="<?php echo esc_url((string) $sheet['url']); ?>" target="_blank" rel="noopener">Mở Sheet</a>
                                    <?php elseif (!empty($sheet['spreadsheet_id'])) : ?>
                                        <span class="htp-status-badge htp-status-inactive">Đang tắt</span>
                                    <?php else : ?><em>Chưa cấu hình</em><?php endif; ?>
                                </td>
                                <td data-label="Trạng thái"><span class="htp-status-badge <?php echo $salon->status === 'active' ? 'htp-status-active' : 'htp-status-inactive'; ?>"><?php echo $salon->status === 'active' ? 'Đang hoạt động' : 'Tạm ngừng'; ?></span></td>
                                <td data-label="Thao tác"><a class="button" href="<?php echo esc_url(add_query_arg(['page' => 'htp-salons', 'salon_id' => $salon->id], admin_url('admin.php'))); ?>">Sửa</a> <a class="button htp-confirm" href="<?php echo esc_url($toggle_url); ?>"><?php echo $salon->status === 'active' ? 'Tạm ngừng' : 'Kích hoạt'; ?></a></td>
                            </tr>
                        <?php endforeach; endif; ?>
                        </tbody>
                    </table>
                </div>
            </section>
        </div>
        <?php
    }

    public static function save(): void
    {
        if (!current_user_can('htp_manage_salons')) {
            wp_die('Không có quyền.');
        }
        check_admin_referer('htp_save_salon');
        $id = absint($_POST['salon_id'] ?? 0);
        $data = wp_unslash($_POST);
        $repository = new HTP_Salon_Repository();
        try {
            if ($id) {
                $existing = $repository->find_by_id($id);
                if (!$existing) {
                    throw new RuntimeException('Không tìm thấy salon.');
                }
                $data['code'] = $existing->code;
                $repository->update($id, $data);
                $message = 'updated';
            } else {
                $id = $repository->create($data);
                $message = 'created';
            }
            HTP_Google_Sheets_Service::save_salon_destination($id, $data);
            $page_id = absint($data['landing_page_id'] ?? 0);
            (new HTP_Landing_Service())->attach_page($id, $page_id);
            HTP_Activity_Logger::log('salon_saved', 'salon', $id, [
                'owner_user_id' => absint($data['owner_user_id'] ?? 0),
                'google_sheet_enabled' => !empty($data['google_sheet_enabled']),
            ]);
        } catch (Throwable $exception) {
            wp_die(esc_html($exception->getMessage()));
        }
        wp_safe_redirect(add_query_arg(['page' => 'htp-salons', 'salon_id' => $id, 'htp_message' => $message], admin_url('admin.php')));
        exit;
    }

    public static function test_google_sheet(): void
    {
        $id = absint($_GET['salon_id'] ?? 0);
        if (!current_user_can('htp_manage_salons') || !$id) {
            wp_die('Không có quyền.');
        }
        check_admin_referer('htp_test_salon_google_sheet_' . $id);
        try {
            $result = HTP_Google_Sheets_Service::test_salon_connection($id);
            HTP_Activity_Logger::log('salon_google_sheet_tested', 'salon', $id, ['spreadsheet' => (string) ($result['spreadsheet'] ?? '')]);
        } catch (Throwable $exception) {
            wp_die(esc_html('Kiểm tra Google Sheet riêng thất bại: ' . $exception->getMessage()));
        }
        wp_safe_redirect(add_query_arg(['page' => 'htp-salons', 'salon_id' => $id, 'htp_message' => 'google_salon_test_ok'], admin_url('admin.php')));
        exit;
    }

    public static function sync_google_sheet(): void
    {
        $id = absint($_GET['salon_id'] ?? 0);
        if (!current_user_can('htp_manage_salons') || !$id) {
            wp_die('Không có quyền.');
        }
        check_admin_referer('htp_sync_salon_google_sheet_' . $id);
        try {
            $count = HTP_Google_Sheets_Service::queue_salon($id);
            HTP_Google_Sheets_Service::process_queue(50);
            HTP_Activity_Logger::log('salon_google_sheet_resync_queued', 'salon', $id, ['count' => $count]);
        } catch (Throwable $exception) {
            wp_die(esc_html('Không thể đồng bộ lại Google Sheet riêng: ' . $exception->getMessage()));
        }
        wp_safe_redirect(add_query_arg(['page' => 'htp-salons', 'salon_id' => $id, 'htp_message' => 'google_salon_queued'], admin_url('admin.php')));
        exit;
    }

    public static function create_landing_page(): void
    {
        $id = absint($_GET['salon_id'] ?? 0);
        if (!current_user_can('htp_manage_salons') || !$id) {
            wp_die('Không có quyền.');
        }
        check_admin_referer('htp_create_salon_page_' . $id);
        $salon = (new HTP_Salon_Repository())->find_by_id($id);
        if (!$salon) {
            wp_die('Không tìm thấy salon.');
        }
        try {
            (new HTP_Landing_Service())->create_default_page($salon);
        } catch (Throwable $exception) {
            wp_die(esc_html($exception->getMessage()));
        }
        wp_safe_redirect(add_query_arg(['page' => 'htp-salons', 'salon_id' => $id, 'htp_message' => 'page_created'], admin_url('admin.php')));
        exit;
    }

    public static function toggle(): void
    {
        $id = absint($_GET['salon_id'] ?? 0);
        if (!current_user_can('htp_manage_salons') || !$id) {
            wp_die('Không có quyền.');
        }
        check_admin_referer('htp_toggle_salon_' . $id);
        $status = ($_GET['status'] ?? '') === 'active' ? 'active' : 'inactive';
        (new HTP_Salon_Repository())->set_status($id, $status);
        HTP_Activity_Logger::log('salon_status_updated', 'salon', $id, ['status' => $status]);
        wp_safe_redirect(add_query_arg(['page' => 'htp-salons', 'htp_message' => 'status'], admin_url('admin.php')));
        exit;
    }

    public static function download_qr(): void
    {
        $id = absint($_GET['salon_id'] ?? 0);
        if (!current_user_can('htp_manage_salons') || !$id) {
            wp_die('Không có quyền.');
        }
        check_admin_referer('htp_download_qr_' . $id);
        $salon = (new HTP_Salon_Repository())->find_by_id($id);
        if (!$salon || !absint($salon->landing_page_id ?? 0)) {
            wp_die('Salon chưa có trang landing.');
        }
        $image_url = HTP_QR_Service::image_url(HTP_QR_Service::registration_url($salon), 600);
        $response = wp_remote_get($image_url, ['timeout' => 20]);
        if (is_wp_error($response) || wp_remote_retrieve_response_code($response) !== 200) {
            wp_safe_redirect($image_url);
            exit;
        }
        nocache_headers();
        header('Content-Type: image/png');
        header('Content-Disposition: attachment; filename="qr-' . sanitize_file_name(strtolower($salon->code)) . '.png"');
        echo wp_remote_retrieve_body($response); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
        exit;
    }
}
