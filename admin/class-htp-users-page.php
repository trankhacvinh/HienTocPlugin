<?php

defined('ABSPATH') || exit;

final class HTP_Users_Page
{
    public static function init(): void
    {
        add_action('admin_menu', [self::class, 'register_page'], 12);
        add_action('admin_post_htp_save_user', [self::class, 'save']);
        add_action('admin_post_htp_toggle_user', [self::class, 'toggle']);
    }

    public static function register_page(): void
    {
        add_submenu_page(
            'htp-dashboard',
            'Tài khoản',
            'Tài khoản',
            'htp_manage_users',
            'htp-users',
            [self::class, 'render']
        );
    }

    public static function render(): void
    {
        if (!current_user_can('htp_manage_users')) {
            wp_die(esc_html__('Bạn không có quyền truy cập.', 'hien-toc-plugin'));
        }
        HTP_Admin::notice_from_query();

        $edit_id = isset($_GET['user_id']) ? absint($_GET['user_id']) : 0;
        $editing = $edit_id ? get_user_by('id', $edit_id) : false;
        if ($editing && in_array('administrator', $editing->roles, true)) {
            wp_die(esc_html__('Không chỉnh sửa tài khoản Administrator trong module này.', 'hien-toc-plugin'));
        }
        $assigned = $editing ? HTP_User_Salon_Service::salon_ids_for_user($editing->ID) : [];
        $salons = (new HTP_Salon_Repository())->all();
        $users = HTP_User_Salon_Service::plugin_users();
        ?>
        <div class="wrap htp-admin-wrap">
            <h1>Quản lý tài khoản</h1>
            <div class="htp-two-column">
                <section class="htp-panel">
                    <h2><?php echo $editing ? 'Sửa tài khoản' : 'Tạo tài khoản'; ?></h2>
                    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                        <input type="hidden" name="action" value="htp_save_user">
                        <input type="hidden" name="user_id" value="<?php echo esc_attr((string) ($editing->ID ?? 0)); ?>">
                        <?php wp_nonce_field('htp_save_user'); ?>
                        <div class="htp-form-grid">
                            <label><span>Tên đăng nhập *</span><input name="user_login" required maxlength="60" value="<?php echo esc_attr($editing->user_login ?? ''); ?>" <?php disabled((bool) $editing); ?>></label>
                            <label><span>Họ tên *</span><input name="display_name" required maxlength="190" value="<?php echo esc_attr($editing->display_name ?? ''); ?>"></label>
                            <label><span>Email *</span><input type="email" name="user_email" required maxlength="190" value="<?php echo esc_attr($editing->user_email ?? ''); ?>"></label>
                            <label><span>Mật khẩu <?php echo $editing ? '(để trống nếu không đổi)' : '*'; ?></span><input type="password" name="user_pass" <?php echo $editing ? '' : 'required'; ?> autocomplete="new-password" minlength="8"></label>
                            <label class="htp-span-2"><span>Vai trò</span><select name="htp_role" required>
                                <option value="htp_program_manager" <?php selected($editing && in_array('htp_program_manager', $editing->roles, true)); ?>>Quản lý chương trình</option>
                                <option value="htp_salon_user" <?php selected(!$editing || in_array('htp_salon_user', $editing->roles ?? [], true)); ?>>Tài khoản salon</option>
                            </select></label>
                            <fieldset class="htp-span-2"><legend>Salon được phân công</legend><div class="htp-checkbox-grid">
                                <?php foreach ($salons as $salon) : ?>
                                    <label><input type="checkbox" name="salon_ids[]" value="<?php echo esc_attr((string) $salon->id); ?>" <?php checked(in_array((int) $salon->id, $assigned, true)); ?>> <?php echo esc_html($salon->code . ' — ' . $salon->name); ?></label>
                                <?php endforeach; ?>
                            </div><p class="description">Quản lý chương trình có thể xem toàn bộ; phân công salon chủ yếu áp dụng cho Tài khoản salon.</p></fieldset>
                        </div>
                        <?php submit_button($editing ? 'Lưu tài khoản' : 'Tạo tài khoản'); ?>
                        <?php if ($editing) : ?><a class="button" href="<?php echo esc_url(admin_url('admin.php?page=htp-users')); ?>">Hủy sửa</a><?php endif; ?>
                    </form>
                </section>

                <section class="htp-panel htp-help-panel">
                    <h2>Phân quyền</h2>
                    <p><strong>Quản lý chương trình:</strong> quản lý salon, đăng ký, tài khoản, báo cáo và xuất dữ liệu.</p>
                    <p><strong>Tài khoản salon:</strong> chỉ xem đăng ký thuộc salon được gán và cập nhật trạng thái theo luồng cho phép.</p>
                    <p>Tài khoản bị khóa sẽ không thể đăng nhập nhưng lịch sử thao tác vẫn được giữ.</p>
                </section>
            </div>

            <section class="htp-panel">
                <h2>Danh sách tài khoản</h2>
                <div class="htp-table-wrap"><table class="widefat striped htp-responsive-table">
                    <thead><tr><th>Tài khoản</th><th>Vai trò</th><th>Salon</th><th>Đăng nhập gần nhất</th><th>Trạng thái</th><th>Thao tác</th></tr></thead>
                    <tbody>
                    <?php foreach ($users as $user) :
                        $role_label = in_array('administrator', $user->roles, true) ? 'Administrator' : (in_array('htp_program_manager', $user->roles, true) ? 'Quản lý chương trình' : 'Tài khoản salon');
                        $salon_ids = HTP_User_Salon_Service::salon_ids_for_user($user->ID);
                        $user_salons = (new HTP_Salon_Repository())->all($salon_ids);
                        $disabled = (bool) get_user_meta($user->ID, 'htp_disabled', true);
                        $toggle_url = wp_nonce_url(add_query_arg(['action' => 'htp_toggle_user', 'user_id' => $user->ID, 'disabled' => $disabled ? 0 : 1], admin_url('admin-post.php')), 'htp_toggle_user_' . $user->ID);
                        ?>
                        <tr>
                            <td data-label="Tài khoản"><strong><?php echo esc_html($user->display_name); ?></strong><br><small><?php echo esc_html($user->user_login . ' — ' . $user->user_email); ?></small></td>
                            <td data-label="Vai trò"><?php echo esc_html($role_label); ?></td>
                            <td data-label="Salon"><?php echo esc_html(implode(', ', array_map(static fn($s) => $s->code, $user_salons)) ?: '—'); ?></td>
                            <td data-label="Đăng nhập gần nhất"><?php $last = get_user_meta($user->ID, 'htp_last_login', true); echo esc_html($last ? mysql2date('d/m/Y H:i', $last) : 'Chưa có'); ?></td>
                            <td data-label="Trạng thái"><span class="htp-status-badge <?php echo $disabled ? 'htp-status-inactive' : 'htp-status-active'; ?>"><?php echo $disabled ? 'Đã khóa' : 'Hoạt động'; ?></span></td>
                            <td data-label="Thao tác">
                                <?php if (!in_array('administrator', $user->roles, true)) : ?><a class="button" href="<?php echo esc_url(add_query_arg(['page' => 'htp-users', 'user_id' => $user->ID], admin_url('admin.php'))); ?>">Sửa</a><?php endif; ?>
                                <?php if ($user->ID !== get_current_user_id() && !in_array('administrator', $user->roles, true)) : ?><a class="button htp-confirm" href="<?php echo esc_url($toggle_url); ?>"><?php echo $disabled ? 'Mở khóa' : 'Khóa'; ?></a><?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table></div>
            </section>
        </div>
        <?php
    }

