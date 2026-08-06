<?php

defined('ABSPATH') || exit;

final class HTP_Settings
{
    public static function init(): void
    {
        add_action('admin_menu', [self::class, 'register_page'], 15);
        add_action('admin_init', [self::class, 'register_settings']);
        add_action('update_option_htp_registration_page_id', [self::class, 'log_settings'], 10, 2);
    }

    public static function register_page(): void
    {
        add_submenu_page(
            'htp-dashboard',
            'Cài đặt',
            'Cài đặt',
            'htp_manage_settings',
            'htp-settings',
            [self::class, 'render']
        );
    }

    public static function register_settings(): void
    {
        register_setting('htp_settings', 'htp_registration_page_id', ['type' => 'integer', 'sanitize_callback' => 'absint', 'default' => 0]);
        register_setting('htp_settings', 'htp_lookup_page_id', ['type' => 'integer', 'sanitize_callback' => 'absint', 'default' => 0]);
        foreach (['htp_enable_date_of_birth', 'htp_enable_email', 'htp_enable_address', 'htp_enable_customer_note'] as $option) {
            register_setting('htp_settings', $option, ['type' => 'boolean', 'sanitize_callback' => static fn($value) => $value ? 1 : 0, 'default' => 1]);
        }
        register_setting('htp_settings', 'htp_duplicate_days', [
            'type' => 'integer',
            'sanitize_callback' => static fn($value) => max(1, min(365, absint($value))),
            'default' => 30,
        ]);
        register_setting('htp_settings', 'htp_privacy_text', ['type' => 'string', 'sanitize_callback' => 'sanitize_text_field']);
        register_setting('htp_settings', 'htp_success_text', ['type' => 'string', 'sanitize_callback' => 'sanitize_textarea_field']);
    }

    public static function render(): void
    {
        if (!current_user_can('htp_manage_settings')) {
            wp_die(esc_html__('Bạn không có quyền truy cập.', 'hien-toc-plugin'));
        }

        $registration_page = (int) get_option('htp_registration_page_id', 0);
        $lookup_page = (int) get_option('htp_lookup_page_id', 0);
        ?>
        <div class="wrap htp-admin-wrap">
            <h1>Cài đặt Hiến tóc</h1>
            <?php settings_errors(); ?>
            <form method="post" action="options.php">
                <?php settings_fields('htp_settings'); ?>
                <section class="htp-panel">
                    <h2>Trang WordPress và shortcode</h2>
                    <table class="form-table" role="presentation">
                        <tr>
                            <th><label for="htp-registration-page">Trang đăng ký</label></th>
                            <td>
                                <?php wp_dropdown_pages(['name' => 'htp_registration_page_id', 'id' => 'htp-registration-page', 'selected' => $registration_page, 'show_option_none' => '— Chọn trang —', 'option_none_value' => 0]); ?>
                                <p class="description">Trang cần chứa shortcode <code>[htp_registration_form]</code>. Mọi QR salon sẽ dùng permalink của trang này và thêm <code>?salon=XXXX</code>.</p>
                                <?php self::shortcode_status($registration_page, 'htp_registration_form'); ?>
                            </td>
                        </tr>
                        <tr>
                            <th><label for="htp-lookup-page">Trang tra cứu</label></th>
                            <td>
                                <?php wp_dropdown_pages(['name' => 'htp_lookup_page_id', 'id' => 'htp-lookup-page', 'selected' => $lookup_page, 'show_option_none' => '— Chọn trang —', 'option_none_value' => 0]); ?>
                                <p class="description">Trang cần chứa shortcode <code>[htp_registration_lookup]</code>.</p>
                                <?php self::shortcode_status($lookup_page, 'htp_registration_lookup'); ?>
                            </td>
                        </tr>
                    </table>
                </section>

                <section class="htp-panel">
                    <h2>Cấu hình form public</h2>
                    <div class="htp-settings-checks">
                        <?php foreach ([
                            'htp_enable_date_of_birth' => 'Hiển thị ngày sinh',
                            'htp_enable_email' => 'Hiển thị email',
                            'htp_enable_address' => 'Hiển thị địa chỉ',
                            'htp_enable_customer_note' => 'Hiển thị ghi chú khách hàng',
                        ] as $option => $label) : ?>
                            <label><input type="hidden" name="<?php echo esc_attr($option); ?>" value="0"><input type="checkbox" name="<?php echo esc_attr($option); ?>" value="1" <?php checked(get_option($option, 1), 1); ?>> <?php echo esc_html($label); ?></label>
                        <?php endforeach; ?>
                    </div>
                    <table class="form-table" role="presentation">
                        <tr><th><label for="htp-duplicate-days">Cảnh báo đăng ký trùng</label></th><td><input id="htp-duplicate-days" type="number" min="1" max="365" name="htp_duplicate_days" value="<?php echo esc_attr((string) get_option('htp_duplicate_days', 30)); ?>"> ngày gần nhất</td></tr>
                        <tr><th><label for="htp-privacy-text">Nội dung đồng ý dữ liệu</label></th><td><input id="htp-privacy-text" class="large-text" name="htp_privacy_text" value="<?php echo esc_attr((string) get_option('htp_privacy_text')); ?>"></td></tr>
                        <tr><th><label for="htp-success-text">Thông báo sau đăng ký</label></th><td><textarea id="htp-success-text" class="large-text" rows="3" name="htp_success_text"><?php echo esc_textarea((string) get_option('htp_success_text')); ?></textarea></td></tr>
                    </table>
                </section>

                <section class="htp-panel htp-danger-panel">
                    <h2>Gỡ cài đặt</h2>
                    <p>Khi plugin bị <strong>xóa</strong> trong trang Plugins, file <code>uninstall.php</code> sẽ xóa toàn bộ bảng dữ liệu, tùy chọn, vai trò và metadata do plugin tạo. Vô hiệu hóa plugin không xóa dữ liệu.</p>
                    <p><strong>Hãy xuất CSV hoặc sao lưu database trước khi xóa plugin.</strong></p>
                </section>

                <?php submit_button('Lưu cài đặt'); ?>
            </form>
        </div>
        <?php
    }

    public static function log_settings(mixed $old, mixed $new): void
    {
        HTP_Activity_Logger::log('settings_updated', 'settings', null, ['registration_page_id' => (int) $new]);
    }

    private static function shortcode_status(int $page_id, string $shortcode): void
    {
        if (!$page_id) {
            return;
        }
        $page = get_post($page_id);
        if (!$page) {
            echo '<p class="htp-check-bad">Không tìm thấy trang đã chọn.</p>';
            return;
        }
        if (has_shortcode($page->post_content, $shortcode)) {
            echo '<p class="htp-check-good">✓ Trang đã có shortcode yêu cầu. <a href="' . esc_url(get_permalink($page_id)) . '" target="_blank" rel="noopener">Mở trang</a></p>';
        } else {
            echo '<p class="htp-check-bad">⚠ Trang chưa có shortcode <code>[' . esc_html($shortcode) . ']</code>.</p>';
        }
    }
}
