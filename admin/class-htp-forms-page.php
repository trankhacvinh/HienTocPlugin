<?php

defined('ABSPATH') || exit;

final class HTP_Forms_Page
{
    public static function init(): void
    {
        add_action('admin_menu', [self::class, 'register_page'], 12);
        add_action('admin_post_htp_save_form', [self::class, 'save']);
        add_action('admin_post_htp_add_form_field', [self::class, 'add_field']);
        add_action('admin_post_htp_delete_form_field', [self::class, 'delete_field']);
    }

    public static function register_page(): void
    {
        add_submenu_page(
            'htp-dashboard',
            'Cấu hình form',
            'Cấu hình form',
            'htp_manage_forms',
            'htp-forms',
            [self::class, 'render']
        );
    }

    public static function render(): void
    {
        if (!current_user_can('htp_manage_forms')) {
            wp_die(esc_html__('Bạn không có quyền truy cập.', 'hien-toc-plugin'));
        }

        HTP_Admin::notice_from_query();
        $repository = new HTP_Form_Repository();
        $form_key = sanitize_key((string) ($_GET['form_key'] ?? 'donation'));
        if (!in_array($form_key, ['donation', 'member'], true)) {
            $form_key = 'donation';
        }
        $form = $repository->find_by_key($form_key);
        if (!$form) {
            wp_die('Không tìm thấy form.');
        }
        $fields = $repository->fields((int) $form->id);
        $types = HTP_Form_Repository::field_types();
        ?>
        <div class="wrap htp-admin-wrap">
            <h1>Cấu hình form</h1>
            <nav class="nav-tab-wrapper">
                <a class="nav-tab <?php echo $form_key === 'donation' ? 'nav-tab-active' : ''; ?>" href="<?php echo esc_url(add_query_arg(['page' => 'htp-forms', 'form_key' => 'donation'], admin_url('admin.php'))); ?>">Hiến tóc</a>
                <a class="nav-tab <?php echo $form_key === 'member' ? 'nav-tab-active' : ''; ?>" href="<?php echo esc_url(add_query_arg(['page' => 'htp-forms', 'form_key' => 'member'], admin_url('admin.php'))); ?>">Thành viên</a>
            </nav>

            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="htp-panel htp-form-builder">
                <input type="hidden" name="action" value="htp_save_form">
                <input type="hidden" name="form_id" value="<?php echo esc_attr((string) $form->id); ?>">
                <input type="hidden" name="form_key" value="<?php echo esc_attr($form_key); ?>">
                <?php wp_nonce_field('htp_save_form_' . $form->id); ?>

                <div class="htp-form-grid">
                    <label><span>Tên form</span><input name="name" value="<?php echo esc_attr($form->name); ?>" required></label>
                    <label><span>Nhãn nút gửi</span><input name="submit_label" value="<?php echo esc_attr($form->submit_label); ?>" required></label>
                    <label class="htp-span-2"><span>Mô tả</span><textarea name="description" rows="2"><?php echo esc_textarea($form->description); ?></textarea></label>
                    <label class="htp-span-2"><span>Thông báo thành công</span><textarea name="success_message" rows="3"><?php echo esc_textarea($form->success_message); ?></textarea></label>
                    <label><span>Trạng thái</span><select name="status"><option value="active" <?php selected($form->status, 'active'); ?>>Đang hoạt động</option><option value="inactive" <?php selected($form->status, 'inactive'); ?>>Tạm ngừng</option></select></label>
                </div>

                <h2>Các trường dữ liệu</h2>
                <p>Kéo thả để sắp xếp. Trên điện thoại các trường sẽ tự chuyển thành một cột để dễ nhập.</p>
                <div class="htp-fields-list" data-htp-sortable>
                    <?php foreach ($fields as $field) :
                        $options = implode("\n", HTP_Form_Repository::decode_options($field->options_json));
                        $locked = (int) $field->system_field === 1 && in_array($field->field_key, ['full_name', 'phone', 'consent'], true);
                        ?>
                        <section class="htp-field-row" draggable="true" data-field-id="<?php echo esc_attr((string) $field->id); ?>">
                            <input type="hidden" name="field_order[]" value="<?php echo esc_attr((string) $field->id); ?>">
                            <div class="htp-drag-handle" title="Kéo để sắp xếp">⋮⋮</div>
                            <div class="htp-field-row__main">
                                <div class="htp-field-row__title">
                                    <strong><?php echo esc_html($field->label); ?></strong>
                                    <code><?php echo esc_html($field->field_key); ?></code>
                                    <?php if ($locked) : ?><span class="htp-lock">Trường bắt buộc hệ thống</span><?php endif; ?>
                                </div>
                                <div class="htp-field-config-grid">
                                    <label><span>Nhãn hiển thị</span><input name="fields[<?php echo esc_attr((string) $field->id); ?>][label]" value="<?php echo esc_attr($field->label); ?>"></label>
                                    <label><span>Loại trường</span><select name="fields[<?php echo esc_attr((string) $field->id); ?>][field_type]" <?php disabled((int) $field->system_field === 1); ?>><?php foreach ($types as $type => $type_label) : ?><option value="<?php echo esc_attr($type); ?>" <?php selected($field->field_type, $type); ?>><?php echo esc_html($type_label); ?></option><?php endforeach; ?></select><?php if ((int) $field->system_field === 1) : ?><input type="hidden" name="fields[<?php echo esc_attr((string) $field->id); ?>][field_type]" value="<?php echo esc_attr($field->field_type); ?>"><?php endif; ?></label>
                                    <label><span>Chiều rộng</span><select name="fields[<?php echo esc_attr((string) $field->id); ?>][width]"><option value="full" <?php selected($field->width, 'full'); ?>>Toàn dòng</option><option value="two_thirds" <?php selected($field->width, 'two_thirds'); ?>>2/3 dòng</option><option value="half" <?php selected($field->width, 'half'); ?>>1/2 dòng</option><option value="third" <?php selected($field->width, 'third'); ?>>1/3 dòng</option></select></label>
                                    <label><span>Placeholder</span><input name="fields[<?php echo esc_attr((string) $field->id); ?>][placeholder]" value="<?php echo esc_attr($field->placeholder); ?>"></label>
                                    <label class="htp-span-2"><span>Hướng dẫn</span><input name="fields[<?php echo esc_attr((string) $field->id); ?>][help_text]" value="<?php echo esc_attr($field->help_text); ?>"></label>
                                    <label class="htp-span-2"><span>Lựa chọn (mỗi dòng một giá trị)</span><textarea name="fields[<?php echo esc_attr((string) $field->id); ?>][options]" rows="3"><?php echo esc_textarea($options); ?></textarea></label>
                                </div>
                                <div class="htp-field-flags">
                                    <label><input type="checkbox" name="fields[<?php echo esc_attr((string) $field->id); ?>][enabled]" value="1" <?php checked((int) $field->enabled, 1); disabled($locked); ?>> Hiển thị</label>
                                    <label><input type="checkbox" name="fields[<?php echo esc_attr((string) $field->id); ?>][required]" value="1" <?php checked((int) $field->required, 1); disabled($locked); ?>> Bắt buộc</label>
                                    <?php if ($locked) : ?>
                                        <input type="hidden" name="fields[<?php echo esc_attr((string) $field->id); ?>][enabled]" value="1">
                                        <input type="hidden" name="fields[<?php echo esc_attr((string) $field->id); ?>][required]" value="1">
                                    <?php endif; ?>
                                    <?php if ((int) $field->system_field === 0) : ?>
                                        <a class="button-link-delete htp-confirm" href="<?php echo esc_url(wp_nonce_url(add_query_arg(['action' => 'htp_delete_form_field', 'field_id' => $field->id, 'form_key' => $form_key], admin_url('admin-post.php')), 'htp_delete_form_field_' . $field->id)); ?>">Xóa trường</a>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </section>
                    <?php endforeach; ?>
                </div>

                <?php submit_button('Lưu cấu hình form'); ?>
            </form>

            <section class="htp-panel">
                <h2>Thêm trường tùy chỉnh</h2>
                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                    <input type="hidden" name="action" value="htp_add_form_field">
                    <input type="hidden" name="form_id" value="<?php echo esc_attr((string) $form->id); ?>">
                    <input type="hidden" name="form_key" value="<?php echo esc_attr($form_key); ?>">
                    <?php wp_nonce_field('htp_add_form_field_' . $form->id); ?>
                    <div class="htp-form-grid">
                        <label><span>Tên trường *</span><input name="label" required></label>
                        <label><span>Loại trường</span><select name="field_type"><?php foreach ($types as $type => $type_label) : ?><option value="<?php echo esc_attr($type); ?>"><?php echo esc_html($type_label); ?></option><?php endforeach; ?></select></label>
                        <label><span>Chiều rộng</span><select name="width"><option value="full">Toàn dòng</option><option value="two_thirds">2/3 dòng</option><option value="half">1/2 dòng</option><option value="third">1/3 dòng</option></select></label>
                        <label><span>Placeholder</span><input name="placeholder"></label>
                        <label class="htp-span-2"><span>Các lựa chọn</span><textarea name="options" rows="3" placeholder="Mỗi dòng một lựa chọn"></textarea></label>
                        <label class="htp-span-2"><input type="checkbox" name="required" value="1"> Bắt buộc nhập</label>
                    </div>
                    <?php submit_button('Thêm trường'); ?>
                </form>
            </section>
        </div>
        <?php
    }

