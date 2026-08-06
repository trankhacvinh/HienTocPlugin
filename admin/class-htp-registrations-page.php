<?php

defined('ABSPATH') || exit;

final class HTP_Registrations_Page
{
    public static function init(): void
    {
        add_action('admin_menu', [self::class, 'register_page'], 11);
        add_action('admin_post_htp_update_registration_status', [self::class, 'update_status']);
        add_action('admin_post_htp_update_registration', [self::class, 'update_details']);
        add_action('admin_post_htp_export_registrations', [self::class, 'export_csv']);
    }

    public static function register_page(): void
    {
        add_submenu_page(
            'htp-dashboard',
            'Khách đăng ký',
            'Khách đăng ký',
            'htp_view_own_salon',
            'htp-registrations',
            [self::class, 'render']
        );
    }

    public static function render(): void
    {
        if (!current_user_can('htp_view_own_salon')) {
            wp_die(esc_html__('Bạn không có quyền truy cập.', 'hien-toc-plugin'));
        }

        HTP_Admin::notice_from_query();
        $id = isset($_GET['registration_id']) ? absint($_GET['registration_id']) : 0;
        if ($id) {
            self::render_detail($id);
            return;
        }
        self::render_list();
    }

    private static function render_list(): void
    {
        $repository = new HTP_Registration_Repository();
        $allowed = current_user_can('htp_manage_registrations') ? null : HTP_User_Salon_Service::salon_ids_for_user();
        $filters = self::filters_from_request();
        $page = max(1, absint($_GET['paged'] ?? 1));
        $per_page = 30;
        $rows = $repository->search($filters, $page, $per_page, $allowed);
        $total = $repository->count($filters, $allowed);
        $labels = HTP_Registration_Service::status_labels();
        $salon_ids = $allowed === null ? [] : $allowed;
        $salons = (new HTP_Salon_Repository())->all($salon_ids);
        $export_url = wp_nonce_url(add_query_arg(array_merge([
            'action' => 'htp_export_registrations',
        ], $filters), admin_url('admin-post.php')), 'htp_export_registrations');
        ?>
        <div class="wrap htp-admin-wrap">
            <div class="htp-page-heading">
                <h1>Khách đăng ký</h1>
                <?php if (current_user_can('htp_export_data')) : ?><a class="button button-primary" href="<?php echo esc_url($export_url); ?>">Xuất CSV theo bộ lọc</a><?php endif; ?>
            </div>

            <section class="htp-panel">
                <form method="get" class="htp-filter-form">
                    <input type="hidden" name="page" value="htp-registrations">
                    <label><span>Tìm kiếm</span><input type="search" name="s" value="<?php echo esc_attr($filters['s']); ?>" placeholder="Mã, họ tên, số điện thoại"></label>
                    <label><span>Salon</span><select name="salon_id"><option value="0">Tất cả salon</option><?php foreach ($salons as $salon) : ?><option value="<?php echo esc_attr((string) $salon->id); ?>" <?php selected((int) $filters['salon_id'], (int) $salon->id); ?>><?php echo esc_html($salon->code . ' — ' . $salon->name); ?></option><?php endforeach; ?></select></label>
                    <label><span>Trạng thái</span><select name="status"><option value="">Tất cả trạng thái</option><?php foreach ($labels as $key => $label) : ?><option value="<?php echo esc_attr($key); ?>" <?php selected($filters['status'], $key); ?>><?php echo esc_html($label); ?></option><?php endforeach; ?></select></label>
                    <label><span>Từ ngày</span><input type="date" name="from" value="<?php echo esc_attr($filters['from']); ?>"></label>
                    <label><span>Đến ngày</span><input type="date" name="to" value="<?php echo esc_attr($filters['to']); ?>"></label>
                    <div class="htp-filter-actions"><button class="button button-primary">Lọc</button><a class="button" href="<?php echo esc_url(admin_url('admin.php?page=htp-registrations')); ?>">Xóa lọc</a></div>
                </form>
            </section>

            <section class="htp-panel">
                <div class="htp-list-summary">Tìm thấy <strong><?php echo esc_html((string) $total); ?></strong> đăng ký.</div>
                <div class="htp-table-wrap">
                    <table class="widefat striped htp-responsive-table">
                        <thead><tr><th>Mã</th><th>Khách hàng</th><th>Salon</th><th>Ngày đăng ký</th><th>Trạng thái</th><th>Thao tác</th></tr></thead>
                        <tbody>
                        <?php if (!$rows) : ?>
                            <tr><td colspan="6">Không có dữ liệu phù hợp.</td></tr>
                        <?php else : foreach ($rows as $row) : ?>
                            <tr>
                                <td data-label="Mã"><strong><?php echo esc_html($row->registration_code); ?></strong></td>
                                <td data-label="Khách hàng"><strong><?php echo esc_html($row->full_name); ?></strong><br><small><?php echo esc_html(self::mask_phone($row->phone)); ?></small></td>
                                <td data-label="Salon"><?php echo esc_html($row->salon_code); ?><br><small><?php echo esc_html($row->salon_name); ?></small></td>
                                <td data-label="Ngày đăng ký"><?php echo esc_html(mysql2date('d/m/Y H:i', $row->registered_at)); ?></td>
                                <td data-label="Trạng thái"><span class="htp-status-badge htp-status-<?php echo esc_attr($row->status); ?>"><?php echo esc_html($labels[$row->status] ?? $row->status); ?></span></td>
                                <td data-label="Thao tác"><a class="button" href="<?php echo esc_url(add_query_arg(['page' => 'htp-registrations', 'registration_id' => $row->id], admin_url('admin.php'))); ?>">Xem & xử lý</a></td>
                            </tr>
                        <?php endforeach; endif; ?>
                        </tbody>
                    </table>
                </div>
                <?php
                $total_pages = (int) ceil($total / $per_page);
                if ($total_pages > 1) {
                    echo '<div class="tablenav"><div class="tablenav-pages">' . wp_kses_post(paginate_links([
                        'base' => add_query_arg('paged', '%#%'),
                        'format' => '',
                        'current' => $page,
                        'total' => $total_pages,
                    ])) . '</div></div>';
                }
                ?>
            </section>
        </div>
        <?php
    }

