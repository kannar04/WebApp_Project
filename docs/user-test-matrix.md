# Home2Home — User Test Matrix

Môi trường: PHP 8.2.12, MySQL/XAMPP, `http://127.0.0.1:8090`, ngày 2026-10-09.

| Persona | Kịch bản | Kỳ vọng | Kết quả |
|---|---|---|---|
| Public | Mở trang chủ và listing chi tiết | HTTP 200, có dữ liệu listing và booking box | PASS |
| Public | Quote listing hợp lệ | JSON success, tổng tiền đúng snapshot giá | PASS |
| Public | Search checkout trước checkin | API 422, không đưa ngày lỗi xuống SQL | PASS |
| Guest | Truy cập `/bookings` khi chưa login rồi đăng nhập | Trở lại `/bookings` | PASS |
| Guest | Login bằng tài khoản Guest | Về trang chủ | PASS |
| Host | Login bằng tài khoản Host | Về `/host` | PASS |
| Admin | Login bằng tài khoản Admin | Về `/admin` | PASS |
| Host | Mở dashboard | Có link lịch khả dụng trên listing | PASS |
| Host | Mở form sửa listing #1 | 4 tiện nghi đã lưu được checked | PASS |
| Host | Seed lại dữ liệu danh mục | 3/3 loại phòng và 6/6 tiện nghi active | PASS |
| Auth user | POST form với CSRF hết hạn | Quay lại form, có thông báo phục hồi | PASS |
| Authorization | Guest/Host/Admin qua các route theo role | Guard role/ownership áp dụng ở controller | PASS (integration + code review) |
| Booking | Quote → pending → confirmed → cancelled | Chuyển trạng thái hợp lệ; chặn double booking | PASS |
| Database | Tạo schema sạch | Đủ 22 bảng | PASS |
| PHP | Lint toàn bộ source | Không lỗi cú pháp | PASS (50 files) |

## Lệnh kiểm thử

```powershell
C:\xampp\php\php.exe tests\schema_test.php
C:\xampp\php\php.exe tests\booking_flow_test.php
powershell -ExecutionPolicy Bypass -File tests\http_smoke.ps1
```

Các test tạo dữ liệu booking dùng fixture/transaction riêng và dọn dữ liệu kiểm thử; không xóa dữ liệu người dùng ngoài phạm vi fixture.

## Additional source audit cases — 2026-10-09

| Case | Evidence | Result |
|---|---|---|
| Host invalid price/amenity and disguised JPEG upload preserve DB | tests/http_audit.ps1 | PASS |
| Admin approval/rejection event CHECK, self-role removal and duplicate email | tests/http_audit.ps1 + tests/source_audit_test.php | PASS |
| Anonymous/invalid favorite/invalid CSRF JSON responses | tests/http_audit.ps1 | PASS |
| Booking/calendar block, future completion and pending refund | tests/source_audit_test.php | PASS |
| Quote slow earlier response, error-to-success, cleared dates | tests/browser_audit.ps1 | PASS |
| Chrome home/detail 375/768/1024/1440 and Guest/Host/Admin pages | tests/browser_audit.ps1 | PASS |
| Database outage recovery HTML/API | tests/db_outage_audit.ps1 | PASS |
| Image seed repeated without duplicates and local JPEG references | database/seeds/seed_listing_images.php + tests/source_audit_test.php | PASS |
| Multi-process concurrency stress / full keyboard and screen reader audit | Not executed | NOT_VERIFIED |

Full scope and limitations: [syntax-audit-report.md](syntax-audit-report.md).

## Stored Procedure regression matrix — 2026-10-09

Bảng này cập nhật kết quả sau refactor; các số liệu trong phần cũ là snapshot của lần audit trước. [SQL mapping and limits](stored-procedure-audit.md).

