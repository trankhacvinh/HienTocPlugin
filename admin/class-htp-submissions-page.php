<?php

defined('ABSPATH') || exit;

final class HTP_Submissions_Page
{
    public static function init(): void
    {
        add_action('admin_menu', [self::class, 'register_pages'], 13);
        add_action('admin_post_htp_update_submission_status', [self::class, 'update_status']);
        add_action('admin_post_htp_export_submissions', [self::class, 'export']);
    }

    public static function register_pages(): void
    {
        add_submenu_page('htp-dashboard', 'Đăng ký hiến tóc', 'Đăng ký hiến tóc', 'htp_view_own_salon', 'htp-donations', [self::class, 'render_donations']);
        add_submenu_page('htp-dashboard', 'Thành viên', 'Thành viên', 'htp_view_own_salon', 'htp-members', [self::class, 'render_members']);
    }

    public static function render_donations(): void
    {
        self::render('donation');
    }

    public static function render_members(): void
    {
        self::render('member');
    }

    private static function render(string $form_key): void
    {
        if (!current_user_can('htp_view_own_salon')) {
            wp_die('Bạn không có quyền truy cập.');
        }

        HTP_Admin::notice_from_query();
        $repository = new HTP_Submission_Repository();
        $allowed = current_user_can('htp_manage_registrations') ? null : HTP_User_Salon_Service::salon_ids_for_user();
        $page_slug = $form_key === 'member' ? 'htp-members' : 'htp-donations';
        $detail_id = absint($_GET['submission_id'] ?? 0);
        if ($detail_id) {
            self::render_detail($repository, $detail_id, $form_key, $page_slug);
            return;
        }

        $filters = self::filters($form_key);
        $page = max(1, absint($_GET['paged'] ?? 1));
        $per_page = absint($_GET['per_page'] ?? 30);
        if (!in_array($per_page, [20, 30, 50, 100], true)) {
            $per_page = 30;
        }
        $rows = $repository->search($filters, $page, $per_page, $allowed);
        $total = $repository->count($filters, $allowed);
        $salons = (new HTP_Salon_Repository())->all($allowed ?? [], false);
        $labels = HTP_Submission_Service::status_labels($form_key);
        $export_url = wp_nonce_url(add_query_arg(array_merge([
            'action' => 'htp_export_submissions',
            'form_key' => $form_key,
        ], array_filter($filters)), admin_url('admin-post.php')), 'htp_export_submissions_' . $form_key);
        ?>
        <div class="wrap htp-admin-wrap">
            <div class="htp-page-heading">
                <h1><?php echo $form_key === 'member' ? 'Danh sách thành viên' : 'Đăng ký hiến tóc'; ?></h1>
                <?php if (current_user_can('htp_export_data')) : ?><a class="button button-primary" href="<?php echo esc_url($export_url); ?>">Xuất Excel</a><?php endif; ?>
            </div>

            <form method="get" class="htp-panel htp-filter-form">
                <input type="hidden" name="page" value="<?php echo esc_attr($page_slug); ?>">
                <input type="search" name="s" value="<?php echo esc_attr($filters['s']); ?>" placeholder="Mã, họ tên, điện thoại, email">
                <select name="salon_id"><option value="">Tất cả salon</option><?php foreach ($salons as $salon) : ?><option value="<?php echo esc_attr((string) $salon->id); ?>" <?php selected($filters['salon_id'], $salon->id); ?>><?php echo esc_html($salon->code . ' - ' . $salon->name); ?></option><?php endforeach; ?></select>
                <select name="status"><option value="">Tất cả trạng thái</option><?php foreach ($labels as $status => $label) : ?><option value="<?php echo esc_attr($status); ?>" <?php selected($filters['status'], $status); ?>><?php echo esc_html($label); ?></option><?php endforeach; ?></select>
                <input type="date" name="from" value="<?php echo esc_attr($filters['from']); ?>">
                <input type="date" name="to" value="<?php echo esc_attr($filters['to']); ?>">
                <select name="per_page"><option value="20" <?php selected($per_page, 20); ?>>20 dòng</option><option value="30" <?php selected($per_page, 30); ?>>30 dòng</option><option value="50" <?php selected($per_page, 50); ?>>50 dòng</option><option value="100" <?php selected($per_page, 100); ?>>100 dòng</option></select>
                <button class="button button-primary">Lọc</button>
                <a class="button" href="<?php echo esc_url(admin_url('admin.php?page=' . $page_slug)); ?>">Xóa lọc</a>
            </form>

            <section class="htp-panel">
                <p><strong><?php echo esc_html(number_format_i18n($total)); ?></strong> kết quả.</p>
                <div class="htp-table-wrap">
                    <table class="widefat striped htp-responsive-table">
                        <thead><tr><th>Mã</th><th>Khách hàng</th><th>Salon</th><th>Ngày đăng ký</th><th>Trạng thái</th><th>Thao tác</th></tr></thead>
                        <tbody>
                        <?php if (!$rows) : ?><tr><td colspan="6">Không có dữ liệu.</td></tr><?php else : foreach ($rows as $row) : ?>
                            <tr>
                                <td data-label="Mã"><strong><?php echo esc_html($row->submission_code); ?></strong></td>
                                <td data-label="Khách hàng"><strong><?php echo esc_html($row->full_name); ?></strong><br><a href="tel:<?php echo esc_attr(preg_replace('/[^0-9+]/', '', $row->phone)); ?>"><?php echo esc_html($row->phone); ?></a><?php if ($row->email) : ?><br><small><?php echo esc_html($row->email); ?></small><?php endif; ?></td>
                                <td data-label="Salon"><?php echo esc_html($row->salon_code . ' - ' . $row->salon_name); ?></td>
                                <td data-label="Ngày đăng ký"><?php echo esc_html(mysql2date('d/m/Y H:i', $row->created_at)); ?></td>
                                <td data-label="Trạng thái"><span class="htp-status-badge htp-status-<?php echo esc_attr($row->status); ?>"><?php echo esc_html($labels[$row->status] ?? $row->status); ?></span></td>
                                <td data-label="Thao tác"><a class="button" href="<?php echo esc_url(add_query_arg(['page' => $page_slug, 'submission_id' => $row->id], admin_url('admin.php'))); ?>">Xem</a></td>
                            </tr>
                        <?php endforeach; endif; ?>
                        </tbody>
                    </table>
                </div>
                <?php self::pagination($page, $per_page, $total); ?>
            </section>
        </div>
        <?php
    }

