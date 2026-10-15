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
- **Nice-to-have (2026-10-13):** giá cuối tuần/ngày lễ, giảm giá theo tuần/tháng, mã khuyến mãi, Đặt ngay (Instant Book), thanh toán khi nhận phòng (Host/Admin ghi nhận đã thu, Admin hoàn tiền); xác minh SĐT/CCCD và huy hiệu “Đã xác minh”, huy hiệu “Host uy tín”, hướng dẫn nhận phòng; tin nhắn theo booking, nút liên hệ Zalo, Admin quản lý tin nhắn, nhắc lịch trước ngày check-in; tìm chỗ ở trên bản đồ (`/map`), gợi ý theo lịch sử xem/tìm kiếm; cẩm nang/tin tức (`/articles`), chế độ tối và giao diện tiếng Việt/tiếng Anh (nút EN/VI trên header).
- **Thanh toán online (2026-10-15):** booking đã xác nhận có nút “Thanh toán online”. Khách chọn chuyển khoản VietQR vào tài khoản ngân hàng cấu hình trong `.env` (quét bằng app ngân hàng, MoMo hoặc ZaloPay; khách bấm “Tôi đã chuyển khoản”, Admin đối chiếu rồi xác nhận/từ chối có lý do) hoặc cổng VNPay sandbox (kết quả có chữ ký hợp lệ tự ghi nhận đã thanh toán). Thanh toán khi nhận phòng vẫn giữ nguyên.

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