    public static function save(): void
    {
        if (!current_user_can('htp_manage_users')) {
            wp_die(esc_html__('Bạn không có quyền thực hiện thao tác này.', 'hien-toc-plugin'));
        }
        check_admin_referer('htp_save_user');

        $id = absint($_POST['user_id'] ?? 0);
        $login = sanitize_user(wp_unslash((string) ($_POST['user_login'] ?? '')), true);
        $email = sanitize_email(wp_unslash((string) ($_POST['user_email'] ?? '')));
        $display_name = sanitize_text_field(wp_unslash((string) ($_POST['display_name'] ?? '')));
        $password = (string) ($_POST['user_pass'] ?? '');
        $role = in_array($_POST['htp_role'] ?? '', ['htp_program_manager', 'htp_salon_user'], true) ? $_POST['htp_role'] : 'htp_salon_user';
        $salon_ids = isset($_POST['salon_ids']) && is_array($_POST['salon_ids']) ? array_map('absint', $_POST['salon_ids']) : [];

        if ($display_name === '' || !is_email($email) || (!$id && ($login === '' || strlen($password) < 8))) {
            wp_die('Thông tin tài khoản không hợp lệ. Mật khẩu mới phải có ít nhất 8 ký tự.');
        }

        if ($id) {
            $existing = get_user_by('id', $id);
            if (!$existing || in_array('administrator', $existing->roles, true)) {
                wp_die('Không thể chỉnh sửa tài khoản này trong module Hiến tóc.');
            }
        }

        $userdata = ['ID' => $id, 'user_email' => $email, 'display_name' => $display_name, 'role' => $role];
        if (!$id) {
            $userdata['user_login'] = $login;
        }
        if ($password !== '') {
            if (strlen($password) < 8) {
                wp_die('Mật khẩu phải có ít nhất 8 ký tự.');
            }
            $userdata['user_pass'] = $password;
        }

        $result = $id ? wp_update_user($userdata) : wp_insert_user($userdata);
        if (is_wp_error($result)) {
            wp_die(esc_html($result->get_error_message()));
        }
        $user_id = (int) $result;
        HTP_User_Salon_Service::assign($user_id, $salon_ids);
        HTP_Activity_Logger::log($id ? 'user_updated' : 'user_created', 'user', $user_id, ['role' => $role, 'salon_ids' => $salon_ids]);

        wp_safe_redirect(add_query_arg(['page' => 'htp-users', 'htp_message' => $id ? 'updated' : 'created'], admin_url('admin.php')));
        exit;
    }

    public static function toggle(): void
    {
        $id = absint($_GET['user_id'] ?? 0);
        if (!current_user_can('htp_manage_users') || !$id || $id === get_current_user_id()) {
            wp_die(esc_html__('Bạn không có quyền thực hiện thao tác này.', 'hien-toc-plugin'));
        }
        check_admin_referer('htp_toggle_user_' . $id);
        $disabled = !empty($_GET['disabled']) ? 1 : 0;
        update_user_meta($id, 'htp_disabled', $disabled);
        HTP_Activity_Logger::log('user_status_updated', 'user', $id, ['disabled' => $disabled]);
        wp_safe_redirect(add_query_arg(['page' => 'htp-users', 'htp_message' => 'status'], admin_url('admin.php')));
        exit;
    }
}
