# Tiến độ Home2Home

## Phase hiện tại

Phase 3/4 — củng cố Must-have Admin và Important features.

## TESTED

- Clean schema 22 bảng, seed và PDO connection.
- Trang chủ, chi tiết listing và quote JSON.
- Đăng nhập Guest/Host.
- Booking quote/create/nights/snapshot/double-booking protection.
- Host confirm, Guest cancellation/refund, event và notification dataflow.
- Admin tạo/soft-delete user kèm audit; Admin booking transition.
- Host calendar chặn ngày → ghi DB → redirect → cleanup fixture.
- Chrome headless desktop/mobile cho home, login và listing detail.

## IMPLEMENTED

- Auth/register/logout, profile/avatar và grant Host.
- Listing create/update/upload/visibility/availability.
- Host dashboard, booking actions và stats.
- Admin dashboard, user CRUD/lock/role, listing edit/hide/remove/moderation và audit.
- Wishlist, share link, guest review và responsive Warm Minimal UI.

## IN_PROGRESS

- Advanced search: query hỗ trợ giá/type/bedrooms/sort nhưng UI và amenity/pagination chưa đầy đủ.
- Notification đã ghi DB nhưng chưa có inbox/read-state UI.

## NOT_STARTED

- Forgot/change password; map; report workflow; category/amenity CRUD.
- Host reply/review moderation và email delivery.
- Payment, messaging, promotion, instant book, verification, recommendation, bilingual/dark mode/news.

## Lỗi đang tồn tại

- Không có lỗi syntax/schema/core booking đã biết.
- Chưa có browser visual automation nên UI chưa được đánh dấu visual-verified.

## Test gần nhất

- `schema_test.php`: PASS.
- `booking_flow_test.php`: PASS.
- `http_smoke.ps1`: PASS sau khi dùng assertion ASCII ổn định.
- PHP lint toàn project: PASS trước vòng tài liệu; chạy lại ở final regression.

## Việc tiếp theo

1. Hoàn thành khoảng trống Must-have Admin.
2. Bổ sung UI lịch availability và test upload.
3. Hoàn thiện filter/pagination/report/notification inbox.
4. Browser visual review login/home/detail/profile trên desktop và mobile.

## 2026-10-09 — Source/runtime/image audit

- PHP lint: 55 files PASS; 40 route handlers/autoload PASS.
- Fixed moderation event verbs against actual ERD constraints, Host atomic persistence,
  Admin self-demotion/duplicate email recovery, Wishlist JSON errors, quote response
  ordering, booking/calendar guards, pending refund and arrival-time snapshot.
- Added 5 local demo image references without replacing the 5 original photos;
  second seed run inserted zero. All test fixtures cleaned.
- Real Chrome checks at 375/768/1024/1440, HTTP regression, SQL schema/seed tests and
  controlled DB outage checks PASS. Concurrency stress and full accessibility remain
  NOT_VERIFIED.
- Evidence and limits: [syntax-audit-report.md](syntax-audit-report.md).

## 2026-10-09 — Stored Procedure enforcement + UX repair

Completed and verified locally:

- Replaced 108 direct business-query sites (76 runtime) with stored routine access; PHP business SQL scanner reports zero violations.
- Added CALL-only gateway with typed binding, mutation metadata and full result-set/cursor draining.
- Preserved original 16 team routines; repaired 3 trailing-space parameter bugs and legacy city collation; reused/extended existing routines rather than replacing their names.
- 51 new application/CLI routines, 67 distinct production definitions, 14 test-only routines. Removed only 10 unused transitional helpers created during this refactor; no original routine/table/data reset.
- Booking quote/create/transition/cancel, snapshot pricing, nights, event, notification and Admin booking audit are routine-driven and atomic. Caller transactions survive via savepoints.
- Fixed Admin invalid listing recovery/old input, role payload validation, account/audit transaction coordination and Host non-owner visibility feedback.
- Tests actually run: 63 PHP lint; schema + review fixture; source/booking/routine integration; two-process stale-snapshot overlap; HTTP registration/profile/Host/upload/booking/Admin; real Chrome role pages at four widths; safe DB outage; image-seed idempotence.
- Final live baseline retained: 3 users, 3 listings, 10 photos, 0 bookings, 0 fixture users; test uploads removed, .gitkeep preserved.
- No commit/push or shared grants changes.

