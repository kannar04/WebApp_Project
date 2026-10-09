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

