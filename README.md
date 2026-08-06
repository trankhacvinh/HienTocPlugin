# HienTocPlugin

Plugin WordPress quản lý chương trình hiến tóc theo salon.

## Trạng thái hiện tại

Bản nền tảng MVP đầu tiên đã có:

- Tạo bảng salon, đăng ký và lịch sử trạng thái khi kích hoạt plugin.
- Vai trò `Quản lý chương trình hiến tóc` và `Tài khoản salon`.
- Trang quản trị tổng quan và tạo salon cơ bản.
- Chọn một trang WordPress bất kỳ làm trang đăng ký.
- Shortcode `[htp_registration_form]` đọc mã salon từ `?salon=XXXX`.
- Shortcode `[htp_salon_info]` chỉ hiển thị thông tin salon.
- Form public mobile-first, hiển thị rõ salon trước khi khách nhập thông tin.
- Lưu đăng ký, sinh mã dạng `MH001-000001` và ghi lịch sử ban đầu.
- Nonce, honeypot, kiểm tra dữ liệu và xác thực lại salon ở backend.

## Cách thử nhanh

1. Cài plugin vào WordPress và kích hoạt.
2. Vào **Hiến tóc → Cài đặt**, chọn một trang WordPress.
3. Chèn shortcode `[htp_registration_form]` vào trang đó.
4. Vào **Hiến tóc → Salon**, tạo salon mã `MH001`.
5. Mở đường dẫn của salon, ví dụ:

```text
https://domain.com/dang-ky/?salon=MH001
```

## Yêu cầu

- WordPress 6.4+
- PHP 8.1+
- MySQL hoặc MariaDB theo yêu cầu của WordPress

## Ghi chú phát triển

Mã nguồn hiện ở giai đoạn nền tảng. Các module danh sách đăng ký, cập nhật trạng thái, QR, phân quyền theo salon, báo cáo và xuất dữ liệu sẽ được bổ sung ở các pull request tiếp theo.
