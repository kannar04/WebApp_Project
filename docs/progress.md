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