| Case ID | Role / journey | Executed evidence | Result |
|---|---|---|---|
| SP-01 | Source SQL compliance / signatures | stored_procedure_audit_test.php: 0 business SQL, 106 CALL sites, 78 live signatures | PASS |
| SP-02 | Multi-result / cursor release | 100 sequential CALLs, caller deliberately reads only one row | PASS |
| SP-03 | Legacy Unicode city + notifications | Named-param fixes; actual city lookup; nonexistent notification returns 0 | PASS |
| SP-04 | Fresh DB + reproducibility | schema_test.php: 22 tables; seed twice; SQL routines installed; own DB dropped | PASS |
| SP-05 | Atomic roles / amenities / booking | procedure_flow_test.php + source_audit_test.php: SIGNAL, savepoint rollback and outer rollback | PASS |
| SP-06 | Two-process concurrency | Separate PHP/PDO workers, both holding stale read snapshots; one creation / one rejection | PASS |
| SP-07 | Booking price/policy/refunds/events | booking_flow_test.php and source_audit_test.php, confirmed and pending cancellations | PASS |
| SP-08 | Direct SQL-routine authorization | Invalid owner, forged Admin true/NULL/2, invalid amenity/role JSON | PASS |
| SP-09 | Registration/profile/Host/logout | Real HTTP POSTs with isolated marker accounts; no password in old input | PASS |
| SP-10 | Guest booking → Host confirm → Guest cancel | HTTP states verified through fixture inspect; duplicate submit makes no second booking | PASS |
| SP-11 | Host create/update / image references | Actual Listing create, HTTP update, real JPEG multipart upload and exact fixture cleanup | PASS |
| SP-12 | Host calendar / non-owner visibility | Service/calendar conflict guards; HTTP alert-danger and safe redirect for foreign listing | PASS |
| SP-13 | Admin users | HTTP status/roles/delete; model account/profile/NULL-avatar checks; self-demotion blocked | PASS |
| SP-14 | Admin listing CRUD/audit | procedure_flow_test.php + HTTP moderation and bad fee case with preserved form | PASS |
| SP-15 | Completed-stay review | Fresh isolated historical booking; eligible review succeeds, duplicate/other guest rejected | PASS |
| SP-16 | Search/detail/AJAX/wishlist browser | Chrome live quote, stale response, error recovery, clear fields, favorite toggle/restore | PASS |
| SP-17 | Responsive role pages | 48 browser page/viewport checks at 375/768/1024/1440 + login | PASS |
| SP-18 | Frontend syntax/runtime | Chrome V8 parse, nonempty CSS declaration validation, no JS exceptions on journeys | PASS |
| SP-19 | Network/DB recovery | HTTP smoke; separate controlled DB-outage server: safe HTML/API 500 | PASS |
| SP-20 | PHP syntax | 63 files linted | PASS |
| SP-21 | Seed idempotence / data preservation | image seeder dry-run + apply: 0 inserted, 5 skipped; 3 users/3 listings/10 photos/0 bookings/0 fixture users | PASS |
| SP-22 | Git/secret discipline | No commit/push/reset/grants change; SQL/source/docs not blanket ignored; diff whitespace check | PASS |
| SP-23 | Large-load stress / general deadlock retry policy | Not executed; concurrency verification is limited to two independent workers | NOT_VERIFIED |
| SP-24 | Full keyboard/screen reader / assistive technology | Not executed | NOT_VERIFIED |
| SP-25 | Production EXECUTE-only account / Oracle MySQL / non-Vietnam named zones | Not deployed; actual tested server is MariaDB 10.4.32 local XAMPP | NOT_VERIFIED |
| SP-26 | Payments, email delivery, reset-password and other absent routes | Outside implemented workflow scope; no claim of completion | NOT_APPLICABLE |

Fixtures use exact generated markers/IDs. Uploaded test images are removed only after matching the fixture listing prefix, generated basename and resolved public/uploads root. Other uploads/files are untouched. Historical dates are never injected into the live database.
