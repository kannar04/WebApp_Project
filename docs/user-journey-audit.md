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

