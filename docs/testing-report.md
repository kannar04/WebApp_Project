# Báo cáo kiểm thử

Ngày kiểm thử: 2026-10-09 (Asia/Saigon).

## Kết quả đã chạy

| Nhóm | Lệnh/luồng | Kết quả |
|---|---|---|
| PHP syntax | `php -l` toàn bộ file PHP | PASS |
| Schema clean install | `tests/schema_test.php` | PASS — tạo 22 bảng trên DB tạm và cleanup |
| Database connection/seed | `database/import.php --seed-only` | PASS — 3 users, 3 listings |
| Booking integration | `tests/booking_flow_test.php` | PASS |
| HTTP home/detail/quote | `tests/http_smoke.ps1` | PASS sau khi sửa assertion encoding |
| HTTP auth/booking | login Guest → booking → trips | PASS |
| HTTP Host transition | login Host → confirmed | PASS |
| HTTP Guest cancellation | confirmed → cancelled | PASS |
| HTTP Admin user CRUD | create → soft delete → audit | PASS |
| HTTP Host availability | calendar → block date → DB → cleanup | PASS |
| Browser visual | Chrome headless desktop/mobile | PASS — home/login/detail captured |

Booking integration xác minh quote 2 đêm = 2.650.000đ; tạo đúng 2 `booking_nights`; request overlap bị chặn; state `pending → confirmed → cancelled`; tạo đủ 3 events và 3 notifications; refund theo snapshot là 100%; fixture được cleanup.

## Lỗi đã phát hiện và sửa

- Database hiện hữu theo ERD dùng tên cột khác bản phác thảo (`base_nightly_rate`, `policy_snapshot`, `reason`). Code đã được sửa để theo schema hiện hữu.
- Pipe SQL qua Windows PowerShell làm hỏng Unicode. Thay bằng importer PHP đọc trực tiếp UTF-8 và seed lại.
- Smoke test PowerShell đọc literal tiếng Việt theo ANSI. Assertion đổi sang token ASCII; HEX trong DB xác nhận UTF-8 đúng.

## Chưa xác minh

- Visual comparison đã chụp bằng Chrome headless; vẫn chưa xác minh trên thiết bị vật lý.
- SMTP/email delivery vì chưa có cấu hình dịch vụ.
- Load/concurrency nhiều process thực; transaction/locking mới được integration-test tuần tự.
- Các requirement mang trạng thái `IMPLEMENTED`, `IN_PROGRESS`, `NOT_STARTED` trong matrix.

## Quality gate

| Hạng mục | Trạng thái |
|---|---|
| OOP/MVC/separation | PASS |
| Bootstrap 4.6.2/design tokens/responsive rules | PASS (Chrome headless desktop/mobile) |
| Validation/Auth/Authz/CSRF/error handling | PASS cho flow đã test |
| Schema/constraints/prepared statements/transaction | PASS |
| AJAX JSON | PASS cho quote; NOT VERIFIED đầy đủ cho endpoint còn lại |
| Functional/integration/regression | PASS cho core booking/Admin/calendar; PARTIAL Important/Optional |
| Documentation/setup/traceability | PASS |

Ảnh kiểm chứng: [home](screenshots/home.png), [login](screenshots/login.png), [listing](screenshots/listing.png), [home mobile](screenshots/home-mobile.png).