Reports: stored-procedure-audit.md, user-journey-audit.md, user-test-matrix.md.
Remaining NOT_VERIFIED: production least-privilege deployment, high-load/deadlock stress, full assistive-technology audit, Oracle MySQL compatibility/non-Vietnam named timezones.

## 2026-10-09 — Codebase cleanup & team Git hygiene

- Baseline clean branch `test`; no commit/push/merge/untrack or shared schema/routine/grant mutation.
- Removed unused amenities form marker; eliminated three unused detail CALLs per wishlist add; shared strict Admin role validation across create/edit/roles, with safe error recovery for missing Admin listings.
- Canonical schema now matches exact DDL of all 22 live ERD tables; verified in an isolated database, without ALTER on the live DB. Existing-schema initialization and unconfirmed reseeding are blocked; historical DROP migration retained but no longer executed by default.
- `.env.example`/`.env.sample` are shareable, debug defaults off; private environment/uploads/dumps remain ignored. Preserved the existing user upload; no production file removed.
- PASS: 66 PHP lint, 106 CALL/78 signatures, installer/Git gates, schema parity, all integration suites, two-process overlap, expanded HTTP Admin cases, 48 Chrome viewport/page checks, HTTP smoke and controlled DB outage.
- PASS independent source-copy setup with separate DB/server and Guest/Host/Admin login/quote. No remote clone/teammate Apache deployment or full accessibility/load certification claimed.
- Reports: codebase-cleanup-audit.md, codebase-cleanup-report.md, database-consistency-audit.md.

## 2026-10-09 — Autonomous Core quality implementation

- Branch `home2home/quality-core`, no commit/push. Preserved baseline/historical reports above.
- 94-group implementation now 67 implemented / 2 partial / 25 absent; verification 65 PASS / 2 BLOCKED / 27 NOT_VERIFIED. Eight partial Must-have completed; do not interpret these as 94 independent unique features or production certification.
- Added Host photo management/soft-delete/calendar, booking snapshots/refund/details, private Admin preview/search, advanced filters/page totals, reviews/reports/catalog/inbox/monthly stats and secure password change/recovery mechanics.
- User confirmed email recovery **đang chờ cấu hình**; external Maps/native share still NOT_VERIFIED; 25 Optional groups deferred.
- PASS isolated full regression, PHP lint (86), 22-table schema parity, 140 CALL sites/108 signatures, two-worker concurrency, real avatar/13-item pagination, Chrome 80 viewport checks plus interactions and safe outage. Screenshots generated from owned demo DB.
- Installed 106 production routine definitions locally; all 22 shared-table row counts unchanged; no shared import/seed/reset/test routine installation. .env and user uploads preserved.
- Current evidence/dataflow: [docs/quality/final-verification.md](quality/final-verification.md); full mapping: [requirements matrix](quality/requirements-matrix.md).

## 2026-10-09 — Visual UX audit & repair

- Repaired homepage Bootstrap 4.6.2 form structure, five labels/44px aligned controls, DB amenities, Lọc/Đặt lại, validation retention, summary/loading and Back restoration. No SP/schema changes for this UX pass.
- Fixed password success/session redirect, invalid profile/report input retention, Admin/review/email labels, keyboard outline specificity, muted contrast and disabled button palette.
- PASS final isolated `--quality-regression --visual-ux`: 92 extended page/viewport checks plus 76 basic checks and interactions, real filter journeys, keyboard navigation/navbar/table scroll; 86 PHP lint; CSS/V8/PowerShell parse and whitespace checks. Live existing server GET smoke returned HTTP 200 and repaired form.
- 25 before/48 after Chrome images; targeted DOM measurements and actual transcript in [Visual UX report](quality/ui-ux-visual-audit.md). Shared DB/.env/uploads preserved; no commit/push.
- Recovery delivery still **đang chờ cấu hình**; full accessibility/native dialog/external Maps/share coverage NOT_VERIFIED. No change to 94-group implementation counts.