    private static function render_detail(HTP_Submission_Repository $repository, int $id, string $form_key, string $page_slug): void
    {
        $submission = $repository->find($id);
        if (!$submission || $submission->form_key !== $form_key || !HTP_User_Salon_Service::can_access_salon((int) $submission->salon_id)) {
            wp_die('Không tìm thấy dữ liệu hoặc bạn không có quyền truy cập.');
        }
        $values = $repository->values($id);
        $files = $repository->files($id);
        $logs = $repository->status_logs($id);
        $fields = (new HTP_Form_Repository())->fields((int) $submission->form_id);
        $labels = HTP_Submission_Service::status_labels($form_key);
        $back_url = admin_url('admin.php?page=' . $page_slug);
        ?>
        <div class="wrap htp-admin-wrap">
            <div class="htp-page-heading"><h1><?php echo esc_html($submission->submission_code); ?></h1><a class="button" href="<?php echo esc_url($back_url); ?>">← Quay lại danh sách</a></div>
            <div class="htp-two-column">
                <section class="htp-panel">
                    <h2>Thông tin đăng ký</h2>
                    <dl class="htp-detail-list">
                        <dt>Họ và tên</dt><dd><?php echo esc_html($submission->full_name); ?></dd>
                        <dt>Số điện thoại</dt><dd><?php echo esc_html($submission->phone); ?></dd>
                        <dt>Email</dt><dd><?php echo esc_html($submission->email ?: '—'); ?></dd>
                        <dt>Ngày sinh</dt><dd><?php echo esc_html($submission->date_of_birth ? mysql2date('d/m/Y', $submission->date_of_birth) : '—'); ?></dd>
                        <dt>Salon</dt><dd><?php echo esc_html($submission->salon_code . ' - ' . $submission->salon_name); ?></dd>
                        <dt>Ngày tạo</dt><dd><?php echo esc_html(mysql2date('d/m/Y H:i', $submission->created_at)); ?></dd>
                        <?php foreach ($fields as $field) :
                            if (in_array($field->field_key, ['full_name', 'phone', 'email', 'date_of_birth', 'consent'], true) || in_array($field->field_type, ['image', 'images'], true)) {
                                continue;
                            }
                            $value = $values[$field->field_key] ?? '';
                            if (is_array($value)) {
                                $value = implode(', ', array_map('strval', $value));
                            }
                            ?>
                            <dt><?php echo esc_html($field->label); ?></dt><dd><?php echo esc_html($value !== '' ? (string) $value : '—'); ?></dd>
                        <?php endforeach; ?>
                    </dl>

                    <?php if ($files) : ?>
                        <h3>Hình ảnh</h3>
                        <div class="htp-admin-images">
                            <?php foreach ($files as $file) :
                                $thumb = wp_get_attachment_image_url((int) $file->attachment_id, 'medium');
                                $full = wp_get_attachment_url((int) $file->attachment_id);
                                if (!$thumb || !$full) { continue; }
                                ?>
                                <a href="<?php echo esc_url($full); ?>" target="_blank" rel="noopener"><img src="<?php echo esc_url($thumb); ?>" alt="<?php echo esc_attr($file->field_key); ?>"></a>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </section>

                <section class="htp-panel">
                    <h2>Cập nhật trạng thái</h2>
                    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                        <input type="hidden" name="action" value="htp_update_submission_status">
                        <input type="hidden" name="submission_id" value="<?php echo esc_attr((string) $submission->id); ?>">
                        <input type="hidden" name="form_key" value="<?php echo esc_attr($form_key); ?>">
                        <?php wp_nonce_field('htp_update_submission_status_' . $submission->id); ?>
                        <p><select name="status" class="widefat"><?php foreach ($labels as $status => $label) : ?><option value="<?php echo esc_attr($status); ?>" <?php selected($submission->status, $status); ?>><?php echo esc_html($label); ?></option><?php endforeach; ?></select></p>
                        <p><textarea name="note" class="widefat" rows="4" placeholder="Ghi chú thay đổi"></textarea></p>
                        <?php submit_button('Cập nhật trạng thái'); ?>
                    </form>

                    <h3>Lịch sử</h3>
                    <ol class="htp-timeline">
                        <?php foreach ($logs as $log) : ?><li><strong><?php echo esc_html($labels[$log->new_status] ?? $log->new_status); ?></strong><span><?php echo esc_html(mysql2date('d/m/Y H:i', $log->changed_at)); ?><?php if ($log->display_name) : ?> · <?php echo esc_html($log->display_name); ?><?php endif; ?></span><?php if ($log->note) : ?><p><?php echo esc_html($log->note); ?></p><?php endif; ?></li><?php endforeach; ?>
                    </ol>
                </section>
            </div>
        </div>
        <?php
    }

