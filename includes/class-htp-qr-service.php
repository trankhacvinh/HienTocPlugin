<?php

defined('ABSPATH') || exit;

final class HTP_QR_Service
{
    public static function registration_url(object $salon): string
    {
        $page_id = absint($salon->landing_page_id ?? 0);
        if ($page_id) {
            $permalink = get_permalink($page_id);
            if ($permalink) {
                return $permalink;
            }
        }

        return home_url('/' . sanitize_title(strtolower((string) $salon->code)) . '/');
    }

    public static function image_url(string $url, int $size = 300): string
    {
        $size = max(120, min(1000, $size));
        $default = add_query_arg([
            'text' => $url,
            'size' => $size,
            'format' => 'png',
            'margin' => 2,
        ], 'https://quickchart.io/qr');
        return (string) apply_filters('htp_qr_image_url', $default, $url, $size);
    }
}
