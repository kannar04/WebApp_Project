# Home2Home — User Journey Audit

Ngày audit: 2026-10-09  
Phạm vi: Guest, Host, Admin; UI, HTTP/session, authorization và dữ liệu MySQL.

## Kết quả sửa lỗi

| ID | Mức độ | Luồng | Hiện tượng / nguyên nhân gốc | Sửa chữa | Trạng thái |
|---|---|---|---|---|---|
| UX-001 | P1 | Guest → đăng nhập → đặt chỗ | Đăng nhập luôn điều hướng theo role nên mất listing/trang bảo vệ đang xem. | Lưu `intended_url` cho GET bảo vệ và referrer cùng host; đăng nhập quay lại URL hợp lệ. Chặn open redirect. | VERIFIED |
| UX-002 | P1 | Host sửa listing | Form không hydrate quan hệ `listing_amenities`; lưu lại có thể xóa toàn bộ tiện nghi. | Đọc ID tiện nghi từ bảng nối, đánh dấu checkbox và hỗ trợ chủ động bỏ chọn toàn bộ. | VERIFIED |
| UX-003 | P1 | Seed → catalog/form | Upsert seed dùng `VALUES(is_active)` nhưng cột không có trong INSERT, làm loại phòng và tiện nghi thành inactive sau khi seed lại. | Seed truyền `is_active=1` rõ ràng cho mọi bản ghi. | VERIFIED |
| UX-004 | P2 | Form HTML hết phiên | CSRF lỗi trả JSON thô, người dùng không có đường phục hồi. | Request HTML quay lại referrer cùng host và hiện flash; request JSON vẫn nhận 419 có cấu trúc. | VERIFIED |
| UX-005 | P2 | Tìm kiếm theo ngày | Chuỗi ngày thiếu/sai/thứ tự ngược đi thẳng xuống truy vấn. | Parse `Y-m-d` chính xác, bắt buộc đủ cặp ngày, không cho ngày quá khứ hoặc checkout ≤ checkin; API trả 422. | VERIFIED |
| UX-006 | P2 | Host quản lý lịch | Route lịch có nhưng dashboard không có affordance điều hướng. | Thêm CTA “Lịch khả dụng” trên từng listing và empty state. | VERIFIED |
| UX-007 | P2 | Admin sửa người dùng | Route edit tồn tại nhưng dashboard không có điểm vào rõ ràng. | Thêm khu vực liên kết chỉnh sửa hồ sơ từng người dùng. | VERIFIED |

## Luồng dữ liệu đã đối chiếu

- Search: query string → chuẩn hóa/validate controller → tham số PDO → danh sách listing công khai.
- Booking: form/quote → validate ngày, sức chứa, availability → transaction → booking + price snapshot → trạng thái Host/Admin.
- Amenities: `listing_amenities` → model → checkbox Host → POST IDs → cập nhật bảng nối.
- Auth: route bảo vệ → session `intended_url` → login → role/ownership guard → đích ban đầu.
- CSRF: token session → POST guard → HTML redirect + flash hoặc JSON 419.

## Tồn đọng không chặn chạy

- P3: Audit responsive hiện mới dựa trên CSS/layout và HTTP; chưa có bộ screenshot regression tự động theo breakpoint.
- P3: Trang lỗi 500 production còn tối giản; nên bổ sung CTA thử lại/quay về trang chủ.
- P3: Dashboard Admin có nhiều dữ liệu sẽ cần pagination và bộ lọc theo trạng thái.

## Source audit follow-up — 2026-10-09

Source/runtime issues SRC-01 through SRC-16, file changes and verification evidence
are recorded in [syntax-audit-report.md](syntax-audit-report.md). Tested real Chrome
navigation for Guest/Host/Admin and home/detail at 375/768/1024/1440. Fixed quote
response ordering/reset, Host invalid-save rollback and preserved input, Admin
profile role guard, invalid-favorite/expired-session JSON handling and recoverable
500 responses. Data checks: original 3 listings retained, 5 old photos retained,
5 local demo photos appended, no remaining booking or user fixtures.

## Stored Procedure + UX audit follow-up — 2026-10-09

Áp dụng skill_user cho hành trình thật và negative paths; skill_web cho CALL-only/dataflow/transaction; skill_UIUX cho feedback và giữ dữ liệu form. Chi tiết SQL, method mapping, routines và giới hạn tại [stored-procedure-audit.md](stored-procedure-audit.md).

