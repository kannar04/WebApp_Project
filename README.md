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

5. Chạy nhanh:

```powershell
C:\xampp\php\php.exe -S 127.0.0.1:8090 -t public public\router.php
```

Mở `http://127.0.0.1:8090`.

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

Schema khởi tạo khớp cấu trúc MariaDB hiện hữu (22 bảng). Installer không tự chạy migration DROP `040`; giữ script lịch sử để nhóm review riêng. Kiểm thử thiết lập độc lập: `tests/fresh_setup_test.php` tạo DB/server tạm riêng và dọn DB của chính nó, không dùng DB chia sẻ. Audit mới: [docs/codebase-cleanup-report.md](docs/codebase-cleanup-report.md).

## Giới hạn hiện tại

- Khôi phục mật khẩu qua email **đang chờ cấu hình**, theo xác nhận của nhóm. Đổi mật khẩu, token hash dùng một lần/hết hạn và hủy phiên cũ đã được kiểm thử; không tuyên bố email delivery PASS. Form quên mật khẩu hiển thị rõ trạng thái này.
- Filter nâng cao/phân trang, chi tiết booking/refund, lịch tháng, quản lý ảnh Host, inbox trong ứng dụng, phản hồi đánh giá, báo cáo và danh mục Admin đã triển khai và kiểm thử trên DB/server tạm riêng.
- Bản đồ là liên kết Google Maps từ tọa độ/địa chỉ DB, không phải bản đồ tương tác; vị trí địa chỉ và dịch vụ share/clipboard bên ngoài còn NOT_VERIFIED. Chưa có VNPay/MoMo, chat, Instant Book, khuyến mãi, xác minh CCCD và phần lớn Nice-to-have.
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