    public static function save(): void
    {
        $form_id = absint($_POST['form_id'] ?? 0);
        if (!current_user_can('htp_manage_forms') || !$form_id) {
            wp_die('Không có quyền.');
        }
        check_admin_referer('htp_save_form_' . $form_id);

        $repository = new HTP_Form_Repository();
        $rows = [];
        $posted_fields = isset($_POST['fields']) && is_array($_POST['fields']) ? wp_unslash($_POST['fields']) : [];
        $order = isset($_POST['field_order']) && is_array($_POST['field_order']) ? array_map('absint', $_POST['field_order']) : [];
        foreach ($order as $field_id) {
            if (isset($posted_fields[$field_id])) {
                $rows[$field_id] = $posted_fields[$field_id];
            }
        }
        foreach ($posted_fields as $field_id => $row) {
            if (!isset($rows[$field_id])) {
                $rows[$field_id] = $row;
            }
        }

        try {
            $repository->update_form($form_id, wp_unslash($_POST));
            $repository->update_fields($form_id, $rows);
            HTP_Activity_Logger::log('form_updated', 'form', $form_id);
        } catch (Throwable $exception) {
            wp_die(esc_html($exception->getMessage()));
        }

        self::redirect((string) ($_POST['form_key'] ?? 'donation'), 'saved');
    }

