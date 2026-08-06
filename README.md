# HienTocPlugin

Plugin WordPress quản lý hệ thống MyHair theo salon.

## Chức năng chính

- Mỗi salon có một landing WordPress riêng, QR mở trực tiếp landing.
- Nút **Tạo trang mặc định** tạo trang có shortcode `[htp_salon_landing]`; trang có thể chỉnh tự do bằng Gutenberg/page builder.
- Landing hiển thị thông tin salon và hai tab chuyển qua lại:
  - Đăng ký hiến tóc.
  - Đăng ký thành viên.
- Cấu hình form dùng chung toàn hệ thống:
  - Bật/tắt trường.
  - Đặt bắt buộc.
  - Kéo thả thứ tự.
  - Chọn toàn dòng, 2/3, 1/2 hoặc 1/3 dòng.
  - Thêm trường tùy chỉnh.
- Form hiến tóc cho phép chụp/chọn nhiều ảnh tóc.
- Form thành viên cho phép chụp/chọn ảnh đại diện và cấp mã thành viên riêng theo salon.
- Một số điện thoại có thể đăng ký hiến tóc nhiều lần và có thể là thành viên của nhiều salon.
- Thành viên đã tồn tại trong cùng salon sẽ nhận lại mã thành viên hiện có thay vì tạo trùng.
- Sau đăng ký thành viên hiển thị nút **Quan tâm OA MyHair**, dùng URL chung hoặc URL riêng của salon.
- Danh sách hiến tóc/thành viên có tìm kiếm, lọc, phân trang 20/30/50/100 dòng.
- Xuất Excel `.xlsx` theo bộ lọc; fallback `.xls` khi hosting không có `ZipArchive`.
- Phân quyền theo salon, báo cáo, nhật ký hoạt động và tra cứu mã.
- Có công cụ bật permalink `/%postname%/` để loại `index.php` khỏi URL khi máy chủ hỗ trợ rewrite.

## Cài đặt

1. Nén thư mục plugin thành ZIP.
2. WordPress → Plugin → Thêm plugin mới → Tải plugin lên.
3. Kích hoạt plugin.
4. Vào **MyHair → Salon**, tạo salon và bấm **Tạo trang mặc định**.
5. Vào **MyHair → Cấu hình form** để điều chỉnh hai form.
6. Vào **MyHair → Cài đặt** để nhập URL OA và kiểm tra đường dẫn đẹp.

## Shortcode

- `[htp_salon_landing]`
- `[htp_donation_form]`
- `[htp_member_form]`
- `[htp_registration_lookup]`
- `[htp_salon_list]`
- `[htp_statistics]`

## Gỡ cài đặt

- Vô hiệu hóa: giữ nguyên dữ liệu.
- Xóa plugin: xóa toàn bộ bảng, tùy chọn, vai trò, ảnh form và các trang do plugin tự tạo.
- Trang WordPress do người dùng tự tạo/gắn vào salon được giữ lại.
