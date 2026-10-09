# Home2Home

Nền tảng Web kết nối Host có chỗ ở với Guest cần tìm và đặt chỗ, lấy cảm hứng từ Airbnb. Giao diện Warm Minimalism; backend dùng PHP OOP/MVC và dữ liệu thật từ MySQL.

## Stack

- PHP 8.2 OOP, MVC, PDO
- MySQL/MariaDB (`db_home2home`)
- HTML5, CSS3, JavaScript/AJAX
- Bootstrap 4.6.2
- XAMPP/Apache

## Cấu trúc

```text
app/          Controllers, Models, Services, Views
config/       cấu hình app/database từ environment
core/         Router, Controller, PDO, Auth, Session, CSRF
database/     schema, seed và importer UTF-8
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
C:\xampp\php\php.exe tests\schema_test.php
C:\xampp\php\php.exe tests\booking_flow_test.php
powershell -ExecutionPolicy Bypass -File tests\http_smoke.ps1
```

Quote và tổng tiền luôn được tính lại ở server. Transaction booking lưu snapshot chính sách, giá từng đêm, event và notification. Chi tiết tại [docs/architecture.md](docs/architecture.md).

## Giới hạn hiện tại

- Chưa có reset mật khẩu/email delivery, bản đồ, payment, messaging và optional features.
- Admin chưa có đầy đủ create/edit/delete user và transition booking.
- Filter nâng cao/pagination, report workflow, notification inbox và Host reply review chưa hoàn chỉnh.
- HTTP và DB integration đã chạy; chưa có visual regression bằng browser automation ở lượt này.

## Nhóm thực hiện

| MSSV | Họ và tên |
|---|---|
| 31241021765 | Lê Trung Phong (Nhóm trưởng) |
| 31241020790 | Đỗ Sơn Thành |
| 31241020025 | Nguyễn Tuấn Khôi |
| 31241025109 | Nguyễn Minh Thức |
