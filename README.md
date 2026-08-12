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
- Có đồng bộ Google Sheets với hàng đợi và retry tự động.
- Có thể ghi đồng thời vào **Sheet tổng MyHair** và **Google Sheet riêng của từng salon** chỉ với một Apps Script chung.

## Đồng bộ Google Sheets

Trong **MyHair → Cài đặt → Đồng bộ Google Sheets**:

- Kết nối bằng **Google Apps Script Web App**, không cần cài Google SDK trên hosting PHP.
- Có cấu hình bật/tắt, Web App URL, Secret key và tên hai tab Google Sheet.
- Đăng ký mới và thay đổi trạng thái tự được đưa vào hàng đợi đồng bộ.
- Nếu Google lỗi hoặc mất mạng, dữ liệu chính vẫn lưu trong WordPress; hàng đợi tự thử lại tối đa nhiều lần với thời gian chờ tăng dần.
- Có nút **Kiểm tra kết nối**, **Đồng bộ hàng đợi ngay** và **Đồng bộ lại toàn bộ dữ liệu**.
- Apps Script dùng `submission_code` làm khóa upsert nên chạy lại không tạo dòng trùng.
- Payload gồm mã salon, tên salon, chủ salon, thông tin khách, trạng thái, trường tùy chỉnh và URL ảnh.
- Hai loại dữ liệu mặc định tách thành tab `Hien toc` và `Thanh vien`, có thể đổi tên trong cấu hình.

Mã Apps Script mẫu nằm tại `docs/google-sheets-apps-script.gs`.

### Thiết lập Sheet tổng

1. Mở Google Sheet tổng muốn nhận dữ liệu.
2. Vào **Extensions → Apps Script**.
3. Dán nội dung `docs/google-sheets-apps-script.gs`.
4. Đổi `MYHAIR_SECRET` thành một chuỗi bí mật và nhập đúng chuỗi đó trong WordPress.
5. Nếu Apps Script là project standalone, nhập Spreadsheet ID của Sheet tổng vào `MYHAIR_SPREADSHEET_ID`; nếu script được mở từ chính Sheet tổng thì có thể để trống.
6. Deploy Apps Script thành **Web App** và lấy URL kết thúc bằng `/exec`.
7. Dán URL vào **MyHair → Cài đặt → Đồng bộ Google Sheets**, lưu và bấm **Kiểm tra kết nối**.
8. Nếu website đã có dữ liệu cũ, bấm **Đồng bộ lại toàn bộ dữ liệu** một lần.

### Thiết lập Google Sheet riêng cho từng salon

Không cần tạo Apps Script hoặc Secret riêng cho từng salon. Tất cả dùng chung Web App đã cấu hình ở trên.

1. Tạo một file Google Sheet riêng cho salon, ví dụ `Salon Phú - MyHair`.
2. Share file đó cho đúng tài khoản Google đang sở hữu/Deploy Apps Script với quyền **Editor**.
3. Trong WordPress vào **MyHair → Salon → Sửa salon**.
4. Tại phần **Google Sheet riêng của salon**, bật **Bật Google Sheet riêng cho salon này**.
5. Dán URL Google Sheet hoặc Spreadsheet ID và bấm **Lưu thay đổi**.
6. Bấm **Kiểm tra kết nối Sheet**. Nếu thành công, Apps Script đã có quyền mở file salon.
7. Nếu salon đã có dữ liệu cũ, bấm **Đồng bộ lại dữ liệu salon**. Plugin sẽ đưa toàn bộ đăng ký của riêng salon vào hàng đợi và bắt đầu xử lý.
8. Sau đó mỗi đăng ký mới/thay đổi trạng thái sẽ được upsert đồng thời vào Sheet tổng và Sheet riêng của đúng salon.

Nếu Sheet riêng bị mất quyền Editor hoặc tạm lỗi, WordPress vẫn giữ dữ liệu chính. Hàng đợi sẽ retry; việc ghi lại vào Sheet tổng cũng an toàn vì `submission_code` không tạo dòng trùng.

## Sao lưu & khôi phục

Trong **MyHair → Cài đặt → Sao lưu & khôi phục**:

- **Tải bản sao lưu đầy đủ**: tạo file `.htpbackup` để lưu về máy tính.
- **Khôi phục từ file**: nhập file `.htpbackup`; dữ liệu MyHair hiện tại sẽ được thay thế bằng bản backup.
- Backup gồm: salon, cấu hình form, khách hiến tóc, thành viên, trạng thái, lịch sử, phân quyền salon, cấu hình plugin, landing page và ảnh liên quan.
- Cấu hình Google Sheet riêng của từng salon được lưu trong option plugin và được đưa vào backup cùng các cấu hình `htp_*` khác.
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
7. Vào **MyHair → Cài đặt** để nhập URL OA, cấu hình Google Sheets, kiểm tra đường dẫn đẹp và quản lý backup.

## Cách hệ thống xác định khách của salon

Ví dụ salon có mã `PHU0001` và chủ salon là ông A:

```text
Landing PHU0001
→ khách gửi form
→ lưu salon_id của PHU0001
→ lưu owner_user_id của ông A tại thời điểm gửi
→ sinh mã PHU0001-D-xxxxxx hoặc PHU0001-M-xxxxxx
→ ghi Sheet tổng
→ nếu salon bật Sheet riêng: ghi thêm Sheet riêng PHU0001
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
