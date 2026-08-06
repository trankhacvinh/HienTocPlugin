<?php

defined('ABSPATH') || exit;

final class HTP_Landing_Service
{
    public function create_default_page(object $salon, string $requested_slug = ''): int
    {
        $existing_id = absint($salon->landing_page_id ?? 0);
        if ($existing_id && get_post($existing_id)) {
            return $existing_id;
        }

        $slug = sanitize_title($requested_slug !== '' ? $requested_slug : strtolower((string) $salon->code));
        if ($slug === '') {
            $slug = 'salon-' . absint($salon->id);
        }

        $page_id = wp_insert_post([
            'post_title' => (string) $salon->name,
            'post_name' => $slug,
            'post_content' => "[htp_salon_landing]\n",
            'post_status' => 'publish',
            'post_type' => 'page',
            'comment_status' => 'closed',
        ], true);

        if (is_wp_error($page_id)) {
            throw new RuntimeException($page_id->get_error_message());
        }

        update_post_meta((int) $page_id, '_htp_created_page', 'salon');
        update_post_meta((int) $page_id, '_htp_salon_id', (int) $salon->id);
        (new HTP_Salon_Repository())->set_landing_page((int) $salon->id, (int) $page_id);
        HTP_Activity_Logger::log('salon_landing_created', 'salon', (int) $salon->id, ['page_id' => (int) $page_id]);
        return (int) $page_id;
    }

    public function attach_page(int $salon_id, int $page_id): void
    {
        global $wpdb;

        $old_pages = $wpdb->get_col($wpdb->prepare(
            "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = '_htp_salon_id' AND meta_value = %d",
            $salon_id
        ));
        foreach ($old_pages as $old_page_id) {
            if ((int) $old_page_id !== $page_id) {
                delete_post_meta((int) $old_page_id, '_htp_salon_id');
            }
        }

        if (!$page_id) {
            (new HTP_Salon_Repository())->set_landing_page($salon_id, 0);
            return;
        }

        $page = get_post($page_id);
        if (!$page || $page->post_type !== 'page') {
            throw new InvalidArgumentException('Trang landing không hợp lệ.');
        }

        $wpdb->update(
            $wpdb->prefix . 'htp_salons',
            ['landing_page_id' => null, 'updated_at' => current_time('mysql')],
            ['landing_page_id' => $page_id]
        );
        delete_post_meta($page_id, '_htp_salon_id');
        update_post_meta($page_id, '_htp_salon_id', $salon_id);
        (new HTP_Salon_Repository())->set_landing_page($salon_id, $page_id);
    }

    public function resolve_salon(array $atts = []): ?object
    {
        $repository = new HTP_Salon_Repository();
        $code = isset($atts['salon']) ? sanitize_text_field((string) $atts['salon']) : '';
        if ($code === '' && isset($_GET['salon'])) {
            $code = sanitize_text_field(wp_unslash($_GET['salon']));
        }
        if ($code !== '') {
            return $repository->find_active_by_code($code);
        }

        $post_id = get_queried_object_id();
        if ($post_id) {
            $salon_id = absint(get_post_meta($post_id, '_htp_salon_id', true));
            if ($salon_id) {
                $salon = $repository->find_by_id($salon_id);
                return $salon && $salon->status === 'active' ? $salon : null;
            }
        }

        return null;
    }
}
