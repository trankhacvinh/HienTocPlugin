<?php

defined('ABSPATH') || exit;

final class HTP_Settings
{
    public static function init(): void
    {
        add_action('admin_menu', [self::class, 'register_page']);
        add_action('admin_init', [self::class, 'register_settings']);
        add_action('admin_post_htp_enable_pretty_permalinks', [self::class, 'enable_pretty_permalinks']);
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
    }

    public static function render(): void
    {
        if (!current_user_can('htp_manage_settings')) {
            wp_die('Bạn không có quyền truy cập.');
        }
        HTP_Admin::notice_from_query();
        $structure = (string) get_option('permalink_structure', '');
        $pretty = $structure !== '' && !str_contains($structure, 'index.php');
        ?>
        <div class="wrap htp-admin-wrap">
            <h1>Cài đặt MyHair</h1>

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
}