## 2026-10-13 — Gộp 5 nhánh Nice-to-have

- Trình tự gộp: commit chuẩn bị (điểm neo trong `schema.sql`/`seed.sql`, `fresh_setup_test.php` tự chạy `tests/feature_*_test.php` và `tests/feature_*_audit.ps1`, biến `$pageScripts` cho JS riêng từng trang) → trust + conversation → booking extras → discovery → content. Sau đó là giao diện tiếng Việt/tiếng Anh (mục dưới).
- Booking extras: giá cuối tuần/ngày lễ, giảm giá theo tuần/tháng, mã khuyến mãi, Instant Book, thanh toán khi nhận phòng (Host/Admin đánh dấu đã thu, Admin hoàn tiền); Admin quản lý ngày lễ, mã khuyến mãi, thanh toán.
- Trust: xác minh SĐT/CCCD có Admin duyệt, huy hiệu “Đã xác minh”, huy hiệu “Host uy tín”, hướng dẫn nhận phòng (mã cửa, Wi-Fi).
- Conversation: tin nhắn theo booking + inbox `/messages`, nút liên hệ Zalo, Admin tìm/ẩn/hiện tin nhắn, nhắc lịch trước ngày check-in (CLI `database/checkin_reminders.php` + lượt tải trang).
- Discovery: tọa độ cho chỗ ở, bản đồ nhỏ trên trang chi tiết, `/map` tìm theo khung bản đồ, lịch sử xem/tìm kiếm, gợi ý, xóa lịch sử.
- Content: CRUD bài viết/cẩm nang (`/admin/articles`, `/articles`) và chế độ tối.
- Database: 22 → 35 bảng (13 bảng mới, chỉ `CREATE TABLE IF NOT EXISTS`, không ALTER/DROP); 106 → 159 định nghĩa routine production (53 mới; đổi tham số `sp_booking_quote`, `sp_booking_create`, `sp_listing_create`, `sp_listing_update`); 16 → 38 định nghĩa test opt-in; 140 → 236 CALL sites, 108 → 179 routine signatures; installer zero DROP.
- Gate sau từng lần gộp (`php tests/fresh_setup_test.php --quality-regression --no-screenshots`, DB/server tạm riêng, log nằm ngoài repo): gộp 1 (trust + conversation) 176 dòng PASS, 26 bảng; gộp 2 (+ booking) 201 PASS, 32 bảng; gộp 3 (+ discovery) 219 PASS, 34 bảng; gộp 4 (+ content) 264 PASS, 35 bảng. Cả bốn lần exit 0, không có dòng FAIL; mọi lần đều kết thúc bằng `PASS exact owned test database cleanup; shared database untouched`.
- Gate gộp 4 theo suite: feature_booking_extras 9, feature_trust 6, feature_conversation 9, feature_discovery 10, feature_content 12, feature_content_audit.ps1 9, browser_audit 158 (thêm 17 trang × 4 độ rộng), http_audit 12, quality_core 12, procedure_flow 7, source_audit 6, stored_procedure_audit 4, codebase_cleanup 4, setup 3, booking_flow 1, http_smoke 1.
- Ma trận 94 nhóm: 88 IMPLEMENTED / 6 PARTIAL / 0 NOT_IMPLEMENTED; 86 PASS / 2 BLOCKED / 6 NOT_VERIFIED / 0 FAIL. Nice-to-have chưa PASS: VNPay/MoMo (cần thông tin merchant) và giao diện tiếng Việt/tiếng Anh. Chi tiết: [requirements matrix](quality/requirements-matrix.md).
- Nâng cấp DB `db_home2home` đang dùng (không xóa dữ liệu) và lịch nhắc check-in: [database-setup.md](database-setup.md). Đường nâng cấp được thử trên một DB riêng dựng lại từ schema/seed/routines cũ: 22 bảng cũ giữ nguyên số dòng và checksum, `tests/schema_test.php --compare-live` PASS 35 bảng.
- Giới hạn đã biết (từ báo cáo từng nhánh): chưa có nhãn Instant Book trên thẻ kết quả; POST đặt chỗ lỗi không giữ ngày đã chọn; xác minh danh tính chỉ kiểm tra thủ công (không ảnh/OCR/OTP); tin nhắn không real-time, không email/SMS; tile bản đồ cần Internet, không geocoding, không gom cụm; đổi slug bài viết làm hỏng link cũ; chế độ tối lưu theo trình duyệt, không theo tài khoản. Không commit/push trong vòng cập nhật tài liệu này.

