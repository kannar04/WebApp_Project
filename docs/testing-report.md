# Báo cáo kiểm thử

Ngày kiểm thử: 2026-10-09 (Asia/Saigon).

Phần dưới là snapshot kiểm thử ban đầu. Kết quả cleanup mới và hướng dẫn seed có xác nhận: [codebase-cleanup-report.md](codebase-cleanup-report.md), [database-setup.md](database-setup.md). Đã kiểm thử hai process đồng thời và Chrome 48 page/viewport checks; không nhầm với stress test tải lớn. Import/seed mặc định giờ chặn DB đã có bảng.

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

## Kiểm thử sau khi gộp Nice-to-have — 2026-10-13

Lệnh: `C:\xampp\php\php.exe tests\fresh_setup_test.php --quality-regression --no-screenshots`. Test tạo DB và server PHP tạm riêng, chỉ cài test routines tại đó và dọn DB của chính nó; không đụng DB dùng chung. Từ bản này, `fresh_setup_test.php` tự chạy mọi `tests/feature_*_test.php` và `tests/feature_*_audit.ps1`.

Gate được chạy sau mỗi lần gộp: gộp 1 (trust + conversation) 176 dòng PASS; gộp 2 (+ booking extras) 201; gộp 3 (+ discovery) 219; gộp 4 (+ content) 264. Cả bốn lần exit 0, không có dòng FAIL, dòng cuối `PASS exact owned test database cleanup; shared database untouched`. Số dòng PASS của lần gộp 4:

| Suite | Dòng PASS | Nội dung chính |
|---|---|---|
| Setup độc lập (`fresh_setup_test.php`) | 3 | schema/seed chạy lặp, HTTP home/detail/login/quote, đăng nhập Guest/Host/Admin |
| `codebase_cleanup_test.php` | 4 | installer 159 định nghĩa production / 38 test opt-in, zero DROP; 35 bảng schema |
| `stored_procedure_audit_test.php` | 4 | 0 SQL nghiệp vụ trực tiếp; 236 CALL sites / 179 routine signatures |
| `booking_flow_test.php` | 1 | quote → pending → confirmed → cancelled, chặn trùng |
| `source_audit_test.php` | 6 | 96 route handlers, ràng buộc SQL, refund, guard lịch |
| `procedure_flow_test.php` | 7 | quyền routine, savepoint, hai process đặt trùng |
| `quality_core_test.php` | 12 | các chức năng Core/Important |
| `feature_booking_extras_test.php` (mới) | 9 | ngày lễ, giá Host, giá linh hoạt, mã khuyến mãi, booking có mã, Instant Book (kể cả hai process), thanh toán/hoàn tiền, HTTP, dọn fixture |
| `feature_trust_test.php` (mới) | 6 | gửi xác minh, Admin duyệt, huy hiệu Host uy tín, hướng dẫn nhận phòng, HTTP 302/403/404/419, dọn fixture |
| `feature_conversation_test.php` (mới) | 9 | Zalo, tin nhắn/chống dồn/đã đọc, độ dài 1–2000, quyền và cửa sổ 14 ngày, inbox, Admin tìm/ẩn/hiện/audit, nhắc lịch (idempotent, throttle 30 phút, CLI, DB lỗi không làm hỏng trang), HTTP, dọn fixture |
| `feature_discovery_test.php` (mới) | 10 | tọa độ, khung bản đồ (kể cả vượt kinh tuyến 180), khung sai 422, lịch sử xem, gợi ý, lịch sử tìm kiếm (20 lần gần nhất), riêng tư/xóa, HTTP bản đồ, HTTP gợi ý/hồ sơ/xóa, dọn fixture |
| `feature_content_test.php` (mới) | 12 | slug tiếng Việt, chỉ Admin trong SQL, slug trùng, validation SQL, audit xuất bản, danh sách công khai 9 bài/trang, xóa mềm, HTTP công khai/Guest 403/Admin CSRF + upload, dọn fixture |
| `http_smoke.ps1` | 1 | home/detail/quote |
| `http_audit.ps1` | 12 | hành trình HTTP Core |
| `browser_audit.ps1` | 158 | Chrome thật ở 375/768/1024/1440, gồm 17 trang mới × 4 độ rộng; không lỗi JavaScript |
| `feature_content_audit.ps1` (mới) | 9 | theme áp dụng trước lần vẽ đầu, nút chế độ tối desktop/mobile/đăng nhập, tương phản WCAG trên 88 lượt xem trang (Guest 7, Host 8, Admin 19 trang) |

Test có sẵn đã sửa: `schema_test.php` và `codebase_cleanup_test.php` đổi số bảng 22 → 35; `browser_audit.ps1` chỉ thêm trang mới vào danh sách; `fresh_setup_test.php` tự tìm suite `feature_*` và thêm cờ `--no-screenshots`.

Ngoài gate, ngày 2026-10-13 đã thử đường nâng cấp DB cũ (22 bảng) lên 35 bảng trên một DB riêng: dữ liệu 22 bảng cũ giữ nguyên số dòng và CHECKSUM, `tests\schema_test.php --compare-live` PASS 35 bảng, `database\checkin_reminders.php` tạo 2 nhắc lịch rồi 0 ở lần chạy lại. Chi tiết: [database-setup.md](database-setup.md).

### Chưa xác minh (Nice-to-have)

- Thao tác kéo bản đồ và bản đồ nhỏ ở trang chi tiết: chỉ kiểm tra thủ công bằng Chrome (CDP) trong báo cáo nhánh discovery, không có test tự động; tile cần Internet.
- Lịch Windows Task Scheduler và cron chưa được tạo/chạy thử; cron chưa thử trên Linux.
- Nâng cấp qua giao diện phpMyAdmin chưa chạy thử (đã thử bằng `mysql.exe ... source`).
- VNPay/MoMo chưa tích hợp. Giao diện tiếng Việt/tiếng Anh đang được xây dựng (`tests/feature_i18n_test.php` thuộc phần này).
- Các kịch bản click-through thủ công trong báo cáo từng nhánh chưa được chạy lại sau khi gộp; kết quả sau gộp dựa trên test tự động.
