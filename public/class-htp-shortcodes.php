<?php

defined('ABSPATH') || exit;

final class HTP_Shortcodes
{
    public static function init(): void
    {
        add_shortcode('htp_salon_landing', [self::class, 'salon_landing']);
        add_shortcode('htp_donation_form', [self::class, 'donation_form']);
        add_shortcode('htp_member_form', [self::class, 'member_form']);
        add_shortcode('htp_registration_form', [self::class, 'donation_form']);
        add_shortcode('htp_registration_lookup', [self::class, 'lookup']);
        add_shortcode('htp_salon_list', [self::class, 'salon_list']);
        add_shortcode('htp_statistics', [self::class, 'statistics']);
    }

    public static function salon_landing(array $atts = []): string
    {
        $atts = shortcode_atts(['salon' => ''], $atts, 'htp_salon_landing');
        $salon = (new HTP_Landing_Service())->resolve_salon($atts);
        if (!$salon) {
            return self::notice('Không tìm thấy salon hoặc salon hiện đang tạm ngừng hoạt động.', 'warning');
        }

        $active_form = isset($_POST['htp_form_key'])
            ? sanitize_key(wp_unslash($_POST['htp_form_key']))
            : sanitize_key((string) ($_GET['form'] ?? 'donation'));
        if (!in_array($active_form, ['donation', 'member'], true)) {
            $active_form = 'donation';
        }

        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            (new HTP_Submission_Service())->record_visit($salon, $active_form);
        }

        ob_start();
        ?>
        <section class="htp-public htp-landing" data-htp-tabs data-active-tab="<?php echo esc_attr($active_form); ?>">
            <?php echo self::salon_card($salon); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>

            <div class="htp-tabs" role="tablist" aria-label="Chọn loại đăng ký">
                <button type="button" class="htp-tab <?php echo $active_form === 'donation' ? 'is-active' : ''; ?>" role="tab" aria-selected="<?php echo $active_form === 'donation' ? 'true' : 'false'; ?>" aria-controls="htp-panel-donation" data-htp-tab="donation">
                    <span class="htp-tab__icon" aria-hidden="true">✂</span>
                    <span>Đăng ký hiến tóc</span>
                </button>
                <button type="button" class="htp-tab <?php echo $active_form === 'member' ? 'is-active' : ''; ?>" role="tab" aria-selected="<?php echo $active_form === 'member' ? 'true' : 'false'; ?>" aria-controls="htp-panel-member" data-htp-tab="member">
                    <span class="htp-tab__icon" aria-hidden="true">♡</span>
                    <span>Đăng ký thành viên</span>
                </button>
            </div>

