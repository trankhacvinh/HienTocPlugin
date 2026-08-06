<?php

defined('ABSPATH') || exit;

final class HTP_Settings
{
    public static function init(): void
    {
        add_action('admin_menu', [self::class, 'register_page']);
        add_action('admin_init', [self::class, 'register_settings']);
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
        register_setting('htp_settings', 'htp_registration_page_id', [
            'type' => 'integer',
            'sanitize_callback' => 'absint',
            'default' => 0,
        ]);
    }

    public static function render(): void
    {
        if (!current_user_can('htp_manage_settings')) {
            wp_die(esc_html__('Bạn không có quyền truy cập.', 'hien-toc-plugin'));
        }

        $page_id = (int) get_option('htp_registration_page_id', 0);
        ?>
        <div class="wrap">
            <h1>Cài đặt Hiến tóc</h1>
            <form method="post" action="options.php">
                <?php settings_fields('htp_settings'); ?>
                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row"><label for="htp-registration-page">Trang đăng ký</label></th>
                        <td>
                            <?php
                            wp_dropdown_pages([
                                'name' => 'htp_registration_page_id',
                                'id' => 'htp-registration-page',
                                'selected' => $page_id,
                                'show_option_none' => '— Chọn một trang WordPress —',
                                'option_none_value' => 0,
                            ]);
                            ?>
                            <p class="description">Chèn shortcode <code>[htp_registration_form]</code> vào trang đã chọn. Link salon sẽ có dạng <code>?salon=MH001</code>.</p>
                            <?php if ($page_id) : ?>
                                <p><a href="<?php echo esc_url(get_permalink($page_id)); ?>" target="_blank" rel="noopener">Mở trang đăng ký</a></p>
                            <?php endif; ?>
                        </td>
                    </tr>
                </table>
                <?php submit_button('Lưu cài đặt'); ?>
            </form>
        </div>
        <?php
    }
}
