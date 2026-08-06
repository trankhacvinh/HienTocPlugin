<?php

defined('ABSPATH') || exit;

final class HTP_Shortcodes
{
    public static function init(): void
    {
        add_shortcode('htp_registration_form', [self::class, 'registration_form']);
        add_shortcode('htp_salon_info', [self::class, 'salon_info']);
    }

    public static function registration_form(): string
    {
        $repository = new HTP_Salon_Repository();
        $salon_code = isset($_GET['salon']) ? sanitize_text_field(wp_unslash($_GET['salon'])) : '';
        $salon = $salon_code !== '' ? $repository->find_active_by_code($salon_code) : null;

        if (!$salon) {
            return self::notice(
                $salon_code === ''
                    ? 'Vui lòng quét mã QR của salon để mở đúng trang đăng ký.'
                    : 'Không tìm thấy salon hoặc salon hiện đang tạm ngừng tiếp nhận.',
                'warning'
            );
        }

        $error = '';
        $result = null;

        if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['htp_registration_submit'])) {
            $nonce = isset($_POST['htp_registration_nonce']) ? sanitize_text_field(wp_unslash($_POST['htp_registration_nonce'])) : '';

            if (!wp_verify_nonce($nonce, 'htp_register_' . $salon->id)) {
                $error = 'Phiên làm việc đã hết hạn. Vui lòng tải lại trang.';
            } elseif (!empty($_POST['website'])) {
                $error = 'Không thể gửi biểu mẫu.';
            } else {
                try {
                    $service = new HTP_Registration_Service();
                    $result = $service->register($salon, wp_unslash($_POST));
                } catch (Throwable $exception) {
                    $error = $exception->getMessage();
                }
            }
        }

        ob_start();
        ?>
        <section class="htp-public" aria-labelledby="htp-form-title">
            <?php echo self::salon_card($salon); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>

            <?php if ($result) : ?>
                <div class="htp-success" role="status">
                    <div class="htp-success__icon" aria-hidden="true">✓</div>
                    <h2>Đăng ký thành công</h2>
                    <p>Mã đăng ký của bạn:</p>
                    <strong class="htp-registration-code"><?php echo esc_html($result['registration_code']); ?></strong>
                    <p>Vui lòng lưu mã này và cung cấp cho salon khi đến hiến tóc.</p>
                </div>
            <?php else : ?>
                <div class="htp-form-card">
                    <h2 id="htp-form-title">Thông tin người đăng ký</h2>
                    <p class="htp-form-intro">Các trường có dấu <span aria-hidden="true">*</span> là bắt buộc.</p>

                    <?php if ($error !== '') : ?>
                        <?php echo self::notice($error, 'error'); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
                    <?php endif; ?>

                    <form method="post" class="htp-form" novalidate>
                        <?php wp_nonce_field('htp_register_' . $salon->id, 'htp_registration_nonce'); ?>
                        <input type="text" name="website" value="" class="htp-honeypot" tabindex="-1" autocomplete="off" aria-hidden="true">

                        <label class="htp-field">
                            <span>Họ và tên <b aria-hidden="true">*</b></span>
                            <input type="text" name="full_name" autocomplete="name" required maxlength="190" value="<?php echo esc_attr(self::old('full_name')); ?>">
                        </label>

                        <label class="htp-field">
                            <span>Số điện thoại <b aria-hidden="true">*</b></span>
                            <input type="tel" name="phone" inputmode="tel" autocomplete="tel" required maxlength="30" value="<?php echo esc_attr(self::old('phone')); ?>">
                        </label>

                        <label class="htp-field">
                            <span>Ngày sinh</span>
                            <input type="date" name="date_of_birth" value="<?php echo esc_attr(self::old('date_of_birth')); ?>">
                        </label>

                        <label class="htp-field">
                            <span>Email</span>
                            <input type="email" name="email" inputmode="email" autocomplete="email" maxlength="190" value="<?php echo esc_attr(self::old('email')); ?>">
                        </label>

                        <label class="htp-field">
                            <span>Địa chỉ</span>
                            <textarea name="address" rows="3" autocomplete="street-address"><?php echo esc_textarea(self::old('address')); ?></textarea>
                        </label>

                        <label class="htp-field">
                            <span>Ghi chú</span>
                            <textarea name="customer_note" rows="3"><?php echo esc_textarea(self::old('customer_note')); ?></textarea>
                        </label>

                        <label class="htp-consent">
                            <input type="checkbox" name="consent" value="1" required <?php checked(self::old('consent'), '1'); ?>>
                            <span>Tôi đồng ý cung cấp thông tin cho chương trình hiến tóc. <b aria-hidden="true">*</b></span>
                        </label>

                        <button type="submit" name="htp_registration_submit" value="1" class="htp-submit">Đăng ký hiến tóc</button>
                    </form>
                </div>
            <?php endif; ?>
        </section>
        <?php
        return (string) ob_get_clean();
    }

    public static function salon_info(): string
    {
        $code = isset($_GET['salon']) ? sanitize_text_field(wp_unslash($_GET['salon'])) : '';
        $salon = $code !== '' ? (new HTP_Salon_Repository())->find_active_by_code($code) : null;

        return $salon ? self::salon_card($salon) : self::notice('Không tìm thấy salon phù hợp.', 'warning');
    }

    private static function salon_card(object $salon): string
    {
        ob_start();
        ?>
        <div class="htp-salon-card">
            <div class="htp-salon-card__eyebrow">Bạn đang đăng ký tại</div>
            <h1><?php echo esc_html($salon->name); ?></h1>
            <div class="htp-salon-code">Mã salon: <?php echo esc_html($salon->code); ?></div>
            <?php if ($salon->address) : ?><p><strong>Địa chỉ:</strong> <?php echo esc_html($salon->address); ?></p><?php endif; ?>
            <?php if ($salon->phone) : ?><p><strong>Điện thoại:</strong> <a href="tel:<?php echo esc_attr(preg_replace('/[^0-9+]/', '', $salon->phone)); ?>"><?php echo esc_html($salon->phone); ?></a></p><?php endif; ?>
            <?php if ($salon->manager_name) : ?><p><strong>Người phụ trách:</strong> <?php echo esc_html($salon->manager_name); ?></p><?php endif; ?>
            <?php if ($salon->instruction) : ?><div class="htp-salon-instruction"><?php echo wp_kses_post(wpautop($salon->instruction)); ?></div><?php endif; ?>
            <p class="htp-salon-confirm">Vui lòng kiểm tra đúng salon trước khi nhập thông tin.</p>
        </div>
        <?php
        return (string) ob_get_clean();
    }

    private static function notice(string $message, string $type): string
    {
        return sprintf('<div class="htp-public htp-notice htp-notice--%s" role="alert">%s</div>', esc_attr($type), esc_html($message));
    }

    private static function old(string $key): string
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !isset($_POST[$key])) {
            return '';
        }

        return is_scalar($_POST[$key]) ? sanitize_text_field(wp_unslash((string) $_POST[$key])) : '';
    }
}
