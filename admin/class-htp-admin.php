<?php

defined('ABSPATH') || exit;

final class HTP_Admin
{
    public static function init(): void
    {
        add_action('admin_menu', [self::class, 'register_menu']);
        add_action('admin_post_htp_create_salon', [self::class, 'create_salon']);
    }

    public static function register_menu(): void
    {
        add_menu_page(
            'Hiến tóc',
            'Hiến tóc',
            'htp_manage_salons',
            'htp-dashboard',
            [self::class, 'render_dashboard'],
            'dashicons-heart',
            26
        );

        add_submenu_page(
            'htp-dashboard',
            'Salon',
            'Salon',
            'htp_manage_salons',
            'htp-salons',
            [self::class, 'render_salons']
        );
    }

    public static function render_dashboard(): void
    {
        if (!current_user_can('htp_manage_salons')) {
            wp_die(esc_html__('Bạn không có quyền truy cập.', 'hien-toc-plugin'));
        }

        global $wpdb;
        $salons = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}htp_salons");
        $registrations = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}htp_registrations");
        ?>
        <div class="wrap">
            <h1>Quản lý chương trình hiến tóc</h1>
            <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:16px;max-width:760px;margin-top:20px">
                <div class="postbox" style="padding:20px"><strong>Tổng salon</strong><div style="font-size:32px;margin-top:8px"><?php echo esc_html((string) $salons); ?></div></div>
                <div class="postbox" style="padding:20px"><strong>Tổng đăng ký</strong><div style="font-size:32px;margin-top:8px"><?php echo esc_html((string) $registrations); ?></div></div>
            </div>
            <p><code>[htp_registration_form]</code> — chèn form đăng ký vào trang WordPress bất kỳ. Trang sẽ tự đọc tham số <code>?salon=XXXX</code>.</p>
        </div>
        <?php
    }

    public static function render_salons(): void
    {
        if (!current_user_can('htp_manage_salons')) {
            wp_die(esc_html__('Bạn không có quyền truy cập.', 'hien-toc-plugin'));
        }

        $salons = (new HTP_Salon_Repository())->all();
        $registration_page = (int) get_option('htp_registration_page_id', 0);
        $base_url = $registration_page ? get_permalink($registration_page) : home_url('/');
        ?>
        <div class="wrap">
            <h1 class="wp-heading-inline">Salon</h1>
            <?php if (isset($_GET['created'])) : ?><div class="notice notice-success is-dismissible"><p>Đã tạo salon.</p></div><?php endif; ?>

            <h2>Thêm salon</h2>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="max-width:720px">
                <input type="hidden" name="action" value="htp_create_salon">
                <?php wp_nonce_field('htp_create_salon'); ?>
                <table class="form-table" role="presentation">
                    <tr><th><label for="htp-code">Mã salon</label></th><td><input id="htp-code" name="code" class="regular-text" required pattern="[A-Za-z0-9-]+" placeholder="MH001"></td></tr>
                    <tr><th><label for="htp-name">Tên salon</label></th><td><input id="htp-name" name="name" class="regular-text" required></td></tr>
                    <tr><th><label for="htp-address">Địa chỉ</label></th><td><textarea id="htp-address" name="address" class="large-text" rows="3"></textarea></td></tr>
                    <tr><th><label for="htp-phone">Điện thoại</label></th><td><input id="htp-phone" name="phone" class="regular-text" inputmode="tel"></td></tr>
                    <tr><th><label for="htp-manager">Người phụ trách</label></th><td><input id="htp-manager" name="manager_name" class="regular-text"></td></tr>
                </table>
                <?php submit_button('Tạo salon'); ?>
            </form>

            <h2>Danh sách salon</h2>
            <table class="widefat striped">
                <thead><tr><th>Mã</th><th>Tên salon</th><th>Địa chỉ</th><th>Điện thoại</th><th>Đường dẫn đăng ký</th><th>Trạng thái</th></tr></thead>
                <tbody>
                <?php if (!$salons) : ?>
                    <tr><td colspan="6">Chưa có salon.</td></tr>
                <?php else : foreach ($salons as $salon) :
                    $url = add_query_arg('salon', $salon->code, $base_url);
                    ?>
                    <tr>
                        <td><strong><?php echo esc_html($salon->code); ?></strong></td>
                        <td><?php echo esc_html($salon->name); ?></td>
                        <td><?php echo esc_html($salon->address); ?></td>
                        <td><?php echo esc_html($salon->phone); ?></td>
                        <td><input type="text" readonly value="<?php echo esc_attr($url); ?>" style="width:100%"></td>
                        <td><?php echo $salon->status === 'active' ? 'Đang hoạt động' : 'Tạm ngừng'; ?></td>
                    </tr>
                <?php endforeach; endif; ?>
                </tbody>
            </table>
        </div>
        <?php
    }

    public static function create_salon(): void
    {
        if (!current_user_can('htp_manage_salons')) {
            wp_die(esc_html__('Bạn không có quyền thực hiện thao tác này.', 'hien-toc-plugin'));
        }

        check_admin_referer('htp_create_salon');

        $code = isset($_POST['code']) ? strtoupper(sanitize_text_field(wp_unslash($_POST['code']))) : '';
        $name = isset($_POST['name']) ? sanitize_text_field(wp_unslash($_POST['name'])) : '';

        if ($code === '' || $name === '' || !preg_match('/^[A-Z0-9-]+$/', $code)) {
            wp_die(esc_html__('Mã salon hoặc tên salon không hợp lệ.', 'hien-toc-plugin'));
        }

        (new HTP_Salon_Repository())->create([
            'code' => $code,
            'name' => $name,
            'address' => $_POST['address'] ?? '',
            'phone' => $_POST['phone'] ?? '',
            'manager_name' => $_POST['manager_name'] ?? '',
            'status' => 'active',
        ]);

        wp_safe_redirect(add_query_arg('created', '1', admin_url('admin.php?page=htp-salons')));
        exit;
    }
}
