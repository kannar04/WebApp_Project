# Home2Home

Nền tảng Web kết nối Host có chỗ ở với Guest cần tìm và đặt chỗ, lấy cảm hứng từ Airbnb. Giao diện Warm Minimalism; backend dùng PHP OOP/MVC và dữ liệu thật từ MySQL.

## Stack

- PHP 8.2 OOP, MVC, PDO CALL / Stored Procedures
- MySQL/MariaDB (`db_home2home`)
- HTML5, CSS3, JavaScript/AJAX
- Bootstrap 4.6.2
- XAMPP/Apache

## Cấu trúc

```text
app/          Controllers, Models, Services, Views
config/       cấu hình app/database từ environment
core/         Router, Controller, PDO, Auth, Session, CSRF
database/     schema, seed, procedure migrations và importer UTF-8
public/       front controller, assets, uploads
routes/       route web/API
tests/        schema, booking integration, HTTP smoke
docs/         kiến trúc, traceability, tiến độ và test report
```

## Chức năng

- **Guest:** tìm kiếm theo địa điểm/ngày/số khách và bộ lọc nâng cao, xem chi tiết và bản đồ nhỏ, báo giá theo ngày, đặt chỗ, hủy theo chính sách, yêu thích, đánh giá sau lưu trú, báo cáo, hộp thông báo.
- **Host:** đăng ký làm Host, tạo/sửa chỗ ở, quản lý ảnh, lịch trống, duyệt/từ chối/hoàn thành booking, phản hồi đánh giá, thống kê.
- **Admin:** quản lý người dùng, vai trò, chỗ ở (duyệt/ẩn/gỡ), booking, đánh giá, báo cáo, danh mục, thống kê và nhật ký quản trị.
- **Nice-to-have (2026-10-13):** giá cuối tuần/ngày lễ, giảm giá theo tuần/tháng, mã khuyến mãi, Đặt ngay (Instant Book), thanh toán khi nhận phòng (Host/Admin ghi nhận đã thu, Admin hoàn tiền); xác minh SĐT/CCCD và huy hiệu “Đã xác minh”, huy hiệu “Host uy tín”, hướng dẫn nhận phòng; tin nhắn theo booking, nút liên hệ Zalo, Admin quản lý tin nhắn, nhắc lịch trước ngày check-in; tìm chỗ ở trên bản đồ (`/map`), gợi ý theo lịch sử xem/tìm kiếm; cẩm nang/tin tức (`/articles`) và chế độ tối. Giao diện tiếng Việt/tiếng Anh đang được xây dựng.

## Cài đặt và chạy

1. Bật Apache và MySQL trong XAMPP.
2. Khuyến nghị cấu hình Apache VirtualHost trỏ DocumentRoot tới `public/`.
3. Sao chép `.env.example` thành `.env`, chỉnh credential nếu cần.
4. Import database:

```powershell
C:\xampp\php\php.exe database\import.php
```

Lệnh này chỉ dành cho DB mới/chưa có bảng. Nếu DB đã tồn tại, importer sẽ dừng trước khi seed để tránh ghi đè dữ liệu nhóm. `.env.example` được chia sẻ trong Git, mặc định tắt debug.

Nếu đã có `db_home2home`, chỉ cài routines, không cần import/seed lại dữ liệu:

```powershell
C:\xampp\php\php.exe database\install_procedures.php
```

