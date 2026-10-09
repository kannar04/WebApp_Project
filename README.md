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
C:\xampp\php\php.exe database\install_procedures.php --include-tests
C:\xampp\php\php.exe tests\stored_procedure_audit_test.php
C:\xampp\php\php.exe tests\schema_test.php
C:\xampp\php\php.exe tests\booking_flow_test.php
C:\xampp\php\php.exe tests\procedure_flow_test.php
powershell -ExecutionPolicy Bypass -File tests\http_smoke.ps1
```

Quote và tổng tiền được tính trong routines. Transaction booking lưu snapshot chính sách, giá từng đêm, event, notification và audit Admin; caller transaction được giữ bằng savepoint. Chi tiết tại [docs/stored-procedure-audit.md](docs/stored-procedure-audit.md).

## Giới hạn hiện tại

- Chưa có reset mật khẩu/email delivery, bản đồ, payment, messaging và optional features.
- Admin user CRUD/status/roles, listing CRUD/moderation và booking transition đã kiểm thử local; dashboard dữ liệu lớn vẫn cần thêm pagination phù hợp.
- Filter nâng cao/pagination, report workflow, notification inbox và Host reply review chưa hoàn chỉnh.
- HTTP/DB integration và Chrome responsive/AJAX cho Guest/Host/Admin đã chạy; chưa có screenshot-diff regression, full screen reader/keyboard hoặc load testing production.

## Nhóm thực hiện

| MSSV | Họ và tên |
|---|---|
| 31241021765 | Lê Trung Phong (Nhóm trưởng) |
| 31241020790 | Đỗ Sơn Thành |
| 31241020025 | Nguyễn Tuấn Khôi |
| 31241025109 | Nguyễn Minh Thức |