    private static function render_detail(int $id): void
    {
        $repository = new HTP_Registration_Repository();
        $registration = $repository->find($id);
        if (!$registration || !HTP_User_Salon_Service::can_access_salon((int) $registration->salon_id)) {
            wp_die(esc_html__('Không tìm thấy đăng ký hoặc bạn không có quyền truy cập.', 'hien-toc-plugin'));
        }
        $logs = $repository->status_logs($id);
        $labels = HTP_Registration_Service::status_labels();
        $service = new HTP_Registration_Service();
        $allowed_transitions = current_user_can('htp_manage_registrations')
            ? array_values(array_diff(HTP_Registration_Service::statuses(), [$registration->status]))
            : $service->allowed_transitions((string) $registration->status);
        ?>
        <div class="wrap htp-admin-wrap">
            <div class="htp-page-heading">
                <div><a href="<?php echo esc_url(admin_url('admin.php?page=htp-registrations')); ?>">← Quay lại danh sách</a><h1><?php echo esc_html($registration->registration_code); ?></h1></div>
                <span class="htp-status-badge htp-status-<?php echo esc_attr($registration->status); ?>"><?php echo esc_html($labels[$registration->status] ?? $registration->status); ?></span>
            </div>

            <div class="htp-two-column htp-detail-layout">
                <section class="htp-panel">
                    <h2>Thông tin khách hàng</h2>
                    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                        <input type="hidden" name="action" value="htp_update_registration">
                        <input type="hidden" name="registration_id" value="<?php echo esc_attr((string) $registration->id); ?>">
                        <?php wp_nonce_field('htp_update_registration_' . $registration->id); ?>
                        <div class="htp-form-grid">
                            <label><span>Họ và tên *</span><input name="full_name" required value="<?php echo esc_attr($registration->full_name); ?>"></label>
                            <label><span>Số điện thoại *</span><input name="phone" inputmode="tel" required value="<?php echo esc_attr($registration->phone); ?>"></label>
                            <label><span>Ngày sinh</span><input type="date" name="date_of_birth" value="<?php echo esc_attr($registration->date_of_birth); ?>"></label>
                            <label><span>Email</span><input type="email" name="email" value="<?php echo esc_attr($registration->email); ?>"></label>
                            <label class="htp-span-2"><span>Địa chỉ</span><textarea name="address" rows="3"><?php echo esc_textarea($registration->address); ?></textarea></label>
                            <label class="htp-span-2"><span>Ghi chú khách hàng</span><textarea name="customer_note" rows="3"><?php echo esc_textarea($registration->customer_note); ?></textarea></label>
                            <label class="htp-span-2"><span>Ghi chú nội bộ</span><textarea name="internal_note" rows="4"><?php echo esc_textarea($registration->internal_note); ?></textarea></label>
                        </div>
                        <?php submit_button('Lưu thông tin'); ?>
                    </form>
                </section>

                <aside>
                    <section class="htp-panel">
                        <h2>Thông tin đăng ký</h2>
                        <dl class="htp-meta-list">
                            <div><dt>Salon</dt><dd><?php echo esc_html($registration->salon_code . ' — ' . $registration->salon_name); ?></dd></div>
                            <div><dt>Ngày đăng ký</dt><dd><?php echo esc_html(mysql2date('d/m/Y H:i', $registration->registered_at)); ?></dd></div>
                            <div><dt>Đồng ý dữ liệu</dt><dd><?php echo esc_html(mysql2date('d/m/Y H:i', $registration->consent_at)); ?></dd></div>
                            <?php if ($registration->received_at) : ?><div><dt>Ngày tiếp nhận</dt><dd><?php echo esc_html(mysql2date('d/m/Y H:i', $registration->received_at)); ?></dd></div><?php endif; ?>
                            <?php if ($registration->completed_at) : ?><div><dt>Ngày hoàn thành</dt><dd><?php echo esc_html(mysql2date('d/m/Y H:i', $registration->completed_at)); ?></dd></div><?php endif; ?>
                        </dl>
                    </section>

                    <section class="htp-panel">
                        <h2>Cập nhật trạng thái</h2>
                        <?php if (!$allowed_transitions) : ?>
                            <p>Không có trạng thái tiếp theo khả dụng với quyền hiện tại.</p>
                        <?php else : ?>
                            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                                <input type="hidden" name="action" value="htp_update_registration_status">
                                <input type="hidden" name="registration_id" value="<?php echo esc_attr((string) $registration->id); ?>">
                                <?php wp_nonce_field('htp_update_registration_status_' . $registration->id); ?>
                                <label class="htp-admin-field"><span>Trạng thái mới</span><select name="new_status" required><option value="">— Chọn —</option><?php foreach ($allowed_transitions as $status) : ?><option value="<?php echo esc_attr($status); ?>"><?php echo esc_html($labels[$status] ?? $status); ?></option><?php endforeach; ?></select></label>
                                <label class="htp-admin-field"><span>Ghi chú</span><textarea name="status_note" rows="4"></textarea></label>
                                <?php submit_button('Cập nhật trạng thái', 'primary', 'submit', false); ?>
                            </form>
                        <?php endif; ?>
                    </section>
                </aside>
            </div>

            <section class="htp-panel">
                <h2>Lịch sử trạng thái</h2>
                <div class="htp-timeline">
                    <?php foreach ($logs as $log) : ?>
                        <article>
                            <div class="htp-timeline-dot"></div>
                            <div>
                                <strong><?php echo esc_html(($log->old_status ? ($labels[$log->old_status] ?? $log->old_status) . ' → ' : '') . ($labels[$log->new_status] ?? $log->new_status)); ?></strong>
                                <p><?php echo esc_html($log->note ?: 'Không có ghi chú.'); ?></p>
                                <small><?php echo esc_html(mysql2date('d/m/Y H:i', $log->changed_at)); ?><?php if ($log->display_name) : ?> — <?php echo esc_html($log->display_name); ?><?php endif; ?></small>
                            </div>
                        </article>
                    <?php endforeach; ?>
                </div>
            </section>
        </div>
        <?php
    }

