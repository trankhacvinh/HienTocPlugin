<?php

defined('ABSPATH') || exit;

final class HTP_Upload_Service
{
    public function upload_field(string $field_name, bool $multiple = false): array
    {
        if (!isset($_FILES[$field_name])) {
            return [];
        }

        $file = $_FILES[$field_name];
        $items = $multiple ? $this->normalize_multiple($file) : [$file];
        $limit = $multiple ? max(1, min(10, (int) get_option('htp_hair_photo_limit', 3))) : 1;
        $items = array_slice($items, 0, $limit);
        $attachment_ids = [];

        foreach ($items as $index => $item) {
            if ((int) ($item['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
                continue;
            }
            $attachment_ids[] = $this->upload_one($field_name . '_' . $index, $item);
        }

        return array_values(array_filter(array_map('absint', $attachment_ids)));
    }

    private function upload_one(string $temporary_key, array $file): int
    {
        if ((int) ($file['error'] ?? UPLOAD_ERR_OK) !== UPLOAD_ERR_OK) {
            throw new RuntimeException('Không thể tải ảnh lên. Vui lòng thử lại.');
        }

        $max_bytes = max(1, min(20, (int) get_option('htp_upload_max_mb', 5))) * MB_IN_BYTES;
        if ((int) ($file['size'] ?? 0) > $max_bytes) {
            throw new RuntimeException('Ảnh vượt quá dung lượng cho phép.');
        }

        $allowed = [
            'jpg|jpeg|jpe' => 'image/jpeg',
            'png' => 'image/png',
            'webp' => 'image/webp',
        ];
        $check = wp_check_filetype_and_ext((string) $file['tmp_name'], (string) $file['name'], $allowed);
        if (empty($check['type']) || !in_array($check['type'], $allowed, true)) {
            throw new RuntimeException('Chỉ chấp nhận ảnh JPG, PNG hoặc WebP.');
        }

        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/media.php';
        require_once ABSPATH . 'wp-admin/includes/image.php';

        $extension = pathinfo((string) ($check['proper_filename'] ?: $file['name']), PATHINFO_EXTENSION);
        $random_name = 'htp-' . wp_generate_password(20, false, false) . '.' . strtolower($extension ?: 'jpg');
        $file['name'] = $random_name;
        $_FILES[$temporary_key] = $file;

        $attachment_id = media_handle_upload($temporary_key, 0, [], [
            'test_form' => false,
            'mimes' => $allowed,
        ]);
        unset($_FILES[$temporary_key]);

        if (is_wp_error($attachment_id)) {
            throw new RuntimeException($attachment_id->get_error_message());
        }

        update_post_meta((int) $attachment_id, '_htp_submission_file', 1);
        return (int) $attachment_id;
    }

    private function normalize_multiple(array $file): array
    {
        if (!is_array($file['name'] ?? null)) {
            return [$file];
        }

        $result = [];
        foreach ($file['name'] as $index => $name) {
            $result[] = [
                'name' => $name,
                'type' => $file['type'][$index] ?? '',
                'tmp_name' => $file['tmp_name'][$index] ?? '',
                'error' => $file['error'][$index] ?? UPLOAD_ERR_NO_FILE,
                'size' => $file['size'][$index] ?? 0,
            ];
        }
        return $result;
    }
}
