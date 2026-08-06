# HienTocPlugin

Plugin WordPress quản lý chương trình hiến tóc theo salon, ưu tiên trải nghiệm khách hàng trên điện thoại.

## Chức năng chính

- Quản lý salon, trạng thái hoạt động và người phụ trách.
- Chọn một trang WordPress bất kỳ làm trang đăng ký.
- Shortcode `[htp_registration_form]` tự đọc `?salon=XXXX`, hiển thị đúng thông tin salon và form mobile-first.
- Shortcode `[htp_salon_info]`, `[htp_registration_lookup]`, `[htp_salon_list]`, `[htp_statistics]`.
- Sinh đường dẫn và QR riêng cho từng salon.
- Quản lý đăng ký, tìm kiếm, lọc, chỉnh sửa và cập nhật trạng thái.
- Lịch sử trạng thái, nhật ký hoạt động và báo cáo theo salon.
- Tài khoản Quản lý chương trình và Tài khoản salon, phân quyền theo salon.
- Xuất CSV theo bộ lọc.
- Cảnh báo đăng ký trùng theo số điện thoại trong khoảng ngày cấu hình.
- Khi xóa plugin, `uninstall.php` xóa toàn bộ bảng, tùy chọn, vai trò và metadata do plugin tạo.

## Yêu cầu

- WordPress 6.4+
- PHP 8.1+
- MySQL/MariaDB theo yêu cầu của WordPress
- Máy chủ cần truy cập được `quickchart.io` để hiển thị/tải ảnh QR mặc định. Có thể thay URL QR bằng filter `htp_qr_image_url`.

## Cài đặt

1. Đặt thư mục plugin vào `wp-content/plugins/hien-toc-plugin` hoặc đóng gói thành ZIP và upload trong WordPress.
2. Kích hoạt plugin.
3. Plugin tự tạo trang đăng ký và trang tra cứu mặc định.
4. Vào **Hiến tóc → Cài đặt** để chọn trang WordPress khác nếu cần.
5. Tạo salon tại **Hiến tóc → Salon**.
6. Sao chép link hoặc tải QR của salon.

## Shortcodes

```text
[htp_registration_form]
[htp_salon_info]
[htp_registration_lookup]
[htp_salon_list]
[htp_statistics]
```

Ví dụ đường dẫn:

```text
https://domain.com/dang-ky-hien-toc/?salon=MH001
```

## Quy trình trạng thái

- `new` — Mới đăng ký
- `confirmed` — Đã xác nhận
- `received` — Đã tiếp nhận
- `completed` — Đã hoàn thành
- `rejected` — Không đạt yêu cầu
- `cancelled` — Đã hủy
- `duplicate` — Trùng đăng ký

Tài khoản salon chỉ được chuyển theo luồng nghiệp vụ. Quản lý chương trình có thể điều chỉnh trạng thái khi cần xử lý ngoại lệ.

## Xóa plugin

- **Vô hiệu hóa**: giữ nguyên dữ liệu.
- **Xóa plugin trong WordPress**: xóa toàn bộ bảng và cấu hình của plugin.

Hãy xuất CSV hoặc sao lưu database trước khi xóa plugin.