Nếu `db_home2home` đã có 35 bảng (trước bản thanh toán online), chạy lại `database/schema.sql` một lần để tạo bảng `payment_transactions` và cho phép phương thức `vietqr`/`vnpay` (không xóa dữ liệu), rồi chạy `install_procedures.php`. Chi tiết: [docs/database-setup.md › Nâng cấp cho thanh toán online](docs/database-setup.md#nâng-cấp-cho-thanh-toán-online-2026-10-15).

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

Giao diện tiếng Anh: khi thêm chữ mới, view viết câu tiếng Việt trong `__('...')` (JS: `h2hT('...')`), câu có phần thay đổi dùng chỗ trống `__('Cuộc trò chuyện booking #{id}', ['id' => $id])`; sau đó thêm cặp tiếng Việt → tiếng Anh vào tệp đúng khu vực trong `lang/en/` (chữ cho JS vào `lang/en/js.php`). `tests/feature_i18n_test.php` báo câu nào chưa qua `__()` hoặc chưa có bản dịch. Cách hoạt động: [docs/architecture.md](docs/architecture.md#giao-diện-tiếng-việttiếng-anh).

## Giới hạn hiện tại

- Khôi phục mật khẩu qua email **đang chờ cấu hình**, theo xác nhận của nhóm. Đổi mật khẩu, token hash dùng một lần/hết hạn và hủy phiên cũ đã được kiểm thử; không tuyên bố email delivery PASS. Form quên mật khẩu hiển thị rõ trạng thái này.
- Filter nâng cao/phân trang, chi tiết booking/refund, lịch tháng, quản lý ảnh Host, inbox trong ứng dụng, phản hồi đánh giá, báo cáo và danh mục Admin đã triển khai và kiểm thử trên DB/server tạm riêng.
- Bản đồ dùng tile OpenStreetMap nên cần Internet; không có geocoding (Host tự nhập/chọn tọa độ), chỗ ở chưa có tọa độ không hiện trên bản đồ, không gom cụm marker. Bản đồ nhỏ ở trang chi tiết và thao tác kéo bản đồ mới chỉ kiểm tra thủ công bằng Chrome; liên kết Google Maps, vị trí địa chỉ và dịch vụ share/clipboard bên ngoài còn NOT_VERIFIED.
- Thanh toán online: VNPay mới chạy bằng merchant sandbox (tiền thử nghiệm); IPN cần địa chỉ public nên trên localhost kết quả được ghi nhận qua trang VNPay trả về (vẫn kiểm tra chữ ký và số tiền). MoMo/ZaloPay không có cổng riêng, khách dùng ví đó để quét mã VietQR. Chuyển khoản VietQR không có webhook ngân hàng: Admin đối chiếu sao kê thủ công. Hoàn tiền online vẫn là ghi nhận thủ công, không gọi API hoàn tiền. Booking tạo trước bản Nice-to-have chưa có dòng thanh toán cho đến khi được đánh dấu đã thu.
- Tin nhắn chỉ trong ứng dụng, không real-time, không email/SMS; Admin chỉ xem/ẩn/hiện, không gửi tin. Nhắc lịch cần tác vụ định kỳ hoặc lượt tải trang.
- Xác minh danh tính do Admin kiểm tra thủ công dữ liệu nhập (không upload ảnh, OCR hay OTP). Hướng dẫn nhận phòng lưu theo chỗ ở, mã cửa không tự đổi.
- Chưa có nhãn Đặt ngay trên thẻ kết quả trang chủ/tìm kiếm; đặt chỗ lỗi không giữ ngày đã chọn. Ngày lễ seed là dữ liệu mẫu, Admin cần xác nhận.
- Đổi slug bài viết làm hỏng link cũ; ảnh bìa bị thay vẫn nằm trên đĩa. Chế độ tối lưu theo trình duyệt (không theo tài khoản) và cần JavaScript. Ngôn ngữ cũng lưu theo trình duyệt (cookie `h2h_locale`); chỉ dịch giao diện, không dịch dữ liệu người dùng nhập; giá (`1.250.000đ`) và ngày (`d/m/Y`) giữ định dạng Việt Nam ở cả hai ngôn ngữ, riêng số thập phân (điểm đánh giá, phần trăm) dùng dấu chấm khi xem tiếng Anh.
- Dashboard Admin giới hạn tập kết quả; dữ liệu quy mô lớn vẫn cần phân trang theo module và load test.
- HTTP/DB integration và Chrome responsive/AJAX cho Guest/Host/Admin đã chạy; chưa có screenshot-diff regression, full screen reader/keyboard hoặc load testing production.

Đối chiếu đầy đủ 94 nhóm: [requirements matrix](docs/quality/requirements-matrix.md). Kết quả, dataflow, evidence và giới hạn: [final verification](docs/quality/final-verification.md).

### Cấu hình email khi nhóm có dịch vụ

Hiện để `MAIL_ENABLED=false`. Chỉ sau khi cấu hình transport PHP/XAMPP sendmail/SMTP và kiểm tra gửi/nhận thật, mới điền `MAIL_FROM`, `PASSWORD_RESET_BASE_URL` (HTTPS khi public), `MAIL_ENABLED=true` trong `.env` **local**. Các biến này không tự cấu hình SMTP; `mail()` phụ thuộc transport của PHP. Mật khẩu/API key chỉ nằm trong cấu hình riêng, không gửi vào chat hoặc Git. `.env.example` chỉ chứa placeholder an toàn, không được điền secret thật.

### Cấu hình thanh toán online

Chỉ điền trong `.env` **local**; nhóm nào để trống thì phương thức đó ẩn khỏi trang thanh toán. Không đưa số tài khoản hay khóa VNPay vào Git hoặc chat.

- **VietQR:** `PAYMENT_BANK_ID` (mã BIN hoặc tên viết tắt ngân hàng theo danh sách https://api.vietqr.io/v2/banks, ví dụ `970436` hoặc `VCB`), `PAYMENT_BANK_NAME` (tên hiển thị), `PAYMENT_ACCOUNT_NO`, `PAYMENT_ACCOUNT_NAME` (tên chủ tài khoản, không dấu). Mã QR lấy từ dịch vụ ảnh miễn phí `img.vietqr.io` nên cần Internet; nội dung chuyển khoản là mã `H2H<booking>X<5 ký tự>` để Admin đối chiếu.
- **VNPay sandbox:** đăng ký merchant thử nghiệm tại http://sandbox.vnpayment.vn/devreg/, điền `VNPAY_TMN_CODE` và `VNPAY_HASH_SECRET` nhận qua email. `VNPAY_PAY_URL` mặc định là cổng sandbox; `VNPAY_RETURN_URL` để trống thì dùng địa chỉ khách đang mở. Khi chạy trên tên miền public, khai báo IPN URL `https://<tên miền>/payments/vnpay/ipn` trong trang quản trị merchant.
- **Thẻ test VNPay** (theo tài liệu sandbox của VNPay): ngân hàng NCB, số thẻ `9704198526191432198`, chủ thẻ `NGUYEN VAN A`, ngày phát hành `07/15`, OTP `123456`.

Chạy `fresh_setup_test.php --quality-regression` sẽ tạo DB ngẫu nhiên/server riêng, opt-in các test routines **chỉ tại đó**, chạy PHP/SQL/HTTP/Chrome và dọn DB của chính lượt chạy. Không chạy các mutation suite lẻ trên DB dùng chung. Installer production không seed, reset bảng hay cài test routines theo mặc định.

## Nhóm thực hiện

| MSSV | Họ và tên |
|---|---|
| 31241021765 | Lê Trung Phong (Nhóm trưởng) |
| 31241020790 | Đỗ Sơn Thành |
| 31241020025 | Nguyễn Tuấn Khôi |
| 31241025109 | Nguyễn Minh Thức |