## 2026-10-13 — Giao diện tiếng Việt/tiếng Anh

- Nền: `core/I18n.php` và hàm `__()` (câu tiếng Việt trong code là khóa), cookie `h2h_locale`, nút EN/VI trên header gửi `POST /locale` có CSRF, `h2hT()` cho JavaScript, flash và JSON được dịch khi in ra. Không thêm bảng hay cột nào.
- Dịch theo khu vực, mỗi khu vực một tệp trong `lang/en/` (số câu hiện tại): chung/trang chủ/chi tiết chỗ ở `common` 214, JavaScript `js` 42, Host `host` 156, Guest `guest` 216, Admin `admin` 286, thông báo controller/service `messages` 235, danh mục (loại chỗ ở, tiện nghi, chính sách) `catalog` 29, thông báo routine `procedures` 180, mẫu có chỗ trống `patterns_messages` 11 + `patterns_procedures` 21.
- Test mới `tests/feature_i18n_test.php` (7 dòng PASS trong gate): kiểm tra từ điển không xung đột và chỗ trống khớp, mọi chữ tiếng Việt trong view/JS/controller/routine có bản dịch, công tắc ngôn ngữ, và 80 trang tiếng Anh của 4 vai trò không còn câu chưa dịch. `H2H_I18N_SCOPE` giới hạn phạm vi khi đang dịch dở; `--report-vietnamese` liệt kê chữ tiếng Việt còn lại (lần chạy cuối chỉ còn dữ liệu mẫu và nhãn “Tiếng Việt”).
- So sánh giao diện tiếng Việt trước và sau khi thêm i18n (156 lượt xem trang, 4 vai trò): giống hệt, chỉ khác nút EN mới và thuộc tính nhãn của nút chế độ tối.
- Kiểm thử hành trình Guest/Host/Admin ở cả hai ngôn ngữ tìm thấy 37 lỗi (0 P1, 8 P2, 29 P3); phần lớn có từ trước i18n (mã trạng thái thô, ngày có micro giây). Đã sửa 33 lỗi (trong đó có lỗi giá cuối tuần/ngày lễ bằng 0), sửa một phần 2 lỗi (nhãn trạng thái đã dịch nhưng câu thông báo “chuyển sang confirmed” trong routine chưa đổi; tiêu đề cột “Booking” ở trang lịch trống giữ nguyên), để lại 2 lỗi có từ trước; thêm `status_label()`, `display_date()`, `display_decimal()` trong `core/bootstrap.php`. Chi tiết và các lỗi còn lại: [testing-report.md](testing-report.md#kiểm-thử-hành-trình-guesthostadmin-2026-10-13).
- Gate cuối (`php tests/fresh_setup_test.php --quality-regression --no-screenshots`): exit 0, 271 dòng PASS (264 cũ + 7 i18n), không có FAIL.
- Ma trận 94 nhóm: 91 IMPLEMENTED / 3 PARTIAL / 0 NOT_IMPLEMENTED; 89 PASS / 2 BLOCKED / 3 NOT_VERIFIED / 0 FAIL. Nice-to-have: 26 IMPLEMENTED + 1 PARTIAL; chỉ còn A28 VNPay/MoMo (cần thông tin merchant).
- `db_home2home` trên máy phát triển đã được nâng cấp lên 35 bảng và cài lại routines; bản sao lưu trước khi nâng cấp nằm ngoài repo.
- Giới hạn: chỉ dịch giao diện, không dịch dữ liệu người dùng nhập; ngôn ngữ lưu theo trình duyệt, không theo tài khoản; giá (`1.250.000đ`) và ngày (`d/m/Y`) giữ định dạng Việt Nam ở cả hai ngôn ngữ, chỉ số thập phân đổi dấu.
