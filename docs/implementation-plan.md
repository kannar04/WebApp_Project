# Kế hoạch triển khai

## Phase 0 — Audit và nền móng (đã kiểm thử)

- Đọc `Functions.txt`, skill, ERD, class diagram, decomposition diagram và UI reference.
- Thiết lập MVC/router/config/PDO/session/CSRF/error handling.
- Nghiệm thu: PHP lint pass; schema tạo sạch; kết nối `db_home2home` thật.

## Phase 1 — Must-have Guest (đã kiểm thử phần lõi)

- Auth, profile, browse/search/detail, server quote, create/list/cancel booking.
- Nghiệm thu: HTTP home/detail/quote; booking service kiểm tra snapshot, nights, event, notification và double-booking.

## Phase 2 — Must-have Host (implemented; flow trạng thái đã integration-test)

- Kích hoạt Host, CRUD listing, upload ảnh, visibility, availability, danh sách/duyệt booking, thống kê.
- Nghiệm thu: ownership, upload validation, state machine, overlap check.

## Phase 3 — Must-have Admin (implemented; core dataflow đã test)

- Dashboard, user CRUD/status/roles, listing edit/hide/remove/moderation, booking overview/transition và audit log.
- Nghiệm thu hiện có: HTTP create/soft-delete user + audit; booking service admin transition; listing edit page HTTP 200.

## Phase 4 — Important (một phần)

- Đã có: filter/sort cơ bản, policy, wishlist, share, review, in-app notification data, stats.
- Còn lại: reset/change password, amenity filter UI, pagination, map, report workflow, category CRUD, host reply, notification inbox/email.

## Phase 5 — Optional (chưa bắt đầu)

- Payment, promotion, instant book, messaging, identity verification, recommendation, bilingual/dark mode, news.
- Chỉ triển khai sau khi Must-have và Important có E2E/browser regression đầy đủ.