            <div id="htp-panel-donation" class="htp-tab-panel <?php echo $active_form === 'donation' ? 'is-active' : ''; ?>" role="tabpanel" data-htp-panel="donation">
                <?php echo self::render_form($salon, 'donation'); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
            </div>
            <div id="htp-panel-member" class="htp-tab-panel <?php echo $active_form === 'member' ? 'is-active' : ''; ?>" role="tabpanel" data-htp-panel="member">
                <?php echo self::render_form($salon, 'member'); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
            </div>
        </section>
        <?php
        return (string) ob_get_clean();
    }

    public static function donation_form(array $atts = []): string
    {
        $salon = (new HTP_Landing_Service())->resolve_salon(shortcode_atts(['salon' => ''], $atts, 'htp_donation_form'));
        return $salon ? self::render_form($salon, 'donation') : self::notice('Không tìm thấy salon phù hợp.', 'warning');
    }

    public static function member_form(array $atts = []): string
    {
        $salon = (new HTP_Landing_Service())->resolve_salon(shortcode_atts(['salon' => ''], $atts, 'htp_member_form'));
        return $salon ? self::render_form($salon, 'member') : self::notice('Không tìm thấy salon phù hợp.', 'warning');
    }

    public static function lookup(): string
    {
        $result = null;
        $error = '';
        if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['htp_lookup_submit'])) {
            $nonce = sanitize_text_field(wp_unslash($_POST['htp_lookup_nonce'] ?? ''));
            if (!wp_verify_nonce($nonce, 'htp_lookup')) {
                $error = 'Phiên làm việc đã hết hạn. Vui lòng tải lại trang.';
            } else {
                $code = sanitize_text_field(wp_unslash($_POST['submission_code'] ?? ''));
                $last4 = sanitize_text_field(wp_unslash($_POST['phone_last4'] ?? ''));
                $result = (new HTP_Submission_Repository())->find_by_code_and_phone_last4($code, $last4);
                if (!$result) {
                    $error = 'Không tìm thấy thông tin phù hợp.';
                }
            }
        }

        ob_start();
        ?>
        <section class="htp-public">
            <div class="htp-form-card">
                <h2>Tra cứu đăng ký</h2>
                <p class="htp-form-intro">Nhập mã đăng ký và 4 số cuối điện thoại.</p>
                <?php if ($error) : echo self::notice($error, 'error'); endif; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
                <?php if ($result) : ?>
                    <?php $labels = HTP_Submission_Service::status_labels((string) $result->form_key); ?>
                    <div class="htp-lookup-result">
                        <p><strong>Mã:</strong> <?php echo esc_html($result->submission_code); ?></p>
                        <p><strong>Loại:</strong> <?php echo esc_html($result->form_name); ?></p>
                        <p><strong>Salon:</strong> <?php echo esc_html($result->salon_name); ?></p>
                        <p><strong>Ngày đăng ký:</strong> <?php echo esc_html(mysql2date('d/m/Y H:i', $result->created_at)); ?></p>
                        <p><strong>Trạng thái:</strong> <?php echo esc_html($labels[$result->status] ?? $result->status); ?></p>
                    </div>
                <?php else : ?>
                    <form method="post" class="htp-form">
                        <?php wp_nonce_field('htp_lookup', 'htp_lookup_nonce'); ?>
                        <label class="htp-field htp-width-full"><span>Mã đăng ký</span><input name="submission_code" required autocomplete="off" placeholder="SL001-D-000001"></label>
                        <label class="htp-field htp-width-full"><span>4 số cuối điện thoại</span><input name="phone_last4" required inputmode="numeric" pattern="[0-9]{4}" maxlength="4"></label>
                        <button type="submit" name="htp_lookup_submit" value="1" class="htp-submit">Tra cứu</button>
                    </form>
                <?php endif; ?>
            </div>
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
            <?php foreach ($salons as $salon) : ?>
                <article class="htp-salon-list__item">
                    <h3><?php echo esc_html($salon->name); ?></h3>
                    <?php if ($salon->address) : ?><p><?php echo esc_html($salon->address); ?></p><?php endif; ?>
                    <?php if ($salon->phone) : ?><p><a href="tel:<?php echo esc_attr(preg_replace('/[^0-9+]/', '', $salon->phone)); ?>"><?php echo esc_html($salon->phone); ?></a></p><?php endif; ?>
                    <a class="htp-link-button" href="<?php echo esc_url(HTP_QR_Service::registration_url($salon)); ?>">Xem salon và đăng ký</a>
                </article>
            <?php endforeach; ?>
        </div>
        <?php
        return (string) ob_get_clean();
    }

    public static function statistics(): string
    {
        $counts = (new HTP_Submission_Repository())->dashboard_counts(null);
        return sprintf(
            '<div class="htp-public htp-public-stats"><div><strong>%d</strong><span>Salon</span></div><div><strong>%d</strong><span>Lượt hiến tóc</span></div><div><strong>%d</strong><span>Thành viên</span></div></div>',
            (new HTP_Salon_Repository())->counts()['active'],
            $counts['donation'],
            $counts['member']
        );
    }

    private static function render_form(object $salon, string $form_key): string
    {
        $form_repository = new HTP_Form_Repository();
        $form = $form_repository->find_by_key($form_key, true);
        if (!$form) {
            return self::notice('Form hiện đang tạm ngừng.', 'warning');
        }
        $fields = $form_repository->fields((int) $form->id, true);
        $error = '';
        $result = null;
        $is_current_post = $_SERVER['REQUEST_METHOD'] === 'POST'
            && isset($_POST['htp_form_submit'])
            && sanitize_key(wp_unslash($_POST['htp_form_key'] ?? '')) === $form_key;

        if ($is_current_post) {
            $nonce = sanitize_text_field(wp_unslash($_POST['htp_form_nonce'] ?? ''));
            $posted_salon_id = absint($_POST['htp_salon_id'] ?? 0);
            if (!wp_verify_nonce($nonce, 'htp_submit_' . $form_key . '_' . $salon->id)) {
                $error = 'Phiên làm việc đã hết hạn. Vui lòng tải lại trang.';
            } elseif ($posted_salon_id !== (int) $salon->id) {
                $error = 'Salon không hợp lệ. Vui lòng tải lại trang.';
            } elseif (!empty($_POST['website'])) {
                $error = 'Không thể gửi biểu mẫu.';
            } else {
                try {
                    $payload = wp_unslash($_POST);
                    $payload['source_url'] = self::current_url();
                    $result = (new HTP_Submission_Service())->submit($salon, $form_key, $payload);
                } catch (Throwable $exception) {
                    $error = $exception->getMessage();
                }
            }
        }

        ob_start();
        ?>
        <div class="htp-form-card" data-htp-form-card="<?php echo esc_attr($form_key); ?>">
            <?php if ($result) : ?>
                <div class="htp-success" role="status">
                    <div class="htp-success__icon" aria-hidden="true">✓</div>
                    <h2><?php echo $form_key === 'member' ? 'Đăng ký thành viên thành công' : 'Đăng ký hiến tóc thành công'; ?></h2>
                    <?php if (!empty($result['existing'])) : ?><p>Bạn đã là thành viên tại salon này. Mã thành viên hiện tại:</p><?php else : ?><p>Mã của bạn:</p><?php endif; ?>
                    <strong class="htp-registration-code"><?php echo esc_html($result['submission_code']); ?></strong>
                    <div class="htp-success-message"><?php echo wp_kses_post(wpautop((string) ($result['success_message'] ?? $form->success_message))); ?></div>
                    <?php if ($form_key === 'member' && !empty($result['oa_url'])) : ?>
                        <a class="htp-oa-button" href="<?php echo esc_url($result['oa_url']); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html((string) get_option('htp_oa_button_label', 'Quan tâm OA MyHair')); ?></a>
                    <?php endif; ?>
                </div>
            <?php else : ?>
                <h2><?php echo esc_html($form->name); ?></h2>
                <?php if ($form->description) : ?><p class="htp-form-intro"><?php echo esc_html($form->description); ?></p><?php endif; ?>
                <?php if ($error) : echo self::notice($error, 'error'); endif; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
                <form method="post" enctype="multipart/form-data" class="htp-form" novalidate data-htp-public-form>
                    <?php wp_nonce_field('htp_submit_' . $form_key . '_' . $salon->id, 'htp_form_nonce'); ?>
                    <input type="hidden" name="htp_form_key" value="<?php echo esc_attr($form_key); ?>">
                    <input type="hidden" name="htp_salon_id" value="<?php echo esc_attr((string) $salon->id); ?>">
                    <input type="text" name="website" value="" class="htp-honeypot" tabindex="-1" autocomplete="off" aria-hidden="true">
                    <?php foreach ($fields as $field) : echo self::render_field($field, $is_current_post); endforeach; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
                    <button type="submit" name="htp_form_submit" value="1" class="htp-submit"><span><?php echo esc_html($form->submit_label); ?></span></button>
                </form>
            <?php endif; ?>
        </div>
        <?php
        return (string) ob_get_clean();
    }

    private static function render_field(object $field, bool $use_old): string
    {
        $key = (string) $field->field_key;
        $type = (string) $field->field_type;
        $label = (string) $field->label;
        $required = (int) $field->required === 1;
        $old = $use_old ? self::old($key) : '';
        $options = HTP_Form_Repository::decode_options($field->options_json);
        $classes = 'htp-field htp-width-' . sanitize_html_class((string) $field->width);
        $required_mark = $required ? ' <b aria-hidden="true">*</b>' : '';
        $required_attr = $required ? ' required' : '';
        $placeholder = $field->placeholder ? ' placeholder="' . esc_attr($field->placeholder) . '"' : '';

        ob_start();
        if (in_array($type, ['checkbox', 'consent'], true)) :
            ?>
            <label class="htp-consent <?php echo esc_attr('htp-width-' . $field->width); ?>">
                <input type="checkbox" name="<?php echo esc_attr($key); ?>" value="1"<?php checked((string) $old, '1'); echo $required_attr; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?> >
                <span><?php echo esc_html($label); echo $required_mark; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></span>
            </label>
            <?php
        elseif ($type === 'checkbox_group') :
            $selected = is_array($old) ? $old : [];
            ?>
            <fieldset class="<?php echo esc_attr($classes); ?> htp-choice-group">
                <legend><?php echo esc_html($label); echo $required_mark; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></legend>
                <div class="htp-choice-list">
                    <?php foreach ($options as $option) : ?>
                        <label><input type="checkbox" name="<?php echo esc_attr($key); ?>[]" value="<?php echo esc_attr($option); ?>" <?php checked(in_array($option, $selected, true)); ?>><span><?php echo esc_html($option); ?></span></label>
                    <?php endforeach; ?>
                </div>
                <?php if ($field->help_text) : ?><small><?php echo esc_html($field->help_text); ?></small><?php endif; ?>
            </fieldset>
            <?php
        elseif ($type === 'radio') :
            ?>
            <fieldset class="<?php echo esc_attr($classes); ?> htp-choice-group">
                <legend><?php echo esc_html($label); echo $required_mark; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></legend>
                <div class="htp-choice-list">
                    <?php foreach ($options as $index => $option) : ?>
                        <label><input type="radio" name="<?php echo esc_attr($key); ?>" value="<?php echo esc_attr($option); ?>" <?php checked((string) $old, $option); echo ($required && $index === 0) ? ' required' : ''; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>><span><?php echo esc_html($option); ?></span></label>
                    <?php endforeach; ?>
                </div>
            </fieldset>
            <?php
        elseif ($type === 'select') :
            ?>
            <label class="<?php echo esc_attr($classes); ?>">
                <span><?php echo esc_html($label); echo $required_mark; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></span>
                <select name="<?php echo esc_attr($key); ?>"<?php echo $required_attr; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>><option value="">— Chọn —</option><?php foreach ($options as $option) : ?><option value="<?php echo esc_attr($option); ?>" <?php selected((string) $old, $option); ?>><?php echo esc_html($option); ?></option><?php endforeach; ?></select>
                <?php if ($field->help_text) : ?><small><?php echo esc_html($field->help_text); ?></small><?php endif; ?>
            </label>
            <?php
        elseif ($type === 'textarea') :
            ?>
            <label class="<?php echo esc_attr($classes); ?>"><span><?php echo esc_html($label); echo $required_mark; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></span><textarea name="<?php echo esc_attr($key); ?>" rows="3"<?php echo $required_attr . $placeholder; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>><?php echo esc_textarea((string) $old); ?></textarea><?php if ($field->help_text) : ?><small><?php echo esc_html($field->help_text); ?></small><?php endif; ?></label>
            <?php
        elseif (in_array($type, ['image', 'images'], true)) :
            $multiple = $type === 'images';
            $capture = $key === 'avatar' ? 'user' : 'environment';
            ?>
            <label class="<?php echo esc_attr($classes); ?> htp-file-field">
                <span><?php echo esc_html($label); echo $required_mark; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></span>
                <input type="file" name="<?php echo esc_attr($key . ($multiple ? '[]' : '')); ?>" accept="image/jpeg,image/png,image/webp" capture="<?php echo esc_attr($capture); ?>"<?php echo $multiple ? ' multiple' : ''; echo $required_attr; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?> data-htp-image-input>
                <?php if ($field->help_text) : ?><small><?php echo esc_html($field->help_text); ?></small><?php endif; ?>
                <div class="htp-image-preview" data-htp-image-preview></div>
            </label>
            <?php
        else :
            $input_type = in_array($type, ['text', 'tel', 'email', 'number', 'date'], true) ? $type : 'text';
            $inputmode = $type === 'tel' ? ' inputmode="tel" autocomplete="tel"' : ($type === 'email' ? ' inputmode="email" autocomplete="email"' : '');
            $step = $type === 'number' ? ' step="any"' : '';
            ?>
            <label class="<?php echo esc_attr($classes); ?>"><span><?php echo esc_html($label); echo $required_mark; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></span><input type="<?php echo esc_attr($input_type); ?>" name="<?php echo esc_attr($key); ?>" value="<?php echo esc_attr((string) $old); ?>"<?php echo $required_attr . $placeholder . $inputmode . $step; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>><?php if ($field->help_text) : ?><small><?php echo esc_html($field->help_text); ?></small><?php endif; ?></label>
            <?php
        endif;
        return (string) ob_get_clean();
    }

    private static function salon_card(object $salon): string
    {
        $logo = absint($salon->logo_id ?? 0) ? wp_get_attachment_image_url((int) $salon->logo_id, 'medium') : '';
        $cover = absint($salon->cover_image_id ?? 0) ? wp_get_attachment_image_url((int) $salon->cover_image_id, 'large') : '';
        ob_start();
        ?>
        <header class="htp-salon-hero"<?php if ($cover) : ?> style="--htp-cover:url('<?php echo esc_url($cover); ?>')"<?php endif; ?>>
            <div class="htp-salon-hero__overlay"></div>
            <div class="htp-salon-hero__content">
                <?php if ($logo) : ?><img class="htp-salon-logo" src="<?php echo esc_url($logo); ?>" alt="Logo <?php echo esc_attr($salon->name); ?>"><?php endif; ?>
                <div>
                    <div class="htp-salon-card__eyebrow">Salon thành viên MyHair</div>
                    <h1><?php echo esc_html($salon->name); ?></h1>
                    <span class="htp-salon-code">Mã salon: <?php echo esc_html($salon->code); ?></span>
                </div>
            </div>
        </header>
        <div class="htp-salon-card">
            <?php if ($salon->intro) : ?><div class="htp-salon-intro"><?php echo wp_kses_post(wpautop($salon->intro)); ?></div><?php endif; ?>
            <div class="htp-salon-details">
                <?php if ($salon->address) : ?><p><strong>Địa chỉ:</strong> <?php echo esc_html($salon->address); ?></p><?php endif; ?>
                <?php if ($salon->phone) : ?><p><strong>Điện thoại:</strong> <a href="tel:<?php echo esc_attr(preg_replace('/[^0-9+]/', '', $salon->phone)); ?>"><?php echo esc_html($salon->phone); ?></a></p><?php endif; ?>
                <?php if ($salon->opening_hours) : ?><p><strong>Giờ hoạt động:</strong> <?php echo nl2br(esc_html($salon->opening_hours)); ?></p><?php endif; ?>
                <?php if ($salon->map_url) : ?><p><a class="htp-text-link" href="<?php echo esc_url($salon->map_url); ?>" target="_blank" rel="noopener">Xem Google Maps</a></p><?php endif; ?>
            </div>
            <?php if ($salon->instruction) : ?><div class="htp-salon-instruction"><?php echo wp_kses_post(wpautop($salon->instruction)); ?></div><?php endif; ?>
        </div>
        <?php
        return (string) ob_get_clean();
    }

    private static function notice(string $message, string $type): string
    {
        return sprintf('<div class="htp-public htp-notice htp-notice--%s" role="alert">%s</div>', esc_attr($type), esc_html($message));
    }

    private static function old(string $key): mixed
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !isset($_POST[$key])) {
            return '';
        }
        $value = wp_unslash($_POST[$key]);
        if (is_array($value)) {
            return array_values(array_map('sanitize_text_field', array_map('strval', $value)));
        }
        return sanitize_text_field((string) $value);
    }

    private static function current_url(): string
    {
        $scheme = is_ssl() ? 'https' : 'http';
        $host = isset($_SERVER['HTTP_HOST']) ? sanitize_text_field(wp_unslash($_SERVER['HTTP_HOST'])) : '';
        $uri = isset($_SERVER['REQUEST_URI']) ? esc_url_raw(wp_unslash($_SERVER['REQUEST_URI'])) : '';
        return $host ? $scheme . '://' . $host . $uri : '';
    }
}
