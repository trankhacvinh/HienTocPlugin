<?php

defined('ABSPATH') || exit;

final class HTP_QR_Service
{
    public static function registration_url(object $salon): string
    {
        $page_id = (int) get_option('htp_registration_page_id', 0);
        $base_url = $page_id ? get_permalink($page_id) : home_url('/');
        return add_query_arg('salon', rawurlencode((string) $salon->code), $base_url);
    }

    public static function image_url(string $text, int $size = 320): string
    {
        $size = max(160, min(1000, $size));
        $url = add_query_arg([
            'text' => $text,
            'size' => $size,
            'margin' => 2,
            'format' => 'png',
        ], 'https://quickchart.io/qr');
        return (string) apply_filters('htp_qr_image_url', $url, $text, $size);
    }
}