| Issue ID | Role / route | Steps to reproduce | Expected | Actual before fix | Severity | Root cause | Files changed | Verification | Status |
|---|---|---|---|---|---|---|---|---|---|
| SP-UX-01 | Tất cả / data layer | Inventory prepare/query trong Model, Service, seeder và tests | Business SQL chỉ qua routine | 108 sites trực tiếp, gồm 76 runtime sites | P1 — architectural requirement | Thiếu gateway và routine coverage | Models, BookingService, procedures, test/seed PHP | stored_procedure_audit_test: 0 violations, signatures match | VERIFIED |
| SP-UX-02 | Login/search / /login, / | Chạy routines mới; gọi city routine với city thật | Query Unicode chạy đúng | 1267 illegal mix of collations; có regression trong migration mới và lỗi tham số routine city cũ | P1 | Tham số dùng collation mặc định khác cột hiện hữu | Procedure SQL / legacy repair | Auth HTTP + legacy city test | VERIFIED |
| SP-UX-03 | Guest / wishlist | Nhấn lưu rồi bỏ lưu | JSON saved đổi đúng, không treo | Routine cũ add/remove tham chiếu p_listing_id không tồn tại | P1 | Identifier parameter có trailing space; notification routine có lỗi tương tự | 005_legacy_repairs.sql | Real Chrome toggle/restore + direct routine tests | VERIFIED |
| SP-UX-04 | Guest / detail | Mở /listings/2 sau refactor | Detail 200, có booking form | 500; migration mới thêm active-host predicate nhưng thiếu JOIN alias | P1 — regression | l.host_id không có l trong reviews query | 010_models.sql | Chrome detail all four widths, HTTP detail/quote | VERIFIED |
| SP-UX-05 | Guest/Host/Admin / booking | Gửi yêu cầu trùng / sai actor / mở caller transaction | Một booking, rollback đúng, không tự commit caller | PHP transaction/SQL scattered, thiếu invariants tập trung trong routine | P1 | Workflow nhiều bảng chưa nằm trong routine | 020_booking.sql, BookingService | Two-process stale snapshots + lifecycle + outer rollback | VERIFIED |
| SP-UX-06 | Admin / edit listing | POST phụ phí -1, với địa chỉ hợp lệ mới | Báo lỗi, giữ form, DB không đổi | HTTP 500, không trở về form theo ngữ cảnh | P1 | Không validate fee/catch lỗi DB ở Admin listingUpdate | AdminController, admin/listing-edit, atomic SQL | HTTP reproduced 500 then rerun PASS with preserved input + unchanged price | VERIFIED |
| SP-UX-07 | Host / visibility | Host fixture POST visibility của listing Host khác | Không thay dữ liệu, phản hồi lỗi | SQL owner predicate bảo vệ dữ liệu nhưng controller luôn flash success | P2 | Không phân biệt update 0 row / unauthorized | HostController, sp_listing_set_visible | HTTP returns /host + alert-danger; direct routine ownership tests | VERIFIED |
| SP-UX-08 | Admin / roles | POST roles dạng scalar hoặc target không tồn tại | Giữ trạng thái và thông báo có đường tiếp tục | Typed array method có thể phát TypeError; role/status/audit calls thiếu boundary chung | P2 | Payload chưa được kiểm tra và audit ngoài transaction | AdminController, atomic role routines | HTTP malformed roles + user CRUD; rollback tests | VERIFIED |
| SP-UX-09 | Admin / user edit | Duplicate email sau khi đổi tên/phone | Trở lại form, giữ input không nhạy cảm | Có recover nhưng form chỉ hiện giá trị DB cũ | P2 | Không truyền old input vào edit view | AdminController, admin/user-edit | Duplicate-email HTTP recovery + browser user form | VERIFIED |
| SP-QA-10 | Browser automation | Error page không có ảnh / reuse debug port | Test phải FAIL khi 500 hoặc CSS chưa được kiểm tra | 500 có thể được đánh PASS; fixed port tái sử dụng context; CSS regex có thể không match | P1 — verification quality | Thiếu HTTP status assertion, endpoint isolation, declaration-count check | browser_audit.ps1 | Strict 200; isolated dynamic port; parsed CSS declarations; final run all pages PASS | VERIFIED |

Sau repairs, pipeline đã chạy trên database thật và Chrome thật. Không tạo screenshot/log giả hoặc thay business result bằng dữ liệu hardcode.

Verified journeys:

- Anonymous/Guest: invalid registration giữ input và không giữ password → register/login → profile update → enroll Host → logout/protected route redirect.
- Search/detail: malformed filters 422, public/active-host visibility, live quote, slow stale response, invalid-to-valid quote, clear dates.
- Guest → Host → Guest: booking pending → duplicate submission rejected → Host confirmed → Guest cancelled; UI redirects và DB states được đối chiếu.
- Host: ownership, invalid fee/amenity/upload rollback, valid JPEG upload/reference, calendar guards, listing form/dashboard.
- Admin: moderation/event verbs, self-demotion guard, duplicate email, invalid listing form recovery, account roles/status/soft-delete, listing CRUD/audit.
- Review: eligible completed historical fixture được seed **chỉ trong isolated schema-test DB** → one review → duplicate/other guest rejected → public guest-name projection.
- Error recovery: 403/404, JSON 401/404/419, HTML/API 500 khi DB outage.

48 responsive page/viewport checks thực tế: home/detail, Guest profile/bookings/wishlist/home, Host dashboard/form/calendar, Admin dashboard/user form/listing form tại 375, 768, 1024, 1440px. Thêm login, real AJAX và favorite interactions. Đây là Chrome viewport checks, không phải chứng nhận mọi thiết bị.

No reproduced P0–P2 remains in the **tested implemented** journeys. Full keyboard/screen reader, load/stress, production privileges/deployment and features without current routes remain NOT_VERIFIED. Older report sections are historical snapshots, not current unverified blockers already resolved in this follow-up.