    public static function update_status(): void
    {
        $id = absint($_POST['registration_id'] ?? 0);
        if (!current_user_can('htp_update_registration_status') || !$id) {
            wp_die(esc_html__('Bạn không có quyền thực hiện thao tác này.', 'hien-toc-plugin'));
        }
        check_admin_referer('htp_update_registration_status_' . $id);
        try {
            (new HTP_Registration_Service())->update_status(
                $id,
                sanitize_key((string) ($_POST['new_status'] ?? '')),
                sanitize_textarea_field(wp_unslash((string) ($_POST['status_note'] ?? '')))
            );
        } catch (Throwable $exception) {
            wp_die(esc_html($exception->getMessage()));
        }
        wp_safe_redirect(add_query_arg(['page' => 'htp-registrations', 'registration_id' => $id, 'htp_message' => 'status'], admin_url('admin.php')));
        exit;
    }

    public static function update_details(): void
    {
        $id = absint($_POST['registration_id'] ?? 0);
        if (!current_user_can('htp_update_registration_status') || !$id) {
            wp_die(esc_html__('Bạn không có quyền thực hiện thao tác này.', 'hien-toc-plugin'));
        }
        check_admin_referer('htp_update_registration_' . $id);
        try {
            (new HTP_Registration_Service())->update_details($id, wp_unslash($_POST));
        } catch (Throwable $exception) {
            wp_die(esc_html($exception->getMessage()));
        }
        wp_safe_redirect(add_query_arg(['page' => 'htp-registrations', 'registration_id' => $id, 'htp_message' => 'updated'], admin_url('admin.php')));
        exit;
    }

