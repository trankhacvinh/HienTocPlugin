# HienTocPlugin

Plugin WordPress quản lý hệ thống MyHair theo salon.

## Chức năng chính

- Mỗi salon có một mã duy nhất, ví dụ `PHU0001`.
- Mỗi salon có một landing WordPress riêng, QR mở trực tiếp landing.
- Nút **Tạo trang mặc định** tạo trang có shortcode `[htp_salon_landing]`; trang có thể chỉnh tự do bằng Gutenberg/page builder.
- Mỗi salon có thể gán một **chủ salon chính** bằng tài khoản WordPress trong hệ thống MyHair.
- Mỗi form gửi từ landing được lưu với `salon_id` và bản chụp chủ salon tại thời điểm đăng ký, nên quản trị tổng biết chính xác khách thuộc salon và chủ salon nào.
- Chủ salon chính được tự động cấp quyền truy cập salon; có thể phân công thêm nhân viên tại màn hình Tài khoản.
- Landing hiển thị thông tin salon và hai tab chuyển qua lại: đăng ký hiến tóc và đăng ký thành viên.
- Cấu hình form dùng chung toàn hệ thống: bật/tắt trường, bắt buộc, kéo thả thứ tự, chiều rộng và trường tùy chỉnh.
- Form hiến tóc cho phép chụp/chọn nhiều ảnh tóc.
- Form thành viên cho phép chụp/chọn ảnh đại diện và cấp mã thành viên riêng theo salon.
- Một số điện thoại có thể đăng ký hiến tóc nhiều lần và có thể là thành viên của nhiều salon.
- Thành viên đã tồn tại trong cùng salon sẽ nhận lại mã thành viên hiện có thay vì tạo trùng.
- Sau đăng ký thành viên hiển thị nút **Quan tâm OA MyHair**, dùng URL chung hoặc URL riêng của salon.
- Danh sách hiến tóc/thành viên hiển thị mã salon, tên salon và chủ salon; có tìm kiếm, lọc, phân trang 20/30/50/100 dòng.
- Xuất Excel `.xlsx` theo bộ lọc, bao gồm mã salon và chủ salon; fallback `.xls` khi hosting không có `ZipArchive`.
- Phân quyền theo salon, báo cáo theo salon/chủ salon, nhật ký hoạt động và tra cứu mã.
- Có công cụ bật permalink `/%postname%/` để loại `index.php` khỏi URL khi máy chủ hỗ trợ rewrite.
- Ngày sinh trên form public nhập theo chuẩn Việt Nam `dd/mm/yyyy`.

## Sao lưu & khôi phục

Trong **MyHair → Cài đặt → Sao lưu & khôi phục**:

- **Tải bản sao lưu đầy đủ**: tạo file `.htpbackup` để lưu về máy tính.
- **Khôi phục từ file**: nhập file `.htpbackup`; dữ liệu MyHair hiện tại sẽ được thay thế bằng bản backup.
- Backup gồm: salon, cấu hình form, khách hiến tóc, thành viên, trạng thái, lịch sử, phân quyền salon, cấu hình plugin, landing page và ảnh liên quan.
- Tài khoản WordPress không bị xóa hoặc tạo mới khi restore; plugin ánh xạ lại tài khoản theo dữ liệu hiện có.
- Khi người dùng **xóa plugin**, `uninstall.php` cố gắng tạo một backup đầy đủ trong `wp-content/htp-backups` trước khi xóa bảng/ảnh/trang do plugin tạo.
- Thư mục backup có file bảo vệ cho Apache/IIS và không bị xóa cùng plugin. Sau khi cài lại, các backup tự động sẽ xuất hiện lại trong màn hình Cài đặt để khôi phục.

> Dù có backup tự động, trước khi xóa plugin vẫn nên bấm **Tải bản sao lưu đầy đủ** và giữ một bản trên máy tính.

## Cài đặt

1. Nén thư mục plugin thành ZIP.
2. WordPress → Plugin → Thêm plugin mới → Tải plugin lên.
3. Kích hoạt plugin.
4. Vào **MyHair → Tài khoản**, tạo tài khoản chủ salon.
5. Vào **MyHair → Salon**, tạo salon, nhập mã như `PHU0001`, chọn chủ salon chính và bấm **Tạo trang mặc định**.
6. Vào **MyHair → Cấu hình form** để điều chỉnh hai form.
7. Vào **MyHair → Cài đặt** để nhập URL OA, kiểm tra đường dẫn đẹp và quản lý backup.

## Cách hệ thống xác định khách của salon

Ví dụ salon có mã `PHU0001` và chủ salon là ông A:

```text
Landing PHU0001
→ khách gửi form
→ lưu salon_id của PHU0001
→ lưu owner_user_id của ông A tại thời điểm gửi
→ sinh mã PHU0001-D-xxxxxx hoặc PHU0001-M-xxxxxx
```

Khi xem danh sách tổng hoặc xuất Excel, quản trị viên sẽ thấy cả salon `PHU0001` và tên chủ salon tương ứng.

## Shortcode

- `[htp_salon_landing]`
- `[htp_donation_form]`
- `[htp_member_form]`
- `[htp_registration_lookup]`
- `[htp_salon_list]`
- `[htp_statistics]`

## Gỡ cài đặt

- Vô hiệu hóa: giữ nguyên dữ liệu.
- Xóa plugin: trước tiên cố gắng tạo backup tự động, sau đó xóa toàn bộ bảng, tùy chọn, vai trò, ảnh form và các trang do plugin tự tạo.
- Trang WordPress do người dùng tự tạo/gắn vào salon được giữ lại.
- Backup tự động trong `wp-content/htp-backups` được giữ lại để dùng sau khi cài lại.
