<?php

defined('ABSPATH') || exit;

final class HTP_Salons_Page
{
    public static function init(): void
    {
        add_action('admin_menu', [self::class, 'register_page'], 10);
        add_action('admin_post_htp_save_salon', [self::class, 'save']);
        add_action('admin_post_htp_toggle_salon', [self::class, 'toggle']);
        add_action('admin_post_htp_download_qr', [self::class, 'download_qr']);
    }

    public static function register_page(): void
    {
        add_submenu_page(
            'htp-dashboard',
            'Salon',
            'Salon',
            'htp_manage_salons',
            'htp-salons',
            [self::class, 'render']
        );
    }

    public static function render(): void
    {
        if (!current_user_can('htp_manage_salons')) {
            wp_die(esc_html__('Bạn không có quyền truy cập.', 'hien-toc-plugin'));
        }

        HTP_Admin::notice_from_query();
        $repository = new HTP_Salon_Repository();
        $edit_id = isset($_GET['salon_id']) ? absint($_GET['salon_id']) : 0;
        $editing = $edit_id ? $repository->find_by_id($edit_id) : null;
        $salons = $repository->all();
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
                            <label><span>Mã salon *</span><input name="code" required pattern="[A-Za-z0-9-]{2,50}" maxlength="50" value="<?php echo esc_attr($editing->code ?? ''); ?>" <?php disabled((bool) $editing); ?>></label>
                            <?php if ($editing) : ?><input type="hidden" name="code" value="<?php echo esc_attr($editing->code); ?>"><?php endif; ?>
                            <label><span>Tên salon *</span><input name="name" required maxlength="190" value="<?php echo esc_attr($editing->name ?? ''); ?>"></label>
                            <label><span>Điện thoại</span><input name="phone" inputmode="tel" maxlength="30" value="<?php echo esc_attr($editing->phone ?? ''); ?>"></label>
                            <label><span>Email</span><input name="email" type="email" maxlength="190" value="<?php echo esc_attr($editing->email ?? ''); ?>"></label>
                            <label><span>Người phụ trách</span><input name="manager_name" maxlength="190" value="<?php echo esc_attr($editing->manager_name ?? ''); ?>"></label>
                            <label><span>Trạng thái</span><select name="status"><option value="active" <?php selected($editing->status ?? 'active', 'active'); ?>>Đang hoạt động</option><option value="inactive" <?php selected($editing->status ?? '', 'inactive'); ?>>Tạm ngừng</option></select></label>
                            <label class="htp-span-2"><span>Địa chỉ</span><textarea name="address" rows="3"><?php echo esc_textarea($editing->address ?? ''); ?></textarea></label>
                            <label class="htp-span-2"><span>Hướng dẫn riêng của salon</span><textarea name="instruction" rows="5"><?php echo esc_textarea($editing->instruction ?? ''); ?></textarea></label>
                        </div>
                        <?php submit_button($editing ? 'Lưu thay đổi' : 'Tạo salon'); ?>
                        <?php if ($editing) : ?><a class="button" href="<?php echo esc_url(admin_url('admin.php?page=htp-salons')); ?>">Hủy sửa</a><?php endif; ?>
                    </form>
                </section>

                <section class="htp-panel htp-help-panel">
                    <h2>Đường dẫn QR</h2>
                    <p>QR của từng salon sẽ mở trang WordPress đã chọn trong phần Cài đặt và tự thêm <code>?salon=MÃ</code>.</p>
                    <p>Form public sẽ đọc mã salon, hiển thị đúng thông tin salon trước khi khách nhập dữ liệu.</p>
                </section>
            </div>

            <section class="htp-panel">
                <h2>Danh sách salon</h2>
                <div class="htp-table-wrap">
                    <table class="widefat striped htp-responsive-table">
                        <thead><tr><th>Mã</th><th>Salon</th><th>Liên hệ</th><th>Đường dẫn & QR</th><th>Trạng thái</th><th>Thao tác</th></tr></thead>
                        <tbody>
                        <?php if (!$salons) : ?>
                            <tr><td colspan="6">Chưa có salon.</td></tr>
                        <?php else : foreach ($salons as $salon) :
                            $url = HTP_QR_Service::registration_url($salon);
                            $qr_url = HTP_QR_Service::image_url($url, 280);
                            $toggle_url = wp_nonce_url(add_query_arg([
                                'action' => 'htp_toggle_salon',
                                'salon_id' => $salon->id,
                                'status' => $salon->status === 'active' ? 'inactive' : 'active',
                            ], admin_url('admin-post.php')), 'htp_toggle_salon_' . $salon->id);
                            $download_url = wp_nonce_url(add_query_arg([
                                'action' => 'htp_download_qr',
                                'salon_id' => $salon->id,
                            ], admin_url('admin-post.php')), 'htp_download_qr_' . $salon->id);
                            ?>
                            <tr>
                                <td data-label="Mã"><strong><?php echo esc_html($salon->code); ?></strong></td>
                                <td data-label="Salon"><strong><?php echo esc_html($salon->name); ?></strong><br><small><?php echo esc_html($salon->address); ?></small></td>
                                <td data-label="Liên hệ"><?php echo esc_html($salon->phone); ?><br><small><?php echo esc_html($salon->manager_name); ?></small></td>
                                <td data-label="Đường dẫn & QR">
                                    <input class="htp-copy-source" type="text" readonly value="<?php echo esc_attr($url); ?>">
                                    <div class="htp-inline-actions">
                                        <button type="button" class="button htp-copy-button">Sao chép</button>
                                        <a class="button" href="<?php echo esc_url($qr_url); ?>" target="_blank" rel="noopener">Xem QR</a>
                                        <a class="button" href="<?php echo esc_url($download_url); ?>">Tải PNG</a>
                                    </div>
                                </td>
                                <td data-label="Trạng thái"><span class="htp-status-badge <?php echo $salon->status === 'active' ? 'htp-status-active' : 'htp-status-inactive'; ?>"><?php echo $salon->status === 'active' ? 'Đang hoạt động' : 'Tạm ngừng'; ?></span></td>
                                <td data-label="Thao tác">
                                    <a class="button" href="<?php echo esc_url(add_query_arg(['page' => 'htp-salons', 'salon_id' => $salon->id], admin_url('admin.php'))); ?>">Sửa</a>
                                    <a class="button htp-confirm" href="<?php echo esc_url($toggle_url); ?>"><?php echo $salon->status === 'active' ? 'Tạm ngừng' : 'Kích hoạt'; ?></a>
                                </td>
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
            wp_die(esc_html__('Bạn không có quyền thực hiện thao tác này.', 'hien-toc-plugin'));
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
                HTP_Activity_Logger::log('salon_updated', 'salon', $id);
                $message = 'updated';
            } else {
                $id = $repository->create($data);
                HTP_Activity_Logger::log('salon_created', 'salon', $id);
                $message = 'created';
            }
        } catch (Throwable $exception) {
            wp_die(esc_html($exception->getMessage()));
        }

        wp_safe_redirect(add_query_arg(['page' => 'htp-salons', 'htp_message' => $message], admin_url('admin.php')));
        exit;
    }

    public static function toggle(): void
    {
        $id = absint($_GET['salon_id'] ?? 0);
        if (!current_user_can('htp_manage_salons') || !$id) {
            wp_die(esc_html__('Bạn không có quyền thực hiện thao tác này.', 'hien-toc-plugin'));
        }
        check_admin_referer('htp_toggle_salon_' . $id);
        $status = (isset($_GET['status']) && $_GET['status'] === 'active') ? 'active' : 'inactive';
        (new HTP_Salon_Repository())->set_status($id, $status);
        HTP_Activity_Logger::log('salon_status_updated', 'salon', $id, ['status' => $status]);
        wp_safe_redirect(add_query_arg(['page' => 'htp-salons', 'htp_message' => 'status'], admin_url('admin.php')));
        exit;
    }

    public static function download_qr(): void
    {
        $id = absint($_GET['salon_id'] ?? 0);
        if (!current_user_can('htp_manage_salons') || !$id) {
            wp_die(esc_html__('Bạn không có quyền thực hiện thao tác này.', 'hien-toc-plugin'));
        }
        check_admin_referer('htp_download_qr_' . $id);
        $salon = (new HTP_Salon_Repository())->find_by_id($id);
        if (!$salon) {
            wp_die('Không tìm thấy salon.');
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