    public static function export_csv(): void
    {
        if (!current_user_can('htp_export_data')) {
            wp_die(esc_html__('Bạn không có quyền xuất dữ liệu.', 'hien-toc-plugin'));
        }
        check_admin_referer('htp_export_registrations');
        $allowed = current_user_can('htp_manage_registrations') ? null : HTP_User_Salon_Service::salon_ids_for_user();
        $rows = (new HTP_Registration_Repository())->export_rows(self::filters_from_request(), $allowed);
        $labels = HTP_Registration_Service::status_labels();

        HTP_Activity_Logger::log('registrations_exported', 'registration', null, ['count' => count($rows)]);
        nocache_headers();
        header('Content-Type: text/csv; charset=UTF-8');
        header('Content-Disposition: attachment; filename="dang-ky-hien-toc-' . gmdate('Ymd-His') . '.csv"');
        $output = fopen('php://output', 'wb');
        fwrite($output, "\xEF\xBB\xBF");
        fputcsv($output, ['Mã đăng ký', 'Salon', 'Họ tên', 'Số điện thoại', 'Ngày sinh', 'Email', 'Địa chỉ', 'Trạng thái', 'Ngày đăng ký', 'Ngày tiếp nhận', 'Ngày hoàn thành', 'Ghi chú khách', 'Ghi chú nội bộ']);
        foreach ($rows as $row) {
            fputcsv($output, [
                $row['registration_code'],
                $row['salon_code'] . ' — ' . $row['salon_name'],
                $row['full_name'],
                $row['phone'],
                $row['date_of_birth'],
                $row['email'],
                $row['address'],
                $labels[$row['status']] ?? $row['status'],
                $row['registered_at'],
                $row['received_at'],
                $row['completed_at'],
                $row['customer_note'],
                $row['internal_note'],
            ]);
        }
        fclose($output);
        exit;
    }

    private static function filters_from_request(): array
    {
        return [
            's' => isset($_GET['s']) ? sanitize_text_field(wp_unslash($_GET['s'])) : '',
            'salon_id' => isset($_GET['salon_id']) ? absint($_GET['salon_id']) : 0,
            'status' => isset($_GET['status']) ? sanitize_key(wp_unslash($_GET['status'])) : '',
            'from' => isset($_GET['from']) ? sanitize_text_field(wp_unslash($_GET['from'])) : '',
            'to' => isset($_GET['to']) ? sanitize_text_field(wp_unslash($_GET['to'])) : '',
        ];
    }

    private static function mask_phone(string $phone): string
    {
        $digits = preg_replace('/\D+/', '', $phone) ?: '';
        if (strlen($digits) < 7) {
            return $phone;
        }
        return substr($digits, 0, 4) . ' *** ' . substr($digits, -3);
    }
}
