=== Hien Toc Plugin ===
Contributors: HienTocPlugin
Requires at least: 6.4
Requires PHP: 8.1
Stable tag: 2.1.0
License: GPLv2 or later

Plugin quản lý landing salon, đăng ký hiến tóc và thành viên MyHair.

== Description ==

Mỗi salon có mã, landing, QR và chủ salon chính. Mọi form gửi từ landing được gắn với salon và lưu chủ salon tại thời điểm đăng ký. Landing chứa hai tab form hiến tóc/thành viên, form cấu hình được, hỗ trợ ảnh, phân trang, xuất Excel và sao lưu/khôi phục toàn bộ dữ liệu.

== Installation ==

1. Upload ZIP tại Plugins > Add New.
2. Activate.
3. Tạo tài khoản chủ salon.
4. Tạo salon, gán chủ salon và tạo trang landing mặc định.
5. Cấu hình form và URL OA.
6. Trước khi xóa/cài lại plugin, vào MyHair > Cài đặt > Sao lưu & khôi phục để tải file .htpbackup.

== Changelog ==

= 2.1.0 =
* Thêm xuất backup đầy đủ dạng .htpbackup.
* Thêm nhập/khôi phục backup, thay thế toàn bộ dữ liệu MyHair hiện tại.
* Backup gồm salon, form, khách hiến tóc, thành viên, trạng thái, cấu hình, phân quyền salon, landing page và ảnh liên quan.
* Khi xóa plugin, hệ thống tự tạo một backup an toàn trên máy chủ trước khi xóa bảng dữ liệu.
* Sau khi cài lại plugin có thể khôi phục trực tiếp từ backup tự động còn trên máy chủ.
* Giữ cơ chế ánh xạ tài khoản theo ID/email/login khi khôi phục.

= 2.0.3 =
* Ngày sinh hiển thị và nhập theo chuẩn Việt Nam dd/mm/yyyy.
* Vẫn hỗ trợ chọn ngày bằng lịch của trình duyệt/điện thoại.
* Cố định giao diện checkbox/radio để không bị theme WordPress làm phóng to hoặc biến dạng.

= 2.0.2 =
* Gán chủ salon chính bằng tài khoản MyHair.
* Lưu bản chụp chủ salon cho từng đăng ký.
* Hiển thị chủ salon trong danh sách, chi tiết, báo cáo và file Excel.
* Tự động cấp quyền salon cho tài khoản chủ salon.

= 2.0.1 =
* Landing riêng cho từng salon.
* Hai form dạng tab.
* Form builder dùng chung toàn hệ thống.
* Ảnh đại diện và ảnh tóc.
* Danh sách phân trang và xuất Excel.