    public static function add_field(): void
    {
        $form_id = absint($_POST['form_id'] ?? 0);
        if (!current_user_can('htp_manage_forms') || !$form_id) {
            wp_die('Không có quyền.');
        }
        check_admin_referer('htp_add_form_field_' . $form_id);
        try {
            (new HTP_Form_Repository())->add_custom_field($form_id, wp_unslash($_POST));
            HTP_Activity_Logger::log('form_field_created', 'form', $form_id);
        } catch (Throwable $exception) {
            wp_die(esc_html($exception->getMessage()));
        }
        self::redirect((string) ($_POST['form_key'] ?? 'donation'), 'created');
    }

    public static function delete_field(): void
    {
        $field_id = absint($_GET['field_id'] ?? 0);
        if (!current_user_can('htp_manage_forms') || !$field_id) {
            wp_die('Không có quyền.');
        }
        check_admin_referer('htp_delete_form_field_' . $field_id);
        try {
            (new HTP_Form_Repository())->delete_custom_field($field_id);
            HTP_Activity_Logger::log('form_field_deleted', 'form_field', $field_id);
        } catch (Throwable $exception) {
            wp_die(esc_html($exception->getMessage()));
        }
        self::redirect((string) ($_GET['form_key'] ?? 'donation'), 'updated');
    }

    private static function redirect(string $form_key, string $message): void
    {
        wp_safe_redirect(add_query_arg(['page' => 'htp-forms', 'form_key' => sanitize_key($form_key), 'htp_message' => $message], admin_url('admin.php')));
        exit;
    }
}