    public static function update_status(): void
    {
        $id = absint($_POST['submission_id'] ?? 0);
        $form_key = sanitize_key((string) ($_POST['form_key'] ?? 'donation'));
        if (!current_user_can('htp_update_registration_status') || !$id) {
            wp_die('Không có quyền.');
        }
        check_admin_referer('htp_update_submission_status_' . $id);
        try {
            (new HTP_Submission_Service())->update_status($id, (string) ($_POST['status'] ?? ''), (string) ($_POST['note'] ?? ''));
        } catch (Throwable $exception) {
            wp_die(esc_html($exception->getMessage()));
        }
        $page_slug = $form_key === 'member' ? 'htp-members' : 'htp-donations';
        wp_safe_redirect(add_query_arg(['page' => $page_slug, 'submission_id' => $id, 'htp_message' => 'status'], admin_url('admin.php')));
        exit;
    }

    public static function export(): void
    {
        $form_key = sanitize_key((string) ($_GET['form_key'] ?? 'donation'));
        if (!current_user_can('htp_export_data') || !in_array($form_key, ['donation', 'member'], true)) {
            wp_die('Không có quyền.');
        }
        check_admin_referer('htp_export_submissions_' . $form_key);

        $allowed = current_user_can('htp_manage_registrations') ? null : HTP_User_Salon_Service::salon_ids_for_user();
        $filters = self::filters($form_key);
        $repository = new HTP_Submission_Repository();
        $base_rows = $repository->export_rows($filters, $allowed);
        $form = (new HTP_Form_Repository())->find_by_key($form_key);
        $fields = $form ? (new HTP_Form_Repository())->fields((int) $form->id) : [];
        $ids = array_map(static fn(array $row): int => (int) $row['id'], $base_rows);
        $all_values = $repository->bulk_values($ids);
        $all_files = $repository->bulk_files($ids);
        $labels = HTP_Submission_Service::status_labels($form_key);

        $headers = ['Mã', 'Salon', 'Họ và tên', 'Số điện thoại', 'Email', 'Ngày sinh', 'Trạng thái', 'Ngày đăng ký'];
        $extra_fields = [];
        foreach ($fields as $field) {
            if (in_array($field->field_key, ['full_name', 'phone', 'email', 'date_of_birth', 'consent'], true)) {
                continue;
            }
            $extra_fields[] = $field;
            $headers[] = $field->label;
        }
        $rows = [$headers];
        foreach ($base_rows as $row) {
            $line = [
                $row['submission_code'],
                $row['salon_code'] . ' - ' . $row['salon_name'],
                $row['full_name'],
                $row['phone'],
                $row['email'],
                $row['date_of_birth'],
                $labels[$row['status']] ?? $row['status'],
                mysql2date('d/m/Y H:i', $row['created_at']),
            ];
            foreach ($extra_fields as $field) {
                if (in_array($field->field_type, ['image', 'images'], true)) {
                    $urls = $all_files[$row['id']][$field->field_key] ?? [];
                    $line[] = implode(', ', $urls);
                } else {
                    $value = $all_values[$row['id']][$field->field_key] ?? '';
                    $line[] = is_array($value) ? implode(', ', array_map('strval', $value)) : (string) $value;
                }
            }
            $rows[] = $line;
        }

        HTP_Activity_Logger::log('submissions_exported', 'form', (int) ($form->id ?? 0), ['form_key' => $form_key, 'count' => count($base_rows)]);
        HTP_Xlsx_Exporter::download('myhair-' . $form_key . '-' . gmdate('Y-m-d-His') . '.xlsx', $rows);
    }

    private static function filters(string $form_key): array
    {
        return [
            'form_key' => $form_key,
            's' => sanitize_text_field(wp_unslash($_GET['s'] ?? '')),
            'salon_id' => absint($_GET['salon_id'] ?? 0),
            'status' => sanitize_key((string) ($_GET['status'] ?? '')),
            'from' => sanitize_text_field(wp_unslash($_GET['from'] ?? '')),
            'to' => sanitize_text_field(wp_unslash($_GET['to'] ?? '')),
        ];
    }

    private static function pagination(int $page, int $per_page, int $total): void
    {
        $pages = (int) ceil($total / max(1, $per_page));
        if ($pages <= 1) {
            return;
        }
        echo '<div class="tablenav"><div class="tablenav-pages">';
        echo wp_kses_post(paginate_links([
            'base' => add_query_arg('paged', '%#%'),
            'format' => '',
            'current' => $page,
            'total' => $pages,
            'prev_text' => '‹',
            'next_text' => '›',
        ]));
        echo '</div></div>';
    }
}