Nếu `db_home2home` được tạo **trước** khi gộp Nice-to-have (22 bảng), cần nâng cấp trước khi mở web: chạy lại `database/schema.sql` để tạo 13 bảng mới (chỉ `CREATE TABLE IF NOT EXISTS`, không đụng dữ liệu cũ), tùy chọn nạp phần seed Nice-to-have, rồi chạy `install_procedures.php` như trên. Các bước đầy đủ (phpMyAdmin hoặc dòng lệnh) ở [docs/database-setup.md › Nâng cấp db_home2home đang dùng](docs/database-setup.md#nâng-cấp-db_home2home-đang-dùng-lên-bản-nice-to-have-2026-10-13).

5. Chạy nhanh:

```powershell
C:\xampp\php\php.exe -S 127.0.0.1:8090 -t public public\router.php
```

Mở `http://127.0.0.1:8090`.

6. (Tùy chọn) Nhắc lịch check-in hằng ngày bằng Windows Task Scheduler — thay `C:\path\to\project` bằng thư mục dự án:

```powershell
schtasks /Create /TN "Home2Home check-in reminders" /SC DAILY /ST 07:00 /F /TR "C:\xampp\php\php.exe C:\path\to\project\database\checkin_reminders.php --days=1"
```

Không cài tác vụ thì nhắc lịch vẫn được tạo khi người dùng đã đăng nhập tải trang (tối đa mỗi 30 phút một lần). Dòng cron cho Linux và giải thích tham số: [docs/database-setup.md › Nhắc lịch check-in tự động](docs/database-setup.md#nhắc-lịch-check-in-tự-động).

## Tài khoản local/test

Mật khẩu chung: `Password123!`.

- Admin: `admin@home2home.test`
- Host: `host@home2home.test`
- Guest: `guest@home2home.test`

Không dùng các tài khoản/mật khẩu này trên môi trường public.

## Test

```powershell
C:\xampp\php\php.exe tests\fresh_setup_test.php --quality-regression
C:\xampp\php\php.exe tests\schema_test.php --compare-live
powershell -ExecutionPolicy Bypass -File tests\db_outage_audit.ps1
```

Quote và tổng tiền được tính trong routines. Transaction booking lưu snapshot chính sách, giá từng đêm, event, notification và audit Admin; caller transaction được giữ bằng savepoint. Chi tiết tại [docs/stored-procedure-audit.md](docs/stored-procedure-audit.md).

Schema khởi tạo khớp cấu trúc MariaDB hiện hữu (35 bảng: 22 bảng gốc + 13 bảng Nice-to-have). `fresh_setup_test.php --quality-regression` tự chạy mọi `tests/feature_*_test.php` và `tests/feature_*_audit.ps1`; thêm `--no-screenshots` để không ghi đè ảnh trong `docs/quality/screenshots`. Installer không tự chạy migration DROP `040`; giữ script lịch sử để nhóm review riêng. Kiểm thử thiết lập độc lập: `tests/fresh_setup_test.php` tạo DB/server tạm riêng và dọn DB của chính nó, không dùng DB chia sẻ. Audit mới: [docs/codebase-cleanup-report.md](docs/codebase-cleanup-report.md).

## Giới hạn hiện tại

- Khôi phục mật khẩu qua email **đang chờ cấu hình**, theo xác nhận của nhóm. Đổi mật khẩu, token hash dùng một lần/hết hạn và hủy phiên cũ đã được kiểm thử; không tuyên bố email delivery PASS. Form quên mật khẩu hiển thị rõ trạng thái này.
- Filter nâng cao/phân trang, chi tiết booking/refund, lịch tháng, quản lý ảnh Host, inbox trong ứng dụng, phản hồi đánh giá, báo cáo và danh mục Admin đã triển khai và kiểm thử trên DB/server tạm riêng.
- Bản đồ dùng tile OpenStreetMap nên cần Internet; không có geocoding (Host tự nhập/chọn tọa độ), chỗ ở chưa có tọa độ không hiện trên bản đồ, không gom cụm marker. Bản đồ nhỏ ở trang chi tiết và thao tác kéo bản đồ mới chỉ kiểm tra thủ công bằng Chrome; liên kết Google Maps, vị trí địa chỉ và dịch vụ share/clipboard bên ngoài còn NOT_VERIFIED.
- Chưa tích hợp VNPay/MoMo (cần thông tin merchant); hiện chỉ có thanh toán khi nhận phòng. Booking tạo trước bản này chưa có dòng thanh toán cho đến khi được đánh dấu đã thu.
- Tin nhắn chỉ trong ứng dụng, không real-time, không email/SMS; Admin chỉ xem/ẩn/hiện, không gửi tin. Nhắc lịch cần tác vụ định kỳ hoặc lượt tải trang.
- Xác minh danh tính do Admin kiểm tra thủ công dữ liệu nhập (không upload ảnh, OCR hay OTP). Hướng dẫn nhận phòng lưu theo chỗ ở, mã cửa không tự đổi.
- Chưa có nhãn Đặt ngay trên thẻ kết quả trang chủ/tìm kiếm; đặt chỗ lỗi không giữ ngày đã chọn. Ngày lễ seed là dữ liệu mẫu, Admin cần xác nhận.
- Đổi slug bài viết làm hỏng link cũ; ảnh bìa bị thay vẫn nằm trên đĩa. Chế độ tối lưu theo trình duyệt (không theo tài khoản) và cần JavaScript. Giao diện tiếng Việt/tiếng Anh đang được xây dựng.
- Dashboard Admin giới hạn tập kết quả; dữ liệu quy mô lớn vẫn cần phân trang theo module và load test.
- HTTP/DB integration và Chrome responsive/AJAX cho Guest/Host/Admin đã chạy; chưa có screenshot-diff regression, full screen reader/keyboard hoặc load testing production.

Đối chiếu đầy đủ 94 nhóm: [requirements matrix](docs/quality/requirements-matrix.md). Kết quả, dataflow, evidence và giới hạn: [final verification](docs/quality/final-verification.md).

### Cấu hình email khi nhóm có dịch vụ

Hiện để `MAIL_ENABLED=false`. Chỉ sau khi cấu hình transport PHP/XAMPP sendmail/SMTP và kiểm tra gửi/nhận thật, mới điền `MAIL_FROM`, `PASSWORD_RESET_BASE_URL` (HTTPS khi public), `MAIL_ENABLED=true` trong `.env` **local**. Các biến này không tự cấu hình SMTP; `mail()` phụ thuộc transport của PHP. Mật khẩu/API key chỉ nằm trong cấu hình riêng, không gửi vào chat hoặc Git. `.env.example` chỉ chứa placeholder an toàn, không được điền secret thật.

Chạy `fresh_setup_test.php --quality-regression` sẽ tạo DB ngẫu nhiên/server riêng, opt-in các test routines **chỉ tại đó**, chạy PHP/SQL/HTTP/Chrome và dọn DB của chính lượt chạy. Không chạy các mutation suite lẻ trên DB dùng chung. Installer production không seed, reset bảng hay cài test routines theo mặc định.

## Nhóm thực hiện

| MSSV | Họ và tên |
|---|---|
| 31241021765 | Lê Trung Phong (Nhóm trưởng) |
| 31241020790 | Đỗ Sơn Thành |
| 31241020025 | Nguyễn Tuấn Khôi |
| 31241025109 | Nguyễn Minh Thức |
