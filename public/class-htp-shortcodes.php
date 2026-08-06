<?php

defined('ABSPATH') || exit;

final class HTP_Shortcodes
{
    public static function init(): void
    {
        add_shortcode('htp_registration_form', [self::class, 'registration_form']);
        add_shortcode('htp_salon_info', [self::class, 'salon_info']);
        add_shortcode('htp_registration_lookup', [self::class, 'registration_lookup']);
        add_shortcode('htp_salon_list', [self::class, 'salon_list']);
        add_shortcode('htp_statistics', [self::class, 'statistics']);
    }

    public static function registration_form(): string
    {
        $repository = new HTP_Salon_Repository();
        $salon_code = self::salon_code_from_request();
        $salon = $salon_code !== '' ? $repository->find_active_by_code($salon_code) : null;

        if (!$salon) {
            return self::missing_salon($salon_code);
        }

        $service = new HTP_Registration_Service();
        $service->record_visit($salon);
        $error = '';
        $result = null;
        $duplicate_warning = [];

        if (self::is_registration_post()) {
            $nonce = isset($_POST['htp_registration_nonce']) ? sanitize_text_field(wp_unslash($_POST['htp_registration_nonce'])) : '';
            $posted_salon = isset($_POST['htp_salon_code']) ? strtoupper(sanitize_text_field(wp_unslash($_POST['htp_salon_code']))) : '';

            if (!wp_verify_nonce($nonce, 'htp_register_' . $salon->id)) {
                $error = 'Phiên làm việc đã hết hạn. Vui lòng tải lại trang.';
            } elseif (!empty($_POST['website'])) {
                $error = 'Không thể gửi biểu mẫu.';
            } elseif ($posted_salon !== strtoupper((string) $salon->code)) {
                $error = 'Thông tin salon không hợp lệ. Vui lòng tải lại trang.';
            } else {
                try {
                    $duplicate_warning = $service->recent_duplicates($salon, (string) ($_POST['phone'] ?? ''));
                    $confirmed_duplicate = !empty($_POST['confirm_duplicate']);
                    if ($duplicate_warning && !$confirmed_duplicate) {
                        $error = 'Số điện thoại này đã có đăng ký gần đây tại salon. Vui lòng kiểm tra và xác nhận nếu bạn vẫn muốn tạo đăng ký mới.';
                    } else {
                        $input = wp_unslash($_POST);
                        $input['source_url'] = self::current_url();
                        $result = $service->register($salon, $input);
                    }
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
                    <p><?php echo esc_html((string) get_option('htp_success_text', 'Vui lòng lưu mã đăng ký và cung cấp cho salon khi đến hiến tóc.')); ?></p>
                    <?php $lookup_page = (int) get_option('htp_lookup_page_id', 0); ?>
                    <?php if ($lookup_page) : ?>
                        <p><a class="htp-secondary-link" href="<?php echo esc_url(get_permalink($lookup_page)); ?>">Tra cứu trạng thái đăng ký</a></p>
                    <?php endif; ?>
                </div>
            <?php else : ?>
                <div class="htp-form-card">
                    <h2 id="htp-form-title">Thông tin người đăng ký</h2>
                    <p class="htp-form-intro">Các trường có dấu <span aria-hidden="true">*</span> là bắt buộc.</p>

                    <?php if ($error !== '') : ?>
                        <?php echo self::notice($error, 'error'); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
                    <?php endif; ?>

                    <?php if ($duplicate_warning) : ?>
                        <div class="htp-duplicate-box">
                            <strong>Đăng ký gần đây:</strong>
                            <ul>
                                <?php foreach ($duplicate_warning as $duplicate) : ?>
                                    <li><?php echo esc_html($duplicate->registration_code . ' — ' . mysql2date('d/m/Y H:i', $duplicate->registered_at)); ?></li>
                                <?php endforeach; ?>
                            </ul>
                        </div>
                    <?php endif; ?>

                    <form method="post" class="htp-form">
                        <?php wp_nonce_field('htp_register_' . $salon->id, 'htp_registration_nonce'); ?>
                        <input type="hidden" name="htp_salon_code" value="<?php echo esc_attr($salon->code); ?>">
                        <input type="text" name="website" value="" class="htp-honeypot" tabindex="-1" autocomplete="off" aria-hidden="true">

                        <label class="htp-field">
                            <span>Họ và tên <b aria-hidden="true">*</b></span>
                            <input type="text" name="full_name" autocomplete="name" required maxlength="190" value="<?php echo esc_attr(self::old('full_name')); ?>">
                        </label>

                        <label class="htp-field">
                            <span>Số điện thoại <b aria-hidden="true">*</b></span>
                            <input type="tel" name="phone" inputmode="tel" autocomplete="tel" required maxlength="30" value="<?php echo esc_attr(self::old('phone')); ?>">
                        </label>

                        <?php if (get_option('htp_enable_date_of_birth', 1)) : ?>
                            <label class="htp-field">
                                <span>Ngày sinh</span>
                                <input type="date" name="date_of_birth" value="<?php echo esc_attr(self::old('date_of_birth')); ?>">
                            </label>
                        <?php endif; ?>

                        <?php if (get_option('htp_enable_email', 1)) : ?>
                            <label class="htp-field">
                                <span>Email</span>
                                <input type="email" name="email" inputmode="email" autocomplete="email" maxlength="190" value="<?php echo esc_attr(self::old('email')); ?>">
                            </label>
                        <?php endif; ?>

                        <?php if (get_option('htp_enable_address', 1)) : ?>
                            <label class="htp-field">
                                <span>Địa chỉ</span>
                                <textarea name="address" rows="3" autocomplete="street-address"><?php echo esc_textarea(self::old('address')); ?></textarea>
                            </label>
                        <?php endif; ?>

                        <?php if (get_option('htp_enable_customer_note', 1)) : ?>
                            <label class="htp-field">
                                <span>Ghi chú</span>
                                <textarea name="customer_note" rows="3"><?php echo esc_textarea(self::old('customer_note')); ?></textarea>
                            </label>
                        <?php endif; ?>

                        <?php if ($duplicate_warning) : ?>
                            <label class="htp-consent htp-consent--warning">
                                <input type="checkbox" name="confirm_duplicate" value="1" required>
                                <span>Tôi xác nhận vẫn muốn tạo một đăng ký mới.</span>
                            </label>
                        <?php endif; ?>

                        <label class="htp-consent">
                            <input type="checkbox" name="consent" value="1" required <?php checked(self::old('consent'), '1'); ?>>
                            <span><?php echo esc_html((string) get_option('htp_privacy_text', 'Tôi đồng ý cung cấp thông tin cho chương trình hiến tóc.')); ?> <b aria-hidden="true">*</b></span>
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
        $code = self::salon_code_from_request();
        $salon = $code !== '' ? (new HTP_Salon_Repository())->find_active_by_code($code) : null;
        return $salon ? self::salon_card($salon) : self::missing_salon($code);
    }

    public static function registration_lookup(): string
    {
        $result = null;
        $error = '';
        if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['htp_lookup_submit'])) {
            $nonce = isset($_POST['htp_lookup_nonce']) ? sanitize_text_field(wp_unslash($_POST['htp_lookup_nonce'])) : '';
            if (!wp_verify_nonce($nonce, 'htp_lookup')) {
                $error = 'Phiên làm việc đã hết hạn. Vui lòng tải lại trang.';
            } else {
                $code = isset($_POST['registration_code']) ? sanitize_text_field(wp_unslash($_POST['registration_code'])) : '';
                $last4 = isset($_POST['phone_last4']) ? sanitize_text_field(wp_unslash($_POST['phone_last4'])) : '';
                $result = (new HTP_Registration_Repository())->find_by_code_and_phone_last4($code, $last4);
                if (!$result) {
                    $error = 'Không tìm thấy đăng ký. Vui lòng kiểm tra mã và 4 số cuối điện thoại.';
                }
            }
        }

        $labels = HTP_Registration_Service::status_labels();
        ob_start();
        ?>
        <section class="htp-public">
            <div class="htp-form-card">
                <h2>Tra cứu đăng ký</h2>
                <p class="htp-form-intro">Nhập mã đăng ký và 4 số cuối điện thoại.</p>
                <?php if ($error) : echo self::notice($error, 'error'); endif; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
                <form method="post" class="htp-form">
                    <?php wp_nonce_field('htp_lookup', 'htp_lookup_nonce'); ?>
                    <label class="htp-field"><span>Mã đăng ký</span><input type="text" name="registration_code" required maxlength="80" autocapitalize="characters" value="<?php echo esc_attr(self::old('registration_code')); ?>"></label>
                    <label class="htp-field"><span>4 số cuối điện thoại</span><input type="text" name="phone_last4" inputmode="numeric" pattern="[0-9]{4}" maxlength="4" required value="<?php echo esc_attr(self::old('phone_last4')); ?>"></label>
                    <button type="submit" name="htp_lookup_submit" value="1" class="htp-submit">Tra cứu</button>
                </form>
            </div>
            <?php if ($result) : ?>
                <div class="htp-success htp-lookup-result">
                    <h2><?php echo esc_html($result->registration_code); ?></h2>
                    <dl>
                        <div><dt>Salon</dt><dd><?php echo esc_html($result->salon_name); ?></dd></div>
                        <div><dt>Ngày đăng ký</dt><dd><?php echo esc_html(mysql2date('d/m/Y H:i', $result->registered_at)); ?></dd></div>
                        <div><dt>Trạng thái</dt><dd><span class="htp-status htp-status--<?php echo esc_attr($result->status); ?>"><?php echo esc_html($labels[$result->status] ?? $result->status); ?></span></dd></div>
                    </dl>
                </div>
            <?php endif; ?>
        </section>
        <?php
        return (string) ob_get_clean();
    }

    public static function salon_list(): string
    {
        $salons = (new HTP_Salon_Repository())->all([], true);
        ob_start();
        ?>
        <div class="htp-public htp-salon-list">
            <?php if (!$salons) : ?>
                <?php echo self::notice('Hiện chưa có salon đang tiếp nhận.', 'warning'); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
            <?php else : ?>
                <?php foreach ($salons as $salon) : ?>
                    <article class="htp-salon-list__item">
                        <h3><?php echo esc_html($salon->name); ?></h3>
                        <p class="htp-salon-code">Mã salon: <?php echo esc_html($salon->code); ?></p>
                        <?php if ($salon->address) : ?><p><?php echo esc_html($salon->address); ?></p><?php endif; ?>
                        <?php if ($salon->phone) : ?><p><a href="tel:<?php echo esc_attr(preg_replace('/[^0-9+]/', '', $salon->phone)); ?>"><?php echo esc_html($salon->phone); ?></a></p><?php endif; ?>
                        <a class="htp-submit htp-submit--link" href="<?php echo esc_url(HTP_QR_Service::registration_url($salon)); ?>">Đăng ký tại salon này</a>
                    </article>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
        <?php
        return (string) ob_get_clean();
    }

    public static function statistics(): string
    {
        $counts = (new HTP_Registration_Repository())->dashboard_counts(null);
        ob_start();
        ?>
        <div class="htp-public htp-public-stats">
            <div><strong><?php echo esc_html((string) $counts['total']); ?></strong><span>Lượt đăng ký</span></div>
            <div><strong><?php echo esc_html((string) $counts['received']); ?></strong><span>Đã tiếp nhận</span></div>
            <div><strong><?php echo esc_html((string) $counts['completed']); ?></strong><span>Đã hoàn thành</span></div>
        </div>
        <?php
        return (string) ob_get_clean();
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

    private static function missing_salon(string $code): string
    {
        $message = $code === ''
            ? 'Vui lòng quét mã QR của salon hoặc chọn một salon bên dưới.'
            : 'Không tìm thấy salon hoặc salon hiện đang tạm ngừng tiếp nhận.';
        $salons = (new HTP_Salon_Repository())->all([], true);
        ob_start();
        ?>
        <div class="htp-public">
            <?php echo self::notice($message, 'warning'); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
            <?php if ($salons) : ?>
                <div class="htp-form-card"><h2>Chọn salon</h2><div class="htp-salon-picker">
                    <?php foreach ($salons as $salon) : ?>
                        <a href="<?php echo esc_url(add_query_arg('salon', $salon->code)); ?>"><strong><?php echo esc_html($salon->name); ?></strong><span><?php echo esc_html($salon->address); ?></span></a>
                    <?php endforeach; ?>
                </div></div>
            <?php endif; ?>
        </div>
        <?php
        return (string) ob_get_clean();
    }

    private static function notice(string $message, string $type): string
    {
        return sprintf('<div class="htp-notice htp-notice--%s" role="alert">%s</div>', esc_attr($type), esc_html($message));
    }

    private static function old(string $key): string
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !isset($_POST[$key])) {
            return '';
        }
        return is_scalar($_POST[$key]) ? sanitize_text_field(wp_unslash((string) $_POST[$key])) : '';
    }

    private static function salon_code_from_request(): string
    {
        return isset($_GET['salon']) ? strtoupper(trim(sanitize_text_field(wp_unslash($_GET['salon'])))) : '';
    }

    private static function is_registration_post(): bool
    {
        return $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['htp_registration_submit']);
    }

    private static function current_url(): string
    {
        $scheme = is_ssl() ? 'https' : 'http';
        $host = isset($_SERVER['HTTP_HOST']) ? sanitize_text_field(wp_unslash($_SERVER['HTTP_HOST'])) : '';
        $uri = isset($_SERVER['REQUEST_URI']) ? esc_url_raw(wp_unslash($_SERVER['REQUEST_URI'])) : '';
        return esc_url_raw($scheme . '://' . $host . $uri);
    }
}
